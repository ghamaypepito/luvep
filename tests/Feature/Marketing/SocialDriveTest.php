<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingPost;
use App\Models\SocialAsset;
use App\Models\User;
use App\Services\Ghl\GhlConnection;
use App\Services\Ghl\GhlSocialReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SocialDriveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_drive_and_file_bytes_are_private_to_active_admins_and_explicit_internal_digital_members(): void
    {
        Storage::disk('local')->put('social-drive/private.png', 'private-bytes');
        $asset = SocialAsset::create(['name' => 'Private artwork', 'file_path' => 'social-drive/private.png', 'mime' => 'image/png', 'uploaded_by' => 1]);
        foreach (['/social/drive/assets', '/social/drive/assets/'.$asset->id.'/download'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ([['role' => 'education'], ['role' => 'lead', 'module_permissions' => ['social_marketing']], ['role' => 'admin', 'is_active' => false]] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            foreach (['/social/drive/assets', '/social/drive/assets/'.$asset->id.'/download'] as $url) {
                $response = $this->getJson($url);
                if ($attributes['is_active'] ?? true) {
                    $response->assertForbidden();
                } else {
                    $this->assertContains($response->status(), [401, 403]);
                }
            }
        }
        foreach ([['role' => 'admin'], ['role' => 'education', 'module_permissions' => ['social_marketing']]] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            $this->getJson('/social/drive/assets')->assertOk()->assertJsonPath('assets.data.0.name', 'Private artwork')->assertDontSee('private.png');
            $this->get('/social/drive/assets/'.$asset->id.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        Http::assertNothingSent();
    }

    public function test_upload_retry_is_idempotent_and_new_post_media_is_a_separate_unapproved_copy(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $key = (string) Str::uuid();
        $file = UploadedFile::fake()->image('draft.png');
        $payload = ['name' => 'New design', 'caption' => 'Draft caption', 'upload_key' => $key, 'file' => $file];
        $asset = $this->post('/social/drive/assets', $payload, ['Accept' => 'application/json'])->assertCreated()->json('asset');
        $this->post('/social/drive/assets', $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('asset.id', $asset['id']);
        $this->assertDatabaseCount('social_assets', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->post('/social/drive/assets', [...$payload, 'name' => 'Different payload'], ['Accept' => 'application/json'])->assertConflict();
        $post = $this->post('/social/marketing/posts', ['campaign' => 'Reused design', 'service' => 'General', 'brief' => 'Reuse the approved visual with fresh copy.', 'caption' => 'New caption', 'media' => $file], ['Accept' => 'application/json'])->assertOk()->json('post');
        $this->assertSame('draft', $post['status']);
        $this->assertNull($post['approved_by']);
        $this->assertNotSame(SocialAsset::first()->file_path, MarketingPost::find($post['id'])->media_path);
        $this->getJson('/social/marketing/posts/'.$post['id'])->assertOk()->assertJsonPath('post.brief', 'Reuse the approved visual with fresh copy.');
    }

    public function test_only_current_approved_versions_can_be_saved_and_snapshots_are_immutable_and_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($user);
        $post = MarketingPost::create(['location_id' => GhlConnection::LOCATION, 'account_id' => GhlSocialReader::ACCOUNT, 'campaign' => 'Approved content', 'brief' => 'Original brief', 'caption' => 'Approved caption', 'service' => 'General', 'version' => 2, 'created_by' => $user->id]);
        $this->postJson('/social/drive/posts/'.$post->id, ['version' => 2])->assertConflict();
        $post->update(['status' => 'approved', 'approved_version' => 2, 'approved_by' => $user->id, 'approval_fingerprint' => $post->fingerprint()]);
        $id = $this->postJson('/social/drive/posts/'.$post->id, ['version' => 2])->assertOk()->json('asset.id');
        $this->postJson('/social/drive/posts/'.$post->id, ['version' => 2])->assertOk()->assertJsonPath('asset.id', $id);
        $this->assertDatabaseCount('social_assets', 1);
        $post->update(['status' => 'draft', 'version' => 3, 'caption' => 'Changed caption']);
        $this->assertSame('Approved caption', SocialAsset::find($id)->caption);
        $this->postJson('/social/drive/posts/'.$post->id, ['version' => 2])->assertConflict();
        $this->postJson('/social/drive/posts/'.$post->id, ['version' => 3])->assertConflict();
    }

    public function test_digital_grants_allow_drafts_and_planner_but_never_approval_or_settings(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'education', 'module_permissions' => ['social_marketing']]));
        $this->get('/admin/content-calendar')->assertOk();
        $post = $this->postJson('/social/marketing/posts', ['campaign' => 'Digital draft', 'brief' => 'Persist this brief', 'service' => 'General', 'caption' => 'Draft copy'])->assertOk()->json('post');
        $this->postJson('/social/marketing/posts/'.$post['id'].'/submit', ['version' => $post['version']])->assertOk();
        $this->postJson('/social/marketing/posts/'.$post['id'].'/approve', ['version' => 2])->assertForbidden();
        $this->postJson('/social/marketing/posts/'.$post['id'].'/schedule', ['version' => 2])->assertForbidden();
        $this->getJson('/social/marketing/identity')->assertForbidden();
        $this->get('/admin/marketing-connectors')->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_drive_rejects_disguised_files_and_returns_search_pages_without_private_paths(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/social/drive/assets', ['name' => 'Unsafe', 'upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('photo.png', '<script>bad</script>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('social_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        SocialAsset::create(['name' => 'Find this', 'caption' => 'Copy', 'file_path' => 'hidden-path', 'sha256' => 'secret-hash', 'uploaded_by' => 1]);
        $this->getJson('/social/drive/assets?search=Find')->assertOk()->assertJsonPath('assets.total', 1)->assertDontSee('hidden-path')->assertDontSee('secret-hash');
    }
}
