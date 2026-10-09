<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * When a public visa assessment is submitted by someone who is ALREADY an
 * immigration case (matched on email), sync the fresh submission into that
 * existing case instead of leaving a disconnected duplicate.
 *
 * A submission never creates a Lead of its own (the Lead only exists once the
 * enquiry was converted to a case), so without this a returning client's new
 * form lands as an orphan intake/assessment with no link to the case they are
 * already being handled under. Here we copy their latest personal + visa
 * details onto the case and point the case at this newest assessment.
 *
 * Runs after the intake has been committed and never throws — a sync failure
 * must not turn a saved submission into a user-facing error.
 */
trait SyncsIntakeToCase
{
    protected function syncIntakeToExistingCase(Model $intake, string $visaLabel): void
    {
        try {
            $email = mb_strtolower(trim((string) ($intake->email ?? '')));
            $dob = static::caseSyncDob($intake->dob ?? null);
            // Need both an email AND a date of birth to be sure it's the same
            // person: families routinely share one email, so email alone would
            // fold a husband's enquiry into his wife's case. Email + DOB is the
            // identity key (see caseSyncIdentityKey used by the list too).
            if ($email === '' || $dob === null) {
                return;
            }

            $case = Lead::where('is_immigration_case', true)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->get()
                ->first(fn (Lead $c) => static::caseSyncDob($c->dob) === $dob);
            if (! $case) {
                return; // no existing case for this exact person — nothing to sync
            }

            // Resident uses last_name/nationality; the other funnels use
            // family_name/country_of_citizenship — accept whichever is present.
            $patch = [
                'first_name' => $intake->first_name ?? null,
                'last_name' => $intake->last_name ?? ($intake->family_name ?? null),
                'phone' => $intake->phone ?? null,
                'passport_number' => $intake->passport_number ?? null,
                'passport_expiry' => $intake->passport_expiry ?? null,
                'citizenship' => $intake->nationality ?? ($intake->country_of_citizenship ?? null),
            ];

            // Only write fields the submission actually provided — a blank answer
            // must never wipe a value staff already hold on the case.
            $patch = array_filter($patch, fn ($v) => $v !== null && $v !== '');

            // The visa type on the case is staff's authoritative choice (e.g.
            // "Student Visa (Fee Paying)"). A public assessment re-submission only
            // knows the generic funnel label ("Student Visa"), so seed
            // inz_visa_type only when the case has none yet — never overwrite an
            // existing, more specific type the client would otherwise see switch.
            if (empty($case->inz_visa_type)) {
                $patch['inz_visa_type'] = $visaLabel;
            }

            $case->forceFill($patch)->save();
        } catch (\Throwable $e) {
            Log::warning('Intake→case sync failed', [
                'intake' => $intake->getMorphClass().'#'.$intake->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Normalise a date (string|Carbon|null) to Y-m-d, or null when unusable. */
    protected static function caseSyncDob($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The "same person" key the intake list and the sync agree on:
     * lower(email)|Y-m-d. Null when either half is missing, so an incomplete
     * record never matches (and a shared email alone never collapses two
     * people). Shared so both surfaces treat identity identically.
     */
    public static function caseSyncIdentityKey($email, $dob): ?string
    {
        $email = mb_strtolower(trim((string) ($email ?? '')));
        $dob = static::caseSyncDob($dob);

        return ($email === '' || $dob === null) ? null : $email.'|'.$dob;
    }
}
