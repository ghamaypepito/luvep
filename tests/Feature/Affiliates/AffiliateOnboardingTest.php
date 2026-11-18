<?php

namespace Tests\Feature\Affiliates;

use App\Mail\AffiliateAgreementInvitation;
use App\Mail\AffiliateApplicationMail;
use App\Models\ActivityLog;
use App\Models\AffiliateApplication;
use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AffiliateOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function application(): AffiliateApplication
    {
        Mail::fake();
        Storage::fake('local');
        $this->post('/affiliate/apply', [
            'name' => 'Synthetic Applicant', 'email' => 'Applicant@Example.test', 'phone' => '12345', 'location' => 'Toronto', 'company' => 'Example Co', 'consent' => 1,
        ])->assertRedirect('/affiliate/apply');
        $app = AffiliateApplication::sole();
        $this->assertSame('applicant@example.test', $app->email);
        Mail::assertQueued(AffiliateApplicationMail::class, fn ($mail) => $mail->hasTo($app->email));
        $this->assertDatabaseMissing('users', ['email' => $app->email]);
        $this->assertDatabaseCount('leads', 0);
        $this->assertNotNull($app->consented_at);

        return $app;
    }

    private function signed(AffiliateApplication $app, string $action, ?AgentAgreement $agreement = null, $expiry = null): string
    {
        return $app->signedUrl($action, $agreement?->id, $expiry ?? now()->addHour());
    }

    private function approve(AffiliateApplication $app, User $admin): User
    {
        $this->post($this->signed($app, 'verify'))->assertRedirect();
        $this->assertDatabaseMissing('users', ['email' => $app->email]);
        $this->actingAs($admin)->post("/admin/agents/applications/{$app->id}/approve")->assertRedirect();
        $user = $app->refresh()->user;
        $this->assertFalse($user->is_active);
        $this->assertNull($user->ensureReferralCode());

        return $user;
    }

    public static function orders(): array
    {
        return [['agent'], ['company']];
    }

    #[DataProvider('orders')]
    public function test_activation_requires_approval_and_both_signatures_once(string $first): void
    {
        $app = $this->application();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post("/admin/agents/applications/{$app->id}/approve")->assertSessionHasErrors('application');
        $user = $this->approve($app, $admin);
        $this->post("/admin/users/{$user->id}/toggle-active")->assertSessionHasErrors('user');
        $this->post("/admin/agents/{$user->id}/lifecycle", ['action' => 'disable', 'expected' => 'awaiting_signatures'])->assertSessionHasErrors('user');
        $this->post("/admin/agents/{$user->id}/agreement/generate")->assertRedirect();
        $agreement = AgentAgreement::sole();
        $this->assertEquals($agreement->id, $app->refresh()->agreement_id);
        $this->post("/admin/agents/{$user->id}/agreement/send?agreement={$agreement->id}")->assertRedirect();
        Mail::assertSent(AffiliateAgreementInvitation::class, fn ($mail) => $mail->hasTo($app->email) && str_contains($mail->render(), 'signature='));
        $sign = $this->signed($app, 'sign', $agreement);
        $payload = ['signer_name' => 'Applicant', 'signature_data' => self::PNG, 'agreement_id' => $agreement->id, 'terms_accepted' => 1];
        $doSign = function (string $side) use ($admin, $user, $agreement, $payload, $sign) {
            if ($side === 'company') {
                return $this->actingAs($admin)->post("/admin/agents/{$user->id}/agreement/sign?agreement={$agreement->id}", $payload);
            }
            Auth::logout();

            return $this->post($sign, $payload);
        };
        $this->get($this->signed($app, 'status', $agreement))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
        $this->post($sign, ['terms_accepted' => 0] + $payload)->assertSessionHasErrors('terms_accepted');
        $this->post($sign, ['agreement_id' => $agreement->id + 1] + $payload)->assertUnprocessable();
        $doSign($first)->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNull($app->fresh()->activated_at);
        $doSign($first === 'agent' ? 'company' : 'agent')->assertRedirect();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotNull($app->fresh()->activated_at);
        $this->get($this->signed($app, 'document', $agreement))->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'affiliate.application.activated')->count());
        $this->actingAs($admin)->post("/admin/agents/{$user->id}/lifecycle", ['action' => 'disable', 'expected' => 'active'])->assertRedirect();
        $doSign('agent')->assertUnprocessable();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(1, ActivityLog::where('action', 'affiliate.application.activated')->count());
    }

    public function test_archive_retains_referrals_and_private_documents_and_restore_is_inactive(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'agent']);
        $code = $user->ensureReferralCode();
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Client', 'original_affiliate_id' => $user->id, 'agent_id' => $user->id]);
        $this->actingAs($admin)->post("/admin/agents/{$user->id}/agreement/generate")->assertRedirect();
        $agreement = AgentAgreement::sole();
        $this->post("/admin/agents/{$user->id}/lifecycle", ['action' => 'archive', 'expected' => 'active'])->assertRedirect();
        $this->get("/admin/agents/{$user->id}")->assertOk();
        $this->get('/admin/agents?account_state=archived')->assertInertia(fn ($page) => $page->where('summary.leads', 1)->where('agents.0.account_state', 'archived'));
        $this->get('/admin/agents')->assertInertia(fn ($page) => $page->where('summary.leads', 0));
        $this->get("/affiliate/register/$code")->assertNotFound();
        $this->get("/admin/agents/{$user->id}/agreement/download?agreement={$agreement->id}")->assertOk();
        $this->assertSame($user->id, $lead->fresh()->original_affiliate_id);
        $this->post("/admin/agents/{$user->id}/lifecycle", ['action' => 'restore', 'expected' => 'archived'])->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->get("/affiliate/register/$code")->assertNotFound();
        $this->post("/admin/agents/{$user->id}/lifecycle", ['action' => 'activate', 'expected' => 'disabled'])->assertRedirect();
        $this->get("/affiliate/register/$code")->assertOk();
    }

    public function test_duplicate_inputs_stay_private_and_existing_accounts_cannot_be_claimed(): void
    {
        $app = $this->application();
        $this->post('/affiliate/apply', ['name' => 'Sample', 'email' => 'sample@example.test', 'phone' => '321', 'location' => 'Canada'])->assertSessionHasErrors('consent');
        $this->post('/affiliate/apply', ['name' => 'Replacement', 'email' => $app->email, 'phone' => '321', 'location' => 'Elsewhere', 'consent' => 1])
            ->assertRedirect('/affiliate/apply')->assertSessionHas('application_submitted', true);
        $this->assertSame('Synthetic Applicant', $app->fresh()->name);
        Mail::assertQueued(AffiliateApplicationMail::class, 1);
        $this->post('/affiliate/apply/resend', ['email' => 'absent@example.test'])->assertRedirect('/affiliate/apply')->assertSessionHas('application_submitted', true);
        Mail::assertQueued(AffiliateApplicationMail::class, 1);
        $this->post('/affiliate/apply/resend', ['email' => $app->email])->assertRedirect('/affiliate/apply')->assertSessionHas('application_submitted', true);
        Mail::assertQueued(AffiliateApplicationMail::class, 2);
        $this->post($this->signed($app, 'verify'))->assertRedirect();
        $existing = User::factory()->create(['role' => 'sales', 'email' => 'APPLICANT@example.test']);
        $existing->delete();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post("/admin/agents/applications/{$app->id}/approve")->assertSessionHasErrors('application');
        $this->assertNull($app->fresh()->user_id);
        $this->assertSame('sales', $existing->fresh()->role);
        $this->assertTrue($existing->fresh()->trashed());
        $this->post("/admin/agents/applications/{$app->id}/decline")->assertRedirect();
        $this->post("/admin/agents/applications/{$app->id}/approve")->assertSessionHasErrors('application');
        $this->assertSame('Declined', $app->fresh()->status());
    }

    public function test_private_links_are_expiring_version_scoped_and_block_archive_or_changed_identity(): void
    {
        $app = $this->application();
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->approve($app, $admin);
        $base = "/admin/agents/{$user->id}/agreement";
        $this->post("$base/generate")->assertRedirect();
        $old = AgentAgreement::sole();
        $this->post("$base/generate", ['intent' => 'draft'])->assertRedirect();
        $draft = AgentAgreement::latest('id')->firstOrFail();
        $this->assertSame($old->id, $app->fresh()->agreement_id);
        $this->get($this->signed($app, 'document', $draft))->assertNotFound();
        $this->post("$base/generate", ['draft_id' => $draft->id])->assertRedirect();
        $current = $draft->fresh();
        $this->assertSame($current->id, $app->fresh()->agreement_id);
        $this->get($this->signed($app, 'document', $old))->assertNotFound();
        $this->get(str_replace('agreement='.$old->id, 'agreement='.$current->id, $this->signed($app, 'document', $old)))->assertForbidden();
        $this->get($this->signed($app, 'document', $current, now()->subMinute()))->assertForbidden();
        $other = AffiliateApplication::create(['email' => 'other@example.test', 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'consented_at' => now(), 'name' => 'Other Applicant', 'phone' => '321', 'location' => 'Canada']);
        $this->get($this->signed($other, 'document', $current))->assertNotFound();
        $current->update(['expires_on' => today()->subDay()]);
        $this->get($this->signed($app, 'document', $current))->assertUnprocessable();
        $current->update(['expires_on' => null]);
        $user->update(['email' => 'changed@example.test']);
        $this->get($this->signed($app, 'document', $current))->assertNotFound();
        $user->update(['email' => $app->email]);
        $this->delete("/admin/users/{$user->id}")->assertRedirect();
        $this->get($this->signed($app, 'document', $current))->assertNotFound();
        $current->update(['send_requested_at' => now()]);
        (new \App\Jobs\SendAffiliateAgreement($current->id, $app->email))->handle();
        Mail::assertNotSent(AffiliateAgreementInvitation::class);
        $this->assertNotNull($current->fresh()->send_error);
        $this->post("/admin/users/{$user->id}/restore")->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->post("/admin/users/{$user->id}/toggle-active")->assertSessionHasErrors('user');
        $this->assertNull($user->fresh()->ensureReferralCode());
    }

    public function test_signature_image_dimensions_are_bounded_before_rendering(): void
    {
        $app = $this->application();
        $user = $this->approve($app, User::factory()->create(['role' => 'admin']));
        $this->post("/admin/agents/{$user->id}/agreement/generate")->assertRedirect();
        $agreement = AgentAgreement::sole();
        $image = imagecreatetruecolor(4097, 1);
        ob_start();
        imagepng($image);
        $data = 'data:image/png;base64,'.base64_encode(ob_get_clean());
        $this->post($this->signed($app, 'sign', $agreement), ['agreement_id' => $agreement->id, 'signer_name' => 'Demo', 'terms_accepted' => 1, 'signature_data' => $data])->assertSessionHasErrors('signature_data');
        $this->assertNull($agreement->fresh()->agent_signed_at);
    }

    public function test_queued_invitation_only_renews_after_explicit_delivery_and_never_extends_its_parent_link(): void
    {
        $app = $this->application();
        $user = $this->approve($app, User::factory()->create(['role' => 'admin']));
        $this->post("/admin/agents/{$user->id}/agreement/generate")->assertRedirect();
        $agreement = AgentAgreement::sole();
        $this->post('/affiliate/apply/resend', ['email' => $app->email])->assertRedirect();
        Mail::assertNotQueued(AffiliateApplicationMail::class, fn ($mail) => str_contains($mail->link, 'agreement='));
        $this->post("/admin/agents/{$user->id}/agreement/send?agreement={$agreement->id}")->assertRedirect();
        $this->post('/affiliate/apply/resend', ['email' => $app->email])->assertRedirect();
        Mail::assertQueued(AffiliateApplicationMail::class, fn ($mail) => str_contains($mail->link, 'agreement='.$agreement->id));
        $expires = now()->addMinutes(3);
        $response = $this->get($this->signed($app, 'status', $agreement, $expires))->assertOk();
        $sign = $response->viewData('page')['props']['agreement']['sign_url'];
        parse_str(parse_url($sign, PHP_URL_QUERY), $query);
        $this->assertSame($expires->getTimestamp(), (int) $query['expires']);
    }

    public function test_application_resend_is_rate_limited_without_disclosing_email_existence(): void
    {
        Mail::fake();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/affiliate/apply/resend', ['email' => 'synthetic@example.test'])->assertRedirect('/affiliate/apply');
        }
        $this->post('/affiliate/apply/resend', ['email' => 'synthetic@example.test'])->assertTooManyRequests();
        Mail::assertNothingQueued();
    }

    public function test_management_rejects_staff_and_non_partner_targets(): void
    {
        $app = $this->application();
        $staff = User::factory()->create(['role' => 'sales', 'portal_access' => ['admin'], 'module_permissions' => ['agents']]);
        $partner = User::factory()->create(['role' => 'agent']);
        $this->actingAs($staff)->get('/admin/agents/applications')->assertForbidden();
        $this->post("/admin/agents/applications/{$app->id}/approve")->assertForbidden();
        $this->post("/admin/agents/{$partner->id}/lifecycle", ['action' => 'archive', 'expected' => 'active'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/agents/{$staff->id}/lifecycle", ['action' => 'disable', 'expected' => 'active'])->assertNotFound();
        $this->assertNull($partner->fresh()->deleted_at);
        $this->assertTrue($staff->fresh()->is_active);
    }
}
