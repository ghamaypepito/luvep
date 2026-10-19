<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'actor_id', 'subject_type', 'subject_id', 'deduplication_key', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function subject()
    {
        return $this->morphTo();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
