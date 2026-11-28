<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationAgreement;
use App\Models\TenantDocument;
use App\Services\Accommodation\AgreementEmail;
use App\Services\Accommodation\AgreementTerms;
use App\Services\Accommodation\CountersignAgreement;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class AgreementHistoryController extends Controller
{
    public function show(Request $request, AccommodationAgreement $agreement, CountersignAgreement $countersign)
    {
        TenantDocument::authorizeStaff($request->user());

        $signature = $agreement->data['exalt_countersignature'] ?? [];
        $signed = $agreement->status === AccommodationAgreement::STATUS_SIGNED;
        $canCountersign = $this->isSignatory($request);
        $hasOriginal = $signed && $agreement->signed_pdf_path && Storage::disk('local')->exists($agreement->signed_pdf_path);

        return inertia('portal/accommodation/forms/AgreementHistory', [
            'agreement' => [
                'id' => $agreement->id, 'name' => $agreement->client_name, 'type' => $agreement->typeLabel(),
                'issued' => $agreement->sent_at?->toIso8601String(), 'viewed' => $agreement->viewed_at?->toIso8601String(),
                'signed' => $agreement->signed_at?->toIso8601String(),
                'tracksAllTenants' => ($agreement->data['workflow_version'] ?? null) === 2,
                'exaltSigned' => $agreement->data['admin_signed_on'] ?? null,
                'countersign' => Arr::only($signature, ['name', 'signed_at']),
            ],
            'emails' => $agreement->emails()->oldest('id')->get()->map(fn ($email) => [
                'id' => $email->id, 'kind' => $email->kind, 'status' => $email->status,
                'at' => ($email->sent_at ?? $email->updated_at)?->toIso8601String(),
            ]),
            'originalUrl' => $hasOriginal ? route('portal.accommodation.forms.agreements.download', $agreement) : null,
            'countersignedUrl' => ! empty($signature['path']) ? route('portal.accommodation.forms.agreements.countersigned-pdf', $agreement) : null,
            'reviewHash' => $canCountersign ? $countersign->originalHash($agreement) : null,
            'needsExaltSignature' => $hasOriginal && $countersign->eligible($agreement),
            'canEmailExalt' => $hasOriginal && ($agreement->data['layout_version'] ?? null) === AccommodationAgreement::LAYOUT_EXALT_2026_11
                && ! $request->attributes->get('user_view_actor'),
            'exaltMailboxConfigured' => AgreementEmail::exaltAddress() !== null,
        ]);
    }

    public function countersign(Request $request, AccommodationAgreement $agreement, CountersignAgreement $countersign)
    {
        abort_unless($this->isSignatory($request), 403);
        $input = $request->validate(['original_hash' => 'required|regex:/^[a-f0-9]{64}$/', 'confirmed' => 'required|accepted']);
        $countersign->sign($agreement, $request->user(), $input['original_hash']);

        return back()->with('success', 'Countersigned copy saved. The original client-signed PDF is retained.');
    }

    public function download(Request $request, AccommodationAgreement $agreement)
    {
        TenantDocument::authorizeStaff($request->user());
        $path = $agreement->data['exalt_countersignature']['path'] ?? null;
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->download($path, 'exalt-countersigned-agreement-'.$agreement->id.'.pdf');
    }

    public function emailExalt(Request $request, AccommodationAgreement $agreement, AgreementEmail $email)
    {
        TenantDocument::authorizeStaff($request->user());
        abort_if($request->attributes->get('user_view_actor'), 403);
        abort_unless($agreement->status === AccommodationAgreement::STATUS_SIGNED
            && ($agreement->data['layout_version'] ?? null) === AccommodationAgreement::LAYOUT_EXALT_2026_11, 409);

        return $email->completedCopy($agreement, exalt: true)
            ? back()->with('success', 'Completed PDF queued for Exalt.')
            : back()->with('error', 'Could not queue the PDF for Exalt. Check the configured mailbox and stored PDF.');
    }

    private function isSignatory(Request $request): bool
    {
        return ! $request->attributes->get('user_view_actor')
            && $request->user()->email === AgreementTerms::SIGNATORY_EMAIL
            && app(AgreementTerms::class)->signatory()?->is($request->user());
    }
}
