<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrShift extends Model
{
    protected $fillable = ['hr_employee_id', 'work_date', 'timezone', 'started_at', 'ended_at', 'scheduled_end_at', 'schedule_snapshot', 'active_guard', 'reminder_claimed_at'];

    protected $casts = ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime', 'reminder_claimed_at' => 'immutable_datetime', 'schedule_snapshot' => 'array'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function breaks()
    {
        return $this->hasMany(HrBreak::class);
    }
}
