<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreeAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent the AnalyzeLeadAssessment job from making real HTTP calls
        // to the Cerebras API during tests. Without these fakes, the queued
        // job (run synchronously with QUEUE_CONNECTION=sync) hits a real
        // endpoint and the resulting exception corrupts the SQLite :memory:
        // transaction state, poisoning every subsequent test class in the
        // full suite run.
        Bus::fake();
        Http::preventStrayRequests();
    }

    /**
     * Full form payload matching the 12-step frontend form.
     */
    private function fullPayload(): array
    {
        return [
            // Step 1
            'terms_accepted' => true,

            // Step 2 - Personal
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'has_other_names' => 'Yes',
            'other_names' => 'Johnny Cruz',
            'gender' => 'Male',
            'marital_status' => 'Single',
            'phone' => '+639171234567',
            'email' => 'juan@test.com',
            'dob' => '1995-06-15',
            'country_of_birth' => 'Philippines',
            'place_of_birth' => 'Manila',
            'citizenship' => 'Filipino',
            'residence_city' => 'Makati',
            'residence_state' => 'Metro Manila',
            'residence_country' => 'Philippines',
            'has_passport' => 'Yes',
            'passport_number' => 'P1234567A',
            'passport_expiry' => '2030-12-31',

            // Step 3 - Study Plans
            'study_plans' => [
                'preferred_course' => 'Bachelor of IT',
                'qualification_level' => 'Bachelor Degree (Level 7)',
                'preferred_city' => 'Auckland',
                'preferred_intake' => 'Feb 2027',
                'has_english_test' => 'Yes',
                'english_test_type' => 'IELTS Academic',
                'test_score_overall' => '7.0',
                'test_score_reading' => '7.5',
                'test_score_writing' => '6.5',
                'test_score_listening' => '7.0',
                'test_score_speaking' => '7.0',
                'test_date' => '2026-01-15',
            ],

            // Step 4 - Education
            'high_school_completed' => 'Yes',
            'high_school_level' => '12th',
            'high_school_institution' => 'Manila Science High School',
            'high_school_start' => '2008-06-01',
            'high_school_end' => '2012-03-31',
            'high_school_marks' => '92%',
            'education_background' => [
                ['level' => 'Diploma', 'completed' => false, 'field_of_study' => '', 'institution' => '', 'start_date' => '', 'end_date' => '', 'marks_percentage' => ''],
                ['level' => "Bachelor's Degree", 'completed' => true, 'field_of_study' => 'Computer Science', 'institution' => 'University of the Philippines', 'start_date' => '2012-06-01', 'end_date' => '2016-04-30', 'marks_percentage' => '1.5 GWA'],
                ['level' => "Master's Degree", 'completed' => false, 'field_of_study' => '', 'institution' => '', 'start_date' => '', 'end_date' => '', 'marks_percentage' => ''],
                ['level' => 'Doctorate', 'completed' => false, 'field_of_study' => '', 'institution' => '', 'start_date' => '', 'end_date' => '', 'marks_percentage' => ''],
            ],
            'education_docs' => ['12th Certificate', "Bachelor's Certificate", 'Academic Transcripts'],
            'has_gap' => 'Yes',
            'gap_length' => '2 years',
            'gap_activities' => ['Working'],
            'gap_explanation' => 'Worked as a developer to gain experience.',

            // Step 5 - Work
            'work_experience' => [
                [
                    'company_name' => 'TechCorp Philippines',
                    'job_title' => 'Software Developer',
                    'start_date' => '2016-07-01',
                    'end_date' => '2026-03-31',
                    'is_current' => 'No',
                    'duties' => 'Full-stack development with Laravel and React.',
                    'has_supporting_docs' => 'Yes',
                    'supporting_docs' => ['Certificate of Employment', 'Salary slips'],
                ],
            ],

            // Step 6 - Financial
            'financial_info' => [
                'can_cover_tuition' => 'Yes',
                'can_cover_living' => 'Yes',
                'funding_source' => ['Personal Savings', 'Family Support'],
                'estimated_budget' => '3,000,000 PHP',
                'has_sponsors' => 'Yes',
                'sponsor_relation' => 'Parents',
            ],

            // Step 7 - Source of Funds
            'source_of_funds_info' => [
                'sources' => ['Family savings', 'Personal savings'],
                'will_self_fund' => 'No',
                'student_financial_docs' => ['Bank statements (6 months)'],
                'will_use_sponsor' => 'Yes',
                'sponsor_relation' => 'Both parents',
                'sponsor_nz_based' => 'No',
                'sponsor_nz_resident' => 'No',
                'sponsor_occupation' => 'Business Owner',
                'sponsor_employer' => 'Cruz Trading Co.',
                'sponsor_annual_income' => 'NZ$120,000',
                'sponsor_source_of_funds' => ['Savings', 'Income from business'],
                'sponsor_financial_docs' => ['Bank statements', 'Income Tax Return'],
                'sponsor_identity_docs' => ['Passport'],
            ],

            // Step 8 - Immigration
            'immigration_info' => [
                'has_travelled_overseas' => 'Yes',
                'overseas_travel_details' => 'Japan, Tourist Visa, Approved, Dec 2024',
                'has_applied_nz_visa' => 'No',
                'nz_visa_details' => '',
                'total_nz_time_24_months' => 'No',
                'has_applied_other_visa' => 'No',
                'other_visa_details' => '',
                'has_visa_refusal' => 'No',
                'visa_refusal_details' => '',
                'submission_country' => 'Philippines',
            ],

            // Step 9 - Character
            'character_info' => [
                'has_conviction' => 'No',
                'under_investigation' => 'No',
                'has_deportation' => 'No',
                'has_visa_refusal_other' => 'No',
                'lived_5_years_since_17' => 'No',
            ],

            // Step 9 - Health
            'health_info' => [
                'has_tuberculosis' => 'No',
                'has_renal_dialysis' => 'No',
                'needs_hospital_care' => 'No',
                'needs_residential_care' => 'No',
                'is_pregnant' => 'No',
            ],

            // Step 10 - Family
            'family_info' => [
                'members' => [
                    ['relation' => 'Father', 'first_name' => 'Pedro', 'family_name' => 'Dela Cruz', 'dob' => '1965-03-10', 'partnership_status' => 'Married', 'country_of_residence' => 'Philippines', 'country_of_birth' => 'Philippines', 'occupation' => 'Business Owner'],
                    ['relation' => 'Mother', 'first_name' => 'Maria', 'family_name' => 'Dela Cruz', 'dob' => '1968-07-22', 'partnership_status' => 'Married', 'country_of_residence' => 'Philippines', 'country_of_birth' => 'Philippines', 'occupation' => 'Homemaker'],
                ],
            ],

            // Step 11 - NZ Contacts
            'nz_contacts_info' => [
                'has_nz_contacts' => 'Yes',
                'contact_first_name' => 'Carlos',
                'contact_family_name' => 'Santos',
                'contact_relationship' => 'Friend',
                'contact_address' => '123 Queen Street, Auckland',
                'contact_number' => '+6421234567',
            ],

            // Step 11 - Military
            'military_info' => [
                'military_compulsory' => 'No',
                'has_military_service' => 'No',
            ],

            // Step 11 - Home Ties
            'home_ties_info' => [
                'family_owns_property' => 'Yes',
                'property_type' => 'House',
                'property_location' => 'Makati City',
                'property_owner' => 'Parents',
                'family_owns_business' => 'Yes',
                'business_type' => 'Import/Export Trading',
                'business_involvement' => 'None',
            ],

            // Step 12 - Declaration
            'declaration_accepted' => true,
        ];
    }

    public function test_free_assessment_page_loads(): void
    {
        $response = $this->get('/free-assessment');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('free-assessment/FreeAssessmentPage', false));
    }

    public function test_full_submission_creates_lead_with_all_fields(): void
    {
        $response = $this->post('/free-assessment', $this->fullPayload());

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify lead was created
        $lead = Lead::latest()->first();
        $this->assertNotNull($lead);
        $this->assertStringStartsWith('FA-', $lead->lead_id);

        // Personal fields
        $this->assertEquals('Juan', $lead->first_name);
        $this->assertEquals('Dela Cruz', $lead->last_name);
        $this->assertEquals('juan@test.com', $lead->email);
        $this->assertEquals('+639171234567', $lead->phone);
        $this->assertEquals('Male', $lead->gender);
        $this->assertEquals('Single', $lead->marital_status);
        $this->assertEquals('Johnny Cruz', $lead->other_names);
        $this->assertEquals('Philippines', $lead->country_of_birth);
        $this->assertEquals('Manila', $lead->place_of_birth);
        $this->assertEquals('Filipino', $lead->citizenship);
        $this->assertEquals('Makati', $lead->residence_city);
        $this->assertEquals('Metro Manila', $lead->residence_state);
        $this->assertEquals('Philippines', $lead->residence_country);
        $this->assertEquals('Yes', $lead->has_passport);
        $this->assertEquals('P1234567A', $lead->passport_number);
        $this->assertTrue((bool) $lead->terms_accepted);
        $this->assertTrue((bool) $lead->declaration_accepted);
    }

    public function test_study_plans_saved_as_related_record(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $studyPlan = $lead->studyPlans()->first();

        $this->assertNotNull($studyPlan);
        $this->assertEquals('Bachelor of IT', $studyPlan->preferred_course);
        $this->assertEquals('Bachelor Degree (Level 7)', $studyPlan->qualification_level);
        $this->assertEquals('Auckland', $studyPlan->preferred_city);
        $this->assertEquals('Feb 2027', $studyPlan->preferred_intake);
        $this->assertTrue($studyPlan->english_test_taken);
        $this->assertEquals('IELTS Academic', $studyPlan->english_test_type);
        $this->assertEquals('7.0', $studyPlan->score_overall);
        $this->assertEquals('7.5', $studyPlan->score_reading);
        $this->assertEquals('6.5', $studyPlan->score_writing);
        $this->assertEquals('7.0', $studyPlan->score_listening);
        $this->assertEquals('7.0', $studyPlan->score_speaking);
    }

    public function test_only_completed_education_entries_saved(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $eduRecords = $lead->educationExps;

        // Only Bachelor's was marked completed
        $this->assertCount(1, $eduRecords);
        $this->assertEquals("Bachelor's Degree", $eduRecords[0]->level);
        $this->assertEquals('Computer Science', $eduRecords[0]->field_of_study);
        $this->assertEquals('University of the Philippines', $eduRecords[0]->institution);
        $this->assertEquals('1.5 GWA', $eduRecords[0]->average_marks);
    }

    public function test_education_notes_stores_high_school_and_gap_data(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $notes = $lead->education_notes;

        $this->assertIsArray($notes);
        $this->assertEquals('Yes', $notes['high_school_completed']);
        $this->assertEquals('12th', $notes['high_school_level']);
        $this->assertEquals('Manila Science High School', $notes['high_school_institution']);
        $this->assertEquals('92%', $notes['high_school_marks']);
        $this->assertEquals(['12th Certificate', "Bachelor's Certificate", 'Academic Transcripts'], $notes['education_docs']);
        $this->assertEquals('Yes', $notes['has_gap']);
        $this->assertEquals('2 years', $notes['gap_length']);
        $this->assertEquals(['Working'], $notes['gap_activities']);
    }

    public function test_work_experience_stored_as_json(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $work = $lead->work_info;

        $this->assertIsArray($work);
        $this->assertEquals('TechCorp Philippines', $work[0]['company_name']);
        $this->assertEquals('Software Developer', $work[0]['job_title']);
        $this->assertEquals('No', $work[0]['is_current']);
        $this->assertEquals('Yes', $work[0]['has_supporting_docs']);
        $this->assertContains('Certificate of Employment', $work[0]['supporting_docs']);
        $this->assertContains('Salary slips', $work[0]['supporting_docs']);
    }

    public function test_financial_info_stored_as_json(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $fin = $lead->financial_info;

        $this->assertIsArray($fin);
        $this->assertEquals('Yes', $fin['can_cover_tuition']);
        $this->assertEquals('Yes', $fin['can_cover_living']);
        $this->assertContains('Personal Savings', $fin['funding_source']);
        $this->assertEquals('3,000,000 PHP', $fin['estimated_budget']);
    }

    public function test_source_of_funds_stored_as_json(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $funds = $lead->source_of_funds_info;

        $this->assertIsArray($funds);
        $this->assertContains('Family savings', $funds['sources']);
        $this->assertEquals('Yes', $funds['will_use_sponsor']);
        $this->assertEquals('Both parents', $funds['sponsor_relation']);
        $this->assertEquals('No', $funds['sponsor_nz_based']);
        $this->assertEquals('Business Owner', $funds['sponsor_occupation']);
        $this->assertEquals('NZ$120,000', $funds['sponsor_annual_income']);
        $this->assertContains('Passport', $funds['sponsor_identity_docs']);
    }

    public function test_immigration_info_stored_as_json(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $imm = $lead->immigration_info;

        $this->assertIsArray($imm);
        $this->assertEquals('Yes', $imm['has_travelled_overseas']);
        $this->assertStringContainsString('Japan', $imm['overseas_travel_details']);
        $this->assertEquals('No', $imm['has_applied_nz_visa']);
        $this->assertEquals('No', $imm['has_visa_refusal']);
        $this->assertEquals('Philippines', $imm['submission_country']);
    }

    public function test_character_and_health_info_stored(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();

        // Character
        $char = $lead->character_info;
        $this->assertIsArray($char);
        $this->assertEquals('No', $char['has_conviction']);
        $this->assertEquals('No', $char['under_investigation']);
        $this->assertEquals('No', $char['has_deportation']);

        // Health
        $health = $lead->health_info;
        $this->assertIsArray($health);
        $this->assertEquals('No', $health['has_tuberculosis']);
        $this->assertEquals('No', $health['is_pregnant']);
    }

    public function test_family_info_stored_as_json(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $family = $lead->family_info;

        $this->assertIsArray($family);
        $this->assertCount(2, $family['members']);
        $this->assertEquals('Father', $family['members'][0]['relation']);
        $this->assertEquals('Pedro', $family['members'][0]['first_name']);
        $this->assertEquals('Mother', $family['members'][1]['relation']);
        $this->assertEquals('Maria', $family['members'][1]['first_name']);
    }

    public function test_nz_contacts_military_home_ties_stored(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();

        // NZ Contacts
        $nz = $lead->nz_contacts_info;
        $this->assertEquals('Yes', $nz['has_nz_contacts']);
        $this->assertEquals('Carlos', $nz['contact_first_name']);
        $this->assertEquals('Friend', $nz['contact_relationship']);

        // Military
        $mil = $lead->military_info;
        $this->assertEquals('No', $mil['military_compulsory']);
        $this->assertEquals('No', $mil['has_military_service']);

        // Home Ties
        $home = $lead->home_ties_info;
        $this->assertEquals('Yes', $home['family_owns_property']);
        $this->assertEquals('House', $home['property_type']);
        $this->assertEquals('Parents', $home['property_owner']);
        $this->assertEquals('Yes', $home['family_owns_business']);
        $this->assertEquals('Import/Export Trading', $home['business_type']);
    }

    public function test_validation_requires_name_and_email(): void
    {
        $response = $this->post('/free-assessment', [
            'terms_accepted' => true,
        ]);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'email']);
    }

    public function test_validation_requires_terms_accepted(): void
    {
        $response = $this->post('/free-assessment', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@test.com',
            'terms_accepted' => false,
        ]);

        $response->assertSessionHasErrors(['terms_accepted']);
    }

    public function test_partial_submission_is_rejected(): void
    {
        // The form contract requires all step fields. A submission missing any
        // of them must be rejected with validation errors so the frontend can
        // navigate the user back to the offending step.
        $response = $this->post('/free-assessment', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@test.com',
            'terms_accepted' => true,
        ]);

        $response->assertSessionHasErrors([
            'phone',
            'gender',
            'dob',
            'country_of_birth',
            'citizenship',
            'residence_country',
            'study_plans.preferred_course',
            'study_plans.qualification_level',
            'work_experience',
            'financial_info.funding_source',
            'financial_info.estimated_budget',
            'source_of_funds_info.sources',
            'immigration_info.submission_country',
            'declaration_accepted',
        ]);

        $this->assertNull(Lead::latest()->first());
    }

    public function test_passport_pdf_upload(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $payload = $this->fullPayload();
        $payload['passport_pdf'] = UploadedFile::fake()->create('passport.pdf', 500, 'application/pdf');

        $response = $this->post('/free-assessment', $payload);

        $response->assertRedirect();

        $lead = Lead::latest()->first();
        $this->assertNotNull($lead->passport_path);
        Storage::disk('local')->assertExists($lead->passport_path);
        Storage::disk('public')->assertMissing($lead->passport_path);
    }

    public function test_enrolment_uploads_create_checklist_documents(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $payload = $this->fullPayload();
        $payload['cv_files'] = [UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf')];
        $payload['passport_files'] = [UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf')];
        $payload['diploma_files'] = [UploadedFile::fake()->image('diploma.jpg')];
        $payload['transcript_files'] = [UploadedFile::fake()->create('tor.pdf', 200, 'application/pdf')];

        $this->post('/free-assessment', $payload)->assertRedirect();

        $lead = \App\Models\Lead::latest()->first();
        $this->assertNotNull($lead);

        // Each upload becomes a checklist-keyed LeadDocument so it surfaces in
        // the staff Documents tab (not just the education_notes JSON bucket).
        $keys = \App\Models\LeadDocument::where('lead_id', $lead->id)
            ->pluck('checklist_key')
            ->all();

        $this->assertContains('acad.cv', $keys);
        $cv = \App\Models\LeadDocument::where('lead_id', $lead->id)->where('checklist_key', 'acad.cv')->sole();
        Storage::disk('local')->assertExists($cv->file_path);
        Storage::disk('public')->assertMissing($cv->file_path);
        $this->assertContains('pers.passport', $keys);
        $this->assertContains('acad.degree_diploma', $keys);
        $this->assertContains('acad.transcript', $keys);
    }

    public function test_invalid_email_rejected(): void
    {
        $payload = $this->fullPayload();
        $payload['email'] = 'not-an-email';

        $response = $this->post('/free-assessment', $payload);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_lead_id_starts_with_fa_prefix(): void
    {
        $this->post('/free-assessment', $this->fullPayload());

        $lead = Lead::latest()->first();
        $this->assertStringStartsWith('FA-', $lead->lead_id);
    }
}
