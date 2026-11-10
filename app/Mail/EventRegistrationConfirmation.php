<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\EventSession;
use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Branded confirmation sent to a lead right after they register for an
 * event. Queued so it never blocks the public registration response.
 */
class EventRegistrationConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $firstName;

    public function __construct(
        public Lead $lead,
        public Event $event,
        public ?EventSession $session = null,
    ) {
        $this->firstName = $lead->first_name ?: 'there';
    }

    public function envelope(): Envelope
    {
        $contact = $this->eventContact();
        $from = $contact['email'] ?? config('services.contact.event_from_email');
        $fromName = $contact['name'] ?? config('services.contact.event_from_name');
        $replyTo = $contact['email'] ?? config('services.contact.reply_to');

        return new Envelope(
            from: $from ? new Address($from, $fromName ?: null) : null,
            replyTo: $replyTo ? [new Address($replyTo, $contact['name'] ?? null)] : [],
            subject: 'You\'re registered — '.$this->event->name,
        );
    }

    public function content(): Content
    {
        $contact = $this->eventContact();

        return new Content(
            view: 'emails.event-registration',
            with: [
                'firstName'    => $this->firstName,
                'event'        => $this->event,
                'eventName'    => $this->event->name,
                'dateLine'     => $this->dateLine(),
                'timeLine'     => $this->timeLine(),
                'locationLine' => $this->locationLine(),
                'bookUrl'      => config('services.contact.booking_url') ?: rtrim(config('app.url'), '/').'/booking',
                'siteUrl'      => rtrim(config('app.url'), '/'),
                'phone'        => config('services.contact.phone'),
                'facebook'     => config('services.contact.facebook'),
                'messenger'    => config('services.contact.messenger'),
                'email'        => config('services.contact.email'),
                'eventContact' => $contact,
                'headerImage'  => $contact ? 'bhutanese-association-header.jpg' : 'team-header.png',
                'footerImage'  => $contact ? 'bhutanese-association-footer.jpg' : null,
            ],
        );
    }

    private function eventContact(): ?array
    {
        if ($this->event->event_code !== 'bhutanese-association-of-new-zealand-fz35m') {
            return null;
        }

        return [
            'first_name' => 'Eireen',
            'name' => 'Eireen Serrano',
            'email' => 'eireen@dimmigration.co',
            'phone' => '+64 21 227 8811',
        ];
    }

    /** Human date, using the chosen session's date when present. */
    private function dateLine(): ?string
    {
        $date = $this->session?->date ?? $this->event->date_from;
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->translatedFormat('l, j F Y');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Human time range, preferring the chosen session's times. */
    private function timeLine(): ?string
    {
        $start = $this->session?->time_start ?? $this->event->time_start;
        $end = $this->session?->time_end ?? $this->event->time_end;

        $fmt = function ($raw) {
            if (! $raw) {
                return null;
            }
            try {
                return Carbon::parse($raw)->format('g:i A');
            } catch (\Throwable) {
                return null;
            }
        };

        $start = $fmt($start);
        $end = $fmt($end);

        if ($start && $end) {
            return "{$start} – {$end}";
        }

        return $start ?: null;
    }

    /** Venue / mode line — session venue first, else the event location. */
    private function locationLine(): ?string
    {
        if ($this->session) {
            $parts = array_filter([
                $this->session->venue_name,
                $this->session->city,
            ]);
            if ($parts) {
                return implode(', ', $parts);
            }
        }

        if ($this->event->location) {
            return $this->event->location;
        }

        return match ($this->event->mode) {
            'online' => 'Online',
            'hybrid' => 'Hybrid (online & in-person)',
            default => null,
        };
    }
}
