<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\EngagementPackArchive;
use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\PaymentProofReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngagementArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    private function lead(): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Archive', 'email' => 'archive@example.test', 'is_immigration_case' => true]);
    }

    private function document(Lead $lead, ?string $pack = 'selected-pack', string $variant = 'engagement:written_agreement'): LeadDocument
    {
        $path = 'synthetic/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF synthetic retained bytes');

        return LeadDocument::create(['lead_id' => $lead->id, 'uploaded_by' => User::factory()->create(['role' => 'admin'])->id,
            'original_name' => basename($path), 'file_path' => $path, 'mime' => 'application/pdf', 'size' => 29,
            'source' => 'generated', 'source_variant' => $variant, 'status' => 'StaffShared',
            'notes' => $pack === null ? null : ['engagement_pack_id' => $pack, 'engagement_sent_at' => now()->toIso8601String(), 'engagement_signing_token' => 'synthetic-token']]);
    }

    private function url(Lead $lead): string
    {
        return '/portal/immigration/cases/'.$lead->id.'/engagement/archive';
    }

    public function test_sent_signed_pack_is_hidden_only_in_engagement_with_finance_and_documents_unchanged(): void
    {
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $lead = $this->lead();
        $agreement = $this->document($lead);
        Storage::disk('local')->put('synthetic/signature.png', 'retained-signature');
        $agreement->update(['client_signed_at' => now(), 'client_signer_name' => 'Synthetic signer', 'client_signature_path' => 'synthetic/signature.png']);
        $invoice = $this->document($lead, 'selected-pack', 'invoice');
        $otherPack = $this->document($lead, 'other-pack');
        $otherCase = $this->document($this->lead(), 'selected-pack');
        $receivable = FinanceReceivable::create(['invoice_document_id' => $invoice->id, 'lead_id' => $lead->id,
            'invoice_no' => 'ARCHIVE-SYNTHETIC', 'client_name' => 'Synthetic Archive', 'amount' => 100, 'currency' => 'NZD', 'status' => 'sent',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString()]);
        $payment = CaseFinancePayment::create(['lead_id' => $lead->id, 'invoice_document_id' => $invoice->id, 'paid_at' => now()->toDateString(), 'amount' => 25, 'currency' => 'NZD', 'finance_verified' => true]);
        $proof = $this->document($lead, 'selected-pack', 'proof_of_payment');
        PaymentProofReview::create(['document_id' => $proof->id, 'invoice_document_id' => $invoice->id, 'finance_payment_id' => $payment->id, 'supporting' => false]);
        CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 100, 'currency' => 'NZD', 'notes' => 'Retain original financial data']);
        $finance = User::factory()->create(['role' => 'finance']);
        $financeUrl = '/portal/finance/receivables?q=ARCHIVE-SYNTHETIC';
        $financeBefore = $this->actingAs($finance)->get($financeUrl)->assertOk()->inertiaProps();
        $this->assertCount(1, $financeBefore['rows']);
        $tables = ['leads', 'lead_documents', 'finance_receivables', 'case_finance_payments', 'payment_proof_reviews', 'case_financials'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $files = [];
        foreach (Storage::disk('local')->allFiles() as $path) {
            $files[$path] = hash('sha256', Storage::disk('local')->get($path));
        }
        $this->actingAs($manager)->from('/portal/immigration/cases/engagement')->post($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $agreement->id])
            ->assertRedirect('/portal/immigration/cases/engagement')->assertSessionHasNoErrors();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table.' must be unchanged');
        }
        foreach ($files as $path => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($path)));
        }
        $this->assertSame(array_keys($files), Storage::disk('local')->allFiles());
        $this->assertSame($manager->id, EngagementPackArchive::sole()->archived_by);
        $audit = ActivityLog::where('action', 'engagement.archived')->sole();
        $this->assertSame($manager->id, $audit->user_id);
        $this->assertSame('selected-pack', $audit->properties['pack_id']);
        $financeAfter = $this->actingAs($finance)->get($financeUrl)->assertOk()->inertiaProps();
        $this->assertSame($financeBefore['rows'], $financeAfter['rows']);
        $this->assertSame($financeBefore['summary'], $financeAfter['summary']);
        $this->actingAs($manager);
        $rows = $this->get('/portal/immigration/cases/engagement')->assertOk()->inertiaProps()['generated'];
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$otherPack->id, $otherCase->id], collect($rows)->pluck('documents')->flatten(1)->pluck('id')->all());
        $this->assertModelExists($receivable);
        $this->assertModelExists($proof);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $owner = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($owner)->get('/portal/lead/documents/'.$agreement->id.'/download')->assertOk();
    }

    public function test_retry_is_idempotent_and_does_not_hide_another_pack(): void
    {
        $lead = $this->lead();
        $doc = $this->document($lead);
        $other = $this->document($lead, 'another-pack');
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        foreach (range(1, 2) as $attempt) {
            $this->post($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $doc->id])->assertRedirect();
        }
        $this->assertDatabaseCount('engagement_pack_archives', 1);
        $this->assertSame(1, ActivityLog::where('action', 'engagement.archived')->count());
        $this->assertModelExists($other);
    }

    public function test_legacy_and_modern_pack_filters_are_isolated_and_search_cannot_reintroduce_archives(): void
    {
        $lead = $this->lead();
        $legacy = $this->document($lead, null);
        $modern = $this->document($lead);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->post($this->url($lead), ['pack_id' => 'legacy', 'document_id' => $legacy->id])->assertRedirect();
        foreach (['', '?q=Synthetic'] as $search) {
            $rows = $this->get('/portal/immigration/cases/engagement'.$search)->assertOk()->inertiaProps()['generated'];
            $this->assertSame([$modern->id], collect($rows)->pluck('documents')->flatten(1)->pluck('id')->all());
        }
    }

    public function test_foreign_or_stale_document_and_non_case_targets_cannot_be_archived(): void
    {
        $lead = $this->lead();
        $own = $this->document($lead);
        $otherPack = $this->document($lead, 'other-pack');
        $foreign = $this->document($this->lead());
        $student = Lead::create(['first_name' => 'Education', 'last_name' => 'Only', 'is_student' => true]);
        $studentDoc = $this->document($student);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        foreach ([$otherPack->id, $foreign->id, 999999] as $id) {
            $this->postJson($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $id])->assertNotFound();
        }
        $this->postJson($this->url($student), ['pack_id' => 'selected-pack', 'document_id' => $studentDoc->id])->assertNotFound();
        $own->delete();
        $this->postJson($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $own->id])->assertNotFound();
        $this->assertDatabaseCount('engagement_pack_archives', 0);
    }

    public function test_archive_permissions_and_validation(): void
    {
        $lead = $this->lead();
        $doc = $this->document($lead);
        $payload = ['pack_id' => 'selected-pack', 'document_id' => $doc->id];
        $this->post($this->url($lead), $payload)->assertRedirect('/login');
        foreach (['lead', 'agent', 'education', 'finance', 'sales'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))
                ->postJson($this->url($lead), $payload)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->postJson($this->url($lead), [])->assertUnprocessable()->assertJsonValidationErrors(['pack_id', 'document_id']);
        $this->postJson($this->url($lead), ['pack_id' => str_repeat('x', 101), 'document_id' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('engagement_pack_archives', 0);
    }

    public function test_authorized_roles_can_archive_a_colleagues_pack(): void
    {
        foreach (['admin', 'super_admin', 'immigration', 'immigration_manager', 'immigration_adviser'] as $role) {
            $lead = $this->lead();
            $doc = $this->document($lead);
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $doc->id])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('engagement_pack_archives', 5);
    }

    public function test_archive_and_audit_roll_back_together(): void
    {
        $lead = $this->lead();
        $doc = $this->document($lead);
        ActivityLog::creating(fn ($log) => $log->action === 'engagement.archived' ? throw new \RuntimeException('Synthetic audit failure') : null);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->postJson($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $doc->id])->assertStatus(500);
        $this->assertDatabaseCount('engagement_pack_archives', 0);
        $this->assertModelExists($doc);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_archived_packs_are_excluded_before_the_workspace_document_limit(): void
    {
        $lead = $this->lead();
        $archived = $this->document($lead);
        $row = $archived->getAttributes();
        unset($row['id']);
        LeadDocument::insert(array_fill(0, 301, $row));
        $active = $this->document($lead, 'older-active');
        $active->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->post($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $archived->id])->assertRedirect();
        $rows = $this->get('/portal/immigration/cases/engagement')->assertOk()->inertiaProps()['generated'];
        $this->assertSame([$active->id], collect($rows)->pluck('documents')->flatten(1)->pluck('id')->all());
        $this->assertSame(303, LeadDocument::count());
    }

    public function test_legacy_immigration_handoff_can_be_archived_without_changing_the_case(): void
    {
        $lead = $this->lead();
        $lead->update(['is_immigration_case' => false, 'education_stage' => 'Endorsed to Immigration']);
        $doc = $this->document($lead);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->post($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $doc->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse((bool) $lead->fresh()->is_immigration_case);
        $this->assertSame('Endorsed to Immigration', $lead->education_stage);
        $this->assertDatabaseCount('engagement_pack_archives', 1);
    }

    public function test_inactive_account_deleted_case_and_invoice_target_cannot_be_archived(): void
    {
        $lead = $this->lead();
        $doc = $this->document($lead);
        $payload = ['pack_id' => 'selected-pack', 'document_id' => $doc->id];
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager', 'is_active' => false]))
            ->postJson($this->url($lead), $payload)->assertForbidden();
        $invoice = $this->document($lead, 'selected-pack', 'invoice');
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->postJson($this->url($lead), ['pack_id' => 'selected-pack', 'document_id' => $invoice->id])->assertNotFound();
        $lead->delete();
        $this->postJson($this->url($lead), $payload)->assertNotFound();
        $this->assertDatabaseCount('engagement_pack_archives', 0);
        $this->assertModelExists($invoice);
        Storage::disk('local')->assertExists($doc->file_path);
    }
}
