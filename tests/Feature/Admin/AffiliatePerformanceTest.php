<?php

namespace Tests\Feature\Admin;

use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AffiliatePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function lead(User $agent, string $created, array $attributes = []): Lead
    {
        $lead = Lead::create($attributes + ['agent_id' => $agent->id, 'first_name' => 'Sample', 'last_name' => 'Referral', 'tracking_code' => bin2hex(random_bytes(8)), 'status' => 'New Leads']);
        $lead->forceFill(['created_at' => $created])->saveQuietly();

        return $lead;
    }

    public function test_report_filters_referral_cohort_and_counts_each_multiservice_lead_once(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent', 'name' => 'Alpha Partner']);
        $other = User::factory()->create(['role' => 'agent', 'name' => 'Beta Partner']);
        $this->lead($agent, '2026-11-02 00:00:00');
        $this->lead($agent, '2026-11-17 23:59:59', ['is_student' => true, 'is_immigration_case' => true, 'education_stage' => 'Started Course', 'immigration_stage' => 'Approved Visa']);
        $this->lead($agent, '2026-10-31 23:59:59', ['status' => 'Converted']);
        $this->lead($agent, '2026-11-18 00:00:00', ['status' => 'Converted']);
        $this->lead($other, '2026-11-02 12:00:00', ['status' => 'Converted']);
        $this->lead($agent, '2026-11-03 12:00:00')->delete();

        $this->actingAs($admin)->get('/admin/agents?from=2026-11-02&to=2026-11-17&agent_id='.$agent->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/agents/Index')
            ->where('summary', ['registered_partners' => 2, 'leads' => 2, 'converted' => 1])
            ->where('agents.0.leads_count', 2)->where('agents.0.converted_count', 1)
            ->where('agents.1.leads_count', 0)
            ->where('daily', [['date' => '2026-11-02', 'count' => 1], ['date' => '2026-11-17', 'count' => 1]])
            ->where('statusCounts', fn ($rows) => collect($rows)->firstWhere('status', 'Started Course')['count'] === 1
                && collect($rows)->sum('count') === 2));
    }

    public function test_original_affiliate_attribution_survives_assignment_changes_with_legacy_fallback(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $original = User::factory()->create(['role' => 'agent', 'name' => 'Alpha Origin']);
        $assigned = User::factory()->create(['role' => 'agent', 'name' => 'Beta Assignee']);
        $this->lead($assigned, '2026-11-03 12:00:00', ['original_affiliate_id' => $original->id]);
        $this->lead($assigned, '2026-11-04 12:00:00', ['is_english_student' => true]);

        $this->actingAs($admin)->get('/admin/agents?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.leads', 2)->where('summary.converted', 1)
            ->where('agents.0.leads_count', 1)->where('agents.0.converted_count', 0)
            ->where('agents.1.leads_count', 1)->where('agents.1.converted_count', 1));
    }

    public function test_default_dates_use_current_month_and_empty_results_are_zero(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
        $admin = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->lead($agent, '2026-10-31 23:59:59');
        $this->actingAs($admin)->get('/admin/agents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-11-01')->where('filters.to', '2026-11-18')
            ->where('summary', ['registered_partners' => 1, 'leads' => 0, 'converted' => 0])
            ->has('daily', 0)->has('statusCounts', 0));
    }

    public function test_directory_uses_version_order_when_an_older_draft_is_issued_last(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        AgentAgreement::create(['agent_id' => $agent->id, 'version' => 3, 'is_draft' => false, 'file_path' => 'current.pdf', 'original_name' => 'current.pdf']);
        AgentAgreement::create(['agent_id' => $agent->id, 'version' => 2, 'is_draft' => true, 'file_path' => '', 'original_name' => 'Draft']);

        $this->actingAs($admin)->get('/admin/agents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('agents.0.agreement_status', 'Generated'));
    }

    public function test_invalid_filter_or_nonaffiliate_id_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sales = User::factory()->create(['role' => 'sales']);
        $this->actingAs($admin)->getJson('/admin/agents?agent_id='.$sales->id)->assertUnprocessable()->assertJsonValidationErrors('agent_id');
        $this->getJson('/admin/agents?from=2026-11-18&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/admin/agents?from=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_partner_performance_is_limited_to_admin_roles_with_default_access(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get('/admin/agents')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/agents')->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'module_permissions' => ['agents']]))->get('/admin/agents')->assertOk();
    }
}
