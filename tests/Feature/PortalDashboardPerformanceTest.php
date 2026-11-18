<?php

namespace Tests\Feature;

use App\Http\Controllers\Portal\SalesController;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_dashboard_batches_recent_lead_details_and_keeps_their_values(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($staff);
        $first = null;
        for ($i = 0; $i < 8; $i++) {
            $lead = Lead::create(['first_name' => 'Sample', 'last_name' => (string) $i, 'created_at' => now()->subMinutes($i + 1)]);
            $lead->notes()->create(['kind' => 'general', 'body' => "Note {$i}", 'user_id' => $staff->id]);
            $first ??= $lead;
        }
        $first->notes()->create(['kind' => 'pre_screen', 'body' => 'Pre-screen details', 'user_id' => $staff->id]);
        $first->notes()->create(['kind' => 'goal_setting', 'body' => 'Study goals', 'user_id' => $staff->id]);
        Lead::create(['first_name' => 'Converted', 'last_name' => 'Student', 'is_student' => true]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = app(SalesController::class)->dashboard();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $request = Request::create('/portal/sales/dashboard');
        $request->headers->set('X-Inertia', 'true');
        $props = $response->toResponse($request)->getData(true)['props'];
        $rows = collect($props['recentLeads']);
        $this->assertCount(8, $rows);
        $this->assertSame('Sample 0', $rows->first()['name']);
        $this->assertSame(3, $rows->first()['notes_count']);
        $this->assertSame('Pre-screen details', $rows->first()['latest_pre_screen']['body']);
        $this->assertSame('Study goals', $rows->first()['latest_goal_setting']['body']);
        $this->assertFalse($rows->contains('name', 'Converted Student'));
        $this->assertLessThanOrEqual(35, $queries, "Sales dashboard ran {$queries} queries for eight recent leads.");
    }
}
