<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EducationAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-18 00:30:00', 'UTC'));
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
    }

    private function student(): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'age@example.test', 'dob' => '2018-03-08', 'is_student' => true]);
    }

    public function test_reference_day_is_only_added_to_education_profile_context_and_matches_saved_age(): void
    {
        $lead = $this->student();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        foreach (["/portal/education/leads/{$lead->id}", "/admin/leads/{$lead->id}"] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
                ->where('ageReferenceDate', '2026-11-18')->where('lead.age', 8));
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get("/admin/leads/{$lead->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->missing('ageReferenceDate')->where('lead.age', 8));
    }

    public function test_saving_dob_recalculates_age_without_writing_a_submitted_age_or_changing_other_fields(): void
    {
        $lead = $this->student();
        $lead->age = 40;
        $lead->save();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->post("/admin/leads/{$lead->id}/personal", ['first_name' => $lead->first_name, 'dob' => '2000-11-19', 'age' => 999])
            ->assertRedirect()->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame(25, $lead->age);
        $this->assertSame(40, (int) $lead->getRawOriginal('age'));
        $this->assertSame('2000-11-19', $lead->dob->toDateString());
        $this->assertSame('age@example.test', $lead->email);
        $this->assertTrue($lead->is_student);
        $this->get("/portal/education/leads/{$lead->id}")->assertInertia(fn ($page) => $page->where('lead.age', 25));
    }

    public function test_invalid_and_future_dates_are_rejected_and_legacy_no_dob_age_is_preserved(): void
    {
        $lead = $this->student();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        foreach (['2026-02-30', '2026-11-19'] as $dob) {
            $this->post("/admin/leads/{$lead->id}/personal", ['first_name' => $lead->first_name, 'dob' => $dob])
                ->assertSessionHasErrors('dob');
            $this->assertSame('2018-03-08', $lead->fresh()->dob->toDateString());
        }
        $lead->dob = null;
        $lead->age = 40;
        $lead->save();
        $this->get("/portal/education/leads/{$lead->id}")->assertInertia(fn ($page) => $page->where('lead.age', 40));
        $this->assertNull($lead->fresh()->dob);
    }

    public function test_server_age_calendar_boundaries_match_live_display_contract(): void
    {
        $lead = $this->student();
        foreach ([['2000-11-18', '2026-11-17', 25], ['2000-11-18', '2026-11-18', 26],
            ['2000-11-18', '2026-11-19', 26], ['2004-02-29', '2025-02-28', 20],
            ['2004-02-29', '2025-03-01', 21], ['2026-11-17', '2026-11-18', 0]] as [$dob, $day, $expected]) {
            $this->travelTo(Carbon::parse($day.' 12:00:00', 'UTC'));
            $lead->dob = $dob;
            $lead->save();
            $this->assertSame($expected, $lead->fresh()->age, $dob.' at '.$day);
        }
    }
}
