<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Services\OnboardingTransitionService;
use App\Services\PdfGenerator;
use App\Support\OnboardingPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompleteAgreement
{
    public function sign(Request $request, string $token, callable $render): AccommodationAgreement
    {
        $data = $request->validate([
            'signer_name' => 'required|string|max:200', 'signature_data' => 'required|string|max:3000000',
            'terms_accepted' => 'required|accepted',
            'signer_index' => 'nullable|integer|min:0|max:9',
        ]);
        $image = null;
        if (preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $data['signature_data'], $matches)) {
            $bytes = base64_decode($matches[2], true);
            $image = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        }
        if (! $image || $image[0] > 4000 || $image[1] > 4000 || ! in_array($image['mime'], ['image/png', 'image/jpeg'], true)) {
            throw ValidationException::withMessages(['signature_data' => 'Provide a valid PNG or JPEG signature, up to 4000 pixels per side.']);
        }
        $path = null;
        try {
            return DB::transaction(function () use ($token, $data, $request, $render, &$path) {
                $agreement = AccommodationAgreement::where('signing_token', $token)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($agreement->status, ['sent', 'viewed'], true), 409, 'This agreement has already been signed.');
                $index = (int) ($data['signer_index'] ?? 0);
                $snapshot = $agreement->data;
                abort_unless(isset($snapshot['subtenants'][$index]), 422, 'Select a tenant listed on the issued agreement.');
                $signatures = $snapshot['tenant_signatures'] ?? [];
                abort_if(isset($signatures[$index]), 409, 'This tenant has already signed.');
                $signatures[$index] = [
                    'name' => $data['signer_name'], 'image' => $data['signature_data'],
                    'at' => now()->toIso8601String(), 'ip' => $request->ip(), 'user_agent' => $request->userAgent(),
                ];
                if (array_sum(array_map(fn ($signature) => strlen($signature['image']), $signatures)) > 2000000) {
                    throw ValidationException::withMessages(['signature_data' => 'The combined signature images must be under 2 MB. Use a smaller image or the Type/Draw option.']);
                }
                $snapshot['tenant_signatures'] = $signatures;
                $agreement->data = $snapshot;
                if (count($signatures) < count($snapshot['subtenants'])) {
                    $agreement->status = AccommodationAgreement::STATUS_VIEWED;
                    $agreement->save();

                    return $agreement;
                }
                $agreement->fill([
                    'signer_name' => $data['signer_name'], 'signature_data' => $data['signature_data'],
                    'status' => AccommodationAgreement::STATUS_SIGNED,
                    'signer_ip' => $request->ip(), 'signer_user_agent' => $request->userAgent(), 'signed_at' => now(),
                ]);
                $path = 'accommodation-agreements/signed-'.$agreement->id.'-'.Str::random(12).'.pdf';
                $agreement->signed_pdf_path = app(PdfGenerator::class)->renderToFile($render($agreement), $path);
                if (! Storage::disk('local')->exists($agreement->signed_pdf_path)) {
                    throw new \RuntimeException('Signed PDF was not stored.');
                }
                $agreement->save();
                app(SignedAgreementTenant::class)->link($agreement);
                if ($agreement->eoi_submission_id) {
                    $sub = EoiSubmission::whereKey($agreement->eoi_submission_id)->lockForUpdate()->firstOrFail();
                    if (OnboardingPipeline::canTransition($sub->status, 'agreement_signed')) {
                        app(OnboardingTransitionService::class)->transition($sub, 'agreement_signed');
                    }
                }

                return $agreement;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
