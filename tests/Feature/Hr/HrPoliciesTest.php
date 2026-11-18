<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\HrPolicy;
use App\Models\User;
use App\Services\Hr\PolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        Storage::fake('hr_private');
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $hr = User::factory()->create(['role' => 'super_admin']);

        return [$user, $hr];
    }

    public function test_drafts_are_invisible_and_only_authorized_hr_can_publish(): void
    {
        [$user,$hr] = $this->fixture();
        $policy = HrPolicy::create(['draft_title' => 'Private draft', 'draft_body' => 'Not yet published']);
        $this->actingAs($user)->get('https://hr.luvep.com/policies')->assertOk()->assertInertia(fn (Assert $p) => $p->has('policies', 0));
        $this->postJson('https://hr.luvep.com/policies/'.$policy->id.'/publish', ['title' => 'Policy', 'body' => 'Text', 'version' => 0])->assertForbidden();
        $version = app(PolicyService::class)->publish($hr, $policy, 'Published policy', 'Plain text <script>never execute</script>');
        $this->get('https://hr.luvep.com/policies')->assertOk()->assertInertia(fn (Assert $p) => $p->has('policies', 1)->where('policies.0.versions.0.id', $version->id)->missing('policies.0.draft_body'));
    }

    public function test_acknowledgement_is_idempotent(): void
    {
        [$user,$hr] = $this->fixture();
        $policy = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $v = app(PolicyService::class)->publish($hr, $policy, 'Policy', 'Published text');
        $this->actingAs($user)->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertOk();
        $this->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertOk();
        $this->assertDatabaseCount('hr_policy_acknowledgements', 1);
        $this->assertDatabaseHas('hr_policy_acknowledgements', ['user_id' => $user->id, 'hr_policy_version_id' => $v->id]);
    }

    public function test_new_version_requires_new_acknowledgement_and_old_displayed_version_remains_exact(): void
    {
        [$user,$hr] = $this->fixture();
        $p = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $s = app(PolicyService::class);
        $old = $s->publish($hr, $p, 'Policy', 'Version one');
        $new = $s->publish($hr, $p->fresh(), 'Policy', 'Version two');
        $s->acknowledge($user, $old);
        $this->assertDatabaseMissing('hr_policy_acknowledgements', ['user_id' => $user->id, 'hr_policy_version_id' => $new->id]);
        $this->assertSame('Version one', $old->fresh()->body);
        $s->acknowledge($user, $new);
        $this->assertDatabaseCount('hr_policy_acknowledgements', 2);
    }

    public function test_preview_and_archived_employee_cannot_acknowledge_and_report_is_restricted(): void
    {
        [$user,$hr] = $this->fixture();
        $p = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $v = app(PolicyService::class)->publish($hr, $p, 'Policy', 'Text');
        $employee = HrEmployee::where('user_id', $user->id)->first();
        $this->actingAs($hr)->withSession(['hr_preview_employee_id' => $employee->id])->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertForbidden();
        $this->withSession(['hr_preview_employee_id' => null])->actingAs($user)->getJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledgements')->assertForbidden();
        $employee->update(['status' => 'archived']);
        $this->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertForbidden();
        $this->assertDatabaseCount('hr_policy_acknowledgements', 0);
    }

    private function pdf(string $text = 'Policy file'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('policy.pdf', "%PDF-1.4\n".$text."\n%%EOF");
    }

    public function test_attachment_snapshots_are_private_immutable_and_mine_has_only_published_versions(): void
    {
        [$user, $hr] = $this->fixture();
        HrEmployee::create(['user_id' => $hr->id, 'status' => 'active']);
        $service = app(PolicyService::class);
        $policy = $service->saveDraft($hr, 'Leave Policy', 'Body', null, 0, $this->pdf('First contents'));
        $firstAttachment = $policy->draft_attachment;
        $this->actingAs($user)->get('https://hr.luvep.com/policies/'.$policy->id.'/draft/attachment')->assertNotFound();
        $old = $service->publish($hr, $policy, 'Leave Policy', 'Body');
        $policy = $service->saveDraft($hr, 'Leave Policy', 'Updated body', $policy->fresh(), 2, $this->pdf('Second contents'));
        $new = $service->publish($hr, $policy, 'Leave Policy', 'Updated body');
        $this->assertSame($firstAttachment, $old->fresh()->attachment);
        $this->assertNotSame($old->attachment['storage_key'], $new->attachment['storage_key']);
        $download = $this->get('https://hr.luvep.com/policies/versions/'.$old->id.'/attachment')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('First contents', $download->streamedContent());
        $this->actingAs($hr)->get('https://hr.luvep.com/policies?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p->where('scope', 'mine')->where('manage', false)
            ->missing('policies.0.draft_attachment')->missing('policies.0.draft_body')->where('policies.0.versions.0.attachment.name', 'policy.pdf')
            ->missing('policies.0.versions.0.attachment.storage_key')->missing('policies.0.versions.0.attachment.sha256'));
        $this->get('https://hr.luvep.com/policies/'.$policy->id.'/draft/attachment')->assertOk();
        $this->withSession(['hr_preview_employee_id' => HrEmployee::where('user_id', $user->id)->value('id')])->get('https://hr.luvep.com/policies/'.$policy->id.'/draft/attachment')->assertForbidden();
        $this->assertStringNotContainsString('First contents', \App\Models\HrAuditEvent::all()->toJson());
    }

    public function test_multipart_attachments_keep_revision_guards_and_publisher_authorization(): void
    {
        [$user, $hr] = $this->fixture();
        $payload = ['title' => 'Official policy', 'body' => 'Text', 'version' => 0];
        $this->actingAs($user)->postJson('https://hr.luvep.com/policies', [...$payload, 'file' => $this->pdf()])->assertForbidden();
        $this->actingAs($hr)->post('https://hr.luvep.com/policies', [...$payload, 'file' => new UploadedFile(base_path('tests/Fixtures/Hr/minimal.docx'), 'policy.docx', null, null, true)])->assertRedirect();
        $policy = HrPolicy::firstOrFail();
        $before = Storage::disk('hr_private')->allFiles();
        $this->postJson('https://hr.luvep.com/policies/'.$policy->id.'/draft', [...$payload, 'file' => $this->pdf()])->assertConflict();
        $this->postJson('https://hr.luvep.com/policies/'.$policy->id.'/publish', [...$payload, 'file' => $this->pdf()])->assertConflict();
        $this->assertSame($before, Storage::disk('hr_private')->allFiles());
        $this->post('https://hr.luvep.com/policies/'.$policy->id.'/publish', [...$payload, 'version' => 1])->assertRedirect();
        $this->assertSame('policy.docx', $policy->versions()->firstOrFail()->attachment['original_filename']);
        $this->assertDatabaseCount('hr_policy_versions', 1);
    }

    public function test_stale_publish_and_audit_failure_clean_up_new_attachment_without_changing_published_snapshot(): void
    {
        [$user, $hr] = $this->fixture();
        $service = app(PolicyService::class);
        $policy = $service->saveDraft($hr, 'Policy', 'Body', null, 0, $this->pdf('Keep me'));
        $version = $service->publish($hr, $policy, 'Policy', 'Body');
        $before = Storage::disk('hr_private')->allFiles();
        try {
            $service->publish($hr, $policy, 'Policy', 'Stale body', $this->pdf('Stale'));
            $this->fail('Stale publish was accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->mock(\App\Services\Hr\HrAudit::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('audit failure')));
        foreach (['saveDraft', 'publish'] as $method) {
            try {
                $method === 'saveDraft'
                    ? $service->saveDraft($hr, 'Policy', 'New body', $policy->fresh(), 2, $this->pdf('Rollback'))
                    : $service->publish($hr, $policy->fresh(), 'Policy', 'New body', $this->pdf('Rollback'));
                $this->fail('Audit failure was ignored.');
            } catch (\RuntimeException $error) {
                $this->assertSame('audit failure', $error->getMessage());
            }
        }
        $this->assertSame($before, Storage::disk('hr_private')->allFiles());
        $this->assertDatabaseCount('hr_policy_versions', 1);
        $this->assertSame(2, $policy->fresh()->revision);
        $this->assertSame($version->attachment, $policy->fresh()->draft_attachment);
    }

    public function test_published_policy_model_rejects_attachment_mutation_and_migration_is_reversible(): void
    {
        [$user, $hr] = $this->fixture();
        $policy = app(PolicyService::class)->saveDraft($hr, 'Policy', 'Body', null, 0, $this->pdf());
        $version = app(PolicyService::class)->publish($hr, $policy, 'Policy', 'Body');
        try {
            $version->update(['attachment' => null]);
            $this->fail('Published policy attachment changed.');
        } catch (\LogicException $error) {
            $this->assertSame('Published policies are immutable.', $error->getMessage());
        }
        $migration = require database_path('migrations/2026_11_18_130000_add_hr_file_attachments.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('hr_policy_versions', 'attachment'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('hr_documents', 'submitted_by'));
        $migration->up();
        $this->assertDatabaseHas('hr_policy_versions', ['id' => $version->id, 'body' => 'Body']);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('hr_policies', 'draft_attachment'));
    }

    public function test_failed_policy_storage_write_preserves_existing_draft_and_revision(): void
    {
        [$user, $hr] = $this->fixture();
        $policy = HrPolicy::create(['draft_title' => 'Existing', 'draft_body' => 'Keep']);
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('hr_private')->andReturn($disk);
        try {
            app(PolicyService::class)->saveDraft($hr, 'New', 'New', $policy, 0, $this->pdf());
            $this->fail('Storage failure should abort draft save.');
        } catch (\RuntimeException $error) {
            $this->assertSame('The private policy file could not be stored.', $error->getMessage());
        }
        $this->assertSame('Keep', $policy->fresh()->draft_body);
        $this->assertSame(0, $policy->fresh()->revision);
        $this->assertNull($policy->fresh()->draft_attachment);
    }

    public function test_replacing_an_unpublished_draft_prunes_old_file_after_commit_but_preserves_published_ones(): void
    {
        [$user, $hr] = $this->fixture();
        $service = app(PolicyService::class);
        $policy = $service->saveDraft($hr, 'Policy', 'Body', null, 0, $this->pdf('Unused first'));
        $unused = $policy->draft_attachment['storage_key'];
        $policy = $service->saveDraft($hr, 'Policy', 'Body', $policy, 1, $this->pdf('Published second'));
        Storage::disk('hr_private')->assertMissing($unused);
        $published = $service->publish($hr, $policy, 'Policy', 'Body');
        $service->saveDraft($hr, 'Policy', 'Body', $policy->fresh(), 3, $this->pdf('Draft third'));
        Storage::disk('hr_private')->assertExists($published->attachment['storage_key']);
        $this->assertCount(2, Storage::disk('hr_private')->allFiles());
    }
}
