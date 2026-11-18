<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationRecipientList extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'lead_ids' => 'array', 'source_refs' => 'array'];
    }
}
