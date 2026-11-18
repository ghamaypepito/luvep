<?php

namespace Tests\Feature\Immigration;

use App\Models\Assessment;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\ResidentIntake;
use App\Models\User;
use App\Models\VisaType;
use App\Models\WorkIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Build 11.D Phase 1 — Inertia prop loading + intake resolution.
 *
 * Converted cases use assessment_id; legacy cases can match by email and
 * applicant name. A shared email must not expose another person's intake.
 * Sales-converted cases without an assessment load with intake=null.
 */
class CaseProfileDataLoadingTest extends TestCase
{
    use RefreshDatabase;

    private function adminActing(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        return $admin;
    }

    private function makeCase(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'first_name'          => 'Pat',
            'last_name'           => 'Patel',
            'email'               => 'pat.patel@example.test',
            'phone'               => '+64 21 555 0100',
            'is_immigration_case' => true,
            'inz_visa_type'       => 'Skilled Migrant Category',
            'inz_status'          => 'In Progress',
        ], $attrs));
    }

    private function makeVisaType(string $name): VisaType
    {
        return VisaType::create([
            'code'                   => strtolower(str_replace(' ', '_', $name)),
            'name'                   => $name,
            'category'               => 'Resident',
            'consultation_price_nzd' => 250,
            'active'                 => true,
        ]);
    }

    public function test_sales_converted_case_loads_with_null_intake(): void
    {
        $this->adminActing();
        $case = $this->makeCase();

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/immigration/CaseProfile')
                ->where('lead.id', $case->id)
                ->where('lead.email', 'pat.patel@example.test')
                ->where('lead.is_assessment_converted', false)
                ->where('intake', null)
            );
    }

    public function test_legacy_assessment_case_loads_intake_data_for_matching_name_and_email(): void
    {
        $this->adminActing();
        $visaType = $this->makeVisaType('Skilled Migrant Category');
        $case = $this->makeCase([
            'first_name' => 'Assessment',
            'last_name' => 'Applicant',
            'email' => 'assessment.applicant@example.test',
        ]);

        $intake = ResidentIntake::create([
            'intake_id'             => 'RI-TEST-1',
            'first_name'            => 'Assessment',
            'last_name'             => 'Applicant',
            'email'                 => 'assessment.applicant@example.test',
            'phone'                 => '+64 21 555 0200',
            'dob'                   => '1990-01-01',
            'nationality'           => 'India',
            'passport_number'       => 'X1234567',
            'passport_expiry'       => '2030-01-01',
            'issuing_country'       => 'India',
            'current_visa_type'     => 'AEWV',
            'current_visa_expiry'   => '2027-01-01',
            'nz_arrival_date'       => '2022-01-01',
            'job_title'             => 'Software Developer',
            'employment_start'      => '2022-02-01',
            'employment_type'       => 'Permanent full-time',
            'hourly_rate'           => 45.00,
            'highest_qualification' => 'bachelor',
            'nz_skilled_years'      => 2,
            'total_skilled_years'   => 7,
            'english_evidence'      => 'Passport (exempt country)',
            'include_family'        => 'No — applying alone',
            'status'                => 'Engaged',
        ]);

        $assessment = Assessment::createForIntake($intake, $visaType);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intake.type', 'resident')
                ->where('intake.data.intake_id', 'RI-TEST-1')
                ->where('intake.data.assessment_id', $assessment->id)
                ->where('lead.is_assessment_converted', true)
            );
    }

    public function test_work_intake_resolves_with_correct_type(): void
    {
        $this->adminActing();
        $visaType = $this->makeVisaType('Accredited Employer Work Visa');
        $case = $this->makeCase([
            'first_name' => 'Work',
            'last_name' => 'Applicant',
            'email'         => 'work.applicant@example.test',
            'inz_visa_type' => 'Accredited Employer Work Visa',
        ]);

        $intake = WorkIntake::create([
            'intake_id'              => 'WI-TEST-1',
            'first_name'             => 'Work',
            'family_name'            => 'Applicant',
            'email'                  => 'work.applicant@example.test',
            'phone'                  => '+64 21 555 0300',
            'dob'                    => '1990-01-01',
            'country_of_citizenship' => 'India',
            'status'                 => 'Engaged',
        ]);

        $assessment = Assessment::createForIntake($intake, $visaType);
        $case->update(['assessment_id' => $assessment->id]);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intake.type', 'work')
                ->where('intake.data.intake_id', 'WI-TEST-1')
                ->where('intake.data.assessment_id', $assessment->id)
                ->where('lead.is_assessment_converted', true)
            );
    }

    public function test_shared_email_does_not_load_another_applicants_intake(): void
    {
        $this->adminActing();
        $visaType = $this->makeVisaType('Accredited Employer Work Visa');
        $case = $this->makeCase(['email' => 'shared@example.test']);
        $otherIntake = WorkIntake::create([
            'intake_id' => 'WI-OTHER',
            'first_name' => 'Other',
            'family_name' => 'Applicant',
            'email' => 'shared@example.test',
            'status' => 'Engaged',
        ]);
        Assessment::createForIntake($otherIntake, $visaType);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lead.id', $case->id)
                ->where('intake', null)
                ->where('vif', null)
            );
    }

    public function test_documents_load_for_the_case(): void
    {
        $this->adminActing();
        $case = $this->makeCase();

        LeadDocument::create([
            'lead_id'       => $case->id,
            'checklist_key' => 'passport',
            'original_name' => 'passport.pdf',
            'file_path'     => 'docs/passport.pdf',
            'mime'          => 'application/pdf',
            'size'          => 12345,
            'status'        => LeadDocument::STATUS_APPROVED,
            'source'        => LeadDocument::SOURCE_UPLOAD,
        ]);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents', 1)
                ->where('documents.0.original_name', 'passport.pdf')
                ->where('documents.0.status', LeadDocument::STATUS_APPROVED)
            );
    }

    public function test_checklist_loads_from_visa_type(): void
    {
        $this->adminActing();
        VisaType::create([
            'code'                   => 'smc_resident',
            'name'                   => 'Skilled Migrant Category',
            'category'               => 'Resident',
            'consultation_price_nzd' => 250,
            'active'                 => true,
            'checklist_items'        => [
                ['key' => 'passport', 'label' => 'Passport', 'required' => true],
                ['key' => 'ielts',    'label' => 'IELTS Certificate', 'required' => true],
            ],
        ]);

        $case = $this->makeCase();

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checklist.source', 'visa_type')
                ->where('checklist.visa', 'Skilled Migrant Category')
                ->has('checklist.items', 3)
                ->where('checklist.items.0.key', 'svf')
                ->where('checklist.items.0.required', true)
                ->where('checklist.items.1.key', 'passport')
                ->where('checklist.items.2.key', 'ielts')
            );
    }

    public function test_checklist_keeps_required_universal_form_when_no_visa_type_match(): void
    {
        $this->adminActing();
        $case = $this->makeCase(['inz_visa_type' => 'Made Up Visa']);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checklist.source', 'none')
                ->has('checklist.items', 1)
                ->where('checklist.items.0.key', 'svf')
                ->where('checklist.items.0.label', 'Visa Information Form')
                ->where('checklist.items.0.required', true)
            );
    }

    public function test_communications_load_from_message_logs(): void
    {
        $this->adminActing();
        $case = $this->makeCase(['email' => 'comms.test@example.test']);

        DB::table('message_logs')->insert([
            'channel'           => 'email',
            'recipient_type'    => 'lead',
            'recipient_id'      => $case->id,
            'recipient_address' => $case->email,
            'subject'           => 'Welcome to ePathways',
            'body'              => 'Hello Pat, welcome!',
            'status'            => 'sent',
            'sent_at'           => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('communications', 1)
                ->where('communications.0.subject', 'Welcome to ePathways')
                ->where('communications.0.status', 'sent')
            );
    }

    public function test_notes_load_for_the_case(): void
    {
        $this->adminActing();
        $case = $this->makeCase();
        LeadNote::create([
            'lead_id' => $case->id,
            'body'    => 'Initial consultation booked for next week.',
        ]);

        $this->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notes', 1)
                ->where('notes.0.body', 'Initial consultation booked for next week.')
            );
    }
}
