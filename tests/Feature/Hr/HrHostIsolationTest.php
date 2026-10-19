<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrHostIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    public function test_disabled_hr_returns_not_found_on_both_hosts(): void
    {
        config(['hr.enabled' => false]);
        $this->actingAs($this->employee())->get('https://hr.luvep.com/profile')->assertNotFound();
        $this->get('https://luvep.com/hr/profile')->assertNotFound();
    }

    public function test_hr_host_does_not_expose_main_application_routes(): void
    {
        config(['hr.enabled' => true]);
        $this->actingAs($this->employee())->get('https://hr.luvep.com/admin/users')->assertNotFound();
        $this->get('https://hr.luvep.com/activities')->assertNotFound();
    }

    public function test_mirrored_pages_navigate_to_hr_but_clock_api_stays_on_main(): void
    {
        config(['hr.enabled' => true]);
        $this->actingAs($this->employee())->get('https://luvep.com/hr/profile')->assertRedirect('https://hr.luvep.com/profile');
        $this->getJson('https://luvep.com/hr/clock')->assertOk()->assertJsonPath('status', 'off_shift');
    }

    public function test_unknown_hosts_cannot_serve_hr_routes(): void
    {
        config(['hr.enabled' => true]);
        $this->actingAs($this->employee())->getJson('https://unexpected.example/hr/clock')->assertNotFound();
    }
}
