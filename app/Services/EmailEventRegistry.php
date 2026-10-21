<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * The catalogue of automatable email events, grouped by department → feature.
 *
 * This is the single source of truth: the admin "Email Automation" page reads
 * it to draw the rows, and the code fires events by key. Most events are
 * declared explicitly (each maps to a real trigger point in the app); the
 * repetitive ones — a message per case stage, per document status — are
 * *generated* from the enums the app already owns, so they never drift.
 */
class EmailEventRegistry
{
    /** Recipient roles a message can target, per department. */
    public const RECIPIENTS = [
        'immigration' => ['client', 'adviser', 'manager', 'team'],
        'default' => ['client', 'team'],
    ];

    public static function recipientsFor(string $department): array
    {
        return self::RECIPIENTS[$department] ?? self::RECIPIENTS['default'];
    }

    /** Human labels for recipient roles. */
    public const RECIPIENT_LABELS = [
        'client' => 'Client', 'adviser' => 'Adviser', 'manager' => 'Case manager', 'team' => 'Case team',
    ];

    /**
     * The full catalogue: [ department => [ ['group'=>.., 'events'=>[ ['key','label','when','vars'] ]] ] ].
     */
    public function catalogue(): array
    {
        return [
            'immigration' => $this->immigration(),
            'sales' => $this->sales(),
            'education' => $this->education(),
            'english' => $this->english(),
            'accommodation' => $this->accommodation(),
            'finance' => $this->finance(),
        ];
    }

    /** Department labels for the left rail. */
    public const DEPARTMENTS = [
        'sales' => 'Sales', 'education' => 'Education', 'english' => 'English',
        'immigration' => 'Immigration', 'accommodation' => 'Accommodation', 'finance' => 'Finance',
    ];

    /** Flat map of event_key => event definition, across every department. */
    public function events(): array
    {
        $out = [];
        foreach ($this->catalogue() as $groups) {
            foreach ($groups as $group) {
                foreach ($group['events'] as $ev) {
                    $out[$ev['key']] = $ev;
                }
            }
        }

        return $out;
    }

    public function find(string $key): ?array
    {
        return $this->events()[$key] ?? null;
    }

    // ── Immigration ─────────────────────────────────────────────────────────

    private function immigration(): array
    {
        $stageEvents = [];
        foreach (Lead::IMMIGRATION_STAGES as $stage) {
            $vars = ['first_name', 'stage', 'adviser_name', 'tracker_url'];
            // The RFI move captures a response deadline + note, passed to the
            // email — advertise them so admins can use them in the template.
            if (in_array($stage, ['Request for Information', 'Request for Medical'], true)) {
                $vars[] = $stage === 'Request for Medical' ? 'medical_deadline' : 'rfi_deadline';
                $vars[] = 'status_detail';
            }
            $stageEvents[] = [
                'key' => 'immigration.stage.'.Str::slug($stage, '_'),
                'label' => 'Moved to '.$stage,
                'when' => 'When the case stage changes to “'.$stage.'”',
                'vars' => $vars,
            ];
        }

        return [
            ['group' => 'Leads & assessment', 'events' => [
                ['key' => 'immigration.lead.captured', 'label' => 'New enquiry captured', 'when' => 'When a client submits a visa assessment on the website', 'vars' => ['first_name', 'visa_type']],
                ['key' => 'immigration.education.handoff', 'label' => 'Education student handed to Immigration', 'when' => 'When an Education student first becomes an immigration case', 'vars' => ['first_name', 'visa_type']],
                ['key' => 'immigration.case.converted', 'label' => 'Converted to case', 'when' => 'When a lead or assessment becomes an immigration case', 'vars' => ['first_name', 'visa_type']],
                ['key' => 'immigration.assessment.ready', 'label' => 'Assessment result ready', 'when' => 'When eligibility scoring finishes', 'vars' => ['first_name', 'eligibility_score']],
                ['key' => 'immigration.lead.cold', 'label' => 'Lead went cold', 'when' => 'No client reply for 7 days', 'vars' => ['first_name'], 'scheduled' => true],
            ]],
            ['group' => 'Engagement', 'events' => [
                ['key' => 'immigration.engagement.sent', 'label' => 'Engagement pack sent', 'when' => 'When the signing link is emailed', 'vars' => ['first_name', 'adviser_name', 'engagement_url']],
                ['key' => 'immigration.engagement.signed', 'label' => 'Agreement signed', 'when' => 'When the client e-signs the agreement', 'vars' => ['first_name', 'adviser_name', 'signed_at']],
                ['key' => 'immigration.engagement.expired', 'label' => 'Pack expired unsigned', 'when' => '14 days after the pack was sent', 'vars' => ['first_name', 'adviser_name'], 'scheduled' => true],
            ]],
            ['group' => 'Money', 'events' => [
                ['key' => 'immigration.invoice.sent', 'label' => 'Invoice sent', 'when' => 'When an invoice is generated & sent', 'vars' => ['first_name', 'invoice_number', 'invoice_total', 'due_date']],
                ['key' => 'immigration.proof.uploaded', 'label' => 'Proof of payment uploaded', 'when' => 'When the client uploads a proof of payment (notify staff)', 'vars' => ['first_name', 'adviser_name']],
                ['key' => 'immigration.invoice.paid', 'label' => 'Payment verified', 'when' => 'When a proof of payment is confirmed', 'vars' => ['first_name', 'invoice_total', 'adviser_name']],
                ['key' => 'immigration.invoice.overdue', 'label' => 'Payment overdue', 'when' => '7 days after the invoice due date', 'vars' => ['first_name', 'invoice_total'], 'scheduled' => true],
            ]],
            ['group' => 'Documents', 'events' => [
                ['key' => 'immigration.document.uploaded', 'label' => 'Document uploaded by client', 'when' => 'When the client uploads a document (notify staff)', 'vars' => ['first_name', 'document_name', 'adviser_name']],
                ['key' => 'immigration.document.requested', 'label' => 'Document requested', 'when' => 'When staff request a document', 'vars' => ['first_name', 'document_name', 'document_list', 'message']],
                ['key' => 'immigration.document.approved', 'label' => 'Document approved', 'when' => 'When a document is approved', 'vars' => ['first_name', 'document_name']],
                ['key' => 'immigration.document.rejected', 'label' => 'Document needs attention', 'when' => 'When a document is rejected', 'vars' => ['first_name', 'document_name', 'reason']],
            ]],
            ['group' => 'Case stages', 'events' => $stageEvents],
        ];
    }

    // ── Other departments (starter sets) ─────────────────────────────────────

    private function sales(): array
    {
        return [
            ['group' => 'Leads', 'events' => [
                ['key' => 'sales.lead.captured', 'label' => 'New lead captured', 'when' => 'When a lead is created', 'vars' => ['first_name']],
                ['key' => 'sales.booking.confirmed', 'label' => 'Booking confirmed', 'when' => 'When a consultation is booked', 'vars' => ['first_name', 'booking_time']],
            ]],
            ['group' => 'Proposals', 'events' => [
                ['key' => 'sales.proposal.sent', 'label' => 'Proposal sent', 'when' => 'When a proposal is generated', 'vars' => ['first_name']],
            ]],
        ];
    }

    private function education(): array
    {
        return [
            ['group' => 'Proposals', 'events' => [
                ['key' => 'education.proposal.submitted', 'label' => 'Proposal submitted for verification', 'when' => 'When staff submit a study proposal for verification', 'vars' => ['first_name', 'program_list', 'program_count', 'tracker_url']],
                ['key' => 'education.proposal.approved', 'label' => 'Proposal verified & approved', 'when' => 'When staff verify & approve all programmes in Program Verification', 'vars' => ['first_name', 'program_list', 'program_count', 'tracker_url']],
                ['key' => 'education.consultancy.submitted', 'label' => 'Consultancy agreement submitted for verification', 'when' => 'When staff submit a consultancy agreement for verification', 'vars' => ['first_name', 'agreement_type', 'total_amount']],
                ['key' => 'education.consultancy.approved', 'label' => 'Consultancy agreement approved', 'when' => 'When staff approve a consultancy agreement in verification', 'vars' => ['first_name', 'agreement_type', 'total_amount', 'tracker_url']],
                ['key' => 'education.program.chosen', 'label' => 'Client chose a program', 'when' => 'When the client picks one programme from their shortlist on the tracking link', 'vars' => ['first_name', 'program_name', 'program_level', 'program_location', 'tracker_url']],
            ]],
            ['group' => 'Students', 'events' => [
                ['key' => 'education.student.converted', 'label' => 'Converted to student', 'when' => 'When a lead becomes a student', 'vars' => ['first_name']],
                ['key' => 'education.offer.ready', 'label' => 'Offer letter ready', 'when' => 'When an offer is uploaded', 'vars' => ['first_name', 'school']],
            ]],
        ];
    }

    private function english(): array
    {
        return [
            ['group' => 'Classes', 'events' => [
                ['key' => 'english.enrolled', 'label' => 'Enrolled in a class', 'when' => 'When a learner is enrolled', 'vars' => ['first_name', 'class_name']],
                ['key' => 'english.class.reminder', 'label' => 'Class reminder', 'when' => 'The day before a session', 'vars' => ['first_name', 'class_time']],
            ]],
        ];
    }

    private function accommodation(): array
    {
        return [
            ['group' => 'Viewings', 'events' => [
                ['key' => 'accommodation.viewing.confirmed', 'label' => 'Viewing confirmed', 'when' => 'When a viewing is booked', 'vars' => ['first_name', 'viewing_time']],
            ]],
            ['group' => 'Tenancy', 'events' => [
                ['key' => 'accommodation.rent.recorded', 'label' => 'Rent receipt', 'when' => 'When rent is recorded', 'vars' => ['first_name', 'amount']],
            ]],
        ];
    }

    private function finance(): array
    {
        return [
            ['group' => 'Invoicing', 'events' => [
                ['key' => 'finance.invoice.sent', 'label' => 'Invoice sent', 'when' => 'When an invoice is issued', 'vars' => ['first_name', 'invoice_total']],
                ['key' => 'finance.payment.recorded', 'label' => 'Payment receipt', 'when' => 'When a payment is recorded', 'vars' => ['first_name', 'amount']],
            ]],
        ];
    }

    /** Which department a fired event_key belongs to (its prefix). */
    public function departmentOf(string $eventKey): string
    {
        return explode('.', $eventKey)[0] ?? 'immigration';
    }
}
