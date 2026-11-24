<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostic: prove whether the Google Business Profile reviews API actually
 * works for this project yet, or is still blocked (i.e. access not approved).
 *
 * It does NOT sync anything — it authenticates with the refresh token and makes
 * live calls, then reports one of three plain outcomes:
 *   - WORKING      : reviews came back (or the endpoint answered without a block)
 *   - BLOCKED      : 403 / SERVICE_DISABLED / PERMISSION_DENIED  -> access not approved yet
 *   - AUTH FAILED  : refresh token / client credentials are wrong or missing
 *
 * Run on the server where the real .env lives:  php artisan google:test-reviews
 */
class GoogleTestReviews extends Command
{
    protected $signature = 'google:test-reviews {--limit=3 : How many reviews to try to fetch}';

    protected $description = 'Test whether the Google Business Profile reviews API is working or still blocked (not approved)';

    public function handle(): int
    {
        $cfg = config('services.google_business');

        $this->line('');
        $this->info('== Google Business Profile API — connectivity test ==');
        $this->line('');

        // 1) Config presence -------------------------------------------------
        $present = fn ($k) => filled($cfg[$k] ?? null);
        $this->line('Credentials in config (from .env):');
        foreach (['client_id', 'client_secret', 'refresh_token', 'account_id', 'location_id'] as $k) {
            $this->line(sprintf('  %-15s %s', $k, $present($k) ? '<info>set</info>' : '<comment>missing</comment>'));
        }
        $this->line('');

        if (! $present('client_id') || ! $present('client_secret') || ! $present('refresh_token')) {
            $this->error('AUTH FAILED — client_id, client_secret and refresh_token are all required.');
            $this->line('Fill them in the server .env, then run `php artisan config:cache` and retry.');

            return self::FAILURE;
        }

        // 2) Exchange the refresh token for an access token ------------------
        $this->line('Step 1 — exchanging refresh token for an access token...');
        $tokenResp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $cfg['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if (! $tokenResp->successful() || ! is_string($tokenResp->json('access_token')) || trim($tokenResp->json('access_token')) === '') {
            $this->error('  AUTH FAILED — could not get an access token.');
            $this->line('  Google said: '.trim($tokenResp->json('error_description') ?? $tokenResp->json('error') ?? $tokenResp->body()));
            $this->line('  This usually means the refresh token is wrong/expired, or the OAuth consent step was never completed as the Business-Profile account.');

            return self::FAILURE;
        }
        $token = $tokenResp->json('access_token');
        $this->info('  OK — got an access token.');
        $this->line('');

        // 3) List accounts (Account Management API — generally available) -----
        $this->line('Step 2 — listing Business Profile accounts...');
        $accountsResp = Http::withToken($token)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if ($block = $this->blockReason($accountsResp)) {
            $this->warn('  BLOCKED — the Account Management API rejected the call.');
            $this->line('  Reason: '.$block);
            $this->line('  If this says the API is disabled, enable "My Business Account Management API" in the Cloud project.');
            $this->line('  Check profile manager permissions, API enablement and project approval. This response alone does not identify which requirement is missing.');

            return self::FAILURE;
        }
        if (! $accountsResp->successful()) {
            $this->error('  Unexpected error listing accounts: HTTP '.$accountsResp->status());
            $this->line('  '.trim($accountsResp->body()));

            return self::FAILURE;
        }

        $accounts = $accountsResp->json('accounts', []);
        if (empty($accounts)) {
            $this->warn('  Authenticated, but this account manages 0 Business Profiles.');
            $this->line('  Make sure the OAuth "Allow" was done signed in as the account that manages the Google Business Profile.');
        } else {
            $this->info('  OK — '.count($accounts).' account(s) visible:');
            foreach ($accounts as $a) {
                $this->line(sprintf('    %s  (%s)', $a['name'] ?? '?', $a['accountName'] ?? $a['type'] ?? ''));
            }
        }
        $this->line('');

        // 4) Try the reviews endpoint (v4 — the gated one) -------------------
        if (! $present('account_id')) {
            $this->warn('UNVERIFIED: set GOOGLE_BUSINESS_ACCOUNT_ID for the intended business before testing reviews.');

            return self::FAILURE;
        }
        $accountPath = 'accounts/'.$cfg['account_id'];

        if (! $present('location_id')) {
            $this->warn('No GOOGLE_BUSINESS_LOCATION_ID set — skipping the reviews fetch.');
            $this->line('UNVERIFIED: add the intended location id. Authentication alone does not verify review access.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $url = sprintf(
            'https://mybusiness.googleapis.com/v4/%s/locations/%s/reviews?pageSize=%d',
            $accountPath,
            $cfg['location_id'],
            max(1, min(50, $limit))
        );

        $this->line('Step 3 — fetching reviews (this is the access-gated call)...');
        $this->line('  GET '.$url);
        $reviewsResp = Http::withToken($token)->get($url);

        if ($block = $this->blockReason($reviewsResp)) {
            $this->warn('  UNVERIFIED: the reviews request did not succeed.');
            $this->line('  Reason: '.$block);
            $this->line('  Check Google Cloud quota, API enablement and profile permissions. A 429 can indicate quota exhaustion; check whether the quota is zero before concluding project approval is missing.');

            return self::FAILURE;
        }
        if (! $reviewsResp->successful()) {
            $this->error('  Reviews call failed: HTTP '.$reviewsResp->status());
            $this->line('  '.trim($reviewsResp->body()));

            return self::FAILURE;
        }

        $count = $reviewsResp->json('totalReviewCount', null);
        $fetched = count($reviewsResp->json('reviews', []));
        $this->info('  WORKING — the reviews API responded successfully.');
        $this->line('  Total reviews on this location: '.($count ?? 'unknown'));
        $this->line('  Fetched in this call: '.$fetched);
        $this->line('');
        $this->info('Reviews endpoint verified for the configured location. Next verify the dry sync, moderation and scheduled run before resolving the ticket.');

        return self::SUCCESS;
    }

    /**
     * Return a human string when the response is an access/enablement block
     * (403 PERMISSION_DENIED / SERVICE_DISABLED), or null otherwise.
     */
    private function blockReason($resp): ?string
    {
        if ($resp->status() !== 403 && $resp->status() !== 429) {
            return null;
        }
        $status = $resp->json('error.status');
        $message = $resp->json('error.message');
        if ($resp->status() === 429) {
            return 'RATE LIMITED (429) — '.($message ?: 'too many requests');
        }

        return trim(($status ? $status.' — ' : '').($message ?: $resp->body()));
    }
}
