<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\CommunicationService;
use App\Support\LeadDiscussionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One-off message to a single lead from the lead detail page. Email / SMS /
 * both, template-or-custom, personalised and logged via CommunicationService.
 * Access-gated per lead so a staffer can only message a lead in their
 * department (admins anyone).
 */
class ComposeMessageController extends Controller
{
    public function __construct(protected CommunicationService $comms) {}

    /** GET /admin/leads/{lead}/compose/templates */
    public function templates(Request $request, Lead $lead)
    {
        abort_unless(LeadDiscussionAccess::staffCanDiscuss($request->user(), $lead), 403, 'You do not have access to this lead.');

        $department = $request->user()->templateDepartment();

        return response()->json(MessageTemplate::active()
            ->when($department !== null, fn ($query) => $query->whereIn('department', [$department, '']))
            ->orderBy('name')->get(['id', 'name', 'channels']));
    }

    /** POST /admin/leads/{lead}/compose */
    public function send(Request $request, Lead $lead)
    {
        abort_unless(LeadDiscussionAccess::staffCanDiscuss($request->user(), $lead), 403, 'You do not have access to this lead.');

        $data = $request->validate([
            'channel' => 'required|in:email,sms,both',
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'subject' => ['nullable', Rule::requiredIf(fn () => ! $request->filled('template_id') && in_array($request->input('channel'), ['email', 'both'], true)), 'string', 'max:255'],
            'body' => 'nullable|required_without:template_id|string|max:50000',
        ]);

        $logs = [];

        if (! empty($data['template_id'])) {
            $template = MessageTemplate::findOrFail($data['template_id']);
            $department = $request->user()->templateDepartment();
            abort_if($department !== null && ! in_array($template->department, [$department, ''], true), 403, 'This template belongs to another department.');

            $channels = $data['channel'] === 'both' ? ['email', 'sms'] : [$data['channel']];
            if (array_diff($channels, $template->channels ?? [])) {
                throw ValidationException::withMessages(['channel' => 'This template does not support all selected channels.']);
            }

            $recipientErrors = [];
            if (in_array('email', $channels, true) && blank($lead->email)) {
                $recipientErrors['email'] = 'This lead has no email address on file.';
            }
            if (in_array('sms', $channels, true) && blank($lead->phone)) {
                $recipientErrors['phone'] = 'This lead has no phone number on file.';
            }
            if ($recipientErrors) {
                throw ValidationException::withMessages($recipientErrors);
            }

            // Use this exact selection and only the requested channels. The
            // channel override is in memory and never changes the saved template.
            $template->channels = $channels;
            $sent = $this->comms->sendTemplate($template, $lead);
            $logs = array_values(array_filter([$sent['email'] ?? null, $sent['sms'] ?? null]));
        } else {
            $subject = $this->comms->render($lead, (string) ($data['subject'] ?? ''));
            $body = $this->comms->render($lead, $data['body']);

            if (in_array($data['channel'], ['email', 'both'], true)) {
                $logs[] = $this->comms->sendRaw(MessageLog::CHANNEL_EMAIL, $lead, $subject, $body);
            }
            if (in_array($data['channel'], ['sms', 'both'], true)) {
                $logs[] = $this->comms->sendRaw(MessageLog::CHANNEL_SMS, $lead, null, $body);
            }
        }

        if (collect($logs)->contains(fn ($log) => $log->status === MessageLog::STATUS_FAILED)) {
            return response()->json(['message' => 'One or more messages could not be sent. Check the conversation status before retrying.', 'logs' => $logs], 502);
        }

        return response()->json(['logs' => $logs, 'count' => count($logs)]);
    }
}
