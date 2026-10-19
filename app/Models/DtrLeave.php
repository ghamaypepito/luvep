<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DtrLeave extends Model
{
    /** Section 2 — Type of Leave Requested (mirrors the Application for Leave form). */
    public const TYPES = [
        'Annual Leave',
        'Personal / Sick Leave',
        "Carer's Leave",
        'Compassionate / Bereavement Leave',
        'Other',
    ];

    /** Section 5 — Manager decision options. */
    public const DECISIONS = ['Approved', 'Approved in part', 'Declined', 'Deferred'];

    protected $fillable = [
        'user_id',
        'full_name', 'position',
        'type', 'other_specify',
        'start_date', 'end_date', 'return_date', 'total_days', 'half_day',
        'reason', 'declaration', 'employee_signature', 'employee_signed_at',
        'status', 'decision', 'working_days_approved', 'operational_impact',
        'manager_comments', 'manager_signature', 'manager_signed_at',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'hr_version' => 'integer', 'calendar_snapshot' => 'array', 'emergency_required' => 'boolean', 'emergency_approved_at' => 'datetime', 'notified_at' => 'datetime',
        'team_checked' => 'boolean', 'travel_acknowledged' => 'boolean', 'auto_reply_planned' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'return_date' => 'date',
        'total_days' => 'decimal:1',
        'declaration' => 'boolean',
        'employee_signed_at' => 'datetime',
        'manager_signed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
