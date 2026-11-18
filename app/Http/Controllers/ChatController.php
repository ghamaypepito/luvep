<?php

namespace App\Http\Controllers;

use App\Services\Claude\PathyService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __invoke(Request $request, PathyService $pathy)
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'sometimes|array|max:12',
            'history.*' => 'array:role,content',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:2000',
        ]);
        $result = $pathy->reply($data['message'], $data['history'] ?? []);

        return response()->json($result, ($result['unavailable'] ?? false) ? 503 : 200);
    }
}
