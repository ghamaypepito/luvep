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
}
