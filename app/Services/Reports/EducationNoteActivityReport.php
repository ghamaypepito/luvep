<?php

namespace App\Services\Reports;

use App\Models\EducationNoteActivity;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Http\Request;

/** Immutable posting events, separate from student stages and manual accomplishments. */
class EducationNoteActivityReport
{
    public static function isEducationPost(Request $request): bool
    {
        return $request->is('portal/education/*')
            || ($request->is('admin/leads/*') && $request->user()?->role === 'education');
    }

    public static function clients()
    {
        // Match the records Education's discussion access exposes.
        return Lead::where(fn ($q) => $q->where(fn ($p) => $p->inLeadPipeline())
            ->orWhere(fn ($s) => $s->inStudentsRegister()));
    }

    public function record(Request $request, Lead $lead, LeadNote $note, string $activity): void
    {
        if (! self::isEducationPost($request) || ! ReportWeek::canUseNotes($request->user())
            || ! $request->user()->canAccessPortal('education') || ! self::clients()->whereKey($lead->id)->exists()) {
            return;
        }
        // Support's "view as user" must not credit the impersonated member.
        $actor = $request->attributes->get('user_view_actor') ?? $request->user();
        EducationNoteActivity::firstOrCreate(['note_id' => $note->id], [
            'lead_id' => $lead->id, 'user_id' => $actor->id, 'author_name' => $actor->name,
            'activity' => $activity, 'is_reply' => $note->parent_id !== null, 'posted_at' => $note->created_at,
        ]);
    }

    public function build(ReportPeriod $period, ?User $user): ?array
    {
        if (! ReportWeek::canUseNotes($user) || ! $user->canAccessPortal('education')) {
            return null;
        }
        $base = EducationNoteActivity::where('user_id', $user->id)
            ->whereIn('lead_id', self::clients()->select('id'))
            ->where('posted_at', '<=', $period->now->utc())
            ->when(! $period->isAll(), fn ($q) => $q->where('posted_at', '>=', $period->start->utc())
                ->where('posted_at', '<', $period->end->utc()));
        $counts = (clone $base)->selectRaw('activity, COUNT(*) AS total, COUNT(DISTINCT lead_id) AS clients')
            ->groupBy('activity')->get()->keyBy('activity');
        $query = (clone $base)->with(['lead:id,first_name,last_name', 'note:id'])
            ->orderByDesc('posted_at')->orderByDesc('id');
        $page = (clone $query)->paginate(30, ['*'], 'note_page')->withQueryString();
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $query)->paginate(30, ['*'], 'note_page', $page->lastPage())->withQueryString();
        }
        return [
            'total' => (clone $base)->count(), 'clients' => (clone $base)->distinct()->count('lead_id'),
            'counts' => collect(['update' => 'Client updates', 'follow_up' => 'Follow-ups'])->map(fn ($label, $kind) => [
                'label' => $label, 'events' => (int) ($counts[$kind]->total ?? 0),
                'clients' => (int) ($counts[$kind]->clients ?? 0),
            ])->all(),
            'entries' => $page->through(fn ($event) => [
                'id' => $event->id, 'client' => trim($event->lead->first_name.' '.$event->lead->last_name),
                'url' => "/portal/education/leads/{$event->lead_id}?tab=activity",
                'author' => $event->author_name, 'activity' => $event->activity,
                'posted_at' => $event->posted_at->toIso8601String(),
                'is_reply' => $event->is_reply, 'note_available' => $event->note !== null,
            ]),
        ];
    }
}
