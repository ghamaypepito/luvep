<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrChecklist extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['template_snapshot'];

    protected $casts = ['template_snapshot' => 'encrypted:array', 'due_date' => 'date', 'completed_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function tasks()
    {
        return $this->hasMany(HrChecklistTask::class);
    }
}
