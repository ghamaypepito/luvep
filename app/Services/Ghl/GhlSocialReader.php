<?php

namespace App\Services\Ghl;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class GhlSocialReader
{
    public const ACCOUNT = '6acefdbae422a0c5111f01d3_EPwLVKAvmJdF8IvwBLwO_105394802181267_page';

    private ?array $accountResult = null;

    public function __construct(private GhlConnection $connection) {}

    private function request(string $method, string $path, array $payload = []): array
    {
        if ($this->connection->token() === '') {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        try {
            $http = Http::withToken($this->connection->token())->acceptJson()->withHeaders(['Version' => 'v3'])->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20);
            $r = $method === 'GET' ? $http->get('https://services.leadconnectorhq.com/social-media-posting/'.$path) : $http->post('https://services.leadconnectorhq.com/social-media-posting/'.$path, $payload);
            if (! $r->successful()) {
                return ['ok' => false, 'error' => in_array($r->status(), [401, 403]) ? 'authorization' : ($r->status() === 429 ? 'rate_limit' : 'provider_unavailable')];
            }
            $data = $r->json();
            if (! is_array($data) || ($data['success'] ?? true) !== true) {
                return ['ok' => false, 'error' => 'invalid_response'];
            }

            return ['ok' => true, 'data' => $data];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'connection'];
        }
    }

    private function fetchAccounts(): array
    {
        if ($this->accountResult !== null) {
            return $this->accountResult;
        }
        $r = $this->request('GET', GhlConnection::LOCATION.'/accounts');
        if (! $r['ok']) {
            return $this->accountResult = $r;
        }
        $rows = $r['data']['results']['accounts'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return $this->accountResult = ['ok' => false, 'error' => 'invalid_response'];
        }
        $safe = [];
        foreach ($rows as $a) {
            if (! is_array($a) || ! is_string($a['id'] ?? null) || (($a['locationId'] ?? GhlConnection::LOCATION) !== GhlConnection::LOCATION)) {
                return $this->accountResult = ['ok' => false, 'error' => 'location_mismatch'];
            }
            $safe[] = ['id' => mb_substr($a['id'], 0, 255), 'name' => mb_substr((string) ($a['name'] ?? 'Unnamed account'), 0, 200), 'platform' => mb_substr((string) ($a['platform'] ?? 'unknown'), 0, 40), 'active' => (bool) ($a['active'] ?? false) && ! ($a['isExpired'] ?? false), 'selected' => $a['id'] === self::ACCOUNT, 'profile_id' => is_string($a['profileId'] ?? null) ? $a['profileId'] : null];
        }
        if (! collect($safe)->contains(fn ($a) => $a['selected'])) {
            return $this->accountResult = ['ok' => false, 'error' => 'ep_account_missing'];
        }

        return $this->accountResult = ['ok' => true, 'accounts' => $safe];
    }

    public function accounts(): array
    {
        $r = $this->fetchAccounts();
        if (! $r['ok']) {
            return $r;
        }

        return ['ok' => true, 'accounts' => array_map(function ($a) {
            unset($a['profile_id']);

            return $a;
        }, $r['accounts']), 'selected_account_id' => self::ACCOUNT, 'location_id' => GhlConnection::LOCATION, 'publishing_enabled' => $this->connection->state()['publishing_enabled'], 'synced_at' => now()->toIso8601String()];
    }

    private function selected(): array
    {
        $r = $this->fetchAccounts();
        if (! $r['ok']) {
            return $r;
        }
        $a = collect($r['accounts'])->firstWhere('selected', true);

        return $a['active'] ? ['ok' => true, 'account' => $a] : ['ok' => false, 'error' => 'account_inactive'];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 100) {
            return null;
        }try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): int|float|null
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? $value + 0 : null;
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 4096 && filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https' ? $value : null;
    }

    private function media(array $post): array
    {
        $thumbnail = $this->url($post['thumbnail'] ?? null);
        $rows = is_array($post['media'] ?? null) ? $post['media'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = is_string($row['type'] ?? null) ? strtolower(trim(explode(';', $row['type'])[0])) : '';
            $kind = match ($type) {
                'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp' => 'image',
                'video/mp4', 'video/webm', 'video/mov', 'video/quicktime' => 'video',
                default => null,
            };
            $url = $this->url($row['url'] ?? null) ?? $this->url($row['watermarkedUrl'] ?? null) ?? $this->url($row['originalUrl'] ?? null);
            if (! $kind || ! $url) {
                continue;
            }
            $poster = $this->url($row['thumbnail'] ?? null) ?? $this->url($row['defaultThumb'] ?? null) ?? (count($items) === 0 ? $thumbnail : null);
            $items[] = ['kind' => $kind, 'type' => $type, 'url' => $url, 'poster' => $poster];
        }
        $first = $items[0] ?? null;
        $image = $first && $first['kind'] === 'image' ? $first['url'] : ($first['poster'] ?? $thumbnail);

        return ['media' => $items, 'image' => $image, 'thumbnail' => $thumbnail];
    }

    public function posts(string $from, string $to, int $page = 1, string $type = 'all'): array
    {
        $a = $this->selected();
        if (! $a['ok']) {
            return $a;
        }
        $start = CarbonImmutable::parse($from, 'Pacific/Auckland')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Pacific/Auckland')->endOfDay()->utc();
        $r = $this->request('POST', GhlConnection::LOCATION.'/posts/list', ['type' => $type, 'accounts' => self::ACCOUNT, 'skip' => (string) (($page - 1) * 100), 'limit' => '100', 'fromDate' => $start->toIso8601String(), 'toDate' => $end->toIso8601String(), 'includeUsers' => 'false']);
        if (! $r['ok']) {
            return $r;
        }
        $rows = $r['data']['results']['posts'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['ok' => false, 'error' => 'invalid_response'];
        }
        $safe = [];
        $excluded = false;
        foreach ($rows as $p) {
            if (! is_array($p) || ($p['locationId'] ?? null) !== GhlConnection::LOCATION) {
                return ['ok' => false, 'error' => 'location_mismatch'];
            }
            if (! is_array($p['accountIds'] ?? null) || ! in_array(self::ACCOUNT, $p['accountIds'], true)) {
                $excluded = true;

                continue;
            }
            if (($p['deleted'] ?? false) || ($p['status'] ?? '') === 'deleted') {
                continue;
            }
            $id = $p['_id'] ?? $p['postId'] ?? null;
            if (! is_string($id)) {
                return ['ok' => false, 'error' => 'invalid_response'];
            }
            $insights = $p['insights'] ?? [];
            $media = $this->media($p);
            $safe[] = ['id' => mb_substr($id, 0, 255), 'caption' => mb_substr(is_string($p['summary'] ?? null) ? $p['summary'] : '', 0, 12000), 'status' => mb_substr((string) ($p['status'] ?? 'unknown'), 0, 40), 'date' => $this->date($p['scheduleDate'] ?? $p['publishedAt'] ?? $p['displayDate'] ?? null), 'image' => $media['image'], 'thumbnail' => $media['thumbnail'], 'media' => $media['media'], 'preview_url' => $this->url($p['previewLink'] ?? null), 'account_name' => $a['account']['name'], 'platform' => $a['account']['platform'], 'insights' => ['likes' => $this->number($insights['like'] ?? null), 'comments' => $this->number($insights['comment'] ?? null), 'shares' => $this->number($insights['share'] ?? null)]];
        }
        $count = $excluded ? null : $this->number($r['data']['results']['count'] ?? null);

        return ['ok' => true, 'posts' => $safe, 'total' => $count, 'page' => $page, 'has_more' => count($rows) === 100 && ($count === null || $count > $page * 100), 'from' => $from, 'to' => $to, 'timezone' => 'Pacific/Auckland', 'synced_at' => now()->toIso8601String()];
    }

    public function insights(int $days): array
    {
        $a = $this->selected();
        if (! $a['ok']) {
            return $a;
        }if (! $a['account']['profile_id']) {
            return ['ok' => false, 'error' => 'statistics_unavailable'];
        }
        $end = CarbonImmutable::now('Pacific/Auckland')->startOfDay()->subSecond();
        $start = $end->startOfDay()->subDays($days - 1);
        $r = $this->request('POST', 'statistics?locationId='.GhlConnection::LOCATION, ['profileIds' => [$a['account']['profile_id']], 'platforms' => [$a['account']['platform']], 'currentRange' => ['startDate' => $start->utc()->toIso8601String(), 'endDate' => $end->utc()->toIso8601String()]]);
        if (! $r['ok']) {
            return $r;
        }
        $data = $r['data']['results'] ?? null;
        if (! is_array($data) || ! is_array($data['totals'] ?? null)) {
            return ['ok' => false, 'error' => 'invalid_response'];
        }
        $totals = [];
        foreach (['posts', 'likes', 'comments', 'shares', 'followers', 'impressions'] as $key) {
            $totals[$key] = $this->number($data['totals'][$key] ?? null);
        }
        $trend = $data['postPerformance']['impressions'] ?? null;
        $trend = is_array($trend) && array_is_list($trend) ? array_map(fn ($v) => $this->number($v), array_slice($trend, 0, 62)) : [];

        return ['ok' => true, 'account_name' => $a['account']['name'], 'platform' => $a['account']['platform'], 'totals' => $totals, 'impressions_trend' => $trend, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'timezone' => 'Pacific/Auckland', 'synced_at' => now()->toIso8601String(), 'campaign_attribution_available' => false];
    }
}
