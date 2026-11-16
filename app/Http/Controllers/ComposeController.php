<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\CommunicationService;
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
            'basePath' => $ctx['basePath'],
            'sent' => $this->sentList(),
            'templates' => MessageTemplate::active()
                ->whereJsonContains('channels', 'email')
                ->whereNotNull('email_body')->where('email_body', '!=', '')
                ->orderBy('name')
                ->get(['id', 'name', 'department', 'email_subject', 'email_body', 'design_json'])
                ->map(function (MessageTemplate $template) {
                    if (! preg_match('/<[a-z][\s\S]*>/i', $template->email_body)) {
                        // Markdown percent-encodes the merge fields used as link destinations.
                        $template->email_body = preg_replace('/%7B%7B([a-z0-9_.]+)%7D%7D/i', '{{$1}}', Str::markdown($template->email_body));
                    }

                    return $template;
                }),
        ]);
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
        foreach (Lead::whereIn('id', $leadIds)->get() as $lead) {
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
        $strip = fn ($t) => preg_replace('/\{\{\s*\w+\s*\}\}/', '', (string) $t);
        foreach ($emails as $email) {
            $comms->sendComposedEmail($email, $strip($subject), $strip($body), $attachments, $rawHtml, null);
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

        $leads = Lead::whereNotNull('email')->where('email', '!=', '')
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

    /** Recent Compose-sent emails (shared history) for the left panel. */
    private function sentList()
    {
        return MessageLog::where('channel', MessageLog::CHANNEL_EMAIL)
            ->where('source', 'compose')
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
