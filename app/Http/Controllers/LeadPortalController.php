<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Event;
use App\Models\FacebookLiveSession;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Services\LeadPhaseService;
use App\Services\NewsFeedService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Client-facing Leads Portal — scoped to the lead-role user's own Lead
 * record. The portal middleware ('portal:lead') enforces role; this
 * controller enforces record-level scope (`$user->lead`).
 *
 * All public-facing data (events, news, Facebook Lives) is reused from the
 * same models the marketing site renders — leads see authoritative content
 * without us maintaining a parallel data set.
 */
class LeadPortalController extends Controller
{
    /** Full submissions timeline — every form the lead has signed against. */
    public function submissions()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Submissions', [
            'lead' => $this->leadPayload($lead),
            'submissions' => $this->submissionsTimeline($lead),
        ]);
    }

    /** Upcoming + past activities the lead can join. */
    public function activities()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Activities', [
            'lead' => $this->leadPayload($lead),
            'upcoming' => $this->upcomingEvents(20),
            'past' => $this->pastEvents(10),
            'registeredEventId' => $lead->event_id,
        ]);
    }

    /** Announcements page — Facebook Live sessions + auto-fetched migration news. */
    public function announcements()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Announcements', [
            'lead' => $this->leadPayload($lead),
            'facebookLives' => $this->facebookLives(8),
            'news' => NewsFeedService::latest(6),
        ]);
    }

    /** Vertical timeline of every engagement step + current expanded. */
    public function journey()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Journey', [
            'lead' => $this->leadPayload($lead),
            'roadmap' => LeadPhaseService::roadmap($lead->status),
            'currentPhase' => LeadPhaseService::phaseFor($lead->status),
            'preEngagement' => LeadPhaseService::isPreEngagement($lead->status),
            'submissions' => $this->submissionsTimeline($lead),
        ]);
    }

    /** Checklist view — uses the same document_checklist + section verifications. */
    public function checklist()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Checklist', [
            'lead' => $this->leadPayload($lead),
            'documentChecklist' => $lead->document_checklist ?? [],
            'sectionVerifications' => $lead->section_verifications ?? [],
            // Documents the adviser has shared with the client (RFI letters etc.).
            'sharedDocuments' => $this->sharedDocumentsFor($lead),
        ]);
    }

    /** INZ forms the case sent this client to fill — with a preview + fields. */
    public function visaForms()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $filler = app(\App\Services\Immigration\InzFormFiller::class);
        $context = \App\Services\Immigration\InzCaseContext::for($lead);

        $forms = \App\Models\CaseFormAssignment::where('lead_id', $lead->id)
            ->with(['form', 'version'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (\App\Models\CaseFormAssignment $a) => [
                'id' => $a->id,
                'code' => $a->form->code,
                'name' => $a->form->name,
                'version' => $a->version?->version_label,
                'status' => $a->status,
                'ready' => $a->version?->isReady() ?? false,
                'preview_url' => ($a->version && $a->version->isReady()) ? "/portal/lead/visa-forms/{$a->id}/preview" : null,
                'fields' => $a->version ? $filler->clientFields($a->version, $context, $a->field_values ?? []) : [],
                'submitted_at' => optional($a->submitted_at)->toIso8601String(),
            ]);

        return inertia('portal/lead/VisaForms', ['lead' => $this->leadPayload($lead), 'forms' => $forms]);
    }

    /** Client submits their filled answers for an assigned INZ form. */
    public function visaFormSubmit(\Illuminate\Http\Request $request, int $id)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $assignment = \App\Models\CaseFormAssignment::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();

        $data = $request->validate([
            'field_values' => 'present|array',
            'field_values.*' => 'nullable|string|max:2000',
        ]);

        $assignment->forceFill([
            'field_values' => $data['field_values'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ])->save();

        return back()->with('success', 'Submitted — your adviser will review it.');
    }

    /** Stream the official INZ PDF for an assigned form (preview, client-owned). */
    public function visaFormPreview(int $id)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $assignment = \App\Models\CaseFormAssignment::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();
        $version = $assignment->version;
        abort_unless($version && $version->isReady(), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($version->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$assignment->form->code.'.pdf"',
        ]);
    }

    /**
     * Forms hub — three cards, always shown: the client's Visa Assessment (for
     * the visa set on their case), their Free Assessment, and INZ Forms (which
     * only become available once an adviser generates them).
     */
    public function forms()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        [$type, $intake] = $this->leadIntake($lead);
        $cards = [];

        // 1. Visa Assessment — based on the visa set to the client. If they've
        //    taken it, open the editable form + VIF; if not, send them to the
        //    landing-page assessment form for their visa so they can complete it.
        $hasIntake = (bool) ($type && $intake);
        // Prefer the visa type staff set on the case (e.g. "Student Visa (Fee
        // Paying)") so the card never switches to the generic funnel label once
        // an assessment intake exists; fall back to the intake's generic label.
        $visaLabel = ($lead->inz_visa_type ?: null)
            ?? ($intake ? \App\Support\IntakeVisaTypeMap::label($intake::class) : null);
        $visaPct = $hasIntake ? \App\Support\AssessmentFormSchema::stats($intake, $type)['pct'] : null;
        $cards[] = [
            'key' => 'visa',
            'title' => $visaLabel ? "Visa Assessment — {$visaLabel}" : 'Visa Assessment',
            'subtitle' => $hasIntake
                ? 'Your visa information form and assessment answers. Edit anytime to keep it current.'
                : 'Complete the assessment form for your visa — it flows straight onto your case.',
            'available' => true,
            'status' => $hasIntake ? "{$visaPct}% complete" : 'Get started',
            'href' => $hasIntake
                ? '/portal/lead/visa-assessment'
                : ($this->assessmentFormUrlFor($lead) ?? '/portal/lead/visa-assessment'),
            'cta' => $hasIntake ? 'Open' : 'Take assessment',
        ];

        // 2. Free Assessment — the initial eligibility questionnaire. Always open.
        $hasFree = str_starts_with((string) $lead->lead_id, 'FA-')
            || in_array($lead->source, ['free-assessment', 'education-enrolment'], true)
            || ! empty($lead->immigration_info);
        $cards[] = [
            'key' => 'free',
            'title' => 'Free Assessment',
            'subtitle' => 'The initial questionnaire you completed to check your eligibility.',
            'available' => true,
            'status' => $hasFree ? 'Completed' : 'Not started',
            'href' => $hasFree ? "/assessment-result/{$lead->lead_id}" : '/free-assessment',
            'cta' => $hasFree ? 'View result' : 'Start',
        ];

        // 3. INZ Forms — only available once an adviser has generated them.
        $inzCount = \App\Models\CaseFormAssignment::where('lead_id', $lead->id)->count();
        $inzAvailable = $inzCount > 0;
        $cards[] = [
            'key' => 'inz',
            'title' => 'INZ Forms',
            'subtitle' => 'Official Immigration New Zealand forms prepared by your adviser.',
            'available' => $inzAvailable,
            'status' => $inzAvailable ? ($inzCount.' form'.($inzCount > 1 ? 's' : '').' ready') : 'Not available yet',
            'href' => $inzAvailable ? '/portal/lead/visa-forms' : null,
            'cta' => 'Open',
        ];

        return inertia('portal/lead/Forms', [
            'lead' => $this->leadPayload($lead),
            'cards' => $cards,
        ]);
    }

    /**
     * Visa Information Form — the client generates the official VIF from their
     * completed assessment; it then becomes downloadable here and satisfies the
     * VIF checklist item. The client never uploads it (it's produced, not provided).
     */
    public function visaAssessment()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        [$type, $intake] = $this->leadIntake($lead);
        $assessment = null;
        if ($type && $intake) {
            $assessment = [
                'type' => $type,
                'values' => $this->assessmentValues($intake, $type),
                'stats' => \App\Support\AssessmentFormSchema::stats($intake, $type),
                'save_url' => '/portal/lead/visa-assessment',
            ];
        }

        return inertia('portal/lead/VisaAssessment', [
            'lead' => $this->leadPayload($lead),
            'vif' => $this->leadVif($lead),
            // The client's editable assessment (null when the case has no intake).
            'assessment' => $assessment,
        ]);
    }

    /**
     * The public landing-page assessment form URL for the client's visa, keyed
     * off their VisaType category. Returns null when the visa can't be mapped
     * (caller falls back to the in-portal assessment page).
     */
    private function assessmentFormUrlFor(Lead $lead): ?string
    {
        if (! $lead->inz_visa_type) {
            return null;
        }
        $category = \App\Models\VisaType::where('name', $lead->inz_visa_type)->value('category');
        if (! $category) {
            return null;
        }

        return match (strtolower($category)) {
            'resident' => '/resident-interest',
            'work' => '/work-interest',
            'student' => '/student-interest',
            'visitor' => '/visitor-interest',
            'partnership', 'family' => '/family-interest',
            default => null,
        };
    }

    /** Resolve the lead's assessment intake (authoritative FK path). */
    private function leadIntake(Lead $lead)
    {
        if (! $lead->assessment_id) {
            return [null, null];
        }
        $assessment = \App\Models\Assessment::whereNotNull('intakeable_type')->find($lead->assessment_id);
        $intake = $assessment?->intakeable;
        $type = $intake ? \App\Support\AssessmentFormSchema::typeForClass($intake::class) : null;

        return [$type, $type ? $intake : null];
    }

    /** The intake's current values for the schema fields, shaped for form inputs. */
    private function assessmentValues($intake, string $type): array
    {
        $out = [];
        foreach (\App\Support\AssessmentFormSchema::fields($type) as $key) {
            if (! array_key_exists($key, $intake->getAttributes())) {
                continue;
            }
            $v = $intake->{$key};
            if ($v instanceof \DateTimeInterface) {
                $v = \Illuminate\Support\Carbon::parse($v)->toDateString();
            } elseif (is_bool($v)) {
                $v = $v ? 'Yes' : 'No';
            }
            $out[$key] = $v;
        }

        return $out;
    }

    /** VIF availability + generated state for the client portal. */
    private function leadVif(Lead $lead): array
    {
        [$type, $intake] = $this->leadIntake($lead);
        $doc = \App\Models\LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'vif')->latest()->first();

        // The finished VIF is a licensed deliverable — the client may generate it
        // from their assessment, but it is only DOWNLOADABLE once the adviser's
        // verification marks it Approved. Until then it sits with the adviser.
        $approved = $doc && $doc->status === \App\Models\LeadDocument::STATUS_APPROVED;

        return [
            'available' => $type !== null && $intake !== null, // has a work/student/visitor assessment
            'generated' => (bool) $doc,
            'generated_at' => optional($doc?->created_at)->toIso8601String(),
            'status' => $doc?->status,           // Submitted | Checked | Approved | Rejected
            'approved' => $approved,             // gate for the download button
            'download_url' => $approved ? '/portal/lead/vif' : null,
        ];
    }

    /** Client generates their official VIF from the assessment. */
    public function generateVif()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        [$type, $intake] = $this->leadIntake($lead);
        abort_unless($intake, 422, 'No completed assessment to generate a Visa Information Form from.');

        $this->buildAndStoreVif($lead, $type, $intake);

        return back()->with('success', 'Your Visa Information Form has been generated.');
    }

    /** Build the VIF PDF from an intake and store it as the lead's VIF document. */
    private function buildAndStoreVif(Lead $lead, string $type, $intake): void
    {
        // Same flexible builder the staff export uses — the VIF contains every
        // section the client actually filled, in the official format.
        $sections = app(\App\Http\Controllers\Portal\ImmigrationController::class)
            ->buildVifSections($type, $intake->toArray());
        $applicant = trim(($intake->first_name ?? '').' '.($intake->last_name ?? $intake->family_name ?? '')) ?: 'Applicant';

        $bytes = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.intake', [
            'applicant' => $applicant,
            'sections' => $sections,
            'intakeId' => $intake->intake_id ?? null,
            'generatedAt' => now()->format('d/m/Y'),
            'mode' => 'pdf',
        ])->setPaper('a4')->output();

        $name = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $applicant)) ?: 'Applicant';
        $path = "lead-documents/{$lead->id}/vif-".\Illuminate\Support\Str::uuid().'.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);

        // Replaces any prior VIF; checklist_key 'svf' satisfies the VIF checklist item.
        // The VIF is referred straight to the licensed adviser for verification —
        // it lands in the adviser's queue as 'Checked' (not a client "Submitted"
        // upload). Regenerating after an edit re-refers it, invalidating any prior
        // approval so the adviser re-checks the changed details. Clears the prior
        // reviewer stamp so a re-referred form doesn't look already-verified.
        \App\Models\LeadDocument::updateOrCreate(
            ['lead_id' => $lead->id, 'source_variant' => 'vif'],
            [
                'checklist_key' => 'svf',
                'original_name' => "{$name} VIF.pdf",
                'file_path' => $path,
                'mime' => 'application/pdf',
                'size' => strlen($bytes),
                'source' => 'generated',
                'status' => \App\Models\LeadDocument::STATUS_CHECKED,
                'reviewed_by' => null,
                'reviewed_at' => now(), // referral time — orders it into the queue
            ],
        );
    }

    /**
     * Client edits their assessment from the portal. Saves the allowed fields
     * back to the intake, regenerates the VIF (it's derived from the assessment),
     * and notifies all case staff. Only assessment fields are writable.
     */
    public function updateAssessment(\Illuminate\Http\Request $request)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        [$type, $intake] = $this->leadIntake($lead);
        abort_unless($type && $intake, 422, 'There is no assessment to edit on this case.');

        $data = $request->validate([
            'field_values' => 'present|array',
            'field_values.*' => 'nullable',
        ]);

        $allowed = array_flip(\App\Support\AssessmentFormSchema::fields($type));
        $fillable = array_flip($intake->getFillable());
        $casts = $intake->getCasts();

        foreach ($data['field_values'] as $key => $value) {
            // Only assessment fields the model itself allows may be written.
            if (! isset($allowed[$key]) || ! isset($fillable[$key])) {
                continue;
            }
            if ($value === '') {
                $value = null;
            }
            if (in_array($casts[$key] ?? null, ['bool', 'boolean'], true)) {
                $value = in_array(strtolower((string) $value), ['yes', '1', 'true', 'on'], true);
            }
            $intake->{$key} = $value;
        }
        $intake->save();

        // The VIF is derived from the assessment — keep it in lockstep.
        $this->buildAndStoreVif($lead, $type, $intake);

        // Notify all case staff that the client changed their assessment / VIF.
        try {
            $pct = \App\Support\AssessmentFormSchema::stats($intake, $type)['pct'];
            $recipients = \App\Models\User::whereIn('role', array_merge(['immigration'], \App\Models\User::IMMIGRATION_ROLES, ['admin', 'super_admin']))->get();
            if ($lead->immigration_assignee && ($owner = \App\Models\User::find($lead->immigration_assignee))) {
                $recipients = $recipients->push($owner);
            }
            $recipients = $recipients->unique('id')->values();
            if ($recipients->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send($recipients, new \App\Notifications\ClientUpdatedAssessment($lead, $pct));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Client assessment-update notify failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', 'Your assessment has been updated and your Visa Information Form regenerated.');
    }

    /** Stream the client's generated VIF. */
    public function downloadVif()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        $doc = \App\Models\LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'vif')->latest()->firstOrFail();
        // Strict gate: the VIF is released to the client only after the licensed
        // adviser has verified it (status Approved). A generated-but-unverified
        // form is never downloadable — it is still with the adviser for review.
        abort_unless($doc->status === \App\Models\LeadDocument::STATUS_APPROVED, 403, 'Your Visa Information Form is awaiting adviser approval.');
        abort_unless($doc->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->file_path), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($doc->file_path), [
            'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
        ]);
    }

    /** My Family — dependants the principal has included in their application. */
    public function family()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $dependents = \App\Models\CaseDependent::where('lead_id', $lead->id)
            ->with(['documents' => fn ($q) => $q->orderByDesc('created_at'), 'linkedLead', 'visaType'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (\App\Models\CaseDependent $d) => array_merge([
                'id' => $d->id,
                'relationship' => $d->relationship,
                'first_name' => $d->first_name,
                'family_name' => $d->family_name,
                'middle_name' => $d->middle_name,
                'full_name' => $d->fullName(),
                'dob' => optional($d->dob)->toDateString(),
                'gender' => $d->gender,
                'nationality' => $d->nationality,
                'passport_number' => $d->passport_number,
                'passport_expiry' => optional($d->passport_expiry)->toDateString(),
            ], $this->clientSafeChecklist($d->checklistData())));

        return inertia('portal/lead/Family', ['lead' => $this->leadPayload($lead), 'dependents' => $dependents]);
    }

    /**
     * Remap a dependant checklist's document statuses to their client-safe form
     * before it reaches the applicant — the manager's internal "Checked" shows
     * as "Under review" until the adviser Approves/Rejects.
     */
    private function clientSafeChecklist(array $data): array
    {
        foreach ($data['checklist'] ?? [] as &$item) {
            $item['status'] = \App\Models\LeadDocument::clientStatus($item['status'] ?? null);
            if (! empty($item['document']['status'])) {
                $item['document']['status'] = \App\Models\LeadDocument::clientStatus($item['document']['status']);
            }
        }
        unset($item);

        return $data;
    }

    private function familyRules(): array
    {
        return [
            'relationship' => ['required', \Illuminate\Validation\Rule::in(\App\Models\CaseDependent::RELATIONSHIPS)],
            'first_name' => 'nullable|string|max:120',
            'family_name' => 'nullable|string|max:120',
            'middle_name' => 'nullable|string|max:120',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'nationality' => 'nullable|string|max:100',
            'passport_number' => 'nullable|string|max:60',
            'passport_expiry' => 'nullable|date',
        ];
    }

    /** Principal adds a dependant — auto-linked to their own case. */
    public function familyStore(\Illuminate\Http\Request $request)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        \App\Models\CaseDependent::create(array_merge($request->validate($this->familyRules()), [
            'lead_id' => $lead->id,
            'source' => 'portal',
        ]));

        return back()->with('success', 'Family member added.');
    }

    public function familyUpdate(\Illuminate\Http\Request $request, int $id)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        $dep = \App\Models\CaseDependent::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();
        $dep->update($request->validate($this->familyRules()));

        return back()->with('success', 'Family member updated.');
    }

    public function familyDelete(int $id)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        $dep = \App\Models\CaseDependent::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();
        foreach ($dep->documents as $doc) {
            if ($doc->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->file_path)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->file_path);
            }
        }
        $dep->documents()->delete();
        $dep->delete();

        return back()->with('success', 'Family member removed.');
    }

    public function familyDocumentStore(\Illuminate\Http\Request $request, int $id)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        $dep = \App\Models\CaseDependent::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();

        // The main applicant manages every family member's documents from their
        // own portal — including members tied to their own case. For a linked
        // member the file attaches to THAT case (so it shows in the same
        // read-through view); otherwise it attaches to the dependant sub-record.
        $child = $dep->linked_lead_id ? \App\Models\Lead::find($dep->linked_lead_id) : null;
        $validKeys = $child
            ? collect(app(\App\Services\Immigration\CaseChecklistService::class)->withStatuses($child))->pluck('key')->filter()->all()
            : $dep->checklistKeys();

        $data = $request->validate([
            'file' => 'required|file|max:20480',
            'checklist_key' => ['nullable', 'string', \Illuminate\Validation\Rule::in($validKeys)],
        ]);

        $file = $request->file('file');
        $targetLeadId = $child?->id ?? $lead->id;
        $path = $file->store($child ? "lead-documents/{$child->id}" : "lead-documents/{$lead->id}/dependents/{$dep->id}", 'local');

        // Rename to the visa checklist's convention for a linked member (their
        // own case + checklist); a plain dependant sub-record has no case to
        // name against, so it keeps the client's own filename.
        $displayName = ($child && ! empty($data['checklist_key'])
            ? app(\App\Services\Immigration\CaseChecklistService::class)->uploadFileNameFor($child, $data['checklist_key'], $file->getClientOriginalName())
            : null) ?: $file->getClientOriginalName();

        \App\Models\LeadDocument::create([
            'lead_id' => $targetLeadId,
            'dependent_id' => $child ? null : $dep->id,
            'checklist_key' => $data['checklist_key'] ?? null,
            'original_name' => $displayName,
            'file_path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'source' => $child ? 'upload' : 'dependent',
            'status' => 'Submitted',
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function familyDocumentDelete(int $id, int $docId)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        $dep = \App\Models\CaseDependent::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();
        // The dependant's own doc, or — when tied to the child's case — a doc on
        // that case (but never a staff-generated artifact like an agreement/invoice).
        $doc = \App\Models\LeadDocument::where('id', $docId)
            ->where(function ($q) use ($dep) {
                $q->where('dependent_id', $dep->id);
                if ($dep->linked_lead_id) {
                    $q->orWhere(fn ($w) => $w->where('lead_id', $dep->linked_lead_id)->whereNotIn('source', ['generated', 'engagement']));
                }
            })->firstOrFail();
        if ($doc->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->file_path)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->file_path);
        }
        $doc->delete();

        return back()->with('success', 'Document removed.');
    }

    public function familyDocumentDownload(int $id, int $docId)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }
        $dep = \App\Models\CaseDependent::where('id', $id)->where('lead_id', $lead->id)->firstOrFail();
        // Documents attached directly to the dependant sub-record, OR — when the
        // dependant is tied to the child's own case — documents the child
        // submitted on that linked case (read-through, so the parent can view them).
        $doc = \App\Models\LeadDocument::where('id', $docId)
            ->where(function ($q) use ($dep) {
                $q->where('dependent_id', $dep->id);
                if ($dep->linked_lead_id) {
                    $q->orWhere('lead_id', $dep->linked_lead_id);
                }
            })->firstOrFail();
        abort_unless($doc->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->file_path), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($doc->file_path), [
            'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
        ]);
    }

    /**
     * Documents the adviser has SHARED with this client (RFI letters, outcome
     * letters, any staff→client share) — separate from the client's own upload
     * checklist. Surfaced on the dashboard + Documents page so the client can
     * view/download them. Respects the same tracker-visibility toggle staff use.
     */
    private function sharedDocumentsFor(Lead $lead): array
    {
        $hidden = is_array($lead->hidden_track_documents) ? $lead->hidden_track_documents : [];

        return $lead->documents()
            ->where('status', LeadDocument::STATUS_STAFF_SHARED)
            ->orderByDesc('created_at')
            ->get()
            ->reject(fn (LeadDocument $d) => $d->checklist_key && in_array($d->checklist_key, $hidden, true))
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'title' => $d->original_name,
                'size' => $d->size,
                'created_at' => $d->created_at?->toIso8601String(),
                'view_url' => "/portal/lead/documents/{$d->id}/download?inline=1",
                'download_url' => "/portal/lead/documents/{$d->id}/download",
            ])
            ->values()
            ->all();
    }

    /** Appointments — upcoming + past, derived from Bookings on email match. */
    public function appointments()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $bookings = Booking::where('email', $lead->email)->orderByDesc('appointment_date')->get();
        $now = now()->toDateString();

        return inertia('portal/lead/Appointments', [
            'lead' => $this->leadPayload($lead),
            'upcoming' => $bookings->filter(fn ($b) => $b->appointment_date && $b->appointment_date->toDateString() >= $now)->values()->map(fn ($b) => $this->bookingRow($b)),
            'past' => $bookings->filter(fn ($b) => ! $b->appointment_date || $b->appointment_date->toDateString() < $now)->values()->map(fn ($b) => $this->bookingRow($b)),
        ]);
    }

    public function proposals()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Proposals', ['lead' => $this->leadPayload($lead)]);
    }

    public function agreements()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Agreements', ['lead' => $this->leadPayload($lead)]);
    }

    public function payments()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Payments', ['lead' => $this->leadPayload($lead)]);
    }

    /**
     * Read-only history of emails the team has sent this lead. Scoped to the
     * caller's own record ($user->lead) — there is no id parameter, so a lead
     * can never reach another lead's messages. (Two-way replies land in
     * Build 14; SMS history is withheld here for now.)
     */
    public function messages()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $messages = \App\Models\MessageLog::where('recipient_type', 'lead')
            ->where('recipient_id', $lead->id)
            ->where('channel', \App\Models\MessageLog::CHANNEL_EMAIL)
            ->with('triggeredBy:id,name')
            ->latest()
            ->paginate(20)
            ->through(fn (\App\Models\MessageLog $m) => [
                'id' => $m->id,
                'subject' => $m->subject,
                'body' => $m->body,
                'from' => $m->triggeredBy?->name ?? 'EP',
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return inertia('portal/lead/Messages', [
            'lead' => $this->leadPayload($lead),
            'messages' => $messages,
        ]);
    }

    public function settings()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        return inertia('portal/lead/Settings', ['lead' => $this->leadPayload($lead)]);
    }

    /**
     * The lead portal is three tracker views, one per sidebar item:
     *   dashboard()    → Overview      (/portal/lead/dashboard)
     *   requirements() → checklist     (/portal/lead/requirements)
     *   profile()      → applicant     (/portal/lead/profile)
     * All reuse LeadTrackingController's payload builder so the client, staff
     * and public /track/{code} surfaces can never drift. tracker() is kept as a
     * back-compat alias that lands on the dashboard view.
     */
    /**
     * Client dashboard — a lively overview (not the raw tracker): journey
     * progress, at-a-glance analytics, the family/dependants' document status,
     * the next appointment, submissions, and news. The document *checklist*
     * detail lives on the Documents (tracker) page.
     */
    public function dashboard()
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $family = $this->familySummary($lead);
        $docSummary = $this->documentSummary($lead);
        $counts = $this->submissionsCounts($lead);
        $roadmap = LeadPhaseService::roadmap($lead->status);
        $currentPhase = LeadPhaseService::phaseFor($lead->status);

        $nextBooking = Booking::where('email', $lead->email)
            ->whereNotNull('appointment_date')
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->first();

        return inertia('portal/lead/Dashboard', [
            'lead' => $this->leadPayload($lead),
            'submissionsCounts' => $counts,
            'documentSummary' => $docSummary,
            // Documents the adviser has requested that the client still needs to
            // act on — surfaced on the dashboard so it's the first thing they see.
            'requestedDocuments' => $this->requestedDocumentsSummary($lead),
            // Documents the adviser has SHARED with the client (RFI letters,
            // outcome letters…) — viewable/downloadable straight from here.
            'sharedDocuments' => $this->sharedDocumentsFor($lead),
            'nextActivity' => $this->upcomingEvents(1)->first(),
            'latestAnnouncement' => $this->announcementFeed(1)->first(),
            'roadmap' => $roadmap,
            'currentPhase' => $currentPhase,
            'preEngagement' => LeadPhaseService::isPreEngagement($lead->status),
            'family' => $family,
            'nextAppointment' => $nextBooking ? $this->bookingRow($nextBooking) : null,
            'analytics' => $this->dashboardAnalytics($roadmap, $currentPhase, $docSummary, $family, $counts),
        ]);
    }

    /**
     * Compact per-dependant status for the dashboard "My family" band: name,
     * relationship, their document progress, and their uploaded photo (served
     * through the record-scoped portal route, never a public URL).
     */
    private function familySummary(Lead $lead): array
    {
        return \App\Models\CaseDependent::where('lead_id', $lead->id)
            ->with(['documents' => fn ($q) => $q->orderByDesc('created_at'), 'linkedLead', 'visaType'])
            ->orderBy('created_at')
            ->get()
            ->map(function (\App\Models\CaseDependent $d) {
                $data = $d->checklistData();
                $photo = collect($data['checklist'])
                    ->first(fn ($i) => str_ends_with((string) ($i['key'] ?? ''), 'face_image') && ! empty($i['document']));
                $p = $data['progress'];

                return [
                    'id' => $d->id,
                    'full_name' => $d->fullName(),
                    'relationship' => $d->relationship,
                    'photo_url' => $photo ? "/portal/lead/family/{$d->id}/documents/{$photo['document']['id']}" : null,
                    'progress' => $p,
                    'linked' => $data['linked'] ?? false,
                    'complete' => $p['required_total'] > 0 && $p['required_done'] >= $p['required_total'],
                ];
            })->all();
    }

    /** Derived headline numbers for the dashboard analytics band. */
    private function dashboardAnalytics(array $roadmap, ?array $currentPhase, array $docSummary, array $family, array $counts): array
    {
        $journeyTotal = count($roadmap);
        $journeyStep = $currentPhase ? ((int) $currentPhase['index'] + 1) : 0;

        $famReqTotal = collect($family)->sum(fn ($f) => $f['progress']['required_total']);
        $famReqDone = collect($family)->sum(fn ($f) => $f['progress']['required_done']);

        return [
            'journey_pct' => $journeyTotal ? (int) round($journeyStep / $journeyTotal * 100) : 0,
            'journey_step' => $journeyStep,
            'journey_total' => $journeyTotal,
            'docs_total' => $docSummary['total'],
            'docs_approved' => $docSummary['approved'],
            'docs_pending' => $docSummary['pending'],
            'family_count' => count($family),
            'family_docs_total' => $famReqTotal,
            'family_docs_done' => $famReqDone,
            'family_pct' => $famReqTotal ? (int) round($famReqDone / $famReqTotal * 100) : 0,
            'bookings' => $counts['bookings'],
        ];
    }

    public function requirements()
    {
        return $this->trackerView('visa');
    }

    public function profile()
    {
        return $this->trackerView('profile');
    }

    public function tracker()
    {
        return $this->trackerView('overview');
    }

    private function trackerView(string $tab)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $payload = app(LeadTrackingController::class)->buildTrackerPayload($lead, $lead->tracking_code);
        $payload['embedded'] = true;
        $payload['sidebarTabs'] = true;
        $payload['initialTab'] = $tab;

        // buildTrackerPayload builds shared-document URLs against the no-login
        // /track/{code} route. A signed-in client must go through their own
        // authenticated portal route instead — the /track route can 404 here
        // (tracker gating / no code context). Rewrite view + download links.
        $payload['shared_documents'] = collect($payload['shared_documents'] ?? [])
            ->map(function (array $d) {
                $d['view_url'] = "/portal/lead/documents/{$d['id']}/download?inline=1";
                $d['download_url'] = "/portal/lead/documents/{$d['id']}/download";

                return $d;
            })->all();

        return inertia('portal/lead/Tracker', $payload);
    }

    /**
     * Let a signed-in lead change their own portal password. Verifies the
     * current password (via the `current_password` rule against the active
     * guard) before applying the new one.
     */
    public function updatePassword(\Illuminate\Http\Request $request)
    {
        $lead = $this->resolveLeadOrLogout();
        if (! $lead instanceof Lead) {
            return $lead;
        }

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
        ]);

        $request->user()->update([
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
        ]);

        return back()->with('success', 'Your password has been updated.');
    }

    private function bookingRow(Booking $b): array
    {
        return [
            'id' => $b->id,
            'service_type' => $b->service_type,
            'consultant_name' => $b->consultant_name,
            'platform' => $b->platform,
            'status' => $b->status ?: 'Pending',
            'appointment_date' => $b->appointment_date ? \Illuminate\Support\Carbon::parse($b->appointment_date)->toDateString() : null,
            'appointment_time' => $b->appointment_time,
            'message' => $b->message,
            'created_at' => $b->created_at,
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** Defensive: a role='lead' user without a linked Lead is a broken state. */
    private function resolveLeadOrLogout()
    {
        $user = Auth::user();
        $lead = $user?->lead;

        if ($lead) {
            return $lead;
        }

        // Staff preview — an admin/super-admin can browse the client portal to see
        // its features, using a sample client as context. Read-only: all writes are
        // blocked by the BlockLeadPortalPreviewWrites middleware on this group.
        if ($user && $user->isAtLeast('admin') && ($preview = $this->previewLead())) {
            return $preview;
        }

        Auth::logout();

        return redirect('/login')->withErrors([
            'email' => 'Portal account is not linked to a lead record. Please contact EP.',
        ]);
    }

    /** Whether the current viewer is a staff member previewing the client portal. */
    private function isPreview(): bool
    {
        $user = Auth::user();

        return $user && ! $user->lead && $user->isAtLeast('admin');
    }

    /** The client whose portal a staff previewer is currently viewing. */
    private function previewLead(): ?Lead
    {
        $id = request('preview_lead') ?: session('lead_portal_preview_id');
        if ($id && ($lead = Lead::find($id))) {
            session(['lead_portal_preview_id' => $lead->id]);

            return $lead;
        }

        // Default to a client who actually has a portal account, then any case.
        $lead = Lead::whereHas('portalUser')->latest()->first()
            ?? Lead::where('is_immigration_case', true)->latest()->first()
            ?? Lead::latest()->first();

        if ($lead) {
            session(['lead_portal_preview_id' => $lead->id]);
        }

        return $lead;
    }

    private function leadPayload(Lead $lead): array
    {
        return [
            'lead_id' => $lead->lead_id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'residence_country' => $lead->residence_country,
            'stage' => $lead->stage,
            'status' => $lead->status,
            'created_at' => $lead->created_at,
        ];
    }

    /** Summary tile counts surfaced on the Dashboard. */
    private function submissionsCounts(Lead $lead): array
    {
        $assessmentSubmitted = str_starts_with((string) $lead->lead_id, 'FA-')
            || $lead->source === 'free-assessment';

        return [
            'assessment_submitted' => $assessmentSubmitted,
            'ai_status' => $lead->ai_analysis_status,
            'bookings' => Booking::where('email', $lead->email)->count(),
            'event_registered' => (bool) $lead->event_id,
        ];
    }

    private function documentSummary(Lead $lead): array
    {
        $docs = LeadDocument::where('lead_id', $lead->id)->get();

        return [
            'total' => $docs->count(),
            'pending' => $docs->where('status', 'Submitted')->count(),
            'approved' => $docs->where('status', 'Approved')->count(),
            'rejected' => $docs->where('status', 'Rejected')->count(),
        ];
    }

    /**
     * Adviser-requested documents the client still needs to act on — nothing
     * uploaded against the request yet, or the last upload was rejected. Drives
     * the dashboard "action needed" card; uploading happens on the Documents page.
     */
    private function requestedDocumentsSummary(Lead $lead): array
    {
        $requests = $lead->documentRequests()
            ->with('latestDocument')
            ->orderByDesc('created_at')
            ->get();

        $outstanding = $requests->filter(function (LeadDocumentRequest $r) {
            $doc = $r->latestDocument;

            return ! $doc || $doc->status === LeadDocument::STATUS_REJECTED;
        })->values();

        return [
            'total' => $requests->count(),
            'outstanding' => $outstanding->count(),
            'items' => $outstanding->take(6)->map(fn (LeadDocumentRequest $r) => [
                'id' => $r->id,
                'label' => $r->label,
                'requested_at' => optional($r->requested_at)->toIso8601String(),
                // null = not uploaded yet; 'Rejected' = adviser asked for a new file.
                'status' => $r->latestDocument?->status,
            ])->all(),
        ];
    }

    /** Full chronological history of the lead's interactions. */
    private function submissionsTimeline(Lead $lead): array
    {
        $items = collect();

        // Free Assessment — implied by lead_id prefix or source tag.
        if (str_starts_with((string) $lead->lead_id, 'FA-') || $lead->source === 'free-assessment') {
            $items->push([
                'type' => 'free_assessment',
                'title' => 'Free Assessment Submitted',
                'subtitle' => 'Eligibility profile · '.($lead->stage ?: 'Evaluation'),
                'status' => $lead->ai_analysis_status === 'completed' ? 'Reviewed' : 'In review',
                'status_tone' => $lead->ai_analysis_status === 'completed' ? 'success' : 'pending',
                'reference' => $lead->lead_id,
                'date' => optional($lead->created_at)->toIso8601String(),
                'cta_label' => $lead->ai_analysis_status === 'completed' ? 'View result' : null,
                'cta_href' => $lead->ai_analysis_status === 'completed'
                    ? route('assessment-result', ['lead_id' => $lead->lead_id])
                    : null,
            ]);
        }

        // Bookings — matched by email.
        Booking::where('email', $lead->email)
            ->orderByDesc('created_at')
            ->get()
            ->each(function (Booking $b) use ($items) {
                $items->push([
                    'type' => 'booking',
                    'title' => '1:1 Consultation Booked',
                    'subtitle' => trim(($b->service_type ?: 'Consultation').' · '.($b->consultant_name ?: 'Adviser TBD')),
                    'status' => $b->status ?: 'Pending',
                    'status_tone' => match ($b->status) {
                        'Confirmed' => 'success', 'Completed' => 'success',
                        'Cancelled' => 'muted',
                        default => 'pending',
                    },
                    'reference' => 'BK-'.$b->id,
                    'date' => optional($b->created_at)->toIso8601String(),
                    'detail' => $b->appointment_date
                        ? 'Appointment: '.\Illuminate\Support\Carbon::parse($b->appointment_date)->toFormattedDateString()
                            .($b->appointment_time ? ' · '.$b->appointment_time : '')
                        : null,
                ]);
            });

        // Event registration — leads.event_id link.
        if ($lead->event_id && $lead->event) {
            $event = $lead->event;
            $items->push([
                'type' => 'event_registration',
                'title' => 'Registered for '.$event->name,
                'subtitle' => trim(($event->type ?: 'Event').' · '.($event->mode ?: 'In-person')),
                'status' => 'Registered',
                'status_tone' => 'success',
                'reference' => $event->event_code,
                'date' => optional($lead->created_at)->toIso8601String(),
                'detail' => $event->date_from ? 'On '.$event->date_from->toFormattedDateString() : null,
            ]);
        }

        return $items
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    private function upcomingEvents(int $limit = 6)
    {
        return Event::where('status', 'active')
            ->whereDate('date_from', '>=', now()->toDateString())
            ->orderBy('date_from')
            ->limit($limit)
            ->get()
            ->map(fn (Event $e) => $this->eventPayload($e));
    }

    private function pastEvents(int $limit = 10)
    {
        return Event::whereDate('date_from', '<', now()->toDateString())
            ->orderByDesc('date_from')
            ->limit($limit)
            ->get()
            ->map(fn (Event $e) => $this->eventPayload($e));
    }

    private function eventPayload(Event $e): array
    {
        return [
            'id' => $e->id,
            'event_code' => $e->event_code,
            'name' => $e->name,
            'description' => $e->description,
            'type' => $e->type,
            'mode' => $e->mode,
            'date_from' => optional($e->date_from)->toIso8601String(),
            'date_to' => optional($e->date_to)->toIso8601String(),
            'banner_url' => $e->banner_image ? Storage::disk('public')->url($e->banner_image) : null,
            'register_href' => $e->registration_link ?: ($e->event_code ? "/register/{$e->event_code}" : null),
        ];
    }

    private function facebookLives(int $limit = 6)
    {
        return FacebookLiveSession::orderByDesc('session_date')
            ->limit($limit)
            ->get()
            ->map(fn (FacebookLiveSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'fb_link' => $s->fb_link,
                'image_url' => $s->image_url,
                'session_date' => optional($s->session_date)->toIso8601String(),
                'is_upcoming' => $s->session_date && $s->session_date->gte(now()->startOfDay()),
            ]);
    }

    /** Unified announcements feed used by the Dashboard teaser:
     *  Facebook Live sessions first (own content), then news headlines. */
    private function announcementFeed(int $limit)
    {
        $live = $this->facebookLives(2)->take(1)->map(fn ($s) => [
            'kind' => 'facebook_live',
            'title' => $s['title'],
            'subtitle' => $s['is_upcoming'] ? 'Upcoming Facebook Live' : 'Recent Facebook Live',
            'date' => $s['session_date'],
            'href' => $s['fb_link'] ?: '/portal/lead/announcements',
        ]);

        $news = collect(NewsFeedService::latest(1))->map(fn ($n) => [
            'kind' => 'news',
            'title' => $n['title'],
            'subtitle' => $n['source'] ?: 'NZ migration news',
            'date' => $n['published_at'],
            'href' => $n['link'],
        ]);

        return $live->concat($news)->take($limit);
    }
}
