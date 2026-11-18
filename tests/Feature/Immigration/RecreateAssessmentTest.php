<?php

namespace Tests\Feature\Immigration;

use App\Models\Assessment;
use App\Models\ResidentIntake;
use App\Models\StudentIntake;
use App\Models\VisaType;
use App\Models\WorkIntake;
use App\Services\Immigration\IntakeTypeConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-create a submitted assessment as a different visa type (the wrong-type fix).
 * Shared answers copy across; visa-specific sections are left for the client to
 * complete; a paired Assessment is created so it appears in the Visa Assessment list.
 */
class RecreateAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVisaTypes();
    }

    private function seedVisaTypes(): void
    {
        foreach ([
            ['code' => 'SMC', 'name' => 'Skilled Migrant Resident Visa', 'category' => 'Resident'],
            ['code' => 'WORK_AEWV', 'name' => 'Accredited Employer Work Visa', 'category' => 'Work'],
            ['code' => 'STUDENT', 'name' => 'Student Visa', 'category' => 'Student'],
        ] as $row) {
            VisaType::create(array_merge($row, [
                'short_description' => 'Test', 'consultation_price_nzd' => 200.00,
                'consultation_duration_minutes' => 30, 'estimated_minutes' => 10,
                'icon' => 'Globe', 'active' => true,
            ]));
        }
    }

    private function workIntake(array $o = []): WorkIntake
    {
        return WorkIntake::create(array_merge([
            'intake_id' => 'WI-TEST-'.uniqid(), 'status' => 'Submitted',
            'first_name' => 'Juan', 'family_name' => 'Dela Cruz', 'gender' => 'Male', 'dob' => '1992-07-20',
            'country_of_birth' => 'Philippines', 'place_of_birth' => 'Cebu',
            'email' => 'juan@example.test', 'phone' => '+64210000000',
            'country_of_citizenship' => 'Philippines', 'partnership_status' => 'Single',
            'character_convicted' => 'No', 'health_tb' => 'No',
            'currently_working' => 'Yes', 'current_employer_name' => 'Acme NZ', 'current_job_title' => 'Chef',
            'declaration_accepted' => true, 'signature_name' => 'Juan Dela Cruz', 'signature_date' => '2026-01-01',
        ], $o));
    }

    public function test_recreate_work_as_student_copies_shared_fields_and_pairs_assessment(): void
    {
        $work = $this->workIntake();

        $new = app(IntakeTypeConverter::class)->recreateAs($work, 'student');

        $this->assertInstanceOf(StudentIntake::class, $new);
        // Shared applicant profile carried over.
        $this->assertSame('Juan', $new->first_name);
        $this->assertSame('Dela Cruz', $new->family_name);
        $this->assertSame('Philippines', $new->country_of_citizenship);
        $this->assertSame('No', $new->character_convicted);
        $this->assertSame('Acme NZ', $new->current_employer_name);

        // Visa-specific left blank; declaration reset for the new form.
        $this->assertNull($new->school_name);
        $this->assertFalse((bool) $new->declaration_accepted);

        // A paired Assessment exists so the row shows in the list.
        $assessment = Assessment::where('intakeable_type', StudentIntake::class)
            ->where('intakeable_id', $new->id)->first();
        $this->assertNotNull($assessment);
        $this->assertSame('Student Visa', $assessment->visaType->name);

        // The original is untouched.
        $this->assertDatabaseHas('work_intakes', ['id' => $work->id]);
    }

    public function test_recreate_work_as_resident_fills_required_fields(): void
    {
        $work = $this->workIntake();

        // Resident has NOT NULL columns the work form doesn't collect — the
        // converter must seed them so the record saves.
        $new = app(IntakeTypeConverter::class)->recreateAs($work, 'resident');

        $this->assertInstanceOf(ResidentIntake::class, $new);
        // country_of_citizenship -> nationality alias.
        $this->assertSame('Philippines', $new->nationality);
        $this->assertSame('Juan', $new->first_name);
        $this->assertSame('Dela Cruz', $new->last_name);
        // Luvep's linked-draft migration makes unknown dates nullable. Preserve
        // that behavior rather than inventing an expiry date during conversion.
        $this->assertNull($new->current_visa_expiry);

        $this->assertNotNull(
            Assessment::where('intakeable_type', ResidentIntake::class)->where('intakeable_id', $new->id)->first()
        );
    }

    public function test_recreate_fails_cleanly_when_target_visa_type_missing(): void
    {
        VisaType::where('category', 'Student')->orWhere('code', 'STUDENT')->delete();
        $work = $this->workIntake();

        $this->expectException(\RuntimeException::class);
        app(IntakeTypeConverter::class)->recreateAs($work, 'student');

        // No orphan student intake created.
        $this->assertSame(0, StudentIntake::count());
    }
}
