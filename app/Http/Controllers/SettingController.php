<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Key prefixes owned by a dedicated admin screen. They're deliberately
     * hidden from this generic editor — hand-editing `maintenance.enabled`
     * or `tracker.enabled` here would take a public surface down with no
     * confirmation or context. Both live on the super-admin Maintenance page.
     */
    private const MANAGED_ELSEWHERE = ['maintenance.', 'tracker.', 'claude_connector.', 'ghl_connector.'];

    /**
     * Admin index — lists every settings row grouped by `group`.
     */
    public function index()
    {
        $settings = Setting::query()
            ->where(function ($q) {
                foreach (self::MANAGED_ELSEWHERE as $prefix) {
                    $q->where('key', 'not like', $prefix.'%');
                }
            })
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return inertia('admin/Settings', [
            'settings' => $settings,
        ]);
    }

    /**
     * Bulk-update settings from the admin page. Body shape:
     * { values: { key1: value1, key2: value2, ... } }
     */
    public function update(Request $request)
    {
        $payload = $request->validate([
            'values' => 'required|array',
            'values.*' => 'nullable',
        ]);

        foreach ($payload['values'] as $key => $value) {
            $existing = Setting::query()->where('key', $key)->first();
            if (! $existing) {
                continue;
            }

            // Inspect the stored key, not the request alias: production SQL
            // comparisons can ignore case and accents.
            if (array_filter(self::MANAGED_ELSEWHERE, fn ($prefix) => str_starts_with(strtolower($existing->key), $prefix))) {
                continue;
            }

            // For `int` settings, coerce so junk like "abc" doesn't silently
            // store as 0 — we just leave the previous value untouched.
            if ($existing->type === 'int' && ! is_numeric($value)) {
                continue;
            }

            Setting::set($existing->key, $value);
        }

        return back()->with('success', 'Settings updated.');
    }
}
