<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Nothing happens when I submit" — prove each public visa intake actually
 * accepts a valid submission end to end: no 500, no silent 422, and the
 * `intake_submitted` flash the success modal waits on is set. Also covers the
 * silent server-side draft endpoint the forms auto-save to.
 */
class VisaIntakeSubmitSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function base(): array
    {
        return [
            'first_name' => 'Ana', 'family_name' => 'Cruz', 'last_name' => 'Cruz',
            'dob' => '1992-04-11', 'email' => 'ana.cruz@example.test', 'phone' => '+64 21 555 0000',
        ];
    }

    public function test_work_intake_submits(): void
    {
        $res = $this->post('/work-interest', $this->base() + ['declaration_accepted' => true]);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();
        $this->assertSame('Work Visa (AEWV)', session('intake_submitted'));
    }

    public function test_student_intake_submits(): void
    {
        // Student + Visitor additionally require an accepted declaration.
        $res = $this->post('/student-interest', $this->base() + ['declaration_accepted' => true]);

        $res->assertSessionHasNoErrors();
        $this->assertSame('Student Visa', session('intake_submitted'));
    }

    public function test_visitor_intake_submits(): void
    {
        $res = $this->post('/visitor-interest', $this->base() + ['declaration_accepted' => true]);

        $res->assertSessionHasNoErrors();
        $this->assertSame('Visitor Visa (GVV)', session('intake_submitted'));
    }

    public function test_family_intake_submits(): void
    {
        $res = $this->post('/family-interest', $this->base());

        $res->assertSessionHasNoErrors();
        $this->assertSame('Family Visa (Partner / Child)', session('intake_submitted'));
    }

    public function test_resident_intake_submits(): void
    {
        $payload = $this->base() + [
            'terms_accepted' => true,
            'nationality' => 'Filipino',
            'passport_number' => 'P1234567', 'passport_expiry' => '2031-01-01', 'issuing_country' => 'Philippines',
            'current_visa_type' => 'AEWV', 'current_visa_expiry' => '2027-01-01', 'nz_arrival_date' => '2023-06-01',
            'job_title' => 'Chef', 'employment_start' => '2023-07-01', 'employment_type' => 'Full-time', 'hourly_rate' => 32.5,
            'highest_qualification' => "Bachelor's Degree",
            'nz_skilled_years' => 2, 'total_skilled_years' => 5,
            'english_evidence' => 'Recognised employment', 'include_family' => 'No',
        ];

        $res = $this->post('/resident-interest', $payload);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();
    }

    public function test_family_intake_submits_even_when_family_visa_type_is_soft_deleted(): void
    {
        // Reproduces the prod failure: an admin soft-deleted the FAMILY visa
        // type. A trashed row still holds the unique `code`, so firstOrCreate
        // couldn't see it yet collided on insert (SQLSTATE 1062) — every Family
        // submit 500'd. The controller must find it withTrashed and restore it.
        $type = \App\Models\VisaType::create([
            'code' => 'FAMILY', 'name' => 'Family Visa (Partner / Child)',
            'category' => 'Partnership', 'active' => true,
        ]);
        $type->delete(); // soft delete
        $this->assertSoftDeleted('visa_types', ['code' => 'FAMILY']);

        $res = $this->post('/family-interest', $this->base());

        $res->assertSessionHasNoErrors();
        $this->assertSame('Family Visa (Partner / Child)', session('intake_submitted'));
        // The type is restored, and there is still exactly one FAMILY row.
        $this->assertFalse($type->fresh()->trashed());
        $this->assertSame(1, \App\Models\VisaType::withTrashed()->where('code', 'FAMILY')->count());
    }

    public function test_resident_intake_saves_new_processing_questions(): void
    {
        $payload = $this->base() + [
            'terms_accepted' => true,
            'nationality' => 'Filipino',
            'passport_number' => 'P1234567', 'passport_expiry' => '2031-01-01', 'issuing_country' => 'Philippines',
            'current_visa_type' => 'AEWV', 'current_visa_expiry' => '2027-01-01', 'nz_arrival_date' => '2023-06-01',
            'job_title' => 'Chef', 'employer_name' => 'Acme Ltd',
            'employment_start' => '2023-07-01', 'employment_type' => 'Full-time', 'hourly_rate' => 32.5,
            'highest_qualification' => "Bachelor's Degree",
            'nz_skilled_years' => 2, 'total_skilled_years' => 5,
            'english_evidence' => 'Recognised employment', 'include_family' => 'No',
            // The new processing questions.
            'birth_certificate_available' => 'No', 'birth_certificate_reason' => 'Lost in a fire',
            'face_image_consent' => true,
            'medical_cert_recent' => 'Yes', 'medical_cert_submitted_last' => 'Yes',
            'medical_cert_type' => 'X-Ray Medical Examination', 'police_certificate_available' => 'Yes',
        ];

        $this->post('/resident-interest', $payload)->assertSessionHasNoErrors();

        $row = \App\Models\ResidentIntake::latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('No', $row->birth_certificate_available);
        $this->assertSame('Lost in a fire', $row->birth_certificate_reason);
        $this->assertTrue((bool) $row->face_image_consent);
        $this->assertSame('Acme Ltd', $row->employer_name);
        $this->assertSame('Yes', $row->medical_cert_recent);
        $this->assertSame('X-Ray Medical Examination', $row->medical_cert_type);
        $this->assertSame('Yes', $row->police_certificate_available);
    }

    public function test_work_intake_saves_new_processing_questions(): void
    {
        $this->post('/work-interest', $this->base() + [
            'declaration_accepted' => true,
            'birth_certificate_available' => 'Yes', 'face_image_consent' => true,
            'medical_cert_submitted_last' => 'Yes', 'medical_cert_type' => 'General Medical',
            'police_certificate_available' => 'No',
        ])->assertSessionHasNoErrors();

        $row = \App\Models\WorkIntake::latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('Yes', $row->birth_certificate_available);
        $this->assertTrue((bool) $row->face_image_consent);
        $this->assertSame('General Medical', $row->medical_cert_type);
        $this->assertSame('No', $row->police_certificate_available);
    }

    public function test_birth_certificate_reason_is_required_when_not_available(): void
    {
        $res = $this->post('/work-interest', $this->base() + [
            'declaration_accepted' => true,
            'birth_certificate_available' => 'No', // no reason given
        ]);

        $res->assertSessionHasErrors('birth_certificate_reason');
    }

    public function test_medical_type_is_required_when_submitted_last(): void
    {
        $res = $this->post('/work-interest', $this->base() + [
            'declaration_accepted' => true,
            'medical_cert_submitted_last' => 'Yes', // no type given
        ]);

        $res->assertSessionHasErrors('medical_cert_type');
    }

    /** @dataProvider draftVisas */
    public function test_draft_endpoint_saves(string $visa): void
    {
        $res = $this->postJson("/visa-interest/{$visa}/draft", [
            'first_name' => 'Ana', 'email' => 'ana.draft@example.test',
        ]);

        $res->assertOk();
        $res->assertJson(['draft_saved' => true]);
    }

    public static function draftVisas(): array
    {
        return [['resident'], ['work'], ['student'], ['visitor'], ['family']];
    }
}
