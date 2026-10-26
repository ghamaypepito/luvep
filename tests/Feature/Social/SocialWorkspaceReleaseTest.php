<?php

namespace Tests\Feature\Social;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SocialWorkspaceReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('social-workspace-test/v1/index.html', '<!doctype html><html><head></head><body>Test workspace <a href="#">Home</a><script src="app.js"></script></body></html>');
        Storage::disk('local')->put('social-workspace-test/v1/app.js', 'console.log("test workspace");');
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    }

    public function test_workspace_and_assets_require_authentication(): void
    {
        $this->get('/social/compose')->assertRedirect('/login');
        $this->get('/social/workspace-test/v1/app.js')->assertRedirect('/login');
    }

    public function test_non_admin_staff_cannot_access_workspace_or_exports(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->get('/social/compose')->assertForbidden();
        $this->get('/social/workspace-test/v1/app.js')->assertForbidden();
    }

    public function test_authorized_workspace_is_no_store_and_resolves_protected_assets(): void
    {
        $this->admin();
        $this->get('/social/compose')->assertOk()->assertSee('<base href="/social/workspace-test/v1/">', false)->assertSee('Test workspace')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/social/workspace-test/v1/app.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8');
    }

    public function test_inertia_navigation_gets_a_full_page_redirect(): void
    {
        $this->admin();
        $this->get('/social/compose', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', url('/social/compose'));
    }

    public function test_asset_traversal_unknown_extension_and_outside_symlink_are_denied(): void
    {
        $this->admin();
        Storage::disk('local')->put('private-token.txt', 'do-not-expose');
        Storage::disk('local')->put('social-workspace-test/v1/private.php', 'do-not-expose');
        $this->get('/social/workspace-test/v1/private.php')->assertNotFound();
        $this->get('/social/workspace-test/v1/%2e%2e/private-token.txt')->assertNotFound();
        symlink(Storage::disk('local')->path('private-token.txt'), Storage::disk('local')->path('social-workspace-test/v1/outside.txt'));
        $this->get('/social/workspace-test/v1/outside.txt')->assertNotFound()->assertDontSee('do-not-expose');
    }

    public function test_previous_composer_is_retained_for_existing_tools(): void
    {
        $this->admin();
        $this->get('/social/compose/legacy')->assertOk()->assertInertia(fn ($page) => $page->component('admin/social/Compose'));
    }
}
