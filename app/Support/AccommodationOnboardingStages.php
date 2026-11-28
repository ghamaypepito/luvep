<?php

namespace App\Support;

use App\Models\EoiSubmission;

class AccommodationOnboardingStages
{
    public const STAGES = [
        'registration' => ['Registration / Leads', ['new', 'reviewed', 'declined']],
        'shortlisted' => ['Shortlisted', ['shortlisted']],
        'viewing_approved' => ['Approved to Book a Viewing', ['viewing_email_sent']],
        'viewing_booked' => ['Viewing Booked', ['viewing_booked']],
        'viewing_completed' => ['Viewing Completed', ['viewing_completed']],
        'proposal_agreement' => ['Proposal / Agreement Sent', ['post_viewing_followup', 'pre_tenancy_form_sent', 'pre_tenancy_form_completed', 'agreement_sent']],
        'agreement_signed' => ['Agreement Signed', ['agreement_signed']],
        'invoice_sent' => ['Move-In Cost Invoice Sent', ['invoice_sent']],
        'payment_confirmed' => ['Move-In Cost Payment Confirmed', ['payment_confirmed', 'moved_in']],
        'welcome_sent' => ['Welcome Email Sent', []],
    ];

    public static function options(): array
    {
        return collect(self::STAGES)->map(fn ($stage, $key) => ['id' => $key, 'label' => $stage[0]])->values()->all();
    }

    public static function label(EoiSubmission $submission): string
    {
        if ($submission->welcome_email_sent_at) {
            return self::STAGES['welcome_sent'][0];
        }

        foreach (self::STAGES as [$label, $statuses]) {
            if (in_array($submission->status, $statuses, true)) {
                return $label;
            }
        }

        return 'Not Proceeding';
    }
}
