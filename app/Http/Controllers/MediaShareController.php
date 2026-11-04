<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MediaShare;
use App\Services\MediaDrive\Access;
use App\Services\MediaDrive\Library;
use App\Services\MediaDrive\Sharing;
use App\Services\MediaDrive\Uploads;
use Illuminate\Http\Request;

class MediaShareController extends Controller
{
    public function __construct(private Access $access, private Library $library, private Sharing $sharing) {}

    private function rules(): array
    {
        return ['email' => 'required|email:rfc|max:254', 'permission' => 'required|in:viewer,editor', 'expires_in_days' => 'required|integer|min:1|max:90'];
    }

    private function response(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function index(Request $r, MediaFolder $folder)
    {
        $this->access->assertFolder($r->user(), $folder);
        abort_unless($this->access->canShare($r->user()), 403);

        return $this->response(['shares' => $folder->shares()->latest('id')->get()->map(fn ($s) => $this->sharing->data($s))]);
    }

    public function store(Request $r, MediaFolder $folder)
    {
        $this->access->assertFolder($r->user(), $folder);
        abort_unless($this->access->canShare($r->user()), 403);
        $share = $this->sharing->create($r->user(), $folder, $r->validate($this->rules()));

        return $this->response(['share' => $this->sharing->data($share)], 201);
    }

    public function update(Request $r, MediaShare $share)
    {
        $share = $this->sharing->update($r->user(), $share, $r->validate($this->rules()));

        return $this->response(['share' => $this->sharing->data($share)]);
    }

    public function revoke(Request $r, MediaShare $share)
    {
        $share = $this->sharing->update($r->user(), $share, null);

        return $this->response(['share' => $this->sharing->data($share)]);
    }

    public function show(Request $r, string $token)
    {
        try {
            $share = $this->sharing->resolve($token);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [404, 410])) {
                throw $e;
            }

            return inertia('media/Shared', ['unavailable' => 'This link is expired, revoked or unavailable.'])->toResponse($r)->setStatusCode($e->getStatusCode())->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        $parts = explode('@', $share->email);
        $hint = mb_substr($parts[0], 0, 1).'•••@'.$parts[1];

        return inertia('media/Shared', ['verified' => $this->sharing->verified($r, $share), 'base' => '/shared/media/'.$token, 'email_hint' => $hint])->toResponse($r)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function code(Request $r, string $token)
    {
        $share = $this->sharing->resolve($token);
        $data = $r->validate(['email' => 'required|string|email:rfc|max:254']);
        $this->sharing->requestCode($r, $share, $data['email']);

        return $this->response(['message' => 'If this is the invited email, a verification code has been sent.', 'resend_after' => 60]);
    }

    public function verify(Request $r, string $token)
    {
        $share = $this->sharing->resolve($token);
        $data = $r->validate(['code' => 'required|digits:6']);
        $this->sharing->verify($r, $share, $data['code']);

        return $this->response(['verified' => true]);
    }

    public function contents(Request $r, string $token)
    {
        $share = $this->sharing->resolve($token);
        $this->sharing->assertGrant($r, $share);
        $data = $r->validate(['folder_id' => 'nullable|integer', 'search' => 'nullable|string|max:120']);
        $folder = $this->sharing->assertSubtree($share, $data['folder_id'] ?? $share->folder_id);
        $files = MediaFile::where('folder_id', $folder->id)->with('documents:id,media_file_id,status')->when(! empty($data['search']), fn ($q) => $q->where('original_name', 'like', '%'.$data['search'].'%'))->latest('id')->paginate(30)->through(fn ($f) => $this->library->fileData($f, $token));
        $crumbIds = array_filter(explode('/', $folder->ancestry));

        return $this->response(['folder' => $this->library->folderData($folder, false), 'breadcrumbs' => $share->folder->subtree()->whereIn('id', $crumbIds)->orderBy('id')->get()->map(fn ($f) => $this->library->folderData($f, false)), 'folders' => $share->folder->subtree()->where('parent_id', $folder->id)->orderBy('name')->get()->map(fn ($f) => $this->library->folderData($f, false)), 'files' => $files, 'permission' => $share->permission, 'expires_at' => $share->expires_at->toIso8601String()]);
    }

    public function download(Request $r, string $token, MediaFile $file)
    {
        $share = $this->sharing->resolve($token);
        $this->sharing->assertGrant($r, $share);
        $this->sharing->assertSubtree($share, $file->folder_id);

        return $this->library->stream($file, $r->boolean('inline'));
    }

    public function upload(Request $r, string $token, MediaFolder $folder, Uploads $uploads)
    {
        $share = $this->sharing->resolve($token);
        $this->sharing->assertGrant($r, $share, true);
        $this->sharing->assertSubtree($share, $folder->id);
        $data = $r->validate(['upload_key' => 'required|uuid', 'file' => 'required|file|mimes:'.Uploads::MIMES.'|extensions:'.Uploads::MIMES.'|max:102400']);
        [$file,$created] = $uploads->store($folder, $r->file('file'), $data['upload_key'], null, $share, function () use ($r, $share, $folder) {
            $locked = MediaShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            $this->sharing->assertGrant($r, $locked, true);
            $this->sharing->assertSubtree($locked, $folder->id);
        });

        return $this->response(['file' => $this->library->fileData($file, $token)], $created ? 201 : 200);
    }
}
