<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ReceptionDoorbellRang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ReceptionDoorbellController extends Controller
{
    public function index(Request $request)
    {
        $this->guardManager($request);
        $token = Setting::get('reception_doorbell_token');

        return inertia('portal/immigration/Reception', [
            'receptionUrl' => $token ? url('/reception/'.$token) : null,
        ]);
    }

    public function setup(Request $request)
    {
        $this->guardManager($request);
        // Concurrent setup requests must keep the same printed link.
        Setting::query()->firstOrCreate(['key' => 'reception_doorbell_token'], [
            'value' => Str::random(48), 'type' => 'string', 'group' => 'immigration',
            'label' => 'Reception doorbell link',
        ]);
        Cache::store('array')->forget('setting:reception_doorbell_token');

        return back()->with('success', 'Reception QR is ready.');
    }

    public function show(string $token)
    {
        $this->guardToken($token);

        return inertia('reception/Doorbell', ['ringUrl' => '/reception/'.$token]);
    }

    public function ring(string $token)
    {
        $this->guardToken($token);
        $recipients = User::where('role', User::ROLE_IMMIGRATION_MANAGER)
            ->where('is_active', true)->get();
        abort_if($recipients->isEmpty(), 503, 'Reception alerts are unavailable. Please contact the office directly.');

        // One shared cooldown prevents concurrent scans from flooding every manager.
        $key = 'reception-doorbell:'.hash('sha256', $token);
        if (! Cache::add($key, true, 60)) {
            return response()->json(['message' => 'Reception was recently notified. Please wait a minute before ringing again.'], 429);
        }

        try {
            DB::transaction(fn () => Notification::send($recipients, new ReceptionDoorbellRang));
        } catch (\Throwable $e) {
            Cache::forget($key);
            throw $e;
        }

        return response()->json(['notified' => true]);
    }

    private function guardManager(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_active
            && ($user->isAdmin() || $user->role === User::ROLE_IMMIGRATION_MANAGER), 403);
    }

    private function guardToken(string $token): void
    {
        $expected = Setting::get('reception_doorbell_token');
        abort_unless(is_string($expected) && strlen($expected) === 48 && hash_equals($expected, $token), 404);
    }
}
