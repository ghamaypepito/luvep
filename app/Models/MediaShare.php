<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaShare extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'token_hash', 'email'];

    protected $casts = ['token' => 'encrypted', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'version' => 'integer'];

    public function folder()
    {
        return $this->belongsTo(MediaFolder::class);
    }

    public function isAvailable(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture() && $this->folder?->ownerClient() !== null;
    }
}
