<?php

namespace Tests\Feature\Schools;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Program;
use App\Models\School;
use App\Models\SchoolWorkspaceRecord;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Program $program;

    private Lead $student;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($this->staff);
        $this->school = School::create(['name' => 'Synthetic School']);
        $this->program = Program::create(['school_id' => $this->school->id, 'title' => 'Synthetic Course', 'level' => 7,
            'category' => 'bachelors', 'status' => 'published', 'tuition_fee' => 12345]);
        $this->student = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true, 'school_id' => $this->school->id]);
    }

    private function url(string $suffix = ''): string
    {
        return '/portal/education/schools/'.$this->school->id.'/management'.$suffix;
    }

    private function enrolment(array $changes = []): array
    {
        return array_replace(['program_id' => $this->program->id, 'lead_id' => $this->student->id, 'status' => 'Currently Studying',
            'intake' => 'February 2027', 'starts_on' => '2027-02-01', 'ends_on' => '2028-02-01'], $changes);
    }

    public function test_dashboard_reuses_catalogue_and_never_infers_enrolment_or_leaks_credentials(): void
    {
        $this->school->update(['portal_password' => 'synthetic-secret', 'portal_username' => 'synthetic-login']);
        $before = $this->student->fresh()->getRawOriginal();
        $response = $this->get($this->url())->assertOk();
        $props = $response->inertiaProps();
        $this->assertSame([], $props['records']);
        $this->assertCount(1, $props['students']);
        $this->assertEquals($this->program->id, $props['programs'][0]['id']);
        $this->assertEquals(12345, $props['programs'][0]['tuition_fee']);
        $this->assertArrayNotHasKey('portal_password', $props['school']);
        $this->assertArrayNotHasKey('email', $props['students'][0]);
        $this->assertSame($before, $this->student->fresh()->getRawOriginal());
        $profile = $this->get('/portal/education/schools/'.$this->school->id.'/profile')->assertOk()->inertiaProps();
        $this->assertEquals($this->school->id, $profile['workspace']['school']['id']);
        $this->assertSame([], $profile['workspace']['records']);
        $this->assertSame('intake', $this->get($this->url('?tab=intake'))->inertiaProps()['initialSection']);
    }

    public function test_enrolment_edits_preserve_identity_stage_study_plans_and_catalogue(): void
    {
        $before = $this->student->fresh()->getRawOriginal();
        $program = $this->program->fresh()->getRawOriginal();
        $this->post($this->url('/enrolment'), $this->enrolment())->assertRedirect()->assertSessionHasNoErrors();
        $record = SchoolWorkspaceRecord::sole();
        $this->assertSame(1, $record->version);
        $this->post($this->url('/enrolment/'.$record->id), $this->enrolment(['version' => 1, 'status' => 'Completed']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $record->fresh()->data['status']);
        $this->assertSame(2, $record->fresh()->version);
        $this->assertSame($before, $this->student->fresh()->getRawOriginal());
        $this->assertSame($program, $this->program->fresh()->getRawOriginal());
        $this->assertSame($this->staff->id, ActivityLog::where('action', 'school.workspace_saved')->firstOrFail()->user_id);
    }

    public function test_dates_are_validated_without_guessing_missing_start_dates(): void
    {
        $this->postJson($this->url('/enrolment'), $this->enrolment(['ends_on' => '2026-01-01']))->assertUnprocessable();
        $this->postJson($this->url('/intake'), ['program_id' => $this->program->id, 'status' => 'Open', 'name' => 'Feb',
            'starts_on' => '2027-02-30'])->assertUnprocessable();
        $this->postJson($this->url('/intake'), ['program_id' => $this->program->id, 'status' => 'Open', 'name' => 'Feb',
            'starts_on' => '2027-02-01', 'opens_on' => '2027-01-20', 'closes_on' => '2027-01-01'])->assertUnprocessable();
        $this->assertSame(0, SchoolWorkspaceRecord::count());
        $this->post($this->url('/enrolment'), $this->enrolment(['starts_on' => null]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(SchoolWorkspaceRecord::sole()->data['starts_on']);
    }

    public function test_cross_school_program_plain_lead_and_invalid_status_are_denied(): void
    {
        $other = School::create(['name' => 'Unrelated School']);
        $program = Program::create(['title' => 'Other Course', 'school_id' => $other->id, 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $plain = Lead::create(['first_name' => 'Plain', 'last_name' => 'Lead', 'is_student' => false]);
        foreach ([['program_id' => $program->id], ['lead_id' => $plain->id], ['status' => 'Visa Approved']] as $change) {
            $this->postJson($this->url('/enrolment'), $this->enrolment($change))->assertUnprocessable();
        }
        $this->assertSame(0, SchoolWorkspaceRecord::count());
    }

    public function test_stale_update_is_denied_without_overwriting_newer_data(): void
    {
        $this->post($this->url('/enrolment'), $this->enrolment());
        $record = SchoolWorkspaceRecord::sole();
        $this->postJson($this->url('/enrolment/'.$record->id), $this->enrolment(['version' => 9, 'status' => 'Withdrawn']))->assertUnprocessable()->assertJsonValidationErrors('record');
        $this->assertSame('Currently Studying', $record->fresh()->data['status']);
        $other = School::create(['name' => 'Other']);
        $this->postJson('/portal/education/schools/'.$other->id.'/management/enrolment/'.$record->id, $this->enrolment(['version' => 1]))->assertNotFound();
        $this->postJson($this->url('/communication/'.$record->id), [])->assertNotFound();
    }

    public function test_private_correspondence_files_are_school_and_record_scoped_and_retained_on_edit(): void
    {
        $payload = ['communicated_on' => '2026-11-24', 'subject' => 'Synthetic meeting', 'type' => 'Meeting', 'contact' => 'Synthetic Contact',
            'program_id' => null, 'lead_id' => null,
            'staff_id' => $this->staff->id, 'summary' => 'Discuss programmes', 'status' => 'Awaiting Response', 'follow_up_on' => '2026-11-30',
            'attachments' => [UploadedFile::fake()->create('meeting.pdf', 10, 'application/pdf')]];
        $this->post($this->url('/communication'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $record = SchoolWorkspaceRecord::with('files')->sole();
        $file = $record->files->sole();
        $url = '/portal/education/schools/'.$this->school->id.'/management-records/'.$record->id.'/files/'.$file->id;
        $this->get($url)->assertOk()->assertDownload('meeting.pdf');
        $props = $this->get($this->url())->inertiaProps();
        $this->assertArrayNotHasKey('path', $props['records'][0]['files'][0]);
        unset($payload['attachments']);
        $this->post($this->url('/communication/'.$record->id), $payload + ['version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($file->path);
        $other = School::create(['name' => 'Other']);
        $this->get(str_replace('/schools/'.$this->school->id.'/', '/schools/'.$other->id.'/', $url))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get($url)->assertForbidden();
    }

    public function test_course_supplements_do_not_replace_existing_prices_and_only_one_per_course(): void
    {
        $payload = ['program_id' => $this->program->id, 'currency' => 'NZD', 'application_fee' => 0, 'other_fees' => 'Materials charged separately', 'status' => 'Inactive'];
        $before = $this->program->fresh()->getRawOriginal();
        $this->post($this->url('/course'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($this->url('/course'), $payload)->assertUnprocessable()->assertJsonValidationErrors('record');
        $this->assertSame($before, $this->program->fresh()->getRawOriginal());
        $this->postJson($this->url('/course'), array_replace($payload, ['application_fee' => -1]))->assertUnprocessable();
        $this->assertSame(1, SchoolWorkspaceRecord::count());
    }

    public function test_intakes_are_exact_programme_records_not_mutations_of_month_only_catalogue(): void
    {
        $this->program->update(['intake_months' => 'February, July']);
        foreach (['February 2027', 'February 2028'] as $name) {
            $this->post($this->url('/intake'), ['program_id' => $this->program->id, 'name' => $name, 'status' => 'Open',
                'starts_on' => str_contains($name, '2028') ? '2028-02-01' : '2027-02-01'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('February, July', $this->program->fresh()->intake_months);
        $this->assertSame(2, SchoolWorkspaceRecord::count());
    }

    public function test_unrelated_roles_extra_portal_grants_and_inactive_accounts_cannot_write(): void
    {
        foreach (['sales', 'agent', 'lead', 'immigration_manager', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['education']]))
                ->postJson($this->url('/enrolment'), $this->enrolment())->assertForbidden();
            $this->get($this->url())->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'education', 'is_active' => false]))
            ->postJson($this->url('/enrolment'), $this->enrolment())->assertForbidden();
        $this->assertSame(0, SchoolWorkspaceRecord::count());
    }

    public function test_admin_can_use_workspace_and_missing_school_cannot_receive_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get($this->url())->assertOk();
        $this->postJson('/portal/education/schools/999999/management/enrolment', $this->enrolment())->assertNotFound();
    }

    public function test_duplicate_enrolments_do_not_create_a_second_record(): void
    {
        $this->post($this->url('/enrolment'), $this->enrolment())->assertSessionHasNoErrors();
        $this->postJson($this->url('/enrolment'), $this->enrolment())->assertUnprocessable()->assertJsonValidationErrors('record');
        $this->assertSame(1, SchoolWorkspaceRecord::count());
        $this->post($this->url('/enrolment'), $this->enrolment(['intake' => 'July 2027']))->assertSessionHasNoErrors();
        $this->assertSame(2, SchoolWorkspaceRecord::count());
    }

    public function test_history_protection_prevents_deleting_files_or_moving_catalogue_associations(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('programs/synthetic.png', 'synthetic');
        $this->program->update(['image' => 'programs/synthetic.png']);
        $this->post($this->url('/enrolment'), $this->enrolment());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->delete('/admin/programs/'.$this->program->id)->assertRedirect()->assertSessionHas('error');
        Storage::disk('public')->assertExists('programs/synthetic.png');
        $other = School::create(['name' => 'Other school']);
        $this->postJson('/admin/programs/'.$this->program->id, ['title' => 'Synthetic Course', 'level' => 7, 'category' => 'bachelors',
            'status' => 'published', 'school_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('school_id');
        $this->delete('/admin/schools/'.$this->school->id)->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($this->school->fresh());
        $this->post('/admin/programs/'.$this->program->id, ['title' => 'Synthetic Course', 'level' => 7, 'category' => 'bachelors',
            'status' => 'archived'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('archived', $this->program->fresh()->status);
        $this->assertSame(1, SchoolWorkspaceRecord::count());
    }

    public function test_failed_catalogue_deletion_keeps_existing_files_and_metadata(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('programs/retained.png', 'programme banner');
        Storage::disk('public')->put('schools/retained.png', 'school logo');
        Storage::disk('local')->put('schools/retained.pdf', 'school agreement');
        $this->program->update(['image' => 'programs/retained.png']);
        $this->school->update(['logo_path' => 'schools/retained.png', 'agreement_path' => 'schools/retained.pdf']);
        // A late database/model failure must not destroy files before deletion commits.
        Program::deleting(function () {
            throw new \RuntimeException('Synthetic deletion failure');
        });
        School::deleting(function () {
            throw new \RuntimeException('Synthetic deletion failure');
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->delete('/admin/programs/'.$this->program->id)->assertStatus(500);
        $this->delete('/admin/schools/'.$this->school->id)->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($this->program->fresh());
        $this->assertNotNull($this->school->fresh());
        $this->assertSame('programme banner', Storage::disk('public')->get('programs/retained.png'));
        $this->assertSame('school logo', Storage::disk('public')->get('schools/retained.png'));
        $this->assertSame('school agreement', Storage::disk('local')->get('schools/retained.pdf'));
    }

    public function test_failed_audit_rolls_back_record_and_private_upload_and_invalid_upload_never_writes(): void
    {
        $this->postJson($this->url('/course'), ['program_id' => $this->program->id, 'status' => 'Active', 'currency' => 'NZD',
            'attachments' => [UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')]])->assertUnprocessable();
        ActivityLog::creating(function ($log) {
            if ($log->action === 'school.workspace_saved') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->post($this->url('/course'), ['program_id' => $this->program->id, 'status' => 'Active', 'currency' => 'NZD',
            'attachments' => [UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf')]])->assertStatus(500);
        $this->assertSame(0, SchoolWorkspaceRecord::count());
        $this->assertSame([], Storage::disk('local')->allFiles('school-workspace'));
    }

    public function test_failed_attachment_storage_is_rejected_without_record_or_bogus_file_metadata(): void
    {
        $manager = app('filesystem');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $this->postJson($this->url('/communication'), [
                'status' => 'Pending', 'communicated_on' => '2026-11-26', 'subject' => 'Synthetic storage failure',
                'type' => 'Email', 'staff_id' => $this->staff->id, 'summary' => 'Synthetic correspondence',
                'attachments' => [UploadedFile::fake()->create('reference.pdf', 2, 'application/pdf')],
            ])->assertUnprocessable()->assertJsonValidationErrors('attachments');
            $this->assertSame(0, SchoolWorkspaceRecord::count());
            $this->assertSame(0, DB::table('school_workspace_files')->count());
        } finally {
            Storage::swap($manager);
        }
    }

    public function test_partial_attachment_failure_cleans_only_successfully_stored_new_files(): void
    {
        $manager = app('filesystem');
        $path = 'school-workspace/'.$this->school->id.'/first.pdf';
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->twice()->andReturn($path, false);
        $disk->shouldReceive('delete')->once()->with($path)->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $this->postJson($this->url('/communication'), [
                'status' => 'Pending', 'communicated_on' => '2026-11-26', 'subject' => 'Synthetic partial upload',
                'type' => 'Email', 'staff_id' => $this->staff->id, 'summary' => 'Synthetic correspondence',
                'attachments' => [UploadedFile::fake()->create('first.pdf', 2, 'application/pdf'), UploadedFile::fake()->create('second.pdf', 2, 'application/pdf')],
            ])->assertUnprocessable()->assertJsonValidationErrors('attachments');
            $this->assertSame(0, SchoolWorkspaceRecord::count());
            $this->assertSame(0, DB::table('school_workspace_files')->count());
        } finally {
            Storage::swap($manager);
        }
    }

    public function test_history_created_during_programme_update_cannot_leave_an_orphaned_school_record(): void
    {
        Storage::fake('public');
        $oldImage = 'programs/retained.png';
        Storage::disk('public')->put($oldImage, 'retained banner');
        $this->program->update(['image' => $oldImage]);
        $actor = User::factory()->create(['role' => 'admin']);
        $other = School::create(['name' => 'Another school']);
        $inserted = false;
        Program::updating(function ($program) use ($actor, &$inserted) {
            if ($program->id !== $this->program->id || ! $program->isDirty('school_id')) {
                return;
            }
            $request = Request::create('/synthetic', 'POST', $this->enrolment());
            $request->setUserResolver(fn () => $actor);
            app(\App\Http\Controllers\SchoolWorkspaceController::class)->save($request, $this->school->id, 'enrolment');
            $inserted = true;
        });
        $this->actingAs($actor)->postJson('/admin/programs/'.$this->program->id, [
            'title' => 'Synthetic Course', 'level' => 7, 'category' => 'bachelors', 'status' => 'published',
            'school_id' => $other->id, 'image' => UploadedFile::fake()->image('new.png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('school_id');
        $this->assertTrue($inserted);
        $this->assertSame($this->school->id, $this->program->fresh()->school_id);
        $this->assertSame(0, SchoolWorkspaceRecord::count(), 'Observer-created history rolls back with the rejected move');
        $this->assertSame($oldImage, $this->program->fresh()->image);
        $this->assertSame('retained banner', Storage::disk('public')->get($oldImage));
        $this->assertSame([$oldImage], Storage::disk('public')->allFiles('programs'));
    }

    public function test_view_as_preview_cannot_write_school_data(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->postJson('/admin/user-view/'.$this->staff->id)->assertOk();
        $token = session('user_view.token');
        $this->withHeader('X-Luvep-User-View', $token)->postJson($this->url('/enrolment'), $this->enrolment())->assertForbidden();
        $this->assertSame(0, SchoolWorkspaceRecord::count());
    }

    public function test_existing_catalogue_and_profiles_work_before_the_workspace_migration_is_installed(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('school_workspace_files');
        \Illuminate\Support\Facades\Schema::dropIfExists('school_workspace_records');
        try {
            $this->assertNull($this->get('/portal/education/schools/'.$this->school->id.'/profile')->assertOk()->inertiaProps()['workspace']);
            $this->get($this->url())->assertStatus(503);
            $this->actingAs(User::factory()->create(['role' => 'admin']));
            $other = School::create(['name' => 'Synthetic other school']);
            $this->post('/admin/programs/'.$this->program->id, ['title' => 'Synthetic Course', 'level' => 7,
                'category' => 'bachelors', 'status' => 'published', 'school_id' => $other->id])->assertRedirect()->assertSessionHasNoErrors();
            $this->delete('/admin/programs/'.$this->program->id)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNull($this->program->fresh());
            $this->delete('/admin/schools/'.$this->school->id)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNull($this->school->fresh());
        } finally {
            (require database_path('migrations/2026_11_24_010000_create_school_workspace_records.php'))->up();
        }
    }
}
