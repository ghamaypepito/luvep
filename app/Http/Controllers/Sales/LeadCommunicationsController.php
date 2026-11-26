<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MessageLog;
use App\Services\CommunicationService;
use App\Services\Immigration\CaseChecklistService;
use App\Services\MediaDrive\Attachments as MediaAttachments;
use App\Support\LeadDiscussionAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

/**
 * Staff-side conversation for one lead — the emails/SMS we sent them
 * (MessageLog) merged with the replies they sent back (EmailReply inbound),
 * newest first, plus an inline composer to email them a reply. Access-gated
 * per lead. A lead with no email address can be viewed but not messaged.
 */
class LeadCommunicationsController extends Controller
{
    private const MAX_ATTACHMENTS = 5;

    private const MAX_ATTACHMENT_KB = 10240;

    /** GET /admin/leads/{lead}/communications */
    public function index(Request $request, Lead $lead)
    {
        abort_unless(LeadDiscussionAccess::staffCanDiscuss($request->user(), $lead), 403, 'You do not have access to this lead.');

        // Outbound — everything we sent this lead (templates, composed, replies).
        $outbound = MessageLog::query()
            ->where('recipient_type', 'lead')
            ->where('recipient_id', $lead->id)
            ->with('triggeredBy:id,name')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (MessageLog $log) => [
                'key' => 'log-'.$log->id,
                'direction' => 'out',
                'channel' => $log->channel,
                'subject' => $log->subject,
                'body' => $log->body,
                'status' => $log->status,
                'error' => $log->error_message,
                'sender' => $log->triggeredBy?->name,
                'from' => null,
                'template_key' => $log->template_key,
                'created_at' => $log->created_at?->toIso8601String(),
                'ts' => $log->created_at?->getTimestamp() ?? 0,
            ]);

        // Inbound — the lead's replies pulled from the monitored mailbox.
        $inbound = EmailReply::query()
            ->where('lead_id', $lead->id)
            ->where('direction', 'inbound')
            ->latest('received_at')
            ->limit(200)
            ->get()
            ->map(fn (EmailReply $r) => [
                'key' => 'reply-'.$r->id,
                'direction' => 'in',
                'channel' => 'email',
                'subject' => $r->subject,
                'body' => $r->body_text ?: trim(strip_tags((string) $r->body_html)),
                'status' => 'received',
                'error' => null,
                'sender' => null,
                'from' => $r->from_name ?: $r->from_email,
                'template_key' => null,
                'created_at' => optional($r->received_at)?->toIso8601String(),
                'ts' => optional($r->received_at)?->getTimestamp() ?? 0,
            ]);

        $items = $outbound->concat($inbound)
            ->sortByDesc('ts')
            ->take(200)
            ->map(fn ($i) => collect($i)->except('ts')->all())
            ->values();

        $canAttachDocuments = $this->canAttachStudentDocuments($request, $lead);

        return response()->json([
            'data' => $items,
            'next_page_url' => null,
            'lead_email' => $lead->email,
            'can_email' => ! empty($lead->email),
            'can_attach_documents' => $canAttachDocuments,
            'attachment_documents' => $canAttachDocuments
                ? $this->attachableDocuments($lead)->latest()->get(['id', 'original_name', 'status', 'created_at'])
                    ->map(fn (LeadDocument $doc) => ['id' => $doc->id, 'name' => $doc->original_name,
                        'status' => $doc->status, 'added_at' => $doc->created_at?->toIso8601String()])
                : [],
        ]);
    }

    /**
     * POST /admin/leads/{lead}/communications — email this lead a reply from the
     * conversation tab. Logs a MessageLog (recipient=lead) so it appears back in
     * the feed. Blocked when the lead has no email address.
     */
    public function reply(Request $request, Lead $lead, CommunicationService $comms)
    {
        abort_unless(LeadDiscussionAccess::staffCanDiscuss($request->user(), $lead), 403, 'You do not have access to this lead.');

        if (empty($lead->email)) {
            return response()->json(['message' => 'This lead has no email address — add one in Personal Info first.'], 422);
        }

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'cc' => [$request->user()->canAccessPortal('education') ? 'nullable' : 'prohibited', 'array', 'max:10'],
            'cc.*' => ['required', 'string', 'max:254', 'email:filter'],
            'body' => ['nullable', 'required_without_all:attachments,document_ids', 'string', 'max:50000'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['required', 'file', 'max:'.self::MAX_ATTACHMENT_KB],
            'document_ids' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'document_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $files = $request->file('attachments') ?? [];
        $documentIds = $data['document_ids'] ?? [];
        if (count($files) + count($documentIds) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['attachments' => 'You can attach up to five files per email.']);
        }

        $documents = collect();
        if ($documentIds) {
            abort_unless($this->canAttachStudentDocuments($request, $lead), 403);
            $documents = $this->attachableDocuments($lead)->whereIn('id', $documentIds)->get();
            if ($documents->count() !== count($documentIds)) {
                throw ValidationException::withMessages(['document_ids' => 'Choose approved or shared documents from this student’s profile.']);
            }
        }

        // A full HTML document (e.g. pasted from the builder) sends as-is; plain
        // rich-text goes through the branded shell like every other send.
        $draftBody = $data['body'] ?? '';
        $rawHtml = (bool) preg_match('/^\s*<(!doctype|html)\b/i', $draftBody);
        $attachments = $this->snapshotAttachments($request, $files, $documents);
        $subject = $comms->render($lead, trim((string) ($data['subject'] ?? ''))) ?: 'A message from EP';
        $body = $comms->render($lead, $draftBody);

        $cc = collect($data['cc'] ?? [])
            ->map(fn (string $address) => strtolower(trim($address)))
            ->reject(fn (string $address) => $address === strtolower(trim($lead->email)))
            ->unique()->implode(', ');
        $log = $comms->sendComposedEmail($lead->email, $subject, $body, $attachments, $rawHtml, $lead->id, cc: $cc ?: null);

        if ($log->status === MessageLog::STATUS_FAILED) {
            return response()->json(['message' => 'Could not send the email. Your draft has been kept; please try again.', 'log_id' => $log->id], 502);
        }

        return response()->json(['ok' => true, 'log_id' => $log->id]);
    }

    private function canAttachStudentDocuments(Request $request, Lead $lead): bool
    {
        return $request->user()->canAccessPortal('education')
            && Lead::inStudentsRegister()->whereKey($lead->id)->exists();
    }

    private function attachableDocuments(Lead $lead): HasMany
    {
        $hidden = app(CaseChecklistService::class)->hiddenKeysFor($lead);

        return $lead->documents()
            ->whereIn('status', [LeadDocument::STATUS_APPROVED, LeadDocument::STATUS_STAFF_SHARED])
            ->whereNull('dependent_id')
            ->where(fn ($query) => $query->whereNull('checklist_key')->orWhereNotIn('checklist_key', $hidden))
            ->where('size', '<=', self::MAX_ATTACHMENT_KB * 1024);
    }

    private function snapshotAttachments(Request $request, array $files, \Illuminate\Support\Collection $documents): array
    {
        $disk = Storage::disk('local');
        $folder = 'compose-attachments/'.Str::uuid();
        $attachments = [];

        try {
            // Keep a private copy for delivery even if the original is replaced later.
            foreach ($documents as $document) {
                $source = $disk;
                $path = $document->file_path;
                if ($document->media_file_id) {
                    $path = app(MediaAttachments::class)->documentFile($document, $request->user())->file_path;
                } elseif (! $disk->exists($path)) {
                    $source = Storage::disk('public');
                }
                if (! $source->exists($path) || $source->size($path) > self::MAX_ATTACHMENT_KB * 1024) {
                    throw ValidationException::withMessages(['document_ids' => 'A selected document is unavailable or exceeds 10 MB.']);
                }

                $destination = $folder.'/'.$document->id;
                $stream = $source->readStream($path);
                try {
                    if (! is_resource($stream) || ! $disk->put($destination, $stream)) {
                        throw ValidationException::withMessages(['document_ids' => 'A selected document could not be attached.']);
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $attachments[] = ['path' => $destination, 'name' => $document->original_name];
            }

            foreach ($files as $file) {
                $path = $file->store($folder, 'local');
                if (! $path) {
                    throw ValidationException::withMessages(['attachments' => 'An uploaded file could not be attached.']);
                }
                $attachments[] = ['path' => $path, 'name' => $file->getClientOriginalName()];
            }
        } catch (\Throwable $e) {
            $disk->deleteDirectory($folder);
            if ($e instanceof FilesystemException) {
                throw ValidationException::withMessages(['attachments' => 'A file could not be attached. Select it again or choose another copy.']);
            }

            throw $e;
        }

        return $attachments;
    }

    /**
     * POST /admin/leads/{lead}/communications/sync — pull the mailbox on demand
     * so a lead's just-sent reply shows up without leaving the tab (the same
     * IMAP fetch the Email → Replies inbox "Sync now" button runs). Queued, so
     * the slow fetch never blocks the request; the feed picks it up on refresh.
     */
    public function sync(Request $request, Lead $lead)
    {
        abort_unless(LeadDiscussionAccess::staffCanDiscuss($request->user(), $lead), 403, 'You do not have access to this lead.');

        \App\Jobs\SyncEmailRepliesJob::dispatch();

        return response()->json(['ok' => true]);
    }
}
