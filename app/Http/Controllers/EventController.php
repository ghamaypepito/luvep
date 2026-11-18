<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\FacebookLiveSession;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\NewRegistrationReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /**
     * Return all events (with session counts) for the Events page.
     */
    public function index(Request $request)
    {
        $events = Event::with(['sessions', 'agent:id,name,avatar_path'])->withCount(['sessions', 'leads'])->latest()->get();

        // Append the registration URL (and banner URL, if any) to each event
        $events->each(function ($event) {
            $event->registration_url = url('/register/'.$event->event_code);
            if ($event->banner_image) {
                $event->banner_image_url = Storage::disk('public')->url($event->banner_image);
            }
        });

        return inertia('admin/Events', [
            'events' => $events,
            'defaultFormFields' => Event::DEFAULT_FIELDS,
            'customFieldTypes' => Event::CUSTOM_FIELD_TYPES,
            'lockedFieldKeys' => Event::LOCKED_KEYS,
            // Recruiting agents for the "Agent" dropdown in the event editor.
            'agents' => \App\Models\User::where('role', 'agent')
                ->orderBy('name')
                ->get(['id', 'name']),
            // Active email templates the admin can bind as an event's
            // registration confirmation email (email-channel only).
            'emailTemplates' => \App\Models\MessageTemplate::active()
                ->orderBy('name')
                ->get(['id', 'name', 'key', 'department', 'channels'])
                ->filter(fn ($t) => $t->hasChannel('email'))
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'key' => $t->key,
                    'department' => $t->department,
                ])
                ->values(),
        ]);
    }

    /**
     * Show the detailed view for a single event (including leads).
     */
    public function show($id)
    {
        $event = Event::with('sessions')->findOrFail($id);

        // Optionally append the public URL for the banner image to be displayed in the overview
        if ($event->banner_image) {
            $event->banner_image_url = Storage::disk('public')->url($event->banner_image);
        }

        // Fetch leads that registered for this specific event
        $leads = $event->leads()->with(['studyPlans', 'educationExps', 'eventSession'])->latest()->get();

        // Active email templates staff can start from in the Email tab.
        $emailTemplates = \App\Models\MessageTemplate::active()
            ->whereIn('department', ['', $event->organizer_id])
            ->orderBy('name')
            ->get()
            ->filter(fn ($t) => $t->hasChannel('email'))
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'email_subject' => $t->email_subject,
                'email_body' => $t->email_body,
            ])
            ->values();

        // Recent emails sent to this event's registrants (Email tab history).
        $sentEmails = \App\Models\MessageLog::whereIn('recipient_id', $leads->pluck('id'))
            ->where('channel', 'email')
            ->latest()
            ->take(50)
            ->get(['id', 'subject', 'recipient_address', 'status', 'error_message', 'created_at'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'subject' => $l->subject,
                'recipient' => $l->recipient_address,
                'status' => $l->status,
                'error' => $l->error_message,
                'sent_at' => optional($l->created_at)->toIso8601String(),
            ]);

        // Scheduled (future) + recently-fired scheduled emails for this event.
        $scheduledEmails = \App\Models\ScheduledEventEmail::where('event_id', $event->id)
            ->with('creator:id,name')
            ->orderByDesc('scheduled_at')
            ->take(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'subject' => $s->subject,
                'recipient_count' => is_array($s->recipient_ids) ? count($s->recipient_ids) : 0,
                'scheduled_at' => optional($s->scheduled_at)->toIso8601String(),
                'status' => $s->status,
                'sent' => $s->sent_count,
                'failed' => $s->failed_count,
                'sent_at' => optional($s->sent_at)->toIso8601String(),
                'created_by' => $s->creator?->name,
                'cancelable' => $s->isCancelable(),
            ]);

        return inertia('admin/EventDetails', [
            'event' => $event,
            'leads' => $leads,
            'emailTemplates' => $emailTemplates,
            'sentEmails' => $sentEmails,
            'scheduledEmails' => $scheduledEmails,
        ]);
    }

    /**
     * Send a composed (or template-based) email to selected registrants of an
     * event. Body is rich HTML rendered through the branded shell; variables
     * like {{first_name}} and the event's {{event_*}} are substituted per lead.
     */
    public function sendRegistrantEmail(Request $request, $id, \App\Services\EventEmailSender $sender)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:65000',
            'recipient_ids' => 'required|array|min:1',
            'recipient_ids.*' => 'integer',
            'template_id' => 'nullable|integer|exists:message_templates,id',
        ]);

        // When composed from a template, the send brands the email with that
        // template's banner/footer so the Email tab matches it exactly.
        $template = ! empty($validated['template_id'])
            ? \App\Models\MessageTemplate::find($validated['template_id'])
            : null;

        $result = $sender->send($event, $validated['recipient_ids'], $validated['subject'], $validated['body'], $template);

        if ($result['sent'] === 0 && $result['failed'] === 0) {
            return back()->with('error', 'No valid recipients with an email address were selected.');
        }

        $msg = "Email queued to {$result['sent']} registrant".($result['sent'] === 1 ? '' : 's').'.';
        if ($result['failed'] > 0) {
            $msg .= " {$result['failed']} failed.";
        }

        return back()->with('success', $msg);
    }

    /**
     * Queue a composed email to send to selected registrants at a future
     * date/time. A per-minute scheduler (events:dispatch-due-emails) fires it
     * through the same branded send path when due.
     */
    public function scheduleRegistrantEmail(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:65000',
            'recipient_ids' => 'required|array|min:1',
            'recipient_ids.*' => 'integer',
            'template_id' => 'nullable|integer|exists:message_templates,id',
            'scheduled_at' => 'required|date|after:now',
        ]);

        // Snapshot only registrants of this event that have an email.
        $recipientIds = $event->leads()
            ->whereIn('leads.id', $validated['recipient_ids'])
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('leads.id')->all();

        if (empty($recipientIds)) {
            return back()->with('error', 'No valid recipients with an email address were selected.');
        }

        $scheduled = \App\Models\ScheduledEventEmail::create([
            'event_id' => $event->id,
            'created_by' => $request->user()->id,
            'template_id' => $validated['template_id'] ?? null,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'recipient_ids' => $recipientIds,
            'scheduled_at' => $validated['scheduled_at'],
            'status' => \App\Models\ScheduledEventEmail::STATUS_PENDING,
        ]);

        return back()->with('success', 'Email scheduled for '.$scheduled->scheduled_at->format('M j, Y g:i A').' to '.count($recipientIds).' registrant'.(count($recipientIds) === 1 ? '' : 's').'.');
    }

    /**
     * Cancel a still-pending scheduled event email before it fires.
     */
    public function cancelScheduledEmail($id, $scheduledId)
    {
        $scheduled = \App\Models\ScheduledEventEmail::where('event_id', $id)->findOrFail($scheduledId);

        if (! $scheduled->isCancelable()) {
            return back()->with('error', 'Only pending scheduled emails can be canceled.');
        }

        $scheduled->update(['status' => \App\Models\ScheduledEventEmail::STATUS_CANCELED]);

        return back()->with('success', 'Scheduled email canceled.');
    }

    /**
     * Store a newly created event (with optional sessions) in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            // Event-level start/end times — used when the event has no
            // sessions. Sessions still own their own time columns.
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i|after_or_equal:time_start',
            'status' => 'required|in:draft,upcoming,ongoing,completed,cancelled',
            'mode' => 'required|in:in-person,online,hybrid',
            'location' => 'nullable|string|max:255',
            'organizer_id' => 'nullable|string|max:255',
            // Recruiting agent assigned to this event (registrants get
            // attributed to them). Must be a user with the 'agent' role.
            'agent_id' => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'agent')],
            'notes' => 'nullable|string',
            // Optional per-event confirmation template; null = global default.
            'registration_template_id' => 'nullable|integer|exists:message_templates,id',
            'banner_image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:4096',
            // Custom registration-form schema. Null/missing = default fields.
            'form_fields' => 'nullable|array',
            'form_fields.*.key' => 'required|string|max:60|regex:/^[a-z][a-z0-9_]*$/',
            'form_fields.*.label' => 'required|string|max:120',
            'form_fields.*.type' => 'required|string|in:text,email,tel,textarea,select,pills',
            'form_fields.*.required' => 'nullable|boolean',
            'form_fields.*.locked' => 'nullable|boolean',
            'form_fields.*.default' => 'nullable|boolean',
            'form_fields.*.enabled' => 'nullable|boolean',
            'form_fields.*.placeholder' => 'nullable|string|max:200',
            'form_fields.*.hint' => 'nullable|string|max:300',
            'form_fields.*.section' => 'nullable|string|max:120',
            'form_fields.*.order' => 'nullable|integer|min:0',
            'form_fields.*.options' => 'nullable|array',
            'form_fields.*.options.*' => 'string|max:120',
            // sessions are fully optional
            'sessions' => 'nullable|array',
            'sessions.*.venue_name' => 'nullable|string|max:255',
            'sessions.*.address' => 'nullable|string|max:500',
            'sessions.*.city' => 'nullable|string|max:100',
            'sessions.*.date' => 'nullable|date',
            'sessions.*.time_start' => 'nullable|date_format:H:i',
            'sessions.*.time_end' => 'nullable|date_format:H:i',
            'sessions.*.capacity' => 'nullable|integer|min:1',
            'sessions.*.status' => 'nullable|in:draft,upcoming,ongoing,completed,cancelled',
        ]);

        // Handle banner image upload
        if ($request->hasFile('banner_image')) {
            $validated['banner_image'] = $request->file('banner_image')->store('events/banners', 'public');
        }

        try {
            DB::beginTransaction();

            // Auto-generate a unique event_code from the name + random suffix
            $eventCode = Str::slug($validated['name']).'-'.strtolower(Str::random(5));

            $event = Event::create(array_merge($validated, [
                'event_code' => $eventCode,
            ]));

            // Sessions are optional — only create if provided
            if (! empty($validated['sessions'])) {
                foreach ($validated['sessions'] as $sessionData) {
                    $event->sessions()->create($sessionData);
                }
            }

            DB::commit();

            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('success', 'Event created successfully.');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Event created successfully.',
                'data' => $event->load('sessions'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Event creation failed', ['error' => $e->getMessage()]);

            if ($request->header('X-Inertia')) {
                return redirect()->back()->withErrors(['message' => 'Failed to create event. Please try again.']);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create event. Please try again.',
            ], 500);
        }
    }

    /**
     * Update an existing event (and sync its sessions) in storage.
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            // Event-level start/end times — used when the event has no
            // sessions. Sessions still own their own time columns.
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i|after_or_equal:time_start',
            'status' => 'required|in:draft,upcoming,ongoing,completed,cancelled',
            'mode' => 'required|in:in-person,online,hybrid',
            'location' => 'nullable|string|max:255',
            'organizer_id' => 'nullable|string|max:255',
            // Recruiting agent assigned to this event (registrants get
            // attributed to them). Must be a user with the 'agent' role.
            'agent_id' => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'agent')],
            'notes' => 'nullable|string',
            // Optional per-event confirmation template; null = global default.
            'registration_template_id' => 'nullable|integer|exists:message_templates,id',
            'banner_image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:4096',
            // Custom registration-form schema. Null/missing = default fields.
            'form_fields' => 'nullable|array',
            'form_fields.*.key' => 'required|string|max:60|regex:/^[a-z][a-z0-9_]*$/',
            'form_fields.*.label' => 'required|string|max:120',
            'form_fields.*.type' => 'required|string|in:text,email,tel,textarea,select,pills',
            'form_fields.*.required' => 'nullable|boolean',
            'form_fields.*.locked' => 'nullable|boolean',
            'form_fields.*.default' => 'nullable|boolean',
            'form_fields.*.enabled' => 'nullable|boolean',
            'form_fields.*.placeholder' => 'nullable|string|max:200',
            'form_fields.*.hint' => 'nullable|string|max:300',
            'form_fields.*.section' => 'nullable|string|max:120',
            'form_fields.*.order' => 'nullable|integer|min:0',
            'form_fields.*.options' => 'nullable|array',
            'form_fields.*.options.*' => 'string|max:120',
            'sessions' => 'nullable|array',
            'sessions.*.id' => 'nullable|integer',
            'sessions.*.venue_name' => 'nullable|string|max:255',
            'sessions.*.address' => 'nullable|string|max:500',
            'sessions.*.city' => 'nullable|string|max:100',
            'sessions.*.date' => 'nullable|date',
            'sessions.*.time_start' => 'nullable|date_format:H:i',
            'sessions.*.time_end' => 'nullable|date_format:H:i',
            'sessions.*.capacity' => 'nullable|integer|min:1',
            'sessions.*.status' => 'nullable|in:draft,upcoming,ongoing,completed,cancelled',
        ]);

        // Handle banner image upload — only replace when a new file is provided
        if ($request->hasFile('banner_image')) {
            if ($event->banner_image) {
                Storage::disk('public')->delete($event->banner_image);
            }
            $validated['banner_image'] = $request->file('banner_image')->store('events/banners', 'public');
        } else {
            unset($validated['banner_image']);
        }

        try {
            DB::beginTransaction();

            $sessions = $validated['sessions'] ?? null;
            unset($validated['sessions']);

            $event->update($validated);

            // Retroactively credit the event's recruiting agent to registrants
            // who don't already have one, so setting/changing the agent on an
            // event with existing sign-ups flows straight to that agent's tab.
            if ($event->agent_id) {
                $event->leads()->whereNull('agent_id')->update(['agent_id' => $event->agent_id]);
            }

            // Sync sessions only when the key is present in the request.
            // Existing sessions (matched by id) are updated, new ones created,
            // and removed ones deleted — except those that already have
            // registered leads, to avoid orphaning lead->session links.
            if ($request->has('sessions')) {
                $keepIds = [];

                foreach ($sessions ?? [] as $sessionData) {
                    $sessionId = $sessionData['id'] ?? null;
                    unset($sessionData['id']);

                    if ($sessionId) {
                        $session = $event->sessions()->find($sessionId);
                        if ($session) {
                            $session->update($sessionData);
                            $keepIds[] = $session->id;
                        }
                    } else {
                        $keepIds[] = $event->sessions()->create($sessionData)->id;
                    }
                }

                $event->sessions()
                    ->whereNotIn('id', $keepIds)
                    ->whereDoesntHave('leads')
                    ->delete();
            }

            DB::commit();

            return redirect()->back()->with('success', 'Event updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Event update failed', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['message' => 'Failed to update event. Please try again.']);
        }
    }

    /**
     * Remove an event (its banner, sessions, and lead links) from storage.
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        try {
            DB::beginTransaction();

            // Detach any leads that registered for this event so we don't
            // delete the lead records themselves, just the event association.
            $event->leads()->update([
                'event_id' => null,
                'event_session_id' => null,
            ]);

            if ($event->banner_image) {
                Storage::disk('public')->delete($event->banner_image);
            }

            $event->sessions()->delete();
            $event->delete();

            DB::commit();

            return redirect()->back()->with('success', 'Event deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Event deletion failed', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['message' => 'Failed to delete event. Please try again.']);
        }
    }

    /**
     * Publicly show the registration form for a specific event.
     */
    public function showRegistrationForm(Request $request, $event_code)
    {
        $event = Event::with(['sessions' => function ($query) {
            $query->where('status', 'upcoming');
        }])->where('event_code', $event_code)->firstOrFail();

        // Append public URL for the banner image
        if ($event->banner_image) {
            $event->banner_image_url = Storage::disk('public')->url($event->banner_image);
        }

        // Hand the effective field schema to the React page so it can
        // render the form dynamically. Falls back to DEFAULT_FIELDS
        // when the event has no custom form_fields configured.
        $event->effective_fields = $event->effectiveFields();

        return inertia('registration/RegistrationPage', [
            'event' => $event,
            'registered' => $request->session()->get('registered_event_id') === $event->id,
        ]);
    }

    /**
     * Publicly register a lead for an event.
     */
    public function registerLead(Request $request, $event_code)
    {
        $event = Event::where('event_code', $event_code)->firstOrFail();

        // Build the dynamic validator from the event's effective field
        // schema. Locked keys (first_name / last_name / email / phone) are
        // always required regardless of admin config so the lead-follow-up
        // flow can never break.
        $fields = $event->effectiveFields();
        $rules = ['event_session_id' => 'nullable|exists:event_sessions,id'];

        foreach ($fields as $f) {
            $enabled = ($f['enabled'] ?? true) !== false;
            $locked = ($f['locked'] ?? false) === true;
            $required = ($f['required'] ?? false) === true;
            if (! $enabled && ! $locked) {
                continue;
            }

            $rule = ($required || $locked) ? 'required' : 'nullable';
            $rule .= '|'.match ($f['type'] ?? 'text') {
                'email' => 'email|max:255',
                'tel' => 'string|max:40',
                'textarea' => 'string|max:5000',
                'select', 'pills' => isset($f['options']) && count($f['options']) > 0
                    ? 'string|in:'.implode(',', $f['options'])
                    : 'string|max:255',
                default => 'string|max:255',
            };
            $rules[$f['key']] = $rule;
        }

        $validated = $request->validate($rules);

        try {
            DB::beginTransaction();

            // 1. Find-or-create the Lead through the unified intake —
            // repeat registrants by the same email are de-duped (a
            // 'lead.resubmitted' activity entry gets appended instead).
            $intake = app(\App\Services\LeadIntakeService::class);
            $lead = $intake->ingest("event:{$event->event_code}", array_filter([
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'country' => $validated['country'] ?? null,
                'stage' => $validated['interest'] ?? null,
            ], fn ($v) => ! is_null($v) && $v !== ''), $request);

            // Split responses into "known" (mapped to dedicated columns
            // on the lead / its related rows) and "custom" (everything
            // else — lands in lead.event_response JSON). The known set
            // matches Event::DEFAULT_FIELDS so newly-added custom fields
            // automatically take the JSON path.
            $knownKeys = array_column(\App\Models\Event::DEFAULT_FIELDS, 'key');
            $customResponses = collect($validated)
                ->except(array_merge($knownKeys, ['event_session_id']))
                ->all();

            // Attach event-level + work + financial context. Only fields
            // that were actually submitted contribute — null/missing
            // entries fall through array_filter and don't overwrite
            // anything the lead already had.
            $workInfo = array_filter([
                'employment_status' => $validated['employment_status'] ?? null,
                'city' => $validated['city'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ], fn ($v) => ! is_null($v) && $v !== '');

            $financialInfo = array_filter([
                'funding_source' => $validated['funding_source'] ?? null,
            ], fn ($v) => ! is_null($v) && $v !== '');

            $eventAttrs = array_filter([
                'branch' => $lead->branch ?: 'Online Registration',
                'event_id' => $event->id,
                'event_session_id' => $validated['event_session_id'] ?? null,
                // Credit the event's recruiting agent — unless the lead is
                // already attributed to an agent (don't override).
                'agent_id' => $lead->agent_id ?: $event->agent_id,
                'work_info' => $lead->work_info ?: ($workInfo ?: null),
                'financial_info' => $lead->financial_info ?: ($financialInfo ?: null),
                'event_response' => $customResponses ?: null,
            ], fn ($v) => ! is_null($v));
            $lead->update($eventAttrs);

            // 2. Add Education Experience — only when the relevant default
            // fields were submitted. Deduped by level so a repeat registrant
            // updates the same row instead of stacking duplicates.
            if (! empty($validated['education_level']) || ! empty($validated['field_of_study'])) {
                $lead->educationExps()->updateOrCreate(
                    ['level' => $validated['education_level'] ?? null],
                    array_filter([
                        'field_of_study' => $validated['field_of_study'] ?? null,
                        'institution' => 'N/A',
                    ], fn ($v) => ! is_null($v) && $v !== '')
                );
            }

            // 3. Add Study Plan — same gating. updateOrCreate keeps a single
            // canonical plan per lead so re-registrations enrich it instead of
            // creating duplicate rows.
            if (! empty($validated['interest']) || ! empty($validated['planning_timeline'])) {
                $planValues = array_filter([
                    'preferred_course' => $validated['interest'] ?? null,
                    'preferred_intake' => $validated['planning_timeline'] ?? null,
                    'preferred_city' => 'New Zealand',
                    'qualification_level' => $validated['education_level'] ?? null,
                ], fn ($v) => ! is_null($v) && $v !== '');
                if (! empty($planValues)) {
                    $lead->studyPlans()->firstOrNew([])->fill($planValues)->save();
                }
            }

            DB::commit();

            // Confirmation email. Prefer the staff-editable 'event_registration'
            // message template (with event variables); fall back to the built-in
            // branded mailable if that template isn't configured. Wrapped so a
            // mail/queue hiccup never turns a successful registration into error.
            if (! empty($lead->email)) {
                try {
                    $session = ! empty($validated['event_session_id'])
                        ? $event->sessions()->find($validated['event_session_id'])
                        : null;

                    $eventCtx = app(\App\Services\EventEmailSender::class)->context($event, $session);
                    // Per-event template if the admin picked one; otherwise the
                    // shared 'event_registration' template.
                    // By ID (not key/department resolution) so a template's
                    // department can change without breaking this. Only an
                    // ACTIVE template is used — a deleted or deactivated one
                    // falls back to the shared 'event_registration' default.
                    $eventTemplate = $event->registration_template_id
                        ? \App\Models\MessageTemplate::active()->find($event->registration_template_id)
                        : null;

                    $comms = app(\App\Services\CommunicationService::class);
                    $sent = $eventTemplate
                        ? $comms->sendTemplate($eventTemplate, $lead, $eventCtx)
                        : $comms->sendTemplated('event_registration', $lead, $eventCtx);

                    if (! $sent['email']) {
                        \Illuminate\Support\Facades\Mail::to($lead->email)
                            ->send(new \App\Mail\EventRegistrationConfirmation($lead, $event, $session));
                    }
                } catch (\Throwable $e) {
                    Log::error('Event confirmation email failed', [
                        'lead_id' => $lead->id,
                        'event_id' => $event->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($request->header('X-Inertia') || ! $request->wantsJson()) {
                return to_route('events.registration', ['event_code' => $event->event_code])
                    ->with('registered_event_id', $event->id);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Registration successful! We will contact you soon.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Public registration failed', ['error' => $e->getMessage()]);

            if ($request->header('X-Inertia') || ! $request->wantsJson()) {
                return redirect()->back()->withErrors(['error' => 'Registration failed. Please try again later.']);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed. Please try again later.',
            ], 500);
        }
    }

    /**
     * Notify the sales team (+ admins/super-admins) of a new registration.
     * Database channel only — best-effort, never blocks the public submit.
     */
    private function notifyNewRegistration(\App\Models\Lead $lead, string $kind, ?Event $event = null): void
    {
        try {
            $recipients = User::whereIn('role', ['sales', 'admin', 'super_admin'])->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new NewRegistrationReceived($lead, $kind, $event));
            }
        } catch (\Throwable $e) {
            Log::warning('New-registration notification failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Publicly list all upcoming and ongoing events.
     */
    public function activities()
    {
        $events = Event::with('sessions')
            ->whereIn('status', ['upcoming', 'ongoing'])
            ->latest()
            ->get();

        $events->each(function ($event) {
            $event->registration_url = url('/register/'.$event->event_code);
        });

        $today = today()->toDateString();

        $featuredSession = FacebookLiveSession::whereDate('session_date', '>=', $today)
            ->orderBy('session_date', 'asc')
            ->first();

        $pastSessions = FacebookLiveSession::whereDate('session_date', '<', $today)
            ->orderBy('session_date', 'desc')
            ->get();

        if (! $featuredSession && $pastSessions->isNotEmpty()) {
            $featuredSession = $pastSessions->shift();
        }

        return inertia('activities/ActivitiesPage', [
            'events' => $events,
            'pastSessions' => $pastSessions->values(),
            'featuredSession' => $featuredSession,
        ]);
    }

    /**
     * GET /admin/events/{eventId}/registrants/{leadId}
     *
     * Read-only registrant view — renders the event registration form
     * as-filled + an editable notes area, without the full lead-management
     * chrome. Reachable from the Events page → "View Lead" link on any
     * registrant row.
     *
     * Field resolution mirrors LeadController::show()'s eventRegistration
     * block: each of the event's effective fields is looked up in the
     * lead's dedicated columns, JSON buckets, related rows, or in the
     * event_response JSON for custom fields.
     */
    public function showRegistrant($eventId, $leadId)
    {
        $event = Event::findOrFail($eventId);
        $lead = Lead::with(['studyPlans', 'educationExps', 'eventNotesEditor:id,name'])
            ->where('event_id', $event->id)
            ->findOrFail($leadId);

        $eventFields = $event->effectiveFields();
        $eduFirst = $lead->educationExps->first();
        $planFirst = $lead->studyPlans->first();
        $work = is_array($lead->work_info) ? $lead->work_info : [];
        $finance = is_array($lead->financial_info) ? $lead->financial_info : [];
        $custom = is_array($lead->event_response) ? $lead->event_response : [];

        $resolveValue = function (array $field) use ($lead, $custom, $work, $finance, $eduFirst, $planFirst) {
            $key = $field['key'];
            if (array_key_exists($key, $custom)) {
                return $custom[$key];
            }

            return match ($key) {
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'country' => $lead->country ?: $lead->residence_country,
                'city' => $work['city'] ?? $lead->residence_city,
                'employment_status' => $work['employment_status'] ?? null,
                'remarks' => $work['remarks'] ?? null,
                'funding_source' => $finance['funding_source'] ?? null,
                'education_level' => optional($eduFirst)->level,
                'field_of_study' => optional($eduFirst)->field_of_study,
                'interest' => optional($planFirst)->preferred_course,
                'planning_timeline' => optional($planFirst)->preferred_intake,
                default => null,
            };
        };

        $registrationFields = collect($eventFields)
            ->filter(fn ($f) => ($f['locked'] ?? false) === true || ($f['enabled'] ?? true) !== false)
            ->map(fn ($f) => [
                'key' => $f['key'],
                'label' => $f['label'] ?? $f['key'],
                'type' => $f['type'] ?? 'text',
                'section' => $f['section'] ?? 'Additional',
                'options' => $f['options'] ?? null,
                'value' => $resolveValue($f),
            ])
            ->values()
            ->all();

        return inertia('admin/EventRegistrantView', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_code' => $event->event_code,
                'type' => $event->type,
                'date_from' => optional($event->date_from)->toIso8601String(),
                'location' => $event->location,
                'mode' => $event->mode,
            ],
            'lead' => [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'status' => $lead->status,
                'stage' => $lead->stage,
                'created_at' => optional($lead->created_at)->toIso8601String(),
            ],
            'registrationFields' => $registrationFields,
            'notes' => [
                'body' => $lead->event_notes,
                'updated_at' => optional($lead->event_notes_updated_at)->toIso8601String(),
                'editor_name' => optional($lead->eventNotesEditor)->name,
            ],
        ]);
    }

    /**
     * POST /admin/events/{eventId}/registrants/{leadId}/notes
     *
     * Save event-desk notes attached to this registrant. Notes live on the
     * lead itself (event_notes / event_notes_updated_at / event_notes_by)
     * because they're per-lead-per-event and there's exactly one event per
     * lead. Any staff member can edit.
     */
    public function updateRegistrantNotes(Request $request, $eventId, $leadId)
    {
        $validated = $request->validate([
            'event_notes' => 'nullable|string|max:10000',
        ]);

        $lead = Lead::where('event_id', $eventId)->findOrFail($leadId);
        $lead->fill([
            'event_notes' => $validated['event_notes'] ?? null,
            'event_notes_updated_at' => now(),
            'event_notes_updated_by' => Auth::id(),
        ])->save();

        return back()->with('success', 'Notes saved.');
    }
}
