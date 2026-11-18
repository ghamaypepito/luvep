<?php

namespace Tests\Feature\Admin;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AffiliateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_paginates_complete_history_and_preserves_referral_source_after_assignment(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $source = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $assigned = User::factory()->create(['role' => 'agent']);
        for ($index = 0; $index < 26; $index++) {
            Lead::create([
                'lead_id' => 'AP-PROFILE-'.$index, 'first_name' => 'Profile', 'last_name' => 'Example '.$index,
                'email' => "profile-$index@example.test", 'agent_id' => $assigned->id,
                'original_affiliate_id' => $source->id, 'referred_at' => now(),
                'is_student' => true, 'is_immigration_case' => true,
            ]);
        }
        $this->actingAs($admin)->get("/admin/agents/{$source->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('leads', 25)
                ->where('leadsCount', 26)->where('convertedCount', 26)
                ->where('statusCounts', fn ($rows) => collect($rows)->sum('count') === 26)
                ->where('leadPagination.2.url', fn ($url) => str_contains($url, 'page=2'))
                ->where('agent.referral.url', fn ($url) => str_contains($url, '/affiliate/register/')));
        $this->get("/admin/agents/{$source->id}?page=2")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('leads', 1)->where('leadsCount', 26));
        $this->get("/admin/agents/{$assigned->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('leads', 0)->where('convertedCount', 0));
    }
}
