<?php

namespace Tests\Feature\EpAgent;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RunPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ep_agent.enabled' => true, 'app.debug' => true]);
        Http::preventStrayRequests();
    }

    private function staff(string $role = 'education'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function conversation(User $owner): int
    {
        return $this->actingAs($owner)->postJson('/api/ep-agent/conversations', ['portal' => 'education', 'title' => 'secret-title-139'])->assertCreated()->json('id');
    }

    public function test_runs_are_blocked_without_paid_requests_and_replay_is_idempotent(): void
    {
        $owner = $this->staff();
        $id = $this->conversation($owner);
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'secret-prompt-139'])->assertCreated();
        $run = $this->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'test-once'])->assertCreated()
            ->assertJsonPath('state', 'blocked')->assertJsonPath('reason', 'provider_not_activated')->json('id');
        $this->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'test-once'])->assertCreated()->assertJsonPath('id', $run);
        $this->assertDatabaseCount('ep_runs', 1);
        $this->assertDatabaseCount('ep_usage_reservations', 0);
        $this->assertDatabaseCount('ep_events', 3); // conversation, message and one run
        $audit = json_encode(DB::table('ep_events')->get());
        $this->assertStringNotContainsString('secret-', $audit);
        Http::assertNothingSent();
    }

    public function test_ownership_revoked_access_archives_and_key_reuse_are_rechecked(): void
    {
        $owner = $this->staff();
        $id = $this->conversation($owner);
        $this->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'same-key'])->assertCreated();
        $another = $this->conversation($owner);
        $this->postJson("/api/ep-agent/conversations/$another/runs", ['idempotency_key' => 'same-key'])->assertConflict();
        $this->actingAs($this->staff('admin'))->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'other'])->assertNotFound();
        $owner->update(['role' => 'sales']);
        $this->actingAs($owner)->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'same-key'])->assertForbidden();
        $owner->update(['role' => 'education']);
        $this->deleteJson("/api/ep-agent/conversations/$id")->assertNoContent();
        $this->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'archived'])->assertConflict();
        config(['ep_agent.enabled' => false]);
        $this->postJson("/api/ep-agent/conversations/$another/runs", ['idempotency_key' => 'off'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_usage_reports_only_metadata_never_private_content(): void
    {
        $owner = $this->staff();
        $id = $this->conversation($owner);
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'secret-prompt-139'])->assertCreated();
        $this->postJson("/api/ep-agent/conversations/$id/runs", ['idempotency_key' => 'report'])->assertCreated();
        $this->getJson('/api/ep-agent/admin/usage')->assertForbidden();
        $res = $this->actingAs($this->staff('admin'))->getJson('/api/ep-agent/admin/usage')->assertOk()
            ->assertJsonPath('data.0.actor_id', $owner->id)->assertJsonPath('data.0.state', 'blocked')->assertJsonPath('data.0.actual_micros', null)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['id', 'actor_id', 'portal', 'state', 'reason', 'created_at', 'actual_micros', 'upper_bound_micros', 'input_tokens', 'output_tokens', 'cached_read_tokens', 'cached_write_tokens', 'tool_units'], array_keys($res->json('data.0')));
        $this->assertStringNotContainsString('secret-', $res->getContent());
    }

    public function test_database_failure_is_sanitized_even_in_debug_and_does_not_log_private_exception(): void
    {
        $id = $this->conversation($this->staff());
        Log::spy();
        Schema::drop('ep_messages');
        $response = $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'secret-prompt-139'])->assertStatus(503)
            ->assertExactJson(['error' => 'ep_unavailable'])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('secret-', $response->getContent());
        Log::shouldNotHaveReceived('error');
        Http::assertNothingSent();
    }
}
