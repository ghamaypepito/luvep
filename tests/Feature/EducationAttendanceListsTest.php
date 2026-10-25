<?php

namespace Tests\Feature;

use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationAttendanceListsTest extends TestCase
{
    use RefreshDatabase;

    private function visit(string $school, string $slug): EducationVisit
    {
        return EducationVisit::create(['school' => $school, 'slug' => $slug, 'kind' => 'school', 'activity' => 'Education event', 'status' => 'open', 'timezone' => 'Asia/Manila']);
    }

    private function rsvp(EducationVisit $v, string $name, string $status = 'rsvp'): EducationRsvp
    {
        return EducationRsvp::create(['education_visit_id' => $v->id, 'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'full_name' => $name, 'name_key' => Str::slug($name), 'email' => Str::random(10).'@example.test', 'phone' => '+639171234567', 'consented_at' => now(), 'attendance_status' => $status]);
    }

    private function staff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    public function test_school_filter_combines_all_visits_and_other_filters(): void
    {
        $a = $this->visit('Alpha College', 'alpha-one');
        $b = $this->visit('Alpha College', 'alpha-two');
        $c = $this->visit('Beta College', 'beta');
        $this->rsvp($a, 'Alpha First', 'attended');
        $this->rsvp($b, 'Alpha Second');
        $this->rsvp($c, 'Beta Person');
        $this->staff();
        $this->get('/portal/education/rsvps?school=Alpha%20College')->assertOk()->assertViewHas('rsvps', fn ($rows) => $rows->total() === 2);
        $this->get('/portal/education/rsvps?school=Alpha%20College&attendance_status=attended')->assertViewHas('rsvps', fn ($rows) => $rows->total() === 1 && $rows->first()->full_name === 'Alpha First');
    }

    public function test_csv_exports_all_filtered_rows_and_neutralizes_formulas(): void
    {
        $a = $this->visit('Alpha College', 'alpha');
        $b = $this->visit('Beta College', 'beta');
        for ($i = 0; $i < 55; $i++) {
            $this->rsvp($a, 'Alpha Person '.$i);
        }
        $this->rsvp($a, '=DANGEROUS()');
        $this->rsvp($b, 'Excluded Person');
        $this->staff();
        $response = $this->get('/portal/education/rsvps/export?school=Alpha%20College&format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $text = $response->streamedContent();
        $this->assertStringContainsString('Alpha Person 0', $text);
        $this->assertStringContainsString('Alpha Person 54', $text);
        $this->assertStringNotContainsString('Excluded Person', $text);
        $this->assertStringContainsString("'=DANGEROUS()", $text);
        $this->assertStringContainsString("'+639171234567", $text);
        $lines = explode("\n", trim($text));
        $this->assertCount(57, $lines);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_pdf_exports_only_matching_attendees(): void
    {
        $a = $this->visit('Alpha College', 'alpha');
        $b = $this->visit('Beta College', 'beta');
        $this->rsvp($a, 'Allowed Student');
        $this->rsvp($b, 'Excluded Student');
        $this->staff();
        $response = $this->get('/portal/education/rsvps/export?school=Alpha%20College&format=pdf')->assertOk();
        $pdf = (new \Smalot\PdfParser\Parser)->parseContent($response->getContent());
        $text = $pdf->getText();
        $this->assertStringContainsString('Allowed Student', $text);
        $this->assertStringNotContainsString('Excluded Student', $text);
        $this->assertStringContainsString('to be confirmed', $text);
    }

    public function test_saved_list_in_folder_updates_with_new_rsvps_and_can_be_moved(): void
    {
        $a = $this->visit('Alpha College', 'alpha');
        $b = $this->visit('Beta College', 'beta');
        $this->rsvp($a, 'First Match');
        $this->rsvp($b, 'Other School');
        $this->staff();
        $folder = $this->postJson('/portal/education/rsvps/folders', ['name' => 'School visits'])->assertCreated()->json('id');
        $list = $this->postJson('/portal/education/rsvps/lists', ['name' => 'Alpha attendance', 'folder_id' => $folder, 'save_mode' => 'new', 'filters' => ['school' => 'Alpha College']])->assertCreated()->json('id');
        $this->get('/portal/education/rsvps?list_id='.$list)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 1);
        $this->rsvp($a, 'New Matching Attendee');
        $this->get('/portal/education/rsvps?list_id='.$list)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 2);
        $this->get('/portal/education/rsvps/export?list_id='.$list.'&format=csv')->assertOk();
        $other = $this->postJson('/portal/education/rsvps/folders', ['name' => 'Follow ups'])->assertCreated()->json('id');
        $this->postJson('/portal/education/rsvps/lists', ['list_id' => $list, 'save_mode' => 'update', 'name' => 'Renamed list', 'folder_id' => $other, 'filters' => ['school' => 'Beta College']])->assertOk();
        $this->get('/portal/education/rsvps?list_id='.$list)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 1 && $rows->first()->full_name === 'Other School');
        $this->assertDatabaseCount('education_rsvp_saved_lists', 1);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('education_outreach', 0);
    }

    public function test_export_and_list_management_are_restricted_and_validate_metadata(): void
    {
        $this->getJson('/portal/education/rsvps/export?format=csv')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'sales']))->get('/portal/education/rsvps/export?format=csv')->assertForbidden();
        $this->postJson('/portal/education/rsvps/folders', ['name' => 'Forbidden'])->assertForbidden();
        $this->staff();
        $this->postJson('/portal/education/rsvps/folders', ['name' => 'School lists'])->assertCreated();
        $this->postJson('/portal/education/rsvps/folders', ['name' => 'School lists'])->assertUnprocessable();
        $this->postJson('/portal/education/rsvps/lists', ['name' => 'Bad list', 'folder_id' => 9999, 'filters' => ['attendance_status' => 'invalid']])->assertUnprocessable();
        $this->getJson('/portal/education/rsvps/export?format=invalid')->assertUnprocessable();
        $this->getJson('/portal/education/rsvps?list_id=99999')->assertUnprocessable();
    }

    public function test_clearing_saved_filters_and_school_zero_do_not_widen_a_school_filter(): void
    {
        $v = $this->visit('Alpha College', 'alpha');
        $r = $this->rsvp($v, 'Verified Student');
        $r->update(['verified_at' => now()]);
        $this->staff();
        $folder = \App\Models\EducationRsvpFolder::first();
        $list = $this->postJson('/portal/education/rsvps/lists', ['save_mode' => 'new', 'folder_id' => $folder->id, 'name' => 'Verified', 'filters' => ['school' => 'Alpha College', 'verification' => 'verified', 'untrusted' => 'ignored']])->assertCreated()->json('id');
        $zeroResponse = $this->get('/portal/education/rsvps?school=0')->assertViewHas('rsvps', fn ($rows) => $rows->total() === 0);
        preg_match('/<select name="school"[^>]*>(.*?)<\/select>/s', $zeroResponse->getContent(), $schoolSelect);
        $this->assertStringContainsString('<option value="0" selected', $schoolSelect[1]);
        $this->get('/portal/education/rsvps?list_id='.$list.'&_filtered=1')->assertViewHas('attendanceFilters', [])->assertViewHas('listModified', true);
        $this->get('/portal/education/rsvps?verification=unverified')->assertViewHas('rsvps', fn ($rows) => $rows->total() === 0);
        $this->assertArrayNotHasKey('untrusted', \App\Models\EducationRsvpSavedList::find($list)->filters);
        $this->postJson('/portal/education/rsvps/lists', ['save_mode' => 'update', 'list_id' => ['bad'], 'folder_id' => [$folder->id], 'name' => 'Invalid', 'filters' => []])->assertUnprocessable();
    }

    public function test_folder_creation_keeps_current_filters_and_pdf_limit_never_truncates(): void
    {
        $this->staff();
        $this->post('/portal/education/rsvps/folders', ['name' => 'Follow up', 'filters' => ['school' => 'Alpha College']])->assertRedirect('/portal/education/rsvps?school=Alpha%20College&_filtered=1#attendance');
        $v = $this->visit('Alpha College', 'alpha');
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['education_visit_id' => $v->id, 'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'full_name' => 'Limit Student '.$i, 'name_key' => 'limit-'.$i, 'email' => 'limit-'.$i.'@example.test', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        EducationRsvp::insert($rows);
        $this->getJson('/portal/education/rsvps/export?format=pdf')->assertUnprocessable()->assertJsonValidationErrors('export');
    }

    public function test_selected_attendees_can_join_multiple_groups_without_duplication_and_export_by_group(): void
    {
        $v = $this->visit('Alpha College', 'alpha');
        $one = $this->rsvp($v, 'Grouped Student One');
        $two = $this->rsvp($v, 'Grouped Student Two');
        $other = $this->rsvp($v, 'Unselected Student');
        $this->staff();
        $folder = \App\Models\EducationRsvpFolder::first();
        $second = \App\Models\EducationRsvpFolder::create(['name' => 'Follow up']);
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => $folder->id, 'rsvp_ids' => [$one->id, $two->id]])->assertOk()->assertJsonPath('added', 2);
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => $folder->id, 'rsvp_ids' => [$one->id, $two->id]])->assertOk()->assertJsonPath('added', 0);
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => $second->id, 'rsvp_ids' => [$one->id]])->assertOk()->assertJsonPath('added', 1);
        $this->assertDatabaseCount('education_rsvp_folder_members', 3);
        $this->get('/portal/education/rsvps?folder_id='.$folder->id)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 2);
        $csv = $this->get('/portal/education/rsvps/export?format=csv&folder_id='.$folder->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Grouped Student One', $csv);
        $this->assertStringContainsString('Grouped Student Two', $csv);
        $this->assertStringNotContainsString('Unselected Student', $csv);
        $pdf = $this->get('/portal/education/rsvps/export?format=pdf&folder_id='.$second->id)->assertOk();
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('Grouped Student One', $text);
        $this->assertStringNotContainsString('Grouped Student Two', $text);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('education_outreach', 0);
    }

    public function test_group_saved_list_tracks_membership_and_removal_preserves_attendance(): void
    {
        $v = $this->visit('Alpha College', 'alpha');
        $one = $this->rsvp($v, 'First Member', 'attended');
        $two = $this->rsvp($v, 'Second Member');
        $lead = \App\Models\Lead::create(['lead_id' => 'GROUP-PRESERVE', 'first_name' => 'First', 'last_name' => 'Member', 'email' => $one->email, 'status' => 'Contact Attempted']);
        $one->update(['lead_id' => $lead->id, 'verified_at' => now(), 'linked_at' => now()]);
        $historyBefore = app(\App\Services\EducationRsvpWorkflow::class)->history($lead);
        $this->assertCount(1, $historyBefore);
        $this->staff();
        $folder = \App\Models\EducationRsvpFolder::first();
        $list = $this->postJson('/portal/education/rsvps/lists', ['save_mode' => 'new', 'name' => 'My group', 'folder_id' => $folder->id, 'filters' => ['folder_id' => $folder->id]])->assertCreated()->json('id');
        $this->get('/portal/education/rsvps?list_id='.$list)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 0);
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => $folder->id, 'rsvp_ids' => [$one->id, $two->id]])->assertOk();
        $this->get('/portal/education/rsvps?list_id='.$list)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 2);
        $this->deleteJson('/portal/education/rsvps/folders/'.$folder->id.'/members/'.$one->id)->assertOk();
        $this->get('/portal/education/rsvps?list_id='.$list)->assertViewHas('rsvps', fn ($rows) => $rows->total() === 1);
        $this->assertDatabaseHas('education_rsvps', ['id' => $one->id, 'attendance_status' => 'attended']);
        $this->assertDatabaseCount('education_rsvps', 2);
        $this->assertEquals($lead->id, $one->fresh()->lead_id);
        $this->assertSame('Contact Attempted', $lead->fresh()->status);
        $this->assertSame($historyBefore, app(\App\Services\EducationRsvpWorkflow::class)->history($lead));
    }

    public function test_manual_group_batches_are_atomic_validated_and_role_restricted(): void
    {
        $v = $this->visit('Alpha College', 'alpha');
        $one = $this->rsvp($v, 'Selected Student');
        $folder = \App\Models\EducationRsvpFolder::first();
        $params = ['folder_id' => $folder->id, 'rsvp_ids' => [$one->id]];
        $this->postJson('/portal/education/rsvps/group-members', $params)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'sales']))->postJson('/portal/education/rsvps/group-members', $params)->assertForbidden();
        $this->deleteJson('/portal/education/rsvps/folders/'.$folder->id.'/members/'.$one->id)->assertForbidden();
        $this->staff();
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => $folder->id, 'rsvp_ids' => [$one->id, 99999]])->assertUnprocessable();
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => $folder->id, 'rsvp_ids' => []])->assertUnprocessable();
        $this->postJson('/portal/education/rsvps/group-members', ['folder_id' => 99999, 'rsvp_ids' => [$one->id]])->assertUnprocessable();
        $this->assertDatabaseCount('education_rsvp_folder_members', 0);
    }
}
