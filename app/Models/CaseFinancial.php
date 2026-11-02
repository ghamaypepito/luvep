<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-case financials (the money side of the immigration dashboard). Fees are
 * entered by a human; every total here is arithmetic on those entries plus the
 * payment ledger — the system generates no figures of its own.
 */
class CaseFinancial extends Model
{
    protected $fillable = [
        'lead_id', 'service_fee_normal', 'service_fee_chargeable', 'inz_fee', 'other_fee',
        'disbursement', 'payment_type', 'inz_fee_paid_to', 'issued_from',
        'invoice_no', 'invoice_sent_at', 'currency', 'notes', 'updated_by',
    ];

    protected $casts = [
        'service_fee_normal' => 'decimal:2',
        'service_fee_chargeable' => 'decimal:2',
        'inz_fee' => 'decimal:2',
        'other_fee' => 'decimal:2',
        'disbursement' => 'decimal:2',
        'invoice_sent_at' => 'date',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Total the client is billed: chargeable service fee + INZ fee + other. */
    public function totalPayable(): float
    {
        return round((float) $this->service_fee_chargeable + (float) $this->inz_fee + (float) $this->other_fee, 2);
    }

    /** Sum of the case's payment ledger. */
    public function totalPaid(): float
    {
        return round((float) CaseFinancePayment::where('lead_id', $this->lead_id)
            ->where(fn ($query) => $query->whereNull('currency')->orWhere('currency', strtoupper($this->currency ?: 'NZD')))
            ->sum('amount'), 2);
    }

    /** Outstanding balance (can be negative if overpaid). */
    public function amountOwed(): float
    {
        return round($this->totalPayable() - $this->totalPaid(), 2);
    }

    /** The pass-through disbursement (defaults to the INZ fee when unset). */
    public function disbursementAmount(): float
    {
        return round($this->disbursement !== null ? (float) $this->disbursement : (float) $this->inz_fee, 2);
    }

    /** Net received after removing the disbursement (ePathways-side revenue). */
    public function netAfterDisbursement(): float
    {
        return round($this->totalPaid() - $this->disbursementAmount(), 2);
    }

    /** Account settled once there's something to pay and nothing is owed. */
    public function isSettled(): bool
    {
        return $this->totalPayable() > 0 && $this->amountOwed() <= 0.005;
    }
}
