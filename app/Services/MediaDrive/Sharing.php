<?php

namespace App\Services\MediaDrive;

use App\Mail\MediaDriveCode;
use App\Models\MediaChallenge;
use App\Models\MediaFolder;
use App\Models\MediaShare;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Sharing
{
    public function __construct(private Access $access, private Library $library) {}

    public function data(MediaShare $share): array
    {
        return ['id' => $share->id, 'email' => $share->email, 'permission' => $share->permission, 'expires_at' => $share->expires_at->toIso8601String(), 'revoked_at' => $share->revoked_at?->toIso8601String(), 'version' => $share->version, 'url' => url('/shared/media/'.$share->token)];
    }

    public function create(User $user, MediaFolder $folder, array $data): MediaShare
    {
        $this->access->assertFolder($user, $folder);
        abort_unless($this->access->canShare($user), 403);

        return DB::transaction(function () use ($user, $folder, $data) {
            $token = Str::random(64);
            $share = MediaShare::create(['folder_id' => $folder->id, 'email' => mb_strtolower(trim($data['email'])), 'permission' => $data['permission'], 'expires_at' => now()->addDays($data['expires_in_days']), 'created_by' => $user->id, 'token' => $token, 'token_hash' => hash('sha256', $token)]);
            $this->library->event($folder, 'sharing_created', $user->id, $share->id, null, ['permission' => $share->permission]);

            return $share;
        });
    }

    public function update(User $user, MediaShare $share, ?array $data): MediaShare
    {
        $this->access->assertFolder($user, $share->folder);
        abort_unless($this->access->canShare($user), 403);

        return DB::transaction(function () use ($user, $share, $data) {
            $locked = MediaShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            if ($data) {
                abort_if($locked->revoked_at, 422, 'This link was revoked. Create a new link.');
                $locked->fill(['email' => mb_strtolower(trim($data['email'])), 'permission' => $data['permission'], 'expires_at' => now()->addDays($data['expires_in_days']), 'version' => $locked->version + 1])->save();
            } elseif (! $locked->revoked_at) {
                $locked->update(['revoked_at' => now(), 'version' => $locked->version + 1]);
            }
            $this->library->event($locked->folder, $data ? 'sharing_updated' : 'sharing_revoked', $user->id, $locked->id, null, ['permission' => $locked->permission]);

            return $locked;
        });
    }

    public function resolve(string $token): MediaShare
    {
        abort_unless(strlen($token) === 64, 404);
        $share = MediaShare::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($share->isAvailable(), 410, 'This sharing link is expired or unavailable. Please request a new link from the EP team.');

        return $share;
    }

    private function sessionHash(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }

    public function verified(Request $r, MediaShare $share): bool
    {
        $grant = $r->session()->get('media_grants.'.$share->id);

        return $share->isAvailable() && is_array($grant) && ($grant['version'] ?? 0) === $share->version && ($grant['expires'] ?? 0) > now()->timestamp;
    }

    public function assertGrant(Request $r, MediaShare $share, bool $editor = false): void
    {
        abort_unless($share->isAvailable(), 410, 'This sharing link is expired or unavailable.');
        abort_unless($this->verified($r, $share), 403, 'Verify the invited email to open this folder.');
        abort_if($editor && $share->permission !== 'editor', 403, 'This link has Viewer access. Uploading requires Editor access.');
    }

    public function assertSubtree(MediaShare $share, int $folderId): MediaFolder
    {
        return $share->folder->subtree()->whereKey($folderId)->firstOrFail();
    }

    public function requestCode(Request $r, MediaShare $share, string $email): void
    {
        if (! hash_equals($share->email, mb_strtolower(trim($email)))) {
            return;
        }
        $hour = 'media-code-hour:'.$share->id;
        $cool = 'media-code-cool:'.$share->id;
        abort_if(RateLimiter::tooManyAttempts($hour, 3) || RateLimiter::tooManyAttempts($cool, 1), 429, 'Please wait before requesting another code. Up to three codes can be sent per hour.');
        RateLimiter::hit($hour, 3600);
        RateLimiter::hit($cool, 60);
        $prior = MediaChallenge::where('share_id', $share->id)->latest('created_at')->first();
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($prior && Hash::check($code, $prior->code_hash));
        $challenge = MediaChallenge::create(['id' => (string) Str::uuid(), 'share_id' => $share->id, 'share_version' => $share->version, 'session_hash' => $this->sessionHash($r), 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
        $r->session()->put('media_challenges.'.$share->id, $challenge->id);
        try {
            Mail::to($share->email)->send(new MediaDriveCode($code));
        } catch (\Throwable $e) {
            $challenge->delete();
            $r->session()->forget('media_challenges.'.$share->id);
            RateLimiter::clear($cool);
            report($e);
            abort(503, 'The verification email could not be sent. Please try again.');
        }
        $this->library->event($share->folder, 'verification_requested', null, $share->id);
    }

    public function verify(Request $r, MediaShare $share, string $code): void
    {
        $challengeId = $r->session()->get('media_challenges.'.$share->id);
        $error = DB::transaction(function () use ($r, $share, $code, $challengeId) {
            $locked = MediaShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            $this->resolve($locked->token);
            $challenge = MediaChallenge::where('share_id', $locked->id)->whereKey($challengeId)->lockForUpdate()->first();
            if (! $challenge || $challenge->used_at || $challenge->expires_at->lte(now()) || $challenge->attempts >= 5 || (int) $challenge->share_version !== $locked->version || ! hash_equals($challenge->session_hash, $this->sessionHash($r))) {
                return 'This code has expired or is unavailable. Request a new code.';
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');

                return 'Incorrect verification code. Please try again.';
            }
            $challenge->update(['used_at' => now()]);
            $r->session()->migrate(true);
            $r->session()->put('media_grants.'.$locked->id, ['version' => $locked->version, 'expires' => min(now()->addHour()->timestamp, $locked->expires_at->timestamp)]);
            $this->library->event($locked->folder,'email_verified',null,$locked->id);

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['code' => $error]);
        }
    }
}
