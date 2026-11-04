<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['code_hash', 'session_hash'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
}
