<?php

namespace App\Services\MediaDrive;

use App\Models\Lead;
use App\Models\MediaEvent;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Library
{
    public function __construct(private Access $access) {}

    public function clientData($client): array
    {
        return ['type' => $client instanceof Lead ? 'lead' : 'tenant', 'id' => $client->id, 'name' => $client instanceof Lead ? trim($client->first_name.' '.$client->last_name) : $client->display_name, 'reference' => $client instanceof Lead ? $client->lead_id : 'Tenant '.$client->id, 'email' => $client->email];
    }

    public function folderData(MediaFolder $folder, bool $staff = true): array
    {
        $data = ['id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id, 'root_id' => $folder->root_id ?: $folder->id];
        if ($staff) {
            $root = $folder->ownerRoot();
            $data['client'] = $this->clientData($root->lead ?: $root->tenant);
            $data['shared'] = $folder->shares()->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        }

        return $data;
    }

    public function fileData(MediaFile $file, ?string $token = null): array
    {
        $base = $token ? '/shared/media/'.$token.'/files/'.$file->id : '/media-drive/api/files/'.$file->id.'/download';
        $statuses = $file->documents->pluck('status')->unique()->values();
        // Reviews stay on each attachment; a file is globally Approved only if all its references are Approved.
        $status = $statuses->count() === 1 ? $statuses->first() : ($statuses->isEmpty() ? $file->initial_status : 'Submitted');

        return ['id' => $file->id, 'folder_id' => $file->folder_id, 'name' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size,
            'status' => $token ? \App\Models\LeadDocument::clientStatus($status) : $status, 'download_url' => $base, 'preview_url' => $base.'?inline=1',
            'added_by' => $token ? ($file->uploaded_share_id ? 'Client upload' : 'EP team') : ($file->uploader?->name ?: 'Client upload'),
            'created_at' => $file->created_at?->toIso8601String()];
    }

    public function create(User $user, array $data): MediaFolder
    {
        if (! empty($data['parent_id'])) {
            $parent = MediaFolder::findOrFail($data['parent_id']);
            $root = $this->access->assertFolder($user, $parent);
            if (substr_count($parent->ancestry, '/') >= 10) {
                throw ValidationException::withMessages(['name' => 'Folders can be nested up to ten levels.']);
            }
            $attrs = ['parent_id' => $parent->id, 'root_id' => $root->id, 'ancestry' => $parent->ancestry.$parent->id.'/'];
        } else {
            $client = $this->access->client($user, $data['client_type'], $data['client_id']);
            $attrs = ['client_key' => $data['client_type'].':'.$client->id, $data['client_type'] === 'lead' ? 'lead_id' : 'tenant_id' => $client->id];
            if (MediaFolder::where('client_key', $attrs['client_key'])->exists()) {
                throw ValidationException::withMessages(['client_id' => 'This client already has a folder. Open the existing folder.']);
            }
        }
        $name = trim($data['name']);
        if ($name === '' || MediaFolder::where('parent_id', $attrs['parent_id'] ?? null)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when(! isset($attrs['parent_id']), fn ($q) => $q->where('client_key', $attrs['client_key']))->exists()) {
            throw ValidationException::withMessages(['name' => 'A folder with this name already exists here.']);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($attrs, $name, $user) {
            $folder = MediaFolder::create([...$attrs, 'name' => $name, 'created_by' => $user->id]);
            $this->event($folder, 'folder_created', $user->id);

            return $folder;
        });
    }

    public function event(MediaFolder $folder, string $kind, ?int $user = null, ?int $share = null, ?MediaFile $file = null, array $metadata = []): void
    {
        MediaEvent::create(['folder_id' => $folder->id, 'file_id' => $file?->id, 'user_id' => $user, 'share_id' => $share, 'kind' => $kind, 'metadata' => $metadata, 'created_at' => now()]);
    }

    public function stream(MediaFile $file, bool $inline = false)
    {
        abort_unless(Storage::disk('local')->exists($file->file_path), 404);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
        if ($inline && in_array($file->mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm', 'text/plain', 'text/csv'], true)) {
            return response()->file(Storage::disk('local')->path($file->file_path), [...$headers, 'Content-Type' => $file->mime, 'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('inline', $file->original_name, 'file')]);
        }

        return Storage::disk('local')->download($file->file_path,$file->original_name,$headers);
    }
}
