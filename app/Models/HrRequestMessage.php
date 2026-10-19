<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrRequestMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['body' => 'encrypted', 'internal' => 'boolean', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('HR case messages are immutable.'));
        static::deleting(fn () => throw new \LogicException('HR case messages cannot be deleted.'));
    }
}
