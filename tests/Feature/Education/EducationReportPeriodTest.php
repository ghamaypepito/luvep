<?php

namespace Tests\Feature\Education;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EducationReportPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04T00:00:00Z'));
        $this->staff = User::factory()->create(['role' => 'education']);
    }

    private function report(string $query = ''): array
    {
        $response = $this->actingAs($this->staff)->get('/portal/education/reports'.$query)->assertOk();

        return json_decode(json_encode($response->viewData('page')), true)['props'];
    }

    private function student(array $history = [], array $extra = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Report', 'last_name' => 'Student', 'is_student' => true,
            'education_stage' => 'Started Course', 'stage_history' => $history], $extra));
    }

    private function event(string $stage, mixed $at, string $department = 'education'): array
    {
        return compact('stage', 'at', 'department');
    }

    private function stage(array $props, string $stage, string $dept = 'education'): array
    {
        return collect($props['departments'][$dept]['stages'])->firstWhere('stage', $stage);
    }

    public function test_presets_follow_nz_calendar_boundaries_and_default_to_this_month(): void
    {
        foreach (['today' => '2026-11-04', 'this_week' => '2026-11-02', 'this_month' => '2026-11-01', 'this_year' => '2026-01-01'] as $preset => $from) {
            $p = $this->report('?preset='.$preset);
            $this->assertSame($from, $p['range']['from']);
            $this->assertSame('2026-11-04', $p['range']['to']);
            $this->assertSame('Pacific/Auckland', $p['range']['timezone']);
        }
        $this->assertSame('this_month', $this->report()['range']['preset']);
    }

    public function test_dated_cards_keep_past_milestones_and_do_not_use_shared_update_dates(): void
    {
        $included = $this->student([$this->event('Proposal Sent', '2026-11-03T20:00:00Z'), $this->event('Started Course', '2026-11-03T22:00:00Z')]);
        $this->student([$this->event('Proposal Sent', '2026-09-01T12:00:00Z')], ['education_stage' => 'Proposal Sent', 'stage_updated_at' => now()]);
        $this->student([$this->event('Proposal Sent', now()->toIso8601String(), 'immigration')], ['stage_updated_at' => now()]);
        $p = $this->report('?preset=today');
        $this->assertSame(1, $p['departments']['education']['total']);
        $this->assertSame(2, $p['departments']['education']['event_count']);
        $this->assertSame([$included->id], array_column($this->stage($p, 'Proposal Sent')['clients'], 'id'));
        $this->assertSame(1, $this->stage($p, 'Started Course')['count']);
        $this->assertSame('activity', $p['departments']['education']['scope']);
    }

    public function test_repeat_entries_count_student_once_and_ignore_exact_duplicate_history_entries(): void
    {
        $entry = $this->event('School Enrolment', '2026-11-03T22:00:00Z');
        $this->student([$entry, $entry, $this->event('School Enrolment', '2026-11-03T23:00:00Z')]);
        $p = $this->report('?preset=today');
        $this->assertSame(1, $this->stage($p, 'School Enrolment')['count']);
        $this->assertSame(2, $p['departments']['education']['event_count']);
        $this->assertCount(1, $this->stage($p, 'School Enrolment')['clients']);
        $this->assertStringContainsString('23:00:00', $this->stage($p, 'School Enrolment')['clients'][0]['date']);
    }

    public function test_nz_midnight_and_future_events_are_handled_without_utc_day_leakage(): void
    {
        $this->student([$this->event('School Enrolment', '2026-11-03T11:00:00Z')]);
        $this->student([$this->event('School Enrolment', '2026-11-03T10:59:59Z')]);
        $this->student([$this->event('School Enrolment', '2026-11-04T02:00:00Z')]);
        $this->assertSame(1, $this->stage($this->report('?preset=today'), 'School Enrolment')['count']);
    }

    public function test_custom_nz_dst_day_uses_calendar_days_and_a_half_open_next_day_boundary(): void
    {
        $this->student([$this->event('School Enrolment', '2026-09-26T12:00:00Z')]);
        $this->student([$this->event('School Enrolment', '2026-09-27T10:59:59Z')]);
        $this->student([$this->event('School Enrolment', '2026-09-27T11:00:00Z')]);
        $p = $this->report('?preset=custom&from=2026-09-27&to=2026-09-27');
        $this->assertSame(2, $this->stage($p, 'School Enrolment')['count']);
        $this->assertSame(1, $p['range']['days']);
        $this->assertSame(['from' => '2026-09-26', 'to' => '2026-09-26'], $p['range']['previous']);
    }

    public function test_all_retains_legacy_and_undated_records_without_fabricating_entry_dates(): void
    {
        $missing = $this->student([], ['education_stage' => null, 'stage_updated_at' => now()]);
        $legacy = $this->student([], ['education_stage' => 'Legacy programme stage']);
        $dated = $this->report('?preset=today');
        $this->assertSame(0, $dated['departments']['education']['total']);
        $p = $this->report('?preset=all');
        $this->assertSame(2, $p['departments']['education']['total']);
        $this->assertFalse($p['range']['compare']);
        $this->assertSame($missing->id, $this->stage($p, 'No stage set')['clients'][0]['id']);
        $this->assertNull($this->stage($p, 'No stage set')['clients'][0]['date']);
        $this->assertSame($legacy->id, $this->stage($p, 'Legacy programme stage')['clients'][0]['id']);
        $this->assertSame(1, $p['departments']['education']['insights']['missing_stage']);
        $this->assertSame(1, $p['departments']['education']['insights']['missing_history']);
    }

    public function test_malformed_dates_and_history_shapes_do_not_break_or_create_activity(): void
    {
        $this->student([null, 'bad', ['stage' => 'School Enrolment'], $this->event('School Enrolment', 'tomorrow'),
            $this->event('School Enrolment', '2026-02-31'), $this->event('School Enrolment', ['bad']),
            $this->event('School Enrolment', now()->toIso8601String())]);
        $p = $this->report('?preset=today');
        $this->assertSame(1, $p['departments']['education']['event_count']);
        $this->assertSame(1, $this->stage($p, 'School Enrolment')['count']);
    }

    public function test_insight_trend_totals_equal_distinct_matching_lists_and_previous_window(): void
    {
        $this->student([$this->event('School Enrolment', '2026-11-03T22:00:00Z'), $this->event('Started Course', '2026-11-03T23:00:00Z')]);
        $this->student([$this->event('School Enrolment', '2026-11-02T22:00:00Z')]);
        $p = $this->report('?preset=today');
        $m = collect($p['departments']['education']['insights']['metrics'])->firstWhere('key', 'School Enrolment');
        $this->assertSame(1, $m['count']);
        $this->assertSame(1, $m['previous_count']);
        $this->assertSame($this->stage($p, 'School Enrolment')['count'], array_sum(array_column($m['points'], 'count')));
        $this->assertSame(1, $p['range']['bucket_days']);
    }

    public function test_departments_use_their_own_history_and_exclude_pure_immigration_cases(): void
    {
        $this->student([$this->event('For PTE Exam', now()->toIso8601String(), 'english'), $this->event('Visa Lodged', now()->toIso8601String(), 'immigration')], ['english_stage' => 'For PTE Exam', 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        $this->student([$this->event('Visa Lodged', now()->toIso8601String(), 'immigration')], ['is_student' => false, 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        $p = $this->report('?preset=today');
        $this->assertSame(0, $p['departments']['education']['total']);
        $this->assertSame(1, $p['departments']['english']['total']);
        $this->assertSame(1, $p['departments']['immigration']['total']);
    }

    public function test_program_catalogue_counts_are_only_exposed_under_all(): void
    {
        $p = $this->report('?preset=today');
        $this->assertNull($p['programs']['total']);
        $this->assertFalse($p['programs']['history_available']);
        $this->assertIsInt($this->report('?preset=all')['programs']['total']);
    }

    public function test_success_explicitly_clears_an_old_partial_reload_error(): void
    {
        $props = $this->report('?preset=today');
        $this->assertArrayHasKey('error', $props);
        $this->assertNull($props['error']);
    }

    public function test_pipeline_uses_recorded_status_audit_changes_and_retains_pre_conversion_activity(): void
    {
        $lead = $this->student([], ['status' => 'Consultancy Agreement', 'stage_updated_at' => now()]);
        ActivityLog::create(['action' => 'lead.updated', 'created_at' => '2026-11-03 22:00:00', 'properties' => [
            'subject_type' => 'Lead', 'subject_id' => $lead->id, 'changes' => ['status' => ['old' => 'Qualified', 'new' => 'Proposal Sent']],
        ]]);
        Lead::create(['first_name' => 'No audit', 'last_name' => 'Proof', 'status' => 'Proposal Sent', 'stage_updated_at' => now()]);
        $p = $this->report('?preset=today');
        $this->assertSame(1, $p['totalRegister']);
        $this->assertSame($lead->id, $p['register']['Proposal Sent'][0]['id']);
        $this->assertSame('Proposal Sent', $p['pipeline'][0]['stage']);
        $this->assertArrayHasKey('notice', $p['pipeline_history']);
    }

    public function test_invalid_custom_ranges_return_validation_errors_and_legacy_links_resolve(): void
    {
        foreach ([['from' => 'bad', 'to' => '2026-11-04'], ['from' => '2026-11-05', 'to' => '2026-11-06'], ['from' => '2026-11-03', 'to' => '2026-11-01']] as $range) {
            $this->actingAs($this->staff)->getJson('/portal/education/reports?'.http_build_query(['preset' => 'custom'] + $range))->assertUnprocessable();
        }
        foreach (['quarter', 'last_month', 'two_weeks'] as $preset) {
            $this->assertSame('custom', $this->report('?preset='.$preset)['range']['preset']);
        }
    }

    public function test_external_grants_and_inactive_users_cannot_read_reports_or_write_commentary(): void
    {
        foreach (['lead', 'agent', 'sub_agent', 'revoked_lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['education']]))
                ->get('/portal/education/reports?preset=all')->assertForbidden();
            $this->post('/portal/education/reports/note', ['note_key' => 'edu_report_note:all', 'note' => 'Forbidden'])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'education', 'is_active' => false]))
            ->get('/portal/education/reports?preset=all')->assertRedirect('/login');
    }
}
