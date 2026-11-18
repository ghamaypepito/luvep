<?php

namespace Tests\Feature\Immigration;

use App\Models\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SponsorPassportMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function visa(string $code, array $items): VisaType
    {
        return VisaType::create(['code' => $code, 'name' => $code, 'category' => 'Work',
            'short_description' => 'Test', 'consultation_price_nzd' => 0, 'consultation_duration_minutes' => 30,
            'estimated_minutes' => 10, 'icon' => 'Globe', 'active' => true, 'checklist_items' => $items]);
    }

    public function test_patch_is_targeted_idempotent_and_preserves_existing_checklist_with_backup(): void
    {
        $original = [['key' => 'sponsor_visa', 'label' => 'NZ Visa of Sponsor', 'required' => true]];
        $target = $this->visa('PRSWV', $original);
        $unrelated = $this->visa('SV', $original);
        $already = $this->visa('PRAEWV', [['key' => 'custom_ppt', 'label' => 'Passport of Sponsor', 'required' => false]]);
        $migration = require database_path('migrations/2026_10_21_170000_add_partner_sponsor_passport.php');
        $migration->up();
        $migration->up();
        $this->assertCount(2, $target->fresh()->checklist_items);
        $this->assertSame($original[0], $target->fresh()->checklist_items[0]);
        $this->assertSame('sponsor_passport', $target->fresh()->checklist_items[1]['key']);
        $this->assertSame($original, $unrelated->fresh()->checklist_items);
        $this->assertCount(1, $already->fresh()->checklist_items);
        $this->assertDatabaseCount('visa_checklist_ticket_74_backups', 1);
        $this->assertSame($original, json_decode(DB::table('visa_checklist_ticket_74_backups')->value('original_checklist'), true));
        $migration->down();
        $this->assertSame($original, $target->fresh()->checklist_items);
    }

    public function test_rollback_does_not_overwrite_subsequent_catalogue_edits(): void
    {
        $visa = $this->visa('PRNZRV', [['key' => 'identity', 'label' => 'Passport']]);
        $migration = require database_path('migrations/2026_10_21_170000_add_partner_sponsor_passport.php');
        $migration->up();
        $edited = $visa->fresh()->checklist_items;
        $edited[0]['label'] = 'Staff customised passport';
        $visa->update(['checklist_items' => $edited]);
        $migration->down();
        $this->assertSame($edited, $visa->fresh()->checklist_items);
        $this->assertDatabaseCount('visa_checklist_ticket_74_backups', 1);
    }

    public function test_rollback_preserves_sponsor_slot_after_upload_even_if_case_changes_visa(): void
    {
        $visa = $this->visa('PRNZRV', [['key' => 'identity', 'label' => 'Passport']]);
        $migration = require database_path('migrations/2026_10_21_170000_add_partner_sponsor_passport.php');
        $migration->up();
        $lead = \App\Models\Lead::create(['first_name' => 'Test', 'last_name' => 'Case', 'inz_visa_type' => 'Different Visa']);
        \App\Models\LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'sponsor_passport',
            'original_name' => 'passport.pdf', 'file_path' => 'private/passport.pdf', 'mime' => 'application/pdf',
            'size' => 10, 'status' => 'Submitted', 'source' => 'upload']);
        $patched = $visa->fresh()->checklist_items;
        $migration->down();
        $this->assertSame($patched, $visa->fresh()->checklist_items);
        $this->assertDatabaseCount('lead_documents', 1);
    }

    public function test_existing_outcome_sections_gain_optional_interim_and_rfi_slots_once(): void
    {
        $original = [['key' => 'approved_visa', 'label' => 'Visa Approved', 'category' => 'Visa Outcome', 'required' => false]];
        $visa = $this->visa('PRSWV', $original);
        $unrelated = $this->visa('AEWV', [['key' => 'passport', 'label' => 'Passport', 'category' => 'Identity']]);
        $migration = require database_path('migrations/2026_10_21_180000_add_interim_and_rfi_outcome_slots.php');
        $migration->up();
        $migration->up();
        $items = collect($visa->fresh()->checklist_items);
        $this->assertCount(3, $items);
        $this->assertFalse($items->firstWhere('key', 'interim_visa')['required']);
        $this->assertSame('Visa Outcome', $items->firstWhere('key', 'request_for_information')['category']);
        $this->assertCount(1, $unrelated->fresh()->checklist_items);
        $migration->down();
        $this->assertSame($original, $visa->fresh()->checklist_items);
    }
}
