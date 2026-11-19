<?php

namespace Tests\Feature\Education;

use App\Models\EducationDatedReportNote;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reports\EducationPersonalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationDatedReportNotesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/portal/education/reports/dated-notes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-12T00:00:00Z'));
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
    }

    private function staff(array $extra = []): User
    {
        return User::factory()->create(array_merge(['role' => 'education'], $extra));
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['occurred_on' => '2026-11-11', 'category' => 'other_activities',
            'body' => 'Synthetic team meeting', 'submission_key' => (string) Str::uuid()], $extra);
    }

    private function report(User $user, string $query = '?preset=this_week'): array
    {
        $response = $this->actingAs($user)->get('/portal/education/reports'.$query)->assertOk();
        $props = json_decode(json_encode($response->viewData('page')), true)['props'];
        $this->assertNull($props['error'] ?? null);

        return $props['personalReport'];
    }

    public function test_multiple_notes_keep_their_dates_and_categories_without_changing_legacy_notes_or_totals(): void
    {
        $emma = $this->staff();
        $legacy = ['accomplishments' => 'Original\nsummary', 'other_activities' => 'Historical meeting', 'next_week_tasks' => 'Plan ahead'];
        $key = EducationPersonalReport::noteKey($emma->id, '2026-11-09');
        Setting::set($key, $legacy, 'json');
        Setting::set('weekly_report_note:education:2026-11-09', 'Shared department note');
        Setting::set('weekly_report_note:immigration:2026-11-09', 'Immigration note');
        $this->actingAs($emma)->post(self::URL, $this->payload(['body' => 'First note']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(self::URL, $this->payload(['category' => 'accomplishments', 'body' => 'Second note']))->assertRedirect();
        $this->post(self::URL, $this->payload(['occurred_on' => '2026-11-10']))->assertRedirect();
        $report = $this->report($emma);
        $this->assertSame($legacy, $report['notes']);
        $this->assertSame(['2026-11-10' => 1, '2026-11-11' => 2], $report['dated_notes']['day_counts']);
        $this->assertSame(['2026-11-11', '2026-11-11', '2026-11-10'], array_column($report['dated_notes']['entries']['data'], 'date'));
        $this->assertSame('accomplishments', $report['dated_notes']['entries']['data'][0]['category']);
        $this->assertSame(0, array_sum(array_column($report['counts'], 'events')));
        $this->assertDatabaseCount('education_report_activities', 0);
        $this->assertSame($legacy, Setting::get($key));
        $this->assertSame('Shared department note', Setting::get('weekly_report_note:education:2026-11-09'));
        $this->assertSame('Immigration note', Setting::get('weekly_report_note:immigration:2026-11-09'));
        // The existing undated editor still updates only its old setting, never the new entries.
        $this->post('/portal/education/reports/personal-notes', ['week_start' => '2026-11-09'] + $legacy)->assertRedirect();
        $this->assertDatabaseCount('education_dated_report_notes', 3);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_retry_keys_are_idempotent_and_cannot_be_reused_after_change_or_deletion(): void
    {
        $emma = $this->staff();
        $other = $this->staff();
        $payload = $this->payload(['user_id' => $other->id]);
        $this->actingAs($emma)->post(self::URL, $payload)->assertRedirect();
        $this->post(self::URL, $payload)->assertRedirect();
        $note = EducationDatedReportNote::sole();
        $this->assertSame($emma->id, (int) $note->user_id);
        $this->postJson(self::URL, array_merge($payload, ['body' => 'Changed retry']))->assertConflict();
        $this->delete(self::URL.'/'.$note->id, ['revision' => 1])->assertRedirect();
        $this->assertSoftDeleted($note);
        $this->postJson(self::URL, $payload)->assertConflict();
        $this->assertDatabaseCount('education_dated_report_notes', 1);
    }

    public function test_edit_moves_a_note_to_its_activity_day_and_stale_edits_or_removal_do_not_lose_it(): void
    {
        $emma = $this->staff();
        $this->actingAs($emma)->post(self::URL, $this->payload())->assertRedirect();
        $note = EducationDatedReportNote::sole();
        $update = $this->payload(['occurred_on' => '2026-11-05', 'body' => 'Corrected', 'revision' => 1]);
        $this->patch(self::URL.'/'.$note->id, $update)->assertRedirect();
        $this->assertSame(2, $note->fresh()->revision);
        $this->assertSame([], $this->report($emma)['dated_notes']['entries']['data']);
        $last = $this->report($emma, '?preset=last_week')['dated_notes'];
        $this->assertSame('Corrected', $last['entries']['data'][0]['body']);
        $this->assertSame(['2026-11-05' => 1], $last['day_counts']);
        $this->patchJson(self::URL.'/'.$note->id, $update)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->deleteJson(self::URL.'/'.$note->id, ['revision' => 1])->assertUnprocessable();
        $this->assertSame('Corrected', $note->fresh()->body);
        $this->assertFalse($note->fresh()->trashed());
        $this->delete(self::URL.'/'.$note->id, ['revision' => 2])->assertRedirect();
        $this->assertSame([], $this->report($emma, '?preset=last_week')['dated_notes']['entries']['data']);
    }

    public function test_notes_are_private_even_between_education_staff_and_admin_accounts(): void
    {
        $emma = $this->staff();
        $this->actingAs($emma)->post(self::URL, $this->payload())->assertRedirect();
        $note = EducationDatedReportNote::sole();
        foreach ([$this->staff(), $this->staff(['role' => 'admin'])] as $other) {
            $this->assertSame([], $this->report($other)['dated_notes']['entries']['data']);
            $this->actingAs($other)->patchJson(self::URL.'/'.$note->id, $this->payload(['revision' => 1]))->assertNotFound();
            $this->deleteJson(self::URL.'/'.$note->id, ['revision' => 1])->assertNotFound();
        }
        $this->assertSame('Synthetic team meeting', $note->fresh()->body);
    }

    public function test_external_inactive_and_ungranted_roles_cannot_mutate_notes(): void
    {
        foreach (['lead', 'agent', 'sub_agent', 'revoked_lead', 'finance', 'immigration'] as $role) {
            $user = $this->staff(['role' => $role, 'portal_access' => in_array($role, ['finance', 'immigration']) ? [] : ['education']]);
            $this->actingAs($user)->postJson(self::URL, $this->payload())->assertForbidden();
            $this->patchJson(self::URL.'/1', $this->payload(['revision' => 1]))->assertForbidden();
            $this->deleteJson(self::URL.'/1', ['revision' => 1])->assertForbidden();
        }
        $this->actingAs($this->staff(['is_active' => false]))->postJson(self::URL, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('education_dated_report_notes', 0);
    }

    public function test_validation_rejects_invalid_future_empty_oversized_and_unknown_notes(): void
    {
        $this->actingAs($this->staff());
        foreach ([['occurred_on' => '2026-02-30'], ['occurred_on' => '2026-11-13'], ['body' => '   '],
            ['body' => str_repeat('x', 4001)], ['category' => 'next_week_tasks'], ['submission_key' => 'bad']] as $invalid) {
            $this->postJson(self::URL, $this->payload($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('education_dated_report_notes', 0);
    }

    public function test_periods_use_nz_civil_dates_custom_ranges_and_no_future_or_other_user_entries(): void
    {
        $emma = $this->staff();
        foreach (['2026-11-08', '2026-11-09', '2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
            EducationDatedReportNote::create($this->payload(['user_id' => $emma->id, 'occurred_on' => $date]));
        }
        $this->assertSame(['2026-11-12', '2026-11-11', '2026-11-09'], array_column($this->report($emma)['dated_notes']['entries']['data'], 'date'));
        $this->assertSame(['2026-11-08'], array_column($this->report($emma, '?preset=last_week')['dated_notes']['entries']['data'], 'date'));
        $this->assertSame(['2026-11-11'], array_column($this->report($emma, '?preset=custom&from=2026-11-10&to=2026-11-11')['dated_notes']['entries']['data'], 'date'));
        $this->assertCount(4, $this->report($emma, '?preset=all')['dated_notes']['entries']['data']);
        // NZ is already Friday, while UTC still shows Thursday.
        $this->travelTo(Carbon::parse('2026-11-12T11:15:00Z'));
        $this->assertCount(5, $this->report($emma)['dated_notes']['entries']['data']);
        $this->post(self::URL, $this->payload(['occurred_on' => '2026-11-13']))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_paginated_day_counts_and_empty_last_page_keep_all_entries_findable(): void
    {
        $emma = $this->staff();
        for ($i = 0; $i < 31; $i++) {
            EducationDatedReportNote::create($this->payload(['user_id' => $emma->id, 'body' => 'Note '.$i]));
        }
        $report = $this->report($emma)['dated_notes'];
        $this->assertCount(30, $report['entries']['data']);
        $this->assertSame(31, $report['day_counts']['2026-11-11']);
        $second = $this->report($emma, '?preset=this_week&note_page=2')['dated_notes'];
        $this->assertCount(1, $second['entries']['data']);
        $this->delete(self::URL.'/'.$second['entries']['data'][0]['id'], ['revision' => 1])->assertRedirect();
        $after = $this->report($emma, '?preset=this_week&note_page=2')['dated_notes'];
        $this->assertSame(1, $after['entries']['current_page']);
        $this->assertSame(30, $after['day_counts']['2026-11-11']);
        $this->assertCount(30, $after['entries']['data']);
    }
}
