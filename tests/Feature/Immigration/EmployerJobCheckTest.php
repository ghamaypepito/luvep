<?php

namespace Tests\Feature\Immigration;

use App\Models\ImmigrationEmployer;
use App\Models\ImmigrationJobCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployerJobCheckTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/portal/immigration/employer-job-checks';

    public function test_manager_creates_an_employer_and_tracks_a_job_check_with_an_assignee(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_IMMIGRATION_MANAGER]);
        $staff = User::factory()->create(['role' => 'immigration', 'is_active' => true]);
        $employerData = ['legal_name' => 'Example Employer Ltd', 'employer_identifier' => 'TEST-01'];

        $this->actingAs($manager)->from(self::PATH)->post(self::PATH.'/employers', $employerData)
            ->assertRedirect(self::PATH)->assertSessionHasNoErrors();
        $employer = ImmigrationEmployer::sole();
        $this->post(self::PATH.'/employers', $employerData)
            ->assertSessionHasErrors('employer_identifier');
        $this->patch(self::PATH.'/employers/'.$employer->id, [
            ...$employerData, 'accreditation_expires_on' => '2028-06-01',
        ])->assertSessionHasNoErrors();

        $this->post(self::PATH, [
            'employer_id' => $employer->id, 'role_title' => 'Cook', 'assigned_to' => $staff->id,
        ])->assertSessionHasNoErrors();
        $case = ImmigrationJobCheck::sole();
        $this->assertEquals($staff->id, $case->assigned_to);
        $this->assertSame('intake', $case->stage);

        $this->actingAs($staff)->patch(self::PATH.'/'.$case->id, [
            'role_title' => 'Cook',
            'stage' => 'advertising',
            'details' => [
                'guaranteed_hours' => 30,
                'maximum_hours' => 40,
                'wage_min' => 30,
                'wage_max' => 35,
                'ad_url' => 'https://example.test/listing',
                'ad_starts_on' => '2027-01-01',
                'ad_closes_on' => '2027-01-21',
                'inz1279_status' => 'received',
            ],
        ])->assertSessionHasNoErrors();

        $this->withoutVite()->get(self::PATH.'/'.$case->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/immigration/EmployerJobChecks')
            ->where('jobCheck.stage', 'advertising')
            ->where('jobCheck.employer.accreditation_expires_on', '2028-06-01')
            ->where('jobCheck.details.ad_closes_on', '2027-01-21')
            ->where('jobCheck.details.inz1279_status', 'received')
            ->where('canManage', false));
    }

    public function test_staff_cannot_read_or_change_cases_assigned_to_another_staff_member(): void
    {
        $staff = User::factory()->create(['role' => 'immigration']);
        $other = User::factory()->create(['role' => 'immigration']);
        $assigned = $this->createCase($staff, 'Visible Employer', 'Cook');
        $hidden = $this->createCase($other, 'Restricted Employer', 'Engineer');

        $this->withoutVite()->actingAs($staff)->get(self::PATH)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cases.data', 1)->where('cases.data.0.id', $assigned->id)
            ->has('employers', 1)->where('employers.0.id', $assigned->employer_id));
        $this->get(self::PATH.'?search=Restricted')->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));
        $this->get(self::PATH.'/'.$hidden->id)->assertNotFound();
        $this->patch(self::PATH.'/'.$hidden->id, [])->assertNotFound();
        $this->post(self::PATH, ['employer_id' => $hidden->employer_id, 'role_title' => 'Cook'])->assertNotFound();
        $this->patch(self::PATH.'/employers/'.$assigned->employer_id, [])->assertForbidden();
        $this->patch(self::PATH.'/'.$assigned->id, [
            'role_title' => 'Cook', 'stage' => 'intake', 'assigned_to' => $other->id, 'details' => [],
        ])->assertSessionHasErrors('assigned_to');
        $this->assertEquals($staff->id, $assigned->fresh()->assigned_to);
    }

    public function test_external_users_and_other_departments_cannot_enter_the_module(): void
    {
        $this->get(self::PATH)->assertRedirect();
        foreach (['lead', 'education', 'agent'] as $role) {
            $user = User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]);
            $this->actingAs($user)->get(self::PATH)->assertForbidden();
            $this->post(self::PATH.'/employers', [])->assertForbidden();
        }
    }

    public function test_case_rejects_inconsistent_intake_and_invalid_ad_url(): void
    {
        $staff = User::factory()->create(['role' => 'immigration']);
        $case = $this->createCase($staff, 'Example Employer', 'Cook');
        $this->actingAs($staff)->patch(self::PATH.'/'.$case->id, [
            'role_title' => 'Cook',
            'stage' => 'advertising',
            'details' => [
                'guaranteed_hours' => 40,
                'maximum_hours' => 30,
                'wage_min' => 35,
                'wage_max' => 30,
                'ad_url' => 'javascript:alert(1)',
                'ad_starts_on' => '2027-01-21',
                'ad_closes_on' => '2027-01-01',
            ],
        ])->assertSessionHasErrors([
            'details.maximum_hours', 'details.wage_max', 'details.ad_url', 'details.ad_closes_on',
        ]);

        $this->assertSame('intake', $case->fresh()->stage);
    }

    private function createCase(User $user, string $employerName, string $title): ImmigrationJobCheck
    {
        $employer = ImmigrationEmployer::create([
            'legal_name' => $employerName, 'employer_identifier' => $employerName,
        ]);

        return ImmigrationJobCheck::create([
            'employer_id' => $employer->id, 'assigned_to' => $user->id,
            'role_title' => $title, 'stage' => 'intake', 'details' => [],
        ]);
    }
}
