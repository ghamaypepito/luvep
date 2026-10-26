<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationRsvpSavedList extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    public function folder()
    {
        return $this->belongsTo(EducationRsvpFolder::class, 'folder_id');
    }
}
