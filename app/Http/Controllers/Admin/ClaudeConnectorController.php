<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Claude\ClaudeClient;
use App\Services\Claude\ClaudeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ClaudeConnectorController extends Controller
{
    public function index(Request $request, ClaudeSettings $settings)
    {
        $data = ['connector' => $settings->publicState()];

        return $request->wantsJson() ? response()->json($data) : inertia('admin/ClaudeConnectors', $data);
    }

    public function update(Request $request, ClaudeSettings $settings)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'min:20', 'max:512', 'regex:/^\S+$/'],
            'remove_saved_key' => 'sometimes|boolean',
            'pathy_enabled' => 'required|boolean', 'staff_enabled' => 'required|boolean',
            'pathy_model' => ['required', 'string', 'max:100', 'regex:/^claude-[a-z0-9.-]+$/'],
            'staff_model' => ['required', 'string', 'max:100', 'regex:/^claude-[a-z0-9.-]+$/'],
            'pathy_instructions' => 'required|string|max:12000', 'staff_instructions' => 'required|string|max:12000',
        ]);
        DB::transaction(function () use ($data) {
            if ($data['remove_saved_key'] ?? false) {
                Setting::set('claude_connector.api_key', '');
            } elseif (! empty($data['api_key'])) {
                Setting::set('claude_connector.api_key', Crypt::encryptString($data['api_key']), 'string', 'Encrypted Claude credential', 'claude_connector');
            }
            foreach (['pathy', 'staff'] as $surface) {
                foreach (['enabled', 'model', 'instructions'] as $field) {
                    Setting::set('claude_connector.'.$surface.'_'.$field, $data[$surface.'_'.$field], $field === 'enabled' ? 'bool' : 'string', null, 'claude_connector');
                }
            }
            ActivityLog::record('claude_connector.updated', ['description' => 'Updated Claude assistant connector settings.',
                'properties' => ['pathy_enabled' => $data['pathy_enabled'], 'staff_enabled' => $data['staff_enabled'],
                    'credential_changed' => ! empty($data['api_key']) || ($data['remove_saved_key'] ?? false)]]);
        });

        return response()->json(['connector' => $settings->publicState(), 'message' => 'Claude connector saved.']);
    }

    public function test(Request $request, ClaudeClient $client)
    {
        $data = $request->validate(['surface' => 'required|in:pathy,staff']);
        $result = $client->testConnection($data['surface']);
        ActivityLog::record('claude_connector.tested', ['properties' => ['surface' => $data['surface'], 'successful' => $result['connected']]]);

        return response()->json(['connected' => $result['connected'],
            'message' => $result['connected'] ? 'Claude connection verified.' : 'Connection failed. Check the API key, model access and Anthropic billing.',
            'model' => $result['model']], $result['connected'] ? 200 : 502);
    }
}
