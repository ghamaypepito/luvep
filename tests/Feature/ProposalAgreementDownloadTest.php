<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class ProposalAgreementDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_staff_can_download_current_agreement_without_saving_or_sending(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'education']);
        $lead = Lead::create(['first_name' => 'Download', 'last_name' => 'Sample', 'email' => 'sample@example.test']);
        $before = $lead->fresh()->getAttributes();
        $response = $this->actingAs($user)->post('/admin/leads/'.$lead->id.'/generate/consultancy_onshore/download-preview');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('draft', $response->headers->get('Content-Disposition'));
        $text = (new Parser)->parseContent($response->getContent())->getText();
        $this->assertStringContainsString('Download Sample', $text);
        $this->assertStringContainsString('ENGAGEMENT AGREEMENT', $text);
        $this->assertStringContainsString('DRAFT - FOR REVIEW', $text);
        $this->assertSame($before, $lead->fresh()->getAttributes());
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_pdf_keeps_edited_fees_currency_and_bank_details(): void
    {
        $lead = Lead::create(['first_name' => 'Custom', 'last_name' => 'Fees']);
        $data = ['currency' => 'nzd', 'school_enrolment_fee' => 43210,
            'english_proficiency_fee' => 1234, 'bank_name' => 'Preview Bank', 'bank_account_number' => 'TEST-123'];
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/leads/{$lead->id}/generate/consultancy_std_100/download-preview", $data);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = (new Parser)->parseContent($response->getContent())->getText();
        foreach (['43,210', '1,234', 'NZ$', 'Preview Bank', 'TEST-123'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
    }

    public function test_proposal_download_uses_unsaved_selection_and_reasons_without_changing_lead(): void
    {
        Mail::fake();
        $old = Program::create(['title' => 'Previous programme', 'status' => 'published', 'category' => 'bachelors', 'level' => '7']);
        $school = School::create(['name' => 'Sample University']);
        $selected = Program::create(['title' => 'Selected programme', 'status' => 'published', 'category' => 'bachelors', 'level' => '7', 'institution' => 'Old School', 'school_id' => $school->id, 'intake_months' => 'February 2027, July 2027', 'tuition_fee' => 12345]);
        $lead = Lead::create(['first_name' => 'Proposal', 'last_name' => 'Sample', 'proposed_program_ids' => [$old->id], 'proposal_review' => ['status' => 'pending']]);
        $before = $lead->fresh()->getAttributes();
        $data = ['program_ids' => [$selected->id], 'reasons' => [$selected->id => 'Matches your study goals.']];
        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->post("/admin/leads/{$lead->id}/generate/proposal/download-preview", $data);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = (new Parser)->parseContent($response->getContent())->getText();
        foreach (['Selected programme', 'Sample University', 'February 2027, July 2027', '12,345', 'Matches your study goals.'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertStringNotContainsString('Previous programme', $text);
        $this->assertStringNotContainsString('Old School', $text);
        $this->assertSame($before, $lead->fresh()->getAttributes());
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_download_retains_staff_only_access_and_rejects_empty_proposals(): void
    {
        $lead = Lead::create(['first_name' => 'Private', 'last_name' => 'Sample']);
        $url = "/admin/leads/{$lead->id}/generate/consultancy_onshore/download-preview";
        $this->post($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'lead']))->post($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson("/admin/leads/{$lead->id}/generate/proposal/download-preview")
            ->assertUnprocessable()->assertJsonValidationErrors('program_ids');
    }

    public function test_existing_html_preview_still_renders(): void
    {
        $lead = Lead::create(['first_name' => 'Preview', 'last_name' => 'Sample']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get("/admin/leads/{$lead->id}/generate/consultancy_onshore/preview")
            ->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('ENGAGEMENT AGREEMENT');
    }
}
