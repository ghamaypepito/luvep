<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\ResidentIntake;
use App\Models\User;
use App\Models\UserReview;
use App\Traits\BuildsLeadRow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ImmigrationController extends Controller
{
    use BuildsLeadRow;

    private const LEAD_STATUSES = Lead::STAGES;

    /** Free-assessment Personal-detail fields that map 1:1 to Lead columns and
     *  are therefore inline-editable from the assessment modal. */
    private const FREE_EDITABLE_COLUMNS = [
        'dob', 'gender', 'marital_status', 'other_names', 'country_of_birth',
        'place_of_birth', 'citizenship', 'residence_city', 'residence_state',
        'residence_country', 'has_passport', 'passport_number', 'passport_expiry',
    ];

    /**
     * Immigration dashboard — adviser-focused. Top tiles show "what's on my
     * plate today", visa-case pipeline + INZ aging surface the active work,
     * and the public-submissions strip preserves the historical view.
     */
    public function dashboard()
    {
        try {
            $now = now();
            $weekStart = $now->copy()->startOfWeek();
            $monthStart = $now->copy()->startOfMonth();

            // ── Top tiles ──────────────────────────────────────────────────
            // A lead is an immigration case if the team explicitly opened
            // one (is_immigration_case) OR the Education team's hand-off
            // stage was set to one of the immigration stages — see
            // Lead::scopeImmigrationCase().
            $activeCases = Lead::immigrationCase()->count();
            $newAssessmentsThisWeek = ResidentIntake::where('created_at', '>=', $weekStart)->count();
            $bookingsPaidUnseen = Booking::where('status', 'Confirmed')->whereNull('appointment_date')->count();
            $docsPendingReview = LeadDocument::whereIn('status', ['Submitted', 'UnderReview'])->count();
            $casesLodged = Lead::immigrationCase()->whereIn('inz_status', ['Lodged', 'Decision Pending', 'Info Requested'])->count();
            $infoRequests = Lead::immigrationCase()->where('inz_status', 'Info Requested')->count();

            // ── INZ pipeline aging (green / amber / red) ─────────────────
            $visaTypes = \App\Models\VisaType::pluck('expected_processing_days', 'name')->all();
            $defaultWindow = 40; // fallback when visa-type not in catalog
            $inzCases = Lead::immigrationCase()
                ->whereIn('inz_status', ['Lodged', 'Decision Pending', 'Info Requested'])
                ->whereNotNull('inz_lodged_at')
                ->get(['id', 'lead_id', 'first_name', 'last_name', 'inz_visa_type', 'inz_lodged_at', 'inz_status']);

            $inzAging = ['green' => 0, 'amber' => 0, 'red' => 0, 'rows' => []];
            foreach ($inzCases as $c) {
                $window = $visaTypes[$c->inz_visa_type] ?? $defaultWindow;
                $daysSince = (int) \Illuminate\Support\Carbon::parse($c->inz_lodged_at)->diffInDays(now());
                $bucket = $daysSince > $window ? 'red'
                    : ($daysSince >= ($window - 5) ? 'amber' : 'green');
                $inzAging[$bucket]++;
                $inzAging['rows'][] = [
                    'id' => $c->id,
                    'lead_id' => $c->lead_id,
                    'name' => trim("{$c->first_name} {$c->last_name}") ?: 'Unknown',
                    'visa_type' => $c->inz_visa_type,
                    'lodged_at' => $c->inz_lodged_at,
                    'days_since' => $daysSince,
                    'expected_days' => $window,
                    'bucket' => $bucket,
                    'status' => $c->inz_status,
                ];
            }
            // Sort rows by aging (worst first).
            usort($inzAging['rows'], fn ($a, $b) => $b['days_since'] <=> $a['days_since']);

            // ── IAA compliance — current user's licence status ──────────
            $me = auth()->user();
            $iaa = null;
            if ($me) {
                $expiry = $me->iaa_licence_expiry;
                $iaa = [
                    'licence_number' => $me->iaa_licence_number,
                    'expiry' => $expiry ? $expiry->toDateString() : null,
                    'days_to_expiry' => $expiry ? (int) now()->diffInDays($expiry, false) : null,
                    'status' => ! $me->iaa_licence_number ? 'missing'
                        : (! $expiry ? 'no_expiry'
                            : ((int) now()->diffInDays($expiry, false) < 0 ? 'expired'
                                : ((int) now()->diffInDays($expiry, false) <= 60 ? 'expiring' : 'ok'))),
                ];
            }

            // ── 6-month intakes trend (kept from old dashboard) ────────────
            $monthly = [];
            for ($i = 5; $i >= 0; $i--) {
                $mStart = $now->copy()->subMonths($i)->startOfMonth();
                $mEnd = $now->copy()->subMonths($i)->endOfMonth();
                $monthly[] = [
                    'label' => $mStart->format('M'),
                    'intakes' => ResidentIntake::whereBetween('created_at', [$mStart, $mEnd])->count(),
                ];
            }

            // ── Visa-case pipeline — leads in any "visa-touching" stage ────
            // We don't have separate INZ statuses yet; the closest proxy is
            // the lead's pipeline status. Frontend flags as needing infra.
            $visaStages = ['Visa Process', 'Consultancy Agreement', 'English Pro', 'School Enrollment'];
            $pipeline = collect($visaStages)->map(fn ($s) => [
                'stage' => $s,
                'count' => Lead::where('status', $s)->count(),
            ])->all();

            // ── Urgent actions feed ────────────────────────────────────────
            $urgent = [
                'assessments_pending' => ResidentIntake::whereIn('status', ['New', null])->count(),
                'paid_unscheduled' => $bookingsPaidUnseen,
                'rejected_docs' => LeadDocument::where('status', 'Rejected')->where('reviewed_at', '>', $now->copy()->subDays(14))->count(),
                'agreements_pending' => Lead::where('status', 'Consultancy Agreement')->count(),
            ];

            // ── This week's appointments ───────────────────────────────────
            $weekEnd = $now->copy()->endOfWeek();
            $weekAppts = Booking::whereBetween('appointment_date', [$weekStart, $weekEnd])
                ->orderBy('appointment_date')->orderBy('appointment_time')
                ->limit(8)->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => trim("{$b->first_name} {$b->last_name}") ?: 'Unknown',
                    'service_type' => $b->service_type,
                    'consultant_name' => $b->consultant_name,
                    'platform' => $b->platform,
                    'status' => $b->status ?: 'Pending',
                    'appointment_date' => $b->appointment_date ? \Illuminate\Support\Carbon::parse($b->appointment_date)->toDateString() : null,
                    'appointment_time' => $b->appointment_time,
                ]);

            return inertia('portal/immigration/Dashboard', [
                'tiles' => [
                    'active_cases' => $activeCases,
                    'new_assessments_week' => $newAssessmentsThisWeek,
                    'bookings_paid_unseen' => $bookingsPaidUnseen,
                    'docs_pending_review' => $docsPendingReview,
                    'cases_lodged' => $casesLodged,
                    'info_requests_outstanding' => $infoRequests,
                ],
                'pipeline' => $pipeline,
                'inz_aging' => $inzAging,
                'iaa' => $iaa,
                'monthly' => $monthly,
                'urgent' => $urgent,
                'week_appointments' => $weekAppts,
                'recent_intakes' => ResidentIntake::latest()->take(5)->get([
                    'id', 'intake_id', 'first_name', 'last_name', 'email',
                    'current_visa_type', 'status', 'created_at',
                ]),
                'recent_reviews' => UserReview::latest()->take(5)->get([
                    'id', 'review_id', 'name', 'email', 'mode', 'status', 'created_at',
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Immigration dashboard failed', ['error' => $e->getMessage()]);

            return inertia('portal/immigration/Dashboard', [
                'tiles' => [], 'pipeline' => [], 'monthly' => [], 'urgent' => [],
                'week_appointments' => [], 'recent_intakes' => [], 'recent_reviews' => [],
            ]);
        }
    }

    /**
     * Leads queue — Immigration's pre-engagement-fee leads. Same shape as
     * Sales / Education so the shared Leads.jsx renders identically.
     */
    public function leads(string $page = 'portal/immigration/Leads', string $portal = 'immigration')
    {
        try {
            return inertia($page, [
                'portal' => $portal,
                'statuses' => self::LEAD_STATUSES,
                // Pipeline only — converted leads (cases) move to the Cases page.
                'leads' => Lead::inLeadPipeline()
                    ->with([
                        'studyPlans', 'event', 'tags:id,name', 'portalUser:id,lead_id,last_login_at',
                        'stageUpdater:id,name', 'agent:id,name,avatar_path', 'notes' => fn ($q) => $q->latest(),
                        // Doc rows drive the "Docs progress" column in the
                        // leads table (via BuildsLeadRow::leadChecklistTotals).
                        'documents:id,lead_id,checklist_key,status',
                    ])
                    ->withCount(['notes', 'documents'])
                    ->latest()->get()->map(fn ($l) => $this->leadRow($l)),
                // Full tag dictionary — the Leads-page Tag filter lists every
                // tag ever created, not just the ones on visible leads.
                'allTagNames' => \App\Models\LeadTag::orderBy('name')->pluck('name'),
                'events' => $this->eventsSummary(),
                'tabCounts' => $this->leadTabCounts(),
                'visaOptions' => $this->visaOptions(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Immigration leads list failed', ['error' => $e->getMessage()]);

            return inertia($page, [
                'portal' => $portal, 'statuses' => self::LEAD_STATUSES, 'leads' => collect(),
            ]);
        }
    }

    public function updateLead(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::LEAD_STATUSES)],
        ]);
        try {
            $lead = Lead::findOrFail($id);
            $lead->status = $validated['status'];
            $lead->save();

            return back()->with('success', "Lead {$lead->lead_id} updated.");
        } catch (\Throwable $e) {
            Log::error('Immigration lead update failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not update that lead. Please try again.');
        }
    }

    /** Events list for the Leads page "Events" tab — each with a registrant count. */
    private function eventsSummary()
    {
        return Event::withCount('leads')
            ->with('agent:id,name')
            ->orderByDesc('date_from')
            ->latest()
            ->get()
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'event_code' => $e->event_code,
                'type' => $e->type,
                'mode' => $e->mode,
                'location' => $e->location,
                'date_from' => optional($e->date_from)->toIso8601String(),
                'status' => $e->status,
                'agent' => optional($e->agent)->name,
                'registrations_count' => $e->leads_count,
            ]);
    }

    /** GET /portal/immigration/events/{id}/registrations — registrants drawer. */
    public function eventRegistrations($id)
    {
        $event = Event::findOrFail($id);

        $registrations = $event->leads()
            ->latest()
            ->get()
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unnamed lead',
                'email' => $l->email,
                'phone' => $l->phone,
                'status' => $l->status,
                'created_at' => optional($l->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'event' => ['id' => $event->id, 'name' => $event->name],
            'registrations' => $registrations,
        ]);
    }

    /** GET /portal/immigration/events/{id}/registrants — full-page registrants view. */
    public function eventRegistrantsPage($id)
    {
        $event = Event::findOrFail($id);

        return inertia('portal/immigration/EventRegistrants', array_merge(
            $this->eventRegistrantsPayload($event),
            ['portalBase' => '/portal/immigration']
        ));
    }

    /**
     * Cases — leads that have engaged Immigration (currently proxied via
     * Visa Process stage; a dedicated is_immigration_case flag is the next
     * piece of infra to add, mirroring is_student).
     */
    public function cases()
    {
        return inertia('portal/immigration/Cases', $this->casesPayload());
    }

    /**
     * The full Cases-page payload (distribution, priorities, per-case rows).
     * Extracted so other portals (e.g. the adviser portal) can render the exact
     * same List of Cases UI. An optional $scope closure narrows the case query
     * (and its total) — e.g. to a single owner for "My Cases".
     */
    public function casesPayload(?\Closure $scope = null): array
    {
        try {
            // Preload each visa type's checklist so per-case document
            // progress can be measured against the required checklist items
            // (how many the case has actually submitted), not the raw
            // upload count.
            $visaChecklists = \App\Models\VisaType::query()
                ->get(['name', 'checklist_items'])
                ->mapWithKeys(fn ($v) => [$v->name => (is_array($v->checklist_items) ? $v->checklist_items : [])]);

            // Build 12 phase 6 — open, answer-requiring threads addressed to the
            // viewer. These land the case in their queue even when they don't own
            // it, so the My-queue filter can surface "someone asked you" cases.
            $awaitingByLead = \App\Models\CaseThread::awaitingCountsFor((int) auth()->id());

            // Build 12 phase 4 — attention (§5). Latest LICENSED-user open per
            // case, so the board chip reads "has the adviser looked, and when".
            // Unlicensed opens never count. Staff-only signal.
            $attentionByLead = \App\Models\CaseView::latestLicensedOpens();

            // The full immigration-case count, for an honest "showing X of Y"
            // and to detect the (rare) case where we hit the safety ceiling.
            $total = Lead::immigrationCase()->when($scope, $scope)->count();

            // Safety ceiling only — NOT a page size. The old hard `limit(200)`
            // silently dropped the least-recently-active cases: "For Assessment"
            // cases sit untouched at the start of the pipeline, so every stage
            // change on another case pushed them further down and, past 200,
            // out of the list entirely — with client-side search unable to reach
            // them. Load the whole queue (the scope already bounds it) so the
            // board, its counts and its search see every case. If a tenant ever
            // exceeds this ceiling the frontend warns rather than hiding cases
            // silently, and that's the trigger to move to server-side paging.
            $cap = 2000;

            $cases = Lead::with([
                'documents',
                'dependents',
                'tiedAsDependent.lead:id,lead_id,first_name,last_name',
                'faceImage',
                'portalUser:id,lead_id,last_login_at',
                'immigrationConverter:id,name',
                'studentConverter:id,name',
                'stageUpdater:id,name',
                'lastActivityUser:id,name',
                'owner:id,name,avatar_path',
                'agent:id,name',
            ])
                ->immigrationCase()
                ->when($scope, $scope)
                // Newest staff activity first, falling back to the raw
                // timestamp for rows stamped before the column existed.
                ->orderByRaw('COALESCE(last_activity_at, updated_at) DESC')
                ->limit($cap)
                ->get()
                ->map(function ($l) use ($visaChecklists, $awaitingByLead, $attentionByLead) {
                    // Staleness is measured on the last *activity*, not on how
                    // long the owner has held the case: a case actively worked
                    // for 12 days is fine; one untouched for 10 is stuck.
                    $amberDays = (int) config('immigration.custody_stale_amber_days', 6);
                    $redDays = (int) config('immigration.custody_stale_red_days', 10);
                    $lastTouch = $l->last_activity_at ?: $l->updated_at;
                    $idleDays = $lastTouch ? (int) $lastTouch->diffInDays(now()) : null;
                    $custodyStale = $idleDays === null ? null
                        : ($idleDays >= $redDays ? 'red' : ($idleDays >= $amberDays ? 'amber' : null));
                    // All checklist keys for this case's visa vs. the keys it
                    // has actually submitted (any non-rejected doc). Progress
                    // is measured against the full checklist, not just the
                    // required subset.
                    $checklistKeys = collect($visaChecklists[$l->inz_visa_type] ?? [])
                        ->pluck('key')->filter()->unique();
                    $submittedKeys = $l->documents
                        ->whereNotNull('checklist_key')
                        ->whereIn('status', ['Submitted', 'UnderReview', 'Approved'])
                        ->pluck('checklist_key')->unique();

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        // Customer-shareable tracking code — drives the
                        // "Copy tracking link" row action so staff can paste a
                        // /track/{code} URL straight to the client.
                        'tracking_code' => $l->tracking_code,
                        // Most recent stage-mover (falls back to the original
                        // converter if the row predates stage-update
                        // tracking). Drives "Updated [date] · Endorsed by
                        // [Name]" under the stage chip.
                        'endorsed_by' => optional($l->stageUpdater)->name
                                                ?? optional($l->immigrationConverter)->name
                                                ?? optional($l->studentConverter)->name,
                        'stage_updated_at' => optional($l->stage_updated_at)?->toIso8601String(),
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'avatar_url' => $l->faceImageUrl(),
                        // Individual name parts + a few more fields so the row
                        // "Edit case" modal can pre-fill without another fetch.
                        'first_name' => $l->first_name,
                        'middle_name' => $l->middle_name,
                        'last_name' => $l->last_name,
                        'suffix' => $l->suffix,
                        'gender' => $l->gender,
                        'payment' => $l->student_payment,
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'country' => $l->residence_country,
                        'status' => $l->status,
                        // Lead Portal access state — drives the "Request portal
                        // access" row action so it reflects requested/sent/
                        // accepted instead of always reading as a fresh request.
                        'portal_invitation_status' => $l->portal_invitation_status ?: 'none',
                        'inz_status' => $l->inz_status,
                        'inz_visa_type' => $l->inz_visa_type,
                        'inz_reference' => $l->inz_reference,
                        'inz_lodged_at' => $l->inz_lodged_at,
                        // Searchable identity fields (passport + the two INZ
                        // numbers and the medical reference).
                        'passport_number' => $l->passport_number,
                        'passport_expiry' => optional($l->passport_expiry)->toDateString(),
                        'inz_client_number' => $l->inz_client_number,
                        'inz_application_number' => $l->inz_application_number,
                        'inz_medical_ref' => $l->inz_medical_ref,
                        'nzer_number' => $l->nzer_number,
                        // Immigration-team sub-stage. Drives both the inline
                        // status picker on each row and the distribution graph
                        // up top. Pre-existing leads still on `inz_status`
                        // fall back to "Unassigned".
                        'immigration_stage' => $l->immigration_stage,
                        'immigration_priority' => $l->immigration_priority,
                        // INZ visa sub-status summary (Visa Lodged → outcome) — a
                        // compact badge for the row + collapse. null when the case
                        // is not in a tracked stage.
                        'visa_progress' => app(\App\Services\Immigration\VisaProgressService::class)->summary($l),
                        'docs_total' => $l->documents->count(),
                        'docs_approved' => $l->documents->where('status', 'Approved')->count(),
                        'docs_pending' => $l->documents->whereIn('status', ['Submitted', 'UnderReview'])->count(),
                        'docs_rejected' => $l->documents->where('status', 'Rejected')->count(),
                        // Dependants (children / partner) included in this case,
                        // so the list can show "related to" and who's tied in.
                        'dependents' => $l->dependents->map(fn ($d) => [
                            'id' => $d->id,
                            'full_name' => $d->fullName(),
                            'relationship' => $d->relationship,
                        ])->values(),
                        // When this case is itself tied to a parent's case as a
                        // dependant, the parent it belongs to (reciprocal badge).
                        'tied_to' => $l->tiedAsDependent && $l->tiedAsDependent->lead ? [
                            'id' => $l->tiedAsDependent->lead->id,
                            'name' => trim("{$l->tiedAsDependent->lead->first_name} {$l->tiedAsDependent->lead->last_name}") ?: $l->tiedAsDependent->lead->lead_id,
                        ] : null,
                        // Checklist-based progress: how many of the visa's
                        // checklist items the case has submitted (out of the
                        // full checklist).
                        'checklist_total' => $checklistKeys->count(),
                        'checklist_submitted' => $checklistKeys->intersect($submittedKeys)->count(),
                        // Updated column — the last *staff* edit of any kind
                        // (stage moves, profile fields, visa details…),
                        // stamped by Lead::stampLastActivity(). Older rows
                        // predate the stamp, so fall back to the raw
                        // timestamp and the last known handler.
                        'updated_at' => optional($l->last_activity_at ?: $l->updated_at)?->toIso8601String(),
                        'updated_by' => optional($l->lastActivityUser)->name
                                                ?? optional($l->stageUpdater)->name
                                                ?? optional($l->immigrationConverter)->name
                                                ?? optional($l->studentConverter)->name,
                        // Short summary of what that edit changed.
                        'updated_desc' => $l->last_activity_desc,
                        // Conversion provenance — when this lead was converted
                        // into a case and by whom, so the list shows which cases
                        // were converted (and can be sorted by it).
                        'converted_at' => optional($l->immigration_converted_at)?->toIso8601String(),
                        'converted_by' => optional($l->immigrationConverter)->name
                                                ?? optional($l->studentConverter)->name,
                        // Custody (Build 12 phase 2) — current owner, how long
                        // they've held it (shown as plain text), and the
                        // staleness colour derived above from last activity.
                        'owner' => $l->owner ? [
                            'id' => $l->owner->id,
                            'name' => $l->owner->name,
                            'avatar_url' => $l->owner->avatar_url,
                        ] : null,
                        'owner_since' => optional($l->owner_since)?->toIso8601String(),
                        // Referring agent (external recruiting agent, role=agent)
                        // — editable from the row's Edit-case modal.
                        'agent_id' => $l->agent_id,
                        'agent' => $l->agent ? ['id' => $l->agent->id, 'name' => $l->agent->name] : null,
                        'custody_stale' => $custodyStale,
                        'idle_days' => $idleDays,
                        // Build 12 phase 6 — open questions on this case addressed
                        // to the viewer. Drives the queue chip + My-queue filter.
                        'awaiting_my_answer' => (int) ($awaitingByLead[$l->id] ?? 0),
                        // Build 12 phase 4 — when a licensed adviser last opened
                        // this case (null = not opened). No durations, staff-only.
                        'attention_opened_at' => optional($attentionByLead[$l->id] ?? null)?->toIso8601String(),
                    ];
                });

            // Distribution payload for the stacked-bar graph that replaces
            // the old summary cards. Counts how many cases sit in each
            // canonical immigration_stage value plus an "Unassigned"
            // bucket for cases that haven't been put on the ladder yet.
            $distribution = collect(Lead::IMMIGRATION_STAGES)
                ->map(fn ($stage) => [
                    'stage' => $stage,
                    'count' => $cases->where('immigration_stage', $stage)->count(),
                ])
                ->push([
                    'stage' => 'Unassigned',
                    'count' => $cases->whereNull('immigration_stage')->count(),
                ])
                ->values();

            // Priority breakdown for the counters above the table — one per
            // level plus a "none" bucket for cases with no priority set.
            $priorities = [
                'urgent' => $cases->where('immigration_priority', 'urgent')->count(),
                'high' => $cases->where('immigration_priority', 'high')->count(),
                'medium' => $cases->where('immigration_priority', 'medium')->count(),
                'low' => $cases->where('immigration_priority', 'low')->count(),
                'done' => $cases->where('immigration_priority', 'done')->count(),
            ];
            $priorities['none'] = max(0, $cases->count() - array_sum($priorities));

            // Visa-type catalogue for the "Add new case" form. Active
            // entries only so inactive types don't pollute the dropdown,
            // ordered by category → name to match VisaType admin tooling.
            $visaTypes = \App\Models\VisaType::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'category']);

            // Immigration-capable staff for the handoff picker (incl. admins,
            // who can work any portal). Ordered by name; the current user is
            // used by the My-queue filter + "Claim" affordance.
            $staff = \App\Models\User::query()
                ->whereIn('role', array_merge(
                    [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN, 'immigration'],
                    \App\Models\User::IMMIGRATION_ROLES,
                ))
                ->orderBy('name')
                ->get(['id', 'name', 'avatar_path'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'avatar_url' => $u->avatar_url])
                ->values();

            // Referral agents (+ sub-agents) for the "Referring agent" picker on
            // the Edit-case modal — the external recruiter credited with a case.
            $agents = \App\Models\User::query()
                ->whereIn('role', ['agent', 'sub_agent'])
                ->orderBy('name')
                ->get(['id', 'name', 'referral_code', 'role'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'referral_code' => $u->referral_code, 'role' => $u->role])
                ->values();

            return [
                'cases' => $cases,
                'distribution' => $distribution,
                'priorities' => $priorities,
                'stages' => Lead::IMMIGRATION_STAGES,
                'visaTypes' => $visaTypes,
                'me_id' => auth()->id(),
                'staff' => $staff,
                'agents' => $agents,
                // True queue size vs. how many we loaded — the UI shows an honest
                // count and warns if the safety ceiling ever truncated the list.
                'total' => $total,
                'loaded' => $cases->count(),
            ];
        } catch (\Throwable $e) {
            Log::error('Immigration cases list failed', ['error' => $e->getMessage()]);

            return [
                'cases' => [],
                'distribution' => [],
                'priorities' => ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'done' => 0, 'none' => 0],
                'stages' => Lead::IMMIGRATION_STAGES,
                'visaTypes' => [],
                'agents' => [],
                'total' => 0,
                'loaded' => 0,
            ];
        }
    }

    /**
     * Lightweight case list shared by the Engagement + Invoice generation
     * workspaces. Returns just enough per-case detail to pick a case and
     * generate the relevant document, without the full Cases-page payload.
     */
    private function caseListForGeneration()
    {
        return Lead::immigrationCase()
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'email', 'phone', 'residence_country', 'inz_visa_type', 'immigration_stage'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                'email' => $l->email,
                'phone' => $l->phone,
                'country' => $l->residence_country,
                'inz_visa_type' => $l->inz_visa_type,
                'immigration_stage' => $l->immigration_stage,
            ])
            ->values();
    }

    /**
     * Client Documents — staff attach an extra document to a case so it's
     * included in the client's engagement pack. Scaffold module for now; the
     * upload/send actions are wired in a follow-up. Rendered under either
     * immigration portal via the page/portal params.
     */
    public function clientDocuments(string $page = 'portal/immigration/ClientDocuments', string $portal = 'immigration')
    {
        $formats = \App\Models\DocumentFormat::withCount('uses')->orderByDesc('updated_at')->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'category' => $f->category ?: 'client_facing',
                'content' => $f->content,
                'visa_types' => is_array($f->visa_types) ? $f->visa_types : [],
                'status' => $f->status ?: 'draft',
                'uses_count' => $f->uses_count,
                'updated_at' => optional($f->updated_at)->toIso8601String(),
            ]);

        $usages = \App\Models\DocumentFormatCase::with(['format:id,name', 'lead:id,lead_id,first_name,last_name'])
            ->orderByDesc('updated_at')->limit(300)->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'kind' => 'usage',
                'format_id' => $u->document_format_id,
                'format_name' => $u->format?->name,
                'case_id' => $u->lead_id,
                'case_name' => $u->lead ? (trim("{$u->lead->first_name} {$u->lead->last_name}") ?: $u->lead->lead_id) : '—',
                'case_ref' => $u->lead?->lead_id,
                'state' => $u->state ?: 'edited',
                'updated_at' => optional($u->updated_at)->toIso8601String(),
            ]);

        // Documents actually sent to a client from the composer (Send to client)
        // — surfaced in the same "Sent to cases" list so a send is visible here.
        $sent = \App\Models\LeadDocument::with(['lead:id,lead_id,first_name,last_name'])
            ->where('source_variant', 'client_document')
            ->orderByDesc('updated_at')->limit(300)->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'kind' => 'sent',
                'format_id' => null,
                'format_name' => $d->original_name,
                'case_id' => $d->lead_id,
                'case_name' => $d->lead ? (trim("{$d->lead->first_name} {$d->lead->last_name}") ?: $d->lead->lead_id) : '—',
                'case_ref' => $d->lead?->lead_id,
                'state' => 'sent',
                'updated_at' => optional($d->updated_at)->toIso8601String(),
            ]);

        $usages = $usages->concat($sent)->sortByDesc('updated_at')->values();

        return inertia($page, [
            'portal' => $portal,
            'cases' => $this->caseListForGeneration(),
            'formats' => $formats,
            'usages' => $usages,
            'visaOptions' => $this->visaOptions(),
        ]);
    }

    /**
     * INZ Forms console under Case — every immigration case with the INZ forms
     * its visa category offers, and the state of each (ready to fill, sent to
     * the client, submitted). Staff generate the official draft or send a form
     * to the client to fill, from one screen instead of opening each case.
     */
    public function caseInzForms()
    {
        $cases = Lead::immigrationCase()
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'email', 'inz_visa_type', 'immigration_stage']);

        // visa type/code → category, and forms grouped by category (loaded once).
        $visaCategory = [];
        foreach (\App\Models\VisaType::get(['name', 'code', 'category']) as $v) {
            if ($v->name) {
                $visaCategory[$v->name] = $v->category;
            }
            if ($v->code) {
                $visaCategory[$v->code] = $v->category;
            }
        }
        $formsByCategory = \App\Models\InzForm::where('is_active', true)->orderBy('code')->get()->groupBy('category');

        $assignments = \App\Models\CaseFormAssignment::whereIn('lead_id', $cases->pluck('id'))
            ->get()->groupBy('lead_id');

        // Already-generated INZ drafts (LeadDocument source=generated, keyed by
        // "inz:{code}") so each row can show + link to the latest draft.
        $generated = \App\Models\LeadDocument::whereIn('lead_id', $cases->pluck('id'))
            ->where('source', 'generated')
            ->where('source_variant', 'like', 'inz:%')
            ->orderByDesc('created_at')
            ->get(['id', 'lead_id', 'source_variant', 'created_at'])
            ->groupBy('lead_id');

        // Flat rows for the register table: one per (case × available INZ form).
        $rows = collect();
        $casePicker = collect();
        foreach ($cases as $l) {
            $category = $l->inz_visa_type ? ($visaCategory[$l->inz_visa_type] ?? null) : null;
            $forms = $category ? ($formsByCategory[$category] ?? collect()) : collect();
            $mine = ($assignments[$l->id] ?? collect())->keyBy('inz_form_id');
            $docs = $generated[$l->id] ?? collect(); // newest-first already
            $name = trim("{$l->first_name} {$l->last_name}") ?: 'Unknown';

            $casePicker->push([
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => $name,
                'visa_type' => $l->inz_visa_type,
                'category' => $category,
            ]);

            foreach ($forms as $f) {
                $v = $f->currentVersion();
                $a = $mine->get($f->id);
                $doc = $docs->firstWhere('source_variant', "inz:{$f->code}");
                $rows->push([
                    'case_id' => $l->id,
                    'lead_id' => $l->lead_id,
                    'case_name' => $name,
                    'code' => $f->code,
                    'name' => $f->name,
                    'category' => $f->category,
                    'version' => $v?->version_label,
                    'ready' => $v?->isReady() ?? false,
                    'lapsing' => $v?->isLapsing() ?? false,
                    'assignment_status' => $a?->status,
                    'generated_document_id' => $doc?->id,
                    'generated_at' => optional($doc?->created_at)->toIso8601String(),
                ]);
            }
        }

        // Newest generated drafts float to the top; ungenerated rows follow.
        $rows = $rows->sortByDesc(fn ($r) => $r['generated_at'] ?? '')->values();

        // Modal reference data: categories and the forms available under each.
        $formsByCat = $formsByCategory->map(fn ($group) => $group->map(fn ($f) => [
            'code' => $f->code,
            'name' => $f->name,
            'ready' => $f->currentVersion()?->isReady() ?? false,
        ])->values());
        $categories = \App\Models\VisaCategory::orderBy('name')->pluck('name')->values();

        return inertia('portal/immigration/CaseInzForms', [
            'rows' => $rows->values(),
            'cases' => $casePicker->values(),
            'categories' => $categories,
            'formsByCategory' => $formsByCat,
        ]);
    }

    /**
     * Engagement generation workspace — pick a case, choose which
     * engagement documents to generate (Written Agreement + IAA
     * standards), preview them live, and generate. The Written Agreement's
     * fees come from the case's visa on the Visas page.
     */
    public function engagement(string $page = 'portal/immigration/Engagement')
    {
        try {
            // Visa fee lookup so the picker can flag cases whose visa has
            // no fees set (the Written Agreement would render placeholders).
            $visaFees = \App\Models\VisaType::query()
                ->get(['name', 'professional_fees', 'professional_fees_discounted', 'professional_fees_offshore', 'professional_fees_discounted_offshore', 'inz_application_fee', 'inz_application_fee_offshore'])
                ->mapWithKeys(fn ($v) => [$v->name => [
                    'professional_fees' => $v->professional_fees !== null ? (float) $v->professional_fees : null,
                    // Raw value — null when genuinely unset, so the UI can
                    // tell "no discount" from "discounted == normal".
                    'professional_fees_discounted' => $v->professional_fees_discounted !== null ? (float) $v->professional_fees_discounted : null,
                    // Offshore counterparts — same "raw when unset" rule.
                    'professional_fees_offshore' => $v->professional_fees_offshore !== null ? (float) $v->professional_fees_offshore : null,
                    'professional_fees_discounted_offshore' => $v->professional_fees_discounted_offshore !== null ? (float) $v->professional_fees_discounted_offshore : null,
                    'inz_application_fee' => $v->inz_application_fee !== null ? (float) $v->inz_application_fee : null,
                    'inz_application_fee_offshore' => $v->inz_application_fee_offshore !== null ? (float) $v->inz_application_fee_offshore : null,
                ]]);

            // Cases that already have a generated engagement (draft or sent) are
            // managed from the Generated Documents table, not created again — so
            // keep them out of the "new engagement" case picker.
            $engagedLeadIds = LeadDocument::where('source_variant', 'like', 'engagement:%')
                ->distinct()
                ->pluck('lead_id')
                ->all();

            $cases = Lead::immigrationCase()
                ->whereNotIn('id', $engagedLeadIds)
                ->orderByDesc('updated_at')
                ->limit(300)
                ->get(['id', 'lead_id', 'first_name', 'last_name', 'email', 'phone', 'residence_country', 'inz_visa_type', 'immigration_stage'])
                ->map(function ($l) use ($visaFees) {
                    $fees = $visaFees[$l->inz_visa_type] ?? null;

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'country' => $l->residence_country,
                        'inz_visa_type' => $l->inz_visa_type,
                        'immigration_stage' => $l->immigration_stage,
                        'professional_fees' => $fees['professional_fees'] ?? null,
                        'professional_fees_discounted' => $fees['professional_fees_discounted'] ?? null,
                        'professional_fees_offshore' => $fees['professional_fees_offshore'] ?? null,
                        'professional_fees_discounted_offshore' => $fees['professional_fees_discounted_offshore'] ?? null,
                        'inz_application_fee' => $fees['inz_application_fee'] ?? null,
                        'inz_application_fee_offshore' => $fees['inz_application_fee_offshore'] ?? null,
                    ];
                })
                ->values();

            // Which cases already have a generated invoice — so the draft modal
            // reopens with the Invoice document ticked when one exists.
            $invoicedIds = LeadDocument::where('source_variant', 'invoice')->distinct()->pluck('lead_id');

            $generated = LeadDocument::with([
                'lead:id,first_name,last_name,lead_id,tracking_code,email,phone,residence_country,engagement_signing_token,engagement_sent_at,engagement_fee_total,engagement_total_amount,engagement_fee_location,engagement_fee_tier,engagement_include_gst,engagement_assist_signer_id',
                'lead.faceImage',
                'uploader:id,name,email',
            ])
                ->where('source_variant', 'like', 'engagement:%')
                ->orderByDesc('created_at')
                ->limit(300)
                ->get()
                // One row per CASE — the case's generated documents are
                // nested so the table renders a single line per applicant
                // instead of one line per file.
                ->groupBy('lead_id')
                ->map(function ($docs) use ($invoicedIds) {
                    $first = $docs->first(); // newest first (ordered desc)
                    $lead = $first->lead;

                    // The scoped client link for this engagement pack. Ensure the
                    // token exists so staff can copy/open the link even before the
                    // notification email was sent.
                    $token = $lead?->engagement_signing_token;
                    if ($lead && ! $token) {
                        $token = $lead->ensureEngagementSigningToken();
                    }

                    // Signed = the Written Agreement in this pack is signed. Prefer
                    // a signed copy if one exists so a stray unsigned duplicate
                    // (e.g. from a re-draft) never hides a completed signature.
                    $waCandidates = $docs->filter(fn ($d) => $d->source_variant === 'engagement:written_agreement');
                    $waDoc = $waCandidates->first(fn ($d) => $d->client_signed_at) ?: $waCandidates->first();
                    $signedAt = optional($waDoc)->client_signed_at;
                    // Draft until the pack's signing link has been emailed.
                    $isDraft = $lead ? ! $lead->engagement_sent_at : true;

                    return [
                        'case_id' => $first->lead_id,
                        'case_name' => $lead ? trim("{$lead->first_name} {$lead->last_name}") : '—',
                        'case_ref' => $lead?->lead_id,
                        // Drives the "Open application tracker" row action —
                        // the client-facing /track/{code} page.
                        'tracking_code' => $lead?->tracking_code,
                        'avatar_url' => $lead?->faceImageUrl(),
                        'email' => $lead?->email,
                        'phone' => $lead?->phone,
                        'latest_created_at' => optional($first->created_at)?->toIso8601String(),
                        'latest_by' => $first->uploader?->name,
                        // Draft = generated but not yet emailed. Drafts withhold
                        // the client link and are edited/sent from the manage modal.
                        'is_draft' => $isDraft,
                        // Whether this case already has a generated invoice, so the
                        // draft modal reopens with the Invoice document ticked.
                        'has_invoice' => $invoicedIds->contains($first->lead_id),
                        // The (ex-GST) professional fee the pack was generated at.
                        'fee_total' => $lead?->engagement_fee_total !== null ? (float) $lead->engagement_fee_total : null,
                        // The grand total — our fees (incl GST if quoted so) + INZ
                        // disbursements across the family. Drives the "Total amount"
                        // column; falls back to the ex-GST fee for older rows.
                        'total_amount' => $lead?->engagement_total_amount !== null ? (float) $lead->engagement_total_amount : null,
                        // Consolidated summary the table shows instead of every
                        // file — include the bundled invoice so it matches the
                        // client link's document count.
                        'doc_count' => $docs->count() + ($invoicedIds->contains($first->lead_id) ? 1 : 0),
                        // Link only once the pack has actually been sent to the client.
                        'signing_url' => (! $isDraft && $token) ? '/engagement/'.$token : null,
                        'signer_id' => optional($waDoc)->engagement_signer_id,
                        // Settings the pack was generated at — so the draft modal
                        // reopens with the same location / tier / GST / assisting adviser.
                        'assist_signer_id' => $lead?->engagement_assist_signer_id,
                        'fee_location' => $lead?->engagement_fee_location,
                        'fee_tier' => $lead?->engagement_fee_tier,
                        'include_gst' => (bool) $lead?->engagement_include_gst,
                        'country' => $lead?->residence_country,
                        'sent_at' => optional($lead?->engagement_sent_at)?->toIso8601String(),
                        'signed' => (bool) $signedAt,
                        'signed_at' => optional($signedAt)?->toIso8601String(),
                        'signer_name' => optional($waDoc)->client_signer_name,
                        // Audit trail for the Written Agreement — who sent it, who
                        // signed, and when (drives the Audit trail modal).
                        'audit' => [
                            'file_name' => optional($waDoc)->original_name ?: 'Engagement pack',
                            'status' => $signedAt ? 'Signed' : 'Awaiting signature',
                            'status_at' => optional($signedAt ?: optional($waDoc)->created_at ?: $first->created_at)?->toIso8601String(),
                            'sent_by' => optional(optional($waDoc)->uploader ?: $first->uploader)->name,
                            'sent_by_email' => optional(optional($waDoc)->uploader ?: $first->uploader)->email,
                            'sent_at' => optional(optional($waDoc)->created_at ?: $first->created_at)?->toIso8601String(),
                            'client_name' => $lead ? (trim("{$lead->first_name} {$lead->last_name}") ?: 'Client') : 'Client',
                            'client_email' => $lead?->email,
                            'signer_name' => optional($waDoc)->client_signer_name,
                            'signed_at' => optional($signedAt)?->toIso8601String(),
                        ],
                        'documents' => $docs->map(fn ($d) => [
                            'id' => $d->id,
                            'type_key' => str_replace('engagement:', '', (string) $d->source_variant),
                            'type_label' => \App\Services\Immigration\EngagementDocumentGenerator::DOCS[str_replace('engagement:', '', $d->source_variant)]['label']
                                ?? 'Document',
                            'size' => $d->size,
                            'signed' => (bool) $d->client_signed_at,
                            'created_at' => optional($d->created_at)?->toIso8601String(),
                            'uploaded_by' => $d->uploader?->name,
                            'download_url' => route('admin.documents.download', $d->id),
                            'view_url' => route('admin.documents.download', $d->id).'?inline=1',
                        ])->values(),
                    ];
                })
                ->values();

            // Engagement activity trail — fee adjustments and per-applicant fee
            // edits across all cases, for the Activity Log tab.
            $activityLog = \App\Models\ActivityLog::query()
                ->where('action', 'like', 'engagement.%')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'actor_name' => $log->actor_name ?: 'System',
                    'created_at' => optional($log->created_at)?->toIso8601String(),
                ])->values();

            return inertia($page, [
                'cases' => $cases,
                'documents' => \App\Services\Immigration\EngagementDocumentGenerator::catalogue(),
                'generated' => $generated,
                'signers' => $this->signingAdvisers(),
                'default_signer_id' => $this->defaultSignerId(),
                'me_id' => auth()->id(),
                'activityLog' => $activityLog,
            ]);
        } catch (\Throwable $e) {
            Log::error('Immigration engagement page failed', ['error' => $e->getMessage()]);

            return inertia($page, [
                'cases' => [],
                'documents' => \App\Services\Immigration\EngagementDocumentGenerator::catalogue(),
                'generated' => [],
                'signers' => $this->signingAdvisers(),
                'default_signer_id' => $this->defaultSignerId(),
                'me_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Licensed immigration advisers who can sign engagement documents.
     *
     * Immigration staff and advisers share the same portal role, so the
     * signer isn't a role distinction — an "adviser" is anyone who holds an
     * IAA licence number (the NZ-licensed advisers, e.g. the full-licence
     * and provisional advisers). Non-adviser staff are excluded. Each row
     * carries a `has_signature` flag so the picker can warn when the chosen
     * adviser hasn't set a signature up yet.
     */
    private function signingAdvisers(): \Illuminate\Support\Collection
    {
        return User::whereNotNull('iaa_licence_number')
            ->where('iaa_licence_number', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'iaa_licence_number', 'iaa_licence_expiry', 'signature_path'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'licence' => $u->iaa_licence_number,
                // Checks the file, not just the column — a stale path would
                // otherwise suppress the "no signature" warning while the
                // agreement still renders a blank signature line.
                'has_signature' => $u->hasSignature(),
                // A lapsed-licence adviser stays in the list but is flagged so
                // the picker can warn/disable — generation is blocked server-
                // side regardless (EngagementDocumentGenerator guard).
                'licence_current' => $u->holdsCurrentLicence(),
                'licence_expiry' => optional($u->iaa_licence_expiry)->toDateString(),
            ])
            ->values();
    }

    /**
     * The signer pre-selected in the engagement modal: the practice's licensed
     * adviser named in config('immigration.signing_adviser') (e.g. Hendry Dai),
     * matched case-insensitively against the licensed advisers. Null when the
     * name isn't found — the modal then falls back to the current user / first.
     */
    private function defaultSignerId(): ?int
    {
        $name = trim((string) config('immigration.signing_adviser'));
        if ($name === '') {
            return null;
        }

        return User::whereNotNull('iaa_licence_number')
            ->where('iaa_licence_number', '!=', '')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->value('id');
    }

    /**
     * Invoice generation workspace — pick a case and generate its invoice.
     */
    public function invoice(string $page = 'portal/immigration/Invoice')
    {
        try {
            // Visa fees drive the invoice's default line items, so the
            // picker can pre-fill amounts (and flag visas with none set).
            $visaFees = \App\Models\VisaType::query()
                ->get(['name', 'professional_fees', 'professional_fees_discounted', 'professional_fees_offshore', 'professional_fees_discounted_offshore', 'inz_application_fee', 'inz_application_fee_offshore'])
                ->mapWithKeys(fn ($v) => [$v->name => [
                    'professional_fees' => $v->professional_fees !== null ? (float) $v->professional_fees : null,
                    // Raw value — null when genuinely unset, so the UI can
                    // tell "no discount" from "discounted == normal".
                    'professional_fees_discounted' => $v->professional_fees_discounted !== null ? (float) $v->professional_fees_discounted : null,
                    // Offshore counterparts — same "raw when unset" rule.
                    'professional_fees_offshore' => $v->professional_fees_offshore !== null ? (float) $v->professional_fees_offshore : null,
                    'professional_fees_discounted_offshore' => $v->professional_fees_discounted_offshore !== null ? (float) $v->professional_fees_discounted_offshore : null,
                    'inz_application_fee' => $v->inz_application_fee !== null ? (float) $v->inz_application_fee : null,
                    'inz_application_fee_offshore' => $v->inz_application_fee_offshore !== null ? (float) $v->inz_application_fee_offshore : null,
                ]]);

            $cases = Lead::immigrationCase()
                ->with('faceImage')
                ->withCount('dependents')
                ->orderByDesc('updated_at')
                ->limit(300)
                ->get(['id', 'lead_id', 'first_name', 'last_name', 'email', 'phone', 'residence_country', 'inz_visa_type', 'immigration_stage'])
                ->map(function ($l) use ($visaFees) {
                    $fees = $visaFees[$l->inz_visa_type] ?? null;

                    return [
                        // Case has dependants → default the invoice to a section
                        // per family member.
                        'has_family' => $l->dependents_count > 0,
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'inz_visa_type' => $l->inz_visa_type,
                        'immigration_stage' => $l->immigration_stage,
                        'professional_fees' => $fees['professional_fees'] ?? null,
                        'professional_fees_discounted' => $fees['professional_fees_discounted'] ?? null,
                        'professional_fees_offshore' => $fees['professional_fees_offshore'] ?? null,
                        'professional_fees_discounted_offshore' => $fees['professional_fees_discounted_offshore'] ?? null,
                        'inz_application_fee' => $fees['inz_application_fee'] ?? null,
                        'inz_application_fee_offshore' => $fees['inz_application_fee_offshore'] ?? null,
                    ];
                })
                ->values();

            // Generated invoices — one row per case, invoices nested.
            $generated = LeadDocument::with([
                'lead:id,first_name,last_name,lead_id,tracking_code,email,phone,residence_country,engagement_fee_location,engagement_fee_tier,engagement_include_gst',
                'lead.faceImage',
                'uploader:id,name',
            ])
                ->where('source_variant', 'invoice')
                ->orderByDesc('created_at')
                ->limit(300)
                ->get()
                ->groupBy('lead_id')
                ->map(function ($docs) {
                    $first = $docs->first();
                    $lead = $first->lead;

                    return [
                        'case_id' => $first->lead_id,
                        'case_name' => $lead ? trim("{$lead->first_name} {$lead->last_name}") : '—',
                        'case_ref' => $lead?->lead_id,
                        // Drives the "Open application tracker" row action —
                        // the client-facing /track/{code} page.
                        'tracking_code' => $lead?->tracking_code,
                        'avatar_url' => $lead?->faceImageUrl(),
                        'email' => $lead?->email,
                        'phone' => $lead?->phone,
                        'latest_created_at' => optional($first->created_at)?->toIso8601String(),
                        'latest_by' => $first->uploader?->name,
                        // Pricing context this invoice was generated under.
                        'fee_location' => $lead?->engagement_fee_location,
                        'fee_tier' => $lead?->engagement_fee_tier,
                        'include_gst' => (bool) $lead?->engagement_include_gst,
                        'country' => $lead?->residence_country,
                        // Total invoiced for this case (sum of its invoice totals);
                        // null when none were stored (pre-column invoices).
                        'total_amount' => $docs->whereNotNull('invoice_total')->isNotEmpty()
                            ? (float) $docs->sum(fn ($d) => (float) ($d->invoice_total ?? 0))
                            : null,
                        'invoices' => $docs->map(fn ($d) => [
                            'id' => $d->id,
                            'number' => $d->invoice_number,
                            'total' => $d->invoice_total !== null ? (float) $d->invoice_total : null,
                            'size' => $d->size,
                            'created_at' => optional($d->created_at)?->toIso8601String(),
                            'download_url' => route('admin.documents.download', $d->id),
                            'view_url' => route('admin.documents.download', $d->id).'?inline=1',
                        ])->values(),
                    ];
                })
                ->values();

            // Cases that already have an engagement pack generated but no
            // invoice yet — surfaced as one-click "generate invoice"
            // suggestions so staff don't have to hunt the case in the picker.
            // Signing an engagement is the trigger to bill; this closes the gap.
            $invoicedLeadIds = LeadDocument::where('source_variant', 'invoice')
                ->distinct()
                ->pluck('lead_id');

            $suggestions = LeadDocument::with([
                'lead:id,first_name,last_name,lead_id,inz_visa_type',
                'lead.faceImage',
            ])
                ->where('source_variant', 'like', 'engagement:%')
                ->whereNotIn('lead_id', $invoicedLeadIds)
                ->orderByDesc('created_at')
                ->limit(300)
                ->get()
                ->groupBy('lead_id')
                ->map(function ($docs) {
                    $first = $docs->first();
                    $lead = $first->lead;

                    return [
                        'case_id' => $first->lead_id,
                        'case_name' => $lead ? trim("{$lead->first_name} {$lead->last_name}") : '—',
                        'case_ref' => $lead?->lead_id,
                        'avatar_url' => $lead?->faceImageUrl(),
                        'inz_visa_type' => $lead?->inz_visa_type,
                        'engagement_at' => optional($first->created_at)?->toIso8601String(),
                        'engagement_count' => $docs->count(),
                    ];
                })
                ->values();

            // Proof-of-payment uploads clients submitted from the engagement
            // page — staff verify (approve/reject) them here.
            $proofs = LeadDocument::with(['lead:id,first_name,last_name,lead_id', 'lead.faceImage'])
                ->where('source_variant', 'proof_of_payment')
                ->orderByDesc('created_at')
                ->limit(300)
                ->get()
                // One row per case — the client submits a single proof per
                // invoice, and a re-upload replaces the prior file, so show the
                // latest per case rather than every historical upload.
                ->unique('lead_id')
                ->map(fn (LeadDocument $d) => [
                    'id' => $d->id,
                    'case_id' => $d->lead_id,
                    'case_name' => $d->lead ? (trim("{$d->lead->first_name} {$d->lead->last_name}") ?: '—') : '—',
                    'case_ref' => $d->lead?->lead_id,
                    'avatar_url' => $d->lead?->faceImageUrl(),
                    'original_name' => $d->original_name,
                    'size' => $d->size,
                    'status' => $d->status,
                    'uploaded_at' => optional($d->created_at)?->toIso8601String(),
                    'view_url' => route('admin.documents.download', $d->id).'?inline=1',
                    'download_url' => route('admin.documents.download', $d->id),
                    'status_url' => "/admin/leads/{$d->lead_id}/documents/{$d->id}/status",
                ])
                ->values();

            return inertia($page, [
                'cases' => $cases,
                'generated' => $generated,
                'suggestions' => $suggestions,
                'proofs' => $proofs,
                'nextNumber' => app(\App\Services\Immigration\InvoiceGenerator::class)->nextInvoiceNumber(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Immigration invoice page failed', ['error' => $e->getMessage()]);

            return inertia($page, ['cases' => [], 'generated' => [], 'suggestions' => [], 'proofs' => [], 'nextNumber' => null]);
        }
    }

    /**
     * Create a brand-new immigration case from the Cases page. Saves a
     * Lead row flagged as an immigration case, optionally attaches a
     * LeadNote for the internal-note field, and stamps `inz_visa_type`
     * from the chosen visa-type name so existing tooling sees a familiar
     * label.
     */
    public function storeCase(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:80',
            'middle_name' => 'nullable|string|max:80',
            'last_name' => 'required|string|max:80',
            'suffix' => 'nullable|string|max:20',
            'gender' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:40',
            // Stage is now optional — if the staff member doesn't pick
            // one we default to the first canonical value ("Endorsed")
            // below so the new case lands cleanly on the journey rail.
            'immigration_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGES)],
            'immigration_priority' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_PRIORITIES)],
            'internal_note' => 'nullable|string|max:5000',
            'payment' => 'nullable|string|max:120',
            'visa_type_id' => 'nullable|integer|exists:visa_types,id',
            'agent_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->whereIn('role', ['agent', 'sub_agent'])],
        ]);

        $visa = \App\Models\VisaType::find($data['visa_type_id']);

        $lead = Lead::create([
            'lead_id' => 'IC-'.strtoupper(uniqid()),
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'immigration_stage' => $data['immigration_stage'] ?? Lead::IMMIGRATION_STAGES[0],
            'immigration_priority' => $data['immigration_priority'] ?? null,
            'inz_visa_type' => $visa?->name,
            'student_payment' => $data['payment'] ?? null,
            'agent_id' => $data['agent_id'] ?? null,
            // Mark immediately as an immigration case so scopeImmigrationCase
            // picks it up without waiting for a stage hand-off.
            'is_immigration_case' => true,
            'immigration_converted_at' => now(),
            'immigration_converted_by' => auth()->id(),
            // Initial stage stamp — drives "Updated [date] · Endorsed by
            // [Name]" subtitle in the Cases table.
            'stage_updated_at' => now(),
            'stage_updated_by' => auth()->id(),
            'source' => 'manual.immigration',
            'status' => 'New',
            'stage' => 'Visa Process',
        ]);

        // Internal note → LeadNote so it surfaces in the same notes feed
        // the rest of the system writes to, rather than getting buried in
        // a free-form column.
        if (! empty($data['internal_note'])) {
            \App\Models\LeadNote::create([
                'lead_id' => $lead->id,
                'kind' => 'general',
                'body' => $data['internal_note'],
                'author_name' => auth()->user()?->name ?? 'System',
                'author_role' => auth()->user()?->role ?? 'immigration',
            ]);
        }

        return back()->with('success', "Case {$lead->lead_id} created.");
    }

    /** POST /portal/immigration/cases/{id} — edit a case's core details
     *  (same fields as the "New case" modal). */
    public function updateCase(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'first_name' => 'required|string|max:80',
            'middle_name' => 'nullable|string|max:80',
            'last_name' => 'required|string|max:80',
            'suffix' => 'nullable|string|max:20',
            'gender' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:40',
            'immigration_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGES)],
            'immigration_priority' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_PRIORITIES)],
            'internal_note' => 'nullable|string|max:5000',
            'payment' => 'nullable|string|max:120',
            'visa_type_id' => 'nullable|integer|exists:visa_types,id',
            'agent_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->whereIn('role', ['agent', 'sub_agent'])],
        ]);

        $visa = \App\Models\VisaType::find($data['visa_type_id']);

        $lead->update([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'immigration_stage' => $data['immigration_stage'] ?? $lead->immigration_stage,
            'immigration_priority' => $data['immigration_priority'] ?? null,
            'inz_visa_type' => $visa?->name,
            'student_payment' => $data['payment'] ?? null,
            'agent_id' => $data['agent_id'] ?? null,
        ]);

        // A note is optional on edit — only append when the staffer typed one.
        if (! empty($data['internal_note'])) {
            \App\Models\LeadNote::create([
                'lead_id' => $lead->id,
                'kind' => 'general',
                'body' => $data['internal_note'],
                'author_name' => auth()->user()?->name ?? 'System',
                'author_role' => auth()->user()?->role ?? 'immigration',
            ]);
        }

        return back()->with('success', "Case {$lead->lead_id} updated.");
    }

    /**
     * Inline stage update from the Cases table. Mirrors EducationController's
     * `updateStudentField` pattern — single endpoint, immigration_stage is
     * the only field accepted.
     */
    public function updateCaseStage(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'immigration_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGES)],
            'immigration_assignee' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGE_ASSIGNEES)],
            // Optional note captured when moving to stages like "Request to
            // Lodged" or "Withdrawn". Recorded as an internal case note.
            'stage_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $newStage = $data['immigration_stage'] ?? null;
        $stageMoved = ($lead->immigration_stage ?? null) !== $newStage;
        $stageNote = trim((string) ($data['stage_note'] ?? ''));

        // Records the optional note as an internal case note so it surfaces in
        // the Overview timeline, attributed to the mover and the target stage.
        $recordStageNote = function () use ($lead, $stageNote, $newStage) {
            if ($stageNote === '') {
                return;
            }
            $user = auth()->user();
            \App\Models\LeadNote::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'author_name' => $user?->name,
                'author_role' => $user?->role,
                'kind' => 'note',
                'body' => "Stage → {$newStage}: {$stageNote}",
            ]);
        };

        // Build 12 phase 4.5 (§15.1): the process chain is the single
        // authoritative writer of immigration_stage. When the case is on the
        // chain, a manual stage change is re-pointed to a forward jump through
        // the steps (an explicit override) instead of a direct column write.
        // Cases not yet on the chain fall back to the legacy behaviour below.
        $steps = app(\App\Services\Immigration\CaseStepService::class);
        if ($newStage !== null && $steps->hasChain($lead)) {
            if ($steps->jumpToStage($lead, $newStage, auth()->user())) {
                if (array_key_exists('immigration_assignee', $data)) {
                    $lead->immigration_assignee = $data['immigration_assignee'] ?: null;
                }
                // Visa approved — the case is done, so clear its working priority
                // to "done" (it no longer needs to compete for attention).
                if ($newStage === 'Approved Visa' && $lead->immigration_priority !== 'done') {
                    $lead->immigration_priority = 'done';
                }
                if ($lead->isDirty()) {
                    $lead->save();
                }
                $recordStageNote();
                \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

                // Email automation — the chain path sets the stage without going
                // through advanceImmigrationStage, so fire the per-stage event here.
                app(\App\Services\EmailAutomationService::class)->fire(
                    'immigration.stage.'.\Illuminate\Support\Str::slug($newStage, '_'), $lead, ['stage' => $newStage]
                );

                return back();
            }
        }

        if (array_key_exists('immigration_assignee', $data)) {
            $lead->immigration_assignee = $data['immigration_assignee'] ?: null;
        }

        if ($stageMoved) {
            $lead->immigration_stage = $newStage;
            // Only stamp the stage-tracking columns when the stage
            // actually moved — re-saving the same value shouldn't refresh
            // the "Updated [date]" subtitle the table shows.
            $lead->stage_updated_at = now();
            $lead->stage_updated_by = auth()->id();
            $lead->pushStageHistory('immigration', $newStage, $lead->immigration_assignee);

            // Visa approved — the case is done, so clear its working priority to
            // "done" (it no longer needs to compete for attention).
            if ($newStage === 'Approved Visa' && $lead->immigration_priority !== 'done') {
                $lead->immigration_priority = 'done';
            }
        }

        if ($stageMoved || $lead->isDirty('immigration_assignee')) {
            $lead->save();
        }

        if ($stageMoved) {
            $recordStageNote();

            // Email automation — the legacy path sets the stage directly (it does
            // not go through advanceImmigrationStage, and reaches here when the case
            // is off the chain or the chain can't jump to this stage), so fire the
            // per-stage event here too. Guarded on a non-null stage.
            if ($newStage !== null) {
                app(\App\Services\EmailAutomationService::class)->fire(
                    'immigration.stage.'.\Illuminate\Support\Str::slug($newStage, '_'), $lead, ['stage' => $newStage]
                );
            }
        }

        // Re-evaluate findings off the request path when the stage moves (§8d).
        if ($stageMoved) {
            \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);
        }

        return back();
    }

    /**
     * Move a case to "Decline Visa" with the decline record: an optional decline
     * letter (shared to the client) and an optional note, plus an optional email
     * to the client. The document is stored as a StaffShared LeadDocument so it
     * appears BOTH on the staff Documents tab and in the client's portal.
     */
    public function declineVisa(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $stage = 'Decline Visa';

        // Mirror updateCaseStage: when the case is on the process chain, jump it
        // through the steps; otherwise write the column directly with history.
        $steps = app(\App\Services\Immigration\CaseStepService::class);
        $movedViaChain = $steps->hasChain($lead) && $steps->jumpToStage($lead, $stage, auth()->user());
        if (! $movedViaChain && ($lead->immigration_stage ?? null) !== $stage) {
            $lead->immigration_stage = $stage;
            $lead->stage_updated_at = now();
            $lead->stage_updated_by = auth()->id();
            $lead->pushStageHistory('immigration', $stage, $lead->immigration_assignee);
        }

        // Record the decline outcome + optional details on the case.
        $lead->has_been_declined_visa = true;
        if (! empty($data['note'])) {
            $lead->declined_visa_details = $data['note'];
        }
        $lead->save();

        // Optional decline letter — StaffShared so it surfaces on the Documents
        // tab and in the client portal's "shared with you" list.
        $doc = null;
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $path = $file->store("lead-documents/{$lead->id}", 'local');
            $doc = \App\Models\LeadDocument::create([
                'lead_id' => $lead->id,
                'checklist_key' => null,
                'original_name' => $file->getClientOriginalName() ?: 'Visa decline letter',
                'file_path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'source' => 'upload',
                'source_variant' => 'decline',
                'status' => \App\Models\LeadDocument::STATUS_STAFF_SHARED,
                'uploaded_by' => auth()->id(),
                'note' => $data['note'] ?? null,
            ]);
        }

        // Optional email to the client — factual status update; the letter (if
        // any) is available securely in their portal, not attached.
        if (! empty($data['notify']) && $lead->email) {
            try {
                $name = trim((string) $lead->first_name) ?: 'there';
                $lines = ["Dear {$name},", '', 'We are writing with an update on your visa application. Immigration New Zealand has declined the application.'];
                if (! empty($data['note'])) {
                    $lines[] = '';
                    $lines[] = $data['note'];
                }
                if ($doc) {
                    $lines[] = '';
                    $lines[] = 'A related document has been shared to your client portal, where you can view it securely.';
                }
                $lines[] = '';
                $lines[] = 'Please contact us if you would like to discuss the next steps.';
                $body = nl2br(e(implode("\n", $lines)));
                app(\App\Services\CommunicationService::class)
                    ->sendRaw('email', $lead, 'An update on your visa application', $body);
            } catch (\Throwable $e) {
                Log::warning('Decline email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        $lead->recordStaffActivity('Marked visa declined'.($doc ? ' + shared decline letter' : ''));
        \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

        return back()->with('success', 'Case moved to Decline Visa.');
    }

    /**
     * Positive / interim outcome — moves the case to "Approved Visa",
     * "Interim Visa Issued" or "Approved in Principle" with an optional shared
     * document and note (same modal as decline). Unlike decline, the client
     * email is the configured stage automation, so staff control the wording.
     */
    public function recordOutcome(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'stage' => ['required', 'string', \Illuminate\Validation\Rule::in(['Approved Visa', 'Interim Visa Issued', 'Approved in Principle'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $stage = $data['stage'];
        $note = trim((string) ($data['note'] ?? ''));

        // Set the stage (chain jump when on the process chain, else legacy write).
        $steps = app(\App\Services\Immigration\CaseStepService::class);
        $movedViaChain = $steps->hasChain($lead) && $steps->jumpToStage($lead, $stage, auth()->user());
        if (! $movedViaChain && ($lead->immigration_stage ?? null) !== $stage) {
            $lead->immigration_stage = $stage;
            $lead->stage_updated_at = now();
            $lead->stage_updated_by = auth()->id();
            $lead->pushStageHistory('immigration', $stage, $lead->immigration_assignee);
        }
        // Approved visa is done — clear the working priority so it stops competing.
        if ($stage === 'Approved Visa' && $lead->immigration_priority !== 'done') {
            $lead->immigration_priority = 'done';
        }
        $lead->save();

        // Optional document — StaffShared so it surfaces on the Documents tab and
        // in the client portal's "shared with you" list.
        $doc = null;
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $path = $file->store("lead-documents/{$lead->id}", 'local');
            $doc = \App\Models\LeadDocument::create([
                'lead_id' => $lead->id,
                'checklist_key' => null,
                'original_name' => $file->getClientOriginalName() ?: ($stage.' document'),
                'file_path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'source' => 'upload',
                'source_variant' => 'outcome:'.\Illuminate\Support\Str::slug($stage, '_'),
                'status' => \App\Models\LeadDocument::STATUS_STAFF_SHARED,
                'uploaded_by' => auth()->id(),
                'note' => $note ?: null,
            ]);
        }

        // Record the note on the case timeline.
        if ($note !== '') {
            $user = auth()->user();
            \App\Models\LeadNote::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'author_name' => $user?->name,
                'author_role' => $user?->role,
                'kind' => 'note',
                'body' => "Stage → {$stage}: {$note}",
            ]);
        }

        // Email the client via the configured stage automation (the template staff
        // set up), passing the note as {{status_detail}}. Skipped when unticked.
        if (! empty($data['notify'])) {
            app(\App\Services\EmailAutomationService::class)->fire(
                'immigration.stage.'.\Illuminate\Support\Str::slug($stage, '_'),
                $lead,
                ['stage' => $stage, 'status_detail' => $note]
            );
        }

        $lead->recordStaffActivity("Moved to {$stage}".($doc ? ' + shared document' : ''));
        \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

        return back()->with('success', "Case moved to {$stage}.");
    }

    /**
     * Move a case to "Request for Information" — INZ has asked for more. Captures
     * the response DEADLINE, attaches the RFI PDF(s) (shared to the case + the
     * client portal), records an optional note, and fires the configured RFI
     * stage automation with the deadline as {{rfi_deadline}} and the PDFs
     * attached to the email. Mirrors recordOutcome() with those additions.
     */
    /**
     * Read INZ's RFI letter(s) and return the documents INZ is requesting, so
     * the RFI modal can show them as an editable list before staff confirms.
     *
     * This is a DRAFTING AID (CLAUDE.md AI constraints): it only reads the
     * uploaded PDF(s) and extracts what the letter literally names — it does not
     * store anything, change the stage, or notify the client. Nothing reaches
     * the client until staff confirm the list on the main RFI submit. Degrades
     * to an empty list (with a flag) for scanned PDFs or when AI is off, so the
     * staff member can just type the documents manually.
     */
    public function analyzeRfi(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $request->validate([
            'documents' => ['required', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf', 'max:10240'],
        ]);

        // Parse straight from the uploaded temp files — no need to persist here;
        // the real RFI submit stores them.
        $paths = [];
        foreach ((array) $request->file('documents', []) as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->getRealPath()) {
                $paths[] = $file->getRealPath();
            }
        }

        $result = app(\App\Services\Immigration\RfiDocumentExtractor::class)->extract($paths);

        $lead->recordStaffActivity('Analysed RFI letter'.(
            $result['ai_used'] ? ' ('.count($result['documents']).' document(s) found)' : ''
        ));

        return response()->json($result);
    }

    public function requestForInformation(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'deadline' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf', 'max:10240'],
            'notify' => ['nullable', 'boolean'],
            // The documents INZ asked for, confirmed by staff from the AI-extracted
            // list in the modal. Each becomes a LeadDocumentRequest the client
            // uploads against — origin 'rfi' groups them under "Request Information".
            'requested_documents' => ['nullable', 'array', 'max:25'],
            'requested_documents.*.label' => ['required', 'string', 'max:120'],
            'requested_documents.*.description' => ['nullable', 'string', 'max:500'],
            'requested_documents.*.required' => ['nullable', 'boolean'],
        ]);

        $stage = 'Request for Information';
        $note = trim((string) ($data['note'] ?? ''));
        $deadline = \Illuminate\Support\Carbon::parse($data['deadline'])->startOfDay();

        // Set the stage (chain jump when on the process chain, else legacy write).
        $steps = app(\App\Services\Immigration\CaseStepService::class);
        $movedViaChain = $steps->hasChain($lead) && $steps->jumpToStage($lead, $stage, auth()->user());
        if (! $movedViaChain && ($lead->immigration_stage ?? null) !== $stage) {
            $lead->immigration_stage = $stage;
            $lead->stage_updated_at = now();
            $lead->stage_updated_by = auth()->id();
            $lead->pushStageHistory('immigration', $stage, $lead->immigration_assignee);
        }

        // Store the deadline ON the RFI stage-history entry (both stage paths push
        // one) rather than a new column — the leads god table is at its row-size
        // limit. `$lead->rfi_deadline` reads it back. If the case was already on
        // this stage (no fresh entry), push one so the deadline has a home.
        $history = $lead->stage_history ?? [];
        $idx = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['stage'] ?? null) === $stage) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            $lead->pushStageHistory('immigration', $stage, $lead->immigration_assignee);
            $history = $lead->stage_history;
            $idx = count($history) - 1;
        }
        $history[$idx]['rfi_deadline'] = $deadline->toDateString();
        $lead->stage_history = $history;
        $lead->save();

        // Store each RFI PDF (StaffShared so it shows on the Documents tab and the
        // client portal), and collect them as email attachments.
        $attachments = [];
        foreach ((array) $request->file('documents', []) as $file) {
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                continue;
            }
            $path = $file->store("lead-documents/{$lead->id}", 'local');
            $name = $file->getClientOriginalName() ?: 'RFI document.pdf';
            \App\Models\LeadDocument::create([
                'lead_id' => $lead->id,
                // The staff RFI file is a NOTIFICATION ("here's what INZ wants") —
                // kept as a shared doc (checklist_key null), NOT the client's
                // upload. The client responds via the "Request Information Form"
                // upload slot, whose files ARE keyed 'rfi'.
                'checklist_key' => null,
                'original_name' => $name,
                'file_path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'source' => 'upload',
                'source_variant' => 'rfi',
                'status' => \App\Models\LeadDocument::STATUS_STAFF_SHARED,
                'uploaded_by' => auth()->id(),
                'note' => $note ?: null,
            ]);
            $attachments[] = ['path' => $path, 'name' => $name];
        }

        // The documents INZ asked for — staff confirmed these from the AI-read
        // list in the modal. Each becomes a request the client uploads against
        // (origin 'rfi' groups them under "Request Information" on the Documents
        // tab and the client portal). Deduped by label against existing RFI
        // requests so re-opening the modal doesn't create duplicates.
        $requestedLabels = [];
        $existingRfi = $lead->documentRequests()->where('origin', 'rfi')
            ->pluck('label')->map(fn ($l) => mb_strtolower(trim((string) $l)))->all();
        foreach ((array) ($data['requested_documents'] ?? []) as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' || in_array(mb_strtolower($label), $existingRfi, true)) {
                continue;
            }
            $existingRfi[] = mb_strtolower($label);
            \App\Models\LeadDocumentRequest::create([
                'lead_id' => $lead->id,
                'label' => $label,
                'description' => trim((string) ($item['description'] ?? '')) ?: null,
                'required' => array_key_exists('required', $item) ? (bool) $item['required'] : true,
                'origin' => 'rfi',
                'requested_by' => auth()->id(),
                'requested_at' => now(),
            ]);
            $requestedLabels[] = $label;
        }

        // Record the note (with the deadline) on the case timeline.
        $user = auth()->user();
        \App\Models\LeadNote::create([
            'lead_id' => $lead->id,
            'user_id' => $user?->id,
            'author_name' => $user?->name,
            'author_role' => $user?->role,
            'kind' => 'note',
            'body' => "Stage → {$stage} (respond by {$deadline->format('j M Y')})".($note !== '' ? ": {$note}" : ''),
        ]);

        // Email via the configured RFI automation. {{rfi_deadline}} + the PDFs are
        // passed through; skipped when the "notify" toggle is off.
        if (! empty($data['notify'])) {
            app(\App\Services\EmailAutomationService::class)->fire(
                'immigration.stage.'.\Illuminate\Support\Str::slug($stage, '_'),
                $lead,
                [
                    'stage' => $stage,
                    'status_detail' => $note,
                    'rfi_deadline' => $deadline->format('j M Y'),
                    // {{document_list}} lets the RFI template spell out exactly
                    // what the client must upload; blank when none were extracted.
                    'document_list' => implode(', ', $requestedLabels),
                ],
                $attachments,
            );
        }

        $lead->recordStaffActivity("Moved to {$stage} (respond by {$deadline->format('j M Y')})".($attachments ? ' + '.count($attachments).' document(s)' : ''));
        \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

        return back()->with('success', "Case moved to {$stage}.");
    }

    /**
     * Inline priority update from the Cases table's expanded row.
     */
    public function updateCasePriority(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'immigration_priority' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_PRIORITIES)],
        ]);

        $lead->immigration_priority = $data['immigration_priority'] ?? null;
        $lead->save();

        return back();
    }

    /**
     * Case custody handoff (Build 12 phase 2). One owner at a time; ownership
     * changes only here, and only with the option of a note. Handing a case to
     * yourself (to_user_id = self) is a claim — the same endpoint, no separate
     * path. Stage is deliberately NOT touched: automatic custody movement
     * arrives with the verdict in phase 5, derived from it.
     */
    public function handoff(\Illuminate\Http\Request $request, $id)
    {
        // Row-level scope: case-only, and 404 for anything that isn't an
        // immigration case (EnsurePortalAccess is role-level only).
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $me = auth()->user();
        $newOwner = \App\Models\User::findOrFail($data['to_user_id']);

        // The new owner must be able to work the immigration portal — you can't
        // hand a case to someone who can't open it.
        if (! $newOwner->canAccessPortal('immigration')) {
            return back()->withErrors(['to_user_id' => 'That user cannot be assigned immigration cases.']);
        }

        $isClaim = $me && $newOwner->is($me);
        $note = trim((string) ($data['note'] ?? ''));

        $lead->current_owner_id = $newOwner->id;
        $lead->owner_since = now();
        $lead->save();

        // The note (when given) lands as a case note so it's visible on the
        // Notes tab — carried with the handoff, not lost in a toast.
        if ($note !== '') {
            \App\Models\LeadNote::create([
                'lead_id' => $lead->id,
                'user_id' => $me?->id,
                'author_name' => $me?->name,
                'author_role' => $me?->role,
                'kind' => 'handoff',
                'body' => ($isClaim ? 'Claimed case — ' : "Handed to {$newOwner->name} — ").$note,
            ]);
        }

        // Show it in the Updated column (recordStaffActivity stamps quietly).
        $lead->recordStaffActivity($isClaim ? 'Claimed case' : "Handed off to {$newOwner->name}");

        // Audit trail (Build 12 §13).
        \App\Models\ActivityLog::record('case.handoff', [
            'description' => $isClaim
                ? "{$me?->name} claimed case {$lead->lead_id}"
                : "{$me?->name} handed case {$lead->lead_id} to {$newOwner->name}",
            'properties' => [
                'target_id' => $lead->id,
                'to_user_id' => $newOwner->id,
                'is_claim' => $isClaim,
                'has_note' => $note !== '',
            ],
        ]);

        // Notify the new owner — in-app + email, carrying the note, linking to
        // the case. A claim doesn't notify yourself.
        if (! $isClaim) {
            $newOwner->notify(new \App\Notifications\CaseHandedOff($lead, $me?->name ?? 'A colleague', $note ?: null));
        }

        return back()->with('success', $isClaim
            ? 'You now own this case.'
            : "Case handed to {$newOwner->name}.");
    }

    /**
     * Inline visa-type update from the Cases table. Stamps the matching
     * VisaType name onto `inz_visa_type` (or clears it when null is posted).
     */
    public function updateCaseVisa(\Illuminate\Http\Request $request, $id)
    {
        $lead = Lead::immigrationCase()->findOrFail($id);

        $data = $request->validate([
            'visa_type_id' => 'nullable|integer|exists:visa_types,id',
        ]);

        $visa = ! empty($data['visa_type_id'])
            ? \App\Models\VisaType::find($data['visa_type_id'])
            : null;

        $lead->inz_visa_type = $visa?->name;
        $lead->save();

        return back();
    }

    /**
     * Convert a public visa-interest submission into an active immigration
     * case — works for all four intake types (Resident / Work / Student /
     * Visitor) via the Assessment's polymorphic intakeable relationship.
     *
     * Route param accepts either an Assessment ID (preferred, current
     * frontend) or a legacy ResidentIntake ID (kept working so any
     * pre-Phase-B bookmarks still convert correctly). The shim resolves
     * the right Assessment regardless of which ID was posted.
     *
     * Idempotent: if the matched Lead is already flagged as an immigration
     * case, the existing immigration_converted_at timestamp is preserved.
     * The Assessment + intake are still progressed so the row leaves the
     * triage queue.
     */
    /**
     * Best-effort: stamp this assessment's pre-screening as converted so the
     * potential-client vetting status stays in sync with the case flag. Keyed
     * on the pre-screening's subject (the intake for submitted forms, the Lead
     * for free assessments / drafts). Must never break the conversion itself.
     */
    private function recordPreScreeningConversion(\Illuminate\Database\Eloquent\Model $subject, Lead $lead): void
    {
        try {
            $pre = \App\Models\PreScreening::where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->first();
            if ($pre && $pre->status !== \App\Models\PreScreening::STATUS_CONVERTED) {
                app(\App\Services\Immigration\PreScreeningService::class)
                    ->recordConversion($pre, $lead->id, auth()->user());
            }
        } catch (\Throwable $e) {
            Log::warning('Pre-screening conversion sync failed', ['error' => $e->getMessage()]);
        }
    }

    public function convertAssessmentToCase(\Illuminate\Http\Request $request, $id)
    {
        try {
            // Free assessment — a lead with an AI eligibility score, no intake or
            // paired Assessment. Convert the lead record directly (nothing to
            // migrate: free-assessment uploads are already LeadDocument rows).
            if ($request->input('intake_type') === 'free') {
                return $this->convertFreeAssessmentToCase((int) ($request->input('intake_id') ?: $id));
            }

            $typeMap = [
                'resident' => ResidentIntake::class,
                'work' => \App\Models\WorkIntake::class,
                'student' => \App\Models\StudentIntake::class,
                'visitor' => \App\Models\VisitorIntake::class,
                'family' => \App\Models\FamilyIntake::class,
            ];
            $assessment = null;

            // PREFERRED, UNAMBIGUOUS resolution: the caller names the exact
            // intake (type + id). We resolve its paired Assessment through the
            // morph link — NEVER by treating the intake id as an Assessment id.
            // The old code did `Assessment::find($id)` where $id could be an
            // intake id, so when the two id sequences overlapped it converted an
            // unrelated applicant's case (e.g. a resident intake with no paired
            // Assessment). Resolving by (type, id) removes that collision.
            $intakeType = $request->input('intake_type');
            $intakeId = $request->input('intake_id');
            if ($intakeType && $intakeId && isset($typeMap[$intakeType])) {
                $cls = $typeMap[$intakeType];
                $intakeModel = $cls::find($intakeId);
                if (! $intakeModel) {
                    return back()->with('error', 'Could not find this submission.');
                }
                $assessment = Assessment::with(['visaType', 'intakeable'])
                    ->where('intakeable_type', $cls)
                    ->where('intakeable_id', $intakeModel->id)
                    ->first();
                // Resident intake with no Assessment yet — intake-only path.
                if (! $assessment && $cls === ResidentIntake::class) {
                    return $this->convertResidentIntakeWithoutAssessment($intakeModel);
                }
                if (! $assessment) {
                    Log::warning('Convert-to-case: intake has no paired Assessment.', ['type' => $intakeType, 'id' => $intakeId]);

                    return back()->with('error', 'This submission has no assessment to convert yet.');
                }
            }

            // LEGACY fallback for older callers that only put an id in the URL.
            if (! $assessment) {
                $assessment = Assessment::with(['visaType', 'intakeable'])->find($id);
                if (! $assessment) {
                    $residentIntake = ResidentIntake::find($id);
                    if ($residentIntake) {
                        $assessment = Assessment::with(['visaType', 'intakeable'])
                            ->where('intakeable_type', ResidentIntake::class)
                            ->where('intakeable_id', $residentIntake->id)
                            ->first();
                    }
                    if (! $assessment && $residentIntake) {
                        return $this->convertResidentIntakeWithoutAssessment($residentIntake);
                    }
                }
            }

            if (! $assessment) {
                Log::warning('Convert-to-case: no Assessment or ResidentIntake matched.', ['id' => $id]);

                return back()->with('error', 'Could not find this submission.');
            }

            $intake = $assessment->intakeable;
            if (! $intake) {
                Log::error('Convert-to-case: Assessment has no intakeable.', ['assessment_id' => $assessment->id]);

                return back()->with('error', 'Submission data is incomplete; please contact support.');
            }

            // Visa name from the linked VisaType — falls back to the
            // intake's own visa label so we never blank-stamp inz_visa_type.
            $visaName = $assessment->visaType?->name
                ?? \App\Support\IntakeVisaTypeMap::label($intake::class);

            // Last-name snapshot tolerates both naming conventions
            // (Resident uses last_name; Work/Student/Visitor use family_name).
            $lastName = $intake->last_name ?? $intake->family_name ?? null;

            return DB::transaction(function () use ($assessment, $intake, $visaName, $lastName) {
                // Find-or-create the Lead. Emails are NOT unique to a person
                // (a parent often registers several people under one email),
                // so a same-email lead is only the right target when the NAME
                // also matches — otherwise we'd attach this assessment to a
                // different person's case.
                $email = $intake->email ?: $assessment->applicant_email;
                $wantLast = strtolower(trim((string) $lastName));
                $wantFirst = strtolower(trim((string) ($intake->first_name ?? $assessment->applicant_first_name ?? '')));

                // 0. Prefer the pre-screening's "working lead" — the record that
                //    already holds this applicant's onboarding (payment plan,
                //    bookings, uploaded documents). Converting onto it keeps all
                //    of that attached instead of spawning a parallel case.
                $lead = \App\Models\PreScreening::where('subject_type', $intake->getMorphClass())
                    ->where('subject_id', $intake->getKey())
                    ->whereNotNull('lead_id')
                    ->first()?->lead;

                // 1. Same email AND matching FULL name — the confident match.
                //    Email is not unique to a person (families share an inbox)
                //    and neither is a surname, so require the first name to match
                //    too. Matching on surname alone would hijack a same-email
                //    relative's lead (e.g. converting "Test Rogene" linked to a
                //    different "… Rogene" and the success message named them).
                if (! $lead && $email && $wantLast !== '' && $wantFirst !== '') {
                    $lead = Lead::where('email', $email)->get()
                        ->first(fn ($l) => strtolower(trim((string) $l->last_name)) === $wantLast
                            && strtolower(trim((string) $l->first_name)) === $wantFirst);
                }

                // 2. No email match — a staff-created case may exist under the
                //    same name with no email on it yet. Link only when the
                //    first + last name match is unambiguous (exactly one).
                if (! $lead && $wantFirst !== '' && $wantLast !== '') {
                    $named = Lead::whereRaw('LOWER(TRIM(last_name)) = ?', [$wantLast])
                        ->whereRaw('LOWER(TRIM(first_name)) = ?', [$wantFirst])
                        ->limit(2)->get();
                    if ($named->count() === 1) {
                        $lead = $named->first();
                    }
                }

                // 3. Nothing matched → create a fresh case for this applicant
                //    (below) rather than hijacking a same-email row.

                if (! $lead) {
                    $lead = Lead::create([
                        'lead_id' => Lead::generateLeadId(),
                        'first_name' => $intake->first_name ?? $assessment->applicant_first_name,
                        'last_name' => $lastName ?? $assessment->applicant_last_name,
                        'email' => $email,
                        'phone' => $intake->phone ?? $assessment->applicant_phone,
                        'dob' => $intake->dob ?? null,
                        'citizenship' => $intake->country_of_citizenship ?? $intake->nationality ?? null,
                        'country_of_birth' => $intake->country_of_birth ?? null,
                        'place_of_birth' => $intake->place_of_birth ?? null,
                        'passport_number' => $intake->passport_number ?? null,
                        'passport_expiry' => $intake->passport_expiry ?? null,
                        'source' => self::sourceForIntake($intake),
                        'status' => 'New Leads',
                    ]);
                }

                // Idempotent flip — preserve the original conversion
                // timestamp on a re-run. We still stamp inz_visa_type so
                // a Work-then-Resident conversion can update the visa
                // label without changing the conversion date. assessment_id
                // is always (re)linked so the case profile resolves THIS
                // exact assessment, not a same-email one.
                $patch = ['inz_visa_type' => $visaName, 'assessment_id' => $assessment->id];
                $becameCase = false;
                if (! $lead->is_immigration_case) {
                    $patch['is_immigration_case'] = true;
                    $patch['immigration_converted_at'] = now();
                    $patch['immigration_converted_by'] = auth()->id();
                    $patch['stage_updated_at'] = now();
                    $patch['stage_updated_by'] = auth()->id();
                    $becameCase = true;
                }
                $lead->fill($patch)->save();

                // Carry over any files the applicant uploaded before this
                // conversion — resident intakes store them on the intake, and
                // free-assessment / enrolment uploads live on the lead record —
                // neither is a LeadDocument, so they'd otherwise never show in
                // the case's Documents tab.
                if ($intake instanceof ResidentIntake) {
                    \App\Services\Immigration\IntakeDocumentMigrator::fromResidentIntake($intake, $lead);
                } elseif ($intake instanceof \App\Models\WorkIntake) {
                    \App\Services\Immigration\IntakeDocumentMigrator::fromWorkIntake($intake, $lead);
                } elseif ($intake instanceof \App\Models\StudentIntake
                    || $intake instanceof \App\Models\VisitorIntake
                    || $intake instanceof \App\Models\FamilyIntake) {
                    \App\Services\Immigration\IntakeDocumentMigrator::fromIntake($intake, $lead);
                }
                \App\Services\Immigration\IntakeDocumentMigrator::fromLeadUploads($lead);

                // Mark the intake "Engaged" so it drops out of the
                // triage queue. Assessment moves to "completed" so the
                // assessment lifecycle reflects the handoff.
                $intake->update(['status' => 'Engaged']);
                $assessment->update(['status' => 'completed']);

                // Only fire on a genuinely NEW conversion — a re-run (already a
                // case) must not re-notify.
                if ($becameCase) {
                    $this->fireCaseConverted($lead);
                }

                $this->recordPreScreeningConversion($intake, $lead);

                return redirect("/portal/immigration/cases/{$lead->id}/profile?tab=documents")
                    ->with('success', "Converted {$lead->first_name} to an immigration case.");
            });
        } catch (\Throwable $e) {
            Log::error('Assessment to case conversion failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not convert this assessment.');
        }
    }

    /**
     * Legacy fallback — a ResidentIntake exists but never had an
     * Assessment paired (submission predates Phase A and backfill hasn't
     * run yet). Preserves the original behaviour from before this build.
     */
    /**
     * Convert a FREE-assessment lead into an immigration case. A free assessment
     * is just a Lead with an AI eligibility score — there is no intake or paired
     * Assessment to migrate, and its enrolment uploads are already LeadDocument
     * rows, so this simply flips the case flag (idempotent on a re-run).
     */
    private function convertFreeAssessmentToCase(int $leadId)
    {
        $lead = Lead::find($leadId);
        if (! $lead) {
            return back()->with('error', 'Could not find this assessment.');
        }

        if (! $lead->is_immigration_case) {
            $lead->fill([
                'is_immigration_case' => true,
                'immigration_converted_at' => now(),
                'immigration_converted_by' => auth()->id(),
                'stage_updated_at' => now(),
                'stage_updated_by' => auth()->id(),
            ])->save();
        }

        // Free assessments pre-screen on the Lead itself (no intake record).
        $this->recordPreScreeningConversion($lead, $lead);

        return redirect("/portal/immigration/cases/{$lead->id}/profile?tab=documents")
            ->with('success', "Converted {$lead->first_name} to an immigration case.");
    }

    private function convertResidentIntakeWithoutAssessment(ResidentIntake $intake)
    {
        return DB::transaction(function () use ($intake) {
            // Match a same-email lead only when the full name also matches —
            // families share an inbox, so email alone would hijack a relative.
            $wantFirst = strtolower(trim((string) $intake->first_name));
            $wantLast = strtolower(trim((string) $intake->last_name));
            $lead = null;
            if ($intake->email && $wantFirst !== '' && $wantLast !== '') {
                $lead = Lead::where('email', $intake->email)->get()
                    ->first(fn ($l) => strtolower(trim((string) $l->first_name)) === $wantFirst
                        && strtolower(trim((string) $l->last_name)) === $wantLast);
            }
            if (! $lead) {
                $lead = Lead::create([
                    'lead_id' => Lead::generateLeadId(),
                    'first_name' => $intake->first_name,
                    'last_name' => $intake->last_name,
                    'email' => $intake->email,
                    'phone' => $intake->phone,
                    'source' => 'resident-intake',
                    'status' => 'New Leads',
                ]);
            }

            $becameCase = false;
            if (! $lead->is_immigration_case) {
                $lead->fill([
                    'is_immigration_case' => true,
                    'immigration_converted_at' => now(),
                    'immigration_converted_by' => auth()->id(),
                    'stage_updated_at' => now(),
                    'stage_updated_by' => auth()->id(),
                ])->save();
                $becameCase = true;
            }

            // Carry the applicant's assessment uploads into the case documents.
            \App\Services\Immigration\IntakeDocumentMigrator::fromResidentIntake($intake, $lead);

            $intake->update(['status' => 'Engaged']);

            if ($becameCase) {
                $this->fireCaseConverted($lead);
            }

            $this->recordPreScreeningConversion($intake, $lead);

            return redirect("/portal/immigration/cases/{$lead->id}/profile?tab=documents")
                ->with('success', "Converted {$intake->first_name} to an immigration case.");
        });
    }

    /**
     * Fire the configurable "Converted to case" email automation. A no-op
     * unless an admin enabled a message for `immigration.case.converted`
     * (client welcome and/or a notice to the case's adviser/manager/team), and
     * it never throws — so it can't break a conversion.
     */
    private function fireCaseConverted(Lead $lead): void
    {
        app(\App\Services\EmailAutomationService::class)->fire('immigration.case.converted', $lead, [
            'visa_type' => $lead->inz_visa_type ?? '',
        ]);
    }

    /** "resident-intake" / "work-intake" / "student-intake" / "visitor-intake". */
    private static function sourceForIntake($intake): string
    {
        return match ($intake::class) {
            \App\Models\ResidentIntake::class => 'resident-intake',
            \App\Models\WorkIntake::class => 'work-intake',
            \App\Models\StudentIntake::class => 'student-intake',
            \App\Models\VisitorIntake::class => 'visitor-intake',
            default => 'visa-intake',
        };
    }

    /** Assessments — public ResidentIntake submissions feed for adviser triage. */
    /**
     * Delete an assessment submission — its intake row and paired Assessment.
     * Blocked when the assessment has already been converted to a live case
     * (the case must be removed/unconverted first).
     */
    public function destroyIntake(\Illuminate\Http\Request $request)
    {
        $typeMap = [
            'resident' => ResidentIntake::class,
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];

        $data = $request->validate([
            // Which table the id belongs to. Visa drafts and free assessments
            // are Lead rows, not intake rows — the frontend marks them 'lead' so
            // we delete the right record. An intake id and a lead id can collide
            // (separate auto-increment tables), so we must NOT guess by trying
            // one then the other. Defaults to 'intake' for older callers.
            'record' => ['nullable', \Illuminate\Validation\Rule::in(['intake', 'lead'])],
            'intake_type' => ['required', 'string'],
            'intake_id' => ['required', 'integer'],
        ]);

        // Lead-backed rows: in-progress visa drafts (SavesIntakeDraft) and free
        // assessments. There is no intake row to remove — delete the Lead.
        if (($data['record'] ?? 'intake') === 'lead') {
            $lead = Lead::findOrFail($data['intake_id']);
            if ($lead->is_immigration_case) {
                return back()->with('error', 'This is already a case — delete or unconvert the case first.');
            }
            $lead->delete();

            return back()->with('success', 'Assessment deleted.');
        }

        abort_unless(isset($typeMap[$data['intake_type']]), 404);
        $cls = $typeMap[$data['intake_type']];
        $intake = $cls::findOrFail($data['intake_id']);
        $assessment = Assessment::where('intakeable_type', $cls)->where('intakeable_id', $intake->id)->first();

        if ($assessment && Lead::where('is_immigration_case', true)->where('assessment_id', $assessment->id)->exists()) {
            return back()->with('error', 'This assessment is already a case — delete or unconvert the case first.');
        }

        $assessment?->delete();
        $intake->delete();

        return back()->with('success', 'Assessment deleted.');
    }

    public function assessments()
    {
        return inertia('portal/immigration/Assessments', $this->assessmentsPayload());
    }

    /**
     * Full-page pre-screening profile for one Visa Assessment (the potential-
     * client vetting surface). Reuses the exact row the list builds — keyed by
     * the pre-screening token — so its shape, flags, links and convert action
     * match the list 1:1. The form sections / readiness are self-fetched by the
     * page from the existing intake data endpoint. Procedural, not advice.
     */
    public function assessmentProfile(\App\Models\PreScreening $preScreening)
    {
        $row = collect($this->assessmentsPayload()['intakes'])
            ->first(fn ($r) => ($r['pre_screen']['token'] ?? null) === $preScreening->token);

        abort_unless($row, 404);

        $fmtBooking = function (?\App\Models\Booking $b): ?array {
            if (! $b) {
                return null;
            }

            return [
                'id' => $b->id,
                'status' => $b->status,
                'service_type' => $b->service_type,
                'date' => optional($b->appointment_date)->toDateString(),
                'time' => $b->appointment_time,
                'at' => optional($b->appointment_at)->toIso8601String(),
                'consultant' => $b->consultant_name,
                'meet_link' => $b->meet_link,
                'manage_token' => $b->manage_token,
            ];
        };

        $plans = app(\App\Services\Immigration\InstallmentPlanService::class);

        return inertia('portal/immigration/AssessmentProfile', [
            'intake' => $row,
            // The two pre-screening meetings (consultation, then consent).
            'bookings' => [
                'consult' => $fmtBooking($preScreening->consultBooking),
                'consent' => $fmtBooking($preScreening->consentBooking),
            ],
            // Files the client uploaded from their /application link.
            'client_documents' => $this->preScreeningUploads($preScreening),
            // Installments: the live plan (if any), a fee prefill from the visa
            // type, and the config knobs (presets + deposit band).
            'plan' => $preScreening->lead ? $plans->summary($plans->activeFor($preScreening->lead)) : null,
            'fee_quote' => $this->feeQuote($preScreening),
            'installment_config' => [
                'presets' => $plans->presets(),
                'deposit' => $plans->depositBounds(),
            ],
        ]);
    }

    /** Fee prefill (GST-inclusive totals + INZ fee) for a pre-screening's visa. */
    private function feeQuote(\App\Models\PreScreening $pre, string $location = 'onshore'): array
    {
        $subject = $pre->subject;
        $visaType = null;

        if ($subject instanceof Lead) {
            $visaType = $subject->inz_visa_type
                ? \App\Models\VisaType::where('name', $subject->inz_visa_type)->first()
                : null;
        } elseif ($subject) {
            $visaType = \App\Support\IntakeVisaTypeMap::resolve($subject::class);
        }

        if (! $visaType) {
            return ['resolved' => false, 'normal_total' => 0, 'discounted_total' => 0, 'inz_fee' => 0];
        }

        $rows = collect($visaType->feeBreakdown($location));
        $normal = $rows->firstWhere('tier', 'normal');
        $disc = $rows->firstWhere('tier', 'discounted');

        return [
            'resolved' => true,
            'visa_type' => $visaType->name,
            'normal_total' => (float) ($normal['total'] ?? 0),
            'discounted_total' => (float) ($disc['total'] ?? 0),
            'inz_fee' => (float) ($normal['inz_fee'] ?? 0),
        ];
    }

    /** Documents the client uploaded via /application (the working lead's own uploads). */
    private function preScreeningUploads(\App\Models\PreScreening $pre): array
    {
        $lead = $pre->lead;
        if (! $lead) {
            return [];
        }

        return \App\Models\LeadDocument::where('lead_id', $lead->id)
            ->where('source', \App\Models\LeadDocument::SOURCE_UPLOAD)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'label' => $d->original_name,
                'ext' => strtoupper(pathinfo((string) $d->original_name, PATHINFO_EXTENSION)) ?: null,
                'url' => "/application/{$pre->token}/uploads/{$d->id}?inline=1",
            ])
            ->all();
    }

    /** Assessments-page payload (intakes). Extracted so the adviser portal can
     *  render the same Visa Assessment UI under its own chrome. */
    public function assessmentsPayload(): array
    {
        try {
            // Pre-fetch all Assessments + their Bookings for the intakes we're
            // about to show. Indexed by intakeable id so the normalizer can
            // look up the journey state without N+1 queries.
            $loadAssessments = function (string $modelClass, $intakes) {
                if ($intakes->isEmpty()) {
                    return collect();
                }

                return \App\Models\Assessment::with('booking:id,status,appointment_date,appointment_time')
                    ->where('intakeable_type', $modelClass)
                    ->whereIn('intakeable_id', $intakes->pluck('id'))
                    ->get()
                    ->keyBy('intakeable_id');
            };

            // Pre-compute which Assessment ids already map to a Lead flagged
            // as an immigration case. Matching by the exact assessment_id
            // (not email) means only the assessment actually converted shows
            // as "Converted" — a second same-email applicant's assessment
            // stays convertible on its own.
            $convertedAssessmentIds = Lead::query()
                ->where('is_immigration_case', true)
                ->whereNotNull('assessment_id')
                ->pluck('assessment_id')
                ->flip();

            // Identity keys (lower(email)|dob) of every immigration case, so a
            // fresh submission from someone who is ALREADY a case shows as
            // belonging to that case instead of a convertible duplicate — the
            // public funnels sync it in (see SyncsIntakeToCase). Email+DOB (not
            // email alone) keeps family members who share an email distinct.
            $caseIdentities = Lead::query()
                ->where('is_immigration_case', true)
                ->get(['email', 'dob'])
                ->map(fn ($c) => \App\Http\Controllers\Concerns\SyncsIntakeToCase::caseSyncIdentityKey($c->email, $c->dob))
                ->filter()
                ->flip();

            $normalize = function ($intake, string $visaType, $assessment, $review = null) use ($convertedAssessmentIds, $caseIdentities): array {
                $first = (string) ($intake->first_name ?? '');
                $last = (string) ($intake->last_name ?? $intake->family_name ?? '');
                $hasAssessment = (bool) $assessment;

                // Readiness — how COMPLETE & clean this submission is, so the
                // adviser can prioritise the ready-to-action ones. NOT an
                // eligibility/outcome signal (that would be AI-061, licence-gated).
                [$readiness, $readinessPct] = $this->readinessFor($intake, $review);

                // Triaged — staff have changed the intake status away from
                // the default "Submitted"/"submitted"/"New" set. Anything
                // else counts.
                $defaultStatuses = ['Submitted', 'submitted', 'New', 'new'];
                $isTriaged = $intake->status !== null
                    && ! in_array($intake->status, $defaultStatuses, true);

                // Converted — this exact assessment is linked to a case, the
                // intake was marked "Engaged" post-convert, OR the submitter is
                // already a case (same email + DOB): their fresh form was synced
                // into that case, so it isn't a new lead to convert.
                $identityKey = \App\Http\Controllers\Concerns\SyncsIntakeToCase::caseSyncIdentityKey($intake->email, $intake->dob);
                $belongsToCase = $identityKey !== null && isset($caseIdentities[$identityKey]);
                $isConverted = ($assessment && isset($convertedAssessmentIds[$assessment->id]))
                    || $intake->status === 'Engaged'
                    || $belongsToCase;

                return [
                    'id' => $intake->id,
                    'record' => 'intake', // deletes from the intake table
                    'assessment_id' => $assessment?->id,
                    'intake_id' => $intake->intake_id,
                    'visa_type' => $visaType, // resident | work | student | visitor
                    'name' => trim("{$first} {$last}") ?: 'Unknown',
                    'email' => $intake->email,
                    'phone' => $intake->phone,
                    'status' => $intake->status,
                    'created_at' => $intake->created_at,
                    // Readiness prioritisation (completeness-based, not outcome).
                    'readiness' => $readiness,        // ready | minor | needs_info
                    'readiness_pct' => $readinessPct, // % of the form filled in
                    'readiness_reviewed' => (bool) $review, // whether an AI review fed in
                    'extra' => $visaType === 'resident'
                        ? trim(($intake->current_visa_type ?? '').($intake->job_title ? ' · '.$intake->job_title : ''))
                        : null,
                    // Convert is available to all four visa types now,
                    // gated on (1) a paired Assessment exists, (2) the
                    // intake isn't already Engaged, (3) no existing
                    // Lead with matching email is already an
                    // immigration case.
                    // Resident intakes can convert even without a paired
                    // Assessment — the controller falls back to the
                    // intake-only path (convertResidentIntakeWithoutAssessment)
                    // so the action isn't a dead end for pre-Assessment rows.
                    'can_convert' => ($hasAssessment || $visaType === 'resident')
                        && $intake->status !== 'Engaged'
                        && ! $isConverted,
                    'detail_url' => $visaType === 'resident'
                        ? "/admin/immigration/resident-intakes/{$intake->id}"
                        : "/portal/immigration/intakes/{$visaType}/{$intake->id}",
                    // JSON of the submitted form, for the "Open" modal.
                    'data_url' => "/portal/immigration/intakes/{$visaType}/{$intake->id}/data",
                    // Three-step lifecycle: Submitted → Triaged →
                    // Converted to Case. Pay/Book are deliberately
                    // omitted while payment intake stays disabled —
                    // re-add when AssessmentController::simulatePay
                    // gets a real Stripe body.
                    'journey' => [
                        'submitted' => true, // any visible row exists
                        'submitted_at' => $intake->created_at,
                        'triaged' => $isTriaged,
                        'converted' => $isConverted,
                        'assessment_status' => $assessment?->status,
                    ],
                ];
            };

            // Pull each intake table, then their assessments in a single
            // query each.
            $resident = ResidentIntake::latest()->limit(200)->get();
            $work = \App\Models\WorkIntake::latest()->limit(200)->get();
            $student = \App\Models\StudentIntake::latest()->limit(200)->get();
            $visitor = \App\Models\VisitorIntake::latest()->limit(200)->get();
            $family = \App\Models\FamilyIntake::latest()->limit(200)->get();

            $aResident = $loadAssessments(ResidentIntake::class, $resident);
            $aWork = $loadAssessments(\App\Models\WorkIntake::class, $work);
            $aStudent = $loadAssessments(\App\Models\StudentIntake::class, $student);
            $aVisitor = $loadAssessments(\App\Models\VisitorIntake::class, $visitor);
            $aFamily = $loadAssessments(\App\Models\FamilyIntake::class, $family);

            // Latest AI review per intake (if any) — feeds the readiness signal.
            $loadReviews = function (string $modelClass, $intakes) {
                if ($intakes->isEmpty()) {
                    return collect();
                }

                return \App\Models\AssessmentAiReview::where('intakeable_type', $modelClass)
                    ->whereIn('intakeable_id', $intakes->pluck('id'))
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy('intakeable_id')
                    ->map(fn ($g) => $g->first());
            };
            $rvResident = $loadReviews(ResidentIntake::class, $resident);
            $rvWork = $loadReviews(\App\Models\WorkIntake::class, $work);
            $rvStudent = $loadReviews(\App\Models\StudentIntake::class, $student);
            $rvVisitor = $loadReviews(\App\Models\VisitorIntake::class, $visitor);
            $rvFamily = $loadReviews(\App\Models\FamilyIntake::class, $family);

            $rows = collect()
                ->concat($resident->map(fn ($r) => $normalize($r, 'resident', $aResident->get($r->id), $rvResident->get($r->id))))
                ->concat($work->map(fn ($r) => $normalize($r, 'work', $aWork->get($r->id), $rvWork->get($r->id))))
                ->concat($student->map(fn ($r) => $normalize($r, 'student', $aStudent->get($r->id), $rvStudent->get($r->id))))
                ->concat($visitor->map(fn ($r) => $normalize($r, 'visitor', $aVisitor->get($r->id), $rvVisitor->get($r->id))))
                ->concat($family->map(fn ($r) => $normalize($r, 'family', $aFamily->get($r->id), $rvFamily->get($r->id))));

            // Free-assessment submissions live on the Lead (FA-…) with the whole
            // immigration questionnaire stored as JSON columns — surface them here
            // too so an adviser reviews them alongside the visa intakes.
            // Ordered by LAST SAVE, not creation. A returning applicant keeps
            // their original lead row, so a draft filled in today can carry a
            // months-old created_at — ordering by that pushed live drafts to the
            // bottom and, past 200 rows, out of the list entirely.
            $free = Lead::whereIn('source', ['free-assessment', 'education-enrolment'])
                ->latest('updated_at')->limit(200)->get();
            $rows = $rows->concat($free->map(fn ($l) => $this->freeAssessmentRow($l)));

            // In-progress visa intakes. The five public forms auto-save here as
            // Draft leads (see SavesIntakeDraft) — they have no row in the
            // intake tables yet, so they would otherwise be invisible until the
            // applicant submits. Dropped once they do submit, because the real
            // intake row then represents them under the same visa tab.
            $submittedEmails = $rows->pluck('email')->filter()
                ->map(fn ($e) => mb_strtolower(trim((string) $e)))->flip();

            $drafts = Lead::whereIn('source', array_values(\App\Support\VisaIntakeDraft::SOURCES))
                ->where('status', 'Draft')
                ->latest('updated_at')->limit(200)->get();

            // Pre-screening hub (the token for the /application/{token} client
            // link + the vetting stage) for every assessment entry — submitted
            // intakes and draft/free Leads alike. Lazily created the first time
            // an entry is seen here and keyed back to each row so the module can
            // show the link + the pre-screen stage without per-row queries.
            $preSvc = app(\App\Services\Immigration\PreScreeningService::class);
            $preByKey = [];
            $ensurePre = function ($models, string $record, ?string $visaType) use ($preSvc, &$preByKey) {
                if ($models->isEmpty()) {
                    return;
                }
                $map = $preSvc->ensureMany($models);
                foreach ($models as $m) {
                    $ps = $map["{$m->getMorphClass()}:{$m->getKey()}"] ?? null;
                    if (! $ps) {
                        continue;
                    }
                    $key = $record === 'intake' ? "intake:{$visaType}:{$m->getKey()}" : "lead:{$m->getKey()}";
                    $preByKey[$key] = $preSvc->summary($ps);
                }
            };
            $ensurePre($resident, 'intake', 'resident');
            $ensurePre($work, 'intake', 'work');
            $ensurePre($student, 'intake', 'student');
            $ensurePre($visitor, 'intake', 'visitor');
            $ensurePre($family, 'intake', 'family');
            $ensurePre($free, 'lead', null);
            $ensurePre($drafts, 'lead', null);

            $rows = $rows->concat(
                $drafts
                    ->reject(fn (Lead $l) => isset($submittedEmails[mb_strtolower(trim((string) $l->email))]))
                    ->map(fn (Lead $l) => $this->visaDraftRow($l))
                    ->filter()
            );

            // Sort on last activity where we have it (free assessments), falling
            // back to arrival time for the visa intakes, whose updated_at moves
            // whenever staff triage them and would reshuffle the queue.
            $intakes = $rows->sortByDesc(fn ($r) => $r['saved_at'] ?? $r['created_at'])
                ->map(function ($r) use ($preByKey) {
                    $key = ($r['record'] ?? '') === 'intake'
                        ? "intake:{$r['visa_type']}:{$r['id']}"
                        : "lead:{$r['id']}";
                    $r['pre_screen'] = $preByKey[$key] ?? null;
                    $r['application_link'] = $preByKey[$key]['link'] ?? null;

                    return $r;
                })
                ->values();

            return ['intakes' => $intakes];
        } catch (\Throwable $e) {
            Log::error('Immigration assessments page failed', ['error' => $e->getMessage()]);

            return ['intakes' => []];
        }
    }

    /**
     * Generic intake viewer for the Work / Student / Visitor visa types.
     * Resident intakes already have their own dedicated detail page
     * (ResidentIntakeController@adminShow) — this method handles the
     * other three so the Assessments page rows are clickable across
     * every visa type. The frontend reads the intake row + its paired
     * Assessment + Booking and renders a clean property-row layout.
     */
    /**
     * Assessments-list row for an in-progress visa intake (a Draft lead saved
     * by one of the five public forms). Shaped exactly like a real intake row
     * so it sorts and filters alongside them, but with no assessment, no
     * convert action and no detail page — there is no intake record yet.
     */
    private function visaDraftRow(Lead $l): ?array
    {
        $visaType = \App\Support\VisaIntakeDraft::visaTypeForSource($l->source);
        if (! $visaType) {
            return null;
        }

        [$filled, $total, $pct] = \App\Support\VisaIntakeDraft::readiness(
            is_array($l->ai_analysis) ? $l->ai_analysis : []
        );
        $tier = $pct >= 80 ? 'ready' : ($pct >= 55 ? 'minor' : 'needs_info');

        return [
            'id' => $l->id,
            'record' => 'lead', // draft lives on the Lead, not an intake row
            'assessment_id' => null,
            'intake_id' => $l->lead_id,
            'visa_type' => $visaType,
            'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
            'email' => $l->email,
            'phone' => $l->phone,
            'status' => 'Draft',
            'created_at' => $l->created_at,
            // Last autosave — what "Saved 4 Sep" means on a draft, and what the
            // list sorts on so a form being filled in right now surfaces.
            'saved_at' => $l->updated_at,
            'readiness' => $tier,
            'readiness_pct' => $pct,
            'readiness_reviewed' => false,
            'extra' => "{$filled} of {$total} answers so far",
            // Nothing to convert or open yet: the applicant has not submitted,
            // so no intake row exists. The lead record is all there is.
            'can_convert' => false,
            'detail_url' => "/admin/leads/{$l->id}",
            'data_url' => null,
            'journey' => [
                'submitted' => false,
                'submitted_at' => null,
                'triaged' => false,
                'converted' => false,
                'assessment_status' => null,
            ],
        ];
    }

    /** Assessments-list row for a free-assessment Lead (visa_type "free"). */
    private function freeAssessmentRow(Lead $l): array
    {
        [$sections] = $this->freeAssessmentSections($l);
        $all = collect($sections)->flatMap(fn ($s) => $s['fields']);
        $total = $all->count();
        $filled = $all->where('provided', true)->count();
        $pct = $total > 0 ? (int) round($filled / $total * 100) : 0;
        $tier = $pct >= 80 ? 'ready' : ($pct >= 55 ? 'minor' : 'needs_info');

        // A half-filled assessment is saved as a Draft; only the final submit
        // flips it to Submitted. The frontend buckets Draft vs Submitted off
        // journey.submitted, so it must reflect the real status — not always true.
        $isDraft = $l->status === 'Draft';

        return [
            'id' => $l->id,
            'record' => 'lead', // free assessment lives on the Lead
            'assessment_id' => null,
            'intake_id' => $l->lead_id,
            'visa_type' => 'free',
            'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
            'email' => $l->email,
            'phone' => $l->phone,
            'status' => $l->status,
            'created_at' => $l->created_at,
            // Drafts only. A draft is rewritten on every autosave, so this —
            // not created_at — is what "Saved 4 Sep" means, and it is what the
            // list sorts on so a form someone is filling in today surfaces.
            //
            // Deliberately null once submitted: updated_at then moves on every
            // staff edit, which would creep an old submission forward to
            // "just now". Submitted rows keep sorting and displaying by
            // created_at, which in practice is within minutes of the submit.
            'saved_at' => $isDraft ? $l->updated_at : null,
            'readiness' => $tier,
            'readiness_pct' => $pct,
            'readiness_reviewed' => false,
            'extra' => null,
            // Free assessments can be converted straight to a case (the /free-
            // assessment funnel is an immigration enquiry). Hidden once it's
            // already a case so it can't be double-converted.
            'can_convert' => ! $l->is_immigration_case,
            'detail_url' => "/admin/leads/{$l->id}",
            'data_url' => "/portal/immigration/assessments/free/{$l->id}/data",
            'journey' => [
                'submitted' => ! $isDraft,
                'submitted_at' => $isDraft ? null : $l->created_at,
                'triaged' => $l->status !== null && ! in_array($l->status, ['Draft', 'Submitted', 'submitted', 'New', 'new'], true),
                'converted' => (bool) $l->is_immigration_case,
                'assessment_status' => null,
            ],
        ];
    }

    /**
     * Build the free-assessment Lead's stored questionnaire into official-form
     * sections. Returns [sections, filled/total via the caller].
     *
     * @return array{0: array<int, array{title:string,fields:array}>}
     */
    private function freeAssessmentSections(Lead $l): array
    {
        $l->loadMissing(['studyPlans', 'educationExps']);

        // One label/value field — blank shown as "—" so every form field appears.
        // Personal-detail fields map 1:1 to Lead columns and are inline-editable;
        // study-plan / education fields come from relations / JSON so stay read-only.
        $editableCols = self::FREE_EDITABLE_COLUMNS;
        $field = function (string $key, string $label, $val) use ($editableCols) {
            $v = $this->formatIntakeValue($val);
            $prov = ! ($v === null || $v === '');

            return [
                'key' => $key, 'label' => $label,
                'value' => $prov ? $v : '—',
                'raw' => $this->rawIntakeValue($val),
                'editable' => in_array($key, $editableCols, true),
                'provided' => $prov,
            ];
        };
        // Normalise a stored value (array cast OR raw JSON string) to an array.
        $asArray = function ($v) {
            if (is_string($v)) {
                $d = json_decode($v, true);

                return is_array($d) ? $d : [];
            }

            return is_array($v) ? $v : [];
        };
        // Build a group from an ordered [key => label] schema against a stored
        // array — every schema field is emitted, blank when the key is missing,
        // so the whole free-assessment form displays even when unanswered.
        $mapGroup = function (array $schema, $stored) use ($field, $asArray) {
            $stored = $asArray($stored);
            $out = [];
            foreach ($schema as $key => $label) {
                $out[] = $field($key, $label, $stored[$key] ?? null);
            }

            return $out;
        };

        $edu = $asArray($l->education_notes);
        $plan = $l->studyPlans->first();

        $sections = [];

        $sections[] = ['title' => 'Personal Details', 'fields' => [
            $field('dob', 'Date of Birth', $l->dob),
            $field('gender', 'Gender', $l->gender),
            $field('marital_status', 'Marital Status', $l->marital_status),
            $field('other_names', 'Other Names Used', $l->other_names),
            $field('country_of_birth', 'Country of Birth', $l->country_of_birth),
            $field('place_of_birth', 'Place of Birth', $l->place_of_birth),
            $field('citizenship', 'Country of Citizenship', $l->citizenship),
            $field('residence_city', 'City of Residence', $l->residence_city),
            $field('residence_state', 'State / Region of Residence', $l->residence_state),
            $field('residence_country', 'Country of Residence', $l->residence_country),
            $field('has_passport', 'Has a Valid Passport', $l->has_passport),
            $field('passport_number', 'Passport Number', $l->passport_number),
            $field('passport_expiry', 'Passport Expiry', $l->passport_expiry),
        ]];

        $sections[] = ['title' => 'Study Plan', 'fields' => [
            $field('preferred_course', 'Preferred Course', $plan?->preferred_course),
            $field('qualification_level', 'Qualification Level', $plan?->qualification_level),
            $field('preferred_city', 'Preferred City', $plan?->preferred_city),
            $field('preferred_intake', 'Preferred Intake', $plan?->preferred_intake),
            $field('has_english_test', 'Has an English Test', $plan?->has_english_test),
            $field('english_test_type', 'English Test Type', $plan?->english_test_type),
            $field('test_score_overall', 'Overall Score', $plan?->test_score_overall),
            $field('test_date', 'Test Date', $plan?->test_date),
        ]];

        $eduFields = $mapGroup([
            'high_school_completed' => 'High School Completed',
            'high_school_level' => 'Highest Level Completed',
            'high_school_institution' => 'High School Institution',
            'high_school_start' => 'High School Start',
            'high_school_end' => 'High School End',
            'high_school_marks' => 'High School Marks',
            'has_gap' => 'Has a Study / Work Gap',
            'gap_length' => 'Gap Length',
        ], $edu);
        foreach ($l->educationExps as $i => $ex) {
            $lvl = $ex->level ?: ('Qualification '.($i + 1));
            $eduFields[] = $field("edu_{$i}_field", "{$lvl} — Field of Study", $ex->field_of_study);
            $eduFields[] = $field("edu_{$i}_inst", "{$lvl} — Institution", $ex->institution);
            $eduFields[] = $field("edu_{$i}_marks", "{$lvl} — Marks", $ex->average_marks);
        }
        $sections[] = ['title' => 'Education', 'fields' => $eduFields];

        $work = $asArray($l->work_info);
        $workFields = [];
        if (empty($work)) {
            $workFields[] = $field('work_none', 'Work Experience', null);
        } else {
            foreach ($work as $i => $job) {
                $n = $i + 1;
                $workFields[] = $field("work_{$i}_company", "Job {$n} — Company", $job['company_name'] ?? null);
                $workFields[] = $field("work_{$i}_title", "Job {$n} — Title", $job['job_title'] ?? null);
                $workFields[] = $field("work_{$i}_start", "Job {$n} — Start", $job['start_date'] ?? null);
                $workFields[] = $field("work_{$i}_end", "Job {$n} — End", $job['end_date'] ?? null);
                $workFields[] = $field("work_{$i}_current", "Job {$n} — Current Role", $job['is_current'] ?? null);
                $workFields[] = $field("work_{$i}_duties", "Job {$n} — Duties", $job['duties'] ?? null);
            }
        }
        $sections[] = ['title' => 'Work Experience', 'fields' => $workFields];

        $sections[] = ['title' => 'Financial', 'fields' => $mapGroup([
            'can_cover_tuition' => 'Can Cover Tuition',
            'can_cover_living' => 'Can Cover Living Costs',
            'funding_source' => 'Funding Source',
            'estimated_budget' => 'Estimated Budget',
            'has_sponsors' => 'Has Sponsors',
            'sponsor_relation' => 'Sponsor Relationship',
        ], $l->financial_info)];

        $sections[] = ['title' => 'Source of Funds', 'fields' => $mapGroup([
            'sources' => 'Sources of Funds',
            'will_self_fund' => 'Will Self-Fund',
            'will_use_sponsor' => 'Will Use a Sponsor',
            'sponsor_relation' => 'Sponsor Relationship',
            'sponsor_nz_based' => 'Sponsor is NZ-Based',
            'sponsor_nz_resident' => 'Sponsor is a NZ Resident',
            'sponsor_occupation' => 'Sponsor Occupation',
            'sponsor_employer' => 'Sponsor Employer',
            'sponsor_annual_income' => 'Sponsor Annual Income',
            'sponsor_source_of_funds' => "Sponsor's Source of Funds",
        ], $l->source_of_funds_info)];

        $sections[] = ['title' => 'Immigration & Travel', 'fields' => $mapGroup([
            'has_travelled_overseas' => 'Has Travelled Overseas',
            'overseas_travel_details' => 'Overseas Travel Details',
            'has_applied_nz_visa' => 'Applied for a NZ Visa Before',
            'nz_visa_details' => 'NZ Visa Details',
            'total_nz_time_24_months' => 'Total NZ Time 24 Months or More',
            'has_applied_other_visa' => 'Applied for Another Country Visa',
            'other_visa_details' => 'Other Visa Details',
            'has_visa_refusal' => 'Has a Visa Refusal',
            'visa_refusal_details' => 'Visa Refusal Details',
            'submission_country' => 'Country When Submitting',
        ], $l->immigration_info)];

        $sections[] = ['title' => 'Character', 'fields' => $mapGroup([
            'has_conviction' => 'Has a Conviction',
            'under_investigation' => 'Under Investigation',
            'has_deportation' => 'Has a Deportation',
            'has_visa_refusal_other' => 'Refused a Visa by Another Country',
            'lived_5_years_since_17' => 'Lived 5+ Years in Another Country Since 17',
        ], $l->character_info)];

        $sections[] = ['title' => 'Health', 'fields' => $mapGroup([
            'has_tuberculosis' => 'Has Tuberculosis',
            'has_renal_dialysis' => 'Needs Renal Dialysis',
            'needs_hospital_care' => 'Needs Hospital Care',
            'needs_residential_care' => 'Needs Residential Care',
            'is_pregnant' => 'Is Pregnant',
        ], $l->health_info)];

        $members = $asArray($l->family_info)['members'] ?? [];
        $famFields = [];
        if (empty($members) || ! is_array($members)) {
            $famFields[] = $field('family_none', 'Family Members', null);
        } else {
            foreach ($members as $i => $m) {
                $rel = $m['relation'] ?? ('Member '.($i + 1));
                $name = trim(($m['first_name'] ?? '').' '.($m['family_name'] ?? ''));
                $famFields[] = $field("fam_{$i}_name", "{$rel} — Name", $name);
                $famFields[] = $field("fam_{$i}_dob", "{$rel} — Date of Birth", $m['dob'] ?? null);
                $famFields[] = $field("fam_{$i}_status", "{$rel} — Partnership Status", $m['partnership_status'] ?? null);
                $famFields[] = $field("fam_{$i}_residence", "{$rel} — Country of Residence", $m['country_of_residence'] ?? null);
                $famFields[] = $field("fam_{$i}_occupation", "{$rel} — Occupation", $m['occupation'] ?? null);
            }
        }
        $sections[] = ['title' => 'Family', 'fields' => $famFields];

        $sections[] = ['title' => 'NZ Contacts', 'fields' => $mapGroup([
            'has_nz_contacts' => 'Has NZ Contacts',
            'contact_first_name' => 'Contact First Name',
            'contact_family_name' => 'Contact Family Name',
            'contact_relationship' => 'Contact Relationship',
            'contact_address' => 'Contact Address',
            'contact_number' => 'Contact Number',
        ], $l->nz_contacts_info)];

        $sections[] = ['title' => 'Military Service', 'fields' => $mapGroup([
            'military_compulsory' => 'Military Service Was Compulsory',
            'has_military_service' => 'Has Undertaken Military Service',
        ], $l->military_info)];

        $sections[] = ['title' => 'Home Ties', 'fields' => $mapGroup([
            'family_owns_property' => 'Family Owns Property',
            'property_type' => 'Property Type',
            'property_location' => 'Property Location',
            'property_owner' => 'Property Owner',
            'family_owns_business' => 'Family Owns Business',
            'business_type' => 'Business Type',
            'business_involvement' => 'Business Involvement',
        ], $l->home_ties_info)];

        return [$sections];
    }

    /** JSON of a free-assessment Lead's submission for the "Open" modal. */
    public function freeAssessmentData($id)
    {
        $l = Lead::findOrFail($id);
        [$sections] = $this->freeAssessmentSections($l);

        $all = collect($sections)->flatMap(fn ($s) => $s['fields']);
        $total = $all->count();
        $filled = $all->where('provided', true)->count();
        $pct = $total > 0 ? (int) round($filled / $total * 100) : 0;
        if ($pct >= 80) {
            [$verdict, $tone, $rec] = ['Ready to work up', 'emerald', 'The questionnaire is largely complete — good to begin the adviser work-up.'];
        } elseif ($pct >= 55) {
            [$verdict, $tone, $rec] = ['Nearly there', 'teal', 'Most of the questionnaire is answered. Follow up on the remaining gaps.'];
        } elseif ($pct >= 30) {
            [$verdict, $tone, $rec] = ['Incomplete', 'amber', 'Several sections are blank. Follow up with the applicant.'];
        } else {
            [$verdict, $tone, $rec] = ['Very sparse', 'gray', 'Only a little was submitted.'];
        }

        return response()->json([
            'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Applicant',
            'email' => $l->email,
            'phone' => $l->phone,
            'reference' => $l->lead_id,
            'visa_label' => 'Free Assessment',
            'submitted_at' => optional($l->created_at)->toIso8601String(),
            'detail_url' => "/admin/leads/{$l->id}",
            'sections' => $sections,
            'flags' => [],
            'readiness' => ['filled' => $filled, 'total' => $total, 'pct' => $pct, 'verdict' => $verdict, 'tone' => $tone, 'recommendation' => $rec],
            'ai_review' => null,
            'notes' => $this->assessmentNotesFor(\App\Models\Lead::class, $l->id),
        ]);
    }

    /** Raw, input-friendly value for inline editing (dates → Y-m-d; arrays skipped). */
    private function rawIntakeValue($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v)) {
            return null;
        }

        return $v === null ? '' : (string) $v;
    }

    /**
     * Staff inline edit of an intake's form fields from the assessment modal.
     * Only columns that appear in this visa type's form schema are writable, so
     * internal / system columns can never be touched. Returns the same payload
     * as intakeData() so the modal refreshes in place.
     */
    public function updateIntakeFields(Request $request, string $type, int $id)
    {
        $data = $request->validate(['fields' => 'required|array']);

        // Free assessment — a Lead, not an intake. Its editable Personal-detail
        // fields map 1:1 to Lead columns (whitelisted), so update those directly.
        if ($type === 'free') {
            $lead = Lead::findOrFail($id);
            $changed = false;
            foreach ((array) $data['fields'] as $key => $val) {
                if (! in_array($key, self::FREE_EDITABLE_COLUMNS, true)) {
                    continue;
                }
                $lead->{$key} = ($val === '' || $val === null) ? null : $val;
                $changed = true;
            }
            if ($changed) {
                $lead->save();
            }

            return $this->freeAssessmentData($id);
        }

        $modelMap = [
            'resident' => \App\Models\ResidentIntake::class,
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];
        abort_unless(isset($modelMap[$type]), 404, 'Unknown intake type.');

        $intake = $modelMap[$type]::findOrFail($id);

        // Whitelist: only the real form fields for this visa type may be written.
        $allowed = collect($this->intakeSectionSchema($type))->flatten()->filter()->all();
        $attrs = $intake->getAttributes();

        $changed = false;
        foreach ((array) $data['fields'] as $key => $val) {
            if (! in_array($key, $allowed, true) || ! array_key_exists($key, $attrs)) {
                continue; // silently drop anything that isn't a whitelisted field
            }
            // A blank clears the field back to null.
            $intake->{$key} = ($val === '' || $val === null) ? null : $val;
            $changed = true;
        }

        if ($changed) {
            $intake->save();
        }

        return $this->intakeData($type, $id);
    }

    /**
     * The client's submitted visa-interest form as JSON — every filled field,
     * humanised — for the Assessments "Open" modal. Read-only; no side effects.
     */
    public function intakeData(string $type, int $id)
    {
        $modelMap = [
            'resident' => \App\Models\ResidentIntake::class,
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];
        abort_unless(isset($modelMap[$type]), 404, 'Unknown intake type.');

        $intake = $modelMap[$type]::findOrFail($id);

        // Internal / non-form columns never shown to the reviewer. Name, email
        // and phone live in the modal header, so they're skipped here too.
        $skip = [
            'id', 'created_at', 'updated_at', 'deleted_at', 'status', 'intake_id',
            'assessment_id', 'edit_token', 'edit_token_expires_at', 'ip_address',
            'user_agent', 'first_name', 'last_name', 'family_name', 'email', 'phone',
            'payment_status', 'payment_amount', 'payment_ref', 'paid', 'paid_at',
            'payment_session_id', 'payment_amount_cents', 'payment_currency', 'booking_id',
            'terms_accepted',
        ];

        // Mirror the PUBLIC landing-page form for this visa type: identical
        // section order and field order, with every field shown even when blank
        // ("—") so the assessment reads like the official form the client filled,
        // not just the answered fields.
        $attrs = $intake->getAttributes();
        $used = [];
        $sections = [];
        foreach ($this->intakeSectionSchema($type) as $title => $columns) {
            $fields = [];
            foreach ($columns as $col) {
                if (! array_key_exists($col, $attrs)) {
                    continue; // column not present on this model — skip defensively
                }
                $used[$col] = true;
                $rawVal = $intake->{$col};
                $value = $this->formatIntakeValue($rawVal);
                $provided = ! ($value === null || $value === '');
                $fields[] = [
                    'key' => $col,
                    'label' => $this->intakeFieldLabel($col),
                    'value' => $provided ? $value : '—',
                    // Raw value + editability for inline "Edit section" in the modal.
                    'raw' => $this->rawIntakeValue($rawVal),
                    'editable' => ! is_array($rawVal),
                    'provided' => $provided,
                ];
            }
            if (! empty($fields)) {
                $sections[] = ['title' => $title, 'fields' => $fields];
            }
        }

        // Anything submitted that the schema didn't place (a column with no form
        // field) is appended so nothing is silently dropped — but only when it
        // actually holds a value, to avoid cluttering with always-empty columns.
        $extra = [];
        foreach ($attrs as $key => $_) {
            if (isset($used[$key]) || in_array($key, $skip, true) || str_ends_with($key, '_path') || str_ends_with($key, '_token')) {
                continue;
            }
            $value = $this->formatIntakeValue($intake->{$key});
            if ($value === null || $value === '') {
                continue;
            }
            $extra[] = ['key' => $key, 'label' => $this->intakeFieldLabel($key), 'value' => $value, 'raw' => null, 'editable' => false, 'provided' => true];
        }
        if (! empty($extra)) {
            $sections[] = ['title' => 'Other details', 'fields' => $extra];
        }

        $review = \App\Models\AssessmentAiReview::latestFor($intake::class, $intake->id);
        $flags = $this->adviserFlags($intake);

        // The files the applicant uploaded with this submission — a flat, viewable
        // list for the "Documents" tab. Each entry streams inline (viewable) from
        // the PRIVATE disk via the role-gated download route for its intake type.
        $docFiles = is_array($intake->document_files ?? null) ? $intake->document_files : [];
        $docLabels = [
            'passport' => 'Passport (all pages)', 'visa_copies' => 'All NZ visa copies',
            'contracts' => 'NZ employment contracts + JD', 'payslips' => 'Payslips — first 2 mo + latest 1 mo',
            'ird_summary' => 'IRD summary of earnings (monthly)', 'education_certs' => 'Education certificates / transcripts',
            'cv' => 'CV (NZ + overseas history)', 'other' => 'Other supporting documents',
            'job_offer' => 'Job Offer', 'job_token' => 'Job Token / Job Check',
            'employment_contract' => 'Employment Contract', 'valid_pcc' => 'Valid PCC',
            'current_nz_visa' => 'Current NZ Visa Copy', 'anzsco_skills' => 'ANZSCO Skills 3/4/5 evidence',
            'english_test' => 'English Proficiency Test Result', 'ird_earnings' => 'IRD Earnings Summary',
        ];
        $documents = [];
        foreach ($docFiles as $key => $entry) {
            $paths = array_values(is_array($entry) ? $entry : [$entry]);
            $label = $docLabels[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
            foreach ($paths as $idx => $p) {
                if (empty($p)) {
                    continue;
                }
                $documents[] = [
                    'label' => $label.(count($paths) > 1 ? ' ('.($idx + 1).')' : ''),
                    'ext' => strtoupper(pathinfo($p, PATHINFO_EXTENSION) ?: 'PDF'),
                    'url' => $type === 'resident'
                        ? "/admin/immigration/resident-intakes/{$intake->id}/documents/{$key}/{$idx}"
                        : "/admin/immigration/intakes/{$type}/{$intake->id}/documents/{$key}/{$idx}",
                ];
            }
        }
        $documentsCount = count($documents);

        return response()->json([
            'name' => trim(($intake->first_name ?? '').' '.($intake->last_name ?? $intake->family_name ?? '')) ?: 'Applicant',
            'email' => $intake->email,
            'phone' => $intake->phone,
            'reference' => $intake->intake_id,
            'visa_label' => \App\Support\IntakeVisaTypeMap::label($intake::class),
            'submitted_at' => optional($intake->created_at)->toIso8601String(),
            'documents_count' => $documentsCount,
            'documents' => $documents,
            'detail_url' => $type === 'resident'
                ? "/admin/immigration/resident-intakes/{$intake->id}"
                : "/portal/immigration/intakes/{$type}/{$intake->id}",
            'sections' => $sections,
            // Deterministic adviser-style checks on the actual data (passport /
            // visa expiry, English level…) — reliable, computed, not AI-guessed.
            'flags' => $flags,
            // Overall adviser verdict — completeness + a plain recommendation.
            'readiness' => $this->intakeReadiness($intake, $flags),
            // AI review snapshot — softer, adviser-style analysis of the rest.
            'ai_review' => $this->serializeAiReview($review),
            // Attributed internal notes — who noted what on this assessment.
            'notes' => $this->assessmentNotesFor($intake::class, $intake->id),
        ]);
    }

    /**
     * An adviser-style overall verdict on the submission: how complete it is and
     * a plain recommendation on whether it's ready to work up.
     *
     * @param  array<int, array{field:string,severity:string,note:string}>  $flags
     */
    private function intakeReadiness($intake, array $flags): array
    {
        [$filled, $total] = $this->intakeFieldStats($intake);
        $pct = $total > 0 ? (int) round($filled / $total * 100) : 0;
        $hasCritical = collect($flags)->contains(fn ($f) => $f['severity'] === 'critical');

        if ($hasCritical) {
            [$verdict, $tone, $rec] = ['Attention needed', 'red', 'A time-critical issue was found (passport or visa validity). Resolve it before proceeding.'];
        } elseif ($pct >= 80) {
            [$verdict, $tone, $rec] = ['Ready to work up', 'emerald', 'The form is largely complete — good to begin the adviser work-up.'];
        } elseif ($pct >= 55) {
            [$verdict, $tone, $rec] = ['Nearly there', 'teal', 'Most of the form is answered. Chase the remaining gaps before lodging.'];
        } elseif ($pct >= 30) {
            [$verdict, $tone, $rec] = ['Incomplete', 'amber', 'Several key sections are blank. Request the missing information from the applicant.'];
        } else {
            [$verdict, $tone, $rec] = ['Very sparse', 'gray', 'Only a little was submitted. Ask the applicant to complete the assessment form.'];
        }

        return [
            'filled' => $filled,
            'total' => $total,
            'pct' => $pct,
            'verdict' => $verdict,
            'tone' => $tone,
            'recommendation' => $rec,
        ];
    }

    /**
     * Deterministic, adviser-style checks on a submission's actual values —
     * things a Licensed Immigration Adviser eyeballs first (passport / visa
     * validity, English level). Each flag references a field label so the form
     * can highlight it. Reliable (computed), unlike the AI's softer read.
     *
     * @return array<int, array{field:string,severity:string,note:string}>
     */
    private function adviserFlags($intake): array
    {
        $flags = [];
        $now = now();
        $date = function ($v) {
            if (! $v) {
                return null;
            }
            try {
                return \Illuminate\Support\Carbon::parse($v);
            } catch (\Throwable $e) {
                return null;
            }
        };

        // Passport validity — the first thing to check before any lodgement.
        if ($pe = $date($intake->passport_expiry ?? null)) {
            $m = (int) round($now->diffInMonths($pe));
            if ($pe->isPast()) {
                $flags[] = ['field' => 'Passport expiry', 'severity' => 'critical', 'note' => 'Passport has expired — a valid passport is required to lodge.'];
            } elseif ($m <= 6) {
                $flags[] = ['field' => 'Passport expiry', 'severity' => 'critical', 'note' => "Passport expires in about {$m} month(s) — likely needs renewal before lodgement."];
            } elseif ($m <= 12) {
                $flags[] = ['field' => 'Passport expiry', 'severity' => 'warning', 'note' => "Passport expires in about {$m} month(s) — keep validity in view."];
            }
        }

        // Current visa validity — lawful status / timing of the application.
        foreach (['current_visa_expiry', 'visa_expiry', 'current_nz_visa_expiry'] as $k) {
            if (! ($d = $date($intake->{$k} ?? null))) {
                continue;
            }
            $m = (int) round($now->diffInMonths($d));
            $label = \Illuminate\Support\Str::headline($k);
            if ($d->isPast()) {
                $flags[] = ['field' => $label, 'severity' => 'critical', 'note' => 'Current visa appears expired — confirm the applicant\'s lawful status.'];
            } elseif ($m <= 3) {
                $flags[] = ['field' => $label, 'severity' => 'critical', 'note' => "Current visa expires in about {$m} month(s) — timing is tight, plan lodgement."];
            } elseif ($m <= 6) {
                $flags[] = ['field' => $label, 'severity' => 'warning', 'note' => "Current visa expires in about {$m} month(s)."];
            }
            break;
        }

        // English evidence — flag if it reads below the common bar (IELTS 6.5).
        $eng = $intake->english_evidence ?? $intake->english_test ?? $intake->english_score ?? null;
        if ($eng && preg_match('/(\d+(?:\.\d+)?)/', (string) $eng, $mm)) {
            $score = (float) $mm[1];
            if ($score > 0 && $score < 6.5 && $score <= 9) {
                $flags[] = ['field' => 'English evidence', 'severity' => 'warning', 'note' => "Reads as {$eng} — may be below the level some categories require (commonly IELTS 6.5)."];
            }
        }

        return $flags;
    }

    /**
     * [filled, total] over exactly the fields the review modal displays — the
     * single source of "form completeness" so the list Priority and the modal
     * verdict never disagree.
     *
     * @return array{0:int,1:int}
     */
    private function intakeFieldStats($intake): array
    {
        $attrs = $intake->getAttributes();

        // Prefer the form schema so completeness matches exactly what the review
        // modal displays (and the list and modal stay in agreement).
        $type = $this->intakeTypeFor($intake);
        if ($type && ($schema = $this->intakeSectionSchema($type))) {
            $total = 0;
            $filled = 0;
            foreach ($schema as $columns) {
                foreach ($columns as $col) {
                    if (! array_key_exists($col, $attrs)) {
                        continue;
                    }
                    $total++;
                    $value = $this->formatIntakeValue($intake->{$col});
                    if (! ($value === null || $value === '')) {
                        $filled++;
                    }
                }
            }

            return [$filled, $total];
        }

        // Fallback for any type without a schema — count all form-ish columns.
        $skip = [
            'id', 'created_at', 'updated_at', 'deleted_at', 'status', 'intake_id',
            'assessment_id', 'edit_token', 'edit_token_expires_at', 'ip_address',
            'user_agent', 'first_name', 'last_name', 'family_name', 'email', 'phone',
            'payment_status', 'payment_amount', 'payment_ref', 'paid', 'paid_at',
        ];
        $total = 0;
        $filled = 0;
        foreach ($attrs as $key => $_) {
            if (in_array($key, $skip, true) || str_ends_with($key, '_path') || str_ends_with($key, '_token')) {
                continue;
            }
            $total++;
            $value = $this->formatIntakeValue($intake->{$key});
            if (! ($value === null || $value === '')) {
                $filled++;
            }
        }

        return [$filled, $total];
    }

    /**
     * Resolve an assessment "notable" (a visa intake OR a free-assessment Lead)
     * from a type slug + id. Returns [morphType, morphId].
     */
    private function assessmentNotable(string $type, int $id): array
    {
        if ($type === 'free') {
            $lead = Lead::findOrFail($id);

            return [\App\Models\Lead::class, $lead->id];
        }

        $map = [
            'resident' => \App\Models\ResidentIntake::class,
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];
        abort_unless(isset($map[$type]), 404, 'Unknown assessment type.');
        $intake = $map[$type]::findOrFail($id);

        return [$map[$type], $intake->id];
    }

    /** Serialise the attributed internal notes for an assessment, newest first. */
    private function assessmentNotesFor(string $notableType, int $notableId): array
    {
        return \App\Models\AssessmentNote::with('author:id,name,role')
            ->where('notable_type', $notableType)
            ->where('notable_id', $notableId)
            ->latest()
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'body' => $n->body,
                'author' => $n->author_name ?: (optional($n->author)->name ?: 'Staff'),
                'role' => $n->author_role ?: optional($n->author)->role,
                'at' => optional($n->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /** Add an attributed internal note to an assessment (intake or free lead). */
    public function assessmentNoteStore(Request $request, string $type, int $id)
    {
        $data = $request->validate(['body' => 'required|string|max:8000']);
        [$notableType, $notableId] = $this->assessmentNotable($type, $id);

        $user = $request->user();
        $note = \App\Models\AssessmentNote::create([
            'notable_type' => $notableType,
            'notable_id' => $notableId,
            'user_id' => $user->id,
            'author_name' => $user->name,
            'author_role' => $user->role,
            'body' => $data['body'],
        ]);

        return response()->json(['note' => [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $user->name,
            'role' => $user->role,
            'at' => optional($note->created_at)->toIso8601String(),
        ]]);
    }

    /** Resolve the intake type slug from a model instance. */
    private function intakeTypeFor($intake): ?string
    {
        return match ($intake::class) {
            \App\Models\ResidentIntake::class => 'resident',
            \App\Models\WorkIntake::class => 'work',
            \App\Models\StudentIntake::class => 'student',
            \App\Models\VisitorIntake::class => 'visitor',
            \App\Models\FamilyIntake::class => 'family',
            default => null,
        };
    }

    /**
     * The section → ordered-columns layout for each visa type, mirroring the
     * public landing-page intake form so the reviewer sees the same sections in
     * the same order. Columns absent on a given model are skipped by the caller.
     *
     * @return array<string, array<int, string>>
     */
    public function intakeSectionSchema(string $type): array
    {
        return match ($type) {
            'resident' => [
                'Personal Details' => ['dob', 'nationality'],
                'Passport & Visa' => ['passport_number', 'passport_expiry', 'issuing_country', 'current_visa_type', 'current_visa_other', 'current_visa_expiry', 'nz_arrival_date', 'previous_nz_visa_history'],
                'Employment' => ['job_title', 'employment_start', 'employment_type', 'hourly_rate'],
                'Qualifications' => ['highest_qualification', 'institution_name', 'country_of_study', 'nzqa_status', 'nzqa_iqa_reference'],
                'Work Experience' => ['nz_skilled_years', 'total_skilled_years', 'career_summary'],
                'English & Family' => ['english_evidence', 'english_test_score', 'english_test_date', 'include_family', 'family_members'],
                'Documents' => ['documents', 'document_files'],
                'Additional Information' => ['character_health_disclosure', 'other_notes'],
            ],
            'work' => [
                'Identity' => ['other_names', 'gender', 'dob', 'country_of_birth', 'place_of_birth', 'country_of_citizenship', 'other_citizenships', 'national_id', 'partnership_status', 'current_address'],
                'NZ Immigration History' => ['current_country', 'previous_nz_visa', 'previous_nz_visa_details', 'previous_nzeta', 'australian_pr', 'travelled_nz', 'last_nz_departure', 'over_24_months'],
                'NZ Employer' => ['employer_name', 'employer_is_family', 'employer_family_relation', 'self_employed', 'job_start_date', 'hourly_rate', 'supports_dependent_children'],
                'Character' => ['character_convicted', 'character_investigation', 'character_deported', 'character_visa_refused', 'lived_other_country_5y', 'lived_other_country_details'],
                'Health' => ['health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant'],
                'Current Employment' => ['currently_working', 'current_job_title', 'current_job_start', 'current_job_country', 'current_job_region', 'current_employer_name', 'current_employer_phone', 'current_employer_email', 'current_job_duties', 'current_employer_address'],
                'Military & Travel' => ['military_compulsory', 'military_undertaken', 'military_details', 'travelled_internationally'],
                'Declaration' => ['declaration_accepted', 'signature_name', 'signature_date'],
            ],
            'student' => [
                'Identity' => ['other_names', 'gender', 'dob', 'country_of_birth', 'place_of_birth', 'country_of_citizenship', 'other_citizenships', 'national_id', 'passport_number', 'passport_expiry', 'partnership_status', 'current_address', 'overseas_address'],
                'NZ Immigration History' => ['current_country', 'travelled_nz', 'last_nz_departure', 'over_24_months'],
                'Character' => ['character_convicted', 'character_investigation', 'character_deported', 'character_visa_refused', 'lived_other_country_5y', 'lived_other_country_details'],
                'Health' => ['health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant'],
                'Current Employment' => ['currently_working', 'current_job_title', 'current_job_start', 'current_job_finish', 'current_job_country', 'current_job_region', 'current_employer_name', 'current_employer_phone', 'current_employer_email', 'current_job_duties', 'current_employer_address'],
                'Study Plan' => ['programmes', 'study_period_from', 'study_period_to', 'school_name', 'has_offer'],
                'Study Funds & Assets' => ['has_enough_funds', 'tuition_fee_nzd', 'living_expenses_nzd', 'has_sponsor', 'sponsor_relationship', 'sponsor_income_source', 'can_provide_statements', 'has_other_assets', 'other_assets_details'],
                'Declaration' => ['declaration_accepted', 'signature_name', 'signature_date'],
            ],
            'visitor' => [
                'Identity' => ['other_names', 'gender', 'dob', 'country_of_birth', 'place_of_birth', 'country_of_citizenship', 'passport_number', 'passport_expiry', 'other_citizenships', 'national_id', 'partnership_status', 'current_address', 'town_city', 'region', 'postcode'],
                'NZ Immigration History' => ['current_country', 'previous_nz_visa', 'previous_nzeta', 'australian_pr', 'travelled_nz', 'last_nz_departure', 'over_24_months'],
                'Character' => ['character_convicted', 'character_deported', 'character_investigation', 'character_visa_refused', 'lived_other_country_5y', 'previous_police_certificate'],
                'Health' => ['health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant', 'previous_xray', 'previous_inz1007', 'inz_requested_medical'],
                'Education' => ['has_tertiary', 'qualification_duration', 'qualification_name', 'qualification_completed', 'education_provider'],
                'Current Employment' => ['currently_working', 'current_job_title', 'current_job_start', 'current_job_finish', 'current_job_country', 'current_job_region', 'current_employer_name', 'current_employer_phone', 'current_employer_email', 'current_job_duties', 'current_employer_address'],
                'Travel Plan' => ['purpose_of_visit', 'intended_stay_length', 'intended_from', 'intended_to', 'has_leave_permit', 'multi_entry_plans'],
                'Travel Funds' => ['travel_funds_description', 'can_provide_statements', 'has_other_assets', 'other_assets_details'],
                'Declaration' => ['declaration_accepted', 'signature_name', 'signature_date'],
            ],
            'family' => [
                'Identity' => ['other_names', 'gender', 'dob', 'partnership_status', 'country_of_birth', 'place_of_birth', 'country_of_citizenship', 'other_citizenships', 'national_id'],
                'NZ Immigration' => ['current_country', 'previous_nz_visa', 'current_address'],
                'Visa Details' => ['applying_as', 'visa_type', 'partner_living_together', 'partner_12_months', 'partner_same_period', 'partner_close_relatives', 'child_dependent'],
                'Character' => ['character_convicted', 'character_removed', 'character_investigation', 'character_visa_refused', 'lived_other_country_5y', 'previous_police_certificate'],
                'Health' => ['health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant', 'previous_xray', 'previous_medical_cert', 'countries_visited_3m'],
                'Work History' => ['currently_working', 'current_employer_name', 'current_occupation', 'current_employer_phone', 'current_employer_email', 'current_start', 'current_end', 'current_employer_address'],
                'Contacts & Declaration' => ['nz_contacts', 'declaration_accepted', 'signature_name', 'signature_date'],
            ],
            default => [],
        };
    }

    /** Friendly label for an intake column (overrides where headline reads poorly). */
    public function intakeFieldLabel(string $col): string
    {
        static $labels = [
            'dob' => 'Date of Birth',
            'nationality' => 'Nationality',
            'national_id' => 'National ID',
            'country_of_citizenship' => 'Country of Citizenship',
            'other_citizenships' => 'Other Citizenships',
            'current_address' => 'Current Physical Address',
            'overseas_address' => 'Most Recent Overseas Address',
            'issuing_country' => 'Passport Issuing Country',
            'current_visa_type' => 'Current NZ Visa Type',
            'current_visa_other' => 'Visa Type (Other)',
            'current_visa_expiry' => 'Current Visa Expiry',
            'nz_arrival_date' => 'NZ Arrival Date',
            'previous_nz_visa' => 'Previously Applied for a NZ Visa',
            'previous_nz_visa_details' => 'Previous NZ Visa Details',
            'previous_nz_visa_history' => 'Previous NZ Visa History',
            'previous_nzeta' => 'Previously Requested an NZeTA',
            'australian_pr' => 'Holds Australian PR Visa',
            'travelled_nz' => 'Ever Travelled to NZ',
            'last_nz_departure' => 'Last Departure from NZ',
            'over_24_months' => 'Total NZ Time 24 Months or More',
            'employer_name' => 'Employer Name',
            'employer_is_family' => 'Employer is a Family Member',
            'employer_family_relation' => 'Relationship to Employer',
            'self_employed' => 'Will be Self-Employed',
            'job_start_date' => 'Job Start Date',
            'hourly_rate' => 'Hourly Rate (NZD)',
            'supports_dependent_children' => 'Supports Dependent Children',
            'nz_skilled_years' => 'Years of NZ Skilled Work',
            'total_skilled_years' => 'Total Years Skilled Work',
            'career_summary' => 'Career Summary',
            'nzqa_status' => 'NZQA (IQA) Assessment Status',
            'nzqa_iqa_reference' => 'NZQA IQA Reference',
            'english_evidence' => 'English Language Evidence',
            'english_test_score' => 'English Test Score / Band',
            'english_test_date' => 'English Test Date',
            'include_family' => 'Family Members to Include',
            'family_members' => 'Family Members',
            'documents' => 'Document Checklist',
            'document_files' => 'Uploaded Documents',
            'character_convicted' => 'Convicted of an Offence',
            'character_investigation' => 'Under Investigation / Facing Charges',
            'character_deported' => 'Expelled / Deported / Refused Entry',
            'character_removed' => 'Removed / Deported / Refused Entry',
            'character_visa_refused' => 'Refused a Visa by Any Country',
            'lived_other_country_5y' => 'Lived in Another Country 5+ Years',
            'lived_other_country_details' => 'Country and Years',
            'character_health_disclosure' => 'Character / Health Matters to Disclose',
            'previous_police_certificate' => 'Previously Provided Police Certificate',
            'health_tb' => 'Tuberculosis',
            'health_renal' => 'Receiving Renal Dialysis',
            'health_hospital' => 'Receiving Hospital Care',
            'health_residential' => 'Receiving Residential Care',
            'health_pregnant' => 'Pregnant',
            'previous_xray' => 'Previously Provided Chest X-ray',
            'previous_inz1007' => 'Previously Provided General Medical (INZ 1007)',
            'previous_medical_cert' => 'Previously Provided Medical Certificate',
            'inz_requested_medical' => 'INZ Requested Medical Info Last Time',
            'countries_visited_3m' => 'Countries Visited / Lived 3+ Months',
            'currently_working' => 'Currently Working',
            'current_job_title' => 'Job Title',
            'current_occupation' => 'Occupation / Job Title',
            'current_job_duties' => 'Detailed Job Duties',
            'current_job_start' => 'Employment Start Date',
            'current_job_finish' => 'Employment Finish Date',
            'current_start' => 'Start Date',
            'current_end' => 'End Date',
            'current_job_country' => 'Country of Work',
            'current_job_region' => 'Region of Work',
            'current_employer_name' => 'Organisation Name',
            'current_employer_address' => 'Employer Address',
            'current_employer_phone' => 'Employer Phone',
            'current_employer_email' => 'Employer Email',
            'military_compulsory' => 'Military Service Was Compulsory',
            'military_undertaken' => 'Ever Undertaken Military Service',
            'military_details' => 'Military Service Details',
            'travelled_internationally' => 'Ever Travelled Internationally',
            'has_tertiary' => 'Any Tertiary Education',
            'qualification_duration' => 'Duration of Study',
            'qualification_name' => 'Qualification and Major',
            'qualification_completed' => 'Qualification Completed',
            'education_provider' => 'Education Provider',
            'programmes' => 'Programme(s) to Study',
            'study_period_from' => 'Intended Study From',
            'study_period_to' => 'Intended Study To',
            'school_name' => 'School / Institution',
            'has_offer' => 'Has an Offer of Place',
            'has_enough_funds' => 'Has Enough Funds',
            'tuition_fee_nzd' => 'Tuition Fee (NZD)',
            'living_expenses_nzd' => 'Living Expenses (NZD)',
            'has_sponsor' => 'Has a Sponsor',
            'sponsor_relationship' => 'Relationship to Sponsor',
            'sponsor_income_source' => "Sponsor's Source of Income",
            'can_provide_statements' => 'Can Provide 6 Months Bank Statements',
            'has_other_assets' => 'Has Other Assets',
            'other_assets_details' => 'Assets — Type and Value',
            'purpose_of_visit' => 'Purpose of Visit',
            'intended_stay_length' => 'Intended Length of Stay',
            'intended_from' => 'Intended Arrival',
            'intended_to' => 'Intended Departure',
            'has_leave_permit' => 'Has a Leave Permit',
            'multi_entry_plans' => 'Multi-Entry Plans',
            'travel_funds_description' => 'Travel Funds',
            'applying_as' => 'Applying As',
            'visa_type' => 'Visa Type Applying For',
            'partner_living_together' => 'Currently Living Together',
            'partner_12_months' => 'Living Together 12 Months Total',
            'partner_same_period' => 'Both in NZ Same Period',
            'partner_close_relatives' => 'Are Close Relatives',
            'child_dependent' => 'Child is Dependent (19 or Under)',
            'nz_contacts' => 'Contacts in New Zealand',
            'declaration_accepted' => 'Declaration Accepted',
            'signature_name' => 'Applicant Name (Printed)',
            'signature_date' => 'Date Signed',
            'job_title' => 'Job Title',
            'employment_start' => 'Employment Start Date',
            'employment_type' => 'Employment Type',
            'highest_qualification' => 'Highest Qualification',
            'institution_name' => 'Institution Name',
            'country_of_study' => 'Country of Study',
            'other_notes' => 'Other Notes for Adviser',
            'current_country' => 'Country When Application is Submitted',
            'partnership_status' => 'Partnership Status',
            'other_names' => 'Other Names Used',
            'country_of_birth' => 'Country of Birth',
            'place_of_birth' => 'Place of Birth',
            'passport_number' => 'Passport Number',
            'passport_expiry' => 'Passport Expiry',
            'town_city' => 'Town / City',
            'postcode' => 'Post Code',
        ];

        return $labels[$col] ?? \Illuminate\Support\Str::headline($col);
    }

    /** Humanise a single intake attribute value for display. */
    public function formatIntakeValue($value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value instanceof \Illuminate\Support\Carbon || $value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::parse($value)->toFormattedDateString();
        }
        if (is_array($value)) {
            if (array_is_list($value) && collect($value)->every(fn ($v) => is_scalar($v))) {
                return implode(', ', $value);
            }

            return collect($value)
                ->map(fn ($item) => is_array($item)
                    ? collect($item)->filter(fn ($v) => ! is_null($v) && $v !== '')->map(fn ($v, $k) => \Illuminate\Support\Str::headline($k).': '.(is_scalar($v) ? $v : json_encode($v)))->implode(', ')
                    : (string) $item)
                ->filter()
                ->implode("\n");
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Build the Visa Information Form's sections from the client's ACTUAL
     * assessment (the same per-visa schema the readiness workspace shows), so
     * every section they filled — Study Plan, Travel Plan, Family, etc. — is in
     * the VIF. Output shape matches the pdf.intake Blade (letter/title/rows).
     * Files aren't questions, so they're skipped.
     */
    public function buildVifSections(string $type, array $data): array
    {
        $schema = $this->intakeSectionSchema($type);
        $sections = [];
        $letterCode = ord('A');

        foreach ($schema as $title => $keys) {
            $isDeclaration = str_contains(strtolower($title), 'declaration');
            $rows = [];

            foreach ($keys as $key) {
                if (in_array($key, ['documents', 'document_files'], true)) {
                    continue; // uploads, not Q&A rows
                }
                if ($key === 'declaration_accepted') {
                    $rows[] = [
                        't' => 'check',
                        'label' => 'I declare that the information provided is true, correct and complete.',
                        'on' => (bool) ($data[$key] ?? false),
                    ];

                    continue;
                }
                $rows[] = [
                    'q' => $this->intakeFieldLabel($key),
                    'a' => $this->formatIntakeValue($data[$key] ?? null) ?? '',
                ];
            }

            if (empty($rows)) {
                continue;
            }
            $sections[] = [
                'letter' => chr($letterCode),
                'title' => $title,
                // Declaration sections render as statements, not a Q/A table.
                'bare' => $isDeclaration,
                'rows' => $rows,
            ];
            $letterCode++;
        }

        return $sections;
    }

    /**
     * Free-assessment VIF sections — the free funnel stores its answers on the
     * Lead + JSON blocks, so reuse the readiness-workspace section builder and
     * map it into the same VIF (Blade) shape. Guarantees the free assessment's
     * full content lands in the VIF too.
     */
    public function freeVifSections(Lead $lead): array
    {
        [$schemaSections] = $this->freeAssessmentSections($lead);
        $sections = [];
        $letterCode = ord('A');

        foreach ($schemaSections as $sec) {
            $rows = [];
            foreach (($sec['fields'] ?? []) as $field) {
                $rows[] = [
                    'q' => $field['label'] ?? '',
                    'a' => ($field['provided'] ?? false) ? ($field['value'] ?? '') : '',
                ];
            }
            if (empty($rows)) {
                continue;
            }
            $sections[] = [
                'letter' => chr($letterCode),
                'title' => $sec['title'] ?? '',
                'bare' => false,
                'rows' => $rows,
            ];
            $letterCode++;
        }

        return $sections;
    }

    public function showIntake(string $type, int $id)
    {
        $modelMap = [
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];
        if (! isset($modelMap[$type])) {
            abort(404, 'Unknown intake type.');
        }

        $class = $modelMap[$type];
        $intake = $class::findOrFail($id);

        // Paired Assessment (Pay/Book funnel state) + Booking if any.
        $assessment = \App\Models\Assessment::with('booking')
            ->where('intakeable_type', $class)
            ->where('intakeable_id', $intake->id)
            ->first();

        // Resolve the case this intake was converted into. Prefer the exact
        // assessment_id link (a shared email points at the wrong person), and
        // only fall back to email when the name also matches.
        $lead = null;
        if ($assessment) {
            $lead = Lead::where('assessment_id', $assessment->id)
                ->first(['id', 'lead_id', 'first_name', 'last_name', 'status']);
        }
        $email = strtolower(trim((string) ($intake->email ?? '')));
        $wantLast = strtolower(trim((string) ($intake->last_name ?? $intake->family_name ?? '')));
        $wantFirst = strtolower(trim((string) ($intake->first_name ?? '')));

        if (! $lead && $email !== '') {
            $candidates = Lead::where('is_immigration_case', true)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->get(['id', 'lead_id', 'first_name', 'last_name', 'status']);
            $lead = $candidates->first(fn ($l) => $wantLast !== '' && strtolower(trim((string) $l->last_name)) === $wantLast);
            // No confident name match on a shared email → don't claim a link.
        }

        // Name match — covers a staff-created case with no email on it.
        // Only link when the first + last name match is unambiguous.
        if (! $lead && $wantFirst !== '' && $wantLast !== '') {
            $named = Lead::where('is_immigration_case', true)
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [$wantLast])
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [$wantFirst])
                ->limit(2)
                ->get(['id', 'lead_id', 'first_name', 'last_name', 'status']);
            if ($named->count() === 1) {
                $lead = $named->first();
            }
        }

        return inertia('portal/immigration/IntakeDetails', [
            'type' => $type,
            'intake' => $intake->toArray(),
            'assessment' => $assessment ? [
                'id' => $assessment->id,
                'status' => $assessment->status,
                'token' => $assessment->token,
                'paid_at' => $assessment->paid_at,
                'booking' => $assessment->booking ? [
                    'id' => $assessment->booking->id,
                    'status' => $assessment->booking->status,
                    'appointment_date' => $assessment->booking->appointment_date,
                    'appointment_time' => $assessment->booking->appointment_time,
                ] : null,
            ] : null,
            'linkedLead' => $lead ? [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: 'Unknown',
                'status' => $lead->status,
            ] : null,
            // Uploaded documents (shared tab) — shown in the assessment-module
            // Documents card and streamed from the private disk.
            'documents' => $this->intakeDocumentsPayload($type, $intake),
        ]);
    }

    /**
     * Documents-card payload for the assessment-module intake view — the
     * uploaded files (keyed) + human labels + a type-aware download base. Null
     * (so the card hides) when the intake carries no uploads.
     */
    private function intakeDocumentsPayload(?string $type, $intake): ?array
    {
        $files = $intake->document_files ?? null;
        if (empty($files) || ! is_array($files)) {
            return null;
        }

        // Merged label map across the shared checklist and the work tab; any
        // unknown key is humanised.
        $labels = [
            'passport' => 'Passport (all pages)', 'visa_copies' => 'All NZ visa copies',
            'contracts' => 'NZ employment contracts + JD', 'payslips' => 'Payslips — first 2 mo + latest 1 mo',
            'ird_summary' => 'IRD summary of earnings (monthly)', 'education_certs' => 'Education certificates / transcripts',
            'cv' => 'CV (NZ + overseas history)', 'other' => 'Other supporting documents',
            'job_offer' => 'Job Offer', 'job_token' => 'Job Token / Job Check',
            'employment_contract' => 'Employment Contract', 'valid_pcc' => 'Valid PCC',
            'current_nz_visa' => 'Current NZ Visa Copy', 'anzsco_skills' => 'ANZSCO Skills 3/4/5 evidence',
            'english_test' => 'English Proficiency Test Result', 'ird_earnings' => 'IRD Earnings Summary',
        ];
        $present = [];
        foreach (array_keys($files) as $k) {
            if ($k === 'other') {
                continue;
            }
            $present[$k] = $labels[$k] ?? ucwords(str_replace('_', ' ', (string) $k));
        }

        return [
            'labels' => $present,
            'ticked' => $intake->documents ?? (object) [],
            'files' => $files,
            'other_label' => 'Other supporting documents',
            'base' => "/admin/immigration/intakes/{$type}/{$intake->id}/documents",
        ];
    }

    /**
     * Stream one uploaded intake document (Work / Student / Visitor / Family)
     * from the PRIVATE disk. Role-gated by the route group.
     */
    public function downloadIntakeDocument(\Illuminate\Http\Request $request, string $type, $id, string $key, $index = 0)
    {
        $map = [
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];
        abort_unless(isset($map[$type]), 404);

        $intake = $map[$type]::findOrFail($id);
        $files = $intake->document_files ?? [];
        abort_unless(isset($files[$key]), 404);

        $entry = $files[$key];
        $paths = is_array($entry) ? array_values($entry) : [$entry];
        $i = (int) $index;
        abort_unless(isset($paths[$i]), 404);
        $path = $paths[$i];
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'pdf';
        $filename = $intake->intake_id.' - '.$key.'.'.$ext;

        return $request->boolean('download')
            ? \Illuminate\Support\Facades\Storage::disk('local')->download($path, $filename)
            : \Illuminate\Support\Facades\Storage::disk('local')->response($path, $filename);
    }

    /**
     * Readiness of an intake for adviser prioritisation — how COMPLETE and clean
     * the submission is, NOT how likely it is to succeed (that would be
     * eligibility advice, AI-061, licence-gated). Blends form-field completeness
     * with any AI review's flagged gaps.
     *
     * @return array{0: string, 1: int} [tier, completeness %]
     */
    private function readinessFor($intake, $review = null): array
    {
        // SAME completeness + thresholds as the "Overall assessment" verdict in
        // the review modal, so the list's Priority column and the modal always
        // agree — "Ready to work up" ⇒ "Ready" priority.
        [$filled, $total] = $this->intakeFieldStats($intake);
        $pct = $total > 0 ? (int) round($filled / $total * 100) : 0;

        // A time-critical issue (passport / visa validity) drops it to needs-info,
        // matching the modal's "Attention needed" verdict.
        $hasCritical = collect($this->adviserFlags($intake))->contains(fn ($f) => $f['severity'] === 'critical');

        $tier = 'ready';
        if ($hasCritical || $pct < 55) {
            $tier = 'needs_info';
        } elseif ($pct < 80) {
            $tier = 'minor';
        }

        return [$tier, $pct];
    }

    /** Map an assessment type slug to its intake model class. */
    private function intakeClassFor(string $type): ?string
    {
        return [
            'resident' => \App\Models\ResidentIntake::class,
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ][$type] ?? null;
    }

    /** Serialize a stored AI review for the frontend. */
    private function serializeAiReview(?\App\Models\AssessmentAiReview $r): ?array
    {
        if (! $r) {
            return null;
        }

        return [
            'id' => $r->id,
            'summary' => $r->summary,
            'observations' => $r->observations ?? [],
            'risks' => $r->risks ?? [],
            'checklist' => $r->checklist ?? [],
            'adviser_note' => $r->adviser_note,
            'client_email' => $r->client_email ?? ['subject' => '', 'body' => ''],
            'model' => $r->model,
            'reviewed_by' => optional($r->reviewer)->name,
            'created_at' => optional($r->created_at)->toIso8601String(),
            'edited_by' => optional($r->editor)->name,
            'edited_at' => optional($r->edited_at)->toIso8601String(),
        ];
    }

    /**
     * Return the last stored AI review for an intake (or null). Immigration
     * staff only (route group). Internal, indicative — see aiReviewRun.
     */
    public function aiReviewShow(string $type, int $id)
    {
        abort_unless($this->intakeClassFor($type), 404);
        $review = \App\Models\AssessmentAiReview::latestFor($this->intakeClassFor($type), $id);

        return response()->json(['review' => $this->serializeAiReview($review)]);
    }

    /**
     * Run an AI completeness/consistency review of an intake. INTERNAL and
     * INDICATIVE only — it flags missing/inconsistent fields for the licensed
     * adviser to follow up, and is NOT eligibility advice or a decision
     * (immigration AI guardrails §1/§2). Immigration staff only (route group).
     */
    public function aiReviewRun(Request $request, string $type, int $id)
    {
        $class = $this->intakeClassFor($type);
        abort_unless($class, 404);
        $intake = $class::findOrFail($id);

        $ai = app(\App\Services\OpenRouterService::class);
        if (! $ai->configured()) {
            return response()->json([
                'message' => 'AI review isn\'t configured yet — add a valid OPENROUTER_API_KEY to .env.',
            ], 422);
        }

        try {
            $review = app(\App\Services\Immigration\AssessmentReviewService::class)
                ->review($intake, $type, $request->user());
        } catch (\Throwable $e) {
            Log::error('AI assessment review failed', ['type' => $type, 'id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The AI review could not be completed. Please try again.'], 500);
        }

        return response()->json(['review' => $this->serializeAiReview($review)]);
    }

    /**
     * Save the adviser's edits to the drafted note + client email on the latest
     * review. The adviser is the author of record (guardrail §2) — the AI output
     * stays in `raw`; this overwrites the working copy and stamps who/when.
     */
    public function aiReviewEdit(Request $request, string $type, int $id)
    {
        $class = $this->intakeClassFor($type);
        abort_unless($class, 404);

        $review = \App\Models\AssessmentAiReview::latestFor($class, $id);
        abort_unless($review, 404, 'Run an AI review first.');

        $data = $request->validate([
            'adviser_note' => 'nullable|string|max:8000',
            'client_email' => 'nullable|array',
            'client_email.subject' => 'nullable|string|max:255',
            'client_email.body' => 'nullable|string|max:8000',
        ]);

        $review->update([
            'adviser_note' => $data['adviser_note'] ?? $review->adviser_note,
            'client_email' => array_key_exists('client_email', $data)
                ? ['subject' => $data['client_email']['subject'] ?? '', 'body' => $data['client_email']['body'] ?? '']
                : $review->client_email,
            'edited_by' => $request->user()->id,
            'edited_at' => now(),
        ]);

        return response()->json(['review' => $this->serializeAiReview($review->fresh(['reviewer', 'editor']))]);
    }

    /**
     * Download a Work / Student / Visitor intake as the official ePathways
     * "Visa Information Form – General Application" PDF, filled with the
     * applicant's submitted answers. Replaces the old raw-JSON export.
     */
    public function downloadIntakePdf(string $type, int $id)
    {
        [$data, $base] = $this->intakeVifData($type, $id);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.intake', array_merge($data, ['mode' => 'pdf']))
            ->setPaper('a4')
            ->download($base.'.pdf');
    }

    /** Inline HTML preview of the Visa Information Form (for the download modal). */
    public function previewIntakePdf(string $type, int $id)
    {
        [$data] = $this->intakeVifData($type, $id);

        return response(view('pdf.intake', array_merge($data, ['mode' => 'web']))->render());
    }

    /** Download the Visa Information Form as an editable Word (.doc) file. */
    public function downloadIntakeWord(string $type, int $id)
    {
        [$data, $base] = $this->intakeVifData($type, $id);
        $html = view('pdf.intake', array_merge($data, ['mode' => 'word']))->render();

        return response($html, 200, [
            'Content-Type' => 'application/msword; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$base.'.doc"',
        ]);
    }

    /**
     * Resolve a Work / Student / Visitor intake into the Visa Information Form
     * view data, shared by the PDF, preview and Word exports.
     *
     * @return array{0: array, 1: string} [$viewData, $filenameBase]
     */
    private function intakeVifData(string $type, int $id): array
    {
        // Free assessment — a Lead, not an intake. Built from the free
        // assessment's own sections so its full content lands in the VIF.
        if ($type === 'free') {
            $lead = Lead::findOrFail($id);
            $applicant = trim("{$lead->first_name} {$lead->last_name}") ?: 'Applicant';
            $data = [
                'applicant' => $applicant,
                'sections' => $this->freeVifSections($lead),
                'intakeId' => $lead->lead_id,
                'generatedAt' => now()->format('d/m/Y'),
            ];
            $name = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $applicant)) ?: ($lead->lead_id ?? 'Applicant');

            return [$data, $name.' VIF'];
        }

        $modelMap = [
            'resident' => \App\Models\ResidentIntake::class,
            'work' => \App\Models\WorkIntake::class,
            'student' => \App\Models\StudentIntake::class,
            'visitor' => \App\Models\VisitorIntake::class,
            'family' => \App\Models\FamilyIntake::class,
        ];
        if (! isset($modelMap[$type])) {
            abort(404, 'Unknown intake type.');
        }

        $intake = $modelMap[$type]::findOrFail($id)->toArray();
        // Build the VIF from the client's ACTUAL assessment sections so nothing
        // they filled is dropped (Study Plan, Travel Plan, Family, …).
        $applicant = trim(($intake['first_name'] ?? '').' '.($intake['last_name'] ?? $intake['family_name'] ?? '')) ?: 'Applicant';

        $data = [
            'applicant' => $applicant,
            'sections' => $this->buildVifSections($type, $intake),
            'intakeId' => $intake['intake_id'] ?? null,
            'generatedAt' => now()->format('d/m/Y'),
        ];

        // Filename = client's name + " VIF" (e.g. "Mary Katherine Paspe VIF").
        // Falls back to the reference id, then a generic label.
        $name = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $applicant));
        if ($name === '') {
            $name = $intake['intake_id'] ?? 'Applicant';
        }
        $base = $name.' VIF';

        return [$data, $base];
    }

    /** Documents — Queue (pending / stale / rejected) + Folders per case. */
    public function documents()
    {
        try {
            $pending = LeadDocument::with('lead:id,first_name,last_name,lead_id')
                ->whereIn('status', ['Submitted', 'UnderReview'])
                ->orderBy('created_at')->limit(50)->get()
                ->map(fn ($d) => $this->docQueueRow($d, 'pending'));

            $stale = LeadDocument::with('lead:id,first_name,last_name,lead_id')
                ->where('status', 'Submitted')
                ->where('created_at', '<', now()->subDays(7))
                ->orderBy('created_at')->limit(30)->get()
                ->map(fn ($d) => $this->docQueueRow($d, 'stale'));

            $rejected = LeadDocument::with('lead:id,first_name,last_name,lead_id')
                ->where('status', 'Rejected')
                ->where('reviewed_at', '>', now()->subDays(14))
                ->orderByDesc('reviewed_at')->limit(30)->get()
                ->map(fn ($d) => $this->docQueueRow($d, 'rejected'));

            $folders = Lead::has('documents')
                ->with('documents:id,lead_id,status,checklist_key')
                ->orderBy('first_name')->limit(200)->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'lead_id' => $l->lead_id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                    'total' => $l->documents->count(),
                    'approved' => $l->documents->where('status', 'Approved')->count(),
                    'pending' => $l->documents->whereIn('status', ['Submitted', 'UnderReview'])->count(),
                    'rejected' => $l->documents->where('status', 'Rejected')->count(),
                ]);

            return inertia('portal/immigration/Documents', [
                'pending' => $pending, 'stale' => $stale, 'rejected' => $rejected, 'folders' => $folders,
            ]);
        } catch (\Throwable $e) {
            Log::error('Immigration documents page failed', ['error' => $e->getMessage()]);

            return inertia('portal/immigration/Documents', ['pending' => [], 'stale' => [], 'rejected' => [], 'folders' => []]);
        }
    }

    private function docQueueRow($d, $bucket): array
    {
        return [
            'id' => $d->id,
            'bucket' => $bucket,
            'original_name' => $d->original_name,
            'status' => $d->status,
            'note' => $d->note,
            'created_at' => $d->created_at,
            'reviewed_at' => $d->reviewed_at,
            'checklist_key' => $d->checklist_key,
            'lead' => $d->lead ? [
                'id' => $d->lead->id,
                'lead_id' => $d->lead->lead_id,
                'name' => trim("{$d->lead->first_name} {$d->lead->last_name}") ?: 'Unknown',
            ] : null,
        ];
    }

    public function appointments()
    {
        try {
            $rows = Booking::where('service_type', 'like', '%Immigration%')
                ->with('visaType:id,name,code')
                ->orderByDesc('appointment_date')->limit(300)->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => trim("{$b->first_name} {$b->last_name}") ?: 'Unknown',
                    'email' => $b->email,
                    'phone' => $b->phone,
                    'service_type' => $b->service_type,
                    'visa' => $b->visaType?->name,
                    'consultant_name' => $b->consultant_name,
                    'platform' => $b->platform,
                    'status' => $b->status ?: 'Pending',
                    'payment_status' => $b->payment_status ?: 'unpaid',
                    'amount' => $b->amount,
                    'currency' => $b->currency,
                    'appointment_date' => $b->appointment_date ? \Illuminate\Support\Carbon::parse($b->appointment_date)->toDateString() : null,
                    'appointment_time' => $b->appointment_time,
                    'created_at' => optional($b->created_at)->toIso8601String(),
                ]);

            return inertia('portal/immigration/Appointments', array_merge(
                ['appointments' => $rows],
                $this->availabilityProps(),
            ));
        } catch (\Throwable $e) {
            Log::error('Immigration appointments page failed', ['error' => $e->getMessage()]);

            return inertia('portal/immigration/Appointments', array_merge(
                ['appointments' => []],
                $this->availabilityProps(),
            ));
        }
    }

    /** Current user's availability + the immigration team's, for the settings tab. */
    private function availabilityProps(): array
    {
        $me = auth()->user();
        $default = \App\Models\StaffAvailability::defaultSchedule();
        $saved = \App\Models\StaffAvailability::pluck('schedule', 'user_id');

        $staff = \App\Models\User::whereIn('role', ['immigration', 'immigration_manager', 'immigration_adviser'])
            ->orderBy('name')->get(['id', 'name', 'role']);

        // Ensure the acting user (e.g. an admin) can always set their own hours.
        if ($me && ! $staff->contains('id', $me->id)) {
            $staff->push($me);
        }

        return [
            'currentUserId' => $me?->id,
            'myAvailability' => ($me && isset($saved[$me->id])) ? $saved[$me->id] : $default,
            'teamAvailability' => $staff->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'schedule' => $saved[$u->id] ?? $default,
                'is_set' => isset($saved[$u->id]),
            ])->values(),
        ];
    }

    /** Save the acting user's weekly availability. */
    public function saveAvailability(Request $request)
    {
        $data = $request->validate([
            'schedule' => 'required|array',
            'schedule.*.enabled' => 'required|boolean',
            'schedule.*.start' => 'nullable|date_format:H:i',
            'schedule.*.end' => 'nullable|date_format:H:i',
        ]);

        // Keep only known days.
        $schedule = array_intersect_key($data['schedule'], array_flip(\App\Models\StaffAvailability::DAYS));

        \App\Models\StaffAvailability::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['schedule' => $schedule],
        );

        return back()->with('success', 'Your availability has been saved.');
    }

    /** Re-send the booking confirmation + invoice email to the client. */
    public function resendInvoice($id)
    {
        $booking = Booking::findOrFail($id);

        if (empty($booking->email)) {
            return back()->with('error', 'This booking has no email address.');
        }

        try {
            // Queued so the SMTP round-trip never blocks the single-threaded
            // dev server (which caused the page to blank mid-send).
            \Illuminate\Support\Facades\Mail::to($booking->email)
                ->queue(new \App\Mail\BookingConfirmationMail($booking->fresh('visaType')));

            return back()->with('success', 'Invoice re-sent to '.$booking->email.'.');
        } catch (\Throwable $e) {
            Log::error('Resend invoice failed', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not send the invoice. Please try again.');
        }
    }

    public function reports(Request $request)
    {
        // ── Date range (default: last 2 weeks) ───────────────────────────
        [$preset, $from, $to] = $this->resolveReportRange($request);
        $now = now();
        $rangeDays = max(1, (int) $from->diffInDays($to) + 1);

        // Stage groupings (based on the case's current immigration_stage).
        $awaitingStages = ['Visa Lodged', 'Request for Information', 'Approved in Principle'];
        $lodgedStages = ['Visa Lodged', 'Request for Information', 'Approved in Principle', 'Approved Visa', 'Decline Visa'];
        $endorsedStages = ['Endorsed', 'Agreement Sent', 'Agreement Signed', 'For Agreement & Invoice', 'Invoice Paid', 'Visa Lodged', 'Request for Information', 'Approved in Principle', 'Approved Visa', 'Decline Visa'];
        $engagedStages = ['Agreement Signed', 'For Agreement & Invoice', 'Invoice Paid', 'Visa Lodged', 'Request for Information', 'Approved in Principle', 'Approved Visa', 'Decline Visa'];
        $terminalStages = ['Approved Visa', 'Decline Visa'];

        $count = fn ($stages) => Lead::immigrationCase()->whereIn('immigration_stage', (array) $stages)->count();
        // Movements INTO a set of stages within the window (by stage_updated_at).
        $inWindow = fn ($stages) => Lead::immigrationCase()
            ->whereIn('immigration_stage', (array) $stages)
            ->whereBetween('stage_updated_at', [$from, $to])
            ->count();

        // ── Activity in the selected window ──────────────────────────────
        $activity = [
            'new_clients' => Lead::immigrationCase()->whereBetween('immigration_converted_at', [$from, $to])->count(),
            'files_endorsed' => $inWindow(['For Assessment', 'Endorsed']),
            'agreements_signed' => $inWindow('Agreement Signed'),
            'apps_lodged' => $inWindow('Visa Lodged'),
            'visas_approved' => $inWindow('Approved Visa'),
            'visas_declined' => $inWindow('Decline Visa'),
        ];

        // ── Cases by stage — current snapshot ────────────────────────────
        $stageDistribution = collect(Lead::IMMIGRATION_STAGES)
            ->map(fn ($s) => ['stage' => $s, 'count' => $count($s)])
            ->push(['stage' => 'Unassigned', 'count' => Lead::immigrationCase()->whereNull('immigration_stage')->count()])
            ->values();
        $totalCases = $stageDistribution->sum('count');

        // ── Documents in the window ──────────────────────────────────────
        $docWin = fn () => LeadDocument::whereBetween('created_at', [$from, $to]);
        $documents = [
            'total' => (clone $docWin())->count(),
            'approved' => (clone $docWin())->where('status', 'Approved')->count(),
            'pending' => (clone $docWin())->whereIn('status', ['Submitted', 'UnderReview'])->count(),
            'rejected' => (clone $docWin())->where('status', 'Rejected')->count(),
            'pending_review_all' => LeadDocument::whereIn('status', ['Submitted', 'UnderReview'])->count(),
        ];

        // ── 6-month trend — new cases vs visas approved per month ────────
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $mStart = $m->copy()->startOfMonth();
            $mEnd = $m->copy()->endOfMonth();
            $trend[] = [
                'label' => $m->format('M'),
                'new_cases' => Lead::immigrationCase()->whereBetween('immigration_converted_at', [$mStart, $mEnd])->count(),
                'approved' => Lead::immigrationCase()->where('immigration_stage', 'Approved Visa')->whereBetween('stage_updated_at', [$mStart, $mEnd])->count(),
            ];
        }

        $approved = $count('Approved Visa');
        $declined = $count('Decline Visa');
        $decided = $approved + $declined;

        $kpis = [
            'active_cases' => Lead::immigrationCase()->whereNotIn('immigration_stage', $terminalStages)->count(),
            'with_inz' => $count($awaitingStages),
            'docs_pending' => $documents['pending_review_all'],
            'approval_rate' => $decided > 0 ? (int) round($approved / $decided * 100) : 0,
        ];

        $ytd = [
            'total_clients' => Lead::immigrationCase()->count(),
            'endorsed' => $count($endorsedStages),
            'lodged' => $count($lodgedStages),
            'approved' => $approved,
            'engagements' => $count($engagedStages),
            'declined' => $declined,
            'decided' => $decided,
            'approval_rate' => $decided > 0 ? (int) round($approved / $decided * 100) : 0,
        ];

        // ─────────────────────────────────────────────────────────────────
        // Weekly management report (the slide-deck format).
        // ─────────────────────────────────────────────────────────────────
        $withInzStages = ['Visa Lodged', 'Interim Visa Issued', 'Request for Information', 'Approved in Principle'];

        // Named-detail rows. Pass which date column labels the row.
        $named = function ($query, string $dateField = 'stage_updated_at') {
            return $query
                ->get(['id', 'first_name', 'last_name', 'inz_visa_type', 'stage_updated_at', 'immigration_converted_at', 'immigration_assignee'])
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Case',
                    'visa' => $l->inz_visa_type,
                    'assignee' => $l->immigration_assignee,
                    'date' => optional($l->{$dateField})->toIso8601String(),
                ])->values();
        };
        $inCase = fn () => Lead::immigrationCase();

        // Pipeline position — mostly current snapshots (new_clients is in-window).
        // "For quotation" = the pricing/invoice stage; "on hold" is omitted.
        $pipeline = [
            'new_clients' => $inCase()->whereBetween('immigration_converted_at', [$from, $to])->count(),
            'in_progress' => $inCase()->whereNotIn('immigration_stage', array_merge($withInzStages, $terminalStages))
                ->where(fn ($q) => $q->whereNotNull('immigration_stage'))->count(),
            'for_quotation' => $count('For Agreement & Invoice'),
            'endorsed_dev' => $inCase()->where('immigration_assignee', 'Dev')->count(),
            'endorsed_hendry' => $inCase()->where('immigration_assignee', 'Hendry')->count(),
            'agreements_sent' => $count('Agreement Sent'),
            'with_inz' => $count($withInzStages),
            'pre_lodgement' => [
                'paid' => $count('Invoice Paid'),
                'awaiting' => $count('For Agreement & Invoice'),
                'total' => $count('Invoice Paid') + $count('For Agreement & Invoice'),
            ],
        ];

        // Intake & engagements — named, movements within the window.
        $namedIntake = [
            'new_clients' => $named($inCase()->whereBetween('immigration_converted_at', [$from, $to])->orderBy('immigration_converted_at'), 'immigration_converted_at'),
            'agreements_issued' => $named($inCase()->where('immigration_stage', 'Agreement Sent')->whereBetween('stage_updated_at', [$from, $to])),
            'endorsed_dev' => $named($inCase()->where('immigration_assignee', 'Dev')->whereBetween('stage_updated_at', [$from, $to])),
            'engagement_signed' => $named($inCase()->where('immigration_stage', 'Agreement Signed')->whereBetween('stage_updated_at', [$from, $to])),
        ];

        // Submissions & information requests.
        $submissions = [
            'lodged' => $named($inCase()->where('immigration_stage', 'Visa Lodged')->whereBetween('stage_updated_at', [$from, $to])),
            'rfis' => $named($inCase()->where('immigration_stage', 'Request for Information')),          // current open
            'also_assessing' => $named($inCase()->whereIn('immigration_stage', ['Approved in Principle', 'Interim Visa Issued'])),
        ];

        // Decision outcomes — within the window; plus the with-INZ breakdown.
        $outcomes = [
            'approved' => $named($inCase()->where('immigration_stage', 'Approved Visa')->whereBetween('stage_updated_at', [$from, $to])),
            'interim' => $named($inCase()->where('immigration_stage', 'Interim Visa Issued')->whereBetween('stage_updated_at', [$from, $to])),
            'declined' => $named($inCase()->where('immigration_stage', 'Decline Visa')->whereBetween('stage_updated_at', [$from, $to])),
            'with_inz_breakdown' => collect($withInzStages)
                // Include the named cases per state so the report can reveal WHO
                // is in each INZ queue (shown on hover).
                ->map(fn ($s) => ['stage' => $s, 'count' => $count($s), 'cases' => $named($inCase()->where('immigration_stage', $s))])
                ->filter(fn ($r) => $r['count'] > 0)
                ->values(),
        ];

        // Conclusion — auto factual summary (no interpretation), editable per period.
        $noteKey = 'imm_report_note:'.$from->toDateString().':'.$to->toDateString();
        $autoSummary = sprintf(
            '%d application%s lodged with INZ and %d new client record%s opened this period. %d approved, %d declined. %d file%s currently sit with INZ awaiting a decision.',
            $activity['apps_lodged'], $activity['apps_lodged'] === 1 ? '' : 's',
            $pipeline['new_clients'], $pipeline['new_clients'] === 1 ? '' : 's',
            $activity['visas_approved'], $activity['visas_declined'],
            $pipeline['with_inz'], $pipeline['with_inz'] === 1 ? '' : 's',
        );
        $conclusion = [
            'auto' => $autoSummary,
            'note' => \App\Models\Setting::get($noteKey),
            'note_key' => $noteKey,
            'stats' => [
                'lodged' => $activity['apps_lodged'],
                'approved' => $activity['visas_approved'],
                'declined' => $activity['visas_declined'],
                'new_records' => $pipeline['new_clients'],
            ],
        ];

        return inertia('portal/immigration/Reports', [
            'pipeline' => $pipeline,
            'namedIntake' => $namedIntake,
            'submissions' => $submissions,
            'outcomes' => $outcomes,
            'conclusion' => $conclusion,
            'range' => [
                'preset' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $rangeDays,
                'label' => $this->reportRangeLabel($preset, $from, $to),
            ],
            'kpis' => $kpis,
            'activity' => $activity,
            'stageDistribution' => $stageDistribution,
            'totalCases' => $totalCases,
            'documents' => $documents,
            'trend' => $trend,
            'ytd' => $ytd,
            'attention' => $this->needsAttention($terminalStages),
            'workload' => $this->adviserWorkload($terminalStages),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => optional(auth()->user())->name,
        ]);
    }

    /**
     * Save (or clear) the editable conclusion commentary for a report period.
     * Keyed by the period so each week keeps its own note.
     */
    public function saveReportNote(Request $request)
    {
        $data = $request->validate([
            'note_key' => ['required', 'string', 'starts_with:imm_report_note:', 'max:120'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        \App\Models\Setting::set($data['note_key'], $data['note'] ?: null, 'string', 'Immigration report note', 'immigration');

        return back()->with('success', 'Report note saved.');
    }

    /**
     * Resolve the report window from the request. Default is the last 14 days.
     * Presets: two_weeks | this_month | last_month | quarter | custom(from,to).
     *
     * @return array{0: string, 1: \Illuminate\Support\Carbon, 2: \Illuminate\Support\Carbon}
     */
    private function resolveReportRange(Request $request): array
    {
        $now = now();
        $preset = $request->input('preset', 'two_weeks');
        if (! in_array($preset, ['today', 'this_week', 'two_weeks', 'this_month', 'last_month', 'quarter', 'custom'], true)) {
            $preset = 'two_weeks';
        }

        switch ($preset) {
            case 'today':
                return [$preset, $now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case 'this_week':
                return [$preset, $now->copy()->startOfWeek(), $now->copy()->endOfDay()];
            case 'this_month':
                return [$preset, $now->copy()->startOfMonth(), $now->copy()->endOfDay()];
            case 'last_month':
                $m = $now->copy()->subMonthNoOverflow();

                return [$preset, $m->copy()->startOfMonth(), $m->copy()->endOfMonth()];
            case 'quarter':
                return [$preset, $now->copy()->subMonthsNoOverflow(3)->startOfDay(), $now->copy()->endOfDay()];
            case 'custom':
                $from = $request->filled('from')
                    ? \Illuminate\Support\Carbon::parse($request->input('from'))->startOfDay()
                    : $now->copy()->subDays(14)->startOfDay();
                $to = $request->filled('to')
                    ? \Illuminate\Support\Carbon::parse($request->input('to'))->endOfDay()
                    : $now->copy()->endOfDay();
                if ($from->gt($to)) {
                    [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
                }

                return [$preset, $from, $to];
            default: // two_weeks
                return ['two_weeks', $now->copy()->subDays(14)->startOfDay(), $now->copy()->endOfDay()];
        }
    }

    private function reportRangeLabel(string $preset, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): string
    {
        return match ($preset) {
            'today' => 'Today',
            'this_week' => 'This week',
            'this_month' => $from->format('F Y'),
            'last_month' => $from->format('F Y'),
            'quarter' => 'Last 3 months',
            'two_weeks' => 'Last 2 weeks',
            default => $from->format('d M Y').' – '.$to->format('d M Y'),
        };
    }

    /**
     * Cases needing attention — merges the real operational signals so staff
     * see what to chase first: stale custody, unassigned, blocking findings,
     * overdue process steps, documents awaiting review, unanswered questions,
     * and cases the adviser could not endorse. Snapshot (not window-bound).
     *
     * @param  array<int, string>  $terminalStages
     * @return array<int, array<string, mixed>>
     */
    private function needsAttention(array $terminalStages): array
    {
        $now = now();
        $amber = (int) config('immigration.custody_stale_amber_days', 6);
        $red = (int) config('immigration.custody_stale_red_days', 10);
        $docDays = 5;
        $threadDays = (int) config('immigration.findings.thread_unanswered_days', 3);

        // Active (non-terminal) cases, keyed by id, with owner for labelling.
        $cases = Lead::immigrationCase()
            ->where(function ($q) use ($terminalStages) {
                $q->whereNotIn('immigration_stage', $terminalStages)->orWhereNull('immigration_stage');
            })
            ->with('owner:id,name')
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'current_owner_id', 'last_activity_at', 'updated_at', 'immigration_stage'])
            ->keyBy('id');

        if ($cases->isEmpty()) {
            return [];
        }
        $ids = $cases->keys()->all();

        $attn = [];
        $bump = function ($id, string $reason, int $weight) use (&$attn, $cases) {
            if (! isset($cases[$id])) {
                return;
            }
            if (! isset($attn[$id])) {
                $l = $cases[$id];
                $idle = ($l->last_activity_at ?: $l->updated_at)?->diffInDays(now());
                $attn[$id] = [
                    'id' => $id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: ($l->lead_id ?: 'Unknown'),
                    'owner' => optional($l->owner)->name,
                    'stage' => $l->immigration_stage,
                    'idle_days' => $idle !== null ? (int) $idle : null,
                    'reasons' => [],
                    'score' => 0,
                ];
            }
            $attn[$id]['reasons'][] = $reason;
            $attn[$id]['score'] += $weight;
        };

        // 1) Stale custody + unassigned.
        foreach ($cases as $id => $l) {
            $idle = (int) (($l->last_activity_at ?: $l->updated_at)?->diffInDays($now) ?? 0);
            if ($idle >= $red) {
                $bump($id, "No activity for {$idle} days", 2);
            } elseif ($idle >= $amber) {
                $bump($id, "Going stale ({$idle}d idle)", 1);
            }
            if (! $l->current_owner_id) {
                $bump($id, 'Unassigned — no owner', 1);
            }
        }

        // 2) Open blocking findings.
        foreach (\App\Models\CaseFinding::whereIn('lead_id', $ids)->where('status', 'open')->where('severity', 'blocking')
            ->selectRaw('lead_id, COUNT(*) c')->groupBy('lead_id')->pluck('c', 'lead_id') as $id => $c) {
            $bump($id, $c.' blocking issue'.($c == 1 ? '' : 's'), 3);
        }

        // 3) Overdue process steps.
        foreach (\App\Models\CaseStepState::whereIn('lead_id', $ids)->where('status', 'active')
            ->whereNotNull('due_at')->where('due_at', '<', $now)
            ->selectRaw('lead_id, COUNT(*) c')->groupBy('lead_id')->pluck('c', 'lead_id') as $id => $c) {
            $bump($id, $c.' step'.($c == 1 ? '' : 's').' overdue', 2);
        }

        // 4) Documents awaiting review too long.
        foreach (LeadDocument::whereIn('lead_id', $ids)->whereIn('status', ['Submitted', 'UnderReview'])
            ->where('created_at', '<', $now->copy()->subDays($docDays))
            ->selectRaw('lead_id, COUNT(*) c')->groupBy('lead_id')->pluck('c', 'lead_id') as $id => $c) {
            $bump($id, $c.' doc'.($c == 1 ? '' : 's').' awaiting review', 1);
        }

        // 5) Unanswered questions (threads).
        foreach (\App\Models\CaseThread::whereIn('lead_id', $ids)->awaitingAnswer()
            ->where('created_at', '<=', $now->copy()->subDays($threadDays))
            ->selectRaw('lead_id, COUNT(*) c')->groupBy('lead_id')->pluck('c', 'lead_id') as $id => $c) {
            $bump($id, $c.' unanswered question'.($c == 1 ? '' : 's'), 2);
        }

        // 6) Latest verdict is cannot_endorse.
        $latestVerdicts = \App\Models\CaseAttestation::whereIn('lead_id', $ids)->where('type', 'verdict')
            ->orderByDesc('id')->get(['lead_id', 'verdict'])->unique('lead_id');
        foreach ($latestVerdicts->where('verdict', 'cannot_endorse')->pluck('lead_id') as $id) {
            $bump($id, 'Adviser could not endorse — on hold', 3);
        }

        return collect($attn)
            ->sortByDesc(fn ($a) => [$a['score'], $a['idle_days'] ?? 0])
            ->values()
            ->take(30)
            ->map(fn ($a) => array_merge($a, [
                'severity' => $a['score'] >= 3 ? 'high' : ($a['score'] >= 2 ? 'medium' : 'low'),
                'link' => "/portal/immigration/cases/{$a['id']}/profile",
            ]))
            ->all();
    }

    /**
     * How the active caseload is spread across owners — plus an unassigned
     * bucket — so a manager can see who's carrying what.
     *
     * @param  array<int, string>  $terminalStages
     * @return array<int, array<string, mixed>>
     */
    private function adviserWorkload(array $terminalStages): array
    {
        $base = fn () => Lead::immigrationCase()->where(function ($q) use ($terminalStages) {
            $q->whereNotIn('immigration_stage', $terminalStages)->orWhereNull('immigration_stage');
        });

        $counts = (clone $base())->whereNotNull('current_owner_id')
            ->selectRaw('current_owner_id, COUNT(*) c')->groupBy('current_owner_id')->pluck('c', 'current_owner_id');

        $names = User::whereIn('id', $counts->keys())->pluck('name', 'id');

        $rows = $counts->map(fn ($c, $id) => [
            'owner' => $names[$id] ?? 'Unknown',
            'count' => (int) $c,
        ])->sortByDesc('count')->values();

        $unassigned = (clone $base())->whereNull('current_owner_id')->count();
        if ($unassigned > 0) {
            $rows->push(['owner' => 'Unassigned', 'count' => $unassigned]);
        }

        return $rows->all();
    }

    // Stubs — coming-soon pages.
    public function visaTypes()
    {
        return inertia('portal/immigration/VisaTypes', []);
    }

    public function intakes()
    {
        return inertia('portal/immigration/Intakes', []);
    }

    /** INZ form catalogue + version register (upload official PDFs, map fields). */
    public function inzForms(string $page = 'portal/immigration/InzForms')
    {
        $forms = \App\Models\InzForm::with(['versions' => fn ($q) => $q->orderByDesc('is_current')->orderByDesc('id')])
            ->orderBy('category')->orderBy('code')
            ->get()
            ->map(fn (\App\Models\InzForm $f) => [
                'id' => $f->id,
                'code' => $f->code,
                'name' => $f->name,
                'category' => $f->category,
                'is_active' => $f->is_active,
                'versions' => $f->versions->map(fn (\App\Models\InzFormVersion $v) => [
                    'id' => $v->id,
                    'version_label' => $v->version_label,
                    'is_current' => $v->is_current,
                    'ready' => $v->isReady(),
                    'is_acroform' => $v->is_acroform,
                    'field_map' => $v->field_map ?? [],
                    'effective_from' => optional($v->effective_from)->toDateString(),
                    'accepted_until' => optional($v->accepted_until)->toDateString(),
                    'lapsing' => $v->isLapsing(),
                    'lapsed' => $v->hasLapsed(),
                    'checked_at' => optional($v->checked_at)->toIso8601String(),
                ])->values(),
            ]);

        $visaTypes = \App\Models\VisaType::orderBy('category')->orderBy('name')
            ->get(['id', 'code', 'name', 'category']);

        $categories = \App\Models\VisaCategory::orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'description' => $c->description,
            'visa_type_ids' => $visaTypes->where('category', $c->name)->pluck('id')->values(),
            'form_count' => $forms->where('category', $c->name)->count(),
        ]);

        return inertia($page, [
            'forms' => $forms,
            'categories' => $categories,
            'visaTypes' => $visaTypes,
            // The context keys a field map can reference (for the map editor).
            'contextKeys' => array_keys(\App\Services\Immigration\InzCaseContext::for(new Lead)),
        ]);
    }

    /** Create a visa category. */
    public function categoryStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60|unique:visa_categories,name',
            'code' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:255',
        ]);
        \App\Models\VisaCategory::create($data);

        return back()->with('success', "Added category {$data['name']}.");
    }

    /** Update a category (renaming re-tags its visa types + INZ forms). */
    public function categoryUpdate(Request $request, \App\Models\VisaCategory $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60|unique:visa_categories,name,'.$category->id,
            'code' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:255',
        ]);

        $oldName = $category->name;
        $category->update($data);

        if ($oldName !== $data['name']) {
            \App\Models\VisaType::where('category', $oldName)->update(['category' => $data['name']]);
            \App\Models\InzForm::where('category', $oldName)->update(['category' => $data['name']]);
        }

        return back()->with('success', "Updated category {$data['name']}.");
    }

    /** Delete a category (visa types / forms keep their string until reassigned). */
    public function categoryDestroy(\App\Models\VisaCategory $category)
    {
        $name = $category->name;
        $category->delete();

        return back()->with('success', "Removed category {$name}.");
    }

    /** Set which visa types belong to a category (authoritative for that category). */
    public function categoryAssignVisas(Request $request, \App\Models\VisaCategory $category)
    {
        $data = $request->validate([
            'visa_type_ids' => 'present|array',
            'visa_type_ids.*' => 'integer|exists:visa_types,id',
        ]);

        // Selected visas → this category; any previously in this category but no
        // longer selected → cleared (the selection is the source of truth).
        \App\Models\VisaType::where('category', $category->name)
            ->whereNotIn('id', $data['visa_type_ids'])
            ->update(['category' => null]);
        \App\Models\VisaType::whereIn('id', $data['visa_type_ids'])
            ->update(['category' => $category->name]);

        return back()->with('success', "Updated visas under {$category->name}.");
    }

    /** Upload the official PDF for a form version (creates the version if new). */
    public function inzUploadVersion(Request $request, \App\Models\InzForm $form)
    {
        $data = $request->validate([
            'version_label' => 'required|string|max:40',
            'file' => 'required|file|mimetypes:application/pdf|max:20480',
            'effective_from' => 'nullable|date',
            'accepted_until' => 'nullable|date',
            'make_current' => 'boolean',
        ]);

        $bytes = file_get_contents($request->file('file')->getRealPath());
        $path = "inz-forms/{$form->code}/".\Illuminate\Support\Str::slug($data['version_label']).'.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);

        $version = $form->versions()->updateOrCreate(
            ['version_label' => $data['version_label']],
            [
                'file_path' => $path,
                'is_acroform' => app(\App\Services\Immigration\InzFormFiller::class)->looksLikeAcroForm($bytes),
                'effective_from' => $data['effective_from'] ?? null,
                'accepted_until' => $data['accepted_until'] ?? null,
                'checked_at' => now(),
                'uploaded_by' => auth()->id(),
            ],
        );

        if ($request->boolean('make_current', true)) {
            $form->versions()->where('id', '!=', $version->id)->update(['is_current' => false]);
            $version->forceFill(['is_current' => true])->save();
        }

        return back()->with('success', "Uploaded {$form->code} {$data['version_label']}.");
    }

    /** Save the field map (pdf_field → context source) for a version. */
    public function inzSaveFieldMap(Request $request, \App\Models\InzFormVersion $version)
    {
        $data = $request->validate([
            'field_map' => 'present|array',
            'field_map.*.pdf_field' => 'required|string|max:120',
            'field_map.*.source' => 'nullable|string|max:120',
            'field_map.*.literal' => 'nullable|string|max:255',
        ]);

        $version->forceFill(['field_map' => array_values($data['field_map']), 'checked_at' => now()])->save();

        return back()->with('success', 'Field map saved.');
    }

    /** Record that a human verified this is still the current INZ version. */
    public function inzMarkChecked(\App\Models\InzFormVersion $version)
    {
        $version->forceFill(['checked_at' => now()])->save();

        return back()->with('success', 'Marked as checked.');
    }

    /** Create a new INZ form in the catalogue. */
    public function inzStoreForm(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:20|unique:inz_forms,code',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:40',
        ]);
        \App\Models\InzForm::create($data + ['is_active' => true]);

        return back()->with('success', "Added {$data['code']}.");
    }

    /** Update an INZ form's name / category / active flag. */
    public function inzUpdateForm(Request $request, \App\Models\InzForm $form)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:40',
            'is_active' => 'boolean',
        ]);
        $form->update($data);

        return back()->with('success', "Updated {$form->code}.");
    }

    /** Delete an INZ form and its versions. */
    public function inzDestroyForm(\App\Models\InzForm $form)
    {
        $code = $form->code;
        $form->delete();

        return back()->with('success', "Removed {$code}.");
    }

    /** Delete a single version. */
    public function inzDeleteVersion(\App\Models\InzFormVersion $version)
    {
        $version->delete();

        return back()->with('success', 'Version removed.');
    }

    /** Make a version the current one to file. */
    public function inzSetCurrentVersion(\App\Models\InzFormVersion $version)
    {
        $version->form->versions()->where('id', '!=', $version->id)->update(['is_current' => false]);
        $version->forceFill(['is_current' => true])->save();

        return back()->with('success', "{$version->version_label} is now current.");
    }

    public function checklistTemplates()
    {
        return inertia('portal/immigration/ChecklistTemplates', []);
    }

    public function profile()
    {
        $me = auth()->user();

        return inertia('portal/immigration/Profile', [
            'user' => $me->only(['id', 'name', 'email', 'role', 'iaa_licence_number', 'iaa_licence_type', 'iaa_licence_expiry', 'iaa_licence_verified_at']),
            'signature' => [
                'data_uri' => $me->signatureDataUri(),
                'updated_at' => optional($me->signature_updated_at)?->toIso8601String(),
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        // IAA licence details are a compliance record — admin-set + audited via
        // the admin Users screen (Build 12 fast-follow). Self-service editing
        // was removed so the advice gate cannot be self-certified: the licence
        // that prints on a client's legal document is not something its holder
        // edits. This endpoint no longer writes anything; the profile page
        // shows the licence read-only and points staff at an administrator.
        return back()->with('error', 'Licence details are managed by an administrator. Ask an admin to update your IAA licence record.');
    }

    /**
     * Save the staff member's e-signature — accepts either a drawn canvas
     * PNG (base64 data URL) or an uploaded image. Stored privately and
     * rendered onto engagement documents this user signs.
     */
    public function saveSignature(Request $request)
    {
        $request->validate([
            'signature_image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'signature_data' => 'nullable|string',
        ]);

        $me = auth()->user();

        try {
            $binary = null;
            $ext = 'png';

            if ($request->hasFile('signature_image')) {
                $file = $request->file('signature_image');
                $binary = file_get_contents($file->getRealPath());
                $ext = strtolower($file->getClientOriginalExtension()) === 'jpg' ? 'jpeg' : strtolower($file->getClientOriginalExtension());
                $ext = in_array($ext, ['png', 'jpeg'], true) ? $ext : 'png';
            } elseif ($request->filled('signature_data')) {
                // data:image/png;base64,XXXX  → raw bytes
                $data = $request->input('signature_data');
                if (preg_match('/^data:image\/(png|jpe?g);base64,/', $data, $m)) {
                    $ext = str_starts_with($m[1], 'jp') ? 'jpeg' : 'png';
                    $data = substr($data, strpos($data, ',') + 1);
                }
                $binary = base64_decode(str_replace(' ', '+', $data), true);
            }

            if (! $binary) {
                return back()->withErrors(['error' => 'No signature provided.']);
            }

            // Overwrite any previous signature file for this user.
            if ($me->signature_path) {
                Storage::disk('local')->delete($me->signature_path);
            }

            $path = "signatures/user-{$me->id}-".Str::random(8).".{$ext}";
            Storage::disk('local')->put($path, $binary);

            $me->forceFill([
                'signature_path' => $path,
                'signature_updated_at' => now(),
            ])->save();

            return back()->with('success', 'Signature saved.');
        } catch (\Throwable $e) {
            Log::error('Signature save failed', ['user_id' => $me?->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not save the signature.']);
        }
    }

    /** Remove the staff member's stored e-signature. */
    public function deleteSignature(Request $request)
    {
        $me = auth()->user();

        try {
            if ($me->signature_path) {
                Storage::disk('local')->delete($me->signature_path);
            }
            $me->forceFill(['signature_path' => null, 'signature_updated_at' => null])->save();

            return back()->with('success', 'Signature removed.');
        } catch (\Throwable $e) {
            Log::error('Signature delete failed', ['user_id' => $me?->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not remove the signature.']);
        }
    }

    /**
     * Task Board page — mirrors the Sales/Education shape. See
     * App\Http\Controllers\Portal\SalesController::tasks() for the
     * canonical implementation; everything here is the same query keyed
     * off the current user. Department-scoping is UI-only for now until
     * LeadTask grows a department column.
     */
    public function tasks(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $scope = $request->input('scope', 'mine');
            $now = now();
            $todayEnd = $now->copy()->endOfDay();
            $weekEnd = $now->copy()->endOfWeek();

            $base = \App\Models\LeadTask::with(['lead:id,lead_id,first_name,last_name,email,status', 'assignee:id,name,avatar_path', 'creator:id,name,avatar_path', 'attachments'])
                ->withCount('comments')
                ->when($scope === 'mine', fn ($q) => $q->where('assignee_id', $userId))
                ->when($scope === 'department', fn ($q) => $q->where('department', 'immigration'));

            $serialize = fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'note' => $t->note,
                'comments_count' => (int) ($t->comments_count ?? 0),
                'priority' => $t->priority,
                'progress' => (int) ($t->progress ?? 0),
                'due_at' => $t->due_at,
                'completed' => $t->completed,
                'completed_at' => $t->completed_at,
                'overdue' => ! $t->completed && $t->due_at && $t->due_at->isPast(),
                'type' => $t->type,
                'category' => $t->category,
                'department' => $t->department,
                'tags' => $t->tags,
                'status' => $t->status,
                'completion_notes' => $t->completion_notes,
                'attachments' => $t->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'url' => $a->url,
                    'original_filename' => $a->original_filename,
                    'is_image' => $a->is_image,
                    'mime_type' => $a->mime_type,
                    'size' => $a->size,
                ])->values(),
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name, 'avatar_url' => $t->assignee->avatar_url] : null,
                'additional_assignee_ids' => $t->additional_assignee_ids ?? [],
                'additional_lead_ids' => $t->additional_lead_ids ?? [],
                'creator' => $t->creator ? ['id' => $t->creator->id,  'name' => $t->creator->name, 'avatar_url' => $t->creator->avatar_url] : null,
                'lead' => $t->lead ? [
                    'id' => $t->lead->id,
                    'lead_id' => $t->lead->lead_id,
                    'name' => trim("{$t->lead->first_name} {$t->lead->last_name}"),
                    'status' => $t->lead->status,
                ] : null,
            ];

            // Full task set for the kanban (all dates, all statuses).
            $allTasks = (clone $base)->orderByDesc('created_at')->limit(1000)->get()->map($serialize);
            $today = (clone $base)->where('completed', false)->whereBetween('due_at', [$now, $todayEnd])->orderBy('due_at')->get()->map($serialize);
            $overdue = (clone $base)->where('completed', false)->whereNotNull('due_at')->where('due_at', '<', $now)->orderBy('due_at')->get()->map($serialize);
            $thisWeek = (clone $base)->where('completed', false)->whereBetween('due_at', [$todayEnd, $weekEnd])->orderBy('due_at')->get()->map($serialize);
            $undated = (clone $base)->where('completed', false)->whereNull('due_at')->orderByDesc('created_at')->limit(50)->get()->map($serialize);
            $recentlyDone = (clone $base)->where('completed', true)->where('completed_at', '>=', $now->copy()->subDays(7))->orderByDesc('completed_at')->limit(50)->get()->map($serialize);

            return inertia('portal/immigration/Tasks', [
                'portal' => 'immigration',
                'scope' => $scope,
                'all_tasks' => $allTasks,
                'today' => $today,
                'overdue' => $overdue,
                'this_week' => $thisWeek,
                'undated' => $undated,
                'recently_done' => $recentlyDone,
                'staffOptions' => \App\Models\User::whereNotIn('role', ['lead', 'revoked_lead'])->orderBy('name')->get(['id', 'name', 'role', 'avatar_path']),
                'recent_activity' => \App\Models\ActivityLog::where('action', 'like', 'lead_task.%')
                    ->latest()->limit(30)
                    ->get(['id', 'action', 'description', 'actor_name', 'actor_role', 'properties', 'created_at']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Immigration tasks page failed', ['error' => $e->getMessage()]);

            return inertia('portal/immigration/Tasks', ['portal' => 'immigration', 'scope' => 'mine', 'today' => [], 'overdue' => [], 'this_week' => [], 'undated' => [], 'recently_done' => [], 'staffOptions' => []]);
        }
    }
}
