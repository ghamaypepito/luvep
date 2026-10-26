<?php

namespace Tests\Feature\Marketing;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlSocialReaderTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = '6acefdbae422a0c5111f01d3_EPwLVKAvmJdF8IvwBLwO_105394802181267_page';

    private const LOCATION = 'EPwLVKAvmJdF8IvwBLwO';

    private function connect(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        Setting::set('ghl_connector.token', Crypt::encryptString('private-ghl-test-token'));
    }

    private function fake(array $posts = [], array $stats = []): void
    {
        Http::fake(['*/accounts' => Http::response(['success' => true, 'results' => ['accounts' => [['id' => self::ACCOUNT, 'profileId' => 'ep-profile', 'locationId' => self::LOCATION, 'name' => 'Luv, Ep', 'platform' => 'facebook', 'active' => true, 'oauthId' => 'must-never-return']]]]),
            '*/posts/list' => Http::response(['success' => true, 'results' => ['posts' => $posts, 'count' => count($posts)]], 201),
            '*/statistics*' => Http::response(['results' => $stats])]);
    }

    public function test_read_endpoints_are_admin_only(): void
    {
        Http::preventStrayRequests();
        foreach (['accounts', 'posts', 'insights'] as $part) {
            $this->getJson('/social/ghl/'.$part)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(['role' => 'education']));
        foreach (['accounts', 'posts', 'insights'] as $part) {
            $this->getJson('/social/ghl/'.$part)->assertForbidden();
        }Http::assertNothingSent();
    }

    public function test_accounts_expose_only_safe_fields_and_select_ep_page(): void
    {
        $this->connect();
        $this->fake();
        $r = $this->getJson('/social/ghl/accounts')->assertOk()->assertJsonPath('accounts.0.selected', true)->assertJsonPath('publishing_enabled', false);
        $r->assertDontSee('must-never-return')->assertDontSee('ep-profile')->assertDontSee('private-ghl-test-token');
    }

    public function test_posts_use_fixed_location_account_string_pagination_and_safe_output(): void
    {
        $this->connect();
        $this->fake([['_id' => 'ep-post', 'locationId' => self::LOCATION, 'accountIds' => [self::ACCOUNT], 'status' => 'scheduled', 'summary' => 'A real post', 'scheduleDate' => '2026-10-28T00:00:00Z', 'createdBy' => 'private-user', 'insights' => ['like' => 3, 'share' => 0], 'media' => [['url' => 'javascript:bad']]]]);
        $this->getJson('/social/ghl/posts?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('posts.0.caption', 'A real post')->assertJsonPath('posts.0.insights.likes', 3)->assertJsonPath('posts.0.image', null)->assertDontSee('private-user');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/'.self::LOCATION.'/posts/list') && $r['accounts'] === self::ACCOUNT && $r['skip'] === '0' && $r['limit'] === '100' && $r['includeUsers'] === 'false');
    }

    public function test_other_locations_and_unselected_page_posts_are_rejected_or_excluded(): void
    {
        $this->connect();
        $this->fake([['_id' => 'other', 'locationId' => self::LOCATION, 'accountIds' => ['thumbai'], 'summary' => 'Do not show ThumbAI', 'status' => 'published']]);
        $this->getJson('/social/ghl/posts?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(0, 'posts')->assertDontSee('Do not show ThumbAI');
    }

    public function test_post_from_other_location_is_rejected(): void
    {
        $this->connect();
        $this->fake([['_id' => 'other', 'locationId' => 'wrong-location', 'accountIds' => [self::ACCOUNT]]]);
        $this->getJson('/social/ghl/posts?from=2026-10-01&to=2026-10-31')->assertStatus(502)->assertJsonPath('error', 'location_mismatch');
    }

    public function test_stats_include_location_query_and_use_profile_id_and_truthful_missing_metrics(): void
    {
        $this->connect();
        $this->fake([], ['totals' => ['impressions' => 123, 'likes' => 0], 'dayRange' => ['Mon'], 'postPerformance' => ['impressions' => [123]]]);
        $this->getJson('/social/ghl/insights?days=7')->assertOk()->assertJsonPath('totals.impressions', 123)->assertJsonPath('totals.likes', 0)->assertJsonPath('totals.comments', null);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'statistics?locationId='.self::LOCATION) && $r['profileIds'] === ['ep-profile']);
    }

    public function test_provider_errors_and_bad_ranges_are_safe_and_never_publish(): void
    {
        $this->connect();
        Http::preventStrayRequests();
        $this->getJson('/social/ghl/posts?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        Http::assertNothingSent();
        Http::fake(['*' => Http::response(['secret' => 'upstream-token'], 403)]);
        $this->getJson('/social/ghl/accounts')->assertStatus(502)->assertJsonPath('error', 'authorization')->assertDontSee('upstream-token');
    }

    public function test_video_post_uses_its_poster_for_calendar_and_exposes_playable_media(): void
    {
        $this->connect();
        $this->fake([['_id' => 'video-post', 'locationId' => self::LOCATION, 'accountIds' => [self::ACCOUNT], 'status' => 'scheduled', 'summary' => 'Full video caption', 'thumbnail' => 'https://cdn.example/poster.png', 'media' => [['url' => 'https://cdn.example/clip.mp4', 'type' => 'video/mp4', 'thumbnail' => '']]]]);
        $this->getJson('/social/ghl/posts?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('posts.0.image', 'https://cdn.example/poster.png')->assertJsonPath('posts.0.media.0.kind', 'video')->assertJsonPath('posts.0.media.0.url', 'https://cdn.example/clip.mp4')->assertJsonPath('posts.0.media.0.poster', 'https://cdn.example/poster.png')->assertJsonPath('posts.0.caption', 'Full video caption');
    }

    public function test_image_gallery_preserves_all_safe_attachments(): void
    {
        $this->connect();
        $this->fake([['_id' => 'gallery', 'locationId' => self::LOCATION, 'accountIds' => [self::ACCOUNT], 'status' => 'published', 'media' => [['url' => 'https://cdn.example/one.jpg', 'type' => 'image/jpeg'], ['url' => 'https://cdn.example/two.png', 'type' => 'image/png'], ['url' => 'javascript:bad', 'type' => 'image/png']]]]);
        $this->getJson('/social/ghl/posts?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(2, 'posts.0.media')->assertJsonPath('posts.0.media.1.url', 'https://cdn.example/two.png')->assertJsonPath('posts.0.image', 'https://cdn.example/one.jpg')->assertDontSee('javascript:bad');
    }
}
