<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EducationVisit extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['event_date' => 'date', 'proposed_date' => 'date', 'is_hidden' => 'boolean', 'schedule_confirmed' => 'boolean', 'schedule_version' => 'integer'];

    public function scopePubliclyVisible(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_hidden', false);
    }

    public function rsvps()
    {
        return $this->hasMany(EducationRsvp::class);
    }

    public function startsAt(): ?Carbon
    {
        return $this->schedule_confirmed && $this->event_date && $this->event_time ? Carbon::parse($this->event_date->format('Y-m-d').' '.$this->event_time, $this->timezone) : null;
    }

    public function summary(): string
    {
        if ($this->status === 'cancelled') {
            return 'Meeting / visit cancelled';
        }if (! $this->schedule_confirmed || ! $this->event_date) {
            return 'Date and time to be confirmed';
        }

        return $this->event_date->format('j F Y').($this->event_time ? ' · '.Carbon::parse($this->event_time)->format('g:i A') : ' · Time to be confirmed').' · '.$this->timezone;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'slug' => $this->slug, 'school' => $this->school, 'kind' => $this->kind, 'city' => $this->city, 'activity' => $this->activity, 'mode' => $this->mode, 'logo' => $this->logo_path, 'date' => $this->schedule_confirmed ? $this->event_date?->format('Y-m-d') : null, 'time' => $this->schedule_confirmed ? $this->event_time : null, 'timezone' => $this->timezone, 'location' => $this->schedule_confirmed ? $this->location : null, 'summary' => $this->summary(), 'schedule_confirmed' => $this->schedule_confirmed];
    }
}
