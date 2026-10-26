<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\PotentialCase;
use App\Models\User;
use App\Services\Immigration\PotentialCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Potential Cases onboarding pipeline: two consultations + key-matters audio
 * gate the written-agreement signing; licensed-adviser override; promotion to
 * an official case.
 */
class PotentialCaseTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): Lead
    {
        return Lead::create(['first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'priya@example.test']);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'immigration', 'is_active' => true]);
    }

    private function adviser(bool $current = true): User
    {
        return User::factory()->create([
            'role' => User::ROLE_IMMIGRATION_ADVISER, 'is_active' => true,
            'iaa_licence_number' => 'IAA-99',
            'iaa_licence_expiry' => $current ? now()->addYear() : now()->subDay(),
        ]);
    }

    public function test_mark_lead_as_potential_case(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->staff())
            ->post('/portal/immigration/potential-cases', ['lead_id' => $lead->id])
            ->assertRedirect();

        $this->assertDatabaseHas('potential_cases', ['lead_id' => $lead->id, 'status' => 'onboarding']);
    }

    public function test_cannot_mark_an_official_case_as_potential(): void
    {
        $lead = Lead::create(['first_name' => 'Al', 'last_name' => 'Ready', 'email' => 'al@example.test', 'is_immigration_case' => true]);
        $this->actingAs($this->staff())
            ->post('/portal/immigration/potential-cases', ['lead_id' => $lead->id])
            ->assertStatus(422);
    }

    public function test_sign_is_gated_until_two_meetings_and_audio(): void
    {
        $svc = app(PotentialCaseService::class);
        $pc = $svc->createFor($this->lead(), $this->staff());

        $this->assertFalse($pc->gatePassed());

        // One meeting + no audio → still gated.
        $svc->logMeeting($pc, 1, Carbon::now(), [['name' => 'Emma']], 'Intro consult', $this->staff());
        $this->assertFalse($pc->fresh()->gatePassed());

        // Second meeting + audio ack → gate passes.
        $svc->logMeeting($pc, 2, Carbon::now(), [['name' => 'Hendry']], 'Pre-sign consult', $this->staff());
        $svc->acknowledgeAudio($pc, $this->staff());
        $this->assertTrue($pc->fresh()->gatePassed());

        $signed = $svc->sign($pc->fresh());
        $this->assertSame('signed', $signed->status);
        $this->assertNotNull($signed->agreement_signed_at);
    }

    public function test_adviser_override_bypasses_the_gate(): void
    {
        $svc = app(PotentialCaseService::class);
        $pc = $svc->createFor($this->lead(), $this->staff());
        $adviser = $this->adviser();

        $svc->override($pc, $adviser, 'Client overseas, consults done by video and recorded elsewhere.');
        $this->assertTrue($pc->fresh()->gatePassed());
        $this->assertSame('signed', $svc->sign($pc->fresh())->status);
    }

    public function test_override_requires_a_current_licence(): void
    {
        $svc = app(PotentialCaseService::class);
        $pc = $svc->createFor($this->lead(), $this->staff());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->override($pc, $this->adviser(false), 'no licence');
    }

    public function test_promote_creates_official_case_and_records_promoter(): void
    {
        $svc = app(PotentialCaseService::class);
        $staff = $this->staff();
        $pc = $svc->createFor($this->lead(), $staff);
        $svc->override($pc, $this->adviser(), 'ok');
        $svc->sign($pc->fresh());

        $promoted = $svc->promote($pc->fresh(), $staff);

        $this->assertSame('promoted', $promoted->status);
        $this->assertSame($staff->id, $promoted->promoted_by);
        $this->assertFalse($promoted->promoted_by_system);

        $lead = $promoted->lead->fresh();
        $this->assertTrue((bool) $lead->is_immigration_case);
        $this->assertSame('For Assessment', $lead->immigration_stage);
    }

    public function test_cannot_promote_before_signing(): void
    {
        $svc = app(PotentialCaseService::class);
        $pc = $svc->createFor($this->lead(), $this->staff());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->promote($pc, $this->staff());
    }

    public function test_decline_sets_status(): void
    {
        $svc = app(PotentialCaseService::class);
        $pc = $svc->createFor($this->lead(), $this->staff());

        $declined = $svc->decline($pc, $this->staff(), 'Not a viable visa pathway.');
        $this->assertSame('declined', $declined->status);
        $this->assertSame('Not a viable visa pathway.', $declined->decline_reason);
    }
}
