<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrPolicy extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['revision' => 0];

    protected $casts = ['revision' => 'integer'];

    public function versions()
    {
        return $this->hasMany(HrPolicyVersion::class);
    }
}
