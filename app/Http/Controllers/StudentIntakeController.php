<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FiresEnquiryAutomation;
use App\Http\Controllers\Concerns\HandlesIntakeDocuments;
use App\Http\Controllers\Concerns\SyncsIntakeToCase;
use App\Models\Assessment;
use App\Models\StudentIntake;
use App\Support\IntakeVisaTypeMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentIntakeController extends Controller
{
    use FiresEnquiryAutomation;
    use HandlesIntakeDocuments;
    use SyncsIntakeToCase;

    public function showForm()
    {
        return inertia('visa/StudentInterestPage');
    }

    public function store(Request $request)
    {
        // Inertia ships untouched empty inputs as "" rather than null, which
        // makes `nullable|email` / `nullable|date` rules fail on otherwise
        // optional fields. Convert all empty scalars to null up front so
        // `nullable` does what the rule name says it does.
        $request->merge(collect($request->all())
            ->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)
            ->all());

        $validated = $request->validate($this->rules());

        try {
            DB::beginTransaction();

            $intakeId = 'SI-'.strtoupper(uniqid());

            // Document tab — files to the private disk, kept out of mass-assignment.
            unset($validated['document_files']);
            $storedFiles = $this->persistIntakeFiles($request, 'student-intakes', $intakeId);

            $intake = StudentIntake::create(array_merge($validated, [
                'intake_id' => $intakeId,
                'status' => 'Submitted',
                'documents' => ! empty($validated['documents']) ? $validated['documents'] : null,
                'document_files' => $storedFiles ?: null,
            ]));

            // Tracking-only Assessment row — payment + booking stay
            // disabled (see AssessmentController::simulatePay). The
            // portal Assessments page + Convert-to-Case flow need a
            // paired Assessment to hang journey state on. Pay/Book
            // redirect stays commented; payment intake is dormant until
            // Stripe is wired up in a future build.
            $visaType = IntakeVisaTypeMap::resolve(StudentIntake::class);
            if (! $visaType) {
                Log::warning('VisaType not found for Student intake; skipping Assessment creation.', [
                    'intake_id' => $intake->id,
                    'intake_class' => StudentIntake::class,
                ]);
            } else {
                Assessment::createForIntake($intake, $visaType);
            }

            DB::commit();

            $this->fireEnquiryCaptured($intake, 'Student Visa');

            $this->syncIntakeToExistingCase($intake, 'Student Visa');

            // return redirect()->route('assessment.pay', $assessment->token);
            return back()->with('intake_submitted', 'Student Visa');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Student intake storage failed', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors([
                'error' => 'Failed to submit intake. Please try again.',
            ]);
        }
    }

    private function rules(): array
    {
        return [
            // Shared document-tab rules (passport, visa copies, files, …).
            ...$this->intakeDocumentRules(),
            'family_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'other_names' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:30',
            'dob' => 'required|date',
            'country_of_birth' => 'nullable|string|max:120',
            'place_of_birth' => 'nullable|string|max:120',
            'current_address' => 'nullable|string',
            'overseas_address' => 'nullable|string',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:40',
            'country_of_citizenship' => 'nullable|string|max:120',
            'other_citizenships' => 'nullable|string|max:255',
            'national_id' => 'nullable|string|max:80',
            'passport_number' => 'nullable|string|max:60',
            'passport_expiry' => 'nullable|date',
            'partnership_status' => 'nullable|string|max:60',

            'current_country' => 'nullable|string|max:120',
            'previous_nz_visas' => 'nullable|array',
            'previous_nzeta' => 'nullable|array',
            'australian_pr' => 'nullable|array',
            'travelled_nz' => 'nullable|string|max:10',
            'last_nz_departure' => 'nullable|date',
            'over_24_months' => 'nullable|string|max:10',

            'character_convicted' => 'nullable|string|max:10',
            'character_investigation' => 'nullable|string|max:10',
            'character_deported' => 'nullable|string|max:10',
            'character_visa_refused' => 'nullable|string|max:10',
            'lived_other_country_5y' => 'nullable|string|max:10',
            'lived_other_country_details' => 'nullable|string',

            'health_tb' => 'nullable|string|max:10',
            'health_renal' => 'nullable|string|max:10',
            'health_hospital' => 'nullable|string|max:10',
            'health_residential' => 'nullable|string|max:10',
            'health_pregnant' => 'nullable|string|max:10',

            'qualifications' => 'nullable|array',

            'currently_working' => 'nullable|string|max:10',
            'current_job_title' => 'nullable|string|max:255',
            'current_job_duties' => 'nullable|string',
            'current_job_start' => 'nullable|date',
            'current_job_finish' => 'nullable|date',
            'current_job_country' => 'nullable|string|max:120',
            'current_job_region' => 'nullable|string|max:120',
            'current_employer_name' => 'nullable|string|max:255',
            'current_employer_address' => 'nullable|string',
            'current_employer_phone' => 'nullable|string|max:60',
            'current_employer_email' => 'nullable|email|max:255',

            'family_members' => 'nullable|array',
            'has_nz_contacts' => 'nullable|string|max:10',
            'nz_contacts' => 'nullable|array',

            'military_compulsory' => 'nullable|string|max:10',
            'military_undertaken' => 'nullable|string|max:10',
            'military_details' => 'nullable|string',

            'travelled_internationally' => 'nullable|string|max:10',
            'travel_trips' => 'nullable|array',

            'programmes' => 'nullable|string',
            'study_period_from' => 'nullable|date',
            'study_period_to' => 'nullable|date',
            'school_name' => 'nullable|string|max:255',
            'has_offer' => 'nullable|string|max:10',

            'has_enough_funds' => 'nullable|string|max:10',
            'tuition_fee_nzd' => 'nullable|numeric|min:0',
            'available_funds' => 'nullable|array',
            'living_expenses_nzd' => 'nullable|numeric|min:0',
            'has_sponsor' => 'nullable|string|max:10',
            'sponsor_relationship' => 'nullable|string|max:120',
            'sponsor_income_source' => 'nullable|string|max:255',
            'can_provide_statements' => 'nullable|string|max:10',
            'has_other_assets' => 'nullable|string|max:10',
            'other_assets_details' => 'nullable|string',

            'declaration_accepted' => 'required|accepted',
            'signature_name' => 'nullable|string|max:255',
            'signature_date' => 'nullable|date',

            // Extra processing questions (all optional).
            'birth_certificate_available' => 'nullable|in:Yes,No',
            'birth_certificate_reason' => 'nullable|required_if:birth_certificate_available,No|string|max:500',
            'face_image_consent' => 'nullable|boolean',
            'medical_cert_recent' => 'nullable|in:Yes,No',
            'medical_cert_submitted_last' => 'nullable|in:Yes,No',
            'medical_cert_type' => 'nullable|required_if:medical_cert_submitted_last,Yes|in:General Medical,X-Ray Medical Examination',
            'police_certificate_available' => 'nullable|in:Yes,No',
        ];
    }
}
