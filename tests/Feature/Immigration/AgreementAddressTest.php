<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Services\Immigration\EngagementDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_written_agreements_use_the_requested_company_address(): void
    {
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Applicant', 'is_immigration_case' => true]);
        $html = app(EngagementDocumentGenerator::class)->renderHtml($lead, 'written_agreement');
        $this->assertStringContainsString('15 Mercari Way, Albany, Auckland 0632, New Zealand</td>', $html);
        $this->assertStringNotContainsString('4/15L Mercari Way', $html);
        $this->assertStringNotContainsString('21 Vazey Way', $html);
    }
}
