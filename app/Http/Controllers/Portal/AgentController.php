<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LeadController;
use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\Program;
use App\Services\AgentAgreementService;
use App\Traits\BuildsLeadRow;
use App\Traits\CreatesDashboardLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Recruiting Agent portal. An agent works alongside sales but only ever sees
 * the leads THEY added (scoped by leads.agent_id). They can add a lead and
 * edit its basic info — but NOT change its stage/status, convert it, or
 * delete it. Those pipeline actions stay with sales/admin.
 */
class AgentController extends Controller
{
    use BuildsLeadRow, CreatesDashboardLead;

    /** Statuses shown (read-only) on the agent's own leads. */
    private const LEAD_STATUSES = [
        'New Leads', 'Attempted to Contact', 'Contacted', 'For Assessment',
        'Consultation Booked', 'Consultation Done', 'For Proposal',
        'Proposal Sent', 'Converted', 'Not Interested', 'Lost',
    ];

    /**
     * Only this agent's leads, newest first. Leads that have become immigration
     * cases are excluded — they live in the Cases module now, so showing them
     * here too would double-list the same person.
     */
    private function ownLeadsQuery()
    {
        return Lead::where('agent_id', Auth::id())
            ->where(fn ($q) => $q->where('is_immigration_case', false)->orWhereNull('is_immigration_case'));
    }

    public function dashboard()
    {
        $agentId = Auth::id();
        $base = fn () => Lead::where('agent_id', $agentId);

        $recent = $base()
            ->with(['studyPlans', 'tags:id,name', 'notes' => fn ($q) => $q->latest(), 'documents:id,lead_id,checklist_key,status'])
            ->withCount(['notes', 'documents'])
            ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
            ->latest()
            ->limit(8)
            ->get();

        // Public per-agent registration link. Generated on first read so
        // agents seeded before the referral_code column existed still get
        // one automatically.
        $agent = Auth::user();
        $code = $agent?->ensureReferralCode();
        $referralUrl = $code ? route('affiliate.registration', ['code' => $code]) : null;

        return inertia('portal/agent/Dashboard', [
            'stats' => [
                'total' => $base()->count(),
                'this_week' => $base()->where('created_at', '>=', now()->startOfWeek())->count(),
                'this_month' => $base()->where('created_at', '>=', now()->startOfMonth())->count(),
                'converted' => $base()->where(fn ($q) => $q->where('status', 'Converted')->orWhere('is_student', true)->orWhere('is_immigration_case', true))->count(),
            ],
            'recent' => $recent->map(fn ($l) => $this->leadRow($l)),
            'referral' => [
                'code' => $code,
                'url' => $referralUrl,
            ],
        ]);
    }

    /**
     * Students, read-only — the leads this agent recruited who have since been
     * converted to a student, an English learner or an immigration case.
     *
     * Renders the shared Education "Students" screen with the query narrowed to
     * `agent_id = me`. `portal:agent` only proves the role; without this scope
     * an agent would see every student in the business. The screen's write
     * actions are switched off server-side (see `readOnly` in the payload) —
     * an agent tracks their referrals' progress, they don't administer them.
     */
    public function students()
    {
        $me = Auth::id();

        return app(EducationController::class)->students(
            fn ($q) => $q->where('agent_id', $me)
        );
    }

    /**
     * Cases — the agent's OWN referrals that have become immigration cases.
     *
     * Renders the SAME "List of Cases" board immigration staff use (via a
     * re-export at portal/agent/Cases), built from the exact same payload —
     * ImmigrationController::casesPayload() — narrowed to `agent_id = me`. The
     * board is fully interactive: a First-Immigration agent works their own
     * referrals (inline stage / visa / priority, open the case profile) just
     * like staff, with the board's URLs resolving to the agent-portal case
     * routes (portal-relative via resources/js/lib/caseRoutes.js). Gated by the
     * `referral_cases` module; `portal:agent` proves the role only.
     */
    public function cases()
    {
        $me = Auth::id();

        try {
            $payload = app(\App\Http\Controllers\Portal\ImmigrationController::class)
                ->casesPayload(fn ($q) => $q->where('agent_id', $me));

            return inertia('portal/agent/Cases', array_merge($payload, [
                'portal' => 'agent',
            ]));
        } catch (\Throwable $e) {
            Log::error('Agent cases list failed', ['error' => $e->getMessage()]);

            return inertia('portal/agent/Cases', [
                'portal' => 'agent',
                'cases' => [],
                'distribution' => [],
                'priorities' => [],
                'stages' => Lead::IMMIGRATION_STAGES,
                'visaTypes' => [],
            ]);
        }
    }

    /**
     * The full immigration Case Profile for one of the agent's own referrals,
     * rendered under the Agent portal chrome. Ownership is enforced by the
     * `lead.scope` middleware (agent_id = me) and `is_immigration_case` by
     * CaseProfileController::show; this delegates with the agent page so app.jsx
     * wraps it in AgentLayout and its writes stay portal-relative. Advice-bearing
     * actions remain licence-gated inside the controllers (adviser assists).
     */
    public function caseProfile(Lead $lead)
    {
        return app(\App\Http\Controllers\Immigration\CaseProfileController::class)->show(
            $lead,
            app(\App\Services\Immigration\CaseChecklistService::class),
            'portal/agent/CaseProfile'
        );
    }

    /**
     * Stream a single case document to the agent. The `lead.scope` middleware
     * can't scope this route (it carries a document id, not a lead id), so the
     * ownership predicate lives here: the document's lead must belong to me.
     */
    public function downloadCaseDocument(Request $request, $docId)
    {
        $doc = \App\Models\LeadDocument::with('lead:id,agent_id')->findOrFail($docId);
        abort_unless($doc->lead && $doc->lead->agent_id === Auth::id(), 404);

        return app(\App\Http\Controllers\LeadDocumentController::class)->download($request, $docId);
    }

    /** Agent's own account page — mirrors the pattern used by sales /
     *  education / immigration portals. The referral link is exposed too
     *  so the agent can copy it from here as well as the dashboard. */
    public function profile()
    {
        $me = Auth::user();
        $code = $me?->ensureReferralCode();

        return inertia('portal/agent/Profile', [
            'portal' => 'agent',
            'user' => $me?->only(['id', 'name', 'email', 'role', 'phone']),
            'referral' => $code ? [
                'code' => $code,
                'url' => route('affiliate.registration', ['code' => $code]),
            ] : null,
        ]);
    }

    /** The agent's own Referral Agent Agreement — view, sign, and download.
     *  Staff generate it from the Agents module; the agent signs it here. */
    public function agreement()
    {
        $agreement = AgentAgreement::where('agent_id', Auth::id())->latest()->first();

        // Current values of the Affiliate-Partner-filled fields (Schedule B bank
        // + contact) so the agent's sign step is pre-seeded with anything staff
        // already entered or the agent previously provided.
        $storedFields = is_array($agreement?->fields) ? $agreement->fields : [];
        $affiliateFields = AgentAgreementService::affiliateFields();
        $affiliateValues = collect($affiliateFields)
            ->mapWithKeys(fn ($f) => [$f['key'] => (string) ($storedFields[$f['key']] ?? '')])
            ->all();

        return inertia('portal/agent/Agreement', [
            'agreement' => $agreement ? [
                'original_name' => $agreement->original_name,
                'size' => $agreement->size,
                'created_at' => optional($agreement->created_at)?->toIso8601String(),
                'download_url' => '/portal/agent/agreement/download',
                'view_url' => '/portal/agent/agreement/view',
                'signed' => $agreement->isSignedByAgent(),
                'signer_name' => $agreement->agent_signer_name,
                'signed_at' => optional($agreement->agent_signed_at)?->toIso8601String(),
            ] : null,
            // Schedule B + contact fields the Affiliate Partner completes themselves.
            'affiliateFields' => $affiliateFields,
            'affiliateValues' => $affiliateValues,
        ]);
    }

    /** Live HTML preview of the agent's agreement, reflecting their in-progress
     *  Schedule B / contact edits (query params) over the stored fields. */
    public function previewAgreement(Request $request, AgentAgreementService $service)
    {
        $agreement = AgentAgreement::where('agent_id', Auth::id())->latest()->firstOrFail();

        $keys = collect(AgentAgreementService::affiliateFields())->pluck('key')->all();
        $override = collect($keys)
            ->filter(fn ($k) => $request->has($k))
            ->mapWithKeys(fn ($k) => [$k => (string) $request->query($k)])
            ->all();

        $html = $service->previewHtmlForAgreement($agreement, $override);

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Stream the agent's own agreement PDF inline (viewable in the browser). */
    public function viewAgreement()
    {
        $agreement = AgentAgreement::where('agent_id', Auth::id())->latest()->firstOrFail();

        abort_unless(Storage::disk('local')->exists($agreement->file_path), 404);

        return response()->file(Storage::disk('local')->path($agreement->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$agreement->original_name.'"',
        ]);
    }

    /** Affiliate Partner fills their own Schedule B bank + contact details.
     *  After staff generate the agreement, this is the agent's part. Saved
     *  independently of signing; re-renders the PDF (keeping any signatures). */
    public function updateAgreementDetails(Request $request, AgentAgreementService $service)
    {
        $keys = collect(AgentAgreementService::affiliateFields())->pluck('key')->all();
        $rules = [];
        foreach ($keys as $key) {
            $rules[$key] = ['nullable', 'string', 'max:500'];
        }
        $request->validate($rules);

        $agreement = AgentAgreement::where('agent_id', Auth::id())->latest()->firstOrFail();
        $service->updateFields($agreement, $request->only($keys));

        return back()->with('success', 'Your details were saved.');
    }

    /** Record the agent's e-signature on their own agreement (same capture
     *  method as the tracker agreement signing). */
    public function signAgreement(Request $request, AgentAgreementService $service)
    {
        // Signature + terms, plus the Affiliate-Partner-filled fields
        // (Schedule B bank account + their execution contact details).
        $affiliateKeys = collect(AgentAgreementService::affiliateFields())->pluck('key')->all();
        $rules = [
            'signer_name' => ['required', 'string', 'max:200'],
            'signature_data' => ['required', 'string', 'max:5000000'],
            'terms_accepted' => ['required', 'accepted'],
        ];
        foreach ($affiliateKeys as $key) {
            $rules[$key] = ['nullable', 'string', 'max:500'];
        }
        $validated = $request->validate($rules);

        $agreement = AgentAgreement::where('agent_id', Auth::id())->latest()->firstOrFail();

        // Already signed — don't overwrite an existing signature.
        abort_if($agreement->isSignedByAgent(), 422, 'This agreement is already signed.');

        // Merge the agent's Schedule B + contact details into the stored fields
        // so the re-rendered PDF (done inside recordAgentSignature) includes them.
        $fields = is_array($agreement->fields) ? $agreement->fields : [];
        foreach ($affiliateKeys as $key) {
            if ($request->has($key)) {
                $fields[$key] = mb_substr(trim((string) $request->input($key)), 0, 500);
            }
        }
        $agreement->fields = $fields;
        $agreement->save();

        $service->recordAgentSignature(
            $agreement,
            trim($validated['signer_name']),
            $validated['signature_data'],
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('success', 'Agreement signed. Thank you!');
    }

    /** Stream the agent's own agreement PDF (scoped to Auth::id()). */
    public function downloadAgreement()
    {
        $agreement = AgentAgreement::where('agent_id', Auth::id())->latest()->firstOrFail();

        abort_unless(Storage::disk('local')->exists($agreement->file_path), 404);

        return Storage::disk('local')->download($agreement->file_path, $agreement->original_name);
    }

    /**
     * The agent's own leads — rendered with the SHARED sales Leads screen so
     * the table looks and behaves the same everywhere (same re-export pattern
     * as admin / education / immigration).
     *
     * Two things make that safe. The query is scoped to `agent_id = me`, so an
     * agent only ever sees leads they recruited. And `portal => 'agent'` makes
     * the shared component drop every pipeline write — stage, visa, notes,
     * import, kanban, bulk assign/delete — none of which the agent portal
     * exposes an endpoint for. Add Lead and the Edit modal stay live, pointed
     * at this portal's own routes.
     */
    public function leads()
    {
        try {
            $leads = $this->ownLeadsQuery()
                ->with([
                    'studyPlans',
                    'tags:id,name',
                    'notes' => fn ($q) => $q->latest(),
                    'documents:id,lead_id,checklist_key,status',
                ])
                ->withCount(['notes', 'documents'])
                ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
                ->latest()
                ->get();

            return inertia('portal/agent/Leads', [
                'portal' => 'agent',
                'portalBase' => '/portal/agent',
                // The rows carry the SALES status vocabulary (sales works them
                // after hand-off), so the filter list has to be that one — the
                // Add-Lead form's shorter list would not match a single row.
                'statuses' => SalesController::LEAD_STATUSES,
                'programs' => Program::orderBy('title')->pluck('title')->filter()->values(),
                'leads' => $leads->map(fn ($l) => $this->leadRow($l)),
                'visaOptions' => $this->visaOptions(),
                // Tab counts scoped to this agent — the shared trait's version
                // counts every lead in the system and would leak other agents'
                // volumes through the badge.
                'tabCounts' => $this->ownTabCounts(),
                // Deliberately empty: staff assignment, the agent roster, the
                // events desk and the internal tag dictionary are all sales-side
                // surfaces. Passing them would light up controls that 403.
                'staffOptions' => [],
                'agents' => [],
                'events' => [],
                'allTagNames' => [],
            ]);
        } catch (\Throwable $e) {
            Log::error('Agent leads list failed', ['error' => $e->getMessage()]);

            return inertia('portal/agent/Leads', [
                'portal' => 'agent',
                'portalBase' => '/portal/agent',
                'statuses' => SalesController::LEAD_STATUSES,
                'programs' => collect(),
                'leads' => collect(),
            ]);
        }
    }

    /** Registration / events tab counts, over this agent's leads only. */
    private function ownTabCounts(): array
    {
        $since = now()->subDays(7);

        return [
            'registration' => (clone $this->ownLeadsQuery())
                ->where('source', 'registration')->where('created_at', '>=', $since)->count(),
            'events' => (clone $this->ownLeadsQuery())
                ->whereNotNull('event_id')->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Full lead profile, inside the Agent portal chrome.
     *
     * Row-scoped before anything is read: an agent may only open a lead they
     * recruited. `portal:agent` is role-level only and would happily let one
     * agent read another's lead by guessing an id, so the ownership check has
     * to live here.
     *
     * The payload is built by the shared LeadController::show(), which picks
     * the page component and the portal base off the request path — so the
     * profile renders under AgentLayout and every link inside it stays on
     * /portal/agent/*, leaving the sidebar untouched.
     */
    public function showLead($id)
    {
        $this->ownLeadsQuery()->where('id', $id)->firstOrFail();

        return app(LeadController::class)->show($id);
    }

    /**
     * The Edit modal's two endpoints, mirrored under /portal/agent so an agent
     * can correct their own referral's details without reaching into /admin.
     *
     * Both are ownership-checked first, then handed to the shared LeadController
     * handlers. `updatePersonal` is safe to expose here because it only accepts
     * personal FACTS — name, contact, identity, address. Stage, priority,
     * ownership and every conversion flag live on other endpoints the agent
     * portal deliberately does not route.
     */
    public function leadEditData($id)
    {
        $this->ownLeadsQuery()->where('id', $id)->firstOrFail();

        return app(LeadController::class)->editData($id);
    }

    public function updateLeadPersonal(Request $request, $id)
    {
        $this->ownLeadsQuery()->where('id', $id)->firstOrFail();

        return app(LeadController::class)->updatePersonal($request, $id);
    }

    /** Add a new lead — agent_id is auto-stamped by CreatesDashboardLead. */
    public function storeLead(Request $request)
    {
        $validated = $request->validate(
            $this->dashboardLeadRules(self::LEAD_STATUSES) + $this->dashboardDocumentRules()
        );

        $lead = $this->createDashboardLead($validated);
        $this->storeDashboardLeadDocuments($lead, $request);

        return back()->with('success', 'Lead added successfully.');
    }

    /**
     * Edit a lead's basic contact / personal info. Ownership-checked: an
     * agent may only edit a lead they added. Deliberately does NOT touch
     * stage, status, priority, or any conversion flag.
     */
    public function updateLeadInfo(Request $request, $id)
    {
        $lead = Lead::where('id', $id)->where('agent_id', Auth::id())->firstOrFail();

        $validated = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'suffix' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:40',
            'residence_city' => 'nullable|string|max:120',
            'residence_country' => 'nullable|string|max:120',
            'highest_qualification' => 'nullable|string|max:200',
            'program_offered' => 'nullable|string|max:200',
        ]);

        if (\App\Support\CaseRecordAccess::nameLocked($request, $lead)) {
            \App\Support\CaseRecordAccess::authorizeNameChanges($request, $lead, $validated, \App\Support\CaseRecordAccess::NAME_FIELDS);
        } else {
            $lead->first_name = trim($validated['first_name']);
            $lead->last_name = trim(($validated['last_name'] ?? '').' '.($validated['suffix'] ?? ''));
        }
        $lead->email = $validated['email'] ?? null;
        $lead->phone = $validated['phone'] ?? null;
        $lead->residence_city = $validated['residence_city'] ?? null;
        $lead->residence_country = $validated['residence_country'] ?? null;
        $lead->highest_qualification = $validated['highest_qualification'] ?? null;
        $lead->save();

        // PROGRAM OFFERED → mirror into the lead's study plan (create/update
        // the first one) so it reads back in the leads table.
        if (! empty($validated['program_offered'])) {
            $plan = $lead->studyPlans()->first();
            if ($plan) {
                $plan->update(['preferred_course' => $validated['program_offered']]);
            } else {
                $lead->studyPlans()->create([
                    'preferred_course' => $validated['program_offered'],
                    'qualification_level' => '',
                ]);
            }
        }

        return back()->with('success', 'Lead info updated.');
    }
}
