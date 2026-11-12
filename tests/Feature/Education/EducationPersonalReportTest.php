<?php

namespace Tests\Feature\Education;

use App\Models\ActivityLog;
use App\Models\EducationReportActivity;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationPersonalReportTest extends TestCase
{
    use RefreshDatabase;

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

    private function client(array $extra = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Student',
            'is_student' => true, 'education_stage' => 'School Enrolment', 'stage_history' => []], $extra));
    }

    private function payload(Lead $lead, array $extra = []): array
    {
        return array_merge(['lead_id' => $lead->id, 'kind' => 'enrolment', 'occurred_on' => '2026-11-10',
            'details' => 'Synthetic university, programme A', 'submission_key' => (string) Str::uuid()], $extra);
    }

    private function report(User $user, string $query = '?preset=this_week'): array
    {
        $response = $this->actingAs($user)->get('/portal/education/reports'.$query)->assertOk();
        $props = json_decode(json_encode($response->viewData('page')), true)['props'];
        $this->assertNull($props['error'] ?? null);
        $this->assertArrayHasKey('personalReport', $props);
        return $props;
    }

    private function statusLog(User $actor, Lead $lead, string $new, string $at = '2026-11-11 23:00:00'): void
    {
        $log = ActivityLog::create(['user_id' => $actor->id, 'action' => 'lead.updated',
            'properties' => ['subject_type' => 'Lead', 'subject_id' => $lead->id,
                'changes' => ['status' => ['old' => 'New Leads', 'new' => $new]]]]);
        $log->forceFill(['created_at' => $at])->saveQuietly();
    }

    public function test_ten_enrolments_for_one_student_are_ten_dated_activities_without_stage_changes(): void
    {
        $emma = $this->staff();
        $student = $this->client();
        $before = $student->fresh()->getAttributes();
        Mail::fake(); Notification::fake(); Bus::fake();
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($emma)->post('/portal/education/reports/activities',
                $this->payload($student, ['details' => 'Synthetic school '.$i]))->assertRedirect()->assertSessionHasNoErrors();
        }
        $p = $this->report($emma)['personalReport'];
        $this->assertSame(10, $p['counts']['enrolment']['events']);
        $this->assertSame(1, $p['counts']['enrolment']['clients']);
        $this->assertCount(10, $p['entries']['data']);
        foreach ($p['entries']['data'] as $row) {
            $this->assertSame('Synthetic Student', $row['client']);
            $this->assertSame('2026-11-10', $row['date']);
        }
        $this->assertSame($before, $student->fresh()->getAttributes());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_contact_attribution_is_actor_specific_and_excludes_students_and_unknown_actors(): void
    {
        $emma = $this->staff();
        $nova = $this->staff(['role' => 'immigration_manager', 'portal_access' => ['education']]);
        $own = $this->client(['is_student' => false, 'education_stage' => null, 'status' => 'Contact Attempted']);
        $other = $this->client(['is_student' => false, 'education_stage' => null, 'status' => 'Contact Attempted']);
        $student = $this->client();
        $this->statusLog($emma, $own, 'Contact Attempted');
        $this->statusLog($emma, $own, 'Contact Attempted', '2026-11-11 22:00:00');
        $this->statusLog($nova, $other, 'Contact Attempted');
        $this->statusLog($emma, $student, 'Contact Attempted');
        $this->statusLog($emma, $own, 'Contacted for Booking');
        ActivityLog::create(['action' => 'lead.updated', 'properties' => ['subject_type' => 'Lead',
            'subject_id' => $other->id, 'changes' => ['status' => ['old' => 'New Leads', 'new' => 'Contact Attempted']]]]);
        $contacts = $this->report($emma)['personalReport']['contacts'];
        $this->assertSame([$own->id], array_column($contacts['Contact Attempted'], 'id'));
        $this->assertSame([$own->id], array_column($contacts['Contacted'], 'id'));
        $this->assertSame([$other->id], array_column($this->report($nova)['personalReport']['contacts']['Contact Attempted'], 'id'));
    }

    public function test_weekly_notes_are_personal_and_do_not_overwrite_shared_or_other_portal_notes(): void
    {
        $emma = $this->staff();
        $other = $this->staff();
        Setting::set('weekly_report_note:education:2026-11-09', 'Shared Education note');
        Setting::set('weekly_report_note:immigration:2026-11-09', 'Immigration note');
        $notes = ['week_start' => '2026-11-09', 'accomplishments' => 'Called five clients',
            'other_activities' => 'Team meeting', 'next_week_tasks' => 'Review offers'];
        $this->actingAs($emma)->post('/portal/education/reports/personal-notes', $notes)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Called five clients', $this->report($emma)['personalReport']['notes']['accomplishments']);
        $this->assertSame('', $this->report($other)['personalReport']['notes']['accomplishments']);
        $this->assertSame('', $this->report($emma, '?preset=last_week')['personalReport']['notes']['accomplishments']);
        $this->assertSame('Shared Education note', Setting::get('weekly_report_note:education:2026-11-09'));
        $this->assertSame('Immigration note', Setting::get('weekly_report_note:immigration:2026-11-09'));
        $this->actingAs($emma)->postJson('/portal/education/reports/personal-notes',
            array_merge($notes, ['week_start' => '2026-11-10']))->assertUnprocessable();
        $this->postJson('/portal/education/reports/personal-notes',
            array_merge($notes, ['week_start' => '2026-11-16']))->assertUnprocessable();
    }

    public function test_retry_keys_prevent_duplicates_and_actor_cannot_be_spoofed(): void
    {
        $emma = $this->staff();
        $other = $this->staff();
        $payload = $this->payload($this->client(), ['user_id' => $other->id]);
        $this->actingAs($emma)->post('/portal/education/reports/activities', $payload)->assertRedirect();
        $this->post('/portal/education/reports/activities', $payload)->assertRedirect();
        $entry = EducationReportActivity::sole();
        $this->assertSame($emma->id, (int) $entry->user_id);
        $this->post('/portal/education/reports/activities', array_merge($payload, ['details' => 'Different offer']))->assertStatus(409);
        $this->actingAs($other)->delete('/portal/education/reports/activities/'.$entry->id)->assertNotFound();
        $this->assertSame(0, $this->report($other)['personalReport']['counts']['enrolment']['events']);
        $this->actingAs($emma)->delete('/portal/education/reports/activities/'.$entry->id)->assertRedirect();
        $this->assertSoftDeleted('education_report_activities', ['id' => $entry->id]);
        $this->post('/portal/education/reports/activities', $payload)->assertStatus(409);
        $this->assertSame(0, $this->report($emma)['personalReport']['counts']['enrolment']['events']);
    }

    public function test_invalid_dates_details_and_unrelated_clients_are_rejected(): void
    {
        $emma = $this->staff();
        $student = $this->client();
        $this->actingAs($emma)->postJson('/portal/education/reports/activities',
            $this->payload($student, ['occurred_on' => '2026-11-13']))->assertUnprocessable();
        $this->postJson('/portal/education/reports/activities',
            $this->payload($student, ['kind' => 'enrolment', 'details' => '']))->assertUnprocessable();
        $this->postJson('/portal/education/reports/activities',
            $this->payload($student, ['kind' => 'unknown']))->assertUnprocessable();
        $immigration = $this->client(['is_student' => false, 'is_immigration_case' => true]);
        $this->postJson('/portal/education/reports/activities', $this->payload($immigration))->assertNotFound();
        $this->assertDatabaseCount('education_report_activities', 0);
    }

    public function test_search_and_mutations_deny_external_and_ungranted_portal_users(): void
    {
        $student = $this->client();
        foreach (['lead', 'agent', 'sub_agent', 'revoked_lead', 'finance', 'immigration'] as $role) {
            $extra = in_array($role, ['finance', 'immigration']) ? [] : ['portal_access' => ['education']];
            $this->actingAs($this->staff(['role' => $role] + $extra))
                ->getJson('/portal/education/reports/clients?q=Synthetic')->assertForbidden();
            $this->postJson('/portal/education/reports/activities', $this->payload($student))->assertForbidden();
            $this->postJson('/portal/education/reports/personal-notes', ['week_start' => '2026-11-09'])->assertForbidden();
        }
        $this->actingAs($this->staff())->getJson('/portal/education/reports/clients?q=Synthetic')->assertOk()
            ->assertJsonPath('clients.0.id', $student->id);
    }

    public function test_date_filters_and_pagination_keep_all_repeated_offers_and_proposals(): void
    {
        $emma = $this->staff();
        $student = $this->client();
        for ($i = 0; $i < 31; $i++) {
            EducationReportActivity::create($this->payload($student, ['kind' => 'offer', 'user_id' => $emma->id,
                'occurred_on' => '2026-11-12', 'details' => 'Offer '.$i]));
        }
        EducationReportActivity::create($this->payload($student, ['kind' => 'proposal', 'user_id' => $emma->id,
            'occurred_on' => '2026-11-11']));
        $p = $this->report($emma, '?preset=today')['personalReport'];
        $this->assertSame(31, $p['counts']['offer']['events']);
        $this->assertSame(1, $p['counts']['offer']['clients']);
        $this->assertSame(0, $p['counts']['proposal']['events']);
        $this->assertCount(30, $p['entries']['data']);
        $this->assertSame(2, $p['entries']['last_page']);
        $this->assertCount(1, $this->report($emma, '?preset=today&activity_page=2')['personalReport']['entries']['data']);
        $this->assertSame(1, $this->report($emma)['personalReport']['counts']['proposal']['events']);
        $lastPage = $this->report($emma, '?preset=today&activity_page=2')['personalReport']['entries']['data'];
        $this->actingAs($emma)->delete('/portal/education/reports/activities/'.$lastPage[0]['id'])->assertRedirect();
        $afterRemoval = $this->report($emma, '?preset=today&activity_page=2')['personalReport'];
        $this->assertSame(1, $afterRemoval['entries']['current_page']);
        $this->assertCount(30, $afterRemoval['entries']['data']);

    }

    public function test_contact_dates_use_new_zealand_midnight_and_exclude_future_logs(): void
    {
        $emma = $this->staff();
        $beforeMidnight = $this->client(['is_student' => false, 'status' => 'Contact Attempted']);
        $atMidnight = $this->client(['is_student' => false, 'status' => 'Contact Attempted']);
        $future = $this->client(['is_student' => false, 'status' => 'Contact Attempted']);
        $this->statusLog($emma, $beforeMidnight, 'Contact Attempted', '2026-11-11 10:59:59');
        $this->statusLog($emma, $atMidnight, 'Contact Attempted', '2026-11-11 11:00:00');
        $this->statusLog($emma, $future, 'Contact Attempted', '2026-11-12 01:00:00');
        $rows = $this->report($emma, '?preset=today')['personalReport']['contacts']['Contact Attempted'];
        $this->assertSame([$atMidnight->id], array_column($rows, 'id'));
    }
}
