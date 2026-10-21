<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemTicketEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'actor_id', 'kind', 'from_status', 'to_status', 'metadata', 'created_at'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SystemTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
