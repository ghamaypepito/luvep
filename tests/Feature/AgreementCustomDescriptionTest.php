<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\AgreementGenerator;
use App\Services\ConsultancyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class AgreementCustomDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $this->staff = User::factory()->create(['role' => 'education']);
        $this->lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant']);
    }

    public function test_live_preview_uses_custom_plain_text_with_line_breaks_and_no_executable_markup(): void
    {
        $text = "Custom enrolment & documentation support\n<img src=x onerror=alert(1)>";
        $response = $this->actingAs($this->staff)->get('/admin/leads/'.$this->lead->id.'/generate/consultancy_std_150/preview?'.http_build_query([
            'application_description' => $text, 'applicant_mode' => 'couple',
        ]))->assertOk();
        $this->assertStringContainsString('Custom enrolment &amp; documentation support', $response->getContent());
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->getContent());
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->getContent());
        $this->assertStringContainsString('MAIN APPLICANT (Couple)', $response->getContent());
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertNull($this->lead->fresh()->consultancy_review);
    }

    public function test_downloaded_pdf_uses_current_custom_text_without_saving_or_sending(): void
    {
        $before = $this->lead->fresh()->getAttributes();
        $response = $this->actingAs($this->staff)->post('/admin/leads/'.$this->lead->id.'/generate/consultancy_std_100/download-preview', [
            'application_description' => 'Custom description for the selected application only.',
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = (new Parser)->parseContent($response->getContent())->getText();
        $this->assertStringContainsString('Custom description for the selected application only.', $text);
        $this->assertSame($before, $this->lead->fresh()->getAttributes());
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_custom_description_survives_submission_review_and_approved_pdf_generation(): void
    {
        $custom = 'Tailored school enrolment and application support.';
        $this->actingAs($this->staff)->post('/admin/leads/'.$this->lead->id.'/generate/consultancy_std_150', [
            'application_description' => $custom, 'applicant_mode' => 'couple', 'notify' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review = $this->lead->fresh()->consultancy_review;
        $this->assertSame($custom, $review['overrides']['application_description'] ?? null);
        $this->assertSame('pending', $review['status']);
        $this->assertSame($custom, ConsultancyReviewService::overridesForGeneration($review)['application_description']);
        $this->assertDatabaseCount('lead_documents', 0);
        $reviewer = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($reviewer)->get('/consultancy-verification/'.$this->lead->id.'/preview')->assertOk()->assertSee($custom);
        $this->post('/consultancy-verification/'.$this->lead->id.'/approve', ['verify_all' => true, 'send_email' => false])->assertRedirect();
        $doc = LeadDocument::where('lead_id', $this->lead->id)->where('checklist_key', 'agree.consultancy')->sole();
        $text = (new Parser)->parseContent(Storage::disk('local')->get($doc->file_path))->getText();
        $this->assertStringContainsString($custom, $text);
        Mail::assertNothingOutgoing();
    }

    public function test_original_descriptions_and_voucher_formatting_are_retained_without_a_custom_value(): void
    {
        $generator = app(AgreementGenerator::class);
        foreach (AgreementGenerator::consultancyScenarios() as $key => $scenario) {
            [$payload] = $generator->buildConsultancyPayload($this->lead, $key);
            $html = view('agreements.consultancy', $payload)->render();
            $this->assertStringContainsString($scenario['description'], $html);
            [$blank] = $generator->buildConsultancyPayload($this->lead, $key, ['application_description' => '   ']);
            $this->assertSame($payload['scenario_description'], $blank['scenario_description']);
        }
    }

    public function test_description_type_and_length_are_validated_for_preview_download_and_submission(): void
    {
        foreach ([str_repeat('x', 1001), ['not' => 'plain text']] as $invalid) {
            $data = ['application_description' => $invalid];
            $base = '/admin/leads/'.$this->lead->id.'/generate/consultancy_std_100';
            $this->actingAs($this->staff)->getJson($base.'/preview?'.http_build_query($data))
                ->assertUnprocessable()->assertJsonValidationErrors('application_description');
            $this->postJson($base.'/download-preview', $data)->assertUnprocessable()->assertJsonValidationErrors('application_description');
            $this->postJson($base, $data)->assertUnprocessable()->assertJsonValidationErrors('application_description');
        }
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertNull($this->lead->fresh()->consultancy_review);
        Mail::assertNothingOutgoing();
    }

    public function test_many_short_lines_remain_inside_pdf_pages(): void
    {
        $custom = implode("\n", array_map(fn ($n) => sprintf('Detail %02d', $n), range(1, 80)));
        $response = $this->actingAs($this->staff)->post('/admin/leads/'.$this->lead->id.'/generate/consultancy_std_100/download-preview', [
            'application_description' => $custom,
        ])->assertOk();
        $pdf = (new Parser)->parseContent($response->getContent());
        $found = [];
        foreach ($pdf->getPages() as $page) {
            foreach ($page->getDataTm() as [$matrix, $text]) {
                if (preg_match_all('/Detail (\d{2})/', $text, $matches)) {
                    $this->assertGreaterThanOrEqual(40, (float) $matrix[5], 'Custom description must not flow below the page footer.');
                    $this->assertLessThanOrEqual(800, (float) $matrix[5], 'Custom description must not flow above the page body.');
                    $found = array_merge($found, $matches[1]);
                }
            }
        }
        $this->assertCount(80, array_unique($found));
        $this->assertSame('80', end($found));
    }
}
