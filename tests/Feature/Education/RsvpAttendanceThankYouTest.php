<?php

namespace Tests\Feature\Education;

use App\Mail\EducationRsvpMessage;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\MessageLog;
use App\Services\EducationOutreachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class RsvpAttendanceThankYouTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_sends_once_at_five_hours_to_eligible_rsvps_only(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-09 23:59:00', 'Asia/Manila')->utc());
        $visit = $this->visit('2026-11-09');
        $recipient = $this->rsvp($visit);
        $this->rsvp($visit, ['attendance_status' => 'cancelled']);
        $this->rsvp($this->visit('2026-11-08'));
        $this->rsvp($this->visit('2026-11-09', ['status' => 'cancelled']));
        $this->rsvp($this->visit('2026-11-09', ['schedule_confirmed' => false]));
        $bounced = $this->rsvp($visit);
        MessageLog::create([
            'channel' => 'email', 'recipient_type' => 'education_rsvp',
            'recipient_address' => $bounced->email, 'status' => MessageLog::STATUS_BOUNCED,
        ]);

        $this->artisan('education:dispatch-rsvps')->assertSuccessful();
        Mail::assertNothingSent();

        $this->travel(1)->minutes();
        $this->artisan('education:dispatch-rsvps')->assertSuccessful();
        $this->artisan('education:dispatch-rsvps')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(EducationRsvpMessage::class, function ($mail) use ($recipient) {
            $mail->assertHasReplyTo('emma@luvep.com');
            $mail->assertSeeInHtml('Book a Free Consultation');
            $mail->assertSeeInHtml('https://calendar.google.com/calendar/appointments/schedules/AcZssZ195GftX_H65VZZmTZkt2-N8xZKyEBHK3Cmv4Z3aUt4G6OynvouCIZLOTbzFjFIA67PjgahnnsL');

            return $mail->notice === 'thank_you' && $mail->hasTo($recipient->email);
        });
    }

    public function test_queued_mail_checks_the_revised_start_time_before_sending(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-10 00:00:00', 'Asia/Manila')->utc());
        $visit = $this->visit('2026-11-09');
        $this->rsvp($visit);
        $service = app(EducationOutreachService::class);
        $service->plan();
        $delivery = EducationOutreach::where('kind', 'thank_you')->sole();

        $visit->update(['event_date' => '2026-11-10', 'event_time' => '09:00']);
        $service->process($delivery);
        Mail::assertNothingSent();
        $this->assertSame('2026-11-10 06:00:00', $delivery->fresh()->next_attempt_at?->utc()->toDateTimeString());

        $this->travelTo(Carbon::parse('2026-11-10 14:00:00', 'Asia/Manila')->utc());
        $this->artisan('education:dispatch-rsvps')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    private function visit(string $date, array $attributes = []): EducationVisit
    {
        return EducationVisit::create($attributes + [
            'slug' => (string) Str::uuid(), 'school' => 'Example University',
            'kind' => 'school', 'activity' => 'Education orientation', 'status' => 'open',
            'timezone' => 'Asia/Manila', 'schedule_confirmed' => true,
            'event_date' => $date, 'event_time' => '19:00',
        ]);
    }

    private function rsvp(EducationVisit $visit, array $attributes = []): EducationRsvp
    {
        return $visit->rsvps()->create($attributes + [
            'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'full_name' => 'Test Attendee',
            'name_key' => 'testattendee', 'email' => Str::uuid().'@example.test',
            'consented_at' => now(),
        ]);
    }
}
