<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrChecklistTemplate extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 0];

    protected $casts = ['tasks' => 'encrypted:array', 'version' => 'integer'];
}
