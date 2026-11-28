<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccommodationMoveOut extends Model
{
    public const AUTOMATION_SETTING = 'accommodation_move_out_automatic_email';

    public const STAGES = [
        'check_in' => 'Renewal or Move-Out Check-In',
        'decision' => 'Renewal Requested / Move-Out Confirmed',
        'reminded' => 'One-Week Move-Out Reminder Sent',
        'inspected' => 'Room Inspection Completed',
        'feedback' => 'Inspection Feedback Sent',
        'statement' => 'Bond Refund Statement Sent',
        'refunded' => 'Bond Refund Processed',
    ];

    protected $table = 'accommodation_move_outs';

    protected $fillable = [
        'tenant_id', 'tenant_label', 'property_id', 'property_label', 'agreement_id', 'assignee_id', 'agreement_ends_on',
        'move_out_on', 'check_in_on', 'reminder_on', 'automatic_email', 'stage', 'decision', 'closed_at',
    ];

    protected $casts = [
        'agreement_ends_on' => 'immutable_date',
        'move_out_on' => 'immutable_date',
        'check_in_on' => 'immutable_date',
        'reminder_on' => 'immutable_date',
        'automatic_email' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccommodationMoveOutEvent::class, 'move_out_id');
    }
}
