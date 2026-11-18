<?php

namespace Tests\Feature\Immigration;

use App\Models\VisaType;
use Database\Seeders\VisaChecklistSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the VisaChecklistSeeder behaviour:
 *   - seeds the 5 visa types that exist in the DB with the right counts
 *   - skips the 5 "future" visa types (STRV/WTRV/PRV/DCRV/SVDC) with a
 *     warning rather than erroring
 *   - is idempotent (re-running overwrites with identical data)
 *   - writes labels in the canonical "Section · Item Name" shape that
 *     the /track upload modal parses into section groups
 */
class VisaChecklistSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Per-visa item counts after the documents-only strip. Items captured
     * by Lead columns (full_name/mobile_whatsapp/email across all visas,
     * employer_phone/_email on AEWV, the 6 PRV "Confirmations" items)
     * were removed and now live on the lead row itself.
     */
    private const EXPECTED_COUNTS = [
        'WORK_AEWV' => 17,
        'STUDENT' => 32,
        'POST_STUDY_WORK' => 16,
        'PARTNER_WORK' => 22,
        'PARTNER_RES' => 22,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the 5 visa types the seeder is meant to populate. The other
        // 5 codes (STRV/WTRV/PRV/DCRV/SVDC) deliberately do NOT exist so
        // we can verify the fail-soft skip path.
        foreach (array_keys(self::EXPECTED_COUNTS) as $code) {
            VisaType::create([
                'code' => $code,
                'name' => "Test {$code}",
                'category' => 'Test',
                'short_description' => 'Test',
                'consultation_price_nzd' => 200.00,
                'consultation_duration_minutes' => 30,
                'estimated_minutes' => 10,
                'icon' => 'Globe',
                'active' => true,
            ]);
        }
    }

    public function test_student_template_keeps_one_applicant_employment_certificate_in_financial_documents(): void
    {
        $items = collect((new VisaChecklistSeeder)->templates()['student']['items']);
        $coe = $items->where('key', 'certificate_of_employment');
        $this->assertCount(1, $coe);
        $this->assertSame('Financial Documents (Applicant)', $coe->first()['category']);
    }

    public function test_partnership_templates_include_one_sponsor_passport_without_duplicate_sponsor_visas(): void
    {
        $templates = (new VisaChecklistSeeder)->templates();
        foreach (['partner_work', 'partner_res'] as $key) {
            $items = collect($templates[$key]['items']);
            $this->assertCount(1, $items->where('key', 'sponsor_passport'));
            $this->assertCount(1, $items->filter(fn ($item) => in_array($item['key'], ['sponsor_nz_visa', 'sponsor_visa'])));
        }
    }

    public function test_seeds_five_overlapping_visa_types_with_correct_counts(): void
    {
        $this->seed(VisaChecklistSeeder::class);

        foreach (self::EXPECTED_COUNTS as $code => $expected) {
            $visa = VisaType::where('code', $code)->firstOrFail();
            $this->assertIsArray($visa->checklist_items, "Checklist for {$code} not stored as array");
            $this->assertCount(
                $expected,
                $visa->checklist_items,
                "Wrong item count for {$code} — got ".count($visa->checklist_items).", expected {$expected}"
            );
        }

        // Total across the 5 overlapping visas after the strip.
        $total = collect(self::EXPECTED_COUNTS)->sum();
        $this->assertSame(109, $total, 'Sanity: per-visa counts should total 109.');
    }

    public function test_stripped_items_no_longer_appear_in_any_visa_checklist(): void
    {
        $this->seed(VisaChecklistSeeder::class);

        // The 3 always-shared items + 2 AEWV-specific + 6 PRV-specific
        // candidates that now live on the lead row instead.
        $strippedKeys = [
            // All 10 visas
            'full_name', 'mobile_whatsapp', 'email',
            // AEWV only
            'employer_phone', 'employer_email',
            // PRV only
            'residential_address', 'character_issues', 'visa_decline_history',
            'partnership_status', 'nz_presence_184_days', 'dependent_children_info',
        ];

        foreach (array_keys(self::EXPECTED_COUNTS) as $code) {
            $items = collect(VisaType::where('code', $code)->firstOrFail()->checklist_items);
            foreach ($strippedKeys as $strippedKey) {
                $this->assertNull(
                    $items->firstWhere('key', $strippedKey),
                    "Visa {$code} still contains stripped key '{$strippedKey}'."
                );
            }
        }
    }

    public function test_each_item_has_the_canonical_flat_schema(): void
    {
        $this->seed(VisaChecklistSeeder::class);

        $visa = VisaType::where('code', 'WORK_AEWV')->firstOrFail();
        $first = $visa->checklist_items[0];

        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('hint', $first);
        $this->assertArrayHasKey('required', $first);
        $this->assertIsString($first['key']);
        $this->assertIsString($first['label']);
        $this->assertIsBool($first['required']);
    }

    public function test_student_sections_use_separate_category_fields(): void
    {
        $this->seed(VisaChecklistSeeder::class);
        $items = collect(VisaType::where('code', 'STUDENT')->firstOrFail()->checklist_items);
        $sections = $items->pluck('category')->unique()->all();
        $this->assertContains('Applicant Documents', $sections);
        $this->assertContains('Financial Documents (Applicant)', $sections);
        $this->assertContains('Sponsor Documents (if sponsored)', $sections);
        foreach ($items as $item) {
            $this->assertNotEmpty($item['category']);
            $this->assertNotEmpty($item['label']);
        }
    }

    public function test_skips_missing_visa_types_without_erroring(): void
    {
        // STRV/WTRV/PRV/DCRV/SVDC don't exist in the DB; the seeder must
        // warn and skip rather than blow up. The 5 overlapping visas
        // still get their checklists.
        $this->seed(VisaChecklistSeeder::class);

        foreach (['STRV', 'WTRV', 'PRV', 'DCRV', 'SVDC'] as $missingCode) {
            $this->assertNull(
                VisaType::where('code', $missingCode)->first(),
                "Seeder must NOT create missing visa_type rows (found {$missingCode})."
            );
        }

        // And the present ones still got seeded.
        $this->assertCount(
            self::EXPECTED_COUNTS['WORK_AEWV'],
            VisaType::where('code', 'WORK_AEWV')->firstOrFail()->checklist_items
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(VisaChecklistSeeder::class);
        $firstRun = VisaType::where('code', 'WORK_AEWV')->firstOrFail()->checklist_items;

        // Run a second time.
        $this->seed(VisaChecklistSeeder::class);
        $secondRun = VisaType::where('code', 'WORK_AEWV')->firstOrFail()->checklist_items;

        $this->assertSame($firstRun, $secondRun, 'Re-seeding must yield identical checklist_items.');

        // And we still have exactly one row per code — no duplicates.
        $this->assertSame(1, VisaType::where('code', 'WORK_AEWV')->count());
    }

    public function test_required_flag_distinguishes_mandatory_from_optional_items(): void
    {
        $this->seed(VisaChecklistSeeder::class);

        $visa = VisaType::where('code', 'STUDENT')->firstOrFail();
        $items = collect($visa->checklist_items);

        $required = $items->where('required', true);
        $optional = $items->where('required', false);

        // Student Visa has both mandatory documents (passport, offer of
        // place, …) and optional ones (sponsor section, marriage cert,
        // property ownership, …). The flag must distinguish them.
        $this->assertGreaterThan(0, $required->count(), 'Expected some required items.');
        $this->assertGreaterThan(0, $optional->count(), 'Expected some optional items.');

        // Sponsor items in the Student Visa checklist are all optional.
        $sponsorItems = $items->filter(fn ($i) => str_contains($i['label'], 'Sponsor (if applicable)'));
        $this->assertTrue(
            $sponsorItems->every(fn ($i) => $i['required'] === false),
            'All sponsor items must be optional.'
        );
    }
}
