<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationDtrTask extends Model
{
    protected $fillable = ['title', 'status', 'assignee_id', 'completed_by', 'completed_by_name', 'completed_at', 'version'];

    protected $casts = ['completed_at' => 'datetime', 'version' => 'integer'];
}
