<?php

namespace App\Traits;

use App\Models\Event;
use App\Models\Lead;

/**
 * Shared lead-row builder used by Sales, Education, and Immigration portal
 * controllers so the Leads.jsx table receives an identical shape no matter
 * which portal rendered it.
 */
trait BuildsLeadRow
{
    /**
     * Visa-type names for the Leads table's inline "Visa" dropdown — the same
     * active catalogue the registration form offers. Alphabetical; the row's
     * own value ("Other" free-text visas) is preserved even when absent here.
     */
    protected function visaOptions(): array
    {
        return \App\Models\VisaType::where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    protected function leadRow(Lead $l): array
    {
        $ai = is_array($l->ai_analysis) ? $l->ai_analysis : [];

        $sourceLabel = $l->source
            ? $this->prettifySource((string) $l->source)
            : ($l->event ? "Event: {$l->event->name}" : ($l->branch ?: 'Online form'));

        // Pre-screen and goal-setting summaries are kept as note "kinds"
        // (see LeadNoteController). Surface the most-recent of each so
        // the leads index can show a compact at-a-glance row without
        // round-tripping into the lead detail page.
        $latestPreScreen = $l->relationLoaded('notes')
            ? $l->notes->where('kind', 'pre_screen')->sortByDesc('created_at')->first()
            : $l->notes()->where('kind', 'pre_screen')->latest()->first();
        $latestGoalSetting = $l->relationLoaded('notes')
            ? $l->notes->where('kind', 'goal_setting')->sortByDesc('created_at')->first()
            : $l->notes()->where('kind', 'goal_setting')->latest()->first();

        // The most recent note of ANY kind — drives the leads-table "Note"
        // column (who wrote it + a snippet).
        $latestNote = $l->relationLoaded('notes')
            ? $l->notes->sortByDesc('created_at')->first()
            : $l->notes()->latest()->first();

        $checklistTotals = $this->leadChecklistTotals($l);

        return [
            'id' => $l->id,
            'lead_id' => $l->lead_id,
            // Customer-shareable code — drives the "Copy tracking link"
            // action in the Leads row menu so staff can paste a
            // /track/{code} URL straight to the client.
            'tracking_code' => $l->tracking_code,
            'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
            // Profile picture — the applicant's uploaded Face image (null =
            // fall back to an initials avatar in the table).
            'avatar_url' => $l->faceImageUrl(),
            // Raw name parts for edit forms (the combined `name` is display-only).
            'first_name' => $l->first_name,
            'last_name' => $l->last_name,
            'email' => $l->email,
            'phone' => $l->phone,
            'country' => $l->residence_country ?: $l->country,
            // Raw location parts for edit forms (`location` is the combined label).
            'residence_city' => $l->residence_city,
            'residence_country' => $l->residence_country,
            // Combined "Location" column — city + country when both present.
            'location' => trim(implode(', ', array_filter([
                $l->residence_city,
                $l->residence_country ?: $l->country,
            ]))) ?: null,
            'priority' => $l->priority,
            // Free-form tags — drives the leads-table Tags filter.
            'tags' => ($l->relationLoaded('tags') ? $l->tags : $l->tags()->get())
                ->pluck('name')->filter()->values()->all(),
            'course' => optional($l->studyPlans->first())->preferred_course,
            'highest_qualification' => $l->highest_qualification,
            // Visa the applicant chose on the /register form (or later
            // captured by staff). Drives the Agent portal's "Visa" column
            // and any pipeline view that wants to segment by visa.
            'visa' => $l->inz_visa_type,
            'source' => $sourceLabel,
            'source_key' => $l->source,
            'status' => $l->status ?: 'New',
            'stage' => $l->stage,
            'ai_status' => $l->ai_analysis_status,
            'ai_score' => $ai['overall_score'] ?? null,
            'ai_pathway' => $ai['recommended_pathway'] ?? null,
            'ai_department' => $ai['recommended_department'] ?? null,
            'created_at' => $l->created_at,
            // "Updated" column — the last *staff* edit (any field), stamped
            // by Lead::stampLastActivity(). Falls back to the raw timestamp
            // and the last stage-mover for rows that predate the stamp.
            'updated_at' => $l->last_activity_at ?: $l->updated_at,
            'updated_by' => optional($l->lastActivityUser)->name
                                ?? optional($l->stageUpdater)->name,
            'updated_desc' => $l->last_activity_desc,
            // When staff last moved this lead through the pipeline. Drives
            // the default "actively-being-worked-on" sort on the Sales
            // Leads table so recently-advanced leads bubble to the top.
            'stage_updated_at' => $l->stage_updated_at,
            // Recruiting Agent who added this lead (null for staff-added
            // leads). Drives the Sales Leads "Agent" column + Agents tab.
            'agent' => $l->agent ? [
                'id' => $l->agent->id,
                'name' => $l->agent->name,
                'avatar_url' => $l->agent->avatar_url,
            ] : null,
            // Most-recent internal note (any kind) for the "Note" column.
            'latest_note' => $latestNote ? [
                'author' => $latestNote->author_name ?: 'Unknown',
                'body' => $latestNote->body,
                'when' => optional($latestNote->created_at)->toIso8601String(),
            ] : null,
            'portal_invitation_status' => $l->portal_invitation_status ?: 'none',
            'portal_last_login_at' => optional($l->portalUser)->last_login_at,

            // Activity counters — fed by withCount on the controller.
            'notes_count' => (int) ($l->notes_count ?? $l->notes()->count()),
            'tasks_open_count' => (int) ($l->tasks_open_count ?? $l->tasks()->where('completed', false)->count()),
            'documents_count' => (int) ($l->documents_count ?? $l->documents()->count()),

            // Docs progress vs the general checklist, mirroring the
            // immigration Cases column so the leads table shows a "%
            // submitted" summary. `checklist_total` excludes items staff
            // hid from this lead's tracker; `checklist_submitted` counts
            // unique checklist_keys that have a non-rejected doc.
            'checklist_total' => $checklistTotals['total'],
            'checklist_submitted' => $checklistTotals['submitted'],

            // Latest pre-screen and goal-setting notes for the expanded
            // row panel. Null when nothing has been captured yet.
            'latest_pre_screen' => $latestPreScreen ? [
                'author' => $latestPreScreen->pre_screened_by ?: $latestPreScreen->author_name,
                'mode' => $latestPreScreen->pre_screen_mode,
                'date' => optional($latestPreScreen->pre_screen_date)->toDateString(),
                'body' => $latestPreScreen->body,
                'when' => optional($latestPreScreen->created_at)->toIso8601String(),
            ] : null,
            'latest_goal_setting' => $latestGoalSetting ? [
                'status' => $latestGoalSetting->goal_setting_status,
                'by' => $latestGoalSetting->goal_setting_by ?: $latestGoalSetting->author_name,
                'body' => $latestGoalSetting->body,
                'when' => optional($latestGoalSetting->created_at)->toIso8601String(),
            ] : null,

            // Sales-dashboard mirror columns (flat for easy table rendering).
            'pre_screened_by' => $latestPreScreen?->pre_screened_by ?: $latestPreScreen?->author_name,
            'pre_screening_notes' => $latestPreScreen?->body,
            'goal_setting_by' => $latestGoalSetting?->goal_setting_by ?: $latestGoalSetting?->author_name,
            'goal_setting_status' => $latestGoalSetting?->goal_setting_status,
            'goal_setting_notes' => $latestGoalSetting?->body,
            'program_offered' => optional($l->studyPlans->first())->preferred_course,
            'calendar_date' => optional($l->calendar_date)->toDateString(),
            'client_info_link' => $l->client_info_link,
            'call_update_form_link' => $l->call_update_form_link,

            // Recent internal notes (all kinds) for the expander — so staff
            // see general notes alongside pre-screen / goal-setting captures.
            'recent_notes' => ($l->relationLoaded('notes') ? $l->notes : $l->notes()->latest()->limit(6)->get())
                ->sortByDesc('pinned')->sortByDesc('created_at')->take(6)->values()->map(fn ($n) => [
                    'id' => $n->id,
                    'kind' => $n->kind ?: 'general',
                    'body' => $n->body,
                    'programs' => $n->programs ?? [],
                    'author_name' => $n->author_name ?: 'Unknown',
                    'author_role' => $n->author_role,
                    'pinned' => (bool) $n->pinned,
                    'pre_screened_by' => $n->pre_screened_by,
                    'pre_screen_mode' => $n->pre_screen_mode,
                    'goal_setting_status' => $n->goal_setting_status,
                    'goal_setting_by' => $n->goal_setting_by,
                    'created_at' => optional($n->created_at)->toIso8601String(),
                ]),
        ];
    }

    protected function prettifySource(string $source): string
    {
        return ucwords(str_replace(['-', ':', '_'], [' ', ' / ', ' '], $source));
    }

    /**
     * Total vs. submitted checklist items for a lead, using the general
     * lead-documents checklist config. Items staff have hidden for this
     * lead (via `hidden_track_documents`) are excluded from `total`, so
     * the "%" the Leads table shows matches what the client actually sees
     * on the public tracker. Prefers the eager-loaded `documents`
     * relation to avoid N+1 in the leads list.
     */
    protected function leadChecklistTotals(Lead $l): array
    {
        static $allChecklistIds = null;
        if ($allChecklistIds === null) {
            $allChecklistIds = collect(config('lead_document_checklist.sections', []))
                ->flatMap(fn ($s) => collect($s['items'] ?? [])->pluck('id'))
                ->filter()
                ->unique()
                ->values();
        }

        $hidden = is_array($l->hidden_track_documents) ? $l->hidden_track_documents : [];
        $visible = $allChecklistIds->reject(fn ($k) => in_array($k, $hidden, true)
            || (! $l->is_immigration_case && $k === 'agree.proposal'));

        $submittedKeys = ($l->relationLoaded('documents') ? $l->documents : $l->documents()->get(['id', 'lead_id', 'checklist_key', 'status']))
            ->whereNotNull('checklist_key')
            ->whereIn('status', ['Submitted', 'UnderReview', 'Approved'])
            ->pluck('checklist_key')
            ->unique();

        return [
            'total' => $visible->count(),
            'submitted' => $visible->intersect($submittedKeys)->count(),
        ];
    }

    /**
     * Inertia props for the full-page "View registrants" screen — shared by
     * every portal so the registrants table + expandable dashboard panel
     * render identically to the Open-opportunities table. Callers add their
     * own `portalBase` (drives the row-action / back-link URLs).
     *
     * @return array{event: array, registrations: \Illuminate\Support\Collection, statuses: array}
     */
    protected function eventRegistrantsPayload(Event $event): array
    {
        $registrations = $event->leads()
            ->with([
                'studyPlans', 'tags:id,name', 'portalUser:id,lead_id,last_login_at',
                'stageUpdater:id,name', 'lastActivityUser:id,name', 'eventNotesEditor:id,name',
                'notes' => fn ($q) => $q->latest(),
            ])
            ->withCount(['notes', 'documents'])
            ->latest()
            ->get()
            ->map(fn (Lead $l) => array_merge($this->leadRow($l), [
                'event_notes' => $l->event_notes,
                'event_notes_updated_at' => optional($l->event_notes_updated_at)->toIso8601String(),
                'event_notes_editor' => $l->eventNotesEditor
                    ? ['id' => $l->eventNotesEditor->id, 'name' => $l->eventNotesEditor->name]
                    : null,
            ]));

        return [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_code' => $event->event_code,
                'type' => $event->type,
                'date_from' => optional($event->date_from)->toIso8601String(),
                'location' => $event->location,
                'mode' => $event->mode,
            ],
            'registrations' => $registrations,
            'statuses' => Lead::STAGES,
        ];
    }

    /**
     * "New sign-ups" counters for the Leads-page tab badges. Counts leads
     * that arrived via the registration form / an event in the last 7 days,
     * so staff see at a glance how many fresh registrations need attention.
     *
     * @return array{registration:int, events:int}
     */
    protected function leadTabCounts(): array
    {
        $since = now()->subDays(7);

        return [
            'registration' => Lead::where('source', 'registration')
                ->where('created_at', '>=', $since)->count(),
            'events' => Lead::whereNotNull('event_id')
                ->where('created_at', '>=', $since)->count(),
        ];
    }
}
