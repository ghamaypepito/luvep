<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An advice-bearing attestation (Build 12 phase 5): the case verdict or the
 * lodgement sign-off. Licence-gated at the write path (AdviceBearingPolicy) with
 * no exceptions — only ever created via VerdictService. Append-only; a changed
 * verdict is a new row with supersedes_id set.
 */
class CaseAttestation extends Model
{
    public const TYPE_VERDICT = 'verdict';

    public const TYPE_LODGEMENT_SIGNOFF = 'lodgement_signoff';

    public const VERDICT_GOOD_TO_GO = 'good_to_go';

    public const VERDICT_NEEDS_SOMETHING = 'needs_something';

    public const VERDICT_CANNOT_ENDORSE = 'cannot_endorse';

    public const VERDICTS = [
        self::VERDICT_GOOD_TO_GO,
        self::VERDICT_NEEDS_SOMETHING,
        self::VERDICT_CANNOT_ENDORSE,
    ];

    protected $fillable = [
        'lead_id', 'adviser_id', 'type', 'verdict', 'reason', 'supersedes_id',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id');
    }

    /** Count missing attestations for an already-authorized set of case IDs. */
    public static function pendingCountsFor(iterable $leadIds): array
    {
        $ids = collect($leadIds)->unique()->values();
        if ($ids->isEmpty()) {
            return ['awaiting_verdict' => 0, 'awaiting_lodgement' => 0, 'pending_signoff' => 0];
        }

        $presence = static::whereIn('lead_id', $ids)
            ->select('lead_id')
            ->selectRaw('MAX(CASE WHEN type = ? THEN 1 ELSE 0 END) AS has_verdict', [self::TYPE_VERDICT])
            ->selectRaw('MAX(CASE WHEN type = ? THEN 1 ELSE 0 END) AS has_signoff', [self::TYPE_LODGEMENT_SIGNOFF])
            ->groupBy('lead_id')->get();

        return [
            'awaiting_verdict' => $ids->count() - $presence->where('has_verdict', 1)->count(),
            'awaiting_lodgement' => $ids->count() - $presence->where('has_signoff', 1)->count(),
            'pending_signoff' => $ids->count() - $presence->filter(fn ($row) => $row->has_verdict && $row->has_signoff)->count(),
        ];
    }

    /** The current (latest) verdict for a case, or null. */
    public static function currentVerdict(int $leadId): ?self
    {
        return static::where('lead_id', $leadId)
            ->where('type', self::TYPE_VERDICT)
            ->latest('id')
            ->first();
    }

    /** Whether a case has a lodgement sign-off on record. */
    public static function hasLodgementSignoff(int $leadId): bool
    {
        return static::where('lead_id', $leadId)
            ->where('type', self::TYPE_LODGEMENT_SIGNOFF)
            ->exists();
    }
}
