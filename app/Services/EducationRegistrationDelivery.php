<?php

namespace App\Services;

use App\Mail\EducationRegistrationWelcome;
use App\Models\EducationEventRegistration;
use App\Models\MessageLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EducationRegistrationReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EducationRegistrationDelivery
{
    public function deliver(EducationEventRegistration $registration): void
    {
        Cache::lock('education-delivery:'.$registration->id, 30)->block(5, function () use ($registration) {
            $r = $registration->fresh();
            $log = $r->welcome_log_id ? MessageLog::find($r->welcome_log_id) : null;
            if (! $log || $log->status === MessageLog::STATUS_FAILED) {
                try {
                    $email = config('services.contact.reply_to') ?: config('services.contact.email');
                    $body = view('emails.education-registration-welcome', ['registration' => $r, 'contact' => ['email_url' => $email ? 'mailto:'.$email : null]])->render();
                    $log = MessageLog::create([
                        'source' => 'education-event', 'channel' => 'email', 'recipient_type' => 'lead',
                        'recipient_id' => $r->lead_id, 'recipient_address' => $r->contact_details['email'],
                        'subject' => 'Welcome — your Education registration is received', 'body' => $body, 'status' => 'queued',
                    ]);
                    $r->update(['welcome_log_id' => $log->id]);
                    Mail::to($r->contact_details['email'])->queue(new EducationRegistrationWelcome(
                        $log->subject, $body, [], null, null, $log->id,
                        config('services.contact.event_from_email'), config('services.contact.event_from_name'),
                        rawHtml: true, replyToEmail: $email,
                    ));
                } catch (\Throwable $e) {
                    $log?->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => mb_substr($e->getMessage(), 0, 2000)]);
                    Log::error('Education welcome delivery pending retry', ['registration_id' => $r->id, 'error' => $e->getMessage()]);
                }
            }
            $ids = (array) Setting::get('education_landing_notify_ids', []);
            $recipients = $ids ? User::whereIn('id', $ids)->get()->filter(fn ($u) => $u->canAccessPortal('education')) : User::where('role', 'education')->get();
            $already = $r->notified_user_ids ?? [];
            foreach ($recipients as $staff) {
                if (in_array($staff->id, $already, true)) {
                    continue;
                }
                try {
                    $staff->notify(new EducationRegistrationReceived($r));
                    $already[] = $staff->id;
                    $r->update(['notified_user_ids' => $already]);
                } catch (\Throwable $e) {
                    Log::error('Education staff notice pending retry', ['registration_id' => $r->id, 'user_id' => $staff->id, 'error' => $e->getMessage()]);
                }
            }
            if ($log && $log->fresh()->status !== 'failed' && $recipients->isNotEmpty() && $recipients->every(fn ($u) => in_array($u->id, $already, true))) {
                $r->update(['outreach_completed_at' => now()]);
            }
        });
    }
}
