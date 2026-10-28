<?php

namespace Tests\Feature\Immigration;

use App\Models\Assessment;
use App\Models\Lead;
use App\Models\ResidentIntake;
use App\Models\StudentIntake;
use App\Models\User;
use App\Models\VisaType;
use App\Models\VisitorIntake;
use App\Models\WorkIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase A + B + D coverage — the public visa-interest intake → Assessment
 * → Cases pipeline. Test cases are numbered to match the build spec
 * (1–22). Each visa type uses minimal valid payloads to keep the test
 * setup focused on the bridge behaviour rather than form validation.
 */
class AssessmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVisaTypes();
    }

    // ─── Seed helpers ────────────────────────────────────────────────

    private function seedVisaTypes(): void
    {
        // Mirror VisaTypeSeeder so IntakeVisaTypeMap can resolve.
        foreach ([
            ['code' => 'SMC',         'name' => 'Skilled Migrant Resident Visa', 'category' => 'Resident'],
            ['code' => 'WORK_AEWV',   'name' => 'Accredited Employer Work Visa', 'category' => 'Work'],
            ['code' => 'STUDENT',     'name' => 'Student Visa',                  'category' => 'Student'],
            ['code' => 'VISITOR',     'name' => 'Visitor Visa',                  'category' => 'Visitor'],
        ] as $row) {
            VisaType::create(array_merge($row, [
                'short_description'             => 'Test',
                'consultation_price_nzd'        => 200.00,
                'consultation_duration_minutes' => 30,
                'estimated_minutes'             => 10,
                'icon'                          => 'Globe',
                'active'                        => true,
            ]));
        }
    }

    // ─── Payload helpers ─────────────────────────────────────────────

    private function residentPayload(array $o = []): array
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
        ], $o);
    }

    private function workPayload(array $o = []): array
    {
        return array_merge([
            'first_name'            => 'Bob',
            'family_name'           => 'Builder',
            'email'                 => 'bob@example.com',
            'phone'                 => '+64211234999',
            'dob'                   => '1988-04-04',
            'declaration_accepted'  => true,
        ], $o);
    }

    private function studentPayload(array $o = []): array
    {
        return array_merge([
            'first_name'            => 'Sara',
            'family_name'           => 'Studious',
            'email'                 => 'sara@example.com',
            'phone'                 => '+64211234888',
            'dob'                   => '2002-08-08',
            'declaration_accepted'  => true,
        ], $o);
    }

    private function visitorPayload(array $o = []): array
    {
        return array_merge([
            'first_name'            => 'Vince',
            'family_name'           => 'Visitor',
            'email'                 => 'vince@example.com',
            'phone'                 => '+64211234777',
            'dob'                   => '1995-09-09',
            'declaration_accepted'  => true,
        ], $o);
    }

    // ─── 1–4 : Each intake form submission creates a paired Assessment ───

    public function test_01_resident_intake_submission_creates_paired_assessment(): void
    {
        $this->post('/resident-interest', $this->residentPayload())->assertRedirect();

        $intake = ResidentIntake::firstOrFail();
        $assessment = Assessment::query()
            ->where('intakeable_type', ResidentIntake::class)
            ->where('intakeable_id', $intake->id)
            ->firstOrFail();

        $this->assertNotEmpty($assessment->token);
    }

    public function test_02_work_intake_submission_creates_paired_assessment(): void
    {
        $this->post('/work-interest', $this->workPayload())->assertRedirect();

        $intake = WorkIntake::firstOrFail();
        $this->assertDatabaseHas('assessments', [
            'intakeable_type' => WorkIntake::class,
            'intakeable_id'   => $intake->id,
        ]);
    }

    public function test_03_student_intake_submission_creates_paired_assessment(): void
    {
        $this->post('/student-interest', $this->studentPayload())->assertRedirect();

        $intake = StudentIntake::firstOrFail();
        $this->assertDatabaseHas('assessments', [
            'intakeable_type' => StudentIntake::class,
            'intakeable_id'   => $intake->id,
        ]);
    }

    public function test_04_visitor_intake_submission_creates_paired_assessment(): void
    {
        $this->post('/visitor-interest', $this->visitorPayload())->assertRedirect();

        $intake = VisitorIntake::firstOrFail();
        $this->assertDatabaseHas('assessments', [
            'intakeable_type' => VisitorIntake::class,
            'intakeable_id'   => $intake->id,
        ]);
    }

    // ─── 5 : visa_type_id matches the resolved code ──────────────────

    public function test_05_assessment_has_correct_visa_type_id_per_intake_class(): void
    {
        $this->post('/resident-interest', $this->residentPayload())->assertRedirect();
        $this->post('/work-interest',     $this->workPayload())->assertRedirect();
        $this->post('/student-interest',  $this->studentPayload())->assertRedirect();
        $this->post('/visitor-interest',  $this->visitorPayload())->assertRedirect();

        $smc      = VisaType::where('code', 'SMC')->firstOrFail();
        $aewv     = VisaType::where('code', 'WORK_AEWV')->firstOrFail();
        $student  = VisaType::where('code', 'STUDENT')->firstOrFail();
        $visitor  = VisaType::where('code', 'VISITOR')->firstOrFail();

        $this->assertSame($smc->id,     Assessment::where('intakeable_type', ResidentIntake::class)->firstOrFail()->visa_type_id);
        $this->assertSame($aewv->id,    Assessment::where('intakeable_type', WorkIntake::class)->firstOrFail()->visa_type_id);
        $this->assertSame($student->id, Assessment::where('intakeable_type', StudentIntake::class)->firstOrFail()->visa_type_id);
        $this->assertSame($visitor->id, Assessment::where('intakeable_type', VisitorIntake::class)->firstOrFail()->visa_type_id);
    }

    // ─── 6 : applicant name/email snapshot from intake ───────────────

    public function test_06_assessment_snapshots_applicant_identity_from_intake(): void
    {
        $this->post('/resident-interest', $this->residentPayload([
            'first_name' => 'Maya', 'last_name' => 'Patel', 'email' => 'maya@p.com', 'phone' => '+64211111000',
        ]))->assertRedirect();

        $a = Assessment::firstOrFail();
        $this->assertSame('Maya',         $a->applicant_first_name);
        $this->assertSame('Patel',        $a->applicant_last_name);
        $this->assertSame('maya@p.com',   $a->applicant_email);
        $this->assertSame('+64211111000', $a->applicant_phone);

        // Work intake uses family_name — confirm the snapshot still
        // lands in applicant_last_name.
        $this->post('/work-interest', $this->workPayload([
            'first_name' => 'Lee', 'family_name' => 'Wong', 'email' => 'lee@w.com',
        ]))->assertRedirect();
        $a2 = Assessment::where('intakeable_type', WorkIntake::class)->firstOrFail();
        $this->assertSame('Lee',       $a2->applicant_first_name);
        $this->assertSame('Wong',      $a2->applicant_last_name);
        $this->assertSame('lee@w.com', $a2->applicant_email);
    }

    // ─── 7 : status defaults ─────────────────────────────────────────

    public function test_07_assessment_starts_as_submitted_and_payment_pending(): void
    {
        $this->post('/resident-interest', $this->residentPayload())->assertRedirect();

        $a = Assessment::firstOrFail();
        $this->assertSame('submitted', $a->status);
        $this->assertSame('pending',   $a->payment_status);
        // Lock window populated by lockCurrentPrice — proves the
        // 30-day price-lock helper ran on creation.
        $this->assertNotNull($a->locked_price_nzd);
        $this->assertNotNull($a->locked_price_expires_at);
    }

    // ─── 8 : existing thank-you flash preserved ─────────────────────

    public function test_08_intake_form_still_flashes_thank_you_on_success(): void
    {
        $this->post('/resident-interest', $this->residentPayload())
            ->assertRedirect()
            ->assertSessionHas('intake_submitted', 'Resident Visa (SMC)');

        $this->post('/work-interest', $this->workPayload())
            ->assertRedirect()
            ->assertSessionHas('intake_submitted', 'Work Visa (AEWV)');

        $this->post('/student-interest', $this->studentPayload())
            ->assertRedirect()
            ->assertSessionHas('intake_submitted', 'Student Visa');

        $this->post('/visitor-interest', $this->visitorPayload())
            ->assertRedirect()
            ->assertSessionHas('intake_submitted', 'Visitor Visa (GVV)');
    }

    // ─── 9 : missing VisaType — intake still saves, warning logged ──

    public function test_09_intake_still_saves_when_visa_type_missing(): void
    {
        // Wipe the resident's VisaType so resolution falls through.
        VisaType::where('code', 'SMC')->delete();
        VisaType::where('category', 'Resident')->delete();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'VisaType not found')
                    && ($context['intake_class'] ?? null) === ResidentIntake::class;
            });
        // Suppress other log calls so the test doesn't choke on
        // unrelated info/debug emissions.
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->post('/resident-interest', $this->residentPayload())->assertRedirect();

        $this->assertSame(1, ResidentIntake::count(), 'Intake should still save.');
        $this->assertSame(0, Assessment::count(), 'No Assessment should be created without a matching VisaType.');
    }

    // ─── 10–13 : Convert flow for each visa type ─────────────────────

    private function actAsAdviser(): User
    {
        $user = User::factory()->create(['role' => 'immigration']);
        $this->actingAs($user);
        return $user;
    }

    public function test_10_convert_resident_assessment_creates_lead_flagged_as_case(): void
    {
        $this->actAsAdviser();
        $this->post('/resident-interest', $this->residentPayload());
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect();

        $lead = Lead::firstWhere('email', 'jane@example.com');
        $this->assertNotNull($lead);
        $this->assertTrue((bool) $lead->is_immigration_case);
        $this->assertSame('Skilled Migrant Resident Visa', $lead->inz_visa_type);
    }

    public function test_11_convert_work_assessment_creates_lead_flagged_as_case(): void
    {
        $this->actAsAdviser();
        $this->post('/work-interest', $this->workPayload());
        $assessment = Assessment::where('intakeable_type', WorkIntake::class)->firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect();

        $lead = Lead::firstWhere('email', 'bob@example.com');
        $this->assertNotNull($lead);
        $this->assertTrue((bool) $lead->is_immigration_case);
        $this->assertSame('Accredited Employer Work Visa', $lead->inz_visa_type);
    }

    public function test_12_convert_student_assessment_creates_lead_flagged_as_case(): void
    {
        $this->actAsAdviser();
        $this->post('/student-interest', $this->studentPayload());
        $assessment = Assessment::where('intakeable_type', StudentIntake::class)->firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect();

        $lead = Lead::firstWhere('email', 'sara@example.com');
        $this->assertNotNull($lead);
        $this->assertTrue((bool) $lead->is_immigration_case);
        $this->assertSame('Student Visa', $lead->inz_visa_type);
    }

    public function test_13_convert_visitor_assessment_creates_lead_flagged_as_case(): void
    {
        $this->actAsAdviser();
        $this->post('/visitor-interest', $this->visitorPayload());
        $assessment = Assessment::where('intakeable_type', VisitorIntake::class)->firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect();

        $lead = Lead::firstWhere('email', 'vince@example.com');
        $this->assertNotNull($lead);
        $this->assertTrue((bool) $lead->is_immigration_case);
        $this->assertSame('Visitor Visa', $lead->inz_visa_type);
    }

    // ─── 14 : reuse the same applicant without hijacking a shared inbox ──

    public function test_14_convert_reuses_an_existing_lead_with_matching_email_and_full_name(): void
    {
        $this->actAsAdviser();
        $existing = Lead::create([
            'lead_id' => 'LP-99999', 'first_name' => 'Jane', 'last_name' => 'Doe',
            'email'   => 'jane@example.com', 'phone' => '+64210000000', 'status' => 'New Leads',
        ]);

        $this->post('/resident-interest', $this->residentPayload());
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect();

        $this->assertSame(1, Lead::where('email', 'jane@example.com')->count(), 'Expected to reuse existing lead, not create a duplicate.');
        $existing->refresh();
        $this->assertTrue((bool) $existing->is_immigration_case);
        $this->assertSame($assessment->id, $existing->assessment_id);
    }

    public function test_conversion_does_not_hijack_a_relative_with_the_same_email_and_surname(): void
    {
        $this->actAsAdviser();
        $relative = Lead::create([
            'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'status' => 'New Leads',
        ]);
        $this->post('/resident-interest', $this->residentPayload())->assertRedirect();
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Lead::where('email', 'jane@example.com')->count());
        $relative->refresh();
        $this->assertFalse((bool) $relative->is_immigration_case);
        $this->assertNull($relative->assessment_id);
        $applicant = Lead::where('first_name', 'Jane')->firstOrFail();
        $this->assertTrue((bool) $applicant->is_immigration_case);
        $this->assertSame($assessment->id, $applicant->assessment_id);
    }

    // ─── 15 : timestamps + endorser ──────────────────────────────────

    public function test_15_convert_stamps_immigration_converted_at_and_by(): void
    {
        $user = $this->actAsAdviser();
        $this->post('/resident-interest', $this->residentPayload());
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case")
            ->assertRedirect();

        $lead = Lead::firstWhere('email', 'jane@example.com');
        $this->assertNotNull($lead->immigration_converted_at);
        $this->assertSame($user->id, $lead->immigration_converted_by);
    }

    // ─── 16 : intake status flipped to Engaged ───────────────────────

    public function test_16_convert_sets_intake_status_to_engaged(): void
    {
        $this->actAsAdviser();
        $this->post('/resident-interest', $this->residentPayload());
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case");

        $this->assertSame('Engaged', ResidentIntake::firstOrFail()->status);
    }

    // ─── 17 : Assessment status flipped to completed ─────────────────

    public function test_17_convert_sets_assessment_status_to_completed(): void
    {
        $this->actAsAdviser();
        $this->post('/resident-interest', $this->residentPayload());
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case");

        $assessment->refresh();
        $this->assertSame('completed', $assessment->status);
    }

    // ─── 18 : idempotent — converting twice preserves original timestamps ──

    public function test_18_convert_is_idempotent(): void
    {
        $this->actAsAdviser();
        $this->post('/resident-interest', $this->residentPayload());
        $assessment = Assessment::firstOrFail();

        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case");
        $lead = Lead::firstWhere('email', 'jane@example.com');
        $firstStamp = $lead->immigration_converted_at;

        // Second post — original timestamp must survive.
        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case");
        $lead->refresh();
        $this->assertTrue($lead->immigration_converted_at->equalTo($firstStamp), 'Second convert call should preserve the original timestamp.');
    }

    // ─── 19 : backward-compat — legacy intakeId still works ──────────

    public function test_19_legacy_resident_intake_id_route_still_converts(): void
    {
        $this->actAsAdviser();
        $this->post('/resident-interest', $this->residentPayload());
        $intake = ResidentIntake::firstOrFail();

        // POST with the *intake* id — pre-Phase-B URL shape. Controller
        // resolves the paired Assessment internally.
        $this->post("/portal/immigration/assessments/{$intake->id}/convert-to-case")
            ->assertRedirect();

        $this->assertTrue((bool) Lead::firstWhere('email', 'jane@example.com')->is_immigration_case);
    }

    // ─── 20–22 : Backfill command ────────────────────────────────────

    public function test_20_backfill_creates_assessments_for_historical_intakes(): void
    {
        // Simulate a pre-Phase-A intake by inserting directly without
        // going through the controller (so no Assessment is paired).
        $intake = ResidentIntake::create($this->residentPayload([
            'intake_id' => 'RI-LEGACY1',
            'status'    => 'Submitted',
        ]));

        $this->assertSame(0, Assessment::count(), 'Direct insert should not have created an Assessment.');

        $this->artisan('immigration:backfill-assessments')->assertSuccessful();

        $this->assertSame(1, Assessment::count());
        $this->assertDatabaseHas('assessments', [
            'intakeable_type' => ResidentIntake::class,
            'intakeable_id'   => $intake->id,
        ]);
    }

    public function test_21_backfill_dry_run_reports_without_writing(): void
    {
        ResidentIntake::create($this->residentPayload([
            'intake_id' => 'RI-LEGACY2',
            'status'    => 'Submitted',
        ]));

        $this->artisan('immigration:backfill-assessments', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Assessment::count(), '--dry-run must not write any rows.');
    }

    public function test_22_backfill_is_idempotent(): void
    {
        $this->post('/resident-interest', $this->residentPayload());
        $this->post('/work-interest',     $this->workPayload());

        $before = Assessment::count();
        $this->assertSame(2, $before, 'Submissions should have created 2 Assessments to start.');

        $this->artisan('immigration:backfill-assessments')->assertSuccessful();
        $this->artisan('immigration:backfill-assessments')->assertSuccessful();

        $this->assertSame($before, Assessment::count(), 'Backfill should not duplicate existing rows.');
    }
}
