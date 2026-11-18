<?php

namespace App\Http\Controllers;

use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Services\OnboardingTransitionService;
use App\Services\PdfGenerator;
use App\Support\OnboardingPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Public, tokenised e-signing surface for accommodation agreements — mirrors the
 * immigration tracker signing flow. The signing_token is the bearer credential
 * (emailed to the client). The client reviews the agreement, draws a signature,
 * and the signed PDF is baked with the signature image + an audit block.
 */
class AccommodationAgreementSigningController extends Controller
{
    private const TITLES = [
        'whole_property' => 'Flat/House Sharing Agreement',
        'standard_flatmate' => 'Flat/House Sharing Agreement',
        'transient_flatmate' => 'Transient Flatmate Agreement — Short-Term',
    ];

    public function show(string $token)
    {
        $agreement = AccommodationAgreement::where('signing_token', $token)->firstOrFail();

        if ($agreement->status === AccommodationAgreement::STATUS_SENT) {
            $agreement->update(['status' => AccommodationAgreement::STATUS_VIEWED, 'viewed_at' => now()]);
        }

        return inertia('accommodation/SignAgreement', [
            'token' => $token,
            'clientName' => $agreement->client_name,
            'typeLabel' => $agreement->typeLabel(),
            'documentUrl' => route('agreement.sign.document', $token),
            'signed' => $agreement->status === AccommodationAgreement::STATUS_SIGNED,
        ]);
    }

    /** The agreement rendered as HTML for the signing-page iframe. */
    public function document(string $token)
    {
        $agreement = AccommodationAgreement::where('signing_token', $token)->firstOrFail();

        return response($this->renderHtml($agreement, preview: true))->header('Content-Type', 'text/html');
    }

    public function sign(Request $request, string $token, OnboardingTransitionService $transition, PdfGenerator $pdf)
    {
        $agreement = AccommodationAgreement::where('signing_token', $token)->firstOrFail();

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:200'],
            'signature_data' => ['required', 'string', 'max:5000000'],
            'terms_accepted' => ['required', 'accepted'],
        ]);

        // State/idempotency guard — only an unsigned, sent/viewed agreement signs.
        abort_unless(
            in_array($agreement->status, [AccommodationAgreement::STATUS_SENT, AccommodationAgreement::STATUS_VIEWED], true),
            404,
            'This agreement is not available for signing.'
        );

        $agreement->fill([
            'signer_name' => $data['signer_name'],
            'signature_data' => $data['signature_data'],
            'signer_ip' => $request->ip(),
            'signer_user_agent' => $request->userAgent(),
            'signed_at' => now(),
            'status' => AccommodationAgreement::STATUS_SIGNED,
        ])->save();

        // Bake the signed PDF (signature image + audit block embedded).
        try {
            $path = 'accommodation-agreements/signed-'.$agreement->id.'-'.Str::random(6).'.pdf';
            $agreement->signed_pdf_path = $pdf->renderToFile($this->renderHtml($agreement, preview: false, signed: true), $path);
            $agreement->save();
        } catch (\Throwable $e) {
            Log::error('Signed agreement PDF failed', ['agreement' => $agreement->id, 'error' => $e->getMessage()]);
        }

        // Advance the onboarding record to "Agreement signed" when applicable.
        if ($agreement->eoi_submission_id) {
            $sub = EoiSubmission::find($agreement->eoi_submission_id);
            if ($sub && OnboardingPipeline::canTransition($sub->status, 'agreement_signed')) {
                $transition->transition($sub, 'agreement_signed');
            }
        }

        $this->notifyStaff($agreement);

        return redirect()->route('agreement.sign.signed', $token);
    }

    public function signed(string $token)
    {
        $agreement = AccommodationAgreement::where('signing_token', $token)->firstOrFail();
        abort_unless($agreement->status === AccommodationAgreement::STATUS_SIGNED, 404);

        return inertia('accommodation/AgreementSigned', [
            'clientName' => $agreement->client_name,
            'typeLabel' => $agreement->typeLabel(),
        ]);
    }

    /** Stream the signed PDF to the client from the signing surface. */
    public function downloadSigned(string $token)
    {
        $agreement = AccommodationAgreement::where('signing_token', $token)->firstOrFail();
        abort_unless($agreement->signed_pdf_path && Storage::disk('local')->exists($agreement->signed_pdf_path), 404);

        return Storage::disk('local')->download($agreement->signed_pdf_path, Str::slug(($agreement->client_name ?: 'agreement')).'-signed.pdf');
    }

    private function renderHtml(AccommodationAgreement $agreement, bool $preview, bool $signed = false): string
    {
        $extra = [];
        if ($signed || $agreement->status === AccommodationAgreement::STATUS_SIGNED) {
            $extra['signature'] = $agreement->signature_data;
            $extra['signedMeta'] = [
                'signer_name' => $agreement->signer_name,
                'signed_at' => optional($agreement->signed_at)->format('d M Y'),
                'signer_ip' => $agreement->signer_ip,
            ];
        }

        return View::make('agreements.flatmate.agreement', array_merge([
            'agreementType' => $agreement->agreement_type,
            'data' => $agreement->data ?? [],
            'titles' => self::TITLES,
            'preview' => $preview,
            // Exalt Property Admin signatory snapshotted at generate time.
            'adminSignature' => $agreement->data['admin_signature'] ?? null,
            'adminName' => $agreement->data['admin_name'] ?? null,
        ], $extra))->render();
    }

    private function notifyStaff(AccommodationAgreement $agreement): void
    {
        try {
            $recipients = \App\Models\User::whereIn('role', [
                'accommodation', \App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPER_ADMIN,
            ])->get();
            if ($recipients->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send($recipients, new \App\Notifications\AccommodationAgreementSigned($agreement));
            }
        } catch (\Throwable $e) {
            Log::error('Agreement signed notify failed', ['agreement' => $agreement->id, 'error' => $e->getMessage()]);
        }
    }
}
