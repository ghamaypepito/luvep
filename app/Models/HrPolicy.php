<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrPolicy extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['draft_attachment'];

    protected $attributes = ['revision' => 0];

    protected $casts = ['revision' => 'integer', 'draft_attachment' => 'array'];

    public function versions()
    {
        return $this->hasMany(HrPolicyVersion::class);
    }
}
