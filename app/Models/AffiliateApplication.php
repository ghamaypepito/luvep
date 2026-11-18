<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class AffiliateApplication extends Model
{
    protected $fillable = ['uuid', 'name', 'email', 'phone', 'location', 'company', 'consented_at', 'verified_at', 'decision', 'reviewed_at', 'reviewed_by', 'user_id', 'agreement_id', 'activated_at'];

    protected $casts = ['consented_at' => 'datetime', 'verified_at' => 'datetime', 'reviewed_at' => 'datetime', 'activated_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(AgentAgreement::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->activated_at !== null => 'Activated',
            $this->decision === 'approved' => 'Awaiting signatures',
            $this->decision === 'declined' => 'Declined',
            $this->verified_at !== null => 'Awaiting review',
            default => 'Awaiting email verification',
        };
    }

    public function matchesPartner(?User $partner): bool
    {
        return $this->verified_at && $this->decision === 'approved' && $partner && ! $partner->trashed()
            && $partner->role === 'agent' && $partner->id === $this->user_id && mb_strtolower(trim($partner->email)) === $this->email;
    }

    public function signedUrl(string $action, ?int $agreementId = null, ?DateTimeInterface $expires = null): string
    {
        return rtrim(config('app.url'), '/').URL::temporarySignedRoute('affiliate.application.'.$action, $expires ?? now()->addDays(2),
            array_filter(['application' => $this->uuid, 'agreement' => $agreementId]), absolute: false);
    }
}
