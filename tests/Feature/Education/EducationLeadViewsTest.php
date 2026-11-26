<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationLeadViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    public static function educationStages(): array
    {
        return [[null, 'Declined Visa'], ['Started Course', 'Started Course'], ['On Hold', 'On Hold']];
    }

    #[DataProvider('educationStages')]
    public function test_existing_immigration_decline_is_a_read_only_fallback_for_a_missing_education_stage(?string $educationStage, string $display): void
    {
        $student = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student',
            'status' => 'New', 'is_student' => true, 'is_immigration_case' => false,
            'education_stage' => $educationStage, 'immigration_stage' => 'Decline Visa']);
        $before = $student->fresh()->getRawOriginal();

        $response = $this->get('/portal/education/students')->assertOk();

        $row = collect($response->inertiaProps()['students'])->firstWhere('id', $student->id);
        $this->assertSame($display, $row['status']);
        $this->assertSame($educationStage === null ? 'Declined Visa' : null, $row['education_stage_hint']);
        $this->assertSame($educationStage, $row['education_stage']);
        $this->assertSame('Decline Visa', $row['immigration_stage']);
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_other_immigration_stages_do_not_become_an_education_outcome(): void
    {
        $student = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Unchanged',
            'status' => 'New', 'is_student' => true, 'immigration_stage' => 'Visa Lodged']);

        $response = $this->get('/portal/education/students')->assertOk();

        $this->assertSame('New', collect($response->inertiaProps()['students'])->firstWhere('id', $student->id)['status']);
        $this->assertNull($student->fresh()->education_stage);
        $this->assertNull(collect($response->inertiaProps()['students'])->firstWhere('id', $student->id)['education_stage_hint']);
    }

    public function test_affiliate_detail_displays_saved_department_stages_and_only_shows_both_for_student_visas(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $visa = VisaType::create(['code' => 'AFF-STUDENT', 'name' => 'Student Visa (Fee Paying)', 'category' => 'Student']);
        $student = Lead::create([
            'first_name' => 'Student', 'last_name' => 'Referral', 'agent_id' => $agent->id, 'is_student' => true,
            'status' => 'New', 'inz_visa_type' => $visa->name,
            'education_stage' => 'School Enrolment', 'immigration_stage' => 'Visa Lodged',
        ]);
        $otherVisa = Lead::create([
            'first_name' => 'Other', 'last_name' => 'Visa', 'agent_id' => $agent->id, 'status' => 'New',
            'inz_visa_type' => 'Work Visa', 'education_stage' => 'On Hold', 'immigration_stage' => 'Approved Visa',
        ]);
        $educationOnly = Lead::create([
            'first_name' => 'Education', 'last_name' => 'Referral', 'agent_id' => $agent->id, 'education_stage' => 'Pre-Screening Done',
        ]);
        $url = '/portal/education/agents/'.$agent->id.'/leads';
        $rows = collect($this->get($url)->assertOk()->inertiaProps()['leads'])->keyBy('id');
        $this->assertSame(['Education' => 'School Enrolment', 'Immigration' => 'Visa Lodged'], (array) $rows[$student->id]['department_stages']);
        $this->assertSame(['Immigration' => 'Approved Visa'], (array) $rows[$otherVisa->id]['department_stages']);
        $this->assertSame(['Education' => 'Pre-Screening Done'], (array) $rows[$educationOnly->id]['department_stages']);
        $this->assertSame('New', $student->fresh()->status);

        $student->updateQuietly(['immigration_stage' => 'Approved Visa']);
        $rows = collect($this->get($url)->assertOk()->inertiaProps()['leads'])->keyBy('id');
        $this->assertSame(['Education' => 'School Enrolment', 'Immigration' => 'Approved Visa'], (array) $rows[$student->id]['department_stages']);
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_affiliate_detail_supplies_authoritative_staff_updates_and_uses_the_education_profile(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Referral', 'agent_id' => $agent->id,
            'last_activity_at' => '2026-11-20 12:00:00', 'last_activity_by' => auth()->id(), 'last_activity_desc' => 'Updated notes']);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Affiliate']);

        $response = $this->get('/portal/education/agents/'.$agent->id.'/leads')->assertOk();

        $props = $response->inertiaProps();
        $this->assertSame('/portal/education', $props['portalBase']);
        $this->assertSame([$lead->id], collect($props['leads'])->pluck('id')->all());
        $row = collect($props['leads'])->first();
        $this->assertStringContainsString('2026-11-20', (string) $row['updated_at']);
        $this->assertSame('Updated notes', $row['updated_desc']);
        $this->get('/portal/education/leads/'.$lead->id)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]));
        $this->get('/portal/education/agents/'.$agent->id.'/leads')->assertForbidden();
        $this->get('/portal/education/leads/'.$lead->id)->assertForbidden();
    }
}
