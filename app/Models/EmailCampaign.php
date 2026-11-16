<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELED = 'canceled';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    protected $fillable = [
        'name',
        'department',
        'created_by',
        'template_id',
        'channel',
        'subject',
        'body',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'failed_count',
        'skipped_count',
        'recipient_lead_ids',
        'new_leads_only',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'recipient_lead_ids' => 'array',
        'new_leads_only' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MessageLog::class, 'campaign_id');
    }

    /** A scheduled campaign can still be canceled before it fires. */
    public function isCancelable(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** Scope a department's campaigns (null = no scoping / all departments). */
    public function scopeForDepartmentList(Builder $query, ?string $department): Builder
    {
        return $department === null ? $query : $query->where('department', $department);
    }
}
