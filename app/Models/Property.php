<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Property extends Model
{
    use LogsActivity;

    protected $table = 'accommodation_properties';

    /** Property-type options (whole-dwelling classification, internal). */
    public const PROPERTY_TYPES = ['House', 'Apartment', 'Townhouse', 'Studio', 'Room'];

    /**
     * How this listing is rented. Drives which fields the form + public
     * page use — per_room shows rent_single/rent_couple + room/bed/bath
     * type; whole_property shows whole_property_rent_weekly + bedrooms +
     * bathrooms. Bond + advance stay visible in both modes.
     */
    public const RENTAL_MODE_PER_ROOM = 'per_room';

    public const RENTAL_MODE_WHOLE = 'whole_property';

    public const RENTAL_MODES = [self::RENTAL_MODE_PER_ROOM, self::RENTAL_MODE_WHOLE];

    /** Owner/PM payout cadences. */
    public const PAYMENT_SCHEDULES = [
        'Every Monday', 'Every Tuesday', 'Every Wednesday', 'Every Thursday',
        'Every Friday', 'Every Saturday', 'Every Sunday', 'Fortnightly',
        'Fortnight Friday', 'Auto Monday', 'Manual',
    ];

    /**
     * Internal department-only columns. Kept out of default serialization via
     * $hidden so the public /accommodation pages never receive them; the portal
     * controller re-exposes them with makeVisible(self::MANAGEMENT_FIELDS).
     */
    public const MANAGEMENT_FIELDS = [
        'code', 'address', 'city', 'region', 'property_type', 'total_rooms',
        'mercury_account_number', 'mercury_account_holder', 'property_icp',
        'house_code', 'internet_passcode', 'bond_total_nzd', 'advance_total_nzd',
        'property_manager_name', 'property_manager_phone', 'property_manager_email',
        'pm_payment_schedule', 'power_due_date', 'water_due_date', 'internet_due_date',
        'last_gas_purchase', 'uses_bottled_gas', 'is_active', 'notes',
    ];

    protected $fillable = [
        'name', 'slug', 'location', 'suburb', 'room_type', 'has_wardrobe', 'bed_type',
        'bathroom_type', 'includes', 'rent_single', 'rent_couple',
        'rental_mode', 'whole_property_rent_weekly', 'bedrooms', 'bathrooms', 'rooms_layout',
        'bills_excluded', 'description', 'map_url', 'status',
        // Internal management fields
        'code', 'address', 'city', 'region', 'property_type', 'total_rooms',
        'mercury_account_number', 'mercury_account_holder', 'property_icp',
        'house_code', 'internet_passcode', 'bond_total_nzd', 'advance_total_nzd',
        'property_manager_name', 'property_manager_phone', 'property_manager_email',
        'pm_payment_schedule', 'power_due_date', 'water_due_date', 'internet_due_date',
        'last_gas_purchase', 'uses_bottled_gas', 'is_active', 'notes',
    ];

    protected $casts = [
        'has_wardrobe' => 'boolean',
        'bills_excluded' => 'boolean',
        'rent_single' => 'decimal:2',
        'rent_couple' => 'decimal:2',
        'whole_property_rent_weekly' => 'decimal:2',
        'bedrooms' => 'integer',
        'bathrooms' => 'integer',
        'rooms_layout' => 'array',
        'total_rooms' => 'integer',
        'bond_total_nzd' => 'decimal:2',
        'advance_total_nzd' => 'decimal:2',
        'uses_bottled_gas' => 'boolean',
        'is_active' => 'boolean',
        'power_due_date' => 'date',
        'water_due_date' => 'date',
        'internet_due_date' => 'date',
        'last_gas_purchase' => 'date',
    ];

    // Hide internal fields + computed occupancy from public serialization by
    // default. The portal calls makeVisible() to surface them for staff.
    protected $hidden = [
        'code', 'address', 'city', 'region', 'property_type', 'total_rooms',
        'mercury_account_number', 'mercury_account_holder', 'property_icp',
        'house_code', 'internet_passcode', 'bond_total_nzd', 'advance_total_nzd',
        'property_manager_name', 'property_manager_phone', 'property_manager_email',
        'pm_payment_schedule', 'power_due_date', 'water_due_date', 'internet_due_date',
        'last_gas_purchase', 'uses_bottled_gas', 'is_active', 'notes',
        'rooms_occupied', 'occupancy_status',
    ];

    protected $appends = ['cover_image', 'rooms_occupied', 'occupancy_status'];

    protected static function booted(): void
    {
        // Auto-generate a unique URL slug from the name when one isn't set.
        static::saving(function (Property $property) {
            if (empty($property->slug) && ! empty($property->name)) {
                $property->slug = static::generateUniqueSlug($property->name, $property->id);
            }
        });

        // Block deletion while tenants still live here — they must be vacated
        // (or moved) first. Mirrors the controller guard for defence in depth.
        static::deleting(function (Property $property) {
            if ($property->activeTenants()->exists()) {
                throw new \RuntimeException('Cannot delete a property with active tenants. All tenants must be vacated first.');
            }

            // Only historical (vacated / soft-deleted) tenant rows remain at
            // this point. Hard-remove them so the restrict FK doesn't block the
            // property delete — deleting the property discards its tenant history.
            $property->tenants()->withTrashed()->forceDelete();

            // Delete each image via Eloquent so its file is cleaned up (the DB
            // cascade alone would not fire PropertyImage's deleting event).
            $property->images()->get()->each->delete();
        });
    }

    protected static function generateUniqueSlug(string $name, $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'property';
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderBy('sort_order');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'property_id');
    }

    /** Tenants currently in residence (active / notice_given / vacating). */
    public function activeTenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'property_id')
            ->whereIn('current_status', Tenant::ACTIVE_STATUSES);
    }

    public function getCoverImageAttribute(): ?string
    {
        // Only use the eager-loaded relation; never fire a lazy query here,
        // otherwise serializing a list of properties would cause N+1.
        // Controllers that need the cover image must Property::with('images').
        if (! $this->relationLoaded('images')) {
            return null;
        }

        return optional($this->images->first())->url;
    }

    /**
     * Rooms currently occupied — count of active tenants. Prefers a
     * withCount('activeTenants') aggregate (list views) or an eager-loaded
     * relation, falling back to a direct count so single records are correct
     * without forcing every caller to eager-load.
     */
    public function getRoomsOccupiedAttribute(): int
    {
        if (array_key_exists('active_tenants_count', $this->attributes)) {
            return (int) $this->attributes['active_tenants_count'];
        }

        if ($this->relationLoaded('activeTenants')) {
            return $this->activeTenants->count();
        }

        if ($this->relationLoaded('tenants')) {
            return $this->tenants->whereIn('current_status', Tenant::ACTIVE_STATUSES)->count();
        }

        return $this->exists ? $this->activeTenants()->count() : 0;
    }

    /** vacant | partial | full — derived from total_rooms vs rooms_occupied. */
    public function getOccupancyStatusAttribute(): string
    {
        $occupied = $this->rooms_occupied;

        if ($occupied <= 0) {
            return 'vacant';
        }

        if ($this->total_rooms && $occupied >= $this->total_rooms) {
            return 'full';
        }

        return 'partial';
    }
}
