<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LeadNoteController;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Models\LeadTask;
use App\Models\User;
use App\Traits\BuildsLeadRow;
use App\Traits\CreatesDashboardLead;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Sub-agent portal — a sales-style pipeline scoped to ONE recruiting agent's
 * referral leads (the sub-agent's `parent_agent_id`). Every read and write is
 * row-scoped to `agent_id = parent_agent_id`, so a sub-agent can never see or
 * touch a lead outside their agent's referrals. Writes re-check ownership and
 * then delegate to the existing (validated) sales / note handlers.
 */
class SubAgentController extends Controller
{
    use BuildsLeadRow;
    use CreatesDashboardLead;

    /**
     * The only document types a sub-agent handles on a referral lead. Fixed set —
     * they collect these four from the applicant, nothing else.
     */
    private const DOC_TYPES = [
        'subagent_passport' => 'Passport',
        'subagent_cv' => 'CV / Resume',
        'subagent_diploma' => 'Diploma',
        'subagent_tor' => 'Transcript of Records (TOR)',
    ];

    /** The agent this sub-agent works under, or null if unassigned. */
    private function parentAgentId(): ?int
    {
        return Auth::user()?->parent_agent_id;
    }

    /** Base query — only the parent agent's referral leads. */
    private function scoped()
    {
        return Lead::where('agent_id', $this->parentAgentId());
    }

    /** Resolve a lead this sub-agent is allowed to touch, or 404. */
    private function findScoped($id): Lead
    {
        abort_if(! $this->parentAgentId(), 404);

        return $this->scoped()->where('id', $id)->firstOrFail();
    }

    public function dashboard()
    {
        $agentId = $this->parentAgentId();
        $agent = $agentId ? User::find($agentId) : null;
        $base = fn () => Lead::where('agent_id', $agentId);

        return inertia('portal/sub-agent/Dashboard', [
            'agent' => $agent ? ['name' => $agent->name, 'referral_code' => $agent->referral_code] : null,
            'stats' => $agentId ? [
                'total' => $base()->count(),
                'in_pipeline' => $base()->whereNotIn('status', ['Closed', 'Not Qualified'])->count(),
                'new_today' => $base()->whereDate('created_at', now()->toDateString())->count(),
                'converted' => $base()->where(fn ($q) => $q->where('is_student', true)->orWhere('is_immigration_case', true))->count(),
            ] : ['total' => 0, 'in_pipeline' => 0, 'new_today' => 0, 'converted' => 0],
        ]);
    }

    /**
     * Referral Leads — the parent agent's leads in the shared `leadRow()` shape,
     * plus the three things this screen adds on top of it: how many of the four
     * documents are in, when the lead was last actually spoken to, and the
     * passport expiry that drives the drawer's document warning.
     */
    public function leads()
    {
        $agentId = $this->parentAgentId();

        // No agent assigned yet → render the same page empty with a notice.
        if (! $agentId) {
            return inertia('portal/sub-agent/AgentLeads', [
                'agent' => ['name' => 'No agent assigned', 'email' => null, 'phone' => null],
                'leads' => [],
                'statuses' => SalesController::LEAD_STATUSES,
                'portalBase' => '/portal/sub-agent',
            ]);
        }

        $agent = User::find($agentId);

        $leads = Lead::where('agent_id', $agentId)
            ->with([
                'studyPlans',
                'event',
                'tags:id,name',
                'portalUser:id,lead_id,last_login_at',
                'stageUpdater:id,name', 'lastActivityUser:id,name',
                'notes' => fn ($q) => $q->latest(),
                'documents:id,lead_id,checklist_key,status',
                'contactProfile',
            ])
            ->withCount(['notes', 'documents'])
            ->withCount(['tasks as tasks_open_count' => fn ($q) => $q->where('completed', false)])
            ->latest()
            ->get();

        // One query for every lead's filled document slots, rather than four
        // per row. A slot counts as "in" once any file exists against it.
        $docsIn = LeadDocument::whereIn('lead_id', $leads->pluck('id'))
            ->whereIn('checklist_key', array_keys(self::DOC_TYPES))
            ->get(['lead_id', 'checklist_key'])
            ->groupBy('lead_id')
            ->map(fn ($g) => $g->pluck('checklist_key')->unique()->count());

        $rows = $leads->map(function (Lead $l) use ($docsIn) {
            // A logged call note is a contact attempt — an email or a stage
            // move is not a conversation, so neither counts here.
            $calls = $l->notes->where('kind', 'client_contact');

            return $this->leadRow($l) + [
                'docs_in' => (int) ($docsIn[$l->id] ?? 0),
                'docs_total' => count(self::DOC_TYPES),
                'passport_expiry' => $l->passport_expiry ? Carbon::parse($l->passport_expiry)->toDateString() : null,
                'last_contact_at' => $calls->first()?->created_at,
                'attempts' => $calls->count(),
                'stage_added_at' => $l->stage_updated_at ?: $l->created_at,
                'personal' => $this->personalPayload($l),
            ];
        });

        return inertia('portal/sub-agent/AgentLeads', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'location' => $agent->location,
                'avatar_url' => $agent->avatar_url,
                'leads_count' => $rows->count(),
            ],
            'leads' => $rows,
            'statuses' => SalesController::LEAD_STATUSES,
            'portalBase' => '/portal/sub-agent',
            'referralValue' => config('sub_agent.referral_value'),
            // Named on the Personal tab so it is unambiguous who owns advice:
            // a sub-agent collects facts, the licensed adviser assesses them.
            'adviceOwner' => $agent->name,
        ]);
    }

    /**
     * The facts behind the lead modal's "Personal" tab. Everything here is
     * recorded fact, never inference: an unset field comes back null so the UI
     * can say "not set" rather than showing a plausible guess.
     */
    private function personalPayload(Lead $l): array
    {
        // Contact facts live in the 1-to-1 lead_contact_profiles table, not on
        // the lead row. Flatten to a keyed array so the rest of this method reads
        // the same as before.
        $l->loadMissing('contactProfile');
        $cp = $l->contactProfile;
        $profile = $cp ? [
            'best_time_to_call' => $cp->best_time_to_call,
            'preferred_channel' => $cp->preferred_channel,
            'languages' => $cp->languages,
            'emergency_contact' => $cp->emergency_contact,
            'goal' => $cp->goal,
        ] : [];
        // Join the parts that are actually present — nulls and blanks drop out
        // rather than leaving stray separators behind.
        $join = fn (array $parts, string $glue = ', ') => implode($glue, array_filter(
            array_map(fn ($p) => trim((string) $p), $parts),
            fn ($p) => $p !== '',
        )) ?: null;

        // Dependants read off three separate flags — collapse them into the one
        // line the modal shows next to marital status.
        $dependants = [];
        if ($l->has_dependent_partner) {
            $dependants[] = 'partner';
        }
        if ($l->number_of_children) {
            $dependants[] = (int) $l->number_of_children.' child'.((int) $l->number_of_children === 1 ? '' : 'ren');
        }

        // Onshore/offshore is the single most load-bearing fact a sub-agent
        // collects, so derive it from residence rather than leaving it blank.
        $country = $l->residence_country ?: $l->country;
        $onshore = $country && str_contains(mb_strtolower($country), 'new zealand');

        return [
            // ── Contact ──
            'email' => $l->email,
            'phone' => $l->phone,
            'whatsapp' => $l->whatsapp,
            'best_time_to_call' => $profile['best_time_to_call'] ?? null,
            'preferred_channel' => $profile['preferred_channel'] ?? null,

            // ── Identity ──
            'full_legal_name' => $join([$l->first_name, $l->middle_name, $l->last_name, $l->suffix], ' '),
            'dob' => $l->dob ? Carbon::parse($l->dob)->toDateString() : null,
            'age' => $l->dob ? Carbon::parse($l->dob)->age : ($l->age ? (int) $l->age : null),
            'nationality' => $l->citizenship,
            'passport_number' => $l->passport_number,
            'passport_expiry' => $l->passport_expiry ? Carbon::parse($l->passport_expiry)->toDateString() : null,
            'address' => $join([
                $l->residence_address_line_1,
                $l->residence_address_line_2,
                $join([$l->residence_city, $l->residence_state, $l->residence_address_postcode], ' '),
                $country,
            ]),
            'languages' => $profile['languages'] ?? null,
            'marital_status' => $l->marital_status,
            'dependants' => $dependants ? implode(' · ', $dependants) : null,

            // ── Background & intent ──
            'current_status' => $country ? ($onshore ? 'Onshore · in New Zealand' : "Offshore · in {$country}") : null,
            'goal' => $profile['goal'] ?? null,
            'target_intake' => $l->preferred_intake,
            'highest_study' => $join([
                $join([$l->highest_qualification, $l->highest_qualification_field], ' '),
                $l->highest_qualification_year_completed,
            ]),
            'english_test' => $join([
                $join([$l->english_test_type, $l->english_test_overall_score], ' '),
                $l->english_test_date ? 'taken '.Carbon::parse($l->english_test_date)->format('M Y') : null,
            ], ' · '),
            // A declined visa is only "none" once someone has actually answered
            // the question — an untouched flag stays unknown.
            'previous_declines' => $l->has_been_declined_visa
                ? ($l->declined_visa_details ?: 'Declined — details not recorded')
                : ($l->has_been_declined_visa === null ? null : 'None declared'),
            'emergency_contact' => $profile['emergency_contact'] ?? null,

            // The same facts as raw column values, for the edit form. The
            // display strings above are joined/derived and can't be posted back.
            'raw' => collect(array_keys(self::EDITABLE_COLUMNS))
                ->mapWithKeys(function ($col) use ($l) {
                    $v = $l->{$col};
                    // Date inputs want Y-m-d, never a full timestamp.
                    if ($v && in_array($col, ['dob', 'passport_expiry', 'english_test_date'], true)) {
                        $v = Carbon::parse($v)->toDateString();
                    }

                    return [$col => $v];
                })
                ->merge(collect(array_keys(self::EDITABLE_PROFILE))
                    ->mapWithKeys(fn ($k) => [$k => $profile[$k] ?? null]))
                ->all(),
        ];
    }

    /**
     * Fields a sub-agent may edit on a referral lead. Deliberately narrow: the
     * facts they collect from the applicant, and nothing that carries a
     * judgement. Pipeline stage, priority, ownership, fees and any assessment
     * of eligibility are all excluded — those are not a sub-agent's call.
     */
    private const EDITABLE_COLUMNS = [
        'email' => 'nullable|email|max:200',
        'phone' => 'nullable|string|max:40',
        'whatsapp' => 'nullable|string|max:40',
        'first_name' => 'nullable|string|max:120',
        'middle_name' => 'nullable|string|max:120',
        'last_name' => 'nullable|string|max:120',
        'dob' => 'nullable|date|before:today',
        'citizenship' => 'nullable|string|max:120',
        'passport_number' => 'nullable|string|max:60',
        'passport_expiry' => 'nullable|date',
        'residence_address_line_1' => 'nullable|string|max:200',
        'residence_address_line_2' => 'nullable|string|max:200',
        'residence_city' => 'nullable|string|max:120',
        'residence_state' => 'nullable|string|max:120',
        'residence_address_postcode' => 'nullable|string|max:20',
        'residence_country' => 'nullable|string|max:120',
        'marital_status' => 'nullable|string|max:60',
        'preferred_intake' => 'nullable|string|max:120',
        'highest_qualification' => 'nullable|string|max:200',
        'highest_qualification_field' => 'nullable|string|max:200',
        'highest_qualification_year_completed' => 'nullable|integer|min:1950|max:2100',
        'english_test_type' => 'nullable|string|max:60',
        'english_test_overall_score' => 'nullable|string|max:20',
        'english_test_date' => 'nullable|date',
    ];

    /** Fields stored on the 1-to-1 lead_contact_profiles table. */
    private const EDITABLE_PROFILE = [
        'best_time_to_call' => 'nullable|string|max:120',
        'preferred_channel' => 'nullable|string|max:60',
        'languages' => 'nullable|string|max:200',
        'emergency_contact' => 'nullable|string|max:200',
        'goal' => 'nullable|string|max:300',
    ];

    /** Save edits from the lead modal's Personal tab. */
    public function updateLeadProfile(Request $request, $id)
    {
        $lead = $this->findScoped($id);

        $data = $request->validate(array_merge(self::EDITABLE_COLUMNS, self::EDITABLE_PROFILE));

        foreach (array_keys(self::EDITABLE_COLUMNS) as $col) {
            if ($request->exists($col)) {
                $lead->{$col} = $data[$col] ?? null;
            }
        }

        $lead->save();

        // Contact facts go to the 1-to-1 lead_contact_profiles table. Merge
        // rather than replace — a tab that posts only the contact block must not
        // blank the goal set from the other block — so only the keys actually
        // present in the request are touched. Empty strings normalise to null.
        $profileChanges = [];
        foreach (array_keys(self::EDITABLE_PROFILE) as $key) {
            if ($request->exists($key)) {
                $val = $data[$key] ?? null;
                $profileChanges[$key] = ($val === '') ? null : $val;
            }
        }
        if ($profileChanges) {
            $lead->contactProfile()->updateOrCreate([], $profileChanges);
        }

        return back()->with('success', 'Lead details saved.');
    }

    /**
     * Lead lifecycle flags the sub-agent owns. Both are free-form tags rather
     * than pipeline stages: a sub-agent records what happened on their side,
     * they do not move a lead through the sales pipeline or judge eligibility.
     * Both are reversible, and both leave a note behind saying who did it.
     */
    private const TAG_UNRESPONSIVE = 'unresponsive';

    private const TAG_READY = 'ready-for-review';

    public function markLead(Request $request, $id)
    {
        $lead = $this->findScoped($id);

        $data = $request->validate([
            'flag' => ['required', 'in:unresponsive,ready'],
            'on' => ['required', 'boolean'],
        ]);

        $tag = $data['flag'] === 'unresponsive' ? self::TAG_UNRESPONSIVE : self::TAG_READY;
        $on = (bool) $data['on'];

        if ($data['flag'] === 'ready' && $on) {
            // "Ready" means the paperwork is complete, so it is a fact the
            // server can check rather than take on trust from the button.
            $in = LeadDocument::where('lead_id', $lead->id)
                ->whereIn('checklist_key', array_keys(self::DOC_TYPES))
                ->distinct()->count('checklist_key');
            if ($in < count(self::DOC_TYPES)) {
                return back()->withErrors(['error' => "All {$this->docTotal()} required documents must be in first — {$in} are."]);
            }
        }

        $tagModel = \App\Models\LeadTag::findOrCreateByName($tag);
        if ($on) {
            // syncWithoutDetaching keeps this idempotent — clicking twice is a
            // no-op rather than a duplicate pivot row.
            $lead->tags()->syncWithoutDetaching([$tagModel->id => ['user_id' => Auth::id()]]);
        } else {
            $lead->tags()->detach($tagModel->id);
        }

        $verb = $on ? 'Marked' : 'Cleared';
        $label = $data['flag'] === 'unresponsive' ? 'unresponsive' : 'ready for adviser review';
        $user = Auth::user();
        $lead->notes()->create([
            'user_id' => $user?->id,
            'author_name' => $user?->name,
            'author_role' => $user?->role,
            'kind' => 'goal_setting',
            'body' => "{$verb} {$label}.",
        ]);

        // Marking unresponsive releases the lead: the open follow-ups chasing
        // them are closed so they stop appearing on the cadence screen.
        if ($data['flag'] === 'unresponsive' && $on) {
            LeadTask::where('lead_id', $lead->id)
                ->where('assignee_id', Auth::id())
                ->where('completed', false)
                ->update([
                    'completed' => true,
                    'completed_at' => now(),
                    'completed_by' => Auth::id(),
                    'status' => 'completed',
                    'snoozed_until' => null,
                ]);
        }

        return back()->with('success', "{$verb} {$lead->first_name} as {$label}.");
    }

    private function docTotal(): int
    {
        return count(self::DOC_TYPES);
    }

    /**
     * Full lead profile, inside the Sub-agent portal chrome.
     *
     * findScoped() enforces the same row filter as every other write here, so
     * a sub-agent can only open a lead belonging to their parent agent.
     * LeadController::show() then picks the page component and portal base off
     * the request path, which keeps the profile under SubAgentLayout and every
     * link inside it on /portal/sub-agent/* — the sidebar never changes.
     */
    public function showLead($id)
    {
        $this->findScoped($id);

        return app(\App\Http\Controllers\LeadController::class)->show($id);
    }

    /** Stage/status update — ownership re-checked, then the sales handler runs. */
    public function updateLead(Request $request, $id)
    {
        $this->findScoped($id);

        return app(SalesController::class)->updateLead($request, $id);
    }

    /** Add a note — ownership re-checked, then the shared note handler runs. */
    public function storeNote(Request $request, $id)
    {
        $this->findScoped($id);

        return app(LeadNoteController::class)->store($request, $id);
    }

    /**
     * Where a sub-agent's ad-hoc extras live inside `leads.custom_documents`.
     * Tagging the section keeps them separate from custom items staff added on
     * the admin side, which are none of a sub-agent's business.
     */
    private const EXTRA_SECTION = 'Sub-agent extras';

    /** This lead's ad-hoc extra document definitions (not the files). */
    private function extraDefinitions(Lead $lead): array
    {
        $items = is_array($lead->custom_documents) ? $lead->custom_documents : [];

        return array_values(array_filter(
            $items,
            fn ($i) => ($i['section'] ?? null) === self::EXTRA_SECTION && ! empty($i['key'])
        ));
    }

    /**
     * Every document slot on this lead: the four required ones, then whatever
     * extras the sub-agent added. One shape for both, so the UI renders them
     * the same way and uploads take the same path.
     */
    private function docSlots(Lead $lead): array
    {
        $extras = $this->extraDefinitions($lead);
        $labels = self::DOC_TYPES + collect($extras)->mapWithKeys(fn ($i) => [$i['key'] => $i['name']])->all();

        $docs = LeadDocument::where('lead_id', $lead->id)
            ->whereIn('checklist_key', array_keys($labels))
            ->latest('id')->get()->keyBy('checklist_key');

        // Outstanding requests, so a slot can show "requested 3 days ago"
        // instead of silently offering a second identical request.
        $requests = LeadDocumentRequest::where('lead_id', $lead->id)
            ->whereIn('label', array_values($labels))
            ->latest('id')->get()->keyBy('label');

        $extraExpiry = collect($extras)->mapWithKeys(fn ($i) => [$i['key'] => $i['expires_at'] ?? null]);

        $slots = [];
        foreach ($labels as $key => $label) {
            $d = $docs->get($key);
            $required = isset(self::DOC_TYPES[$key]);
            // The passport's expiry is the one the lead record already holds;
            // an extra carries its own, captured when it was added.
            $expiry = $key === 'subagent_passport' ? $lead->passport_expiry : $extraExpiry->get($key);

            $slots[] = [
                'type' => $key,
                'label' => $label,
                'required' => $required,
                'file' => $d ? [
                    'id' => $d->id,
                    'name' => $d->original_name,
                    'uploaded_at' => $d->created_at,
                    'url' => "/portal/sub-agent/leads/{$lead->id}/documents/{$d->id}/download",
                ] : null,
                'expires_at' => $expiry ? Carbon::parse($expiry)->toDateString() : null,
                'requested_at' => $d ? null : $requests->get($label)?->requested_at,
            ];
        }

        return $slots;
    }

    /** JSON: every document slot for a lead, with the uploaded file (if any). */
    public function documents($id)
    {
        $lead = $this->findScoped($id);
        $slots = $this->docSlots($lead);
        $required = array_values(array_filter($slots, fn ($s) => $s['required']));

        return response()->json([
            'slots' => $slots,
            // The progress figure counts only the four required documents —
            // adding an extra should not make the lead look more complete.
            'filled' => collect($required)->whereNotNull('file')->count(),
            'total' => count($required),
            'presets' => array_values(config('sub_agent.extra_documents', [])),
        ]);
    }

    /**
     * Add an ad-hoc document slot beyond the four required ones, optionally
     * uploading the file in the same step. Stored per-lead in
     * `leads.custom_documents` (the same mechanism the admin Documents tab
     * uses), so no other lead sees it.
     */
    public function addCustomDocument(Request $request, $id)
    {
        $lead = $this->findScoped($id);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'expires_at' => 'nullable|date',
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
        ]);

        $name = trim($data['name']);

        // Re-use the slot if one with this name already exists, so adding
        // "Police certificate" twice does not produce two identical rows.
        $existing = collect($this->extraDefinitions($lead))
            ->first(fn ($i) => mb_strtolower($i['name']) === mb_strtolower($name));

        if ($existing) {
            $key = $existing['key'];
            $items = array_map(
                fn ($i) => ($i['key'] ?? null) === $key ? ['expires_at' => $data['expires_at'] ?? null] + $i : $i,
                is_array($lead->custom_documents) ? $lead->custom_documents : []
            );
        } else {
            $key = 'custom.'.\Illuminate\Support\Str::random(12);
            $items = is_array($lead->custom_documents) ? $lead->custom_documents : [];
            $items[] = [
                'key' => $key,
                'name' => $name,
                'section' => self::EXTRA_SECTION,
                'expires_at' => $data['expires_at'] ?? null,
                'created_at' => now()->toIso8601String(),
                'created_by' => Auth::id(),
            ];
        }

        $lead->custom_documents = $items;
        $lead->save();

        if ($request->hasFile('file')) {
            $this->putDocument($lead, $key, $request->file('file'));
        }

        return back()->with('success', "{$name} added to this lead.");
    }

    /**
     * Remove an ad-hoc slot. Any file already uploaded against it is kept but
     * detached — deleting a mistyped label should not destroy the applicant's
     * document along with it.
     */
    public function removeCustomDocument($id, $key)
    {
        $lead = $this->findScoped($id);

        abort_unless(collect($this->extraDefinitions($lead))->contains(fn ($i) => $i['key'] === $key), 404);

        $lead->custom_documents = array_values(array_filter(
            is_array($lead->custom_documents) ? $lead->custom_documents : [],
            fn ($i) => ($i['key'] ?? null) !== $key
        )) ?: null;
        $lead->save();

        LeadDocument::where('lead_id', $lead->id)->where('checklist_key', $key)->update(['checklist_key' => null]);

        return back()->with('success', 'Document removed from this lead.');
    }

    /**
     * Ask the applicant for one or more of the four documents. Multiple types
     * are deliberately sent as a SINGLE email naming all of them — chasing a
     * lead with four separate emails is what the cadence exists to avoid.
     */
    public function requestDocuments(Request $request, $id)
    {
        $lead = $this->findScoped($id);

        $allowed = $this->allowedDocKeys($lead);
        $data = $request->validate([
            'types' => ['required', 'array', 'min:1', 'max:'.count($allowed)],
            'types.*' => ['required', Rule::in($allowed)],
        ]);

        $names = collect($this->docSlots($lead))->pluck('label', 'type');
        $types = array_values(array_unique($data['types']));
        $labels = array_values(array_filter(array_map(fn ($t) => $names->get($t), $types)));

        try {
            foreach ($labels as $label) {
                LeadDocumentRequest::updateOrCreate(
                    ['lead_id' => $lead->id, 'label' => $label],
                    ['required' => true, 'requested_by' => Auth::id(), 'requested_at' => now()],
                );
            }

            // Same single-source rule the rest of the app uses: fire the
            // configured automation message first, and only fall back to the
            // built-in email when no client message is set up.
            $documentName = $this->joinLabels($labels);
            $firedClient = app(\App\Services\EmailAutomationService::class)
                ->fire('immigration.document.requested', $lead, ['document_name' => $documentName]);

            if (! $firedClient && ! empty($lead->email)) {
                app(\App\Services\CommunicationService::class)
                    ->sendTemplated('doc_request', $lead, ['document_name' => $documentName]);
            }
        } catch (\Throwable $e) {
            Log::error('Sub-agent document request failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not send the document request.']);
        }

        return back()->with('success', count($labels) === 1
            ? "{$labels[0]} requested from {$lead->first_name}."
            : count($labels)." documents requested from {$lead->first_name} in one email.");
    }

    /** "Passport, CV / Resume and Diploma" — names the request email's contents. */
    private function joinLabels(array $labels): string
    {
        if (count($labels) === 1) {
            return $labels[0];
        }
        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }

    /** Every checklist key this sub-agent may touch on this lead. */
    private function allowedDocKeys(Lead $lead): array
    {
        return array_merge(
            array_keys(self::DOC_TYPES),
            array_column($this->extraDefinitions($lead), 'key')
        );
    }

    /** Upload (or replace) one document slot for a scoped lead. */
    public function storeDocument(Request $request, $id)
    {
        $lead = $this->findScoped($id);

        $data = $request->validate([
            'type' => ['required', Rule::in($this->allowedDocKeys($lead))],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
        ]);

        $this->putDocument($lead, $data['type'], $request->file('file'));

        $label = self::DOC_TYPES[$data['type']]
            ?? collect($this->extraDefinitions($lead))->firstWhere('key', $data['type'])['name']
            ?? 'Document';

        return back()->with('success', $label.' uploaded.');
    }

    /** Store a file against one slot, replacing whatever was there before. */
    private function putDocument(Lead $lead, string $key, $file): void
    {
        // Replace any existing file for this slot (upload = latest wins).
        LeadDocument::where('lead_id', $lead->id)
            ->where('checklist_key', $key)
            ->get()
            ->each(function (LeadDocument $old) {
                if ($old->file_path && Storage::disk('local')->exists($old->file_path)) {
                    \App\Support\DocumentFiles::delete($old->file_path, 'local');
                }
                $old->delete();
            });

        $path = $file->store("lead-documents/{$lead->id}", 'local');
        LeadDocument::create([
            'lead_id' => $lead->id,
            'checklist_key' => $key,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_UPLOAD,
            'uploaded_by' => Auth::id(),
        ]);
    }

    /** Stream a scoped document from the private disk (never a public URL). */
    public function downloadDocument($id, $docId)
    {
        $lead = $this->findScoped($id);

        $doc = LeadDocument::where('lead_id', $lead->id)
            ->whereIn('checklist_key', $this->allowedDocKeys($lead))
            ->where('id', $docId)
            ->firstOrFail();

        abort_unless($doc->file_path && Storage::disk('local')->exists($doc->file_path), 404);

        return Storage::disk('local')->download($doc->file_path, $doc->original_name);
    }

    /** Set a lead's priority (Urgent/Normal/Low) — ownership re-checked. */
    public function updatePriority(Request $request, $id)
    {
        $lead = $this->findScoped($id);

        // Matches the general lead priority vocabulary (urgent | medium | low);
        // the UI labels "medium" as "Normal".
        $validated = $request->validate([
            'priority' => ['required', 'in:urgent,medium,low'],
        ]);

        $lead->priority = $validated['priority'];
        $lead->save();

        return back()->with('success', "Lead {$lead->lead_id} priority updated.");
    }

    /** Quick-add a referral lead under the parent agent (sales-style). */
    public function storeLead(Request $request)
    {
        $agentId = $this->parentAgentId();
        abort_if(! $agentId, 404);

        $validated = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:40',
        ]);

        // Force the parent agent as the recruiting agent — the new lead lands
        // in this sub-agent's scoped pipeline (agent_id = parent_agent_id).
        $validated['agent_id'] = $agentId;
        $this->createDashboardLead($validated);

        return back()->with('success', 'Lead added.');
    }

    // ─── Follow-ups ───────────────────────────────────────────────────────
    // The same `lead_tasks` rows the Task Board works with, presented as a
    // cadence instead of a board: overdue first, then today, then the rest of
    // the week. A sub-agent sees a follow-up only when it hangs off one of
    // their agent's leads, or when it is their own unlinked task.

    /** Base query for every follow-up this sub-agent may see. */
    private function scopedTasks()
    {
        $me = Auth::id();
        $leadIds = $this->parentAgentId() ? $this->scoped()->pluck('id') : collect();

        return LeadTask::with(['lead:id,lead_id,first_name,last_name,email,phone,status,residence_city,residence_country'])
            ->where(function ($q) use ($leadIds, $me) {
                $q->whereIn('lead_id', $leadIds)
                    ->orWhere(fn ($q2) => $q2->whereNull('lead_id')->where('created_by', $me));
            });
    }

    /** Resolve a follow-up this sub-agent may act on, or 404. */
    private function findScopedTask($id): LeadTask
    {
        return $this->scopedTasks()->where('lead_tasks.id', $id)->firstOrFail();
    }

    public function followUps()
    {
        $now = now();
        $todayEnd = $now->copy()->endOfDay();
        $weekEnd = $now->copy()->endOfWeek();

        $serialize = fn (LeadTask $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'note' => $t->note,
            'type' => $t->type,
            'priority' => $t->priority,
            'due_at' => $t->due_at,
            'completed' => (bool) $t->completed,
            'completed_at' => $t->completed_at,
            'snoozed_until' => $t->snoozed_until,
            'lead' => $t->lead ? [
                'id' => $t->lead->id,
                'lead_id' => $t->lead->lead_id,
                'name' => trim("{$t->lead->first_name} {$t->lead->last_name}") ?: 'Unknown',
                'email' => $t->lead->email,
                'phone' => $t->lead->phone,
                'status' => $t->lead->status,
                'location' => trim(implode(', ', array_filter([$t->lead->residence_city, $t->lead->residence_country]))) ?: null,
            ] : null,
        ];

        // A snoozed follow-up is parked: it drops out of the date buckets until
        // the snooze expires, rather than sitting in Overdue nagging.
        $notSnoozed = fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
        $open = fn () => $this->scopedTasks()->where('completed', false);

        return inertia('portal/sub-agent/FollowUps', [
            'portalBase' => '/portal/sub-agent',
            'overdue' => $open()->where($notSnoozed)->whereNotNull('due_at')->where('due_at', '<', $now)
                ->orderBy('due_at')->get()->map($serialize)->values(),
            'today' => $open()->where($notSnoozed)->whereBetween('due_at', [$now, $todayEnd])
                ->orderBy('due_at')->get()->map($serialize)->values(),
            'this_week' => $open()->where($notSnoozed)->whereBetween('due_at', [$todayEnd, $weekEnd])
                ->orderBy('due_at')->get()->map($serialize)->values(),
            'undated' => $open()->where($notSnoozed)->whereNull('due_at')
                ->orderByDesc('created_at')->limit(50)->get()->map($serialize)->values(),
            'snoozed' => $open()->where('snoozed_until', '>', $now)
                ->orderBy('snoozed_until')->get()->map($serialize)->values(),
            'recently_done' => $this->scopedTasks()->where('completed', true)
                ->orderByDesc('completed_at')->limit(20)->get()->map($serialize)->values(),
            // Leads the "New task" form may link to — the scoped set, nothing else.
            'leadOptions' => $this->parentAgentId()
                ? $this->scoped()->orderBy('first_name')->get(['id', 'lead_id', 'first_name', 'last_name'])
                    ->map(fn ($l) => [
                        'id' => $l->id,
                        'lead_id' => $l->lead_id,
                        'name' => trim("{$l->first_name} {$l->last_name}") ?: $l->lead_id,
                    ])->values()
                : collect(),
            'cadence' => $this->cadenceCopy(),
            'avgFirstContact' => $this->avgFirstContactDays(),
            'scripts' => config('sub_agent.scripts', []),
            'timezones' => $this->leadTimezones(),
        ]);
    }

    /** Create a follow-up. A linked lead is re-checked against the scope. */
    public function storeFollowUp(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'lead_id' => 'nullable|integer',
            'due_at' => 'required|date',
            'type' => ['nullable', Rule::in(LeadTask::TYPES)],
            'priority' => ['nullable', Rule::in(LeadTask::PRIORITIES)],
        ]);

        // A sub-agent may only attach a follow-up to their own agent's lead.
        if (! empty($data['lead_id'])) {
            $this->findScoped($data['lead_id']);
        }

        $task = LeadTask::create([
            'lead_id' => $data['lead_id'] ?: null,
            'created_by' => Auth::id(),
            'assignee_id' => Auth::id(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'],
            'type' => $data['type'] ?? 'follow_up',
            'priority' => $data['priority'] ?? 'normal',
            // The sub-agent portal sits under Sales, so its follow-ups belong
            // to the sales department and roll up on the Sales task board. The
            // `sub-agent` tag is what tells them apart there.
            'department' => 'sales',
            'tags' => ['sub-agent'],
            'status' => 'not_started',
        ]);

        return back()->with('success', "Follow-up \"{$task->title}\" added.");
    }

    /** Complete / reopen / reschedule / snooze one follow-up. */
    public function updateFollowUp(Request $request, $id)
    {
        $task = $this->findScopedTask($id);

        $data = $request->validate([
            'action' => ['required', 'in:complete,reopen,reschedule,snooze'],
            'due_at' => ['required_if:action,reschedule', 'nullable', 'date'],
            'snooze_days' => ['required_if:action,snooze', 'nullable', 'integer', 'min:1', 'max:90'],
        ]);

        switch ($data['action']) {
            case 'complete':
                $task->fill([
                    'completed' => true,
                    'completed_at' => now(),
                    'completed_by' => Auth::id(),
                    'status' => 'completed',
                    'snoozed_until' => null,
                ]);
                $message = 'Follow-up done.';
                break;

            case 'reopen':
                $task->fill(['completed' => false, 'completed_at' => null, 'completed_by' => null, 'status' => 'not_started']);
                $message = 'Follow-up reopened.';
                break;

            case 'reschedule':
                // Rescheduling clears any snooze — the new date IS the decision.
                $task->fill(['due_at' => $data['due_at'], 'snoozed_until' => null]);
                $message = 'Follow-up moved to '.Carbon::parse($data['due_at'])->format('j M').'.';
                break;

            default: // snooze
                $days = (int) $data['snooze_days'];
                $task->snoozed_until = now()->addDays($days)->startOfDay()->addHours(9);
                $message = "Snoozed for {$days} day".($days === 1 ? '' : 's').'.';
        }

        $task->save();

        return back()->with('success', $message);
    }

    /** The cadence rule sentence + its parts, filled from config/sub_agent.php. */
    private function cadenceCopy(): array
    {
        $offsets = array_values(config('sub_agent.cadence.offsets', [1, 3, 7, 14]));
        $attempts = (int) config('sub_agent.cadence.max_attempts', 4);

        // A first offset of a day or less reads better in hours than "day 1".
        $first = $offsets[0] <= 1 ? round($offsets[0] * 24).' h' : "day {$offsets[0]}";
        $rest = array_map(fn ($d) => "day {$d}", array_slice($offsets, 1));

        return [
            'offsets' => $offsets,
            'max_attempts' => $attempts,
            'first_label' => $first,
            'rest_labels' => $rest,
            'summary' => implode(' / ', array_merge([$first], $rest)),
            'rule' => strtr((string) config('sub_agent.cadence.rule'), [
                ':first' => $first,
                ':rest' => implode(', ', $rest),
                ':attempts' => (string) $attempts,
            ]),
        ];
    }

    /**
     * Average days between a referral arriving and its first logged call, over
     * the scoped leads that have been called at all. Null when none have — an
     * empty field is "unknown", not zero.
     */
    private function avgFirstContactDays(): ?int
    {
        if (! $this->parentAgentId()) {
            return null;
        }

        $gaps = $this->scoped()
            ->with(['notes' => fn ($q) => $q->where('kind', 'client_contact')->oldest()])
            ->get(['id', 'created_at'])
            ->map(function (Lead $l) {
                $first = $l->notes->first();

                return $first ? $l->created_at->diffInDays($first->created_at) : null;
            })
            ->filter(fn ($v) => $v !== null);

        return $gaps->isEmpty() ? null : (int) round($gaps->avg());
    }

    /**
     * The clocks worth having on screen: the office, plus one per country the
     * scoped leads actually live in. A country absent from the config map is
     * skipped rather than guessed at.
     */
    private function leadTimezones(): array
    {
        $office = (string) config('sub_agent.office_timezone', 'Pacific/Auckland');
        $map = config('sub_agent.timezones', []);

        $out = [['label' => 'Auckland', 'timezone' => $office, 'office' => true]];
        $seen = [$office => true];

        if (! $this->parentAgentId()) {
            return $out;
        }

        $this->scoped()
            ->whereNotNull('residence_country')
            ->get(['residence_city', 'residence_country'])
            ->each(function ($l) use (&$out, &$seen, $map) {
                $tz = $map[mb_strtolower(trim((string) $l->residence_country))] ?? null;
                if (! $tz || isset($seen[$tz])) {
                    return;
                }
                $seen[$tz] = true;
                $out[] = [
                    'label' => $l->residence_city ?: $l->residence_country,
                    'timezone' => $tz,
                    'office' => false,
                ];
            });

        return array_slice($out, 0, 7);
    }

    /**
     * Students, read-only — the parent agent's referrals who have since been
     * converted to a student, an English learner or an immigration case.
     *
     * Same shared Education screen, narrowed to `agent_id = parent_agent_id`,
     * which is the row filter every other read here uses. Writes are switched
     * off server-side (`readOnly` in the payload): a sub-agent follows their
     * agent's students through, they don't administer them.
     */
    public function students()
    {
        $agentId = $this->parentAgentId();

        return app(EducationController::class)->students(
            // No parent agent yet → no referrals, so match nothing rather than
            // fall through to an unscoped list.
            fn ($q) => $q->where('agent_id', $agentId ?: 0)
        );
    }

    public function profile()
    {
        $user = Auth::user();
        $agent = $user->parent_agent_id ? User::find($user->parent_agent_id) : null;

        return inertia('portal/sub-agent/Profile', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'location' => $user->location,
                'avatar_url' => $user->avatar_url,
            ],
            'agent' => $agent ? ['name' => $agent->name, 'referral_code' => $agent->referral_code] : null,
        ]);
    }
}
