<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingPost;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ghl\GhlConnection;
use App\Services\Ghl\GhlSocialReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MarketingPostWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    }

    private function draft(array $extra = []): array
    {
        return $this->postJson('/social/marketing/posts', array_merge(['campaign' => 'NZ campaign', 'service' => 'Education', 'caption' => 'Approved NZ caption', 'schedule_date' => now('Pacific/Auckland')->addDays(2)->toDateString(), 'schedule_time' => '14:30'], $extra))->assertOk()->json('post');
    }

    private function approve(array $p): array
    {
        $p = $this->postJson("/social/marketing/posts/{$p['id']}/submit", ['version' => $p['version']])->assertOk()->json('post');

        return $this->postJson("/social/marketing/posts/{$p['id']}/approve", ['version' => $p['version']])->assertOk()->json('post');
    }

    private int $writes = 0;

    private ?string $capturedMediaUrl = null;

    private function connected(string $mode = 'success'): void
    {
        Setting::set('ghl_connector.token', Crypt::encryptString('test-token'));
        Setting::set('ghl_connector.verified_at', now()->toIso8601String());
        Setting::set('ghl_connector.verified_fingerprint', hash('sha256', 'test-token'));
        Setting::set('ghl_connector.user_id', 'verified-ghl-user');
        Http::fake(function ($r) use ($mode) {
            if ($r->method() === 'GET') {
                return Http::response(['success' => true, 'results' => ['accounts' => [['id' => GhlSocialReader::ACCOUNT, 'locationId' => GhlConnection::LOCATION, 'active' => true, 'name' => 'Luv, Ep']]]]);
            }
            $this->writes++;
            $this->capturedMediaUrl = $r['media'][0]['url'] ?? null;
            $this->assertSame('scheduling', MarketingPost::latest('updated_at')->first()->status);
            if ($mode === 'timeout') {
                throw new \Illuminate\Http\Client\ConnectionException('secret transport');
            }

            return Http::response(['success' => true, 'results' => ['post' => ['_id' => 'remote-post', 'locationId' => $mode === 'foreign' ? 'other' : GhlConnection::LOCATION, 'accountIds' => [GhlSocialReader::ACCOUNT], 'status' => 'scheduled']]], 201);
        });
    }

    public function test_saved_workflow_requires_super_admin_for_review_and_every_edit_invalidates_approval(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $p = $this->draft();
        foreach (['approve', 'changes', 'schedule'] as $action) {
            $this->postJson("/social/marketing/posts/{$p['id']}/$action", ['version' => $p['version']])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $p = $this->approve($p);
        $edited = $this->draft(['id' => $p['id'], 'version' => $p['version'], 'caption' => 'Edited caption']);
        $this->assertSame('draft', $edited['status']);
        $this->assertNull($edited['approved_by']);
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_stale_review_and_unsubmitted_approval_are_rejected(): void
    {
        $p = $this->draft();
        $this->postJson("/social/marketing/posts/{$p['id']}/approve", ['version' => $p['version']])->assertConflict();
        $submitted = $this->postJson("/social/marketing/posts/{$p['id']}/submit", ['version' => $p['version']])->assertOk()->json('post');
        $this->postJson("/social/marketing/posts/{$p['id']}/approve", ['version' => $p['version']])->assertConflict();
        $this->postJson("/social/marketing/posts/{$p['id']}/changes", ['version' => $submitted['version'], 'note' => 'Please revise'])->assertOk()->assertJsonPath('post.status', 'changes_requested')->assertJsonPath('post.review_note', 'Please revise');
    }

    public function test_schedule_only_uses_persisted_approved_content_and_fixed_destination(): void
    {
        $this->connected();
        $p = $this->approve($this->draft());
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version'], 'caption' => 'override'])->assertUnprocessable();
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertOk()->assertJsonPath('post.status', 'scheduled')->assertJsonPath('post.ghl_post_id', 'remote-post');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://services.leadconnectorhq.com/social-media-posting/'.GhlConnection::LOCATION.'/posts' && $r['summary'] === $p['caption'] && $r['status'] === 'scheduled' && $r['type'] === 'post' && $r['accountIds'] === [GhlSocialReader::ACCOUNT] && $r['userId'] === 'verified-ghl-user' && $r->hasHeader('Version', 'v3'));
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertConflict();
    }

    public function test_missing_identity_and_missing_or_past_timing_never_write_to_provider(): void
    {
        $p = $this->approve($this->draft());
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertUnprocessable();
        $this->connected();
        foreach ([['schedule_date' => null, 'schedule_time' => null], ['schedule_date' => '2020-01-01']] as $timing) {
            $p = $this->approve($this->draft($timing));
            $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_ambiguous_result_persists_reconciliation_and_cannot_be_retried_or_edited(): void
    {
        $this->connected('timeout');
        $p = $this->approve($this->draft());
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertStatus(502)->assertDontSee('secret transport')->assertJsonPath('post.status', 'needs_reconciliation');
        $fresh = MarketingPost::find($p['id']);
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $fresh->version])->assertConflict();
        $this->postJson('/social/marketing/posts', ['id' => $p['id'], 'version' => $fresh->version, 'campaign' => 'x', 'service' => 'x', 'caption' => 'changed'])->assertConflict();
        $this->assertSame(1, $this->writes);
    }

    public function test_foreign_or_unconfirmed_response_cannot_be_marked_scheduled(): void
    {
        $this->connected('foreign');
        $p = $this->approve($this->draft());
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertStatus(502)->assertJsonPath('post.status', 'needs_reconciliation')->assertJsonPath('post.ghl_post_id', null);
    }

    public function test_private_media_requires_current_approved_version_and_hash(): void
    {
        $p = $this->draft(['media' => UploadedFile::fake()->image('post.png', 100, 100)]);
        $row = MarketingPost::find($p['id']);
        $this->assertFalse(Storage::disk('public')->exists($row->media_path));
        $this->get($p['image_url'])->assertOk();
        $url = URL::temporarySignedRoute('marketing.media.shared', now()->addDays(4), ['id' => $p['id'], 'version' => $row->version, 'hash' => $row->media_hash]);
        $this->get($url)->assertNotFound();
        $p = $this->approve($p);
        $row->refresh();
        $url = URL::temporarySignedRoute('marketing.media.shared', now()->addDays(4), ['id' => $p['id'], 'version' => $row->version, 'hash' => $row->media_hash]);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->draft(['id' => $p['id'], 'version' => $p['version']]);
        $this->get($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($p['image_url'])->assertUnauthorized();
    }

    public function test_invalid_image_urls_and_oversized_pixels_are_rejected_and_shape_is_safe(): void
    {
        $this->postJson('/social/marketing/posts', ['campaign' => 'x', 'service' => 'x', 'caption' => 'x', 'media' => 'https://example.org/a.png'])->assertUnprocessable();
        $this->postJson('/social/marketing/posts', ['campaign' => 'x', 'service' => 'x', 'caption' => 'x', 'image_url' => 'https://example.org/a.png'])->assertUnprocessable();
        $p = $this->draft();
        $this->getJson('/social/marketing/posts')->assertOk()->assertJsonPath('can_approve', true)->assertJsonPath('ghl_user_configured', false)->assertDontSee('media_path')->assertDontSee('approval_fingerprint')->assertDontSee('location_id');
        $this->postJson('/social/marketing/posts', ['campaign' => 'x', 'service' => 'x', 'caption' => 'x', 'media' => UploadedFile::fake()->image('large.png', 9000, 1)])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->getJson('/social/marketing/posts')->assertForbidden();
    }

    public function test_missing_user_with_verified_connection_prevents_all_network_calls(): void
    {
        $this->connected();
        Setting::set('ghl_connector.user_id', '');
        $p = $this->approve($this->draft());
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_invalid_scheduling_identity_never_reaches_provider(): void
    {
        $this->connected();
        Setting::set('ghl_connector.user_id', 'arbitrary user / overrides');
        $p = $this->approve($this->draft());
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_changed_approval_fingerprint_and_other_location_are_not_usable(): void
    {
        $p = $this->approve($this->draft());
        MarketingPost::find($p['id'])->update(['caption' => 'Bypassed edit']);
        $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertConflict();
        MarketingPost::find($p['id'])->update(['location_id' => 'other']);
        $this->getJson('/social/marketing/posts')->assertOk()->assertJsonCount(0, 'posts');
        $this->getJson("/social/marketing/posts/{$p['id']}/media")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_media_scheduling_sends_signed_approved_file_and_nz_time_as_utc(): void
    {
        URL::forceScheme('https');
        try {
            $this->connected();
            $p = $this->approve($this->draft(['media' => UploadedFile::fake()->image('post.jpg', 80, 80), 'schedule_date' => now('Pacific/Auckland')->addDays(2)->toDateString()]));
            $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])->assertOk();
            Http::assertSent(function ($r) use ($p) {
                if ($r->method() !== 'POST') {
                    return false;
                }
                $this->assertSame('image/jpeg', $r['media'][0]['type']);
                $this->assertSame($p['scheduled_at'], $r['scheduleDate']);
                $url = $r['media'][0]['url'];
                $this->assertStringStartsWith('https://', $url);
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertGreaterThan(strtotime($p['scheduled_at']) + 29 * 86400, (int) $query['expires']);
                $this->get($url)->assertOk();

                return true;
            });
        } finally {
            URL::forceScheme(null);
        }
    }

    public function test_spoofed_image_mime_and_unsigned_public_access_are_rejected(): void
    {
        $this->postJson('/social/marketing/posts', ['campaign' => 'x', 'service' => 'x', 'caption' => 'x',
            'media' => UploadedFile::fake()->createWithContent('fake.png', '<svg onload="alert(1)"></svg>')])->assertUnprocessable();
        $p = $this->draft(['media' => UploadedFile::fake()->image('post.png', 80, 80)]);
        $this->get('/social/marketing/media/'.$p['id'])->assertForbidden();
    }

    public function test_timeout_keeps_approved_media_available_for_deferred_provider_fetch_and_forbids_retry(): void
    {
        URL::forceScheme('https');
        try {
            $this->connected('timeout');
            $p = $this->approve($this->draft(['media' => UploadedFile::fake()->image('approved.png', 80, 80)]));
            $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $p['version']])
                ->assertStatus(502)->assertJsonPath('post.status', 'needs_reconciliation');
            $this->assertNotNull($this->capturedMediaUrl);
            $post = MarketingPost::findOrFail($p['id']);
            $this->postJson("/social/marketing/posts/{$p['id']}/schedule", ['version' => $post->version])->assertConflict();
            $this->assertSame(1, $this->writes);
            $this->app['auth']->forgetGuards();
            $response = $this->get($this->capturedMediaUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertSame(Storage::disk('local')->get($post->media_path), $response->streamedContent());
        } finally {
            URL::forceScheme(null);
        }
    }
}
