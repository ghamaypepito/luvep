<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDtrReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['before_state' => 'array', 'after_state' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('DTR review history is immutable.'));
        static::deleting(fn () => throw new \LogicException('DTR review history is immutable.'));
    }
}
