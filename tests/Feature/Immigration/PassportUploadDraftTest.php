<?php

namespace Tests\Feature\Immigration;

use App\Jobs\ExtractPassportDraft;
use App\Models\ActivityLog;
use App\Models\CaseDependent;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\AIService;
use App\Services\Immigration\PassportPrefillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassportUploadDraftTest extends TestCase
{
    use RefreshDatabase;

    private AIService $ai;

    private Lead $case;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Queue::fake([ExtractPassportDraft::class]);
        config(['ai.passport_prefill_enabled' => true, 'ai.passport_prefill_processing_approved' => true]);
        $this->case = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'is_immigration_case' => true, 'passport_number' => 'OLD']);
        $this->staff = User::factory()->create(['role' => 'immigration']);
        $this->actingAs($this->staff);
        $this->ai = new class extends AIService
        {
            public int $calls = 0;

            public function isEnabled(): bool
            {
                return true;
            }

            public function configured(): bool
            {
                return true;
            }

            public function chat(array $messages, ?string $model = null): array
            {
                $this->calls++;

                return ['model' => 'synthetic-test', 'content' => '{"fields":{"passport_number":{"value":"NEW-SYNTHETIC","confidence":0.99,"page":1,"evidence":"Synthetic passport number"}}}'];
            }
        };
        $this->app->instance(AIService::class, $this->ai);
    }

    private function document(array $attributes = []): LeadDocument
    {
        $path = 'private/'.uniqid('passport-').'.pdf';
        Storage::disk('local')->put($path, '%PDF synthetic passport');

        return LeadDocument::create(array_merge(['lead_id' => $this->case->id, 'original_name' => 'passport.pdf', 'file_path' => $path, 'mime' => 'application/pdf', 'checklist_key' => 'passport', 'uploaded_by' => $this->staff->id], $attributes));
    }

    public function test_committed_staff_upload_queues_once_and_rollbacks_do_not_queue(): void
    {
        $doc = $this->document();
        Queue::assertPushed(ExtractPassportDraft::class, fn ($job) => $job->leadId === $this->case->id && $job->userId === $this->staff->id && $job->documentId === $doc->id);
        try {
            DB::transaction(function () {
                $this->document();
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic rollback', $e->getMessage());
        }
        Queue::assertPushed(ExtractPassportDraft::class, 1);
        $this->assertSame(0, $this->ai->calls);
    }

    public function test_background_job_prepares_a_private_review_draft_without_saving_and_is_idempotent(): void
    {
        $doc = $this->document();
        $job = new ExtractPassportDraft($this->case->id, $this->staff->id, $doc->id);
        Auth::logout();
        $job->handle(app(PassportPrefillService::class));
        $job->handle(app(PassportPrefillService::class));
        $this->assertSame(1, $this->ai->calls);
        $this->assertSame('OLD', $this->case->fresh()->passport_number);
        $this->assertSame($this->staff->id, ActivityLog::where('action', 'passport_prefill.requested')->sole()->user_id);
        $this->actingAs($this->staff)->getJson('/portal/immigration/cases/'.$this->case->id.'/passport-prefill')
            ->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('draft.document.id', $doc->id);
    }

    public function test_upload_endpoint_background_review_and_confirmation_form_one_workflow(): void
    {
        $this->post('/admin/leads/'.$this->case->id.'/documents/checklist/passport/upload', [
            'files' => [\Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic-passport.pdf', "%PDF-1.4\n% synthetic passport fixture")],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $doc = LeadDocument::where('lead_id', $this->case->id)->sole();
        Queue::assertPushed(ExtractPassportDraft::class, fn ($job) => $job->documentId === $doc->id);
        $this->assertSame(0, $this->ai->calls);
        $this->assertSame('OLD', $this->case->fresh()->passport_number);
        (new ExtractPassportDraft($this->case->id, $this->staff->id, $doc->id))->handle(app(PassportPrefillService::class));
        $base = '/portal/immigration/cases/'.$this->case->id.'/passport-prefill';
        $draft = $this->getJson($base)->assertOk()->assertJsonPath('status', 'ready')->json('draft');
        $this->assertSame('OLD', $this->case->fresh()->passport_number);
        $this->postJson($base.'/'.$draft['token'], ['confirmed' => true, 'fields' => ['passport_number']])->assertOk();
        $this->assertSame('NEW-SYNTHETIC', $this->case->fresh()->passport_number);
        $this->get('/portal/immigration/cases/'.$this->case->id.'/profile?tab=personal')->assertOk();
        $this->assertSame(1, $this->ai->calls);
    }

    public function test_outdated_and_disabled_jobs_never_send_to_the_provider(): void
    {
        $old = $this->document();
        $new = $this->document();
        (new ExtractPassportDraft($this->case->id, $this->staff->id, $old->id))->handle(app(PassportPrefillService::class));
        $this->assertSame(0, $this->ai->calls);
        config(['ai.passport_prefill_processing_approved' => false]);
        (new ExtractPassportDraft($this->case->id, $this->staff->id, $new->id))->handle(app(PassportPrefillService::class));
        $this->assertSame(0, $this->ai->calls);
        config(['ai.passport_prefill_processing_approved' => true]);
        $this->staff->update(['is_active' => false]);
        (new ExtractPassportDraft($this->case->id, $this->staff->id, $new->id))->handle(app(PassportPrefillService::class));
        $this->assertSame(0, $this->ai->calls);
    }

    public function test_other_documents_dependants_clients_and_off_switch_do_not_queue(): void
    {
        $this->document(['checklist_key' => 'cv', 'original_name' => 'cv.pdf']);
        $this->document(['source' => 'generated']);
        $dependent = CaseDependent::create(['lead_id' => $this->case->id, 'first_name' => 'Synthetic', 'family_name' => 'Child', 'relationship' => 'child']);
        $this->document(['dependent_id' => $dependent->id]);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $this->case->id]));
        $this->document();
        $this->actingAs($this->staff);
        config(['ai.passport_prefill_enabled' => false]);
        $this->document();
        Queue::assertNotPushed(ExtractPassportDraft::class);
        $this->assertSame(0, $this->ai->calls);
    }
}
