<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead under onboarding checks, before it becomes an official immigration
 * case. Holds the two required consultations, the "key matters" audio
 * acknowledgment, the initial written agreement, the gate override and the
 * promotion/decline record. See App\Services\Immigration\PotentialCaseService.
 */
class PotentialCase extends Model
{
    use LogsActivity;

    public const STATUS_ONBOARDING = 'onboarding';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'lead_id', 'status', 'meetings',
        'audio_ack_at', 'audio_ack_by',
        'agreement_sent_at', 'agreement_signed_at',
        'override_by', 'override_reason', 'override_at',
        'promoted_by', 'promoted_by_system', 'promoted_at',
        'declined_by', 'decline_reason', 'declined_at',
        'created_by',
    ];

    protected $casts = [
        'meetings' => 'array',
        'audio_ack_at' => 'datetime',
        'agreement_sent_at' => 'datetime',
        'agreement_signed_at' => 'datetime',
        'override_at' => 'datetime',
        'promoted_by_system' => 'boolean',
        'promoted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by');
    }

    /** One meeting's stored payload, or null. $no is 1 or 2. */
    public function meeting(int $no): ?array
    {
        return $this->meetings[$no] ?? $this->meetings[(string) $no] ?? null;
    }

    public function meetingsDone(): bool
    {
        return $this->meeting(1) !== null && $this->meeting(2) !== null;
    }

    public function audioAcknowledged(): bool
    {
        return $this->audio_ack_at !== null;
    }

    public function isOverridden(): bool
    {
        return $this->override_at !== null;
    }

    /**
     * The compliance gate on signing the written agreement: both consultations
     * logged AND the key-matters audio acknowledged — unless a licensed adviser
     * has overridden it with a reason.
     */
    public function gatePassed(): bool
    {
        return $this->isOverridden() || ($this->meetingsDone() && $this->audioAcknowledged());
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, [self::STATUS_PROMOTED, self::STATUS_DECLINED], true);
    }
}
