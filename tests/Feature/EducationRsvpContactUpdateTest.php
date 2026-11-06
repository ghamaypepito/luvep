<?php

namespace Tests\Feature;

use App\Mail\EducationRsvpContactRequest;
use App\Models\ActivityLog;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\Lead;
use App\Services\EducationOutreachService;
use App\Services\EducationRsvpContactUpdate;
use App\Services\EducationRsvpWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationRsvpContactUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function attendee(array $extra = []): EducationRsvp
    {
        $v = EducationVisit::create(['slug' => (string) Str::uuid(), 'school' => 'Davao City', 'kind' => 'school', 'activity' => 'EP Education event', 'status' => 'open', 'timezone' => 'Asia/Manila']);

        return EducationRsvp::create(array_merge(['education_visit_id' => $v->id, 'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'full_name' => 'Maria Santos', 'name_key' => EducationRsvpWorkflow::nameKey('Maria Santos'), 'email' => 'maria@example.test', 'phone' => null, 'consented_at' => now(), 'attendance_status' => 'rsvp'], $extra));
    }

    public function test_new_rsvps_require_a_number_and_accept_completed_contacts(): void
    {
        $r = $this->attendee();
        $payload = ['submission_id' => (string) Str::uuid(), 'visit_id' => $r->education_visit_id, 'full_name' => 'New Attendee', 'email' => 'new@example.test', 'consent' => true];
        $this->postJson('/education/rsvp', $payload)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/education/rsvp', $payload + ['phone' => '+639171234567'])->assertCreated();
        $this->assertDatabaseHas('education_rsvps', ['email' => 'new@example.test', 'phone' => '+639171234567']);
    }

    public function test_contact_link_requires_signature_expiry_and_unchanged_email(): void
    {
        $r = $this->attendee();
        $this->get('/education/rsvp/contact/'.$r->public_id)->assertForbidden();
        $url = app(EducationRsvpContactUpdate::class)->url($r);
        $this->get($url)->assertOk()->assertSee('Add your contact number')->assertHeader('Referrer-Policy', 'no-referrer');
        $other = $this->attendee(['email' => 'other@example.test']);
        $this->get(str_replace($r->public_id, $other->public_id, $url))->assertForbidden();
        $r->update(['email' => 'changed@example.test']);
        $this->get($url)->assertForbidden();
        $r->update(['email' => 'maria@example.test']);
        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_number_is_required_and_an_unsigned_post_cannot_update_a_record(): void
    {
        $r = $this->attendee();
        $url = app(EducationRsvpContactUpdate::class)->url($r);
        foreach (['', '   ', null] as $phone) {
            $this->postJson($url, ['phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->postJson('/education/rsvp/contact/'.$r->public_id, ['phone' => '+639171234567'])->assertForbidden();
        $this->assertNull($r->fresh()->phone);
    }

    public function test_secure_update_fills_only_matching_blank_records_and_preserves_other_numbers(): void
    {
        $lead = Lead::create(['lead_id' => 'PHONE-UPDATE', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.test', 'phone' => null, 'status' => 'Contact Attempted']);
        $r = $this->attendee(['lead_id' => $lead->id, 'verified_at' => now()]);
        $sibling = $this->attendee();
        $existing = $this->attendee(['phone' => '+639180000000']);
        $other = $this->attendee(['full_name' => 'Ana Santos', 'name_key' => 'anasantos']);
        $url = app(EducationRsvpContactUpdate::class)->url($r);
        $this->post($url, ['phone' => '+63 917 123 4567', 'consent' => false, 'email' => 'other@example.test'])->assertRedirect($url);
        $this->assertSame('+63 917 123 4567', $r->fresh()->phone);
        $this->assertSame('+63 917 123 4567', $sibling->fresh()->phone);
        $this->assertSame('+639180000000', $existing->fresh()->phone);
        $this->assertNull($other->fresh()->phone);
        $this->assertSame('+63 917 123 4567', $lead->fresh()->phone);
        $this->assertSame('Contact Attempted', $lead->fresh()->status);
        $this->assertSame('maria@example.test', $r->fresh()->email);
        $this->assertNotNull($r->fresh()->consented_at);
        $audit = ActivityLog::where('action', 'education.rsvp.phone_updated')->firstOrFail();
        $this->assertStringNotContainsString('917', json_encode($audit->properties));
        $this->assertDatabaseCount('education_outreach', 0);
    }

    public function test_linked_applicant_existing_or_mismatched_contact_is_never_overwritten(): void
    {
        $lead = Lead::create(['lead_id' => 'PHONE-KEEP', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.test', 'phone' => '+6421000000', 'status' => 'New Lead']);
        $r = $this->attendee(['lead_id' => $lead->id]);
        $this->post(app(EducationRsvpContactUpdate::class)->url($r), ['phone' => '+639171234567'])->assertRedirect();
        $this->assertSame('+6421000000', $lead->fresh()->phone);
        $lead->update(['phone' => null, 'email' => 'different@example.test']);
        $this->post(app(EducationRsvpContactUpdate::class)->url($r), ['phone' => '+639181234567'])->assertRedirect();
        $this->assertNull($lead->fresh()->phone);
    }

    public function test_cancelled_contacts_cannot_use_update_links(): void
    {
        foreach ([['attendance_status' => 'cancelled']] as $attributes) {
            $r = $this->attendee($attributes);
            $url = app(EducationRsvpContactUpdate::class)->url($r);
            $this->get($url)->assertGone();
            $this->postJson($url, ['phone' => '+639171234567'])->assertGone();
            $this->assertNull($r->fresh()->phone);
        }
    }

    public function test_campaign_is_dry_by_default_then_sends_once_per_missing_number_identity(): void
    {
        Mail::fake();
        $r = $this->attendee();
        $this->attendee(); // Same email and name, another event.
        $this->attendee(['email' => 'has-phone@example.test', 'phone' => '+639170000001']);
        $this->attendee(['email' => 'cancelled@example.test', 'attendance_status' => 'cancelled']);
        $cancelledEvent = $this->attendee(['email' => 'cancelled-event@example.test']);
        $cancelledEvent->visit->update(['status' => 'cancelled']);
        $this->artisan('education:request-rsvp-contact')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('education_outreach', 0);
        $this->artisan('education:request-rsvp-contact --send')->assertSuccessful();
        $this->artisan('education:request-rsvp-contact --send')->assertSuccessful();
        Mail::assertSent(EducationRsvpContactRequest::class, 1);
        $this->assertDatabaseCount('education_outreach', 1);
        $this->assertNotNull(EducationOutreach::first()->sent_at);
    }

    public function test_pending_request_is_cancelled_if_number_is_added_before_sending(): void
    {
        Mail::fake();
        $r = $this->attendee();
        $d = app(EducationRsvpContactUpdate::class)->request($r);
        $r->update(['phone' => '+639171234567']);
        app(EducationOutreachService::class)->process($d);
        Mail::assertNothingSent();
        $this->assertNotNull($d->fresh()->cancelled_at);
    }

    public function test_mail_has_secure_update_link_emma_reply_to_and_no_programme_signup(): void
    {
        $r = $this->attendee();
        $mail = new EducationRsvpContactRequest($r);
        $html = $mail->render();
        $this->assertStringContainsString('Add my contact number', $html);
        $this->assertStringContainsString('schedule and venue', $html);
        $mail->assertHasReplyTo('emma@luvep.com');
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($mail->updateUrl)));
        $this->assertStringNotContainsString('/education/register', $html);
        $this->assertStringNotContainsString('signature=', $mail->logBody($html));
    }
}
