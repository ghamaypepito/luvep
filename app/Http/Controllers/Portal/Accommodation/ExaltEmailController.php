<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationViewingAttendee as Attendee;
use App\Models\AccommodationViewingSlot as Slot;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\Accommodation\ExaltEmailActions;
use App\Services\Accommodation\ExaltEmailTemplates;
use App\Services\Accommodation\ViewingSchedule;
use App\Services\CommunicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExaltEmailController extends Controller
{
    public function options(Request $request, ExaltEmailTemplates $templates, ExaltEmailActions $actions)
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['template_id' => 'required|integer', 'email' => 'required|email|max:191']);
        $template = $this->template($data['template_id']);

        return response()->json([
            'options' => $actions->options($templates->metadata($template)['action'], mb_strtolower($data['email'])),
        ]);
    }

    public function preview(Request $request, ExaltEmailTemplates $templates, ExaltEmailActions $actions)
    {
        return response()->json($this->prepare($request, $templates, $actions, preview: true));
    }

    public function send(Request $request, ExaltEmailTemplates $templates, ExaltEmailActions $actions, CommunicationService $communications)
    {
        $message = $this->prepare($request, $templates, $actions, preview: false);
        $attachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('compose-attachments', 'local');
            if (! $path) {
                Storage::disk('local')->delete(array_column($attachments, 'path'));
                throw ValidationException::withMessages(['attachments' => 'Could not store the attachments. Please try again.']);
            }
            $attachments[] = ['path' => $path, 'name' => $file->getClientOriginalName()];
        }

        // The Accommodation Compose history rollout adds its own source and metadata.
        $tracking = defined(MessageLog::class.'::SOURCE_ACCOMMODATION_COMPOSE')
            ? ['accommodation' => ['recipient_name' => $message['recipient_name']]]
            : [];
        $log = $communications->sendComposedEmail(
            $message['email'], $message['subject'], $message['body'], $attachments, true, ...$tracking,
        );
        if ($log->status === MessageLog::STATUS_FAILED) {
            throw ValidationException::withMessages(['email' => 'The email could not be queued. Please review Compose and try again.']);
        }

        return back()->with('success', 'Email queued for '.$message['email'].'.');
    }

    public function attendance(Request $request, Attendee $attendee, string $response, ExaltEmailActions $actions)
    {
        $this->authorizeResponse($request, $attendee, $response, $actions);

        return view('accommodation.viewing-attendance', ['response' => $response, 'saved' => false]);
    }

    public function recordAttendance(Request $request, Attendee $attendee, string $response, ExaltEmailActions $actions)
    {
        DB::transaction(function () use ($request, $attendee, $response, $actions) {
            $slot = Slot::lockForUpdate()->findOrFail($attendee->viewing_slot_id);
            $record = $slot->attendees()->lockForUpdate()->findOrFail($attendee->id);
            $record->setRelation('slot', $slot);
            $this->authorizeResponse($request, $record, $response, $actions);
            if ($record->attendance_response !== $response) {
                $record->forceFill(['attendance_response' => $response, 'attendance_responded_at' => now()])->save();
            }
        }, 3);

        return view('accommodation.viewing-attendance', ['response' => $response, 'saved' => true]);
    }

    public function invoice(Request $request, int $invoice, ExaltEmailActions $actions)
    {
        return $actions->invoiceDownload($invoice, (string) $request->query('recipient', ''));
    }

    private function prepare(Request $request, ExaltEmailTemplates $templates, ExaltEmailActions $actions, bool $preview): array
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'template_id' => 'required|integer',
            'email' => 'required|email|max:191',
            'action_id' => 'nullable|integer',
            'fields' => 'nullable|array|max:36',
            'fields.*' => 'nullable|string|max:3000',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240',
        ]);
        $template = $this->template($data['template_id']);
        $action = $templates->metadata($template)['action'];
        if (! $preview && $action === 'welcome' && empty($data['attachments'])) {
            throw ValidationException::withMessages(['attachments' => 'Attach the storage photos and room condition report before sending.']);
        }

        $email = mb_strtolower($data['email']);
        [$recordFields, $buttons] = $actions->resolve($action, $email, isset($data['action_id']) ? (int) $data['action_id'] : null, $preview);

        return [
            ...$templates->render($template, $data['fields'] ?? [], $recordFields, $buttons),
            'email' => $email,
            'recipient_name' => $data['fields']['First Name'] ?? null,
        ];
    }

    private function template(int $id): MessageTemplate
    {
        return MessageTemplate::active()->forDepartment('accommodation')
            ->whereIn('key', ExaltEmailTemplates::KEYS)->findOrFail($id);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(ViewingSchedule::staffCanManage($request->user()) && ! $request->attributes->get('user_view_actor'), 403);
    }

    private function authorizeResponse(Request $request, Attendee $attendee, string $response, ExaltEmailActions $actions): void
    {
        abort_unless(in_array($response, ['confirmed', 'declined'], true)
            && hash_equals($actions->recipientHash($attendee->email), (string) $request->query('recipient', ''))
            && $attendee->status === Attendee::CONFIRMED && $attendee->slot->startsAt()->isFuture(), 404);
    }
}
