<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Pre-screening record for one Visa Assessment entry. Carries the private token
 * for the /application/{token} client link and the vetting state (two
 * consultation bookings, consent + audio, Eligible/Converted/Declined/Skip).
 *
 * Procedural vetting/compliance tracking — not immigration advice.
 */
class PreScreening extends Model
{
    use LogsActivity;

    public const STATUS_NEW = 'new';

    public const STATUS_CONSULTATION = 'consultation';

    public const STATUS_CONSENT = 'consent';

    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'token', 'subject_type', 'subject_id', 'lead_id', 'status',
        'consult_booking_id', 'consent_booking_id',
        'consent_sent_at', 'consent_ack_at',
        'eligible_at', 'eligible_by',
        'converted_at', 'converted_by', 'converted_lead_id',
        'declined_at', 'declined_by', 'decline_reason',
        'skip_at', 'skip_by', 'skip_reason',
        'created_by',
    ];

    protected $casts = [
        'consent_sent_at' => 'datetime',
        'consent_ack_at' => 'datetime',
        'eligible_at' => 'datetime',
        'converted_at' => 'datetime',
        'declined_at' => 'datetime',
        'skip_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pre) {
            if (empty($pre->token)) {
                $pre->token = self::newToken();
            }
        });
    }

    public static function newToken(): string
    {
        do {
            $token = Str::lower(Str::random(40));
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** The "working lead" holding the client's uploads before conversion. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function consultBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'consult_booking_id');
    }

    public function consentBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'consent_booking_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_CONVERTED, self::STATUS_DECLINED], true);
    }

    public function isSkipped(): bool
    {
        return $this->skip_at !== null;
    }

    public function consentAcknowledged(): bool
    {
        return $this->consent_ack_at !== null;
    }
}
