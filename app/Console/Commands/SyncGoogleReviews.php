<?php

namespace App\Console\Commands;

use App\Models\UserReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pull Google Business Profile reviews into the UserReview moderation queue.
 *
 * Each Google review is upserted (idempotent by external_id = the Google review
 * id) as source=google, is_published=false — it lands in Admin → User Reviews
 * unpublished, so nothing shows on the site until a teammate approves it (same
 * moderation + carousel as on-site reviews). Re-syncs refresh the review's
 * content (text/rating/photo/date) but never touch staff moderation fields
 * (status / is_published / department / is_featured).
 *
 * Needs GOOGLE_BUSINESS_{REFRESH_TOKEN,ACCOUNT_ID,LOCATION_ID} in the server
 * .env (mint them with `php artisan google:business-auth --listen`).
 */
class SyncGoogleReviews extends Command
{
    protected $signature = 'google:sync-reviews {--dry : List what would change without writing}';

    protected $description = 'Sync Google Business Profile reviews into the review moderation queue';

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function handle(): int
    {
        $cfg = config('services.google_business');
        foreach (['client_id', 'client_secret', 'refresh_token', 'account_id', 'location_id'] as $k) {
            if (empty($cfg[$k])) {
                $this->error("Missing services.google_business.{$k} — set GOOGLE_BUSINESS_".strtoupper($k).' in .env.');

                return self::FAILURE;
            }
        }

        // 1) Refresh access token.
        $tok = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $cfg['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        if (! $tok->successful() || ! is_string($tok->json('access_token')) || trim($tok->json('access_token')) === '') {
            $this->error('Token refresh failed ('.$tok->status().'): '.$tok->body());

            return self::FAILURE;
        }
        $access = $tok->json('access_token');

        // 2) Page through all reviews (My Business v4).
        $base = "https://mybusiness.googleapis.com/v4/accounts/{$cfg['account_id']}/locations/{$cfg['location_id']}/reviews";
        $pageToken = null;
        $seen = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $dry = (bool) $this->option('dry');

        do {
            $params = ['pageSize' => 50];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $res = Http::withToken($access)->get($base, $params);
            if (! $res->successful()) {
                $this->error('Reviews fetch failed ('.$res->status().'): '.$res->body());
                Log::error('google:sync-reviews fetch failed', ['status' => $res->status(), 'body' => $res->body()]);

                return self::FAILURE;
            }

            foreach ($res->json('reviews', []) as $r) {
                $seen++;
                $extId = $r['reviewId'] ?? ($r['name'] ?? null);
                $comment = trim((string) ($r['comment'] ?? ''));
                if (! $extId || $comment === '') {
                    // Star-only ratings with no text aren't useful in the carousel.
                    $skipped++;

                    continue;
                }

                $content = [
                    'name' => $r['reviewer']['displayName'] ?? 'Google user',
                    'mode' => 'paragraph',
                    'paragraph' => $comment,
                    'rating' => self::STARS[$r['starRating'] ?? ''] ?? 5,
                    'external_photo_url' => $r['reviewer']['profilePhotoUrl'] ?? null,
                    'review_date' => isset($r['createTime']) ? \Carbon\Carbon::parse($r['createTime']) : now(),
                ];

                $existing = UserReview::where('source', UserReview::SOURCE_GOOGLE)
                    ->where('external_id', $extId)->first();

                if ($existing) {
                    if (! $dry) {
                        // Refresh Google-owned content only; leave moderation alone.
                        $existing->fill($content)->save();
                    }
                    $updated++;
                } else {
                    if (! $dry) {
                        UserReview::create(array_merge($content, [
                            'review_id' => 'GR-'.strtoupper(Str::random(10)),
                            'source' => UserReview::SOURCE_GOOGLE,
                            'external_id' => $extId,
                            'department' => UserReview::DEPT_BOTH,
                            'status' => 'New',
                            'is_published' => false, // moderation gate — nothing shows until approved
                        ]));
                    }
                    $created++;
                }
            }

            $pageToken = $res->json('nextPageToken');
        } while ($pageToken);

        $this->info(($dry ? '[dry] ' : '')."Google reviews synced — seen {$seen}, created {$created}, updated {$updated}, skipped {$skipped} (no text).");

        return self::SUCCESS;
    }
}
