<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\AgreementSigningLink;
use App\Models\AccommodationAgreement;
use App\Models\ActivityLog;
use App\Models\EoiSubmission;
use App\Models\TenantDocument;
use App\Services\Accommodation\AgreementBuilder;
use App\Services\Accommodation\AgreementTerms;
use App\Services\OnboardingTransitionService;
use App\Support\OnboardingPipeline;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Forms → Agreements module. Left pane = one client list merged from both
 * pre-tenancy sources (general + onboarding); right pane = agreement-type
 * picker, auto-filled editable fields and a live preview. Generating renders
 * the chosen Exalt agreement to a stored PDF and, when the client came from an
 * onboarding record, links it so the onboarding Agreement stage can source it.
 */
class AccommodationAgreementController extends Controller
{
    /** Title shown at the top of each agreement type. */
    private const TITLES = [
        'whole_property' => 'Flat/House Sharing Agreement',
        'standard_flatmate' => 'Flat/House Sharing Agreement',
        'transient_flatmate' => 'Transient Flatmate Agreement — Short-Term',
    ];

    public function __construct(private readonly AgreementBuilder $builder) {}

    public function index(Request $request)
    {
        TenantDocument::authorizeStaff($request->user());
        $canManage = $request->user()->is_active && ! $request->attributes->get('user_view_actor');
        $agreements = AccommodationAgreement::with('creator')->latest()->get()->map(fn ($a) => [
            'id' => $a->id,
            'type' => $a->agreement_type,
            'type_label' => $a->typeLabel(),
            'client_name' => $a->client_name,
            'status' => $a->status,
            'signing_url' => $a->archived_at ? null : $a->signingUrl(),
            'tenant_id' => $a->tenant_id,
            'can_link_tenant' => $canManage && ! $a->archived_at && $a->status === 'signed',
            'signed' => $a->status === AccommodationAgreement::STATUS_SIGNED,
            'can_resend_copy' => ! $a->archived_at && $a->status === AccommodationAgreement::STATUS_SIGNED && ($a->data['workflow_version'] ?? null) === 2,
            'can_delete' => $canManage && $a->canDeleteFromGeneratedList(),
            'archived_at' => $a->archived_at?->toIso8601String(),
            'created_at' => optional($a->created_at)->toIso8601String(),
            'created_by' => optional($a->creator)->name,
        ]);
        [$archived, $active] = $agreements->partition(fn ($a) => $a['archived_at'] !== null);

        $properties = \App\Models\Property::where('is_active', true)
            ->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->address ?: $p->name])->values()->all();

        return inertia('portal/accommodation/forms/Agreements', [
            'clients' => $this->builder->clients(),
            'agreements' => $active->values(),
            'archivedAgreements' => $archived->sortByDesc('archived_at')->values(),
            'canManageAgreements' => $canManage,
            'types' => AccommodationAgreement::TYPES,
            'properties' => $properties,
            'signatoryReady' => app(AgreementTerms::class)->signatory() !== null,
            // Immigration visa catalogue — drives the per-sub-tenant Visa Type dropdown.
            'visaTypes' => \App\Models\VisaType::where('active', true)->orderBy('name')->pluck('name')->values()->all(),
        ]);
    }

    /** JSON: auto-fill data for a chosen client (source + id). */
    public function prefill(Request $request)
    {
        \App\Models\TenantDocument::authorizeStaff(request()->user());
        $v = $request->validate([
            'source' => 'required|in:general,onboarding',
            'id' => 'required|integer',
        ]);

        $prefill = $this->builder->prefill($v['source'], (int) $v['id']);
        abort_if($prefill === null, 404);

        return response()->json(['data' => $prefill]);
    }

    /** Live HTML preview of the agreement for the current (edited) fields. */
    public function preview(Request $request)
    {
        \App\Models\TenantDocument::authorizeStaff(request()->user());
        $v = $request->validate([
            'agreement_type' => 'required|in:'.implode(',', array_keys(self::TITLES)),
            'data' => 'nullable|array',
        ]);

        $data = app(AgreementTerms::class)->calculate($v['data'] ?? []);
        $signatory = app(AgreementTerms::class)->signatory();
        $html = view('agreements.flatmate.agreement', [
            'agreementType' => $v['agreement_type'],
            'data' => $data,
            'titles' => self::TITLES,
            'preview' => true,
            // Confirmed Exalt signatory, independent of the generating staff account.
            'adminSignature' => $signatory?->signatureDataUriTrimmed(),
            'adminName' => AgreementTerms::SIGNATORY,
        ])->render();

        return $request->expectsJson() ? response()->json(['html' => $html, 'data' => $data])
            : response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Render the agreement to a stored PDF, generate a signing token, and email
     * the client the link to review & e-sign. When the client came from an
     * onboarding record, advance it to "Agreement sent".
     */
    public function generate(Request $request, OnboardingTransitionService $transition)
    {
        \App\Models\TenantDocument::authorizeStaff(request()->user());
        $v = $request->validate([
            'agreement_type' => 'required|in:'.implode(',', array_keys(self::TITLES)),
            'source' => 'required|in:general,onboarding',
            'source_id' => 'required|integer',
            'data' => 'required|array',
        ]);

        $ctx = $this->builder->resolve($v['source'], (int) $v['source_id']);
        abort_if($ctx === null, 404);

        $data = app(AgreementTerms::class)->calculate($v['data'], issuing: true);
        $signatory = app(AgreementTerms::class)->signatory();
        if (! $signatory) {
            throw \Illuminate\Validation\ValidationException::withMessages(['agreement' => 'Alane\'s approved Accommodation account must be active and have a saved signature before issuing agreements.']);
        }
        if (! filter_var($ctx['email'], FILTER_VALIDATE_EMAIL)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['agreement' => 'Add a valid client email to the pre-tenancy source before issuing the agreement.']);
        }

        $adminSignature = $signatory->signatureDataUriTrimmed();
        if (! $adminSignature) {
            throw \Illuminate\Validation\ValidationException::withMessages(['agreement' => 'The approved Exalt signature is unavailable. Restore it before issuing an agreement.']);
        }

        try {
            // Snapshot the confirmed Exalt signatory so later profile changes cannot alter this agreement.
            $adminName = AgreementTerms::SIGNATORY;
            $data['admin_signature'] = $adminSignature;
            $data['admin_name'] = $adminName;
            $data['admin_user_id'] = $signatory->id;
            $data['recipient_email'] = $ctx['email'];

            $pdf = Pdf::loadView('agreements.flatmate.agreement', [
                'agreementType' => $v['agreement_type'],
                'data' => $data,
                'titles' => self::TITLES,
                'preview' => false,
                'adminSignature' => $adminSignature,
                'adminName' => $adminName,
            ])->setPaper('a4')->output();

            $name = Str::slug(($ctx['name'] ?: 'client').'-'.$v['agreement_type']);
            $path = "accommodation-agreements/{$name}-".Str::random(6).'.pdf';
            if (! Storage::disk('local')->put($path, $pdf)) {
                throw new \RuntimeException('Agreement PDF could not be stored.');
            }

            $agreement = AccommodationAgreement::create([
                'agreement_type' => $v['agreement_type'],
                'pre_tenancy_form_id' => $ctx['pre_tenancy_form_id'],
                'eoi_submission_id' => $ctx['eoi_submission_id'],
                'property_id' => $data['property_id'],
                'client_name' => $ctx['name'],
                'property_address' => $data['property_address'] ?? $ctx['property_address'],
                'data' => $data,
                'status' => AccommodationAgreement::STATUS_SENT,
                'pdf_path' => $path,
                'signing_token' => Str::random(48),
                'sent_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            // Email the client the signing link.
            $emailed = false;
            if (! empty($ctx['email'])) {
                try {
                    Mail::to($ctx['email'])->queue(new AgreementSigningLink(
                        $agreement->client_name ?: 'there',
                        $agreement->typeLabel(),
                        $agreement->signingUrl(),
                    ));
                    $emailed = true;
                } catch (\Throwable $e) {
                    Log::error('Agreement signing email failed', ['agreement' => $agreement->id, 'error' => $e->getMessage()]);
                }
            }

            // Advance the onboarding record to "Agreement sent" when applicable.
            if ($ctx['eoi_submission_id']) {
                $sub = EoiSubmission::find($ctx['eoi_submission_id']);
                if ($sub && OnboardingPipeline::canTransition($sub->status, 'agreement_sent')) {
                    $transition->transition($sub, 'agreement_sent');
                }
            }

            $msg = 'Agreement generated for '.$agreement->client_name.'.';
            $msg .= $emailed
                ? ' Signing link emailed to the client.'
                : (empty($ctx['email']) ? ' No client email on file — copy the signing link to send it.' : ' The email could not be queued.');

            return redirect()->route('portal.accommodation.forms.agreements')->with('success', $msg);
        } catch (\Throwable $e) {
            if (isset($path) && ! isset($agreement)) {
                Storage::disk('local')->delete($path);
            }
            Log::error('Accommodation agreement generate failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not generate the agreement. Please try again.');
        }
    }

    /** Re-send the signing link to the client. */
    public function resend(AccommodationAgreement $agreement)
    {
        \App\Models\TenantDocument::authorizeStaff(request()->user());
        if ($agreement->status === AccommodationAgreement::STATUS_SIGNED && ($agreement->data['workflow_version'] ?? null) === 2) {
            abort_unless($agreement->signed_pdf_path && Storage::disk('local')->exists($agreement->signed_pdf_path), 404);
            Mail::to($agreement->data['recipient_email'])->queue(new \App\Mail\AccommodationSignedCopy($agreement->client_name, $agreement->signed_pdf_path));

            return back()->with('success', 'Signed agreement copy queued for the original client recipient.');
        }
        $email = $agreement->eoiSubmission?->email
            ?? $agreement->preTenancyForm?->email;

        if (! $agreement->signing_token) {
            $agreement->update(['signing_token' => Str::random(48), 'status' => AccommodationAgreement::STATUS_SENT, 'sent_at' => now()]);
        }
        if (! $email) {
            return back()->with('error', 'No client email on file — copy the signing link to send it manually.');
        }

        Mail::to($email)->queue(new AgreementSigningLink($agreement->client_name ?: 'there', $agreement->typeLabel(), $agreement->signingUrl()));

        return back()->with('success', 'Signing link re-sent to '.$email.'.');
    }

    public function download(Request $request, AccommodationAgreement $agreement)
    {
        \App\Models\TenantDocument::authorizeStaff(request()->user());
        // Prefer the signed PDF (with the embedded signature + audit block).
        $path = ($agreement->signed_pdf_path && Storage::disk('local')->exists($agreement->signed_pdf_path))
            ? $agreement->signed_pdf_path
            : $agreement->pdf_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $suffix = $agreement->signed_pdf_path === $path ? '-signed' : '';
        $file = Str::slug(($agreement->client_name ?: 'agreement').'-'.$agreement->agreement_type).$suffix.'.pdf';

        // Inline (preview in a new tab) vs attachment (download).
        if ($request->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($path), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$file.'"',
            ]);
        }

        return Storage::disk('local')->download($path, $file);
    }

    public function archive(Request $request, AccommodationAgreement $agreement)
    {
        return $this->setArchived($request, $agreement, true);
    }

    public function restore(Request $request, AccommodationAgreement $agreement)
    {
        return $this->setArchived($request, $agreement, false);
    }

    private function setArchived(Request $request, AccommodationAgreement $agreement, bool $archive)
    {
        $this->authorizeAgreementManagement($request);
        DB::transaction(function () use ($agreement, $archive) {
            $current = AccommodationAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            if (($current->archived_at !== null) === $archive) {
                return;
            }
            $current->archived_at = $archive ? now() : null;
            $current->save();
            ActivityLog::record($archive ? 'accommodation.agreement.archived' : 'accommodation.agreement.restored', [
                'portal' => 'accommodation',
                'entity_type' => AccommodationAgreement::class,
                'entity_id' => $current->id,
            ]);
        });

        return back()->with('success', $archive ? 'Agreement archived.' : 'Agreement restored.');
    }

    private function authorizeAgreementManagement(Request $request): void
    {
        TenantDocument::authorizeStaff($request->user());
        abort_unless($request->user()->is_active && ! $request->attributes->get('user_view_actor'), 403);
    }

    public function destroy(AccommodationAgreement $agreement)
    {
        $this->authorizeAgreementManagement(request());
        $result = DB::transaction(function () use ($agreement) {
            $current = AccommodationAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            if (! $current->canDeleteFromGeneratedList()) {
                return null;
            }
            $path = $current->pdf_path;
            ActivityLog::record('accommodation.agreement.deleted', [
                'portal' => 'accommodation',
                'entity_type' => AccommodationAgreement::class,
                'entity_id' => $current->id,
                'properties' => ['client_name' => $current->client_name, 'status' => $current->status],
            ]);
            $current->delete();

            return ['path' => $path];
        });
        if ($result === null) {
            return back()->with('error', 'Signed, partially signed, or tenant-linked agreements must be retained. Restore archived unsigned agreements before deleting them.');
        }
        // A failed/rolled-back delete must never remove the agreement's evidence.
        if ($result['path']) {
            Storage::disk('local')->delete($result['path']);
        }

        return back()->with('success', 'Agreement deleted.');
    }
}
