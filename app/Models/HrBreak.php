<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrBreak extends Model
{
    protected $fillable = ['hr_shift_id', 'started_at', 'ended_at', 'active_guard'];

    protected $casts = ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];

    public function shift()
    {
        return $this->belongsTo(HrShift::class, 'hr_shift_id');
    }
}
