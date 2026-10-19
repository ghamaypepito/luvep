<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrEquipment extends Model
{
    protected $table = 'hr_equipment';

    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer', 'issued_at' => 'datetime', 'returned_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }
}
