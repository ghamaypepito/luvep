<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProofReview extends Model
{
    protected $fillable = ['document_id', 'invoice_document_id', 'finance_payment_id', 'supporting'];

    protected $casts = ['supporting' => 'boolean'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LeadDocument::class, 'document_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(LeadDocument::class, 'invoice_document_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CaseFinancePayment::class, 'finance_payment_id');
    }
}
