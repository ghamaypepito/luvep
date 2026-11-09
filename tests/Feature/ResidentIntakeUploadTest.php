<?php

namespace Tests\Feature;

use App\Models\ResidentIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResidentIntakeUploadTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'terms_accepted'        => true,
            'first_name'            => 'Jane',
            'last_name'             => 'Doe',
            'dob'                   => '1990-01-01',
            'nationality'           => 'India',
            'email'                 => 'jane@example.com',
            'phone'                 => '+64211234567',
            'passport_number'       => 'A1234567',
            'passport_expiry'       => '2030-01-01',
            'issuing_country'       => 'India',
            'current_visa_type'     => 'AEWV',
            'current_visa_expiry'   => '2027-01-01',
            'nz_arrival_date'       => '2022-01-01',
            'job_title'             => 'Software Developer',
            'employment_start'      => '2022-02-01',
            'employment_type'       => 'Permanent full-time',
            'hourly_rate'           => '45.00',
            'highest_qualification' => 'bachelor',
            'nz_skilled_years'      => '2',
            'total_skilled_years'   => '7',
            'english_evidence'      => 'Passport (exempt country)',
            'include_family'        => 'No — applying alone',
            'documents'             => [
                'passport' => true, 'visa_copies' => false, 'contracts' => false,
                'payslips' => false, 'ird_summary' => false, 'education_certs' => false, 'cv' => false,
            ],
        ], $overrides);
    }

    public function test_uploaded_pdf_is_stored_and_referenced(): void
    {
        Storage::fake('local');

        $pdf = UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf');

        $response = $this->post('/resident-interest', $this->payload([
            'document_files' => ['passport' => [$pdf]],
        ]));

        $response->assertSessionHas('intake_submitted');
        $response->assertSessionHasNoErrors();

        $intake = ResidentIntake::firstOrFail();

        $this->assertIsArray($intake->document_files);
        $this->assertArrayHasKey('passport', $intake->document_files);
        $this->assertIsArray($intake->document_files['passport']);
        $this->assertCount(1, $intake->document_files['passport']);

        $path = $intake->document_files['passport'][0];
        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString("resident-intakes/{$intake->intake_id}", $path);
    }

    public function test_qualification_evidence_can_be_saved_and_reopened_without_test_results(): void
    {
        $qualification = $this->payload(['english_evidence' => 'Qualification(s)']);
        $this->post('/resident-interest', $qualification)->assertSessionHas('intake_submitted')->assertSessionHasNoErrors();

        $intake = ResidentIntake::sole();
        $this->assertSame('Qualification(s)', $intake->english_evidence);
        $intake->update(['edit_token' => 'test-qualification-edit-token']);
        $editUrl = '/resident-interest/edit/'.$intake->edit_token;

        $this->post($editUrl, $this->payload(['english_evidence' => 'IELTS']))
            ->assertSessionHasErrors(['english_test_score', 'english_test_date']);
        $this->post($editUrl, $this->payload([
            'english_evidence' => 'IELTS', 'english_test_score' => '7', 'english_test_date' => '2026-10-01',
        ]))->assertSessionHas('edited', true)->assertSessionHasNoErrors();
        $this->post($editUrl, $qualification + ['english_test_score' => '', 'english_test_date' => ''])
            ->assertSessionHas('edited', true)->assertSessionHasNoErrors();
        $this->get($editUrl)->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('editIntake.english_evidence', 'Qualification(s)')
            ->where('editIntake.english_test_score', null)
            ->where('editIntake.english_test_date', null));
    }

    public function test_multiple_pdfs_per_key_and_other_bucket_are_stored(): void
    {
        Storage::fake('local');

        $a = UploadedFile::fake()->create('passport-a.pdf', 100, 'application/pdf');
        $b = UploadedFile::fake()->create('passport-b.pdf', 120, 'application/pdf');
        $other = UploadedFile::fake()->create('reference.pdf', 80, 'application/pdf');

        $response = $this->post('/resident-interest', $this->payload([
            'document_files' => [
                'passport' => [$a, $b],
                'other'    => [$other],
            ],
        ]));

        $response->assertSessionHas('intake_submitted');
        $response->assertSessionHasNoErrors();

        $intake = ResidentIntake::firstOrFail();

        $this->assertCount(2, $intake->document_files['passport']);
        $this->assertCount(1, $intake->document_files['other']);

        foreach ($intake->document_files['passport'] as $p) {
            Storage::disk('local')->assertExists($p);
        }
        Storage::disk('local')->assertExists($intake->document_files['other'][0]);
    }

    public function test_edit_link_token_lets_applicant_update_and_append_files(): void
    {
        Storage::fake('local');

        // Initial submission: one PDF on "passport".
        $original = UploadedFile::fake()->create('passport-orig.pdf', 100, 'application/pdf');
        $this->post('/resident-interest', $this->payload([
            'document_files' => ['passport' => [$original]],
        ]))->assertSessionHas('intake_submitted');

        /** @var ResidentIntake $intake */
        $intake = ResidentIntake::firstOrFail();
        $intake->edit_token = 'tok_test_' . bin2hex(random_bytes(8));
        $intake->save();

        // Applicant opens the token-bearing edit URL and POSTs new data + extra PDFs.
        $additional = UploadedFile::fake()->create('passport-extra.pdf', 80, 'application/pdf');
        $otherDoc = UploadedFile::fake()->create('reference.pdf', 60, 'application/pdf');

        $response = $this->post("/resident-interest/edit/{$intake->edit_token}", $this->payload([
            'phone'          => '+64211999888', // changed
            'document_files' => [
                'passport' => [$additional],
                'other'    => [$otherDoc],
            ],
        ]));

        $response->assertSessionHas('success', true);
        $response->assertSessionHas('edited', true);
        $response->assertSessionHasNoErrors();

        $intake->refresh();
        $this->assertSame('+64211999888', $intake->phone);
        $this->assertCount(2, $intake->document_files['passport']); // appended, not replaced
        $this->assertCount(1, $intake->document_files['other']);
    }

    public function test_admin_can_generate_edit_link(): void
    {
        Storage::fake('local');

        $this->post('/resident-interest', $this->payload([
            'document_files' => [],
        ]))->assertSessionHas('intake_submitted');

        $intake = ResidentIntake::firstOrFail();
        $this->assertNull($intake->edit_token);

        // Authenticate as an admin to hit the protected route.
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post("/admin/immigration/resident-intakes/{$intake->id}/edit-link");

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('edit_link_intake_id', $intake->id);
        $response->assertSessionHas('edit_link_url');

        $intake->refresh();
        $this->assertNotEmpty($intake->edit_token);
        $this->assertStringContainsString('/resident-interest/edit/' . $intake->edit_token, session('edit_link_url'));
    }

    public function test_submission_without_files_still_works(): void
    {
        Storage::fake('local');

        $response = $this->post('/resident-interest', $this->payload([
            'document_files' => [],
        ]));

        $response->assertSessionHas('intake_submitted');
        $response->assertSessionHasNoErrors();

        $intake = ResidentIntake::firstOrFail();
        $this->assertNull($intake->document_files);
    }
}
