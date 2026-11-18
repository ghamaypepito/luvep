<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Ghl\GhlConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GhlIdentityController extends Controller
{
    private function users(GhlConnection $g): array
    {
        abort_if($g->token() === '', 422, 'Save the EP GHL credential first.');
        try {
            $r = Http::withToken($g->token())->acceptJson()->withHeaders(['Version' => '2021-07-28'])->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->get('https://services.leadconnectorhq.com/users/', ['locationId' => GhlConnection::LOCATION]);
        } catch (\Throwable) {
            abort(502, 'GHL users are unavailable. Try again shortly.');
        }
        abort_unless($r->successful(), 502, 'GHL denied user access. Check the users.readonly permission.');
        $rows = $r->json('users');
        abort_unless(is_array($rows) && array_is_list($rows), 502, 'GHL returned an invalid user list.');
        $safe = [];
        foreach ($rows as $u) {
            if (! is_array($u) || ! is_string($u['id'] ?? null)) {
                continue;
            }$locations = $u['roles']['locationIds'] ?? [];
            if (is_array($locations) && count($locations) > 0 && ! in_array(GhlConnection::LOCATION, $locations, true)) {
                continue;
            }$name = $u['name'] ?? trim(($u['firstName'] ?? '').' '.($u['lastName'] ?? ''));
            $safe[] = ['id' => mb_substr($u['id'], 0, 100), 'name' => mb_substr((string) $name, 0, 200)];
        }

        return $safe;
    }

    public function index(Request $request, GhlConnection $g)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return response()->json(['users' => $this->users($g), 'configured_user_id' => Setting::get('ghl_connector.user_id')], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, GhlConnection $g)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $data = $request->validate(['user_id' => 'required|string|max:100']);
        $user = collect($this->users($g))->firstWhere('id', $data['user_id']);
        if (! $user) {
            throw ValidationException::withMessages(['user_id' => 'Choose an existing epathways GHL user.']);
        }Setting::set('ghl_connector.user_id', $user['id']);
        Setting::set('ghl_connector.user_name', $user['name']);
        ActivityLog::record('ghl_connector.scheduling_identity_updated', ['properties' => ['location_id' => GhlConnection::LOCATION, 'user_id' => $user['id']]]);

        return response()->json(['configured_user_id' => $user['id'], 'name' => $user['name']]);
    }
}
