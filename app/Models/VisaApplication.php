<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One case Lead's place in a client's chain of visa applications (Visa 1, 2, …).
 * A closed row means that application is locked — the client has moved on to the
 * next visa. Procedural case tracking — not immigration advice.
 */
class VisaApplication extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'lead_id', 'root_lead_id', 'previous_lead_id', 'sequence',
        'visa_type', 'status', 'outcome', 'opened_at', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(VisaApplication::class, 'previous_lead_id', 'lead_id');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
