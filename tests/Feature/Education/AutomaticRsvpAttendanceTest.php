<?php

namespace Tests\Feature\Education;

use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticRsvpAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_rsvp_dispatch_marks_attendance_after_each_confirmed_local_event_date(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-08 00:10', 'Asia/Manila'));

        $manila = $this->visit('2026-11-07');
        $auckland = $this->visit('2026-11-08', ['timezone' => 'Pacific/Auckland']);
        $manilaToday = $this->visit('2026-11-08', ['event_time' => '00:01']);
        $unverified = $this->rsvp($manila);
        $cancelledRsvp = $this->rsvp($manila, ['attendance_status' => 'cancelled']);
        $cancelledVisit = $this->rsvp($this->visit('2026-11-07', ['status' => 'cancelled']));
        $tentativeVisit = $this->rsvp($this->visit('2026-11-07', ['schedule_confirmed' => false]));
        $olderVisit = $this->rsvp($this->visit('2026-11-03'));
        $sameDay = $this->rsvp($manilaToday);
        $aucklandRsvp = $this->rsvp($auckland);
        $undated = $this->rsvp($this->visit(null, ['schedule_confirmed' => false]));

        $this->artisan('education:dispatch-rsvps')->assertSuccessful();

        $this->assertSame('attended', $unverified->fresh()->attendance_status);
        $this->assertSame('automatic', $unverified->fresh()->attendance_source);
        $this->assertNull($unverified->fresh()->verified_at);
        $this->assertSame('cancelled', $cancelledRsvp->fresh()->attendance_status);
        foreach ([$cancelledVisit, $tentativeVisit, $olderVisit, $sameDay, $aucklandRsvp, $undated] as $rsvp) {
            $this->assertSame('rsvp', $rsvp->fresh()->attendance_status);
        }

        $this->travelTo(Carbon::parse('2026-11-09 00:05', 'Pacific/Auckland'));
        $this->artisan('education:dispatch-rsvps')->assertSuccessful();
        $this->assertSame('attended', $aucklandRsvp->fresh()->attendance_status);
        $this->assertSame('rsvp', $sameDay->fresh()->attendance_status);
    }

    public function test_staff_correction_survives_the_next_automatic_run(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-08 00:10', 'Asia/Manila'));
        $rsvp = $this->rsvp($this->visit('2026-11-07'));
        $this->artisan('education:dispatch-rsvps')->assertSuccessful();
        $this->assertSame('attended', $rsvp->fresh()->attendance_status);

        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/rsvps?attendance_status=attended')
            ->assertOk()
            ->assertSee('Marked automatically after the event date.');

        $this->put('/portal/education/rsvps/'.$rsvp->id.'/attendance', ['attendance_status' => 'rsvp'])
            ->assertRedirect();
        $this->artisan('education:dispatch-rsvps')->assertSuccessful();

        $this->assertSame('rsvp', $rsvp->fresh()->attendance_status);
        $this->assertSame('manual', $rsvp->fresh()->attendance_source);
    }

    public function test_staff_can_mark_only_eligible_selected_rsvps_attended_in_bulk(): void
    {
        $visit = $this->visit(null, ['schedule_confirmed' => false]);
        $selected = $this->rsvp($visit);
        $unselected = $this->rsvp($visit);
        $cancelled = $this->rsvp($visit, ['attendance_status' => 'cancelled']);
        $cancelledVisit = $this->rsvp($this->visit(null, ['status' => 'cancelled', 'schedule_confirmed' => false]));
        $url = '/portal/education/rsvps/bulk-attendance';
        $selection = ['rsvp_ids' => [$selected->id, $cancelled->id, $cancelledVisit->id]];

        $this->actingAs(User::factory()->create(['role' => 'sales']))->postJson($url, $selection)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->postJson($url, ['rsvp_ids' => []])->assertUnprocessable();
        $this->postJson($url, $selection)->assertOk()->assertJson(['updated' => 1]);
        $this->postJson($url, $selection)->assertOk()->assertJson(['updated' => 0]);

        $this->assertSame('attended', $selected->fresh()->attendance_status);
        $this->assertSame('manual', $selected->fresh()->attendance_source);
        $this->assertSame('rsvp', $unselected->fresh()->attendance_status);
        $this->assertSame('cancelled', $cancelled->fresh()->attendance_status);
        $this->assertSame('rsvp', $cancelledVisit->fresh()->attendance_status);
        $this->get('/portal/education/rsvps')->assertOk()->assertSee('Mark selected as Attended');
    }

    private function visit(?string $date, array $attributes = []): EducationVisit
    {
        return EducationVisit::create($attributes + [
            'slug' => (string) Str::uuid(), 'school' => 'Test school', 'kind' => 'school',
            'activity' => 'Education event', 'status' => 'closed', 'timezone' => 'Asia/Manila',
            'event_date' => $date, 'schedule_confirmed' => true,
        ]);
    }

    private function rsvp(EducationVisit $visit, array $attributes = []): EducationRsvp
    {
        return $visit->rsvps()->create($attributes + [
            'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'full_name' => 'Test attendee', 'name_key' => 'testattendee',
            'email' => Str::uuid().'@example.test', 'consented_at' => now(), 'attendance_status' => 'rsvp',
        ]);
    }
}
