<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\Immigration\EngagementDocumentGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Standalone engagement-signing surface. The client reaches it from the
 * "engagement documents ready" email via a per-lead bearer token
 * (leads.engagement_signing_token). Unlike the public tracker it is NOT
 * excluded for immigration cases — but the token grants access to ONLY that
 * lead's engagement-pack documents (source_variant "engagement:*"), nothing
 * else on the case. The client views the pack and e-signs the Written
 * Agreement here; every other file is view/download only.
 */
class EngagementSigningController extends Controller
{
    private string $resolvedPackId = 'legacy';

    /** Plain-language, client-facing blurbs for the standard pack documents. */
    private const DOC_BLURBS = [
        'complaints_procedure' => 'How to raise a concern with us, and with the Immigration Advisers Authority.',
        'code_of_conduct' => 'The professional rules every licensed adviser in New Zealand must follow.',
        'professional_standards' => 'The service levels and timeframes you can expect from us.',
    ];

    /** Resolve the lead by its engagement token (the bearer credential). */
    private function resolveByToken(string $token): ?Lead
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $doc = LeadDocument::where('notes->engagement_signing_token', $token)->first();
        if ($doc) {
            if (empty($doc->notes['engagement_sent_at'])) {
                return null;
            }
            $this->resolvedPackId = $doc->notes['engagement_pack_id'];

            return $doc->lead;
        }
        $this->resolvedPackId = 'legacy';

        return Lead::where('engagement_signing_token', $token)->first();
    }

    /**
     * Documents the engagement token may stream: the engagement pack, the
     * bundled tax invoice, and the client's own proof-of-payment uploads.
     */
    private function packQuery(Lead $lead)
    {
        return $lead->documents()
            ->when($this->resolvedPackId === 'legacy', fn ($q) => $q->whereNull('notes->engagement_pack_id'), fn ($q) => $q->where('notes->engagement_pack_id', $this->resolvedPackId))
            ->where(function ($q) {
                $q->where('source_variant', 'like', 'engagement:%')
                    ->orWhere('source_variant', 'invoice')->orWhere('source_variant', 'like', 'invoice:%')
                    ->orWhere('source_variant', 'proof_of_payment');
            });
    }

    /** The standalone page: the engagement pack + the agreement to sign. */
    public function show(Request $request, string $token)
    {
        $lead = $this->resolveByToken($token);
        abort_unless($lead, 404);

        $labels = EngagementDocumentGenerator::DOCS;

        // The pack + the bundled invoice (proof-of-payment uploads are listed
        // separately below).
        $documents = $this->packQuery($lead)
            ->where(function ($q) {
                $q->where('source_variant', 'like', 'engagement:%')
                    ->orWhere('source_variant', 'invoice')->orWhere('source_variant', 'like', 'invoice:%');
            })
            ->when($request->filled('pack'), fn ($q) => $q->where('notes->engagement_pack_id', $request->query('pack')))
            ->where(fn ($q) => $q->where('status', '!=', 'Rejected')->orWhere(fn ($q) => $q->where('source_variant', '!=', 'invoice')->where('source_variant', 'not like', 'invoice:%')))
            ->with('invoicePayments')->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(function (LeadDocument $d) use ($token, $labels) {
                $isInvoice = \App\Services\PaymentProofService::isInvoice($d);
                $type = str_replace('engagement:', '', (string) $d->source_variant);
                $isWrittenAgreement = EngagementDocumentGenerator::signable($type);
                $title = $isInvoice ? 'Invoice'.($d->invoice_number ? " {$d->invoice_number}" : '')
                    : (isset($labels[$type]) ? $labels[$type]['label'] : $d->original_name);

                return [
                    'id' => $d->id,
                    'title' => $title,
                    'original_name' => $d->original_name,
                    'size' => $d->size,
                    'is_invoice' => $isInvoice,
                    // Plain-language blurb for the "yours to keep" list.
                    'desc' => self::DOC_BLURBS[$type] ?? null,
                    // Invoice figures (real; nulls stay null).
                    'invoice_number' => $isInvoice ? $d->invoice_number : null,
                    'payment_summary' => $isInvoice ? app(\App\Services\PaymentProofService::class)->invoiceSummary($d) : null,
                    'invoice_total' => $isInvoice && $d->invoice_total !== null ? (float) $d->invoice_total : null,
                    'currency' => $d->notes['engagement_currency'] ?? 'NZD',
                    'engagement_type' => $d->notes['engagement_type'] ?? 'immigration_advice',
                    'due_date' => $isInvoice ? optional(optional($d->created_at)->addDays(7))->toIso8601String() : null,
                    'view_url' => "/engagement/{$token}/documents/{$d->id}/download?inline=1",
                    'download_url' => "/engagement/{$token}/documents/{$d->id}/download",
                    // Live HTML preview (only for the signable agreement) — the
                    // drawn/uploaded signature is injected into it in real time.
                    'preview_url' => $isWrittenAgreement ? "/engagement/{$token}/documents/{$d->id}/preview" : null,
                    'signable' => $isWrittenAgreement,
                    'signed' => (bool) $d->client_signed_at,
                    'signed_at' => optional($d->client_signed_at)->toIso8601String(),
                    'signer_name' => $d->client_signer_name,
                    // When the agreement was made available, the client has a
                    // week to sign — a soft deadline shown as "N days left".
                    'sign_by' => $isWrittenAgreement ? optional(optional($d->created_at)->addDays(7))->toIso8601String() : null,
                    'sign_url' => $isWrittenAgreement ? "/engagement/{$token}/documents/{$d->id}/sign" : null,
                ];
            })
            ->values();

        // The licensed adviser who signs — from the written agreement's signer.
        $waDoc = $documents->firstWhere('signable', true);
        $signerId = $waDoc ? $this->packQuery($lead)->where('id', $waDoc['id'])->value('engagement_signer_id') : null;
        $signer = $signerId ? \App\Models\User::find($signerId) : null;
        $adviser = $signer ? [
            'name' => $signer->name,
            'licence' => $signer->iaa_licence_number,
            'avatar' => $signer->avatar_url,
        ] : null;

        // The client's own proof-of-payment uploads.
        $proofs = $this->packQuery($lead)
            ->where('source_variant', 'proof_of_payment')
            ->with(['proofReview.invoice.invoicePayments', 'proofReview.payment', 'reviewer:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'original_name' => $d->original_name,
                'size' => $d->size,
                'status' => $d->status,
                'note' => $d->note,
                'proof_review' => app(\App\Services\PaymentProofService::class)->metadata($d),
                'reviewed_at' => $d->reviewed_at?->toIso8601String(),
                'uploaded_at' => optional($d->created_at)->toIso8601String(),
                'view_url' => "/engagement/{$token}/documents/{$d->id}/download?inline=1",
                'download_url' => "/engagement/{$token}/documents/{$d->id}/download",
            ])
            ->values();

        return inertia('track/EngagementPack', [
            'token' => $token,
            'client' => [
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: 'Client',
                'first_name' => $lead->first_name,
            ],
            'documents' => $documents,
            'proofs' => $proofs,
            'proof_upload_url' => "/engagement/{$token}/proof-of-payment",
            'adviser' => $adviser,
            // Upload or partial confirmation must not unlock paid onboarding.
            'invoice_paid' => $documents->where('is_invoice', true)->isNotEmpty()
                && $documents->where('is_invoice', true)->every(fn ($invoice) => $invoice['payment_summary']['paid']),
        ]);
    }

    /** Stream an engagement-pack document (inline preview or download). */
    public function download(Request $request, string $token, $docId)
    {
        $lead = $this->resolveByToken($token);
        abort_unless($lead, 404);

        $doc = $this->packQuery($lead)->where('id', $docId)->firstOrFail();
        abort_unless($doc->file_path && Storage::disk('local')->exists($doc->file_path), 404);

        if ($request->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($doc->file_path), [
                'Content-Type' => $doc->mime ?: 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
            ]);
        }

        return Storage::disk('local')->download($doc->file_path, $doc->original_name);
    }

    /** Append a validated batch; an upload never replaces earlier evidence. */
    public function uploadProof(Request $request, string $token)
    {
        $lead = $this->resolveByToken($token);
        abort_unless($lead, 404);
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,gif', 'max:10240'],
            'invoice_id' => ['nullable', 'integer'],
        ]);
        $invoices = $this->packQuery($lead)->where(fn ($query) => $query->where('source_variant', 'invoice')->orWhere('source_variant', 'like', 'invoice:%'))->get();
        $invoice = ! empty($data['invoice_id']) ? $invoices->firstWhere('id', (int) $data['invoice_id']) : ($invoices->count() === 1 ? $invoices->first() : null);
        if (! $invoice || $invoice->status === 'Rejected') {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice_id' => 'Choose the invoice from this engagement pack before uploading payment proofs.']);
        }
        $stored = [];
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $lead, $invoice, &$stored) {
                Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                LeadDocument::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                foreach ($request->file('files') as $file) {
                    $path = $file->store("lead-documents/{$lead->id}", 'local');
                    if (! $path) {
                        throw new \RuntimeException('Payment proof storage failed.');
                    }
                    $stored[] = $path;
                    $proof = LeadDocument::create([
                        'lead_id' => $lead->id, 'checklist_key' => 'proof_of_payment',
                        'original_name' => $file->getClientOriginalName(), 'file_path' => $path,
                        'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                        'status' => LeadDocument::STATUS_SUBMITTED, 'source' => LeadDocument::SOURCE_UPLOAD,
                        'source_variant' => 'proof_of_payment', 'uploaded_by' => null,
                        'notes' => $this->resolvedPackId === 'legacy' ? null : ['engagement_pack_id' => $this->resolvedPackId],
                    ]);
                    \App\Models\PaymentProofReview::create(['document_id' => $proof->id, 'invoice_document_id' => $invoice->id]);
                }
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($lead, $invoice) {
                    try {
                        app(\App\Services\EmailAutomationService::class)->fire('immigration.proof.uploaded', $lead, ['invoice_number' => $invoice->invoice_number ?? '']);
                    } catch (\Throwable $exception) {
                        \Illuminate\Support\Facades\Log::warning('Payment proof upload notification failed', ['lead_id' => $lead->id, 'error' => $exception->getMessage()]);
                    }
                });
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', count($stored).' payment proof file(s) uploaded. Earlier files are retained for review.');
    }

    /**
     * Live HTML preview of the Written Agreement. Its blade listens for the
     * applicant signature via postMessage and drops it into the signature slot,
     * so the client sees their signature on the document as they draw/upload it.
     */
    public function preview(EngagementDocumentGenerator $generator, string $token, $docId)
    {
        $lead = $this->resolveByToken($token);
        abort_unless($lead, 404);

        $doc = $this->packQuery($lead)->where('id', $docId)->firstOrFail();
        $type = str_replace('engagement:', '', $doc->source_variant);
        abort_unless(EngagementDocumentGenerator::signable($type), 403);

        // Any already-stored signature, so a re-open shows what was signed.
        $clientSig = null;
        if ($doc->client_signature_path && Storage::disk('local')->exists($doc->client_signature_path)) {
            $ext = strtolower(pathinfo($doc->client_signature_path, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
            $clientSig = "data:{$mime};base64,".base64_encode(Storage::disk('local')->get($doc->client_signature_path));
        }

        $overrides = ['signer_id' => $doc->engagement_signer_id];
        if (! empty($doc->notes['engagement_payload'])) {
            $overrides['payload_snapshot'] = $doc->notes['engagement_payload'];
        }
        if ($clientSig) {
            $overrides['client_signature'] = $clientSig;
            $overrides['client_signed_date'] = $doc->client_signed_at?->format('d/m/Y');
        }

        $html = $generator->renderHtml($lead, $type, $overrides);

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Client e-signs the Written Agreement — bakes the signature into the PDF. */
    public function sign(Request $request, EngagementDocumentGenerator $generator, string $token, $docId)
    {
        $lead = $this->resolveByToken($token);
        abort_unless($lead, 404);

        return \Illuminate\Support\Facades\Cache::lock("engagement:case:{$lead->id}", 90)->block(5,
            fn () => $this->signLocked($request, $generator, $token, $docId));
    }

    private function signLocked(Request $request, EngagementDocumentGenerator $generator, string $token, $docId)
    {
        $lead = $this->resolveByToken($token);
        abort_unless($lead, 404);

        $doc = $this->packQuery($lead)->where('id', $docId)->firstOrFail();
        $type = str_replace('engagement:', '', $doc->source_variant);
        abort_unless(EngagementDocumentGenerator::signable($type), 403);
        abort_if($doc->client_signed_at, 409, 'This agreement has already been signed.');

        // Capture BEFORE we stamp client_signed_at so the confirmation email
        // fires only on the first signing, not on a re-sign.
        $firstSigning = empty($doc->client_signed_at);

        $request->validate([
            'signature_data' => 'nullable|string',
            'signature_image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'signer_name' => 'required|string|max:120',
        ]);

        [$binary, $ext] = $this->decodeSignature($request) ?? [null, null];
        abort_if($binary === null, 422, 'No signature provided.');

        if ($doc->client_signature_path) {
            Storage::disk('local')->delete($doc->client_signature_path);
        }
        $sigPath = "signatures/client-doc-{$doc->id}-".Str::random(8).".{$ext}";
        Storage::disk('local')->put($sigPath, $binary);

        $clientSig = 'data:image/'.($ext === 'jpeg' ? 'jpeg' : 'png').';base64,'.base64_encode($binary);

        // Re-render the Written Agreement with the applicant signature baked in.
        $pdf = $generator->pdfBinary($lead, $type, [
            'signer_id' => $doc->engagement_signer_id,
            'client_signature' => $clientSig,
            'client_signed_date' => now()->format('d/m/Y'),
            ...(! empty($doc->notes['engagement_payload']) ? ['payload_snapshot' => $doc->notes['engagement_payload']] : []),
        ]);
        Storage::disk('local')->put($doc->file_path, $pdf);

        $doc->forceFill([
            'size' => strlen($pdf),
            'client_signature_path' => $sigPath,
            'client_signed_at' => now(),
            'client_signer_name' => $request->input('signer_name'),
        ])->save();

        $lead->recordStaffActivity('Client signed the Written Agreement');

        // Signing moves the case to "Agreement Signed" — only from a pre-signing
        // stage, so it never downgrades a case that's already lodged/approved.
        if ($firstSigning && $type === 'written_agreement') {
            if ($lead->advanceImmigrationStage('Agreement Signed', ['For Assessment', 'Endorsed', 'Agreement Sent', 'For Agreement & Invoice', 'Request for Information'], null)) {
                \App\Jobs\EvaluateCaseFindings::dispatch($lead->id);
            }
        }

        // Confirm receipt to the client with their document checklist (key:
        // agreement_signed). First signing only; best-effort so a mail failure
        // never blocks the signature from being saved.
        if ($firstSigning) {
            \App\Jobs\SendLeadFollowupEmail::sendKey('agreement_signed', $lead);
            app(\App\Services\EmailAutomationService::class)->fire('immigration.engagement.signed', $lead, []);
        }

        return back()->with('success', 'Thank you — your agreement has been signed.');
    }

    /** Decode a drawn-canvas data URL or an uploaded image into [binary, ext]. */
    private function decodeSignature(Request $request): ?array
    {
        $data = $request->input('signature_data');
        if (is_string($data) && str_starts_with($data, 'data:image')) {
            $parts = explode(',', $data, 2);
            if (count($parts) === 2) {
                $ext = (str_contains($parts[0], 'jpeg') || str_contains($parts[0], 'jpg')) ? 'jpeg' : 'png';
                $bin = base64_decode($parts[1], true);

                return $bin ? [$bin, $ext] : null;
            }
        }

        if ($request->hasFile('signature_image')) {
            $f = $request->file('signature_image');
            $ext = strtolower($f->getClientOriginalExtension());

            return [file_get_contents($f->getRealPath()), $ext === 'jpg' ? 'jpeg' : ($ext ?: 'png')];
        }

        return null;
    }
}
