<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationMoveOutEvent extends Model
{
    protected $table = 'accommodation_move_out_events';

    protected $fillable = ['action', 'actor', 'details'];

    protected $casts = ['details' => 'array'];
}
