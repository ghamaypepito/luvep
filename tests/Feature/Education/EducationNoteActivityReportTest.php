<?php

namespace Tests\Feature\Education;

use App\Models\EducationNoteActivity;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Services\Reports\EducationNoteActivityReport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EducationNoteActivityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-12T00:00:00Z'));
        CarbonImmutable::setTestNow(Carbon::now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function client(): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true,
            'education_stage' => 'School Enrolment']);
    }

    private function postNote(Lead $lead, array $data = [], string $base = '/portal/education'): LeadNote
    {
        $this->post("{$base}/leads/{$lead->id}/notes", ['body' => 'Private synthetic update', ...$data])
            ->assertRedirect()->assertSessionHasNoErrors();
        return $lead->notes()->latest('id')->firstOrFail();
    }

    private function report(string $query = 'preset=this_week'): array
    {
        $response = $this->get('/portal/education/reports?'.$query)->assertOk();
        $this->assertNull($response->inertiaProps('error'));
        return $response->inertiaProps('summary.note_activity');
    }

    public function test_each_note_is_recorded_with_its_client_and_explicit_activity_without_stage_changes(): void
    {
        $emma = User::factory()->create(['role' => 'education', 'name' => 'Synthetic Emma']);
        $this->actingAs($emma);
        $lead = $this->client();
        $before = $lead->fresh()->getAttributes();
        $this->postNote($lead, ['body' => 'I have not called the client yet']);
        for ($i = 0; $i < 10; $i++) {
            $this->postNote($lead, ['education_report_activity' => 'follow_up']);
        }
        $report = $this->report();
        $this->assertSame(11, $report['total']);
        $this->assertSame(1, $report['clients']);
        $this->assertSame(10, $report['counts']['follow_up']['events']);
        $this->assertSame(1, $report['counts']['update']['events']);
        $this->assertCount(11, $report['entries']['data']);
        $this->assertSame('Synthetic Student', $report['entries']['data'][0]['client']);
        $this->assertSame('Synthetic Emma', $report['entries']['data'][0]['author']);
        $this->assertSame($before, $lead->fresh()->getAttributes());
        $this->assertArrayNotHasKey('body', $report['entries']['data'][0]);
    }

    public function test_another_staff_members_posts_are_not_credited_to_emma_and_other_portals_are_unchanged(): void
    {
        $emma = User::factory()->create(['role' => 'education']);
        $nova = User::factory()->create(['role' => 'immigration_manager', 'portal_access' => ['education']]);
        $lead = $this->client();
        $this->actingAs($nova);
        $this->postNote($lead, ['education_report_activity' => 'follow_up']);
        $this->postNote($lead, ['education_report_activity' => 'follow_up'], '/portal/immigration');
        $this->assertSame(1, EducationNoteActivity::count());
        $this->assertSame(2, LeadNote::count());
        $this->assertSame(1, $this->report()['total']);
        $this->actingAs($emma);
        $this->assertSame(0, $this->report()['total']);
        $this->postNote($lead, [], '/admin'); // Older Education clients use the shared endpoint.
        $this->assertSame(1, $this->report()['total']);
    }

    public function test_edit_pin_and_report_refresh_do_not_create_more_posting_events_and_deletion_preserves_the_event(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = $this->client();
        $note = $this->postNote($lead);
        $this->post("/admin/leads/{$lead->id}/notes/{$note->id}", ['body' => 'Edited', 'pinned' => true])->assertRedirect();
        $this->assertSame(1, $this->report()['total']);
        $this->assertSame(1, $this->report()['total']);
        $this->delete("/admin/leads/{$lead->id}/notes/{$note->id}")->assertRedirect();
        $this->assertNull(EducationNoteActivity::sole()->note_id);
        $this->assertFalse($this->report()['entries']['data'][0]['note_available']);
    }

    public function test_validation_failure_saves_neither_note_nor_activity_and_replies_are_recorded_once(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = $this->client();
        $this->post("/portal/education/leads/{$lead->id}/notes", ['body' => 'Test', 'education_report_activity' => 'made_up'])
            ->assertSessionHasErrors('education_report_activity');
        $this->assertSame(0, LeadNote::count());
        $this->assertSame(0, EducationNoteActivity::count());
        $parent = $this->postNote($lead);
        $reply = $this->postNote($lead, ['parent_id' => $parent->id]);
        $this->assertTrue(EducationNoteActivity::where('note_id', $reply->id)->sole()->is_reply);
        $this->assertSame(2, $this->report()['total']);
    }

    public function test_note_and_report_event_are_atomic_when_recording_fails(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = $this->client();
        $this->mock(EducationNoteActivityReport::class, fn ($mock) =>
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic failure')));
        $this->post("/portal/education/leads/{$lead->id}/notes", ['body' => 'Test'])
            ->assertSessionHasErrors('error');
        $this->assertSame(0, LeadNote::count());
        $this->assertSame(0, EducationNoteActivity::count());
    }

    public function test_period_boundaries_pagination_and_unknown_historical_notes(): void
    {
        $emma = User::factory()->create(['role' => 'education']);
        $this->actingAs($emma);
        $lead = $this->client();
        LeadNote::create(['lead_id' => $lead->id, 'user_id' => $emma->id, 'body' => 'Unattributed historical department']);
        $this->assertSame(0, $this->report()['total']);
        foreach (['2026-11-08 10:59:59', '2026-11-08 11:00:00', '2026-11-12 00:00:01'] as $posted) {
            EducationNoteActivity::create(['lead_id' => $lead->id, 'user_id' => $emma->id, 'author_name' => $emma->name,
                'activity' => 'update', 'posted_at' => $posted]);
        }
        $this->assertSame(1, $this->report()['total']); // NZ Monday midnight inclusive; future excluded.
        $this->assertSame(1, $this->report('preset=last_week')['total']);
        for ($i = 0; $i < 31; $i++) {
            EducationNoteActivity::create(['lead_id' => $lead->id, 'user_id' => $emma->id, 'author_name' => $emma->name,
                'activity' => 'follow_up', 'posted_at' => '2026-11-12 00:00:00']);
        }
        $report = $this->report('preset=this_week&note_page=2');
        $this->assertSame(32, $report['total']);
        $this->assertCount(2, $report['entries']['data']);
        $this->assertSame(2, $report['entries']['current_page']);
        $this->assertSame(31, $this->report('preset=today')['total']);
    }

    public function test_unrelated_records_and_external_accounts_remain_denied(): void
    {
        $emma = User::factory()->create(['role' => 'education']);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Case', 'is_accommodation_client' => true]);
        $this->actingAs($emma)->post("/portal/education/leads/{$other->id}/notes", ['body' => 'Denied'])->assertForbidden();
        foreach (['lead', 'agent', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/portal/education/reports')->assertForbidden();
        }
        $this->assertSame(0, EducationNoteActivity::count());
    }

    public function test_support_view_as_user_credits_the_actual_actor_instead_of_the_impersonated_account(): void
    {
        $emma = User::factory()->create(['role' => 'education']);
        $support = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic Support']);
        $lead = $this->client();
        $note = LeadNote::create(['lead_id' => $lead->id, 'user_id' => $emma->id, 'body' => 'Synthetic assisted post']);
        $request = \Illuminate\Http\Request::create("/portal/education/leads/{$lead->id}/notes", 'POST');
        $request->setUserResolver(fn () => $emma);
        $request->attributes->set('user_view_actor', $support);
        app(EducationNoteActivityReport::class)->record($request, $lead, $note, 'update');
        $this->assertSame($support->id, EducationNoteActivity::sole()->user_id);
        $this->actingAs($emma);
        $this->assertSame(0, $this->report()['total']);
        $this->actingAs($support);
        $report = $this->report();
        $this->assertSame(1, $report['total']);
        $this->assertSame('Synthetic Support', $report['entries']['data'][0]['author']);
    }
}
