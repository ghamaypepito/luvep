<?php

namespace Tests\Feature\MediaDrive;

use App\Models\Lead;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function client(array $fields = []): Lead
    {
        return Lead::create(['first_name' => 'Drive', 'last_name' => 'Client', 'email' => Str::uuid().'@example.test', ...$fields]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function root(Lead $lead): int
    {
        return $this->postJson('/media-drive/api/folders', ['client_type' => 'lead', 'client_id' => $lead->id, 'name' => 'Client documents'])->assertCreated()->json('folder.id');
    }

    public function test_department_scopes_and_explicit_portal_grants_match_the_client_registers(): void
    {
        $pipeline = $this->client();
        $student = $this->client(['is_student' => true]);
        $case = $this->client(['is_immigration_case' => true]);
        $english = $this->client(['is_english_student' => true]);
        $housing = $this->client(['is_accommodation_client' => true]);
        foreach ([['education', [], [$pipeline->id, $student->id]], ['immigration_manager', [], [$pipeline->id, $student->id, $case->id]], ['english', [], [$english->id]], ['sales', ['education'], [$pipeline->id, $student->id]], ['sales', [], [$pipeline->id]]] as [$role,$grants,$ids]) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => $grants]))->getJson('/media-drive/api/clients')
                ->assertOk()->assertJsonCount(count($ids), 'clients')->assertJsonPath('clients', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($ids)->sort()->values()->all());
        }
        $education = User::factory()->create(['role' => 'education']);
        $this->actingAs($education)->postJson('/media-drive/api/folders', ['client_type' => 'lead', 'client_id' => $case->id, 'name' => 'Forbidden'])->assertForbidden();
        $this->assertDatabaseCount('media_folders', 0);
    }

    public function test_folder_roots_are_client_specific_and_children_cannot_escape_their_root(): void
    {
        $this->actingAs($this->admin());
        $a = $this->client();
        $b = $this->client();
        $root = $this->root($a);
        $other = $this->root($b);
        $child = $this->postJson('/media-drive/api/folders', ['parent_id' => $root, 'name' => 'Identity'])->assertCreated()->json('folder.id');
        $this->postJson('/media-drive/api/folders', ['parent_id' => $child, 'name' => 'Photos'])->assertCreated();
        $this->postJson('/media-drive/api/folders', ['parent_id' => $root, 'name' => 'Identity'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/media-drive/api/folders', ['client_type' => 'lead', 'client_id' => $a->id, 'name' => 'Duplicate'])->assertUnprocessable();
        $this->getJson('/media-drive/api/folders?folder_id='.$root)->assertOk()->assertJsonCount(1, 'folders')->assertJsonPath('folder.id', $root)->assertJsonPath('folder.client.id', $a->id);
        $this->assertSame($root, MediaFolder::findOrFail($child)->root_id);
        $this->assertSame(4, MediaFolder::count());
    }

    public function test_uploads_are_private_content_validated_and_retry_keys_bind_to_the_payload(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $root = $this->root($this->client());
        $key = (string) Str::uuid();
        $upload = fn ($name = 'sample.pdf') => $this->postJson("/media-drive/api/folders/{$root}/files", ['upload_key' => $key, 'file' => UploadedFile::fake()->create($name, 10, 'application/pdf')]);
        $file = $upload()->assertCreated()->json('file');
        $this->assertArrayNotHasKey('file_path', $file);
        $this->assertStringNotContainsString('/storage/', $file['download_url']);
        $model = MediaFile::findOrFail($file['id']);
        Storage::disk('local')->assertExists($model->file_path);
        $upload()->assertOk()->assertJsonPath('file.id', $file['id']);
        $this->assertDatabaseCount('media_files', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $upload('changed.pdf')->assertStatus(409);
        $this->get($file['download_url'])->assertDownload('sample.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson("/media-drive/api/folders/{$root}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('bad.html', 1, 'text/html')])->assertUnprocessable();
        $this->postJson("/media-drive/api/folders/{$root}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('large.pdf', 102401, 'application/pdf')])->assertUnprocessable();
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_recruiting_accounts_are_own_referral_only_and_cannot_share_externally(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $mine = $this->client(['agent_id' => $agent->id]);
        $foreign = $this->client();
        $this->actingAs($this->admin());
        $foreignFolder = $this->root($foreign);
        $this->actingAs($agent);
        $folder = $this->root($mine);
        $this->getJson('/media-drive/api/folders?folder_id='.$foreignFolder)->assertForbidden();
        $this->getJson('/media-drive/api/clients')->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $mine->id);
    }

    public function test_finance_only_sees_clients_in_its_existing_finance_workflow(): void
    {
        $client = $this->client();
        $other = $this->client();
        \App\Models\CaseFinancial::create(['lead_id' => $client->id]);
        $this->actingAs(User::factory()->create(['role' => 'finance']))->getJson('/media-drive/api/clients')->assertOk()->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $client->id);
        $this->postJson('/media-drive/api/folders', ['client_type' => 'lead', 'client_id' => $other->id, 'name' => 'No access'])->assertForbidden();
    }

    public function test_standalone_tenants_are_available_only_to_admin_and_accommodation(): void
    {
        $property = Property::create(['name' => 'Synthetic house', 'rent_single' => 100, 'status' => 'available']);
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Tenant', 'family_name' => 'Sample', 'contract_type' => 'fixed_term']);
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $folder = $this->postJson('/media-drive/api/folders', ['client_type' => 'tenant', 'client_id' => $tenant->id, 'name' => 'Tenant documents'])->assertCreated()->json('folder.id');
        $this->actingAs(User::factory()->create(['role' => 'sales', 'portal_access' => ['accommodation']]))->getJson('/media-drive/api/folders?folder_id='.$folder)->assertForbidden();
        $this->actingAs($this->admin())->getJson('/media-drive/api/folders?folder_id='.$folder)->assertOk()->assertJsonPath('folder.client.type', 'tenant');
    }

    public function test_external_inactive_and_deleted_clients_cannot_use_the_staff_library(): void
    {
        $this->getJson('/media-drive/api/folders')->assertUnauthorized();
        foreach (['lead', 'revoked_lead', 'student'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['education']]))->getJson('/media-drive/api/folders')->assertForbidden();
        }
        $this->actingAs($this->admin());
        $lead = $this->client();
        $folder = $this->root($lead);
        $lead->delete();
        $this->getJson('/media-drive/api/folders?folder_id='.$folder)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]))->getJson('/media-drive/api/folders')->assertForbidden();
    }

    public function test_renamed_executables_and_failed_audit_do_not_leave_files_or_records(): void
    {
        $this->actingAs($this->admin());
        $folder = $this->root($this->client());
        $temp = tempnam(sys_get_temp_dir(), 'media-type-');
        file_put_contents($temp, '<?php echo "not a PDF";');
        try {
            $this->postJson("/media-drive/api/folders/{$folder}/files", ['upload_key' => (string) Str::uuid(), 'file' => new UploadedFile($temp, 'fake.pdf', null, null, true)])->assertUnprocessable();
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        $dispatcher = \App\Models\MediaEvent::getEventDispatcher();
        \App\Models\MediaEvent::setEventDispatcher(clone $dispatcher);
        \App\Models\MediaEvent::creating(function ($event) {
            if ($event->kind === 'file_uploaded') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $this->postJson("/media-drive/api/folders/{$folder}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('sample.pdf', 10, 'application/pdf')])->assertStatus(500);
        } finally {
            \App\Models\MediaEvent::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('media_files', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_subfolders_report_inherited_sharing_and_review_filter_follows_context_reviews(): void
    {
        $this->actingAs($this->admin());
        $lead = $this->client();
        $root = $this->root($lead);
        $child = $this->postJson('/media-drive/api/folders', ['parent_id' => $root, 'name' => 'Child'])->assertCreated()->json('folder.id');
        $this->postJson("/media-drive/api/folders/{$root}/shares", ['email' => 'sample@example.test', 'permission' => 'viewer', 'expires_in_days' => 30])->assertCreated();
        $this->getJson('/media-drive/api/folders?folder_id='.$child)->assertOk()->assertJsonPath('folder.shared', true)->assertJsonPath('folder.sharing', 'inherited');
        $id = $this->postJson("/media-drive/api/folders/{$root}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('sample.pdf', 5, 'application/pdf')])->assertCreated()->json('file.id');
        MediaFile::findOrFail($id)->update(['initial_status' => 'Submitted']);
        $this->postJson('/media-drive/api/attach', ['client_type' => 'lead', 'client_id' => $lead->id, 'file_ids' => [$id]])->assertOk();
        \App\Models\LeadDocument::sole()->update(['status' => 'Approved']);
        $this->getJson('/media-drive/api/folders?folder_id='.$root.'&review=1')->assertOk()->assertJsonCount(0, 'files.data');
    }
}
