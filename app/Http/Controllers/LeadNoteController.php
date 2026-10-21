<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Internal notes on a Lead. Any logged-in staff member can add notes; only
 * the author or an admin can edit/delete. Toggling `pinned` is an
 * any-staff action so the team can highlight key context.
 */
class LeadNoteController extends Controller
{
    private function authorizeLead(Request $request, $leadId): Lead
    {
        $lead = Lead::findOrFail($leadId);
        $user = $request->user();
        $ownerId = match ($user?->role) {
            'agent' => $user->id,
            'sub_agent' => $user->parent_agent_id,
            default => null,
        };
        $ownsReferral = $ownerId !== null && (int) $lead->agent_id === (int) $ownerId;
        abort_unless($user && ($ownsReferral || \App\Support\LeadAccess::canView($user, $lead)), 403);

        return $lead;
    }

    /**
     * List notes for a lead, newest first. Used by the kanban edit modal
     * to surface existing internal notes alongside the stage picker.
     */
    public function index(Request $request, $leadId)
    {
        $this->authorizeLead($request, $leadId);
        try {
            $lead = Lead::findOrFail($leadId);

            $rows = $lead->notes()
                ->orderByDesc('pinned')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($n) => [
                    'id'          => $n->id,
                    'body'        => $n->body,
                    'programs'    => $n->programs ?? [],
                    'kind'        => $n->kind,
                    'pinned'      => (bool) $n->pinned,
                    'author_name' => $n->author_name,
                    'author_role' => $n->author_role,
                    'created_at'  => $n->created_at,
                ]);

            return response()->json(['notes' => $rows]);
        } catch (\Throwable $e) {
            Log::error('Lead notes list failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return response()->json(['notes' => []], 500);
        }
    }

    public function store(Request $request, $leadId)
    {
        $this->authorizeLead($request, $leadId);
        $validated = $request->validate([
            'body'                => 'required|string|max:2000',
            'programs' => 'nullable|array|max:50',
            'programs.*' => 'required|string|max:255|distinct|exists:programs,title',
            'pinned'              => 'nullable|boolean',
            // Reply to another note (threaded internal notes).
            'parent_id'           => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('lead_notes', 'id')->where('lead_id', $leadId)],
            // Media attachments — images or PDFs, stored on the private disk.
            'files'               => 'nullable|array|max:6',
            'files.*'             => 'file|mimes:pdf,jpg,jpeg,png,webp,gif,heic|max:10240',
            'kind'                => ['nullable', \Illuminate\Validation\Rule::in(['general', 'pre_screen', 'goal_setting', 'risk', 'client_contact'])],
            'pre_screened_by'     => 'nullable|string|max:80',
            'pre_screen_mode'     => ['nullable', \Illuminate\Validation\Rule::in(['gmeet', 'call'])],
            'pre_screen_date'     => 'nullable|date',
            'goal_setting_status' => ['nullable', \Illuminate\Validation\Rule::in([
                'Consultation Done', 'For Proposal', 'Proposal Sent', 'No Show',
            ])],
            'goal_setting_by'     => 'nullable|string|max:120',
        ]);

        try {
            $lead = Lead::findOrFail($leadId);
            $user = Auth::user();

            // Stash any uploaded media on the private disk; keep the metadata
            // on the note so the Overview can render + stream them back.
            $attachments = [];
            foreach ($request->file('files', []) as $file) {
                $path = $file->store("lead-notes/{$lead->id}", 'local');
                $attachments[] = [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ];
            }

            $note = LeadNote::create([
                'lead_id'             => $lead->id,
                'parent_id'           => $validated['parent_id'] ?? null,
                'user_id'             => $user?->id,
                'author_name'         => $user?->name,
                'author_role'         => $user?->role,
                'body'                => $validated['body'],
                // Snapshot catalogue titles so historical notes retain their meaning.
                'programs'            => $validated['programs'] ?? [],
                'attachments'         => $attachments ?: null,
                'pinned'              => (bool) ($validated['pinned'] ?? false),
                'kind'                => $validated['kind'] ?? 'general',
                'pre_screened_by'     => $validated['pre_screened_by']     ?? null,
                'pre_screen_mode'     => $validated['pre_screen_mode']     ?? null,
                'pre_screen_date'     => $validated['pre_screen_date']     ?? null,
                'goal_setting_status' => $validated['goal_setting_status'] ?? null,
                'goal_setting_by'     => $validated['goal_setting_by']     ?? null,
            ]);

            return back()->with('success', 'Note added.');
        } catch (\Throwable $e) {
            Log::error('Lead note create failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Could not save the note.']);
        }
    }

    /**
     * Stream a note's media attachment. Staff-only (this route lives in the
     * staff group); the file sits on the private disk and is never public.
     */
    public function attachment(Request $request, $leadId, $noteId, $index)
    {
        $this->authorizeLead($request, $leadId);
        $note = LeadNote::where('lead_id', $leadId)->findOrFail($noteId);
        $att = ($note->attachments ?? [])[(int) $index] ?? null;
        abort_unless($att && ! empty($att['path']) && Storage::disk('local')->exists($att['path']), 404);

        if ($request->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($att['path']), [
                'Content-Type' => $att['mime'] ?? 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.($att['original_name'] ?? 'attachment').'"',
            ]);
        }

        return Storage::disk('local')->download($att['path'], $att['original_name'] ?? 'attachment');
    }

    public function update(Request $request, $leadId, $noteId)
    {
        $this->authorizeLead($request, $leadId);
        $validated = $request->validate([
            'body'   => 'sometimes|string|max:2000',
            'pinned' => 'sometimes|boolean',
        ]);

        try {
            $note = LeadNote::where('lead_id', $leadId)->findOrFail($noteId);
            $user = Auth::user();

            // Author or admin can edit body; anyone can toggle pinned.
            $canEditBody = $user?->isAdmin() || $note->user_id === $user?->id;

            isset($validated['body']) && $canEditBody
                ? $note->body = $validated['body']
                : null;

            isset($validated['pinned'])
                ? $note->pinned = (bool) $validated['pinned']
                : null;

            $note->save();

            return back()->with('success', 'Note updated.');
        } catch (\Throwable $e) {
            Log::error('Lead note update failed', ['note_id' => $noteId, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Could not update the note.']);
        }
    }

    public function destroy(Request $request, $leadId, $noteId)
    {
        $this->authorizeLead($request, $leadId);
        try {
            $note = LeadNote::where('lead_id', $leadId)->findOrFail($noteId);
            $user = Auth::user();

            $canDelete = $user?->isAdmin() || $note->user_id === $user?->id;
            $canDelete ? $note->delete() : abort(403, 'You can only delete your own notes.');

            return back()->with('success', 'Note deleted.');
        } catch (\Throwable $e) {
            Log::error('Lead note delete failed', ['note_id' => $noteId, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Could not delete the note.']);
        }
    }
}
