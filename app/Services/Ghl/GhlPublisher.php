<?php

namespace App\Services\Ghl;

use App\Models\MarketingPost;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class GhlPublisher
{
    public function __construct(private GhlConnection $connection, private GhlSocialReader $reader) {}

    public function assertReady(): void
    {
        if (! $this->connection->state()['verified'] || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', (string) Setting::get('ghl_connector.user_id', ''))) {
            throw ValidationException::withMessages(['connection' => 'Verify the saved EP connection and configure its scheduling user first.']);
        }
        $accounts = $this->reader->accounts();
        $selected = collect($accounts['accounts'] ?? [])->firstWhere('id', GhlSocialReader::ACCOUNT);
        if (! ($accounts['ok'] ?? false) || ! ($selected['active'] ?? false)) {
            throw ValidationException::withMessages(['connection' => 'The selected Luv, Ep account must be available and active.']);
        }
    }

    /** Exactly one create request. Any uncertain result requires manual reconciliation. */
    public function schedule(MarketingPost $post): ?string
    {
        $payload = ['type' => 'post', 'accountIds' => [GhlSocialReader::ACCOUNT],
            'userId' => (string) Setting::get('ghl_connector.user_id'), 'summary' => $post->caption,
            'status' => 'scheduled', 'scheduleDate' => $post->scheduled_at->utc()->toIso8601String()];
        if ($post->media_path) {
            $url = URL::temporarySignedRoute('marketing.media.shared', $post->scheduled_at->addDays(30), [
                'id' => $post->id, 'version' => $post->approved_version, 'hash' => $post->media_hash,
            ]);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                return null;
            }
            $payload['media'] = [['url' => $url, 'type' => $post->media_mime]];
        }
        try {
            $response = Http::withToken($this->connection->token())->acceptJson()
                ->withHeaders(['Version' => 'v3'])->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)->timeout(30)
                ->post('https://services.leadconnectorhq.com/social-media-posting/'.GhlConnection::LOCATION.'/posts', $payload);
            $remote = $response->json('results.post');
            if (! $response->successful() || $response->json('success') !== true || ! is_array($remote)
                || ($remote['locationId'] ?? null) !== GhlConnection::LOCATION
                || ($remote['status'] ?? null) !== 'scheduled'
                || ($remote['accountIds'] ?? null) !== [GhlSocialReader::ACCOUNT]
                || ! is_string($remote['_id'] ?? null) || trim($remote['_id']) === '' || strlen($remote['_id']) > 255) {
                return null;
            }

            return $remote['_id'];
        } catch (\Throwable) {
            return null;
        }
    }
}
