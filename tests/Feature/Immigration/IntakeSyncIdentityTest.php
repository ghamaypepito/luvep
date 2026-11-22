<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntakeSyncIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::fake('local');
    }

    public static function publicForms(): array
    {
        return array_combine(
            ['work', 'student', 'visitor', 'family', 'resident'],
            array_map(fn ($type) => ["/{$type}-interest"], ['work', 'student', 'visitor', 'family', 'resident']),
        );
    }

    private function existingCase(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Anika', 'last_name' => 'Synthetic',
            'email' => 'shared171@example.test', 'dob' => '1992-04-11',
            'phone' => 'ORIGINAL', 'passport_number' => 'ORIGINAL-PASSPORT',
            'passport_expiry' => '2030-01-01', 'citizenship' => 'Original',
            'is_immigration_case' => true,
        ], $overrides))->fresh();
    }

    private function submit(string $url, array $overrides = []): void
    {
        $this->post($url, array_merge([
            'first_name' => 'Anika', 'family_name' => 'Synthetic', 'last_name' => 'Synthetic',
            'email' => 'shared171@example.test', 'dob' => '1992-04-11',
            'phone' => '+64 21 171 999', 'passport_number' => 'NEW-PASSPORT',
            'passport_expiry' => '2031-01-01', 'country_of_citizenship' => 'New',
            'declaration_accepted' => true, 'terms_accepted' => true,
            'nationality' => 'New', 'issuing_country' => 'New',
            'current_visa_type' => 'AEWV', 'current_visa_expiry' => '2030-01-01',
            'nz_arrival_date' => '2025-01-01', 'job_title' => 'Test',
            'employment_start' => '2025-02-01', 'employment_type' => 'Full time',
            'hourly_rate' => 40, 'highest_qualification' => 'Degree',
            'nz_skilled_years' => 1, 'total_skilled_years' => 3,
            'english_evidence' => 'IELTS', 'english_test_score' => '7',
            'english_test_date' => '2026-01-01', 'include_family' => 'No',
        ], $overrides))->assertRedirect()->assertSessionHasNoErrors();
    }

    #[DataProvider('publicForms')]
    public function test_ambiguous_cases_are_all_unchanged_even_when_one_name_matches(string $url): void
    {
        $first = $this->existingCase();
        $second = $this->existingCase(['first_name' => 'Beatriz', 'phone' => 'SECOND']);
        $before = [$first->getRawOriginal(), $second->getRawOriginal()];

        $this->submit($url, ['first_name' => 'Beatriz']);

        $this->assertSame($before, [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()]);
    }

    #[DataProvider('publicForms')]
    public function test_unique_case_with_a_conflicting_applicant_is_unchanged(string $url): void
    {
        $case = $this->existingCase();
        $before = $case->getRawOriginal();

        $this->submit($url, ['first_name' => 'Beatriz']);

        $this->assertSame($before, $case->fresh()->getRawOriginal());
    }

    #[DataProvider('publicForms')]
    public function test_unique_matching_applicant_still_syncs_and_preserves_staff_visa(string $url): void
    {
        $case = $this->existingCase(['inz_visa_type' => 'Student Visa (Fee Paying)']);
        $unrelated = $this->existingCase(['email' => 'other171@example.test']);
        $before = $unrelated->getRawOriginal();

        $this->submit($url);

        $this->assertSame('+64 21 171 999', $case->fresh()->phone);
        $this->assertSame('Student Visa (Fee Paying)', $case->fresh()->inz_visa_type);
        $this->assertSame($before, $unrelated->fresh()->getRawOriginal());
    }

    public function test_matching_passport_and_dob_allow_a_name_change_without_changing_the_case_name(): void
    {
        $case = $this->existingCase();

        $this->submit('/resident-interest', [
            'first_name' => 'New name', 'passport_number' => 'ORIGINAL-PASSPORT',
        ]);

        $this->assertSame('+64 21 171 999', $case->fresh()->phone);
        $this->assertSame('Anika', $case->fresh()->first_name);
    }

    public function test_even_matching_passport_and_name_cannot_select_between_duplicate_cases(): void
    {
        $first = $this->existingCase();
        $second = $this->existingCase();
        $before = [$first->getRawOriginal(), $second->getRawOriginal()];

        $this->submit('/resident-interest', ['passport_number' => 'ORIGINAL-PASSPORT']);

        $this->assertSame($before, [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()]);
    }

    #[DataProvider('publicForms')]
    public function test_handoff_only_case_also_makes_the_identity_ambiguous(string $url): void
    {
        $first = $this->existingCase();
        $handoff = $this->existingCase(['is_immigration_case' => false, 'education_stage' => 'Endorsed to Immigration']);
        $before = [$first->getRawOriginal(), $handoff->getRawOriginal()];

        $this->submit($url);

        $this->assertSame($before, [$first->fresh()->getRawOriginal(), $handoff->fresh()->getRawOriginal()]);
    }

    #[DataProvider('publicForms')]
    public function test_legacy_email_whitespace_does_not_hide_an_ambiguous_case(string $url): void
    {
        $first = $this->existingCase();
        $second = $this->existingCase(['email' => " \tSHARED171@example.test\r\n"]);
        $before = [$first->getRawOriginal(), $second->getRawOriginal()];

        $this->submit($url);

        $this->assertSame($before, [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()]);
    }

    #[DataProvider('publicForms')]
    public function test_a_sole_handoff_only_case_is_not_newly_eligible_for_public_writes(string $url): void
    {
        $case = $this->existingCase(['is_immigration_case' => false, 'education_stage' => 'Endorsed to Immigration']);
        $before = $case->getRawOriginal();

        $this->submit($url);

        $this->assertSame($before, $case->fresh()->getRawOriginal());
    }
}
