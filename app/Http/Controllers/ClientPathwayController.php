<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\ClientPathways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientPathwayController extends Controller
{
    public function index(Request $request, Lead $lead, ClientPathways $pathways): JsonResponse
    {
        abort_unless($pathways->canManage($request->user(), $lead), 403);

        return response()->json(['pathways' => $pathways->options($request->user(), $lead)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Lead $lead, ClientPathways $pathways): RedirectResponse
    {
        abort_unless($pathways->canManage($request->user(), $lead), 403);
        $definitions = $pathways->definitions();
        $request->validate(['pathway' => ['required', 'string', Rule::in(array_keys($definitions))]]);
        $definition = $definitions[$request->input('pathway')];
        $data = $request->validate([
            'pathway' => ['required', 'string', Rule::in(array_keys($definitions))],
            'stage' => ['nullable', 'string', Rule::in($definition['stages'])],
            'assignee' => ['nullable', 'string', Rule::in($definition['assignees'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $added = $pathways->add($request->user(), $lead, $data);

        return back()->with('success', $added
            ? $definition['label'].' pathway added. Existing client information and pathways are preserved.'
            : 'This client already has an active '.$definition['label'].' pathway. Existing progress is preserved.');
    }
}
