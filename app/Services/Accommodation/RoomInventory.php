<?php

namespace App\Services\Accommodation;

use App\Models\Property;
use App\Models\PropertyRoomVerification;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class RoomInventory
{
    public static function residents(Property $property): Collection
    {
        return Tenant::where('property_id', $property->id)->active()
            ->where(fn ($q) => $q->whereNull('contract_start')->orWhereDate('contract_start', '<=', now('Pacific/Auckland')->toDateString()))
            ->orderBy('id')->get(['id', 'property_id', 'unit', 'current_status', 'contract_start']);
    }

    public static function fingerprint(Property $property, Collection $residents): string
    {
        return hash('sha256', json_encode([
            'property' => $property->id, 'active' => (bool) $property->is_active, 'rooms' => $property->total_rooms, 'rental_mode' => $property->rental_mode,
            'residents' => $residents->map(fn ($tenant) => $tenant->only(['id', 'unit', 'current_status', 'contract_start']))->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public static function summarize(Property $property, ?Collection $residents = null, ?PropertyRoomVerification $verification = null, bool $lookup = true, ?bool $installed = null): array
    {
        $residents ??= self::residents($property);
        $installed ??= PropertyRoomVerification::available();
        $verification ??= $installed && $lookup ? PropertyRoomVerification::where('property_id', $property->id)->first() : null;
        $fingerprint = self::fingerprint($property, $residents);
        $verified = $verification && $property->total_rooms > 0
            && $verification->total_rooms === $property->total_rooms
            && min($verification->occupied, $verification->vacant, $verification->unavailable) >= 0
            && $verification->occupied + $verification->vacant + $verification->unavailable === $property->total_rooms
            && hash_equals($verification->tenancy_fingerprint, $fingerprint);
        $state = ! $verification ? 'unverified' : ($verified ? 'verified' : 'needs_review');

        return [
            'total' => $property->total_rooms > 0 ? (int) $property->total_rooms : null,
            'households' => $residents->count(), 'unlabelled_households' => $residents->filter(fn ($tenant) => blank($tenant->unit))->count(),
            'occupied' => $verified ? $verification->occupied : null,
            'vacant' => $verified ? $verification->vacant : null,
            'available_to_let' => $verified && $property->rental_mode === Property::RENTAL_MODE_PER_ROOM ? $verification->vacant : ($verified ? 0 : null),
            'unavailable' => $verified ? $verification->unavailable : null,
            'state' => $state, 'installed' => $installed,
            'observed_on' => $verification?->observed_on,
            'source_ref' => $verification?->source_ref,
            'vacancy_notes' => $verified ? $verification->vacancy_notes : null,
            'version' => hash('sha256', $fingerprint.json_encode($verification?->getRawOriginal(), JSON_THROW_ON_ERROR)),
            'today' => now('Pacific/Auckland')->toDateString(),
        ];
    }

    public static function dashboard(): array
    {
        $properties = Property::where('is_active', true)->orderBy('code')->orderBy('id')->get(['id', 'name', 'code', 'total_rooms', 'is_active', 'rental_mode']);
        $rows = self::forProperties($properties);

        return ['properties' => $rows->count(), 'rooms' => $rows->sum('total'), 'households' => $rows->sum('households'),
            'occupied' => $rows->sum('occupied'), 'vacant' => $rows->sum('vacant'), 'unavailable' => $rows->sum('unavailable'),
            'available_to_let' => $rows->sum('available_to_let'),
            'unverified_rooms' => $rows->where('state', '!=', 'verified')->sum('total'),
            'unverified_properties' => $rows->where('state', '!=', 'verified')->count(),
            'missing_capacity' => $rows->whereNull('total')->count(), 'unlabelled_households' => $rows->sum('unlabelled_households'),
            'rows' => $rows->all()];
    }

    public static function forProperties(Collection $properties): Collection
    {
        $residents = Tenant::whereIn('property_id', $properties->pluck('id'))->active()
            ->where(fn ($q) => $q->whereNull('contract_start')->orWhereDate('contract_start', '<=', now('Pacific/Auckland')->toDateString()))
            ->orderBy('id')->get(['id', 'property_id', 'unit', 'current_status', 'contract_start'])->groupBy('property_id');
        $installed = PropertyRoomVerification::available();
        $checks = $installed ? PropertyRoomVerification::whereIn('property_id', $properties->pluck('id'))->get()->keyBy('property_id') : collect();

        return $properties->map(function ($property) use ($residents, $checks, $installed) {
            $summary = self::summarize($property, $residents->get($property->id, collect()), $checks->get($property->id), false, $installed);

            return ['id' => $property->id, 'name' => $property->name, 'code' => $property->code, 'rental_mode' => $property->rental_mode] + $summary;
        });

    }
}
