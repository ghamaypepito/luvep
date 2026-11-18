<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrEquipmentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Equipment history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Equipment history cannot be deleted.'));
    }
}
