<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Lead;
use App\Models\WorkIntake;
use App\Services\EmailAutomationService;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires the configurable "New enquiry captured" email automation
 * (`immigration.lead.captured`) when a client submits a visa assessment on the
 * public site.
 *
 * The visa-interest funnel stores an Intake (+ a tracking Assessment) but NOT a
 * Lead — a Lead is only created later at Convert-to-Case. So we build a
 * transient (unsaved) Lead purely to carry the applicant's contact details and
 * visa type into the notification's template variables. Every lead_id-scoped
 * lookup inside CommunicationService then simply resolves to empty, and staff
 * recipients fall back to the practice's default adviser/manager — correct for a
 * brand-new enquiry that has no assigned case yet.
 *
 * EmailAutomationService::fire() is a safe no-op unless an admin has enabled a
 * message for this event, and it never throws, so this can never break submit.
 */
trait FiresEnquiryAutomation
{
    protected function fireEnquiryCaptured(Model $intake, string $visaLabel): void
    {
        $lead = new Lead;
        $lead->first_name = $intake->first_name ?? '';
        // Resident intake uses `last_name`; the other funnels use `family_name`.
        $lead->last_name = $intake->last_name ?? ($intake->family_name ?? '');
        $lead->email = $intake->email ?? '';
        $lead->phone = $intake->phone ?? '';
        $lead->inz_visa_type = $visaLabel;

        $automation = app(EmailAutomationService::class);
        $context = ['visa_type' => $visaLabel];
        if ($intake instanceof WorkIntake) {
            $automation->fireWithStaffRecipients('immigration.lead.captured', $lead, $context, config('immigration.aewv_submission_recipients'));

            return;
        }

        $automation->fire('immigration.lead.captured', $lead, $context);
    }
}
