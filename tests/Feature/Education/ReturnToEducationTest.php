<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\MessageLog;
use App\Models\User;
use App\Services\EmailAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnToEducationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    private function student(array $a = []): Lead
    {
        return Lead::create($a + ['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'synthetic@example.test',
            'is_student' => true, 'is_immigration_case' => false, 'education_stage' => 'Endorsed to Immigration', 'immigration_stage' => 'Visa Lodged',
            'stage_history' => [['department' => 'immigration', 'stage' => 'Visa Lodged', 'at' => '2026-10-01T01:00:00+00:00', 'assignee' => 'Dev']], 'immigration_assignee' => 'Dev', 'inz_visa_type' => 'Student Visa', 'status' => 'New Leads', 'english_stage' => 'PTE Review']);
    }

    private function change(Lead $lead, string $path, string $stage)
    {
        return match ($path) {
            'inline' => $this->post("/portal/education/students/{$lead->id}/dashboard-field", ['education_stage' => $stage]),
            'profile' => $this->post("/admin/leads/{$lead->id}/stage", ['field' => 'education_stage', 'status' => $stage]),
            'edit' => $this->post("/portal/education/students/{$lead->id}/update", ['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'synthetic@example.test', 'phone' => '+639000000000', 'program_text' => '', 'education_stage' => $stage]),
        };
    }

    public static function moves(): array
    {
        $rows = [];
        foreach (['inline', 'profile', 'edit'] as $path) {
            foreach (['School Enrolment', 'Course Ongoing', 'Completed'] as $stage) {
                $rows["$path $stage"] = [$path, $stage];
            }
        }

        return $rows;
    }

    #[DataProvider('moves')]
    public function test_legacy_handoff_can_resume_education_without_losing_its_case_or_related_records(string $path, string $stage): void
    {
        $education = auth()->user();
        $lead = $this->student();
        $id = $lead->id;
        $code = $lead->lead_id;
        Storage::disk('local')->put('synthetic/retained.txt', 'Synthetic retained document');
        $doc = LeadDocument::create(['lead_id' => $id, 'original_name' => 'Synthetic.txt', 'file_path' => 'synthetic/retained.txt', 'checklist_key' => 'general.other', 'status' => 'StaffShared']);
        $note = LeadNote::create(['lead_id' => $id, 'body' => 'Existing synthetic history', 'kind' => 'note']);
        $message = MessageLog::create(['recipient_type' => 'lead', 'recipient_id' => $id, 'recipient_address' => $lead->email, 'channel' => 'email', 'status' => 'sent', 'body' => 'Earlier synthetic message']);
        $this->assertTrue(Lead::immigrationCase()->whereKey($id)->exists());
        $this->mock(EmailAutomationService::class)->shouldNotReceive('fire');
        $this->change($lead, $path, $stage)->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $lead->refresh();
        $this->assertSame($id, $lead->id);
        $this->assertSame($code, $lead->lead_id);
        $this->assertSame($stage, $lead->education_stage);
        $this->assertTrue(Lead::immigrationCase()->whereKey($id)->exists());
        $this->assertTrue($lead->is_student);
        $this->assertTrue($lead->is_immigration_case);
        $this->assertSame('Visa Lodged', $lead->immigration_stage);
        $this->assertSame('Dev', $lead->immigration_assignee);
        $this->assertSame('Student Visa', $lead->inz_visa_type);
        $this->assertSame('PTE Review', $lead->english_stage);
        $this->assertSame('New Leads', $lead->status);
        $this->assertNull($lead->immigration_converted_at);
        $this->assertNull($lead->immigration_converted_by);
        $this->assertSame($id, $doc->fresh()->lead_id);
        $this->assertSame('synthetic/retained.txt', $doc->fresh()->file_path);
        $this->assertSame('Synthetic retained document', Storage::disk('local')->get('synthetic/retained.txt'));
        $this->assertSame($id, $note->fresh()->lead_id);
        $this->assertSame('Existing synthetic history', $note->fresh()->body);
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertDatabaseCount('leads', 1);
        $this->assertSame('Visa Lodged', $lead->stage_history[0]['stage']);
        $this->assertSame('immigration', $lead->stage_history[0]['department']);
        $this->assertContains($stage, array_column($lead->stage_history, 'stage'));
        $this->get('/portal/education/students')->assertOk()->assertInertia(fn ($p) => $p->where('students.0.id', $id));
        $this->actingAs(User::factory()->create(['role' => 'immigration']))->get("/portal/immigration/cases/{$id}/profile")->assertOk();
        $this->actingAs($education);
        $this->change($lead, $path, $stage)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('leads', 1);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_pure_education_stage_changes_do_not_create_cases(): void
    {
        $lead = $this->student(['education_stage' => 'Proposal Sent', 'immigration_stage' => null]);
        $this->change($lead, 'inline', 'School Enrolment')->assertSessionHasNoErrors();
        $this->assertFalse($lead->fresh()->is_immigration_case);
        $this->assertFalse(Lead::immigrationCase()->whereKey($lead->id)->exists());
    }

    public function test_existing_explicit_case_keeps_original_conversion_metadata(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $stamp = now()->subDays(10);
        $lead = $this->student(['is_immigration_case' => true, 'immigration_converted_at' => $stamp, 'immigration_converted_by' => $actor->id]);
        $stamp = $lead->fresh()->immigration_converted_at;
        $this->change($lead, 'inline', 'School Enrolment')->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertTrue($lead->is_immigration_case);
        $this->assertTrue($lead->immigration_converted_at->equalTo($stamp));
        $this->assertSame($actor->id, $lead->immigration_converted_by);
    }

    public function test_unrelated_edits_do_not_backfill_legacy_conversion_flags(): void
    {
        $lead = $this->student();
        $this->post("/portal/education/students/{$lead->id}/dashboard-field", ['comments' => 'Synthetic update'])->assertSessionHasNoErrors();
        $this->assertFalse($lead->fresh()->is_immigration_case);
        $this->assertTrue(Lead::immigrationCase()->whereKey($lead->id)->exists());
    }

    public function test_external_user_cannot_change_department_stages(): void
    {
        $lead = $this->student();
        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $this->change($lead, 'inline', 'School Enrolment')->assertForbidden();
        $this->assertSame('Endorsed to Immigration', $lead->fresh()->education_stage);
    }

    public function test_deliberate_case_flag_changes_are_not_overridden_by_retention_hook(): void
    {
        $lead = $this->student(['is_immigration_case' => true]);
        $lead->fill(['education_stage' => 'School Enrolment', 'is_immigration_case' => false])->save();
        $this->assertFalse($lead->fresh()->is_immigration_case);
    }
}
