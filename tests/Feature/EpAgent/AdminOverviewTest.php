<?php

namespace Tests\Feature\EpAgent;

use App\EpAgent\Conversation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ep_agent.enabled' => true, 'ep_agent.ui_enabled' => true, 'ep_agent.pilot_admins' => true]);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06 12:00:00', 'UTC'));
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function createRun(User $actor, array $data = []): int
    {
        $c = Conversation::create(['user_id' => $actor->id, 'portal' => 'education', 'title' => 'SECRET-TITLE']);
        DB::table('ep_messages')->insert(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'SECRET-PROMPT', 'created_at' => now()]);

        return DB::table('ep_runs')->insertGetId(array_merge(['actor_id' => $actor->id, 'conversation_id' => $c->id, 'portal' => 'education', 'state' => 'blocked', 'reason' => 'provider_not_activated', 'idempotency_key' => uniqid('SECRET-KEY-'), 'created_at' => now(), 'updated_at' => now()], $data));
    }

    private function account(array $data = []): int
    {
        return DB::table('ep_budget_accounts')->insertGetId(array_merge(['currency' => 'USD', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20), 'limit_micros' => 10000000, 'spent_micros' => 1250000, 'held_micros' => 250000, 'frozen' => false], $data));
    }

    private function usage(int $account, array $data = []): int
    {
        return DB::table('ep_usage_reservations')->insertGetId(array_merge(['account_id' => $account, 'idempotency_key' => uniqid('private-key-'), 'state' => 'reconciled', 'upper_bound_micros' => 1000000, 'actual_micros' => 125000, 'provider' => 'fixture', 'model' => 'test-model', 'pricing_version' => 'test-v1', 'input_tokens' => 10, 'output_tokens' => 20, 'cached_read_tokens' => 3, 'cached_write_tokens' => 4, 'tool_units' => 0, 'created_at' => now(), 'updated_at' => now()], $data));
    }

    public function test_authorization_requires_pilot_admin_and_no_user_view(): void
    {
        $staff = $this->admin('education');
        config(['ep_agent.pilot_user_ids' => [$staff->id]]);
        $this->actingAs($staff)->getJson('/api/ep-agent/admin/overview')->assertForbidden();
        $this->getJson('/admin/ep-agent')->assertForbidden();
        $admin = $this->admin('super_admin');
        $this->actingAs($admin)->get('/admin/ep-agent')->assertOk()->assertInertia(fn ($p) => $p->component('admin/EpAgentOverview', false));
        $this->postJson('/admin/user-view/'.$staff->id)->assertOk();
        $this->getJson('/api/ep-agent/admin/overview')->assertForbidden();
        $this->getJson('/admin/ep-agent')->assertForbidden();
    }

    public function test_list_detail_and_overview_exclude_private_chat_and_secret_values(): void
    {
        $admin = $this->admin('super_admin');
        $owner = $this->admin();
        $id = $this->createRun($owner, ['reason' => 'SECRET-RAW-ERROR']);
        Setting::set('claude_connector.api_key', Crypt::encryptString('SECRET-API-KEY-123456789'), 'string');
        Setting::set('claude_connector.staff_instructions', 'SECRET-INSTRUCTIONS');
        foreach (['/api/ep-agent/admin/overview', '/api/ep-agent/admin/runs', '/api/ep-agent/admin/runs/'.$id] as $url) {
            $res = $this->actingAs($admin)->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringNotContainsString('SECRET-', $res->getContent());
        }
        $detail = $this->getJson('/api/ep-agent/admin/runs/'.$id)->assertJsonMissingPath('run.conversation_id')->assertJsonMissingPath('run.idempotency_key')->assertJsonPath('run.actual_micros', null);
        $this->assertSame('Status requires review', $detail->json('run.reason_label'));
        Http::assertNothingSent();
    }

    public function test_recorded_usage_zero_unknown_holds_and_orphans_stay_distinct(): void
    {
        $admin = $this->admin();
        $a = $this->account();
        $u = $this->usage($a);
        $id = $this->createRun($admin, ['reservation_id' => $u, 'state' => 'succeeded']);
        $this->usage($a, ['state' => 'uncertain', 'actual_micros' => null, 'upper_bound_micros' => 500000, 'created_at' => now()->subMonths(2)]);
        $this->usage($a, ['actual_micros' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cached_read_tokens' => 0, 'cached_write_tokens' => 0]);
        $res = $this->actingAs($admin)->getJson('/api/ep-agent/admin/overview')->assertOk()->assertJsonPath('ledger.actual_micros', '125000')->assertJsonPath('ledger.open_hold_micros', '500000')->assertJsonPath('ledger.unresolved_count', 1)->assertJsonPath('ledger.reconciled_count', 2)->assertJsonPath('ledger.unattributed_count', 1)->assertJsonPath('ledger.input_tokens', 10);
        $this->getJson('/api/ep-agent/admin/runs/'.$id)->assertJsonPath('run.actual_micros', '125000')->assertJsonPath('run.input_tokens', 10);
        $blocked = $this->createRun($admin);
        $this->getJson('/api/ep-agent/admin/runs/'.$blocked)->assertJsonPath('run.actual_micros', null)->assertJsonPath('run.input_tokens', null);
        $res->assertJsonPath('paid_enabled', false);
    }

    public function test_budget_accounts_keep_their_own_periods_and_freeze_states(): void
    {
        $admin = $this->admin();
        $active = $this->account();
        $frozen = $this->account(['frozen' => true, 'spent_micros' => 11000000]);
        $expired = $this->account(['ends_at' => now()->subHour()]);
        $res = $this->actingAs($admin)->getJson('/api/ep-agent/admin/overview')->assertOk();
        $accounts = collect($res->json('budgets.data'))->keyBy('id');
        $this->assertSame('8500000', $accounts[$active]['available_micros']);
        $this->assertSame('active', $accounts[$active]['status']);
        $this->assertSame('0', $accounts[$frozen]['available_micros']);
        $this->assertSame('frozen', $accounts[$frozen]['status']);
        $this->assertSame('0', $accounts[$expired]['available_micros']);
        $this->assertSame('expired', $accounts[$expired]['status']);
        $res->assertJsonMissingPath('budgets.shared_limit_micros');
    }

    public function test_filters_replace_pages_and_window_is_server_defined(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 27; $i++) {
            $this->createRun($admin);
        }
        $this->createRun($admin, ['portal' => 'immigration']);
        $this->createRun($admin, ['created_at' => now()->subDays(60)]);
        $res = $this->actingAs($admin)->getJson('/api/ep-agent/admin/runs?portal=education&state=blocked&period=month')->assertOk()->assertJsonCount(25, 'data');
        $this->getJson('/api/ep-agent/admin/runs?portal=education&state=blocked&before='.$res->json('next_before'))->assertJsonCount(2, 'data');
        $this->getJson('/api/ep-agent/admin/overview')->assertJsonPath('runs.total', 28);
        foreach (['portal=employment', 'state=SECRET', 'period=all', 'before=-1'] as $query) {
            $this->getJson('/api/ep-agent/admin/runs?'.$query)->assertUnprocessable();
        }
    }

    public function test_connector_status_is_super_admin_only_and_never_an_ep_connection(): void
    {
        config(['claude.api_key' => 'SECRET-ENV-KEY-123456789']);
        $this->actingAs($this->admin())->getJson('/api/ep-agent/admin/overview')->assertOk()->assertJsonPath('connector.can_manage', false)->assertJsonMissingPath('connector.legacy_configured')->assertJsonPath('connector.ep_transport_connected', false);
        $this->actingAs($this->admin('super_admin'))->getJson('/api/ep-agent/admin/overview')->assertOk()->assertJsonPath('connector.can_manage', true)->assertJsonPath('connector.legacy_configured', true)->assertJsonPath('connector.settings_url', '/admin/ai-connectors')->assertDontSee('SECRET-ENV');
    }

    public function test_empty_ledger_and_unconfigured_budgets_are_explicit_and_disabled_module_denies(): void
    {
        $this->actingAs($this->admin())->getJson('/api/ep-agent/admin/overview')->assertOk()->assertJsonPath('ledger.actual_micros', '0')->assertJsonPath('ledger.reconciled_count',0)->assertJsonCount(0,'budgets.data');
        config(['ep_agent.enabled' => false]);
        $this->getJson('/api/ep-agent/admin/overview')->assertForbidden();
    }
}
