<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationViewingAttendee extends Model
{
    public const CONFIRMED = 'Confirmed';

    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::CONFIRMED, 'Completed', self::CANCELLED];

    protected $fillable = ['name', 'email', 'mobile', 'status', 'attendance_response', 'attendance_responded_at'];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AccommodationViewingSlot::class, 'viewing_slot_id');
    }

    public function contactDetails(): array
    {
        return $this->only(['id', 'name', 'email', 'mobile', 'status', 'attendance_response']);
    }
}
