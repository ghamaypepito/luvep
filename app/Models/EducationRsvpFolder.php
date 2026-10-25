<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationRsvpFolder extends Model
{
    protected $guarded = ['id'];

    public function lists()
    {
        return $this->hasMany(EducationRsvpSavedList::class, 'folder_id')->orderBy('name');
    }
}
