<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Tenant extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'accommodation_tenants';

    /** current_status values that count a tenant as in-residence. */
    public const ACTIVE_STATUSES = ['active', 'notice_given', 'vacating'];

    public const CONTRACT_TYPES = ['fixed_term', 'periodic', 'open', 'not_yet_defined'];

    public const STATUSES = ['active', 'notice_given', 'vacating', 'vacated', 'breached'];

    /** Window (days) before contract_end that flags a tenancy as "ending soon". */
    public const ENDING_SOON_DAYS = 25;

    protected $fillable = [
        'property_id', 'unit', 'first_name', 'family_name', 'display_name_override',
        'email', 'phone', 'whatsapp', 'nationality', 'date_of_birth', 'passport_number',
        'contract_start', 'contract_end', 'contract_type', 'open_contract_notice_weeks',
        'bond_paid_nzd', 'advance_paid_nzd', 'weekly_rent_nzd', 'weekly_utilities_nzd',
        'has_passport_in_drive', 'has_tenancy_agreement_in_drive', 'has_inspection_report_in_drive',
        'current_status', 'converted_from_viewer_id', 'moved_to_property_id', 'notes',
    ];

    protected $casts = [
        'contract_start' => 'date',
        'contract_end' => 'date',
        'date_of_birth' => 'date',
        'ended_at' => 'datetime',
        'bond_paid_nzd' => 'decimal:2',
        'advance_paid_nzd' => 'decimal:2',
        'weekly_rent_nzd' => 'decimal:2',
        'weekly_utilities_nzd' => 'decimal:2',
        'has_passport_in_drive' => 'boolean',
        'has_tenancy_agreement_in_drive' => 'boolean',
        'has_inspection_report_in_drive' => 'boolean',
        'passport_number' => 'encrypted',
    ];

    protected $appends = ['display_name', 'weekly_total_due', 'days_to_end', 'contract_status'];

    // ---- Relationships ----------------------------------------------------

    public function documents(): HasMany
    {
        return $this->hasMany(TenantDocument::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function movedToProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'moved_to_property_id');
    }

    public function rentPayments(): HasMany
    {
        return $this->hasMany(RentPayment::class);
    }

    // Viewer model does not exist yet — converted_from_viewer_id is a nullable
    // placeholder wired up once the Viewers/Onboarding module is built.

    // ---- Computed accessors ----------------------------------------------

    public function getDisplayNameAttribute(): string
    {
        return $this->display_name_override ?: trim("{$this->first_name} {$this->family_name}");
    }

    public function getWeeklyTotalDueAttribute(): string
    {
        return number_format((float) $this->weekly_rent_nzd + (float) $this->weekly_utilities_nzd, 2, '.', '');
    }

    /** Whole days until contract_end. Null if no end date; negative once ended. */
    public function getDaysToEndAttribute(): ?int
    {
        if (! $this->contract_end) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->contract_end->copy()->startOfDay(), false);
    }

    /** open | no_dates | ended | ending_soon | active */
    public function getContractStatusAttribute(): string
    {
        if ($this->contract_type === 'open') {
            return 'open';
        }

        if (! $this->contract_start && ! $this->contract_end) {
            return 'no_dates';
        }

        $days = $this->days_to_end;

        if ($days !== null && $days <= 0) {
            return 'ended';
        }

        if ($days !== null && $days >= 1 && $days <= self::ENDING_SOON_DAYS) {
            return 'ending_soon';
        }

        return 'active';
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->whereIn('current_status', self::ACTIVE_STATUSES);
    }

    public function scopeVacated($query)
    {
        return $query->where('current_status', 'vacated');
    }

    public function scopeEndingSoon($query)
    {
        return $query->whereNotNull('contract_end')
            ->whereBetween('contract_end', [Carbon::today(), Carbon::today()->addDays(self::ENDING_SOON_DAYS)]);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('contract_end')
            ->whereDate('contract_end', '<', Carbon::today())
            ->where('current_status', '!=', 'vacated');
    }

    public function scopeWithMissingDocs($query)
    {
        return $query->where(fn ($q) => $q
            ->where('has_passport_in_drive', false)
            ->orWhere('has_tenancy_agreement_in_drive', false)
            ->orWhere('has_inspection_report_in_drive', false));
    }
}
