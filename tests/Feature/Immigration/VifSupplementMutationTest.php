<?php

namespace Tests\Feature\Immigration;

use App\Models\Assessment;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use App\Models\WorkIntake;
use App\Services\Immigration\AssessmentReviewService;
use App\Services\OpenRouterService;
use App\Support\VifSupplement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VifSupplementMutationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
        $this->staff = User::factory()->create(['role' => 'immigration_manager']);
        $this->actingAs($this->staff);
        foreach ([['WORK_AEWV', 'Work'], ['POST_STUDY_WORK', 'Work'], ['STUDENT', 'Student'], ['FAMILY', 'Partnership'], ['SMC', 'Resident']] as [$code,$category]) {
            VisaType::create(['code' => $code, 'name' => 'Synthetic '.$code, 'category' => $category]);
        }
    }

    private function linked(string $type, array $fields = []): array
    {
        $visa = VisaType::where('code', ['work' => 'WORK_AEWV', 'student' => 'STUDENT', 'family' => 'FAMILY', 'resident' => 'SMC'][$type])->sole();
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Mutation', 'email' => 'synthetic@example.test', 'is_immigration_case' => true, 'inz_visa_type' => $visa->name, 'portal_invitation_status' => 'accepted']);
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id, 'is_active' => true]);
        $this->post('/portal/immigration/cases/'.$lead->id.'/assessment-invitation')->assertRedirect()->assertSessionHasNoErrors();
        $intake = Assessment::findOrFail($lead->refresh()->assessment_id)->intakeable;
        if ($type === 'family') {
            $fields += ['applying_as' => 'Dependent child', 'visa_type' => 'Residence class visa'];
        }$intake->update($fields);

        return [$lead, $intake, $client];
    }

    private function approve(Lead $lead, User $client): LeadDocument
    {
        $this->actingAs($client)->post('/portal/lead/vif')->assertRedirect();
        $doc = LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'vif')->sole();
        $doc->update(['status' => 'Approved', 'reviewed_by' => $this->staff->id, 'reviewed_at' => now()]);
        Notification::fake();

        return $doc->refresh();
    }

    public function test_legacy_keyed_and_single_lists_render_in_stable_order_without_mutating_source(): void
    {
        [$lead,$intake] = $this->linked('student', ['family_members' => ['old-key' => ['full_name' => 'First preserved member', 'unknown_column' => 'Retained data'], 'other-key' => ['name' => 'Second preserved member']]]);
        $before = $intake->refresh()->getRawOriginal();
        $this->getJson('/portal/immigration/intakes/student/'.$intake->id.'/data')->assertOk()->assertJsonPath('vif_values.family_members.0.name', 'First preserved member')->assertJsonPath('vif_values.family_members.0.unknown_column', 'Retained data');
        $this->get('/portal/immigration/intakes/student/'.$intake->id.'/preview')->assertOk()->assertSee('Family members (including current partner) 1')->assertSee('First preserved member')->assertSee('Second preserved member');
        $this->assertSame($before, $intake->refresh()->getRawOriginal());
        $single = VifSupplement::sections('visitor', ['nz_contacts' => ['first_name' => 'Single contact', 'family_name' => 'Preserved']]);
        $this->assertStringContainsString('Single contact', json_encode($single));
        $encoded = VifSupplement::sections('visitor', ['nz_contacts' => json_encode([['first_name' => 'Encoded legacy contact']])]);
        $this->assertStringContainsString('Encoded legacy contact', json_encode($encoded));
    }

    public function test_explicit_canonical_empty_wins_over_legacy_alias_in_read_write_and_export(): void
    {
        [$lead,$intake] = $this->linked('student', ['family_members' => [['name' => '', 'full_name' => 'DO NOT RESURRECT', 'occupation' => 'Retained occupation', 'unknown_column' => 'Preserved']]]);
        $html = $this->get('/portal/immigration/intakes/student/'.$intake->id.'/preview')->assertOk()->assertDontSee('DO NOT RESURRECT');
        $this->patchJson('/portal/immigration/intakes/student/'.$intake->id, ['fields' => ['family_members' => ['legacy-key' => ['name' => '', 'full_name' => 'DO NOT RESURRECT', 'unknown_column' => 'Preserved']]]])->assertOk()->assertJsonPath('vif_values.family_members.0.name', null);
        $this->assertSame('Preserved', $intake->refresh()->family_members[0]['unknown_column']);
    }

    public static function writers(): array
    {
        return [['staff'], ['client']];
    }

    #[DataProvider('writers')]
    public function test_work_category_changes_reclassify_only_the_paired_assessment_and_drop_inactive_answers(string $writer): void
    {
        [$lead,$intake,$client] = $this->linked('work', ['vif_answers' => ['work_visa_category' => 'Accredited Employer Work Visa', 'middle_name' => 'Shared name', 'employer_nzbn' => 'PRIVATE_AEWV_ONLY']]);
        $leadBefore = $lead->refresh()->getRawOriginal();
        $url = '/portal/immigration/intakes/work/'.$intake->id;
        $post = function (array $answers) use ($writer, $client, $url) {
            if ($writer === 'staff') {
                return $this->actingAs($this->staff)->patchJson($url, ['fields' => ['vif_answers' => $answers]])->assertOk();
            }

            return $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => $answers]])->assertRedirect()->assertSessionHasNoErrors();
        };
        $post(['work_visa_category' => 'Post-Study Work Visa', 'previous_post_study_work_visa' => 'No']);
        $this->assertEquals(VisaType::where('code', 'POST_STUDY_WORK')->value('id'), Assessment::find($lead->assessment_id)->visa_type_id);
        $this->assertArrayNotHasKey('employer_nzbn', $intake->refresh()->vif_answers);
        $post(['work_visa_category' => 'Accredited Employer Work Visa']);
        $this->assertEquals(VisaType::where('code', 'WORK_AEWV')->value('id'), Assessment::find($lead->assessment_id)->visa_type_id);
        $this->assertArrayNotHasKey('previous_post_study_work_visa', $intake->refresh()->vif_answers);
        $this->assertSame('Shared name', $intake->vif_answers['middle_name']);
        $this->assertSame($leadBefore, $lead->refresh()->getRawOriginal());
    }

    public function test_missing_work_category_mapping_rolls_back_the_edit(): void
    {
        [$lead,$intake] = $this->linked('work', ['vif_answers' => ['middle_name' => 'Original']]);
        VisaType::where('code', 'POST_STUDY_WORK')->delete();
        $before = $intake->refresh()->getRawOriginal();
        $this->patchJson('/portal/immigration/intakes/work/'.$intake->id, ['fields' => ['vif_answers' => ['work_visa_category' => 'Post-Study Work Visa']]])->assertUnprocessable();
        $this->assertSame($before, $intake->refresh()->getRawOriginal());
    }

    public function test_unchanged_client_save_preserves_approved_vif_and_sends_no_notification(): void
    {
        [$lead,$intake,$client] = $this->linked('work', ['vif_answers' => ['middle_name' => 'Same value']]);
        $doc = $this->approve($lead, $client);
        $before = $doc->getRawOriginal();
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => ['middle_name' => 'Same value']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $doc->refresh()->getRawOriginal());
        Notification::assertNothingSent();
    }

    public function test_family_context_only_change_refreshes_approved_vif_and_removes_private_answers(): void
    {
        [$lead,$intake,$client] = $this->linked('family', ['vif_answers' => ['supporting_parent_first_name' => 'PRIVATE_PARENT_HISTORY']]);
        $doc = $this->approve($lead, $client);
        $path = $doc->file_path;
        $this->actingAs($this->staff)->patchJson('/portal/immigration/intakes/family/'.$intake->id, ['fields' => ['applying_as' => 'Partner']])->assertOk();
        $this->assertSame('Checked', $doc->refresh()->status);
        $this->assertNotSame($path, $doc->file_path);
        $this->assertArrayNotHasKey('supporting_parent_first_name', $intake->refresh()->vif_answers);
        Storage::disk('local')->assertExists($path);
    }

    public function test_inactive_legacy_answers_are_absent_from_read_payload_and_ai_prompt_without_rewriting_source(): void
    {
        [$lead,$intake] = $this->linked('work', ['vif_answers' => ['work_visa_category' => 'Accredited Employer Work Visa', 'employer_nzbn' => 'ACTIVE_NZBN', 'inz_requested_medical' => 'PRIVATE_PSWV_HISTORY']]);
        $before = $intake->refresh()->getRawOriginal();
        $this->getJson('/portal/immigration/intakes/work/'.$intake->id.'/data')->assertOk()->assertDontSee('PRIVATE_PSWV_HISTORY');
        $prompt = '';
        $mock = \Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->once()->andReturnUsing(function ($messages) use (&$prompt) {
            $prompt = json_encode($messages);

            return json_encode(['summary' => 'Synthetic review', 'observations' => [], 'risks' => [], 'checklist' => [], 'adviser_note' => '', 'client_email' => ['subject' => '', 'body' => '']]);
        });
        $mock->shouldReceive('model')->andReturn('synthetic/model');
        $mock->shouldReceive('extractJson')->andReturnUsing(fn ($reply) => $reply);
        $this->instance(OpenRouterService::class, $mock);
        app(AssessmentReviewService::class)->review($intake, 'work', $this->staff);
        $this->assertStringContainsString('ACTIVE_NZBN', $prompt);
        $this->assertStringNotContainsString('PRIVATE_PSWV_HISTORY', $prompt);
        $this->assertSame($before, $intake->refresh()->getRawOriginal());
    }

    public function test_public_submission_drops_inactive_family_answers(): void
    {
        $this->post('/family-interest', ['first_name' => 'Synthetic', 'family_name' => 'Public', 'dob' => '1990-01-01', 'email' => 'public@example.test', 'phone' => '+64215550000', 'declaration_accepted' => true, 'applying_as' => 'Partner', 'visa_type' => 'Partnership visa', 'vif_answers' => ['supporting_parent_first_name' => 'PRIVATE_INACTIVE_PARENT']])->assertRedirect()->assertSessionHasNoErrors();
        $intake = \App\Models\FamilyIntake::sole();
        $this->assertArrayNotHasKey('supporting_parent_first_name', $intake->vif_answers ?? []);
    }

    public function test_partial_supplement_patch_keeps_fresh_sibling_values(): void
    {
        [$lead,$intake] = $this->linked('work', ['vif_answers' => ['middle_name' => 'Original', 'employer_nzbn' => 'Old sibling']]);
        WorkIntake::whereKey($intake->id)->update(['vif_answers' => json_encode(['middle_name' => 'Original', 'employer_nzbn' => 'Concurrent sibling'])]);
        $this->patchJson('/portal/immigration/intakes/work/'.$intake->id, ['fields' => ['vif_answers' => ['middle_name' => 'Changed only']]])->assertOk();
        $this->assertSame(['middle_name' => 'Changed only', 'employer_nzbn' => 'Concurrent sibling'], $intake->refresh()->vif_answers);
    }

    #[DataProvider('writers')]
    public function test_category_switch_discards_inactive_stale_list_before_validation(string $writer): void
    {
        [$lead,$intake,$client] = $this->linked('work', ['vif_answers' => ['work_visa_category' => 'Post-Study Work Visa']]);
        $answers = ['work_visa_category' => 'Accredited Employer Work Visa', 'health_countries' => ['stale' => ['country' => 'PRIVATE_UNUSED', 'from' => 'not a date']]];
        if ($writer === 'staff') {
            $this->patchJson('/portal/immigration/intakes/work/'.$intake->id, ['fields' => ['vif_answers' => $answers]])->assertOk();
        } else {
            $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => $answers]])->assertRedirect()->assertSessionHasNoErrors();
        }$this->assertArrayNotHasKey('health_countries', $intake->refresh()->vif_answers);
    }

    public function test_public_work_submission_discards_inactive_stale_list_before_validation(): void
    {
        $this->post('/work-interest', ['first_name' => 'Synthetic', 'family_name' => 'Public', 'dob' => '1990-01-01', 'email' => 'public@example.test', 'phone' => '+64215550000', 'declaration_accepted' => true, 'vif_answers' => ['work_visa_category' => 'Accredited Employer Work Visa', 'health_countries' => [['from' => 'not a date']]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('health_countries', WorkIntake::sole()->vif_answers);
    }

    public function test_noop_does_not_generate_first_vif_but_explicit_generation_still_does(): void
    {
        [$lead,$intake,$client] = $this->linked('work');
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lead_documents', 0);
        Notification::assertNothingSent();
        $this->post('/portal/lead/vif')->assertRedirect();
        $this->assertDatabaseCount('lead_documents', 1);
    }

    public function test_recreated_pswv_retains_its_explicit_requested_assessment_category(): void
    {
        $source = WorkIntake::create(['intake_id' => 'PSWV-CLONE', 'first_name' => 'Synthetic', 'family_name' => 'Clone', 'email' => 'clone@example.test', 'vif_answers' => ['work_visa_category' => 'Post-Study Work Visa']]);
        $target = app(\App\Services\Immigration\IntakeTypeConverter::class)->recreateAs($source, 'work');
        $this->assertEquals(VisaType::where('code', 'POST_STUDY_WORK')->value('id'), Assessment::where('intakeable_type', WorkIntake::class)->where('intakeable_id', $target->id)->sole()->visa_type_id);
    }

    private function residentPayload(): array
    {
        return ['first_name' => 'Synthetic', 'last_name' => 'Mutation', 'dob' => '1990-01-01', 'nationality' => 'India', 'email' => 'synthetic@example.test', 'phone' => '+64215550000', 'passport_number' => 'SYNTHETIC', 'passport_expiry' => '2031-01-01', 'issuing_country' => 'India', 'current_visa_type' => 'AEWV', 'current_visa_expiry' => '2027-01-01', 'nz_arrival_date' => '2022-01-01', 'job_title' => 'Synthetic role', 'employment_start' => '2022-02-01', 'employment_type' => 'Permanent full-time', 'hourly_rate' => '45.00', 'highest_qualification' => 'bachelor', 'nz_skilled_years' => '2', 'total_skilled_years' => '7', 'english_evidence' => 'Qualification(s)', 'include_family' => 'No'];
    }

    public function test_public_resident_edit_preserves_legacy_row_columns_and_refreshes_only_effective_content_changes(): void
    {
        $payload = $this->residentPayload() + ['vif_answers' => ['middle_name' => 'Same name', 'family_details' => [['name' => 'Synthetic relative', 'legacy_column' => 'Untouched legacy value']]]];
        [$lead,$intake,$client] = $this->linked('resident', $payload + ['edit_token' => 'synthetic-resident-edit']);
        $doc = $this->approve($lead, $client);
        $path = $doc->file_path;
        $url = '/resident-interest/edit/synthetic-resident-edit';
        $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Untouched legacy value', $intake->refresh()->vif_answers['family_details'][0]['legacy_column']);
        $this->assertSame('Approved', $doc->refresh()->status);
        $this->assertSame($path, $doc->file_path);
        $payload['vif_answers']['middle_name'] = 'Changed name';
        $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Checked', $doc->refresh()->status);
        $this->assertNotSame($path, $doc->file_path);
    }

    public function test_legacy_inactive_family_history_is_hidden_from_read_and_ai_without_rewriting_storage(): void
    {
        [$lead,$intake] = $this->linked('family', ['applying_as' => 'Partner', 'vif_answers' => ['supporting_parent_first_name' => 'PRIVATE_CHILD_HISTORY']]);
        $before = $intake->refresh()->getRawOriginal();
        $this->getJson('/portal/immigration/intakes/family/'.$intake->id.'/data')->assertOk()->assertDontSee('PRIVATE_CHILD_HISTORY');
        $prompt = '';
        $mock = \Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->once()->andReturnUsing(function ($messages) use (&$prompt) {
            $prompt = json_encode($messages);

            return '{"summary":"Synthetic","observations":[],"risks":[],"checklist":[],"adviser_note":"","client_email":{"subject":"","body":""}}';
        });
        $mock->shouldReceive('model')->andReturn('synthetic/model');
        $mock->shouldReceive('extractJson')->andReturnUsing(fn ($reply) => $reply);
        $this->instance(OpenRouterService::class, $mock);
        app(AssessmentReviewService::class)->review($intake, 'family', $this->staff);
        $this->assertStringContainsString('Applying As: Partner', $prompt);
        $this->assertStringNotContainsString('PRIVATE_CHILD_HISTORY', $prompt);
        $this->assertSame($before, $intake->refresh()->getRawOriginal());
    }

    #[DataProvider('writers')]
    public function test_malformed_family_context_is_rejected_without_removing_private_answers(string $writer): void
    {
        [$lead,$intake,$client] = $this->linked('family', ['vif_answers' => ['supporting_parent_first_name' => 'Retain source']]);
        $before = $intake->refresh()->getRawOriginal();
        if ($writer === 'staff') {
            $this->patchJson('/portal/immigration/intakes/family/'.$intake->id, ['fields' => ['applying_as' => ['Partner']]])->assertUnprocessable();
        } else {
            $this->actingAs($client)->postJson('/portal/lead/visa-assessment', ['field_values' => ['visa_type' => ['Wrong shape']]])->assertUnprocessable();
        }$this->assertSame($before, $intake->refresh()->getRawOriginal());
    }

    public function test_client_canonicalizes_legacy_rows_without_invalidating_unchanged_rendered_vif(): void
    {
        [$lead,$intake,$client] = $this->linked('student', ['family_members' => [['full_name' => 'Same relative', 'unknown_column' => 'Retained']]]);
        $doc = $this->approve($lead, $client);
        $before = $doc->getRawOriginal();
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['family_members' => [['name' => 'Same relative', 'unknown_column' => 'Retained']]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $doc->refresh()->getRawOriginal());
        $this->assertSame('Retained', $intake->refresh()->family_members[0]['unknown_column']);
        Notification::assertNothingSent();
    }

    public function test_model_mapping_and_backfill_respect_explicit_work_categories_without_guessing_aewv(): void
    {
        $pswv = WorkIntake::create(['intake_id' => 'PSWV-HISTORICAL', 'first_name' => 'Synthetic', 'family_name' => 'Historical', 'email' => 'history@example.test', 'vif_answers' => ['work_visa_category' => 'Post-Study Work Visa']]);
        $this->assertSame('POST_STUDY_WORK', \App\Support\IntakeVisaTypeMap::resolve($pswv)->code);
        $this->artisan('immigration:backfill-assessments', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('assessments', 0);
        $this->artisan('immigration:backfill-assessments')->assertSuccessful();
        $this->assertSame(\App\Support\IntakeVisaTypeMap::resolve($pswv)->id, Assessment::where('intakeable_type', WorkIntake::class)->where('intakeable_id', $pswv->id)->sole()->visa_type_id);
        VisaType::where('code', 'WORK_AEWV')->delete();
        $this->assertNull(\App\Support\IntakeVisaTypeMap::resolve(WorkIntake::class), 'AEWV must not fall back to a configured PSWV record');
    }

    #[DataProvider('writers')]
    public function test_a_partial_edit_can_replace_a_malformed_legacy_supplement_container(string $writer): void
    {
        [$lead, $intake, $client] = $this->linked('work', ['vif_answers' => 'legacy scalar']);
        if ($writer === 'staff') {
            $this->patchJson('/portal/immigration/intakes/work/'.$intake->id, ['fields' => ['vif_answers' => ['middle_name' => 'Corrected answer']]])->assertOk();
        } else {
            $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => ['middle_name' => 'Corrected answer']]])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(['middle_name' => 'Corrected answer'], $intake->refresh()->vif_answers);
    }

    public static function renderedWriters(): array
    {
        return [['staff'], ['client'], ['public']];
    }

    #[DataProvider('renderedWriters')]
    public function test_numeric_looking_identifiers_are_real_rendered_changes(string $writer): void
    {
        $payload = $this->residentPayload();
        $payload['passport_number'] = '001234';
        [$lead, $intake, $client] = $this->linked('resident', $payload + ['edit_token' => 'strict-rendered-id']);
        $doc = $this->approve($lead, $client);
        $oldPath = $doc->file_path;
        if ($writer === 'staff') {
            $this->actingAs($this->staff)->patchJson('/portal/immigration/intakes/resident/'.$intake->id, ['fields' => ['passport_number' => '1234']])->assertOk();
        } elseif ($writer === 'client') {
            $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['passport_number' => '1234']])->assertRedirect()->assertSessionHasNoErrors();
        } else {
            $this->post('/resident-interest/edit/strict-rendered-id', array_replace($payload, ['passport_number' => '1234']))->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('1234', $intake->refresh()->passport_number);
        $this->assertSame('Checked', $doc->refresh()->status);
        $this->assertNotSame($oldPath, $doc->file_path);
        Storage::disk('local')->assertExists($oldPath);
    }

    public static function vifFailures(): array
    {
        return [['render'], ['storage'], ['storage-exception'], ['pointer']];
    }

    #[DataProvider('vifFailures')]
    public function test_failed_client_generation_rolls_back_and_identical_retry_refreshes(string $failure): void
    {
        [$lead, $intake, $client] = $this->linked('work', ['vif_answers' => ['middle_name' => 'Original']]);
        $doc = $this->approve($lead, $client);
        $originalIntake = $intake->refresh()->getRawOriginal();
        $originalDoc = $doc->getRawOriginal();
        $disk = Storage::disk('local');
        $oldBytes = $disk->get($doc->file_path);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::getFacadeRoot();
        if ($failure === 'render') {
            \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('Synthetic render failure'));
        } elseif ($failure === 'pointer') {
            $failOnce = true;
            LeadDocument::updating(function () use (&$failOnce) {
                if ($failOnce) {
                    $failOnce = false;
                    throw new \RuntimeException('Synthetic pointer failure');
                }
            });
        } else {
            $failingDisk = \Mockery::mock($disk)->makePartial();
            $failingDisk->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk, $failure) {
                $disk->put($path, 'partial synthetic bytes');
                if ($failure === 'storage-exception') {
                    throw new \RuntimeException('Synthetic storage failure');
                }

                return false;
            });
            Storage::set('local', $failingDisk);
        }
        $oldFiles = $disk->allFiles('lead-documents/'.$lead->id);
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => ['middle_name' => 'Updated']]])->assertServerError();
        $this->assertSame($originalIntake, $intake->refresh()->getRawOriginal());
        $this->assertSame($originalDoc, $doc->refresh()->getRawOriginal());
        $this->assertSame($oldBytes, $disk->get($doc->file_path));
        $this->assertSame($oldFiles, $disk->allFiles('lead-documents/'.$lead->id), 'Failed candidates must not leave partial or orphan files');
        Notification::assertNothingSent();
        \Barryvdh\DomPDF\Facade\Pdf::swap($pdf);
        Storage::set('local', $disk);
        $this->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => ['middle_name' => 'Updated']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated', $intake->refresh()->vif_answers['middle_name']);
        $this->assertSame('Checked', $doc->refresh()->status);
        $this->assertNotSame($originalDoc['file_path'], $doc->file_path);
    }

    public function test_client_render_and_document_update_share_the_intake_lock_before_commit_notification(): void
    {
        [$lead, $intake, $client] = $this->linked('work', ['vif_answers' => ['middle_name' => 'Original']]);
        $doc = $this->approve($lead, $client);
        $baselineLevel = \Illuminate\Support\Facades\DB::transactionLevel();
        $events = [];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::getFacadeRoot();
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $content) use (&$events, $baselineLevel, $pdf) {
            $this->assertSame($baselineLevel + 1, \Illuminate\Support\Facades\DB::transactionLevel(), 'Render must still hold the edit transaction');
            $this->assertStringContainsString('Latest locked value', json_encode($content));
            $events[] = 'render';

            return $pdf->loadView($view, $content);
        });
        $disk = Storage::disk('local');
        $recordingDisk = \Mockery::mock($disk)->makePartial();
        $recordingDisk->shouldReceive('put')->once()->andReturnUsing(function ($path, $bytes) use (&$events, $baselineLevel, $disk) {
            $this->assertSame($baselineLevel + 1, \Illuminate\Support\Facades\DB::transactionLevel());
            $events[] = 'write';

            return $disk->put($path, $bytes);
        });
        Storage::set('local', $recordingDisk);
        LeadDocument::updating(function ($updating) use (&$events, $baselineLevel, $doc) {
            if ($updating->id === $doc->id) {
                $this->assertSame($baselineLevel + 1, \Illuminate\Support\Facades\DB::transactionLevel());
                $events[] = 'document';
            }
        });
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$events) {
            if (str_contains($query->sql, 'work_intakes') && str_starts_with($query->sql, 'select')) {
                $events[] = 'intake-read';
            }
        });
        Notification::shouldReceive('send')->once()->andReturnUsing(function () use (&$events, $baselineLevel) {
            $this->assertSame($baselineLevel, \Illuminate\Support\Facades\DB::transactionLevel(), 'Notice must follow commit');
            $events[] = 'notify';
        });
        $this->actingAs($client)->post('/portal/lead/visa-assessment', ['field_values' => ['vif_answers' => ['middle_name' => 'Latest locked value']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['render', 'write', 'document', 'notify'], array_values(array_filter($events, fn ($event) => $event !== 'intake-read')));
        $this->assertGreaterThanOrEqual(2, count(array_filter($events, fn ($event) => $event === 'intake-read')), 'Resolve then fresh locked read precede publishing');
    }
}
