<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $property = Property::create(['name' => 'House', 'rent_single' => 200, 'status' => 'available']);

        return Tenant::create(['property_id' => $property->id, 'first_name' => 'Ann', 'family_name' => 'Lee', 'contract_type' => 'fixed_term']);
    }

    public function test_staff_can_upload_list_and_download_only_under_the_owning_tenant(): void
    {
        Storage::fake('tenant_private');
        $tenant = $this->tenant();
        $other = $this->tenant();
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $this->post("/portal/accommodation/tenants/{$tenant->id}/documents", [
            'category' => 'passport', 'file' => UploadedFile::fake()->create('passport.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $doc = $tenant->documents()->firstOrFail();
        Storage::disk('tenant_private')->assertExists($doc->path);
        $this->get("/portal/accommodation/tenants/{$tenant->id}")->assertInertia(fn (Assert $page) => $page
            ->has('documents', 1)->where('documents.0.name', 'passport.pdf')->missing('documents.0.path'));
        $this->get("/portal/accommodation/tenants/{$tenant->id}/documents/{$doc->id}")->assertDownload('passport.pdf');
        $this->get("/portal/accommodation/tenants/{$other->id}/documents/{$doc->id}")->assertNotFound();
    }

    public function test_external_users_with_portal_grants_cannot_list_upload_or_download_tenant_documents(): void
    {
        Storage::fake('tenant_private');
        $tenant = $this->tenant();
        $document = $tenant->documents()->create(['category' => 'passport', 'name' => 'private.pdf', 'path' => 'private.pdf', 'size' => 10]);
        foreach (['lead', 'revoked_lead', 'agent', 'sub_agent', 'student', 'sales'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['accommodation']]));
            $this->get("/portal/accommodation/tenants/{$tenant->id}")->assertForbidden();
            $this->post("/portal/accommodation/tenants/{$tenant->id}/documents", [
                'category' => 'passport', 'file' => UploadedFile::fake()->create('passport.pdf', 10, 'application/pdf'),
            ])->assertForbidden();
            $this->get("/portal/accommodation/tenants/{$tenant->id}/documents/{$document->id}")->assertForbidden();
        }
        $this->assertSame([], Storage::disk('tenant_private')->allFiles());
    }

    public function test_admins_can_upload_and_download_while_guests_cannot(): void
    {
        Storage::fake('tenant_private');
        $tenant = $this->tenant();
        $this->get("/portal/accommodation/tenants/{$tenant->id}/documents/1")->assertRedirect('/login');
        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->post("/portal/accommodation/tenants/{$tenant->id}/documents", [
                'category' => 'tenancy_agreement', 'file' => UploadedFile::fake()->create('agreement.pdf', 10, 'application/pdf'),
            ])->assertSessionHasNoErrors()->assertRedirect();
            $document = $tenant->documents()->latest('id')->firstOrFail();
            $this->get("/portal/accommodation/tenants/{$tenant->id}/documents/{$document->id}")->assertDownload('agreement.pdf');
        }
    }

    public function test_oversized_files_are_rejected_without_writing_storage(): void
    {
        Storage::fake('tenant_private');
        $tenant = $this->tenant();
        $this->actingAs(User::factory()->create(['role' => 'accommodation']))
            ->post("/portal/accommodation/tenants/{$tenant->id}/documents", [
                'category' => 'passport', 'file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
            ])->assertInvalid(['file']);
        $this->assertSame([], Storage::disk('tenant_private')->allFiles());
    }

    public function test_upload_rejects_unsupported_files_and_invalid_categories(): void
    {
        Storage::fake('tenant_private');
        $tenant = $this->tenant();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post("/portal/accommodation/tenants/{$tenant->id}/documents", [
            'category' => 'arbitrary', 'file' => UploadedFile::fake()->create('script.html', 10, 'text/html'),
        ])->assertInvalid(['file', 'category']);
        $this->assertSame([], Storage::disk('tenant_private')->allFiles());
    }
}
