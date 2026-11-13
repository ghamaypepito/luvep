<?php

namespace Tests\Feature\Documents;

use App\Jobs\PushApprovedDocumentToDrive;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\GoogleDriveService;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Permission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleDriveRecordIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $remote = [];

    private array $queries = [];

    private array $grants = [];

    private int $fileWrites = 0;

    private int $permissionWrites = 0;

    private bool $loseUploadResponse = false;

    private bool $losePermissionResponse = false;

    private bool $denyRemote = false;

    private string $keyPath;

    private GoogleDriveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->keyPath = tempnam(sys_get_temp_dir(), 'drive-test-');
        config(['services.google_drive.key_file' => $this->keyPath, 'services.google_drive.shared_drive_id' => 'test-drive',
            'services.google_drive.share_with' => null, 'services.google_drive.folder_prefix' => 'TEST ']);
        $api = Mockery::mock(Drive::class);
        $files = Mockery::mock(Files::class);
        $api->files = $files;
        $permissions = Mockery::mock(Permissions::class);
        $api->permissions = $permissions;
        $files->shouldReceive('listFiles')->andReturnUsing(function (array $options) {
            $this->assertTrue($options['supportsAllDrives']);
            $this->assertSame('test-drive', $options['driveId']);
            $this->queries[] = $options['q'];
            preg_match("/key='([^']+)' and value='([^']+)'/", $options['q'], $property);
            preg_match("/and '([^']+)' in parents/", $options['q'], $parent);
            $this->assertNotEmpty($property, 'All lookups use record metadata, never a name-only search');
            $found = array_filter($this->remote, fn ($file) => ! $file->getTrashed()
                && ($file->getAppProperties()[$property[1]] ?? null) === $property[2]
                && in_array($parent[1], $file->getParents() ?? [], true));

            return new FileList(['files' => array_values($found)]);
        });
        $files->shouldReceive('get')->andReturnUsing(function ($id, array $options) {
            $this->assertTrue($options['supportsAllDrives']);
            if ($this->denyRemote || ! isset($this->remote[$id])) {
                throw new RuntimeException('Remote access unavailable');
            }

            return $this->remote[$id];
        });
        $files->shouldReceive('create')->andReturnUsing(function (DriveFile $file, array $options) {
            $this->assertTrue($options['supportsAllDrives']);
            $id = 'remote-'.(count($this->remote) + 1);
            $file->setId($id);
            $file->setDriveId('test-drive');
            $this->remote[$id] = $file;
            if (isset($options['data'])) {
                $this->fileWrites++;
                if ($this->loseUploadResponse) {
                    $this->loseUploadResponse = false;
                    throw new RuntimeException('Upload response lost after remote create');
                }
            }

            return $file;
        });
        $files->shouldReceive('update')->andReturnUsing(function ($id, DriveFile $file, array $options) {
            $this->assertTrue($options['supportsAllDrives']);
            $this->assertArrayHasKey($id, $this->remote);
            $this->fileWrites++;

            return $this->remote[$id];
        });
        $permissions->shouldReceive('listPermissions')->andReturnUsing(fn ($id, $options) => new PermissionList(['permissions' => $this->grants[$id] ?? []]));
        $permissions->shouldReceive('create')->andReturnUsing(function ($id, Permission $permission, array $options) {
            $this->assertSame('reader', $permission->getRole());
            $this->assertSame('user', $permission->getType());
            $this->assertFalse($options['sendNotificationEmail']);
            $this->permissionWrites++;
            $this->grants[$id][] = $permission;
            if ($this->losePermissionResponse) {
                $this->losePermissionResponse = false;
                throw new RuntimeException('Share response lost');
            }

            return $permission;
        });
        // Only the Drive boundary is replaced. Persistence, storage and jobs are real.
        $this->service = new class($api) extends GoogleDriveService
        {
            public function __construct(private Drive $api) {}

            protected function drive(): Drive
            {
                return $this->api;
            }
        };
        $this->app->instance(GoogleDriveService::class, $this->service);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
        parent::tearDown();
    }

    private function client(): Lead
    {
        return Lead::create(['first_name' => 'Same', 'last_name' => 'Name', 'is_student' => true]);
    }

    private function document(Lead $lead, string $disk = 'local'): LeadDocument
    {
        $path = 'private/'.$lead->id.'/synthetic.pdf';
        Storage::disk($disk)->put($path, 'synthetic private bytes');

        return LeadDocument::create(['lead_id' => $lead->id, 'file_path' => $path, 'original_name' => 'synthetic.pdf',
            'mime' => 'application/pdf', 'status' => 'Approved', 'source' => 'upload']);
    }

    public function test_same_name_clients_receive_different_id_scoped_folders_and_retry_updates_the_same_file(): void
    {
        $a = $this->client();
        $b = $this->client();
        $first = $this->document($a);
        $second = $this->document($b, 'public');
        $this->assertTrue($this->service->syncApprovedDocument($first));
        $this->assertTrue($this->service->syncApprovedDocument($second));
        $this->assertNotSame($a->refresh()->gdrive_folder_id, $b->refresh()->gdrive_folder_id);
        $this->assertStringContainsString('[Lead '.$a->id.']', $this->remote[$a->gdrive_folder_id]->getName());
        $id = $first->refresh()->gdrive_file_id;
        $this->assertTrue($this->service->syncApprovedDocument($first));
        $this->assertSame($id, $first->refresh()->gdrive_file_id);
        $this->assertCount(4, $this->remote);
        Storage::disk('local')->assertExists($first->file_path);
        $this->assertNotNull($first->gdrive_synced_at);
    }

    public function test_legacy_name_only_folder_is_never_adopted_for_an_unmapped_record(): void
    {
        $lead = $this->client();
        $this->remote['legacy'] = new DriveFile(['id' => 'legacy', 'name' => 'TEST Same Name',
            'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['test-drive'], 'driveId' => 'test-drive']);
        $this->assertTrue($this->service->syncApprovedDocument($this->document($lead)));
        $this->assertNotSame('legacy', $lead->refresh()->gdrive_folder_id);
    }

    public function test_remote_create_then_lost_response_is_recovered_by_document_identity(): void
    {
        $doc = $this->document($this->client());
        $this->loseUploadResponse = true;
        try {
            $this->service->syncApprovedDocument($doc);
            $this->fail('Expected transient failure');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('response lost', $error->getMessage());
        }
        $this->assertNull($doc->refresh()->gdrive_file_id);
        $this->assertTrue($this->service->syncApprovedDocument($doc));
        $this->assertCount(2, $this->remote);
        $this->assertNotNull($doc->refresh()->gdrive_file_id);
    }

    public function test_share_retry_does_not_skip_or_duplicate_the_configured_reader(): void
    {
        config(['services.google_drive.share_with' => 'approved-reviewer@example.test']);
        $doc = $this->document($this->client());
        $this->losePermissionResponse = true;
        try {
            $this->service->syncApprovedDocument($doc);
            $this->fail('Expected sharing failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Share response lost', $error->getMessage());
        }
        $this->assertTrue($this->service->syncApprovedDocument($doc));
        $this->assertSame(1, $this->permissionWrites);
        $this->assertCount(2, $this->remote);
    }

    public function test_duplicate_mapped_folder_including_archived_clients_stops_upload(): void
    {
        $a = $this->client();
        $b = $this->client();
        $a->forceFill(['gdrive_folder_id' => 'legacy'])->save();
        $a->delete();
        $b->forceFill(['gdrive_folder_id' => 'legacy'])->save();
        $this->remote['legacy'] = new DriveFile(['id' => 'legacy', 'mimeType' => 'application/vnd.google-apps.folder', 'driveId' => 'test-drive']);
        $this->expectExceptionMessage('folder mapping requires authorised record review');
        $this->service->syncApprovedDocument($this->document($b));
    }

    public function test_wrong_parent_file_mapping_stops_before_overwriting_another_clients_file(): void
    {
        $doc = $this->document($this->client());
        $this->remote['wrong'] = new DriveFile(['id' => 'wrong', 'parents' => ['another-client-folder'], 'driveId' => 'test-drive']);
        $doc->forceFill(['gdrive_file_id' => 'wrong'])->save();
        try {
            $this->service->syncApprovedDocument($doc);
            $this->fail('Expected mapping rejection');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('file mapping requires', $error->getMessage());
        }
        $this->assertSame(0, $this->fileWrites);
    }

    public function test_revoked_access_fails_without_a_sync_stamp_or_removing_the_portal_file(): void
    {
        $doc = $this->document($this->client());
        $this->denyRemote = true;
        try {
            $this->service->syncApprovedDocument($doc);
            $this->fail('Expected access failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Remote access unavailable', $error->getMessage());
        }
        $this->assertNull($doc->refresh()->gdrive_synced_at);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_missing_file_is_reported_as_skipped_not_successfully_synced(): void
    {
        $doc = $this->document($this->client());
        Storage::disk('local')->delete($doc->file_path);
        $this->artisan('gdrive:sync-approved')->expectsOutputToContain('Synced: 0  Skipped: 1  Failed: 0')->assertExitCode(1);
        $this->assertSame([], $this->remote);
        $this->assertNull($doc->refresh()->gdrive_synced_at);
    }

    public function test_disabled_configuration_and_unapproved_documents_make_no_remote_calls(): void
    {
        $doc = $this->document($this->client());
        $doc->forceFill(['status' => 'Submitted'])->save();
        (new PushApprovedDocumentToDrive($doc->id))->handle($this->service);
        $this->assertFalse($this->service->syncApprovedDocument($doc));
        config(['services.google_drive.key_file' => null]);
        $doc->forceFill(['status' => 'Approved'])->save();
        (new PushApprovedDocumentToDrive($doc->id))->handle($this->service);
        $this->artisan('gdrive:sync-approved')->assertExitCode(1);
        $this->assertSame([], $this->remote);
        $this->assertSame([], $this->queries);
    }

    public function test_scoped_backfill_uploads_only_the_selected_approved_sample(): void
    {
        $selected = $this->document($this->client());
        $other = $this->document($this->client());
        $this->artisan('gdrive:sync-approved', ['--document' => $selected->id])
            ->expectsOutputToContain('Synced: 1  Skipped: 0  Failed: 0')->assertExitCode(0);
        $this->assertNotNull($selected->refresh()->gdrive_file_id);
        $this->assertNull($other->refresh()->gdrive_file_id);
        $this->artisan('gdrive:sync-approved', ['--document' => 'invalid'])->assertExitCode(1);
        $this->assertCount(2, $this->remote);
    }

    public function test_existing_mapping_and_manual_link_are_retained_without_regranting_a_revoked_reader(): void
    {
        $lead = $this->client();
        $lead->forceFill(['student_gdrive_link' => 'https://drive.google.com/drive/folders/staff-verified-link'])->save();
        $doc = $this->document($lead);
        config(['services.google_drive.share_with' => 'approved-reviewer@example.test']);
        $this->service->syncApprovedDocument($doc);
        $folder = $lead->refresh()->gdrive_folder_id;
        $this->grants[$folder] = []; // The Drive owner explicitly revoked the reader.
        $this->service->syncApprovedDocument($doc);
        $this->service->syncApprovedDocument($doc);
        $this->assertSame($folder, $lead->refresh()->gdrive_folder_id);
        $this->assertSame('https://drive.google.com/drive/folders/staff-verified-link', $lead->student_gdrive_link);
        $this->assertSame(1, $this->permissionWrites);
        $this->assertSame([], $this->grants[$folder]);
        $this->assertCount(2, $this->remote);
    }

    public function test_ambiguous_remote_identity_matches_are_rejected_instead_of_choosing_a_folder(): void
    {
        $lead = $this->client();
        foreach (['one', 'two'] as $id) {
            $this->remote[$id] = new DriveFile(['id' => $id, 'parents' => ['test-drive'],
                'mimeType' => 'application/vnd.google-apps.folder', 'driveId' => 'test-drive',
                'appProperties' => ['luvep_lead_id' => (string) $lead->id]]);
        }
        $this->expectExceptionMessage('Multiple Drive folders match this record');
        $this->service->syncApprovedDocument($this->document($lead));
    }
}
