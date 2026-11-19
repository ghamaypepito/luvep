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
        if ($type === 'resident') {
            $payload['vif_answers']['smc_employment_basis'] = 'A contract for services';
        } elseif ($type === 'family') {
            $payload['vif_answers']['parent_nz_citizen_or_resident'] = 'Yes';
            $payload['vif_answers']['supporting_parent_nz_status'] = 'New Zealand citizen';
        }
        $this->post('/'.$type.'-interest', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $model = 'App\\Models\\'.ucfirst($type).'Intake';
        foreach ($payload['vif_answers'] as $key => $answer) {
            $this->assertSame($answer, $model::firstOrFail()->vif_answers[$key]);
        }
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

    public function test_source_parent_status_and_all_smc_employment_bases_persist_and_export_without_eligibility_changes(): void
    {
        $bases = [
            'Permanent or for a fixed term of at least 1 year',
            'A contract for services',
            'I am an independent midwife practitioner with a letter of authority to claim under the Maternity Notice and registration with the Midwifery Council',
            'None of the above',
        ];
        foreach (['family', 'resident'] as $type) {
            [$staff, $lead, $intake, $client] = $this->linkedDraft($type);
            $url = "/portal/immigration/intakes/{$type}/{$intake->id}";
            $answers = $type === 'family'
                ? [['parent_nz_citizen_or_resident' => 'Yes', 'supporting_parent_nz_status' => 'New Zealand resident or permanent resident visa holder']]
                : array_map(fn ($basis) => ['smc_employment_basis' => $basis], $bases);
            foreach ($answers as $value) {
                $this->actingAs($staff)->patchJson($url, ['fields' => ['vif_answers' => $value]])->assertOk();
                foreach ($value as $key => $answer) {
                    $this->assertSame($answer, $intake->refresh()->vif_answers[$key]);
                    $this->get($url.'/preview')->assertOk()->assertSee($answer);
                    $this->get($url.'/word')->assertOk()->assertSee($answer);
                }
                $pdf = $this->get($url.'/pdf')->assertOk();
                $text = preg_replace('/\s+/', ' ', (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText());
                $this->assertStringContainsString(end($value), $text);
                $this->assertSame('Draft', $intake->refresh()->status);
                $this->assertSame(0, LeadDocument::where('lead_id', $lead->id)->count());
            }
            $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => $answers[0]]])
                ->assertRedirect()->assertSessionHasNoErrors();
            foreach ($answers[0] as $key => $answer) {
                $this->assertSame($answer, $intake->refresh()->vif_answers[$key]);
            }
        }
    }

    public function test_source_gap_fields_remain_visa_scoped_and_keep_legacy_parent_yes_no(): void
    {
        $fields = collect(VifSupplement::schema('resident'))->flatMap(fn ($section) => $section['fields']);
        $this->assertSame([
            'Permanent or for a fixed term of at least 1 year', 'A contract for services',
            'I am an independent midwife practitioner with a letter of authority to claim under the Maternity Notice and registration with the Midwifery Council',
            'None of the above',
        ], $fields->firstWhere('key', 'smc_employment_basis')['options']);
        $familyFields = collect(VifSupplement::schema('family'))->flatMap(fn ($section) => $section['fields']);
        $this->assertSame(['Yes', 'No'], $familyFields->firstWhere('key', 'parent_nz_citizen_or_resident')['options']);
        $this->assertFalse(Validator::make(['vif_answers' => ['smc_employment_basis' => 'Guessed eligibility']], VifSupplement::rules('resident'))->passes());
        $this->assertFalse(Validator::make(['vif_answers' => ['supporting_parent_nz_status' => 'Resident']], VifSupplement::rules('work'))->passes());
        $this->assertStringNotContainsString('citizenship or residence status', json_encode(VifSupplement::sections('family', ['applying_as' => 'Partner', 'visa_type' => 'Residence class visa'])));
    }

    public static function recreationTransitions(): array
    {
        return [
            ['resident', 'work', 'smc_employment_basis', 'A contract for services'],
            ['work', 'resident', 'employer_nzbn', 'Synthetic employer number'],
            ['family', 'resident', 'parent_nz_citizen_or_resident', 'Yes'],
            ['resident', 'family', 'smc_employment_basis', 'A contract for services'],
        ];
    }

    #[DataProvider('recreationTransitions')]
    public function test_recreated_intake_keeps_shared_supplemental_answers_and_can_save_its_target_form(string $from, string $to, string $specificKey, string $specificValue): void
    {
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        foreach ([['SMC', 'Resident'], ['WORK_AEWV', 'Work'], ['FAMILY', 'Partnership']] as [$code, $category]) {
            VisaType::create(['code' => $code, 'name' => 'Synthetic '.$code, 'category' => $category]);
        }
        $class = ['resident' => \App\Models\ResidentIntake::class, 'work' => \App\Models\WorkIntake::class, 'family' => \App\Models\FamilyIntake::class][$from];
        $source = $class::create([
            'intake_id' => 'SOURCE-SYNTHETIC', 'first_name' => 'Synthetic', 'last_name' => 'Applicant', 'family_name' => 'Applicant',
            'email' => 'synthetic@example.test', 'phone' => '+64215550000', 'declaration_accepted' => true,
            'vif_answers' => ['middle_name' => 'Common retained answer', $specificKey => $specificValue],
        ])->fresh();
        $before = $source->getRawOriginal();
        $target = app(\App\Services\Immigration\IntakeTypeConverter::class)->recreateAs($source, $to);
        $this->assertSame(['middle_name' => 'Common retained answer'], $target->vif_answers);
        $this->assertSame('synthetic@example.test', $target->email);
        $this->assertSame('+64215550000', $target->phone);
        $this->assertFalse((bool) $target->declaration_accepted);
        $this->assertSame('Submitted', $target->status);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->patchJson("/portal/immigration/intakes/{$to}/{$target->id}", ['fields' => ['vif_answers' => $target->vif_answers]])
            ->assertOk();
        $this->assertTrue(Validator::make(['vif_answers' => $target->refresh()->vif_answers], VifSupplement::rules($to))->passes());
        $this->assertSame($before, $source->fresh()->getRawOriginal());
    }

    public function test_recreated_target_accepts_absent_or_malformed_legacy_supplement_without_changing_source(): void
    {
        Mail::fake();
        Notification::fake();
        VisaType::create(['code' => 'WORK_AEWV', 'name' => 'Synthetic work', 'category' => 'Work']);
        foreach ([null, 'legacy scalar'] as $answers) {
            $source = \App\Models\ResidentIntake::create([
                'intake_id' => 'SOURCE-'.uniqid(), 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
                'email' => 'synthetic@example.test', 'vif_answers' => $answers,
            ])->fresh();
            $before = $source->getRawOriginal();
            $target = app(\App\Services\Immigration\IntakeTypeConverter::class)->recreateAs($source, 'work');
            $this->assertNull($target->vif_answers);
            $this->assertTrue(Validator::make(['vif_answers' => $target->vif_answers], VifSupplement::rules('work'))->passes());
            $this->assertSame($before, $source->fresh()->getRawOriginal());
        }
    }
}
