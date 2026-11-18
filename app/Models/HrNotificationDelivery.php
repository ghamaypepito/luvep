<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrNotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['attempts' => 'integer', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];

    public function event()
    {
        return $this->belongsTo(HrEvent::class, 'event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
