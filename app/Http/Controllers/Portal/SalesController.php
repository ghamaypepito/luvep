<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Program;
use App\Traits\BuildsLeadRow;
use App\Traits\CreatesDashboardLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SalesController extends Controller
{
    use BuildsLeadRow;
    use CreatesDashboardLead;

    // Unified lead-pipeline stages (merged from the old `status` + `stage`
    // concepts into one). Order matters — this is the canonical pipeline
    // order surfaced in the dropdown.
    public const LEAD_STATUSES = [
        'New Leads',
        'Contact Attempted',
        'Contacted for Booking',
        'Booking Confirmation',
        'Missed the Meeting',
        'Qualified but Not Ready',
        'Qualified but No Funds',
        'Consultation Done',
        'Proposal Sent',
        'Program Selected',
        'Consultancy Agreement Sent',
        'Consultancy Agreement Signed',
        'Consultancy Agreement',
        'English Pro',
        'School Enrollment',
        'Visa Process',
        'Not Qualified',
        'Work Pathway / Other',
    ];

    private const BOOKING_STATUSES = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

    /** Sales overview: pipeline counts, 6-month trend, recent leads, upcoming consultations. */
    public function dashboard()
    {
        try {
            $now = now();
            $monthStart = $now->copy()->startOfMonth();
            $lastMonthStart = $monthStart->copy()->subMonths(1);

            $leadStats = [
                'total' => Lead::count(),
                'new' => Lead::where('status', 'New')->count(),
                'this_month' => Lead::where('created_at', '>=', $monthStart)->count(),
                'last_month' => Lead::whereBetween('created_at', [$lastMonthStart, $monthStart])->count(),
                'qualified' => Lead::whereIn('status', ['Qualified', 'Processing'])->count(),
                'closed' => Lead::where('status', 'Closed')->count(),
                'ai_done' => Lead::where('ai_analysis_status', 'completed')->count(),
                'ai_pending' => Lead::where('ai_analysis_status', 'processing')->count(),
            ];

            $bookingStats = [
                'total' => Booking::count(),
                'this_month' => Booking::where('created_at', '>=', $monthStart)->count(),
                'upcoming' => Booking::whereDate('appointment_date', '>=', $now->toDateString())->count(),
                'pending' => Booking::where('status', 'Pending')->count(),
            ];

            $leadsByMonth = collect(range(5, 0))->map(function ($i) use ($monthStart) {
                $m = $monthStart->copy()->subMonths($i);

                return [
                    'label' => $m->format('M'),
                    'count' => Lead::whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count(),
                ];
            })->all();

            return inertia('portal/sales/Dashboard', [
                'portal' => 'sales',
                'leadStats' => $leadStats,
                'bookingStats' => $bookingStats,
                'leadsByMonth' => $leadsByMonth,
                // Only leads still in the pipeline — a lead converted to a
                // student / case / accommodation client has moved on and
                // shouldn't resurface in the Sales "recent leads" widget.
                'recentLeads' => Lead::inLeadPipeline()
                    ->with([
                        'studyPlans', 'event', 'tags:id,name',
                        'portalUser:id,lead_id,last_login_at',
                        'stageUpdater:id,name', 'lastActivityUser:id,name',
                        'agent:id,name,avatar_path',
                        'notes' => fn ($q) => $q->latest(),
                        'documents:id,lead_id,checklist_key,status', 'faceImage',
                    ])
                    ->withCount(['notes', 'documents'])
                    ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
                    ->latest()->limit(8)->get()->map(fn ($l) => $this->leadRow($l)),
                'upcomingBookings' => Booking::with('lead')
                    ->whereDate('appointment_date', '>=', $now->toDateString())
                    ->orderBy('appointment_date')->orderBy('appointment_time')
                    ->limit(8)->get()->map(fn ($b) => $this->bookingRow($b)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Sales dashboard failed', ['error' => $e->getMessage()]);

            return inertia('portal/sales/Dashboard', [
                'portal' => 'sales',
                'leadStats' => array_fill_keys(['total', 'new', 'this_month', 'last_month', 'qualified', 'closed', 'ai_done', 'ai_pending'], 0),
                'bookingStats' => array_fill_keys(['total', 'this_month', 'upcoming', 'pending'], 0),
                'leadsByMonth' => [],
                'recentLeads' => collect(),
                'upcomingBookings' => collect(),
            ]);
        }
    }

    /** Full leads list with inline status updates. */
    public function leads()
    {
        try {
            // Eager-load the latest pre-screen / goal-setting notes so the
            // index can render their summary chips and the expander panel
            // without triggering N+1. `tasks_open_count` is a custom alias
            // for incomplete tasks only.
            // Sales only sees leads still in the pipeline — once any
            // department adopts the lead (student / English / case /
            // accommodation) it disappears from this table.
            $leads = Lead::inLeadPipeline()
                ->with([
                    'studyPlans',
                    'event',
                    'tags:id,name',
                    'portalUser:id,lead_id,last_login_at',
                    'stageUpdater:id,name', 'lastActivityUser:id,name',
                    'agent:id,name,avatar_path',
                    'notes' => fn ($q) => $q->latest(),
                    // Doc rows drive the "Docs progress" column in the
                    // leads table (via BuildsLeadRow::leadChecklistTotals).
                    'documents:id,lead_id,checklist_key,status',
                    'faceImage',
                ])
                ->withCount(['notes', 'documents'])
                ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
                ->latest()->get();

            return inertia('portal/sales/Leads', [
                'portal' => 'sales',
                'statuses' => self::LEAD_STATUSES,
                'programs' => Program::orderBy('title')->pluck('title')->filter()->values(),
                'staffOptions' => $this->dashboardStaff(),
                'leads' => $leads->map(fn ($l) => $this->leadRow($l)),
                // Full tag dictionary — the Leads-page Tag filter lists every
                // tag ever created, not just the ones on visible leads.
                'allTagNames' => \App\Models\LeadTag::orderBy('name')->pluck('name'),
                'events' => $this->eventsSummary(),
                'tabCounts' => $this->leadTabCounts(),
                // Recruiting agents roster for the Leads "Agents" tab — each
                // with a count of leads they've added (incl. converted ones).
                'agents' => $this->agentsSummary(),
                'visaOptions' => $this->visaOptions(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Sales leads list failed', ['error' => $e->getMessage()]);

            return inertia('portal/sales/Leads', ['portal' => 'sales', 'statuses' => self::LEAD_STATUSES, 'programs' => Program::orderBy('title')->pluck('title')->filter()->values(), 'staffOptions' => $this->dashboardStaff(), 'leads' => collect()]);
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

    /**
     * Recruiting agents (role='agent') with a total count of leads each has
     * added — for the Leads page "Agents" tab. The per-agent lead list is
     * built client-side from the leads collection (which carries `agent`),
     * so this only needs the roster + a headline count.
     */
    private function agentsSummary()
    {
        return \App\Models\User::where('role', 'agent')
            ->withCount('agentLeads')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'location', 'avatar_path'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'phone' => $a->phone,
                'location' => $a->location,
                'avatar_url' => $a->avatar_url,
                'leads_count' => (int) $a->agent_leads_count,
            ]);
    }

    /**
     * GET /portal/sales/events/{id}/registrations — the leads who registered
     * for an event (all of them, incl. converted), for the Events-tab drawer.
     */
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

    /**
     * GET /portal/sales/agents/{id}/leads — full-page list of leads a
     * recruiting agent has added, styled like Open opportunities. Reached
     * from the Agents tab's "View leads" action.
     *
     * Shared by sales, education and admin. The screen (and its row actions)
     * are picked by request path so each portal lands in its own layout with
     * a matching portalBase — same approach as eventRegistrantsPage.
     */
    public function agentLeadsPage($id)
    {
        $agent = \App\Models\User::where('role', 'agent')->findOrFail($id);

        $leads = Lead::where('agent_id', $agent->id)
            ->with([
                'studyPlans',
                'event',
                'tags:id,name',
                'portalUser:id,lead_id,last_login_at',
                'stageUpdater:id,name', 'lastActivityUser:id,name',
                'notes' => fn ($q) => $q->latest(),
                'documents:id,lead_id,checklist_key,status',
            ])
            ->withCount(['notes', 'documents'])
            ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
            ->latest()
            ->get()
            ->map(fn (Lead $l) => $this->leadRow($l));

        [$component, $portalBase] = match (true) {
            request()->is('admin/*') => ['admin/AgentLeads', '/admin'],
            request()->is('portal/education/*') => ['portal/education/AgentLeads', '/portal/education'],
            request()->is('portal/immigration-adviser/*') => ['portal/immigration-adviser/AgentLeads', '/portal/immigration-adviser'],
            request()->is('portal/sub-agent/*') => ['portal/sub-agent/AgentLeads', '/portal/sub-agent'],
            default => ['portal/sales/AgentLeads', '/portal/sales'],
        };

        return inertia($component, [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'location' => $agent->location,
                'avatar_url' => $agent->avatar_url,
                'leads_count' => $leads->count(),
            ],
            'leads' => $leads,
            'statuses' => self::LEAD_STATUSES,
            'portalBase' => $portalBase,
        ]);
    }

    /**
     * GET /portal/sales/events/{id}/registrants — full-page equivalent of
     * the JSON drawer above. Same data plus per-registrant notes and the
     * pipeline stage picker, rendered as its own screen so staff can
     * bookmark / share the URL rather than losing state on a modal
     * close.
     */
    public function eventRegistrantsPage($id)
    {
        $event = Event::findOrFail($id);

        // Same leadRow() shape the Open-opportunities table uses, so the
        // registrants table + its expandable dashboard panel render
        // identically. Event-specific note fields are merged on top.
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

        // Admin reuses this exact screen from /admin/events/{id}/registrants —
        // render under admin/ (AdminLayout) and point row actions back at the
        // /admin routes; department portals keep their /portal/{role} base.
        $isAdmin = request()->is('admin/*');
        $registrantsPage = request()->is('portal/immigration-adviser/*')
            ? 'portal/immigration-adviser/EventRegistrants'
            : ($isAdmin ? 'admin/EventRegistrants' : 'portal/sales/EventRegistrants');

        return inertia($registrantsPage, [
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
            'statuses' => \App\Models\Lead::STAGES,
            'portalBase' => $isAdmin ? '/admin' : '/portal/sales',
        ]);
    }

    /**
     * GET /portal/sales/leads/{id}/registration — read-only snapshot of
     * what a lead submitted via the public /register form.
     *
     * Registration data is scattered across several places on the lead
     * (dedicated columns, work_info / financial_info / family_info /
     * education_notes JSON, plus LeadDocument rows for uploaded files).
     * This method reconstructs the form-as-filled by pulling each field
     * from wherever storeRegistration put it, so sales staff see one
     * clean "here's what they said" page rather than having to unpack
     * five JSON blobs on the lead profile.
     */
    public function showLeadRegistration($id)
    {
        $lead = Lead::with(['documents:id,lead_id,checklist_key,original_name,file_path,size,mime,status,created_at'])
            ->findOrFail($id);

        $work = is_array($lead->work_info) ? $lead->work_info : [];
        $edu = is_array($lead->education_notes) ? $lead->education_notes : [];
        $family = is_array($lead->family_info) ? $lead->family_info : [];
        $partner = is_array($family['partner'] ?? null) ? $family['partner'] : [];

        // Group uploaded LeadDocument rows by the checklist bucket the
        // registration flow puts them in (cv / passport / diploma /
        // transcript). Anything without one of these keys is dropped —
        // this view is registration-only, not a general docs viewer.
        $docBucketMap = ['cv' => 'CV', 'passport' => 'Passport', 'diploma' => 'Diploma', 'transcript' => 'Transcript of Record'];
        $documents = collect($docBucketMap)->map(function ($label, $key) use ($lead) {
            $files = $lead->documents
                ->where('checklist_key', $key)
                ->values()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'original_name' => $d->original_name,
                    'size' => $d->size,
                    'mime' => $d->mime,
                    // Access-checked staff stream, not a public URL — client
                    // uploads live on the private disk (Privacy-Act fix).
                    'url' => $d->file_path ? route('admin.documents.download', ['docId' => $d->id]).'?inline=1' : null,
                    'created_at' => optional($d->created_at)->toIso8601String(),
                ])
                ->all();

            return ['key' => $key, 'label' => $label, 'files' => $files];
        })->values()->all();

        $isAdmin = request()->is('admin/*');
        $isAdviser = request()->is('portal/immigration-adviser/*');
        $regPage = $isAdviser ? 'portal/immigration-adviser/LeadRegistration'
            : ($isAdmin ? 'admin/LeadRegistration' : 'portal/sales/LeadRegistration');
        $regBase = $isAdviser ? '/portal/immigration-adviser' : ($isAdmin ? '/admin' : '/portal/sales');

        return inertia($regPage, [
            'portalBase' => $regBase,
            'lead' => [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: 'Unnamed lead',
                'email' => $lead->email,
                'phone' => $lead->phone,
                'stage' => $lead->stage,
                'status' => $lead->status,
                'source' => $lead->source,
                'created_at' => optional($lead->created_at)->toIso8601String(),
            ],
            // Reconstructed form sections, matching the /register page's
            // section order + labels so staff read exactly what the lead
            // was shown.
            'sections' => [
                [
                    'title' => 'Personal',
                    'fields' => [
                        ['label' => 'First name',        'value' => $lead->first_name],
                        ['label' => 'Last name',         'value' => $lead->last_name],
                        ['label' => 'Email',             'value' => $lead->email],
                        ['label' => 'Phone',             'value' => $lead->phone],
                        ['label' => 'Age',               'value' => $edu['age'] ?? null],
                        ['label' => 'Gender',            'value' => $lead->gender],
                        ['label' => 'Civil status',      'value' => $lead->marital_status],
                        ['label' => 'Country of origin', 'value' => $lead->country_of_birth],
                    ],
                ],
                [
                    'title' => 'Education & Interest',
                    'fields' => [
                        ['label' => 'Current education attainment',   'value' => $lead->highest_qualification],
                        ['label' => "Bachelor's course / program",    'value' => $lead->highest_qualification_field],
                        ['label' => 'Current job / occupation',       'value' => $lead->current_position_title],
                        ['label' => 'Pathway of interest',            'value' => $edu['pathway_interest'] ?? null],
                    ],
                ],
                [
                    'title' => 'Partner / Spouse',
                    'visible' => ($lead->marital_status === 'Married') || array_filter($partner, fn ($v) => $v !== null && $v !== ''),
                    'fields' => [
                        ['label' => 'Full name',                        'value' => $partner['full_name'] ?? null],
                        ['label' => 'Age',                              'value' => $partner['age'] ?? null],
                        ['label' => 'Current education level',          'value' => $partner['education_level'] ?? null],
                        ['label' => 'Current work experience',          'value' => $partner['work_experience'] ?? null],
                        ['label' => 'Years of experience',              'value' => $partner['years_experience'] ?? null],
                    ],
                ],
                [
                    'title' => 'Children',
                    'visible' => ! is_null($lead->number_of_children) && (int) $lead->number_of_children > 0,
                    'fields' => [
                        ['label' => 'Number of children',       'value' => $lead->number_of_children],
                        ['label' => 'Child age(s)',             'value' => $lead->dependent_children_notes],
                        ['label' => 'Will you bring your children?', 'value' => $edu['bring_children'] ?? null],
                    ],
                ],
                [
                    'title' => 'Additional information',
                    'fields' => [
                        ['label' => 'Question for our advisor', 'value' => $edu['advisor_question'] ?? null, 'multiline' => true],
                    ],
                ],
            ],
            'documents' => $documents,
        ]);
    }

    /** Programs catalogue for the sales portal — same shared Program model
     *  + ProgramController CRUD that education uses. */
    public function programs()
    {
        // Return the FULL program attributes — the shared ProgramModal seeds
        // its edit form from this row, so any omitted field would render
        // blank and be wiped on save. Only `enrolled` is extra.
        $programs = Program::orderBy('title')->get()->map(fn (Program $p) => array_merge(
            $p->attributesToArray(),
            ['enrolled' => Lead::whereHas('studyPlans', fn ($q) => $q->where('preferred_course', $p->title))->count()]
        ));

        // Picker options for the "School" dropdown in the New/Edit
        // Program modal — same shape the admin Programs page ships.
        $schools = \App\Models\School::orderBy('name')->get(['id', 'name']);

        return inertia('portal/sales/Programs', [
            'portal' => 'sales',
            'programs' => $programs,
            'schools' => $schools,
        ]);
    }

    /** Manually add a lead from the dashboard "Add Lead" form. */
    public function storeLead(Request $request)
    {
        $validated = $request->validate(
            $this->dashboardLeadRules(self::LEAD_STATUSES) + $this->dashboardDocumentRules()
        );

        try {
            $lead = $this->createDashboardLead($validated);
            $this->storeDashboardLeadDocuments($lead, $request);

            return back()->with('success', "Lead {$lead->lead_id} added.");
        } catch (\Throwable $e) {
            Log::error('Sales add-lead failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not add that lead. Please try again.');
        }
    }

    public function updateLead(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::LEAD_STATUSES)],
            'stage' => 'nullable|string|max:120',
        ]);

        try {
            $lead = Lead::findOrFail($id);
            $lead->status = $validated['status'];
            $lead->stage = ! empty($validated['stage']) ? $validated['stage'] : $lead->stage;
            $lead->save();

            // Audited automatically by the LogsActivity trait on the Lead model.

            return back()->with('success', "Lead {$lead->lead_id} updated.");
        } catch (\Throwable $e) {
            Log::error('Lead update failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not update that lead. Please try again.');
        }
    }

    /**
     * Assign (or clear) the recruiting agent on multiple leads at once.
     * Drives the "Set agent" bulk action on the Leads table — one query
     * instead of a request per row.
     */
    public function bulkAssignAgent(Request $request)
    {
        $validated = $request->validate([
            'lead_ids' => 'required|array|min:1',
            'lead_ids.*' => 'integer|exists:leads,id',
            'agent_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'agent')],
        ]);

        try {
            $count = Lead::whereIn('id', $validated['lead_ids'])
                ->update(['agent_id' => $validated['agent_id'] ?: null]);

            $msg = $validated['agent_id']
                ? "Agent assigned to {$count} lead".($count === 1 ? '' : 's').'.'
                : "Agent cleared from {$count} lead".($count === 1 ? '' : 's').'.';

            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            Log::error('Bulk agent assign failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not assign the agent to the selected leads.');
        }
    }

    /**
     * Human document-type label for an agreement — the specific scenario/variant
     * (e.g. "Standard · 150,000", "Offshore - Philippines") rather than the
     * generic bucket. Works for both live docs (variant "consultancy:key:mode"
     * or "engagement-english[-offshore]") and pending reviews (scenario like
     * "consultancy_std_150" / "english_engagement").
     */
    private function agreementTypeLabel(string $checklistKey, ?string $variant): string
    {
        if ($checklistKey === 'agree.proposal') {
            return 'Study Proposal';
        }
        if ($checklistKey === 'agree.engagement_english') {
            if (in_array($variant, ['engagement-english-offshore-1000', 'english_offshore_1000'], true)) {
                return 'Offshore - English ($1,000)';
            }

            return in_array($variant, ['engagement-english-offshore', 'english_offshore'], true)
                ? 'Offshore - English'
                : 'Offshore - Philippines';
        }
        if ($checklistKey === 'agree.consultancy') {
            $key = str_starts_with((string) $variant, 'consultancy:')
                ? (explode(':', (string) $variant)[1] ?? null)
                : str_replace('consultancy_', '', (string) $variant);

            return match ($key) {
                'std_150' => 'Standard · 150,000',
                'voucher_150' => 'With Voucher · 150,000',
                'std_100' => 'Standard · 100,000',
                'english_100' => 'With English · 100,000',
                'offshore' => 'Standard · Offshore',
                'offshore_zero' => 'Standard · Offshore — Zero fees',
                'onshore' => 'Onshore Engagement',
                default => 'Consultancy Agreement',
            };
        }

        return ucfirst(str_replace(['agree.', '_'], ['', ' '], $checklistKey));
    }

    /** Agreement variant/scenario → the ConsultancyReviewService fee-item type. */
    private function variantToFeeType(string $checklistKey, ?string $variant): ?string
    {
        if ($checklistKey === 'agree.engagement_english') {
            if (in_array($variant, ['engagement-english-offshore-1000', 'english_offshore_1000'], true)) {
                return 'english_offshore_1000';
            }

            return in_array($variant, ['engagement-english-offshore', 'english_offshore'], true)
                ? 'english_offshore'
                : 'english_engagement';
        }
        if ($checklistKey === 'agree.consultancy') {
            // Live docs: "consultancy:std_150:single"; pending: "consultancy_std_150".
            if (str_starts_with((string) $variant, 'consultancy:')) {
                return 'consultancy_'.(explode(':', (string) $variant)[1] ?? '');
            }

            return $variant ?: null;
        }

        return null; // proposals have no fee total
    }

    /** NZ$ for offshore / English-offshore agreements, Php otherwise. */
    private function agreementCurrencySymbol(string $checklistKey, ?string $variant): string
    {
        $type = $this->variantToFeeType($checklistKey, $variant);

        return in_array($type, ['consultancy_offshore', 'consultancy_offshore_zero', 'english_offshore', 'english_offshore_1000'], true)
            ? 'NZ$'
            : 'Php';
    }

    /**
     * Fee total for one agreement doc. Prefers the lead's stored review amounts
     * when its scenario matches this doc (those carry the reviewer's edits);
     * otherwise falls back to the scenario's default fee total.
     */
    private function agreementTotal(Lead $l, string $checklistKey, ?string $variant): ?int
    {
        $type = $this->variantToFeeType($checklistKey, $variant);
        if ($type === null) {
            return null;
        }

        $review = is_array($l->consultancy_review) ? $l->consultancy_review : [];
        if (($review['scenario'] ?? null) === $type && is_array($review['items'] ?? null)) {
            return array_sum(array_map(fn ($m) => (int) ($m['amount'] ?? 0), $review['items']));
        }

        $items = \App\Services\ConsultancyReviewService::feeItems($type, []);

        return array_sum(array_map(fn ($it) => (int) ($it['amount'] ?? 0), $items));
    }

    /**
     * GET /portal/{role}/leads/proposals-agreements — sidebar page listing
     * every lead that has at least one generated Proposal or Agreement
     * (checklist_key in the agreement bucket + source='generated'). The
     * "+ New" button on this page targets any lead, so we also expose a
     * lightweight roster of pipeline leads for the picker.
     */
    public function proposalsAgreementsPage(Request $request)
    {
        try {
            // Proposals are no longer PDF docs — they're a shortlist of up
            // to 3 programs a staff member suggested for the lead, stored
            // on leads.proposed_program_ids. Agreements are still generated
            // PDFs (Consultancy, English Engagement).
            $agreementKeys = ['agree.consultancy', 'agree.engagement_english'];
            $generatedKeys = $agreementKeys;

            // Stages the system reads as "ready for a document":
            //   Proposal-ready → Consultation Done  (adviser has met the lead,
            //                    time to send a written pitch)
            //   Agreement-ready → Proposal Sent + Consultancy Agreement stage
            //                    (client has seen the proposal / is ready to
            //                    sign, but no consultancy agreement generated yet)
            $suggestionProposalStages = ['Consultation Done'];
            $suggestionAgreementStages = ['Proposal Sent', 'Consultancy Agreement'];
            $suggestionStages = array_merge($suggestionProposalStages, $suggestionAgreementStages);

            // Every lead that has at least one generated doc, plus a per-lead
            // count / latest-generated date so each tab can render useful
            // context without a second query.
            $withDocs = Lead::whereHas('documents', function ($q) use ($generatedKeys) {
                $q->whereIn('checklist_key', $generatedKeys)
                    ->where('source', \App\Models\LeadDocument::SOURCE_GENERATED);
            })
                ->with(['faceImage', 'documents' => function ($q) use ($generatedKeys) {
                    $q->whereIn('checklist_key', $generatedKeys)
                        ->where('source', \App\Models\LeadDocument::SOURCE_GENERATED)
                        ->with('uploader:id,name,email')
                        ->orderByDesc('created_at');
                }])
                ->orderByDesc('updated_at')
                ->get();

            $mapRow = function (Lead $l) {
                $docs = $l->documents->map(function ($d) use ($l) {
                    // source_variant now carries the applicant mode as a
                    // third segment (e.g. "consultancy:std_150:couple").
                    // Older rows will have only 2 segments — default to
                    // 'single' since that was the only mode at the time.
                    $applicantMode = null;
                    if ($d->checklist_key === 'agree.consultancy') {
                        $parts = explode(':', (string) $d->source_variant);
                        $applicantMode = $parts[2] ?? 'single';
                    }

                    return [
                        'id' => $d->id,
                        'checklist_key' => $d->checklist_key,
                        // Specific document type (the scenario/variant), not the
                        // generic bucket — e.g. "Standard · 150,000",
                        // "Offshore - Philippines".
                        'type' => $this->agreementTypeLabel($d->checklist_key, $d->source_variant),
                        'variant' => $d->source_variant,
                        'applicant_mode' => $applicantMode,
                        // Fee total for the Total column — the lead's verified
                        // review amounts when they match this doc, else the
                        // scenario's default fee total.
                        'total_amount' => $this->agreementTotal($l, $d->checklist_key, $d->source_variant),
                        'currency_symbol' => $this->agreementCurrencySymbol($d->checklist_key, $d->source_variant),
                        // True when this doc's agreement was approved in the
                        // verification queue — the Agreements tab shows "Sent"
                        // even for older leads whose pipeline status predates
                        // the approve→sent stage bump.
                        'review_approved' => (is_array($l->consultancy_review)
                            && ($l->consultancy_review['status'] ?? null) === 'approved'
                            && ($l->consultancy_review['scenario'] ?? null) === $this->variantToFeeType($d->checklist_key, $d->source_variant)),
                        'original_name' => $d->original_name,
                        'size' => $d->size,
                        'created_at' => optional($d->created_at)->toIso8601String(),
                        'note' => trim((string) ($d->note ?? '')) ?: null,
                        'notes' => collect(is_array($d->notes) ? $d->notes : [])
                            ->map(fn ($n) => [
                                'id' => $n['id'] ?? (string) \Illuminate\Support\Str::uuid(),
                                'tag' => $n['tag'] ?? 'note',
                                'body' => $n['body'] ?? '',
                                'author' => $n['author'] ?? 'Staff',
                                'role' => $n['role'] ?? null,
                                'created_at' => $n['created_at'] ?? null,
                                'actioned_at' => $n['actioned_at'] ?? null,
                                'replies' => array_values(is_array($n['replies'] ?? null) ? $n['replies'] : []),
                            ])->values(),
                        'uploader' => $d->uploader ? [
                            'id' => $d->uploader->id,
                            'name' => $d->uploader->name,
                        ] : null,
                    ];
                })->values();

                return [
                    'id' => $l->id,
                    'lead_id' => $l->lead_id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                    'avatar_url' => $l->faceImageUrl(),
                    'email' => $l->email,
                    'phone' => $l->phone,
                    'stage' => $l->stage,
                    'status' => $l->status,
                    'documents' => $docs,
                    'documents_count' => $docs->count(),
                    'latest_generated_at' => $docs->first()['created_at'] ?? null,
                ];
            };

            // Tab: Agreements — leads with at least one Agreement generated
            // (Consultancy or English Engagement).
            $agreements = $withDocs
                ->filter(fn ($l) => $l->documents->contains(fn ($d) => in_array($d->checklist_key, $agreementKeys, true)))
                ->map($mapRow)
                ->values();

            // Consultancy agreements still IN verification (pending / verified)
            // have no generated PDF yet — but staff should still see them on the
            // Agreements tab as "For verification" entries (in addition to the
            // verification queue). Skip any lead already shown with a real doc.
            $shownIds = $agreements->pluck('id');
            $pendingConsultancy = Lead::whereNotNull('consultancy_review')
                ->whereIn('consultancy_review->status', ['pending', 'verified'])
                ->with('faceImage')
                ->orderByDesc('updated_at')
                ->get()
                ->reject(fn (Lead $l) => $shownIds->contains($l->id))
                ->map(function (Lead $l) {
                    $review = is_array($l->consultancy_review) ? $l->consultancy_review : [];
                    $items = is_array($review['items'] ?? null) ? $review['items'] : [];
                    $scenario = $review['scenario'] ?? null;
                    // English agreements now go through this queue too — reflect
                    // their real document bucket + type, not "Consultancy".
                    $isEnglish = in_array($scenario, ['english_engagement', 'english_offshore', 'english_offshore_1000'], true);
                    $key = $isEnglish ? 'agree.engagement_english' : 'agree.consultancy';

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'avatar_url' => $l->faceImageUrl(),
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'stage' => $l->stage,
                        'status' => $l->status,
                        'documents' => [[
                            'id' => 'review-'.$l->id,
                            'checklist_key' => $key,
                            'type' => $this->agreementTypeLabel($key, $scenario),
                            'variant' => $review['scenario'] ?? null,
                            'applicant_mode' => $review['applicant_mode'] ?? 'single',
                            'original_name' => $review['scenario_label'] ?? 'Consultancy Agreement',
                            'size' => null,
                            'created_at' => $review['submitted_at'] ?? null,
                            'note' => null,
                            'notes' => [],
                            'uploader' => null,
                            // Flags the Agreements table to show the verification
                            // status instead of a download / lifecycle badge.
                            'pending_verification' => true,
                            'verification_status' => $review['status'] ?? 'pending', // pending | verified
                            'total_amount' => array_sum(array_map(fn ($m) => (int) ($m['amount'] ?? 0), $items)),
                            'currency_symbol' => ($review['currency'] ?? 'php') === 'nzd' ? 'NZ$' : 'Php',
                        ]],
                        'documents_count' => 1,
                        'latest_generated_at' => $review['submitted_at'] ?? null,
                    ];
                });

            $agreements = $agreements->concat($pendingConsultancy)->values();

            // Tab: Proposals — leads with a program shortlist saved. Each
            // row exposes the picked programs (id + title) so the frontend
            // can render badges without a second lookup.
            $programCatalog = Program::with('school:id,name')->orderBy('title')
                ->get(['id', 'title', 'level', 'category', 'price_text', 'location', 'industry', 'school_id', 'institution']);
            $programMap = $programCatalog->keyBy('id');

            // Reusable: turn a list of program ids into badge payloads,
            // preserving order and dropping any that no longer exist.
            $mapPrograms = fn ($ids) => collect(is_array($ids) ? $ids : [])
                ->map(fn ($pid) => $programMap->get((int) $pid))
                ->filter()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'level' => $p->level,
                    'category' => $p->category,
                    'price_text' => $p->price_text,
                    'location' => $p->location,
                    'school' => $p->school?->name ?: ($p->institution ?: null),
                ])
                ->values();

            $proposalLeads = Lead::whereNotNull('proposed_program_ids')
                ->where(function ($q) {
                    // JSON_LENGTH not portable across SQLite (tests) and
                    // MySQL, so filter empty arrays in PHP instead.
                    $q->whereRaw("proposed_program_ids != '[]'");
                })
                // Full proposal version history, newest first, with who saved
                // each one.
                ->with(['faceImage', 'proposals.creator:id,name'])
                ->orderByDesc('updated_at')
                ->get();

            // Resolve reviewer / submitter names once for the "owner" line.
            $ownerIds = $proposalLeads->flatMap(function (Lead $l) {
                $r = is_array($l->proposal_review) ? $l->proposal_review : [];

                return [$r['verified_by'] ?? null, $r['submitted_by'] ?? null, $r['changes_requested']['by'] ?? null];
            })->filter()->unique()->values();
            $ownerNames = \App\Models\User::whereIn('id', $ownerIds)->pluck('name', 'id');

            $proposals = $proposalLeads
                ->filter(fn (Lead $l) => is_array($l->proposed_program_ids) && count($l->proposed_program_ids) > 0)
                ->map(function (Lead $l) use ($mapPrograms, $programMap, $ownerNames) {
                    // Active shortlist = the lead's current proposed_program_ids;
                    // its selection is the live preferred_program_id. Each pick
                    // is merged with the Program Verification overrides (fee /
                    // school / intake / per-program status), the internal staff
                    // note, and the client-facing reason.
                    $meta = is_array($l->proposed_program_meta) ? $l->proposed_program_meta : [];
                    $reasons = is_array($l->proposed_program_reasons) ? $l->proposed_program_reasons : [];
                    $picks = collect(is_array($l->proposed_program_ids) ? $l->proposed_program_ids : [])
                        ->map(function ($pid) use ($programMap, $meta, $reasons) {
                            $p = $programMap->get((int) $pid);
                            if (! $p) {
                                return null;
                            }
                            $m = is_array($meta[(string) $p->id] ?? null) ? $meta[(string) $p->id] : [];

                            return [
                                'id' => $p->id,
                                'title' => $p->title,
                                'level' => $p->level,
                                'category' => $p->category,
                                'price_text' => $p->price_text,
                                'location' => $p->location,
                                // Program Verification overrides + notes.
                                'school' => $m['school'] ?? null,
                                'intake' => $m['intake'] ?? null,
                                'fee' => $m['fee'] ?? null,
                                'fee_confirmed' => (bool) ($m['fee_confirmed'] ?? false),
                                'verify_status' => $m['status'] ?? null, // verified | needs_check | null
                                'note' => trim((string) ($m['note'] ?? '')) ?: null,   // internal (staff)
                                'reason' => trim((string) ($reasons[(string) $p->id] ?? '')) ?: null, // client-facing
                                // Threaded review notes for this programme (Proposals inbox).
                                'notes' => collect(is_array($m['notes'] ?? null) ? $m['notes'] : [])
                                    ->map(fn ($n) => [
                                        'id' => $n['id'] ?? null,
                                        'tag' => $n['tag'] ?? 'note',
                                        'body' => $n['body'] ?? '',
                                        'author' => $n['author'] ?? 'Staff',
                                        'role' => $n['role'] ?? null,
                                        'created_at' => $n['created_at'] ?? null,
                                        'actioned_at' => $n['actioned_at'] ?? null,
                                        'actioned_by' => $n['actioned_by'] ?? null,
                                        'replies' => collect(is_array($n['replies'] ?? null) ? $n['replies'] : [])
                                            ->map(fn ($rp) => [
                                                'id' => $rp['id'] ?? null,
                                                'body' => $rp['body'] ?? '',
                                                'author' => $rp['author'] ?? 'Staff',
                                                'role' => $rp['role'] ?? null,
                                                'created_at' => $rp['created_at'] ?? null,
                                            ])->values(),
                                    ])->values(),
                            ];
                        })
                        ->filter()
                        ->values();

                    // Previous versions = every saved proposal EXCEPT the newest
                    // (which is the active one shown above). Each keeps the
                    // program it had selected at the time.
                    $previous = $l->proposals->slice(1)->map(fn ($p) => [
                        'id' => $p->id,
                        'programs' => $mapPrograms($p->program_ids),
                        'programs_count' => count($p->program_ids ?? []),
                        'selected_program_id' => $p->selected_program_id,
                        'created_by' => optional($p->creator)->name,
                        'created_at' => optional($p->created_at)->toIso8601String(),
                    ])->values();

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'avatar_url' => $l->faceImageUrl(),
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'stage' => $l->stage,
                        'status' => $l->status,
                        'programs' => $picks,
                        'programs_count' => $picks->count(),
                        // Previous proposals kept for reference (newest first).
                        'previous' => $previous,
                        'previous_count' => $previous->count(),
                        // The active proposal's chosen program (null when a new
                        // proposal was just created — nothing selected yet).
                        'preferred_program_id' => $l->preferred_program_id,
                        'preferred_program_chosen_at' => optional($l->preferred_program_chosen_at)->toIso8601String(),
                        // Verification status: pending | verified | approved.
                        // Null = legacy proposal (predates the workflow).
                        'proposal_status' => $l->proposalStatus(),
                        // Reviewer's "request changes" note + the specific
                        // programmes flagged for revision (from Program
                        // Verification). Null when nothing was requested.
                        'changes_requested' => (function () use ($l, $ownerNames) {
                            $r = is_array($l->proposal_review) ? $l->proposal_review : [];
                            $cr = $r['changes_requested'] ?? null;

                            return is_array($cr) ? [
                                'message' => $cr['message'] ?? null,
                                'program_ids' => array_map('intval', $cr['program_ids'] ?? []),
                                'at' => $cr['at'] ?? null,
                                'by' => isset($cr['by']) ? ($ownerNames[$cr['by']] ?? null) : null,
                            ] : null;
                        })(),
                        // Review-inbox grouping: action sits with staff (needs_you)
                        // until the client has chosen / the proposal is approved,
                        // when it's waiting on the client (with_client).
                        'group' => ($l->preferred_program_id || $l->proposalStatus() === 'approved') ? 'with_client' : 'needs_you',
                        'owner_name' => (function () use ($l, $ownerNames) {
                            $r = is_array($l->proposal_review) ? $l->proposal_review : [];
                            $id = $r['verified_by'] ?? ($r['changes_requested']['by'] ?? ($r['submitted_by'] ?? null));

                            return $id ? ($ownerNames[$id] ?? null) : null;
                        })(),
                        'created_by_label' => $l->preferred_program_id ? 'client selected' : 'created by staff',
                        'updated_at' => optional($l->updated_at)->toIso8601String(),
                    ];
                })
                ->values();

            // Tab: Suggestions — leads whose pipeline stage tells us they
            // should have a proposal (program shortlist) or agreement (PDF)
            // but don't yet. Each row carries a `suggestion` field so the
            // frontend can hint what the New button should create.
            $suggestions = Lead::inLeadPipeline()
                ->whereIn('status', $suggestionStages)
                ->where(function ($q) use ($generatedKeys) {
                    // Proposal-ready leads: no program shortlist yet.
                    // Agreement-ready leads: no generated PDF yet. Either
                    // gap qualifies, so OR the two conditions.
                    $q->where(function ($qq) {
                        $qq->whereNull('proposed_program_ids')
                            ->orWhereRaw("proposed_program_ids = '[]'");
                    })->orWhereDoesntHave('documents', function ($qq) use ($generatedKeys) {
                        $qq->whereIn('checklist_key', $generatedKeys)
                            ->where('source', \App\Models\LeadDocument::SOURCE_GENERATED);
                    });
                })
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (Lead $l) use ($suggestionProposalStages, $generatedKeys) {
                    $suggested = in_array($l->status, $suggestionProposalStages, true) ? 'proposal' : 'agreement';
                    // Skip suggestions where the specific gap is already
                    // closed for THIS suggestion type. Prevents a lead who
                    // has a proposal but no agreement from showing up as
                    // "needs proposal" and vice versa.
                    if ($suggested === 'proposal' && ! empty($l->proposed_program_ids)) {
                        return null;
                    }
                    if ($suggested === 'agreement') {
                        $hasAgreement = $l->documents()
                            ->whereIn('checklist_key', $generatedKeys)
                            ->where('source', \App\Models\LeadDocument::SOURCE_GENERATED)
                            ->exists();
                        if ($hasAgreement) {
                            return null;
                        }
                    }

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'stage' => $l->stage,
                        'status' => $l->status,
                        'suggestion' => $suggested,
                        'updated_at' => optional($l->updated_at)->toIso8601String(),
                    ];
                })
                ->filter()
                ->values();

            // Roster for the "+ New" picker — leads staff can generate a
            // proposal/agreement for. Pipeline leads, PLUS students and
            // free-assessment / education-enrolment leads: those progress out of
            // the raw pipeline (a free-assessment enquiry becomes a student or a
            // case) but staff still generate documents for them, and
            // inLeadPipeline() alone wrongly hid them — so a free-assessment
            // lead's name couldn't be searched here.
            $picker = Lead::where(function ($q) {
                $q->where(function ($p) {
                    $p->where('is_student', false)
                        ->where('is_immigration_case', false)
                        ->where('is_accommodation_client', false)
                        ->where('is_english_student', false);
                })
                    ->orWhere('is_student', true)
                    ->orWhereIn('source', ['free-assessment', 'education-enrolment']);
            })
                ->orderBy('first_name')
                ->limit(500)
                ->get(['id', 'lead_id', 'first_name', 'last_name', 'email'])
                ->map(fn (Lead $l) => [
                    'id' => $l->id,
                    'lead_id' => $l->lead_id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                    'email' => $l->email,
                ]);

            return inertia($this->proposalsPageForRequest($request), [
                'portal' => $this->currentPortalFromRequest($request),
                'suggestions' => $suggestions,
                'proposals' => $proposals,
                'agreements' => $agreements,
                'picker' => $picker,
                // Program catalogue for the Proposal picker in the New modal.
                'programs' => $programCatalog->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'level' => $p->level,
                    'category' => $p->category,
                    'price_text' => $p->price_text,
                    'location' => $p->location,
                    'industry' => $p->industry,
                    'school' => $p->school?->name ?: ($p->institution ?: null),
                ])->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Proposals & Agreements page failed', ['error' => $e->getMessage()]);

            return inertia($this->proposalsPageForRequest($request), [
                'portal' => $this->currentPortalFromRequest($request),
                'suggestions' => collect(),
                'proposals' => collect(),
                'agreements' => collect(),
                'picker' => collect(),
                'programs' => collect(),
            ]);
        }
    }

    /**
     * Server-side lead search for the "+ New" proposal/agreement picker. The
     * page ships an initial roster (first 500), but on a large dataset a lead
     * late in the alphabet is cut off — so the modal searches here instead of
     * filtering the capped list client-side, making ANY eligible lead findable.
     */
    public function docPickerSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $leads = Lead::query()
            ->where(function ($w) {
                $w->where(function ($p) {
                    $p->where('is_student', false)
                        ->where('is_immigration_case', false)
                        ->where('is_accommodation_client', false)
                        ->where('is_english_student', false);
                })
                    ->orWhere('is_student', true)
                    ->orWhereIn('source', ['free-assessment', 'education-enrolment']);
            })
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                // Full-name concat differs by driver (MySQL prod / sqlite tests).
                $concat = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
                    ? "(first_name || ' ' || last_name)"
                    : "CONCAT(first_name, ' ', last_name)";
                $w->where(function ($s) use ($like, $concat) {
                    $s->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhereRaw("{$concat} like ?", [$like])
                        ->orWhere('email', 'like', $like)
                        ->orWhere('lead_id', 'like', $like);
                });
            })
            ->orderBy('first_name')
            ->limit(50)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'email'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                'email' => $l->email,
            ]);

        return response()->json(['leads' => $leads]);
    }

    /** Which portal prefix served this request (drives the frontend base URL). */
    private function currentPortalFromRequest(Request $request): string
    {
        $path = $request->path();
        foreach (['sales', 'education', 'immigration'] as $p) {
            if (str_starts_with($path, "portal/{$p}/")) {
                return $p;
            }
        }
        if (str_starts_with($path, 'admin/')) {
            return 'admin';
        }

        return 'sales';
    }

    /**
     * Which Inertia page to render — matches the URL prefix so the right
     * layout wraps it (EducationLayout for /portal/education, AdminLayout
     * for /admin, etc). Each non-sales entry is a thin re-export of the
     * sales page.
     */
    private function proposalsPageForRequest(Request $request): string
    {
        return match ($this->currentPortalFromRequest($request)) {
            'education' => 'portal/education/ProposalsAgreements',
            'immigration' => 'portal/immigration/ProposalsAgreements',
            'admin' => 'admin/ProposalsAgreements',
            default => 'portal/sales/ProposalsAgreements',
        };
    }

    /**
     * Soft-delete (archive) multiple leads at once. Same behaviour as the
     * single-lead delete on the lead detail page — the rows are recoverable
     * via the admin Trash view.
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'lead_ids' => 'required|array|min:1',
            'lead_ids.*' => 'integer|exists:leads,id',
        ]);

        try {
            $count = Lead::whereIn('id', $validated['lead_ids'])->delete();

            return back()->with('success', "Deleted {$count} lead".($count === 1 ? '' : 's').'.');
        } catch (\Throwable $e) {
            Log::error('Bulk lead delete failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not delete the selected leads.');
        }
    }

    /**
     * Assign (or clear) the recruiting agent on a lead. `agent_id` must be a
     * registered user with the 'agent' role, or null to detach.
     */
    public function updateLeadAgent(Request $request, $id)
    {
        $validated = $request->validate([
            'agent_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'agent')],
        ]);

        try {
            $lead = Lead::findOrFail($id);
            $lead->agent_id = $validated['agent_id'] ?: null;
            $lead->save();

            return back()->with('success', "Lead {$lead->lead_id} agent updated.");
        } catch (\Throwable $e) {
            Log::error('Lead agent update failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not update the agent. Please try again.');
        }
    }

    /**
     * Tasks & Follow-ups — every open + recently-completed task across
     * every lead, bucketed Today / This Week / Overdue / All so staff
     * can plan their day from one screen instead of opening each lead.
     */
    public function tasks(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $scope = $request->input('scope', 'mine'); // mine | all
            $now = now();
            $todayEnd = $now->copy()->endOfDay();
            $weekEnd = $now->copy()->endOfWeek();

            $base = \App\Models\LeadTask::with(['lead:id,lead_id,first_name,last_name,email,status', 'assignee:id,name,avatar_path', 'creator:id,name,avatar_path', 'attachments'])
                ->withCount('comments')
                ->when($scope === 'mine', fn ($q) => $q->where('assignee_id', $userId))
                ->when($scope === 'department', fn ($q) => $q->where('department', 'sales'));

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
                'creator' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name, 'avatar_url' => $t->creator->avatar_url] : null,
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
            $recentlyDone = (clone $base)->where('completed', true)->orderByDesc('completed_at')->limit(20)->get()->map($serialize);

            return inertia('portal/sales/Tasks', [
                'portal' => 'sales',
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
            Log::error('Sales tasks page failed', ['error' => $e->getMessage()]);

            return inertia('portal/sales/Tasks', ['portal' => 'sales', 'scope' => 'mine', 'today' => [], 'overdue' => [], 'this_week' => [], 'undated' => [], 'recently_done' => [], 'staffOptions' => []]);
        }
    }

    /**
     * Sales Weekly Report — 11 sections per spec. Sections backed by real
     * data we have today; ones that need new infra (AI tracking, lead
     * assignment, sales targets) return null so the frontend renders a
     * "needs infra" placeholder.
     */
    public function report(Request $request)
    {
        try {
            // Week anchor — default to the current ISO week. URL query
            // `?week_start=YYYY-MM-DD` lets the selector jump around.
            $weekStart = $request->filled('week_start')
                ? \Illuminate\Support\Carbon::parse($request->input('week_start'))->startOfDay()
                : now()->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();
            $prevStart = $weekStart->copy()->subWeek();
            $prevEnd = $weekEnd->copy()->subWeek();

            // ── Section 1 — Week at a glance ───────────────────────────
            $newLeads = Lead::whereBetween('created_at', [$weekStart, $weekEnd])->count();
            $newLeadsPrev = Lead::whereBetween('created_at', [$prevStart, $prevEnd])->count();

            $convertedStages = ['Consultancy Agreement', 'English Pro', 'School Enrollment', 'Visa Process'];
            $converted = Lead::whereIn('status', $convertedStages)
                ->whereBetween('updated_at', [$weekStart, $weekEnd])->count();
            $convertedPrev = Lead::whereIn('status', $convertedStages)
                ->whereBetween('updated_at', [$prevStart, $prevEnd])->count();

            $conversionRate = $newLeads > 0 ? round(($converted / $newLeads) * 100, 1) : 0;
            $conversionRatePrev = $newLeadsPrev > 0 ? round(($convertedPrev / $newLeadsPrev) * 100, 1) : 0;

            $lostStages = ['Not Qualified', 'Did Not Book Consultation', 'No Show'];
            $lost = Lead::whereIn('status', $lostStages)
                ->whereBetween('updated_at', [$weekStart, $weekEnd])->count();
            $topLostStage = Lead::whereIn('status', $lostStages)
                ->whereBetween('updated_at', [$weekStart, $weekEnd])
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->orderByDesc('c')
                ->value('status');

            // Cold leads = no update in 14+ days, still in open stages.
            $openStages = collect(Lead::STAGES)
                ->diff(array_merge($convertedStages, $lostStages, ['Work Pathway / Other']))
                ->values()->all();
            $cold14 = Lead::whereIn('status', $openStages)->where('updated_at', '<', now()->subDays(14))->count();
            $cold7to14 = Lead::whereIn('status', $openStages)->whereBetween('updated_at', [now()->subDays(14), now()->subDays(7)])->count();
            $cold30 = Lead::whereIn('status', $openStages)->where('updated_at', '<', now()->subDays(30))->count();

            // ── Section 2 — Pipeline health ─────────────────────────────
            $byStageRaw = Lead::selectRaw('status, COUNT(*) as c')
                ->whereNotNull('status')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();
            $byStage = collect(Lead::STAGES)->map(fn ($s) => [
                'stage' => $s,
                'count' => (int) ($byStageRaw[$s] ?? 0),
                'bucket' => $this->stageBucket($s),
            ])->all();

            // Aging — leads stuck in same stage 7+ / 14+ / 30+ days.
            $aging = [
                'stuck_7' => Lead::whereIn('status', $openStages)->where('updated_at', '<', now()->subDays(7))->count(),
                'stuck_14' => Lead::whereIn('status', $openStages)->where('updated_at', '<', now()->subDays(14))->count(),
                'stuck_30' => Lead::whereIn('status', $openStages)->where('updated_at', '<', now()->subDays(30))->count(),
            ];

            // ── Section 5 — Lead sources ────────────────────────────────
            $bySource = Lead::whereBetween('created_at', [$weekStart, $weekEnd])
                ->selectRaw("COALESCE(source, 'Unknown') as source, COUNT(*) as c")
                ->groupBy('source')->orderByDesc('c')->limit(8)
                ->get()->map(fn ($r) => ['label' => $this->prettifySource((string) $r->source), 'count' => (int) $r->c])->all();

            // ── Section 6 — Bookings & meetings ─────────────────────────
            $bookings = [
                'booked_this_week' => Booking::whereBetween('created_at', [$weekStart, $weekEnd])->count(),
                'completed_this_week' => Booking::where('status', 'Completed')->whereBetween('updated_at', [$weekStart, $weekEnd])->count(),
                'no_shows_this_week' => Booking::whereIn('status', ['No Show', 'Cancelled'])->whereBetween('updated_at', [$weekStart, $weekEnd])->count(),
                'cancelled_this_week' => Booking::where('status', 'Cancelled')->whereBetween('updated_at', [$weekStart, $weekEnd])->count(),
                'scheduled_next_week' => Booking::whereBetween('appointment_date', [$weekStart->copy()->addWeek(), $weekEnd->copy()->addWeek()])->count(),
            ];

            // ── Section 7 — Lost analysis ───────────────────────────────
            $lostBreakdown = Lead::whereIn('status', $lostStages)
                ->whereBetween('updated_at', [$weekStart, $weekEnd])
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')->orderByDesc('c')
                ->get()->map(fn ($r) => ['label' => $r->status, 'count' => (int) $r->c])->all();
            $lostBySource = Lead::whereIn('status', $lostStages)
                ->whereBetween('updated_at', [$weekStart, $weekEnd])
                ->selectRaw("COALESCE(source, 'Unknown') as source, COUNT(*) as c")
                ->groupBy('source')->orderByDesc('c')->limit(6)
                ->get()->map(fn ($r) => ['label' => $this->prettifySource((string) $r->source), 'count' => (int) $r->c])->all();

            // ── Section 9 — 8-week trend ───────────────────────────────
            $trend = [];
            for ($i = 7; $i >= 0; $i--) {
                $wStart = now()->copy()->startOfWeek()->subWeeks($i);
                $wEnd = $wStart->copy()->endOfWeek();
                $trend[] = [
                    'label' => $wStart->format('d M'),
                    'new_leads' => Lead::whereBetween('created_at', [$wStart, $wEnd])->count(),
                    'conversions' => Lead::whereIn('status', $convertedStages)->whereBetween('updated_at', [$wStart, $wEnd])->count(),
                    'active' => Lead::whereIn('status', $openStages)->whereBetween('created_at', [$wStart, $wEnd])->count(),
                ];
            }

            return inertia('portal/sales/Reports', [
                'portal' => 'sales',
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),

                'glance' => [
                    'new_leads' => ['value' => $newLeads,          'prev' => $newLeadsPrev],
                    'converted' => ['value' => $converted,         'prev' => $convertedPrev],
                    'conversion_rate' => ['value' => $conversionRate,    'prev' => $conversionRatePrev],
                    'lost' => ['value' => $lost,              'top_reason' => $topLostStage],
                    'avg_response_time_hrs' => null, // needs first-touch tracking
                    'cold_actioned' => null, // needs cold-action audit
                ],
                'pipeline' => [
                    'by_stage' => $byStage,
                    'stage_dropoff' => null, // needs transition audit (we have it, can add later)
                    'aging' => $aging,
                ],
                'cold_buckets' => [
                    '7_to_14' => $cold7to14,
                    '15_to_30' => max(0, $cold14 - $cold30),
                    '30_plus' => $cold30,
                ],
                'per_agent' => null, // needs assignee_id on Lead
                'by_source' => $bySource,
                'bookings' => $bookings,
                'lost_analysis' => [
                    'total' => $lost,
                    'breakdown' => $lostBreakdown,
                    'by_source' => $lostBySource,
                ],
                'ai_activity' => null, // needs AI activity tracking
                'trend' => $trend,
                'notable' => [
                    'wins' => $this->reportWins($weekStart, $weekEnd, $convertedStages),
                    'concerns' => $this->reportConcerns($openStages),
                ],
                'generated_at' => now()->toIso8601String(),
                'generated_by' => optional(auth()->user())->name,
            ]);
        } catch (\Throwable $e) {
            Log::error('Sales report failed', ['error' => $e->getMessage()]);

            return inertia('portal/sales/Reports', ['portal' => 'sales', 'error' => 'Could not build the report.']);
        }
    }

    /** Group a stage into one of 4 visual buckets for the pipeline chart. */
    private function stageBucket(string $stage): string
    {
        if (in_array($stage, ['New Leads', 'Contact Attempted', 'Contacted for Booking', 'Booking Confirmation', 'Missed the Meeting'], true)) {
            return 'Early';
        }
        if (in_array($stage, ['Qualified but Not Ready', 'Qualified but No Funds', 'Qualified', 'Booked Consultation', 'Did Not Book Consultation', 'No Show', 'Consultation Done'], true)) {
            return 'Nurture';
        }
        if (in_array($stage, ['Proposal Sent', 'Program Selected', 'Consultancy Agreement Sent', 'Consultancy Agreement Signed', 'Consultancy Agreement', 'English Pro', 'School Enrollment', 'Visa Process'], true)) {
            return 'Qualified';
        }

        return 'Closed';
    }

    private function reportWins(\Carbon\Carbon $weekStart, \Carbon\Carbon $weekEnd, array $convertedStages): array
    {
        return Lead::whereIn('status', $convertedStages)
            ->whereBetween('updated_at', [$weekStart, $weekEnd])
            ->orderByDesc('updated_at')->limit(5)
            ->get()->map(fn ($l) => [
                'name' => trim("{$l->first_name} {$l->last_name}") ?: $l->lead_id,
                'detail' => "Moved to {$l->status}",
                'when' => optional($l->updated_at)->toIso8601String(),
                'href' => "/admin/leads/{$l->id}",
            ])->all();
    }

    private function reportConcerns(array $openStages): array
    {
        return Lead::whereIn('status', $openStages)
            ->where('updated_at', '<', now()->subDays(30))
            ->orderBy('updated_at')->limit(5)
            ->get()->map(fn ($l) => [
                'name' => trim("{$l->first_name} {$l->last_name}") ?: $l->lead_id,
                'detail' => "Stuck in {$l->status} for ".(int) optional($l->updated_at)->diffInDays(now()).' days',
                'when' => optional($l->updated_at)->toIso8601String(),
                'href' => "/admin/leads/{$l->id}",
            ])->all();
    }

    public function campaigns()
    {
        return inertia('portal/sales/Campaigns', ['portal' => 'sales']);
    }

    public function profile()
    {
        return inertia('portal/sales/Profile', ['portal' => 'sales', 'user' => auth()->user()->only(['id', 'name', 'email', 'role'])]);
    }

    /**
     * Sales assessments queue — same Free Assessment + Education Enrolment
     * submissions Education sees, but rendered under SalesLayout via a thin
     * re-export at pages/portal/sales/Assessments.jsx.
     */
    public function assessments()
    {
        return inertia('portal/sales/Assessments', [
            'portal' => 'sales',
            'eligibility' => $this->assessmentRows('free-assessment'),
            'enrolment' => $this->assessmentRows('education-enrolment'),
        ]);
    }

    private function assessmentRows(string $source)
    {
        return \App\Models\Lead::query()
            ->where('source', $source)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function (\App\Models\Lead $l) {
                $analysis = is_array($l->ai_analysis) ? $l->ai_analysis : [];
                $sp = $analysis['study_plans'] ?? [];

                return [
                    'id' => $l->id,
                    'lead_id' => $l->lead_id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                    'email' => $l->email,
                    'phone' => $l->phone,
                    'country' => $l->country,
                    'status' => $l->status,
                    'stage' => $l->stage,
                    'created_at' => $l->created_at,
                    'updated_at' => $l->updated_at,
                    'programme' => $sp['preferred_course'] ?? null,
                    'level' => $sp['qualification_level'] ?? null,
                    'intake' => $sp['preferred_intake'] ?? null,
                    'analysis_done' => $l->ai_analysis_status === 'completed',
                    'detail_url' => "/portal/sales/leads/{$l->id}",
                ];
            });
    }

    /** Consultation bookings with inline scheduling + status updates. */
    public function bookings()
    {
        try {
            return inertia('portal/sales/Bookings', [
                'portal' => 'sales',
                'statuses' => self::BOOKING_STATUSES,
                'bookings' => Booking::with('lead')->latest()->get()->map(fn ($b) => $this->bookingRow($b)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Sales bookings list failed', ['error' => $e->getMessage()]);

            return inertia('portal/sales/Bookings', ['portal' => 'sales', 'statuses' => self::BOOKING_STATUSES, 'bookings' => collect()]);
        }
    }

    public function updateBooking(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::BOOKING_STATUSES)],
            'appointment_date' => 'nullable|date',
            'appointment_time' => 'nullable|string|max:50',
            'consultant_name' => 'nullable|string|max:120',
        ]);

        try {
            $booking = Booking::findOrFail($id);
            $booking->update($validated);

            // Audited automatically by the LogsActivity trait on the Booking model.

            return back()->with('success', "Booking #{$booking->id} updated.");
        } catch (\Throwable $e) {
            Log::error('Booking update failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not update that booking. Please try again.');
        }
    }

    private function bookingRow(Booking $b): array
    {
        return [
            'id' => $b->id,
            'name' => trim("{$b->first_name} {$b->last_name}") ?: 'Unknown',
            'email' => $b->email,
            'phone' => $b->phone,
            'service_type' => $b->service_type,
            'consultant_name' => $b->consultant_name,
            'platform' => $b->platform,
            'current_country' => $b->current_country,
            'status' => $b->status ?: 'Pending',
            'appointment_date' => $b->appointment_date ? \Illuminate\Support\Carbon::parse($b->appointment_date)->toDateString() : null,
            'appointment_time' => $b->appointment_time,
            'message' => $b->message,
            'lead_ref' => optional($b->lead)->lead_id,
            'created_at' => $b->created_at,
        ];
    }
}
