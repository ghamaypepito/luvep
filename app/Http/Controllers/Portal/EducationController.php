<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Program;
use App\Traits\BuildsLeadRow;
use App\Traits\CreatesDashboardLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EducationController extends Controller
{
    use BuildsLeadRow;
    use CreatesDashboardLead;

    private const LEAD_STATUSES = Lead::STAGES;

    /**
     * Sales stages hidden from the education Leads picker and the report — these
     * are department-conversion statuses (English / School / Visa), not stages a
     * lead is manually moved through. They stay in Lead::STAGES so existing leads
     * that already hold them still validate; they are just not offered or charted.
     */
    private const HIDDEN_LEAD_STAGES = ['English Pro', 'School Enrollment', 'Visa Process'];

    /** The selectable lead stages — Lead::STAGES minus the hidden conversion ones. */
    private function leadStageOptions(): array
    {
        return array_values(array_filter(Lead::STAGES, fn ($s) => ! in_array($s, self::HIDDEN_LEAD_STAGES, true)));
    }

    /** Education overview: programs, students (study-plan leads), recent intakes. */
    public function dashboard()
    {
        try {
            $now = now();
            $monthStart = $now->copy()->startOfMonth();

            $programStats = [
                'total' => Program::count(),
                'published' => Program::where('status', 'published')->count(),
                'draft' => Program::where('status', 'draft')->count(),
                'archived' => Program::where('status', 'archived')->count(),
            ];

            $studentStats = [
                'total_with_plan' => Lead::has('studyPlans')->count(),
                'this_month' => Lead::has('studyPlans')->where('created_at', '>=', $monthStart)->count(),
                'qualified' => Lead::has('studyPlans')->whereIn('status', ['Qualified', 'Processing'])->count(),
                'enrolled' => Lead::has('studyPlans')->where('status', 'Closed')->count(),
            ];

            $recentStudents = Lead::with('studyPlans')->has('studyPlans')
                ->latest()->limit(8)->get()->map(function ($l) {
                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'email' => $l->email,
                        'course' => optional($l->studyPlans->first())->preferred_course,
                        'level' => optional($l->studyPlans->first())->preferred_level,
                        'status' => $l->status ?: 'New',
                        'created_at' => $l->created_at,
                    ];
                });

            $recentPrograms = Program::latest()->limit(6)->get(['id', 'title', 'slug', 'status', 'updated_at']);

            return inertia('portal/education/Dashboard', [
                'programStats' => $programStats,
                'studentStats' => $studentStats,
                'recentStudents' => $recentStudents,
                'recentPrograms' => $recentPrograms,
            ]);
        } catch (\Throwable $e) {
            Log::error('Education dashboard failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/Dashboard', [
                'programStats' => array_fill_keys(['total', 'published', 'draft', 'archived'], 0),
                'studentStats' => array_fill_keys(['total_with_plan', 'this_month', 'qualified', 'enrolled'], 0),
                'recentStudents' => collect(),
                'recentPrograms' => collect(),
            ]);
        }
    }

    /**
     * Leads queue for the Education portal — same shape as Sales so the
     * Leads.jsx page renders identically. No server-side filtering yet;
     * the page's status filter + search lets users narrow to their own
     * department-relevant rows.
     */
    public function leads()
    {
        try {
            // Pipeline only — once a lead is converted (to a student / case /
            // English / accommodation) it drops off the Leads list and lives
            // on its department page instead.
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
                ])
                ->withCount(['notes', 'documents'])
                ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
                ->latest()->get();

            return inertia('portal/education/Leads', [
                'portal' => 'education',
                'statuses' => $this->leadStageOptions(),
                'programs' => Program::orderBy('title')->pluck('title')->filter()->values(),
                'staffOptions' => $this->dashboardStaff(),
                'leads' => $leads->map(fn ($l) => $this->leadRow($l)),
                // Full tag dictionary — the Leads-page Tag filter lists every
                // tag ever created, not just the ones on visible leads.
                'allTagNames' => \App\Models\LeadTag::orderBy('name')->pluck('name'),
                'events' => $this->eventsSummary(),
                'tabCounts' => $this->leadTabCounts(),
                'agents' => $this->agentsSummary(),
                'visaOptions' => $this->visaOptions(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Education leads list failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/Leads', [
                'portal' => 'education',
                'statuses' => $this->leadStageOptions(),
                'programs' => Program::orderBy('title')->pluck('title')->filter()->values(),
                'staffOptions' => $this->dashboardStaff(),
                'leads' => collect(),
            ]);
        }
    }

    /**
     * Recruiting agents (role='agent') with a total count of leads each has
     * added — for the Leads page "Agents" tab. Mirrors the sales/admin feed
     * so the shared Leads component renders the same roster + "View leads".
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

    /** GET /portal/education/events/{id}/registrations — registrants drawer. */
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

    /** GET /portal/education/events/{id}/registrants — full-page registrants view. */
    public function eventRegistrantsPage($id)
    {
        $event = Event::findOrFail($id);

        return inertia('portal/education/EventRegistrants', array_merge(
            $this->eventRegistrantsPayload($event),
            ['portalBase' => '/portal/education']
        ));
    }

    /** Manually add a lead from the dashboard "Add Lead" form. */
    public function storeLead(Request $request)
    {
        $validated = $request->validate(
            $this->dashboardLeadRules(self::LEAD_STATUSES) + $this->dashboardDocumentRules()
        );

        try {
            $lead = $this->createDashboardLead($validated);
            // Persist the modal's CV / passport / diploma / transcript uploads.
            $this->storeDashboardLeadDocuments($lead, $request);

            return back()->with('success', "Lead {$lead->lead_id} added.");
        } catch (\Throwable $e) {
            Log::error('Education add-lead failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not add that lead. Please try again.');
        }
    }

    public function updateLead(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::LEAD_STATUSES)],
        ]);

        try {
            $lead = Lead::findOrFail($id);
            $previousStatus = $lead->status;
            $lead->status = $validated['status'];
            $lead->save();

            // Fire the stage's automatic client email on a real transition
            // (self-filters to the config/stage_emails.php map + debounces).
            \App\Jobs\SendStageTransitionEmail::maybeDispatch($lead, $previousStatus, $lead->status);

            return back()->with('success', "Lead {$lead->lead_id} updated.");
        } catch (\Throwable $e) {
            Log::error('Education lead update failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not update that lead. Please try again.');
        }
    }

    /**
     * Students — engaged leads with a study plan or in any post-engagement
     * stage. The Leads page covers prospecting; this is the "in-flight" view.
     */
    /**
     * @param  callable|null  $scope  Optional extra row filter applied to the
     *                                student query. The recruiting portals (agent / sub-agent) pass one to
     *                                narrow the list to their own referrals — `portal:` is role-level only
     *                                and would otherwise show them every student in the business.
     */
    public function students(?callable $scope = null)
    {
        try {
            // Students are leads explicitly flipped via "Convert to Student"
            // on the lead detail page. The flag is the source of truth — no
            // more guessing from status or study plans.
            //
            // Returned payload mirrors the Education team's "Students
            // Dashboard" spreadsheet (Date Engaged, Status, Location, Payment,
            // Intake, Program, School, COOP, PTE/IELTS, OOP, Contact, Email,
            // GDrive, Comments) so the expanded student row on the portal
            // matches the columns staff are already tracking offline.
            // Widened to include not just Education students but also leads
            // who have moved on to English (stage = "English Pro") or have
            // been converted to an Immigration case. The frontend segments
            // these into three department tabs (Education / English /
            // Immigration) — once a lead moves on, they drop out of the
            // Education tab automatically.
            // Names of the (fee-paying) Student-category visas. An immigration
            // case only belongs in the Students module when it holds one of
            // these — that client is both a student and a case. Non-student
            // cases (Work, Visitor, Residence, …) stay out of the Students
            // list even though they are immigration cases.
            $students = Lead::with([
                'studyPlans',
                'documents',
                'faceImage',
                'school',
                'studentConverter:id,name',
                'immigrationConverter:id,name',
                'stageUpdater:id,name', 'lastActivityUser:id,name',
                'agent:id,name',
            ])
                // Education students + English learners + student-visa
                // immigration cases — the report reuses this exact scope so the
                // two surfaces always count the same rows.
                ->inStudentsRegister()
                ->when($scope, $scope)
                ->orderByDesc('student_converted_at')
                ->limit(200)
                ->get()
                ->map(function ($l) {
                    $plan = $l->studyPlans->first();
                    // Checklist progress (submitted vs visible items) — same
                    // metric the Immigration Cases + Leads Docs column uses.
                    $checklist = $this->leadChecklistTotals($l);

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        // General lead priority (urgent | medium | low) —
                        // shown + editable in the collapsed row.
                        'priority' => $l->priority,
                        // Customer-shareable tracking code — drives the
                        // "Copy tracking link" row action so staff can
                        // paste a /track/{code} URL straight to the student.
                        'tracking_code' => $l->tracking_code,
                        // Most recent stage-mover (falls back to whoever
                        // converted them in if the row predates the
                        // stage-update tracking). Drives the
                        // "Updated [date] · Endorsed by [Name]" subtitle
                        // under the stage chip.
                        'endorsed_by' => optional($l->stageUpdater)->name
                                            ?? optional($l->studentConverter)->name
                                            ?? optional($l->immigrationConverter)->name,
                        'stage_updated_at' => optional($l->stage_updated_at)?->toIso8601String(),
                        // Updated column — last *staff* activity of any kind
                        // (stamped by Lead::stampLastActivity), with a
                        // fallback for rows that predate the stamp.
                        'updated_at' => optional($l->last_activity_at ?: $l->updated_at)?->toIso8601String(),
                        'updated_by' => optional($l->lastActivityUser)->name
                                            ?? optional($l->stageUpdater)->name,
                        'updated_desc' => $l->last_activity_desc,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'avatar_url' => $l->faceImageUrl(),
                        'email' => $l->email,
                        'phone' => $l->phone,
                        'referral' => $l->referral,
                        // Recruiting agent (role='agent') credited with this
                        // student — drives the "Referring agent" dropdown in
                        // the add/edit modal (replaces the old free-text
                        // "Referral" field).
                        'agent_id' => $l->agent_id,
                        'agent_name' => optional($l->agent)->name,
                        // `education_stage` is the Education-team lifecycle
                        // shown as a dropdown in the Status column; falls
                        // back to the lead's generic status only as a hint.
                        'status' => $l->education_stage ?: $l->status,
                        // Lead Portal access state — drives the "Request portal
                        // access" row action so it reflects requested/sent/
                        // accepted instead of always reading as a fresh request.
                        // This list is shared into the Sales + Immigration
                        // Students tabs too, so one field fixes all three.
                        'portal_invitation_status' => $l->portal_invitation_status ?: 'none',
                        'education_stage' => $l->education_stage,
                        'english_stage' => $l->english_stage,
                        'immigration_stage' => $l->immigration_stage,
                        // Named people handling the English / Immigration stage.
                        'english_assignee' => $l->english_assignee,
                        'immigration_assignee' => $l->immigration_assignee,
                        // Full dated status timeline for the Pipeline view.
                        'stage_history' => $l->stage_history ?? [],
                        // Department-routing flags read by the tab strip.
                        // Drives precedence on the frontend: Immigration >
                        // English > Education.
                        'is_student' => (bool) $l->is_student,
                        'is_immigration_case' => (bool) $l->is_immigration_case,
                        'stage' => $l->stage,
                        'location' => $l->residence_country,
                        'date_engaged' => optional($l->date_of_engagement)->toDateString()
                            ?? optional($l->student_converted_at)->toDateString(),
                        'program' => optional($plan)->preferred_course,
                        // Per-program schools, index-aligned with `program`.
                        'program_schools' => optional($plan)->program_schools,
                        'level' => optional($plan)->qualification_level,
                        'intake' => optional($plan)->preferred_intake,
                        'english_test' => optional($plan)->english_test_type,
                        'english_test_taken' => (bool) optional($plan)->english_test_taken,
                        'english_test_score' => optional($plan)->score_overall,
                        // Spreadsheet-mirror fields stored directly on leads.
                        'middle_name' => $l->middle_name,
                        'suffix' => $l->suffix,
                        'gender' => $l->gender,
                        'payment' => $l->student_payment,
                        'school' => $l->student_school,
                        'school_id' => $l->school_id,
                        'school_name' => optional($l->school)->name,
                        'coop' => $l->student_coop,
                        'oop' => $l->student_oop,
                        'gdrive_link' => $l->student_gdrive_link,
                        'comments' => $l->student_comments,
                        'docs_total' => $l->documents->count(),
                        'docs_approved' => $l->documents->where('status', 'Approved')->count(),
                        // Checklist-based progress for the Docs column bar.
                        'checklist_total' => $checklist['total'],
                        'checklist_submitted' => $checklist['submitted'],
                    ];
                });

            $schoolOptions = \App\Models\School::where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'country', 'city']);
            // school_id + institution let the add/edit modal auto-fill the
            // School field when a program is picked.
            $programOptions = \App\Models\Program::orderBy('title')
                ->get(['id', 'title', 'level', 'school_id', 'institution']);
            // Recruiting agents for the "Referring agent" dropdown in the
            // add/edit student modal.
            $agentOptions = \App\Models\User::where('role', 'agent')
                ->orderBy('name')
                ->get(['id', 'name']);

            [$component, $portal] = $this->studentsComponent();

            return inertia($component, [
                'portal' => $portal,
                // Recruiting agents and sub-agents view their own referrals'
                // progress; every write on this screen belongs to education /
                // sales, and none of those endpoints exist under their prefix.
                'readOnly' => in_array($portal, ['agent', 'sub-agent'], true),
                'students' => $students,
                'schoolOptions' => $schoolOptions,
                'programOptions' => $programOptions,
                'agentOptions' => $agentOptions,
            ]);
        } catch (\Throwable $e) {
            Log::error('Education students list failed', ['error' => $e->getMessage()]);

            [$component, $portal] = $this->studentsComponent();

            return inertia($component, [
                'portal' => $portal,
                'readOnly' => in_array($portal, ['agent', 'sub-agent'], true),
                'students' => collect(),
            ]);
        }
    }

    /**
     * The Students screen is shared by three portals — Education owns it,
     * Sales and Immigration read the same list. Each portal resolves its own
     * page component (a re-export of Education's) so app.jsx wraps it in
     * that portal's layout instead of always using EducationLayout.
     *
     * @return array{0: string, 1: string} [component, portal]
     */
    private function studentsComponent(): array
    {
        return match (true) {
            request()->is('portal/sales/*') => ['portal/sales/Students', 'sales'],
            request()->is('portal/immigration-adviser/*') => ['portal/immigration-adviser/Students', 'immigration-adviser'],
            request()->is('portal/immigration/*') => ['portal/immigration/Students', 'immigration'],
            // Recruiting portals — same screen, read-only (see `readOnly` below).
            request()->is('portal/sub-agent/*') => ['portal/sub-agent/Students', 'sub-agent'],
            request()->is('portal/agent/*') => ['portal/agent/Students', 'agent'],
            default => ['portal/education/Students', 'education'],
        };
    }

    /**
     * Intake monitoring — every student grouped by their study-plan intake
     * month, so staff can see how many people start each intake and who is
     * still unscheduled. Same student universe as the Students page.
     */
    public function intakeMonitoring()
    {
        try {
            $students = Lead::with(['studyPlans', 'school', 'faceImage'])
                ->inStudentsRegister()
                ->limit(1000)
                ->get()
                ->map(function (Lead $l) {
                    $plan = $l->studyPlans->first();
                    $intakeRaw = optional($plan)->preferred_intake;
                    [$monthKey, $monthLabel, $intakeDisplay] = $this->intakeBucket($intakeRaw);

                    // Department track (mirrors the Students tabs) and the
                    // effective stage used for the card chip + grouping.
                    $track = $l->is_immigration_case
                        ? 'immigration'
                        : (($l->is_english_student || $l->english_stage) ? 'english' : 'education');
                    $effStage = $l->education_stage ?: ($l->immigration_stage ?: ($l->english_stage ?: $l->status));

                    return [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                        'avatar_url' => $l->faceImageUrl(),
                        'status' => $effStage ?: null,
                        'location' => $l->residence_country,
                        'intake' => $intakeDisplay,
                        'school' => optional($l->school)->name ?: $l->student_school,
                        'program' => optional($plan)->preferred_course,
                        'month_key' => $monthKey,
                        'month_label' => $monthLabel,
                        'track' => $track,
                        // Card-grouping bucket for the "Group by Stage" view.
                        'category' => $this->intakeCategory($track, $effStage),
                    ];
                })
                // Chronological, with unscheduled (key '9999-99') always last.
                ->sortBy([['month_key', 'asc'], ['name', 'asc']])
                ->values();

            return inertia('portal/education/IntakeMonitoring', [
                'portal' => 'education',
                'students' => $students,
            ]);
        } catch (\Throwable $e) {
            Log::error('Intake monitoring failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/IntakeMonitoring', [
                'portal' => 'education',
                'students' => collect(),
            ]);
        }
    }

    /**
     * Stage → card-grouping bucket for the Intake Monitoring board's
     * "Group by Stage" view: needs_attention | immigration | offers |
     * english | started | lead_stages.
     */
    private function intakeCategory(string $track, ?string $stage): string
    {
        $s = (string) $stage;
        if ($s === 'Started Course') {
            return 'started';
        }
        if (in_array($s, ['For Relodgement', 'Declined Visa', 'Decline Visa', 'Request for Information', 'Request to Lodge', 'Missed the Meeting', 'Not Qualified'], true)) {
            return 'needs_attention';
        }
        if ($track === 'immigration' || in_array($s, ['Endorsed to Immigration', 'Visa Lodged', 'Approved in Principle', 'Approved Visa', 'Endorsed', 'For Assessment', 'Interim Visa Issued', 'RFI Responded'], true)) {
            return 'immigration';
        }
        if (in_array($s, ['Conditional Offer', 'Unconditional Offer', 'School Enrolment', 'School Enrollment', 'Endorsed to School'], true)) {
            return 'offers';
        }
        if ($track === 'english' || in_array($s, ['English Pro', 'PTE Review', 'DIY Review', 'For PTE Mocktest', 'For PTE Exam'], true)) {
            return 'english';
        }

        return 'lead_stages';
    }

    /**
     * Resolve a free-text / dated intake value to a sortable month bucket.
     * Returns [month_key, month_label, display]. Anything unparseable falls
     * into the "Unscheduled" bucket (key sorts last).
     *
     * @return array{0:string,1:string,2:?string}
     */
    private function intakeBucket(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['9999-99', 'Unscheduled', null];
        }

        try {
            $d = \Illuminate\Support\Carbon::parse($raw);

            return [$d->format('Y-m'), $d->format('F Y'), $raw];
        } catch (\Throwable) {
            // Not a full date (e.g. just "November") — keep it visible but
            // group it as unscheduled since we can't place it on a timeline.
            return ['9999-99', 'Unscheduled', $raw];
        }
    }

    /**
     * Inline-update one of the Students-Dashboard mirror columns from the
     * Students screen's expanded row. The frontend posts a single field at
     * a time; the validator whitelists exactly the dashboard fields so
     * nothing else on the lead can be touched through this endpoint.
     */
    public function updateStudentField(\Illuminate\Http\Request $request, int $id)
    {
        // Match the Students page universe, not just is_student — English
        // learners and student-visa Immigration cases show on the list too and
        // must be editable (they aren't flagged is_student).
        $lead = Lead::inStudentsRegister()->findOrFail($id);

        $data = $request->validate([
            'payment' => 'nullable|string|max:191',
            'school' => 'nullable|string|max:191',
            'coop' => 'nullable|string|max:191',
            'oop' => 'nullable|string|max:191',
            'gdrive_link' => 'nullable|url|max:512',
            'comments' => 'nullable|string|max:5000',
            // Department lifecycle stages — each whitelisted to its own
            // canonical list on the Lead model so the columns can't drift
            // to free-form strings.
            'education_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::EDUCATION_STAGES)],
            'english_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::ENGLISH_STAGES)],
            'immigration_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGES)],
            'english_assignee' => ['nullable', \Illuminate\Validation\Rule::in(Lead::ENGLISH_STAGE_ASSIGNEES)],
            'immigration_assignee' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGE_ASSIGNEES)],
        ]);

        $map = [
            'payment' => 'student_payment',
            'school' => 'student_school',
            'coop' => 'student_coop',
            'oop' => 'student_oop',
            'gdrive_link' => 'student_gdrive_link',
            'comments' => 'student_comments',
            'education_stage' => 'education_stage',
            'english_stage' => 'english_stage',
            'immigration_stage' => 'immigration_stage',
            'english_assignee' => 'english_assignee',
            'immigration_assignee' => 'immigration_assignee',
        ];
        // Detect which stage fields actually moved. Only stage-field changes
        // refresh `stage_updated_at` and append a dated timeline entry —
        // touching the spreadsheet columns (payment / coop / oop / etc.)
        // doesn't.
        $stageFields = [
            'education_stage' => 'education',
            'english_stage' => 'english',
            'immigration_stage' => 'immigration',
        ];
        $changedStages = [];
        foreach ($stageFields as $f => $dept) {
            if (array_key_exists($f, $data) && ($lead->{$f} ?? null) !== ($data[$f] ?? null)) {
                $changedStages[$f] = $dept;
            }
        }

        foreach ($data as $k => $v) {
            $lead->{$map[$k]} = $v;
        }

        if (! empty($changedStages)) {
            $lead->stage_updated_at = now();
            $lead->stage_updated_by = auth()->id();
            foreach ($changedStages as $f => $dept) {
                $assignee = $dept === 'english'
                    ? $lead->english_assignee
                    : ($dept === 'immigration' ? $lead->immigration_assignee : null);
                $lead->pushStageHistory($dept, $lead->{$f}, $assignee);
            }
        }

        // Auto-promote the lead to an Immigration Case the first time the
        // Education team moves them onto one of the handoff stages
        // (Endorsed to Immigration → Approved Visa). Without this, the
        // student appears in /portal/immigration/cases via the education
        // scope but the lead detail still reads as "Study only" — the
        // Move To widget then doesn't show Immigration as ACTIVE, which is
        // what surprised staff. Conversion is one-way: we don't roll it
        // back when the stage moves to a non-immigration value, since
        // immigration may still be working the case in parallel.
        $movedToImmigrationStage = array_key_exists('education_stage', $data)
            && in_array($data['education_stage'], Lead::EDUCATION_STAGES_IMMIGRATION, true);

        if ($movedToImmigrationStage && ! $lead->is_immigration_case) {
            $lead->is_immigration_case = true;
            $lead->immigration_converted_at = now();
            $lead->immigration_converted_by = auth()->id();
        }

        if ($movedToImmigrationStage && ! filled($lead->inz_visa_type)) {
            $lead->inz_visa_type = 'Student Visa';
        }

        // A student handed to Immigration STAYS a student — the same person is
        // legitimately under both departments, so is_student is preserved. They
        // remain visible on the Students screen's Education tab (they are still a
        // student) while also appearing under Immigration. (Previously is_student
        // was cleared here, which dropped them off Education entirely.)

        $lead->save();

        return back();
    }

    /**
     * Create a brand-new student from the Education Students page's
     * "Add new student" modal. Spawns a Lead row pre-flagged with
     * is_student=true so it lands in the Students list immediately,
     * plus a LeadStudyPlan capturing program / intake / English test
     * info if any of those fields were provided.
     */
    public function storeStudent(\Illuminate\Http\Request $request)
    {
        $data = $this->validateStudentPayload($request);

        try {
            $lead = Lead::create([
                'lead_id' => Lead::generateLeadId(),
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'gender' => $data['gender'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'referral' => $data['referral'] ?? null,
                'agent_id' => $data['agent_id'] ?? null,
                'residence_country' => $data['location'] ?? null,
                // First canonical stage if the staff member didn't pick
                // one — the row shows up under "Endorsed to School" with
                // an "Endorsed by [Name]" subtitle in the table, instead
                // of looking unstaged from day one.
                'education_stage' => $data['education_stage'] ?? Lead::EDUCATION_STAGE_DEFAULT,
                'english_stage' => $data['english_stage'] ?? null,
                'immigration_stage' => $data['immigration_stage'] ?? null,
                'english_assignee' => $data['english_assignee'] ?? null,
                'immigration_assignee' => $data['immigration_assignee'] ?? null,
                'student_payment' => $data['payment'] ?? null,
                'student_coop' => $data['coop'] ?? null,
                'student_oop' => $data['oop'] ?? null,
                'student_comments' => $data['internal_note'] ?? null,
                'school_id' => $data['school_id'] ?? null,
                // Full (possibly multi) school list mirroring the programs.
                'student_school' => $data['school_text'] ?? null,
                'is_student' => true,
                'student_converted_at' => now(),
                'student_converted_by' => auth()->id(),
                // Initial stage stamp — the table's "Updated [date] ·
                // Endorsed by [Name]" subtitle uses these columns rather
                // than the generic updated_at.
                'stage_updated_at' => now(),
                'stage_updated_by' => auth()->id(),
                // Staff can backdate the engagement; default to today.
                'date_of_engagement' => $data['date_of_engagement'] ?? now()->toDateString(),
            ]);

            // Seed the status timeline with whichever stage the student
            // started on so the Pipeline shows a dated first entry.
            if ($lead->education_stage) {
                $lead->pushStageHistory('education', $lead->education_stage);
            }
            if ($lead->english_stage) {
                $lead->pushStageHistory('english', $lead->english_stage, $lead->english_assignee);
            }
            if ($lead->immigration_stage) {
                $lead->pushStageHistory('immigration', $lead->immigration_stage, $lead->immigration_assignee);
            }
            $lead->save();

            if (! empty($data['program_text']) || ! empty($data['intake']) || ! empty($data['english_test'])) {
                // The Program field is a multi-select combobox — one or more
                // titles joined by " · ", or free-form text. Resolve the FIRST
                // title to a Program so qualification_level can be auto-filled.
                $firstTitle = ! empty($data['program_text'])
                    ? trim(explode(' · ', $data['program_text'])[0])
                    : null;
                $program = $firstTitle
                    ? \App\Models\Program::where('title', $firstTitle)->first()
                    : null;
                $programTitle = $data['program_text'] ?? null;
                $programLevel = $program?->level ?? '';

                \App\Models\LeadStudyPlan::create([
                    'lead_id' => $lead->id,
                    'preferred_course' => $programTitle,
                    'program_schools' => $data['program_schools'] ?? null,
                    'qualification_level' => $programLevel,
                    'preferred_intake' => $data['intake'] ?? null,
                    'english_test_type' => $data['english_test'] ?? null,
                    'english_test_taken' => false,
                ]);
            }

            return back()->with('success', "Student {$lead->lead_id} added.");
        } catch (\Throwable $e) {
            Log::error('Education store student failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not add the student.');
        }
    }

    /**
     * Typeahead for the "Add from existing person" mode of the New Student
     * modal. Returns leads that are NOT yet students (immigration cases, sales
     * leads, anyone) so staff can flag an existing person as a student instead
     * of creating a duplicate Lead row. Matches name / email / lead_id.
     */
    public function searchExistingLeads(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $leads = Lead::query()
            ->where('is_student', false)
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
            ->get(['id', 'lead_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'gender', 'email', 'phone', 'referral', 'agent_id', 'residence_country', 'is_immigration_case', 'immigration_stage', 'inz_visa_type'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                'first_name' => $l->first_name,
                'middle_name' => $l->middle_name,
                'last_name' => $l->last_name,
                'suffix' => $l->suffix,
                'gender' => $l->gender,
                'email' => $l->email,
                'phone' => $l->phone,
                'referral' => $l->referral,
                'agent_id' => $l->agent_id,
                'location' => $l->residence_country,
                // Context chip so staff recognise where the person came from.
                'is_immigration_case' => (bool) $l->is_immigration_case,
                'immigration_stage' => $l->immigration_stage,
                'inz_visa_type' => $l->inz_visa_type,
            ]);

        return response()->json(['leads' => $leads]);
    }

    /**
     * Flag an EXISTING lead (an immigration case, sales lead, etc.) as a
     * student — the "add from immigration" path. This links, it does NOT copy:
     * the same Lead row gains is_student=true so the person keeps one record
     * across departments (documents, tasks, tracking link stay unified). Any
     * immigration data on the row is preserved. Study details entered in the
     * modal are merged in the same way storeStudent seeds a brand-new student.
     */
    public function linkExistingStudent(Request $request)
    {
        $leadId = $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
        ])['lead_id'];

        $lead = Lead::findOrFail($leadId);

        if ($lead->is_student) {
            return back()->with('error', 'That person is already a student.');
        }

        $data = $this->validateStudentPayload($request, $lead->id);

        try {
            // Profile fields — only overwrite when the modal actually sent a
            // value, so we never wipe existing immigration-side data with blanks.
            $lead->fill(array_filter([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'suffix' => $data['suffix'] ?? null,
                'gender' => $data['gender'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'referral' => $data['referral'] ?? null,
                'agent_id' => $data['agent_id'] ?? null,
                'residence_country' => $data['location'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));

            // Student-side fields + the flag that puts them in the register.
            $lead->fill([
                'student_payment' => $data['payment'] ?? $lead->student_payment,
                'student_coop' => $data['coop'] ?? $lead->student_coop,
                'student_oop' => $data['oop'] ?? $lead->student_oop,
                'student_comments' => $data['internal_note'] ?? $lead->student_comments,
                'school_id' => $data['school_id'] ?? $lead->school_id,
                'student_school' => $data['school_text'] ?? $lead->student_school,
                'is_student' => true,
                'student_converted_at' => now(),
                'student_converted_by' => auth()->id(),
            ]);

            // Seed an Education stage only if they aren't already on one — an
            // immigration case handed to education still needs a study stage to
            // surface under the Education tab, but we won't clobber an existing.
            if (empty($lead->education_stage)) {
                $lead->education_stage = $data['education_stage'] ?? Lead::EDUCATION_STAGE_DEFAULT;
                if ($lead->education_stage) {
                    $lead->pushStageHistory('education', $lead->education_stage);
                }
            } elseif (! empty($data['education_stage']) && $data['education_stage'] !== $lead->education_stage) {
                $lead->education_stage = $data['education_stage'];
                $lead->pushStageHistory('education', $lead->education_stage);
            }

            $lead->stage_updated_at = now();
            $lead->stage_updated_by = auth()->id();
            if (empty($lead->date_of_engagement)) {
                $lead->date_of_engagement = $data['date_of_engagement'] ?? now()->toDateString();
            }
            $lead->save();

            // Study plan — reuse the existing one if the lead already has study
            // details (e.g. from a prior assessment), else create it.
            if (! empty($data['program_text']) || ! empty($data['intake']) || ! empty($data['english_test'])) {
                $firstTitle = ! empty($data['program_text'])
                    ? trim(explode(' · ', $data['program_text'])[0])
                    : null;
                $program = $firstTitle
                    ? \App\Models\Program::where('title', $firstTitle)->first()
                    : null;

                \App\Models\LeadStudyPlan::updateOrCreate(
                    ['lead_id' => $lead->id],
                    array_filter([
                        'preferred_course' => $data['program_text'] ?? null,
                        'program_schools' => $data['program_schools'] ?? null,
                        'qualification_level' => $program?->level ?? null,
                        'preferred_intake' => $data['intake'] ?? null,
                        'english_test_type' => $data['english_test'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '')
                );
            }

            return back()->with('success', "{$lead->lead_id} is now a student.");
        } catch (\Throwable $e) {
            Log::error('Education link existing student failed', ['error' => $e->getMessage(), 'lead' => $leadId]);

            return back()->with('error', 'Could not add that person as a student.');
        }
    }

    /**
     * Update an existing student row from the same modal. Touches the
     * lead's profile fields, school FK, and (if present) the study plan.
     */
    public function updateStudent(\Illuminate\Http\Request $request, int $id)
    {
        // Match the Students page universe, not just is_student — English
        // learners and student-visa Immigration cases show on the list too and
        // must be editable (they aren't flagged is_student).
        $lead = Lead::inStudentsRegister()->findOrFail($id);
        $data = $this->validateStudentPayload($request, $lead->id);

        try {
            $lead->fill([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'gender' => $data['gender'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'referral' => $data['referral'] ?? null,
                'agent_id' => $data['agent_id'] ?? null,
                'residence_country' => $data['location'] ?? null,
                'education_stage' => $data['education_stage'] ?? null,
                'english_stage' => $data['english_stage'] ?? null,
                'immigration_stage' => $data['immigration_stage'] ?? null,
                'english_assignee' => $data['english_assignee'] ?? null,
                'immigration_assignee' => $data['immigration_assignee'] ?? null,
                'student_payment' => $data['payment'] ?? null,
                'student_coop' => $data['coop'] ?? null,
                'student_oop' => $data['oop'] ?? null,
                'student_comments' => $data['internal_note'] ?? null,
                'school_id' => $data['school_id'] ?? null,
                'student_school' => $data['school_text'] ?? null,
            ]);
            if (array_key_exists('date_of_engagement', $data)) {
                $lead->date_of_engagement = $data['date_of_engagement'] ?: null;
            }

            // Record a dated timeline entry for each stage the edit moved,
            // and refresh the "last moved" stamp if any stage changed.
            $stageMap = [
                'education_stage' => ['education',   null],
                'english_stage' => ['english',     $lead->english_assignee],
                'immigration_stage' => ['immigration', $lead->immigration_assignee],
            ];
            $stageMoved = false;
            foreach ($stageMap as $field => [$dept, $assignee]) {
                if ($lead->isDirty($field)) {
                    $stageMoved = true;
                    $lead->pushStageHistory($dept, $lead->{$field}, $assignee);
                }
            }
            if ($stageMoved) {
                $lead->stage_updated_at = now();
                $lead->stage_updated_by = auth()->id();
            }

            $lead->save();

            // Update or create the primary study plan row.
            $plan = $lead->studyPlans()->first() ?: new \App\Models\LeadStudyPlan(['lead_id' => $lead->id]);
            if (array_key_exists('program_text', $data)) {
                $plan->preferred_course = $data['program_text'] ?: null;
                $plan->program_schools = $data['program_schools'] ?? null;
                if (! empty($data['program_text'])) {
                    $firstTitle = trim(explode(' · ', $data['program_text'])[0]);
                    $match = $firstTitle ? \App\Models\Program::where('title', $firstTitle)->first() : null;
                    if ($match && empty($plan->qualification_level)) {
                        $plan->qualification_level = $match->level ?? '';
                    }
                }
                // The legacy column is NOT NULL — give it an empty string
                // on new plans where we couldn't resolve a level.
                if (! $plan->exists && empty($plan->qualification_level)) {
                    $plan->qualification_level = '';
                }
            }
            if (array_key_exists('intake', $data)) {
                $plan->preferred_intake = $data['intake'] ?: null;
            }
            if (array_key_exists('english_test', $data)) {
                $plan->english_test_type = $data['english_test'] ?: null;
            }
            if ($plan->isDirty() || ! $plan->exists) {
                $plan->save();
            }

            return back()->with('success', "Student {$lead->lead_id} updated.");
        } catch (\Throwable $e) {
            Log::error('Education update student failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not update the student.');
        }
    }

    /**
     * Delete a student — a soft delete (archive) on the underlying Lead.
     * The record drops off every list but notes, documents, tasks and
     * history survive and can be restored from the lead detail. Recoverable
     * rather than destructive, per the Delete-button feedback.
     */
    public function destroyStudent(int $id)
    {
        // Match the Students page universe, not just is_student — English
        // learners and student-visa Immigration cases show on the list too and
        // must be editable (they aren't flagged is_student).
        $lead = Lead::inStudentsRegister()->findOrFail($id);
        try {
            $lead->delete();

            return back()->with('success', "Student {$lead->lead_id} archived.");
        } catch (\Throwable $e) {
            Log::error('Education destroy student failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not delete the student.');
        }
    }

    private function validateStudentPayload(\Illuminate\Http\Request $request, ?int $leadId = null): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:120',
            'middle_name' => 'nullable|string|max:120',
            'last_name' => 'required|string|max:120',
            'suffix' => 'nullable|string|max:20',
            'gender' => 'nullable|string|max:32',
            'email' => 'required|email|max:191',
            'phone' => 'required|string|max:60',
            'referral' => 'nullable|string|max:191',
            // Referring recruiting agent — must be a user with role 'agent'.
            'agent_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'agent')],
            'location' => 'nullable|string|max:120',
            'education_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::EDUCATION_STAGES)],
            'english_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::ENGLISH_STAGES)],
            'immigration_stage' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGES)],
            'english_assignee' => ['nullable', \Illuminate\Validation\Rule::in(Lead::ENGLISH_STAGE_ASSIGNEES)],
            'immigration_assignee' => ['nullable', \Illuminate\Validation\Rule::in(Lead::IMMIGRATION_STAGE_ASSIGNEES)],
            'date_of_engagement' => 'nullable|date',
            'program_text' => 'nullable|string|max:1000',
            'school_id' => 'nullable|integer|exists:schools,id',
            // One or more school names joined by " · " — the de-duplicated union
            // of the selected programs' schools. school_id keeps the first.
            'school_text' => 'nullable|string|max:1000',
            // Per-program school, index-aligned with the delimited program_text,
            // so each program can show its own school on the profile.
            'program_schools' => 'nullable|array|max:50',
            'program_schools.*' => 'nullable|string|max:191',
            'internal_note' => 'nullable|string|max:5000',
            'payment' => 'nullable|string|max:191',
            'intake' => 'nullable|string|max:120',
            'coop' => 'nullable|string|max:120',
            'oop' => 'nullable|string|max:120',
            'english_test' => 'nullable|string|max:32',
        ]);
    }

    /**
     * Documents — Queue view (what needs my attention) + Folders view
     * (every student's folder with completion progress).
     */
    public function documents()
    {
        try {
            $pending = \App\Models\LeadDocument::with('lead:id,first_name,last_name,lead_id')
                ->whereIn('status', ['Submitted', 'UnderReview'])
                ->orderBy('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($d) => $this->docQueueRow($d, 'pending'));

            $stale = \App\Models\LeadDocument::with('lead:id,first_name,last_name,lead_id')
                ->where('status', 'Submitted')
                ->where('created_at', '<', now()->subDays(7))
                ->orderBy('created_at')
                ->limit(30)
                ->get()
                ->map(fn ($d) => $this->docQueueRow($d, 'stale'));

            $rejected = \App\Models\LeadDocument::with('lead:id,first_name,last_name,lead_id')
                ->where('status', 'Rejected')
                ->where('reviewed_at', '>', now()->subDays(14))
                ->orderByDesc('reviewed_at')
                ->limit(30)
                ->get()
                ->map(fn ($d) => $this->docQueueRow($d, 'rejected'));

            // Folders — every lead with at least one document, with progress.
            $folders = Lead::has('documents')
                ->with('documents:id,lead_id,status,checklist_key')
                ->orderBy('first_name')
                ->limit(200)
                ->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'lead_id' => $l->lead_id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
                    'total' => $l->documents->count(),
                    'approved' => $l->documents->where('status', 'Approved')->count(),
                    'pending' => $l->documents->whereIn('status', ['Submitted', 'UnderReview'])->count(),
                    'rejected' => $l->documents->where('status', 'Rejected')->count(),
                ]);

            return inertia('portal/education/Documents', [
                'portal' => 'education',
                'pending' => $pending,
                'stale' => $stale,
                'rejected' => $rejected,
                'folders' => $folders,
            ]);
        } catch (\Throwable $e) {
            Log::error('Education documents page failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/Documents', ['portal' => 'education', 'pending' => [], 'stale' => [], 'rejected' => [], 'folders' => []]);
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

    // Stubs — these get real content as we build each feature out.
    public function checklistTemplates()
    {
        return inertia('portal/education/ChecklistTemplates', ['portal' => 'education']);
    }

    /**
     * Education assessments queue — Free Assessment + Education Enrolment
     * submissions tagged via the `source` column on the leads table. Same
     * page powers Sales; the data is identical, just wrapped with a
     * different portal layout.
     */
    public function assessments()
    {
        return inertia('portal/education/Assessments', [
            'portal' => 'education',
            'eligibility' => $this->assessmentRows('free-assessment'),
            'enrolment' => $this->assessmentRows('education-enrolment'),
        ]);
    }

    /**
     * Shared query helper for both Education + Sales assessments pages.
     * Pulls the most recent 200 leads of a given source plus a synthesised
     * `programme` / `level` summary lifted out of the JSON ai_analysis blob.
     */
    private function assessmentRows(string $source)
    {
        return Lead::query()
            ->where('source', $source)
            // Order by updated_at so a draft that was just auto-saved
            // bubbles to the top — created_at would keep it stuck wherever
            // it was first written and make the page feel unchanged after
            // a save.
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function (Lead $l) {
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
                    // Short AI verdict shown under the progress bar
                    // ("AI analysed · eligible" / "· needs review").
                    'ai_verdict' => $this->assessmentVerdict($l, $analysis),
                    'detail_url' => "/portal/education/leads/{$l->id}",
                ];
            });
    }

    /** A short AI-eligibility verdict for the Assessments progress column. */
    private function assessmentVerdict(Lead $l, array $analysis): ?string
    {
        if ($l->ai_analysis_status !== 'completed') {
            return null;
        }
        foreach (['verdict', 'eligibility', 'recommendation'] as $k) {
            if (! empty($analysis[$k]) && is_string($analysis[$k])) {
                return (string) \Illuminate\Support\Str::of($analysis[$k])->limit(28);
            }
        }
        $score = $analysis['overall_score'] ?? $analysis['eligibility_score'] ?? $analysis['score'] ?? null;
        if ($score !== null) {
            return (int) $score >= 60 ? 'eligible' : 'needs review';
        }

        return 'analysed';
    }

    /** Programs the Education team advises on — same catalogue admin manages. */
    public function programs()
    {
        try {
            // Same data shape the admin Programs page ships (full models +
            // school relation + appended image_url), so the education portal
            // renders the identical Programs module. The full attributes also
            // seed the edit modal so a save can't blank untouched columns.
            $programs = Program::with('school:id,name')->latest()->get();
            $programs->each(function (Program $p) {
                $p->image_url = $p->image
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($p->image)
                    : null;
            });

            $schools = \App\Models\School::orderBy('name')->get(['id', 'name']);

            return inertia('portal/education/Programs', [
                'portal' => 'education',
                'programs' => $programs,
                'schools' => $schools,
            ]);
        } catch (\Throwable $e) {
            Log::error('Education programs page failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/Programs', ['portal' => 'education', 'programs' => collect(), 'schools' => collect()]);
        }
    }

    /**
     * Single Reports page — period is a query param (weekly / monthly /
     * quarterly / custom). All 13 sections render regardless; only the
     * data underneath changes. Filters (counselor / institution / intake
     * / program) also come in via query so they persist across tab clicks.
     */
    /**
     * Education weekly/period report — mirrors the Immigration report: pick a
     * period, get the pipeline position, named client movements, programs
     * snapshot and an editable conclusion, all from live student data
     * (education_stage). The named sections follow the management-report deck.
     */
    public function reports(Request $request)
    {
        [$preset, $from, $to] = $this->resolveEduReportRange($request);
        $rangeDays = max(1, (int) $from->diffInDays($to) + 1);

        try {
            // The education register = students PLUS any lead with a study plan
            // (education intent). A record's position in the 15-stage pipeline is
            // its education_stage when set (stages 6+), else its lead status
            // mapped onto the early stages, so the pre-enrolment cards populate.
            // Department membership — mirrors departmentsOf() on the Students
            // page so the report and the tab badges count the same rows:
            //   - immigration: is_immigration_case, an immigration_stage, or an
            //     education_stage in the immigration-handoff subset.
            //   - english:     an english_stage, or stage = "English Pro".
            //   - education:   a student, or a lead owned by neither of the above.
            $immHandoff = Lead::EDUCATION_STAGES_IMMIGRATION;
            $deptOf = function ($l) use ($immHandoff) {
                $inImm = $l->is_immigration_case || $l->immigration_stage || in_array($l->education_stage, $immHandoff, true);
                $inEng = $l->english_stage || $l->stage === 'English Pro';
                $set = [];
                if ($inImm) {
                    $set[] = 'immigration';
                }
                if ($inEng) {
                    $set[] = 'english';
                }
                if ($l->is_student || (! $inImm && ! $inEng)) {
                    $set[] = 'education';
                }

                return $set;
            };

            $inWin = fn ($l) => $l->stage_updated_at && $l->stage_updated_at->between($from, $to);

            // ── Sections 01/02 — the LEADS pipeline. Mirrors the "List of Leads"
            // page: every lead still in the pipeline (inLeadPipeline), counted by
            // its real sales status. Converted students/cases are NOT here — they
            // live in the department breakdown below.
            $salesTerminal = ['Not Qualified', 'Work Pathway / Other'];
            $leads = Lead::inLeadPipeline()
                // A lead that already carries an English or Immigration sub-stage
                // belongs to the Students register (department breakdown), not the
                // general sales pipeline — keep it out of Section 01.
                ->whereNull('english_stage')
                ->whereNull('immigration_stage')
                ->where(fn ($q) => $q->where('stage', '!=', 'English Pro')->orWhereNull('stage'))
                ->get(['id', 'first_name', 'last_name', 'status', 'stage_updated_at', 'referral', 'student_school'])
                // Drop the department-conversion statuses — they aren't part of
                // the sales funnel and aren't offered in the Leads picker.
                ->reject(fn ($l) => in_array($l->status, self::HIDDEN_LEAD_STAGES, true))
                ->values();

            // Canonical sales order first, then any other status value present in
            // the data (legacy labels like "Submitted"), so nothing is dropped.
            $canonicalStages = $this->leadStageOptions();
            $extraStatuses = $leads->pluck('status')->filter()
                ->reject(fn ($s) => in_array($s, $canonicalStages, true))
                ->unique()->values()->all();
            $pipeStages = array_merge($canonicalStages, $extraStatuses);

            $byStage = [];
            foreach ($pipeStages as $s) {
                $byStage[$s] = collect();
            }
            $noStatus = collect();
            foreach ($leads as $l) {
                if ($l->status && isset($byStage[$l->status])) {
                    $byStage[$l->status]->push($l);
                } else {
                    $noStatus->push($l);
                }
            }
            if ($noStatus->isNotEmpty()) {
                $byStage['No status'] = $noStatus;
                $pipeStages[] = 'No status';
            }

            // ── Section 01 — Pipeline position (only stages that hold leads) ──
            $pipeline = collect($pipeStages)
                ->filter(fn ($s) => $byStage[$s]->isNotEmpty())
                ->values()
                ->map(fn ($s, $i) => [
                    'num' => $i + 1,
                    'stage' => $s,
                    'count' => $byStage[$s]->count(),
                    'moved' => $byStage[$s]->filter($inWin)->count(),
                    'outside' => in_array($s, $salesTerminal, true),
                ])->all();
            $totalRegister = $leads->count();
            $movements = collect($byStage)->sum(fn ($c) => $c->filter($inWin)->count());

            // Top-line summary cards.
            $summary = [
                'new_students' => Lead::where('is_student', true)->whereBetween('student_converted_at', [$from, $to])->count(),
                'total_students' => Lead::where('is_student', true)->count(),
                'new_leads' => Lead::inLeadPipeline()->whereBetween('created_at', [$from, $to])->count(),
                'register' => $totalRegister,
            ];

            // Named lists behind the clickable summary cards.
            $mkList = fn ($query, $dateField) => $query->get(['id', 'first_name', 'last_name', 'referral', 'student_school', $dateField])
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Client',
                    'ref' => $l->referral,
                    'school' => $l->student_school,
                    'date' => optional($l->{$dateField})->toIso8601String(),
                    'moved' => false,
                ])->values();
            $summaryLists = [
                'new_students' => $mkList(Lead::where('is_student', true)->whereBetween('student_converted_at', [$from, $to])->orderByDesc('student_converted_at'), 'student_converted_at'),
                'total_students' => $mkList(Lead::where('is_student', true)->orderByDesc('student_converted_at'), 'student_converted_at'),
                'new_leads' => $mkList(Lead::inLeadPipeline()->whereBetween('created_at', [$from, $to])->orderByDesc('created_at'), 'created_at'),
            ];

            // ── Section 02 — Client register (named leads per sales stage) ───
            $register = collect($pipeStages)
                ->filter(fn ($s) => $byStage[$s]->isNotEmpty())
                ->mapWithKeys(fn ($s) => [$s => $byStage[$s]
                    ->sortByDesc(fn ($l) => optional($l->stage_updated_at)->timestamp)
                    ->map(fn ($l) => [
                        'id' => $l->id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Client',
                        'ref' => $l->referral,
                        'school' => $l->student_school,
                        'date' => optional($l->stage_updated_at)->toIso8601String(),
                        'moved' => $inWin($l),
                    ])->values()->all()])->all();

            // ── Section 02b — Department breakdown ───────────────────────────
            // Each of the three Students tabs (Education / English / Immigration)
            // has its OWN status set, and each real status is tracked here rather
            // than folded onto the education pipeline. A record can appear under
            // more than one department (it counts in each, matching the tabs).
            $mkRow = fn ($l) => [
                'id' => $l->id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Client',
                'ref' => $l->referral,
                'school' => $l->student_school,
                'date' => optional($l->stage_updated_at)->toIso8601String(),
                'moved' => $inWin($l),
            ];
            // The department breakdown mirrors the Students page tabs, so it is
            // scoped to the Students REGISTER only (converted students + English
            // learners + student-visa immigration cases) — NOT the general lead
            // pipeline. That keeps the Education tab at its real student count
            // and its real student statuses (no phantom lead stages like
            // "Proposal Sent" leaking in from the sales pipeline).
            $deptRecords = Lead::inStudentsRegister()
                ->get(['id', 'first_name', 'last_name', 'education_stage', 'immigration_stage', 'english_stage', 'stage', 'is_immigration_case', 'is_student', 'stage_updated_at', 'referral', 'student_school']);

            $buildDept = function (string $dept, array $stages, callable $stageOf) use ($deptRecords, $deptOf, $mkRow, $inWin) {
                $members = $deptRecords->filter(fn ($l) => in_array($dept, $deptOf($l), true));
                $buckets = [];
                foreach ($stages as $s) {
                    $buckets[$s] = collect();
                }
                $unset = collect();
                foreach ($members as $l) {
                    $s = $stageOf($l);
                    if ($s && isset($buckets[$s])) {
                        $buckets[$s]->push($l);
                    } else {
                        $unset->push($l);
                    }
                }
                // Only surface statuses that actually hold clients, in canonical
                // order — an empty stage isn't shown (so Education never lists a
                // status no student sits at).
                $rows = collect($stages)
                    ->filter(fn ($s) => $buckets[$s]->isNotEmpty())
                    ->map(fn ($s) => [
                        'stage' => $s,
                        'count' => $buckets[$s]->count(),
                        'moved' => $buckets[$s]->filter($inWin)->count(),
                        'clients' => $buckets[$s]
                            ->sortByDesc(fn ($l) => optional($l->stage_updated_at)->timestamp)
                            ->map($mkRow)->values()->all(),
                    ]);
                if ($unset->isNotEmpty()) {
                    $rows->push([
                        'stage' => 'No stage set',
                        'count' => $unset->count(),
                        'moved' => $unset->filter($inWin)->count(),
                        'clients' => $unset->map($mkRow)->values()->all(),
                    ]);
                }

                return [
                    'total' => $members->count(),
                    'moved' => $members->filter($inWin)->count(),
                    'stages' => $rows->values()->all(),
                ];
            };

            $departments = [
                'education' => $buildDept('education', Lead::EDUCATION_STAGES,
                    fn ($l) => $l->education_stage),
                'english' => $buildDept('english', Lead::ENGLISH_STAGES,
                    fn ($l) => $l->english_stage),
                'immigration' => $buildDept('immigration', Lead::IMMIGRATION_STAGES,
                    fn ($l) => $l->immigration_stage),
            ];

            // ── Section 04 — ePortal Programs snapshot ───────────────────────
            $programs = [
                'total' => Program::count(),
                'published' => Program::where('status', 'published')->count(),
                'draft' => Program::where('status', 'draft')->count(),
            ];

            // ── Section 05 — Conclusion ──────────────────────────────────────
            $noteKey = 'edu_report_note:'.$from->toDateString().':'.$to->toDateString();
            $proposals = ($byStage['Proposal Sent'] ?? collect())->filter($inWin)->count();
            $qualified = collect(['Qualified', 'Qualified but Not Ready', 'Qualified but No Funds'])
                ->sum(fn ($s) => ($byStage[$s] ?? collect())->filter($inWin)->count());
            $auto = sprintf(
                '%d lead movement%s this period across %d in the pipeline. %d newly qualified, %d proposal%s sent.',
                $movements, $movements === 1 ? '' : 's', $totalRegister,
                $qualified, $proposals, $proposals === 1 ? '' : 's',
            );
            $conclusion = [
                'auto' => $auto,
                'note' => \App\Models\Setting::get($noteKey),
                'note_key' => $noteKey,
                'stats' => [
                    'movements' => $movements,
                    'qualified' => $qualified,
                    'proposals' => $proposals,
                    'register' => $totalRegister,
                ],
            ];

            return inertia('portal/education/Reports', [
                'portal' => 'education',
                'range' => [
                    'preset' => $preset,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => $rangeDays,
                    'label' => $this->eduReportRangeLabel($preset, $from, $to),
                ],
                'pipeline' => $pipeline,
                'summary' => $summary,
                'summaryLists' => $summaryLists,
                'totalRegister' => $totalRegister,
                'movements' => $movements,
                'register' => $register,
                'departments' => $departments,
                'programs' => $programs,
                'conclusion' => $conclusion,
                'generated_at' => now()->toIso8601String(),
                'generated_by' => optional(auth()->user())->name,
            ]);
        } catch (\Throwable $e) {
            Log::error('Education reports failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/Reports', [
                'portal' => 'education',
                'range' => ['preset' => $preset, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $rangeDays, 'label' => $this->eduReportRangeLabel($preset, $from, $to)],
                'error' => 'Could not build the report.',
            ]);
        }
    }

    /** Save (or clear) the editable conclusion note for an education report period. */
    public function saveReportNote(Request $request)
    {
        $data = $request->validate([
            'note_key' => ['required', 'string', 'starts_with:edu_report_note:', 'max:120'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        \App\Models\Setting::set($data['note_key'], $data['note'] ?: null, 'string', 'Education report note', 'education');

        return back()->with('success', 'Report note saved.');
    }

    /**
     * Resolve the report window from the request. Presets mirror the Immigration
     * report: today | this_week | two_weeks | this_month | last_month | quarter |
     * custom(from,to). Default is the last 14 days.
     *
     * @return array{0: string, 1: \Illuminate\Support\Carbon, 2: \Illuminate\Support\Carbon}
     */
    private function resolveEduReportRange(Request $request): array
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
                $cfrom = $request->filled('from')
                    ? \Illuminate\Support\Carbon::parse($request->input('from'))->startOfDay()
                    : $now->copy()->subDays(14)->startOfDay();
                $cto = $request->filled('to')
                    ? \Illuminate\Support\Carbon::parse($request->input('to'))->endOfDay()
                    : $now->copy()->endOfDay();
                if ($cfrom->gt($cto)) {
                    [$cfrom, $cto] = [$cto->copy()->startOfDay(), $cfrom->copy()->endOfDay()];
                }

                return [$preset, $cfrom, $cto];
            default: // two_weeks
                return ['two_weeks', $now->copy()->subDays(14)->startOfDay(), $now->copy()->endOfDay()];
        }
    }

    private function eduReportRangeLabel(string $preset, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): string
    {
        return match ($preset) {
            'today' => 'Today',
            'this_week' => 'This week',
            'this_month' => $from->format('F Y'),
            'last_month' => $from->format('F Y'),
            'quarter' => 'Last 3 months',
            'custom' => $from->format('j M').' – '.$to->format('j M Y'),
            default => 'Last 2 weeks',
        };
    }

    /** Build a period-appropriate trend array (8 buckets). */
    private function buildEducationTrend(string $period, \Carbon\Carbon $anchor): array
    {
        $points = [];
        for ($i = 7; $i >= 0; $i--) {
            $b = $anchor->copy();
            switch ($period) {
                case 'monthly':
                    $b->subMonths($i);
                    $start = $b->copy()->startOfMonth();
                    $end = $b->copy()->endOfMonth();
                    $label = $b->format('M Y');
                    break;
                case 'quarterly':
                    $b->subQuarters($i);
                    $start = $b->copy()->startOfQuarter();
                    $end = $b->copy()->endOfQuarter();
                    $label = 'Q'.$b->quarter.' '.$b->year;
                    break;
                case 'custom':
                    // For custom, just emit 8 equal slices of the range — best-effort.
                    $start = $anchor->copy()->subDays($i * 7);
                    $end = $start->copy()->addDays(6);
                    $label = $start->format('d M');
                    break;
                case 'weekly':
                default:
                    $b->subWeeks($i);
                    $start = $b->copy()->startOfWeek();
                    $end = $b->copy()->endOfWeek();
                    $label = $start->format('d M');
            }
            $points[] = [
                'label' => $label,
                'new_students' => Lead::where('is_student', true)->whereBetween('student_converted_at', [$start, $end])->count(),
                'docs_uploaded' => \App\Models\LeadDocument::whereBetween('created_at', [$start, $end])->count(),
                'docs_approved' => \App\Models\LeadDocument::where('status', 'Approved')->whereBetween('reviewed_at', [$start, $end])->count(),
            ];
        }

        return $points;
    }

    public function profile()
    {
        return inertia('portal/education/Profile', ['portal' => 'education', 'user' => auth()->user()->only(['id', 'name', 'email', 'role'])]);
    }

    /**
     * Tasks & follow-ups across every lead, bucketed Today / This Week /
     * Overdue / All. Mirrors SalesController::tasks — same data, same
     * serialisation. Rendered via the shared Tasks page (Education portal
     * re-exports the sales component verbatim).
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
                ->when($scope === 'department', fn ($q) => $q->where('department', 'education'));

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

            // Full task set for the kanban (all dates, all statuses) — the
            // bucket queries below are kept for the legacy list view.
            $allTasks = (clone $base)->orderByDesc('created_at')->limit(1000)->get()->map($serialize);
            $today = (clone $base)->where('completed', false)->whereBetween('due_at', [$now, $todayEnd])->orderBy('due_at')->get()->map($serialize);
            $overdue = (clone $base)->where('completed', false)->whereNotNull('due_at')->where('due_at', '<', $now)->orderBy('due_at')->get()->map($serialize);
            $thisWeek = (clone $base)->where('completed', false)->whereBetween('due_at', [$todayEnd, $weekEnd])->orderBy('due_at')->get()->map($serialize);
            $undated = (clone $base)->where('completed', false)->whereNull('due_at')->orderByDesc('created_at')->limit(50)->get()->map($serialize);
            $recentlyDone = (clone $base)->where('completed', true)->orderByDesc('completed_at')->limit(20)->get()->map($serialize);

            return inertia('portal/education/Tasks', [
                'portal' => 'education',
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
            Log::error('Education tasks page failed', ['error' => $e->getMessage()]);

            return inertia('portal/education/Tasks', ['portal' => 'education', 'scope' => 'mine', 'today' => [], 'overdue' => [], 'this_week' => [], 'undated' => [], 'recently_done' => [], 'staffOptions' => []]);
        }
    }
}
