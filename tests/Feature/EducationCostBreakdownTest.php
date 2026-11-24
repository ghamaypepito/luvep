<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Education\CostBreakdownRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class EducationCostBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Cache::flush();
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(12, 0));
        $this->actingAs(User::factory()->create(['role' => 'education']));
        Http::preventStrayRequests();
        Http::fake(['api.frankfurter.dev/*' => Http::response(['date' => '2026-11-17', 'base' => 'NZD', 'quote' => 'PHP', 'rate' => 35])]);
    }

    private function client(array $attributes = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Client', 'is_student' => true, 'residence_country' => 'Philippines'], $attributes));
    }

    private function data(Lead $lead): array
    {
        $quote = app(CostBreakdownRates::class)->quote('PHP', 'PHP');

        return ['lead_id' => $lead->id, 'country' => 'Philippines', 'category' => 'philippines', 'family' => 'couple_child',
            'source_currency' => 'PHP', 'target_currency' => 'PHP', 'rate_token' => $quote['token'],
            'rows' => [['description' => 'Custom school enrolment', 'amount' => '3500.00'], ['description' => 'Supporting costs', 'amount' => '1750.00']], 'action' => 'preview'];
    }

    public function test_reference_headings_render_in_preview_pdf_and_saved_snapshot_without_repricing(): void
    {
        $lead = $this->client();
        $before = $lead->fresh()->getRawOriginal();
        $quote = app(CostBreakdownRates::class)->quote('NZD', 'PHP');
        $rows = [
            ['section' => 'school_enrolment', 'description' => 'School enrolment and documentation fee', 'amount' => '2850.00'],
            ['section' => 'english_proficiency', 'description' => "Personalised review\nand unlimited mock tests", 'amount' => '400.00'],
            ['section' => 'english_proficiency', 'description' => 'Examination fee', 'amount' => '420.00'],
            ['section' => 'medical_exam', 'description' => 'Medical examination fee (full medical)', 'amount' => '480.00'],
            ['section' => 'visa_application', 'description' => 'Professional fee of Licensed Immigration Adviser', 'amount' => '2000.00'],
            ['section' => 'visa_application', 'description' => 'Immigration New Zealand fee', 'amount' => '850.00'],
            ['section' => 'travel_insurance', 'description' => 'Travel and medical insurance fee', 'amount' => '1000.00'],
            ['section' => 'other_costs', 'description' => 'Tuition fee - depends on the programme', 'amount' => null],
            ['section' => 'other_costs', 'description' => 'Show money / living expenses', 'amount' => '20000.00'],
            ['section' => 'other_costs', 'description' => 'Travel to New Zealand - cost not yet specified', 'amount' => null],
        ];
        $data = [...$this->data($lead), 'source_currency' => 'NZD', 'rate_token' => $quote['token'], 'rows' => $rows];
        $headings = ['School enrolment and documentation', 'English proficiency exam', 'Medical exam', 'Visa application', 'Travel and medical insurance'];
        foreach (['single' => 'Single', 'couple' => 'Couple', 'couple_child' => 'Couple with child'] as $family => $label) {
            $html = $this->postJson('/portal/education/cost-breakdowns', [...$data, 'family' => $family])->assertOk()->json('html');
            $last = -1;
            foreach ($headings as $heading) {
                $position = strpos($html, $heading);
                $this->assertNotFalse($position);
                $this->assertGreaterThan($last, $position);
                $last = $position;
            }
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString('8,000.00', $html);
            $this->assertStringContainsString('280,000.00', $html);
            $this->assertStringNotContainsString('28,000.00', $html);
            $this->assertGreaterThan(strpos($html, 'Listed cost total'), strpos($html, '<h2>Other costs to prepare for'));
        }
        $pdf = $this->postJson('/portal/education/cost-breakdowns', [...$data, 'family' => 'single', 'action' => 'download'])->assertOk()->getContent();
        $parsed = (new Parser)->parseContent($pdf);
        $text = $parsed->getText();
        foreach ([...$headings, 'Other costs to prepare for', '8,000.00', '280,000.00', 'Not specified'] as $expected) {
            $this->assertStringContainsString(strtoupper($expected), strtoupper($text));
        }
        foreach ($parsed->getPages() as $page) {
            if (str_contains($page->getText(), 'Other costs to prepare for')) {
                $this->assertStringContainsString('Tuition fee', $page->getText(), 'The excluded-cost heading must stay with its first row');
            }
        }
        if (getenv('EDUCATION_COST_SECTIONS_PDF_SAMPLE')) {
            file_put_contents(getenv('EDUCATION_COST_SECTIONS_PDF_SAMPLE'), $pdf);
        }
        $this->postJson('/portal/education/cost-breakdowns', [...$data, 'family' => 'single', 'action' => 'save'])->assertCreated();
        $snapshot = LeadDocument::sole()->notes['cost_breakdown_snapshot'];
        $this->assertEquals([2850, 400, 420, 480, 2000, 850, 1000], array_column(array_slice($snapshot['rows'], 0, 7), 'amount'));
        $this->assertEquals(8000, $snapshot['totals']['amount']);
        $this->assertSame('school_enrolment', $snapshot['rows'][0]['section']);
        $this->assertNull($snapshot['other_rows'][0]['amount']);
        $this->assertEquals(20000, $snapshot['other_rows'][1]['amount']);
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        Mail::assertNothingOutgoing();
    }

    public function test_legacy_rows_keep_totals_and_saved_files_when_new_headings_are_used(): void
    {
        $data = $this->data($this->client());
        $this->postJson('/portal/education/cost-breakdowns', [...$data, 'action' => 'save'])->assertCreated();
        $old = LeadDocument::sole();
        $oldMetadata = $old->getRawOriginal();
        $oldBytes = Storage::disk('local')->get($old->file_path);
        $newRows = $data['rows'];
        $newRows[0]['section'] = 'school_enrolment';
        $newRows[1]['section'] = 'medical_exam';
        $this->postJson('/portal/education/cost-breakdowns', [...$data, 'rows' => $newRows, 'action' => 'save'])->assertCreated();
        $this->assertSame($oldMetadata, $old->fresh()->getRawOriginal());
        $this->assertSame($oldBytes, Storage::disk('local')->get($old->file_path));
        $this->assertDatabaseCount('lead_documents', 2);
        $latest = LeadDocument::latest('id')->first();
        $this->assertEquals(5250, $latest->notes['cost_breakdown_snapshot']['totals']['amount']);
    }

    public function test_section_validation_and_other_cost_missing_amounts_do_not_turn_missing_prices_into_zero(): void
    {
        $data = $this->data($this->client());
        foreach ([
            [['section' => 'unknown', 'description' => 'Invalid heading', 'amount' => 100]],
            [['section' => 'school_enrolment', 'description' => 'Missing fee', 'amount' => null]],
            [['description' => 'Legacy missing fee', 'amount' => null]],
            [['section' => 'other_costs', 'description' => 'Negative excluded fee', 'amount' => -1]],
            [['section' => 'other_costs', 'description' => 'Over-precise excluded fee', 'amount' => '1.001']],
        ] as $rows) {
            $this->postJson('/portal/education/cost-breakdowns', [...$data, 'rows' => $rows])->assertUnprocessable();
        }
        $rows = [
            ['description' => 'Legacy included fee', 'amount' => 100, 'exclude_from_total' => true],
            ['section' => 'other_costs', 'description' => 'Unknown tuition', 'amount' => null],
            ['section' => 'other_costs', 'description' => 'Explicit zero cost', 'amount' => 0],
        ];
        $this->postJson('/portal/education/cost-breakdowns', [...$data, 'rows' => $rows, 'action' => 'save'])->assertCreated();
        $snapshot = LeadDocument::sole()->notes['cost_breakdown_snapshot'];
        $this->assertEquals(100, $snapshot['totals']['amount']);
        $this->assertNull($snapshot['rows'][1]['amount']);
        $this->assertNull($snapshot['rows'][1]['nzd']);
        $this->assertEquals(0, $snapshot['rows'][2]['amount']);
        $this->assertArrayNotHasKey('exclude_from_total', $snapshot['rows'][0]);
    }

    public function test_country_defaults_follow_residence_and_exclude_immigration_only_records(): void
    {
        foreach (['Philippines', 'NZ', 'India', '', 'Unknown country'] as $country) {
            $this->client(['residence_country' => $country]);
        }
        $this->client(['first_name' => 'PrivateCase', 'is_student' => false, 'is_immigration_case' => true]);
        $response = $this->getJson('/portal/education/cost-breakdowns')->assertOk();
        $clients = collect($response->json('clients'))->keyBy('country');
        $this->assertCount(5, $clients);
        $this->assertSame('philippines', $clients['Philippines']['category']);
        $this->assertSame('PHP', $clients['Philippines']['currency']);
        $this->assertSame('onshore', $clients['New Zealand']['category']);
        $this->assertSame('NZD', $clients['New Zealand']['currency']);
        $this->assertSame('offshore', $clients['India']['category']);
        $this->assertSame('INR', $clients['India']['currency']);
        $this->assertSame('', $clients['']['category']);
        $this->assertSame('', $clients['Unknown country']['currency']);
        $this->getJson('/portal/education/cost-breakdowns?q=PrivateCase')->assertOk()->assertJsonCount(0, 'clients');
    }

    public function test_preview_download_and_saved_versions_preserve_original_fees_and_rate_snapshot(): void
    {
        $lead = $this->client();
        $before = $lead->fresh()->getRawOriginal();
        $data = $this->data($lead);
        $this->postJson('/portal/education/cost-breakdowns', $data)->assertOk()->assertJsonPath('html', fn ($html) => str_contains($html, 'Couple with child') && str_contains($html, '150.00') && str_contains($html, '2026-11-17'));
        $this->assertDatabaseCount('lead_documents', 0);
        $pdf = $this->postJson('/portal/education/cost-breakdowns', [...$data, 'action' => 'download'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $text = (new Parser)->parseContent($pdf)->getText();
        foreach (['Synthetic Client', '5,250.00', '150.00', 'PHP', 'NZD', 'Frankfurter', 'Couple with child', 'Custom school enrolment'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        if (getenv('EDUCATION_COST_PDF_SAMPLE')) {
            file_put_contents(getenv('EDUCATION_COST_PDF_SAMPLE'), $pdf);
        }
        $this->postJson('/portal/education/cost-breakdowns', [...$data, 'action' => 'save'])->assertCreated();
        $doc = LeadDocument::sole();
        $original = Storage::disk('local')->get($doc->file_path);
        $snapshot = $doc->notes['cost_breakdown_snapshot'];
        $this->assertSame(3500, $snapshot['rows'][0]['amount']);
        $this->assertEquals(100, $snapshot['rows'][0]['nzd']);
        $this->assertSame('2026-11-17', $snapshot['exchange']['rates']['PHP']['date']);
        $data['rows'][0]['amount'] = '7000.00';
        $this->postJson('/portal/education/cost-breakdowns', [...$data, 'action' => 'save'])->assertCreated();
        $this->assertDatabaseCount('lead_documents', 2);
        $this->assertSame($original, Storage::disk('local')->get($doc->file_path));
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        $this->get('/admin/documents/'.$doc->id.'/download')->assertDownload();
        $other = $this->client(['first_name' => 'Other']);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]))->get('/admin/documents/'.$doc->id.'/download')->assertForbidden();
        Mail::assertNothingOutgoing();
        Http::assertSentCount(1);
    }

    public function test_invalid_inputs_and_changed_expired_rates_cannot_generate_documents(): void
    {
        $data = $this->data($this->client());
        foreach ([['rows' => [['description' => '', 'amount' => '-1']]], ['family' => 'unknown'], ['rows' => [['description' => 'Too precise', 'amount' => '1.001']]], ['rate_token' => 'tampered'], ['target_currency' => 'USD']] as $changes) {
            $this->postJson('/portal/education/cost-breakdowns', array_replace($data, $changes))->assertUnprocessable();
        }
        $this->travel(61)->minutes();
        $this->postJson('/portal/education/cost-breakdowns', $data)->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->assertDatabaseCount('lead_documents', 0);
    }

    public function test_unavailable_stale_or_malformed_rates_fail_without_fake_conversion(): void
    {
        foreach ([Http::response([], 503), Http::response(['date' => '2026-01-01', 'base' => 'NZD', 'quote' => 'PHP', 'rate' => 35]), Http::response(['date' => '2026-11-17', 'base' => 'NZD', 'quote' => 'PHP', 'rate' => -1])] as $response) {
            Cache::flush();
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => $response]);
            $this->getJson('/portal/education/cost-breakdowns/rates?source=NZD&target=PHP')->assertUnprocessable()->assertJsonValidationErrors('rate');
        }
        $this->getJson('/portal/education/cost-breakdowns/rates?source=NZD&target=NZD')->assertOk()->assertJsonPath('rates.NZD.rate', 1);
    }

    public function test_nzd_source_other_country_and_long_itemized_pdf_totals(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['date' => '2026-11-17', 'base' => 'NZD', 'quote' => 'INR', 'rate' => 50])]);
        $lead = $this->client(['residence_country' => 'India']);
        $quote = app(CostBreakdownRates::class)->quote('NZD', 'INR');
        $data = ['lead_id' => $lead->id, 'country' => 'India', 'category' => 'offshore', 'family' => 'single',
            'source_currency' => 'NZD', 'target_currency' => 'INR', 'rate_token' => $quote['token'],
            'rows' => array_fill(0, 30, ['description' => str_repeat('Supporting study and travel cost details. ', 6), 'amount' => '0.03']), 'action' => 'download'];
        $pdf = $this->postJson('/portal/education/cost-breakdowns', $data)->assertOk()->getContent();
        $text = (new Parser)->parseContent($pdf)->getText();
        foreach (['India', 'Offshore', 'Single', 'INR', '45.00', '0.90'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        if (getenv('EDUCATION_COST_PDF_SAMPLE')) {
            file_put_contents(dirname(getenv('EDUCATION_COST_PDF_SAMPLE')).'/sample-long-breakdown.pdf', $pdf);
        }
        $data['action'] = 'preview';
        $data['rows'] = [['description' => '<script>Not executable</script>', 'amount' => '1.01']];
        foreach (['single', 'couple', 'couple_child'] as $family) {
            $this->postJson('/portal/education/cost-breakdowns', [...$data, 'family' => $family])->assertOk()
                ->assertJsonPath('html', fn ($html) => str_contains($html, '&lt;script&gt;') && str_contains($html, '50.50'));
        }
    }

    public function test_unrelated_roles_and_immigration_only_clients_cannot_use_education_builder(): void
    {
        $private = $this->client(['is_student' => false, 'is_immigration_case' => true]);
        $this->postJson('/portal/education/cost-breakdowns', $this->data($private))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'sales']))->getJson('/portal/education/cost-breakdowns')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'lead']))->getJson('/portal/education/cost-breakdowns/rates?source=NZD&target=PHP')->assertForbidden();
    }
}
