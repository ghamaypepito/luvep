<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerActivityFile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];
}
