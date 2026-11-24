<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your proposal / agreement is ready" — nudges a lead to open their
 * public /track/{code} page after staff generates a document on the
 * Proposal & Agreements screen. Kind is 'proposal' or 'agreement' so
 * the subject + copy match what was generated.
 */
class DocumentReadyNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $trackUrl;
    public string $firstName;
    public string $noun;      // "proposal" | "agreement"
    public string $nounTitle; // "Proposal"  | "Agreement"
    public bool $documentOnly = false;

    public function __construct(public Lead $lead, string $kind = 'agreement', ?string $documentUrl = null)
    {
        $kind             = in_array($kind, ['proposal', 'agreement'], true) ? $kind : 'agreement';
        $this->trackUrl   = $documentUrl ?? rtrim(config('app.url'), '/') . '/track/' . $lead->tracking_code;
        $this->documentOnly = $documentUrl !== null;
        $this->firstName  = $lead->first_name ?: 'there';
        $this->noun       = $kind;
        $this->nounTitle  = ucfirst($kind);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->nounTitle} is ready — EP",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.document-ready',
            with: [
                'firstName' => $this->firstName,
                'trackUrl'  => $this->trackUrl,
                'noun'      => $this->noun,
                'nounTitle' => $this->nounTitle,
                'documentOnly' => $this->documentOnly,
            ],
        );
    }
}
