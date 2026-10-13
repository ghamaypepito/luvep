<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\AgreementSigningLink;
use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Services\Accommodation\AgreementBuilder;
use App\Services\OnboardingTransitionService;
use App\Support\OnboardingPipeline;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
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

    public function index()
    {
        $agreements = AccommodationAgreement::with('creator')->latest()->get()->map(fn ($a) => [
            'id' => $a->id,
            'type' => $a->agreement_type,
            'type_label' => $a->typeLabel(),
            'client_name' => $a->client_name,
            'status' => $a->status,
            'signing_url' => $a->signingUrl(),
            'signed' => $a->status === AccommodationAgreement::STATUS_SIGNED,
            'created_at' => optional($a->created_at)->toIso8601String(),
            'created_by' => optional($a->creator)->name,
        ]);

        $properties = \App\Models\Property::where('is_active', true)
            ->get()->map(fn ($p) => $p->address ?: $p->name)->filter()->unique()->values()->all();

        return inertia('portal/accommodation/forms/Agreements', [
            'clients' => $this->builder->clients(),
            'agreements' => $agreements,
            'types' => AccommodationAgreement::TYPES,
            'properties' => $properties,
        ]);
    }

    /** JSON: auto-fill data for a chosen client (source + id). */
    public function prefill(Request $request)
    {
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
        $v = $request->validate([
            'agreement_type' => 'required|in:'.implode(',', array_keys(self::TITLES)),
            'data' => 'nullable|array',
        ]);

        $html = view('agreements.flatmate.agreement', [
            'agreementType' => $v['agreement_type'],
            'data' => $v['data'] ?? [],
            'titles' => self::TITLES,
            'preview' => true,
            // Exalt Property Admin signatory — the generating user's saved signature.
            'adminSignature' => $request->user()->signatureDataUriTrimmed(),
            'adminName' => $request->user()->name,
        ])->render();

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Render the agreement to a stored PDF, generate a signing token, and email
     * the client the link to review & e-sign. When the client came from an
     * onboarding record, advance it to "Agreement sent".
     */
    public function generate(Request $request, OnboardingTransitionService $transition)
    {
        $v = $request->validate([
            'agreement_type' => 'required|in:'.implode(',', array_keys(self::TITLES)),
            'source' => 'required|in:general,onboarding',
            'source_id' => 'required|integer',
            'data' => 'required|array',
        ]);

        $ctx = $this->builder->resolve($v['source'], (int) $v['source_id']);
        abort_if($ctx === null, 404);

        try {
            // Bake the generating user's saved signature onto the Exalt Property
            // Admin signatory, and snapshot it in `data` so the signed PDF re-render
            // (and any later re-render) reproduces the same admin signature.
            $admin = $request->user();
            $adminSignature = $admin->signatureDataUriTrimmed();
            $adminName = $admin->name;
            $data = $v['data'];
            $data['admin_signature'] = $adminSignature;
            $data['admin_name'] = $adminName;

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
            Storage::disk('local')->put($path, $pdf);

            $agreement = AccommodationAgreement::create([
                'agreement_type' => $v['agreement_type'],
                'pre_tenancy_form_id' => $ctx['pre_tenancy_form_id'],
                'eoi_submission_id' => $ctx['eoi_submission_id'],
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
            Log::error('Accommodation agreement generate failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not generate the agreement. Please try again.');
        }
    }

    /** Re-send the signing link to the client. */
    public function resend(AccommodationAgreement $agreement)
    {
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

    public function destroy(AccommodationAgreement $agreement)
    {
        if ($agreement->pdf_path && Storage::disk('local')->exists($agreement->pdf_path)) {
            Storage::disk('local')->delete($agreement->pdf_path);
        }
        $agreement->delete();

        return back()->with('success', 'Agreement deleted.');
    }
}
