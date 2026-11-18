<?php

namespace App\Http\Controllers;

use App\Models\MediaEvent;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Services\MediaDrive\Access;
use App\Services\MediaDrive\Library;
use App\Services\MediaDrive\Uploads;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MediaDriveController extends Controller
{
    public function __construct(private Access $access, private Library $library) {}

    private function staff(Request $r): void
    {
        abort_unless($this->access->canEnter($r->user()), 403);
    }

    public function index(Request $r)
    {
        $this->staff($r);
        $portal = $r->route('portal');
        if (! $portal) {
            abort_unless($r->user()->isAdmin(), 403);
        }
        if ($portal) {
            abort_unless($r->user()->canAccessPortal($portal === 'sub-agent' ? 'sub_agent' : $portal), 403);
        }

        return inertia($portal ? 'portal/'.$portal.'/MediaDrive' : 'admin/MediaDrive', ['can_share' => $this->access->canShare($r->user())]);
    }

    public function entry(Request $r)
    {
        $role = $r->user()->role;
        if ($role === 'lead') {
            return redirect('/portal/lead/media-drive');
        }
        $this->staff($r);
        $portal = match ($role) {
            'admin','super_admin' => null,'immigration_manager' => 'immigration','immigration_adviser' => 'immigration-adviser','sub_agent' => 'sub-agent',default => $role
        };

        return redirect($portal ? '/portal/'.$portal.'/media-drive' : '/admin/media-drive');
    }

    public function clientLinks(Request $r)
    {
        $user = $r->user();
        abort_unless($user->is_active && $user->role === 'lead' && $user->lead_id, 403);
        $roots = MediaFolder::where('lead_id', $user->lead_id)->whereHas('lead')->select('id');
        $folderIds = MediaFolder::where(fn ($q) => $q->whereIn('id', $roots)->orWhereIn('root_id', $roots))->select('id');
        $shares = \App\Models\MediaShare::whereIn('folder_id', $folderIds)->where('email', mb_strtolower(trim($user->email)))->whereNull('revoked_at')->where('expires_at', '>', now())->with('folder')->get();

        return inertia('portal/lead/MediaDrive', ['links' => $shares->map(fn ($s) => ['id' => $s->id, 'name' => $s->folder->name, 'permission' => $s->permission, 'expires_at' => $s->expires_at->toIso8601String(), 'url' => url('/shared/media/'.$s->token)])]);
    }

    public function activity(Request $r, MediaFolder $folder)
    {
        $this->staff($r);
        $this->access->assertFolder($r->user(), $folder);

        return response()->json(['events' => MediaEvent::whereIn('folder_id', $folder->subtree()->select('id'))->with('user:id,name')->latest('id')->limit(40)->get()->map(fn ($e) => ['id' => $e->id, 'kind' => $e->kind, 'actor' => isset($e->metadata['user_view']) ? ($e->metadata['user_view']['actor_name'].' acting as '.($e->user?->name ?? 'team member')) : ($e->user?->name ?: 'Client'), 'created_at' => $e->created_at?->toIso8601String()])])->header('Cache-Control', 'private, no-store');
    }

    public function clients(Request $r)
    {
        $this->staff($r);
        $data = $r->validate(['search' => 'nullable|string|max:120', 'type' => 'nullable|in:lead,tenant', 'id' => 'nullable|integer']);
        $search = trim($data['search'] ?? '');
        $items = collect();
        if (($data['type'] ?? 'lead') === 'lead') {
            $items = $this->access->leads($r->user())->when(isset($data['id']), fn ($q) => $q->whereKey($data['id']))->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('lead_id', 'like', "%{$search}%")))->orderBy('first_name')->limit(30)->get();
        }
        if (($data['type'] ?? 'lead') === 'tenant') {
            $items = $this->access->tenants($r->user())->when(isset($data['id']), fn ($q) => $q->whereKey($data['id']))->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")->orWhere('family_name', 'like', "%{$search}%")))->orderBy('first_name')->limit(30)->get();
        }

        return response()->json(['clients' => $items->map(fn ($client) => $this->library->clientData($client))->values()])->header('Cache-Control', 'private, no-store');
    }

    public function folders(Request $r)
    {
        $this->staff($r);
        $data = $r->validate(['folder_id' => 'nullable|integer', 'client_type' => 'nullable|in:lead,tenant', 'client_id' => 'nullable|integer', 'search' => 'nullable|string|max:120', 'shared' => 'nullable|boolean', 'review' => 'nullable|boolean']);
        $search = trim($data['search'] ?? '');
        if (! empty($data['folder_id'])) {
            $folder = MediaFolder::findOrFail($data['folder_id']);
            $root = $this->access->assertFolder($r->user(), $folder);
            $this->library->event($folder, 'folder_opened', $r->user()->id);
            $files = MediaFile::whereIn('folder_id', ($search !== '' || $r->boolean('review')) ? $root->subtree()->select('id') : [$folder->id])->with(['uploader:id,name', 'documents:id,media_file_id,status,checklist_key']);
            $files->when($search !== '', fn ($q) => $q->where('original_name', 'like', "%{$search}%"));
            $files->when($r->boolean('review'), fn ($q) => $q->where(fn ($w) => $w->where(fn ($q) => $q->whereDoesntHave('documents')->where('initial_status', 'Submitted'))->orWhereHas('documents', fn ($d) => $d->whereIn('status', ['Submitted', 'UnderReview', 'Checked']))));
            $crumbIds = array_filter(explode('/', $folder->ancestry));

            return response()->json(['folder' => $this->library->folderData($folder), 'root' => $this->library->folderData($root), 'breadcrumbs' => MediaFolder::whereIn('id', $crumbIds)->orderBy('id')->get()->map(fn ($f) => $this->library->folderData($f)), 'folders' => MediaFolder::where('parent_id', $folder->id)->withCount('files')->orderBy('name')->get()->map(fn ($f) => $this->library->folderData($f)), 'files' => $files->orderByDesc('id')->paginate(30)->through(fn ($f) => $this->library->fileData($f)), 'can_share' => $this->access->canShare($r->user())])->header('Cache-Control', 'private, no-store');
        }
        $roots = $this->access->roots($r->user())->with(['lead', 'tenant'])->withCount('files')->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        if ($r->boolean('shared')) {
            $roots->whereIn('id', \App\Models\MediaShare::whereNull('revoked_at')->where('expires_at', '>', now())->join('media_folders as shared_folders', 'shared_folders.id', '=', 'media_shares.folder_id')->selectRaw('COALESCE(shared_folders.root_id,shared_folders.id)'));
        }
        if (! empty($data['client_id'])) {
            $this->access->client($r->user(), $data['client_type'] ?? 'lead', $data['client_id']);
            $roots->where('client_key', ($data['client_type'] ?? 'lead').':'.$data['client_id']);
        }

        return response()->json(['folders' => $roots->orderByDesc('updated_at')->paginate(30)->through(fn ($f) => $this->library->folderData($f)), 'can_share' => $this->access->canShare($r->user())])->header('Cache-Control', 'private, no-store');
    }

    public function createFolder(Request $r)
    {
        $this->staff($r);
        $data = $r->validate(['name' => 'required|string|max:120', 'parent_id' => 'nullable|integer', 'client_type' => 'required_without:parent_id|in:lead,tenant', 'client_id' => 'required_without:parent_id|integer']);
        $folder = $this->library->create($r->user(), $data);

        return response()->json(['folder' => $this->library->folderData($folder)], 201);
    }

    public function upload(Request $r, MediaFolder $folder, Uploads $uploads)
    {
        $this->staff($r);
        $this->access->assertFolder($r->user(), $folder);
        $data = $r->validate(['upload_key' => 'required|uuid', 'file' => 'required|file|mimes:'.Uploads::MIMES.'|extensions:'.Uploads::MIMES.'|max:102400']);
        [$file,$created] = $uploads->store($folder, $r->file('file'), $data['upload_key'], $r->user());

        return response()->json(['file' => $this->library->fileData($file)], $created ? 201 : 200);
    }

    public function file(Request $r, MediaFile $file)
    {
        $this->staff($r);
        $this->access->assertFolder($r->user(), $file->folder);

        return response()->json(['file' => $this->library->fileData($file), 'events' => MediaEvent::where('file_id', $file->id)->with('user:id,name')->latest('id')->limit(30)->get()->map(fn ($e) => ['id' => $e->id, 'kind' => $e->kind, 'actor' => $e->user?->name ?: 'Client', 'created_at' => $e->created_at?->toIso8601String()])])->header('Cache-Control', 'private, no-store');
    }

    public function attach(Request $r, \App\Services\MediaDrive\Attachments $attachments)
    {
        $this->staff($r);
        $data = $r->validate(['client_type' => 'required|in:lead,tenant', 'client_id' => 'required|integer', 'file_ids' => 'required|array|min:1|max:10', 'file_ids.*' => 'required|integer|distinct', 'checklist_key' => 'nullable|string|max:150', 'request_id' => 'nullable|integer', 'dependent_id' => 'nullable|integer', 'category' => ['nullable', Rule::in(\App\Models\TenantDocument::CATEGORIES)]]);

        return response()->json($attachments->attach($r->user(), $data))->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $r, MediaFile $file)
    {
        $this->staff($r);
        $this->access->assertFolder($r->user(), $file->folder);

        return $this->library->stream($file, $r->boolean('inline'));
    }
}
