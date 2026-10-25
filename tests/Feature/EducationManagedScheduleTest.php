<?php

namespace Tests\Feature;

use App\Models\EducationEventRegistration;
use App\Models\EducationVisit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationManagedScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(array $extra = []): array
    {
        return array_merge(['school' => 'New College', 'activity' => 'New Zealand careers', 'city' => 'Davao', 'mode' => 'in_person', 'event_date' => '2026-11-12', 'event_time' => '15:30', 'timezone' => 'Asia/Manila', 'location' => 'Main hall', 'schedule_confirmed' => true, 'status' => 'open'], $extra);
    }

    private function visit(array $extra = []): EducationVisit
    {
        return EducationVisit::create(array_merge($this->schedule(), ['slug' => 'managed-event', 'kind' => 'school'], $extra));
    }

    private function contact(array $extra = []): array
    {
        return array_merge(['submission_id' => (string) Str::uuid(), 'first_name' => 'Managed', 'last_name' => 'Applicant', 'email' => 'managed@example.test', 'phone' => '+639170000000', 'goal' => 'Find the right course', 'country' => 'Philippines', 'planning_timeline' => 'Just exploring', 'education_level' => 'High School Graduate', 'consent' => true], $extra);
    }

    private function staff(): User
    {
        $u = User::factory()->create(['role' => 'education']);
        Setting::set('education_landing_owner_id', $u->id, 'int');
        Setting::set('education_landing_notify_ids', [$u->id], 'json');

        return $u;
    }

    public function test_staff_adds_a_schedule_that_appears_in_rsvp_and_registration_dropdowns(): void
    {
        $this->actingAs($this->staff())->postJson('/portal/education/rsvp-visits', $this->schedule())->assertCreated();
        $v = EducationVisit::firstOrFail();
        $this->getJson('/education/rsvp/catalogue')->assertJsonPath('visits.0.date', '2026-11-12');
        $response = $this->get('/education/register')->assertOk()->assertViewHas('registrationVisits', fn ($rows) => $rows && $rows->contains(fn ($row) => $row['id'] === $v->id && $row['time'] === '15:30'));
        $dom = new \DOMDocument;
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $options = (new \DOMXPath($dom))->query('//select[@id="ep-school"]/option[@value="New College"]');
        $this->assertCount(1, $options);
    }

    public function test_another_schedule_reuses_school_logo_without_overwriting_the_previous_event(): void
    {
        $old = $this->visit(['logo_path' => '/education-rsvp/assets/brokenshire-logo.png']);
        $this->actingAs($this->staff())->postJson('/portal/education/rsvp-visits', $this->schedule(['event_date' => null, 'event_time' => null, 'schedule_confirmed' => false]))->assertCreated();
        $this->assertDatabaseCount('education_visits', 2);
        $new = EducationVisit::latest('id')->first();
        $this->assertSame($old->logo_path, $new->logo_path);
        $this->assertNotSame($old->slug, $new->slug);
        $this->assertSame('2026-11-12', $old->fresh()->event_date->format('Y-m-d'));
    }

    public function test_managed_registration_uses_authoritative_schedule_and_retries_after_event_changes(): void
    {
        Mail::fake();
        Notification::fake();
        $owner = $this->staff();
        $v = $this->visit();
        $p = $this->contact(['visit_id' => $v->id, 'school' => 'Tampered School', 'event_name' => 'Fake event', 'event_date' => '2001-01-01', 'event_hour' => 1, 'event_minute' => 0, 'event_period' => 'AM', 'event_timezone' => 'Pacific/Auckland']);
        $this->postJson('/education/register', $p)->assertCreated();
        $r = EducationEventRegistration::firstOrFail();
        $this->assertEquals($v->id, $r->education_visit_id);
        $this->assertSame('New College', $r->school);
        $this->assertSame('New Zealand careers', $r->event_name);
        $this->assertSame('2026-11-12', $r->event_date->format('Y-m-d'));
        $this->assertSame('15:30', $r->event_time);
        $this->assertSame('matched', $r->event_match_status);
        $this->assertEquals($owner->id, $r->lead->assigned_to);
        $v->update(['event_date' => '2026-11-20', 'status' => 'cancelled']);
        $this->postJson('/education/register', $p)->assertOk();
        $this->assertDatabaseCount('education_event_registrations', 1);
        $this->assertSame('2026-11-12', $r->fresh()->event_date->format('Y-m-d'));
        $this->postJson('/education/register', array_merge($p, ['first_name' => 'Changed']))->assertConflict();
    }

    public function test_unconfirmed_managed_schedule_can_register_without_inventing_a_date(): void
    {
        Mail::fake();
        Notification::fake();
        $this->staff();
        $v = $this->visit(['schedule_confirmed' => false]);
        $this->postJson('/education/register', $this->contact(['visit_id' => $v->id]))->assertCreated();
        $r = EducationEventRegistration::firstOrFail();
        $this->assertNull($r->event_date);
        $this->assertNull($r->event_time);
        $this->assertStringContainsString('to be confirmed', $r->eventSummary());
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_cancelled_or_missing_visits_are_rejected_before_pipeline_creation(): void
    {
        $v = $this->visit(['status' => 'cancelled']);
        foreach ([$v->id, 9999, 0] as $id) {
            $this->postJson('/education/register', $this->contact(['visit_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('visit_id');
        }
        $this->assertDatabaseCount('leads', 0);
        $this->actingAs(User::factory()->create(['role' => 'sales']))->postJson('/portal/education/rsvp-visits', $this->schedule())->assertForbidden();
    }

    public function test_closed_intake_stays_in_registration_and_schedule_edits_reflect_there(): void
    {
        $v = $this->visit(['status' => 'closed']);
        $this->getJson('/education/rsvp/catalogue')->assertJsonCount(0, 'visits');
        $this->actingAs($this->staff())->putJson('/portal/education/rsvp-visits/'.$v->id, $this->schedule(['event_date' => '2026-11-20', 'status' => 'closed']))->assertOk();
        $this->get('/education/register')->assertViewHas('registrationVisits', fn ($rows) => $rows && $rows->contains(fn ($row) => $row['date'] === '2026-11-20'));
    }
}
