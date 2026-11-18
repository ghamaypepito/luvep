<?php

namespace App\Http\Controllers;

use App\Models\EmailReply;
use App\Services\CommunicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Email Replies inbox — a conversation view per lead. Inbound messages are
 * pulled from the monitored mailbox (services.imap) by email:sync-replies;
 * outbound messages are staff replies sent from here. Both live in one thread.
 */
class EmailReplyController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $sanitizer = $this->htmlSanitizer();

        $messages = EmailReply::with('lead:id,first_name,last_name,email')
            ->whereNotNull('lead_id')
            ->orderBy('received_at')
            ->get();

        // Group every message into a per-lead conversation thread.
        $threads = $messages
            ->groupBy('lead_id')
            ->map(function ($msgs) use ($sanitizer) {
                $lead = $msgs->first()->lead;
                $last = $msgs->last();

                return [
                    'lead_id' => $lead?->id,
                    'lead' => $lead ? [
                        'id' => $lead->id,
                        'name' => trim("{$lead->first_name} {$lead->last_name}") ?: $lead->email,
                        'email' => $lead->email,
                    ] : null,
                    'subject' => $msgs->firstWhere('subject', '!=', null)?->subject,
                    'last_at' => optional($last->received_at)->toIso8601String(),
                    'last_snippet' => Str::limit(trim(strip_tags((string) ($last->body_text ?: $last->body_html))), 90),
                    'unread' => $msgs->where('direction', 'inbound')->where('is_read', false)->count(),
                    'messages' => $msgs->map(fn (EmailReply $m) => [
                        'id' => $m->id,
                        'direction' => $m->direction,
                        'from_email' => $m->from_email,
                        'from_name' => $m->from_name,
                        // Inbound HTML comes from arbitrary external senders —
                        // sanitize before it ever reaches the admin's browser
                        // (strips scripts, event handlers, javascript: URLs).
                        'body_html' => $m->body_html ? $sanitizer->sanitize($m->body_html) : null,
                        'body_text' => $m->body_text,
                        'received_at' => optional($m->received_at)->toIso8601String(),
                    ])->values(),
                ];
            })
            ->when($search !== '', fn ($t) => $t->filter(fn ($thread) => Str::contains(
                Str::lower(($thread['lead']['name'] ?? '').' '.($thread['lead']['email'] ?? '').' '.($thread['subject'] ?? '')),
                Str::lower($search)
            )))
            ->sortByDesc('last_at')
            ->values();

        [$component, $basePath] = $this->scope($request);

        return inertia($component, [
            'basePath' => $basePath,
            'threads' => $threads,
            'unreadCount' => EmailReply::where('direction', 'inbound')->where('is_read', false)->count(),
            'imapConfigured' => ! empty(config('services.imap.username')) && ! empty(config('services.imap.password')),
            'mailbox' => config('services.imap.username'),
            'search' => $search,
        ]);
    }

    /**
     * Resolve [component, basePath] from the route. The shared inbox renders
     * under whichever portal opened it (same data everywhere); /admin keeps the
     * admin screen. Access is gated by the portal:* middleware on each route.
     *
     * @return array{0: string, 1: string}
     */
    private function scope(Request $request): array
    {
        $name = (string) $request->route()?->getName();
        if (str_starts_with($name, 'portal.')) {
            $dept = explode('.', $name)[1] ?? null;
            if (in_array($dept, \App\Models\MessageTemplate::DEPARTMENTS, true)) {
                return ["portal/{$dept}/Replies", "/portal/{$dept}/email/replies"];
            }
        }

        return ['admin/email/Replies', '/admin/email/replies'];
    }

    /**
     * Sanitizer for untrusted inbound email HTML. Allows a safe subset of
     * presentational elements/attributes and only http(s)/mailto links; drops
     * <script>, event handlers, javascript:/data: URLs, iframes, forms, etc.
     */
    private function htmlSanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes(['https', 'http', 'data'])
            ->dropAttribute('style', '*');

        return new HtmlSanitizer($config);
    }

    /** Mark a lead's whole thread (its inbound messages) as read. */
    public function markThreadRead($leadId)
    {
        EmailReply::where('lead_id', $leadId)->where('direction', 'inbound')->update(['is_read' => true]);

        return back();
    }

    /** Staff reply to a lead — sent via email, and recorded in the thread. */
    public function reply(Request $request, $leadId, CommunicationService $comms)
    {
        $data = $request->validate(['body' => 'required|string|max:20000']);

        $lead = \App\Models\Lead::find($leadId);
        if (! $lead || empty($lead->email)) {
            return back()->with('error', 'That lead has no email address.');
        }

        // Reply subject follows the latest inbound message's subject.
        $lastInbound = EmailReply::where('lead_id', $leadId)->where('direction', 'inbound')->latest('received_at')->first();
        $subject = 'Re: '.($lastInbound?->subject ? preg_replace('/^re:\s*/i', '', $lastInbound->subject) : 'your message');

        // Reply from the monitored inbox so the conversation stays threaded.
        $from = config('services.contact.reply_to') ?: config('mail.from.address');
        $fromName = config('services.contact.event_from_name') ?: config('mail.from.name');

        $log = $comms->sendRaw('email', $lead, $subject, nl2br(e($data['body'])), null, null, $from, $fromName);
        if ($log->status === \App\Models\MessageLog::STATUS_FAILED) {
            return back()->withErrors(['body' => 'Could not send the reply. Your draft has been kept; please try again.']);
        }

        // Record the outbound message in the thread.
        EmailReply::create([
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'from_email' => $from,
            'from_name' => $fromName,
            'to_email' => $lead->email,
            'sent_by_user_id' => $request->user()->id,
            'subject' => $subject,
            'body_text' => $data['body'],
            'received_at' => now(),
            'is_read' => true,
        ]);

        // Mark the lead's inbound as read now that we've responded.
        EmailReply::where('lead_id', $leadId)->where('direction', 'inbound')->update(['is_read' => true]);

        return back()->with('success', 'Reply sent to '.$lead->email.'.');
    }

    /**
     * Pull new replies from the mailbox on demand (the "Sync now" button).
     * Queued so the slow IMAP fetch never blocks/times out the web request —
     * the queue worker runs it and new replies appear on the next refresh.
     */
    public function syncNow()
    {
        // Unique job — repeated clicks won't stack up or block the queue.
        \App\Jobs\SyncEmailRepliesJob::dispatch();

        return back()->with('success', 'Checking the mailbox — new replies will appear here in a moment. Refresh shortly.');
    }
}
