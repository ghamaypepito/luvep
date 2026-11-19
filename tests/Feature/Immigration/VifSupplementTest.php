<?php

namespace Tests\Feature\Immigration;

use App\Http\Controllers\Portal\ImmigrationController;
use App\Models\Assessment;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use App\Support\VifSupplement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VifSupplementTest extends TestCase
{
    use RefreshDatabase;

    public static function categories(): array
    {
        return array_map(fn ($type) => [$type], ['work', 'student', 'visitor', 'resident', 'family']);
    }

    private function linkedDraft(string $type): array
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $staff = User::factory()->create(['role' => 'immigration']);
        $visa = VisaType::create(['name' => 'Synthetic '.$type, 'code' => 'TEST_'.strtoupper($type), 'category' => $type]);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'email' => 'synthetic@example.test', 'is_immigration_case' => true, 'inz_visa_type' => $visa->name, 'portal_invitation_status' => 'accepted']);
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => true]);
        $this->actingAs($staff)->post("/portal/immigration/cases/{$lead->id}/assessment-invitation")->assertRedirect()->assertSessionHasNoErrors();
        $intake = Assessment::findOrFail($lead->refresh()->assessment_id)->intakeable;
        if ($type === 'family') {
            $intake->update(['applying_as' => 'Dependent child', 'visa_type' => 'Residence class visa']);
        }

        return [$staff, $lead, $intake, $client];
    }

    #[DataProvider('categories')]
    public function test_staff_and_owner_can_save_additional_answers_and_exports_include_them(string $type): void
    {
        [$staff, $lead, $intake, $client] = $this->linkedDraft($type);
        $answers = ['middle_name' => 'Synthetic middle'];
        $url = "/portal/immigration/intakes/{$type}/{$intake->id}";
        $this->actingAs($staff)->patchJson($url, ['fields' => ['vif_answers' => $answers, 'status' => 'Hacked']])
            ->assertOk()->assertJsonPath('vif_values.vif_answers.middle_name', 'Synthetic middle');
        $this->assertSame('Draft', $intake->refresh()->status);
        $this->assertSame($answers, $intake->vif_answers);
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => ['middle_name' => 'Client confirmed middle']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Client confirmed middle', $intake->refresh()->vif_answers['middle_name']);
        $document = LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'vif')->firstOrFail();
        $this->assertSame(LeadDocument::STATUS_CHECKED, $document->status);
        $this->get('/portal/lead/vif')->assertForbidden();
        $this->actingAs($staff)->get($url.'/preview')->assertOk()->assertSee('Client confirmed middle');
        $this->get($url.'/word')->assertOk()->assertSee('Client confirmed middle');
        $this->get($url.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_unknown_fields_dates_and_cross_type_answers_are_rejected_without_mutation(): void
    {
        [$staff, $lead, $intake] = $this->linkedDraft('student');
        $url = "/portal/immigration/intakes/student/{$intake->id}";
        $this->patchJson($url, ['fields' => ['vif_answers' => ['payment_status' => 'paid']]])->assertUnprocessable();
        $this->patchJson($url, ['fields' => ['vif_answers' => ['parent_nz_citizen_or_resident' => 'Yes']]])->assertUnprocessable();
        $this->patchJson($url, ['fields' => ['qualifications' => [['start_date' => '2026-02-30']]]])->assertUnprocessable();
        $this->assertNull($intake->refresh()->vif_answers);
    }

    public function test_unrelated_client_cannot_select_another_intake_to_change(): void
    {
        [$staff, $lead, $intake] = $this->linkedDraft('student');
        $other = Lead::create(['first_name' => 'Unrelated', 'last_name' => 'Applicant', 'email' => 'other@example.test']);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);
        $this->actingAs($user)->post('/portal/lead/visa-assessment', ['assessment_id' => $lead->assessment_id, 'field_values' => ['vif_answers' => ['middle_name' => 'Intruder']]])->assertUnprocessable();
        $this->patchJson("/portal/immigration/intakes/student/{$intake->id}", ['fields' => ['vif_answers' => ['middle_name' => 'Intruder']]])->assertForbidden();
        $this->assertNull($intake->refresh()->vif_answers);
    }

    public function test_multiple_contact_entries_and_no_answers_survive_export(): void
    {
        [$staff, $lead, $intake] = $this->linkedDraft('visitor');
        $this->patchJson("/portal/immigration/intakes/visitor/{$intake->id}", ['fields' => ['vif_answers' => ['financially_support_self' => 'No'], 'nz_contacts' => [['family_name' => 'FirstContact', 'dob' => '1991-01-01'], ['family_name' => 'SecondContact', 'dob' => '1992-01-01']]]])->assertOk();
        $sections = app(ImmigrationController::class)->buildVifSections('visitor', $intake->refresh()->toArray());
        $text = json_encode($sections);
        $this->assertStringContainsString('FirstContact', $text);
        $this->assertStringContainsString('SecondContact', $text);
        $this->assertStringContainsString('1992-01-01', $text);
        $this->assertSame('No', $intake->vif_answers['financially_support_self']);
        $this->assertTrue(end($sections)['bare']);
    }

    #[DataProvider('categories')]
    public function test_public_submission_persists_additional_answers_without_requiring_them_on_old_forms(string $type): void
    {
        Mail::fake();
        Notification::fake();
        $payload = [
            'first_name' => 'Synthetic', 'family_name' => 'Applicant', 'last_name' => 'Applicant',
            'dob' => '1992-04-11', 'email' => 'synthetic@example.test', 'phone' => '+64 21 555 0000',
            'declaration_accepted' => true, 'vif_answers' => ['middle_name' => 'Public form middle'],
        ];
        if ($type === 'resident') {
            $payload += [
                'terms_accepted' => true, 'nationality' => 'Philippines',
                'passport_number' => 'SYNTHETIC', 'passport_expiry' => '2031-01-01', 'issuing_country' => 'Philippines',
                'current_visa_type' => 'AEWV', 'current_visa_expiry' => '2027-01-01', 'nz_arrival_date' => '2023-06-01',
                'job_title' => 'Synthetic role', 'employment_start' => '2023-07-01', 'employment_type' => 'Full-time', 'hourly_rate' => 32.5,
                'highest_qualification' => "Bachelor's Degree", 'nz_skilled_years' => 2, 'total_skilled_years' => 5,
                'english_evidence' => 'Recognised employment', 'include_family' => 'No',
            ];
        }
        $this->post('/'.$type.'-interest', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $model = 'App\\Models\\'.ucfirst($type).'Intake';
        $this->assertSame('Public form middle', $model::firstOrFail()->vif_answers['middle_name']);
    }

    public function test_new_post_study_submission_uses_post_study_visa_not_aewv(): void
    {
        Mail::fake();
        Notification::fake();
        $visa = VisaType::create(['code' => 'POST_STUDY_WORK', 'name' => 'Post-Study Work Visa', 'category' => 'Work']);
        $this->post('/work-interest', [
            'first_name' => 'Synthetic', 'family_name' => 'Applicant', 'dob' => '1992-04-11',
            'email' => 'synthetic@example.test', 'phone' => '+64 21 555 0000', 'declaration_accepted' => true,
            'vif_answers' => ['work_visa_category' => 'Post-Study Work Visa', 'previous_post_study_work_visa' => 'No'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($visa->id, Assessment::firstOrFail()->visa_type_id);
        $this->assertSame('No', \App\Models\WorkIntake::firstOrFail()->vif_answers['previous_post_study_work_visa']);
    }

    public function test_legacy_boolean_and_month_precision_education_answers_are_preserved(): void
    {
        $values = ['qualifications' => [['name' => 'Legacy synthetic qualification', 'start_date' => '2014-06', 'finish_date' => '2017', 'completed' => true]]];
        $this->assertTrue(Validator::make($values, VifSupplement::rules('student'))->passes());
        $text = json_encode(VifSupplement::sections('student', $values));
        $this->assertStringContainsString('Legacy synthetic qualification', $text);
        $this->assertStringContainsString('2014-06', $text);
        $this->assertStringContainsString('2017', $text);
        $this->assertStringContainsString('Yes', $text);
    }

    public function test_staff_changes_refresh_an_existing_vif_and_require_adviser_review_again(): void
    {
        [$staff, $lead, $intake, $client] = $this->linkedDraft('work');
        $this->actingAs($client)->post('/portal/lead/vif')->assertRedirect();
        $document = LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'vif')->firstOrFail();
        $oldPath = $document->file_path;
        $document->update(['status' => LeadDocument::STATUS_APPROVED, 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
        $this->actingAs($staff)->patchJson("/portal/immigration/intakes/work/{$intake->id}", ['fields' => ['vif_answers' => []]])->assertOk();
        $this->assertSame(LeadDocument::STATUS_APPROVED, $document->refresh()->status);
        $this->assertSame($oldPath, $document->file_path);
        $this->actingAs($staff)->patchJson("/portal/immigration/intakes/work/{$intake->id}", ['fields' => ['vif_answers' => ['middle_name' => 'Staff confirmed synthetic name']]])->assertOk();
        $this->assertSame(LeadDocument::STATUS_CHECKED, $document->refresh()->status);
        $this->assertNull($document->reviewed_by);
        $this->assertNotSame($oldPath, $document->file_path);
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('local')->assertExists($document->file_path);
        $this->actingAs($client)->get('/portal/lead/vif')->assertForbidden();
    }

    public function test_catalogue_is_scoped_and_does_not_guess_post_study_answers(): void
    {
        $aewv = json_encode(VifSupplement::sections('work', []));
        $pswv = json_encode(VifSupplement::sections('work', ['vif_answers' => ['work_visa_category' => 'Post-Study Work Visa']]));
        $this->assertStringNotContainsString('cohort 4', $aewv);
        $this->assertStringContainsString('cohort 4', $pswv);
        $this->assertStringNotContainsString('NZBN', $pswv);
        $this->assertSame([], VifSupplement::schema('education'));
        $this->assertStringNotContainsString('Supporting parent', json_encode(VifSupplement::sections('family', ['applying_as' => 'Partner'])));
        $this->assertStringContainsString('Supporting parent', json_encode(VifSupplement::sections('family', ['applying_as' => 'Dependent child', 'visa_type' => 'Residence class visa'])));
    }
}
