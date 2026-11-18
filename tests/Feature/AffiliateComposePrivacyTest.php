<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AffiliateComposePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function lead(User $affiliate, array $attributes = []): Lead
    {
        return Lead::create(array_merge(['agent_id' => $affiliate->id, 'first_name' => 'Privacy', 'last_name' => 'Client', 'email' => 'privacy@example.test'], $attributes));
    }

    private function log(?User $sender, ?Lead $lead = null, array $attributes = []): MessageLog
    {
        return MessageLog::create(array_merge([
            'channel' => 'email', 'source' => 'compose', 'triggered_by_user_id' => $sender?->id,
            'recipient_type' => $lead ? 'lead' : 'raw', 'recipient_id' => $lead?->id,
            'recipient_address' => $lead?->email ?? 'typed@example.test',
            'subject' => 'Private email', 'body' => '<p>Private body</p>', 'status' => 'sent',
        ], $attributes));
    }

    public function test_affiliate_history_contains_only_own_sends_to_current_clients_or_typed_addresses(): void
    {
        $affiliate = User::factory()->create(['role' => 'agent']);
        $other = User::factory()->create(['role' => 'agent']);
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->lead($affiliate);
        $transferred = $this->lead($other, ['original_affiliate_id' => $affiliate->id]);
        $ownLead = $this->log($affiliate, $lead);
        $ownRaw = $this->log($affiliate);
        $this->log($affiliate, $transferred);
        $this->log($other);
        $this->log($admin, $lead);
        $this->log(null, $lead);
        $this->log($affiliate, $lead, ['source' => 'template']);
        $deletedLead = $this->lead($affiliate);
        $this->log($affiliate, $deletedLead);
        $deletedLead->delete();

        $this->actingAs($affiliate)->get('/portal/agent/compose')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/agent/Compose')
                ->has('sent', 2)->where('sent', fn ($sent) => collect($sent)->pluck('id')->sort()->values()->all() === [$ownLead->id, $ownRaw->id]));

        $this->actingAs($admin)->get('/admin/email/compose')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sent', 7));
    }

    public function test_affiliate_templates_are_scoped_in_compose_and_json_even_with_another_portal_grant(): void
    {
        $affiliate = User::factory()->create(['role' => 'agent', 'portal_access' => ['education']]);
        foreach (['', 'education', 'sales', 'immigration'] as $department) {
            MessageTemplate::create(['name' => 'Private '.$department, 'department' => $department, 'channels' => ['email'], 'email_body' => '<p>Private</p>', 'is_active' => true]);
        }
        $sample = MessageTemplate::where('key', 'affiliate_client_follow_up')->firstOrFail();
        MessageTemplate::create(['name' => 'Affiliate draft', 'department' => 'agent', 'channels' => ['email'], 'email_body' => '<p>Draft</p>', 'is_active' => false]);

        foreach (['/portal/agent/compose', '/portal/education/compose'] as $url) {
            $this->actingAs($affiliate)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('templates', 1)->where('templates.0.id', $sample->id)->where('templates.0.department', 'agent'));
        }
        $this->getJson('/api/message-templates')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $sample->id);
    }

    public function test_recipient_lookup_and_forged_sends_cannot_access_another_affiliates_client(): void
    {
        Mail::fake();
        $affiliate = User::factory()->create(['role' => 'agent']);
        $other = User::factory()->create(['role' => 'agent']);
        $own = $this->lead($affiliate);
        $foreign = $this->lead($other, ['original_affiliate_id' => $affiliate->id]);
        $this->lead($affiliate, ['email' => null]);
        $this->actingAs($affiliate)->getJson('/portal/agent/compose/leads?q=Privacy')
            ->assertOk()->assertJsonCount(1, 'leads')->assertJsonPath('leads.0.id', $own->id);
        $this->post('/portal/agent/compose/send', ['lead_ids' => [$own->id, $foreign->id], 'emails' => ['typed@example.test'], 'subject' => 'Hello', 'body' => '<p>Hello</p>'])
            ->assertForbidden();
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('message_logs', 0);

        $this->post('/portal/agent/compose/send', ['lead_ids' => [$own->id], 'emails' => ['typed@example.test'], 'subject' => 'Hello {{first_name}}', 'body' => '<p>Hello {{first_name}}</p>'])
            ->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertQueued(TemplatedMessage::class, 2);
        $this->assertSame(2, MessageLog::where('triggered_by_user_id', $affiliate->id)->count());
    }

    public function test_sub_agent_uses_parent_clients_but_does_not_read_the_parent_sent_mail(): void
    {
        $parent = User::factory()->create(['role' => 'agent']);
        $sub = User::factory()->create(['role' => 'sub_agent', 'parent_agent_id' => $parent->id]);
        $own = $this->lead($parent);
        $this->log($parent, $own);
        $this->log($sub, $own);
        $this->actingAs($sub)->getJson('/portal/sub_agent/compose/leads?q=Privacy')->assertOk()->assertJsonCount(1, 'leads');
        $this->getJson('/api/message-templates')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Affiliate client follow-up');

        $orphan = User::factory()->create(['role' => 'sub_agent', 'parent_agent_id' => null]);
        Lead::create(['first_name' => 'Privacy', 'last_name' => 'Unassigned', 'email' => 'unassigned@example.test']);
        $this->actingAs($orphan)->getJson('/portal/sub_agent/compose/leads?q=Privacy')->assertOk()->assertJsonCount(0, 'leads');
    }

    public function test_affiliates_cannot_read_or_modify_the_staff_template_and_branding_management_routes(): void
    {
        $affiliate = User::factory()->create(['role' => 'agent', 'portal_access' => ['education']]);
        $template = MessageTemplate::where('key', 'affiliate_client_follow_up')->firstOrFail();
        $this->actingAs($affiliate);
        foreach (['/portal/agent/email-templates', '/portal/education/email-templates/'.$template->id, '/portal/education/email-branding'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/portal/agent/email-templates/delete', ['ids' => [$template->id]])->assertForbidden();
        $this->post('/portal/education/email-templates/folders', ['name' => 'Unexpected'])->assertForbidden();
        $this->post('/portal/agent/email-branding/default', [])->assertForbidden();
        $this->assertFalse($template->fresh()->trashed());
    }

    public function test_super_admin_view_as_affiliate_cannot_recover_the_shared_sent_history(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $affiliate = User::factory()->create(['role' => 'agent']);
        $this->log($admin);
        $own = $this->log($affiliate);
        $this->actingAs($admin)->postJson('/admin/user-view/'.$affiliate->id)->assertOk();
        $this->get('/portal/agent/compose')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('sent', 1)->where('sent.0.id', $own->id)->has('templates', 1));
    }

    public function test_sample_migration_does_not_overwrite_personalised_or_deleted_templates(): void
    {
        $migration = require database_path('migrations/2026_11_18_210000_add_affiliate_follow_up_template.php');
        $template = MessageTemplate::where('key', 'affiliate_client_follow_up')->firstOrFail();
        $template->update(['email_body' => '<p>Staff personalised</p>']);
        $migration->up();
        $this->assertSame('<p>Staff personalised</p>', $template->fresh()->email_body);
        $template->delete();
        $migration->up();
        $this->assertTrue(MessageTemplate::withTrashed()->findOrFail($template->id)->trashed());
        $this->assertSame(1, MessageTemplate::withTrashed()->where('key', 'affiliate_client_follow_up')->where('department', 'agent')->count());
    }
}
