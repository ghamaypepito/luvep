<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal staff note on a Lead. Authored by any logged-in staff user,
 * editable only by the original author (enforced at the controller).
 * Never surfaced in the lead-facing portal.
 */
class LeadNote extends Model
{
    use LogsActivity;

    protected $fillable = [
        'lead_id', 'parent_id', 'user_id', 'author_name', 'author_role', 'body', 'attachments', 'pinned',
        'kind', 'pre_screened_by', 'pre_screen_mode', 'pre_screen_date',
        'goal_setting_status', 'goal_setting_by', 'programs',
    ];

    protected $casts = [
        'pinned'          => 'boolean',
        'programs'        => 'array',
        'pre_screen_date' => 'date',
        'attachments'     => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Replies to this note (threaded internal notes). */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Keep activity-log entries readable: "lead_note.created" etc. */
    public function activityNoun(): string
    {
        return 'lead_note';
    }
}
