<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\ResidentIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentEnglishExceptionTest extends TestCase
{
    use RefreshDatabase;

    private function intake(): ResidentIntake
    {
        return ResidentIntake::create([
            'intake_id' => 'RI-STAFF-ENGLISH', 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'email' => 'synthetic@example.test', 'english_evidence' => 'IELTS',
            'english_test_score' => '7', 'english_test_date' => '2026-10-01', 'status' => 'Submitted',
        ]);
    }

    public function test_managers_advisers_and_admins_can_record_reason_and_reopen_audited_decision(): void
    {
        $intake = $this->intake();
        foreach (['immigration_manager', 'immigration_adviser', 'admin', 'super_admin'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor);
            $this->patchJson("/portal/immigration/intakes/resident/{$intake->id}", ['fields' => [
                'english_evidence' => 'Not Required', 'english_evidence_reason' => "Recorded reason for {$role}",
            ]])->assertOk()->assertJsonPath('english_evidence_exception.reason', "Recorded reason for {$role}")
                ->assertJsonPath('english_evidence_exception.actor_id', $actor->id);
            $this->getJson("/portal/immigration/intakes/resident/{$intake->id}/data")->assertOk()
                ->assertJsonPath('english_evidence_exception.reason', "Recorded reason for {$role}");
            $intake->refresh();
            $this->assertSame('Not Required', $intake->english_evidence);
            $this->assertNull($intake->english_test_score);
            $this->assertNull($intake->english_test_date);
            $this->assertNotEmpty($intake->english_evidence_exception['recorded_at']);
            $log = ActivityLog::where('action', 'resident_intake.english_evidence_updated')->latest('id')->firstOrFail();
            $this->assertSame($actor->id, $log->user_id);
            $this->assertSame("Recorded reason for {$role}", $log->properties['new']['exception']['reason']);
            $this->assertNotNull($log->created_at);
        }
        $this->assertSame('Submitted', $intake->status);
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame(4, ActivityLog::where('action', 'resident_intake.english_evidence_updated')->count());
    }

    public function test_reason_is_required_and_ordinary_staff_cannot_set_or_erase_exception(): void
    {
        $intake = $this->intake();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        foreach ([null, '', '  '] as $reason) {
            $this->patchJson("/portal/immigration/intakes/resident/{$intake->id}", ['fields' => [
                'english_evidence' => 'Not Required', 'english_evidence_reason' => $reason,
            ]])->assertUnprocessable()->assertJsonValidationErrors('fields.english_evidence_reason');
        }
        $this->assertSame('IELTS', $intake->fresh()->english_evidence);
        $this->actingAs(User::factory()->create(['role' => 'immigration']));
        $this->patchJson("/portal/immigration/intakes/resident/{$intake->id}", ['fields' => [
            'english_evidence' => 'Not Required', 'english_evidence_reason' => 'Forged',
        ]])->assertForbidden();
        $intake->update(['english_evidence' => 'Not Required', 'english_evidence_exception' => ['reason' => 'Protected']]);
        $this->patchJson("/portal/immigration/intakes/resident/{$intake->id}", ['fields' => [
            'english_evidence' => 'Qualification(s)',
        ]])->assertForbidden();
        $this->patchJson("/portal/immigration/intakes/resident/{$intake->id}", ['fields' => ['job_title' => 'Updated job']])->assertOk();
        $this->assertSame(['reason' => 'Protected'], $intake->fresh()->english_evidence_exception);
    }

    public function test_returning_to_normal_evidence_clears_active_reason_and_keeps_history(): void
    {
        $intake = $this->intake();
        $this->actingAs(User::factory()->create(['role' => 'immigration_adviser']));
        $url = "/portal/immigration/intakes/resident/{$intake->id}";
        $this->patchJson($url, ['fields' => ['english_evidence' => 'Not Required', 'english_evidence_reason' => 'Staff record only']])->assertOk();
        $this->patchJson($url, ['fields' => ['english_evidence' => 'Qualification(s)']])->assertOk();
        $this->assertSame('Qualification(s)', $intake->fresh()->english_evidence);
        $this->assertNull($intake->english_evidence_exception);
        $this->getJson($url.'/data')->assertOk()->assertJsonCount(2, 'english_evidence_history');
    }

    public function test_duplicate_save_is_not_a_new_decision_and_unrelated_roles_cannot_waive(): void
    {
        $intake = $this->intake();
        $url = "/portal/immigration/intakes/resident/{$intake->id}";
        foreach (['education', 'agent', 'lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]));
            $this->patchJson($url, ['fields' => ['english_evidence' => 'Not Required', 'english_evidence_reason' => 'Forged']])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $fields = ['english_evidence' => 'Not Required', 'english_evidence_reason' => str_repeat('Full reason ', 50)];
        $this->patchJson($url, ['fields' => $fields])->assertOk();
        $first = $intake->fresh()->english_evidence_exception;
        $this->travel(1)->hours();
        $this->patchJson($url, ['fields' => $fields])->assertOk();
        $this->assertSame($first, $intake->fresh()->english_evidence_exception);
        $this->assertSame(1, ActivityLog::where('action', 'resident_intake.english_evidence_updated')->count());
        $this->patchJson($url, ['fields' => ['english_evidence_exception' => ['reason' => 'Forged']]])
            ->assertUnprocessable()->assertJsonValidationErrors('fields.english_evidence_exception');
    }

    public function test_family_only_edits_do_not_require_or_replace_english_evidence(): void
    {
        $intake = $this->intake();
        $intake->update(['english_evidence' => null, 'english_test_score' => null, 'english_test_date' => null]);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $url = "/portal/immigration/intakes/resident/{$intake->id}";
        $this->patchJson($url, ['fields' => ['include_family' => 'Yes']])->assertOk();
        $this->assertNull($intake->fresh()->english_evidence);
        $this->patchJson($url, ['fields' => ['english_evidence' => 'Not Required', 'english_evidence_reason' => 'Fresh staff record']])->assertOk();
        $decision = $intake->fresh()->english_evidence_exception;
        $this->patchJson($url, ['fields' => ['include_family' => 'No']])->assertOk();
        $intake->refresh();
        $this->assertSame('Not Required', $intake->english_evidence);
        $this->assertSame($decision, $intake->english_evidence_exception);
        $this->assertSame('No', $intake->include_family);
    }
}
