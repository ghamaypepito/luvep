<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payment against a receivable or payable (the shared finance ledger).
 */
class FinancePayment extends Model
{
    protected $fillable = [
        'paymentable_type', 'paymentable_id', 'amount', 'paid_on',
        'method', 'reference', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function scopeForLedger(Builder $query, string $model, string $currency): Builder
    {
        return $query->whereHasMorph('paymentable', [$model], fn (Builder $ledger) => $ledger->where('currency', $currency));
    }

    public function paymentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
