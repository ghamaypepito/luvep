<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\MessageLog;
use App\Models\PartnerActivityFile;
use App\Models\PartnerActivityRecord;
use App\Models\User;
use App\Support\LeadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PartnerActivityTracker
{
    public const STATUSES = [
        'email' => ['awaiting_response', 'responded', 'no_response'],
        'agreement' => ['draft', 'sent', 'under_review', 'signed', 'updated', 'expired'],
        'training' => ['scheduled', 'completed', 'pending'],
        'follow_up' => ['pending', 'completed'],
    ];

    public function authorize(Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && ($actor->isAdmin() || $actor->role === 'education')
            && $actor->canSeeModule('agents') && ! $request->attributes->get('user_view_actor'), 403);
    }

    public function installed(): bool
    {
        return Schema::hasTable('partner_activity_records') && Schema::hasTable('partner_activity_files');
    }

    public function staff(): Collection
    {
        return User::where('is_active', true)->whereIn('role', ['super_admin', 'admin', 'education'])->get(['id', 'name', 'role', 'module_permissions'])
            ->filter(fn ($u) => $u->canSeeModule('agents'))->values();
    }

    private function visibleLead(User $actor, ?Lead $lead): bool
    {
        return $lead && LeadAccess::canView($actor, $lead);
    }

    private function date($value): ?string
    {
        return $value ? Carbon::parse($value)->timezone(config('app.timezone'))->toDateString() : null;
    }

    /** Source IDs, not names/emails, establish automatic attribution. No writes or sends on reads. */
    public function sources(User $actor): Collection
    {
        $partners = User::withTrashed()->where('role', 'agent')->get(['id', 'name', 'deleted_at'])->keyBy('id');
        $rows = collect();
        $eventsByAgreement = ActivityLog::where('action', 'like', 'affiliate.agreement.%')->get()->groupBy(fn ($event) => $event->properties['agreement_id'] ?? 0);
        $agreements = AgentAgreement::whereIn('agent_id', $partners->keys())->get();
        $currentVersions = $agreements->groupBy('agent_id')->map(fn ($group) => ($group->where('is_draft', false)->sortByDesc('version')->first() ?? $group->sortByDesc('version')->first())->id);
        foreach ($agreements as $a) {
            $rows->push([
                'source_key' => 'agreement:'.$a->id, 'agent_id' => $a->agent_id, 'category' => 'agreement',
                'title' => 'Portal agreement v'.$a->version, 'status' => strtolower(str_replace(' ', '_', $a->statusLabel())),
                'activity_on' => $this->date($a->updated_at), 'follow_up_on' => null, 'assignee_id' => $a->generated_by, 'lead_id' => null,
                'updated_at' => $a->updated_at?->toIso8601String(), 'state_only' => true,
                'details' => ['sent_on' => $this->date($a->sent_at), 'received_on' => $this->date($a->agent_signed_at),
                    'expiry_on' => $this->date($a->expires_on), 'version' => $a->version, 'draft' => $a->is_draft, 'current' => $currentVersions->get($a->agent_id) === $a->id,
                    'both_signed' => $a->isSignedByAgent() && $a->isSignedByCompany(),
                    'document_url' => $a->file_path && Storage::disk('local')->exists($a->file_path)
                        ? '/portal/education/partner-tracker/agreements/'.$a->id.'/document' : null],
            ]);
            $events = $eventsByAgreement->get($a->id, collect());
            // Existing audit events are the dated timeline; legacy timestamp fallbacks are explicitly labelled.
            if ($events->isNotEmpty()) {
                foreach ($events as $event) {
                    $rows->push(['source_key' => 'agreement-event:'.$event->id, 'agent_id' => $a->agent_id, 'category' => 'agreement',
                        'title' => 'Agreement v'.$a->version.' — '.str_replace('_', ' ', str_replace('affiliate.agreement.', '', $event->action)),
                        'status' => 'recorded', 'activity_on' => $this->date($event->created_at), 'follow_up_on' => null,
                        'assignee_id' => $a->generated_by, 'lead_id' => null, 'updated_at' => $event->created_at?->toIso8601String(),
                        'details' => ['agreement_id' => $a->id, 'event' => $event->action, 'recorded_actor_id' => $event->user_id, 'remarks' => 'Recorded portal audit event.'], 'event_only' => true]);
                }
            } else {
                foreach (['generated' => $a->generated_at ?? $a->created_at, 'sent' => $a->sent_at,
                    'agent_signed' => $a->agent_signed_at, 'company_signed' => $a->company_signed_at] as $event => $at) {
                    if ($at) {
                        $rows->push(['source_key' => 'agreement-legacy:'.$a->id.':'.$event, 'agent_id' => $a->agent_id, 'category' => 'agreement',
                            'title' => 'Agreement v'.$a->version.' — '.str_replace('_', ' ', $event), 'status' => 'recorded',
                            'activity_on' => $this->date($at), 'follow_up_on' => null, 'assignee_id' => $a->generated_by,
                            'lead_id' => null, 'updated_at' => $at->toIso8601String(), 'event_only' => true,
                            'details' => ['agreement_id' => $a->id, 'remarks' => 'Legacy source timestamp; actor may be unknown.']]);
                    }
                }
            }
        }
        $messages = MessageLog::where('channel', 'email')->where('source', 'partner-tracker')->whereIn('status', ['sent', 'bounced'])->where('recipient_type', 'user')->whereNotNull('sent_at')
            ->whereIn('recipient_id', $partners->keys())->get();
        foreach ($messages as $m) {
            $rows->push(['source_key' => 'message:'.$m->id, 'agent_id' => $m->recipient_id, 'category' => 'email',
                'title' => $m->subject ?: '(No subject)', 'status' => 'awaiting_response', 'activity_on' => $this->date($m->sent_at),
                'follow_up_on' => null, 'assignee_id' => $m->triggered_by_user_id, 'lead_id' => null,
                'updated_at' => $m->updated_at?->toIso8601String(), 'details' => ['delivery' => $m->status, 'remarks' => 'Reply status requires staff confirmation.']]);
        }
        $tasks = LeadTask::where('type', 'follow_up')->whereNotNull('lead_id');
        if (! $actor->isAdmin()) {
            $tasks->whereHas('lead', fn ($lead) => $lead->where('is_student', true))
                ->where(fn ($visible) => $visible->where('department', $actor->role)
                    ->orWhere('created_by', $actor->id)
                    ->orWhere(fn ($assigned) => $assigned->assignedTo($actor->id)));
        }
        foreach ($tasks->with('lead:id,first_name,last_name,agent_id,original_affiliate_id,is_student,is_english_student,is_immigration_case,is_accommodation_client,education_stage,status')->get() as $task) {
            if (! $this->visibleLead($actor, $task->lead)) {
                continue;
            }
            $partner = $task->lead->original_affiliate_id ?? $task->lead->agent_id;
            if (! $partners->has($partner)) {
                continue;
            }
            $rows->push(['source_key' => 'task:'.$task->id, 'agent_id' => $partner, 'category' => 'follow_up',
                'title' => $task->title, 'status' => $task->completed ? 'completed' : 'pending',
                'activity_on' => $this->date($task->completed ? $task->completed_at : $task->created_at),
                'follow_up_on' => $this->date($task->due_at), 'assignee_id' => $task->assignee_id, 'lead_id' => $task->lead_id,
                'updated_at' => $task->updated_at?->toIso8601String(),
                'details' => ['client_name' => trim($task->lead->first_name.' '.$task->lead->last_name),
                    'application_status_current' => $task->lead->education_stage ?: $task->lead->status,
                    'purpose' => $task->description, 'outcome' => $task->completion_notes, 'method' => 'Existing task',
                    'task_url' => '/portal/education/tasks']]);
        }

        return $rows->keyBy('source_key');
    }

    public function rows(User $actor): Collection
    {
        $rows = $this->sources($actor);
        if (! $this->installed()) {
            return $rows->map(fn ($r) => $r + ['automatic' => true, 'version' => '', 'files' => []])->values();
        }
        $files = PartnerActivityFile::get(['id', 'activity_id', 'name', 'size'])->groupBy('activity_id');
        $records = PartnerActivityRecord::all();
        $linkedLeads = Lead::whereIn('id', $records->pluck('lead_id')->filter()->unique())->get(['id', 'is_student', 'is_english_student', 'is_immigration_case', 'is_accommodation_client'])->keyBy('id');
        foreach ($records as $record) {
            if ($record->lead_id && ! $this->visibleLead($actor, $linkedLeads->get($record->lead_id))) {
                continue;
            }
            if (str_starts_with($record->source_key, 'manual:')) {
                $row = $record->only(['source_key', 'agent_id', 'category', 'title', 'status', 'assignee_id', 'lead_id', 'details']);
                $row['activity_on'] = $record->activity_on->toDateString();
                $row['follow_up_on'] = $record->follow_up_on?->toDateString();
                $row['automatic'] = false;
                $row['updated_at'] = $record->updated_at->toIso8601String();
            } else {
                $row = $rows->get($record->source_key);
                if (! $row || ($row['event_only'] ?? false)) {
                    continue; // Lost source access never falls back to a confidential stored snapshot.
                }
                $row['details'] = array_merge($row['details'], $record->details ?? []);
                if ($row['category'] === 'email') {
                    $row['status'] = $record->status;
                }
                if ($row['category'] !== 'follow_up') {
                    $row['assignee_id'] = $record->assignee_id;
                    $row['follow_up_on'] = $record->follow_up_on?->toDateString();
                }
            }
            $row['updated_at'] = max($row['updated_at'] ?? '', $record->updated_at->toIso8601String());
            $row['record_id'] = $record->id;
            $row['version'] = $record->version();
            $row['files'] = ($files->get($record->id) ?? collect())->map(fn ($f) => ['id' => $f->id, 'name' => $f->name,
                'url' => '/portal/education/partner-tracker/files/'.$f->id])->values()->all();
            $rows->put($record->source_key, $row);
        }

        return $rows->map(fn ($r) => $r + ['automatic' => true, 'version' => '', 'files' => []])->values();
    }

    public function report(Request $request): array
    {
        $filters = $request->validate(['agent_id' => 'nullable|integer', 'category' => 'nullable|in:email,agreement,training,follow_up',
            'assignee_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'renewal_days' => 'nullable|integer|min:1|max:365', 'period' => 'nullable|in:week,month,custom']);
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $period = $filters['period'] ?? 'month';
        $from = $filters['from'] ?? ($period === 'week' ? $today->copy()->startOfWeek()->toDateString() : $today->copy()->startOfMonth()->toDateString());
        $to = $filters['to'] ?? $today->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'End date must be on or after start date.']);
        }
        $partners = User::withTrashed()->where('role', 'agent')->get(['id', 'name', 'deleted_at'])->keyBy('id');
        $staff = User::withTrashed()->get(['id', 'name'])->keyBy('id');
        $allRows = $this->rows($request->user());
        $currentAgreementKeys = $allRows->filter(fn ($r) => ($r['state_only'] ?? false))->groupBy('agent_id')
            ->map(fn ($group) => ($group->filter(fn ($r) => ! ($r['details']['draft'] ?? false))->sortByDesc('details.version')->first() ?? $group->sortByDesc('details.version')->first())['source_key']);
        $rows = $allRows->filter(fn ($r) => $partners->has($r['agent_id']))
            ->filter(fn ($r) => empty($filters['agent_id']) || $r['agent_id'] == $filters['agent_id'])
            ->filter(fn ($r) => empty($filters['category']) || $r['category'] === $filters['category'])
            ->filter(fn ($r) => empty($filters['assignee_id']) || $r['assignee_id'] == $filters['assignee_id'])
            ->map(function ($r) use ($partners, $staff, $today) {
                if ($r['category'] === 'agreement' && $r['status'] !== 'draft' && ! empty($r['details']['expiry_on']) && $r['details']['expiry_on'] < $today->toDateString()) {
                    $r['details']['recorded_status'] = $r['status'];
                    $r['status'] = 'expired';
                }
                $r['partner'] = $partners[$r['agent_id']]->name;
                $r['staff'] = $staff->get($r['assignee_id'])?->name;
                $r['overdue'] = $r['follow_up_on'] && $r['follow_up_on'] < $today->toDateString()
                    && ! in_array($r['status'], ['completed', 'responded', 'signed', 'expired'], true) && ! ($r['event_only'] ?? false)
                    && ! (($r['state_only'] ?? false) && ! ($r['details']['current'] ?? true));

                return $r;
            });
        $agreements = $rows->where('category', 'agreement')->filter(fn ($r) => ($r['state_only'] ?? false) || ! $r['automatic']);
        $latest = $agreements->filter(fn ($r) => $r['automatic'] && $currentAgreementKeys->get($r['agent_id']) === $r['source_key'])
            ->values()->merge($agreements->where('automatic', false));
        $active = fn ($r) => empty($r['details']['expiry_on']) || $r['details']['expiry_on'] >= $today->toDateString();
        $inPeriod = $rows->filter(fn ($r) => ! ($r['state_only'] ?? false) && $r['activity_on'] >= $from && $r['activity_on'] <= $to)->sortByDesc('activity_on')->values();
        $days = (int) ($filters['renewal_days'] ?? 30);
        $pending = $rows->where('category', 'follow_up')->where('status', 'pending');

        return ['rows' => $inPeriod, 'agreements' => $agreements->values(), 'workload' => $rows->where('overdue', true)->values(),
            'summary' => ['registered_partners' => $partners->filter(fn ($p) => ! $p->trashed())->when(! empty($filters['agent_id']), fn ($p) => $p->where('id', $filters['agent_id']))->count(),
                'agreements_pending' => $latest->filter(fn ($r) => $active($r) && in_array($r['status'], ['sent', 'under_review', 'pending_signature'], true))->count(),
                'agreements_signed' => $latest->filter(fn ($r) => $active($r) && $r['status'] === 'signed')->count(),
                'renewals_due' => $latest->filter(fn ($r) => $r['status'] === 'signed' && ! empty($r['details']['expiry_on']) && $r['details']['expiry_on'] >= $today->toDateString() && $r['details']['expiry_on'] <= $today->copy()->addDays($days)->toDateString())->count(),
                'agents_trained' => $inPeriod->where('category', 'training')->where('status', 'completed')->filter(fn ($r) => ($r['details']['attendance'] ?? null) === 'attended')->pluck('agent_id')->unique()->count(),
                'training_pending' => $rows->where('category', 'training')->whereIn('status', ['scheduled', 'pending'])->count(),
                'follow_ups_completed' => $inPeriod->where('category', 'follow_up')->where('status', 'completed')->count(),
                'follow_ups_pending' => $pending->count(), 'follow_ups_overdue' => $pending->where('overdue', true)->count()],
            'filters' => array_merge(['agent_id' => '', 'category' => '', 'assignee_id' => ''], $filters, ['from' => $from, 'to' => $to, 'period' => $period, 'renewal_days' => $days]),
            'partners' => $partners->values()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'archived' => $p->trashed()]),
            'staff' => $this->staff()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'today' => $today->toDateString(), 'statuses' => self::STATUSES, 'installed' => $this->installed(), 'timezone' => config('app.timezone')];
    }
}
