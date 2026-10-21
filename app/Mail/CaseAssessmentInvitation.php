<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};
use Illuminate\Queue\SerializesModels;

class CaseAssessmentInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public string $assessmentUrl;

    public function __construct(public Lead $lead)
    {
        $this->assessmentUrl = url('/portal/lead/visa-assessment');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Complete your visa assessment');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.case-assessment-invitation');
    }
}
