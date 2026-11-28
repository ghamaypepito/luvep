<?php

namespace Tests\Feature;

use App\Mail\AccommodationViewingBooked;
use App\Models\AccommodationViewingSlot;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccommodationViewingBookingTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWINGS = '/portal/accommodation/viewings';

    private const MONDAY = '2026-11-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-29 13:00', 'Pacific/Auckland'));
    }

    public function test_shared_link_books_two_one_and_three_attendees_into_three_calendar_entries(): void
    {
        Mail::fake();
        $staff = $this->staff();
        $property = $this->property('97 Sample Avenue');
        $other = $this->property('31 Example Way');
        $this->actingAs($staff);
        foreach (['11:00' => 2, '12:00' => 1, '16:00' => 3] as $time => $capacity) {
            $this->post(self::VIEWINGS.'/slots', $this->slot($property, $time, $capacity))
                ->assertSessionHasNoErrors();
        }
        $slots = AccommodationViewingSlot::orderBy('start_time')->get();
        $properties = $this->get(self::VIEWINGS.'?schedule_date='.self::MONDAY)->assertOk()
            ->inertiaProps('sharedSchedule.properties');
        $link = collect($properties)->firstWhere('id', $property->id)['url'];
        $this->assertSame(URL::signedRoute('accommodation.viewing-booking', $property), $link);

        Auth::forgetGuards();
        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('accommodation/BookViewing')
            ->where('address', '97 Sample Avenue')
            ->has('slots', 3)
            ->where('slots.0.time', '11:00')
            ->where('slots.2.time', '16:00'));
        $this->get(URL::signedRoute('accommodation.viewing-booking', $other))
            ->assertInertia(fn (Assert $page) => $page->has('slots', 0));
        $this->get(route('accommodation.viewing-booking', $property))->assertForbidden();

        $index = 0;
        foreach ($slots as $slot) {
            for ($seat = 0; $seat < $slot->capacity; $seat++) {
                $this->post($link, $this->attendee($slot, ++$index))->assertRedirect($link)->assertSessionHasNoErrors();
            }
        }
        $this->assertDatabaseCount('accommodation_viewing_attendees', 6);
        $this->assertDatabaseCount('bookings', 0);
        Mail::assertQueuedCount(6);
        Mail::assertQueued(AccommodationViewingBooked::class, fn ($mail) => $mail->hasTo('person1@example.test')
            && $mail->address === '97 Sample Avenue' && $mail->date === 'Monday, 30 November 2026' && $mail->time === '11:00 am');
        $this->get($link)->assertInertia(fn (Assert $page) => $page->has('slots', 0)
            ->where('confirmation.date', self::MONDAY)->where('confirmation.time', '16:00'));

        $this->actingAs($staff)->get(self::VIEWINGS.'?schedule_date='.self::MONDAY)->assertInertia(fn (Assert $page) => $page
            ->has('sharedSchedule.slots.0.attendees', 2)
            ->where('sharedSchedule.slots.0.attendees.0.name', 'Sample Person 1')
            ->where('sharedSchedule.slots.0.attendees.0.email', 'person1@example.test')
            ->where('sharedSchedule.slots.0.attendees.0.mobile', '0211234567')
            ->has('sharedSchedule.slots.1.attendees', 1)
            ->has('sharedSchedule.slots.2.attendees', 3));
        $events = $this->getJson($this->eventsUrl())->assertOk()->assertJsonCount(3)
            ->assertJsonPath('0.meta.viewing_date', self::MONDAY)
            ->assertJsonPath('0.meta.start_time', '11:00')
            ->assertJsonPath('0.starts_at', '2026-11-29T22:00:00+00:00')
            ->assertJsonPath('0.meta.attendees.0.email', 'person1@example.test')
            ->assertJsonPath('0.meta.attendees.0.mobile', '0211234567')
            ->json();
        $this->assertSame([2, 1, 3], array_map(fn ($event) => count($event['meta']['attendees']), $events));
        $this->getJson($this->eventsUrl().'&property_ids[]='.$other->id)->assertOk()->assertJsonCount(0);
        $this->getJson($this->eventsUrl().'&event_types[]=custom')->assertOk()->assertJsonCount(0);
    }

    public function test_schedule_rejects_conflicting_properties_and_unavailable_times(): void
    {
        Mail::fake();
        $property = $this->property('Sample House');
        $other = $this->property('Another House');
        $staff = $this->staff();
        $this->actingAs($staff)->post(self::VIEWINGS.'/slots', $this->slot($property, '11:00'))
            ->assertSessionHasNoErrors();
        $open = AccommodationViewingSlot::sole();
        $this->postJson(self::VIEWINGS.'/slots', $this->slot($other, '11:00'))->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson(self::VIEWINGS.'/slots', $this->slot($other, '09:00'))->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson(self::VIEWINGS.'/slots', [...$this->slot($property, '11:00'), 'date' => '2026-11-29'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->post(self::VIEWINGS.'/slots', [...$this->slot($property, '12:00'), 'blocked' => true])
            ->assertSessionHasNoErrors();
        $blocked = AccommodationViewingSlot::where('blocked', true)->firstOrFail();
        $this->post(self::VIEWINGS.'/slots', [...$this->slot($other, '15:00'), 'date' => '2026-12-05'])
            ->assertSessionHasNoErrors(); // Saturday.

        Auth::forgetGuards();
        $link = URL::signedRoute('accommodation.viewing-booking', $property);
        $this->get($link)->assertInertia(fn (Assert $page) => $page->has('slots', 1)->where('slots.0.id', $open->id));
        $this->postJson($link, $this->attendee($blocked))->assertUnprocessable()->assertJsonValidationErrors('slot_id');
        $this->postJson(URL::signedRoute('accommodation.viewing-booking', $other), $this->attendee($open))
            ->assertUnprocessable()->assertJsonValidationErrors('slot_id');
        $this->postJson($link, [...$this->attendee($open), 'mobile' => ''])->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->post($link, $this->attendee($open))->assertRedirect($link);
        $this->post($link, [...$this->attendee($open), 'email' => 'PERSON1@EXAMPLE.TEST'])->assertRedirect($link);
        $this->postJson($link, $this->attendee($open, 2))->assertUnprocessable()->assertJsonValidationErrors('slot_id');
        $this->assertDatabaseCount('accommodation_viewing_attendees', 1);
        Mail::assertQueuedCount(1);

        $this->actingAs($staff)->postJson(self::VIEWINGS.'/slots', [...$this->slot($other, '11:00'), 'slot_id' => $open->id])
            ->assertUnprocessable()->assertJsonValidationErrors('property_id');
        $this->post(self::VIEWINGS.'/slots', [...$this->slot($property, '11:00'), 'slot_id' => $open->id, 'blocked' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('Confirmed', $open->attendees()->sole()->status);
        $property->update(['is_active' => false]);
        $this->get($link)->assertNotFound();
    }

    public function test_staff_access_and_cancellation_preserve_the_legacy_viewing_list(): void
    {
        Mail::fake();
        $staff = $this->staff();
        $property = $this->property('Sample House');
        $this->postJson(self::VIEWINGS.'/slots', $this->slot($property, '11:00'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->postJson(self::VIEWINGS.'/slots', $this->slot($property, '11:00'))->assertForbidden();
        $this->getJson($this->eventsUrl())->assertForbidden();

        $this->actingAs($staff)->post(self::VIEWINGS.'/slots', $this->slot($property, '11:00', 2))->assertSessionHasNoErrors();
        $slot = AccommodationViewingSlot::sole();
        $link = URL::signedRoute('accommodation.viewing-booking', $property);
        $this->post($link, $this->attendee($slot))->assertRedirect($link);
        $this->post($link, $this->attendee($slot, 2))->assertRedirect($link);
        $this->postJson(self::VIEWINGS.'/slots', [...$this->slot($property, '11:00'), 'slot_id' => $slot->id])
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $first = $slot->attendees()->oldest('id')->firstOrFail();
        $this->patch(self::VIEWINGS."/attendees/{$first->id}/status", ['status' => 'Cancelled'])->assertSessionHasNoErrors();
        $this->get($link)->assertInertia(fn (Assert $page) => $page
            ->has('slots', 1)->missing('slots.0.attendees')->missing('slots.0.email'));
        $this->post($link, $this->attendee($slot, 3))->assertRedirect($link);

        Booking::create([
            'first_name' => 'Legacy', 'last_name' => 'Applicant', 'email' => 'legacy@example.test',
            'property_id' => $property->id, 'service_type' => 'Accommodation Viewing',
            'consultant_name' => 'Accommodation Team', 'status' => 'Pending',
        ]);
        $this->get(self::VIEWINGS.'?schedule_date='.self::MONDAY)->assertInertia(fn (Assert $page) => $page
            ->where('viewings.0.name', 'Legacy Applicant')
            ->where('viewings.0.status', 'Pending')
            ->where('sharedSchedule.slots.0.attendees.0.status', 'Cancelled')
            ->has('sharedSchedule.slots.0.attendees', 3));
        $this->actingAs(User::factory()->create(['role' => 'education', 'portal_access' => ['accommodation']]))
            ->getJson($this->eventsUrl())->assertOk()->assertJsonCount(0);
    }

    private function property(string $address): Property
    {
        return Property::create(['name' => $address, 'address' => $address, 'is_active' => true]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'accommodation', 'is_active' => true]);
    }

    private function slot(Property $property, string $time, int $capacity = 1): array
    {
        return [
            'date' => self::MONDAY, 'time' => $time, 'property_id' => $property->id,
            'capacity' => $capacity, 'blocked' => false,
        ];
    }

    private function attendee(AccommodationViewingSlot $slot, int $index = 1): array
    {
        return [
            'slot_id' => $slot->id, 'name' => "Sample Person {$index}",
            'email' => "person{$index}@example.test", 'mobile' => '0211234567',
        ];
    }

    private function eventsUrl(): string
    {
        return '/portal/accommodation/calendar/events?start='.self::MONDAY.'&end='.self::MONDAY;
    }
}
