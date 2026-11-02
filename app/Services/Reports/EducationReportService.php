<?php

namespace App\Services\Reports;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** One event source feeds cards, named rows and trend buckets. No historical backfill. */
class EducationReportService
{
    private const HIDDEN_LEAD_STAGES = ['English Pro', 'School Enrollment', 'Visa Process'];

    public function build(ReportPeriod $period): array
    {
        $students = Lead::inStudentsRegister()->get(['id', 'first_name', 'last_name', 'referral', 'student_school',
            'education_stage', 'english_stage', 'immigration_stage', 'stage', 'is_student', 'is_immigration_case', 'stage_history']);
        $events = $students->flatMap(fn ($lead) => $this->history($lead, $period->now))->all();
        if ($period->isAll()) {
            $earliest = collect($events)->sortBy(fn ($e) => $e['at']->getTimestamp())->first();
            $period = $period->withAllStart($earliest['at'] ?? null);
        }

        $departments = [];
        foreach (['education' => Lead::EDUCATION_STAGES, 'english' => Lead::ENGLISH_STAGES, 'immigration' => Lead::IMMIGRATION_STAGES] as $key => $stages) {
            $members = $students->filter(fn ($lead) => in_array($key, $this->memberships($lead), true));
            $ids = $members->modelKeys();
            $departmentEvents = array_values(array_filter($events, fn ($e) => $e['department'] === $key && in_array($e['lead']->id, $ids, true)));
            $departments[$key] = $this->department($key, $members, $stages, $departmentEvents, $period);
        }

        [$pipeline, $register, $pipelineNotice] = $this->pipeline($period);
        $education = $departments['education'];
        $noteKey = $period->isAll() ? 'edu_report_note:all' : 'edu_report_note:'.$period->start->toDateString().':'.$period->end->subDay()->toDateString();
        $proposals = collect($education['stages'])->firstWhere('stage', 'Proposal Sent')['count'] ?? 0;
        $conclusion = [
            'auto' => $period->isAll()
                ? "{$education['total']} Education students in the current register. Records with missing dates remain included."
                : "{$education['total']} distinct Education students with {$education['event_count']} recorded stage events in this period. A student may appear at more than one milestone.",
            'note' => Setting::get($noteKey), 'note_key' => $noteKey,
            'labels' => ['movements' => 'Recorded stage events', 'qualified' => $period->isAll() ? 'Students with recorded history' : 'Students with changes',
                'proposals' => $period->isAll() ? 'At proposal stage now' : 'Reached proposal stage', 'register' => $period->isAll() ? 'Current Education register' : 'Students in period'],
            'stats' => ['movements' => $education['event_count'], 'qualified' => $education['activity_total'], 'proposals' => $proposals, 'register' => $education['total']],
        ];

        return [
            'error' => null,
            'portal' => 'education', 'range' => $period->toArray(), 'departments' => $departments,
            'pipeline' => $pipeline, 'register' => $register, 'totalRegister' => count(array_unique(array_merge(...array_map(fn ($rows) => array_column($rows, 'id'), array_values($register))))),
            'pipeline_history' => $pipelineNotice, 'movements' => $education['event_count'], 'summary' => [], 'summaryLists' => [],
            'programs' => ['total' => $period->isAll() ? Program::count() : null,
                'published' => $period->isAll() ? Program::where('status', 'published')->count() : null,
                'draft' => $period->isAll() ? Program::where('status', 'draft')->count() : null,
                'history_available' => false, 'scope' => $period->isAll() ? 'current' : 'unavailable'],
            'conclusion' => $conclusion, 'generated_at' => $period->now->toIso8601String(), 'generated_by' => auth()->user()?->name,
        ];
    }

    private function memberships(Lead $lead): array
    {
        $immigration = $lead->is_immigration_case || $lead->immigration_stage || in_array($lead->education_stage, Lead::EDUCATION_STAGES_IMMIGRATION, true);
        $english = $lead->english_stage || $lead->stage === 'English Pro';

        return array_keys(array_filter(['education' => $lead->is_student || (! $immigration && ! $english), 'english' => $english, 'immigration' => $immigration]));
    }

    private function label(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255 ? trim($value) : null;
    }

    private function history(Lead $lead, CarbonImmutable $now): array
    {
        $entries = $lead->stage_history;
        if (! is_array($entries)) {
            return [];
        }
        $unique = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['department'] ?? null) || ! in_array($entry['department'], ['education', 'english', 'immigration'], true)) {
                continue;
            }
            $stage = $this->label($entry['stage'] ?? null);
            $at = ReportPeriod::eventDate($entry['at'] ?? null);
            if ($stage === null || ! $at || $at->gt($now)) {
                continue;
            }
            $unique[$entry['department'].'|'.$stage.'|'.$at->format('U.u')] = ['lead' => $lead, 'stage' => $stage, 'department' => $entry['department'], 'at' => $at];
        }

        return array_values($unique);
    }

    private function latest(array $events, ReportPeriod $period, ?string $stage = null, bool $previous = false): array
    {
        $rows = [];
        foreach ($events as $event) {
            if (($stage !== null && $event['stage'] !== $stage) || ! $period->contains($event['at'], $previous)) {
                continue;
            }
            $id = $event['lead']->id;
            if (! isset($rows[$id]) || $event['at']->gt($rows[$id]['at'])) {
                $rows[$id] = $event;
            }
        }

        return array_values($rows);
    }

    private function row(Lead $lead, ?CarbonImmutable $at, bool $moved): array
    {
        return ['id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name) ?: 'Client',
            'ref' => $lead->referral, 'school' => $lead->student_school, 'date' => $at?->utc()->toIso8601String(), 'moved' => $moved];
    }

    private function rows(array $events): array
    {
        usort($events, fn ($a, $b) => $b['at'] <=> $a['at']);

        return array_map(fn ($e) => $this->row($e['lead'], $e['at'], true), $events);
    }

    private function department(string $key, Collection $members, array $canonical, array $events, ReportPeriod $period): array
    {
        $column = $key.'_stage';
        $currentNames = $members->map(fn ($lead) => $this->label($lead->{$column}))->filter(fn ($name) => $name !== null)->unique()->values()->all();
        $eventNames = $period->isAll() ? [] : array_column(array_filter($events, fn ($e) => $period->contains($e['at'])), 'stage');
        $occupied = array_unique(array_merge($currentNames, $eventNames));
        $names = array_values(array_unique(array_merge(array_values(array_intersect($canonical, $occupied)), $occupied)));
        if ($members->contains(fn ($lead) => $this->label($lead->{$column}) === null)) {
            $names[] = 'No stage set';
        }
        $names = array_values(array_unique($names));
        $stages = [];
        foreach ($names as $name) {
            if ($period->isAll()) {
                $clients = $members->filter(fn ($lead) => ($this->label($lead->{$column}) ?? 'No stage set') === $name)
                    ->map(function ($lead) use ($events, $name) {
                        $matching = array_filter($events, fn ($e) => $e['lead']->id === $lead->id && $e['stage'] === $name);
                        usort($matching, fn ($a, $b) => $b['at'] <=> $a['at']);

                        return $this->row($lead, $matching[0]['at'] ?? null, false);
                    })->values()->all();
            } else {
                $clients = $this->rows($this->latest($events, $period, $name));
            }
            $stages[] = ['stage' => $name, 'count' => count($clients), 'moved' => $period->isAll() ? 0 : count($clients), 'clients' => $clients];
        }
        $active = $this->latest($events, $period);
        $eventCount = count(array_filter($events, fn ($e) => $period->contains($e['at'])));
        $largest = null;
        foreach (array_unique(array_column($events, 'stage')) as $stage) {
            $count = count($this->latest($events, $period, $stage));
            if ($count > ($largest['count'] ?? 0)) {
                $largest = ['stage' => $stage, 'count' => $count, 'share' => count($active) ? round($count / count($active) * 100, 1) : 0];
            }
        }
        $missingStage = $members->filter(fn ($lead) => $this->label($lead->{$column}) === null)->count();
        $missingHistory = $members->filter(function ($lead) use ($column, $events) {
            $name = $this->label($lead->{$column});

            return $name !== null && ! collect($events)->contains(fn ($e) => $e['lead']->id === $lead->id && $e['stage'] === $name);
        })->count();
        $metrics = match ($key) {
            'education' => ['School Enrolment' => 'Enrolment stage', 'Proposal Sent' => 'Proposal stage', 'Started Course' => 'Course starts'],
            'english' => ['For PTE Exam' => 'PTE exam stage', 'For PTE Mocktest' => 'PTE mocktest stage', 'PTE Review' => 'PTE review stage'],
            'immigration' => ['Visa Lodged' => 'Lodged stage', 'Approved Visa' => 'Approved stage', 'Decline Visa' => 'Declined stage'],
        };

        return ['total' => $period->isAll() ? $members->count() : count($active), 'register_total' => $members->count(),
            'activity_total' => count($active), 'event_count' => $eventCount, 'moved' => count($active), 'scope' => $period->isAll() ? 'current' : 'activity', 'stages' => $stages,
            'insights' => ['metrics' => collect($metrics)->map(fn ($label, $stage) => $this->metric($stage, $label, $events, $period))->values()->all(),
                'largest' => $largest, 'active_students' => count($active), 'register_total' => $members->count(), 'missing_stage' => $missingStage, 'missing_history' => $missingHistory],
        ];
    }

    private function metric(string $stage, string $label, array $events, ReportPeriod $period): array
    {
        $current = $this->latest($events, $period, $stage);
        $previous = $period->isAll() ? [] : $this->latest($events, $period, $stage, true);
        $points = [];
        $priorStart = $period->start->subDays($period->days());
        for ($offset = 0; $offset < $period->days(); $offset += $period->bucketDays()) {
            $span = min($period->bucketDays(), $period->days() - $offset);
            $start = $period->start->addDays($offset);
            $end = $start->addDays($span);
            $prior = $priorStart->addDays($offset);
            $priorEnd = $prior->addDays($span);
            $points[] = ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(),
                'previous_from' => $prior->toDateString(), 'previous_to' => $priorEnd->subDay()->toDateString(),
                'count' => count(array_filter($current, fn ($e) => $e['at']->gte($start) && $e['at']->lt($end))),
                'previous_count' => count(array_filter($previous, fn ($e) => $e['at']->gte($prior) && $e['at']->lt($priorEnd)))];
        }

        return ['key' => $stage, 'label' => $label, 'count' => count($current), 'previous_count' => count($previous), 'points' => $points];
    }

    private function pipeline(ReportPeriod $period): array
    {
        $stages = array_values(array_diff(Lead::STAGES, self::HIDDEN_LEAD_STAGES));
        $byStage = [];
        if ($period->isAll()) {
            $leads = Lead::inLeadPipeline()->whereNull('english_stage')->whereNull('immigration_stage')
                ->where(fn ($q) => $q->where('stage', '!=', 'English Pro')->orWhereNull('stage'))
                ->where(fn ($q) => $q->whereNotIn('status', self::HIDDEN_LEAD_STAGES)->orWhereNull('status'))
                ->get(['id', 'first_name', 'last_name', 'status', 'referral', 'student_school']);
            foreach ($leads as $lead) {
                $byStage[$this->label($lead->status) ?? 'No status'][] = $this->row($lead, null, false);
            }
        } else {
            $logs = ActivityLog::where('action', 'lead.updated')
                ->where('created_at', '>=', $period->start->utc())->where('created_at', '<', $period->end->utc())
                ->where('created_at', '<=', $period->now->utc())
                ->where(fn ($q) => $q->whereNotNull('properties->changes->status')->orWhereNotNull('changes->status'))
                ->get(['id', 'properties', 'changes', 'entity_type', 'entity_id', 'created_at']);
            $candidates = [];
            foreach ($logs as $log) {
                $p = is_array($log->properties) ? $log->properties : [];
                $type = $p['subject_type'] ?? $log->entity_type;
                $id = $p['subject_id'] ?? $log->entity_id;
                $changes = $p['changes'] ?? $log->changes;
                $change = is_array($changes) ? ($changes['status'] ?? null) : null;
                $new = is_array($change) ? $this->label($change['new'] ?? null) : null;
                $at = ReportPeriod::eventDate($log->created_at);
                if (! in_array($type, ['Lead', Lead::class], true) || ! is_numeric($id) || $new === null || in_array($new, self::HIDDEN_LEAD_STAGES, true) || $new === ($change['old'] ?? null) || ! $at || ! $period->contains($at)) {
                    continue;
                }
                $key = (int) $id.'|'.$new;
                if (! isset($candidates[$key]) || $at->gt($candidates[$key]['at'])) {
                    $candidates[$key] = ['id' => (int) $id, 'stage' => $new, 'at' => $at];
                }
            }
            $leads = Lead::whereIn('id', array_column($candidates, 'id'))->get(['id', 'first_name', 'last_name', 'referral', 'student_school'])->keyBy('id');
            foreach ($candidates as $event) {
                if ($lead = $leads->get($event['id'])) {
                    $byStage[$event['stage']][] = $this->row($lead, $event['at'], true);
                }
            }
        }
        $ordered = array_values(array_unique(array_merge($stages, array_keys($byStage))));
        $pipeline = [];
        $register = [];
        foreach ($ordered as $name) {
            $rows = $byStage[$name] ?? [];
            if (! $rows) {
                continue;
            }
            usort($rows, fn ($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));
            $register[$name] = $rows;
            $pipeline[] = ['num' => count($pipeline) + 1, 'stage' => $name, 'count' => count($rows), 'moved' => $period->isAll() ? 0 : count($rows),
                'outside' => in_array($name, ['Not Qualified', 'Work Pathway / Other'], true)];
        }

        return [$pipeline, $register, ['source' => $period->isAll() ? 'current_register' : 'status_audit',
            'notice' => $period->isAll() ? 'Current Leads queue. Historical entry dates are not inferred.' : 'Recorded lead status-change audit events in these dates. Creation alone and missing legacy audit history are not stage movements; earlier recorded changes remain counted after conversion.']];
    }
}
