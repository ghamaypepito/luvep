<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class HrAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('HR audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('HR audit events are immutable.'));
    }
}
