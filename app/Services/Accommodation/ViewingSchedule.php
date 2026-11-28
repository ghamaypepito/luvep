<?php

namespace App\Services\Accommodation;

use App\Mail\AccommodationViewingBooked;
use App\Models\AccommodationViewingAttendee as Attendee;
use App\Models\AccommodationViewingSlot as Slot;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class ViewingSchedule
{
    public static function staffCanManage(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || $user->hasRole('accommodation'));
    }

    public function staffDay(string $date): array
    {
        $slots = Slot::where('viewing_date', $date)->with('attendees')->orderBy('start_time')->get();

        return [
            'date' => $date,
            'today' => CarbonImmutable::now(Slot::TIMEZONE)->toDateString(),
            'times' => Slot::TIMES,
            'properties' => Property::where('is_active', true)->orderBy('name')->get()->map(fn (Property $property) => [
                'id' => $property->id,
                'address' => $property->address ?: $property->name,
                'url' => URL::signedRoute('accommodation.viewing-booking', $property),
            ]),
            'slots' => $slots->map(fn (Slot $slot) => [
                ...$slot->only(['id', 'property_id', 'property_address', 'start_time', 'capacity', 'blocked']),
                'past' => $slot->startsAt()->isPast(),
                'attendees' => $slot->attendees->map->contactDetails(),
            ]),
        ];
    }

    public function available(Property $property): array
    {
        $now = CarbonImmutable::now(Slot::TIMEZONE);

        return Slot::where('property_id', $property->id)
            ->where('viewing_date', '>=', $now->toDateString())
            ->where('blocked', false)
            ->withCount(['attendees as reserved' => fn ($query) => $query->where('status', '!=', Attendee::CANCELLED)])
            ->orderBy('viewing_date')->orderBy('start_time')->get()
            ->filter(fn (Slot $slot) => $slot->startsAt()->isAfter($now) && $slot->reserved < $slot->capacity)
            ->map(fn (Slot $slot) => [
                'id' => $slot->id, 'date' => $slot->viewing_date, 'time' => $slot->start_time,
                'address' => $slot->property_address,
            ])
            ->values()->all();
    }

    public function saveSlot(array $data): void
    {
        DB::transaction(function () use ($data) {
            $slot = Slot::firstOrCreate([
                'viewing_date' => $data['date'],
                'start_time' => $data['time'],
            ], ['capacity' => $data['capacity'], 'blocked' => true]);

            if (! $slot->wasRecentlyCreated && (int) ($data['slot_id'] ?? 0) !== $slot->id) {
                throw ValidationException::withMessages(['time' => 'This start time was already assigned. Reload the day to review the current slot.']);
            }

            $slot = Slot::lockForUpdate()->findOrFail($slot->id);
            if ($slot->startsAt()->isPast()) {
                throw ValidationException::withMessages(['time' => 'This viewing time has already passed.']);
            }

            $attendees = $slot->attendees()->lockForUpdate()->get();
            $propertyId = isset($data['property_id']) ? (int) $data['property_id'] : null;
            if ($attendees->isNotEmpty() && $slot->property_id !== $propertyId) {
                throw ValidationException::withMessages(['property_id' => 'The property cannot be changed after someone has booked this time.']);
            }
            if ($attendees->where('status', '!=', Attendee::CANCELLED)->count() > $data['capacity']) {
                throw ValidationException::withMessages(['capacity' => 'The limit must cover everyone already booked.']);
            }

            $property = $propertyId === null ? null : Property::where('is_active', true)->find($propertyId);
            if (! $property && (! $data['blocked'] || $propertyId !== $slot->property_id)) {
                throw ValidationException::withMessages(['property_id' => 'Choose an active property to open this viewing time.']);
            }

            $slot->update([
                'property_id' => $propertyId,
                'property_address' => $slot->property_id === $propertyId && $slot->property_address
                    ? $slot->property_address : ($property?->address ?: $property?->name),
                'capacity' => $data['capacity'],
                'blocked' => $data['blocked'],
            ]);
        }, 3);
    }

    public function book(Property $property, array $data): Slot
    {
        [$slot, $attendee] = DB::transaction(function () use ($property, $data) {
            $slot = Slot::where('property_id', $property->id)->lockForUpdate()->find($data['slot_id']);
            $email = mb_strtolower(trim($data['email']));
            if (! $slot) {
                throw ValidationException::withMessages(['slot_id' => 'This viewing time is no longer available.']);
            }

            $reserved = $slot->attendees()->where('status', '!=', Attendee::CANCELLED)->lockForUpdate()->get();
            if ($reserved->contains('email', $email)) {
                return [$slot, null];
            }
            if ($slot->blocked || ! $slot->startsAt()->isFuture() || $reserved->count() >= $slot->capacity
                || ! Property::whereKey($property->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['slot_id' => 'This viewing time is no longer available. Please choose another time.']);
            }

            $attendee = $slot->attendees()->updateOrCreate(['email' => $email], [
                'name' => $data['name'],
                'mobile' => $data['mobile'],
                'status' => Attendee::CONFIRMED,
                'attendance_response' => null,
                'attendance_responded_at' => null,
            ]);

            return [$slot, $attendee];
        }, 3);

        if ($attendee) {
            $this->queueConfirmation($slot, $attendee);
        }

        return $slot;
    }

    public function changeStatus(Attendee $attendee, string $status): void
    {
        DB::transaction(function () use ($attendee, $status) {
            $slot = Slot::lockForUpdate()->findOrFail($attendee->viewing_slot_id);
            $attendee = $slot->attendees()->lockForUpdate()->findOrFail($attendee->id);
            if ($attendee->status === Attendee::CANCELLED && $status !== Attendee::CANCELLED) {
                throw ValidationException::withMessages(['status' => 'Ask the client to book again if they want to attend.']);
            }

            $attendee->update(['status' => $status]);
        }, 3);
    }

    public function queueConfirmation(Slot $slot, Attendee $attendee): bool
    {
        try {
            Mail::to($attendee->email)->queue(new AccommodationViewingBooked(
                $attendee->name,
                $slot->property_address,
                $slot->startsAt()->format('l, j F Y'),
                $slot->startsAt()->format('g:i a'),
            ));

            return true;
        } catch (\Throwable $error) {
            Log::error('Accommodation viewing confirmation could not be queued', [
                'attendee_id' => $attendee->id,
                'exception' => $error::class,
            ]);

            return false;
        }
    }

    public function calendarEvents(string $start, string $end, ?array $propertyIds): array
    {
        return Slot::whereBetween('viewing_date', [$start, $end])
            ->when($propertyIds, fn ($query) => $query->whereIn('property_id', $propertyIds))
            ->whereHas('attendees')->with('attendees')->get()
            ->map(fn (Slot $slot) => [
                'id' => 'group-viewing-'.$slot->id,
                'source_type' => 'group_viewing',
                'source_id' => $slot->id,
                'title' => $slot->property_address,
                'subtitle' => $slot->attendees->count().' '.str('booking')->plural($slot->attendees->count()),
                'starts_at' => $slot->startsAt()->utc()->toIso8601String(),
                'ends_at' => $slot->startsAt()->addMinutes(30)->utc()->toIso8601String(),
                'is_all_day' => false,
                'color' => '#7C3AED',
                'icon' => 'Home',
                'property_id' => $slot->property_id,
                'property_address' => $slot->property_address,
                'meta' => [
                    'viewing_date' => $slot->viewing_date,
                    'start_time' => $slot->start_time,
                    'attendees' => $slot->attendees->map->contactDetails(),
                ],
            ])->all();
    }

    public function upcomingCount(): int
    {
        $now = CarbonImmutable::now(Slot::TIMEZONE);

        return Attendee::where('status', Attendee::CONFIRMED)
            ->whereHas('slot', fn ($query) => $query->where(fn ($upcoming) => $upcoming
                ->where('viewing_date', '>', $now->toDateString())
                ->orWhere(fn ($today) => $today->where('viewing_date', $now->toDateString())
                    ->where('start_time', '>', $now->format('H:i')))))
            ->count();
    }
}
