<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDtrRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime', 'version' => 'integer'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('DTR change history is immutable.'));
        static::deleting(fn () => throw new \LogicException('DTR change history is immutable.'));
    }
}
