<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationSchoolMailing extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['filters' => 'array', 'template_snapshot' => 'array'];

    public function recipients()
    {
        return $this->hasMany(EducationSchoolMailRecipient::class, 'mailing_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
