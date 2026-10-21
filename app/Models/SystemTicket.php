<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A change/feature request a department raises about the system. Submitted
 * by any staff member; triaged by admins / super-admins.
 */
class SystemTicket extends Model
{
    use LogsActivity, SoftDeletes;

    public const CATEGORIES = ['change', 'feature', 'bug', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_review', 'planned', 'in_progress', 'done', 'declined'];

    protected $fillable = [
        'ticket_ref', 'title', 'description', 'category', 'priority', 'status',
        'submitted_by', 'department', 'admin_response', 'resolved_by', 'resolved_at',
        'images', 'assigned_to', 'assigned_at', 'tracking_started_at', 'archived_at',
        'waiting_on', 'topic', 'resolution_report', 'documentation_title', 'documentation_body',
        'documentation_published_at', 'response_target_hours',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'assigned_at' => 'datetime', 'tracking_started_at' => 'datetime', 'archived_at' => 'datetime',
        'documentation_published_at' => 'datetime', 'resolution_report' => 'array',
        'response_target_hours' => 'integer',
        // Array of private-disk paths for the submitter's screenshots.
        'images' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (SystemTicket $ticket) {
            $ticket->ticket_ref = $ticket->ticket_ref ?: 'TKT-'.strtoupper(Str::random(6));
        });
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SystemTicketMessage::class, 'ticket_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SystemTicketEvent::class, 'ticket_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['done', 'declined']);
    }

    /**
     * Compact summary for the admin / super-admin dashboard widget:
     * the open count plus the latest few open requests.
     */
    public static function dashboardSummary(): array
    {
        return [
            'open_count' => static::open()->count(),
            'recent' => static::open()
                ->latest()
                ->limit(3)
                ->get()
                ->map(fn (self $t) => [
                    'id' => $t->id,
                    'ticket_ref' => $t->ticket_ref,
                    'title' => $t->title,
                    'department' => $t->department,
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'created_at' => optional($t->created_at)?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
