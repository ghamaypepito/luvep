<?php

namespace Tests\Feature\Education;

use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RsvpDesignOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_counts_all_rsvps_even_when_attendance_is_filtered(): void
    {
        $visit = EducationVisit::create([
            'slug' => 'sample-campus', 'school' => 'Sample campus', 'kind' => 'school',
            'activity' => 'Education orientation', 'status' => 'open', 'timezone' => 'Asia/Manila',
        ]);

        foreach (['rsvp', 'attended', 'cancelled'] as $status) {
            EducationRsvp::create([
                'education_visit_id' => $visit->id, 'public_id' => (string) Str::uuid(),
                'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
                'full_name' => 'Sample Attendee', 'name_key' => 'sampleattendee',
                'email' => Str::uuid().'@example.test', 'consented_at' => now(),
                'attendance_status' => $status,
            ]);
        }

        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/rsvps?attendance_status=attended')
            ->assertOk()
            ->assertViewHas('attendanceOverview', fn ($overview) => (int) $overview->total === 3 && (int) $overview->attended === 1)
            ->assertViewHas('rsvps', fn ($records) => $records->total() === 1)
            ->assertSee('Event workspace')
            ->assertSee('/education-ui/education.css', false);
    }

    public function test_both_public_forms_load_the_shared_design(): void
    {
        foreach (['register', 'rsvp'] as $page) {
            $this->get('/education/'.$page)->assertOk()->assertSee('/education-ui/education.css', false);
        }
    }
}
