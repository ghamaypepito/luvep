<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrWeeklySignoff extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revisions' => 'array', 'signed_at' => 'datetime', 'reviewed_at' => 'datetime', 'version' => 'integer'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }
}
