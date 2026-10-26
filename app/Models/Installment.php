<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled payment of an installment plan — the upfront deposit
 * (sequence 0) or a weekly payment (sequence 1..n). Staff mark it paid, which
 * links the CaseFinancePayment receipt it created. "Overdue" is derived.
 */
class Installment extends Model
{
    public const KIND_DEPOSIT = 'deposit';

    public const KIND_WEEKLY = 'weekly';

    public const STATUS_DUE = 'due';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'installment_plan_id', 'sequence', 'kind', 'due_date', 'amount',
        'status', 'paid_at', 'finance_payment_id',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }

    public function financePayment(): BelongsTo
    {
        return $this->belongsTo(CaseFinancePayment::class, 'finance_payment_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Not paid and the due date is strictly before today (due today is not overdue). */
    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date !== null && $this->due_date->lt(Carbon::today());
    }
}
