<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrPolicyAcknowledgement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Acknowledgements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Acknowledgements cannot be deleted.'));
    }
}
