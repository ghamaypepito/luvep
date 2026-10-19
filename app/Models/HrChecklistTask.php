<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrChecklistTask extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['private_note'];

    protected $casts = ['version' => 'integer', 'employee_visible' => 'boolean', 'private_note' => 'encrypted', 'due_date' => 'date', 'completed_at' => 'datetime'];

    public function checklist()
    {
        return $this->belongsTo(HrChecklist::class, 'hr_checklist_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
