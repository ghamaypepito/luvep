<?php

namespace App\Jobs;

use App\Mail\AffiliateAgreementInvitation;
use App\Models\ActivityLog;
use App\Models\AffiliateApplication;
use App\Models\AgentAgreement;
use App\Models\User;
use App\Services\AffiliateOnboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendAffiliateAgreement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $agreementId, public string $email) {}

    public function handle(): void
    {
        $partnerId = AgentAgreement::whereKey($this->agreementId)->value('agent_id');
        $invitation = DB::transaction(function () use ($partnerId) {
            $partner = User::whereKey($partnerId)->lockForUpdate()->first();
            $agreement = AgentAgreement::whereKey($this->agreementId)->lockForUpdate()->first();
            if (! $agreement || $agreement->sent_at || $agreement->send_error || ! $agreement->send_requested_at) {
                return null;
            }
            $application = AffiliateApplication::where('user_id', $partnerId)->first();
            if (! $partner || ! $agreement->canSign() || ! app(AffiliateOnboarding::class)->invitationAllowed($partner, $agreement, $application)
                || mb_strtolower(trim((string) $partner->email)) !== mb_strtolower(trim($this->email))) {
                $agreement->update(['send_error' => 'The agreement or recipient changed. Review before sending again.']);

                return null;
            }
            // A durable claim holds an uncertain transport result for review instead of resending.
            $agreement->update(['send_error' => 'Delivery is in progress or uncertain. Review before sending again.']);

            $agreement->setRelation('agent', $partner);

            return [$agreement, $application && ! $application->activated_at ? $application->signedUrl('status', $agreement->id) : null];
        });
        if (! $invitation) {
            return;
        }
        [$agreement, $privateUrl] = $invitation;
        try {
            Mail::to($this->email)->send(new AffiliateAgreementInvitation($agreement, $privateUrl));
            $agreement->update(['sent_at' => now(), 'send_error' => null]);
            ActivityLog::record('affiliate.agreement.sent', ['portal' => 'admin', 'user_id' => $agreement->generated_by,
                'properties' => ['agreement_id' => $agreement->id, 'version' => $agreement->version]]);
        } catch (\Throwable $error) {
            report($error);
            $agreement->update(['send_error' => 'Delivery failed or is uncertain. Review before creating another invitation.']);
        }
    }

    public function failed(?\Throwable $error): void
    {
        AgentAgreement::whereKey($this->agreementId)->whereNull('sent_at')
            ->update(['send_error' => 'Delivery failed or is uncertain. Review before creating another invitation.']);
    }
}
