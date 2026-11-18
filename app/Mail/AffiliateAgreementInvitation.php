<?php

namespace App\Mail;

use App\Models\AgentAgreement;
use Illuminate\Mail\Mailable;

class AffiliateAgreementInvitation extends Mailable
{
    public function __construct(public AgentAgreement $agreement) {}

    public function build(): self
    {
        $name = e($this->agreement->agent->name);
        $url = e(url('/portal/agent/agreement?agreement='.$this->agreement->id));

        return $this->subject('Your EP Affiliate Partner Agreement · Version '.$this->agreement->version)
            ->html('<p>Hello '.$name.',</p><p>Your Affiliate Partner Agreement is ready to review in your EP portal.</p><p><a href="'.$url.'">Review your agreement</a></p><p>Please sign in using your affiliate account. Previous agreement versions remain available in your agreement history.</p><p>The EP team</p>');
    }
}
