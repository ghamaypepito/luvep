<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationOutreach extends Model
{
    protected $table = 'education_outreach';

    protected $guarded = ['id'];

    protected $casts = ['schedule_version' => 'integer', 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function rsvp()
    {
        return $this->belongsTo(EducationRsvp::class, 'education_rsvp_id');
    }

    public function registration()
    {
        return $this->belongsTo(EducationEventRegistration::class, 'education_registration_id');
    }
}
