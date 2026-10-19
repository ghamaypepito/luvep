<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrRequest extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 0, 'status' => 'open'];

    protected $casts = ['version' => 'integer', 'subject' => 'encrypted'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function messages()
    {
        return $this->hasMany(HrRequestMessage::class);
    }
}
