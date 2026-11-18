<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingPost;
use App\Models\Setting;
use App\Services\Ghl\GhlConnection;
use App\Services\Ghl\GhlPublisher;
use App\Services\Ghl\GhlSocialReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MarketingPostController extends Controller
{
    private const FROZEN = ['scheduling', 'scheduled', 'published', 'needs_reconciliation'];

    private function authorizeUser(Request $request, bool $super = false): void
    {
        abort_unless($request->user() && ($super ? $request->user()->isSuperAdmin() : $request->user()->isAdmin()), 403);
    }

    private function find(string $id, bool $lock = false): MarketingPost
    {
        $query = MarketingPost::where('location_id', GhlConnection::LOCATION)->where('account_id', GhlSocialReader::ACCOUNT);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    private function shape(MarketingPost $post): array
    {
        $nz = $post->scheduled_at?->setTimezone('Pacific/Auckland');

        return ['id' => $post->id, 'version' => $post->version, 'campaign' => $post->campaign,
            'service' => $post->service, 'caption' => $post->caption, 'status' => $post->status,
            'schedule_date' => $nz?->toDateString(), 'schedule_time' => $nz?->format('H:i'),
            'scheduled_at' => $post->scheduled_at?->utc()->toIso8601String(),
            'image_url' => $post->media_path ? route('marketing.posts.media', ['id' => $post->id]) : null,
            'review_note' => $post->review_note, 'ghl_post_id' => $post->ghl_post_id,
            'updated_at' => $post->updated_at?->toIso8601String(), 'approved_by' => $post->approved_by];
    }

    private function exactFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), [...$allowed, '_token'])) {
            throw ValidationException::withMessages(['request' => 'Unexpected fields are not accepted.']);
        }
    }

    private function checkVersion(MarketingPost $post, int $version): void
    {
        abort_if($post->version !== $version, 409, 'This post changed. Reload before continuing.');
    }

    public function index(Request $request)
    {
        $this->authorizeUser($request);
        $posts = MarketingPost::where('location_id', GhlConnection::LOCATION)->where('account_id', GhlSocialReader::ACCOUNT)
            ->latest('updated_at')->limit(200)->get()->map(fn ($p) => $this->shape($p));

        return response()->json(['posts' => $posts, 'can_approve' => $request->user()->isSuperAdmin(),
            'ghl_user_configured' => trim((string) Setting::get('ghl_connector.user_id', '')) !== '']);
    }

    public function store(Request $request)
    {
        $this->authorizeUser($request);
        $this->exactFields($request, ['id', 'version', 'campaign', 'service', 'caption', 'schedule_date', 'schedule_time', 'media']);
        $data = $request->validate(['id' => ['nullable', 'uuid'], 'version' => ['required_with:id', 'integer', 'min:1'],
            'campaign' => ['required', 'string', 'max:200'], 'service' => ['required', 'string', 'max:200'],
            'caption' => ['required', 'string', 'max:12000'], 'schedule_date' => ['nullable', 'required_with:schedule_time', 'date_format:Y-m-d'],
            'schedule_time' => ['nullable', 'required_with:schedule_date', 'date_format:H:i'],
            'media' => ['nullable', 'file', 'image', 'mimetypes:image/png,image/jpeg,image/webp', 'max:10239', 'dimensions:min_width=1,min_height=1,max_width=8192,max_height=8192']]);
        $scheduledAt = null;
        if (! empty($data['schedule_date']) && ! empty($data['schedule_time'])) {
            $local = $data['schedule_date'].' '.$data['schedule_time'];
            $scheduledAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, 'Pacific/Auckland');
            if ($scheduledAt->format('Y-m-d H:i') !== $local) {
                throw ValidationException::withMessages(['schedule_time' => 'This time does not exist in New Zealand.']);
            }
        }
        $file = $request->file('media');
        if ($file) {
            $pixels = @getimagesize($file->getRealPath());
            if (! $pixels || ($pixels[0] * $pixels[1]) > 40000000 || ! in_array($pixels['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true) || $pixels['mime'] !== $file->getMimeType() || $file->getSize() >= 10 * 1024 * 1024) {
                throw ValidationException::withMessages(['media' => 'Upload a valid PNG, JPEG or WebP under 10MB and 40 megapixels.']);
            }
        }
        $newPath = null;
        try {
            $post = DB::transaction(function () use ($request, $data, $scheduledAt, $file, &$newPath) {
                $post = ! empty($data['id']) ? $this->find($data['id'], true) : new MarketingPost([
                    'location_id' => GhlConnection::LOCATION, 'account_id' => GhlSocialReader::ACCOUNT,
                    'created_by' => $request->user()->id, 'version' => 0,
                ]);
                if ($post->exists) {
                    $this->checkVersion($post, (int) $data['version']);
                    abort_if(in_array($post->status, self::FROZEN, true), 409, 'Scheduled or uncertain posts cannot be edited.');
                }
                if ($file) {
                    $newPath = $file->store('marketing-posts', 'local');
                    if (! $newPath) {
                        abort(500, 'Unable to save media.');
                    }
                    $post->media_path = $newPath;
                    $post->media_mime = $file->getMimeType();
                    $post->media_hash = hash_file('sha256', $file->getRealPath());
                }
                $post->fill(['campaign' => $data['campaign'], 'service' => $data['service'], 'caption' => $data['caption'],
                    'scheduled_at' => $scheduledAt?->utc(), 'status' => 'draft', 'review_note' => null,
                    'approved_by' => null, 'approved_version' => null, 'approval_fingerprint' => null,
                    'version' => $post->version + 1]);
                $post->save();

                return $post;
            });
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $e;
        }

        return response()->json(['post' => $this->shape($post)]);
    }

    private function review(Request $request, string $id, string $action)
    {
        $this->authorizeUser($request, $action !== 'submit');
        $this->exactFields($request, $action === 'changes' ? ['version', 'note'] : ['version']);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'note' => [$action === 'changes' ? 'required' : 'nullable', 'string', 'max:2000']]);
        $post = DB::transaction(function () use ($request, $id, $action, $data) {
            $post = $this->find($id, true);
            $this->checkVersion($post, (int) $data['version']);
            $allowed = $action === 'submit' ? ['draft', 'changes_requested'] : ['in_review'];
            abort_unless(in_array($post->status, $allowed, true), 409, 'This post is not ready for this action.');
            $post->version++;
            $post->status = match ($action) {
                'submit' => 'in_review', 'approve' => 'approved', default => 'changes_requested'
            };
            $post->review_note = $action === 'changes' ? $data['note'] : null;
            $post->approved_by = $action === 'approve' ? $request->user()->id : null;
            $post->approved_version = $action === 'approve' ? $post->version : null;
            $post->approval_fingerprint = $action === 'approve' ? $post->fingerprint() : null;
            $post->save();

            return $post;
        });

        return response()->json(['post' => $this->shape($post)]);
    }

    public function submit(Request $request, string $id)
    {
        return $this->review($request, $id, 'submit');
    }

    public function approve(Request $request, string $id)
    {
        return $this->review($request, $id, 'approve');
    }

    public function changes(Request $request, string $id)
    {
        return $this->review($request, $id, 'changes');
    }

    public function schedule(Request $request, string $id, GhlPublisher $publisher)
    {
        $this->authorizeUser($request, true);
        $this->exactFields($request, ['version']);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        // Check approved state before even issuing a provider account read.
        $candidate = $this->find($id);
        $this->assertSchedulable($candidate, (int) $data['version']);
        $publisher->assertReady();
        $post = DB::transaction(function () use ($id, $data) {
            $post = $this->find($id, true);
            $this->assertSchedulable($post, (int) $data['version']);
            $post->status = 'scheduling';
            $post->version++;
            $post->save();

            return $post;
        });
        $remoteId = $publisher->schedule($post);
        $post = DB::transaction(function () use ($id, $remoteId) {
            $post = $this->find($id, true);
            $post->status = $remoteId ? 'scheduled' : 'needs_reconciliation';
            $post->ghl_post_id = $remoteId;
            $post->version++;
            $post->save();

            return $post;
        });

        return response()->json(['post' => $this->shape($post), 'message' => $remoteId ? 'Scheduled in GHL.' : 'The result requires reconciliation. Do not retry.'], $remoteId ? 200 : 502);
    }

    private function assertSchedulable(MarketingPost $post, int $version): void
    {
        $this->checkVersion($post, $version);
        abort_unless($post->status === 'approved' && $post->approved_version === $post->version && $post->hasCurrentApproval(), 409, 'This exact version must be approved first.');
        if (! $post->scheduled_at || ! $post->scheduled_at->isFuture() || $post->scheduled_at->greaterThan(now()->addYear())) {
            throw ValidationException::withMessages(['schedule_date' => 'Approve a future NZ date and time within one year.']);
        }
        if ($post->media_path && (! in_array($post->media_mime, ['image/png', 'image/jpeg'], true) || ! $this->mediaMatches($post))) {
            throw ValidationException::withMessages(['media' => 'GHL scheduling requires the approved PNG or JPEG file.']);
        }
        if ($post->media_path && parse_url(route('marketing.media.shared', ['id' => $post->id]), PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['media' => 'A public HTTPS media endpoint must be configured before scheduling.']);
        }
    }

    private function mediaMatches(MarketingPost $post): bool
    {
        $disk = Storage::disk('local');

        return $post->media_path && $disk->exists($post->media_path)
            && hash_equals((string) $post->media_hash, hash_file('sha256', $disk->path($post->media_path)));
    }

    private function mediaResponse(MarketingPost $post)
    {
        abort_unless($this->mediaMatches($post), 404);

        return Storage::disk('local')->response($post->media_path, null,
            ['Content-Type' => $post->media_mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function media(Request $request, string $id)
    {
        $this->authorizeUser($request);

        return $this->mediaResponse($this->find($id));
    }

    public function sharedMedia(Request $request, string $id)
    {
        abort_unless($request->hasValidSignature(), 403);
        $post = $this->find($id);
        // An accepted request can time out before GHL fetches its image. Keep the
        // immutable approved file available while that outcome is reconciled.
        abort_unless(in_array($post->status, ['approved', 'scheduling', 'scheduled', 'published', 'needs_reconciliation'], true)
            && $post->hasCurrentApproval() && (string) $post->approved_version === (string) $request->query('version')
            && is_string($request->query('hash')) && hash_equals((string) $post->media_hash, $request->query('hash')), 404);

        return $this->mediaResponse($post);
    }
}
