<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\PropertyRoomVerification;
use App\Services\Accommodation\RoomInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomInventoryController extends Controller
{
    public function store(Request $request, Property $property)
    {
        RoomInventory::authorizeStaff($request);
        abort_unless(PropertyRoomVerification::available(), 503, 'Room verification is being installed. Please retry shortly.');
        $data = $request->validate([
            'occupied' => 'required|integer|min:0|max:50', 'vacant' => 'required|integer|min:0|max:50',
            'unavailable' => 'required|integer|min:0|max:50', 'version' => 'required|string|size:64',
            'observed_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Pacific/Auckland')->toDateString(),
            'source_ref' => 'required|string|max:500', 'vacancy_notes' => 'nullable|string|max:1000',
            'confirm' => 'accepted',
        ]);
        DB::transaction(function () use ($property, $request, $data) {
            $property = Property::whereKey($property->id)->lockForUpdate()->firstOrFail();
            if (! $property->is_active) {
                throw ValidationException::withMessages(['version' => 'Reactivate this property before confirming current occupancy.']);
            }
            if ($property->room_register !== null) {
                throw ValidationException::withMessages(['version' => 'This property uses room assignments. Update them in Setup to change occupancy.']);
            }
            $residents = RoomInventory::residents($property);
            $summary = RoomInventory::summarize($property, $residents);
            if (! hash_equals($summary['version'], $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The property or its tenancies changed. Refresh and verify the current figures.']);
            }
            if (! $summary['total'] || ($data['occupied'] + $data['vacant'] + $data['unavailable']) !== $summary['total']) {
                throw ValidationException::withMessages(['occupied' => 'Occupied, vacant and unavailable rooms must equal the verified property room capacity. Update that capacity first if needed.']);
            }
            $old = PropertyRoomVerification::where('property_id', $property->id)->first();
            $before = $old?->only(['occupied', 'vacant', 'unavailable', 'observed_on']);
            PropertyRoomVerification::updateOrCreate(['property_id' => $property->id], [
                ...array_diff_key($data, ['version' => true, 'confirm' => true]), 'total_rooms' => $summary['total'], 'verified_by' => $request->user()->id,
                'tenancy_fingerprint' => RoomInventory::fingerprint($property, $residents),
            ]);
            ActivityLog::record('accommodation.rooms_verified', ['properties' => [
                'property_id' => $property->id, 'before' => $before,
                'after' => array_intersect_key($data, array_flip(['occupied', 'vacant', 'unavailable', 'observed_on'])),
            ]]);
        });

        return back()->with('success', 'Room counts verified. Household agreements remain separate.');
    }
}
