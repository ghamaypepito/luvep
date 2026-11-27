<?php

namespace Tests\Feature\Education;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnglishStudentArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENTS = '/portal/education/students';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_education_can_archive_and_restore_a_shared_student_without_changing_the_lead_or_case(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = Lead::create([
            'first_name' => 'Emma', 'last_name' => 'Example', 'is_student' => true, 'is_immigration_case' => true,
            'english_stage' => 'PTE Review', 'education_stage' => 'Withdrawn', 'immigration_stage' => 'Endorsed',
        ]);
        $before = $lead->fresh()->getRawOriginal();
        $url = $this->action($lead);
        $this->get(self::STUDENTS.'?tab=english')->assertInertia(fn ($page) => $page
            ->where('canManageEnglishArchive', true)->where('initialStudentView', 'english')
            ->where('students.0.english_archived', false));
        $this->travel(1)->minutes();
        $this->from(self::STUDENTS)->patch($url, ['archived' => true])->assertRedirect(self::STUDENTS);
        $this->patch($url, ['archived' => true])->assertRedirect();
        $after = $lead->fresh()->getRawOriginal();
        $this->assertNotNull($after['english_students_archived_at']);
        $this->assertSame($before, [...$after, 'english_students_archived_at' => null]);
        $this->get(self::STUDENTS)->assertInertia(fn ($page) => $page
            ->has('students', 1)->where('students.0.english_archived', true)
            ->where('students.0.is_student', true)->where('students.0.is_immigration_case', true));
        $archive = self::STUDENTS.'/english-archive';
        $this->get($archive.'?search=emma%20example')->assertInertia(fn ($page) => $page
            ->component('portal/education/EnglishStudentArchive')->where('students.data.0.id', $lead->id));
        $this->get($archive.'?search=missing')->assertInertia(fn ($page) => $page->has('students.data', 0));

        $this->from($archive)->patch($url, ['archived' => false])->assertRedirect($archive);
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        $this->get($archive)->assertInertia(fn ($page) => $page->has('students.data', 0));
        $this->assertSame(
            ['education.english_student_archived', 'education.english_student_restored'],
            ActivityLog::where('entity_id', $lead->id)->whereIn('action', [
                'education.english_student_archived', 'education.english_student_restored',
            ])->oldest('id')->pluck('action')->all()
        );
    }

    public function test_archive_hides_an_english_only_row_from_education_students_but_keeps_other_portal_data(): void
    {
        $lead = Lead::create(['first_name' => 'English', 'last_name' => 'Only', 'stage' => 'English Pro']);
        $this->actingAs(User::factory()->create(['role' => 'education']))->patch($this->action($lead), ['archived' => true])->assertRedirect();
        $this->get(self::STUDENTS)->assertInertia(fn ($page) => $page->has('students', 0));
        $this->assertModelExists($lead);

        $this->actingAs(User::factory()->create(['role' => 'sales']))->get('/portal/sales/students')
            ->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.id', $lead->id)
                ->missing('students.0.english_archived')->where('canManageEnglishArchive', false));
    }

    public function test_archive_rejects_outside_roles_and_non_english_rows(): void
    {
        $english = Lead::create(['first_name' => 'English', 'last_name' => 'Student', 'english_stage' => 'DIY Review']);
        $other = Lead::create(['first_name' => 'Education', 'last_name' => 'Only', 'is_student' => true]);
        $this->actingAs(User::factory()->create(['role' => 'sales', 'portal_access' => ['education']]));
        $this->get(self::STUDENTS.'/english-archive')->assertForbidden();
        $this->patchJson($this->action($english), ['archived' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->patchJson($this->action($other), ['archived' => true])->assertNotFound();
        $this->patchJson($this->action($english), ['archived' => 'invalid'])->assertUnprocessable();
        $this->assertNull($english->fresh()->english_students_archived_at);
    }

    private function action(Lead $lead): string
    {
        return self::STUDENTS.'/'.$lead->id.'/english-archive';
    }
}
