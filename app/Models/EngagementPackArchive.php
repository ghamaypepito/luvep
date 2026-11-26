<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngagementPackArchive extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'pack_id', 'archived_by', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];
}
