<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Services\AIService;
use App\Services\AiChatService;
use Illuminate\Http\Request;

/**
 * JSON endpoints behind the topbar AI chat panel. Session-authenticated
 * (lives in the web auth group), so the frontend sends the X-XSRF-TOKEN
 * header on writes. Every read/write is scoped to the current user's own
 * conversations. Leads (external clients) are blocked — staff only.
 */
class AiChatController extends Controller
{
    public function __construct(
        protected AiChatService $chatService,
        protected AIService $ai,
    ) {}

    /** GET /api/ai/conversations — the user's recent, non-archived threads. */
    public function index(Request $request)
    {
        if (! $this->ai->isEnabled()) {
            return response()->json(['conversations' => [], 'ai_disabled' => true]);
        }

        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('is_archived', false)
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get(['id', 'title', 'last_message_at']);

        return response()->json(['conversations' => $conversations]);
    }

    /** GET /api/ai/conversations/{conversation} — full message history. */
    public function show(Request $request, AiConversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        if ($conversation->subject_type === 'lead') {
            $subject = \App\Models\Lead::findOrFail($conversation->subject_id);
            abort_unless(\App\Support\AiScope::canAccessLead($request->user(), $subject), 403);
        }

        return response()->json([
            'conversation' => $conversation,
            'messages'     => $conversation->messages, // ordered asc by the relation
        ]);
    }

    /** POST /api/ai/messages — send a turn; conversation_id optional (new thread if absent). */
    public function sendMessage(Request $request)
    {
        if (! $this->ai->isEnabled()) {
            return response()->json(['error' => 'AI is currently disabled.'], 403);
        }

        abort_if($request->user()->isLead(), 403, 'AI chat is for staff only.');

        $data = $request->validate([
            'message'         => 'required|string|max:8000',
            'conversation_id' => 'nullable|integer|exists:ai_conversations,id',
            // Optional record scope — "ask about this lead / student / case".
            'subject_type'    => 'nullable|in:lead',
            'subject_id'      => 'nullable|integer|exists:leads,id',
        ]);

        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = AiConversation::findOrFail($data['conversation_id']);
            abort_unless($conversation->user_id === $request->user()->id, 403);
        }

        // Resolve + AUTHORISE the record before the model ever sees it. Scope
        // is enforced here, not delegated to the endpoint (see docs/ai-agent).
        $subject = null;
        if (! empty($data['subject_id'])) {
            $subject = \App\Models\Lead::findOrFail($data['subject_id']);
            abort_unless(\App\Support\AiScope::canAccessLead($request->user(), $subject), 403,
                'You do not have access to this record.');
        }

        // Resumed record conversations must retain their record scope and recheck access.
        if ($conversation && $subject && $conversation->subject_type !== 'lead') {
            abort(422, 'Start a new record conversation to attach a record.');
        }
        if ($conversation && $conversation->subject_type === 'lead') {
            if ($subject && $subject->id !== $conversation->subject_id) {
                abort(422, 'Start a new conversation to change the record.');
            }
            $subject = \App\Models\Lead::findOrFail($conversation->subject_id);
            abort_unless(\App\Support\AiScope::canAccessLead($request->user(), $subject), 403);
        }

        $result = $this->chatService->sendMessage($request->user(), $conversation, $data['message'], $subject);

        return response()->json([
            'conversation' => $result['conversation'],
            'message'      => $result['message'],
        ]);
    }

    /** DELETE /api/ai/conversations/{conversation} — archive (soft hide). */
    public function destroy(Request $request, AiConversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $conversation->update(['is_archived' => true]);

        return response()->json(['success' => true]);
    }
}
