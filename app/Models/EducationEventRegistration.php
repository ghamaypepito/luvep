<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationEventRegistration extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'event_date' => 'date', 'contact_details' => 'array', 'notified_user_ids' => 'array',
        'consented_at' => 'datetime', 'prepared_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function eventSummary(): string
    {
        return $this->event_name.' · '.$this->event_date->format('j M Y').' · '.date('g:i A', strtotime($this->event_time)).' · '.$this->event_timezone;
    }
}
