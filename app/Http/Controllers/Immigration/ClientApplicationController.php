<?php

namespace App\Http\Controllers\Immigration;

use App\Contracts\SignatureProvider;
use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\PreScreening;
use App\Services\Immigration\InstallmentPlanService;
use App\Services\Immigration\PreScreeningService;
use App\Support\IntakeVisaTypeMap;
use App\Support\UploadValidation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
            'documents' => $this->documents($preScreening),
            // Engagement agreement to review + sign (null until staff send one).
            'agreement' => $this->agreementSummary($preScreening),
            // Payment plan the client chooses pay-now / pay-later against.
            'plan' => $this->planSummary($preScreening),
            // Where the client books a consultation (the public booking funnel).
            'booking_url' => '/booking',
        ]);
    }

    /** The client chooses pay-now (full) or pay-later (activate the plan). */
    public function choosePlan(Request $request, PreScreening $preScreening, InstallmentPlanService $plans)
    {
        $data = $request->validate(['choice' => 'required|in:pay_now,pay_later']);

        $lead = $preScreening->lead;
        $plan = $lead ? $plans->activeFor($lead) : null;

        if ($data['choice'] === 'pay_later') {
            abort_unless($plan, 422, 'A payment plan has not been set up yet.');
            if ($plan->status === InstallmentPlan::STATUS_DRAFT) {
                $plans->activate($plan);
            }

            return back()->with('success', 'Your weekly payment plan is set.');
        }

        // Pay now — retire any plan; the full amount is due.
        if ($plan && in_array($plan->status, [InstallmentPlan::STATUS_DRAFT, InstallmentPlan::STATUS_ACTIVE], true)) {
            $plan->status = InstallmentPlan::STATUS_CANCELLED;
            $plan->save();
        }

        return back()->with('success', 'Thanks — you have chosen to pay in full.');
    }

    /** The client signs the engagement agreement (reuses the managed-agreement signer). */
    public function signAgreement(Request $request, PreScreening $preScreening, SignatureProvider $signer)
    {
        $request->validate([
            'signer_name' => 'required|string|max:200',
            'signature_data' => 'required|string|max:5000000',
            'terms_accepted' => 'required|accepted',
        ]);

        $agreement = $this->signableAgreement($preScreening);
        abort_unless($agreement, 404, 'There is no agreement ready to sign.');

        $ok = $signer->recordSignature($agreement, $request->only(['signer_name', 'signature_data']), $request);
        abort_unless($ok, 422, 'Your signature could not be recorded.');

        return back()->with('success', 'Thank you — your agreement is signed.');
    }

    /** Stream the agreement PDF for review (marks it viewed on first open). */
    public function agreementPdf(PreScreening $preScreening)
    {
        $lead = $preScreening->lead;
        abort_unless($lead, 404);

        $agreement = Agreement::where('lead_id', $lead->id)
            ->whereNotIn('status', [Agreement::STATUS_DRAFT, Agreement::STATUS_VOIDED])
            ->latest('id')->first();
        abort_unless($agreement, 404);

        if ($agreement->status === Agreement::STATUS_SENT) {
            $agreement->status = Agreement::STATUS_VIEWED;
            $agreement->viewed_at = now();
            $agreement->save();
        }

        $path = $agreement->signed_pdf_path ?: $agreement->pdf_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="agreement.pdf"',
        ]);
    }

    /** The agreement currently open for signing (sent or viewed), if any. */
    private function signableAgreement(PreScreening $pre): ?Agreement
    {
        $lead = $pre->lead;
        if (! $lead) {
            return null;
        }

        return Agreement::where('lead_id', $lead->id)
            ->whereIn('status', [Agreement::STATUS_SENT, Agreement::STATUS_VIEWED])
            ->latest('id')->first();
    }

    /** Client-safe summary of the latest (non-draft) engagement agreement. */
    private function agreementSummary(PreScreening $pre): ?array
    {
        $lead = $pre->lead;
        if (! $lead) {
            return null;
        }

        $a = Agreement::where('lead_id', $lead->id)
            ->whereNotIn('status', [Agreement::STATUS_DRAFT, Agreement::STATUS_VOIDED])
            ->latest('id')->first();
        if (! $a) {
            return null;
        }

        return [
            'title' => $a->title,
            'status' => $a->status,
            'can_sign' => in_array($a->status, [Agreement::STATUS_SENT, Agreement::STATUS_VIEWED], true),
            'signed' => $a->status === Agreement::STATUS_SIGNED,
            'signer_name' => $a->signer_name,
            'signed_at' => optional($a->signed_at)->toIso8601String(),
            'pdf_url' => "/application/{$pre->token}/agreement/pdf",
            'sign_url' => "/application/{$pre->token}/agreement/sign",
        ];
    }

    private function planSummary(PreScreening $pre): ?array
    {
        if (! $pre->lead) {
            return null;
        }
        $plans = app(InstallmentPlanService::class);

        return $plans->summary($plans->activeFor($pre->lead));
    }

    /** The client uploads a document. Attaches to the pre-screening's working lead. */
    public function uploadDoc(Request $request, PreScreening $preScreening)
    {
        $lead = $this->service->ensureWorkingLead($preScreening);

        $files = $request->hasFile('files')
            ? array_values($request->file('files'))
            : array_filter([$request->file('file')]);

        // Pre-screening uploads aren't for INZ lodgement yet, so accept the
        // common client formats (PDF + images), on the PRIVATE disk only.
        Validator::make(['files' => $files], [
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:'.UploadValidation::DOCUMENT_MAX_KB,
        ], [
            'files.*.mimes' => 'Please upload PDF or image (JPG/PNG) files.',
        ])->validate();

        foreach ($files as $file) {
            $path = $file->store("lead-documents/{$lead->id}", 'local');
            LeadDocument::create([
                'lead_id' => $lead->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'status' => LeadDocument::STATUS_SUBMITTED,
                'source' => LeadDocument::SOURCE_UPLOAD,
            ]);
        }

        return back()->with('success', 'Your document has been uploaded.');
    }

    /** Stream back one of the client's OWN uploads from the private disk. */
    public function streamUpload(Request $request, PreScreening $preScreening, $docId)
    {
        $lead = $preScreening->lead;
        abort_unless($lead, 404);

        $doc = LeadDocument::where('id', $docId)
            ->where('lead_id', $lead->id)
            ->firstOrFail();

        // Only the client's own uploads stream here — never shared/generated docs.
        abort_unless($doc->source === LeadDocument::SOURCE_UPLOAD, 403);

        $disk = Storage::disk('local')->exists($doc->file_path) ? 'local'
            : (Storage::disk('public')->exists($doc->file_path) ? 'public' : null);
        abort_unless($disk, 404);

        if ($request->boolean('inline')) {
            return response()->file(Storage::disk($disk)->path($doc->file_path), [
                'Content-Type' => $doc->mime ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$doc->original_name.'"',
            ]);
        }

        return response()->download(Storage::disk($disk)->path($doc->file_path), $doc->original_name);
    }

    /** Client-safe list of the client's own uploads (empty until a lead exists). */
    private function documents(PreScreening $pre): array
    {
        $lead = $pre->lead;
        if (! $lead) {
            return [];
        }

        return LeadDocument::where('lead_id', $lead->id)
            ->where('source', LeadDocument::SOURCE_UPLOAD)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'name' => $d->original_name,
                'status' => LeadDocument::clientStatus($d->status),
                'size' => $d->size,
                'is_image' => str_starts_with((string) $d->mime, 'image/'),
                'url' => "/application/{$pre->token}/uploads/{$d->id}?inline=1",
                'created_at' => optional($d->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /** The client confirms they have read + accepted the consent (+ audio). */
    public function consentAck(PreScreening $preScreening)
    {
        $this->service->acknowledgeConsent($preScreening);

        return back()->with('success', 'Thanks — your consent has been recorded.');
    }

    /**
     * The client requests a preferred time for one of their two meetings. This
     * creates a real (pending) Booking linked to the pre-screening and the
     * working lead; staff confirm the actual slot from the Bookings surface.
     */
    public function book(Request $request, PreScreening $preScreening)
    {
        $data = $request->validate([
            'meeting' => 'required|in:consult,consent',
            'date' => 'required|date',
            'time' => 'required|string|max:20',
            'note' => 'nullable|string|max:500',
        ]);

        $lead = $this->service->ensureWorkingLead($preScreening);
        $subject = $preScreening->subject;

        $at = null;
        try {
            $at = \Illuminate\Support\Carbon::parse("{$data['date']} {$data['time']}");
        } catch (\Throwable $e) {
            // Leave appointment_at null if the time string doesn't parse.
        }

        $booking = Booking::create([
            'first_name' => $subject->first_name ?? $lead->first_name,
            'last_name' => $subject->last_name ?? $subject->family_name ?? $lead->last_name,
            'email' => $subject->email ?? $lead->email,
            'phone' => $subject->phone ?? $lead->phone,
            'service_type' => $data['meeting'] === 'consult' ? 'Immigration Consultation' : 'Consent Meeting',
            'consultant_name' => 'Immigration Adviser',
            'message' => $data['note'] ?? null,
            'appointment_date' => $data['date'],
            'appointment_time' => $data['time'],
            'appointment_at' => $at,
            'status' => 'pending',
            'lead_id' => $lead->id,
        ]);

        if ($data['meeting'] === 'consult') {
            $this->service->setConsultBooking($preScreening, $booking);
        } else {
            $this->service->setConsentBooking($preScreening, $booking);
        }

        return back()->with('success', "Thanks — your preferred time has been sent. We'll confirm shortly.");
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
