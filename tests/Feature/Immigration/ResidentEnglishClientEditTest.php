<?php

namespace Tests\Feature\Immigration;

use App\Models\{ActivityLog, Assessment, Lead, ResidentIntake, User, VisaType};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Storage};
use Tests\TestCase;

class ResidentEnglishClientEditTest extends TestCase
{
    use RefreshDatabase;

    private function linked(): array
    {
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $intake = ResidentIntake::create([
            'intake_id' => 'RI-CLIENT-ENGLISH', 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'email' => 'synthetic@example.test', 'english_evidence' => 'Qualification(s)', 'status' => 'Submitted',
        ]);
        $visa = VisaType::create(['code' => 'SMC', 'name' => 'Resident test', 'category' => 'Resident']);
        $assessment = Assessment::create([
            'visa_type_id' => $visa->id, 'applicant_email' => $intake->email,
            'intakeable_type' => ResidentIntake::class, 'intakeable_id' => $intake->id,
        ]);
        $lead = Lead::create([
            'first_name' => $intake->first_name, 'last_name' => $intake->last_name, 'email' => $intake->email,
            'is_immigration_case' => true, 'portal_invitation_status' => 'accepted',
        ]);
        $lead->forceFill(['assessment_id' => $assessment->id])->save();

        return [$intake, User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => true])];
    }

    public function test_authenticated_client_cannot_set_not_required_or_staff_metadata(): void
    {
        [$intake, $client] = $this->linked();
        $this->actingAs($client);
        $before = ActivityLog::count();
        foreach ([
            ['english_evidence' => 'Not Required'], ['english_evidence' => 'not required'],
            ['english_evidence_reason' => 'Forged'], ['english_evidence_exception' => ['reason' => 'Forged']],
        ] as $fields) {
            $this->postJson('/portal/lead/visa-assessment', ['field_values' => $fields])->assertUnprocessable();
        }
        $this->assertSame('Qualification(s)', $intake->fresh()->english_evidence);
        $this->assertNull($intake->english_evidence_exception);
        $this->assertSame($before, ActivityLog::count());
        $this->assertDatabaseCount('lead_documents', 0);
    }

    public function test_stale_client_editor_preserves_fresh_staff_choice_and_saves_other_fields(): void
    {
        [$intake, $client] = $this->linked();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $this->patchJson("/portal/immigration/intakes/resident/{$intake->id}", ['fields' => [
            'english_evidence' => 'Not Required', 'english_evidence_reason' => 'Private staff reason',
        ]])->assertOk();
        $decision = $intake->fresh()->english_evidence_exception;
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => [
            'english_evidence' => 'IELTS', 'english_test_score' => '9', 'english_test_date' => '2026-10-01',
            'job_title' => 'Updated ordinary field',
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $intake->refresh();
        $this->assertSame('Not Required', $intake->english_evidence);
        $this->assertSame($decision, $intake->english_evidence_exception);
        $this->assertNull($intake->english_test_score);
        $this->assertNull($intake->english_test_date);
        $this->assertSame('Updated ordinary field', $intake->job_title);
        $this->get('/portal/lead/visa-assessment')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('assessment.read_only_fields', $intake->applicantReadOnlyFields())
            ->missing('assessment.values.english_evidence_exception'));
        $this->assertSame(1, ActivityLog::where('action', 'resident_intake.english_evidence_updated')->count());
    }

    public function test_normal_client_evidence_edits_remain_available(): void
    {
        [$intake, $client] = $this->linked();
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => [
            'english_evidence' => 'IELTS', 'english_test_score' => '7', 'english_test_date' => '2026-10-01',
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $intake->refresh();
        $this->assertSame('IELTS', $intake->english_evidence);
        $this->assertSame('7', $intake->english_test_score);
    }
}
