<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CaseAuditView;
use App\Models\LeadDocument;
use App\Services\Immigration\AssignedCaseAccess;
use App\Services\MediaDrive\Uploads;
use App\Support\UploadValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AssignedCaseController extends Controller
{
    public function index(Request $request, AssignedCaseAccess $access)
    {
        $actor = $access->actor($request->user());

        return inertia('portal/agent/AssignedCases', ['cases' => $access->cases($actor)->latest('updated_at')->paginate(25)
            ->through(fn ($case) => $access->summary($case))]);
    }

    public function show(Request $request, int $lead, AssignedCaseAccess $access)
    {
        $actor = $access->actor($request->user());
        $case = $access->find($actor, $lead);
        $documents = $case->documents()->latest('id')->get()->filter(fn ($doc) => $access->canRead($case, $doc))->map(fn ($doc) => [
            'id' => $doc->id, 'name' => $doc->original_name, 'mime' => $doc->mime, 'size' => $doc->size,
            'status' => LeadDocument::clientStatus($doc->status), 'uploaded_at' => $doc->created_at?->toIso8601String(),
            'url' => "/portal/agent/assigned-cases/{$case->id}/documents/{$doc->id}/download",
        ])->values();
        $requests = $access->requests($case)->get()->filter(fn ($item) => $access->allowedRequest($item))
            ->map(fn ($item) => ['id' => $item->id, 'label' => $item->label])->values();
        CaseAuditView::create(['lead_id' => $case->id, 'viewer_id' => $actor->id, 'viewer_name' => $actor->name,
            'viewer_role' => $actor->role, 'action' => 'view', 'context' => 'assigned_case', 'ip' => $request->ip(), 'viewed_at' => now()]);

        return inertia('portal/agent/AssignedCase', ['case' => $access->summary($case), 'documents' => $documents,
            'slots' => $access->slots($case), 'requests' => $requests]);
    }

    public function download(Request $request, int $lead, int $document, AssignedCaseAccess $access)
    {
        $actor = $access->actor($request->user());
        $case = $access->find($actor, $lead);
        $doc = $case->documents()->findOrFail($document);
        abort_unless($access->canRead($case, $doc), 404);
        $access->find($actor, $lead); // Revocation while resolving the file must fail closed.
        CaseAuditView::create(['lead_id' => $case->id, 'viewer_id' => $actor->id, 'viewer_name' => $actor->name,
            'viewer_role' => $actor->role, 'action' => 'download', 'context' => 'assigned_case', 'ip' => $request->ip(), 'viewed_at' => now()]);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('inline') && in_array($doc->mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            return response()->file(Storage::disk('local')->path($doc->file_path), [...$headers, 'Content-Type' => $doc->mime,
                'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('inline', $doc->original_name, 'document')])->setPrivate();
        }

        return Storage::disk('local')->download($doc->file_path, $doc->original_name, $headers);
    }

    public function upload(Request $request, int $lead, AssignedCaseAccess $access)
    {
        $actor = $access->actor($request->user());
        $access->find($actor, $lead);
        abort_if($request->attributes->has('user_view_actor'), 403);
        $unexpected = array_diff(array_keys($request->all()), ['file', 'checklist_key', 'request_id', '_token']);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'Only the requested upload target and file may be submitted.'));
        }
        $data = $request->validate(['file' => 'required|'.UploadValidation::document(),
            'checklist_key' => 'nullable|string|max:120|required_without:request_id|prohibits:request_id',
            'request_id' => 'nullable|integer|min:1|required_without:checklist_key|prohibits:checklist_key',
            'dependent_id' => 'prohibited', 'lead_id' => 'prohibited', 'media_file_id' => 'prohibited',
            'status' => 'prohibited', 'reviewed_by' => 'prohibited', 'uploaded_by' => 'prohibited']);
        $path = null;
        try {
            DB::transaction(function () use ($access, $actor, $lead, $data, $request, &$path) {
                $case = $access->find($actor, $lead, true);
                $key = $data['checklist_key'] ?? null;
                $requestId = $data['request_id'] ?? null;
                if ($key !== null && ! in_array($key, array_column($access->slots($case), 'key'), true)) {
                    throw ValidationException::withMessages(['checklist_key' => 'This upload slot is no longer requested or visible.']);
                }
                if ($requestId) {
                    $target = $access->requests($case)->whereKey($requestId)->lockForUpdate()->first();
                    if (! $target || ! $access->allowedRequest($target)) {
                        throw ValidationException::withMessages(['request_id' => 'This request is no longer available for this case.']);
                    }
                }
                $file = $request->file('file');
                $path = $file->store('lead-documents/'.$case->id, 'local');
                if (! $path) {
                    throw new \RuntimeException('Could not store the requested upload.');
                }
                $doc = LeadDocument::create(['lead_id' => $case->id, 'checklist_key' => $key, 'request_id' => $requestId,
                    'original_name' => Uploads::safeName($file->getClientOriginalName()), 'file_path' => $path,
                    'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'source' => LeadDocument::SOURCE_UPLOAD,
                    'status' => LeadDocument::STATUS_SUBMITTED, 'uploaded_by' => $actor->id]);
                ActivityLog::record('case.helper.document_uploaded', ['description' => 'Helper uploaded requested case evidence.',
                    'properties' => ['subject_type' => 'Lead', 'subject_id' => $case->id, 'document_id' => $doc->id]]);
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return back()->with('success', 'Requested file uploaded for staff review.');
    }
}
