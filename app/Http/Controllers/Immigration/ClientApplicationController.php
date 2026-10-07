<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\PreScreening;
use App\Services\Immigration\PreScreeningService;
use App\Support\IntakeVisaTypeMap;
use Illuminate\Database\Eloquent\Model;

/**
 * Client-facing pre-screening application surface at /application/{token}.
 *
 * PRIVATE, token-bearer (the pre-screening token is the credential — no login).
 * A potential client sees where they are in pre-screening, their visa-assessment
 * summary, their two consultation bookings, and the consent step; they can
 * acknowledge consent and book their meetings. Everything here is procedural —
 * status and process only, never immigration advice.
 */
class ClientApplicationController extends Controller
{
    public function __construct(private PreScreeningService $service) {}

    public function show(PreScreening $preScreening)
    {
        $subject = $preScreening->subject;

        return inertia('application/ApplicationPage', [
            'applicant' => $this->applicant($subject),
            'visa_label' => $this->visaLabel($subject),
            'submitted_at' => optional($subject?->created_at)->toIso8601String(),
            'pre' => $this->service->summary($preScreening),
            'bookings' => [
                'consult' => $this->fmtBooking($preScreening->consultBooking),
                'consent' => $this->fmtBooking($preScreening->consentBooking),
            ],
            // Where the client books a consultation (the public booking funnel).
            'booking_url' => '/booking',
        ]);
    }

    /** The client confirms they have read + accepted the consent (+ audio). */
    public function consentAck(PreScreening $preScreening)
    {
        $this->service->acknowledgeConsent($preScreening);

        return back()->with('success', 'Thanks — your consent has been recorded.');
    }

    /** Name / contact, tolerant of both intake and lead field conventions. */
    private function applicant(?Model $subject): array
    {
        if (! $subject) {
            return ['name' => 'Applicant', 'email' => null, 'phone' => null];
        }

        $first = $subject->first_name ?? null;
        $last = $subject->last_name ?? $subject->family_name ?? null;

        return [
            'name' => trim("{$first} {$last}") ?: 'Applicant',
            'first_name' => $first,
            'email' => $subject->email ?? null,
            'phone' => $subject->phone ?? null,
        ];
    }

    /** Human visa label for the subject (intake type, or a lead's source). */
    private function visaLabel(?Model $subject): string
    {
        if ($subject instanceof Lead) {
            $visaType = \App\Support\VisaIntakeDraft::visaTypeForSource($subject->source);
            $labels = [
                'resident' => 'Resident Visa', 'work' => 'Work Visa', 'student' => 'Student Visa',
                'visitor' => 'Visitor Visa', 'family' => 'Family Visa',
            ];

            return $labels[$visaType] ?? 'Visa Assessment';
        }

        return $subject ? IntakeVisaTypeMap::label($subject::class) : 'Visa Assessment';
    }

    /** Client-safe booking summary (no internal ids beyond the meeting link). */
    private function fmtBooking(?Booking $b): ?array
    {
        if (! $b) {
            return null;
        }

        return [
            'status' => $b->status,
            'date' => optional($b->appointment_date)->toDateString(),
            'time' => $b->appointment_time,
            'at' => optional($b->appointment_at)->toIso8601String(),
            'consultant' => $b->consultant_name,
            'meet_link' => $b->meet_link,
        ];
    }
}
