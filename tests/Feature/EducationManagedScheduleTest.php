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
        $this->postJson('/portal/education/rsvp-visits', $this->schedule(['event_date' => '2026-11-13']))->assertCreated();
        $response = $this->get('/education/register')->assertOk()->assertViewHas('registrationVisits', fn ($rows) => $rows && $rows->contains(fn ($row) => $row['id'] === $v->id && $row['time'] === '15:30'));
        $dom = new \DOMDocument;
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $options = (new \DOMXPath($dom))->query('//select[@id="ep-visit"]/option[@value="'.$v->id.'"]');
        $this->assertCount(1, $options);
        $this->assertStringContainsString('New College', $options->item(0)->textContent);
        $choices = (new \DOMXPath($dom))->query('//select[@id="ep-visit"]/option[contains(text(),"New College")]');
        $this->assertCount(2, $choices);
        $this->assertStringContainsString('12 November 2026', $choices->item(0)->textContent);
        $this->assertStringContainsString('13 November 2026', $choices->item(1)->textContent);
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

    public function test_staff_can_hide_and_restore_individual_visits_without_changing_schedule_or_history(): void
    {
        $v = $this->visit();
        $other = $this->visit(['slug' => 'second-school-visit', 'activity' => 'Another visible event']);
        $staff = $this->staff();
        $r = \App\Models\EducationRsvp::create(['education_visit_id' => $v->id, 'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'full_name' => 'Existing Attendee', 'name_key' => 'existing-attendee', 'email' => 'existing@example.test', 'consented_at' => now(), 'attendance_status' => 'attended']);
        $lead = \App\Models\Lead::create(['lead_id' => 'HIDDEN-PRESERVE', 'first_name' => 'Existing', 'last_name' => 'Attendee', 'email' => $r->email, 'status' => 'Contact Attempted']);
        $r->update(['lead_id' => $lead->id, 'linked_at' => now(), 'verified_at' => now()]);
        $historyBefore = app(\App\Services\EducationRsvpWorkflow::class)->history($lead);
        $this->assertCount(1, $historyBefore);
        $version = $v->fresh()->schedule_version;
        $this->actingAs($staff)->putJson('/portal/education/rsvp-visits/'.$v->id, $this->schedule(['is_hidden' => true]))->assertOk();
        $this->assertTrue($v->fresh()->is_hidden);
        $this->assertSame($version, $v->fresh()->schedule_version);
        $this->getJson('/education/rsvp/catalogue')->assertJsonCount(1, 'visits')->assertJsonPath('visits.0.id', $other->id);
        $this->get('/education/rsvp')->assertViewHas('visits', fn ($visits) => $visits->count() === 1 && $visits->first()['id'] === $other->id);
        $this->get('/education/register')->assertViewHas('registrationVisits', fn ($visits) => $visits->count() === 1 && $visits->first()['id'] === $other->id);
        $this->get('/portal/education/rsvps')->assertOk()->assertSee('Existing Attendee')->assertSee('Hidden');
        $this->assertDatabaseHas('education_rsvps', ['id' => $r->id, 'attendance_status' => 'attended']);
        $this->assertDatabaseCount('education_outreach', 0);
        $this->assertEquals($lead->id, $r->fresh()->lead_id);
        $this->assertSame('Contact Attempted', $lead->fresh()->status);
        $this->assertSame($historyBefore, app(\App\Services\EducationRsvpWorkflow::class)->history($lead));
        $this->putJson('/portal/education/rsvp-visits/'.$v->id, $this->schedule(['is_hidden' => false]))->assertOk();
        $this->getJson('/education/rsvp/catalogue')->assertJsonCount(2, 'visits');
    }

    public function test_hidden_visits_reject_stale_form_intakes_before_creating_contacts(): void
    {
        $v = $this->visit(['is_hidden' => true]);
        $this->postJson('/education/register', $this->contact(['visit_id' => $v->id]))->assertUnprocessable()->assertJsonValidationErrors('visit_id');
        $this->postJson('/education/rsvp', ['submission_id' => (string) Str::uuid(), 'visit_id' => $v->id, 'full_name' => 'Hidden Attendee', 'email' => 'hidden@example.test', 'consent' => true])->assertUnprocessable();
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('education_rsvps', 0);
        $this->assertDatabaseCount('education_outreach', 0);
    }

    public function test_hidden_schedule_creation_requires_staff_and_updates_do_not_unhide_implicitly(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']))->postJson('/portal/education/rsvp-visits', $this->schedule(['is_hidden' => true]))->assertForbidden();
        $this->actingAs($this->staff())->postJson('/portal/education/rsvp-visits', $this->schedule(['is_hidden' => true]))->assertCreated();
        $v = EducationVisit::firstOrFail();
        $this->assertTrue($v->is_hidden);
        $this->putJson('/portal/education/rsvp-visits/'.$v->id, $this->schedule(['is_hidden' => 'invalid']))->assertUnprocessable();
        $this->putJson('/portal/education/rsvp-visits/'.$v->id, $this->schedule(['activity' => 'Updated hidden event']))->assertOk();
        $this->assertTrue($v->fresh()->is_hidden);
        $this->getJson('/education/rsvp/catalogue')->assertJsonCount(0, 'visits');
    }

    public function test_completed_submissions_can_replay_after_a_visit_is_hidden(): void
    {
        Mail::fake();
        Notification::fake();
        $this->staff();
        $v = $this->visit();
        $registration = $this->contact(['visit_id' => $v->id]);
        $this->postJson('/education/register', $registration)->assertCreated();
        $rsvp = ['submission_id' => (string) Str::uuid(), 'visit_id' => $v->id, 'full_name' => 'Original Attendee', 'email' => 'original@example.test', 'consent' => true];
        $this->postJson('/education/rsvp', $rsvp)->assertCreated();
        $outreach = \App\Models\EducationOutreach::count();
        $v->update(['is_hidden' => true]);
        $this->postJson('/education/register', $registration)->assertOk();
        $this->postJson('/education/rsvp', $rsvp)->assertOk();
        $this->assertDatabaseCount('education_event_registrations', 1);
        $this->assertDatabaseCount('education_rsvps', 1);
        $this->assertDatabaseCount('education_outreach', $outreach);
    }

    public function test_managed_schedules_share_the_extended_timezone_choices(): void
    {
        Mail::fake();
        Notification::fake();
        $this->actingAs($this->staff())->postJson('/portal/education/rsvp-visits', $this->schedule(['timezone' => 'Australia/Sydney']))->assertCreated();
        $visit = EducationVisit::firstOrFail();
        $this->get('/education/register')->assertViewHas('registrationVisits', fn ($visits) => $visits->contains(fn ($row) => $row['timezone'] === 'Australia/Sydney'));
        $this->postJson('/education/register', $this->contact(['visit_id' => $visit->id, 'event_timezone' => 'Europe/London']))->assertCreated();
        $this->assertDatabaseHas('education_event_registrations', ['education_visit_id' => $visit->id, 'event_timezone' => 'Australia/Sydney', 'event_time' => '15:30']);
    }
}
