<?php

namespace App\EpAgent;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(private Access $access) {}

    public function create(User $user, string $portal, ?int $leadId, string $title = 'New conversation'): Conversation
    {
        $this->access->assertPortal($user, $portal);
        if ($leadId !== null) {
            $this->access->assertLead($user, $portal, Lead::findOrFail($leadId));
        }

        return DB::transaction(function () use ($user, $portal, $leadId, $title) {
            $conversation = Conversation::create(['user_id' => $user->id, 'portal' => $portal, 'lead_id' => $leadId, 'title' => $title]);
            $this->event($user, $conversation->id, 'conversation_created');

            return $conversation;
        });
    }

    public function read(User $user, int $id): Conversation
    {
        $this->access->assertStaff($user);
        $conversation = Conversation::where('user_id', $user->id)->findOrFail($id);
        $this->access->assertPortal($user, $conversation->portal);
        if ($conversation->lead_id !== null) {
            $this->access->assertLead($user, $conversation->portal, Lead::findOrFail($conversation->lead_id));
        }

        return $conversation;
    }

    public function list(User $user, int $before = PHP_INT_MAX): \Illuminate\Support\Collection
    {
        return $this->access->conversations($user)->whereNull('archived_at')->where('id', '<', $before)
            ->orderByDesc('id')->limit(50)->get(['id', 'portal', 'lead_id', 'title', 'created_at', 'updated_at']);
    }

    public function messages(User $user, int $id, int $before = PHP_INT_MAX): \Illuminate\Support\Collection
    {
        $this->read($user, $id);

        return DB::table('ep_messages')->where('conversation_id', $id)->where('id', '<', $before)
            ->orderByDesc('id')->limit(50)->get(['id', 'role', 'content', 'created_at'])->reverse()->values();
    }

    public function append(User $user, int $id, string $content): object
    {
        abort_unless(mb_strlen(trim($content)) > 0 && mb_strlen($content) <= 8000, 422, 'ep_invalid_message');

        return DB::transaction(function () use ($user, $id, $content) {
            Conversation::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $conversation = $this->read($user, $id);
            abort_if($conversation->archived_at, 409, 'ep_archived');
            $messageId = DB::table('ep_messages')->insertGetId([
                'conversation_id' => $id, 'role' => 'user', 'content' => $content, 'created_at' => now(),
            ]);
            $conversation->touch();
            $this->event($user, $id, 'message_saved');

            return DB::table('ep_messages')->where('id', $messageId)->first(['id', 'role', 'content', 'created_at']);
        });
    }

    private function event(User $user, int $id, string $type): void
    {
        DB::table('ep_events')->insert(['actor_id' => $user->id, 'conversation_id' => $id, 'type' => $type, 'created_at' => now()]);
    }

    public function archive(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            Conversation::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $conversation = $this->read($user, $id);
            if (! $conversation->archived_at) {
                $conversation->update(['archived_at' => now()]);
                $this->event($user, $id, 'conversation_archived');
            }
        });
    }
}
