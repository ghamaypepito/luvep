<?php

namespace Tests\Feature\EpAgent;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ep_agent.enabled' => true]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'education', 'is_active' => true], $attributes));
    }

    private function conversation(User $user, array $attributes = []): int
    {
        return $this->actingAs($user)->postJson('/api/ep-agent/conversations', array_merge(['portal' => 'education'], $attributes))
            ->assertCreated()->json('id');
    }

    public function test_module_off_rejects_even_admin_and_does_not_contact_provider(): void
    {
        config(['ep_agent.enabled' => false]);
        $this->actingAs($this->staff(['role' => 'super_admin']))->postJson('/api/ep-agent/conversations', ['portal' => 'education'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_only_owner_can_read_append_or_archive_even_if_other_is_admin(): void
    {
        $owner = $this->staff();
        $id = $this->conversation($owner, ['title' => 'Private title']);
        $this->actingAs($owner)->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'Private message'])->assertCreated();
        $this->getJson("/api/ep-agent/conversations/$id")->assertOk()->assertJsonPath('messages.0.content', 'Private message');
        foreach ([$this->staff(), $this->staff(['role' => 'admin'])] as $other) {
            $this->actingAs($other)->getJson("/api/ep-agent/conversations/$id")->assertNotFound();
            $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'intrusion'])->assertNotFound();
            $this->deleteJson("/api/ep-agent/conversations/$id")->assertNotFound();
            $this->getJson('/api/ep-agent/conversations')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_identity_is_not_taken_from_request_and_context_cannot_change(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $id = $this->conversation($owner, ['user_id' => $other->id, 'role' => 'admin']);
        $this->assertDatabaseHas('ep_conversations', ['id' => $id, 'user_id' => $owner->id]);
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'hello', 'lead_id' => 123])->assertUnprocessable();
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => str_repeat('x', 8001)])->assertUnprocessable();
    }

    public function test_portal_names_and_external_or_inactive_roles_fail_closed(): void
    {
        foreach (['employment', 'residency', 'sales', 'unknown'] as $portal) {
            $this->actingAs($this->staff(['role' => 'super_admin']))->postJson('/api/ep-agent/conversations', ['portal' => $portal])->assertForbidden();
        }
        foreach (['lead', 'revoked_lead', 'agent', 'sub_agent', 'unknown'] as $role) {
            $this->actingAs($this->staff(['role' => $role, 'portal_access' => ['education']]))->postJson('/api/ep-agent/conversations', ['portal' => 'education'])->assertForbidden();
        }
        $this->actingAs($this->staff(['is_active' => false]))->postJson('/api/ep-agent/conversations', ['portal' => 'education'])->assertForbidden();
        $this->actingAs($this->staff(['role' => 'sales']))->postJson('/api/ep-agent/conversations', ['portal' => 'education'])->assertForbidden();
    }

    public function test_lead_access_requires_assignment_or_current_explicit_grant_and_department(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $lead = Lead::create(['first_name' => 'Student', 'last_name' => 'Test', 'email' => 'student@example.test', 'is_student' => true, 'assigned_to' => $owner->id]);
        $id = $this->conversation($owner, ['lead_id' => $lead->id]);
        $this->actingAs($other)->postJson('/api/ep-agent/conversations', ['portal' => 'education', 'lead_id' => $lead->id])->assertForbidden();
        DB::table('ep_record_grants')->insert(['user_id' => $other->id, 'lead_id' => $lead->id, 'portal' => 'education', 'expires_at' => now()->addHour()]);
        $otherId = $this->conversation($other, ['lead_id' => $lead->id]);
        DB::table('ep_record_grants')->where('user_id', $other->id)->update(['expires_at' => now()->subSecond()]);
        $this->getJson("/api/ep-agent/conversations/$otherId")->assertForbidden();
        $lead->update(['assigned_to' => $other->id]);
        $this->actingAs($owner)->getJson("/api/ep-agent/conversations/$id")->assertForbidden();
        $lead->update(['assigned_to' => $owner->id, 'is_student' => false]);
        $this->getJson("/api/ep-agent/conversations/$id")->assertForbidden();
        $this->getJson('/api/ep-agent/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revoked_portal_grant_prevents_resumed_chat_and_list_disclosure(): void
    {
        $owner = $this->staff(['role' => 'sales', 'portal_access' => ['education']]);
        $id = $this->conversation($owner);
        $owner->update(['portal_access' => []]);
        $this->getJson("/api/ep-agent/conversations/$id")->assertForbidden();
        $this->getJson('/api/ep-agent/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_archive_is_idempotent_and_hides_thread_with_bounded_ordered_history(): void
    {
        $owner = $this->staff();
        $id = $this->conversation($owner);
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'first'])->assertCreated();
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'second'])->assertCreated();
        $this->getJson("/api/ep-agent/conversations/$id")->assertJsonPath('messages.0.content', 'first')->assertJsonPath('messages.1.content', 'second')->assertHeader('Cache-Control', 'no-store, private');
        $this->deleteJson("/api/ep-agent/conversations/$id")->assertNoContent();
        $this->deleteJson("/api/ep-agent/conversations/$id")->assertNoContent();
        $this->getJson('/api/ep-agent/conversations')->assertJsonCount(0, 'data');
        $this->postJson("/api/ep-agent/conversations/$id/messages", ['content' => 'late'])->assertConflict();
    }
}
