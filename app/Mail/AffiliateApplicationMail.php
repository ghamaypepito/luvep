<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

class AffiliateApplicationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $heading, public string $messageText, public string $link)
    {
        $this->afterCommit();
    }

    public function build(): self
    {
        return $this->subject($this->heading)->html('<p>'.e($this->messageText).'</p><p><a href="'.e($this->link).'">Open your private application</a></p><p>This private link expires in two days. Request a fresh link on the EP affiliate application page.</p><p>The EP team</p>');
    }
}
