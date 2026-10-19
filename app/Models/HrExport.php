<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrExport extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_key', 'scope_hash'];

    protected $casts = ['filters' => 'array', 'expires_at' => 'datetime'];

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
