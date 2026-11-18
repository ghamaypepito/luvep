<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        Storage::fake('hr_private');
        $user = User::factory()->create(['role' => 'sales']);
        $employee = HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $hr = User::factory()->create(['role' => 'super_admin']);

        return [$employee, $hr];
    }

    private function pdf(string $name = 'contract.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    public function test_employee_cannot_download_unreleased_version(): void
    {
        [$e,$hr] = $this->fixture();
        $v = app(DocumentService::class)->upload($hr, $e, $this->pdf(), ['title' => 'Employment agreement', 'classification' => 'hr_private']);
        $this->actingAs($e->user)->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertNotFound();
        $this->get('https://hr.luvep.com/documents')->assertOk()->assertInertia(fn (Assert $p) => $p->has('documents', 0));
    }

    public function test_release_revocation_new_versions_and_other_employee_access(): void
    {
        [$e,$hr] = $this->fixture();
        $service = app(DocumentService::class);
        $v = $service->upload($hr, $e, $this->pdf(), ['title' => 'Contract', 'classification' => 'employee_shared']);
        $service->release($hr, $v, true);
        $this->actingAs($e->user)->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $new = $service->upload($hr, $e, $this->pdf(), ['document_id' => $v->hr_document_id, 'title' => 'Contract', 'classification' => 'employee_shared']);
        $this->get('https://hr.luvep.com/documents/versions/'.$new->id)->assertNotFound();
        $service->release($hr, $v, false);
        $this->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertNotFound();
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        $service->release($hr, $v, true);
        $this->actingAs($other)->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertNotFound();
    }

    public function test_private_storage_and_preview_cannot_exceed_actor_or_employee(): void
    {
        [$e,$hr] = $this->fixture();
        $service = app(DocumentService::class);
        $v = $service->upload($hr, $e, $this->pdf(), ['title' => 'Medical file', 'classification' => 'medical']);
        $service->release($hr, $v, true);
        Storage::disk('hr_private')->assertExists($v->storage_key);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $e->id])->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertNotFound();
        $this->actingAs($hr)->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertOk();
        $service->release($hr, $v, false);
        $this->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertNotFound();
    }

    public function test_unsafe_uploads_and_restricted_release_are_denied(): void
    {
        [$e,$hr] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'module_permissions' => ['hr.documents.manage']]);
        foreach ([$this->pdf('fake.jpg'), UploadedFile::fake()->createWithContent('payload.svg', '<svg><script>alert(1)</script></svg>'), UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf'), UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->actingAs($hr)->post('https://hr.luvep.com/documents', ['employee_id' => $e->id, 'title' => 'File', 'classification' => 'hr_private', 'file' => $file])->assertSessionHasErrors('file');
        }
        $this->actingAs($admin)->postJson('https://hr.luvep.com/documents', ['employee_id' => $e->id, 'title' => 'Medical', 'classification' => 'medical', 'file' => $this->pdf()])->assertForbidden();
    }

    public function test_archived_documents_and_archived_employees_lose_self_service(): void
    {
        [$e,$hr] = $this->fixture();
        $service = app(DocumentService::class);
        $v = $service->upload($hr, $e, $this->pdf(), ['title' => 'Contract', 'classification' => 'employee_shared']);
        $service->release($hr, $v, true);
        $v->document->update(['archived_at' => now()]);
        $this->actingAs($e->user)->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertNotFound();
        $v->document->update(['archived_at' => null]);
        $e->update(['status' => 'archived']);
        $this->get('https://hr.luvep.com/documents/versions/'.$v->id)->assertForbidden();
    }

    public function test_staff_can_upload_own_files_and_versions_but_not_change_owner_class_or_hr_documents(): void
    {
        [$employee, $hr] = $this->fixture();
        $other = HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales'])->id, 'status' => 'active']);
        $metadata = ['employee_id' => $employee->id, 'title' => 'Supporting file', 'classification' => 'employee_shared'];
        $this->actingAs($employee->user)->postJson('https://hr.luvep.com/documents', [...$metadata, 'file' => $this->pdf()])->assertCreated();
        $document = \App\Models\HrDocument::firstOrFail();
        $this->assertSame($employee->user_id, $document->submitted_by);
        $first = $document->versions()->firstOrFail();
        $this->get('https://hr.luvep.com/documents/versions/'.$first->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('https://hr.luvep.com/documents', [...$metadata, 'document_id' => $document->id, 'file' => $this->pdf()])->assertCreated();
        foreach ([['employee_id' => $other->id], ['classification' => 'medical'], ['classification' => 'hr_private']] as $change) {
            $this->postJson('https://hr.luvep.com/documents', [...$metadata, ...$change, 'file' => $this->pdf()])->assertForbidden();
        }
        $hrFile = app(DocumentService::class)->upload($hr, $employee, $this->pdf(), ['title' => 'HR only', 'classification' => 'employee_shared']);
        $this->postJson('https://hr.luvep.com/documents', [...$metadata, 'document_id' => $hrFile->hr_document_id, 'file' => $this->pdf()])->assertForbidden();
        $this->postJson('https://hr.luvep.com/documents/versions/'.$first->id.'/release', ['released' => true])->assertForbidden();
        $this->postJson('https://hr.luvep.com/documents/'.$document->id.'/archive')->assertForbidden();
        $this->actingAs($other->user)->get('https://hr.luvep.com/documents/versions/'.$first->id)->assertNotFound();
        $this->assertDatabaseCount('hr_document_versions', 3);
    }

    public function test_mine_scope_omits_other_employees_and_unreleased_hr_versions_even_for_hr_manager(): void
    {
        [$other, $hr] = $this->fixture();
        $employee = HrEmployee::create(['user_id' => $hr->id, 'status' => 'active']);
        $service = app(DocumentService::class);
        $own = $service->upload($hr, $employee, $this->pdf(), ['title' => 'My supporting file', 'classification' => 'employee_shared']);
        $hidden = $service->upload($hr, $employee, $this->pdf(), ['title' => 'Own medical draft', 'classification' => 'medical']);
        $service->upload($hr, $other, $this->pdf(), ['title' => 'Other staff file', 'classification' => 'employee_shared']);
        $this->actingAs($hr)->get('https://hr.luvep.com/documents?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('scope', 'mine')->has('employees', 0)->has('classifications', 0)->where('selfUpload', true)->where('selfEmployeeId', $employee->id)
            ->has('documents', 1)->where('documents.0.id', $own->hr_document_id)->where('documents.0.can_manage', false)->where('documents.0.can_upload', true));
        $this->get('https://hr.luvep.com/documents')->assertOk()->assertInertia(fn (Assert $p) => $p->has('documents', 3));
        $service->release($hr, $hidden, true);
        $this->get('https://hr.luvep.com/documents?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p->has('documents', 2));
    }

    public function test_hr_new_versions_of_staff_documents_stay_private_until_released_and_preview_is_read_only(): void
    {
        [$employee, $hr] = $this->fixture();
        $service = app(DocumentService::class);
        $own = $service->upload($employee->user, $employee, $this->pdf(), ['title' => 'Supporting file', 'classification' => 'employee_shared']);
        $hrVersion = $service->upload($hr, $employee, $this->pdf(), ['document_id' => $own->hr_document_id, 'title' => 'Supporting file', 'classification' => 'employee_shared']);
        $this->actingAs($employee->user)->get('https://hr.luvep.com/documents?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p->has('documents.0.versions', 1)->where('documents.0.versions.0.id', $own->id));
        $this->get('https://hr.luvep.com/documents/versions/'.$hrVersion->id)->assertNotFound();
        $this->actingAs($hr)->withSession(['hr_preview_employee_id' => $employee->id])->get('https://hr.luvep.com/documents?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p->where('selfUpload', false)->where('documents.0.can_upload', false)->where('documents.0.can_manage', false));
        $this->postJson('https://hr.luvep.com/documents', ['employee_id' => $employee->id, 'title' => 'No', 'classification' => 'employee_shared', 'file' => $this->pdf()])->assertForbidden();
    }

    public function test_storage_and_database_roll_back_when_audit_fails(): void
    {
        [$employee, $hr] = $this->fixture();
        $this->mock(\App\Services\Hr\HrAudit::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('audit failure')));
        try {
            app(DocumentService::class)->upload($hr, $employee, $this->pdf(), ['title' => 'Rollback', 'classification' => 'hr_private']);
            $this->fail('Audit failure should abort the upload.');
        } catch (\RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('hr_documents', 0);
        $this->assertDatabaseCount('hr_document_versions', 0);
        $this->assertSame([], Storage::disk('hr_private')->allFiles());
    }

    public function test_owner_and_classification_cannot_be_mutated(): void
    {
        [$employee, $hr] = $this->fixture();
        $version = app(DocumentService::class)->upload($hr, $employee, $this->pdf(), ['title' => 'Immutable', 'classification' => 'medical']);
        $this->expectException(\LogicException::class);
        $version->document->update(['classification' => 'employee_shared', 'submitted_by' => $employee->user_id]);
    }

    public function test_failed_storage_write_aborts_upload_without_creating_metadata(): void
    {
        [$employee, $hr] = $this->fixture();
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('hr_private')->andReturn($disk);
        try {
            app(DocumentService::class)->upload($hr, $employee, $this->pdf(), ['title' => 'Failed', 'classification' => 'hr_private']);
            $this->fail('Storage failure should abort the upload.');
        } catch (\RuntimeException $error) {
            $this->assertSame('The private file could not be stored.', $error->getMessage());
        }
        $this->assertDatabaseCount('hr_documents', 0);
        $this->assertDatabaseCount('hr_document_versions', 0);
    }

    public function test_manager_without_employee_has_an_empty_personal_files_view(): void
    {
        [$employee, $hr] = $this->fixture();
        $this->actingAs($hr)->get('https://hr.luvep.com/documents?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p->has('documents', 0)->where('selfUpload', false)->where('selfEmployeeId', null));
    }

    public function test_default_admin_can_manage_shared_files_without_employee_or_private_document_grants(): void
    {
        [$employee, $hr] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'module_permissions' => []]);
        $data = ['employee_id' => $employee->id, 'title' => 'Shared personnel file', 'classification' => 'employee_shared'];
        $this->actingAs($admin)->postJson('https://hr.luvep.com/documents', [...$data, 'file' => $this->pdf()])->assertCreated();
        $shared = \App\Models\HrDocumentVersion::firstOrFail();
        $this->get('https://hr.luvep.com/documents/versions/'.$shared->id)->assertOk();
        $this->postJson('https://hr.luvep.com/documents', [...$data, 'document_id' => $shared->hr_document_id, 'file' => $this->pdf()])->assertCreated();
        $this->post('https://hr.luvep.com/documents/versions/'.$shared->id.'/release', ['released' => true])->assertRedirect();
        foreach (['hr_private', 'medical', 'compensation', 'case'] as $classification) {
            $private = app(DocumentService::class)->upload($hr, $employee, $this->pdf(), ['title' => 'Restricted', 'classification' => $classification]);
            $this->postJson('https://hr.luvep.com/documents', [...$data, 'classification' => $classification, 'file' => $this->pdf()])->assertForbidden();
            $this->get('https://hr.luvep.com/documents/versions/'.$private->id)->assertNotFound();
            $this->postJson('https://hr.luvep.com/documents/versions/'.$private->id.'/release', ['released' => true])->assertForbidden();
        }
        $this->get('https://hr.luvep.com/documents')->assertOk()->assertInertia(fn (Assert $p) => $p->where('selfUpload', false)->where('classifications', ['employee_shared'])
            ->has('documents', 1)->where('documents.0.can_manage', true)->where('documents.0.can_upload', true));
        $this->assertSame([], $admin->fresh()->module_permissions);
        $admin->update(['is_active' => false]);
        $this->postJson('https://hr.luvep.com/documents', [...$data, 'file' => $this->pdf()])->assertForbidden();
    }
}
