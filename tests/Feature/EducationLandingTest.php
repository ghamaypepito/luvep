<?php

namespace Tests\Feature;

use App\Models\EducationEventRegistration;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EducationRegistrationReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationLandingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'submission_id' => (string) Str::uuid(), 'goal' => 'Find the right course',
            'event_name' => 'Cebu School Visit', 'event_date' => '2026-10-25',
            'event_hour' => 12, 'event_minute' => 15, 'event_period' => 'PM',
            'event_timezone' => 'Asia/Manila', 'country' => 'Philippines',
            'school' => 'University of San Carlos', 'planning_timeline' => 'Just exploring',
            'education_level' => "Bachelor's Degree", 'first_name' => 'Maria',
            'last_name' => 'Santos', 'email' => 'maria@example.com', 'phone' => '+639000000000',
            'consent' => true, 'website' => '',
        ], $overrides);
    }

    private function owner(): User
    {
        $user = User::factory()->create(['role' => 'education']);
        Setting::set('education_landing_owner_id', $user->id, 'int');
        Setting::set('education_landing_notify_ids', [$user->id], 'json');

        return $user;
    }

    public function test_public_page_has_real_links_and_no_review_or_theme_picker(): void
    {
        $this->get('/education/register')->assertOk()->assertSee('Philippines')
            ->assertSee('/login', false)->assertDontSee('CONCEPT PREVIEW')
            ->assertDontSee('See what happens in the portal')->assertDontSee('id="ep-appearance"', false);
    }

    public function test_registration_assigns_new_lead_and_sends_logged_welcome_and_staff_notice(): void
    {
        Mail::fake();
        Notification::fake();
        $owner = $this->owner();
        $payload = $this->payload();
        $this->postJson('/education/register', $payload)->assertCreated()->assertJson(['ok' => true]);
        $lead = Lead::where('email', 'maria@example.com')->firstOrFail();
        $this->assertEquals($owner->id, $lead->assigned_to);
        $this->assertSame('New Leads', $lead->status);
        $this->assertFalse((bool) $lead->is_student);
        $this->assertDatabaseHas('education_event_registrations', ['lead_id' => $lead->id, 'school' => $payload['school'], 'country' => 'Philippines', 'event_time' => '12:15', 'event_match_status' => 'pending']);
        $this->assertDatabaseHas('lead_education_exps', ['lead_id' => $lead->id, 'institution' => $payload['school']]);
        $this->assertDatabaseHas('message_logs', ['recipient_id' => $lead->id, 'recipient_type' => 'lead', 'channel' => 'email']);
        Mail::assertQueued(\App\Mail\EducationRegistrationWelcome::class);
        Notification::assertSentTo($owner, EducationRegistrationReceived::class);
    }

    public function test_duplicate_submission_does_not_repeat_outreach_or_registration(): void
    {
        Mail::fake();
        Notification::fake();
        $owner = $this->owner();
        $payload = $this->payload();
        $this->postJson('/education/register', $payload)->assertCreated();
        $this->postJson('/education/register', $payload)->assertOk();
        $this->assertDatabaseCount('education_event_registrations', 1);
        $this->assertDatabaseCount('leads', 1);
        Mail::assertQueuedCount(1);
        Notification::assertSentToTimes($owner, EducationRegistrationReceived::class, 1);
        $this->postJson('/education/register', array_merge($payload, ['school' => 'Changed']))->assertConflict();
    }

    public function test_returning_applicant_keeps_owner_stage_and_previous_event(): void
    {
        Mail::fake();
        Notification::fake();
        $this->owner();
        $other = User::factory()->create(['role' => 'sales']);
        $lead = Lead::create(['lead_id' => 'EDU-RETURN', 'first_name' => 'Existing', 'last_name' => 'Client', 'email' => 'maria@example.com', 'status' => 'Contact Attempted', 'assigned_to' => $other->id]);
        $this->postJson('/education/register', $this->payload())->assertCreated();
        $this->postJson('/education/register', $this->payload(['event_name' => 'Second school visit']))->assertCreated();
        $lead->refresh();
        $this->assertEquals($other->id, $lead->assigned_to);
        $this->assertSame('Contact Attempted', $lead->status);
        $this->assertSame('Existing', $lead->first_name);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('education_event_registrations', 2);
    }

    public function test_consent_invalid_time_and_honeypot_are_rejected_before_intake(): void
    {
        Mail::fake();
        Notification::fake();
        $this->postJson('/education/register', $this->payload(['consent' => false, 'event_hour' => 13]))->assertUnprocessable()->assertJsonValidationErrors(['consent', 'event_hour']);
        $this->postJson('/education/register', $this->payload(['website' => 'spam']))->assertUnprocessable();
        $this->assertDatabaseCount('leads', 0);
        Mail::assertNothingQueued();
    }

    public function test_midnight_converts_correctly_and_preparation_is_session_scoped(): void
    {
        Mail::fake();
        Notification::fake();
        $this->owner();
        $payload = $this->payload(['event_hour' => 12, 'event_period' => 'AM']);
        $this->postJson('/education/register', $payload)->assertCreated();
        $registration = EducationEventRegistration::firstOrFail();
        $this->assertSame('00:15', $registration->event_time);
        $this->postJson('/education/register/next-step', ['submission_id' => $payload['submission_id'], 'questions' => 'Compare nursing programmes.'])->assertOk();
        $this->assertSame('Compare nursing programmes.', $registration->fresh()->preparation_notes);
        $this->flushSession();
        $this->postJson('/education/register/next-step', ['submission_id' => $payload['submission_id'], 'questions' => 'Overwrite'])->assertForbidden();
    }

    public function test_welcome_escapes_applicant_input_and_has_all_requested_links(): void
    {
        Mail::fake();
        Notification::fake();
        $this->owner();
        $this->postJson('/education/register', $this->payload(['event_name' => '<script>alert(1)</script>']))->assertCreated();
        $body = \App\Models\MessageLog::where('recipient_type', 'lead')->firstOrFail()->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringContainsString('/login', $body);
        $this->assertStringContainsString('Visit website', $body);
        $this->assertStringContainsString('Contact adviser', $body);
    }

    public function test_pending_outreach_is_recovered_without_another_applicant_submit(): void
    {
        Mail::fake();
        Notification::fake();
        $owner = $this->owner();
        $this->postJson('/education/register', $this->payload())->assertCreated();
        $r = EducationEventRegistration::firstOrFail();
        // A persisted registration whose queue was unavailable remains deliverable.
        $r->update(['welcome_log_id' => null, 'notified_user_ids' => [], 'outreach_completed_at' => null]);
        Mail::fake();
        Notification::fake();
        $this->artisan('education:dispatch-registration-outreach')->assertSuccessful();
        Mail::assertQueuedCount(1);
        Notification::assertSentToTimes($owner, EducationRegistrationReceived::class, 1);
        $this->artisan('education:dispatch-registration-outreach')->assertSuccessful();
        Mail::assertQueuedCount(1);
        Notification::assertSentToTimes($owner, EducationRegistrationReceived::class, 1);
    }

    public function test_repeated_distinct_registrations_are_limited_per_email(): void
    {
        Mail::fake();
        Notification::fake();
        $this->owner();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/education/register', $this->payload())->assertCreated();
        }
        $this->postJson('/education/register', $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('education_event_registrations', 3);
    }
}
