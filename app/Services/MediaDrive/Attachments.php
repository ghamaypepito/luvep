<?php

namespace App\Services\MediaDrive;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Attachments
{
    public function __construct(private Access $access, private Library $library) {}

    public function files(User $user, string $type, int $clientId, array $ids): \Illuminate\Database\Eloquent\Collection
    {
        $this->access->client($user, $type, $clientId);
        $files = MediaFile::with('folder.root')->whereIn('id', $ids)->get();
        if ($files->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['file_ids' => 'Some selected files are unavailable. Refresh the folder.']);
        }
        foreach ($files as $file) {
            $root = $this->access->assertFolder($user, $file->folder);
            if (($type === 'lead' ? (int) $root->lead_id : (int) $root->tenant_id) !== $clientId) {
                throw ValidationException::withMessages(['file_ids' => 'Choose files from this client’s Media Drive folder only.']);
            }
        }

        return $files;
    }

    public function attach(User $user, array $data): array
    {
        $client = $this->access->client($user, $data['client_type'], $data['client_id']);
        if ($client instanceof Lead) {
            foreach (['request_id' => 'documentRequests', 'dependent_id' => 'dependents'] as $key => $relation) {
                if (! empty($data[$key]) && ! $client->$relation()->whereKey($data[$key])->exists()) {
                    throw ValidationException::withMessages([$key => 'This attachment target belongs to another client.']);
                }
            }
        }

        return DB::transaction(function () use ($client, $user, $data) {
            $client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $files = $this->files($user, $data['client_type'], $client->id, $data['file_ids']);
            $created = 0;
            $ids = [];
            foreach ($files as $file) {
                $context = $client instanceof Lead ? [$data['checklist_key'] ?? null, empty($data['request_id']) ? null : (int) $data['request_id'], empty($data['dependent_id']) ? null : (int) $data['dependent_id']] : [$data['category'] ?? 'other'];
                $target = hash('sha256', json_encode([$data['client_type'], (int) $client->id, ...$context]));
                if ($client instanceof Lead) {
                    $doc = LeadDocument::firstOrCreate(['media_file_id' => $file->id, 'media_target_key' => $target], ['lead_id' => $client->id, 'checklist_key' => $data['checklist_key'] ?? null, 'request_id' => $data['request_id'] ?? null, 'dependent_id' => $data['dependent_id'] ?? null, 'original_name' => $file->original_name, 'file_path' => $file->file_path, 'mime' => $file->mime, 'size' => $file->size, 'status' => LeadDocument::STATUS_SUBMITTED, 'source' => LeadDocument::SOURCE_UPLOAD, 'uploaded_by' => $file->uploaded_by]);
                } else {
                    $doc = $client->documents()->firstOrCreate(['media_file_id' => $file->id, 'media_target_key' => $target], ['category' => $data['category'] ?? 'other', 'name' => $file->original_name, 'path' => $file->file_path, 'size' => $file->size, 'uploaded_by' => $file->uploaded_by]);
                }
                $ids[] = $doc->id;
                if ($doc->wasRecentlyCreated) {
                    $created++;
                    $this->library->event($file->folder, 'file_attached', $user->id, null, $file, ['document_type' => $client instanceof Lead ? 'lead' : 'tenant', 'document_id' => $doc->id, 'target' => $data['checklist_key'] ?? $data['category'] ?? 'general']);
                }
            }
            if ($client instanceof Lead && $created) {
                \App\Jobs\EvaluateCaseFindings::dispatch($client->id)->afterCommit();
            }

            return ['attached' => $created, 'document_ids' => $ids];
        });
    }

    public function forNote(User $user, Lead $lead, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        try {
            $files = $this->files($user, 'lead', $lead->id, $ids);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['media_file_ids' => 'Choose files from this client’s Media Drive folder only.']);
        }
        $result = [];
        foreach ($files as $file) {
            if ($file->size > 10 * 1024 * 1024 || ! in_array($file->mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic'], true)) {
                throw ValidationException::withMessages(['media_file_ids' => 'Notes accept PDF or image files up to 10 MB.']);
            }
            $result[] = ['media_file_id' => $file->id, 'original_name' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size];
        }

        return $result;
    }

    public function documentFile(LeadDocument $document, User $user): MediaFile
    {
        $file = MediaFile::findOrFail($document->media_file_id);
        $root = $file->folder->ownerRoot();
        abort_unless($root->lead && (int) $root->lead_id === (int) $document->lead_id, 404);
        if ($user->isLead()) {
            abort_unless((int) $user->lead_id === (int) $document->lead_id, 403);
        } else {
            $this->access->assertFolder($user, $file->folder);
        }

        return $file;
    }
}
