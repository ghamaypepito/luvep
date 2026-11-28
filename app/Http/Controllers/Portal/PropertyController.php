<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\Tenant;
use App\Services\Accommodation\RoomInventory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PropertyController extends Controller
{
    /** Internal fields + computed occupancy, surfaced only inside the portal. */
    private const VISIBLE_IN_PORTAL = [...Property::MANAGEMENT_FIELDS, 'rooms_occupied', 'occupancy_status'];

    private function rules(?Property $property = null): array
    {
        // Rental mode decides which listing fields are required. Per-room mode
        // needs room/bed/bath + rent_single; whole-property mode needs the
        // whole_property_rent_weekly + bedrooms + bathrooms. The other set is
        // still accepted (kept for later mode switch) but only validated when
        // relevant to avoid blocking the user with irrelevant errors.
        $mode = request('rental_mode', Property::RENTAL_MODE_PER_ROOM);
        $isPerRoom = $mode === Property::RENTAL_MODE_PER_ROOM;
        $isWhole = $mode === Property::RENTAL_MODE_WHOLE;

        return [
            // Public listing fields
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'suburb' => ['nullable', Rule::in(['Hobsonville', 'Glenfield', 'Kelston', 'Hillsborough', 'Sunnynook'])],
            'rental_mode' => ['required', Rule::in(Property::RENTAL_MODES)],
            // Per-room fields
            'room_type' => [$isPerRoom ? 'required' : 'nullable', Rule::in(['single', 'couple', 'ensuite'])],
            'has_wardrobe' => 'boolean',
            'bed_type' => [$isPerRoom ? 'required' : 'nullable', Rule::in(['single', 'double'])],
            'bathroom_type' => [$isPerRoom ? 'required' : 'nullable', Rule::in(['shared', 'private'])],
            'includes' => 'nullable|string',
            'rent_single' => [$isPerRoom ? 'required' : 'nullable', 'numeric', 'min:0'],
            'rent_couple' => 'nullable|numeric|min:0',
            'bills_excluded' => 'boolean',
            // Whole-property fields
            'whole_property_rent_weekly' => [$isWhole ? 'required' : 'nullable', 'numeric', 'min:0'],
            'bedrooms' => [$isWhole ? 'required' : 'nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => [$isWhole ? 'required' : 'nullable', 'integer', 'min:0', 'max:50'],
            'rooms_layout' => 'nullable|array|max:30',
            'rooms_layout.*.name' => 'nullable|string|max:80',
            'rooms_layout.*.type' => 'nullable|string|max:40',
            'rooms_layout.*.bed' => 'nullable|string|max:40',
            'rooms_layout.*.ensuite' => 'nullable|string|max:20',
            'rooms_layout.*.notes' => 'nullable|string|max:255',
            // Shared
            'map_url' => 'nullable|string|max:2000',
            'description' => 'nullable|string',
            'status' => ['required', Rule::in(['available', 'unavailable'])],
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp,gif|max:4096',

            // Internal management fields
            'code' => ['nullable', 'string', 'max:50', Rule::unique('accommodation_properties', 'code')->ignore($property?->id)],
            'address' => 'nullable|string|max:200',
            'city' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'property_type' => ['nullable', Rule::in(Property::PROPERTY_TYPES)],
            'total_rooms' => 'nullable|integer|min:1|max:50',
            'room_register_version' => 'sometimes|nullable|string|size:64',
            'room_register' => 'sometimes|array|list|max:50',
            'room_register.*' => 'array:id,name,hold',
            'room_register.*.id' => 'nullable|uuid|distinct',
            'room_register.*.name' => 'required|string|max:100',
            'room_register.*.hold' => 'required|boolean',
            'mercury_account_number' => 'nullable|string|max:100',
            'mercury_account_holder' => 'nullable|string|max:255',
            'property_icp' => 'nullable|string|max:100',
            'house_code' => 'nullable|string|max:100',
            'internet_passcode' => 'nullable|string|max:100',
            'bond_total_nzd' => 'nullable|numeric|min:0',
            'advance_total_nzd' => 'nullable|numeric|min:0',
            'property_manager_name' => 'nullable|string|max:255',
            'property_manager_phone' => 'nullable|string|max:50',
            'property_manager_email' => 'nullable|email|max:255',
            'property_owner_name' => 'nullable|string|max:255',
            'property_owner_email' => 'nullable|email|max:255',
            'pm_payment_schedule' => ['nullable', Rule::in(Property::PAYMENT_SCHEDULES)],
            'power_due_date' => 'nullable|date',
            'water_due_date' => 'nullable|date',
            'internet_due_date' => 'nullable|date',
            'last_gas_purchase' => 'nullable|date',
            'uses_bottled_gas' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }

    public function index(Request $request)
    {
        $search = $request->query('search');
        $active = $request->query('active', 'active');   // active | archived | all
        $type = $request->query('property_type');
        $city = $request->query('city');
        $sort = $request->query('sort', 'code');         // code | address | latest

        $properties = Property::with('images')
            ->withCount('activeTenants')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('suburb', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")
                ->orWhere('property_manager_name', 'like', "%{$search}%")))
            ->when($active === 'active', fn ($q) => $q->where('is_active', true))
            ->when($active === 'archived', fn ($q) => $q->where('is_active', false))
            ->when(in_array($type, Property::PROPERTY_TYPES, true), fn ($q) => $q->where('property_type', $type))
            ->when($city, fn ($q) => $q->where('city', $city))
            ->when($request->boolean('has_tenants'), fn ($q) => $q->whereHas('activeTenants'))
            ->when($sort === 'address', fn ($q) => $q->orderBy('address'))
            ->when($sort === 'latest', fn ($q) => $q->latest())
            // Default: numeric-aware sort by code, nulls last.
            ->when(! in_array($sort, ['address', 'latest'], true), fn ($q) => $q
                ->inPortfolioOrder())
            ->paginate(15)
            ->withQueryString();
        $summaries = \App\Services\Accommodation\RoomInventory::forProperties($properties->getCollection())->keyBy('id');
        $properties->through(function (Property $p) use ($summaries) {
            $p->makeVisible(self::VISIBLE_IN_PORTAL);
            $p->room_summary = $summaries->get($p->id);
            return $p;
        });

        return inertia('portal/accommodation/Properties', [
            'properties' => $properties,
            'filters' => [
                'search' => $search,
                'active' => $active,
                'property_type' => $type,
                'city' => $city,
                'sort' => $sort,
                'has_tenants' => $request->boolean('has_tenants'),
            ],
            'options' => [
                'property_types' => Property::PROPERTY_TYPES,
                'cities' => Property::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            ],
        ]);
    }

    public function show(Property $property)
    {
        $property->load('images')->loadCount('activeTenants')->makeVisible(self::VISIBLE_IN_PORTAL);

        $tenants = $property->tenants()->orderByRaw('contract_end IS NULL, contract_end ASC')->get();

        return inertia('portal/accommodation/PropertyDetail', [
            'property' => $property,
            'tenants' => $tenants->whereIn('current_status', Tenant::ACTIVE_STATUSES)->values(),
            'historicalTenants' => $tenants->where('current_status', 'vacated')->values(),
            'roomSummary' => \App\Services\Accommodation\RoomInventory::summarize($property),
            'canVerifyRooms' => request()->user()?->is_active && (request()->user()->isAdmin() || request()->user()->role === 'accommodation')
                && ! request()->attributes->has('user_view_actor') && $property->is_active,
            'activity' => [],
        ]);
    }

    public function create()
    {
        return inertia('portal/accommodation/PropertyForm', [
            'property' => null,
            'options' => $this->formOptions(),
            'next_code' => $this->nextCode(),
        ]);
    }

    public function store(Request $request)
    {
        $property = new Property;
        $this->saveProperty($request, $property, $this->cleanData($request));
        $this->storeImages($property, $request->file('images', []));

        return redirect()->route('portal.accommodation.properties.index')
            ->with('success', 'Property created successfully.');
    }

    public function edit(Property $property)
    {
        $property->load('images')->makeVisible(self::VISIBLE_IN_PORTAL);

        return inertia('portal/accommodation/PropertyForm', [
            'property' => $property,
            'room_register' => $property->room_register ?? [],
            'room_register_version' => $this->roomRegisterVersion($property),
            'options' => $this->formOptions(),
            'next_code' => null,
        ]);
    }

    public function update(Request $request, Property $property)
    {
        $data = $this->cleanData($request, $property);
        DB::transaction(function () use ($request, $property, $data) {
            $locked = Property::whereKey($property->id)->lockForUpdate()->firstOrFail();
            $this->saveProperty($request, $locked, $data);
        });
        $this->storeImages($property, $request->file('images', []));

        return redirect()->route('portal.accommodation.properties.show', $property)
            ->with('success', 'Property updated successfully.');
    }

    private function roomRegisterVersion(Property $property): string
    {
        return hash('sha256', json_encode([$property->total_rooms, $property->room_register], JSON_THROW_ON_ERROR));
    }

    private function saveProperty(Request $request, Property $property, array $data): void
    {
        if (array_key_exists('room_register_version', $data) || array_key_exists('room_register', $data)) {
            RoomInventory::authorizeStaff($request);
            abort_unless(RoomInventory::tenantTrackingAvailable(), 503, 'Room tracking is being installed. Try again shortly.');
            if ($property->exists && ! hash_equals($this->roomRegisterVersion($property), $data['room_register_version'] ?? '')) {
                throw ValidationException::withMessages(['room_register' => 'The property room register changed. Reload before editing it.']);
            }

            $existingIds = array_column($property->room_register ?? [], 'id');
            $rooms = collect($data['room_register'] ?? [])->map(function ($row) use ($existingIds) {
                if (! empty($row['id']) && ! in_array($row['id'], $existingIds, true)) {
                    throw ValidationException::withMessages(['room_register' => 'An existing room ID is unknown. Reload the property and try again.']);
                }

                return ['id' => $row['id'] ?? (string) Str::uuid(), 'name' => trim($row['name']), 'hold' => (bool) $row['hold']];
            });
            if ($rooms->pluck('name')->map(fn ($name) => mb_strtolower($name))->unique()->count() !== $rooms->count()) {
                throw ValidationException::withMessages(['room_register' => 'Give each physical room a distinct name.']);
            }
            $removed = array_diff($existingIds, $rooms->pluck('id')->all());
            if ($removed && Tenant::where('property_id', $property->id)
                ->whereIn('current_status', [...Tenant::ACTIVE_STATUSES, 'pending_payment'])
                ->get(['assigned_room_ids'])->contains(fn ($tenant) => array_intersect($removed, $tenant->assigned_room_ids ?? []))) {
                throw ValidationException::withMessages(['room_register' => 'Reassign the affected tenancies before removing a room.']);
            }
            $property->room_register = $rooms->all();
        }

        $property->fill($data);
        if ($property->exists && $property->getOriginal('is_active') && ! $property->is_active && $property->activeTenants()->exists()) {
            throw ValidationException::withMessages(['is_active' => 'Vacate or move the active tenants before archiving this property.']);
        }
        if (count($property->room_register ?? []) > $property->total_rooms) {
            throw ValidationException::withMessages(['total_rooms' => 'Total rooms must cover every room in the room register.']);
        }
        $property->save();
    }

    /** Archive (soft remove from portfolio) rather than delete. */
    public function archive(Property $property)
    {
        $archived = DB::transaction(function () use ($property) {
            $locked = Property::whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($locked->activeTenants()->exists()) {
                return false;
            }

            return $locked->update(['is_active' => false]);
        });
        if (! $archived) {
            return redirect()->back()
                ->with('error', 'Cannot archive a property with active tenants. Vacate or move them first.');
        }

        return redirect()->back()->with('success', 'Property archived.');
    }

    public function destroy(Property $property)
    {
        if (\App\Models\AccommodationAgreement::where('property_id', $property->id)->exists()) {
            return back()->with('error', 'This property has issued agreements. Archive it to preserve those records.');
        }
        // A property with active tenants cannot be removed — they must be
        // vacated/moved first. (The model enforces this too, for safety.)
        if ($property->activeTenants()->exists()) {
            return redirect()->back()
                ->with('error', 'Cannot delete a property with active tenants. Vacate or move them first.');
        }

        try {
            DB::transaction(fn () => $property->delete());
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            if ($property->hasPurchaseInvoiceHistory()) {
                return redirect()->back()->with('error', Property::PURCHASE_INVOICE_DELETE_ERROR);
            }
            if ($property->hasWeeklyFinanceHistory()) {
                return redirect()->back()->with('error', Property::WEEKLY_FINANCE_DELETE_ERROR);
            }
            throw $e;
        }

        return redirect()->route('portal.accommodation.properties.index')
            ->with('success', 'Property deleted successfully.');
    }

    public function destroyImage(Property $property, PropertyImage $image)
    {
        abort_unless($image->property_id === $property->id, 404);
        $image->delete(); // also removes the file via PropertyImage::booted() deleting event

        return redirect()->back()->with('success', 'Image removed.');
    }

    /** Stream all properties (management columns) as a CSV. */
    public function export()
    {
        $columns = [
            'code', 'name', 'address', 'city', 'region', 'property_type', 'total_rooms',
            'property_manager_name', 'property_manager_phone', 'property_manager_email',
            'pm_payment_schedule', 'bond_total_nzd', 'advance_total_nzd',
            'mercury_account_number', 'mercury_account_holder', 'property_icp',
            'house_code', 'internet_passcode', 'uses_bottled_gas', 'last_gas_purchase',
            'power_due_date', 'water_due_date', 'internet_due_date', 'is_active',
        ];

        return Response::streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            Property::query()->orderByRaw('CAST(code AS UNSIGNED)')->chunk(200, function ($chunk) use ($out, $columns) {
                foreach ($chunk as $p) {
                    fputcsv($out, array_map(fn ($c) => $this->csvValue($p->{$c}), $columns));
                }
            });

            fclose($out);
        }, 'properties.csv', ['Content-Type' => 'text/csv']);
    }

    private function csvValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) ($value ?? '');
    }

    /** Validated scalar fields with booleans normalized. */
    private function cleanData(Request $request, ?Property $property = null): array
    {
        $data = $request->validate($this->rules($property));
        unset($data['images']);

        $data['has_wardrobe'] = $request->boolean('has_wardrobe');
        $data['bills_excluded'] = $request->boolean('bills_excluded');
        $data['uses_bottled_gas'] = $request->boolean('uses_bottled_gas');

        // Drop rows the user left completely blank; store as null when the
        // whole array is empty (or when mode is per_room) so the JSON column
        // stays clean.
        if (isset($data['rooms_layout']) && is_array($data['rooms_layout'])) {
            $rows = collect($data['rooms_layout'])
                ->map(fn ($row) => array_map('trim', array_map(fn ($v) => (string) ($v ?? ''), $row)))
                ->filter(fn ($row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty())
                ->values()
                ->all();
            $data['rooms_layout'] = ($rows === [] || ($data['rental_mode'] ?? null) !== Property::RENTAL_MODE_WHOLE) ? null : $rows;
        }
        // Default new records to active; respect the toggle when present.
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $data;
    }

    /** Store each uploaded file and append it as an ordered image row. */
    private function storeImages(Property $property, array $images): void
    {
        $next = (int) $property->images()->max('sort_order');

        foreach ($images as $file) {
            $property->images()->create([
                'path' => $file->store('accommodation/properties', 'public'),
                'sort_order' => ++$next,
            ]);
        }
    }

    private function formOptions(): array
    {
        return [
            'property_types' => Property::PROPERTY_TYPES,
            'payment_schedules' => Property::PAYMENT_SCHEDULES,
        ];
    }

    /** Suggest the next numeric code (max numeric code + 1). */
    private function nextCode(): string
    {
        $max = (int) Property::query()->whereNotNull('code')->max(DB::raw('CAST(code AS UNSIGNED)'));

        return (string) ($max + 1);
    }
}
