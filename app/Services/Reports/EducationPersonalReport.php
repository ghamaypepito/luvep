<?php

namespace App\Services\Reports;

use App\Models\ActivityLog;
use App\Models\EducationReportActivity;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EducationPersonalReport
{
    public const KINDS = [
        'called' => 'Clients called',
        'messaged' => 'Clients messaged',
        'enrolment' => 'Enrolments / additional enrolments',
        'offer' => 'Offers received',
        'proposal' => 'Proposals sent / additional proposals',
    ];

    public static function leadsOnly(): Builder
    {
        return Lead::inLeadPipeline()->whereNull('english_stage')->whereNull('immigration_stage')
            ->where(fn ($q) => $q->where('stage', '!=', 'English Pro')->orWhereNull('stage'))
            ->where(fn ($q) => $q->whereNotIn('status', ['English Pro', 'School Enrollment', 'Visa Process'])->orWhereNull('status'));
    }

    public static function clients(): Builder
    {
        return Lead::where(fn ($q) => $q->where('is_student', true)->orWhereIn('id', self::leadsOnly()->select('id')));
    }

    public static function noteKey(int $userId, string $monday): string
    {
        return "education_personal_report:{$userId}:{$monday}";
    }

    public function build(ReportPeriod $period, User $user): array
    {
        $base = EducationReportActivity::where('user_id', $user->id)
            ->where('occurred_on', '<', $period->now->addDay()->toDateString())
            ->when(! $period->isAll(), fn ($q) => $q->where('occurred_on', '>=', $period->start->toDateString())
                ->where('occurred_on', '<', $period->end->toDateString()));
        $counts = (clone $base)->selectRaw('kind, COUNT(*) AS total, COUNT(DISTINCT lead_id) AS clients')
            ->groupBy('kind')->get()->keyBy('kind');
        $entries = (clone $base)->with('lead:id,first_name,last_name')->orderByDesc('occurred_on')->orderByDesc('id');
        $page = (clone $entries)->paginate(30, ['*'], 'activity_page')->withQueryString();
        if ($page->currentPage() > $page->lastPage()) {
            // Removing the last row on the last page must not strand the reader.
            $page = (clone $entries)->paginate(30, ['*'], 'activity_page', $page->lastPage())->withQueryString();
        }
        $allowed = array_map('intval', self::clients()->whereIn('id', $page->pluck('lead_id')->filter())->pluck('id')->all());
        $page->through(fn ($entry) => [
            'id' => $entry->id, 'kind' => $entry->kind, 'date' => $entry->occurred_on->toDateString(),
            'client' => $entry->lead && in_array((int) $entry->lead_id, $allowed, true)
                ? trim($entry->lead->first_name.' '.$entry->lead->last_name) : 'Client no longer available in Education',
            'details' => $entry->details,
        ]);
        $week = ReportWeek::forPreset($period->preset, $period->now) ?? ReportWeek::forPreset('this_week', $period->now);
        $monday = $week->start->toDateString();
        $notes = Setting::get(self::noteKey($user->id, $monday), []);
        $notes = is_array($notes) ? $notes : [];
        $fields = [];
        foreach (['accomplishments', 'other_activities', 'next_week_tasks'] as $field) {
            $fields[$field] = is_string($notes[$field] ?? null) ? $notes[$field] : '';
        }

        return [
            'kinds' => self::KINDS, 'entries' => $page,
            'counts' => collect(self::KINDS)->map(fn ($label, $kind) => [
                'label' => $label, 'events' => (int) ($counts[$kind]->total ?? 0),
                'clients' => (int) ($counts[$kind]->clients ?? 0),
            ])->all(),
            'contacts' => $this->contacts($period, $user),
            'notes' => $fields, 'notes_week_start' => $monday,
            'today' => $period->now->toDateString(),
        ];
    }

    private function contacts(ReportPeriod $period, User $user): array
    {
        $result = ['Contact Attempted' => [], 'Contacted' => []];
        $logs = ActivityLog::where('action', 'lead.updated')->where('user_id', $user->id)
            ->when(! $period->isAll(), fn ($q) => $q->where('created_at', '>=', $period->start->utc())
                ->where('created_at', '<', $period->end->utc()))
            ->where('created_at', '<=', $period->now->utc())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['properties', 'changes', 'entity_type', 'entity_id', 'created_at']);
        $events = [];
        foreach ($logs as $log) {
            $properties = is_array($log->properties) ? $log->properties : [];
            $type = $properties['subject_type'] ?? $log->entity_type;
            $id = $properties['subject_id'] ?? $log->entity_id;
            $changes = $properties['changes'] ?? $log->changes;
            $change = is_array($changes) ? ($changes['status'] ?? null) : null;
            if (! in_array($type, ['Lead', Lead::class], true) || ! is_numeric($id) || ! is_array($change)) {
                continue;
            }
            $new = $change['new'] ?? null;
            $label = match ($new) {
                'Contact Attempted' => 'Contact Attempted',
                'Contacted', 'Contacted for Booking' => 'Contacted',
                default => null,
            };
            if ($label && $new !== ($change['old'] ?? null)) {
                $key = $label.'|'.(int) $id;
                $events[$key] ??= ['id' => (int) $id, 'label' => $label, 'date' => $log->created_at->toIso8601String()];
            }
        }
        $leads = self::leadsOnly()->whereIn('id', array_column($events, 'id'))
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        foreach ($events as $event) {
            if ($lead = $leads->get($event['id'])) {
                $result[$event['label']][] = ['id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name), 'date' => $event['date']];
            }
        }

        return $result;
    }
}
