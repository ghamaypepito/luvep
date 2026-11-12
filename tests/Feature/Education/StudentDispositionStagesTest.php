<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentDispositionStagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    public static function stages(): array
    {
        return [['Qualified but No Funds'], ['Archive']];
    }

    private function student(array $extra = []): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true,
            'education_stage' => 'Proposal Sent', 'status' => 'Proposal Sent', ...$extra]);
    }

    #[DataProvider('stages')]
    public function test_dashboard_stage_is_visible_reportable_reversible_and_does_not_change_the_sales_pipeline(string $stage): void
    {
        $student = $this->student();
        $url = "/portal/education/students/{$student->id}/dashboard-field";
        $this->post($url, ['education_stage' => $stage])->assertRedirect()->assertSessionHasNoErrors();
        $student->refresh();
        $this->assertSame($stage, $student->education_stage);
        $this->assertSame('Proposal Sent', $student->status);
        $this->assertTrue($student->is_student);
        $this->assertFalse($student->is_immigration_case);
        $this->assertNull($student->deleted_at);
        $this->assertSame('education', $student->stage_history[0]['department']);
        $this->get('/portal/education/students')->assertOk()->assertInertia(fn ($p) => $p
            ->where('students.0.education_stage', $stage));
        $this->get('/portal/education/reports?preset=today')->assertOk()->assertInertia(fn ($p) => $p
            ->where('departments.education.stages', fn ($stages) => collect($stages)->contains(fn ($row) =>
                $row['stage'] === $stage && in_array($student->id, array_column($row['clients'], 'id')))));
        $count = count($student->stage_history);
        $this->post($url, ['education_stage' => $stage])->assertSessionHasNoErrors();
        $this->assertCount($count, $student->fresh()->stage_history);
        $this->post($url, ['education_stage' => 'Proposal Sent'])->assertSessionHasNoErrors();
        $this->assertSame('Proposal Sent', $student->fresh()->education_stage);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    #[DataProvider('stages')]
    public function test_profile_dropdown_save_preserves_parallel_work_and_advertises_both_options(string $stage): void
    {
        $student = $this->student(['is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged',
            'english_stage' => 'PTE Review', 'immigration_converted_at' => now()->subDay()]);
        $stamp = $student->immigration_converted_at;
        $this->get("/portal/education/leads/{$student->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('stageLists.education', fn ($stages) => collect($stages)->contains('Qualified but No Funds')
                && collect($stages)->contains('Archive')));
        $this->post("/admin/leads/{$student->id}/stage", ['field' => 'education_stage', 'status' => $stage])
            ->assertRedirect()->assertSessionHasNoErrors();
        $student->refresh();
        $this->assertSame($stage, $student->education_stage);
        $this->assertSame('PTE Review', $student->english_stage);
        $this->assertSame('Visa Lodged', $student->immigration_stage);
        $this->assertTrue($student->is_immigration_case);
        $this->assertTrue($student->immigration_converted_at->equalTo($stamp));
        $this->assertNull($student->deleted_at);
    }

    #[DataProvider('stages')]
    public function test_edit_form_and_legacy_handoff_keep_existing_case_membership(string $stage): void
    {
        $student = $this->student(['education_stage' => 'Endorsed to Immigration', 'english_stage' => 'PTE Review',
            'immigration_stage' => 'For Assessment', 'is_immigration_case' => false]);
        $this->assertTrue(Lead::immigrationCase()->whereKey($student->id)->exists());
        $this->post("/portal/education/students/{$student->id}/update", [
            'first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'student@example.test',
            'phone' => '+639000000000', 'program_text' => '', 'education_stage' => $stage,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $student->refresh();
        $this->assertSame($stage, $student->education_stage);
        $this->assertSame('For Assessment', $student->immigration_stage);
        $this->assertSame('PTE Review', $student->english_stage);
        $this->assertTrue(Lead::immigrationCase()->whereKey($student->id)->exists());
        $this->assertNull($student->immigration_converted_at);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_new_labels_do_not_expand_other_department_validation_or_external_access(): void
    {
        $student = $this->student();
        $this->post("/portal/education/students/{$student->id}/dashboard-field", ['immigration_stage' => 'Archive'])
            ->assertSessionHasErrors('immigration_stage');
        $this->post("/portal/education/students/{$student->id}/dashboard-field", ['english_stage' => 'Qualified but No Funds'])
            ->assertSessionHasErrors('english_stage');
        $this->actingAs(User::factory()->create(['role' => 'lead']))
            ->post("/portal/education/students/{$student->id}/dashboard-field", ['education_stage' => 'Archive'])
            ->assertForbidden();
        $this->assertSame('Proposal Sent', $student->fresh()->education_stage);
        $this->assertNotContains('Archive', Lead::EDUCATION_STAGES_IMMIGRATION);
        $this->assertNotContains('Qualified but No Funds', Lead::EDUCATION_STAGES_IMMIGRATION);
    }
}
