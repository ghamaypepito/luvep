<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DtrEntry extends Model
{
    protected $fillable = [
        'user_id', 'work_date', 'time_in', 'time_out', 'tasks', 'remarks',
    ];

    protected $casts = [
        'work_date' => 'date',
        'tasks' => 'array',
        'hr_version' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
