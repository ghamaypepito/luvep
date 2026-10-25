<?php

namespace Tests\Feature;

use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\EducationOutreachService;
use App\Services\EducationRsvpWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationRsvpTest extends TestCase
{
    use RefreshDatabase;

    private function visit(array $data = []): EducationVisit
    {
        return EducationVisit::create(array_merge(['slug' => 'school-test', 'school' => 'Test University', 'kind' => 'school', 'city' => 'Davao', 'activity' => 'Career guidance', 'status' => 'open', 'timezone' => 'Asia/Manila', 'proposed_date' => '2026-11-05'], $data));
    }

    private function payload(EducationVisit $v, array $data = []): array
    {
        return array_merge(['submission_id' => (string) Str::uuid(), 'visit_id' => $v->id, 'full_name' => 'Maria Santos', 'email' => 'Maria@Example.com', 'consent' => true, 'email_reminders' => true, 'future_invites' => false], $data);
    }

    private function staff(): User
    {
        $a = User::factory()->create(['role' => 'education']);
        $b = User::factory()->create(['role' => 'education']);
        Setting::set('education_landing_notify_ids', [$a->id, $b->id], 'json');

        return $a;
    }

    public function test_public_rsvp_page_is_separate_from_programme_registration(): void
    {
        $this->get('/education/rsvp')->assertOk()->assertDontSee('Try a returning guest')->assertDontSee('Preview team alerts');
        $this->get('/education/register')->assertOk();
    }

    public function test_rsvp_has_optional_phone_no_lead_and_no_public_provisional_date(): void
    {
        $this->staff();
        $v = $this->visit();
        $this->getJson('/education/rsvp/catalogue')->assertOk()->assertJsonPath('visits.0.date', null)->assertDontSee('2026-11-05');
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseHas('education_rsvps', ['email' => 'maria@example.com', 'phone' => null]);
        $this->assertDatabaseCount('education_outreach', 4);
    }

    public function test_replays_are_idempotent_and_conflicting_uuid_cannot_change_contact(): void
    {
        $v = $this->visit();
        $p = $this->payload($v);
        $this->postJson('/education/rsvp', $p)->assertCreated();
        $this->postJson('/education/rsvp', $p)->assertOk();
        $this->postJson('/education/rsvp', array_merge($p, ['full_name' => 'Different Person']))->assertConflict();
        $this->postJson('/education/rsvp', $this->payload($v))->assertOk();
        $this->assertDatabaseCount('education_rsvps', 1);
    }

    public function test_invalid_consent_honeypot_and_cancelled_visit_are_rejected(): void
    {
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v, ['consent' => false, 'website' => 'spam']))->assertUnprocessable();
        $v->update(['status' => 'cancelled']);
        $this->postJson('/education/rsvp', $this->payload($v))->assertUnprocessable();
        $this->assertDatabaseCount('education_rsvps', 0);
    }

    public function test_verification_requires_signature_and_recognition_requires_email_proof(): void
    {
        $v = $this->visit();
        $p = $this->payload($v);
        $this->postJson('/education/rsvp', $p)->assertCreated();
        $r = EducationRsvp::firstOrFail();
        $this->postJson('/education/rsvp/recognize', $p)->assertJson(['recognized' => false]);
        $this->get('/education/rsvp/verify/'.$r->public_id)->assertForbidden();
        $this->get(URL::temporarySignedRoute('education.rsvp.verify', now()->addDays(2), ['rsvp' => $r->public_id]))->assertRedirect();
        $this->postJson('/education/rsvp/recognize', $p)->assertJson(['recognized' => true]);
        $this->postJson('/education/rsvp/recognize', array_merge($p, ['full_name' => 'Wrong Name']))->assertJson(['recognized' => false]);
    }

    public function test_only_verified_exact_unique_student_matches_link_without_changing_lead_stage(): void
    {
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $r = EducationRsvp::firstOrFail();
        $lead = Lead::create(['lead_id' => 'RSVP-TEST', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com', 'status' => 'Contact Attempted']);
        $workflow = app(EducationRsvpWorkflow::class);
        $this->assertSame(0, $workflow->linkLead($lead));
        $r->update(['verified_at' => now()]);
        $this->assertSame(1, $workflow->linkLead($lead));
        $this->assertSame(0, $workflow->linkLead($lead));
        $this->assertEquals($lead->id, $r->fresh()->lead_id);
        $this->assertSame('Contact Attempted', $lead->fresh()->status);
        $this->assertCount(1, $workflow->history($lead));
    }

    public function test_all_event_types_link_only_after_verified_programme_registration(): void
    {
        $v = $this->visit(['kind' => 'partnership']);
        $this->postJson('/education/rsvp', $this->payload($v, ['role' => 'Counsellor']))->assertCreated();
        EducationRsvp::first()->update(['verified_at' => now()]);
        $lead = Lead::create(['lead_id' => 'PARTNER-TEST', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com']);
        $this->assertSame(1, app(EducationRsvpWorkflow::class)->linkLead($lead));
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_unconfirmed_schedule_does_not_create_reminders_and_edits_cancel_stale_delivery(): void
    {
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $r = EducationRsvp::first();
        $r->update(['verified_at' => now()]);
        $service = app(EducationOutreachService::class);
        $service->plan();
        $this->assertDatabaseMissing('education_outreach', ['kind' => 'reminder_24h']);
        $v->update(['schedule_confirmed' => true, 'event_date' => now()->addHours(20)->timezone('Asia/Manila')->toDateString(), 'event_time' => now()->addHours(20)->timezone('Asia/Manila')->format('H:i'), 'schedule_version' => 1]);
        $service->plan();
        $d = EducationOutreach::where('kind', 'reminder_24h')->firstOrFail();
        $v->update(['schedule_version' => 2, 'schedule_confirmed' => false]);
        $service->process($d);
        $this->assertNotNull($d->fresh()->cancelled_at);
    }

    public function test_outreach_sends_confirmation_once_and_slack_waits_for_connection(): void
    {
        Mail::fake();
        Notification::fake();
        Http::fake();
        $staff = $this->staff();
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $service = app(EducationOutreachService::class);
        foreach (EducationOutreach::all() as $d) {
            $service->process($d);
            $service->process($d);
        }Mail::assertSent(\App\Mail\EducationRsvpMessage::class, 1);
        Notification::assertSentToTimes($staff, \App\Notifications\EducationRsvpReceived::class, 1);
        $slack = EducationOutreach::where('channel', 'slack')->firstOrFail();
        $this->assertNull($slack->fresh()->sent_at);
        Http::assertNothingSent();
    }

    public function test_slack_failure_retries_without_email_or_phone_in_payload(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::sequence()->push('error', 500)->push('ok', 200)]);
        config(['services.slack.education_webhook' => 'https://hooks.slack.com/services/T/B/test']);
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $d = EducationOutreach::where('channel', 'slack')->firstOrFail();
        app(EducationOutreachService::class)->process($d);
        $this->assertNull($d->fresh()->sent_at);
        $this->assertSame(1, $d->fresh()->attempts);
        Http::assertSent(fn ($req) => ! str_contains($req->body(), 'maria@example.com') && str_contains($req->body(), 'Maria Santos'));
        $d->refresh()->update(['next_attempt_at' => null]);
        app(EducationOutreachService::class)->process($d);
        $this->assertNotNull($d->fresh()->sent_at);
    }

    public function test_staff_schedule_manager_is_protected_and_can_confirm_date_later(): void
    {
        $v = $this->visit();
        $this->get('/portal/education/rsvps')->assertRedirect('/login');
        $sales = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sales)->get('/portal/education/rsvps')->assertForbidden();
        $staff = $this->staff();
        $this->actingAs($staff)->get('/portal/education/rsvps')->assertOk();
        $this->putJson('/portal/education/rsvp-visits/'.$v->id, ['event_date' => '2026-11-06', 'event_time' => '10:00', 'timezone' => 'Asia/Manila', 'schedule_confirmed' => true, 'status' => 'open', 'location' => 'Main hall'])->assertOk();
        $this->assertTrue($v->fresh()->schedule_confirmed);
        $this->assertSame(1, $v->fresh()->schedule_version);
    }

    public function test_programme_registration_creates_emma_pipeline_and_links_verified_rsvp_history(): void
    {
        Mail::fake();
        Notification::fake();
        $owner = $this->staff();
        Setting::set('education_landing_owner_id', $owner->id, 'int');
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $r = EducationRsvp::firstOrFail();
        $r->update(['verified_at' => now()]);
        $payload = ['submission_id' => (string) Str::uuid(), 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com', 'phone' => '+639171234567', 'goal' => 'Find the right course', 'event_name' => 'Education event', 'event_date' => '2026-11-05', 'event_hour' => 10, 'event_minute' => 0, 'event_period' => 'AM', 'event_timezone' => 'Asia/Manila', 'country' => 'Philippines', 'school' => 'Test University', 'planning_timeline' => 'Just exploring', 'education_level' => 'High School Graduate', 'consent' => true];
        $this->postJson('/education/register', $payload)->assertCreated();
        $lead = Lead::where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('New Leads', $lead->status);
        $this->assertEquals($owner->id, $lead->assigned_to);
        $this->assertEquals($lead->id, $r->fresh()->lead_id);
        $this->assertDatabaseHas('education_outreach', ['education_registration_id' => \App\Models\EducationEventRegistration::first()->id, 'channel' => 'slack']);
        $account = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($account)->get('/portal/lead/journey')->assertOk()->assertSee('RSVP received');
        $other = Lead::create(['lead_id' => 'OTHER-LEAD', 'first_name' => 'Other', 'last_name' => 'Person', 'email' => 'other@example.com']);
        $otherAccount = User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);
        $this->actingAs($otherAccount)->get('/portal/lead/journey')->assertOk()->assertDontSee('RSVP received');
    }

    public function test_seed_is_one_event_per_school_and_does_not_overwrite_finalized_schedule(): void
    {
        $this->artisan('education:seed-rsvp-visits')->assertSuccessful();
        $this->assertDatabaseCount('education_visits', 7);
        $v = EducationVisit::first();
        $v->update(['event_date' => '2026-11-08', 'schedule_confirmed' => true]);
        $this->artisan('education:seed-rsvp-visits')->assertSuccessful();
        $this->assertDatabaseCount('education_visits', 7);
        $this->assertTrue($v->fresh()->schedule_confirmed);
        $this->assertSame('2026-11-08', $v->fresh()->event_date->format('Y-m-d'));
    }

    public function test_unverified_or_opted_out_contacts_get_no_reminders(): void
    {
        $v = $this->visit(['event_date' => now()->addHour()->timezone('Asia/Manila')->format('Y-m-d'), 'event_time' => now()->addHour()->timezone('Asia/Manila')->format('H:i'), 'schedule_confirmed' => true]);
        $this->postJson('/education/rsvp', $this->payload($v, ['email_reminders' => false]))->assertCreated();
        $r = EducationRsvp::first();
        $r->update(['verified_at' => now()]);
        app(EducationOutreachService::class)->plan();
        $this->assertDatabaseMissing('education_outreach', ['kind' => 'reminder_2h']);
        $r->update(['verified_at' => null, 'email_reminders' => true]);
        app(EducationOutreachService::class)->plan();
        $this->assertDatabaseMissing('education_outreach', ['kind' => 'reminder_2h']);
    }

    public function test_ambiguous_names_or_wrong_names_are_not_linked(): void
    {
        $v = $this->visit();
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $r = EducationRsvp::first();
        $r->update(['verified_at' => now()]);
        $lead = Lead::create(['lead_id' => 'A-1', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com']);
        Lead::create(['lead_id' => 'A-2', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'MARIA@example.com']);
        $this->assertSame(0, app(EducationRsvpWorkflow::class)->linkLead($lead));
        $this->assertNull($r->fresh()->lead_id);
    }

    public function test_closing_intake_preserves_confirmed_attendee_reminders(): void
    {
        Mail::fake();
        $v = $this->visit(['event_date' => now()->addHour()->timezone('Asia/Manila')->format('Y-m-d'), 'event_time' => now()->addHour()->timezone('Asia/Manila')->format('H:i'), 'schedule_confirmed' => true]);
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        EducationRsvp::first()->update(['verified_at' => now()]);
        $v->update(['status' => 'closed']);
        $service = app(EducationOutreachService::class);
        $service->plan();
        $delivery = EducationOutreach::where('kind', 'reminder_2h')->first();
        $this->assertNotNull($delivery);
        $service->process($delivery);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_slack_backlog_cannot_starve_new_confirmation_delivery(): void
    {
        $v = $this->visit();
        for ($i = 0; $i < 500; $i++) {
            $r = EducationRsvp::create(['public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'education_visit_id' => $v->id, 'full_name' => 'Earlier Guest '.$i, 'name_key' => 'earlierguest'.$i, 'email' => 'earlier'.$i.'@example.test', 'consented_at' => now()]);
            EducationOutreach::create(['event_key' => 'earlier-slack-'.$i, 'education_rsvp_id' => $r->id, 'channel' => 'slack', 'kind' => 'received']);
        }
        $this->postJson('/education/rsvp', $this->payload($v))->assertCreated();
        $confirmation = EducationOutreach::where('channel', 'mail')->firstOrFail();
        foreach ([null, 'https://hooks.slack.com/services/T/B/test'] as $hook) {
            config(['services.slack.education_webhook' => $hook]);
            \Illuminate\Support\Facades\Queue::fake();
            $this->artisan('education:dispatch-rsvps')->assertSuccessful();
            \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendEducationOutreach::class, fn ($job) => $job->deliveryId === $confirmation->id);
        }
    }
}
