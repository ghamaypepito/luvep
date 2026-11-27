<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Accommodation\ExaltEmailTemplates;
use App\Services\CommunicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Compose module — write a one-off email and send it to picked leads and/or any
 * typed addresses, with a left-hand history of Compose-sent emails to preview.
 * Reuses the template body editor (WYSIWYG + Unlayer builder). Sent through
 * CommunicationService::sendComposedEmail (logged with source='compose').
 */
class ComposeController extends Controller
{
    public function index(Request $request)
    {
        $ctx = $this->context($request);

        return inertia($ctx['component'], [
            'partnerRecipient' => ($partner = $this->partnerRecipient($request)) ? ['id' => $partner->id, 'name' => $partner->name, 'email' => $partner->email] : null,
            'basePath' => $ctx['basePath'],
            'sent' => $this->sentList($request),
            'templates' => $this->composeTemplates($request),
        ]);
    }

    private function composeTemplates(Request $request)
    {
        $accommodation = $request->routeIs('portal.accommodation.compose');
        $templates = MessageTemplate::active()->availableTo($request->user())
            ->when($accommodation, fn ($query) => $query->forDepartment('accommodation'))
            ->when(! $accommodation, fn ($query) => $query->where(fn ($other) => $other
                ->where('department', '!=', 'accommodation')->orWhereNull('department')
                ->orWhereNull('key')->orWhereNotIn('key', ExaltEmailTemplates::KEYS)))
            ->whereJsonContains('channels', 'email')
            ->whereNotNull('email_body')->where('email_body', '!=', '')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'department', 'email_subject', 'email_body', 'design_json']);

        return ($accommodation ? ExaltEmailTemplates::sort($templates) : $templates)->map(function (MessageTemplate $template) use ($accommodation) {
            if (! preg_match('/<[a-z][\s\S]*>/i', $template->email_body)) {
                // Markdown percent-encodes the merge fields used as link destinations.
                $template->email_body = preg_replace('/%7B%7B([a-z0-9_.]+)%7D%7D/i', '{{$1}}', Str::markdown($template->email_body));
            }
            if ($accommodation && in_array($template->key, ExaltEmailTemplates::KEYS, true)) {
                $template->exalt = app(ExaltEmailTemplates::class)->metadata($template);
            }

            return $template->makeHidden('key');
        });
    }

    public function send(Request $request, CommunicationService $comms)
    {
        $this->context($request);

        $data = $request->validate([
            'lead_ids' => ['nullable', 'array', 'max:50'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'emails' => ['nullable', 'array', 'max:50'],
            'emails.*' => ['email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $leadIds = $data['lead_ids'] ?? [];
        $emails = array_values(array_unique(array_map('strtolower', $data['emails'] ?? [])));
        if (empty($leadIds) && empty($emails)) {
            return back()->withErrors(['error' => 'Add at least one recipient.']);
        }

        $leads = $this->recipientLeads($request)->whereIn('id', $leadIds)->get();
        abort_unless($leads->count() === count(array_unique($leadIds)), 403, 'You do not have access to one or more recipients.');

        $partner = $this->partnerRecipient($request);
        if ($partner && ($leadIds || count($emails) !== 1 || $emails[0] !== mb_strtolower(trim($partner->email)))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['emails' => 'Partner tracking requires the selected partner as the only recipient.']);
        }
        $subject = $data['subject'] ?? '';
        $body = $data['body'];
        // A visual-builder body is a complete HTML document → send self-contained.
        $rawHtml = (bool) preg_match('/^\s*<(!doctype|html)\b/i', $body);

        // Store attachments once, shared across recipients.
        $attachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('compose-attachments', 'local');
            $attachments[] = ['path' => $path, 'name' => $file->getClientOriginalName()];
        }

        $count = 0;

        // Lead recipients — substitute {{variables}} against each lead.
        foreach ($leads as $lead) {
            if (empty($lead->email)) {
                continue;
            }
            $comms->sendComposedEmail(
                $lead->email,
                $comms->render($lead, $subject),
                $comms->render($lead, $body, [], true),
                $attachments,
                $rawHtml,
                $lead->id,
            );
            $count++;
        }

        // Typed addresses — no lead context, so strip any {{variables}}.
        $strip = fn ($t) => preg_replace('/\{\{.*?\}\}/s', '', (string) $t);
        foreach ($emails as $email) {
            $comms->sendComposedEmail($email, $strip($subject), $strip($body), $attachments, $rawHtml, null, partnerId: $partner?->id);
            $count++;
        }

        return back()->with('success', "Email sent to {$count} recipient".($count === 1 ? '' : 's').'.');
    }

    /** Image upload for the Compose builder (Unlayer asset manager). */
    public function uploadImage(Request $request)
    {
        $this->context($request);

        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        $out = [];
        foreach ($request->file('files', []) as $file) {
            $path = $file->store('email-assets', 'public');
            $out[] = ['src' => Storage::disk('public')->url($path)];
        }

        return response()->json(['data' => $out]);
    }

    /** Typeahead for the recipient picker — leads with an email, by name/email/id. */
    public function searchLeads(Request $request)
    {
        $this->context($request);

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['leads' => []]);
        }

        $leads = $this->recipientLeads($request)->whereNotNull('email')->where('email', '!=', '')
            ->where(function ($w) use ($q) {
                $w->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('lead_id', 'like', "%{$q}%");
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: $l->email,
                'email' => $l->email,
            ]);

        return response()->json(['leads' => $leads]);
    }

    private function recipientLeads(Request $request): Builder
    {
        $user = $request->user();
        $query = Lead::query();
        if (in_array($user->role, ['agent', User::ROLE_SUB_AGENT], true)) {
            $ownerId = $user->role === 'agent' ? $user->id : $user->parent_agent_id;
            $query->where('agent_id', $ownerId ?? 0);
        }

        return $query;
    }

    /** External partners see their own sends, with current lead access enforced. */
    private function sentList(Request $request)
    {
        $canReadPartnerMail = app(\App\Services\PartnerActivityTracker::class)->canAccess($request->user())
            && ! $request->attributes->get('user_view_actor');

        return MessageLog::where('channel', MessageLog::CHANNEL_EMAIL)
            ->where(fn ($sources) => $sources->where('source', 'compose')
                ->when($canReadPartnerMail, fn ($allowed) => $allowed->orWhere('source', 'partner-tracker')))
            ->when(in_array($request->user()->role, ['agent', User::ROLE_SUB_AGENT], true), fn ($query) => $query
                ->where('triggered_by_user_id', $request->user()->id)
                ->where(fn ($recipients) => $recipients->where('recipient_type', 'raw')
                    ->orWhere(fn ($leads) => $leads->where('recipient_type', 'lead')
                        ->whereIn('recipient_id', $this->recipientLeads($request)->select('id')))))
            ->with(['lead:id,first_name,last_name', 'triggeredBy:id,name'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (MessageLog $m) => [
                'id' => $m->id,
                'to' => $m->recipient_address,
                'to_name' => ($m->recipient_type === 'lead' && $m->lead)
                    ? trim("{$m->lead->first_name} {$m->lead->last_name}") : null,
                'subject' => $m->subject,
                'body' => $m->body,
                'status' => $m->status,
                'sender' => $m->triggeredBy?->name,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ]);
    }

    private function partnerRecipient(Request $request): ?User
    {
        if (! $request->filled('partner_id')) {
            return null;
        }
        abort_unless($this->context($request)['basePath'] === '/portal/education/compose', 403);
        app(\App\Services\PartnerActivityTracker::class)->authorize($request);
        $id = $request->validate(['partner_id' => 'required|integer'])['partner_id'];
        $partner = User::where('role', 'agent')->findOrFail($id);
        if (! filter_var($partner->email, FILTER_VALIDATE_EMAIL)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['partner_id' => 'Add a valid email to the partner account first.']);
        }

        return $partner;
    }

    /** Resolve the acting view context (admin vs a department portal) from the route. */
    private function context(Request $request): array
    {
        $name = (string) $request->route()?->getName();

        if (str_starts_with($name, 'portal.')) {
            $dept = explode('.', $name)[1] ?? null;
            if (in_array($dept, User::PORTAL_ROLES, true)) {
                return [
                    'basePath' => "/portal/{$dept}/compose",
                    'component' => "portal/{$dept}/Compose",
                ];
            }
        }

        return ['basePath' => '/admin/email/compose', 'component' => 'admin/email/Compose'];
    }
}
