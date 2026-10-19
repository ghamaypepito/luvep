<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrPolicyVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer', 'published_at' => 'datetime'];

    public function policy()
    {
        return $this->belongsTo(HrPolicy::class, 'hr_policy_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Published policies are immutable.'));
        static::deleting(fn () => throw new \LogicException('Published policies cannot be deleted.'));
    }
}
