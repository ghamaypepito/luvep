<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Services\CommunicationService;
use App\Support\LeadDiscussionAccess;
use Illuminate\Http\Request;

/**
 * Staff-side conversation for one lead — the emails/SMS we sent them
 * (MessageLog) merged with the replies they sent back (EmailReply inbound),
 * newest first, plus an inline composer to email them a reply. Access-gated
 * per lead. A lead with no email address can be viewed but not messaged.
 */
class LeadCommunicationsController extends Controller
{
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

        return response()->json([
            'data' => $items,
            'next_page_url' => null,
            'lead_email' => $lead->email,
            'can_email' => ! empty($lead->email),
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
            'body' => ['required', 'string', 'max:50000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        // A full HTML document (e.g. pasted from the builder) sends as-is; plain
        // rich-text goes through the branded shell like every other send.
        $rawHtml = (bool) preg_match('/^\s*<(!doctype|html)\b/i', $data['body']);

        // Store attachments on the private disk; TemplatedMessage streams them.
        $attachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $attachments[] = ['path' => $file->store('compose-attachments', 'local'), 'name' => $file->getClientOriginalName()];
        }

        $subject = $comms->render($lead, trim((string) ($data['subject'] ?? ''))) ?: 'A message from EP';
        $body = $comms->render($lead, $data['body']);

        $log = $comms->sendComposedEmail($lead->email, $subject, $body, $attachments, $rawHtml, $lead->id);

        if ($log->status === MessageLog::STATUS_FAILED) {
            return response()->json(['message' => 'Could not send the email. Your draft has been kept; please try again.', 'log_id' => $log->id], 502);
        }

        return response()->json(['ok' => true, 'log_id' => $log->id]);
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
