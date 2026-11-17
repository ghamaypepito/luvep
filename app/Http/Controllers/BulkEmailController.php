<?php

namespace App\Http\Controllers;

use App\Jobs\SendCampaign;
use App\Models\EmailCampaign;
use App\Models\Lead;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bulk campaigns — one feature, two channels (email + SMS). Compose from a
 * department/shared template, pick recipients from the leads list, send now or
 * schedule. History + per-recipient status come from message_logs keyed by
 * campaign_id. The channel is inferred from the route (…email.sms* → SMS).
 *
 * Email runs in the sales portal (admins reach it by browsing the portal) and
 * under /admin; SMS is admin-only. Everything else is shared.
 */
class BulkEmailController extends Controller
{
    public function index(Request $request)
    {
        $department = $this->department($request);
        $channel = $this->channel($request);
        $filterNewLeads = $department === 'education' && $channel === EmailCampaign::CHANNEL_EMAIL;
        $eventSender = app(\App\Services\EventEmailSender::class);

        return inertia($this->page($request, 'index'), [
            'basePath' => $this->basePath($request),
            'channel' => $channel,
            'templates' => $this->availableTemplates($department, $channel),
            'recipients' => $this->recipientPool($channel, $filterNewLeads),
            'filterNewLeads' => $filterNewLeads,
            'events' => \App\Models\Event::orderBy('name')
                ->get()
                ->map(fn (\App\Models\Event $e) => array_merge(
                    ['id' => $e->id, 'name' => $e->name],
                    $eventSender->context($e, null)
                ))
                ->values(),
            'campaigns' => EmailCampaign::forDepartmentList($department)
                ->where('channel', $channel)
                ->with('creator:id,name')
                ->latest()->get()->map(fn (EmailCampaign $c) => $this->campaignRow($c)),
        ]);
    }

    public function store(Request $request)
    {
        $department = $this->department($request);
        $channel = $this->channel($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'template_id' => ['required', 'integer', 'exists:message_templates,id'],
            'recipient_lead_ids' => ['required', 'array', 'min:1'],
            'recipient_lead_ids.*' => ['integer'],
            'new_leads_only' => ['sometimes', 'boolean'],
            'action' => ['required', Rule::in(['send_now', 'schedule'])],
            'scheduled_at' => ['nullable', 'required_if:action,schedule', 'date', 'after:now'],
        ]);

        $template = MessageTemplate::findOrFail($data['template_id']);
        $this->authorizeTemplate($department, $template);
        if (! $template->is_active || ! $template->hasChannel($channel)) {
            return back()->with('error', "Pick an active {$channel} template.");
        }

        // Keep only recipients reachable on this channel (snapshot for history).
        $leadIds = array_unique($data['recipient_lead_ids']);
        $newLeadsOnly = $department === 'education' && $channel === EmailCampaign::CHANNEL_EMAIL && $request->boolean('new_leads_only');
        $leads = Lead::query()->when($newLeadsOnly, fn ($query) => $query->inNewLeads())->whereIn('id', $leadIds)->get();
        if ($newLeadsOnly && $leads->count() !== count($leadIds)) {
            throw ValidationException::withMessages([
                'recipient_lead_ids' => 'Some selected recipients are no longer in New Leads. Refresh the list and select the recipients again.',
            ]);
        }

        $comms = app(\App\Services\CommunicationService::class);
        $recipientIds = [];

        foreach ($leads as $lead) {
            if ($channel === EmailCampaign::CHANNEL_SMS) {
                if ($lead->phone && $comms->normalizePhone($lead->phone)) {
                    $recipientIds[] = $lead->id;
                }
            } else {
                if ($lead->email && filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
                    $recipientIds[] = $lead->id;
                }
            }
        }

        if (empty($recipientIds)) {
            return back()->with('error', $channel === EmailCampaign::CHANNEL_SMS
                ? 'None of the selected leads have a valid phone number.'
                : 'None of the selected leads have a valid email address.');
        }

        $scheduling = $data['action'] === 'schedule';
        $isSms = $channel === EmailCampaign::CHANNEL_SMS;

        $campaign = EmailCampaign::create([
            'name' => $data['name'],
            'department' => $department ?? '',
            'created_by' => $request->user()->id,
            'template_id' => $template->id,
            'channel' => $channel,
            'subject' => $isSms ? '' : (string) $template->email_subject,
            'body' => $isSms ? (string) $template->sms_body : (string) $template->email_body,
            'status' => $scheduling ? EmailCampaign::STATUS_SCHEDULED : EmailCampaign::STATUS_SENDING,
            'scheduled_at' => $scheduling ? $data['scheduled_at'] : null,
            'total_recipients' => count($recipientIds),
            'recipient_lead_ids' => $recipientIds,
            'new_leads_only' => $newLeadsOnly,
        ]);

        $noun = $isSms ? 'SMS campaign' : 'Campaign';

        if (! $scheduling) {
            SendCampaign::dispatch($campaign->id);

            return redirect()->to($this->basePath($request))
                ->with('success', "{$noun} '{$campaign->name}' is sending to ".count($recipientIds).' recipients.');
        }

        return redirect()->to($this->basePath($request))
            ->with('success', "{$noun} '{$campaign->name}' scheduled for ".$campaign->scheduled_at->format('M j, Y g:i A').'.');
    }

    public function show(Request $request, $id)
    {
        $department = $this->department($request);
        $campaign = EmailCampaign::with('creator:id,name')->findOrFail($id);
        $this->authorizeCampaign($department, $campaign);

        return inertia($this->page($request, 'show'), [
            'basePath' => $this->basePath($request),
            'channel' => $campaign->channel,
            'campaign' => array_merge($this->campaignRow($campaign), [
                'subject' => $campaign->subject,
                'body' => $campaign->body,
            ]),
            'recipients' => $campaign->logs()->with('lead:id,first_name,last_name,email,phone')
                ->latest()->get()->map(fn ($log) => [
                    'id' => $log->id,
                    'name' => trim(($log->lead->first_name ?? '').' '.($log->lead->last_name ?? '')) ?: '—',
                    'email' => $log->recipient_address,
                    'status' => $log->status,
                    'error' => $log->error_message,
                ]),
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $department = $this->department($request);
        $campaign = EmailCampaign::findOrFail($id);
        $this->authorizeCampaign($department, $campaign);

        if (! $campaign->isCancelable()) {
            return back()->with('error', 'Only scheduled campaigns can be canceled.');
        }

        $campaign->update(['status' => EmailCampaign::STATUS_CANCELED]);

        return back()->with('success', 'Campaign canceled.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /** Channel for this request — SMS routes carry '.sms' in their name. */
    private function channel(Request $request): string
    {
        return str_contains((string) $request->route()?->getName(), '.sms')
            ? EmailCampaign::CHANNEL_SMS
            : EmailCampaign::CHANNEL_EMAIL;
    }

    /** Acting department from the portal route (e.g. 'sales'); null = admin scope. */
    private function department(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();
        if (str_starts_with($name, 'portal.')) {
            $candidate = explode('.', $name)[1] ?? null;
            if (in_array($candidate, MessageTemplate::DEPARTMENTS, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** True when reached via an /admin route (vs a portal route). */
    private function isAdmin(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.');
    }

    /** Page component to render for this channel/scope. */
    private function page(Request $request, string $which): string
    {
        $dept = $this->department($request);
        $isSms = $this->channel($request) === EmailCampaign::CHANNEL_SMS;

        // Portal (department) scope → render under that department's layout so
        // sales/education/immigration each get their own chrome.
        if ($dept !== null) {
            if ($which === 'show') {
                return "portal/{$dept}/CampaignDetail";
            }

            return $isSms ? "portal/{$dept}/Sms" : "portal/{$dept}/BulkEmail";
        }

        // Admin scope.
        if ($isSms) {
            return $which === 'show' ? 'admin/email/CampaignDetail' : 'admin/email/Sms';
        }

        return $which === 'show' ? 'admin/email/CampaignDetail' : 'admin/email/BulkMail';
    }

    /** Base URL for links/redirects/posts — portal department or admin scope. */
    private function basePath(Request $request): string
    {
        $isSms = $this->channel($request) === EmailCampaign::CHANNEL_SMS;
        $dept = $this->department($request);

        if ($dept !== null) {
            return $isSms ? "/portal/{$dept}/sms" : "/portal/{$dept}/bulk-email";
        }

        return $isSms ? '/admin/email/sms' : '/admin/email/bulk';
    }

    /** Active templates this department may use on the channel (own + shared). */
    private function availableTemplates(?string $department, string $channel): \Illuminate\Support\Collection
    {
        $isSms = $channel === EmailCampaign::CHANNEL_SMS;

        // Shared library — every portal may send with any active template.
        return MessageTemplate::active()
            ->orderBy('name')->get()
            ->filter(fn (MessageTemplate $t) => $t->hasChannel($channel))
            ->map(fn (MessageTemplate $t) => [
                'id' => $t->id, 'name' => $t->name,
                'subject' => $isSms ? null : $t->email_subject,
                'body' => $isSms ? $t->sms_body : $t->email_body,
            ])->values();
    }

    /** Selectable recipients: every lead reachable on the channel. */
    private function recipientPool(string $channel, bool $filterNewLeads): \Illuminate\Support\Collection
    {
        $query = Lead::query();
        if ($channel === EmailCampaign::CHANNEL_SMS) {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        } else {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        $comms = app(\App\Services\CommunicationService::class);
        $newLeadIds = $filterNewLeads
            ? Lead::inNewLeads()->select('id')
            : null;

        return $query->select(['id', 'first_name', 'last_name', 'email', 'phone', 'stage', 'status', 'event_id'])
            ->when($newLeadIds, fn ($q) => $q->selectRaw('id in ('.$newLeadIds->toSql().') as is_new_lead', $newLeadIds->getBindings()))
            ->orderBy('first_name')->get()
            ->filter(function (Lead $l) use ($channel, $comms) {
                if ($channel === EmailCampaign::CHANNEL_SMS) {
                    return $l->phone && $comms->normalizePhone($l->phone);
                } else {
                    return $l->email && filter_var($l->email, FILTER_VALIDATE_EMAIL);
                }
            })
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: '—',
                'email' => $l->email,
                'phone' => $l->phone,
                'stage' => $l->stage,
                'status' => $l->status,
                'event_id' => $l->event_id,
                'is_new_lead' => (bool) $l->is_new_lead,
            ])->values();
    }

    private function campaignRow(EmailCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'total' => $c->total_recipients,
            'sent' => $c->sent_count,
            'failed' => $c->failed_count,
            'skipped' => $c->skipped_count,
            'scheduled_at' => optional($c->scheduled_at)?->toIso8601String(),
            'completed_at' => optional($c->completed_at)?->toIso8601String(),
            'created_at' => optional($c->created_at)?->toIso8601String(),
            'created_by' => $c->creator?->name,
            'cancelable' => $c->isCancelable(),
        ];
    }

    private function authorizeTemplate(?string $department, MessageTemplate $template): void
    {
        // Templates are a shared library — any portal may send with any of them.
    }

    private function authorizeCampaign(?string $department, EmailCampaign $campaign): void
    {
        if ($department !== null && $campaign->department !== $department) {
            abort(403, 'This campaign belongs to another department.');
        }
    }
}
