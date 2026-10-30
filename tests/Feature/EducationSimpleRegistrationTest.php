<?php

namespace Tests\Feature;

use App\Models\EducationEventRegistration;
use App\Models\EducationVisit;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationSimpleRegistrationTest extends TestCase
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

    private function payload(array $values = []): array
    {
        return array_merge(['form_version' => 'simple', 'submission_id' => (string) Str::uuid(), 'full_name' => 'Maria Del Carmen Santos', 'email' => 'simple@example.test', 'phone' => '+639170000000', 'whatsapp_same_as_phone' => true, 'country' => 'Philippines', 'event_name' => 'Community Education Evening', 'consent' => true], $values);
    }

    private function visit(array $values = []): EducationVisit
    {
        return EducationVisit::create(array_merge(['slug' => 'simple-clark', 'school' => 'Clark', 'activity' => 'EP Education event', 'kind' => 'school', 'city' => 'Clark', 'status' => 'open', 'timezone' => 'Asia/Manila', 'schedule_confirmed' => false, 'time_confirmed' => true, 'event_time' => '19:00'], $values));
    }

    public function test_simple_manual_registration_stores_only_collected_answers_and_shared_whatsapp(): void
    {
        $p = $this->payload();
        $this->postJson('/education/register', $p)->assertCreated();
        $lead = Lead::where('email', 'simple@example.test')->firstOrFail();
        $r = EducationEventRegistration::firstOrFail();
        $this->assertSame('Maria', $lead->first_name);
        $this->assertSame('Del Carmen Santos', $lead->last_name);
        $this->assertSame('+639170000000', $lead->whatsapp);
        $this->assertSame('Maria Del Carmen Santos', $r->contact_details['full_name']);
        $this->assertSame('+639170000000', $r->contact_details['whatsapp']);
        $this->assertTrue($r->contact_details['whatsapp_same_as_phone']);
        $this->assertNull($r->school);
        $this->assertNull($r->event_date);
        $this->assertNull($r->goal);
        $this->assertNull($r->planning_timeline);
        $this->assertNull($r->education_level);
        $this->assertSame('19:00', $r->event_time);
        $this->assertSame('Asia/Manila', $r->event_timezone);
        $staffEmail = view('emails.education-registration-staff', ['registration' => $r])->render();
        $this->assertStringContainsString('WhatsApp:', $staffEmail);
        $this->assertStringContainsString('+639170000000', $staffEmail);
        $this->assertStringNotContainsString('Study timeline:', $staffEmail);
        $this->assertSame(0, $lead->educationExps()->count());
        $this->assertSame(0, $lead->studyPlans()->count());
        $this->assertSame(1, $lead->tags()->where('name', 'reference-ph-events')->count());
        $this->postJson('/education/register', $p)->assertOk();
        $this->assertDatabaseCount('education_event_registrations', 1);
    }

    public function test_separate_whatsapp_is_required_and_existing_contact_details_remain(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $lead = Lead::create(['first_name' => 'Original', 'last_name' => 'Name', 'email' => 'simple@example.test', 'phone' => '+6491111111', 'whatsapp' => '+6492222222', 'source' => 'booking', 'assigned_to' => $owner->id, 'status' => 'Contact Attempted']);
        $this->postJson('/education/register', $this->payload(['whatsapp_same_as_phone' => false]))->assertUnprocessable()->assertJsonValidationErrors('whatsapp');
        $this->postJson('/education/register', $this->payload(['whatsapp_same_as_phone' => false, 'whatsapp' => '+639188888888']))->assertCreated();
        $lead->refresh();
        $this->assertSame('Original', $lead->first_name);
        $this->assertSame('+6491111111', $lead->phone);
        $this->assertSame('+6492222222', $lead->whatsapp);
        $this->assertSame('booking', $lead->source);
        $this->assertSame('Contact Attempted', $lead->status);
        $this->assertEquals($owner->id, $lead->assigned_to);
        $r = EducationEventRegistration::firstOrFail();
        $this->assertSame('+639188888888', $r->contact_details['whatsapp']);
        $this->assertStringContainsString('+639188888888', $lead->notes()->latest('id')->value('body'));
    }

    public function test_mononym_is_valid_but_blank_names_and_bad_contact_flags_are_rejected(): void
    {
        $this->postJson('/education/register', $this->payload(['full_name' => '   ', 'phone' => '       ', 'whatsapp_same_as_phone' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'phone', 'whatsapp_same_as_phone']);
        $this->postJson('/education/register', $this->payload(['full_name' => '  Tala  ']))->assertCreated();
        $lead = Lead::where('email', 'simple@example.test')->firstOrFail();
        $this->assertSame('Tala', $lead->first_name);
        $this->assertSame('', $lead->last_name);
    }

    public function test_managed_event_publishes_known_time_and_uses_later_rsvp_edits(): void
    {
        $v = $this->visit();
        $p = $this->payload(['visit_id' => $v->id]);
        unset($p['event_name']);
        $data = $v->publicData();
        $this->assertNull($data['date']);
        $this->assertSame('19:00', $data['time']);
        $this->assertNull($v->startsAt());
        $this->postJson('/education/register', $p)->assertCreated();
        $r = EducationEventRegistration::firstOrFail();
        $this->assertNull($r->event_date);
        $this->assertSame('19:00', $r->event_time);
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->putJson('/portal/education/rsvp-visits/'.$v->id, ['timezone' => 'Asia/Manila', 'schedule_confirmed' => true, 'time_confirmed' => true, 'event_date' => '2026-11-15', 'event_time' => '20:00', 'location' => 'Confirmed venue', 'status' => 'open'])->assertOk();
        $p['submission_id'] = (string) Str::uuid();
        $this->postJson('/education/register', $p)->assertCreated();
        $latest = EducationEventRegistration::latest('id')->first();
        $this->assertSame('2026-11-15', $latest->event_date->format('Y-m-d'));
        $this->assertSame('20:00', $latest->event_time);
        $this->assertSame('19:00', $r->fresh()->event_time);
    }

    public function test_unpublished_provisional_time_stays_private_and_staff_must_supply_published_time(): void
    {
        $v = $this->visit(['time_confirmed' => false, 'event_time' => '13:15']);
        $this->assertNull($v->publicData()['time']);
        $this->assertStringNotContainsString('1:15', $v->summary());
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->putJson('/portal/education/rsvp-visits/'.$v->id, ['timezone' => 'Asia/Manila', 'schedule_confirmed' => false, 'time_confirmed' => true, 'event_time' => null, 'status' => 'open'])->assertUnprocessable()->assertJsonValidationErrors('event_time');
    }

    public function test_partial_schedule_update_cannot_clear_a_published_time_without_unpublishing_it(): void
    {
        $visit = $this->visit();
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->putJson('/portal/education/rsvp-visits/'.$visit->id, ['timezone' => 'Asia/Manila', 'schedule_confirmed' => false, 'event_time' => null, 'status' => 'open'])
            ->assertUnprocessable()->assertJsonValidationErrors('event_time');
        $this->assertSame('19:00', $visit->fresh()->event_time);
        $this->putJson('/portal/education/rsvp-visits/'.$visit->id, ['timezone' => 'Asia/Manila', 'schedule_confirmed' => false, 'status' => 'closed'])->assertOk();
        $this->assertTrue($visit->fresh()->time_confirmed);
    }
}
