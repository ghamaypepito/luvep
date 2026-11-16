<?php

namespace Tests\Feature;

use App\Models\UserReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleReviewIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    private const REVIEWS = 'https://mybusiness.googleapis.com/v4/accounts/account-test/locations/location-test/reviews';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_business' => ['client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'refresh_token' => 'synthetic-refresh', 'account_id' => 'account-test', 'location_id' => 'location-test']]);
        Http::preventStrayRequests();
    }

    private function fake(array $reviews = []): void
    {
        Http::fake([self::TOKEN => Http::response(['access_token' => 'synthetic-access']), self::ACCOUNTS => Http::response(['accounts' => [['name' => 'accounts/account-test', 'accountName' => 'Synthetic business']]]), self::REVIEWS.'*' => Http::response(['reviews' => $reviews])]);
    }

    private function review(string $id = 'test-review', string $comment = 'Synthetic review'): array
    {
        return ['reviewId' => $id, 'reviewer' => ['displayName' => 'Synthetic reviewer'], 'comment' => $comment, 'starRating' => 'FOUR', 'createTime' => '2026-11-01T01:00:00Z'];
    }

    public function test_diagnostic_fails_when_missing_location_prevents_the_reviews_test(): void
    {
        config(['services.google_business.location_id' => null]);
        $this->fake();
        $this->artisan('google:test-reviews')->assertFailed();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/reviews'));
    }

    public function test_diagnostic_requires_explicit_account_even_when_an_account_is_visible(): void
    {
        config(['services.google_business.account_id' => null]);
        $this->fake();
        $this->artisan('google:test-reviews')->assertFailed();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/reviews'));
    }

    public function test_missing_oauth_configuration_fails_without_network(): void
    {
        config(['services.google_business.refresh_token' => null]);
        Http::fake();
        $this->artisan('google:test-reviews')->assertFailed();
        $this->artisan('google:sync-reviews')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_diagnostic_succeeds_only_after_reviews_endpoint_answers(): void
    {
        $this->fake();
        $this->artisan('google:test-reviews')->expectsOutputToContain('WORKING')->assertSuccessful();
        Http::assertSent(fn ($r) => str_starts_with($r->url(), self::REVIEWS));
        $this->assertDatabaseCount('user_reviews', 0);
    }

    public function test_diagnostic_rate_limit_is_not_a_conclusion_that_approval_was_denied(): void
    {
        Http::fake([self::TOKEN => Http::response(['access_token' => 'synthetic-access']), self::ACCOUNTS => Http::response(['accounts' => []]), self::REVIEWS.'*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Synthetic quota limit']], 429)]);
        $this->artisan('google:test-reviews')->expectsOutputToContain('RATE LIMITED')->doesntExpectOutputToContain('Access has NOT been approved')->assertFailed();
    }

    public function test_diagnostic_bounds_requested_page_size_to_the_provider_maximum(): void
    {
        $this->fake();
        $this->artisan('google:test-reviews', ['--limit' => 999])->assertSuccessful();
        Http::assertSent(fn ($r) => str_starts_with($r->url(), self::REVIEWS) && str_contains($r->url(), 'pageSize=50'));
    }

    public function test_sync_rejects_successful_token_response_without_a_token(): void
    {
        Http::fake([self::TOKEN => Http::response(['token_type' => 'Bearer'])]);
        $this->artisan('google:sync-reviews')->assertFailed();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('user_reviews', 0);
    }

    public function test_dry_sync_fetches_pages_without_writing_or_publishing(): void
    {
        Http::fake([self::TOKEN => Http::response(['access_token' => 'synthetic-access']), self::REVIEWS.'*' => Http::sequence()->push(['reviews' => [$this->review()], 'nextPageToken' => 'page-two'])->push(['reviews' => [$this->review('second')]])]);
        $this->artisan('google:sync-reviews', ['--dry' => true])->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => ($r->data()['pageToken'] ?? null) === 'page-two');
        $this->assertDatabaseCount('user_reviews', 0);
    }

    public function test_sync_creates_unpublished_google_review_skips_empty_text_and_deduplicates_reruns(): void
    {
        $this->fake([$this->review(), $this->review('empty', '')]);
        $this->artisan('google:sync-reviews')->assertSuccessful();
        $this->artisan('google:sync-reviews')->assertSuccessful();
        $r = UserReview::sole();
        $this->assertSame('google', $r->source);
        $this->assertSame('test-review', $r->external_id);
        $this->assertFalse($r->is_published);
        $this->assertSame('New', $r->status);
        $this->assertSame(4, $r->rating);
    }

    public function test_resync_preserves_staff_moderation_and_publication_fields(): void
    {
        Http::fake([self::TOKEN => Http::response(['access_token' => 'synthetic-access']), self::REVIEWS.'*' => Http::sequence()->push(['reviews' => [$this->review()]])->push(['reviews' => [$this->review('test-review', 'Updated synthetic review')]])]);
        $this->artisan('google:sync-reviews')->assertSuccessful();
        $r = UserReview::sole();
        $r->update(['department' => 'education', 'status' => 'Approved', 'is_published' => true, 'is_featured' => true]);
        $this->artisan('google:sync-reviews')->assertSuccessful();
        $r = $r->fresh();
        $this->assertSame('Updated synthetic review', $r->paragraph);
        $this->assertSame('education', $r->department);
        $this->assertSame('Approved', $r->status);
        $this->assertTrue($r->is_published);
        $this->assertTrue($r->is_featured);
        $this->assertDatabaseCount('user_reviews', 1);
    }

    public function test_provider_denial_leaves_database_unchanged(): void
    {
        Http::fake([self::TOKEN => Http::response(['access_token' => 'synthetic-access']), self::REVIEWS.'*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);
        $this->artisan('google:sync-reviews')->assertFailed();
        $this->assertDatabaseCount('user_reviews', 0);
    }
}
