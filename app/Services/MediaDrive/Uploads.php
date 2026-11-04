<?php

namespace App\Services\MediaDrive;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MediaShare;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Uploads
{
    public const MIMES = 'pdf,jpg,jpeg,png,webp,mp4,mov,webm,doc,docx,xls,xlsx,csv,ppt,pptx,txt';

    public function __construct(private Access $access, private Library $library) {}

    public function store(MediaFolder $folder, UploadedFile $file, string $key, ?User $user = null, ?MediaShare $share = null, ?callable $recheck = null): array
    {
        $actor = $user ? 'user:'.$user->id : 'share:'.$share->id;
        $hash = hash_file('sha256', $file->getRealPath());
        $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'file';
        $existing = MediaFile::where('upload_key', $key)->first();
        if ($existing) {
            $this->assertRetry($existing, $folder, $actor, $hash, $name);

            return [$existing, false];
        }
        $path = $file->store('media-drive/assets/'.($folder->root_id ?: $folder->id), 'local');
        if (! $path) {
            throw new \RuntimeException('Could not store this file. Please retry.');
        }
        try {
            $saved = DB::transaction(function () use ($folder, $file, $key, $user, $share, $recheck, $actor, $hash, $name, $path) {
                $locked = MediaFolder::whereKey($folder->id)->lockForUpdate()->firstOrFail();
                if ($user) {
                    $this->access->assertFolder(User::findOrFail($user->id), $locked);
                }
                if ($recheck) {
                    $recheck();
                }
                $existing = MediaFile::where('upload_key', $key)->first();
                if ($existing) {
                    $this->assertRetry($existing, $folder, $actor, $hash, $name);

                    return [$existing, false];
                }
                $asset = MediaFile::create(['folder_id' => $locked->id, 'original_name' => $name, 'file_path' => $path, 'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => $hash, 'upload_key' => $key, 'actor_key' => $actor, 'uploaded_by' => $user?->id, 'uploaded_share_id' => $share?->id, 'initial_status' => $share ? 'Submitted' : 'StaffShared']);
                $this->library->event($locked, 'file_uploaded', $user?->id, $share?->id, $asset);

                return [$asset, true];
            });
            if (! $saved[1]) {
                Storage::disk('local')->delete($path);
            }

return $saved;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    private function assertRetry(MediaFile $file, MediaFolder $folder, string $actor, string $hash, string $name): void
    {
        abort_unless($file->folder_id === $folder->id && $file->actor_key === $actor && hash_equals($file->sha256,$hash) && $file->original_name === $name, 409, 'This upload key belongs to a different file. Choose the file again.');
    }
}
