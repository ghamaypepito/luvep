<?php

namespace App\Observers;

use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadAssignedToYou;
use App\Support\CaseRecordAccess;
use Illuminate\Support\Facades\Auth;

/**
 * Fires assignment notifications when a lead's owner changes. Using an
 * observer (rather than dispatching from each controller) means the
 * notification fires no matter where the assignment happens, and keeps
 * the wiring in one place.
 */
class LeadObserver
{
    /**
     * Immigration stages from "Visa Lodged" onward — reaching any of them means
     * the application is with INZ. (Withdrawn is excluded — a case can be
     * withdrawn before it is ever lodged.)
     */
    private const LODGED_STAGES = [
        'Visa Lodged', 'Interim Visa Issued', 'Request for Information',
        'RFI Responded', 'Approved in Principle', 'Approved Visa', 'Decline Visa',
    ];

    /**
     * Auto-stamp the INZ lodgement date the first time a case reaches a lodged
     * stage, so the processing tracker works the moment staff advance the stage —
     * no separate manual entry needed. Runs before the write (all stage-change
     * paths funnel through save), only on the stage transition, and never over an
     * existing date, so staff can still correct it afterwards.
     */
    public function saving(Lead $lead): void
    {
        if ($lead->isDirty('immigration_stage')
            && in_array($lead->immigration_stage, self::LODGED_STAGES, true)
            && empty($lead->inz_lodged_at)) {
            $lead->inz_lodged_at = now();
        }
    }

    public function updating(Lead $lead): void
    {
        $request = request();
        if ($lead->isDirty(CaseRecordAccess::NAME_FIELDS) && $request->user() && CaseRecordAccess::nameLocked($request, $lead)) {
            CaseRecordAccess::authorizeNameChanges($request, $lead, $lead->getDirty(), CaseRecordAccess::NAME_FIELDS);
        }
    }

    public function updated(Lead $lead): void
    {
        $this->syncPortalLoginEmail($lead);

        // Only when assigned_to actually changed in this save, and only on
        // assignment (not un-assignment to null).
        if (! $lead->wasChanged('assigned_to') || $lead->assigned_to === null) {
            return;
        }

        $assignee = User::find($lead->assigned_to);
        if (! $assignee) {
            return;
        }

        $actor = Auth::user();
        $assignee->notify(new LeadAssignedToYou(
            $lead,
            $actor?->id,
            $actor?->name ?? 'System',
        ));
    }

    /**
     * A lead's portal login is a separate User row (role=lead, linked by
     * lead_id) whose `email` is the sign-in identifier. It was snapshotted when
     * credentials were generated and does NOT follow later edits to the lead's
     * email on its own — so without this, changing a client's email leaves them
     * logging in with the old address while all mail goes to the new one.
     *
     * Keep the login email in lockstep whenever staff change an active client's
     * email. Skipped for revoked accounts (login intentionally disabled) and
     * when the new value collides with another account — a collision is a
     * genuine conflict we can't auto-resolve, so we log and leave login on the
     * old address rather than throwing (which would roll back the lead edit).
     */
    private function syncPortalLoginEmail(Lead $lead): void
    {
        if (! $lead->wasChanged('email')) {
            return;
        }

        $newEmail = trim((string) $lead->email);
        if ($newEmail === '') {
            return; // never clear a login email — that would lock the client out
        }

        $portalUser = $lead->portalUser;
        if (! $portalUser || $portalUser->role !== User::ROLE_LEAD) {
            return; // no active portal account (or revoked)
        }

        if (strcasecmp($portalUser->email, $newEmail) === 0) {
            return; // already in sync
        }

        $collision = User::where('email', $newEmail)
            ->where('id', '!=', $portalUser->id)
            ->exists();
        if ($collision) {
            \Illuminate\Support\Facades\Log::warning('Lead email changed but portal login not synced — email already in use by another account', [
                'lead_id' => $lead->id,
                'portal_user_id' => $portalUser->id,
                'new_email' => $newEmail,
            ]);

            return;
        }

        $portalUser->forceFill(['email' => $newEmail])->save();
    }
}
