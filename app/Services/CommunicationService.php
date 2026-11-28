<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One door for outbound lead communication. Looks up a template, fills in
 * {{variables}}, routes to email and/or SMS, and records a MessageLog for
 * every send (success or failure). No AI, no scheduling — just the rails.
 */
class CommunicationService
{
    /**
     * Template variables whose values are trusted, server-built HTML and must
     * NOT be HTML-escaped on substitution (their dynamic parts are escaped
     * where the value is assembled). E.g. the Study Proposal's rendered
     * "Recommended Programs" block.
     */
    private const RAW_HTML_KEYS = ['program_options'];

    public function __construct(private SmsProvider $sms) {}

    /**
     * Send a keyed template to a lead across its configured channels.
     * When $department is given, the department's own version of the template
     * is preferred, falling back to the shared/global template of that key.
     *
     * @return array{email: ?MessageLog, sms: ?MessageLog}
     */
    public function sendTemplated(string $key, Lead $lead, array $extraContext = [], ?string $department = null, ?string $extraCc = null, array $attachments = []): array
    {
        $template = MessageTemplate::resolve($key, $department);
        if (! $template) {
            Log::warning("CommunicationService: no active template '{$key}'", [
                'lead_id' => $lead->id, 'department' => $department,
            ]);

            return ['email' => null, 'sms' => null];
        }

        return $this->dispatch($template, $lead, $extraContext, $attachments, $extraCc);
    }

    /**
     * Send a specific template instance to a lead (no key/department lookup).
     * Used by the editor's "send test" and the lead "send update" compose flow,
     * the latter optionally attaching files.
     *
     * @param  list<array{path: string, name: string}>  $attachments
     * @return array{email: ?MessageLog, sms: ?MessageLog}
     */
    public function sendTemplate(MessageTemplate $template, Lead $lead, array $extraContext = [], array $attachments = []): array
    {
        return $this->dispatch($template, $lead, $extraContext, $attachments);
    }

    /**
     * Render a resolved template's channels and send each configured one.
     * Attachments (if any) ride along on the email channel only.
     *
     * @param  list<array{path: string, name: string}>  $attachments
     * @return array{email: ?MessageLog, sms: ?MessageLog}
     */
    private function dispatch(MessageTemplate $template, Lead $lead, array $extraContext, array $attachments = [], ?string $extraCc = null): array
    {
        $result = ['email' => null, 'sms' => null];

        $channels = $template->channels ?? [];
        if (empty($channels)) {
            Log::warning("CommunicationService: template '{$template->key}' has no channels", ['lead_id' => $lead->id]);

            return $result;
        }

        $context = $this->buildContext($lead, $extraContext);

        if (in_array('email', $channels, true) && ! empty($lead->email)) {
            $subject = $this->substitute((string) $template->email_subject, $context, false);
            $body = $this->substitute((string) $template->email_body, $context, true);
            // Merge any per-send extra CC (e.g. the lead's own agent) with the
            // template's static CC list, deduped.
            $cc = $this->mergeAddressList($template->cc, $extraCc);
            $result['email'] = $this->sendEmail(
                $lead, $subject, $body, $template->key, $attachments, null,
                $template->banner_image,
                $template->footer_image,
                $template->from_email,
                $template->from_name,
                $cc,
                $template->bcc,
                $template->branding,
                $template->to_extra,
                // A visual-builder template is a complete, self-contained email —
                // send it as-is, without the branded shell wrapping it.
                filled($template->design_json),
                $template->reply_to_email,
                $template->reply_to_name,
            );
        }

        if (in_array('sms', $channels, true) && ! empty($lead->phone)) {
            $body = $this->substitute((string) $template->sms_body, $context, false);
            $result['sms'] = $this->sendSms($lead, $body, $template->key);
        }

        return $result;
    }

    /**
     * Send an ad-hoc message (no template lookup) to a lead on one channel.
     * Optional banner/footer image paths brand the email shell — pass a
     * template's images to match its look on a custom compose.
     */
    public function sendRaw(string $channel, Lead $lead, ?string $subject, string $body, ?string $bannerImage = null, ?string $footerImage = null, ?string $fromEmail = null, ?string $fromName = null): MessageLog
    {
        return $channel === MessageLog::CHANNEL_SMS
            ? $this->sendSms($lead, $body, null)
            : $this->sendEmail($lead, $subject ?? '(no subject)', $body, null, [], null, $bannerImage, $footerImage, $fromEmail, $fromName);
    }

    /**
     * Render free text against a lead's variable context — for personalised
     * previews and custom (template-free) sends. Mirrors the substitution the
     * templated path uses ({{first_name}}, {{client_portal_url}}, …).
     */
    public function render(Lead $lead, string $text, array $extra = [], bool $escape = false): string
    {
        return $this->substitute($text, $this->buildContext($lead, $extra), $escape);
    }

    /**
     * Send the same keyed template to many leads. One lead's failure never
     * aborts the batch — every lead yields a result row.
     *
     * @param  iterable<Lead>  $leads
     * @return array<int, array{lead_id:int, status:string, log_id:?int, error:?string}>
     */
    public function bulkSendTemplated(iterable $leads, MessageTemplate $template, array $extra = []): array
    {
        $results = [];

        foreach ($leads as $lead) {
            try {
                $sent = $this->sendTemplated($template->key, $lead, $extra);
                $log = $sent['email'] ?? $sent['sms'];
                $failed = $log && $log->status === MessageLog::STATUS_FAILED;

                $results[] = [
                    'lead_id' => $lead->id,
                    'status' => ($log && ! $failed) ? 'sent' : 'failed',
                    'log_id' => $log?->id,
                    'error' => $log?->error_message
                        ?? ($log ? null : 'No deliverable channel (missing email/phone or template has none).'),
                ];
            } catch (\Throwable $e) {
                Log::error('CommunicationService bulk send failed for lead', [
                    'lead_id' => $lead->id, 'error' => $e->getMessage(),
                ]);
                $results[] = ['lead_id' => $lead->id, 'status' => 'failed', 'log_id' => null, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Send an SMS to a bare phone number (no Lead) — used by the test
     * command and any future system-level alert. Logs a 'raw' MessageLog.
     */
    public function sendRawSms(string $phone, string $body): MessageLog
    {
        $to = $this->normalizePhone($phone);

        if (! $to) {
            return $this->log([
                'channel' => MessageLog::CHANNEL_SMS, 'recipient_type' => 'raw',
                'recipient_address' => $phone, 'body' => $body,
                'status' => MessageLog::STATUS_FAILED,
                'error_message' => 'Invalid phone number — could not normalize to E.164.', 'failed_at' => now(),
            ]);
        }

        $res = $this->sms->send($to, $body);

        return $this->log([
            'channel' => MessageLog::CHANNEL_SMS, 'recipient_type' => 'raw',
            'recipient_address' => $to, 'body' => $body,
            'status' => $res['ok'] ? MessageLog::STATUS_QUEUED : MessageLog::STATUS_FAILED,
            'provider_message_id' => $res['message_id'] ?? null,
            'error_message' => $res['ok'] ? null : ($res['error'] ?? 'SMS send failed.'),
            'failed_at' => $res['ok'] ? null : now(),
        ]);
    }

    // ─── Channels ─────────────────────────────────────────────────────────

    /**
     * Substitute and send a raw subject/body to a lead as part of a bulk
     * campaign, logging the row against that campaign. Optional banner/footer/
     * from carry the campaign template's branding so bulk emails match the
     * template exactly. Returns true on a successful queue.
     */
    public function sendCampaignEmail(Lead $lead, string $subject, string $body, int $campaignId, array $extraContext = [], ?string $bannerImage = null, ?string $footerImage = null, ?string $fromEmail = null, ?string $fromName = null): bool
    {
        if (empty($lead->email)) {
            $this->log([
                'campaign_id' => $campaignId, 'channel' => MessageLog::CHANNEL_EMAIL,
                'recipient_id' => $lead->id, 'recipient_address' => '',
                'subject' => $subject, 'status' => MessageLog::STATUS_FAILED,
                'error_message' => 'Lead has no email address.', 'failed_at' => now(),
            ]);

            return false;
        }

        $context = $this->buildContext($lead, $extraContext);
        $sub = $this->substitute($subject, $context, false);
        $bod = $this->substitute($body, $context, true);

        $log = $this->sendEmail($lead, $sub, $bod, null, [], $campaignId, $bannerImage, $footerImage, $fromEmail, $fromName);

        return $log->status !== MessageLog::STATUS_FAILED;
    }

    /**
     * SMS counterpart of sendCampaignEmail: substitute and text a lead as part
     * of a bulk SMS campaign, logging the row against that campaign. Returns
     * true when the provider accepted the message.
     */
    public function sendCampaignSms(Lead $lead, string $body, int $campaignId, array $extraContext = []): bool
    {
        $to = $this->normalizePhone((string) $lead->phone);

        if (! $to) {
            $this->log([
                'campaign_id' => $campaignId, 'channel' => MessageLog::CHANNEL_SMS,
                'recipient_id' => $lead->id, 'recipient_address' => (string) $lead->phone,
                'body' => $body, 'status' => MessageLog::STATUS_FAILED,
                'error_message' => 'Lead has no valid phone number.', 'failed_at' => now(),
            ]);

            return false;
        }

        $text = $this->substitute($body, $this->buildContext($lead, $extraContext), false);
        $res = $this->sms->send($to, $text);

        $this->log([
            'campaign_id' => $campaignId, 'channel' => MessageLog::CHANNEL_SMS,
            'recipient_id' => $lead->id, 'recipient_address' => $to, 'body' => $text,
            'status' => $res['ok'] ? MessageLog::STATUS_QUEUED : MessageLog::STATUS_FAILED,
            'provider_message_id' => $res['message_id'] ?? null,
            'error_message' => $res['ok'] ? null : ($res['error'] ?? 'SMS send failed.'),
            'failed_at' => $res['ok'] ? null : now(),
        ]);

        return $res['ok'];
    }

    /**
     * Send a manually composed email (Compose module) to an arbitrary address,
     * or to a lead when $leadId is given. Subject/body are already substituted by
     * the caller. Tags the message log with the relevant Compose workflow.
     * $rawHtml=true sends a self-contained builder email (no branded shell).
     */
    public function sendComposedEmail(string|array $address, ?string $subject, string $body, array $attachments = [], bool $rawHtml = false, ?int $leadId = null, ?string $toExtra = null, ?string $cc = null, ?string $bcc = null, bool $afterCommit = false, ?int $partnerId = null, ?array $accommodation = null): MessageLog
    {
        // Primary To address(es) plus any template "To — also send to" extras,
        // lower-cased and deduped. Accepts a single address or a list (a staff
        // notice resolves several role recipients).
        $recipients = collect(is_array($address) ? $address : [$address])
            ->merge($this->parseAddresses($toExtra))
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter()->unique()->values()->all();

        $fromEmail = $accommodation === null ? null : config('mail.from.address');
        $fromName = $accommodation === null ? null : config('mail.from.name');
        $log = $this->log([
            'source' => $accommodation !== null ? MessageLog::SOURCE_ACCOMMODATION_COMPOSE : ($partnerId ? 'partner-tracker' : 'compose'),
            'channel' => MessageLog::CHANNEL_EMAIL,
            'recipient_type' => $partnerId ? 'user' : ($leadId ? 'lead' : 'raw'),
            'recipient_id' => $partnerId ?? $leadId,
            'recipient_address' => implode(', ', $recipients),
            'subject' => $subject,
            'body' => $body,
            'status' => MessageLog::STATUS_QUEUED,
            ...($accommodation === null ? [] : ['compose_details' => [
                'recipient_name' => $accommodation['recipient_name'] ?? null,
                'attachments' => $attachments,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'sender' => auth()->user()?->name,
            ]]),
        ]);

        if (empty($recipients)) {
            $log->update(['status' => MessageLog::STATUS_FAILED, 'error_message' => 'No valid recipient address.', 'failed_at' => now()]);

            return $log;
        }

        $replyToEmail = $accommodation['reply_to_email'] ?? null;
        $enqueue = function () use ($recipients, $subject, $body, $attachments, $log, $cc, $bcc, $rawHtml, $fromEmail, $fromName, $replyToEmail): void {
            // Cc/Bcc ride along via TemplatedMessage's envelope, same as the
            // templated client path — so a staff notice honours the template's
            // configured Cc/Bcc, not just the resolved role address.
            try {
                Mail::to($recipients)->queue(
                    new TemplatedMessage($subject ?? '', $body, $attachments, null, null, $log->id, $fromEmail, $fromName, $cc, $bcc, null, $rawHtml, $replyToEmail)
                );
            } catch (\Throwable $e) {
                Log::error('Composed email failed', ['address' => implode(', ', $recipients), 'error' => $e->getMessage()]);
                $log->update(['status' => MessageLog::STATUS_FAILED, 'error_message' => $e->getMessage(), 'failed_at' => now()]);
            }
        };
        if ($afterCommit) {
            DB::afterCommit($enqueue);
        } else {
            $enqueue();
        }

        return $log;
    }

    private function sendEmail(Lead $lead, string $subject, string $body, ?string $key, array $attachments = [], ?int $campaignId = null, ?string $bannerImage = null, ?string $footerImage = null, ?string $fromEmail = null, ?string $fromName = null, ?string $cc = null, ?string $bcc = null, ?string $branding = null, ?string $toExtra = null, bool $raw = false, ?string $replyToEmail = null, ?string $replyToName = null): MessageLog
    {
        if (empty($lead->email)) {
            return $this->log([
                'template_key' => $key, 'campaign_id' => $campaignId, 'channel' => MessageLog::CHANNEL_EMAIL,
                'recipient_id' => $lead->id, 'recipient_address' => '',
                'subject' => $subject, 'body' => $body, 'status' => MessageLog::STATUS_FAILED,
                'error_message' => 'Lead has no email address.', 'failed_at' => now(),
            ]);
        }

        // Log first (status 'queued'), then queue the mail carrying this log's
        // id. The MessageSent listener flips it to 'sent' once the worker
        // actually delivers it to the mail server.
        $log = $this->log([
            'template_key' => $key, 'campaign_id' => $campaignId, 'channel' => MessageLog::CHANNEL_EMAIL,
            'recipient_id' => $lead->id, 'recipient_address' => (string) $lead->email,
            'subject' => $subject, 'body' => $body, 'status' => MessageLog::STATUS_QUEUED,
        ]);

        // The lead is always the primary recipient; a template's optional "To
        // (also send to)" list adds extra addresses (e.g. the internal team) to
        // the To line, deduped against the lead's own address.
        $recipients = collect([$lead->email])
            ->merge($this->parseAddresses($toExtra))
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter()->unique()->values()->all();

        try {
            Mail::to($recipients)->queue(
                new TemplatedMessage($subject, $body, $attachments, $bannerImage, $footerImage, $log->id, $fromEmail, $fromName, $cc, $bcc, $branding, $raw, $replyToEmail, $replyToName)
            );
        } catch (\Throwable $e) {
            Log::error('CommunicationService email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            $log->update([
                'status' => MessageLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
        }

        return $log;
    }

    /**
     * Combine one or more comma/semicolon-separated address strings into a
     * single deduped comma-separated list (blank when none are valid).
     */
    public function mergeAddressList(?string ...$lists): ?string
    {
        $all = collect($lists)
            ->flatMap(fn ($l) => $this->parseAddresses($l))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter()->unique()->values();

        return $all->isNotEmpty() ? $all->implode(', ') : null;
    }

    /** Split a comma/semicolon-separated address string into valid emails. */
    private function parseAddresses(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return collect(preg_split('/[,;]+/', $raw))
            ->map(fn ($e) => trim($e))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()->all();
    }

    private function sendSms(Lead $lead, string $body, ?string $key): MessageLog
    {
        $to = $this->normalizePhone((string) $lead->phone);

        if (! $to) {
            return $this->log([
                'template_key' => $key, 'channel' => MessageLog::CHANNEL_SMS,
                'recipient_id' => $lead->id, 'recipient_address' => (string) $lead->phone,
                'body' => $body, 'status' => MessageLog::STATUS_FAILED,
                'error_message' => 'Invalid phone number — could not normalize to E.164.', 'failed_at' => now(),
            ]);
        }

        $res = $this->sms->send($to, $body);

        return $this->log([
            'template_key' => $key, 'channel' => MessageLog::CHANNEL_SMS,
            'recipient_id' => $lead->id, 'recipient_address' => $to, 'body' => $body,
            'status' => $res['ok'] ? MessageLog::STATUS_QUEUED : MessageLog::STATUS_FAILED,
            'provider_message_id' => $res['message_id'] ?? null,
            'error_message' => $res['ok'] ? null : ($res['error'] ?? 'SMS send failed.'),
            'failed_at' => $res['ok'] ? null : now(),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /** Standard variables available to every template, plus per-send extras. */
    private function buildContext(Lead $lead, array $extra): array
    {
        $staff = $lead->assignee;

        $eventCtx = [];
        if (! empty($lead->event_id)) {
            $event = \App\Models\Event::find($lead->event_id);
            if ($event) {
                $session = $lead->event_session_id ? \App\Models\EventSession::find($lead->event_session_id) : null;
                $eventCtx = app(\App\Services\EventEmailSender::class)->context($event, $session);
            }
        }

        // Self-service booking links from the lead's most recent booking token,
        // so booking templates sent via a LEAD path (e.g. the "Missed the
        // Meeting" follow-ups: missed_the_booking_1/2) still get a working
        // {{reschedule_url}} / {{cancel_url}}. Falls back to the tracker link.
        $trackerUrl = rtrim((string) config('app.url'), '/').'/track/'.$lead->tracking_code;
        $booking = \App\Models\Booking::where('lead_id', $lead->id)
            ->whereNotNull('manage_token')
            ->latest('id')
            ->first();
        $rescheduleUrl = $booking?->manage_token
            ? url('/booking/reschedule/'.$booking->manage_token)
            : $trackerUrl;
        $cancelUrl = $booking?->manage_token
            ? url('/booking/cancel/'.$booking->manage_token)
            : $trackerUrl;

        return array_merge([
            'first_name' => $lead->first_name ?? '',
            'last_name' => $lead->last_name ?? '',
            'full_name' => trim("{$lead->first_name} {$lead->last_name}"),
            'email' => $lead->email ?? '',
            'phone' => $lead->phone ?? '',
            'stage' => $lead->stage ?? ($lead->status ?? ''),
            'reschedule_url' => $rescheduleUrl,
            'cancel_url' => $cancelUrl,
            'visa_type' => $lead->inz_visa_type ?? '',
            // The case's most recent invoice number (blank if none issued yet);
            // an invoice-event context can override with a specific one.
            'invoice_number' => optional(
                \App\Models\LeadDocument::where('lead_id', $lead->id)
                    ->whereNotNull('invoice_number')->latest('id')->first()
            )->invoice_number ?? '',
            'tracker_url' => $trackerUrl,
            'client_portal_url' => $this->clientPortalUrl($lead),
            // Portal sign-in link + username (their email) — for templates that
            // ask the client to log in and act (e.g. re-upload a document).
            'portal_login_url' => rtrim((string) config('app.url'), '/').'/login',
            'portal_username' => $lead->email ?? '',
            // Plaintext password only exists at credential-generation time; it is
            // never stored, so it stays blank unless the calling action passes it.
            'password' => '',
            'has_portal_account' => $lead->portalUser()->exists() ? 'yes' : 'no',
            'assigned_staff_name' => $staff?->name ?? 'the EP team',
        ], $eventCtx, $extra);
    }

    /**
     * The best self-service link for this lead: their secured portal if they
     * have a portal account, else the public tracker link, else blank.
     */
    private function clientPortalUrl(Lead $lead): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($lead->portalUser()->exists()) {
            return $base.'/portal/lead/dashboard';
        }

        if (! empty($lead->tracking_code)) {
            return $base.'/track/'.$lead->tracking_code;
        }

        return '';
    }

    /**
     * Replace {{ var }} tokens. Unknown variables become empty strings
     * (never crash). Email bodies HTML-escape the substituted values to
     * stop a lead's own data injecting markup; SMS stays plain.
     */
    private function substitute(string $template, array $context, bool $escape): string
    {
        // Rich-text editors sometimes inject markup INSIDE a token, e.g.
        // {{<strong>first</strong>.name}}. Strip HTML tags found between the
        // braces first so the token becomes recognisable — otherwise the
        // malformed placeholder slips past substitution and breaks the mail
        // provider's own {{ }} template parser (Brevo: "Error … near '<'").
        $template = preg_replace_callback('/\{\{(.*?)\}\}/s', fn ($m) => '{{'.strip_tags($m[1]).'}}', $template) ?? $template;

        $rendered = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($context, $escape) {
            $key = strtolower($m[1]);
            if (! array_key_exists($key, $context)) {
                // Fall back from a dotted variable to its underscore form, so
                // {{first.name}} resolves to the `first_name` context key. Only
                // used when the literal key is absent, so templates that supply
                // literal dotted keys (e.g. booking's {{contact.email}}) win.
                $alt = str_replace('.', '_', $key);
                if ($alt !== $key && array_key_exists($alt, $context)) {
                    $key = $alt;
                } else {
                    Log::warning("CommunicationService: unknown template variable '{{{$key}}}'");

                    return '';
                }
            }
            $value = (string) $context[$key];

            // A few keys carry trusted, server-built HTML (their dynamic parts
            // are already escaped where built) — never double-escape those, or
            // their markup would render as visible text.
            $isRawHtml = in_array($key, self::RAW_HTML_KEYS, true);

            return ($escape && ! $isRawHtml) ? e($value) : $value;
        }, $template) ?? $template;

        // Safety net: drop any residual {{ … }} the substitution couldn't
        // resolve (e.g. a token with stray characters), so nothing the mail
        // provider would try — and fail — to parse ever leaves the app.
        return preg_replace('/\{\{.*?\}\}/s', '', $rendered) ?? $rendered;
    }

    /** Normalize a freeform phone to E.164 (default region NZ/PH fallback), or null. */
    public function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Prefer libphonenumber when available; fall back to a basic NZ/PH/E.164
        // normalizer so the service works even without the library.
        if (class_exists(\libphonenumber\PhoneNumberUtil::class)) {
            try {
                $util = \libphonenumber\PhoneNumberUtil::getInstance();

                // First try NZ (default region)
                $parsed = $util->parse($phone, 'NZ');
                if ($util->isValidNumber($parsed)) {
                    return $util->format($parsed, \libphonenumber\PhoneNumberFormat::E164);
                }

                // Fallback to PH (Philippines)
                $parsed = $util->parse($phone, 'PH');
                if ($util->isValidNumber($parsed)) {
                    return $util->format($parsed, \libphonenumber\PhoneNumberFormat::E164);
                }

                return null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $this->basicNormalize($phone);
    }

    private function basicNormalize(string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone);
        // Convert international 00 prefix to +
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (str_starts_with($digits, '+') && strlen($digits) >= 8) {
            return $digits;
        }
        // Philippines mobile numbers without leading 0 (e.g. 9206922477) are 10 digits long starting with 9
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '+63'.$digits;
        }
        // Philippines mobile numbers start with 09 and are 11 digits long
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+63'.ltrim($digits, '0');
        }
        if (str_starts_with($digits, '0')) {           // NZ national → +64
            return '+64'.ltrim($digits, '0');
        }

        return strlen($digits) >= 8 ? '+'.$digits : null;
    }

    private function log(array $data): MessageLog
    {
        return MessageLog::create(array_merge([
            'recipient_type' => 'lead',
            'triggered_by_user_id' => auth()->id(),
            'sent_at' => null,
        ], $data));
    }
}
