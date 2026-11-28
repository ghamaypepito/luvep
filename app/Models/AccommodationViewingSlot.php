<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccommodationViewingSlot extends Model
{
    public const TIMEZONE = 'Pacific/Auckland';

    public const TIMES = ['11:00', '12:00', '13:00', '14:00', '15:00', '16:00'];

    protected $fillable = ['property_id', 'property_address', 'viewing_date', 'start_time', 'capacity', 'blocked'];

    protected $casts = ['blocked' => 'boolean', 'capacity' => 'integer', 'property_id' => 'integer'];

    public function attendees(): HasMany
    {
        return $this->hasMany(AccommodationViewingAttendee::class, 'viewing_slot_id');
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->viewing_date.' '.$this->start_time, self::TIMEZONE);
    }
}
