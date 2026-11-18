<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendAffiliateAgreement;
use App\Mail\AffiliateAgreementInvitation;
use App\Models\AgentAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AffiliateAgreementHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=';

    private function setupAgreement(array $fields = []): array
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($staff)->post("/admin/agents/{$agent->id}/agreement/generate", $fields)->assertRedirect();

        return [$staff, $agent, AgentAgreement::firstOrFail()];
    }

    public function test_draft_is_private_and_issue_promotes_same_draft_without_losing_old_versions(): void
    {
        [$staff, $agent, $original] = $this->setupAgreement(['payment_timing' => 'Original timing']);
        $this->post("/admin/agents/{$agent->id}/agreement/generate", ['intent' => 'draft', 'payment_timing' => 'Reviewed timing'])->assertRedirect();
        $draft = AgentAgreement::latest('id')->firstOrFail();
        $this->assertTrue($draft->is_draft);
        $this->actingAs($agent)->get('/portal/agent/agreement')->assertInertia(fn ($p) => $p->where('agreement.id', $original->id)->has('agreements', 1));
        $this->get('/portal/agent/agreement/download?agreement='.$draft->id)->assertNotFound();
        $this->actingAs($staff)->post("/admin/agents/{$agent->id}/agreement/generate", [
            'draft_id' => $draft->id, 'payment_timing' => 'Final timing', 'expires_on' => now()->addYear()->toDateString(),
        ])->assertRedirect();
        $this->assertFalse($draft->fresh()->is_draft);
        $this->assertSame(2, AgentAgreement::count());
        Storage::disk('local')->assertExists($original->file_path);
        $this->actingAs($agent)->get('/portal/agent/agreement')->assertInertia(fn ($p) => $p->where('agreement.id', $draft->id)->has('agreements', 2));
    }

    public function test_history_downloads_are_scoped_and_private(): void
    {
        [$staff, $agent, $original] = $this->setupAgreement();
        $this->post("/admin/agents/{$agent->id}/agreement/generate")->assertRedirect();
        $other = User::factory()->create(['role' => 'agent']);
        $this->actingAs($other)->get('/portal/agent/agreement/download?agreement='.$original->id)->assertNotFound();
        $this->actingAs($agent)->get('/portal/agent/agreement/view?agreement='.$original->id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($staff)->get("/admin/agents/{$other->id}/agreement/download?agreement={$original->id}")->assertNotFound();
    }

    public function test_signatures_keep_the_issued_terms_and_lock_affiliate_details(): void
    {
        [$staff, $agent, $agreement] = $this->setupAgreement(['nz_1_5_amount' => '22,222', 'payment_timing' => 'Specific agreed terms']);
        $issued = Storage::disk('local')->get($agreement->issued_file_path);
        $this->actingAs($agent)->post('/portal/agent/agreement/sign?agreement='.$agreement->id, [
            'signer_name' => $agent->name, 'signature_data' => self::SIGNATURE, 'terms_accepted' => 1,
            'bank_name' => 'Client Bank', 'nz_1_5_amount' => '1',
        ])->assertRedirect();
        $signed = $agreement->fresh();
        $this->assertSame('22,222', $signed->fields['nz_1_5_amount']);
        $this->assertStringContainsString('Specific agreed terms', $signed->rendered_html);
        $this->assertSame('Client Bank', $signed->fields['bank_name']);
        $this->post('/portal/agent/agreement/details?agreement='.$agreement->id, ['bank_name' => 'Changed Bank'])->assertStatus(422);
        $this->assertSame('Client Bank', $agreement->fresh()->fields['bank_name']);
        $this->actingAs($staff)->post("/admin/agents/{$agent->id}/agreement/sign?agreement={$agreement->id}", [
            'signer_name' => 'Company', 'signature_data' => self::SIGNATURE,
        ])->assertRedirect();
        $this->assertSame('Signed', $agreement->fresh()->statusLabel());
        $this->assertSame($issued, Storage::disk('local')->get($agreement->issued_file_path));
        $this->assertStringContainsString(self::SIGNATURE, $agreement->fresh()->rendered_html);
    }

    public function test_named_stale_version_cannot_be_signed_and_missing_version_is_rejected_after_replacement(): void
    {
        [$staff, $agent, $original] = $this->setupAgreement();
        $this->post("/admin/agents/{$agent->id}/agreement/generate")->assertRedirect();
        $payload = ['signer_name' => 'Partner', 'signature_data' => self::SIGNATURE, 'terms_accepted' => 1];
        $this->actingAs($agent)->post('/portal/agent/agreement/sign?agreement='.$original->id, $payload)->assertStatus(422);
        $this->post('/portal/agent/agreement/sign', $payload)->assertStatus(422);
        $this->assertNull($original->fresh()->agent_signed_at);
        $this->assertNull(AgentAgreement::latest('id')->first()->agent_signed_at);
    }

    public function test_legacy_pdf_remains_readable_and_cannot_be_rewritten_using_current_template(): void
    {
        Storage::fake('local');
        $agent = User::factory()->create(['role' => 'agent']);
        Storage::disk('local')->put('old.pdf', 'original legacy bytes');
        $legacy = AgentAgreement::create(['agent_id' => $agent->id, 'file_path' => 'old.pdf', 'issued_file_path' => 'old.pdf', 'original_name' => 'old.pdf']);
        $this->actingAs($agent)->get('/portal/agent/agreement/download')->assertOk();
        $this->post('/portal/agent/agreement/details', ['bank_name' => 'New Bank'])->assertStatus(422);
        $this->post('/portal/agent/agreement/sign', ['signer_name' => 'Partner', 'signature_data' => self::SIGNATURE, 'terms_accepted' => 1])->assertStatus(422);
        $this->assertSame('original legacy bytes', Storage::disk('local')->get($legacy->file_path));
    }

    public function test_repository_filter_and_status_distinguish_actual_signatures_from_queued_mail(): void
    {
        [$staff, $agent, $agreement] = $this->setupAgreement();
        $agreement->update(['send_requested_at' => now()]);
        $this->assertSame('Generated', $agreement->fresh()->statusLabel());
        $this->get('/admin/agents/agreements?status=Sent')->assertInertia(fn ($p) => $p->has('agreements.data', 0));
        $agreement->update(['sent_at' => now()]);
        $this->get('/admin/agents/agreements?status=Sent')->assertInertia(fn ($p) => $p->has('agreements.data', 1));
        $this->get('/admin/agents/agreements?status=Signed')->assertInertia(fn ($p) => $p->has('agreements.data', 0));
        $this->actingAs($agent)->get('/admin/agents/agreements')->assertForbidden();
    }

    public function test_deleted_partner_history_remains_readable_without_reenabling_mutations(): void
    {
        [$staff, $agent, $agreement] = $this->setupAgreement();
        $agent->update(['role' => 'sales']);
        $this->get('/admin/agents/agreements')->assertOk()->assertInertia(fn ($p) => $p->where('agreements.data.0.profile_url', null));
        $this->get("/admin/agents/{$agent->id}/agreement/view?agreement={$agreement->id}")->assertOk();
        $this->post("/admin/agents/{$agent->id}/agreement/generate")->assertNotFound();
        $agent->update(['role' => 'agent']);
        $agent->delete();
        $this->get('/admin/agents/agreements?search='.urlencode($agent->name))->assertOk()
            ->assertInertia(fn ($p) => $p->where('agreements.data.0.partner_name', $agent->name)
                ->where('agreements.data.0.partner_archived', true)->where('agreements.data.0.profile_url', null));
        $this->get("/admin/agents/{$agent->id}/agreement/view?agreement={$agreement->id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get("/admin/agents/{$agent->id}/agreement/download?agreement={$agreement->id}")->assertOk();
        $this->post("/admin/agents/{$agent->id}/agreement/generate")->assertNotFound();
        $this->post("/admin/agents/{$agent->id}/agreement/send?agreement={$agreement->id}")->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'agent']))
            ->get("/admin/agents/{$agent->id}/agreement/download?agreement={$agreement->id}")->assertForbidden();
    }

    public function test_mail_job_sends_named_version_and_does_not_repeat_after_delivery(): void
    {
        Mail::fake();
        [$staff, $agent, $agreement] = $this->setupAgreement();
        $agreement->update(['send_requested_at' => now()]);
        $job = new SendAffiliateAgreement($agreement->id, $agent->email);
        $job->handle();
        $job->handle();
        Mail::assertSent(AffiliateAgreementInvitation::class, 1);
        $this->assertNotNull($agreement->fresh()->sent_at);
        Mail::assertSent(AffiliateAgreementInvitation::class, fn ($mail) => $mail->hasTo($agent->email) && $mail->agreement->id === $agreement->id);
    }

    public function test_mail_job_holds_changed_recipient_and_superseded_versions(): void
    {
        Mail::fake();
        [$staff, $agent, $agreement] = $this->setupAgreement();
        $agreement->update(['send_requested_at' => now()]);
        $oldEmail = $agent->email;
        $agent->update(['email' => 'changed@example.test']);
        (new SendAffiliateAgreement($agreement->id, $oldEmail))->handle();
        Mail::assertNothingSent();
        $this->assertNull($agreement->fresh()->sent_at);
        $this->assertNotNull($agreement->fresh()->send_error);
    }

    public function test_signing_uses_the_frozen_rendering_even_if_the_live_template_changes(): void
    {
        [$staff, $agent, $agreement] = $this->setupAgreement(['payment_timing' => 'Frozen agreed timing']);
        $original = $agreement->rendered_html;
        $directory = storage_path('framework/testing/changed-agreement-template');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($directory.'/agreements');
        \Illuminate\Support\Facades\File::put($directory.'/agreements/agent-referral.blade.php', '<html><body>Different terms from a later template.</body></html>');
        $finder = app('view')->getFinder();
        $paths = $finder->getPaths();
        $finder->setPaths([$directory]);
        $finder->flush();
        try {
            $this->actingAs($agent)->post('/portal/agent/agreement/sign?agreement='.$agreement->id, [
                'signer_name' => $agent->name, 'signature_data' => self::SIGNATURE, 'terms_accepted' => 1,
            ])->assertRedirect();
            $signed = $agreement->fresh()->rendered_html;
            $this->assertStringContainsString('Frozen agreed timing', $signed);
            $this->assertStringNotContainsString('Different terms from a later template', $signed);
            $stripSignature = fn ($html) => preg_replace('~<!--affiliate-(signature|date):agent-->.*?<!--/affiliate-\\1:agent-->~s', '', $html);
            $this->assertSame($stripSignature($original), $stripSignature($signed));
        } finally {
            $finder->setPaths($paths);
            $finder->flush();
            \Illuminate\Support\Facades\File::deleteDirectory($directory);
        }
    }

    public function test_issuing_an_older_draft_gets_a_new_current_version(): void
    {
        [$staff, $agent, $first] = $this->setupAgreement();
        $this->post("/admin/agents/{$agent->id}/agreement/generate", ['intent' => 'draft'])->assertRedirect();
        $draft = AgentAgreement::latest('id')->firstOrFail();
        $this->post("/admin/agents/{$agent->id}/agreement/generate")->assertRedirect();
        $issued = AgentAgreement::latest('id')->firstOrFail();
        $this->post("/admin/agents/{$agent->id}/agreement/generate", ['draft_id' => $draft->id])->assertRedirect();
        $this->assertGreaterThan($issued->version, $draft->fresh()->version);
        $this->assertTrue($draft->fresh()->canSign());
        $this->assertFalse($issued->fresh()->canSign());
        Storage::disk('local')->assertExists($first->file_path);
        Storage::disk('local')->assertExists($issued->file_path);
    }

    public function test_invalid_signature_content_is_rejected(): void
    {
        [$staff, $agent, $agreement] = $this->setupAgreement();
        $this->actingAs($agent)->post('/portal/agent/agreement/sign', [
            'signer_name' => 'Partner', 'signature_data' => 'data:image/png;base64,'.base64_encode('<script>alert(1)</script>'), 'terms_accepted' => 1,
        ])->assertSessionHasErrors('signature_data');
        $this->assertNull($agreement->fresh()->agent_signed_at);
    }
}
