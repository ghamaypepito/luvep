<?php

namespace Tests\Feature\MediaDrive;

use App\Models\Lead;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_every_staff_portal_has_its_own_media_drive_page_and_recruiting_sharing_is_disabled(): void
    {
        foreach (['admin' => '/admin/media-drive', 'super_admin' => '/admin/media-drive', 'sales' => '/portal/sales/media-drive', 'education' => '/portal/education/media-drive', 'english' => '/portal/english/media-drive', 'immigration' => '/portal/immigration/media-drive', 'immigration_manager' => '/portal/immigration/media-drive', 'immigration_adviser' => '/portal/immigration-adviser/media-drive', 'accommodation' => '/portal/accommodation/media-drive', 'finance' => '/portal/finance/media-drive', 'agent' => '/portal/agent/media-drive', 'sub_agent' => '/portal/sub-agent/media-drive'] as $role => $url) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/media-drive')->assertRedirect($url);
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('can_share', ! in_array($role, ['agent', 'sub_agent'])));
        }
        $this->actingAs(User::factory()->create(['role' => 'education']))->get('/portal/finance/media-drive')->assertForbidden();
        $this->get('/admin/media-drive')->assertForbidden();
    }

    public function test_client_portal_only_lists_invited_links_for_its_own_account_and_client(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $a = Lead::create(['first_name' => 'Own', 'last_name' => 'Client', 'email' => 'own@example.test']);
        $b = Lead::create(['first_name' => 'Other', 'last_name' => 'Client', 'email' => 'other@example.test']);
        foreach ([[$a, 'own@example.test'], [$a, 'someone@example.test'], [$b, 'own@example.test']] as [$lead,$email]) {
            $folder = MediaFolder::firstOrCreate(['client_key' => 'lead:'.$lead->id], ['lead_id' => $lead->id, 'name' => $lead->first_name]);
            $this->postJson('/media-drive/api/folders/'.$folder->id.'/shares', ['email' => $email, 'permission' => 'viewer', 'expires_in_days' => 30])->assertCreated();
        }
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $a->id, 'email' => 'own@example.test']));
        $this->get('/portal/lead/media-drive')->assertOk()->assertInertia(fn (Assert $p) => $p->has('links', 1)->where('links.0.name', 'Own'));
        $this->getJson('/media-drive/api/folders')->assertForbidden();
    }

    public function test_abandoned_upload_cleanup_retains_live_assets_references_and_recent_staging(): void
    {
        $folder = MediaFolder::create(['lead_id' => Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client'])->id, 'client_key' => 'lead:1', 'name' => 'Files']);
        foreach (['old.pdf', 'new.pdf', 'saved.pdf', 'legacy-reference.pdf'] as $name) {
            Storage::disk('local')->put('media-drive/assets/'.$name, 'synthetic bytes');
        }
        foreach (['old.pdf', 'saved.pdf', 'legacy-reference.pdf'] as $name) {
            touch(Storage::disk('local')->path('media-drive/assets/'.$name), now()->subDays(2)->timestamp);
        }
        MediaFile::create(['folder_id' => $folder->id, 'original_name' => 'saved.pdf', 'file_path' => 'media-drive/assets/saved.pdf', 'mime' => 'application/pdf', 'size' => 15, 'sha256' => hash('sha256', 'synthetic bytes'), 'upload_key' => (string) Str::uuid(), 'actor_key' => 'user:1']);
        \App\Models\LeadDocument::create(['lead_id' => $folder->lead_id, 'original_name' => 'legacy-reference.pdf', 'file_path' => 'media-drive/assets/legacy-reference.pdf', 'mime' => 'application/pdf', 'size' => 15, 'status' => 'Submitted']);
        Storage::disk('local')->put('unrelated/keep.pdf', 'do not touch');
        $this->artisan('media-drive:prune-abandoned --dry-run')->assertSuccessful();
        Storage::disk('local')->assertExists('media-drive/assets/old.pdf');
        $this->artisan('media-drive:prune-abandoned')->assertSuccessful();
        Storage::disk('local')->assertMissing('media-drive/assets/old.pdf');
        foreach (['new.pdf', 'saved.pdf', 'legacy-reference.pdf'] as $name) {
            Storage::disk('local')->assertExists('media-drive/assets/'.$name);
        }
        Storage::disk('local')->assertExists('unrelated/keep.pdf');
    }
}
