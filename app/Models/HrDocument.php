<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['archived_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function versions()
    {
        return $this->hasMany(HrDocumentVersion::class);
    }
}
