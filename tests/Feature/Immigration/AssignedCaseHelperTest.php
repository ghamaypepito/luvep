<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\CaseDependent;
use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\PaymentProofReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssignedCaseHelperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function agent(array $extra = []): User
    {
        return User::factory()->create(array_merge(['role' => 'agent', 'module_permissions' => ['assigned_immigration_cases']], $extra));
    }

    private function caseFor(?User $helper = null, array $extra = []): Lead
    {
        $lead = Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Assigned', 'is_immigration_case' => true, 'immigration_stage' => 'For Assessment',
            'document_checklist' => [['key' => 'passport', 'label' => 'Passport', 'required' => true], ['key' => 'hidden', 'label' => 'Hidden upload', 'required' => true], ['key' => 'optional', 'label' => 'Optional upload', 'required' => false]],
            'hidden_track_documents' => ['hidden'], 'passport_number' => 'PRIVATE-PASSPORT', 'dob' => '1980-01-01', 'email' => 'private@example.test', 'immigration_assignee' => 'Existing adviser'], $extra));
        $lead->forceFill(['immigration_helper_id' => $helper?->id])->save();

        return $lead;
    }

    private function file(Lead $lead, array $extra = []): LeadDocument
    {
        $doc = LeadDocument::create(array_merge(['lead_id' => $lead->id, 'checklist_key' => 'passport', 'source' => 'upload', 'status' => 'Approved', 'original_name' => 'passport.pdf', 'file_path' => 'private/'.$lead->id.'/'.uniqid().'.pdf', 'mime' => 'application/pdf', 'size' => 20, 'note' => 'PRIVATE-REVIEW', 'notes' => ['private' => 'PRIVATE-NOTES']], $extra));
        Storage::disk('local')->put($doc->file_path, '%PDF-1.4 synthetic private file');

        return $doc;
    }

    private function base(Lead $lead): string
    {
        return '/portal/agent/assigned-cases/'.$lead->id;
    }

    public function test_module_is_opt_in_and_exact_agent_role_is_required(): void
    {
        $this->actingAs($this->agent(['module_permissions' => []]))->get('/portal/agent/assigned-cases')->assertForbidden();
        foreach (['admin', 'super_admin', 'sub_agent', 'immigration_manager', 'lead'] as $role) {
            $this->actingAs($this->agent(['role' => $role, 'portal_access' => ['agent']]))->get('/portal/agent/assigned-cases')->assertForbidden();
        }
        $this->actingAs($this->agent(['is_active' => false]))->get('/portal/agent/assigned-cases')->assertRedirect(route('login'));
    }

    public function test_only_assigned_cases_and_strict_document_dtos_are_exposed(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $unassigned = $this->caseFor();
        $referral = $this->caseFor(null, ['agent_id' => $agent->id, 'first_name' => 'UNASSIGNED-REFERRAL']);
        $nonCase = $this->caseFor($agent, ['is_immigration_case' => false]);
        $archived = $this->caseFor($agent);
        $archived->delete();
        $good = $this->file($case);
        foreach ([['checklist_key' => 'hidden'], ['source' => 'generated', 'source_variant' => 'engagement:written_agreement'], ['source_variant' => 'invoice', 'invoice_total' => 999], ['source_variant' => 'proof_of_payment'], ['checklist_key' => null, 'status' => 'Submitted']] as $extra) {
            $this->file($case, $extra);
        }
        $otherRequest = LeadDocumentRequest::create(['lead_id' => $unassigned->id, 'label' => 'Other client', 'requested_by' => $agent->id]);
        $this->file($case, ['request_id' => $otherRequest->id]);
        $this->actingAs($agent)->get('/portal/agent/assigned-cases')->assertOk()->assertInertia(fn ($p) => $p->has('cases.data', 1)->where('cases.data.0.id', $case->id));
        $response = $this->get($this->base($case))->assertOk();
        $response->assertInertia(fn ($p) => $p->has('documents', 1)->where('documents.0.id', $good->id)->missing('case.email')->missing('case.passport_number')->missing('caseOptions')->missing('financials')->missing('notes')->missing('intake')->missing('documents.0.note')->missing('documents.0.file_path'));
        $encoded = json_encode($response->inertiaProps());
        foreach (['PRIVATE-PASSPORT', 'PRIVATE-REVIEW', 'PRIVATE-NOTES', 'UNASSIGNED-REFERRAL'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        foreach ([$unassigned, $referral, $nonCase, $archived] as $denied) {
            $this->get($this->base($denied))->assertNotFound();
        }
        $this->assertDatabaseHas('case_audit_views', ['lead_id' => $case->id, 'viewer_id' => $agent->id, 'context' => 'assigned_case']);
        $this->get($this->base($case).'/documents/'.$good->id.'/download')->assertDownload($good->original_name)->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->base($unassigned).'/documents/'.$good->id.'/download')->assertNotFound();
        $this->get($this->base($case).'/documents/'.$this->file($unassigned)->id.'/download')->assertNotFound();
    }

    public function test_staff_assignment_requires_confirmation_eligible_opt_in_and_preserves_every_owner_and_role(): void
    {
        $helper = $this->agent();
        $owner = User::factory()->create(['role' => 'immigration_adviser']);
        $case = $this->caseFor(null, ['current_owner_id' => $owner->id, 'owner_since' => now()->subDay(), 'assigned_to' => $owner->id]);
        $before = $case->fresh()->getRawOriginal();
        $beforeUser = $helper->fresh()->getRawOriginal();
        $url = '/portal/immigration/cases/'.$case->id.'/helper';
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $this->actingAs($manager);
        $this->post($url, ['helper_id' => $helper->id])->assertSessionHasErrors('confirmed');
        $this->post($url, ['helper_id' => $this->agent(['module_permissions' => []])->id, 'expected_helper_id' => null, 'confirmed' => true])->assertSessionHasErrors('helper_id');
        $this->post($url, ['helper_id' => $helper->id, 'expected_helper_id' => null, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $case->refresh();
        $this->assertSame($helper->id, $case->immigration_helper_id);
        foreach (['current_owner_id', 'owner_since', 'immigration_assignee', 'assigned_to', 'agent_id', 'immigration_stage'] as $key) {
            $this->assertSame($before[$key], $case->getRawOriginal($key));
        }
        $this->assertSame($beforeUser, $helper->fresh()->getRawOriginal());
        $this->post($url, ['helper_id' => $helper->id, 'expected_helper_id' => null, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityLog::where('action', 'case.helper.assigned')->count());
        $this->assertSame($manager->id, ActivityLog::where('action', 'case.helper.assigned')->sole()->user_id);
        $this->post($url, ['helper_id' => null, 'expected_helper_id' => $helper->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertNull($case->fresh()->immigration_helper_id);
        $this->actingAs($helper)->get($this->base($case))->assertNotFound();
        foreach (['immigration', 'immigration_adviser', 'agent', 'education'] as $role) {
            $this->actingAs($this->agent(['role' => $role, 'portal_access' => ['immigration']]))->post($url, ['helper_id' => $helper->id, 'confirmed' => true])->assertForbidden();
        }
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_uploads_append_only_to_visible_requested_targets_and_cannot_forge_associations_or_review(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $other = $this->caseFor();
        $original = $this->file($case);
        $before = $original->fresh()->getRawOriginal();
        $request = LeadDocumentRequest::create(['lead_id' => $case->id, 'label' => 'Requested evidence', 'requested_by' => User::factory()->create()->id]);
        $foreign = LeadDocumentRequest::create(['lead_id' => $other->id, 'label' => 'Foreign evidence']);
        $upload = fn ($fields) => $this->post($this->base($case).'/uploads', array_merge(['file' => UploadedFile::fake()->create('new.pdf', 5, 'application/pdf')], $fields));
        $this->actingAs($agent);
        $upload(['checklist_key' => 'passport'])->assertRedirect()->assertSessionHasNoErrors();
        $upload(['request_id' => $request->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $new = $case->documents()->where('id', '!=', $original->id)->get();
        $this->assertCount(2, $new);
        foreach ($new as $doc) {
            $this->assertSame($agent->id, $doc->uploaded_by);
            $this->assertSame('Submitted', $doc->status);
            $this->assertNull($doc->reviewed_at);
            Storage::disk('local')->assertExists($doc->file_path);
        }
        foreach ([['checklist_key' => 'hidden'], ['checklist_key' => 'optional'], ['checklist_key' => 'unknown'], ['checklist_key' => '0'], ['request_id' => 0], ['request_id' => $foreign->id], ['checklist_key' => 'passport', 'request_id' => $request->id], ['checklist_key' => 'passport', 'dependent_id' => 999], ['checklist_key' => 'passport', 'status' => 'Approved']] as $fields) {
            $upload($fields)->assertSessionHasErrors();
        }
        $this->post($this->base($case).'/uploads', ['checklist_key' => 'passport', 'file' => UploadedFile::fake()->create('malware.php', 5, 'application/x-httpd-php')])->assertSessionHasErrors('file');
        $this->assertSame(3, $case->documents()->count());
        foreach (['/stage', '/review', '/notes', '/sign', '/payments'] as $path) {
            $this->post($this->base($case).$path, [])->assertNotFound();
        }
        $this->get('/portal/immigration/cases')->assertForbidden();
        $this->get('/portal/agent/cases/'.$case->id.'/profile')->assertForbidden();
        $this->get('/media-drive/api/folders?client_type=lead&client_id='.$case->id)->assertForbidden();
    }

    public function test_removed_assignment_module_and_newly_hidden_or_deleted_request_fail_closed_on_stale_upload(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $doc = $this->file($case);
        $url = $this->base($case);
        $this->actingAs($agent)->get($url)->assertOk();
        $case->forceFill(['immigration_helper_id' => null])->save();
        $this->get($url.'/documents/'.$doc->id.'/download')->assertNotFound();
        $this->post($url.'/uploads', ['checklist_key' => 'passport', 'file' => UploadedFile::fake()->create('new.pdf', 5, 'application/pdf')])->assertNotFound();
        $case->forceFill(['immigration_helper_id' => $agent->id])->save();
        $agent->update(['module_permissions' => []]);
        $this->get($url)->assertForbidden();
        $this->actingAs($agent->fresh())->post($url.'/uploads', ['checklist_key' => 'passport', 'file' => UploadedFile::fake()->create('new.pdf', 5, 'application/pdf')])->assertForbidden();
        $agent->update(['module_permissions' => ['assigned_immigration_cases']]);
        $this->actingAs($agent->fresh());
        $case->update(['hidden_track_documents' => ['hidden', 'passport']]);
        $this->post($url.'/uploads', ['checklist_key' => 'passport', 'file' => UploadedFile::fake()->create('new.pdf', 5, 'application/pdf')])->assertSessionHasErrors();
        $this->assertSame(1, $case->documents()->count());
    }

    public function test_revocation_between_initial_read_and_locked_write_creates_no_file(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $revoked = false;
        Lead::retrieved(function ($record) use ($case, &$revoked) {
            if ($record->id === $case->id && ! $revoked) {
                $revoked = true;
                Lead::whereKey($case->id)->update(['immigration_helper_id' => null]);
            }
        });
        $this->actingAs($agent)->post($this->base($case).'/uploads', ['checklist_key' => 'passport', 'file' => UploadedFile::fake()->create('stale.pdf', 5, 'application/pdf')])->assertNotFound();
        $this->assertTrue($revoked);
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_completed_deleted_and_financial_requests_are_not_upload_targets(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $done = LeadDocumentRequest::create(['lead_id' => $case->id, 'label' => 'Complete evidence']);
        $this->file($case, ['request_id' => $done->id]);
        $deleted = LeadDocumentRequest::create(['lead_id' => $case->id, 'label' => 'Removed request']);
        $deletedId = $deleted->id;
        $deleted->delete();
        $finance = LeadDocumentRequest::create(['lead_id' => $case->id, 'label' => 'Proof of payment']);
        $this->actingAs($agent)->get($this->base($case))->assertInertia(fn ($p) => $p->has('requests', 0));
        foreach ([$done->id, $deletedId, $finance->id] as $id) {
            $this->post($this->base($case).'/uploads', ['request_id' => $id, 'file' => UploadedFile::fake()->create('new.pdf', 5, 'application/pdf')])->assertSessionHasErrors('request_id');
        }
        $this->assertSame(1, $case->documents()->count());
    }

    public function test_media_attachment_lineage_and_dependent_files_cannot_escape_case_scope(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $other = $this->caseFor();
        $root = MediaFolder::create(['client_key' => 'lead:'.$other->id, 'lead_id' => $other->id, 'name' => 'Private other client', 'ancestry' => '/']);
        $asset = MediaFile::create(['folder_id' => $root->id, 'original_name' => 'Private media.pdf', 'file_path' => 'media-drive/private.pdf', 'mime' => 'application/pdf', 'size' => 20, 'sha256' => str_repeat('a', 64), 'upload_key' => 'helper-test', 'actor_key' => 'user:'.$agent->id]);
        $wrong = $this->file($case, ['media_file_id' => $asset->id, 'file_path' => $asset->file_path]);
        $dependent = CaseDependent::create(['lead_id' => $other->id, 'first_name' => 'Other', 'family_name' => 'Dependent', 'relationship' => 'child']);
        $depDoc = $this->file($case, ['dependent_id' => $dependent->id]);
        $this->actingAs($agent)->get($this->base($case))->assertInertia(fn ($p) => $p->has('documents', 0));
        foreach ([$wrong, $depDoc] as $doc) {
            $this->get($this->base($case).'/documents/'.$doc->id.'/download')->assertNotFound();
        }
        $this->getJson('/media-drive/api/folders?folder_id='.$root->id)->assertForbidden();
        $root->update(['lead_id' => $case->id, 'client_key' => 'lead:'.$case->id]);
        $this->get($this->base($case).'/documents/'.$wrong->id.'/download')->assertDownload($wrong->original_name);
        $wrong->update(['file_path' => 'private/incorrect-reference.pdf']);
        Storage::disk('local')->put($wrong->file_path, '%PDF-1.4 different bytes');
        $this->get($this->base($case).'/documents/'.$wrong->id.'/download')->assertNotFound();
    }

    public function test_referral_rights_remain_separate_and_helper_id_is_not_mass_assignable(): void
    {
        $agent = $this->agent(['module_permissions' => ['referral_cases', 'assigned_immigration_cases']]);
        $referral = $this->caseFor(null, ['agent_id' => $agent->id]);
        $assigned = $this->caseFor($agent);
        $this->actingAs($agent)->get('/portal/agent/cases')->assertOk()->assertInertia(fn ($p) => $p->has('cases', 1)->where('cases.0.id', $referral->id));
        $this->get('/portal/agent/cases/'.$assigned->id.'/profile')->assertNotFound();
        $this->get('/portal/agent/assigned-cases')->assertInertia(fn ($p) => $p->has('cases.data', 1)->where('cases.data.0.id', $assigned->id));
        $this->get($this->base($referral))->assertNotFound();
        $referral->fill(['immigration_helper_id' => $agent->id])->save();
        $this->assertNull($referral->fresh()->immigration_helper_id);
        $this->assertArrayNotHasKey('immigration_helper_id', $assigned->toArray());
    }

    public function test_stale_assignment_confirmation_cannot_replace_or_revoke_another_helpers_grant(): void
    {
        $first = $this->agent();
        $second = $this->agent();
        $case = $this->caseFor($first);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $url = '/portal/immigration/cases/'.$case->id.'/helper';
        $this->post($url, ['helper_id' => $second->id, 'expected_helper_id' => null, 'confirmed' => true])->assertSessionHasErrors('expected_helper_id');
        $this->assertSame($first->id, $case->fresh()->immigration_helper_id);
        $this->post($url, ['helper_id' => $second->id, 'expected_helper_id' => $first->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->post($url, ['helper_id' => null, 'expected_helper_id' => $first->id, 'confirmed' => true])->assertSessionHasErrors('expected_helper_id');
        $this->assertSame($second->id, $case->fresh()->immigration_helper_id);
    }

    public function test_unmatched_staff_shared_and_ordinary_uploads_with_financial_or_signature_metadata_are_denied(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $denied = [];
        foreach ([['checklist_key' => null, 'status' => 'StaffShared'], ['invoice_number' => 'LEGACY-1'], ['invoice_total' => 0], ['notes' => ['invoice_finance' => ['amount' => 20]]], ['notes' => ['payment_proof' => ['amount' => 20]]], ['notes' => ['signature_upload' => ['actor' => 1]]], ['notes' => ['staff_signature_upload' => ['actor' => 1]]], ['client_signature_path' => 'private/signature.png']] as $data) {
            $denied[] = $this->file($case, $data);
        }
        $this->actingAs($agent)->get($this->base($case))->assertInertia(fn ($p) => $p->has('documents', 0));
        foreach ($denied as $doc) {
            $this->get($this->base($case).'/documents/'.$doc->id.'/download')->assertNotFound();
        }
    }

    public function test_finance_associations_deny_legacy_uploads_even_when_their_type_fields_are_missing(): void
    {
        $agent = $this->agent();
        $case = $this->caseFor($agent);
        $invoice = $this->file($case);
        $proof = $this->file($case);
        FinanceReceivable::create(['invoice_no' => 'LEGACY-FINANCE', 'invoice_document_id' => $invoice->id, 'lead_id' => $case->id, 'client_name' => 'Synthetic', 'amount' => 20, 'currency' => 'NZD', 'status' => 'sent', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-30']);
        PaymentProofReview::create(['document_id' => $proof->id, 'invoice_document_id' => $invoice->id]);
        $this->actingAs($agent)->get($this->base($case))->assertInertia(fn ($p) => $p->has('documents', 0));
        foreach ([$invoice, $proof] as $doc) {
            $this->get($this->base($case).'/documents/'.$doc->id.'/download')->assertNotFound();
        }
    }
}
