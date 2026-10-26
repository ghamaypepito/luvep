<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Ghl\GhlConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class GhlConnectorController extends Controller
{
    public function index(Request $request, GhlConnection $ghl)
    {
        $data = ['connection' => $ghl->state()];

        return $request->wantsJson() ? response()->json($data) : inertia('admin/GhlConnectors', $data);
    }

    public function update(Request $request, GhlConnection $ghl)
    {
        $data = $request->validate(['ghl_api_token' => ['nullable', 'string', 'min:20', 'max:4096', 'regex:/^\S+$/'],
            'remove_saved_token' => 'sometimes|boolean', 'location_id' => 'prohibited']);
        DB::transaction(function () use ($data, $ghl) {
            $changed = false;
            if ($data['remove_saved_token'] ?? false) {
                Setting::set('ghl_connector.token', '');
                $changed = true;
            } elseif (! empty($data['ghl_api_token'])) {
                Setting::set('ghl_connector.token', Crypt::encryptString($data['ghl_api_token']), 'string', 'Encrypted EP GHL credential', 'ghl_connector');
                $changed = true;
            }
            if ($changed) {
                $ghl->clearVerification();
            }
            ActivityLog::record('ghl_connector.updated', ['description' => 'Updated epathways GHL connection settings.', 'properties' => ['credential_changed' => $changed, 'location_id' => GhlConnection::LOCATION]]);
        });

        return response()->json(['connection' => $ghl->state(), 'message' => 'GHL credential saved. Test the EP connection next.']);
    }

    public function test(GhlConnection $ghl)
    {
        $result = $ghl->verify();
        ActivityLog::record('ghl_connector.tested', ['properties' => ['successful' => $result['ok'], 'location_id' => GhlConnection::LOCATION, 'result' => $result['code']]]);
        $message = match ($result['code']) {
            'verified' => 'EP GHL account access verified. Publishing and automatic calendar/insights sync are not enabled yet.',
            'not_configured' => 'Add an EP-authorized GHL credential, save, then test.',
            'authorization' => 'GHL denied access. Check the epathways location and Social Planner account-read permission. An OAuth installation may be required if that scope is unavailable for a private integration.',
            'location_mismatch' => 'The returned accounts do not match the epathways location. The connection was not verified.',
            default => 'Could not verify EP GHL access. Check the credential and connection, then try again.',
        };

        return response()->json(['connection' => $ghl->state(), 'accounts' => $result['accounts'], 'message' => $message], $result['ok'] ? 200 : ($result['code'] === 'not_configured' ? 422 : 502));
    }
}
