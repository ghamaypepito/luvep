<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationViewingAttendee as Attendee;
use App\Models\AccommodationViewingSlot as Slot;
use App\Models\Property;
use App\Services\Accommodation\ViewingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ViewingBookingController extends Controller
{
    public function show(Request $request, Property $property, ViewingSchedule $schedule)
    {
        abort_unless($property->is_active, 404);
        $confirmation = $request->session()->get('viewing_confirmation');

        return inertia('accommodation/BookViewing', [
            'address' => $property->address ?: $property->name,
            'slots' => $schedule->available($property),
            'bookingUrl' => $request->fullUrl(),
            'confirmation' => ($confirmation['property_id'] ?? null) === $property->id ? $confirmation : null,
        ]);
    }

    public function book(Request $request, Property $property, ViewingSchedule $schedule)
    {
        abort_unless($property->is_active, 404);
        $data = $request->validate([
            'slot_id' => 'required|integer',
            'name' => 'required|string|max:191',
            'email' => 'required|email:rfc|max:191',
            'mobile' => ['required', 'string', 'max:40', 'regex:/^\+?(?=(?:\D*\d){6})[0-9 ().-]+$/D'],
        ]);

        $slot = $schedule->book($property, $data);

        return redirect()->to($request->fullUrl(), 303)->with('viewing_confirmation', [
            'property_id' => $property->id,
            'date' => $slot->viewing_date, 'time' => $slot->start_time, 'address' => $slot->property_address,
        ]);
    }

    public function saveSlot(Request $request, ViewingSchedule $schedule)
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', Rule::in(Slot::TIMES)],
            'slot_id' => 'nullable|integer',
            'property_id' => 'nullable|integer|required_if:blocked,false',
            'capacity' => 'required|integer|min:1|max:100',
            'blocked' => 'required|boolean',
        ]);

        if (CarbonImmutable::parse($data['date'], Slot::TIMEZONE)->isSunday()) {
            throw ValidationException::withMessages(['date' => 'Viewings are available Monday to Saturday.']);
        }

        $schedule->saveSlot($data);

        return back()->with('success', 'Viewing slot saved.');
    }

    public function updateAttendee(Request $request, Attendee $attendee, ViewingSchedule $schedule)
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['status' => ['required', Rule::in(Attendee::STATUSES)]]);
        $schedule->changeStatus($attendee, $data['status']);

        return back()->with('success', 'Viewing attendee updated.');
    }

    public function resend(Request $request, Attendee $attendee, ViewingSchedule $schedule)
    {
        $this->authorizeStaff($request);
        abort_unless($attendee->status === Attendee::CONFIRMED, 422);
        $sent = $schedule->queueConfirmation($attendee->slot, $attendee);

        return back()->with($sent ? 'success' : 'error', $sent ? 'Confirmation queued.' : 'The confirmation could not be queued. Try again.');
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(
            ViewingSchedule::staffCanManage($request->user()) && ! $request->attributes->get('user_view_actor'),
            403,
        );
    }
}
