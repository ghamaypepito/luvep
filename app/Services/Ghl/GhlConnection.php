<?php

namespace App\Services\Ghl;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class GhlConnection
{
    public const LOCATION = 'EPwLVKAvmJdF8IvwBLwO';

    public function token(): string
    {
        $stored = Setting::get('ghl_connector.token');
        if (! $stored) {
            return '';
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return '';
        }
    }

    public function state(): array
    {
        $token = $this->token();
        $verified = $token !== '' && Setting::get('ghl_connector.verified_at') && hash_equals((string) Setting::get('ghl_connector.verified_fingerprint', ''), hash('sha256', $token));

        return ['subaccount' => 'epathways', 'location_id' => self::LOCATION, 'configured' => $token !== '',
            'verified' => (bool) $verified, 'verified_at' => $verified ? Setting::get('ghl_connector.verified_at') : null,
            'account_count' => $verified ? (int) Setting::get('ghl_connector.account_count', 0) : null,
            'publishing_enabled' => (bool) $verified && (bool) Setting::get('ghl_connector.workflow_enabled', false) && trim((string) Setting::get('ghl_connector.user_id', '')) !== ''];
    }

    public function clearVerification(): void
    {
        foreach (['verified_at', 'verified_fingerprint', 'account_count'] as $field) {
            Setting::set('ghl_connector.'.$field, '');
        }
    }

    /** Read-only credential check. No publishing or account changes. */
    public function verify(): array
    {
        $token = $this->token();
        if ($token === '') {
            return ['ok' => false, 'code' => 'not_configured', 'accounts' => []];
        }
        $this->clearVerification();
        try {
            $response = Http::withToken($token)->acceptJson()->withHeaders(['Version' => 'v3'])
                ->connectTimeout(5)->timeout(20)->get('https://services.leadconnectorhq.com/social-media-posting/'.self::LOCATION.'/accounts');
            if (! $response->successful()) {
                return ['ok' => false, 'code' => in_array($response->status(), [401, 403]) ? 'authorization' : 'unavailable', 'accounts' => []];
            }
            $accounts = $response->json('results.accounts');
            if ($response->json('success') !== true || ! is_array($accounts) || ! array_is_list($accounts)) {
                return ['ok' => false, 'code' => 'invalid_response', 'accounts' => []];
            }
            $safe = [];
            foreach ($accounts as $account) {
                if (! is_array($account) || ! is_string($account['id'] ?? null) || (isset($account['locationId']) && $account['locationId'] !== self::LOCATION)) {
                    return ['ok' => false, 'code' => 'location_mismatch', 'accounts' => []];
                }
                $safe[] = ['id' => substr($account['id'], 0, 255), 'name' => substr((string) ($account['name'] ?? 'Unnamed account'), 0, 200),
                    'platform' => substr((string) ($account['platform'] ?? 'unknown'), 0, 40), 'active' => (bool) ($account['active'] ?? false)];
            }
            Setting::set('ghl_connector.verified_at', now()->toIso8601String());
            Setting::set('ghl_connector.verified_fingerprint', hash('sha256', $token));
            Setting::set('ghl_connector.account_count', count($safe), 'int');

            return ['ok' => true, 'code' => 'verified', 'accounts' => $safe];
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'connection', 'accounts' => []];
        }
    }
}
