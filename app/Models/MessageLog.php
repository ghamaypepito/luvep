<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageLog extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BOUNCED = 'bounced';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const SOURCE_ACCOMMODATION_COMPOSE = 'accommodation-mail';

    public const SOURCE_SCHOOL_CORRESPONDENCE = 'school-correspondence';

    protected $fillable = [
        'template_key',
        'source',
        'campaign_id',
        'channel',
        'recipient_type',
        'recipient_id',
        'recipient_address',
        'subject',
        'body',
        'status',
        'provider_message_id',
        'error_message',
        'sent_at',
        'failed_at',
        'triggered_by_user_id',
        'compose_details',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'compose_details' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
