<?php

namespace Tests\Feature\Immigration;

use App\Models\CaseDependent;
use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class CaseArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/portal/immigration/cases';

    private function case(array $attributes = []): Lead
    {
        return Lead::create($attributes + [
            'first_name' => 'Sample', 'last_name' => 'Applicant', 'email' => 'case@example.test',
            'is_immigration_case' => true, 'immigration_stage' => 'For Assessment',
        ]);
    }

    public function test_archive_uses_the_existing_trash_and_only_lists_archived_cases(): void
    {
        $case = $this->case(['lead_id' => 'IC-ARCHIVE-TEST']);
        $other = $this->case(['lead_id' => 'IC-ACTIVE-TEST']);
        $ordinary = $this->case(['is_immigration_case' => false]);
        $ordinary->delete();
        $document = $case->documents()->create(['original_name' => 'offer.pdf', 'file_path' => 'private/offer.pdf', 'status' => LeadDocument::STATUS_APPROVED]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(self::BASE."/{$case->id}/archive")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($case);
        $this->get(self::BASE)->assertInertia(fn (Assert $page) => $page
            ->where('canManageCaseArchive', true)->has('cases', 1)->where('cases.0.id', $other->id));
        $this->get(self::BASE.'/archived?search=IC-ARCHIVE')
            ->assertInertia(fn (Assert $page) => $page->component('portal/immigration/ArchivedCases')
                ->has('cases.data', 1)->where('cases.data.0.id', $case->id));
        $this->get(self::BASE."/{$other->id}/profile")->assertInertia(fn (Assert $page) => $page
            ->where('caseOptions', fn ($options) => ! collect($options)->contains('id', $case->id)));
        $this->postJson(self::BASE."/{$other->id}/dependents", ['relationship' => 'child', 'linked_lead_id' => $case->id])
            ->assertUnprocessable()->assertJsonValidationErrors('linked_lead_id');
        $this->post(self::BASE."/archived/{$case->id}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($case);
        $this->assertModelExists($document);
    }

    public function test_staff_cannot_access_archive_or_change_existing_names(): void
    {
        $case = $this->case(['first_name' => 'Two Names']);
        $archived = $this->case();
        $archived->delete();
        $dependant = CaseDependent::create(['lead_id' => $case->id, 'relationship' => 'child', 'first_name' => 'Existing', 'family_name' => 'Name', 'source' => 'staff']);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $this->get(self::BASE.'/archived')->assertForbidden();
        $this->get(self::BASE."/archived/{$archived->id}/download")->assertForbidden();
        $this->post(self::BASE."/{$case->id}/archive")->assertForbidden();
        $this->patch(self::BASE."/archived/{$archived->id}/name")->assertForbidden();
        $this->post(self::BASE."/archived/{$archived->id}/restore")->assertForbidden();
        $this->delete(self::BASE."/archived/{$archived->id}", ['confirmation' => 'DELETE ACCOUNT'])->assertForbidden();
        $this->postJson(self::BASE."/{$case->id}", ['first_name' => 'Modified', 'last_name' => $case->last_name])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->postJson(self::BASE."/{$case->id}/personal", ['first_name' => $case->first_name, 'last_name' => 'Modified', 'email' => $case->email])
            ->assertUnprocessable()->assertJsonValidationErrors('last_name');
        $this->postJson("/admin/leads/{$case->id}/personal", ['first_name' => 'Modified'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->putJson(self::BASE."/{$case->id}/dependents/{$dependant->id}", ['relationship' => 'child', 'family_name' => 'Modified'])
            ->assertUnprocessable()->assertJsonValidationErrors('family_name');
        $this->post(self::BASE."/{$case->id}/personal", ['first_name' => $case->first_name, 'last_name' => $case->last_name, 'email' => 'contact@example.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame('contact@example.test', $case->fresh()->email);

        $case->update(['is_student' => true]);
        $education = User::factory()->create(['role' => 'education']);
        $this->actingAs($education)->get('/portal/education/students')
            ->assertInertia(fn (Assert $page) => $page->where('students.0.first_name', 'Two Names')->where('students.0.case_name_locked', true));
        $contact = ['last_name' => $case->last_name, 'email' => $case->fresh()->email, 'phone' => '+64210000000'];
        $this->postJson("/portal/education/students/{$case->id}/update", ['first_name' => 'Modified'] + $contact)
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->post("/portal/education/students/{$case->id}/update", ['first_name' => 'Two Names', 'payment' => 'Paid'] + $contact)
            ->assertSessionHasNoErrors();
        $this->assertSame('Paid', $case->fresh()->student_payment);
    }

    public function test_archive_download_includes_case_records_and_files_only(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $archived = $this->case(['lead_id' => 'IC-BACKUP-TEST', 'tracking_code' => 'PRIVATE-CODE']);
        $active = $this->case();
        $document = $archived->documents()->create([
            'original_name' => '../offer.pdf', 'file_path' => 'private/offer.pdf', 'status' => LeadDocument::STATUS_APPROVED,
        ]);
        $missing = $archived->documents()->create(['original_name' => 'missing.pdf', 'file_path' => 'private/missing.pdf']);
        $other = $active->documents()->create(['original_name' => 'other.pdf', 'file_path' => 'private/other.pdf']);
        Storage::disk('local')->put($document->file_path, 'archived case offer');
        Storage::disk('local')->put($other->file_path, 'another case offer');
        $archived->delete();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(self::BASE."/archived/{$active->id}/download")->assertNotFound();
        $download = $this->get(self::BASE."/archived/{$archived->id}/download")
            ->assertOk()->assertHeader('Content-Type', 'application/zip')->assertDownload('ic-backup-test-archive.zip');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $download->headers->get('Cache-Control'));
        $path = $download->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertSame('archived case offer', $zip->getFromName("documents/{$document->id}/offer.pdf"));
            $this->assertFalse($zip->locateName("documents/{$other->id}/other.pdf"));
            $snapshot = json_decode($zip->getFromName('case-records.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('IC-BACKUP-TEST', $snapshot['case']['lead_id']);
            $this->assertArrayNotHasKey('tracking_code', $snapshot['case']);
            $this->assertSame(["documents/{$missing->id}/missing.pdf"], $snapshot['unavailable_files']);
        } finally {
            $zip->close();
            unlink($path);
        }
        $this->assertSoftDeleted($archived);
    }

    public function test_permanent_delete_does_not_require_download_and_preserves_shared_files_and_ledger_entries(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $archived = $this->case(['lead_id' => 'IC-DELETE-TEST']);
        $active = $this->case();
        $dependant = CaseDependent::create([
            'lead_id' => $active->id, 'linked_lead_id' => $archived->id, 'relationship' => 'child', 'source' => 'staff',
        ]);
        $own = $archived->documents()->create(['original_name' => 'own.pdf', 'file_path' => 'private/own.pdf']);
        $shared = $archived->documents()->create(['original_name' => 'shared.pdf', 'file_path' => 'private/shared.pdf']);
        $activeCopy = $active->documents()->create(['original_name' => 'shared.pdf', 'file_path' => $shared->file_path]);
        Storage::disk('local')->put($own->file_path, 'archived only');
        Storage::disk('local')->put($shared->file_path, 'shared source');

        $receivable = DB::table('finance_receivables')->insertGetId([
            'lead_id' => $archived->id, 'invoice_document_id' => $own->id, 'invoice_no' => 'INV-ARCHIVE-TEST',
            'client_name' => 'Sample Applicant', 'amount' => '500.00', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-30',
        ]);
        $entry = FinanceReceivable::findOrFail($receivable);
        $entry->payments()->create(['amount' => '125.00', 'paid_on' => '2026-11-04']);
        $folder = DB::table('media_folders')->insertGetId(['lead_id' => $archived->id, 'name' => 'Archived case']);
        $media = DB::table('media_files')->insertGetId([
            'folder_id' => $folder, 'original_name' => 'photo.png', 'file_path' => 'media-drive/photo.png', 'mime' => 'image/png',
            'size' => 9, 'sha256' => str_repeat('a', 64), 'upload_key' => 'c6477a4b-6278-4cdb-ae19-933429ba5233', 'actor_key' => 'staff',
        ]);
        $archived->documents()->create(['original_name' => 'photo.png', 'file_path' => 'media-drive/photo.png', 'media_file_id' => $media]);
        Storage::disk('local')->put('media-drive/photo.png', 'case file');
        $archived->delete();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->deleteJson(self::BASE."/archived/{$archived->id}", ['confirmation' => 'delete account'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->deleteJson(self::BASE."/archived/{$active->id}", ['confirmation' => 'DELETE ACCOUNT'])->assertNotFound();
        $this->patch(self::BASE."/archived/{$archived->id}/name", [
            'first_name' => 'Corrected', 'middle_name' => null, 'last_name' => 'Applicant', 'suffix' => null,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leads', ['id' => $archived->id, 'first_name' => 'Corrected']);

        $this->delete(self::BASE."/archived/{$archived->id}", ['confirmation' => 'DELETE ACCOUNT'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('leads', ['id' => $archived->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'immigration.case_permanently_deleted', 'user_id' => $admin->id]);
        $this->assertModelMissing($own);
        $this->assertModelMissing($shared);
        $this->assertModelExists($activeCopy);
        $this->assertNotSoftDeleted($active);
        $this->assertDatabaseHas('case_dependents', [
            'id' => $dependant->id, 'linked_lead_id' => null, 'first_name' => 'Corrected', 'family_name' => 'Applicant',
        ]);
        $this->assertDatabaseHas('finance_receivables', [
            'id' => $receivable, 'lead_id' => null, 'invoice_document_id' => null, 'amount' => '500.00',
        ]);
        $this->assertSame(375.0, $entry->fresh()->balance);
        $this->assertDatabaseMissing('media_files', ['id' => $media]);
        Storage::disk('local')->assertMissing('media-drive/photo.png');
        Storage::disk('local')->assertMissing($own->file_path);
        Storage::disk('local')->assertExists($activeCopy->file_path);
    }
}
