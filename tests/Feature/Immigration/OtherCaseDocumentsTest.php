<?php

namespace Tests\Feature\Immigration;

use App\Jobs\EvaluateCaseFindings;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OtherCaseDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
    }

    private function caseRecord(array $extra = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Case', 'is_immigration_case' => true,
            'document_checklist' => [['key' => 'passport', 'label' => 'Passport', 'required' => true]]], $extra));
    }

    private function url(Lead $lead): string
    {
        return "/portal/immigration/cases/{$lead->id}/documents/other";
    }

    private function pdf(string $name = 'support.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nSynthetic supporting file");
    }

    public static function staffRoles(): array
    {
        return array_map(fn ($role) => [$role], ['immigration', 'immigration_manager', 'immigration_adviser', 'admin', 'super_admin']);
    }

    #[DataProvider('staffRoles')]
    public function test_authorized_staff_can_append_repeated_batches_without_replacing_existing_files(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        $lead = $this->caseRecord();
        $existing = LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'existing.pdf', 'file_path' => 'original.pdf', 'status' => 'Approved', 'note' => 'Retain original review', 'reviewed_at' => now(), 'source_variant' => 'proof_of_payment']);
        Storage::disk('local')->put('original.pdf', 'Original bytes');
        $before = $existing->fresh()->getRawOriginal();
        $resolver = app(CaseChecklistService::class);
        $progress = $resolver->progress($lead);
        for ($batch = 0; $batch < 2; $batch++) {
            $files = array_map(fn () => $this->pdf('same-name.pdf'), range(1, 10));
            $this->from('/portal/immigration/cases/'.$lead->id.'/profile?tab=documents')->post($this->url($lead), ['files' => $files])
                ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        }
        $new = $lead->documents()->where('id', '!=', $existing->id)->get();
        $this->assertCount(20, $new);
        $this->assertCount(20, $new->pluck('file_path')->unique());
        foreach ($new as $file) {
            $this->assertNull($file->checklist_key);
            $this->assertNull($file->request_id);
            $this->assertSame('Submitted', $file->status);
            $this->assertSame('upload', $file->source);
            $this->assertSame($user->id, $file->uploaded_by);
            Storage::disk('local')->assertExists($file->file_path);
        }
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame('Original bytes', Storage::disk('local')->get('original.pdf'));
        $this->assertSame($progress['required_total'], $resolver->progress($lead->fresh())['required_total']);
        $this->assertSame($progress['required_approved'], $resolver->progress($lead->fresh())['required_approved']);
        $this->assertCount(21, $resolver->unstructuredDocuments($lead));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('lead_document_requests', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Bus::assertDispatched(EvaluateCaseFindings::class);
        $this->get('/portal/immigration/cases/'.$lead->id.'/profile?tab=documents')
            ->assertOk()->assertInertia(fn ($page) => $page->has('documents', 21));
    }

    public static function deniedRoles(): array
    {
        return array_map(fn ($role) => [$role], ['sales', 'education', 'english', 'finance', 'accommodation', 'agent', 'sub_agent', 'lead']);
    }

    #[DataProvider('deniedRoles')]
    public function test_extra_portal_grants_do_not_allow_unrelated_roles_to_use_the_staff_action(string $role): void
    {
        $lead = $this->caseRecord();
        $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration'], 'lead_id' => $lead->id]));
        $this->post($this->url($lead), ['files' => [$this->pdf()]])->assertForbidden();
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_noncase_and_deleted_case_are_rejected_while_legacy_cases_are_supported(): void
    {
        $plain = $this->caseRecord(['is_immigration_case' => false, 'education_stage' => 'Proposal Sent']);
        $this->post($this->url($plain), ['files' => [$this->pdf()]])->assertNotFound();
        $deleted = $this->caseRecord();
        $deleted->delete();
        $this->post($this->url($deleted), ['files' => [$this->pdf()]])->assertNotFound();
        $legacy = $this->caseRecord(['is_immigration_case' => false, 'education_stage' => 'Endorsed to Immigration']);
        $this->post($this->url($legacy), ['files' => [$this->pdf()]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $legacy->documents()->count());
        $this->assertFalse($legacy->fresh()->is_immigration_case);
    }

    public function test_validation_rejects_empty_oversize_unsupported_and_excess_batch_before_writing(): void
    {
        $lead = $this->caseRecord();
        foreach ([[], ['files' => []], ['files' => array_map(fn () => $this->pdf(), range(1, 11))],
            ['files' => [$this->pdf(), UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')]],
            ['files' => [UploadedFile::fake()->create('large.pdf', 51201, 'application/pdf')]]] as $payload) {
            $this->post($this->url($lead), $payload)->assertRedirect()->assertSessionHasErrors();
            $this->assertDatabaseCount('lead_documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_failed_database_batch_rolls_back_metadata_and_removes_only_new_files(): void
    {
        $lead = $this->caseRecord();
        Storage::disk('local')->put('keep.pdf', 'Keep original');
        LeadDocument::creating(function ($file) {
            if ($file->original_name === 'fail.pdf') {
                throw new \RuntimeException('Synthetic insert failure');
            }
        });
        $this->post($this->url($lead), ['files' => [$this->pdf('first.pdf'), $this->pdf('fail.pdf')]])
            ->assertRedirect()->assertSessionHasErrors('files');
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame(['keep.pdf'], Storage::disk('local')->allFiles());
        $this->assertSame('Keep original', Storage::disk('local')->get('keep.pdf'));
        Bus::assertNotDispatched(EvaluateCaseFindings::class);
    }

    public function test_private_document_access_keeps_client_ownership_checks(): void
    {
        $lead = $this->caseRecord();
        $this->post($this->url($lead), ['files' => [$this->pdf()]])->assertSessionHasNoErrors();
        $doc = $lead->documents()->sole();
        $this->get('/admin/documents/'.$doc->id.'/download')->assertDownload('support.pdf');
        $owner = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($owner)->get('/portal/lead/documents/'.$doc->id.'/download')->assertDownload('support.pdf');
        $other = $this->caseRecord();
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]))
            ->get('/portal/lead/documents/'.$doc->id.'/download')->assertForbidden();
    }
}
