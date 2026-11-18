<?php

namespace App\EpAgent;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RunService
{
    public function __construct(private ConversationService $conversations) {}

    public function create(User $user, int $conversationId, string $idempotencyKey): object
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $idempotencyKey), 422, 'ep_invalid_key');

        return DB::transaction(function () use ($user, $conversationId, $idempotencyKey) {
            // Serialize keys per actor, including concurrent requests for different conversations.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Conversation::where('user_id', $user->id)->whereKey($conversationId)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversations->read($user, $conversationId);
            abort_if($conversation->archived_at, 409, 'ep_archived');
            $existing = DB::table('ep_runs')->where('actor_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless((int) $existing->conversation_id === $conversationId, 409, 'ep_key_conflict');

                return $this->publicRun($existing->id);
            }
            $id = DB::table('ep_runs')->insertGetId([
                'actor_id' => $user->id, 'conversation_id' => $conversationId, 'idempotency_key' => $idempotencyKey,
                'portal' => $conversation->portal, 'state' => 'blocked', 'reason' => 'provider_not_activated',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ep_events')->insert(['actor_id' => $user->id, 'conversation_id' => $conversationId,
                'run_id' => $id, 'type' => 'run_blocked', 'created_at' => now()]);

            return $this->publicRun($id);
        }, 3);
    }

    private function publicRun(int $id): object
    {
        return DB::table('ep_runs')->where('id', $id)->first(['id', 'state', 'reason', 'created_at']);
    }
}
