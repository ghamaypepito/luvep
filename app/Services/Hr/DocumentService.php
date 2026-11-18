<?php

namespace App\Services\Hr;

use App\Models\HrDocument;
use App\Models\HrDocumentVersion;
use App\Models\HrEmployee;
use App\Models\User;
use App\Policies\HrDocumentPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentService
{
    public function upload(User $actor, HrEmployee $employee, UploadedFile $file, array $metadata): HrDocumentVersion
    {
        $data = Validator::make($metadata, ['document_id' => 'nullable|integer|exists:hr_documents,id', 'title' => 'required|string|max:200', 'classification' => ['required', Rule::in(HrDocumentPolicy::CLASSES)]])->validate();
        abort_unless(app(HrDocumentPolicy::class)->manageClass($actor, $data['classification']), 403);
        Validator::make(['file' => $file], ['file' => 'required|file|max:10240|mimetypes:application/pdf,image/png,image/jpeg'])->validate();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());
        $valid = match ($mime) {
            'application/pdf' => ['pdf'],'image/png' => ['png'],'image/jpeg' => ['jpg', 'jpeg'],default => []
        };
        if ($file->getSize() < 1 || ! in_array($extension, $valid, true)) {
            throw ValidationException::withMessages(['file' => 'Upload a non-empty PDF, PNG or JPEG whose filename matches its content.']);
        }
        $key = 'documents/'.Str::uuid().'.'.$valid[0];
        $stored = false;
        try {
            return DB::transaction(function () use ($actor, $employee, $file, $data, $key, $mime, &$stored) {
                HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                abort_if($employee->fresh()->status === 'archived', 409, 'This employee record is archived.');
                if (! empty($data['document_id'])) {
                    $document = HrDocument::whereKey($data['document_id'])->lockForUpdate()->firstOrFail();
                    abort_unless($document->hr_employee_id === $employee->id && $document->classification === $data['classification'] && ! $document->archived_at, 422, 'Choose the existing document’s employee and classification.');
                } else {
                    $document = HrDocument::create(['hr_employee_id' => $employee->id, 'title' => $data['title'], 'classification' => $data['classification']]);
                }
                Storage::disk('hr_private')->put($key, file_get_contents($file->getRealPath()));
                $stored = true;
                $version = HrDocumentVersion::create(['hr_document_id' => $document->id, 'version' => (int) $document->versions()->max('version') + 1, 'storage_key' => $key, 'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'created_by' => $actor->id, 'created_at' => now()]);
                app(HrAudit::class)->record($actor, 'document.uploaded', $version, ['employee_id' => $employee->id, 'version' => $version->version]);

                return $version;
            });
        } catch (\Throwable $error) {
            if ($stored) {
                Storage::disk('hr_private')->delete($key);
            }throw $error;
        }
    }

    public function release(User $actor, HrDocumentVersion $version, bool $released): void
    {
        abort_unless(app(HrDocumentPolicy::class)->manage($actor, $version->document), 403);
        DB::transaction(function () use ($actor, $version, $released) {
            $document = HrDocument::whereKey($version->hr_document_id)->lockForUpdate()->firstOrFail();
            abort_if($released && ($document->archived_at || $document->employee->status !== 'active'), 409, 'Only active employee documents can be released.');
            $version = HrDocumentVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ((bool) $version->released_at === $released) {
                return;
            }$version->update(['released_at' => $released ? now() : null, 'released_by' => $released ? $actor->id : null]);
            app(HrAudit::class)->record($actor, $released ? 'document.released' : 'document.revoked', $version, ['employee_id' => $document->hr_employee_id, 'version' => $version->version]);
        });
    }

    public function download(User $actor, HrEmployee $subject, HrDocumentVersion $version, bool $preview): StreamedResponse
    {
        $document = $version->document;
        $policy = app(HrDocumentPolicy::class);
        $access = app(HrAccess::class);
        abort_unless($access->internal($actor) && $document->hr_employee_id === $subject->id, 404);
        $visible = ! $document->archived_at && $subject->user && $access->employee($subject->user)?->id === $subject->id && $version->released_at;
        $manager = $policy->manage($actor, $document);
        abort_unless($preview ? ($visible && $manager) : ($manager || ($actor->id === $subject->user_id && $visible)), 404);
        abort_unless(Storage::disk('hr_private')->exists($version->storage_key), 404);
        app(HrAudit::class)->record($actor, 'document.downloaded', $version, ['employee_id' => $subject->id, 'version' => $version->version]);

        return Storage::disk('hr_private')->download($version->storage_key, $version->original_filename, ['Content-Type' => $version->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function archive(User $actor, HrDocument $document): void
    {
        abort_unless(app(HrDocumentPolicy::class)->manage($actor, $document), 403);
        DB::transaction(function () use ($actor, $document) {
            $document = HrDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->update(['archived_at' => now()]);
            app(HrAudit::class)->record($actor,'document.archived',$document,['employee_id' => $document->hr_employee_id]);
        });
    }
}
