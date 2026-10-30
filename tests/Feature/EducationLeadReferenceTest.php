<?php

namespace Tests\Feature;

use App\Models\EducationEventRegistration;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\Lead;
use App\Models\LeadTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationLeadReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'submission_id' => (string) Str::uuid(), 'goal' => 'Find the right course',
            'event_name' => 'Davao Education event', 'event_date' => '2026-11-05',
            'event_hour' => 5, 'event_minute' => 0, 'event_period' => 'PM',
            'event_timezone' => 'Asia/Manila', 'country' => 'Philippines',
            'school' => 'Davao', 'event_location_only' => true,
            'planning_timeline' => 'Just exploring', 'education_level' => "Bachelor's Degree",
            'first_name' => 'Sample', 'last_name' => 'Applicant', 'email' => 'reference@example.test',
            'phone' => '+639170000000', 'consent' => true,
        ], $overrides);
    }

    private function registration(Lead $lead, array $overrides = []): EducationEventRegistration
    {
        return EducationEventRegistration::create(array_merge([
            'lead_id' => $lead->id, 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'event_name' => 'Davao Education event', 'school' => 'Davao', 'country' => 'Philippines',
            'event_date' => null, 'event_time' => null, 'event_timezone' => 'Asia/Manila',
            'goal' => 'Find the right course', 'planning_timeline' => 'Just exploring', 'education_level' => "Bachelor's Degree",
            'contact_details' => [], 'consented_at' => now(),
        ], $overrides));
    }

    private function rsvp(Lead $lead, array $overrides = []): EducationRsvp
    {
        $visit = EducationVisit::create(['slug' => 'visit-'.Str::random(10), 'school' => 'Clark', 'city' => 'Clark', 'kind' => 'school', 'activity' => 'EP Education event', 'status' => 'open', 'schedule_confirmed' => false, 'timezone' => 'Asia/Manila']);

        return EducationRsvp::create(array_merge([
            'lead_id' => $lead->id, 'education_visit_id' => $visit->id, 'submission_id' => (string) Str::uuid(),
            'public_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64),
            'full_name' => 'Sample Applicant', 'name_key' => 'sample-applicant', 'email' => $lead->email,
            'consented_at' => now(), 'verified_at' => now(), 'attendance_status' => 'attended',
        ], $overrides));
    }

    public function test_registration_adds_one_reference_and_replay_does_not_duplicate_it(): void
    {
        $payload = $this->payload();
        $this->postJson('/education/register', $payload)->assertCreated();
        $lead = Lead::where('email', 'reference@example.test')->firstOrFail();
        $this->assertSame(['reference-ph-events'], $lead->tags()->pluck('name')->all());
        $this->postJson('/education/register', $payload)->assertOk();
        $this->assertSame(1, $lead->tags()->count());
        $this->assertSame(1, $lead->educationEventRegistrations()->count());
    }

    public function test_returning_registration_preserves_other_tags_first_source_owner_and_stage(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $lead = Lead::create(['first_name' => 'Sample', 'last_name' => 'Applicant', 'email' => 'reference@example.test', 'source' => 'booking', 'utm_source' => 'original-campaign', 'assigned_to' => $owner->id, 'status' => 'Contact Attempted']);
        $existing = LeadTag::findOrCreateByName('Existing follow-up');
        $lead->tags()->attach($existing->id);
        $this->postJson('/education/register', $this->payload())->assertCreated();
        $this->postJson('/education/register', $this->payload(['event_name' => 'Clark Education event']))->assertCreated();
        $lead->refresh();
        $this->assertEqualsCanonicalizing(['Existing follow-up', 'reference-ph-events'], $lead->tags()->pluck('name')->all());
        $this->assertSame('booking', $lead->source);
        $this->assertSame('original-campaign', $lead->utm_source);
        $this->assertEquals($owner->id, $lead->assigned_to);
        $this->assertSame('Contact Attempted', $lead->status);
        $this->assertSame(1, Lead::count());
    }

    public function test_similarly_named_tag_with_the_same_slug_is_preserved_and_not_substituted(): void
    {
        $lead = Lead::create(['first_name' => 'Sample', 'last_name' => 'Applicant', 'email' => 'reference@example.test']);
        $existing = LeadTag::findOrCreateByName('reference ph events');
        $lead->tags()->attach($existing->id);
        $this->postJson('/education/register', $this->payload())->assertCreated();
        $this->assertEqualsCanonicalizing(['reference ph events', 'reference-ph-events'], $lead->tags()->pluck('name')->all());
        $this->assertSame('reference-ph-events', $existing->fresh()->slug);
        $this->assertDatabaseCount('lead_tags', 2);
    }

    public function test_backfill_is_repeatable_and_targets_only_saved_registrations(): void
    {
        $lead = Lead::create(['first_name' => 'Prior', 'last_name' => 'Registrant', 'email' => 'prior@example.test', 'source' => 'booking', 'status' => 'Qualified']);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Contact', 'email' => 'other@example.test']);
        $tag = LeadTag::findOrCreateByName('Existing follow-up');
        $lead->tags()->attach($tag->id);
        $this->rsvp($other, ['lead_id' => null]);
        $this->registration($lead);
        $this->registration($lead);
        $this->artisan('education:tag-registrations')->assertSuccessful();
        $this->artisan('education:tag-registrations')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['Existing follow-up', 'reference-ph-events'], $lead->tags()->pluck('name')->all());
        $this->assertSame(0, $other->tags()->count());
        $this->assertSame(2, Lead::count());
        $this->assertSame('booking', $lead->fresh()->source);
        $this->assertSame('Qualified', $lead->fresh()->status);
        $this->assertDatabaseCount('education_outreach', 0);
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_staff_activity_shows_named_education_sources_only_for_this_contact(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $lead = Lead::create(['first_name' => 'Sample', 'last_name' => 'Applicant', 'email' => 'reference@example.test', 'source' => 'booking', 'created_at' => '2026-10-20 08:00:00']);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Contact', 'email' => 'other@example.test']);
        $registration = $this->registration($lead, ['created_at' => '2026-10-29 11:15:00']);
        $rsvp = $this->rsvp($lead, ['created_at' => '2026-10-28 10:00:00', 'attendance_status' => 'cancelled']);
        $unverified = $this->rsvp($lead, ['verified_at' => null]);
        $hiddenRegistration = $this->registration($other, ['event_name' => 'Private other event']);
        $hiddenRsvp = $this->rsvp($other);

        $this->actingAs($staff)->get('/portal/education/leads/'.$lead->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('activity', function ($items) use ($registration, $rsvp, $unverified, $hiddenRegistration, $hiddenRsvp) {
                $events = collect($items)->where('kind', 'source.event')->values();
                $this->assertCount(2, $events);
                $this->assertSame('Education registration — Davao Education event', $events[0]['title']);
                $this->assertSame('EDU-REG-'.$registration->id, $events[0]['reference']);
                $this->assertStringContainsString('Davao', $events[0]['detail']);
                $this->assertStringContainsString('reference-ph-events', $events[0]['detail']);
                $this->assertStringContainsString('Date to be confirmed', $events[0]['detail']);
                $this->assertSame('Education RSVP — Clark', $events[1]['title']);
                $this->assertSame('RSVP-'.$rsvp->id, $events[1]['reference']);
                $this->assertStringContainsString('Current attendance: Cancelled', $events[1]['detail']);
                $this->assertStringContainsString('RSVP received', $events[1]['detail']);
                $this->assertStringNotContainsString('attended', strtolower($events[1]['detail']));
                $this->assertNotContains('RSVP-'.$unverified->id, $events->pluck('reference')->all());
                $this->assertNotContains('RSVP-'.$hiddenRsvp->id, $events->pluck('reference')->all());
                $this->assertNotContains('EDU-REG-'.$hiddenRegistration->id, $events->pluck('reference')->all());

                return true;
            }));
    }
}
