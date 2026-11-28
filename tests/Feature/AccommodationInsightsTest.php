<?php

namespace Tests\Feature;

use App\Models\AccommodationViewingSlot;
use App\Models\Booking;
use App\Models\EoiSubmission;
use App\Models\LeadTask;
use App\Models\PreTenancyForm;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccommodationInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09T00:00:00Z'));
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
    }

    public function test_report_daily_tasks_reconcile_to_the_selected_nz_week_and_department(): void
    {
        foreach (['2026-11-01T10:59:59Z', '2026-11-01T11:00:00Z', '2026-11-08T10:59:59Z', '2026-11-08T11:00:00Z'] as $at) {
            LeadTask::forceCreate(['title' => 'Synthetic task', 'created_by' => auth()->id(), 'department' => 'accommodation',
                'created_at' => $at, 'completed' => true, 'completed_at' => $at]);
        }
        LeadTask::forceCreate(['title' => 'Other department', 'created_by' => auth()->id(), 'department' => 'finance',
            'created_at' => '2026-11-04 00:00:00', 'completed' => true, 'completed_at' => '2026-11-04 00:00:00']);

        $props = $this->get('/portal/accommodation/reports?preset=last_week')->assertOk()->inertiaProps();
        $this->assertCount(7, $props['insights']['daily_tasks']);
        $this->assertSame(['date' => '2026-11-02', 'created' => 1, 'completed' => 1], $props['insights']['daily_tasks'][0]);
        $this->assertSame(['date' => '2026-11-08', 'created' => 1, 'completed' => 1], $props['insights']['daily_tasks'][6]);
        $this->assertSame(2, array_sum(array_column($props['insights']['daily_tasks'], 'completed')));
        $this->assertSame(2, $props['weeklyReport']['tasks']['completed']);
        $this->assertSame(2, $props['weeklyReport']['tasks']['created']);
        $this->assertSame('2026-11-09', $props['insights']['as_of']);
    }

    public function test_calendar_scopes_the_month_and_excludes_other_services_and_cancelled_viewings(): void
    {
        $property = Property::create(['name' => 'Synthetic viewing property', 'status' => 'available', 'is_active' => true]);
        $base = ['first_name' => 'Synthetic', 'last_name' => 'Visitor', 'email' => 'viewing@example.test',
            'service_type' => 'Accommodation Viewing', 'property_id' => $property->id, 'status' => 'Confirmed',
            'consultant_name' => 'Synthetic staff', 'appointment_time' => '10:30'];
        foreach ([['2026-10-31', 'Confirmed'], ['2026-11-01', 'Confirmed'], ['2026-11-30', 'Pending'], ['2026-12-01', 'Confirmed'], ['2026-11-12', 'Cancelled']] as [$date, $status]) {
            Booking::create(array_replace($base, ['appointment_date' => $date, 'status' => $status]));
        }
        Booking::create(array_replace($base, ['service_type' => 'Immigration Consultation', 'appointment_date' => '2026-11-10']));
        $props = $this->get('/portal/accommodation/dashboard?month=2026-11')->assertOk()->inertiaProps();
        $this->assertSame('2026-11', $props['insights']['calendar']['month']);
        $this->assertSame(['2026-11-01', '2026-11-30'], array_column($props['insights']['calendar']['bookings'], 'date'));
        $this->assertSame(5, $props['viewingTotal'], 'All statuses and dates from the Viewings section count.');
        $this->assertCount(5, $this->get('/portal/accommodation/viewings')->assertOk()->inertiaProps()['viewings']);
        $this->assertSame('Synthetic viewing property', $props['insights']['calendar']['bookings'][0]['property']);
        $this->assertArrayNotHasKey('email', $props['insights']['calendar']['bookings'][0]);
        $this->getJson('/portal/accommodation/dashboard?month=invalid')->assertUnprocessable();
    }

    public function test_group_viewings_count_once_per_booked_slot_and_appear_in_both_insight_calendars(): void
    {
        $property = Property::create(['name' => 'Synthetic group viewing', 'address' => 'Test address', 'is_active' => true]);
        foreach (['11:00' => 2, '12:00' => 1, '16:00' => 3] as $time => $count) {
            $slot = AccommodationViewingSlot::create(['property_id' => $property->id, 'property_address' => 'Test address',
                'viewing_date' => '2026-11-10', 'start_time' => $time, 'capacity' => 5, 'blocked' => false]);
            for ($i = 0; $i < $count; $i++) {
                $slot->attendees()->create(['name' => 'Synthetic visitor '.$i, 'email' => "visitor{$i}@example.test", 'mobile' => '000', 'status' => 'Confirmed']);
            }
        }
        $cancelled = AccommodationViewingSlot::create(['property_id' => $property->id, 'property_address' => 'Test address',
            'viewing_date' => '2026-11-10', 'start_time' => '13:00', 'capacity' => 5, 'blocked' => false]);
        $cancelled->attendees()->create(['name' => 'Cancelled visitor', 'email' => 'cancelled@example.test', 'mobile' => '000', 'status' => 'Cancelled']);
        AccommodationViewingSlot::create(['property_id' => $property->id, 'property_address' => 'Test address',
            'viewing_date' => '2026-11-10', 'start_time' => '14:00', 'capacity' => 5, 'blocked' => false]);
        $dashboard = $this->get('/portal/accommodation/dashboard')->assertOk()->inertiaProps();
        $this->assertSame(4, $dashboard['viewingTotal'], 'Count recorded viewing groups across statuses, not empty slots or individual attendees.');
        foreach (['/portal/accommodation/dashboard', '/portal/accommodation/reports'] as $path) {
            $calendar = $this->get($path)->assertOk()->inertiaProps()['insights']['calendar']['bookings'];
            $this->assertCount(3, $calendar, 'Cancelled-only groups do not appear in the schedule.');
            $this->assertSame(['11:00', '12:00', '16:00'], array_column($calendar, 'time'));
            $this->assertSame(['2 attendees', '1 attendee', '3 attendees'], array_column($calendar, 'name'));
            foreach ($calendar as $item) {
                $this->assertArrayNotHasKey('email', $item);
                $this->assertArrayNotHasKey('mobile', $item);
            }
        }
    }

    public function test_zero_and_unverified_inventory_stay_distinct_on_both_screens(): void
    {
        $empty = $this->get('/portal/accommodation/dashboard')->assertOk()->inertiaProps();
        $this->assertSame(0, $empty['inventoryStats']['properties']);
        $this->assertSame(0, $empty['applicationStats']['total']);
        $this->assertSame(0, $empty['viewingTotal']);
        $this->assertSame([], $empty['insights']['calendar']['bookings']);
        $this->assertSame(0, array_sum(array_column($empty['insights']['daily_tasks'], 'completed')));
        Property::create(['name' => 'Unverified property', 'status' => 'unavailable', 'is_active' => true, 'total_rooms' => 4]);
        $dashboard = $this->get('/portal/accommodation/dashboard')->assertOk()->inertiaProps();
        $report = $this->get('/portal/accommodation/reports?preset=last_week')->assertOk()->inertiaProps();
        $this->assertSame($dashboard['inventoryStats'], $report['inventoryStats']);
        $this->assertSame(1, $report['inventoryStats']['unverified_properties']);
        $this->assertNull($report['inventoryStats']['rows'][0]['occupied']);
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get('/portal/accommodation/dashboard')->assertForbidden();
    }

    public function test_leads_count_website_registrations_including_submissions_that_progressed(): void
    {
        $registration = [
            'full_legal_name' => 'Synthetic Applicant', 'preferred_name' => 'Applicant',
            'id_number' => 'test', 'email' => 'applicant@example.test', 'mobile' => '000',
            'visa_status' => 'Work Visa', 'nationality' => 'Test', 'age' => 30,
            'room_type_interest' => 'single', 'tenancy_start_date' => '2026-11-18',
            'stay_duration' => '1 month', 'occupants' => '1', 'occupant_ages' => '30',
            'employment_status' => 'Employed', 'current_address' => 'Test address',
            'current_address_duration' => '1 year', 'living_situation' => 'Renting',
            'reason_for_moving' => 'Test', 'drinks_alcohol' => 'No', 'work_hours' => 'Day',
            'flatmate_description' => 'Test', 'preferred_viewing_time' => 'Any',
        ];
        EoiSubmission::create([...$registration, 'form_type' => 'cold']);
        EoiSubmission::create([...$registration, 'form_type' => 'hot', 'status' => 'agreement_signed']);
        PreTenancyForm::create(['full_legal_name' => 'Pre-tenancy only', 'email' => 'other@example.test', 'submitted_at' => now(), 'data' => []]);

        $props = $this->get('/portal/accommodation/dashboard')->assertOk()->inertiaProps();
        $this->assertSame(2, $props['applicationStats']['total']);
        $this->assertSame(1, $props['applicationStats']['new']);
    }
}
