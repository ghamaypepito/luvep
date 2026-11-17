<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateCaseFindings;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Support\UploadValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OtherCaseDocumentController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        $user = $request->user();
        abort_unless($user->is_active && ($user->isAdmin() || $user->role === 'immigration'
            || in_array($user->role, User::IMMIGRATION_ROLES, true)), 403);
        abort_unless(Lead::immigrationCase()->whereKey($lead->id)->exists(), 404);
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => UploadValidation::document(),
        ]);

        $paths = [];
        try {
            DB::transaction(function () use ($request, $lead, $user, &$paths) {
                // Recheck membership under a lock before persisting the batch.
                Lead::immigrationCase()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
                foreach ($request->file('files') as $file) {
                    $path = $file->store("lead-documents/{$lead->id}", 'local');
                    if (! $path) {
                        throw new \RuntimeException('Could not store supporting document.');
                    }
                    $paths[] = $path;
                    LeadDocument::create([
                        'lead_id' => $lead->id, 'checklist_key' => null, 'request_id' => null,
                        'original_name' => $file->getClientOriginalName(), 'file_path' => $path,
                        'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                        'status' => LeadDocument::STATUS_SUBMITTED, 'source' => LeadDocument::SOURCE_UPLOAD,
                        'uploaded_by' => $user->id,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            Log::error('Other case document upload failed', ['lead_id' => $lead->id, 'exception' => get_class($e)]);

            return back()->withErrors(['files' => 'Could not save this upload batch. Please check the case and try again.']);
        }

        // Queue availability must not undo an already committed upload.
        try {
            EvaluateCaseFindings::dispatch($lead->id);
        } catch (\Throwable $e) {
            Log::warning('Findings refresh could not be queued after supporting upload', ['lead_id' => $lead->id, 'exception' => get_class($e)]);
        }

        return back()->with('success', count($paths).' supporting document(s) uploaded under Other.');
    }
}
