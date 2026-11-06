<?php

namespace App\Http\Controllers\Api;

use App\EpAgent\ConversationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EpAgentController extends Controller
{
    public function __construct(private ConversationService $conversations) {}

    public function index(Request $request)
    {
        $data = $request->validate(['before' => 'sometimes|integer|min:1']);

        return response()->json(['data' => $this->conversations->list($request->user(), (int) ($data['before'] ?? PHP_INT_MAX))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['portal' => 'required|string|max:32', 'lead_id' => 'nullable|integer|min:1', 'title' => 'sometimes|required|string|max:120']);
        $conversation = $this->conversations->create($request->user(), $data['portal'], $data['lead_id'] ?? null, $data['title'] ?? 'New conversation');

        return response()->json($conversation, 201);
    }

    public function show(Request $request, int $id)
    {
        $data = $request->validate(['before' => 'sometimes|integer|min:1']);

        return response()->json([
            'conversation' => $this->conversations->read($request->user(), $id),
            'messages' => $this->conversations->messages($request->user(), $id, (int) ($data['before'] ?? PHP_INT_MAX)),
        ]);
    }

    public function append(Request $request, int $id)
    {
        $data = $request->validate(['content' => 'required|string|max:8000', 'lead_id' => 'prohibited', 'portal' => 'prohibited', 'role' => 'prohibited', 'user_id' => 'prohibited']);

        return response()->json($this->conversations->append($request->user(), $id, $data['content']), 201);
    }

    public function destroy(Request $request, int $id)
    {
        $this->conversations->archive($request->user(), $id);

        return response()->noContent();
    }
}
