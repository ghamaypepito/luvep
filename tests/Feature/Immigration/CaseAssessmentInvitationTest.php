<?php

namespace Tests\Feature\Immigration;

use App\Mail\CaseAssessmentInvitation;
use App\Models\{Assessment, Lead, User, VisaType};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail, Notification, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CaseAssessmentInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(string $category = 'work'): Lead
    {
        $visa = VisaType::create(['name' => 'Test '.$category, 'code' => strtoupper($category), 'category' => $category]);
        return Lead::create(['first_name' => 'Case', 'last_name' => 'Applicant', 'email' => 'case@example.test', 'is_immigration_case' => true, 'inz_visa_type' => $visa->name]);
    }

    public static function categories(): array
    {
        return array_map(fn ($type) => [$type], ['work', 'student', 'visitor', 'resident', 'family']);
    }

    #[DataProvider('categories')]
    public function test_draft_is_linked_to_exact_case_and_repeated_request_preserves_approval_gate(string $category): void
    {
        Mail::fake();
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = $this->caseFor($category);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Applicant', 'email' => $lead->email]);
        $url = "/portal/immigration/cases/{$lead->id}/assessment-invitation";
        $this->actingAs($staff)->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $firstId = $lead->refresh()->assessment_id;
        $this->assertNotNull($firstId);
        $assessment = Assessment::findOrFail($firstId);
        $this->assertSame('draft', $assessment->status);
        $this->assertSame('Draft', $assessment->intakeable->status);
        $this->assertNull($assessment->intakeable->dob);
        $this->assertSame('pending', $lead->portal_invitation_status);
        $this->assertNull($lead->portal_invitation_approved_at);
        $this->assertNull($lead->portalUser);
        $this->assertNull($other->refresh()->assessment_id);
        $this->actingAs($staff)->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($firstId, $lead->refresh()->assessment_id);
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('leads', 2);
        Mail::assertNothingSent();
    }

    public function test_active_client_receives_one_authenticated_link_and_only_sees_own_draft(): void
    {
        Mail::fake();
        $staff = User::factory()->create(['role' => 'immigration']);
        $lead = $this->caseFor();
        $lead->update(['portal_invitation_status' => 'accepted']);
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => true]);
        $url = "/portal/immigration/cases/{$lead->id}/assessment-invitation";
        $this->actingAs($staff)->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($staff)->post($url)->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertSent(CaseAssessmentInvitation::class, 1);
        Mail::assertSent(CaseAssessmentInvitation::class, fn ($mail) => $mail->hasTo($client->email)
            && $mail->assessmentUrl === url('/portal/lead/visa-assessment'));
        $this->actingAs($client)->get('/portal/lead/visa-assessment')->assertOk()
            ->assertInertia(fn ($page) => $page->where('assessment.type', 'work')->where('assessment.values.dob', null));
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Client', 'email' => $lead->email]);
        $otherClient = User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);
        $this->actingAs($otherClient)->get('/portal/lead/visa-assessment')->assertOk()
            ->assertInertia(fn ($page) => $page->where('assessment', null));
        $this->actingAs($otherClient)->post('/portal/lead/visa-assessment', ['field_values' => ['current_country' => 'Intruder'], 'assessment_id' => $lead->refresh()->assessment_id])->assertUnprocessable();
        $this->assertSame('Case', Assessment::find($lead->assessment_id)->intakeable->first_name);
    }

    #[DataProvider('categories')]
    public function test_owner_can_save_partial_draft_and_generate_vif_without_fabricated_values(string $category): void
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = $this->caseFor($category);
        $lead->update(['portal_invitation_status' => 'accepted']);
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => true]);
        $this->actingAs($staff)->post("/portal/immigration/cases/{$lead->id}/assessment-invitation")->assertSessionHasNoErrors();
        $assessment = Assessment::findOrFail($lead->refresh()->assessment_id);
        $intake = $assessment->intakeable;
        $this->get("/portal/immigration/intakes/{$category}/{$intake->id}/preview")->assertOk();
        $this->actingAs($client)->get('/portal/lead/visa-assessment')->assertOk()
            ->assertInertia(fn ($page) => $page->where('assessment.type', $category)->where('assessment.values.dob', null));
        $field = $category === 'resident' ? 'nationality' : 'current_country';
        $this->post('/portal/lead/visa-assessment', ['field_values' => [$field => 'Philippines']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Philippines', $intake->refresh()->{$field});
        $this->assertNull($intake->dob);
        $document = \App\Models\LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'vif')->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($document->file_path));
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('assessments', 1);
    }

    public function test_failed_email_rolls_back_new_draft_and_can_be_retried(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = $this->caseFor();
        $lead->update(['portal_invitation_status' => 'accepted']);
        User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => true]);
        $mailManager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic transport failure'));
        $url = "/portal/immigration/cases/{$lead->id}/assessment-invitation";
        $this->actingAs($staff)->post($url)->assertRedirect()->assertSessionHasErrors('assessment');
        $this->assertNull($lead->refresh()->assessment_id);
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('work_intakes', 0);
        Mail::swap($mailManager);
        Mail::fake();
        $this->post($url)->assertSessionHasNoErrors();
        Mail::assertSent(CaseAssessmentInvitation::class, 1);
        $this->assertDatabaseCount('assessments', 1);
    }

    public function test_revoked_and_inactive_accounts_are_not_reactivated_or_emailed(): void
    {
        Mail::fake();
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = $this->caseFor();
        $lead->update(['portal_invitation_status' => 'revoked']);
        $this->actingAs($staff)->post("/portal/immigration/cases/{$lead->id}/assessment-invitation")->assertSessionHasErrors('assessment');
        $this->assertNull($lead->refresh()->assessment_id);
        $this->assertSame('revoked', $lead->portal_invitation_status);
        $lead->update(['portal_invitation_status' => 'accepted']);
        User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => false]);
        $this->actingAs($staff)->post("/portal/immigration/cases/{$lead->id}/assessment-invitation")->assertSessionHasErrors('assessment');
        Mail::assertNothingSent();
    }

    public function test_unsupported_category_and_non_staff_requests_do_not_create_drafts(): void
    {
        Mail::fake();
        $lead = $this->caseFor('unknown');
        $url = "/portal/immigration/cases/{$lead->id}/assessment-invitation";
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url)->assertSessionHasErrors('assessment');
        $this->actingAs(User::factory()->create(['role' => 'sales']))->post($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'agent']))->post($url)->assertForbidden();
        $this->assertDatabaseCount('assessments', 0);
        Mail::assertNothingSent();
    }
}
