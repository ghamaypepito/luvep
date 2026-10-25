<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationRsvp extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['consented_at' => 'datetime', 'verified_at' => 'datetime', 'linked_at' => 'datetime', 'email_reminders' => 'boolean', 'future_invites' => 'boolean'];

    public function folders()
    {
        return $this->belongsToMany(EducationRsvpFolder::class, 'education_rsvp_folder_members', 'education_rsvp_id', 'folder_id')->withTimestamps()->orderBy('name');
    }

    public function visit()
    {
        return $this->belongsTo(EducationVisit::class, 'education_visit_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
