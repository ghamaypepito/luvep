<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationEventEmailSubscription extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'follow_up_confirmed_at' => 'datetime',
        'newsletter_confirmed_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'exited_at' => 'datetime',
        'newsletter_synced_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EducationEventRegistration::class);
    }
}
