<?php

namespace Tests\Feature\EpAgent;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ep_agent.enabled' => true, 'ep_agent.ui_enabled' => true, 'ep_agent.pilot_admins' => true]);
    }

    private function lead(array $data = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Client', 'last_name' => 'One', 'email' => 'client@example.test', 'is_immigration_case' => true], $data));
    }

    public function test_manager_totals_and_details_use_current_assignment_scope(): void
    {
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        config(['ep_agent.pilot_user_ids' => [$manager->id]]);
        $other = User::factory()->create(['role' => 'immigration']);
        $mine = $this->lead(['assigned_to' => $manager->id]);
        $hidden = $this->lead(['first_name' => 'SECRET', 'assigned_to' => $other->id]);
        DB::table('leads')->where('id', $mine->id)->update(['updated_at' => now()->subDays(8)]);
        $this->actingAs($manager)->getJson('/api/ep-agent/manager?portal=immigration')->assertOk()->assertJsonPath('counts.total', 1)->assertJsonPath('counts.inactive', 1)->assertJsonPath('records.0.client.id', $mine->id)->assertJsonPath('records.0.personnel.id', $manager->id)->assertDontSee('SECRET');
        $this->getJson('/api/ep-agent/manager/clients/'.$hidden->id.'?portal=immigration')->assertNotFound();
        $this->getJson('/api/ep-agent/manager/personnel/'.$other->id.'?portal=immigration')->assertNotFound();
        $this->getJson('/api/ep-agent/manager?portal=education')->assertForbidden();
        $mine->update(['assigned_to' => $other->id]);
        $this->getJson('/api/ep-agent/manager/clients/'.$mine->id.'?portal=immigration')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_admin_client_and_personnel_popups_only_return_allowlisted_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'education']);
        $client = $this->lead(['is_student' => true, 'assigned_to' => $staff->id, 'passport_number' => 'SECRET-PASSPORT']);
        $this->actingAs($admin)->getJson('/api/ep-agent/manager/clients/'.$client->id.'?portal=education')->assertOk()->assertJsonPath('client.name', 'Client One')->assertJsonPath('client.personnel.id', $staff->id)->assertDontSee('SECRET-PASSPORT')->assertJsonMissingPath('client.passport_number');
        $res = $this->getJson('/api/ep-agent/manager/personnel/'.$staff->id.'?portal=education')->assertOk()->assertJsonPath('personnel.workload', 1)->assertJsonPath('personnel.name', $staff->name)->assertJsonMissingPath('personnel.password')->assertJsonMissingPath('personnel.phone');
        $res->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/ep-agent/manager?portal=employment')->assertForbidden();
        $this->getJson('/api/ep-agent/manager?portal=residency')->assertForbidden();
    }

    public function test_unknown_timestamp_is_not_inactive_and_results_are_bounded_with_team_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'education']);
        for ($i = 0; $i < 27; $i++) {
            $this->lead(['is_student' => true, 'assigned_to' => $staff->id]);
        }
        DB::table('leads')->update(['updated_at' => null]);
        $res = $this->actingAs($admin)->getJson('/api/ep-agent/manager?portal=education')->assertOk()->assertJsonPath('counts.total', 27)->assertJsonPath('counts.inactive', 0)->assertJsonPath('counts.unknown_update', 27)->assertJsonCount(25, 'records')->assertJsonPath('team.0.workload', 27)->assertJsonPath('records.0.updated_at', null);
        $this->getJson('/api/ep-agent/manager?portal=education&before='.$res->json('next_before'))->assertJsonCount(2, 'records');
        $this->getJson('/api/ep-agent/manager?portal=education&attention=1')->assertJsonCount(0, 'records');
    }

    public function test_regular_staff_and_disabled_pilot_cannot_open_manager(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        config(['ep_agent.pilot_user_ids' => [$staff->id]]);
        $this->actingAs($staff)->getJson('/api/ep-agent/manager?portal=education')->assertForbidden();
        $this->getJson('/ep-manager')->assertForbidden();
        config(['ep_agent.ui_enabled' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/ep-agent/manager?portal=education')->assertForbidden();
    }
}
