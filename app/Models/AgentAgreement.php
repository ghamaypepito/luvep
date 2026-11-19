<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated Referral Agent Agreement PDF for an agent (a User with
 * role=agent). Generated versions are retained with their issued documents. The captured fillable + Schedule A values live in `fields`.
 */
class AgentAgreement extends Model
{
    protected $fillable = [
        'agent_id',
        'fields',
        'version',
        'rendered_html',
        'issued_file_path',
        'expires_on',
        'is_draft',
        'generated_at',
        'send_requested_at',
        'sent_at',
        'send_error',
        'file_path',
        'original_name',
        'mime',
        'size',
        'generated_by',
        'agent_signer_name',
        'agent_signature_data',
        'agent_signed_at',
        'agent_signed_ip',
        'agent_signed_user_agent',
        'company_signer_name',
        'company_signature_data',
        'company_signed_at',
        'company_signed_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'expires_on' => 'date',
        'is_draft' => 'boolean',
        'generated_at' => 'datetime',
        'send_requested_at' => 'datetime',
        'sent_at' => 'datetime',
        'agent_signed_at' => 'datetime',
        'company_signed_at' => 'datetime',
    ];

    public function isCurrent(): bool
    {
        return ! self::where('agent_id', $this->agent_id)->where('is_draft', false)->where('version', '>', $this->version)->exists();
    }

    public function canEditDetails(): bool
    {
        return $this->rendered_html !== null && $this->isCurrent()
            && ! $this->isSignedByAgent() && ! $this->isSignedByCompany()
            && ! ($this->expires_on?->isBefore(today()) ?? false);
    }

    public function canSign(): bool
    {
        return ! $this->is_draft && ! ($this->isSignedByAgent() && $this->isSignedByCompany())
            && $this->rendered_html !== null && $this->isCurrent()
            && ! ($this->expires_on?->isBefore(today()) ?? false);
    }

    public function statusLabel(): string
    {
        if ($this->is_draft) {
            return 'Draft';
        }
        if ($this->expires_on?->isBefore(today())) {
            return 'Expired';
        }

        if ($this->isSignedByAgent() && $this->isSignedByCompany()) {
            return 'Signed';
        }

        return $this->isSignedByAgent() || $this->isSignedByCompany() ? 'Pending Signature' : ($this->sent_at ? 'Sent' : 'Generated');
    }

    public function historyPayload(string $base): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->statusLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'agent_signed_at' => $this->agent_signed_at?->toIso8601String(),
            'company_signed_at' => $this->company_signed_at?->toIso8601String(),
            'expires_on' => $this->expires_on?->toDateString(),
            'view_url' => $base.'/view?agreement='.$this->id,
            'download_url' => $base.'/download?agreement='.$this->id,
            'legacy' => $this->rendered_html === null,
            'is_draft' => $this->is_draft,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'send_requested_at' => $this->send_requested_at?->toIso8601String(),
            'send_error' => $this->send_error,
        ];
    }

    /** Has the agent e-signed this agreement? */
    public function isSignedByAgent(): bool
    {
        return $this->agent_signed_at !== null;
    }

    /** Has ePathways (staff) e-signed this agreement? */
    public function isSignedByCompany(): bool
    {
        return $this->company_signed_at !== null;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
