<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationRsvp extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['consented_at' => 'datetime', 'verified_at' => 'datetime', 'linked_at' => 'datetime', 'email_reminders' => 'boolean', 'future_invites' => 'boolean', 'email_follow_up' => 'boolean', 'email_newsletter' => 'boolean', 'legacy_event_follow_up' => 'boolean'];

    public function answersRevision(): string
    {
        return hash('sha256', json_encode($this->only(['full_name', 'email', 'phone', 'role', 'email_reminders', 'future_invites', 'email_follow_up', 'email_newsletter', 'legacy_event_follow_up', 'public_id', 'verified_at', 'lead_id'])));
    }

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
