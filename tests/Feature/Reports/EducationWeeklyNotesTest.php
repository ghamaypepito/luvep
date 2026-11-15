<?php

namespace Tests\Feature\Reports;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EducationWeeklyNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-13T00:00:00Z'));
    }

    private function entry(string $note = 'Event attended'): array
    {
        return ['week_start' => '2026-11-09', 'entry_action' => 'add', 'entry_id' => (string) Str::uuid(), 'note' => $note];
    }

    public function test_multiple_notes_preserve_legacy_content_retry_safely_and_roll_over(): void
    {
        Setting::set('weekly_report_note:education:2026-11-09', 'Existing weekly event');
        $user = User::factory()->create(['role' => 'education']);
        $first = $this->entry();
        $second = $this->entry('Second event');
        $this->actingAs($user);
        foreach ([$first, $second, $first] as $data) {
            $this->post('/portal/education/reports/note', $data)->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('education_weekly_notes', 2);
        $this->get('/portal/education/reports?preset=this_week')->assertInertia(fn (Assert $page) => $page
            ->where('weeklyReport.note', 'Existing weekly event')->has('weeklyReport.entries', 2)
            ->where('weeklyReport.entries.0.author', $user->name)->where('weeklyReport.entries.0.can_edit', true));
        $this->travelTo(\Carbon\Carbon::parse('2026-11-16T00:00:00Z'));
        $this->get('/portal/education/reports?preset=last_week')->assertInertia(fn (Assert $page) => $page->has('weeklyReport.entries', 2));
        $this->get('/portal/education/reports?preset=this_week')->assertInertia(fn (Assert $page) => $page->has('weeklyReport.entries', 0)->where('weeklyReport.note', ''));
    }

    public function test_shared_entries_allow_only_the_author_to_edit_and_reject_stale_edits(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $other = User::factory()->create(['role' => 'education']);
        $data = $this->entry();
        $this->actingAs($owner)->post('/portal/education/reports/note', $data)->assertSessionHasNoErrors();
        $edit = array_merge($data, ['entry_action' => 'edit', 'note' => 'Updated event', 'expected_note' => $data['note']]);
        $this->actingAs($other)->get('/portal/education/reports?preset=this_week')->assertInertia(fn (Assert $page) => $page->where('weeklyReport.entries.0.can_edit', false));
        $this->post('/portal/education/reports/note', $edit)->assertForbidden();
        $this->actingAs($owner)->post('/portal/education/reports/note', $edit)->assertSessionHasNoErrors();
        $this->post('/portal/education/reports/note', array_merge($edit, ['note' => 'Stale overwrite']))->assertSessionHasErrors('note');
        $this->assertDatabaseHas('education_weekly_notes', ['id' => $data['entry_id'], 'note' => 'Updated event']);
        $this->post('/portal/education/reports/note', array_merge($edit, ['week_start' => '2026-11-02']))->assertForbidden();
    }

    public function test_validation_and_department_boundaries_preserve_other_workflows(): void
    {
        $user = User::factory()->create(['role' => 'education']);
        $data = $this->entry();
        $this->actingAs($user);
        foreach ([['note' => ''], ['note' => str_repeat('x', 4001)], ['week_start' => '2026-11-10'], ['week_start' => '2026-11-16'], ['entry_id' => 'invalid'], ['entry_action' => 'delete']] as $invalid) {
            $this->post('/portal/education/reports/note', array_merge($data, $invalid))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('education_weekly_notes', 0);
        $this->actingAs(User::factory()->create(['role' => 'agent']))->post('/portal/education/reports/note', $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance']))
            ->post('/portal/finance/reports/note', ['week_start' => '2026-11-09', 'note' => 'Finance only'])->assertSessionHasNoErrors();
        $this->get('/portal/finance/reports?preset=this_week')->assertInertia(fn (Assert $page) => $page
            ->where('weeklyReport.note', 'Finance only')->missing('weeklyReport.entries'));
        $this->assertDatabaseCount('education_weekly_notes', 0);
        $this->assertSame('Finance only', Setting::get('weekly_report_note:finance:2026-11-09'));
    }
}
