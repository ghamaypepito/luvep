<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrMilestone extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['private_note'];

    protected $attributes = ['version' => 0, 'deadline_revision' => 1];

    protected $casts = ['version' => 'integer', 'deadline_revision' => 'integer', 'due_date' => 'date', 'employee_visible' => 'boolean', 'private_note' => 'encrypted', 'completed_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
