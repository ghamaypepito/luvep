<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Services\AgreementGenerator;
use App\Services\Immigration\CaseChecklistService;
use App\Services\Immigration\EngagementDocumentGenerator;
use App\Support\UploadValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Document workflow shared by staff (admin/sales) and the lead's own portal.
 *
 *   staffIndex()    — staff view of one lead's documents
 *   requestStore()  — staff requests one or more documents from the lead
 *   updateStatus()  — staff approves / rejects a submitted document
 *   destroyRequest() — staff cancels an open request
 *   shareWithLead() — staff uploads a file FOR the lead to download
 *
 *   leadIndex()     — lead's own view of their requested + uploaded docs
 *   leadUpload()    — lead uploads a file (against a request or unsolicited)
 *
 *   download()      — single endpoint, role-gated. Lead can download files
 *                     against their own lead_id. Staff can download any.
 *
 * Files live in storage/app/private/lead-documents/<lead_id>/ and are never
 * exposed via a public URL — every download goes through download().
 */
class LeadDocumentController extends Controller
{
    private const DISK = 'local'; // private; downloads streamed via controller

    /**
     * Store an uploaded checklist file, renaming it to the visa checklist's
     * "NN - CODE - FirstnameLASTNAME<suffix>" convention when the matched
     * checklist item defines a file_code. Falls back to the original filename
     * for items (or visa types) without a naming convention.
     *
     * @return array{0: string, 1: string} [storedPath, displayName]
     */
    private function storeChecklistFile($file, Lead $lead, string $key): array
    {
        $dir = "lead-documents/{$lead->id}";
        $desired = app(CaseChecklistService::class)
            ->uploadFileNameFor($lead, $key, $file->getClientOriginalName());

        if (! $desired) {
            return [$file->store($dir, self::DISK), $file->getClientOriginalName()];
        }

        $filename = $this->uniqueFilename($dir, $desired);

        return [$file->storeAs($dir, $filename, self::DISK), $filename];
    }

    /**
     * Ensure a filename doesn't clobber an existing file in the same folder,
     * appending " (2)", " (3)", … before the extension until it's unique.
     */
    private function uniqueFilename(string $dir, string $filename): string
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists("{$dir}/{$filename}")) {
            return $filename;
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;

        $i = 2;
        do {
            $candidate = $ext !== '' ? "{$base} ({$i}).{$ext}" : "{$base} ({$i})";
            $i++;
        } while ($disk->exists("{$dir}/{$candidate}"));

        return $candidate;
    }

    // ── STAFF (admin / sales) ───────────────────────────────────────────────

    public function staffIndex(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $requests = $lead->loadMissing(['documentRequests.requester:id,name', 'documentRequests.latestDocument'])
            ->documentRequests()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeadDocumentRequest $r) => [
                'id' => $r->id,
                'label' => $r->label,
                'description' => $r->description,
                'required' => $r->required,
                'requested_by' => optional($r->requester)->name,
                'requested_at' => $r->requested_at,
                'latest_document' => $r->latestDocument ? $this->docSerialize($r->latestDocument) : null,
            ]);

        // Documents not tied to any request (unsolicited or staff-shared)
        $orphans = LeadDocument::where('lead_id', $lead->id)
            ->whereNull('request_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeadDocument $d) => $this->docSerialize($d));

        return inertia('admin/LeadDocuments', [
            'lead' => [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'name' => trim("{$lead->first_name} {$lead->last_name}"),
                'email' => $lead->email,
                'status' => $lead->status,
                'stage' => $lead->stage,
                'source' => $lead->source,
                'portal_invitation_status' => $lead->portal_invitation_status,
            ],
            'requests' => $requests,
            'orphans' => $orphans,
            'templates' => $this->requestTemplates(),
        ]);
    }

    public function requestStore(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $data = $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.label' => 'required|string|max:120',
            'items.*.description' => 'nullable|string|max:500',
            'items.*.required' => 'sometimes|boolean',
            // The staff member's optional "message to client" for this batch.
            'message' => 'nullable|string|max:2000',
        ]);

        try {
            // Create every requested item first, collecting the rows so the client
            // is notified ONCE for the whole batch (not one email per document).
            $requests = [];
            foreach ($data['items'] as $item) {
                $requests[] = LeadDocumentRequest::create([
                    'lead_id' => $lead->id,
                    'label' => $item['label'],
                    'description' => $item['description'] ?? null,
                    'required' => $item['required'] ?? true,
                    'requested_by' => Auth::id(),
                    'requested_at' => now(),
                ]);
            }

            // One consolidated notification for the batch. {{document_name}} keeps
            // working (a single label, or "N documents" when several); the new
            // {{document_list}} lists them all. Comma-separated so it renders in
            // both an HTML email body and a plaintext SMS.
            $labels = array_map(fn ($r) => $r->label, $requests);
            $ctx = [
                'document_name' => count($labels) === 1 ? $labels[0] : count($labels).' documents',
                'document_list' => implode(', ', $labels),
                // The staff member's "message to client" — fills {{message}} in the
                // template (blank string, never null, so the placeholder clears).
                'message' => (string) ($data['message'] ?? ''),
            ];

            // The email-automation "Document requested" message is the single
            // editable template for this. Returns true when it sent to the client,
            // in which case we DON'T also send the built-in email (no double-send).
            $firedClient = app(\App\Services\EmailAutomationService::class)
                ->fire('immigration.document.requested', $lead, $ctx);

            // Fallback when no automation message is configured: prefer the
            // 'doc_request' template (also a single email, same variables), and
            // only if it has no email channel fall back to the legacy Mailable.
            if (! $firedClient && ! empty($lead->email)) {
                try {
                    $res = app(\App\Services\CommunicationService::class)
                        ->sendTemplated('doc_request', $lead, $ctx);
                    if (! $res['email']) {
                        // The legacy Mailable is single-document; only in this rare
                        // no-template path does it go out once per request.
                        foreach ($requests as $r) {
                            Mail::to($lead->email)->send(new \App\Mail\DocumentRequestedFromLead($lead, $r));
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('DocumentRequestedFromLead dispatch failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
                }
            }

            return back()->with('success', count($data['items']).' document request(s) added.');
        } catch (\Throwable $e) {
            Log::error('Document request create failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not save requests.']);
        }
    }

    public function destroyRequest(Request $request, $leadId, $requestId)
    {
        $r = LeadDocumentRequest::where('lead_id', $leadId)->findOrFail($requestId);
        // Don't delete the underlying uploads — just decouple them
        $r->documents()->update(['request_id' => null]);
        $r->delete();

        return back()->with('success', 'Request removed.');
    }

    /**
     * Re-send the "please upload this document" email for a single existing
     * request — one click from the Documents tab. Uses the SAME single source as
     * the original request: the configured "Document requested" automation
     * message, falling back to the built-in email only when none is set.
     */
    public function resendRequest(Request $request, $leadId, $requestId)
    {
        $lead = Lead::findOrFail($leadId);
        $docRequest = LeadDocumentRequest::where('lead_id', $lead->id)->findOrFail($requestId);

        // A resend concerns one request, so {{document_name}} and {{document_list}}
        // are both that single label (a template can use either). {{message}} is
        // the instruction saved with the original request.
        $ctx = [
            'document_name' => $docRequest->label,
            'document_list' => $docRequest->label,
            'message' => (string) ($docRequest->description ?? ''),
        ];
        $firedClient = app(\App\Services\EmailAutomationService::class)
            ->fire('immigration.document.requested', $lead, $ctx);

        if (! $firedClient && ! empty($lead->email)) {
            try {
                $res = app(\App\Services\CommunicationService::class)
                    ->sendTemplated('doc_request', $lead, $ctx);
                if (! $res['email']) {
                    Mail::to($lead->email)->send(new \App\Mail\DocumentRequestedFromLead($lead, $docRequest));
                }
            } catch (\Throwable $e) {
                Log::error('Resend document request failed', ['lead_id' => $lead->id, 'request_id' => $docRequest->id, 'error' => $e->getMessage()]);

                return back()->withErrors(['error' => 'Could not send the reminder.']);
            }
        }

        if (empty($lead->email)) {
            return back()->withErrors(['error' => 'No email on file for this client.']);
        }

        return back()->with('success', "Reminder sent for “{$docRequest->label}”.");
    }

    /**
     * Flat JSON list of every file on a case, with its review status.
     *
     * Feeds the "Files" popover on the immigration Cases table so staff can
     * see what has been submitted / reviewed / rejected without opening the
     * case profile. Fetched on demand — the Cases props stay light.
     */
    public function documentsJson(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        // checklist_key → human label, so the history reads "Passport
        // biographical page" rather than "passport_bio_page".
        $labels = collect(app(\App\Services\Immigration\CaseChecklistService::class)->forCase($lead))
            ->filter(fn ($i) => ! empty($i['key']))
            ->mapWithKeys(fn ($i) => [$i['key'] => $i['label'] ?? $i['key']]);

        $docs = $lead->documents()
            ->with(['uploader:id,name', 'reviewer:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'name' => $d->original_name ?: basename((string) $d->file_path),
                'checklist_key' => $d->checklist_key,
                // Which checklist requirement this file answers (null for
                // ad-hoc uploads that don't map to one).
                'checklist_label' => $d->checklist_key ? ($labels[$d->checklist_key] ?? null) : null,
                'mime' => $d->mime,
                'size' => $d->size,
                // Files staff generated (agreements, invoices) never carry a
                // review status — label them so they don't look "pending".
                'status' => $d->source === LeadDocument::SOURCE_GENERATED
                    ? null
                    : ($d->status ?: LeadDocument::STATUS_SUBMITTED),
                'source' => $d->source,
                'note' => $d->note,
                'uploaded_by' => optional($d->uploader)->name,
                'reviewed_by' => optional($d->reviewer)->name,
                'created_at' => optional($d->created_at)?->toIso8601String(),
            ]);

        return response()->json([
            'lead_id' => $lead->id,
            'name' => trim("{$lead->first_name} {$lead->last_name}"),
            'documents' => $docs,
        ]);
    }

    public function updateStatus(Request $request, $leadId, $docId)
    {
        $doc = LeadDocument::where('lead_id', $leadId)->findOrFail($docId);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                LeadDocument::STATUS_SUBMITTED,
                LeadDocument::STATUS_UNDER_REVIEW,
                LeadDocument::STATUS_CHECKED,
                LeadDocument::STATUS_APPROVED,
                LeadDocument::STATUS_REJECTED,
            ])],
            'note' => 'nullable|string|max:500',
            // When verifying a proof of payment from a specific invoice row, the
            // id of that invoice document — so only that invoice is marked paid.
            'invoice_id' => 'nullable|integer',
        ]);

        $doc->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? $doc->note,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        // Push approved documents to the client's Google Drive folder — a
        // best-effort background job, dormant unless Drive is configured.
        if ($validated['status'] === LeadDocument::STATUS_APPROVED
            && \App\Services\GoogleDriveService::isConfigured()) {
            \App\Jobs\PushApprovedDocumentToDrive::dispatch($doc->id);
        }

        // Confirming a proof of payment settles the invoice — move the case to
        // "Invoice Paid" (only from a pre-payment stage, so it never downgrades).
        if ($doc->source_variant === 'proof_of_payment'
            && $validated['status'] === LeadDocument::STATUS_APPROVED
            && $doc->lead) {
            $doc->lead->advanceImmigrationStage(
                'Invoice Paid',
                ['For Assessment', 'Endorsed', 'Agreement Sent', 'Agreement Signed', 'For Agreement & Invoice', 'Request for Information'],
                Auth::id(),
            );

            // Email automation — payment verified (no-op unless configured).
            app(\App\Services\EmailAutomationService::class)->fire('immigration.invoice.paid', $doc->lead, []);

            // Mark the invoice this proof settles as paid so its row flips to
            // "Paid" (reviewed_at doubles as the paid date on the badge). When a
            // specific invoice_id is given (case-profile flow), only that invoice
            // flips. When none is given (the Invoice module's "confirm payment"),
            // settle the case's pending invoice document(s) so the case profile
            // reflects it — otherwise the proof reads Approved but the invoice
            // never shows verified.
            $invoiceQuery = LeadDocument::where('lead_id', $leadId)
                ->whereIn('status', [LeadDocument::STATUS_STAFF_SHARED, LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW]);
            if (! empty($validated['invoice_id'])) {
                $invoiceQuery->where('id', $validated['invoice_id']);
            } else {
                $invoiceQuery->where(function ($q) {
                    $q->where('source_variant', 'invoice')->orWhere('source_variant', 'like', 'invoice:%');
                });
            }
            $invoiceQuery->update([
                'status' => LeadDocument::STATUS_APPROVED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);
        }

        // Tell the lead their document was approved / rejected. Prefer the
        // doc_approved / doc_rejected templates; fall back to the Mailable.
        $lead = $doc->lead;

        // Reviewing a file is staff activity on the case — show it in the
        // portals' Updated column.
        $lead?->recordStaffActivity(
            $validated['status'].' '.($doc->original_name ?: 'file')
        );

        // Email automation — document approved / needs attention (a proof of
        // payment is handled by immigration.invoice.paid above, so exclude it).
        // The configured automation message is the single source: it returns
        // whether it emailed the client, so the built-in email below only sends
        // as a fallback when no client automation message is set (no double-send).
        $firedClient = false;
        if ($lead && $doc->source_variant !== 'proof_of_payment') {
            if ($validated['status'] === LeadDocument::STATUS_APPROVED) {
                $firedClient = app(\App\Services\EmailAutomationService::class)->fire('immigration.document.approved', $lead, ['document_name' => $doc->original_name]);
            } elseif ($validated['status'] === LeadDocument::STATUS_REJECTED) {
                $firedClient = app(\App\Services\EmailAutomationService::class)->fire('immigration.document.rejected', $lead, ['document_name' => $doc->original_name, 'reason' => $validated['note'] ?? '']);
            }
        }
        if (! $firedClient
            && in_array($validated['status'], [LeadDocument::STATUS_APPROVED, LeadDocument::STATUS_REJECTED], true)
            && $lead && ! empty($lead->email)) {
            try {
                // Needs-attention → the "please re-upload" template (asks the
                // client to log in to their portal and submit again); approval
                // keeps the doc_approved template.
                $key = $validated['status'] === LeadDocument::STATUS_APPROVED ? 'doc_approved' : 'document_reupload';
                $res = app(\App\Services\CommunicationService::class)->sendTemplated($key, $lead, [
                    'document_name' => $doc->original_name,
                    'reason' => $validated['note'] ?? '',
                ]);
                if (! $res['email']) {
                    Mail::to($lead->email)->send(new \App\Mail\DocumentStatusChanged($lead, $doc->fresh(), $validated['note'] ?? null));
                }
            } catch (\Throwable $e) {
                Log::error('DocumentStatusChanged dispatch failed', ['doc_id' => $doc->id, 'error' => $e->getMessage()]);
            }
        }

        // A rejection/approval changes the findings picture — re-evaluate (§8d).
        \App\Jobs\EvaluateCaseFindings::dispatch($doc->lead_id);

        return back()->with('success', "Document marked {$validated['status']}.");
    }

    public function shareWithLead(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);
        $request->validate([
            'file' => 'required|'.UploadValidation::document(),
            'note' => 'nullable|string|max:500',
        ]);

        $path = $request->file('file')->store("lead-documents/{$lead->id}", self::DISK);

        LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
            'mime' => $request->file('file')->getMimeType(),
            'size' => $request->file('file')->getSize(),
            'status' => LeadDocument::STATUS_STAFF_SHARED,
            'note' => $request->input('note'),
            'uploaded_by' => Auth::id(),
        ]);

        return back()->with('success', 'File shared with the lead.');
    }

    /**
     * Send an existing case document to the client — flips it to StaffShared so
     * it surfaces on the client's portal + /track. Used from the Documents tab's
     * "Send to client" action for staff-created / generated documents.
     */
    public function sendToClient(Request $request, $leadId, $docId)
    {
        $doc = LeadDocument::where('lead_id', $leadId)->findOrFail($docId);
        abort_unless($doc->file_path, 422, 'This document has no file to send.');

        $doc->update([
            'status' => LeadDocument::STATUS_STAFF_SHARED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        $doc->lead?->recordStaffActivity('Sent to client: '.($doc->original_name ?: 'document'));

        return back()->with('success', 'Document sent to the client.');
    }

    /**
     * Staff uploads a file (or files) for the lead against a specific
     * checklist item — used by the agreements panel, or when staff helps
     * the lead by uploading on their behalf.
     */
    /**
     * Add a per-lead ad-hoc document row to this lead's Documents tab. Scoped
     * to the one lead (stored in leads.custom_documents) — no other lead sees
     * it. Uploads attach to it via its generated `custom.*` checklist_key,
     * reusing the normal upload/status/tracker flow.
     */
    public function addCustomDocument(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            // Which checklist folder/section the document drops into. Free-form
            // (the frontend offers the standard folders) — defaults to the
            // per-lead "Additional Documents" bucket when omitted.
            'section' => 'nullable|string|max:120',
        ]);

        $items = is_array($lead->custom_documents) ? $lead->custom_documents : [];
        $items[] = [
            'key' => 'custom.'.\Illuminate\Support\Str::random(12),
            'name' => trim($data['name']),
            'section' => trim((string) ($data['section'] ?? '')) ?: 'Additional Documents',
            'created_at' => now()->toIso8601String(),
            'created_by' => Auth::id(),
        ];
        $lead->custom_documents = $items;
        $lead->save();

        return back()->with('success', 'Document added to this lead.');
    }

    /**
     * Remove a per-lead custom document row. Any files already uploaded against
     * it are kept — their checklist_key is nulled so they surface as orphans
     * rather than vanishing with the item definition.
     */
    public function removeCustomDocument(Request $request, $leadId, $key)
    {
        $lead = Lead::findOrFail($leadId);

        $items = is_array($lead->custom_documents) ? $lead->custom_documents : [];
        $lead->custom_documents = array_values(array_filter(
            $items,
            fn ($i) => ($i['key'] ?? null) !== $key
        )) ?: null;
        $lead->save();

        LeadDocument::where('lead_id', $lead->id)
            ->where('checklist_key', $key)
            ->update(['checklist_key' => null]);

        return back()->with('success', 'Document removed.');
    }

    public function staffChecklistUpload(Request $request, $leadId, $key)
    {
        $lead = Lead::findOrFail($leadId);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => UploadValidation::document(),
        ]);

        try {
            foreach ($request->file('files') as $file) {
                [$path, $displayName] = $this->storeChecklistFile($file, $lead, $key);

                LeadDocument::create([
                    'lead_id' => $lead->id,
                    'request_id' => null,
                    'checklist_key' => $key,
                    'original_name' => $displayName,
                    'file_path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'status' => LeadDocument::STATUS_SUBMITTED,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            $n = count($request->file('files'));

            // Findings depend on what's been uploaded — re-evaluate off the
            // request path (§8d). The job no-ops for non-cases.
            \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

            return back()->with('success', "{$n} ".($n === 1 ? 'file' : 'files').' uploaded.');
        } catch (\Throwable $e) {
            Log::error('Staff checklist upload failed', ['lead_id' => $leadId, 'key' => $key, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not upload that file.']);
        }
    }

    /**
     * Staff upload a file AGAINST a document request — for when the client hands
     * a requested document to staff directly (email, in person) instead of
     * uploading it themselves. The file is tied to the request (request_id) so it
     * fulfils that row, exactly as a client upload would.
     */
    public function staffRequestUpload(Request $request, $leadId, $requestId)
    {
        $lead = Lead::findOrFail($leadId);
        $docRequest = LeadDocumentRequest::where('lead_id', $lead->id)->findOrFail($requestId);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => UploadValidation::document(),
        ]);

        try {
            foreach ($request->file('files') as $file) {
                $path = $file->store("lead-documents/{$lead->id}", self::DISK);

                LeadDocument::create([
                    'lead_id' => $lead->id,
                    'request_id' => $docRequest->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'status' => LeadDocument::STATUS_SUBMITTED,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            $n = count($request->file('files'));
            \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

            return back()->with('success', "{$n} ".($n === 1 ? 'file' : 'files').' uploaded on behalf of the client.');
        } catch (\Throwable $e) {
            Log::error('Staff request upload failed', ['lead_id' => $leadId, 'request_id' => $requestId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not upload that file.']);
        }
    }

    /**
     * Generate a templated agreement (Blade -> PDF) and attach it to the
     * lead's documents under the matching checklist key. Routes by key:
     *   agree.consultancy        — Consultancy Agreement (Single | Partner)
     *   agree.engagement_english — English Engagement Agreement (no variant)
     */
    public function generateAgreement(Request $request, AgreementGenerator $generator, $leadId, $key)
    {
        $lead = Lead::findOrFail($leadId);

        $data = $request->validate([
            // 'variant' only meaningful for consultancy. Optional so the
            // engagement-english call doesn't need to include it.
            'variant' => 'nullable|in:single,partner',
        ]);

        try {
            if ($key === 'agree.consultancy') {
                $variant = $data['variant'] ?? 'single';
                $generator->consultancy($lead, $variant);

                return back()->with('success', "Consultancy Agreement generated ({$variant}).");
            }

            if ($key === 'agree.engagement_english') {
                $generator->englishEngagement($lead);

                return back()->with('success', 'English Engagement Agreement generated.');
            }

            return back()->withErrors(['error' => 'This checklist item does not support auto-generation.']);
        } catch (\Throwable $e) {
            Log::error('Agreement generation failed', [
                'lead_id' => $leadId,
                'key' => $key,
                'variant' => $data['variant'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not generate the agreement: '.$e->getMessage()]);
        }
    }

    /**
     * Unified generate endpoint for the "Proposal & Agreements" page.
     * Types:
     *   consultancy_std_150      → Scenario #1 STANDARD 150,000
     *   consultancy_voucher_150  → Scenario #2 WITH VOUCHER 150,000
     *   consultancy_std_100      → Scenario #3 STANDARD 100,000
     *   consultancy_english_100  → Scenario #3 WITH ENGLISH 100,000
     *   english_engagement       → English Engagement Agreement
     *
     * Optional POST body: school_enrolment_fee, english_proficiency_fee
     * — override the two editable amounts on the modal Settings panel.
     */
    public function generateDocument(Request $request, AgreementGenerator $generator, $leadId, $type)
    {
        $lead = Lead::findOrFail($leadId);

        try {
            // Immigration engagement docs (written agreement + IAA standards)
            // are backed by their own generator/templates.
            $engageType = $this->engagementTypeFor($type);
            if ($engageType !== null) {
                app(EngagementDocumentGenerator::class)->generate($lead, $engageType);
                $label = EngagementDocumentGenerator::DOCS[$engageType]['label'];

                return back()->with('success', "{$label} generated for {$lead->first_name} {$lead->last_name}.");
            }

            $consultancyScenario = $this->consultancyScenarioForType($type);
            $overrides = $this->feeOverridesFromRequest($request);

            $isEnglishAgreement = in_array($type, ['english_engagement', 'english_offshore', 'english_offshore_1000'], true);

            if ($consultancyScenario !== null
                || in_array($type, ['consultancy_onshore', 'consultancy_offshore', 'consultancy_offshore_zero'], true)
                || $isEnglishAgreement) {
                // Consultancy AND English agreements go through verification
                // BEFORE any PDF exists: submitting only snapshots the scenario +
                // fees + bank details onto the lead. The reviewer previews it
                // live; the PDF is generated and attached only on approval, then
                // posted to the client's tracking link.
                \App\Services\ConsultancyReviewService::submit($lead, $type, $overrides, optional($request->user())->id);

                // Notify via the Education "Consultancy agreement submitted for
                // verification" automation (a no-op unless an admin enabled it).
                $review = is_array($lead->fresh()->consultancy_review) ? $lead->fresh()->consultancy_review : [];
                $items = is_array($review['items'] ?? null) ? $review['items'] : [];
                app(\App\Services\EmailAutomationService::class)->fire('education.consultancy.submitted', $lead->fresh(), [
                    'agreement_type' => \App\Services\ConsultancyReviewService::scenarioLabel($type),
                    'total_amount' => array_sum(array_map(fn ($m) => (int) ($m['amount'] ?? 0), $items)),
                ]);

                $label = $isEnglishAgreement ? 'English agreement' : 'Consultancy Agreement';

                return back()->with('success', "{$label} submitted for verification — {$lead->first_name} {$lead->last_name}.");
            }

            return back()->withErrors(['error' => "Unknown document type: {$type}"]);
        } catch (\Throwable $e) {
            Log::error('Unified document generation failed', [
                'lead_id' => $leadId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not generate the document: '.$e->getMessage()]);
        }
    }

    /**
     * Move an already-generated English agreement into the Consultancy
     * Agreement Verification queue — the ⋮ "Send for verification" row action.
     *
     * Older English agreements were generated straight to a PDF (before English
     * joined the verification flow). This snapshots a fresh PENDING review for
     * the lead so it appears in the queue; the reviewer confirms/edits the fees
     * (they can't be recovered from the baked PDF, so they seed to the defaults)
     * and it's the approval that regenerates the client-facing PDF.
     */
    public function sendEnglishToVerification(Request $request, $leadId, $documentId)
    {
        $lead = Lead::findOrFail($leadId);
        $doc = LeadDocument::where('lead_id', $lead->id)->findOrFail($documentId);

        abort_unless(
            $doc->checklist_key === 'agree.engagement_english' && $doc->source === LeadDocument::SOURCE_GENERATED,
            422,
            'Only a generated English agreement can be sent for verification.'
        );

        // Already under review — don't clobber an in-flight review.
        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        if (in_array($review['status'] ?? null, ['pending', 'verified'], true)) {
            return back()->with('success', 'This agreement is already in the verification queue.');
        }

        // Infer the variant + currency from the stored document.
        $type = match ($doc->source_variant) {
            'engagement-english-offshore' => 'english_offshore',
            'engagement-english-offshore-1000' => 'english_offshore_1000',
            default => 'english_engagement',
        };
        $overrides = ['currency' => $type === 'english_engagement' ? 'php' : 'nzd'];

        \App\Services\ConsultancyReviewService::submit($lead, $type, $overrides, optional($request->user())->id);

        $fresh = is_array($lead->fresh()->consultancy_review) ? $lead->fresh()->consultancy_review : [];
        $items = is_array($fresh['items'] ?? null) ? $fresh['items'] : [];
        app(\App\Services\EmailAutomationService::class)->fire('education.consultancy.submitted', $lead->fresh(), [
            'agreement_type' => \App\Services\ConsultancyReviewService::scenarioLabel($type),
            'total_amount' => array_sum(array_map(fn ($m) => (int) ($m['amount'] ?? 0), $items)),
        ]);

        return back()->with('success', "English agreement sent for verification — {$lead->first_name} {$lead->last_name}.");
    }

    /**
     * Re-render an already-approved consultancy / English agreement PDF in place
     * from the amount currently stored on the lead's review — no re-verification.
     * Fixes file-vs-column drift when a fee was edited after the PDF was made.
     */
    public function regenerateAgreement(Request $request, $leadId, $documentId)
    {
        $lead = Lead::findOrFail($leadId);
        $doc = LeadDocument::where('lead_id', $lead->id)->findOrFail($documentId);

        abort_unless(
            in_array($doc->checklist_key, ['agree.consultancy', 'agree.engagement_english'], true)
                && $doc->source === LeadDocument::SOURCE_GENERATED,
            422,
            'Only a generated agreement can be regenerated.'
        );

        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        abort_if(empty($review) || empty($review['scenario']), 422, 'No stored agreement data to regenerate from.');

        try {
            $g = app(\App\Services\AgreementGenerator::class);
            $overrides = \App\Services\ConsultancyReviewService::overridesForGeneration($review);

            // Preserve the client's e-signature if the doc was already signed —
            // re-apply it to the re-rendered PDF so it stays a signed agreement.
            $clientSig = null;
            if ($doc->client_signature_path && Storage::disk(self::DISK)->exists($doc->client_signature_path)) {
                $ext = strtolower(pathinfo($doc->client_signature_path, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
                $clientSig = "data:{$mime};base64,".base64_encode(Storage::disk(self::DISK)->get($doc->client_signature_path));
            }
            if ($doc->engagement_signer_id) {
                $overrides['signer_id'] = $doc->engagement_signer_id;
            }

            // Build the real PDF payload (reuses the verification-preview mapping)
            // and render it with the current amounts.
            [$view, $payload] = \App\Services\ConsultancyReviewService::previewPayload($g, $lead, $review['scenario'], $overrides);
            unset($payload['preview']); // this is the final PDF, not the in-flow preview
            if ($clientSig) {
                $payload['client_signature'] = $clientSig;
                $payload['acknowledged'] = true;
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $payload)
                ->setPaper('a4')
                ->setOption('isPhpEnabled', true)
                ->output();

            // Overwrite the SAME file so the row + signed state stay intact.
            Storage::disk(self::DISK)->put($doc->file_path, $pdf);
            $doc->forceFill(['size' => strlen($pdf)])->save();

            return back()->with('success', 'Agreement PDF regenerated with the current amount.');
        } catch (\Throwable $e) {
            Log::error('Agreement regenerate failed', ['lead' => $leadId, 'doc' => $documentId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not regenerate the PDF.']);
        }
    }

    /**
     * Generate one or more immigration engagement documents at once —
     * driven by the "New" flow on the Engagement workspace. Each selected
     * type is rendered to a PDF and stored against the case.
     */
    public function generateEngagement(Request $request, EngagementDocumentGenerator $generator, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $data = $request->validate([
            'types' => 'required|array|min:1',
            'types.*' => ['string', Rule::in([...array_keys(EngagementDocumentGenerator::DOCS), 'invoice'])],
            'notify' => 'sometimes|boolean',
            'signer_id' => 'nullable|integer|exists:users,id',
            'assist_signer_id' => 'nullable|integer|exists:users,id',
            'fee_tier' => ['nullable', Rule::in(\App\Models\VisaType::FEE_TIERS)],
            'fee_location' => ['nullable', Rule::in(\App\Models\VisaType::FEE_LOCATIONS)],
            'include_gst' => 'sometimes|boolean',
            // Manual override for the (GST-exclusive) professional fee.
            'professional_fee' => 'nullable|numeric|min:0|max:9999999',
        ]);

        $overrides = [];
        if (! empty($data['signer_id'])) {
            $overrides['signer_id'] = $data['signer_id'];
        }
        if (! empty($data['assist_signer_id'])) {
            $overrides['assist_signer_id'] = $data['assist_signer_id'];
        }
        if (! empty($data['fee_tier'])) {
            $overrides['fee_tier'] = $data['fee_tier'];
        }
        if (! empty($data['fee_location'])) {
            $overrides['fee_location'] = $data['fee_location'];
        }
        if (! empty($data['include_gst'])) {
            $overrides['include_gst'] = true;
        }
        if (isset($data['professional_fee']) && $data['professional_fee'] !== null && $data['professional_fee'] !== '') {
            $overrides['professional_fee'] = (float) $data['professional_fee'];
        }

        try {
            // Never touch a document the client has already signed. Regenerating
            // a signed type would create a conflicting UNSIGNED copy alongside the
            // signed one and make a completed pack look unsigned again — so skip
            // those types entirely and leave the signed document exactly as it is.
            $alreadySigned = LeadDocument::where('lead_id', $lead->id)
                ->where('source_variant', 'like', 'engagement:%')
                ->whereNotNull('client_signed_at')
                ->pluck('source_variant')
                ->map(fn ($v) => str_replace('engagement:', '', $v))
                ->all();

            $typesToGenerate = array_values(array_diff($data['types'], $alreadySigned));
            $skippedSigned = array_values(array_intersect($data['types'], $alreadySigned));

            if (empty($typesToGenerate)) {
                return back()->with('success', 'Those documents are already signed by the client, so nothing was changed.');
            }

            // Replace-per-type: remove the previous UNSIGNED copies of the types
            // we're regenerating so a draft doesn't stack duplicates. Signed
            // documents are never touched (their signature must be preserved).
            LeadDocument::where('lead_id', $lead->id)
                ->whereIn('source_variant', array_map(fn ($t) => "engagement:{$t}", $typesToGenerate))
                ->whereNull('client_signed_at')
                ->get()
                ->each(function (LeadDocument $old) {
                    if ($old->file_path && Storage::disk(self::DISK)->exists($old->file_path)) {
                        Storage::disk(self::DISK)->delete($old->file_path);
                    }
                    $old->delete();
                });

            // Keep the created document id per type so the notification can
            // deep-link each icon straight to its generated PDF.
            $generatedDocIds = [];
            foreach ($typesToGenerate as $t) {
                if ($t === 'invoice') {
                    // The invoice is generated by the InvoiceGenerator off the
                    // same agreed fees, and stored as a normal invoice (so it
                    // also shows in the Invoice workspace). Replace any prior
                    // invoice so the case keeps a single current one.
                    LeadDocument::where('lead_id', $lead->id)
                        ->where('source_variant', 'invoice')
                        ->get()
                        ->each(function (LeadDocument $old) {
                            if ($old->file_path && Storage::disk(self::DISK)->exists($old->file_path)) {
                                Storage::disk(self::DISK)->delete($old->file_path);
                            }
                            $old->delete();
                        });

                    $doc = app(\App\Services\Immigration\InvoiceGenerator::class)
                        ->generate($lead, array_merge($this->invoiceOptionsFromEngagement($overrides), $this->planInvoiceAdjustments($lead)));
                } else {
                    $doc = $generator->generate($lead, $t, $overrides);
                }
                $generatedDocIds[$t] = $doc->id;
            }

            // Audit a manual fee adjustment — the adviser changed the professional
            // fee away from the visa-derived default. One clear line in the case's
            // activity trail ("adjusted from $X to $Y").
            if (isset($overrides['professional_fee'])) {
                $defaultFee = $generator->feeTotals($lead, array_merge($overrides, ['professional_fee' => null]))['professional_excl'];
                $newFee = (float) $overrides['professional_fee'];
                if ($defaultFee !== null && abs((float) $defaultFee - $newFee) > 0.005) {
                    \App\Models\ActivityLog::record('engagement.fee_adjusted', [
                        'description' => sprintf(
                            '%s adjusted the professional fee from $%s to $%s for "%s"',
                            auth()->user()?->name ?? 'Staff',
                            number_format((float) $defaultFee, 2),
                            number_format($newFee, 2),
                            trim("{$lead->first_name} {$lead->last_name}") ?: ('Lead #'.$lead->id),
                        ),
                        'properties' => [
                            'subject_type' => 'Lead',
                            'subject_id' => $lead->id,
                            'original_fee' => (float) $defaultFee,
                            'adjusted_fee' => $newFee,
                        ],
                    ]);
                }
            }

            // Record the fees this pack was generated at, so the Generated
            // Documents table can show the ex-GST professional fee AND the grand
            // total (our fees incl GST + INZ disbursements across the family) —
            // but NEVER move them once the Written Agreement is signed. The signed
            // agreement locks the amounts the client actually agreed to; a later
            // regeneration of the supporting docs must not overwrite them.
            if (! in_array('written_agreement', $alreadySigned, true)) {
                $totals = $generator->feeTotals($lead, $overrides);
                $lead->forceFill([
                    'engagement_fee_total' => $totals['professional_excl'],
                    'engagement_total_amount' => $totals['grand_total'],
                    // Remember the settings so re-opening the draft reproduces them.
                    'engagement_fee_location' => $data['fee_location'] ?? 'onshore',
                    'engagement_fee_tier' => $data['fee_tier'] ?? 'normal',
                    'engagement_include_gst' => (bool) ($data['include_gst'] ?? false),
                    'engagement_assist_signer_id' => $data['assist_signer_id'] ?? null,
                ])->save();
            }

            // Generating the written agreement moves the case forward to
            // "Agreement Sent" — but only from an early stage, so it never
            // downgrades an advanced or already-signed case. Signing then moves
            // it on to "Agreement Signed".
            if (in_array('written_agreement', $typesToGenerate, true)) {
                if ($lead->advanceImmigrationStage('Agreement Sent', ['For Assessment', 'Endorsed'], auth()->id())) {
                    \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);
                }
            }

            $n = count($typesToGenerate);
            $message = "{$n} engagement document(s) generated for {$lead->first_name} {$lead->last_name}.";
            if (! empty($skippedSigned)) {
                $message .= ' '.count($skippedSigned).' already-signed document(s) were left unchanged.';
            }

            // Optionally email the client that their documents are now available
            // on the scoped signing page. Sending marks the pack as no longer a
            // draft (records engagement_sent_at, which reveals the client link).
            // Saving as a draft must clear any previous "sent" flag so the status
            // reads Draft again — unless the pack already carries a signed
            // document, in which case its state is left intact.
            if (! empty($data['notify'])) {
                if (empty($lead->email)) {
                    $message .= ' Email not sent — no email on file.';
                } else {
                    $token = $lead->ensureEngagementSigningToken();
                    $signUrl = rtrim((string) config('app.url'), '/').'/engagement/'.$token;

                    // The "Engagement sent" automation message is the single source
                    // for this email — fire it with the signing link so the template
                    // can include {{engagement_url}}. Only when no client automation
                    // message is configured do we send the built-in email instead
                    // (so the client never gets two).
                    $firedClient = app(\App\Services\EmailAutomationService::class)
                        ->fire('immigration.engagement.sent', $lead, ['engagement_url' => $signUrl]);
                    if (! $firedClient) {
                        $this->notifyEngagement($lead, $token, $typesToGenerate, $generatedDocIds);
                    }

                    $lead->forceFill(['engagement_sent_at' => now()])->save();
                    $message .= " Client notified at {$lead->email}.";
                }
            } elseif (empty($alreadySigned)) {
                $lead->forceFill(['engagement_sent_at' => null])->save();
            }

            return back()->with('success', $message);
        } catch (\App\Exceptions\StaleSignerLicenceException $e) {
            // Expected refusal, not a fault — the chosen adviser's licence has
            // lapsed. Surface the clear message; do not log as an error.
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Engagement bulk generation failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not generate the documents: '.$e->getMessage()]);
        }
    }

    /**
     * Live fee totals for the engagement modal's receipt — the family-aware
     * professional-fee sum, INZ disbursement total and grand total for a given
     * tier/location/GST setting. Read-only JSON; the modal recomputes the
     * editable professional-fee override against these.
     */
    public function engagementFeeTotals(Request $request, EngagementDocumentGenerator $generator, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $overrides = [
            'fee_tier' => $request->query('fee_tier'),
            'fee_location' => $request->query('fee_location'),
            'include_gst' => filter_var($request->query('include_gst'), FILTER_VALIDATE_BOOLEAN),
        ];
        // Deliberately NOT passing professional_fee — the modal applies its own
        // (editable) override on top of the raw family sums returned here.

        return response()->json($generator->feeTotals($lead, $overrides));
    }

    /**
     * The case's family (costed dependants) with their in-agreement flag and
     * per-applicant fee (override + the visa default at the chosen
     * tier/location), plus the principal. Feeds the engagement modal's include
     * checkboxes and editable per-applicant amounts.
     */
    public function engagementFamily(Request $request, $leadId)
    {
        $lead = Lead::with('school')->findOrFail($leadId);

        $tier = in_array($request->query('fee_tier'), \App\Models\VisaType::FEE_TIERS, true) ? $request->query('fee_tier') : 'normal';
        $location = in_array($request->query('fee_location'), \App\Models\VisaType::FEE_LOCATIONS, true) ? $request->query('fee_location') : 'onshore';

        $principalVisa = $lead->inz_visa_type ? \App\Models\VisaType::where('name', $lead->inz_visa_type)->first() : null;

        $family = \App\Models\CaseDependent::where('lead_id', $lead->id)
            ->with(['visaType', 'linkedLead'])
            ->orderBy('created_at')
            ->get()
            // Only members that can be costed (they have a visa, directly or via
            // a linked case) belong on the agreement's fee list.
            ->filter(fn ($d) => $d->visaType || ($d->linkedLead && $d->linkedLead->inz_visa_type))
            ->map(function ($d) use ($tier, $location) {
                $vm = $d->linkedLead && $d->linkedLead->inz_visa_type
                    ? \App\Models\VisaType::where('name', $d->linkedLead->inz_visa_type)->first()
                    : $d->visaType;

                return [
                    'id' => $d->id,
                    'name' => trim("{$d->first_name} {$d->last_name}") ?: $d->fullName(),
                    'relationship' => $d->relationship,
                    'visa' => $d->linkedLead?->inz_visa_type ?: $d->visaType?->name,
                    'in_agreement' => (bool) $d->in_agreement,
                    'fee_override' => $d->fee_override !== null ? (float) $d->fee_override : null,
                    'default_fee' => $vm?->professionalFeeFor($tier, $location),
                    'disbursement_override' => $d->disbursement_override !== null ? (float) $d->disbursement_override : null,
                    'default_disbursement' => $vm?->inzFeeFor($location),
                ];
            })
            ->values();

        return response()->json([
            'principal' => [
                'id' => $lead->id,
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: ('Lead #'.$lead->id),
                'visa' => $lead->inz_visa_type,
                'fee_override' => $lead->principal_fee_override !== null ? (float) $lead->principal_fee_override : null,
                'default_fee' => $principalVisa?->professionalFeeFor($tier, $location),
                'disbursement_override' => $lead->principal_disbursement_override !== null ? (float) $lead->principal_disbursement_override : null,
                'default_disbursement' => $principalVisa?->inzFeeFor($location),
            ],
            'family' => $family,
        ]);
    }

    /** Toggle whether one family member is included on the agreement / invoice. */
    public function setEngagementFamilyInclusion(Request $request, $leadId, \App\Models\CaseDependent $dependent)
    {
        $lead = Lead::findOrFail($leadId);
        abort_unless($dependent->lead_id === $lead->id, 404);
        $data = $request->validate(['in_agreement' => ['required', 'boolean']]);
        $dependent->update(['in_agreement' => $data['in_agreement']]);

        return response()->json(['ok' => true, 'in_agreement' => $data['in_agreement']]);
    }

    /**
     * Set one applicant's professional fee (ex GST) on the engagement — the
     * principal or a dependant. Null clears the override (back to the visa fee).
     * Every change is written to the case activity trail.
     */
    public function setEngagementApplicantFee(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $data = $request->validate([
            'type' => ['required', \Illuminate\Validation\Rule::in(['principal', 'dependent'])],
            'dependent_id' => ['nullable', 'integer'],
            // Which per-applicant amount is being set: the professional fee, or
            // the INZ disbursement. Defaults to the professional fee.
            'column' => ['nullable', \Illuminate\Validation\Rule::in(['fee', 'disbursement'])],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $isDisb = ($data['column'] ?? 'fee') === 'disbursement';
        $leadCol = $isDisb ? 'principal_disbursement_override' : 'principal_fee_override';
        $depCol = $isDisb ? 'disbursement_override' : 'fee_override';
        $label = $isDisb ? 'INZ disbursement' : 'professional fee';

        $newFee = ($data['fee'] === null || $data['fee'] === '') ? null : (float) $data['fee'];

        if ($data['type'] === 'principal') {
            $who = trim("{$lead->first_name} {$lead->last_name}") ?: ('Lead #'.$lead->id);
            $old = $lead->{$leadCol} !== null ? (float) $lead->{$leadCol} : null;
            $lead->forceFill([$leadCol => $newFee])->save();
        } else {
            $dependent = \App\Models\CaseDependent::where('id', $data['dependent_id'])->where('lead_id', $lead->id)->first();
            abort_unless($dependent, 422, 'That family member is not on this case.');
            $who = trim("{$dependent->first_name} {$dependent->last_name}") ?: $dependent->fullName();
            $old = $dependent->{$depCol} !== null ? (float) $dependent->{$depCol} : null;
            $dependent->update([$depCol => $newFee]);
        }

        // Audit: one clear line per change ("set / cleared X's fee to $Y").
        \App\Models\ActivityLog::record('engagement.applicant_fee_set', [
            'description' => sprintf(
                '%s %s %s\'s %s%s',
                auth()->user()?->name ?? 'Staff',
                $newFee === null ? 'cleared' : 'set',
                $who,
                $label,
                $newFee === null
                    ? ($old !== null ? sprintf(' (was $%s) back to the visa fee', number_format($old, 2)) : ' to the visa fee')
                    : sprintf(' to $%s%s', number_format($newFee, 2), $old !== null ? sprintf(' (was $%s)', number_format($old, 2)) : ''),
            ),
            'properties' => [
                'subject_type' => 'Lead',
                'subject_id' => $lead->id,
                'applicant' => $who,
                'column' => $isDisb ? 'disbursement' : 'fee',
                'old_fee' => $old,
                'new_fee' => $newFee,
            ],
        ]);

        return response()->json(['ok' => true, 'column' => $isDisb ? 'disbursement' : 'fee', 'value' => $newFee]);
    }

    /**
     * Email the client the engagement signing link (from the manage-draft
     * modal). Marks the pack as sent so its client link is revealed and the
     * Draft status clears.
     */
    public function sendEngagement(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        if (empty($lead->email)) {
            return back()->withErrors(['error' => 'No email on file for this client.']);
        }

        $typeMap = LeadDocument::where('lead_id', $lead->id)
            ->where('source_variant', 'like', 'engagement:%')
            ->get()
            ->mapWithKeys(fn (LeadDocument $d) => [str_replace('engagement:', '', $d->source_variant) => $d->id])
            ->all();

        if (empty($typeMap)) {
            return back()->withErrors(['error' => 'No engagement documents to send.']);
        }

        $token = $lead->ensureEngagementSigningToken();
        $this->notifyEngagement($lead, $token, array_keys($typeMap), $typeMap);
        $lead->forceFill(['engagement_sent_at' => now()])->save();
        app(\App\Services\EmailAutomationService::class)->fire('immigration.engagement.sent', $lead, []);

        return back()->with('success', "Engagement sent to {$lead->email}.");
    }

    /**
     * Translate the engagement-pack overrides into invoice options so the
     * bundled invoice is priced off the same tier / location / GST as the
     * agreement, and a manual agreement fee flows into the service-fee line.
     */
    /**
     * Discount + admin fee for the invoice, taken from the case's live
     * installment plan (the authoritative onboarding money). Empty when there
     * is no plan, so a plain case invoice is unaffected. InvoiceGenerator adds
     * the negative discount line + the admin-fee line from these keys.
     *
     * @return array{discount_amount?: float, admin_fee?: float}
     */
    private function planInvoiceAdjustments(Lead $lead): array
    {
        $svc = app(\App\Services\Immigration\InstallmentPlanService::class);
        $plan = $svc->summary($svc->activeFor($lead));
        if (! $plan) {
            return [];
        }

        return [
            'discount_amount' => (float) $plan['discount_amount'],
            'admin_fee' => (float) $plan['admin_fee'],
        ];
    }

    private function invoiceOptionsFromEngagement(array $overrides): array
    {
        $o = [];
        if (! empty($overrides['fee_tier'])) {
            $o['fee_tier'] = $overrides['fee_tier'];
        }
        if (! empty($overrides['fee_location'])) {
            $o['fee_location'] = $overrides['fee_location'];
        }
        if (! empty($overrides['include_gst'])) {
            $o['include_gst'] = true;
        }
        // A manual agreement professional fee becomes the invoice's consulting
        // & service line (single applicant); family invoices stay per-applicant.
        if (isset($overrides['professional_fee'])) {
            $o['service_fee'] = (float) $overrides['professional_fee'];
        }

        return $o;
    }

    /**
     * Email the client that their engagement documents are ready. Prefers the
     * editable `migration_agreement` template (its "Open my documents" button is
     * filled with the scoped {{engagement_url}} signing link), and falls back to
     * the hardcoded EngagementDocumentsReady mail only if that template is gone.
     *
     * @param  array<int,string>  $types  Generated engagement doc keys (fallback only)
     * @param  array<string,int>  $docIds  doc key => LeadDocument id (fallback only)
     */
    private function notifyEngagement(Lead $lead, string $token, array $types, array $docIds): void
    {
        $template = \App\Models\MessageTemplate::active()
            ->where('key', 'migration_agreement')
            ->orderByRaw("CASE WHEN department = '' THEN 0 ELSE 1 END")
            ->first();

        if ($template) {
            $link = rtrim((string) config('app.url'), '/').'/engagement/'.$token;
            app(\App\Services\CommunicationService::class)->sendTemplate($template, $lead, ['engagement_url' => $link]);

            return;
        }

        // Template removed — keep the client notified via the original email.
        Mail::to($lead->email)->send(new \App\Mail\EngagementDocumentsReady($lead, $types, $docIds, $token));
    }

    /**
     * Pull the invoice settings (fees, dates, number) out of a request —
     * shared by the live preview (query string) and generate (form body).
     */
    private function invoiceOverridesFrom(Request $request): array
    {
        $out = [];
        foreach (['invoice_number', 'invoice_date', 'due_date', 'visa_label'] as $k) {
            if ($request->filled($k)) {
                $out[$k] = $request->input($k);
            }
        }
        // Pricing tier — drives the default service fee (discounted vs normal).
        if (in_array($request->input('fee_tier'), \App\Models\VisaType::FEE_TIERS, true)) {
            $out['fee_tier'] = $request->input('fee_tier');
        }
        // Applicant location — onshore vs offshore fee schedule.
        if (in_array($request->input('fee_location'), \App\Models\VisaType::FEE_LOCATIONS, true)) {
            $out['fee_location'] = $request->input('fee_location');
        }
        // Whether the service fee is quoted GST-inclusive.
        if ($request->has('include_gst')) {
            $out['include_gst'] = filter_var($request->input('include_gst'), FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['service_fee', 'inz_fee'] as $k) {
            $v = $request->input($k);
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $out[$k] = (float) $v;
            }
        }
        if ($request->has('include_disbursement')) {
            $out['include_disbursement'] = filter_var($request->input('include_disbursement'), FILTER_VALIDATE_BOOLEAN);
        }

        // Custom line items — a JSON string on the preview (query string)
        // or a real array on generate (form body).
        $items = $request->input('items');
        if (is_string($items) && $items !== '') {
            $items = json_decode($items, true);
        }
        if (is_array($items)) {
            $out['items'] = $items;
        }

        return $out;
    }

    /** Live HTML preview of the tax invoice (feeds the New-invoice modal). */
    public function previewInvoice(Request $request, \App\Services\Immigration\InvoiceGenerator $generator, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        return response($generator->renderHtml($lead, $this->invoiceOverridesFrom($request)))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Generate + store the tax invoice for a case. */
    public function generateInvoice(Request $request, \App\Services\Immigration\InvoiceGenerator $generator, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        $request->validate([
            'invoice_number' => 'nullable|string|max:40',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'service_fee' => 'nullable|numeric|min:0',
            'inz_fee' => 'nullable|numeric|min:0',
            'items' => 'nullable|array|max:30',
            'items.*.description' => 'nullable|string|max:600',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        try {
            $doc = $generator->generate($lead, $this->invoiceOverridesFrom($request));

            $this->emailInvoice($lead, $doc);

            return back()->with('success', "Invoice {$doc->invoice_number} generated for {$lead->first_name} {$lead->last_name}.");
        } catch (\Throwable $e) {
            Log::error('Invoice generation failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not generate the invoice: '.$e->getMessage()]);
        }
    }

    /**
     * Email the client their invoice on generation (key: send_invoice), with the
     * invoice PDF attached. Best-effort — a mail failure must never fail the
     * generate, and it no-ops if the template is missing or there's no email.
     */
    private function emailInvoice(Lead $lead, LeadDocument $doc): void
    {
        try {
            if (empty($lead->email)) {
                return;
            }

            $template = \App\Models\MessageTemplate::active()
                ->where('key', 'send_invoice')
                ->orderByRaw("CASE WHEN department = '' THEN 0 ELSE 1 END")
                ->first();
            if (! $template) {
                return;
            }

            app(\App\Services\CommunicationService::class)->sendTemplate($template, $lead, [], [
                ['path' => $doc->file_path, 'name' => 'Invoice '.$doc->invoice_number.'.pdf'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('send_invoice email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /** Delete every generated invoice for a case (one row = one case). */
    public function destroyInvoices(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        try {
            $docs = LeadDocument::where('lead_id', $lead->id)
                ->where('source_variant', 'invoice')
                ->get();

            foreach ($docs as $doc) {
                if ($doc->file_path && Storage::disk(self::DISK)->exists($doc->file_path)) {
                    Storage::disk(self::DISK)->delete($doc->file_path);
                }
                $doc->delete();
            }

            return back()->with('success', $docs->count()." invoice(s) deleted for {$lead->first_name} {$lead->last_name}.");
        } catch (\Throwable $e) {
            Log::error('Invoice delete failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not delete the invoices.']);
        }
    }

    /**
     * Delete every generated engagement document for a case in one call —
     * the Engagement workspace shows one row per case, so its Delete action
     * clears that case's whole engagement pack (files + rows, plus any
     * captured client signature).
     */
    public function destroyEngagementDocs(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        try {
            // Delete the whole engagement pack: the 4 pack documents
            // (engagement:*) AND the invoice generated with them (source_variant
            // "invoice"), which is the pack's 5th document. Client-uploaded
            // proofs of payment and older standalone tax invoices are left alone.
            $docs = LeadDocument::where('lead_id', $lead->id)
                ->where(function ($q) {
                    $q->where('source_variant', 'like', 'engagement:%')
                        ->orWhere('source_variant', 'invoice');
                })
                ->get();

            foreach ($docs as $doc) {
                foreach ([$doc->file_path, $doc->client_signature_path] as $path) {
                    if ($path && Storage::disk(self::DISK)->exists($path)) {
                        Storage::disk(self::DISK)->delete($path);
                    }
                }
                $doc->delete();
            }

            $n = $docs->count();

            return back()->with('success', "{$n} engagement document(s) deleted for {$lead->first_name} {$lead->last_name}.");
        } catch (\Throwable $e) {
            Log::error('Engagement docs delete failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not delete the documents.']);
        }
    }

    /**
     * Resolve a URL `type` of the form `engage_<key>` to its engagement
     * document key, or null when it isn't an engagement document.
     */
    private function engagementTypeFor(string $type): ?string
    {
        if (! str_starts_with($type, 'engage_')) {
            return null;
        }

        $key = substr($type, strlen('engage_'));

        return array_key_exists($key, EngagementDocumentGenerator::DOCS) ? $key : null;
    }

    /** Map a URL type to its consultancy scenario key, or null if not a consultancy. */
    private function consultancyScenarioForType(string $type): ?string
    {
        return match ($type) {
            'consultancy_std_150' => 'std_150',
            'consultancy_voucher_150' => 'voucher_150',
            'consultancy_std_100' => 'std_100',
            'consultancy_english_100' => 'english_100',
            default => null,
        };
    }

    /**
     * Pull school_enrolment_fee + english_proficiency_fee out of the
     * request (query string on GET preview, form body on POST generate)
     * and coerce them to positive integers. Missing keys stay unset so
     * the generator falls back to defaults.
     */
    private function feeOverridesFromRequest(Request $request): array
    {
        $out = [];
        foreach (['school_enrolment_fee', 'english_proficiency_fee', 'english_fee', 'pte_fee'] as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '' && is_numeric($val) && (int) $val > 0) {
                $out[$key] = (int) $val;
            }
        }
        // Single vs Couple pick — only honoured by the generator for
        // scenarios that support both. Anything else is coerced back
        // to 'single' server-side.
        $mode = $request->input('applicant_mode');
        if (in_array($mode, ['single', 'couple'], true)) {
            $out['applicant_mode'] = $mode;
        }

        // Currency for the document's amounts (php | nzd). Only the symbol
        // changes — amounts are entered by staff, never converted. Default
        // php keeps existing documents identical.
        $currency = $request->input('currency');
        if (in_array($currency, ['php', 'nzd'], true)) {
            $out['currency'] = $currency;
        }

        // Editable bank details (ANZ / RCBC preset or fully custom). Blank
        // fields are left unset so the generator falls back to its RCBC
        // defaults. Capped so a stray value can't bloat the PDF.
        foreach (['bank_heading', 'bank_name', 'bank_account_name', 'bank_account_number', 'bank_reference'] as $key) {
            $val = $request->input($key);
            if (is_string($val) && trim($val) !== '') {
                $clean = mb_substr(trim($val), 0, 120);
                // The reference already renders with a leading '#' — drop a
                // pasted one so it doesn't double up (##ref).
                if ($key === 'bank_reference') {
                    $clean = ltrim($clean, '#');
                }
                $out[$key] = $clean;
            }
        }

        return $out;
    }

    /** Symbol for a currency code — 'NZ$' for nzd, 'Php' otherwise. */
    private function currencySymbolFor(?string $currency): string
    {
        return $currency === 'nzd' ? 'NZ$' : 'Php';
    }

    /**
     * Render the same Blade template that generateDocument() will run
     * through dompdf, but return it as raw HTML for iframe preview. Lets
     * the "New" modal on the Proposal & Agreements page show a live
     * preview that updates as staff pick lead + type — dompdf is way
     * too slow to spin up on every keystroke.
     */
    public function previewDocument(Request $request, AgreementGenerator $generator, $leadId, $type)
    {
        $lead = Lead::findOrFail($leadId);

        // The invoice rides in the engagement pack but renders through the
        // InvoiceGenerator, priced off the same tier / location / GST / fee.
        if ($type === 'engage_invoice') {
            $engOverrides = [];
            if (in_array($request->query('fee_tier'), \App\Models\VisaType::FEE_TIERS, true)) {
                $engOverrides['fee_tier'] = $request->query('fee_tier');
            }
            if (in_array($request->query('fee_location'), \App\Models\VisaType::FEE_LOCATIONS, true)) {
                $engOverrides['fee_location'] = $request->query('fee_location');
            }
            if (filter_var($request->query('include_gst'), FILTER_VALIDATE_BOOLEAN)) {
                $engOverrides['include_gst'] = true;
            }
            $profFee = $request->query('professional_fee');
            if ($profFee !== null && $profFee !== '' && is_numeric($profFee)) {
                $engOverrides['professional_fee'] = (float) $profFee;
            }
            $html = app(\App\Services\Immigration\InvoiceGenerator::class)
                ->renderHtml($lead, $this->invoiceOptionsFromEngagement($engOverrides));

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        }

        // Immigration engagement docs render through their own generator.
        $engageType = $this->engagementTypeFor($type);
        if ($engageType !== null) {
            $signer = $request->query('signer');
            $assistSigner = $request->query('assist_signer');
            $tier = $request->query('fee_tier');
            $location = $request->query('fee_location');
            $opts = [];
            if ($signer) {
                $opts['signer_id'] = (int) $signer;
            }
            if ($assistSigner) {
                $opts['assist_signer_id'] = (int) $assistSigner;
            }
            if (in_array($tier, \App\Models\VisaType::FEE_TIERS, true)) {
                $opts['fee_tier'] = $tier;
            }
            if (in_array($location, \App\Models\VisaType::FEE_LOCATIONS, true)) {
                $opts['fee_location'] = $location;
            }
            if (filter_var($request->query('include_gst'), FILTER_VALIDATE_BOOLEAN)) {
                $opts['include_gst'] = true;
            }
            $profFee = $request->query('professional_fee');
            if ($profFee !== null && $profFee !== '' && is_numeric($profFee)) {
                $opts['professional_fee'] = (float) $profFee;
            }
            $html = app(EngagementDocumentGenerator::class)->renderHtml($lead, $engageType, $opts);

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $overrides = $this->feeOverridesFromRequest($request);
        $consultancyScenario = $this->consultancyScenarioForType($type);

        if ($type === 'consultancy_onshore') {
            $payload = $generator->buildOnshoreEngagementPayload($lead, $overrides);
            $payload['preview'] = true;   // in-flow logo, no PDF-only running footer
            $view = 'agreements.onshore-engagement';
        } elseif ($type === 'consultancy_offshore' || $type === 'consultancy_offshore_zero') {
            $offshoreOverrides = $type === 'consultancy_offshore_zero'
                ? array_merge($overrides, ['zero_fees' => true])
                : $overrides;
            $payload = $generator->buildOffshorePayload($lead, $offshoreOverrides);
            $payload['preview'] = true;
            $view = 'agreements.consultancy-offshore';
        } elseif ($consultancyScenario !== null) {
            [$payload] = $generator->buildConsultancyPayload($lead, $consultancyScenario, $overrides);
            $payload['preview'] = true;   // in-flow logo, no PDF-only running footer
            $view = 'agreements.consultancy';
        } elseif ($type === 'english_engagement') {
            $view = 'agreements.engagement-english';
            $payload = $this->englishEngagementPayload($lead, $overrides['currency'] ?? 'php', (int) ($overrides['english_fee'] ?? 14500), (int) ($overrides['pte_fee'] ?? 240))
                + $generator->englishBankVars($overrides);
        } elseif ($type === 'english_offshore' || $type === 'english_offshore_1000') {
            $view = 'agreements.engagement-english-offshore';
            $ov = $overrides;
            if ($type === 'english_offshore_1000') {
                $ov['offshore_variant'] = 'package_1000';
            }
            $payload = $generator->buildEnglishOffshorePayload($lead, $overrides['currency'] ?? 'nzd', $ov);
            $payload['preview'] = true;
        } else {
            return response('<html><body style="font-family:sans-serif;padding:2rem;color:#666">Unknown document type.</body></html>', 400)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response(view($view, $payload)->render())
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function englishEngagementPayload(Lead $lead, string $currency = 'php', int $englishFee = 14500, int $pteFee = 240): array
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");

        // Company signatory — same rule as consultancy: pre-sign with
        // the current staff member. Fallbacks keep old renders looking
        // like the original template.
        $signer = \Illuminate\Support\Facades\Auth::user();

        return [
            'client_name' => $clientName,
            'client_reference' => \Illuminate\Support\Str::slug($clientName ?: 'ClientName', '') ?: 'ClientName',
            'signer_name' => $signer?->name ?: 'Neil Bryan Escaner',
            'signer_mobile' => $signer?->phone ?: '+63945 107 6871',
            'signer_signature' => method_exists($signer, 'signatureDataUriTrimmed') ? $signer->signatureDataUriTrimmed() : null,
            'generated_at' => now(),
            'generated_at_formatted' => now()->format('jS').' day of '.now()->format('F Y'),
            'currency' => $currency === 'nzd' ? 'nzd' : 'php',
            'currency_symbol' => $this->currencySymbolFor($currency),
            'english_fee' => $englishFee,
            'pte_fee' => $pteFee,
        ];
    }

    /**
     * Save the staff-proposed program shortlist for a lead. Not a document —
     * this is what the "Proposal" tab on the Proposal & Agreements page
     * builds up: up to 3 program IDs stored on `leads.proposed_program_ids`
     * so the tracker can render them for the lead to choose from.
     *
     * An empty array clears the proposal.
     */
    public function saveProposal(Request $request, $leadId)
    {
        $validated = $request->validate([
            'program_ids' => 'nullable|array|max:5',
            'program_ids.*' => 'integer|exists:programs,id',
            // Per-program "why this program" reasons, keyed by program id.
            'reasons' => 'nullable|array',
            'reasons.*' => 'nullable|string|max:1000',
        ]);

        try {
            $lead = Lead::findOrFail($leadId);
            // Uniquify + reindex so the JSON stays clean regardless of
            // client-side ordering / duplicates.
            $ids = array_values(array_unique(array_map('intval', $validated['program_ids'] ?? [])));

            // Keep only reasons for programs that are actually in the shortlist,
            // keyed by (string) program id, trimmed, blanks dropped.
            $reasons = [];
            foreach (($validated['reasons'] ?? []) as $pid => $text) {
                $pid = (int) $pid;
                $text = trim((string) $text);
                if ($text !== '' && in_array($pid, $ids, true)) {
                    $reasons[(string) $pid] = $text;
                }
            }

            // Legacy safety: a lead whose active proposal predates versioning
            // has no history row yet. Snapshot that existing shortlist BEFORE
            // overwriting so it isn't lost the first time it's touched.
            $existing = is_array($lead->proposed_program_ids)
                ? array_values(array_map('intval', $lead->proposed_program_ids))
                : [];
            if (! empty($existing) && ! $lead->proposals()->exists()) {
                \App\Models\LeadProposal::create([
                    'lead_id' => $lead->id,
                    'program_ids' => $existing,
                    'created_by' => null,
                ]);
            }

            // A genuinely new/different proposal (not a no-op re-save of the
            // same set) supersedes the current one.
            $latest = $lead->proposals()->first();
            $latestIds = $latest ? array_values(array_map('intval', $latest->program_ids ?? [])) : null;
            $isNewVersion = ! empty($ids) && $latestIds !== $ids;

            if ($isNewVersion) {
                // Freeze the current selection onto the version being
                // superseded, so history keeps "what they'd chosen", then
                // start the new proposal with NOTHING selected by default.
                if ($latest && $lead->preferred_program_id) {
                    $latest->update(['selected_program_id' => (int) $lead->preferred_program_id]);
                }
                $lead->preferred_program_id = null;
                $lead->preferred_program_chosen_at = null;
            }

            $lead->proposed_program_ids = $ids ?: null;
            $lead->proposed_program_reasons = $reasons ?: null;

            // Study proposals now go through verification (Dinah's Program
            // Verification module) before they reach the client. Saving a
            // non-empty shortlist submits it as PENDING — it stays hidden on the
            // tracker and no email fires until it's approved. Clearing resets it.
            if (! empty($ids)) {
                $lead->proposal_review = [
                    'status' => 'pending',
                    'submitted_at' => now()->toIso8601String(),
                    'submitted_by' => optional($request->user())->id,
                ];
            } else {
                $lead->proposal_review = null;
            }
            $lead->save();

            // Snapshot the new version. Clearing (empty list) only resets the
            // active shortlist — it never adds a history row.
            if ($isNewVersion) {
                \App\Models\LeadProposal::create([
                    'lead_id' => $lead->id,
                    'program_ids' => $ids,
                    'reasons' => $reasons ?: null,
                    'created_by' => optional($request->user())->id,
                ]);
            }

            $count = count($ids);

            // Notify via the Education "Proposal submitted for verification"
            // automation (a no-op unless an admin has enabled a message for it).
            if ($count > 0) {
                $titles = \App\Models\Program::whereIn('id', $ids)->orderBy('title')->pluck('title')->all();
                app(\App\Services\EmailAutomationService::class)->fire('education.proposal.submitted', $lead->fresh(), [
                    'program_list' => implode(', ', $titles),
                    'program_count' => $count,
                ]);
            }

            $msg = $count === 0
                ? "Proposal cleared for {$lead->first_name} {$lead->last_name}."
                : "Submitted {$count} program".($count === 1 ? '' : 's').' for verification — the client sees them once approved.';

            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            Log::error('Save proposal failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not save the proposal: '.$e->getMessage()]);
        }
    }

    /**
     * "Your document is ready" nudge — sends the lead the queued
     * DocumentReadyNotification pointing at their /track/{code} page.
     * Called from the Notify button on the Proposal & Agreements table
     * after staff has generated a document.
     */
    public function notifyDocumentReady(Request $request, $leadId)
    {
        $validated = $request->validate([
            'kind' => 'nullable|string|in:proposal,agreement',
            // Optional: the shortlisted program the lead settled on. Only
            // meaningful for proposals; must be one of the lead's picks.
            'preferred_program_id' => 'nullable|integer',
        ]);

        try {
            $lead = Lead::findOrFail($leadId);

            if (empty($lead->email)) {
                return back()->withErrors(['error' => "{$lead->first_name} {$lead->last_name} has no email on file — cannot notify."]);
            }
            if (empty($lead->tracking_code)) {
                return back()->withErrors(['error' => 'Lead has no tracking code — cannot build the tracker URL.']);
            }

            $kind = $validated['kind'] ?? 'agreement';

            // Record which program the lead chose, when staff pass it from the
            // Notify modal. Only for proposals, and only if it's in the lead's
            // shortlist (null clears the pick). Mirrors the tracker's own
            // "Choose this one" write so the list highlight stays consistent.
            if ($kind === 'proposal' && array_key_exists('preferred_program_id', $validated)) {
                $pid = $validated['preferred_program_id'];
                $shortlist = is_array($lead->proposed_program_ids)
                    ? array_map('intval', $lead->proposed_program_ids)
                    : [];

                if ($pid === null || in_array((int) $pid, $shortlist, true)) {
                    $lead->forceFill([
                        'preferred_program_id' => $pid ? (int) $pid : null,
                        'preferred_program_chosen_at' => $pid ? now() : null,
                    ])->saveQuietly();
                }
            }

            // Sending advances the pipeline stage — proposal → "Proposal Sent",
            // agreement → "Consultancy Agreement Sent". Non-regressing: a lead
            // already further along (e.g. Consultancy Agreement Signed, Visa
            // Process) is left where it is.
            $sentStage = $kind === 'proposal' ? 'Proposal Sent' : 'Consultancy Agreement Sent';
            $stages = \App\Models\Lead::STAGES;
            $curIdx = array_search($lead->status, $stages, true);
            $tgtIdx = array_search($sentStage, $stages, true);
            if ($tgtIdx !== false && ($curIdx === false || $curIdx < $tgtIdx)) {
                $lead->status = $sentStage;
                $lead->save();
            }

            // Each notify kind has its own branded template, editable under
            // Email → Templates. Proposals also carry the lead's up-to-3
            // suggested programs. Falls back to the plain Mailable if the
            // template is missing/inactive or has no email channel.
            $templateKey = $kind === 'proposal' ? 'program_proposal' : 'consultancy_agreement';
            $vars = $kind === 'proposal' ? $this->proposalProgramVars($lead) : [];

            $res = app(\App\Services\CommunicationService::class)
                ->sendTemplated($templateKey, $lead, $vars);

            if (! $res['email']) {
                Mail::to($lead->email)->send(new \App\Mail\DocumentReadyNotification($lead, $kind));
            }

            // Start the post-proposal feedback drip (day 1/2/5) on an actual
            // proposal send, regardless of whether the stage transitioned.
            if ($kind === 'proposal') {
                \App\Jobs\SendLeadFollowupEmail::scheduleProposalDrip($lead);
            }

            return back()->with('success', "Notification sent to {$lead->first_name} {$lead->last_name}.");
        } catch (\Throwable $e) {
            Log::error('Document-ready notification failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not send the notification: '.$e->getMessage()]);
        }
    }

    /**
     * Build the Study Proposal template context from the lead's
     * proposed_program_ids.
     *
     *  - {{program_options}} : the whole "Recommended Programs" block as HTML —
     *    ONLY the selected programs, one "Option N:" line each (1..5). This is
     *    the flexible token: 1 program → 1 line, 3 → 3 lines, no blank slots.
     *  - {{program_1}}..{{program_5}} : the same values as individual plain-text
     *    lines, kept for older templates. Unselected slots are empty strings.
     */
    /**
     * Send the client the "Study Proposal ready" email and advance the pipeline
     * to "Proposal Sent". Shared by the Notify button and the Program
     * Verification approval step. Safe no-op when the lead has no email.
     */
    public function sendProposalReadyEmail(Lead $lead, bool $sendMail = true): bool
    {
        if (empty($lead->email)) {
            return false;
        }

        $stages = \App\Models\Lead::STAGES;
        $curIdx = array_search($lead->status, $stages, true);
        $tgtIdx = array_search('Proposal Sent', $stages, true);
        if ($tgtIdx !== false && ($curIdx === false || $curIdx < $tgtIdx)) {
            $lead->status = 'Proposal Sent';
            $lead->save();
        }

        // Kick off the day 1/2/5 feedback drip now that the proposal is being
        // sent — even when the lead was already in "Proposal Sent" (so the
        // status-change hook wouldn't have fired).
        \App\Jobs\SendLeadFollowupEmail::scheduleProposalDrip($lead);

        // A configured client automation may already own the "proposal ready"
        // email — in that case advance the pipeline but skip the built-in send
        // so the client isn't emailed twice.
        if (! $sendMail) {
            return true;
        }

        $res = app(\App\Services\CommunicationService::class)
            ->sendTemplated('program_proposal', $lead, $this->proposalProgramVars($lead));

        if (! ($res['email'] ?? null)) {
            Mail::to($lead->email)->send(new \App\Mail\DocumentReadyNotification($lead, 'proposal'));
        }

        return true;
    }

    /**
     * Send the client the consultancy-agreement email and advance the pipeline
     * to "Consultancy Agreement Sent". Called when a consultancy agreement is
     * APPROVED in verification (was previously fired on generate). `$sendMail`
     * false advances the status but skips the built-in send (so a configured
     * client automation can own the email without a double-send).
     */
    public function sendConsultancyAgreementEmail(Lead $lead, bool $sendMail = true): bool
    {
        $stages = \App\Models\Lead::STAGES;
        $curIdx = array_search($lead->status, $stages, true);
        $tgtIdx = array_search('Consultancy Agreement Sent', $stages, true);
        if ($tgtIdx !== false && ($curIdx === false || $curIdx < $tgtIdx)) {
            $lead->status = 'Consultancy Agreement Sent';
            $lead->save();
        }

        if (! $sendMail || empty($lead->email)) {
            return false;
        }

        app(\App\Services\CommunicationService::class)->sendTemplated('consultancy_agreement', $lead);

        return true;
    }

    private function proposalProgramVars(Lead $lead): array
    {
        $ids = is_array($lead->proposed_program_ids) ? $lead->proposed_program_ids : [];

        $lines = \App\Models\Program::whereIn('id', $ids)
            ->get(['id', 'title', 'level', 'price_text'])
            ->keyBy('id');

        $vars = [
            'program_1' => '', 'program_2' => '', 'program_3' => '',
            'program_4' => '', 'program_5' => '',
        ];
        $optionsHtml = '';

        $slot = 1;
        foreach ($ids as $pid) {
            if ($slot > 5) {
                break;
            }
            $p = $lines->get($pid);
            if (! $p) {
                continue;
            }

            $parts = array_filter([
                $p->title,
                $p->level ? "Level {$p->level}" : null,
                $p->price_text ?: null,
            ]);
            $text = implode(' · ', $parts);
            $vars['program_'.$slot] = $text;
            // Escape the dynamic text; the surrounding markup is ours.
            $optionsHtml .= '<p style="margin:4px 0;"><strong>Option '.$slot.':</strong> '.e($text).'</p>';
            $slot++;
        }

        $vars['program_options'] = $optionsHtml;

        return $vars;
    }

    /**
     * Delete a single uploaded file. Removes the storage object too so the
     * lead's private folder doesn't bloat with orphan files.
     */
    public function destroyDocument(Request $request, $leadId, $docId)
    {
        $doc = LeadDocument::where('lead_id', $leadId)->findOrFail($docId);
        try {
            Storage::disk(self::DISK)->exists($doc->file_path)
                ? Storage::disk(self::DISK)->delete($doc->file_path)
                : null;
            $name = $doc->original_name ?: 'file';
            $doc->delete();

            // Surfaces in the portals' Updated column — deleting a client's
            // file is exactly the kind of action that column exists to show.
            $doc->lead?->recordStaffActivity('Deleted file '.$name);

            return back()->with('success', 'File removed.');
        } catch (\Throwable $e) {
            Log::error('Document delete failed', ['doc_id' => $docId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not delete that file.']);
        }
    }

    /**
     * Lead acknowledges they've read and agreed to the Consultancy +
     * English Engagement Agreement terms. Sets / clears a single
     * timestamp on the lead so staff can see when it happened.
     */
    public function leadAcknowledgeAgreements(Request $request)
    {
        $user = Auth::user();
        $lead = $user?->lead;
        abort_unless($lead, 403);

        $data = $request->validate([
            'acknowledged' => 'required|boolean',
        ]);

        $lead->update([
            'agreements_acknowledged_at' => $data['acknowledged'] ? now() : null,
        ]);

        return back()->with('success', $data['acknowledged']
            ? 'Agreements acknowledged.'
            : 'Acknowledgment cleared.');
    }

    // ── LEAD (own portal) ───────────────────────────────────────────────────

    /**
     * The client whose portal is being viewed. Normally the signed-in lead;
     * for an admin/super-admin previewing the client portal, the preview lead
     * (same session key the tracker/requirements preview uses).
     */
    private function portalLead(): ?Lead
    {
        $user = Auth::user();
        if ($user?->lead) {
            return $user->lead;
        }
        if ($user && $user->isAtLeast('admin')) {
            $id = request('preview_lead') ?: session('lead_portal_preview_id');
            if ($id && ($lead = Lead::find($id))) {
                session(['lead_portal_preview_id' => $lead->id]);

                return $lead;
            }

            return Lead::whereHas('portalUser')->latest()->first()
                ?? Lead::where('is_immigration_case', true)->latest()->first()
                ?? Lead::latest()->first();
        }

        return null;
    }

    public function leadIndex()
    {
        $lead = $this->portalLead();
        abort_unless($lead, 403);

        $requests = $lead->documentRequests()
            ->with('latestDocument')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeadDocumentRequest $r) => [
                'id' => $r->id,
                'label' => $r->label,
                'description' => $r->description,
                'required' => $r->required,
                // 'rfi' groups these under a "Request Information" section the
                // client uploads their INZ-requested documents into.
                'origin' => $r->origin,
                'requested_at' => $r->requested_at,
                'latest_document' => $r->latestDocument ? $this->docSerialize($r->latestDocument) : null,
            ]);

        $sharedByStaff = LeadDocument::where('lead_id', $lead->id)
            ->where('status', LeadDocument::STATUS_STAFF_SHARED)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeadDocument $d) => $this->docSerialize($d));

        // Files uploaded against Documents-tab checklist items, grouped by
        // the item's frontend key so the gated section flow can render them.
        $checklistFiles = LeadDocument::where('lead_id', $lead->id)
            ->whereNotNull('checklist_key')
            ->orderBy('created_at')
            ->get()
            ->groupBy('checklist_key')
            ->map(fn ($files) => $files->map(fn ($f) => [
                'id' => $f->id,
                'original_name' => $f->original_name,
                'mime' => $f->mime,
                'size' => $f->size,
                'status' => $f->status,
                'source' => $f->source,
                'source_variant' => $f->source_variant,
                'created_at' => $f->created_at,
            ])->values());

        // The case's own visa-type checklist, grouped by category — the SAME
        // list staff review on the case profile, so what the client uploads
        // lines up with what the adviser expects. Hidden items and the special
        // rows shown elsewhere are pulled out: 'rfi' → Request Information
        // section; 'svf' (the Visa Information Form) → its own first row under
        // Immigration Team.
        $checklist = [];
        $vifItem = null;
        if ($lead->is_immigration_case) {
            $grouped = app(\App\Services\Immigration\CaseChecklistService::class)->groupedByCategory($lead);
            foreach ($grouped as $category => $items) {
                foreach ($items as $it) {
                    if (($it['key'] ?? null) === 'svf' && empty($it['hidden'])) {
                        $vifItem = $it;
                    }
                }
                $visible = array_values(array_filter(
                    $items,
                    fn ($i) => empty($i['hidden']) && ! in_array($i['key'] ?? null, ['rfi', 'svf'], true),
                ));
                if ($visible) {
                    $checklist[$category] = $visible;
                }
            }
        }

        return inertia('portal/lead/Documents', [
            'lead' => [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'agreements_acknowledged_at' => $lead->agreements_acknowledged_at,
                // RFI response deadline (if the case is in Request for Information)
                // — shown on the client's Documents page so they know the due date.
                'rfi_deadline' => optional($lead->rfi_deadline)->toDateString(),
            ],
            'requests' => $requests,
            'shared_by_staff' => $sharedByStaff,
            // The Visa Information Form checklist item — rendered first under
            // Immigration Team (same function as the "Student Visa Information Form").
            'vifItem' => $vifItem,
            'checklist' => $checklist,
            'checklistFiles' => $checklistFiles,
            'sectionVerifications' => $lead->section_verifications ?? [],
        ]);
    }

    /**
     * Lead uploads one-or-many files against a Documents-tab checklist
     * item from their own portal.
     */
    public function leadChecklistUpload(Request $request, $key)
    {
        $user = Auth::user();
        $lead = $user?->lead;
        abort_unless($lead, 403);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => UploadValidation::document(),
        ]);

        try {
            $lastDoc = null;
            foreach ($request->file('files') as $file) {
                [$path, $displayName] = $this->storeChecklistFile($file, $lead, $key);

                $lastDoc = LeadDocument::create([
                    'lead_id' => $lead->id,
                    'request_id' => null,
                    'checklist_key' => $key,
                    'original_name' => $displayName,
                    'file_path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'status' => LeadDocument::STATUS_SUBMITTED,
                    'uploaded_by' => $user->id,
                ]);
            }

            // One notification per upload batch (not per file).
            if ($lastDoc) {
                $this->notifyDocumentSubmitted($lead, $lastDoc);
            }

            // Client upload changes the findings picture — re-evaluate (§8d).
            \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);

            return back()->with('success', 'Document uploaded. Our team will review it shortly.');
        } catch (\Throwable $e) {
            Log::error('Lead checklist upload failed', ['lead_id' => $lead->id, 'key' => $key, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Upload failed. Please try again.']);
        }
    }

    /** Client removes one of their OWN uploaded files from the portal. */
    public function leadDestroy(Request $request, $docId)
    {
        $lead = $request->user()?->lead;
        abort_unless($lead, 403);

        $doc = LeadDocument::where('id', $docId)->where('lead_id', $lead->id)->first();
        if (! $doc) {
            return back()->with('error', 'Document not found.');
        }
        // Approved docs are locked; staff-generated docs are never client-removable.
        if ($doc->status === LeadDocument::STATUS_APPROVED) {
            return back()->with('error', 'This document has been approved and can no longer be removed.');
        }
        if ($doc->source === 'generated') {
            return back()->with('error', 'This document was prepared for you and cannot be removed.');
        }

        if ($doc->file_path) {
            Storage::disk(self::DISK)->delete($doc->file_path);
            Storage::disk('public')->delete($doc->file_path); // legacy fallback
        }
        $doc->delete();

        return back()->with('success', 'Document removed.');
    }

    /** Client replaces one of their OWN uploads with a fresh file. */
    public function leadReplace(Request $request, $docId)
    {
        $lead = $request->user()?->lead;
        abort_unless($lead, 403);

        $doc = LeadDocument::where('id', $docId)->where('lead_id', $lead->id)->first();
        if (! $doc) {
            return back()->with('error', 'Document not found.');
        }
        if ($doc->status === LeadDocument::STATUS_APPROVED) {
            return back()->with('error', 'This document has been approved and can no longer be replaced.');
        }

        $request->validate(['file' => 'required|'.UploadValidation::document()]);
        $file = $request->file('file');
        [$path, $displayName] = $this->storeChecklistFile($file, $lead, $doc->checklist_key ?? 'other');

        $oldPath = $doc->file_path;
        $doc->update([
            'file_path' => $path,
            'original_name' => $displayName,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'note' => null,
        ]);

        if ($oldPath && $oldPath !== $path) {
            Storage::disk(self::DISK)->delete($oldPath);
            Storage::disk('public')->delete($oldPath);
        }

        return back()->with('success', 'Document replaced.');
    }

    /**
     * Lead flips a checklist section into "in_review" so staff knows to
     * verify it. Lead can only do this for their own lead record.
     */
    public function leadSubmitSection(Request $request, $sectionKey)
    {
        $user = Auth::user();
        $lead = $user?->lead;
        abort_unless($lead, 403);

        try {
            $sections = is_array($lead->section_verifications) ? $lead->section_verifications : [];
            $sections[$sectionKey] = [
                'status' => 'in_review',
                'submitted_at' => now()->toIso8601String(),
            ];
            $lead->section_verifications = $sections;
            $lead->save();

            return back()->with('success', 'Section submitted for review.');
        } catch (\Throwable $e) {
            Log::error('Lead section submit failed', ['lead_id' => $lead->id, 'section' => $sectionKey, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not submit section.']);
        }
    }

    /**
     * Notify the lead's assigned staff (or admins as a fallback) that the
     * lead submitted a document — same alert the public /track upload fires.
     */
    private function notifyDocumentSubmitted(Lead $lead, LeadDocument $document): void
    {
        try {
            $recipients = $lead->assignee
                ? collect([$lead->assignee])
                : \App\Models\User::whereIn('role', [\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPER_ADMIN])->get();

            // Immigration cases always loop in the immigration team so the
            // department hears about document activity on their own cases.
            if ($lead->is_immigration_case) {
                $immigration = \App\Models\User::whereIn('role', array_merge(['immigration'], \App\Models\User::IMMIGRATION_ROLES))->get();
                $recipients = $recipients->merge($immigration)->unique('id')->values();
            }

            if ($recipients->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send(
                    $recipients,
                    new \App\Notifications\DocumentSubmittedForReview($lead, $document)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Lead-portal document notify failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        // Email automation — fires the configured "document uploaded" staff
        // notice (a no-op unless a message is enabled for the key). Kept in its
        // own try so a template/send problem never blocks the in-app alert above.
        try {
            app(\App\Services\EmailAutomationService::class)->fire('immigration.document.uploaded', $lead, [
                'document_name' => $document->original_name,
                'adviser_name' => optional($lead->assignee)->name ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::error('Document-uploaded automation failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    public function leadUpload(Request $request)
    {
        $user = Auth::user();
        $lead = $user?->lead;
        abort_unless($lead, 403);

        $request->validate([
            'file' => 'required|'.UploadValidation::document(),
            'request_id' => 'nullable|integer|exists:lead_document_requests,id',
        ]);

        // If responding to a request, verify it belongs to this lead
        if ($request->filled('request_id')) {
            $req = LeadDocumentRequest::where('lead_id', $lead->id)
                ->where('id', $request->input('request_id'))
                ->first();
            abort_unless($req, 403, 'Request does not belong to you.');
        }

        $path = $request->file('file')->store("lead-documents/{$lead->id}", self::DISK);

        $document = LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => $request->input('request_id'),
            'original_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
            'mime' => $request->file('file')->getMimeType(),
            'size' => $request->file('file')->getSize(),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'uploaded_by' => $user->id,
        ]);

        $this->notifyDocumentSubmitted($lead, $document);

        return back()->with('success', 'Document uploaded. Our team will review it shortly.');
    }

    // ── DOWNLOAD — role-gated ───────────────────────────────────────────────

    /**
     * Save an inline staff note on a generated document (the Notes column on
     * the Proposal & Agreements → Agreements table). Blank clears it.
     */
    public function updateDocumentNote(Request $request, $docId)
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        $doc = LeadDocument::findOrFail($docId);
        $doc->note = trim((string) ($data['note'] ?? '')) ?: null;
        $doc->save();

        return back()->with('success', 'Note saved.');
    }

    /**
     * Threaded staff notes on a generated document (Proposal & Agreements →
     * Agreements Notes column) — add a note, reply to one, or toggle its
     * "actioned" flag. Mirrors the per-programme note thread on the Proposals
     * tab, so generation and verification staff can discuss an agreement.
     */
    public function addDocumentNote(Request $request, $docId)
    {
        $data = $request->validate(['body' => 'required|string|max:2000', 'tag' => 'nullable|in:note,change_requested']);
        $u = $request->user();
        $this->mutateDocNotes($docId, function (array &$notes) use ($u, $data) {
            $notes[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tag' => $data['tag'] ?? 'note',
                'body' => $data['body'],
                'author' => $u->name,
                'author_id' => $u->id,
                'role' => $u->role,
                'created_at' => now()->toIso8601String(),
                'actioned_at' => null,
                'actioned_by' => null,
                'replies' => [],
            ];
        });

        return back()->with('success', 'Note added.');
    }

    public function replyDocumentNote(Request $request, $docId, $noteId)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $u = $request->user();
        $this->mutateDocNotes($docId, function (array &$notes) use ($u, $data, $noteId) {
            foreach ($notes as &$n) {
                if (($n['id'] ?? null) === $noteId) {
                    $n['replies'][] = [
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'body' => $data['body'],
                        'author' => $u->name,
                        'author_id' => $u->id,
                        'role' => $u->role,
                        'created_at' => now()->toIso8601String(),
                    ];
                }
            }
        });

        return back()->with('success', 'Reply added.');
    }

    public function toggleDocumentNoteActioned(Request $request, $docId, $noteId)
    {
        $u = $request->user();
        $this->mutateDocNotes($docId, function (array &$notes) use ($u, $noteId) {
            foreach ($notes as &$n) {
                if (($n['id'] ?? null) === $noteId) {
                    $done = ! empty($n['actioned_at']);
                    $n['actioned_at'] = $done ? null : now()->toIso8601String();
                    $n['actioned_by'] = $done ? null : $u->id;
                }
            }
        });

        return back();
    }

    /** Load a document's notes thread, mutate it, and persist. */
    private function mutateDocNotes($docId, callable $fn): void
    {
        $doc = LeadDocument::findOrFail($docId);
        $notes = is_array($doc->notes) ? $doc->notes : [];
        $fn($notes);
        $doc->notes = $notes;
        $doc->save();
    }

    public function download(Request $request, $docId)
    {
        $doc = LeadDocument::findOrFail($docId);
        $user = Auth::user();

        // Lead users can only download files tied to their own lead record.
        if ($user->isLead()) {
            abort_unless($user->lead_id === $doc->lead_id, 403);
        }

        // Build 12 phase 4 — a staff member opening a case document is passive
        // attention on the case (§5, throttled). Never for lead/client opens.
        if (! $user->isLead() && $doc->lead_id && optional($doc->lead)->is_immigration_case) {
            \App\Models\CaseView::recordOpen($doc->lead_id, $user->id);
        }

        // All uploads now land on the private ('local') disk. The 'public'
        // fallback covers the pre-migration window on production where a few
        // older tracker uploads still sit in public until
        // `documents:privatize --purge` relocates them. Local wins when present.
        $disk = Storage::disk(self::DISK)->exists($doc->file_path)
            ? self::DISK
            : (Storage::disk('public')->exists($doc->file_path) ? 'public' : null);
        abort_unless($disk, 404);

        // ?inline=1 streams with Content-Disposition: inline so the browser
        // renders the PDF in-tab instead of forcing a download — used by the
        // "View" button on the agreements panel.
        if ($request->boolean('inline')) {
            return response()->file(Storage::disk($disk)->path($doc->file_path), [
                'Content-Type' => $doc->mime ?: 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
            ]);
        }

        return Storage::disk($disk)->download($doc->file_path, $doc->original_name);
    }

    /**
     * Bundle every document on a lead into a single ZIP and stream it.
     * Staff-only (this route lives in the staff group); a lead may only
     * pull their own. Files live on either the private ('local') or
     * 'public' disk, so each is resolved the same way download() does.
     */
    public function downloadAll(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);
        $user = Auth::user();

        if ($user->isLead()) {
            abort_unless($user->lead_id === $lead->id, 403);
        }

        if (! class_exists(\ZipArchive::class)) {
            return back()->withErrors(['error' => 'ZIP support is not available on the server.']);
        }

        // An explicit `ids` list (from the "Download all" picker) wins: bundle
        // exactly those documents, still scoped to this lead's own records.
        // Otherwise fall back to the status filtering: APPROVED only by default,
        // `?status=all` (or ?all=1) for EVERY document, `?status=Submitted` (etc.)
        // for another single state.
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($n) => (int) trim($n))
            ->filter()
            ->values()
            ->all();

        $statusParam = $request->query('status', LeadDocument::STATUS_APPROVED);
        $wantAll = $statusParam === 'all' || $request->boolean('all');

        $allowed = [
            LeadDocument::STATUS_SUBMITTED,
            LeadDocument::STATUS_UNDER_REVIEW,
            LeadDocument::STATUS_APPROVED,
            LeadDocument::STATUS_REJECTED,
            LeadDocument::STATUS_STAFF_SHARED,
        ];

        $status = in_array($statusParam, $allowed, true) ? $statusParam : LeadDocument::STATUS_APPROVED;

        $docs = $lead->documents()
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->when(empty($ids) && ! $wantAll, fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')
            ->get();

        if ($docs->isEmpty()) {
            return back()->withErrors([
                'error' => ! empty($ids)
                    ? 'None of the selected documents could be found.'
                    : ($wantAll
                        ? 'This case has no documents to download.'
                        : "This case has no {$status} documents to download."),
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'leaddocs_');
        $zip = new \ZipArchive;
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            return back()->withErrors(['error' => 'Could not create the ZIP archive.']);
        }

        $used = [];
        $added = 0;
        foreach ($docs as $doc) {
            $disk = Storage::disk(self::DISK)->exists($doc->file_path)
                ? self::DISK
                : (Storage::disk('public')->exists($doc->file_path) ? 'public' : null);
            if (! $disk) {
                continue;
            }

            $name = $this->uniqueZipEntry($used, $doc->original_name ?: basename($doc->file_path));
            $used[strtolower($name)] = true;

            $zip->addFile(Storage::disk($disk)->path($doc->file_path), $name);
            $added++;
        }
        $zip->close();

        if ($added === 0) {
            @unlink($tmp);

            return back()->withErrors(['error' => 'No downloadable files were found for this case.']);
        }

        $slug = Str::slug(trim("{$lead->first_name} {$lead->last_name}")) ?: ('case-'.$lead->id);

        // Name the bundle after what's in it — an "approved" ZIP and a
        // "submitted" ZIP must not be mistaken for each other on disk.
        $tag = ! empty($ids) ? 'selected' : ($wantAll ? 'all' : Str::slug($status));

        return response()->download($tmp, "{$slug}-{$tag}-documents.zip", [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Toggle whether a checklist item is hidden from the applicant's public
     * tracking link. Staff-only; stored per-case on
     * leads.hidden_track_documents.
     */
    public function toggleTrackVisibility(Request $request, $leadId)
    {
        $lead = Lead::findOrFail($leadId);

        // Accepts one or many keys so a single row checkbox and a
        // section-level "select all" both hit the same endpoint.
        $data = $request->validate([
            'checklist_keys' => 'required|array|min:1',
            'checklist_keys.*' => 'string|max:80',
            'hidden' => 'required|boolean',
        ]);

        $hidden = array_values(array_unique(array_filter(
            is_array($lead->hidden_track_documents) ? $lead->hidden_track_documents : []
        )));

        foreach ($data['checklist_keys'] as $key) {
            if ($data['hidden']) {
                if (! in_array($key, $hidden, true)) {
                    $hidden[] = $key;
                }
            } else {
                $hidden = array_filter($hidden, fn ($k) => $k !== $key);
            }
        }

        $lead->hidden_track_documents = array_values($hidden);
        $lead->save();

        return back()->with('success', $data['hidden'] ? 'Hidden from the tracker.' : 'Shown on the tracker.');
    }

    /**
     * Return a ZIP entry name that doesn't collide with one already added,
     * appending " (2)", " (3)", … before the extension when needed.
     */
    private function uniqueZipEntry(array $used, string $name): string
    {
        if (! isset($used[strtolower($name)])) {
            return $name;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;

        $i = 2;
        do {
            $candidate = $ext !== '' ? "{$base} ({$i}).{$ext}" : "{$base} ({$i})";
            $i++;
        } while (isset($used[strtolower($candidate)]));

        return $candidate;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function docSerialize(LeadDocument $d): array
    {
        return [
            'id' => $d->id,
            'request_id' => $d->request_id,
            'original_name' => $d->original_name,
            'mime' => $d->mime,
            'size' => $d->size,
            'status' => $d->status,
            'source' => $d->source,
            'source_variant' => $d->source_variant,
            'note' => $d->note,
            'reviewed_at' => $d->reviewed_at,
            'created_at' => $d->created_at,
        ];
    }

    /**
     * Document templates a staff member can pick from. Tuned for the
     * education + immigration flows per this phase's scope.
     */
    private function requestTemplates(): array
    {
        return [
            ['label' => 'Passport bio page',          'description' => 'Clear scan or photo of the photo page'],
            ['label' => 'Birth certificate',          'description' => 'Original or certified copy'],
            ['label' => 'Academic transcripts',       'description' => 'From your most recent institution'],
            ['label' => 'Diploma / degree certificate', 'description' => 'Highest qualification completed'],
            ['label' => 'IELTS / English test result', 'description' => 'Within last 2 years'],
            ['label' => 'Curriculum vitae (CV)',      'description' => 'PDF or DOCX, max 4 pages'],
            ['label' => 'Employment letter',          'description' => 'On company letterhead'],
            ['label' => 'Bank statement',             'description' => 'Last 3-6 months'],
            ['label' => 'Police clearance certificate', 'description' => 'For immigration applications'],
            ['label' => 'Medical certificate',        'description' => 'INZ-approved panel doctor'],
        ];
    }
}
