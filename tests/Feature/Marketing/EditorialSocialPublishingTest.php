<?php

namespace Tests\Feature\Marketing;

use App\Models\EditorialArticle;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ghl\GhlConnection;
use App\Services\Ghl\GhlSocialReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditorialSocialPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://services.leadconnectorhq.com/social-media-posting/'.GhlConnection::LOCATION;

    private function draft(): EditorialArticle
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));

        return EditorialArticle::create([
            'title' => 'NZ Study Checklist', 'slug' => 'nz-study-checklist', 'summary' => 'Start with your documents.',
            'body_html' => '<p>Checklist</p>', 'primary_keyword' => 'NZ study checklist', 'meta_title' => 'NZ study',
            'meta_description' => 'Prepare for study', 'author' => 'EP', 'image_url' => 'https://luvep.com/study.jpg',
            'image_alt' => 'Checklist',
        ]);
    }

    private function stage(EditorialArticle $article, string $action, int $revision = 1): array
    {
        return $this->postJson("/admin/content-calendar/articles/{$article->id}/{$action}", ['revision' => $revision])
            ->assertOk()->json();
    }

    private function enable(): void
    {
        config(['services.ghl.article_facebook_enabled' => true, 'app.canonical_url' => 'https://luvep.com']);
        $token = 'test-token-for-fake-http';
        Setting::set('ghl_connector.token', Crypt::encryptString($token));
        Setting::set('ghl_connector.verified_at', now()->toIso8601String());
        Setting::set('ghl_connector.verified_fingerprint', hash('sha256', $token));
        Setting::set('ghl_connector.user_id', 'editorial-user');
    }

    private function fakePlanner(int $status = 201, bool $active = true): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::API.'/accounts' => Http::response(['success' => true, 'results' => ['accounts' => [
                ['id' => 'thumbai', 'active' => true],
                ['id' => GhlSocialReader::ACCOUNT, 'active' => $active],
            ]]]),
            self::API.'/posts' => Http::response(['success' => true, 'results' => ['post' => [
                '_id' => 'social-post-1', 'status' => 'scheduled', 'locationId' => GhlConnection::LOCATION,
                'accountIds' => [GhlSocialReader::ACCOUNT],
            ]]], $status),
        ]);
    }

    public function test_delivery_migration_resumes_when_mysql_already_created_the_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $table = 'editorial_social_deliveries';
        $uniqueColumns = ['editorial_article_id', 'account_id'];
        $unique = collect($schema->getIndexes($table))->firstWhere('columns', $uniqueColumns);
        $status = collect($schema->getIndexes($table))->firstWhere('columns', ['status']);
        $schema->table($table, function ($blueprint) use ($unique, $status) {
            $blueprint->dropUnique($unique['name']);
            $blueprint->dropIndex($status['name']);
        });
        $article = $this->draft();
        $delivery = \App\Models\EditorialSocialDelivery::create([
            'editorial_article_id' => $article->id,
            'account_id' => GhlSocialReader::ACCOUNT,
            'payload' => [],
        ]);

        $migration = require database_path('migrations/2026_11_08_020000_create_editorial_social_deliveries_table.php');
        $migration->up();

        $this->assertTrue($schema->hasIndex($table, $uniqueColumns, 'unique'));
        $this->assertTrue($schema->hasIndex($table, ['status']));
        $this->assertDatabaseHas($table, ['id' => $delivery->id]);
    }

    public function test_only_first_publication_queues_a_frozen_facebook_share(): void
    {
        $this->enable();
        $this->fakePlanner();
        $article = $this->draft();
        $this->stage($article, 'submit');
        $this->assertNull($this->stage($article, 'approve')['facebook_delivery'] ?? null);
        $this->assertSame('pending', $this->stage($article, 'publish')['facebook_delivery']['status'] ?? null);
        Http::assertNothingSent();

        $article->refresh()->update(['status' => 'draft', 'revision' => 2, 'title' => 'Revised title']);
        foreach (['submit', 'approve', 'publish'] as $action) {
            $this->stage($article, $action, 2);
        }
        $this->assertDatabaseCount('editorial_social_deliveries', 1);
        $this->artisan('editorial:dispatch-social')->assertSuccessful();
        $this->artisan('editorial:dispatch-social')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/posts'
            && $request['accountIds'] === [GhlSocialReader::ACCOUNT]
            && $request['status'] === 'scheduled'
            && $request['userId'] === 'editorial-user'
            && $request['summary'] === "NZ Study Checklist\n\nStart with your documents.\n\nhttps://luvep.com/articles/nz-study-checklist"
            && $request['ogTagsDetails']['metaImage'] === 'https://luvep.com/study.jpg'
            && $request['ogTagsDetails']['metaLink'] === 'https://luvep.com/articles/nz-study-checklist');
        $this->getJson("/admin/content-calendar/articles/{$article->id}")->assertOk()
            ->assertJsonPath('facebook_delivery.status', 'scheduled')
            ->assertJsonPath('facebook_delivery.provider_post_id', 'social-post-1');
    }

    public function test_uncertain_create_is_visible_and_never_resent_automatically(): void
    {
        $this->enable();
        $this->fakePlanner(503);
        $article = $this->draft();
        foreach (['submit', 'approve', 'publish'] as $action) {
            $this->stage($article, $action);
        }

        $this->artisan('editorial:dispatch-social')->assertSuccessful();
        $this->artisan('editorial:dispatch-social')->assertSuccessful();
        Http::assertSentCount(2);
        $this->getJson("/admin/content-calendar/articles/{$article->id}")->assertOk()
            ->assertJsonPath('facebook_delivery.status', 'uncertain');
    }

    public function test_inactive_account_keeps_delivery_waiting_without_creating_a_post(): void
    {
        $this->enable();
        $this->fakePlanner(active: false);
        $article = $this->draft();
        foreach (['submit', 'approve', 'publish'] as $action) {
            $this->stage($article, $action);
        }

        $this->artisan('editorial:dispatch-social')->assertSuccessful();
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->getJson("/admin/content-calendar/articles/{$article->id}")->assertOk()
            ->assertJsonPath('facebook_delivery.status', 'pending')
            ->assertJsonPath('facebook_delivery.waiting_for_connection', true);
    }

    public function test_enabling_after_publication_does_not_backfill_the_article(): void
    {
        $this->fakePlanner();
        config(['services.ghl.article_facebook_enabled' => false]);
        $article = $this->draft();
        foreach (['submit', 'approve', 'publish'] as $action) {
            $this->stage($article, $action);
        }

        $this->enable();
        $this->artisan('editorial:dispatch-social')->assertSuccessful();
        $this->assertDatabaseCount('editorial_social_deliveries', 0);
        Http::assertNothingSent();
    }
}
