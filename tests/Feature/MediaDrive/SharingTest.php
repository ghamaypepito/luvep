<?php

namespace Tests\Feature\MediaDrive;

use App\Mail\MediaDriveCode;
use App\Models\Lead;
use App\Models\MediaChallenge;
use App\Models\MediaFile;
use App\Models\MediaShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withCredentials();
        Storage::fake('local');
        Mail::fake();
        Cache::flush();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($cookie = $response->getCookie(config('session.cookie'))) {
            $this->withCookie(config('session.cookie'), $cookie->getValue());
        }

        return $response;
    }

    private function share(string $permission = 'editor'): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lead = Lead::create(['first_name' => 'Private', 'last_name' => 'Client', 'email' => 'invited@example.test']);
        $root = $this->postJson('/media-drive/api/folders', ['client_type' => 'lead', 'client_id' => $lead->id, 'name' => 'Private client name'])->assertCreated()->json('folder.id');
        $child = $this->postJson('/media-drive/api/folders', ['parent_id' => $root, 'name' => 'Shared documents'])->assertCreated()->json('folder.id');
        $outside = $this->postJson('/media-drive/api/folders', ['parent_id' => $root, 'name' => 'Internal only'])->assertCreated()->json('folder.id');
        $file = $this->postJson("/media-drive/api/folders/{$child}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('shared.pdf', 5, 'application/pdf')])->assertCreated()->json('file.id');
        $secret = $this->postJson("/media-drive/api/folders/{$outside}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('internal.pdf', 5, 'application/pdf')])->assertCreated()->json('file.id');
        $share = $this->postJson("/media-drive/api/folders/{$child}/shares", ['email' => 'INVITED@example.test', 'permission' => $permission, 'expires_in_days' => 30])->assertCreated()->json('share');

        return [$share, $root, $child, $outside, $file, $secret, $lead];
    }

    private function base(array $share): string
    {
        return parse_url($share['url'], PHP_URL_PATH);
    }

    private function code(array $share): string
    {
        $this->postJson($this->base($share).'/code', ['email' => 'invited@example.test'])->assertOk();

        return Mail::sent(MediaDriveCode::class)->last()->code;
    }

    private function verify(array $share): void
    {
        $code = $this->code($share);
        $this->postJson($this->base($share).'/verify', ['code' => $code])->assertOk();
    }

    public function test_unverified_links_do_not_expose_client_files_or_internal_details(): void
    {
        [$share,$root,$child,$outside,$file] = $this->share();
        $base = $this->base($share);
        Mail::assertNothingSent();
        $this->get($base)->assertOk()->assertInertia(fn (Assert $p) => $p->component('media/Shared', false)->where('verified', false)->missing('folder')->missing('files')->missing('email'));
        $this->getJson($base.'/contents')->assertForbidden();
        $this->get($base.'/files/'.$file)->assertForbidden();
        $this->postJson($base.'/code', ['email' => 'wrong@example.test'])->assertOk();
        Mail::assertNothingSent();
        $db = MediaShare::findOrFail($share['id']);
        $raw = $db->getRawOriginal();
        $this->assertSame('invited@example.test', $db->email);
        $this->assertNotSame($db->token, $raw['token']);
        $this->assertSame(hash('sha256', $db->token), $raw['token_hash']);
    }

    public function test_viewer_can_only_read_verified_subtree_and_never_upload_or_manage_sharing(): void
    {
        [$share,$root,$child,$outside,$file,$secret] = $this->share('viewer');
        $base = $this->base($share);
        $this->verify($share);
        $this->getJson($base.'/contents')->assertOk()->assertJsonPath('permission', 'viewer')->assertJsonPath('folder.id', $child)->assertJsonCount(1, 'files.data')->assertJsonMissing(['name' => 'Private client name'])->assertJsonMissing(['name' => 'Internal only']);
        $this->get($base.'/files/'.$file)->assertDownload('shared.pdf');
        $this->get($base.'/files/'.$secret)->assertNotFound();
        $this->getJson($base.'/contents?folder_id='.$root)->assertNotFound();
        $this->postJson($base.'/folders/'.$child.'/files', ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('blocked.pdf', 5, 'application/pdf')])->assertForbidden();
        $this->assertDatabaseCount('media_files', 2);
    }

    public function test_editor_uploads_are_awaiting_review_and_only_in_the_shared_subtree(): void
    {
        [$share,$root,$child,$outside] = $this->share();
        $base = $this->base($share);
        $this->verify($share);
        $data = ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('client.pdf', 5, 'application/pdf')];
        $this->postJson($base.'/folders/'.$outside.'/files', $data)->assertNotFound();
        $file = $this->postJson($base.'/folders/'.$child.'/files', $data)->assertCreated()->assertJsonPath('file.status', 'Submitted')->json('file');
        $this->assertSame($share['id'], MediaFile::findOrFail($file['id'])->uploaded_share_id);
        $this->postJson($base.'/folders/'.$child.'/files', $data)->assertOk()->assertJsonPath('file.id', $file['id']);
        $this->assertDatabaseCount('media_files', 3);
    }

    public function test_codes_are_hashed_one_use_and_bound_to_the_requesting_session(): void
    {
        [$share] = $this->share();
        $base = $this->base($share);
        $code = $this->code($share);
        $challenge = MediaChallenge::sole();
        $this->assertNotSame($code, $challenge->code_hash);
        $this->app['session']->invalidate();
        $this->defaultCookies = [];
        $this->withSession(['media_challenges' => [$share['id'] => $challenge->id]]);
        $this->postJson($base.'/verify', ['code' => $code])->assertUnprocessable();
        $this->assertNull($challenge->fresh()->used_at);
    }

    public function test_five_wrong_attempts_cannot_be_reset_by_replaying_a_correct_code(): void
    {
        [$share] = $this->share();
        $base = $this->base($share);
        $code = $this->code($share);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($base.'/verify', ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
        }
        $this->assertSame(5, MediaChallenge::sole()->attempts);
        $this->postJson($base.'/verify', ['code' => $code])->assertUnprocessable();
        $this->getJson($base.'/contents')->assertForbidden();
    }

    public function test_resend_has_cooldown_hourly_limit_and_invalidates_the_previous_code(): void
    {
        [$share] = $this->share();
        $base = $this->base($share);
        $old = $this->code($share);
        $this->postJson($base.'/code', ['email' => 'invited@example.test'])->assertStatus(429);
        $this->travel(61)->seconds();
        $new = $this->code($share);
        $this->assertSame(2, MediaChallenge::count());
        $this->postJson($base.'/verify', ['code' => $old])->assertUnprocessable();
        $this->postJson($base.'/verify', ['code' => $new])->assertOk();
        $this->postJson($base.'/verify', ['code' => $new])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->code($share);
        $this->travel(61)->seconds();
        $this->postJson($base.'/code', ['email' => 'invited@example.test'])->assertStatus(429);
    }

    public function test_expired_code_and_expired_verified_session_require_a_new_code(): void
    {
        [$share] = $this->share();
        $base = $this->base($share);
        $code = $this->code($share);
        $this->travel(11)->minutes();
        $this->postJson($base.'/verify', ['code' => $code])->assertUnprocessable();
        $this->verify($share);
        $this->travel(61)->minutes();
        $this->getJson($base.'/contents')->assertForbidden();
    }

    public function test_revoke_and_access_changes_invalidate_every_verified_action_immediately(): void
    {
        [$share,$root,$child,$outside,$file] = $this->share();
        $base = $this->base($share);
        $this->verify($share);
        $this->patchJson('/media-drive/api/shares/'.$share['id'], ['email' => 'invited@example.test', 'permission' => 'viewer', 'expires_in_days' => 30])->assertOk();
        $this->getJson($base.'/contents')->assertForbidden();
        $this->get($base.'/files/'.$file)->assertForbidden();
        $this->travel(61)->seconds();
        $this->verify($share);
        $this->getJson($base.'/contents')->assertJsonPath('permission', 'viewer');
        $this->deleteJson('/media-drive/api/shares/'.$share['id'])->assertOk();
        $this->get($base)->assertStatus(410);
        $this->get($base.'/files/'.$file)->assertStatus(410);
    }

    public function test_expired_or_deleted_owner_links_stop_working_and_agents_cannot_create_shares(): void
    {
        [$share,$root,$child,$outside,$file,$secret,$lead] = $this->share();
        $base = $this->base($share);
        $this->verify($share);
        $lead->delete();
        $this->get($base)->assertStatus(410);
        $lead->restore();
        MediaShare::findOrFail($share['id'])->update(['expires_at' => now()->subSecond()]);
        $this->get($base.'/files/'.$file)->assertStatus(410);
        $agent = User::factory()->create(['role' => 'agent']);
        $lead->update(['agent_id' => $agent->id]);
        $this->actingAs($agent)->postJson('/media-drive/api/folders/'.$child.'/shares', ['email' => 'x@example.test', 'permission' => 'viewer', 'expires_in_days' => 30])->assertForbidden();
    }

    public function test_role_changed_during_staging_cannot_commit_an_editor_upload(): void
    {
        [$share,$root,$child] = $this->share();
        $base = $this->base($share);
        $this->verify($share);
        $model = MediaShare::findOrFail($share['id']);
        $model->update(['permission' => 'viewer', 'version' => $model->version + 1]);
        $this->postJson($base.'/folders/'.$child.'/files', ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('late.pdf', 5, 'application/pdf')])->assertForbidden();
        $this->assertDatabaseCount('media_files', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_email_delivery_failure_cannot_leave_a_usable_code_or_false_success(): void
    {
        [$share] = $this->share();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic delivery failure'));
        $this->postJson($this->base($share).'/code', ['email' => 'invited@example.test'])->assertStatus(503);
        $this->assertDatabaseCount('media_challenges', 0);
        $this->getJson($this->base($share).'/contents')->assertForbidden();
    }
}
