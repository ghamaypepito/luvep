<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemTicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'body', 'is_support', 'attachments'];

    protected $hidden = ['attachments'];

    protected $casts = ['is_support' => 'boolean', 'attachments' => 'array'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SystemTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
