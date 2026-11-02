<?php

namespace App\Http\Controllers;

use App\Mail\DocumentStatusChanged;
use App\Models\LeadDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Cross-lead queue of lead-submitted documents so staff can review them in
 * bulk rather than one lead at a time. Approving/rejecting emails the lead
 * (DocumentStatusChanged).
 */
class DocumentQueueController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected', 'all'], true)
            ? $request->query('status') : 'pending';
        $search = trim((string) $request->query('search', ''));
        $docType = $request->query('doc_type');

        $base = LeadDocument::query()
            ->where('source', LeadDocument::SOURCE_UPLOAD) // lead-submitted only
            ->with(['lead:id,first_name,last_name,tracking_code,inz_visa_type'])
            ->when($status === 'pending', fn ($q) => $q->whereIn('status', [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW]))
            ->when($status === 'approved', fn ($q) => $q->where('status', LeadDocument::STATUS_APPROVED))
            ->when($status === 'rejected', fn ($q) => $q->where('status', LeadDocument::STATUS_REJECTED))
            ->when($docType, fn ($q) => $q->where('checklist_key', $docType))
            ->when($search !== '', fn ($q) => $q->whereHas('lead', fn ($l) => $l
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('tracking_code', 'like', "%{$search}%")));

        $documents = (clone $base)
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LeadDocument $d) => [
                'id' => $d->id,
                'lead_id' => $d->lead_id,
                'lead_name' => $d->lead ? trim("{$d->lead->first_name} {$d->lead->last_name}") : 'Unknown',
                'tracking_code' => optional($d->lead)->tracking_code,
                'visa_type' => optional($d->lead)->inz_visa_type,
                'original_name' => $d->original_name,
                'checklist_key' => $d->checklist_key,
                'status' => $d->status,
                'created_at' => optional($d->created_at)?->toIso8601String(),
                // Access-checked staff stream, not a public URL — client
                // uploads live on the private disk (Privacy-Act fix).
                'url' => $d->file_path ? route('admin.documents.download', ['docId' => $d->id]).'?inline=1' : null,
                'is_image' => str_starts_with((string) $d->mime, 'image/'),
            ]);

        return inertia('admin/DocumentQueue', [
            'documents' => $documents,
            'filters' => ['status' => $status, 'search' => $search, 'doc_type' => $docType],
            'counts' => [
                'pending' => (clone $base)->whereIn('status', [LeadDocument::STATUS_SUBMITTED, LeadDocument::STATUS_UNDER_REVIEW])->count(),
            ],
        ]);
    }

    /**
     * Approve or reject one or many documents in a single transaction, then
     * email each affected lead.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => 'nullable|string|max:500',
        ]);

        $status = $data['action'] === 'approve'
            ? LeadDocument::STATUS_APPROVED
            : LeadDocument::STATUS_REJECTED;

        $docs = LeadDocument::with('lead')->whereIn('id', $data['ids'])->get();

        abort_if($docs->contains(fn ($doc) => $doc->source_variant === 'proof_of_payment' || \App\Services\PaymentProofService::isInvoice($doc)), 422, 'Review payment evidence individually through Finance.');

        DB::transaction(function () use ($docs, $status, $data) {
            foreach ($docs as $doc) {
                $doc->update([
                    'status' => $status,
                    'note' => $data['reason'] ?? $doc->note,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);
            }
        });

        // Notify leads after the status writes have committed. Prefer the
        // doc_approved / doc_rejected templates; fall back to the Mailable.
        $key = $status === LeadDocument::STATUS_APPROVED ? 'doc_approved' : 'doc_rejected';
        foreach ($docs as $doc) {
            if ($doc->lead && ! empty($doc->lead->email)) {
                try {
                    $res = app(\App\Services\CommunicationService::class)->sendTemplated($key, $doc->lead, [
                        'document_name' => $doc->original_name,
                        'reason' => $data['reason'] ?? '',
                    ]);
                    if (! $res['email']) {
                        Mail::to($doc->lead->email)->send(new DocumentStatusChanged($doc->lead, $doc->fresh(), $data['reason'] ?? null));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Bulk DocumentStatusChanged failed', ['doc_id' => $doc->id, 'error' => $e->getMessage()]);
                }
            }
        }

        // Push the newly-approved documents to each client's Google Drive
        // folder — best-effort background jobs, dormant unless configured.
        if ($status === LeadDocument::STATUS_APPROVED
            && \App\Services\GoogleDriveService::isConfigured()) {
            foreach ($docs as $doc) {
                \App\Jobs\PushApprovedDocumentToDrive::dispatch($doc->id);
            }
        }

        $verb = $data['action'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('success', $docs->count()." document(s) {$verb}.");
    }
}
