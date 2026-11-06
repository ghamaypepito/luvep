<?php

namespace App\Console\Commands;

use App\Services\EducationOutreachService;
use App\Services\EducationRsvpContactUpdate;
use Illuminate\Console\Command;

class RequestEducationRsvpContact extends Command
{
    protected $signature = 'education:request-rsvp-contact {--send : Send the one-time requests through existing outreach delivery}';

    protected $description = 'Request missing RSVP contact numbers; preview counts unless --send is supplied';

    public function handle(EducationRsvpContactUpdate $contacts, EducationOutreachService $delivery): int
    {
        $recipients = $contacts->recipients();
        $this->info($recipients->count().' RSVP contacts need a contact number.');
        if (! $this->option('send')) {
            $this->line('Preview only. No emails sent.');

            return self::SUCCESS;
        }
        $sent = 0;
        $alreadySent = 0;
        $pending = 0;
        $cancelled = 0;
        foreach ($recipients as $rsvp) {
            $receipt = $contacts->request($rsvp);
            if ($receipt->sent_at) {
                $alreadySent++;

                continue;
            }
            $delivery->process($receipt);
            $receipt->refresh();
            if ($receipt->sent_at) {
                $sent++;
            } elseif ($receipt->cancelled_at) {
                $cancelled++;
            } else {
                $pending++;
            }
        }
        $this->info("Sent: {$sent}; already sent: {$alreadySent}; pending retry: {$pending}; skipped: {$cancelled}.");

        return self::SUCCESS;
    }
}
