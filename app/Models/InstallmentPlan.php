<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "pay later" installment plan for a case/working lead: the agreed weekly
 * schedule (deposit + weekly payments) that staff record against. Figures are
 * snapshotted at generation so the agreed plan is fixed even if a fee changes.
 * Procedural payment tracking — not immigration advice.
 */
class InstallmentPlan extends Model
{
    public const PAYMENT_NOW = 'pay_now';

    public const PAYMENT_LATER = 'pay_later';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'lead_id', 'payment_type', 'plan_months', 'weeks',
        'total_amount', 'deposit_percent', 'deposit_amount', 'inz_fee',
        'financed_amount', 'weekly_amount', 'start_date', 'currency',
        'status', 'created_by',
    ];

    protected $casts = [
        'plan_months' => 'integer',
        'weeks' => 'integer',
        'total_amount' => 'decimal:2',
        'deposit_percent' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'inz_fee' => 'decimal:2',
        'financed_amount' => 'decimal:2',
        'weekly_amount' => 'decimal:2',
        'start_date' => 'date',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /** Schedule rows: deposit first (sequence 0), then the weekly payments. */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('sequence');
    }

    public function totalPaid(): float
    {
        return (float) $this->installments->where('status', Installment::STATUS_PAID)->sum('amount');
    }

    public function amountOwed(): float
    {
        return max(0, (float) $this->total_amount - $this->totalPaid());
    }

    public function isSettled(): bool
    {
        return $this->amountOwed() <= 0.009;
    }
}
