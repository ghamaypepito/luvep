<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAssistant(string $reply = 'Here is a draft for you.'): void
    {
        \App\Models\Setting::set('claude_connector.staff_enabled', true, 'bool');
        config(['claude.api_key' => 'sk-ant-test-staff-connector-12345']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $reply]],
                'usage'   => ['input_tokens' => 20, 'output_tokens' => 22],
                'model'   => 'claude-sonnet-5-5',
            ], 200),
        ]);
    }

    public function test_staff_can_start_a_new_conversation_and_get_a_reply(): void
    {
        $this->fakeAssistant('Sure — here is your draft.');
        $staff = User::factory()->create(['role' => 'sales']);

        $res = $this->actingAs($staff)
            ->postJson('/api/ai/messages', ['message' => 'Draft a follow-up email.']);

        $res->assertOk()
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.content', 'Sure — here is your draft.');

        $this->assertSame(1, AiConversation::where('user_id', $staff->id)->count());
        // One user turn + one assistant turn persisted.
        $this->assertSame(2, AiMessage::count());
    }

    public function test_user_message_and_token_count_persist(): void
    {
        $this->fakeAssistant();
        $staff = User::factory()->create(['role' => 'education']);

        $this->actingAs($staff)->postJson('/api/ai/messages', ['message' => 'Summarise this student.']);

        $this->assertDatabaseHas('ai_messages', ['role' => 'user', 'content' => 'Summarise this student.']);
        $this->assertDatabaseHas('ai_messages', ['role' => 'assistant', 'token_count' => 42]);
    }

    public function test_staff_cannot_view_another_users_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $other = User::factory()->create(['role' => 'sales']);
        $convo = AiConversation::create(['user_id' => $owner->id, 'title' => 'Mine', 'last_message_at' => now()]);

        $this->actingAs($other)->getJson("/api/ai/conversations/{$convo->id}")->assertForbidden();
    }

    public function test_only_last_20_messages_are_sent_as_context(): void
    {
        $this->fakeAssistant();
        $staff = User::factory()->create(['role' => 'sales']);
        $convo = AiConversation::create(['user_id' => $staff->id, 'title' => 'Long', 'last_message_at' => now()]);
        foreach (range(1, 25) as $i) {
            AiMessage::create(['ai_conversation_id' => $convo->id, 'role' => $i % 2 ? 'user' : 'assistant', 'content' => "msg {$i}"]);
        }

        $this->actingAs($staff)->postJson('/api/ai/messages', ['message' => 'next', 'conversation_id' => $convo->id]);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $nonSystem = array_filter($messages, fn ($m) => $m['role'] !== 'system');
            return count($nonSystem) === 20;
        });
    }

    public function test_endpoints_return_disabled_when_ai_is_off(): void
    {
        config(['ai.enabled' => false]);
        $staff = User::factory()->create(['role' => 'sales']);

        $this->actingAs($staff)->getJson('/api/ai/conversations')
            ->assertOk()->assertJsonPath('ai_disabled', true);

        $this->actingAs($staff)->postJson('/api/ai/messages', ['message' => 'hi'])
            ->assertForbidden();
    }

    public function test_archived_conversations_do_not_appear_in_list(): void
    {
        $staff = User::factory()->create(['role' => 'sales']);
        AiConversation::create(['user_id' => $staff->id, 'title' => 'Active', 'last_message_at' => now()]);
        AiConversation::create(['user_id' => $staff->id, 'title' => 'Gone', 'last_message_at' => now(), 'is_archived' => true]);

        $this->actingAs($staff)->getJson('/api/ai/conversations')
            ->assertOk()->assertJsonCount(1, 'conversations');
    }

    public function test_archiving_hides_a_conversation(): void
    {
        $staff = User::factory()->create(['role' => 'sales']);
        $convo = AiConversation::create(['user_id' => $staff->id, 'title' => 'Bye', 'last_message_at' => now()]);

        $this->actingAs($staff)->deleteJson("/api/ai/conversations/{$convo->id}")->assertOk();
        $this->assertTrue($convo->fresh()->is_archived);
    }

    public function test_leads_cannot_use_staff_chat(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $this->actingAs($lead)->postJson('/api/ai/messages', ['message' => 'hi'])->assertForbidden();
    }
}
