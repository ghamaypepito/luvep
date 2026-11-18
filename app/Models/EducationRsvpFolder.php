<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationRsvpFolder extends Model
{
    protected $guarded = ['id'];

    public function rsvps()
    {
        return $this->belongsToMany(EducationRsvp::class, 'education_rsvp_folder_members', 'folder_id', 'education_rsvp_id')->withTimestamps();
    }

    public function lists()
    {
        return $this->hasMany(EducationRsvpSavedList::class, 'folder_id')->orderBy('name');
    }
}
