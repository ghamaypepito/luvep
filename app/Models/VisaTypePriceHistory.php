<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisaTypePriceHistory extends Model
{
    protected $table = 'visa_type_price_history';

    protected $fillable = [
        'visa_type_id',
        'old_price_nzd',
        'new_price_nzd',
        'changed_by_user_id',
        'reason',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'old_price_nzd' => 'decimal:2',
        'new_price_nzd' => 'decimal:2',
    ];

    public function visaType(): BelongsTo
    {
        return $this->belongsTo(VisaType::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
