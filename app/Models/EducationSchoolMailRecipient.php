<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationSchoolMailRecipient extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['source_refs' => 'array'];

    public function mailing()
    {
        return $this->belongsTo(EducationSchoolMailing::class, 'mailing_id');
    }
}
