<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use App\Notifications\DocumentSubmittedForReview;
use App\Notifications\LeadInfoUpdated;
use App\Support\UploadValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public lead-tracking + self-service endpoint. Drives the /track route
 * that the department pastes to clients — they enter no password, only
 * their tracking_code (which is itself the bearer credential).
 *
 * Three responsibilities:
 *   - show()         : render the tracking page with info, documents and timeline
 *   - update()       : let the lead edit a tightly-scoped allow-list of
 *                      personal fields directly from /track/{code}
 *   - uploadDoc()    : let the lead upload a supporting document
 *
 * Everything else (stage, conversion flags, internal notes, AI score,
 * passport scans, financial info) stays read-only or hidden.
 */
class LeadTrackingController extends Controller
{
    /**
     * Fields the lead is allowed to self-edit from the public page.
     * Anything outside this list is silently dropped on update().
     */
    private const EDITABLE = [
        'first_name', 'middle_name', 'last_name', 'other_names',
        'gender', 'marital_status', 'dob',
        'email', 'phone',
        'country_of_birth', 'place_of_birth', 'citizenship',
        'residence_city', 'residence_state', 'residence_country',
        'has_passport', 'passport_number', 'passport_expiry',
    ];

    public function show(Request $request, ?string $code = null)
    {
        $code = $code ?: $request->query('code');

        $payload = [
            'code' => $code,
            'lead' => null,
            'info' => null,
            'documents' => [],
            'agreements' => [],
            'timeline' => [],
            'visa' => null,
            'error' => null,
        ];

        if (! $code) {
            return inertia('track/TrackingPage', $payload);
        }

        $lead = $this->resolveLead($code);

        if (! $lead) {
            // An immigration case? Its client now uses the secure lead portal —
            // the public tracker is disabled for cases (Privacy Act). Point them
            // there rather than a blind "not found".
            $isCase = Lead::where('tracking_code', strtoupper(trim($code)))
                ->where('is_immigration_case', true)->exists();

            // Friendly "not found" — the same tracker shell with a message,
            // but a real 404 status so it isn't mistaken for a valid page.
            $payload['error'] = $isCase
                ? 'This application is now managed in your secure client portal. Please sign in to your EP client portal to view its progress and documents.'
                : 'We could not find an application with that tracking code. Please double-check it and try again.';

            return inertia('track/TrackingPage', $payload)->toResponse($request)->setStatusCode(404);
        }

        $this->recordVisit($request, $lead);

        return inertia('track/TrackingPage', $this->buildTrackerPayload($lead, $code));
    }

    /**
     * Assemble the full tracker payload for a resolved lead. Shared by the
     * public tracker (show) and the authenticated lead portal's Application
     * Tracker page, so both render identical content from one source.
     *
     * Deliberately does NOT record a visit — the caller decides whether a
     * view counts as a public tracker visit (the portal does not).
     */
    public function buildTrackerPayload(Lead $lead, ?string $code): array
    {
        return [
            'code' => $code,
            'error' => null,
            'lead' => $this->publicLeadShape($lead),
            'avatar' => $this->faceAvatar($lead),
            'info' => $this->editableInfo($lead),
            'documents' => $this->publicDocuments($lead),
            // Documents the adviser has generated/shared for the client to
            // download (engagement pack: agreement + IAA standards, etc.).
            'shared_documents' => $this->sharedDocuments($lead, $code),
            'agreements' => $this->publicAgreements($lead, $code),
            // Immigration cases get the 12-step "My Visa Application Journey"
            // roadmap; everyone else (general leads / education students)
            // keeps the high-level 7-step pipeline view.
            'timeline' => $lead->is_immigration_case
                ? $this->buildImmigrationJourney($lead)
                : $this->buildTimeline($lead),
            // Immigration cases get the visa-type-specific checklist.
            // General leads / students ALWAYS get the general Document Checklist
            // (the education intake documents), regardless of any inz_visa_type they may
            // carry — the registration form's "Visa applying for" is just an
            // interest field for staff and must not connect the tracker to an
            // immigration visa's checklist. Only a real immigration case
            // (is_immigration_case) resolves to the visa-specific list.
            'visa' => ($lead->is_immigration_case ? $this->resolveVisa($lead) : null)
                ?? $this->resolveGeneralChecklist($lead),
            // Staff-suggested program shortlist (from the Proposals tab on
            // the internal Proposal & Agreements page).
            'proposal' => $this->publicProposal($lead),
        ];
    }

    /**
     * Public shape of the staff-suggested program shortlist. Returns null
     * (so the tracker can bail cleanly) when the lead has no shortlist.
     * Program details are trimmed down to only what the tracker card needs.
     */
    private function publicProposal(Lead $lead): ?array
    {
        $ids = is_array($lead->proposed_program_ids) ? array_values(array_map('intval', $lead->proposed_program_ids)) : [];
        if (empty($ids)) {
            return null;
        }

        // A study proposal only reaches the client once it's been approved in
        // the Program Verification module. Pending/verified proposals stay
        // hidden; legacy proposals (no review record) remain live.
        if (! $lead->proposalIsLive()) {
            return null;
        }

        // Previous proposal versions (all but the newest, which is the active
        // one shown above). Shown read-only on the tracker as earlier options.
        $previousVersions = $lead->proposals->slice(1)->values();

        // Fetch every program referenced across the active + previous versions
        // in one query.
        $allIds = collect($ids);
        foreach ($previousVersions as $v) {
            $allIds = $allIds->merge(is_array($v->program_ids) ? $v->program_ids : []);
        }
        $allIds = $allIds->map(fn ($x) => (int) $x)->unique()->values();

        $programs = \App\Models\Program::whereIn('id', $allIds)
            ->get(['id', 'title', 'slug', 'level', 'category', 'industry', 'location', 'price_text', 'duration_months', 'intake_months', 'image'])
            ->keyBy('id');

        $mapOne = fn ($p) => [
            'id' => $p->id,
            'title' => $p->title,
            'slug' => $p->slug,
            'level' => $p->level,
            'category' => $p->category,
            'industry' => $p->industry,
            'location' => $p->location,
            'price_text' => $p->price_text,
            'duration_months' => $p->duration_months,
            'intake_months' => $p->intake_months,
            'image_url' => $p->image ? \Illuminate\Support\Facades\Storage::disk('public')->url($p->image) : null,
            'public_url' => '/program-details/'.($p->slug ?: $p->id),
        ];
        // Preserve staff-picked ordering exactly. Drop ids whose Program
        // vanished. Merge in the per-program "why this program" reason from the
        // supplied reasons map (keyed by program id).
        $mapIds = fn ($idList, $reasons = []) => collect($idList)
            ->map(fn ($id) => $programs->get((int) $id))
            ->filter()
            ->map(fn ($p) => array_merge($mapOne($p), [
                'reason' => trim((string) ($reasons[(string) $p->id] ?? ($reasons[$p->id] ?? ''))) ?: null,
            ]))
            ->values();

        $activeReasons = is_array($lead->proposed_program_reasons) ? $lead->proposed_program_reasons : [];

        return [
            'programs' => $mapIds($ids, $activeReasons),
            'preferred_program_id' => $lead->preferred_program_id,
            'chosen_at' => optional($lead->preferred_program_chosen_at)->toIso8601String(),
            // Earlier proposals, newest first — read-only history for the client.
            'previous' => $previousVersions->map(fn ($v) => [
                'id' => $v->id,
                'programs' => $mapIds($v->program_ids ?? [], is_array($v->reasons) ? $v->reasons : []),
                'selected_program_id' => $v->selected_program_id,
                'created_at' => optional($v->created_at)->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * Every program id staff have put in front of THIS lead — the active
     * shortlist plus every earlier proposal version. This is the row-level
     * filter behind programDetails(): a tracking code lets a client read the
     * recommendations made to them, not browse the program catalogue.
     */
    private function shortlistedProgramIds(Lead $lead): array
    {
        $ids = is_array($lead->proposed_program_ids) ? $lead->proposed_program_ids : [];

        foreach ($lead->proposals as $version) {
            $ids = array_merge($ids, is_array($version->program_ids) ? $version->program_ids : []);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Resolve the lead for the program modal on EITHER tracker surface.
     *
     * The public tracker excludes immigration cases by design (resolveLead()),
     * but those clients read the very same shortlist through the authenticated
     * lead portal, which reuses these /track/* endpoints. So a signed-in client
     * asking about their own tracking code is answered from their own record;
     * anonymous callers fall back to the public chokepoint unchanged.
     */
    private function resolveTrackerLead(string $code): ?Lead
    {
        $ownLead = auth()->user()?->lead;
        if ($ownLead && strcasecmp((string) $ownLead->tracking_code, trim($code)) === 0) {
            return $ownLead;
        }

        return $this->resolveLead($code);
    }

    /**
     * JSON: one shortlisted program in full, for the tracker's details modal.
     *
     * Unlike ProgramController::publicShow(), this deliberately does NOT
     * require `status === 'published'`. That check belongs to the marketing
     * site. A shortlisted program is a private recommendation an adviser made
     * to this specific client — whether it is also advertised on the public
     * website has no bearing on whether the client may read what they were
     * sent. Publication state itself is not exposed in the payload.
     */
    public function programDetails(string $code, int $program)
    {
        $lead = $this->resolveTrackerLead($code);
        abort_if(! $lead, 404);

        // Mirror the shortlist's own visibility rule: while a proposal is
        // awaiting verification the cards are hidden, so this must be too.
        abort_if(! $lead->proposalIsLive(), 404);
        abort_if(! in_array($program, $this->shortlistedProgramIds($lead), true), 404);

        $p = \App\Models\Program::with('school:id,name')->find($program);
        abort_if(! $p, 404);

        return response()->json(['program' => $p->detailPayload()]);
    }

    /**
     * Save edits the lead makes on the public tracking page. Only the
     * allow-listed fields are accepted; everything else is ignored.
     */
    public function update(Request $request, string $code)
    {
        $lead = $this->resolveLead($code);

        if (! $lead) {
            return back()->with('error', 'Tracking code not recognised.');
        }

        $validated = $request->validate([
            'first_name' => 'nullable|string|max:80',
            'middle_name' => 'nullable|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'other_names' => 'nullable|string|max:120',
            'gender' => 'nullable|string|max:30',
            'marital_status' => 'nullable|string|max:30',
            'dob' => 'nullable|date',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:40',
            'country_of_birth' => 'nullable|string|max:80',
            'place_of_birth' => 'nullable|string|max:120',
            'citizenship' => 'nullable|string|max:80',
            'residence_city' => 'nullable|string|max:80',
            'residence_state' => 'nullable|string|max:80',
            'residence_country' => 'nullable|string|max:80',
            'has_passport' => ['nullable', Rule::in(['Yes', 'No'])],
            'passport_number' => 'nullable|string|max:40',
            'passport_expiry' => 'nullable|date',
        ]);

        // The allow-list filter is redundant after the validator, but
        // belt-and-braces in case future validation rules drift.
        $filtered = array_intersect_key($validated, array_flip(self::EDITABLE));

        // Compute the change set on DECRYPTED values (encrypted casts would
        // otherwise always read as "dirty"). Sensitive fields are redacted —
        // we record THAT they changed, never the values.
        $sensitive = ['passport_number'];
        $changes = [];
        foreach ($filtered as $field => $new) {
            $old = $lead->{$field};
            if ((string) $old !== (string) $new) {
                $changes[$field] = in_array($field, $sensitive, true)
                    ? ['old' => '[redacted]', 'new' => '[updated]']
                    : ['old' => $old, 'new' => $new];
            }
        }

        // saveQuietly so the LogsActivity trait's null-actor auto-log doesn't
        // fire — we record a tailored entry with the tracking_code as actor.
        $lead->fill($filtered)->saveQuietly();

        if (! empty($changes)) {
            ActivityLog::record('lead.updated', [
                'actor_name' => $lead->tracking_code,
                'actor_role' => 'tracker',
                'portal' => 'public',
                'entity_type' => Lead::class,
                'entity_id' => $lead->id,
                'description' => 'Lead updated their information via the tracker',
                'properties' => [
                    'subject_type' => 'Lead',
                    'subject_id' => $lead->id,
                    'changes' => $changes,
                ],
            ]);
        }

        // Notify staff when a key identity/contact field changes.
        $keyFields = array_values(array_intersect(array_keys($changes), ['passport_number', 'phone', 'email']));
        if (! empty($keyFields)) {
            $recipients = $this->staffRecipients($lead);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new LeadInfoUpdated($lead, $keyFields));
            }
        }

        // Stay on the page the update came from (portal or public tracker).
        return back()->with('success', 'Your information has been updated.');
    }

    /**
     * Lead-side document upload. Reuses the existing LeadDocument model
     * so admin tooling treats these uploads identically to staff-shared
     * or portal-uploaded files.
     */
    public function uploadDoc(Request $request, string $code)
    {
        $lead = $this->resolveLead($code);

        if (! $lead) {
            return back()->with('error', 'Tracking code not recognised.');
        }

        // Accept a single `file` (legacy / one-off uploads) OR a `files[]`
        // array (multi-file batch). Collect into one list so the rest of the
        // method has a single code path. (We read the files directly rather
        // than mutating the request, since Laravel caches converted files.)
        $files = $request->hasFile('files')
            ? array_values($request->file('files'))
            : array_filter([$request->file('file')]);

        $request->validate([
            'checklist_key' => 'nullable|string|max:80',
            // Optional metadata captured at upload-time for passport docs so
            // the lead doesn't have to re-enter passport number / expiry in
            // a separate form. Both fields are nullable for non-passport
            // uploads and silently ignored if checklist_key !== 'passport'.
            'passport_number' => 'nullable|string|max:40',
            'passport_expiry' => 'nullable|date',
        ]);

        $checklistKey = $request->input('checklist_key');

        // Format is enforced per checklist item: every document must be a PDF,
        // except the Face Image, which must be a JPEG — keeps the case file
        // consistent for INZ lodgement.
        $isFaceImage = str_contains(strtolower((string) $checklistKey), 'face');
        $fileRule = $isFaceImage
            ? 'file|mimes:jpg,jpeg|max:'.UploadValidation::DOCUMENT_MAX_KB
            : 'file|mimes:pdf|max:'.UploadValidation::DOCUMENT_MAX_KB;

        // Validate the collected files — one OR many against a single
        // checklist item (a 6-month bank statement or multi-page scan often
        // arrives as several PDFs).
        Validator::make(['files' => $files], [
            'files' => 'required|array|min:1|max:10',
            'files.*' => $fileRule,
        ], [
            'files.*.mimes' => $isFaceImage
                ? 'Please upload the face image as a JPEG (.jpg) file.'
                : 'Please upload this document as a PDF (.pdf) file.',
        ])->validate();
        $lastDoc = null;
        $firstPath = null;
        $checklistSvc = app(\App\Services\Immigration\CaseChecklistService::class);

        foreach ($files as $file) {
            // Private disk: client uploads (passports, bank statements, academic
            // records) must never be world-readable. Served back to the client
            // through the code-gated streamUpload() route, never a public URL.
            $path = $file->store("lead-documents/{$lead->id}", 'local');
            $firstPath ??= $path;

            // Rename to the visa checklist's convention when the matched item
            // defines a file_code — the display name the client and staff see
            // (the on-disk path stays random; downloads use original_name).
            $displayName = ($checklistKey
                ? $checklistSvc->uploadFileNameFor($lead, $checklistKey, $file->getClientOriginalName())
                : null) ?: $file->getClientOriginalName();

            $lastDoc = LeadDocument::create([
                'lead_id' => $lead->id,
                'checklist_key' => $checklistKey,
                'original_name' => $displayName,
                'file_path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'status' => LeadDocument::STATUS_SUBMITTED,
                'source' => LeadDocument::SOURCE_UPLOAD,
            ]);
        }

        // One notification per upload batch, not per file.
        if ($lastDoc) {
            $this->notifyDocumentSubmitted($lead, $lastDoc, 'submitted');
        }

        // Passport upload: surface the entered passport metadata onto the
        // lead row itself so admin search / visa lodgement / immigration
        // workflows pick it up without anyone re-keying. We store the first
        // file's path so the passport scan is the authoritative source if
        // the staff need to verify the typed numbers.
        if ($checklistKey === 'passport' && $firstPath) {
            $patch = ['has_passport' => 'Yes', 'passport_path' => $firstPath];
            if ($request->filled('passport_number')) {
                $patch['passport_number'] = $request->input('passport_number');
            }
            if ($request->filled('passport_expiry')) {
                $patch['passport_expiry'] = $request->input('passport_expiry');
            }
            $lead->fill($patch)->save();
        }

        // Stay on the page the upload came from (the lead portal Documents view
        // or the public tracker) instead of always bouncing to /track/{code}.
        return back()->with('success', 'Your document has been uploaded.');
    }

    /**
     * Replace the file (and optionally the type) on a document the lead
     * already uploaded. Only allowed while the document is still in
     * Submitted state — once a staff member has reviewed it, the lead can
     * no longer self-edit. Avoids leads silently swapping out an approved
     * passport scan after the fact.
     */
    public function updateDoc(Request $request, string $code, int $docId)
    {
        $lead = $this->resolveLead($code);
        if (! $lead) {
            return back()->with('error', 'Tracking code not recognised.');
        }

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->first();

        if (! $doc) {
            return back()->with('error', 'Document not found.');
        }

        // Once staff has approved a doc it's locked; the lead may still fix a
        // pending / under-review / rejected one.
        if ($doc->status === LeadDocument::STATUS_APPROVED) {
            return back()->with('error', 'This document has been approved and can no longer be edited.');
        }

        $request->validate([
            'file' => 'nullable|'.UploadValidation::document(),
            'checklist_key' => 'nullable|string|max:80',
        ]);

        $replacedFile = false;
        if ($request->hasFile('file')) {
            // Best-effort: drop the old file so we don't accumulate
            // orphans. We swallow failures because the audit value of
            // keeping the LeadDocument row beats blocking the lead on a
            // disk hiccup.
            if ($doc->file_path) {
                $this->deleteUpload($doc->file_path);
            }
            $file = $request->file('file');
            $path = $file->store("lead-documents/{$lead->id}", 'local');

            // Rename to the visa checklist's convention (file_code) for the
            // matched item; fall back to the client's own filename.
            $key = $request->input('checklist_key', $doc->checklist_key);
            $displayName = ($key
                ? app(\App\Services\Immigration\CaseChecklistService::class)->uploadFileNameFor($lead, $key, $file->getClientOriginalName())
                : null) ?: $file->getClientOriginalName();

            $doc->fill([
                'original_name' => $displayName,
                'file_path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                // A replaced file goes back into the review queue.
                'status' => LeadDocument::STATUS_SUBMITTED,
            ]);
            $replacedFile = true;
        }

        if ($request->filled('checklist_key')) {
            $doc->checklist_key = $request->input('checklist_key');
        }

        $doc->save();

        if ($replacedFile) {
            $this->notifyDocumentSubmitted($lead, $doc, 'replaced');
        }

        return back()->with('success', 'Document updated.');
    }

    /**
     * Lead-side delete for a still-pending document. Same status guard as
     * updateDoc — once reviewed, only staff can remove it.
     */
    /**
     * Lead picks (or changes) one program from the staff-suggested
     * shortlist. Passing `program_id: null` clears the pick. The chosen
     * id must be one of the leads.proposed_program_ids values so the
     * lead can't post an arbitrary program.
     */
    public function chooseProgram(Request $request, string $code)
    {
        $lead = $this->resolveLead($code);
        if (! $lead) {
            return back()->with('error', 'Tracking code not recognised.');
        }

        $validated = $request->validate([
            'program_id' => 'nullable|integer',
        ]);

        $newId = $validated['program_id'] ?? null;
        $shortlist = is_array($lead->proposed_program_ids) ? $lead->proposed_program_ids : [];

        if ($newId !== null && ! in_array((int) $newId, array_map('intval', $shortlist), true)) {
            return back()->with('error', 'That program is not in your shortlist.');
        }

        $previousId = $lead->preferred_program_id;
        $lead->fill([
            'preferred_program_id' => $newId,
            'preferred_program_chosen_at' => $newId ? now() : null,
        ])->saveQuietly();

        if ((int) $previousId !== (int) $newId) {
            ActivityLog::record('lead.program_chosen', [
                'actor_name' => $lead->tracking_code,
                'actor_role' => 'tracker',
                'portal' => 'public',
                'entity_type' => Lead::class,
                'entity_id' => $lead->id,
                'description' => $newId
                    ? 'Lead chose a program from the shortlist'
                    : 'Lead cleared their program pick',
                'properties' => [
                    'subject_type' => 'Lead',
                    'subject_id' => $lead->id,
                    'from' => $previousId,
                    'to' => $newId,
                ],
            ]);

            // Choosing a program (not clearing) advances the pipeline to
            // "Program Selected" (non-regressing) and emails the lead the
            // program_selected template. Non-fatal on failure.
            if ($newId !== null) {
                $stages = Lead::STAGES;
                $curIdx = array_search($lead->status, $stages, true);
                $tgtIdx = array_search('Program Selected', $stages, true);
                if ($tgtIdx !== false && ($curIdx === false || $curIdx < $tgtIdx)) {
                    $lead->status = 'Program Selected';
                    $lead->save();
                }

                try {
                    $program = \App\Models\Program::find($newId);
                    $context = [
                        'program_name' => $program?->title ?? '',
                        'program_level' => $program && $program->level ? 'Level '.$program->level : '',
                        'program_location' => $program?->location ?? '',
                    ];

                    // Admin-configurable automation first (Admin → Email
                    // Automation → "Client chose a program"). It reports whether
                    // it actually reached the CLIENT, so the built-in
                    // program_selected template only goes out when no client
                    // message is configured — otherwise the applicant would get
                    // two emails for the same click.
                    $firedClient = app(\App\Services\EmailAutomationService::class)
                        ->fire('education.program.chosen', $lead->fresh(), $context);

                    if (! $firedClient) {
                        app(\App\Services\CommunicationService::class)
                            ->sendTemplated('program_selected', $lead, $context);
                    }
                } catch (\Throwable $e) {
                    Log::warning('program_selected email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return back()->with('success', $newId ? 'Your preferred program is saved.' : 'Program preference cleared.');
    }

    public function deleteDoc(Request $request, string $code, int $docId)
    {
        $lead = $this->resolveLead($code);
        if (! $lead) {
            return back()->with('error', 'Tracking code not recognised.');
        }

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->first();

        if (! $doc) {
            return back()->with('error', 'Document not found.');
        }

        // Approved docs are locked; the lead can still remove a pending /
        // under-review / rejected one.
        if ($doc->status === LeadDocument::STATUS_APPROVED) {
            return back()->with('error', 'This document has been approved and can no longer be deleted.');
        }

        if ($doc->file_path) {
            $this->deleteUpload($doc->file_path);
        }
        $doc->delete();

        return back()->with('success', 'Document removed.');
    }

    /**
     * Stream a client's OWN uploaded document (passport, bank statement,
     * academic record) back to them. Gated by the tracking code exactly like
     * downloadDoc(): the doc must belong to the lead the code resolves to, be
     * one of their own uploads, and not be hidden from the tracker.
     *
     * Files live on the private disk; the public-disk fallback covers the
     * brief window on production between deploying this change and running
     * `documents:privatize --purge`, while a few originals still sit in
     * public. Once purged, every file resolves on 'local'.
     */
    public function streamUpload(Request $request, string $code, $docId)
    {
        $lead = $this->resolveLead($code);
        abort_unless($lead, 404);

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->firstOrFail();

        // Only the client's own uploads stream here — shared/generated docs go
        // through downloadDoc(), which enforces its own rules.
        abort_unless($doc->source === LeadDocument::SOURCE_UPLOAD, 403);

        // Respect the staff tracker-visibility toggle.
        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);
        abort_if($doc->checklist_key && in_array($doc->checklist_key, $hidden, true), 404);

        $disk = $this->resolveUploadDisk($doc->file_path);
        abort_unless($disk, 404);

        // ?inline=1 → render in the browser (image avatar / gallery preview);
        // otherwise force a download.
        if ($request->boolean('inline')) {
            return response()->file(Storage::disk($disk)->path($doc->file_path), [
                'Content-Type' => $doc->mime ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
            ]);
        }

        return response()->download(Storage::disk($disk)->path($doc->file_path), $doc->original_name);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /**
     * Which disk currently holds an upload: private first, public as the
     * pre-migration fallback. Null if the file is on neither.
     */
    private function resolveUploadDisk(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('local')->exists($path) ? 'local'
            : (Storage::disk('public')->exists($path) ? 'public' : null);
    }

    /**
     * Best-effort delete of an upload from wherever it lives (private and, for
     * not-yet-migrated files, public). Swallows disk hiccups — keeping the
     * LeadDocument row consistent matters more than a stray orphan file.
     */
    private function deleteUpload(string $path): void
    {
        foreach (['local', 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            } catch (\Throwable $e) { /* noop */
            }
        }
    }

    /**
     * Notify staff that a lead submitted (or replaced) a document. Goes to
     * the lead's assigned staff member; if none is assigned, falls back to
     * admin-tier users so a submission is never dropped on the floor. The
     * lead is never notified here.
     */
    private function notifyDocumentSubmitted(Lead $lead, LeadDocument $document, string $context): void
    {
        $recipients = $this->staffRecipients($lead);
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new DocumentSubmittedForReview($lead, $document, $context));
    }

    /**
     * Who hears about a lead's tracker activity: their assigned staff
     * member, or admin-tier users as a fallback when unassigned.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function staffRecipients(Lead $lead): \Illuminate\Support\Collection
    {
        $recipients = $lead->assignee
            ? collect([$lead->assignee])
            : User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->get();

        // Immigration cases always loop in the immigration team — even for
        // public tracker uploads — so the department hears about activity on
        // their own cases regardless of who (if anyone) is assigned.
        if ($lead->is_immigration_case) {
            $immigration = User::whereIn('role', array_merge(['immigration'], User::IMMIGRATION_ROLES))->get();
            $recipients = $recipients->merge($immigration)->unique('id')->values();
        }

        return $recipients;
    }

    private function resolveLead(string $code): ?Lead
    {
        // Immigration CASES are excluded from the PUBLIC tracker for privacy —
        // their clients use the authenticated lead portal (/portal/lead), which
        // builds the tracker via buildTrackerPayload() directly and never calls
        // this method. This single chokepoint disables the whole public surface
        // (view + uploads + edits + program choice + file streaming) for cases.
        return Lead::where('tracking_code', strtoupper(trim($code)))
            ->where(function ($q) {
                $q->where('is_immigration_case', false)->orWhereNull('is_immigration_case');
            })
            ->first();
    }

    /**
     * Stamp last_seen_at on every visit and write a debounced activity-log
     * entry (at most once per 15 min) so the audit trail doesn't flood on a
     * page that polls/refreshes. The tracking_code is the "actor" since
     * there's no logged-in user; no sensitive lead data is logged.
     */
    private function recordVisit(Request $request, Lead $lead): void
    {
        $shouldLog = is_null($lead->last_seen_at)
            || $lead->last_seen_at->lt(now()->subMinutes(15));

        // saveQuietly so the last_seen_at write doesn't itself spam the
        // LogsActivity 'lead.updated' trail.
        $lead->forceFill(['last_seen_at' => now()])->saveQuietly();

        if (! $shouldLog) {
            return;
        }

        ActivityLog::record('lead.tracker_view', [
            'actor_name' => $lead->tracking_code,
            'actor_role' => 'tracker',
            'portal' => 'public',
            'entity_type' => Lead::class,
            'entity_id' => $lead->id,
            'description' => "Tracker viewed for {$lead->tracking_code}",
            'metadata' => [
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ],
        ]);
    }

    private function publicLeadShape(Lead $lead): array
    {
        return [
            'tracking_code' => $lead->tracking_code,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'stage' => $lead->stage,
            'created_at' => optional($lead->created_at)?->toIso8601String(),
            'is_student' => (bool) $lead->is_student,
            'is_immigration_case' => (bool) $lead->is_immigration_case,
            'is_accommodation_client' => (bool) $lead->is_accommodation_client,
            // Drives the "Start Free Assessment" CTA on the tracker.
            // `has_free_assessment` is the reliable signal — the lead has
            // actually submitted the free-assessment form (which creates
            // educationExps / studyPlans rows). `ai_analysis_status` alone
            // is unreliable: manually-added leads can still carry a default
            // status value even though no assessment was submitted.
            'ai_analysis_status' => $lead->ai_analysis_status,
            'has_free_assessment' => $lead->educationExps()->exists()
                || $lead->studyPlans()->exists(),
        ];
    }

    /**
     * Editable fields pre-filled for the form. Mirrors self::EDITABLE so
     * we always serialise the same shape the frontend expects.
     */
    private function editableInfo(Lead $lead): array
    {
        return collect(self::EDITABLE)
            ->mapWithKeys(fn ($key) => [$key => $lead->{$key}])
            ->toArray();
    }

    /**
     * The applicant's uploaded Face image, surfaced as the tracker profile
     * picture. Latest non-rejected face-image upload wins.
     */
    private function faceAvatar(Lead $lead): ?array
    {
        $doc = $lead->documents()
            ->whereNotNull('checklist_key')
            ->where('checklist_key', 'like', '%face%')
            ->where('status', '!=', LeadDocument::STATUS_REJECTED)
            ->orderByDesc('created_at')
            ->first();

        if (! $doc || ! $doc->file_path || ! str_starts_with((string) $doc->mime, 'image/')) {
            return null;
        }

        return [
            // Code-gated stream, not a public URL — the file lives on the
            // private disk. ?inline=1 renders it as the avatar image.
            'url' => "/track/{$lead->tracking_code}/uploads/{$doc->id}?inline=1",
            'document_id' => $doc->id,
            'checklist_key' => $doc->checklist_key,
        ];
    }

    private function publicDocuments(Lead $lead): array
    {
        // Files uploaded against a checklist item staff have hidden from the
        // tracker are excluded here too, so hiding an item also hides any
        // file already attached to it.
        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);

        return $lead->documents()
            ->orderByDesc('created_at')
            ->get()
            // Only the client's own uploads belong in this gallery. Staff
            // generated / shared documents surface separately (and are
            // stored privately, so a public URL wouldn't resolve anyway).
            ->filter(fn (LeadDocument $d) => $d->source === LeadDocument::SOURCE_UPLOAD)
            ->reject(fn (LeadDocument $d) => $d->checklist_key && in_array($d->checklist_key, $hidden, true))
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'original_name' => $d->original_name,
                'checklist_key' => $d->checklist_key,
                'size' => $d->size,
                'mime' => $d->mime,
                // Client-safe: the manager's internal "Checked" reads as "Under
                // review" until the adviser makes the final Approve/Reject call.
                'status' => LeadDocument::clientStatus($d->status),
                'source' => $d->source,
                'created_at' => $d->created_at?->toIso8601String(),
                // Code-gated private stream (drives the gallery thumbnail
                // click + inline image preview). ?inline=1 renders in-browser;
                // the client's own tracking code is the bearer credential.
                'url' => $d->file_path ? "/track/{$lead->tracking_code}/uploads/{$d->id}?inline=1" : null,
                'is_image' => str_starts_with((string) $d->mime, 'image/'),
                // Lead can only edit / delete while the doc is still
                // pending review. Once a staff member touches it the
                // controller rejects the change anyway, but we surface the
                // flag so the UI can hide the action buttons cleanly.
                'is_editable' => $d->status === LeadDocument::STATUS_SUBMITTED,
            ])
            ->values()
            ->all();
    }

    /**
     * Documents the adviser has produced FOR the client to download —
     * generated engagement documents (Written Agreement + IAA standards)
     * and any staff-shared files. Stored on the private disk, so downloads
     * stream through a code-gated controller route, never a public URL.
     */
    private function sharedDocuments(Lead $lead, string $code): array
    {
        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);
        $labels = \App\Services\Immigration\EngagementDocumentGenerator::DOCS;

        return $lead->documents()
            ->where(function ($q) {
                $q->where('source', LeadDocument::SOURCE_GENERATED)
                    ->orWhere('status', LeadDocument::STATUS_STAFF_SHARED);
            })
            ->orderByDesc('created_at')
            ->get()
            ->reject(fn (LeadDocument $d) => $d->checklist_key && in_array($d->checklist_key, $hidden, true))
            ->map(function (LeadDocument $d) use ($code, $labels) {
                // Prefer a friendly label for engagement docs (engagement:<type>).
                $type = str_starts_with((string) $d->source_variant, 'engagement:')
                    ? str_replace('engagement:', '', $d->source_variant)
                    : null;

                // Every client-facing agreement is signable: immigration
                // Written Agreement + the sales-side Consultancy /
                // English Engagement PDFs.
                $isEngagementWA = $type === 'written_agreement';
                $isConsultancy = $d->checklist_key === 'agree.consultancy';
                $isEnglishEng = $d->checklist_key === 'agree.engagement_english';
                $isSignable = $isEngagementWA || $isConsultancy || $isEnglishEng;

                // Friendly titles for the sales-side docs so the tracker
                // doesn't just show "CA-Ravi-Std150-Single.pdf".
                $title = $type && isset($labels[$type])
                    ? $labels[$type]['label']
                    : ($isConsultancy ? 'Consultancy Agreement'
                        : ($isEnglishEng ? 'English Engagement Agreement'
                            : $d->original_name));

                $signed = (bool) $d->client_signed_at;

                return [
                    'id' => $d->id,
                    'title' => $title,
                    'original_name' => $d->original_name,
                    'size' => $d->size,
                    'created_at' => $d->created_at?->toIso8601String(),
                    'view_url' => "/track/{$code}/documents/{$d->id}/download?inline=1",
                    'download_url' => "/track/{$code}/documents/{$d->id}/download",
                    // Live HTML preview for the signing modal — the drawn
                    // signature streams into it via postMessage.
                    'preview_url' => $isSignable ? "/track/{$code}/documents/{$d->id}/preview" : null,
                    'signable' => $isSignable,
                    'signed' => $signed,
                    'signed_at' => $d->client_signed_at?->toIso8601String(),
                    'signer_name' => $d->client_signer_name,
                    'sign_url' => $isSignable ? "/track/{$code}/documents/{$d->id}/sign" : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Stream a staff-shared / generated document to the client. Gated by
     * the tracking code (the bearer credential): the document must belong
     * to the lead the code resolves to, be a shared/generated doc, and not
     * be hidden from the tracker.
     */
    public function downloadDoc(Request $request, string $code, $docId)
    {
        $lead = $this->resolveLead($code);
        abort_unless($lead, 404);

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->firstOrFail();

        // Only adviser-produced documents are downloadable here.
        $isShared = $doc->source === LeadDocument::SOURCE_GENERATED
            || $doc->status === LeadDocument::STATUS_STAFF_SHARED;
        abort_unless($isShared, 403);

        // Respect the same tracker-visibility toggle staff use elsewhere.
        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);
        abort_if($doc->checklist_key && in_array($doc->checklist_key, $hidden, true), 404);

        abort_unless($doc->file_path && Storage::disk('local')->exists($doc->file_path), 404);

        // ?inline=1 → render in the browser (View); otherwise force download.
        if ($request->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($doc->file_path), [
                'Content-Type' => $doc->mime ?: 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
            ]);
        }

        return response()->download(
            Storage::disk('local')->path($doc->file_path),
            $doc->original_name
        );
    }

    /**
     * Live HTML preview of a generated engagement document for the signing
     * modal. The applicant signature slot is a live element the sign modal
     * streams the drawn signature into (postMessage), so the client sees
     * their signature land on the document in real time.
     */
    public function previewDoc(Request $request, \App\Services\Immigration\EngagementDocumentGenerator $generator, string $code, $docId)
    {
        $lead = $this->resolveLead($code);
        abort_unless($lead, 404);

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->firstOrFail();

        // Any stored client signature — passed to the generator so the
        // preview already shows what's been signed.
        $clientSig = null;
        if ($doc->client_signature_path && Storage::disk('local')->exists($doc->client_signature_path)) {
            $ext = strtolower(pathinfo($doc->client_signature_path, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
            $clientSig = "data:{$mime};base64,".base64_encode(Storage::disk('local')->get($doc->client_signature_path));
        }

        // Route to the right renderer per doc family. Engagement uses its
        // dedicated generator; consultancy + english engagement render
        // straight from the Blade view (via previewDocument-style path).
        if (str_starts_with((string) $doc->source_variant, 'engagement:')) {
            $type = str_replace('engagement:', '', $doc->source_variant);
            $overrides = ['signer_id' => $doc->engagement_signer_id];
            if ($clientSig) {
                $overrides['client_signature'] = $clientSig;
            }
            $html = $generator->renderHtml($lead, $type, $overrides);

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        }

        if ($doc->checklist_key === 'agree.consultancy') {
            [$view, $payload] = $this->consultancyPreviewFor($doc, $lead, $clientSig);

            return response(view($view, $payload)->render())
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        if ($doc->checklist_key === 'agree.engagement_english') {
            $payload = $this->englishEngagementPreviewFor($doc, $lead, $clientSig);

            return response(view('agreements.engagement-english', $payload)->render())
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        abort(403);
    }

    /**
     * Rebuild the consultancy payload from the stored source_variant so
     * the tracker preview + re-sign renders exactly what was generated.
     * source_variant format: "consultancy:{scenario_key}:{applicant_mode}".
     */
    private function consultancyPreviewFor(LeadDocument $doc, Lead $lead, ?string $clientSig): array
    {
        $parts = explode(':', (string) $doc->source_variant);
        $scenario = $parts[1] ?? 'std_100';
        $mode = ($parts[2] ?? 'single') === 'couple' ? 'couple' : 'single';
        $gen = app(\App\Services\AgreementGenerator::class);

        $signerOpt = $doc->engagement_signer_id ? ['signer_id' => $doc->engagement_signer_id] : [];

        // Onshore engagement (free) + offshore single-package each have their
        // own blade; the standard scenarios share agreements.consultancy.
        if ($scenario === 'onshore') {
            $payload = $gen->buildOnshoreEngagementPayload($lead, $signerOpt);
            $view = 'agreements.onshore-engagement';
        } elseif ($scenario === 'offshore') {
            $payload = $gen->buildOffshorePayload($lead, $signerOpt);
            $view = 'agreements.consultancy-offshore';
        } else {
            [$payload] = $gen->buildConsultancyPayload($lead, $scenario, array_merge($signerOpt, ['applicant_mode' => $mode]));
            $view = 'agreements.consultancy';
        }
        $payload['preview'] = true;
        $payload['client_signature'] = $clientSig;

        return [$view, $payload];
    }

    private function englishEngagementPreviewFor(LeadDocument $doc, Lead $lead, ?string $clientSig): array
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $signer = $doc->engagement_signer_id
            ? \App\Models\User::find($doc->engagement_signer_id)
            : \Illuminate\Support\Facades\Auth::user();

        return [
            'client_name' => $clientName,
            'client_reference' => Str::slug($clientName ?: 'ClientName', '') ?: 'ClientName',
            'signer_name' => $signer?->name ?: 'Neil Bryan Escaner',
            'signer_mobile' => $signer?->phone ?: '+63945 107 6871',
            'signer_signature' => method_exists($signer, 'signatureDataUriTrimmed')
                ? $signer->signatureDataUriTrimmed()
                : null,
            'client_signature' => $clientSig,
            'generated_at' => $doc->created_at ?? now(),
            'generated_at_formatted' => ($doc->created_at ?? now())->format('jS').' day of '.($doc->created_at ?? now())->format('F Y'),
        ];
    }

    /**
     * Client e-signs the Written Agreement on the tracker. Accepts a drawn
     * canvas PNG (base64) or an uploaded image, stores it privately, then
     * re-renders the PDF with the applicant signature embedded (keeping the
     * adviser signature that was used at generation).
     */
    public function signDoc(Request $request, \App\Services\Immigration\EngagementDocumentGenerator $generator, string $code, $docId)
    {
        $lead = $this->resolveLead($code);
        abort_unless($lead, 404);

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->firstOrFail();

        $isEngagementWA = $doc->source_variant === 'engagement:written_agreement';
        $isConsultancy = $doc->checklist_key === 'agree.consultancy';
        $isEnglishEng = $doc->checklist_key === 'agree.engagement_english';
        abort_unless($isEngagementWA || $isConsultancy || $isEnglishEng, 403);

        $request->validate([
            'signature_image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'signature_data' => 'nullable|string',
            'signer_name' => 'nullable|string|max:120',
            'acknowledged' => 'nullable|boolean',
        ]);

        // Terms ack is required for the sales-side agreements (consultancy
        // + english engagement); the immigration Written Agreement has no
        // ack box, so we don't gate it there.
        $acknowledged = (bool) $request->input('acknowledged');
        if (($isConsultancy || $isEnglishEng) && ! $acknowledged) {
            return back()->withErrors(['error' => 'Please tick "I have read and agreed to the terms" before signing.']);
        }

        try {
            [$binary, $ext] = $this->readSignatureUpload($request);
            abort_if($binary === null, 422, 'No signature provided.');

            // Store the applicant signature privately.
            if ($doc->client_signature_path) {
                Storage::disk('local')->delete($doc->client_signature_path);
            }
            $sigPath = "signatures/client-doc-{$doc->id}-".Str::random(8).".{$ext}";
            Storage::disk('local')->put($sigPath, $binary);

            $clientSig = 'data:image/'.($ext === 'jpeg' ? 'jpeg' : 'png').';base64,'.base64_encode($binary);

            // Re-render the same document family with the client sig baked in.
            if ($isEngagementWA) {
                $pdf = $generator->pdfBinary($lead, 'written_agreement', [
                    'signer_id' => $doc->engagement_signer_id,
                    'client_signature' => $clientSig,
                ]);
            } elseif ($isConsultancy) {
                $parts = explode(':', (string) $doc->source_variant);
                $scenario = $parts[1] ?? 'std_100';
                $mode = ($parts[2] ?? 'single') === 'couple' ? 'couple' : 'single';
                $gen = app(\App\Services\AgreementGenerator::class);
                // Carry the reviewer-confirmed fees (+ bank/currency) so signing
                // re-renders with the SAME amount the agreement was approved at —
                // not the generator's default. Without this, signing baked in the
                // default fee and the signed PDF drifted from the saved amount.
                $reviewOv = \App\Services\ConsultancyReviewService::overridesForGeneration(
                    is_array($lead->consultancy_review) ? $lead->consultancy_review : []
                );
                $opts = array_merge($reviewOv, array_filter(['signer_id' => $doc->engagement_signer_id]));

                if ($scenario === 'onshore') {
                    // Onshore engagement (free) — its own blade.
                    $payload = $gen->buildOnshoreEngagementPayload($lead, $opts);
                    $payload['client_signature'] = $clientSig;
                    $payload['acknowledged'] = $acknowledged;
                    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('agreements.onshore-engagement', $payload)
                        ->setPaper('a4')->setOption('isPhpEnabled', true)->output();
                } elseif ($scenario === 'offshore') {
                    // Offshore single-package consultancy — its own blade.
                    $payload = $gen->buildOffshorePayload($lead, $opts);
                    $payload['client_signature'] = $clientSig;
                    $payload['acknowledged'] = $acknowledged;
                    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('agreements.consultancy-offshore', $payload)
                        ->setPaper('a4')->setOption('isPhpEnabled', true)->output();
                } else {
                    [$payload, $s] = $gen->buildConsultancyPayload(
                        $lead,
                        $scenario,
                        array_merge($opts, ['applicant_mode' => $mode])
                    );
                    $payload['client_signature'] = $clientSig;
                    $payload['acknowledged'] = $acknowledged;
                    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('agreements.consultancy', $payload)
                        ->setPaper('a4')->setOption('isPhpEnabled', true)->output();
                }
            } else { // english engagement / offshore — pick the right variant + fees
                $scenario = match ($doc->source_variant) {
                    'engagement-english-offshore' => 'english_offshore',
                    'engagement-english-offshore-1000' => 'english_offshore_1000',
                    default => 'english_engagement',
                };
                $ov = \App\Services\ConsultancyReviewService::overridesForGeneration(
                    is_array($lead->consultancy_review) ? $lead->consultancy_review : []
                );
                [$view, $payload] = \App\Services\ConsultancyReviewService::previewPayload(
                    app(\App\Services\AgreementGenerator::class), $lead, $scenario, $ov
                );
                unset($payload['preview']); // final signed PDF, not the in-flow preview
                $payload['client_signature'] = $clientSig;
                $payload['acknowledged'] = $acknowledged;
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $payload)
                    ->setPaper('a4')
                    ->output();
            }

            Storage::disk('local')->put($doc->file_path, $pdf);

            $doc->forceFill([
                'size' => strlen($pdf),
                'client_signature_path' => $sigPath,
                'client_signed_at' => now(),
                'client_signer_name' => $request->input('signer_name') ?: trim("{$lead->first_name} {$lead->last_name}"),
                'client_signer_ip' => $request->ip(),
            ])->save();

            // Signing a consultancy agreement advances the pipeline to
            // "Consultancy Agreement Signed" (non-regressing). That stage
            // transition fires the consultancy_signe client email via the
            // Lead model trigger (same path as staff "Mark as signed").
            if ($isConsultancy) {
                $stages = Lead::STAGES;
                $curIdx = array_search($lead->status, $stages, true);
                $tgtIdx = array_search('Consultancy Agreement Signed', $stages, true);
                if ($tgtIdx !== false && ($curIdx === false || $curIdx < $tgtIdx)) {
                    $lead->status = 'Consultancy Agreement Signed';
                    $lead->save();
                }
            }

            return back()->with('success', 'Thank you — your agreement has been signed.');
        } catch (\Throwable $e) {
            Log::error('Client agreement signing failed', ['doc_id' => $docId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not save your signature. Please try again.']);
        }
    }

    /**
     * Pull raw signature bytes + extension from an uploaded image or a
     * base64 canvas data URL. Returns [null, 'png'] when neither is present.
     *
     * @return array{0: ?string, 1: string}
     */
    private function readSignatureUpload(Request $request): array
    {
        if ($request->hasFile('signature_image')) {
            $file = $request->file('signature_image');
            $ext = strtolower($file->getClientOriginalExtension()) === 'jpg' ? 'jpeg' : strtolower($file->getClientOriginalExtension());
            $ext = in_array($ext, ['png', 'jpeg'], true) ? $ext : 'png';

            return [file_get_contents($file->getRealPath()), $ext];
        }

        if ($request->filled('signature_data')) {
            $data = $request->input('signature_data');
            $ext = 'png';
            if (preg_match('/^data:image\/(png|jpe?g);base64,/', $data, $m)) {
                $ext = str_starts_with($m[1], 'jp') ? 'jpeg' : 'png';
                $data = substr($data, strpos($data, ',') + 1);
            }

            return [base64_decode(str_replace(' ', '+', $data), true) ?: null, $ext];
        }

        return [null, 'png'];
    }

    /**
     * Build 11.D Phase 3 — Agreements awaiting (or already done) on the
     * tracker. Drafts / voided / expired never surface here; signed ones
     * show with a "view confirmation" link so the client can re-read what
     * they signed without bouncing through their inbox.
     */
    private function publicAgreements(Lead $lead, string $code): array
    {
        return $lead->agreements()
            ->whereIn('status', [
                \App\Models\Agreement::STATUS_SENT,
                \App\Models\Agreement::STATUS_VIEWED,
                \App\Models\Agreement::STATUS_SIGNED,
            ])
            ->whereNotNull('tracker_signing_token')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (\App\Models\Agreement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'status' => $a->status,
                'sent_at' => $a->sent_at?->toIso8601String(),
                'signed_at' => $a->signed_at?->toIso8601String(),
                'sign_url' => "/track/{$code}/agreements/{$a->tracker_signing_token}/sign",
                'view_url' => "/track/{$code}/agreements/{$a->tracker_signing_token}/view",
                'signed_url' => "/track/{$code}/agreements/{$a->tracker_signing_token}/signed",
            ])
            ->all();
    }

    /**
     * Canonical roadmap of journey milestones. Each step is always present
     * (whether or not it has happened yet) so the frontend can render a
     * Completed / In Progress / Pending pattern matching the reference
     * design. Status is computed left-to-right: every step with a
     * timestamp = completed; the first step without = current ("In
     * Progress"); everything after = pending.
     */
    /**
     * Resolve the visa-type catalogue entry the lead is on and decorate
     * each of its checklist items with a per-item status (submitted /
     * approved / rejected / missing). Status is computed from the lead's
     * own LeadDocument rows, matched by `checklist_key`.
     *
     * Returns null when the lead has no `inz_visa_type` set OR the visa
     * type can't be located in the catalogue.
     */
    /**
     * Documents shown on EVERY tracker, whatever the visa type — pinned to the
     * top of the Documents tab. Staff can still hide them per-case through the
     * same hidden_track_documents mechanism as any other checklist item.
     */
    private const UNIVERSAL_ITEMS = [
        ['key' => 'svf', 'label' => 'Visa Information Form', 'hint' => 'Visa information form prepared with your adviser.'],
        ['key' => 'rfi', 'label' => 'Request Information Form', 'hint' => 'Information your adviser has requested — review it and upload your response.', 'optional' => true],
    ];

    /**
     * Decorate the universal top items with this lead's upload status, skipping
     * any the staff have hidden. Returned in the same shape as the checklist so
     * the tracker template renders them identically.
     */
    private function universalTopItems(Lead $lead, $docsByKey): array
    {
        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);
        // The Information Form is "Student Visa Information Form" for student
        // visas, plain "Visa Information Form" everywhere else.
        $isStudent = str_contains(strtolower((string) $lead->inz_visa_type), 'student');
        // The RFI upload slot appears only once the adviser has REQUESTED info
        // (attached the RFI notification file at the RFI stage). The slot itself
        // is for the CLIENT's response uploads — the staff notification is not
        // its content (it shows in "Documents from your adviser").
        $hasRfiRequest = $lead->documents()->where('source_variant', 'rfi')->exists();
        $out = [];

        foreach (self::UNIVERSAL_ITEMS as $u) {
            if (in_array($u['key'], $hidden, true)) {
                continue;
            }

            // The RFI slot only shows once information has been requested.
            if ($u['key'] === 'rfi' && ! $hasRfiRequest) {
                continue;
            }

            // The slot's status/count come from the CLIENT's own uploads (keyed
            // by checklist_key), never the staff notification file.
            $docs = $docsByKey->get($u['key']) ?? collect();
            $status = 'missing';
            if ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_APPROVED)) {
                $status = 'approved';
            } elseif ($docs->contains(fn ($d) => in_array($d->status, [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW, LeadDocument::STATUS_CHECKED]))) {
                $status = 'submitted';
            } elseif ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_REJECTED)) {
                $status = 'rejected';
            }

            $out[] = [
                'key' => $u['key'],
                'label' => ($u['key'] === 'svf' && $isStudent) ? 'Student Visa Information Form' : $u['label'],
                'hint' => $u['hint'] ?? null,
                'required' => ! ($u['optional'] ?? false),
                'status' => $status,
                'count' => $docs->count(),
                // Flag the frontend keys on to pin this to the top section.
                'universal' => true,
            ];
        }

        return $out;
    }

    private function resolveVisa(Lead $lead): ?array
    {
        if (! $lead->inz_visa_type) {
            return null;
        }

        // Shared resolver — the tracker MUST land on the same VisaType the
        // staff dashboard does, or the applicant sees a different checklist
        // for the same visa. (A plain "name OR code" query could match a
        // different row once any visa's code equalled another's name.)
        $visa = app(\App\Services\Immigration\CaseChecklistService::class)->resolveVisaType($lead);

        if (! $visa) {
            // Surface the bare visa name so the panel can still render
            // something useful even when the catalogue doesn't match.
            return [
                'name' => $lead->inz_visa_type,
                'short_description' => null,
                'checklist' => [],
                'totals' => ['required' => 0, 'submitted' => 0, 'approved' => 0],
            ];
        }

        // Group docs by checklist_key so we can decorate items in O(1).
        $docsByKey = $lead->documents()
            ->whereNotNull('checklist_key')
            ->get()
            ->groupBy('checklist_key');

        $items = app(\App\Services\Immigration\CaseChecklistService::class)->consolidateAliases($lead, is_array($visa->checklist_items) ? $visa->checklist_items : []);

        // Drop any checklist items staff have hidden from the tracker for
        // this specific case.
        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);
        if (! empty($hidden)) {
            $items = collect($items)
                ->reject(fn ($it) => in_array($it['key'] ?? null, $hidden, true))
                ->values()
                ->all();
        }

        $decorated = collect($items)->map(function ($item) use ($docsByKey) {
            $key = $item['key'] ?? null;
            $docs = collect(array_merge([$key], $item['aliases'] ?? []))->flatMap(fn ($uploadKey) => $docsByKey->get($uploadKey) ?? collect());

            // Status is whichever the "best" doc landed on:
            //   Approved > Submitted/UnderReview > Rejected > none.
            // A manager's internal "Checked" reads as under-review to the client
            // (grouped with Submitted/UnderReview) until the adviser decides.
            $status = 'missing';
            if ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_APPROVED)) {
                $status = 'approved';
            } elseif ($docs->contains(fn ($d) => in_array($d->status, [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW, LeadDocument::STATUS_CHECKED]))) {
                $status = 'submitted';
            } elseif ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_REJECTED)) {
                $status = 'rejected';
            }

            return [
                'key' => $key,
                'aliases' => $item['aliases'] ?? [],
                'label' => $item['label'] ?? '',
                // Section heading. Null for visas saved before Section became
                // its own field — the tracker then falls back to splitting a
                // legacy "Section · Name" label.
                'category' => $item['category'] ?? null,
                'hint' => $item['hint'] ?? null,
                'required' => ($item['required'] ?? true) ? true : false,
                'status' => $status,
                'count' => $docs->count(),
            ];
        })->all();

        $requiredCount = collect($decorated)->where('required', true)->count();
        $submittedCount = collect($decorated)
            ->where('required', true)
            ->whereIn('status', ['submitted', 'approved'])
            ->count();
        $approvedCount = collect($decorated)
            ->where('required', true)
            ->where('status', 'approved')
            ->count();

        return [
            'name' => $visa->name,
            'code' => $visa->code,
            'short_description' => $visa->short_description,
            'checklist' => array_merge($this->universalTopItems($lead, $docsByKey), $decorated),
            'totals' => [
                'required' => $requiredCount,
                'submitted' => $submittedCount,
                'approved' => $approvedCount,
            ],
        ];
    }

    /**
     * Fallback checklist for leads/students that aren't tied to a specific
     * visa type. Reads the shared config-based checklist (the same one the
     * staff Documents tab uses), decorates each item with the lead's own
     * upload status, honours `hidden_track_documents`, and returns the
     * exact same shape as resolveVisa() so the tracker template can render
     * it interchangeably.
     */
    /**
     * The only requirement sections a general/education client sees on their
     * tracker, in the order they should work through them. Every other config
     * section (Immigration Forms, Employment/Financial, Partner, etc.) is for
     * internal/immigration use and is hidden from this tracker.
     */
    private const TRACKER_SECTIONS = [
        'Personal Documents',
        'Offer and Academic Documents',
        'Agreements',
    ];

    /**
     * Map a staff-set checklist status (leads.document_checklist) to the value
     * the tracker shows. Only the client-relevant review states surface; legacy
     * internal planning statuses (available/in_progress/…) are ignored so the
     * tracker falls back to file-based status for those.
     */
    private function trackerStaffStatus(?string $status): ?string
    {
        return match ($status) {
            'accepted' => 'accepted',
            'under_review' => 'under_review',
            'needs_attention' => 'needs_attention',
            default => null,
        };
    }

    private function resolveGeneralChecklist(Lead $lead): array
    {
        // Only the client-facing sections, and only those (drops the internal
        // immigration/financial sections that made the list balloon to 50+).
        $sections = $lead->is_immigration_case
            ? collect(config('lead_document_checklist.sections', []))
                ->filter(fn ($s) => in_array($s['section'] ?? null, self::TRACKER_SECTIONS, true))->all()
            : config('lead_document_checklist.education_sections', []);
        $sectionOrder = $lead->is_immigration_case
            ? self::TRACKER_SECTIONS
            : array_column($sections, 'section');

        $docsByKey = $lead->documents()
            ->whereNotNull('checklist_key')
            ->get()
            ->groupBy('checklist_key');

        $hidden = app(\App\Services\Immigration\CaseChecklistService::class)->hiddenKeysFor($lead);

        // Staff-set per-item status (leads.document_checklist JSON) — the same
        // status shown on the staff Documents tab. When set, the tracker shows
        // this exact status instead of deriving one from file presence.
        $staffChecklist = is_array($lead->document_checklist) ? $lead->document_checklist : [];

        $decorated = [];
        foreach ($sections as $section) {
            foreach (($section['items'] ?? []) as $item) {
                $key = $item['id'] ?? null;
                if (! $key || in_array($key, $hidden, true)) {
                    continue;
                }

                $docs = $docsByKey->get($key) ?? collect();
                $status = 'missing';
                if ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_APPROVED)) {
                    $status = 'approved';
                } elseif ($docs->contains(fn ($d) => in_array($d->status, [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW]))) {
                    $status = 'submitted';
                } elseif ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_REJECTED)) {
                    $status = 'rejected';
                }

                // Frontend parseChecklistLabel splits on " · " to recover
                // the section header, so encode it in the label.
                $decorated[] = [
                    'key' => $key,
                    'label' => ($section['section'] ?? 'General').' · '.($item['name'] ?? $key),
                    'hint' => $item['hint'] ?? null,
                    'required' => ($item['required'] ?? true) ? true : false,
                    'status' => $status,
                    'staff_status' => $this->trackerStaffStatus($staffChecklist[$key]['status'] ?? null),
                    'count' => $docs->count(),
                ];
            }
        }

        // Per-lead ad-hoc documents (leads.custom_documents) — this lead only.
        // Rendered as their own "Additional Documents" section so the client
        // can upload them from the tracker like any other requirement.
        $custom = is_array($lead->custom_documents) ? $lead->custom_documents : [];
        $customSections = [];
        foreach ($custom as $c) {
            $key = $c['key'] ?? null;
            if (! $key || in_array($key, $hidden, true)) {
                continue;
            }
            $docs = $docsByKey->get($key) ?? collect();
            $status = 'missing';
            if ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_APPROVED)) {
                $status = 'approved';
            } elseif ($docs->contains(fn ($d) => in_array($d->status, [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW]))) {
                $status = 'submitted';
            } elseif ($docs->contains(fn ($d) => $d->status === LeadDocument::STATUS_REJECTED)) {
                $status = 'rejected';
            }
            $sec = trim((string) ($c['section'] ?? '')) ?: 'Additional Documents';
            $customSections[$sec] = true;
            $decorated[] = [
                'key' => $key,
                'label' => $sec.' · '.($c['name'] ?? $key),
                'hint' => null,
                'required' => true,
                'status' => $status,
                'staff_status' => $this->trackerStaffStatus($staffChecklist[$key]['status'] ?? null),
                'count' => $docs->count(),
            ];
        }
        // Any custom folder that isn't one of the standard four gets appended
        // to the section order so it renders after them, in order.
        $extraSections = array_values(array_diff(array_keys($customSections), $sectionOrder));

        $requiredCount = collect($decorated)->where('required', true)->count();
        $submittedCount = collect($decorated)
            ->where('required', true)
            ->whereIn('status', ['submitted', 'approved'])
            ->count();
        $approvedCount = collect($decorated)
            ->where('required', true)
            ->where('status', 'approved')
            ->count();

        return [
            // Label the tracker with the visa the applicant selected (if any),
            // e.g. "Tourist Visa" — shown in the header pill, subtitle and
            // "Type of Visa" card. The requirements below stay the general
            // Document Checklist either way; this is a display label only, not
            // a link to the immigration visa's own checklist. Falls back to
            // "Document Checklist" when they never picked one.
            'name' => $lead->inz_visa_type ?: 'Document Checklist',
            'code' => null,
            'short_description' => null,
            // Exactly the config checklist — no universal SV-form injection here.
            // The general tracker must show the SAME items as the staff lead-
            // profile Documents tab (which is config-only). The universal item
            // duplicated the config's "SVF — Student Visa Form" (info.svf) with
            // a second "Visa Information Form" (svf) row, so the two surfaces
            // diverged. Immigration cases keep their universal items (resolveVisa).
            'checklist' => $decorated,
            // The order the client should work through the requirement sections
            // on their tracker. Tracker-only — the staff Documents tab is
            // unaffected. Same list that scopes which sections show above; the
            // per-lead "Additional Documents" section is appended when present.
            'section_order' => empty($extraSections)
                ? $sectionOrder
                : array_merge($sectionOrder, $extraSections),
            'totals' => [
                'required' => $requiredCount,
                'submitted' => $submittedCount,
                'approved' => $approvedCount,
            ],
        ];
    }

    private function buildTimeline(Lead $lead): array
    {
        $earliestConversion = collect([
            $lead->student_converted_at,
            $lead->immigration_converted_at,
            $lead->accommodation_converted_at,
        ])->filter()->sort()->first();

        // Curated roadmap. Edit copy / order in one place.
        $milestones = [
            ['key' => 'enquiry',       'label' => 'Enquiry',            'at' => $lead->created_at],
            ['key' => 'first_contact', 'label' => 'First Contact',      'at' => $lead->date_of_first_contact],
            ['key' => 'engagement',    'label' => 'Engagement',         'at' => $lead->date_of_engagement],
            ['key' => 'agreement',     'label' => 'Services Agreement', 'at' => $lead->services_agreement_signed_at],
            ['key' => 'conversion',    'label' => 'Department Assigned', 'at' => $earliestConversion],
            ['key' => 'inz_lodged',    'label' => 'Lodged with INZ',    'at' => $lead->inz_lodged_at],
            ['key' => 'inz_decision',  'label' => 'INZ Decision',       'at' => $lead->inz_decision_at],
        ];

        $journey = [];
        $foundCurrent = false;

        foreach ($milestones as $m) {
            if ($m['at']) {
                $status = 'completed';
            } elseif (! $foundCurrent) {
                $status = 'current';
                $foundCurrent = true;
            } else {
                $status = 'pending';
            }

            $journey[] = [
                'key' => $m['key'],
                'label' => $m['label'],
                'status' => $status,
                'at' => $m['at'] ? Carbon::parse($m['at'])->toIso8601String() : null,
            ];
        }

        return $journey;
    }

    /**
     * 12-step "My Visa Application Journey" used for immigration cases.
     * Each entry mirrors the standard journey shape (key/label/status/at)
     * with two extras:
     *   - description: per-step copy displayed under the label
     *   - alternative: true when the step is an outcome branch (Decline)
     *     so the frontend can draw the connecting line dashed.
     *
     * The status of each step is computed from explicit lead columns +
     * the lead's current `immigration_stage`. The 11 standard steps are
     * always returned in order; the 12th "Application declined" step is
     * appended only when the case has been declined.
     */
    private function buildImmigrationJourney(Lead $lead): array
    {
        $docs = $lead->documents;
        $stage = $lead->immigration_stage; // one of Lead::IMMIGRATION_STAGES
        $hasDocs = $docs->isNotEmpty();
        $hasUnderReview = $docs->whereIn('status', [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW])->isNotEmpty();
        $hasApprovedDoc = $docs->where('status', LeadDocument::STATUS_APPROVED)->isNotEmpty();

        // Stage hierarchy — anything at or past `Endorsed` counts as "doc
        // review done", anything at or past `Visa Lodged` counts as
        // "application lodged", etc. Decline / Approved Visa are
        // terminal markers.
        $stageRank = [
            'Endorsed' => 1,
            'Visa Lodged' => 2,
            // An interim visa is granted off the back of a lodged application
            // while INZ decides, so it ranks with lodgement — without an entry
            // here the client's journey would regress to "not lodged yet".
            'Interim Visa Issued' => 2,
            'Request for Information' => 3,
            'Approved in Principle' => 4,
            'Approved Visa' => 5,
            'Decline Visa' => -1, // outcome branch
        ];
        $rank = $stageRank[$stage] ?? 0;
        $declined = $stage === 'Decline Visa';

        // Step definitions — `done` is whatever proves the step happened,
        // `at` is the best timestamp we can attach for display.
        $steps = [
            [
                'key' => 'initial_consultation',
                'label' => 'Initial consultation',
                'description' => 'I meet my adviser to check if I\'m eligible and explore my options.',
                'done' => (bool) $lead->date_of_first_contact,
                'at' => optional($lead->date_of_first_contact)?->toDateTimeString(),
            ],
            [
                'key' => 'referred_to_adviser',
                'label' => 'Referred to my adviser',
                'description' => 'My case is passed to a Licensed Immigration Adviser to look after it.',
                'done' => (bool) $lead->date_of_engagement || (bool) $lead->immigration_converted_at,
                'at' => optional($lead->date_of_engagement)?->toDateTimeString()
                                ?? optional($lead->immigration_converted_at)?->toDateTimeString(),
            ],
            [
                'key' => 'engaged_adviser',
                'label' => 'Engaged my adviser',
                'description' => 'I sign the service agreement and my adviser formally takes on my case.',
                'done' => (bool) $lead->services_agreement_signed_at,
                'at' => optional($lead->services_agreement_signed_at)?->toDateTimeString(),
            ],
            [
                'key' => 'gathering_documents',
                'label' => 'Gathering my documents',
                'description' => 'I collect and send through the documents on my checklist.',
                'done' => $hasDocs,
                'at' => optional($docs->sortBy('created_at')->first()?->created_at)?->toDateTimeString(),
            ],
            [
                'key' => 'documentation_review',
                'label' => 'Documentation under review',
                'description' => 'My adviser checks everything is correct, complete and valid.',
                'done' => $hasUnderReview || $hasApprovedDoc || $rank >= 1,
                'at' => optional(
                    $docs->whereIn('status', [LeadDocument::STATUS_APPROVED, LeadDocument::STATUS_UNDER_REVIEW])
                        ->sortBy('created_at')->first()?->created_at
                )?->toDateTimeString(),
            ],
            [
                'key' => 'application_prepared',
                'label' => 'Application prepared',
                'description' => 'My adviser drafts my application, and we do a final check before lodging.',
                'done' => $rank >= 1,
                'at' => null,
            ],
            [
                'key' => 'application_lodged',
                'label' => 'Application lodged',
                'description' => 'My application is formally submitted to Immigration New Zealand.',
                'done' => (bool) $lead->inz_lodged_at || $rank >= 2,
                'at' => optional($lead->inz_lodged_at)?->toDateTimeString(),
            ],
            [
                'key' => 'further_info_requested',
                'label' => 'Further information requested',
                'description' => 'Immigration NZ asks for more details, and my adviser helps me respond.',
                // Treat this as "happened" only when the case currently is
                // or was previously at RFI (rank 3) or has moved past it.
                'done' => $rank >= 3,
                'at' => null,
                // Mark optional so we don't force every applicant through
                // a step they may never trigger.
                'optional' => true,
            ],
            [
                'key' => 'approved_in_principle',
                'label' => 'Approved in principle',
                'description' => 'My visa is approved, pending final steps like payment or medicals.',
                'done' => $rank >= 4,
                'at' => null,
            ],
            [
                'key' => 'visa_approved',
                'label' => 'Visa approved',
                'description' => 'Immigration New Zealand grants my visa.',
                'done' => $rank >= 5,
                'at' => optional($lead->inz_decision_at)?->toDateTimeString(),
            ],
            [
                'key' => 'visa_issued',
                'label' => 'Visa issued & settling in',
                'description' => 'I receive my visa and prepare to travel, arrive and settle in.',
                // We don't track "issued" separately yet; treat as
                // visa_approved + complete (so the lead sees it as
                // pending until staff stamp a decision).
                'done' => $rank >= 5 && (bool) $lead->inz_decision_at,
                'at' => optional($lead->inz_decision_at)?->toDateTimeString(),
            ],
        ];

        // Walk the list and tag completed / current / pending. Optional
        // steps don't promote themselves to "current" — they stay
        // pending until evidence appears.
        $foundCurrent = false;
        $journey = [];
        foreach ($steps as $s) {
            if (! empty($s['done'])) {
                $status = 'completed';
            } elseif (! $foundCurrent && empty($s['optional'])) {
                $status = 'current';
                $foundCurrent = true;
            } else {
                $status = 'pending';
            }

            $journey[] = [
                'key' => $s['key'],
                'label' => $s['label'],
                'description' => $s['description'],
                'status' => $status,
                'at' => $s['at'] ? Carbon::parse($s['at'])->toIso8601String() : null,
            ];
        }

        // Declined outcome — append a 12th alternative-branch step. The
        // frontend renders this with a dashed connecting line so it reads
        // as a branch rather than a continuation.
        if ($declined) {
            // Demote any step we'd already tagged "current" because the
            // outcome decided the case.
            $journey = array_map(function ($s) {
                if ($s['status'] === 'current') {
                    $s['status'] = 'pending';
                }

                return $s;
            }, $journey);

            $journey[] = [
                'key' => 'application_declined',
                'label' => 'Application declined',
                'description' => 'My application wasn\'t successful; my adviser tells me through next steps.',
                'status' => 'completed',
                'at' => optional($lead->inz_decision_at)?->toIso8601String(),
                'alternative' => true,
            ];
        }

        return $journey;
    }
}
