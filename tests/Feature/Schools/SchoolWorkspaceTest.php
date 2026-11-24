<?php

namespace Tests\Feature\Schools;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Program;
use App\Models\School;
use App\Models\SchoolWorkspaceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_view_as_preview_cannot_write_school_data(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->postJson('/admin/user-view/'.$this->staff->id)->assertOk();
        $token = session('user_view.token');
        $this->withHeader('X-Luvep-User-View', $token)->postJson($this->url('/enrolment'), $this->enrolment())->assertForbidden();
        $this->assertSame(0, SchoolWorkspaceRecord::count());
    }
}
