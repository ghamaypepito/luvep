<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MarketingPost;
use App\Models\SocialAsset;
use App\Models\User;
use App\Rules\SocialMediaFile;
use App\Services\Ghl\GhlConnection;
use App\Services\Ghl\GhlSocialReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SocialDriveController extends Controller
{
    private function shape(SocialAsset $asset): array
    {
        return [...$asset->only('id', 'name', 'mime', 'size', 'caption', 'post_type', 'source_version', 'created_at'),
            'download_url' => $asset->file_path ? route('social.drive.download', $asset) : null];
    }

    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1|max:10000']);
        $search = trim($data['search'] ?? '');
        $assets = SocialAsset::query()->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->latest()->orderBy('id')->paginate(24)->through(fn ($asset) => $this->shape($asset));

        return response()->json(['assets' => $assets])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:200', 'caption' => 'nullable|string|max:12000',
            'upload_key' => 'required|uuid', 'file' => ['required', 'file', new SocialMediaFile]]);
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $path = null;
        try {
            $asset = DB::transaction(function () use ($request, $data, $file, $hash, &$path) {
                $user = User::lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->canUseSocialMarketing(), 403);
                if ($existing = SocialAsset::where('upload_key', $data['upload_key'])->first()) {
                    abort_unless($existing->uploaded_by === $user->id && hash_equals($existing->sha256, $hash)
                        && $existing->name === $data['name'] && $existing->caption === ($data['caption'] ?? null), 409, 'This upload key belongs to a different asset.');

                    return $existing;
                }
                $path = $file->store('social-drive', 'local');
                abort_unless($path, 500, 'The file could not be saved.');
                $asset = SocialAsset::create(['name' => $data['name'], 'caption' => $data['caption'] ?? null, 'upload_key' => $data['upload_key'],
                    'file_path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash, 'uploaded_by' => $user->id]);
                ActivityLog::record('social_drive.uploaded', ['properties' => ['asset_id' => $asset->id]]);

                return $asset;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return response()->json(['asset' => $this->shape($asset)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function savePost(Request $request, string $post)
    {
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];
        $asset = DB::transaction(function () use ($request, $post, $version) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->canUseSocialMarketing(), 403);
            $record = MarketingPost::where('location_id', GhlConnection::LOCATION)->where('account_id', GhlSocialReader::ACCOUNT)->lockForUpdate()->findOrFail($post);
            abort_unless($record->version === (int) $version, 409, 'This post changed. Reopen it before saving to Social Drive.');
            abort_unless(in_array($record->status, ['approved', 'scheduled', 'published'], true) && $record->hasCurrentApproval(), 409, 'Only a current approved version can be saved from this queue.');
            if ($record->media_path) {
                abort_unless(is_string($record->media_hash) && in_array($record->media_mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'video/mp4'], true) && Storage::disk('local')->exists($record->media_path)
                    && hash_equals($record->media_hash, hash_file('sha256', Storage::disk('local')->path($record->media_path))), 409, 'The approved media could not be verified.');
            }

            return SocialAsset::firstOrCreate(['source_post_id' => $record->id, 'source_version' => $record->approved_version], [
                'name' => $record->campaign, 'caption' => $record->caption, 'post_type' => $record->post_type ?: 'post', 'file_path' => $record->media_path,
                'mime' => $record->media_mime, 'sha256' => $record->media_hash, 'size' => $record->media_path ? Storage::disk('local')->size($record->media_path) : 0, 'uploaded_by' => $user->id]);
        });

        return response()->json(['asset' => $this->shape($asset)])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, SocialAsset $asset)
    {
        abort_unless($asset->file_path && Storage::disk('local')->exists($asset->file_path), 404);

        return response()->file(Storage::disk('local')->path($asset->file_path), ['Content-Type' => $asset->mime,
            'Content-Disposition' => 'inline; filename="asset.'.match ($asset->mime) {
                'video/mp4' => 'mp4', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'png'
            }.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
