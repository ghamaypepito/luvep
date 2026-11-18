<?php

namespace App\Services;

use App\Mail\AffiliateApplicationMail;
use App\Models\ActivityLog;
use App\Models\AffiliateApplication;
use App\Models\AgentAgreement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AffiliateOnboarding
{
    public function submit(array $data): void
    {
        $application = AffiliateApplication::firstOrCreate(['email' => $data['email']], ['uuid' => (string) Str::uuid(), 'consented_at' => now()] + $data);
        if ($application->wasRecentlyCreated) {
            $this->sendStatus($application);
        }
    }

    public function sendStatus(AffiliateApplication $application): void
    {
        $agreement = $application->agreement;
        $version = $application->matchesPartner($application->user) && ($agreement?->sent_at || $application->activated_at)
            ? $agreement?->id : null;
        $text = match (true) {
            ! $application->verified_at => 'Confirm your email address so the EP team can review your affiliate application.',
            (bool) $application->activated_at => 'Your EP affiliate application is complete. Open your application for your signed agreement and password setup.',
            default => 'Open your private EP affiliate application to see its progress.',
        };
        Mail::to($application->email)->queue(new AffiliateApplicationMail('Your EP affiliate application', $text, $application->signedUrl('status', $version)));
    }

    public function review(AffiliateApplication $application, User $reviewer, bool $approve): void
    {
        abort_unless($reviewer->isAdmin(), 403);
        DB::transaction(function () use ($application, $reviewer, $approve) {
            $application = AffiliateApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if (! $application->verified_at || $application->decision) {
                throw ValidationException::withMessages(['application' => 'Only a verified application awaiting review can be decided.']);
            }
            if ($approve) {
                $conflict = fn () => ValidationException::withMessages(['application' => 'An account already uses this email address. Resolve the conflict before approval.']);
                if (User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?', [$application->email])->exists()) {
                    throw $conflict();
                }
                try {
                    $user = User::create($application->only(['name', 'email', 'phone', 'location']) + ['role' => 'agent', 'is_active' => false, 'password' => Str::random(64)]);
                } catch (UniqueConstraintViolationException) {
                    throw $conflict();
                }
                $user->forceFill(['email_verified_at' => $application->verified_at])->save();
                $application->user_id = $user->id;
            }
            $application->update(['decision' => $approve ? 'approved' : 'declined', 'reviewed_at' => now(), 'reviewed_by' => $reviewer->id]);
            ActivityLog::record('affiliate.application.'.$application->decision, ['entity_type' => AffiliateApplication::class, 'entity_id' => $application->id]);
            $this->sendStatus($application);
        });
    }

    /** Agreement callers hold the partner lock before locking the version, then its application. */
    public function issued(AgentAgreement $agreement): void
    {
        AffiliateApplication::where('user_id', $agreement->agent_id)->where('decision', 'approved')->whereNull('activated_at')->update(['agreement_id' => $agreement->id]);
    }

    public function assertSigningAllowed(User $partner, AgentAgreement $agreement, ?AffiliateApplication $privateApplication): ?AffiliateApplication
    {
        $application = AffiliateApplication::where('user_id', $partner->id)->lockForUpdate()->first();
        if ($privateApplication) {
            abort_unless($application?->is($privateApplication) && ! $application->activated_at, 422, 'This invitation is no longer open for signature.');
        }
        if ($application && ! $application->activated_at) {
            abort_unless($application->matchesPartner($partner) && $application->agreement_id === $agreement->id, 422, 'Choose the current approved application agreement.');
        }

        return $application;
    }

    public function activateWhenSigned(User $partner, AgentAgreement $agreement, ?AffiliateApplication $application): void
    {
        if (! $application || $application->activated_at || ! $agreement->isSignedByAgent() || ! $agreement->isSignedByCompany()) {
            return;
        }
        // Eligibility and selected version were checked under these same locks before signing.
        $application->update(['activated_at' => now()]);
        $partner->update(['is_active' => true]);
        ActivityLog::record('affiliate.application.activated', ['entity_type' => AffiliateApplication::class, 'entity_id' => $application->id,
            'properties' => ['partner_id' => $partner->id, 'agreement_id' => $agreement->id]]);
        $this->sendStatus($application);
    }

    public function invitationAllowed(User $partner, AgentAgreement $agreement, ?AffiliateApplication $application): bool
    {
        if ($application && ! $application->activated_at) {
            return $application->matchesPartner($partner) && $application->agreement_id === $agreement->id;
        }

        return ! $partner->trashed() && $partner->is_active && $partner->canAccessPortal('agent');
    }

    public function accountState(User $partner, ?AffiliateApplication $application): string
    {
        return match (true) {
            $partner->trashed() => 'archived',
            $partner->is_active => 'active',
            $application && ! $application->activated_at => 'awaiting_signatures',
            default => 'disabled',
        };
    }

    public function assertCanActivate(User $user): void
    {
        if (AffiliateApplication::where('user_id', $user->id)->whereNull('activated_at')->exists()) {
            throw ValidationException::withMessages(['user' => 'This affiliate needs approval and both signatures before activation.']);
        }
    }

    public function accountActions(string $state): array
    {
        return match ($state) {
            'archived' => ['restore'],
            'active' => ['disable', 'archive'],
            'disabled' => ['activate', 'archive'],
            'awaiting_signatures' => ['archive'],
            default => [],
        };
    }

    public function changeAccount(User $partner, string $action, string $expected): void
    {
        DB::transaction(function () use ($partner, $action, $expected) {
            $partner = User::withTrashed()->whereKey($partner->id)->where('role', 'agent')->lockForUpdate()->firstOrFail();
            $application = AffiliateApplication::where('user_id', $partner->id)->first();
            $state = $this->accountState($partner, $application);
            if ($expected !== $state || ! in_array($action, $this->accountActions($state), true)) {
                throw ValidationException::withMessages(['user' => 'This action is unavailable in the current account state. Refresh the partner profile.']);
            }
            if ($action === 'activate') {
                $this->assertCanActivate($partner);
            }
            $partner->update(['is_active' => $action === 'activate']);
            if ($action === 'archive') {
                $partner->delete();
            } elseif ($action === 'restore') {
                $partner->restore();
            }
            ActivityLog::record('affiliate.partner.'.$action, ['entity_type' => User::class, 'entity_id' => $partner->id,
                'properties' => ['before' => $state, 'after' => $this->accountState($partner, $application)]]);
        });
    }
}
