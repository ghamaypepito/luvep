<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntakeMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_month_payload_keeps_one_card_per_student_and_does_not_change_records(): void
    {
        $stages = ['Started Course', 'Approved Visa', 'Visa Lodged', 'Endorsed to Immigration', 'Unconditional Offer', 'Conditional Offer', 'Declined Visa', 'On Hold'];
        $records = [];
        foreach ($stages as $index => $stage) {
            $lead = Lead::create(['first_name' => 'Synthetic '.$index, 'last_name' => 'Student', 'is_student' => true,
                'education_stage' => $stage, 'education_notes' => ['legacy' => 'keep'], 'immigration_stage' => $index === 1 ? 'Approved Visa' : null]);
            $lead->studyPlans()->create(['qualification_level' => '', 'preferred_intake' => '2026-11-23', 'preferred_course' => 'Original course']);
            $records[$lead->id] = $lead->refresh()->getAttributes();
        }
        $first = Lead::findOrFail(array_key_first($records));
        $first->studyPlans()->create(['qualification_level' => '', 'preferred_intake' => '2027-02-01', 'preferred_course' => 'Second plan']);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/students/intake-monitoring')->assertOk()->assertInertia(fn ($page) => $page
            ->component('portal/education/IntakeMonitoring')->has('students', 8)
            ->where('students', function ($rows) use ($records, $stages) {
                $rows = collect($rows);
                $this->assertSame(8, $rows->pluck('id')->unique()->count());
                $this->assertEqualsCanonicalizing($stages, $rows->pluck('status')->all());
                $this->assertSame(['2026-11'], $rows->pluck('month_key')->unique()->values()->all());
                foreach ($records as $id => $before) {
                    $this->assertSame($before, Lead::findOrFail($id)->getAttributes());
                }

                return true;
            }));
        $this->assertDatabaseCount('lead_study_plans', 9);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_unscheduled_and_other_months_stay_separate_and_pure_cases_are_excluded(): void
    {
        $undated = Lead::create(['first_name' => 'Undated', 'last_name' => 'Student', 'is_student' => true, 'education_stage' => 'Started Course']);
        $dated = Lead::create(['first_name' => 'Dated', 'last_name' => 'Student', 'is_student' => true, 'education_stage' => 'Unconditional Offer']);
        $dated->studyPlans()->create(['qualification_level' => '', 'preferred_intake' => '2026-12-01']);
        Lead::create(['first_name' => 'Pure case', 'last_name' => 'Synthetic', 'is_student' => false, 'is_immigration_case' => true]);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/students/intake-monitoring')->assertOk()->assertInertia(fn ($page) => $page
            ->has('students', 2)
            ->where('students.0.id', $dated->id)->where('students.0.month_key', '2026-12')
            ->where('students.1.id', $undated->id)->where('students.1.month_key', '9999-99'));
    }

    public function test_existing_portal_access_boundaries_remain_enforced(): void
    {
        foreach (['lead', 'agent', 'finance', 'immigration'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/portal/education/students/intake-monitoring')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/portal/education/students/intake-monitoring')->assertOk();
    }
}
