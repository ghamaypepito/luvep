<?php

namespace App\Services;

use App\Mail\EducationRsvpMessage;
use App\Models\EducationOutreach;
use App\Models\EducationVisit;
use App\Models\MessageLog;
use App\Models\User;
use App\Notifications\EducationRsvpReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EducationOutreachService
{
    public function plan(): void
    {
        EducationVisit::with(['rsvps' => fn ($q) => $q->whereNotNull('verified_at')->where('attendance_status', '!=', 'cancelled')])->each(function ($v) {
            foreach ($v->rsvps as $r) {
                $kind = null;
                if ($v->status === 'cancelled') {
                    $kind = 'cancelled';
                } elseif ($v->schedule_confirmed && $r->schedule_version_seen < $v->schedule_version) {
                    $kind = 'schedule';
                }if ($kind) {
                    app(EducationRsvpWorkflow::class)->receipt('rsvp:'.$r->id.':'.$kind.':'.$v->schedule_version, ['education_rsvp_id' => $r->id, 'channel' => 'mail', 'kind' => $kind, 'schedule_version' => $v->schedule_version]);
                }if (! $r->email_reminders || $v->status === 'cancelled' || ! ($start = $v->startsAt()) || $start->isPast()) {
                    continue;
                }$hours = now()->diffInMinutes($start, false) / 60;
                $notice = $hours <= 2 ? 'reminder_2h' : ($hours <= 24 ? 'reminder_24h' : null);
                if ($notice) {
                    app(EducationRsvpWorkflow::class)->receipt('rsvp:'.$r->id.':'.$notice.':'.$v->schedule_version, ['education_rsvp_id' => $r->id, 'channel' => 'mail', 'kind' => $notice, 'schedule_version' => $v->schedule_version]);
                }
            }
        });
    }

    public function process(EducationOutreach $delivery): void
    {
        Cache::lock('education-outreach:'.$delivery->id, 90)->get(function () use ($delivery) {
            $d = $delivery->fresh();
            if (! $d || $d->sent_at || $d->cancelled_at || $d->attempts >= 8 || ($d->next_attempt_at && $d->next_attempt_at->isFuture())) {
                return;
            }$r = $d->rsvp?->load('visit');
            if ($r && in_array($d->kind, ['schedule', 'cancelled', 'reminder_24h', 'reminder_2h'], true)) {
                $v = $r->visit;
                $cancel = $d->schedule_version !== $v->schedule_version || ! $r->verified_at || $r->attendance_status === 'cancelled';
                if ($d->kind === 'cancelled') {
                    $cancel = $cancel || $v->status !== 'cancelled';
                } else {
                    $cancel = $cancel || ! $v->schedule_confirmed || $v->status === 'cancelled';
                }if (str_starts_with($d->kind, 'reminder')) {
                    $start = $v->startsAt();
                    $hours = $start ? now()->diffInMinutes($start, false) / 60 : -1;
                    $cancel = $cancel || ! $r->email_reminders || $hours <= 0 || ($d->kind === 'reminder_24h' ? ($hours > 24 || $hours <= 2) : $hours > 2);
                }if ($d->kind === 'schedule') {
                    $cancel = $cancel || $r->schedule_version_seen >= $v->schedule_version;
                }if ($cancel) {
                    $d->update(['cancelled_at' => now()]);

                    return;
                }
            }
            $slack = app(ProgramSlackNotifier::class);
            if ($d->channel === 'slack' && ! $slack->configured()) {
                $d->update(['next_attempt_at' => now()->addMinutes(5), 'last_error' => 'Slack connection is pending.']);

                return;
            }
            $d->increment('attempts');
            try {
                if ($d->channel === 'mail' && $r) {
                    $mail = new EducationRsvpMessage($r, $d->kind);
                    $body = $mail->render();
                    $log = MessageLog::create(['source' => 'education-rsvp', 'channel' => 'email', 'recipient_type' => 'education_rsvp', 'recipient_id' => $r->id, 'recipient_address' => $r->email, 'subject' => $mail->subject, 'body' => $body, 'status' => 'queued']);
                    try {
                        Mail::to($r->email)->send($mail);
                        $log->update(['status' => 'sent', 'sent_at' => now()]);
                        if (in_array($d->kind, ['confirmation', 'schedule'], true) && $r->visit->schedule_confirmed) {
                            $r->update(['schedule_version_seen' => $r->visit->schedule_version]);
                        }
                    } catch (\Throwable $e) {
                        $log->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => 'RSVP mail delivery failed; retry pending.']);
                        throw $e;
                    }
                } elseif ($d->channel === 'staff' && $r) {
                    $staff = User::find($d->user_id);
                    if (! $staff || ! $staff->canAccessPortal('education')) {
                        $d->update(['cancelled_at' => now()]);

                        return;
                    }DB::transaction(function () use ($staff, $r, $d) {
                        $staff->notify(new EducationRsvpReceived($r));
                        $d->update(['sent_at' => now(), 'last_error' => null]);
                    });

                    return;
                } elseif ($d->channel === 'slack') {
                    if ($r) {
                        $slack->send('Education', 'New RSVP', ['Name' => $r->full_name, 'School' => $r->visit->school, 'Event' => $r->visit->activity, 'Schedule' => $r->visit->summary(), 'Status' => 'RSVP received · No programme application'], url('/portal/education/rsvps?rsvp='.$r->id));
                    } elseif ($reg = $d->registration) {
                        $slack->send('Education', 'New programme registration', ['Name' => trim(($reg->contact_details['first_name'] ?? '').' '.($reg->contact_details['last_name'] ?? '')), 'School' => $reg->school, 'Event' => $reg->event_name, 'Status' => 'Programme registration received'], url('/portal/education/leads/'.$reg->lead_id));
                    } else {
                        $d->update(['cancelled_at' => now()]);

                        return;
                    }
                } else {
                    $d->update(['cancelled_at' => now()]);

                    return;
                }
                $d->update(['sent_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);
            } catch (\Throwable $e) {
                $d->update(['last_error' => $d->channel === 'slack' ? $e->getMessage() : 'Delivery failed; retry pending.', 'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** min($d->attempts, 6))))]);
            }
        });
    }
}
