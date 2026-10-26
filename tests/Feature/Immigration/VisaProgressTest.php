<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\User;
use App\Services\Immigration\VisaProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INZ-style visa sub-status tracker (Visa Lodged → outcome). Procedural status
 * tracking stored in leads.visa_progress (JSON) — no new leads VARCHAR.
 */
class VisaProgressTest extends TestCase
{
    use RefreshDatabase;

    private function caseLead(string $stage = 'Visa Lodged'): Lead
    {
        return Lead::create([
            'first_name' => 'Hala', 'last_name' => 'Abuzeid', 'email' => 'hala@example.test',
            'is_immigration_case' => true, 'immigration_stage' => $stage,
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'immigration', 'is_active' => true]);
    }

    public function test_fresh_case_returns_full_catalogue_all_not_started_and_tracked(): void
    {
        $svc = app(VisaProgressService::class);
        $p = $svc->get($this->caseLead());

        $this->assertTrue($p['tracked']);
        $this->assertCount(7, $p['activities']);
        $this->assertSame('application_received', $p['activities'][0]['key']);
        $this->assertSame('We have received your application.', $p['activities'][0]['description']);
        $this->assertSame('not_started', $p['activities'][0]['status']);
        // The five INZ statuses (legend) are present.
        $this->assertEqualsCanonicalizing(
            ['not_started', 'in_progress', 'info_requested', 'completed', 'not_applicable'],
            array_keys($p['statuses'])
        );
    }

    public function test_not_tracked_before_lodgement(): void
    {
        $svc = app(VisaProgressService::class);
        $this->assertFalse($svc->get($this->caseLead('Agreement Signed'))['tracked']);
        $this->assertNull($svc->summary($this->caseLead('Agreement Signed')));
    }

    public function test_setting_status_keeps_history_past_and_current(): void
    {
        $svc = app(VisaProgressService::class);
        $user = $this->staff();
        $this->actingAs($user);
        $case = $this->caseLead();

        $svc->setActivityStatus($case->fresh(), 'assessment', 'in_progress', null, $user);
        $svc->setActivityStatus($case->fresh(), 'assessment', 'completed', 'verified', $user);

        $activity = collect($svc->get($case->fresh())['activities'])->firstWhere('key', 'assessment');
        $this->assertSame('completed', $activity['status']);            // current sub-status
        $this->assertCount(2, $activity['history']);                     // past + current
        $this->assertSame('in_progress', $activity['history'][0]['status']);
        $this->assertSame('completed', $activity['history'][1]['status']);
        $this->assertSame($user->id, $activity['history'][1]['by_id']);
    }

    public function test_endpoint_updates_activity_for_staff(): void
    {
        $case = $this->caseLead();

        $this->actingAs($this->staff())
            ->post("/portal/immigration/cases/{$case->id}/visa-progress", [
                'activity' => 'identity_check', 'status' => 'completed',
            ])->assertRedirect();

        $case->refresh();
        $this->assertSame('completed', $case->visa_progress['activities']['identity_check']['status']);
    }

    public function test_endpoint_rejects_invalid_status_and_activity(): void
    {
        $case = $this->caseLead();
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post("/portal/immigration/cases/{$case->id}/visa-progress", ['activity' => 'assessment', 'status' => 'bogus'])
            ->assertSessionHasErrors('status');

        $this->actingAs($staff)
            ->post("/portal/immigration/cases/{$case->id}/visa-progress", ['activity' => 'nope', 'status' => 'completed'])
            ->assertSessionHasErrors('activity');
    }

    public function test_endpoint_404_on_non_case_lead(): void
    {
        $lead = Lead::create(['first_name' => 'Not', 'last_name' => 'Case', 'email' => 'nc@example.test']);

        $this->actingAs($this->staff())
            ->post("/portal/immigration/cases/{$lead->id}/visa-progress", ['activity' => 'assessment', 'status' => 'completed'])
            ->assertNotFound();
    }

    public function test_summary_reflects_current_activity_and_counts(): void
    {
        $svc = app(VisaProgressService::class);
        $user = $this->staff();
        $this->actingAs($user);
        $case = $this->caseLead();

        $svc->setActivityStatus($case->fresh(), 'application_received', 'completed', null, $user);
        $svc->setActivityStatus($case->fresh(), 'assessment', 'in_progress', null, $user);

        $summary = $svc->summary($case->fresh());
        $this->assertSame('Visa Lodged', $summary['stage']);
        $this->assertSame('assessment', $summary['current']['key']);
        $this->assertSame('in_progress', $summary['current']['status']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(7, $summary['total']);
    }

    public function test_application_status_endpoint_sets_label_and_lodged_at(): void
    {
        $case = $this->caseLead();

        $this->actingAs($this->staff())
            ->post("/portal/immigration/cases/{$case->id}/visa-progress/status", [
                'application_status' => 'Under Assessment',
                'lodged_at' => '2026-09-28T10:00:00',
            ])->assertRedirect();

        $case->refresh();
        $this->assertSame('Under Assessment', $case->visa_progress['application_status']);
        $this->assertNotNull($case->inz_lodged_at);
    }
}
