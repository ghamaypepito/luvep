<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement;
use App\Models\Property;
use App\Models\PropertyRoomVerification;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RoomInventory
{
    public static function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user?->is_active && ($user->isAdmin() || $user->role === 'accommodation')
            && ! $request->attributes->has('user_view_actor'), 403);
    }

    public static function tenantTrackingAvailable(): bool
    {
        return Schema::hasColumn('accommodation_tenants', 'residency_started_on');
    }

    private static function currentTenants(bool $tracking): Builder
    {
        $today = now('Pacific/Auckland')->toDateString();
        $contractStarted = fn ($q) => $q->whereNull('contract_start')->orWhereDate('contract_start', '<=', $today);

        return Tenant::query()->active()
            ->when($tracking, fn ($q) => $q->where(fn ($q) => $q->whereDate('residency_started_on', '<=', $today)
                ->orWhere(fn ($q) => $q->whereNull('residency_started_on')->where($contractStarted))),
                fn ($q) => $q->where($contractStarted));
    }

    private const LEGACY_FIELDS = ['id', 'property_id', 'unit', 'current_status', 'contract_start'];

    private const RESIDENT_FIELDS = [...self::LEGACY_FIELDS, 'assigned_room_ids', 'residency_started_on'];

    public static function residents(Property $property): Collection
    {
        $tracking = self::tenantTrackingAvailable();

        return self::currentTenants($tracking)->where('property_id', $property->id)->orderBy('id')
            ->get($tracking ? self::RESIDENT_FIELDS : self::LEGACY_FIELDS);
    }

    public static function validateAssignment(Property $property, array $ids): void
    {
        abort_if($ids && ! self::tenantTrackingAvailable(), 503, 'Room assignments are being installed. Try again shortly.');
        if (array_diff($ids, array_column($property->room_register ?? [], 'id'))) {
            throw ValidationException::withMessages(['assigned_room_ids' => 'Select rooms from the current register for this property.']);
        }
    }

    public static function fingerprint(Property $property, Collection $residents): string
    {
        return hash('sha256', json_encode([
            'property' => $property->id, 'active' => (bool) $property->is_active, 'rooms' => $property->total_rooms, 'rental_mode' => $property->rental_mode,
            'register' => $property->room_register,
            'residents' => $residents->map(fn ($tenant) => $tenant->only(self::RESIDENT_FIELDS))->all(),
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
        $tracked = $property->room_register !== null;
        $rooms = collect($property->room_register);
        $roomIds = $rooms->pluck('id')->all();
        $unassigned = $residents->filter(fn ($tenant) => empty($tenant->assigned_room_ids)
            || array_diff($tenant->assigned_room_ids, $roomIds))->count();
        $complete = $tracked && $rooms->count() === $property->total_rooms && $unassigned === 0;
        $occupiedIds = $residents->flatMap(fn ($tenant) => $tenant->assigned_room_ids ?? [])->unique()->flip();
        $vacantRooms = $rooms->reject(fn ($room) => $occupiedIds->has($room['id']) || $room['hold']);

        if ($tracked) {
            $state = $complete ? 'verified' : 'needs_review';
            $verified = $complete;
            $occupied = $complete ? $occupiedIds->count() : null;
            $vacant = $complete ? $vacantRooms->count() : null;
            $unavailable = $complete ? $rooms->count() - $occupied - $vacant : null;
        } else {
            $occupied = $verified ? $verification->occupied : null;
            $vacant = $verified ? $verification->vacant : null;
            $unavailable = $verified ? $verification->unavailable : null;
        }

        return [
            'total' => $property->total_rooms > 0 ? (int) $property->total_rooms : null,
            'verified_total' => $verified ? ($tracked ? $rooms->count() : $verification->total_rooms) : null,
            'households' => $residents->count(), 'unlabelled_households' => $tracked ? $unassigned : $residents->filter(fn ($tenant) => blank($tenant->unit))->count(),
            'occupied' => $occupied,
            'vacant' => $vacant,
            'available_to_let' => $verified && $property->rental_mode === Property::RENTAL_MODE_PER_ROOM ? $vacant : ($verified ? 0 : null),
            'unavailable' => $unavailable,
            'state' => $state, 'installed' => $installed,
            'tracked' => $tracked,
            'observed_on' => $verification?->observed_on,
            'source_ref' => $verification?->source_ref,
            'vacancy_notes' => $complete ? $vacantRooms->pluck('name')->implode(', ') : ($verified ? $verification?->vacancy_notes : null),
            'version' => hash('sha256', $fingerprint.json_encode($verification?->getRawOriginal(), JSON_THROW_ON_ERROR)),
            'today' => now('Pacific/Auckland')->toDateString(),
        ];
    }

    public static function dashboard(): array
    {
        $tracking = self::tenantTrackingAvailable();
        $propertyFields = ['id', 'name', 'code', 'total_rooms', 'is_active', 'rental_mode'];
        $properties = Property::where('is_active', true)->orderBy('code')->orderBy('id')
            ->get($tracking ? [...$propertyFields, 'room_register'] : $propertyFields);
        $rows = self::forProperties($properties, $tracking);
        $today = now('Pacific/Auckland')->toDateString();
        $activeAgreements = AccommodationAgreement::where('status', AccommodationAgreement::STATUS_SIGNED)
            ->where(fn ($q) => $q->whereNull('data->tenancy->commence_date')
                ->orWhere('data->tenancy->commence_date', '')->orWhereDate('data->tenancy->commence_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('data->tenancy->end_date')
                ->orWhere('data->tenancy->end_date', '')->orWhereDate('data->tenancy->end_date', '>=', $today))
            ->whereExists(self::currentTenants($tracking)->select('accommodation_tenants.id')
                ->whereIn('accommodation_tenants.property_id', $properties->pluck('id'))
                ->whereColumn('accommodation_tenants.id', 'accommodation_agreements.tenant_id')
                ->whereColumn('accommodation_tenants.property_id', 'accommodation_agreements.property_id')->toBase())
            ->count();

        return ['properties' => $rows->count(), 'rooms' => $rows->sum('total'), 'households' => $rows->sum('households'),
            'active_agreements' => $activeAgreements,
            'occupied' => $rows->sum('occupied'), 'vacant' => $rows->sum('vacant'), 'unavailable' => $rows->sum('unavailable'),
            'available_to_let' => $rows->sum('available_to_let'),
            'unverified_rooms' => $rows->where('state', '!=', 'verified')->sum('total'),
            'unverified_properties' => $rows->where('state', '!=', 'verified')->count(),
            'missing_capacity' => $rows->whereNull('total')->count(), 'unlabelled_households' => $rows->sum('unlabelled_households'),
            'rows' => $rows->all()];
    }

    public static function forProperties(Collection $properties, ?bool $tracking = null): Collection
    {
        $tracking ??= self::tenantTrackingAvailable();
        $residents = self::currentTenants($tracking)->whereIn('property_id', $properties->pluck('id'))
            ->orderBy('id')->get($tracking ? self::RESIDENT_FIELDS : self::LEGACY_FIELDS)->groupBy('property_id');
        $installed = PropertyRoomVerification::available();
        $checks = $installed ? PropertyRoomVerification::whereIn('property_id', $properties->pluck('id'))->get()->keyBy('property_id') : collect();

        return $properties->map(function ($property) use ($residents, $checks, $installed) {
            $summary = self::summarize($property, $residents->get($property->id, collect()), $checks->get($property->id), false, $installed);

            return ['id' => $property->id, 'name' => $property->name, 'code' => $property->code, 'rental_mode' => $property->rental_mode] + $summary;
        });

    }
}
