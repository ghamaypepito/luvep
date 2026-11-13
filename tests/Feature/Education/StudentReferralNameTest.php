<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentReferralNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['first_name' => 'Synthetic', 'last_name' => 'Student',
            'email' => 'referee@example.test', 'phone' => '+64000000000', 'program_text' => ''], $extra);
    }

    public function test_sit_is_a_saved_referral_name_without_creating_an_agent_account(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $count = User::count();
        $this->actingAs($staff)->post('/portal/education/students', $this->payload(['referral' => 'SIT']))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $lead = Lead::where('email', 'referee@example.test')->firstOrFail();
        $this->assertSame('SIT', $lead->referral);
        $this->assertNull($lead->agent_id);
        $this->assertSame($count, User::count());
        $this->get('/portal/education/students')->assertInertia(fn (Assert $page) => $page
            ->where('students.0.referral', 'SIT')->where('students.0.agent_id', null));
    }

    public function test_custom_name_and_clear_preserve_the_linked_account_and_existing_department_data(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create($this->payload(['is_student' => true, 'agent_id' => $agent->id,
            'education_stage' => 'Proposal Sent', 'english_stage' => 'For PTE Exam',
            'immigration_stage' => 'For Assessment', 'is_immigration_case' => true,
            'education_notes' => ['uploaded_files' => ['synthetic.pdf']], 'referral' => 'Original referee']));
        $history = $lead->stage_history;
        $url = "/portal/education/students/{$lead->id}/update";
        foreach (['SIT', 'Synthetic Referee', ''] as $name) {
            $this->actingAs($staff)->post($url, $this->payload(['agent_id' => $agent->id, 'referral' => $name]))
                ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
            $lead->refresh();
            $this->assertSame($name ?: null, $lead->referral);
            $this->assertSame($agent->id, $lead->agent_id);
            $this->assertSame('Proposal Sent', $lead->education_stage);
            $this->assertSame('For PTE Exam', $lead->english_stage);
            $this->assertSame('For Assessment', $lead->immigration_stage);
            $this->assertSame($history, $lead->stage_history);
            $this->assertSame(['uploaded_files' => ['synthetic.pdf']], $lead->education_notes);
        }
        Mail::assertNothingSent(); Mail::assertNothingQueued(); Notification::assertNothingSent();
    }

    public function test_link_existing_person_keeps_account_ownership_and_saves_manual_referee(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create($this->payload(['agent_id' => $agent->id, 'is_immigration_case' => true,
            'immigration_stage' => 'For Assessment', 'referral' => 'SIT']));
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->getJson('/portal/education/students/search-existing?q=Synthetic')->assertOk()
            ->assertJsonPath('leads.0.referral', 'SIT')->assertJsonPath('leads.0.agent_id', $agent->id);
        $this->post('/portal/education/students/link-existing', $this->payload(['lead_id' => $lead->id,
            'referral' => 'Synthetic Referee', 'agent_id' => $agent->id]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('Synthetic Referee', $lead->refresh()->referral);
        $this->assertSame($agent->id, $lead->agent_id);
        $this->assertSame('For Assessment', $lead->immigration_stage);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_manual_name_cannot_bypass_account_validation_or_portal_write_permissions(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $lead = Lead::create($this->payload(['is_student' => true]));
        $url = "/portal/education/students/{$lead->id}/update";
        $this->actingAs($staff)->postJson($url, $this->payload(['referral' => str_repeat('x', 192)]))->assertUnprocessable();
        $this->postJson($url, $this->payload(['agent_id' => $staff->id, 'referral' => 'SIT']))->assertUnprocessable();
        $this->postJson($url, $this->payload(['agent_id' => 'SIT', 'referral' => 'SIT']))->assertUnprocessable();
        foreach (['agent', 'lead', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson($url, $this->payload(['referral' => 'SIT']))->assertForbidden();
        }
        $this->assertNull($lead->refresh()->referral);
        $this->assertNull($lead->agent_id);
    }

    public function test_same_manual_name_does_not_grant_another_agent_visibility(): void
    {
        $owner = User::factory()->create(['role' => 'agent', 'module_permissions' => ['referral_students']]);
        $other = User::factory()->create(['role' => 'agent', 'name' => 'SIT', 'module_permissions' => ['referral_students']]);
        $lead = Lead::create($this->payload(['is_student' => true, 'agent_id' => $owner->id, 'referral' => 'SIT']));
        $this->actingAs($owner)->get('/portal/agent/students')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('readOnly', true)->where('students.0.id', $lead->id));
        $this->actingAs($other)->get('/portal/agent/students')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('readOnly', true)->has('students', 0));
    }
}
