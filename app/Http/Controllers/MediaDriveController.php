<?php

namespace App\Http\Controllers;

use App\Models\MediaEvent;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Services\MediaDrive\Access;
use App\Services\MediaDrive\Library;
use App\Services\MediaDrive\Uploads;
use Illuminate\Http\Request;

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
        if ($portal) {
            abort_unless($r->user()->canAccessPortal($portal), 403);
        }

        return inertia($portal ? 'portal/'.$portal.'/MediaDrive' : 'admin/MediaDrive', ['can_share' => $this->access->canShare($r->user())]);
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
            $files = MediaFile::whereIn('folder_id', ($search !== '' || $r->boolean('review')) ? $root->subtree()->select('id') : [$folder->id])->with(['uploader:id,name', 'documents:id,media_file_id,status']);
            $files->when($search !== '', fn ($q) => $q->where('original_name', 'like', "%{$search}%"));
            $files->when($r->boolean('review'), fn ($q) => $q->where(fn ($w) => $w->where('initial_status', 'Submitted')->orWhereHas('documents', fn ($d) => $d->whereIn('status', ['Submitted', 'UnderReview', 'Checked']))));
            $crumbIds = array_filter(explode('/', $folder->ancestry));

            return response()->json(['folder' => $this->library->folderData($folder), 'root' => $this->library->folderData($root), 'breadcrumbs' => MediaFolder::whereIn('id', $crumbIds)->orderBy('id')->get()->map(fn ($f) => $this->library->folderData($f)), 'folders' => MediaFolder::where('parent_id', $folder->id)->orderBy('name')->get()->map(fn ($f) => $this->library->folderData($f)), 'files' => $files->orderByDesc('id')->paginate(30)->through(fn ($f) => $this->library->fileData($f)), 'can_share' => $this->access->canShare($r->user())])->header('Cache-Control', 'private, no-store');
        }
        $roots = $this->access->roots($r->user())->with(['lead', 'tenant'])->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));
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

    public function download(Request $r, MediaFile $file)
    {
        $this->staff($r);
        $this->access->assertFolder($r->user(),$file->folder);

        return $this->library->stream($file,$r->boolean('inline'));
    }
}
