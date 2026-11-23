<?php

namespace App\Http\Controllers;

use App\Services\Immigration\EngagementArchive;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EngagementArchiveController extends Controller
{
    public function store(Request $request, EngagementArchive $archives, int $id)
    {
        abort_unless(EngagementArchive::permitted($request->user()), 403);
        $data = $request->validate([
            'pack_id' => ['required', 'string', 'max:100'],
            'document_id' => ['required', 'integer', 'min:1'],
        ]);
        try {
            $archives->archive($request->user(), $id, $data['pack_id'], (int) $data['document_id']);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['archive' => 'This engagement is busy. Please refresh and try again.']);
        }

        return back()->with('success', 'Engagement archived from the active list. Documents and Finance records are unchanged.');
    }
}
