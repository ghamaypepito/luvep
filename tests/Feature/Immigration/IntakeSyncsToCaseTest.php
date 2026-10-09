<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When someone who is ALREADY an immigration case submits a public visa form
 * again, their latest personal + visa details sync into that existing case
 * instead of creating a disconnected duplicate. Identity is email + DOB, so
 * family members who share one email are NOT folded together.
 */
class IntakeSyncsToCaseTest extends TestCase
{
    use RefreshDatabase;

    private function existingCase(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Ana', 'last_name' => 'Old', 'email' => 'ana@example.com',
            'phone' => 'ORIGINAL', 'dob' => '1992-04-11', 'citizenship' => 'OldCountry',
            'is_immigration_case' => true, 'status' => 'Engaged',
        ], $overrides));
    }

    public function test_returning_case_submission_syncs_into_the_case(): void
    {
        $case = $this->existingCase();

        $this->post('/work-interest', [
            'first_name' => 'Ana', 'family_name' => 'Cruz', 'dob' => '1992-04-11',
            'email' => 'ana@example.com', 'phone' => '+64 21 999 0000',
            'country_of_citizenship' => 'Philippines', 'declaration_accepted' => true,
        ])->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('+64 21 999 0000', $case->phone);       // synced
        $this->assertSame('Cruz', $case->last_name);              // synced
        $this->assertSame('Philippines', $case->citizenship);     // synced
        $this->assertSame('Work Visa (AEWV)', $case->inz_visa_type); // synced

        // No duplicate case was created.
        $this->assertSame(1, Lead::where('is_immigration_case', true)->count());
    }

    public function test_sync_preserves_a_visa_type_staff_already_set(): void
    {
        // Staff set a specific visa type on the case. A later public submission
        // only knows the generic funnel label and must NOT switch it.
        $case = $this->existingCase(['inz_visa_type' => 'Student Visa (Fee Paying)']);

        $this->post('/work-interest', [
            'first_name' => 'Ana', 'family_name' => 'Cruz', 'dob' => '1992-04-11',
            'email' => 'ana@example.com', 'phone' => '+64 21 999 0000',
            'country_of_citizenship' => 'Philippines', 'declaration_accepted' => true,
        ])->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('+64 21 999 0000', $case->phone); // personal details still sync
        // The staff-set visa type is preserved, not overwritten by 'Work Visa (AEWV)'.
        $this->assertSame('Student Visa (Fee Paying)', $case->inz_visa_type);
    }

    public function test_family_member_sharing_the_email_is_not_folded_in(): void
    {
        // Same email, DIFFERENT date of birth → a different person (e.g. spouse).
        $case = $this->existingCase(['dob' => '1992-04-11', 'phone' => 'ORIGINAL']);

        $this->post('/work-interest', [
            'first_name' => 'Ben', 'family_name' => 'Cruz', 'dob' => '1985-01-01',
            'email' => 'ana@example.com', 'phone' => '+64 21 111 2222',
            'declaration_accepted' => true,
        ])->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('ORIGINAL', $case->phone); // untouched — not the same person
    }

    public function test_submission_from_a_non_case_email_syncs_nothing(): void
    {
        $case = $this->existingCase(['phone' => 'ORIGINAL']);

        $this->post('/work-interest', [
            'first_name' => 'Zoe', 'family_name' => 'New', 'dob' => '1990-02-02',
            'email' => 'someone-else@example.com', 'phone' => '+64 21 000 0000',
            'declaration_accepted' => true,
        ])->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('ORIGINAL', $case->phone); // untouched
    }
}
