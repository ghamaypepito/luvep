<?php

namespace App\Http\Controllers;

use App\Models\LeadDocument;
use App\Services\EnglishAgreementAccess;
use App\Services\IssuedEducationAgreement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class EnglishAgreementAccessController extends Controller
{
    private function url(Request $request, LeadDocument $document, string $action): string
    {
        return URL::temporarySignedRoute('english-agreement.'.$action,
            Carbon::createFromTimestamp((int) $request->query('expires')), ['document' => $document->id]);
    }

    public function show(Request $request, LeadDocument $document)
    {
        EnglishAgreementAccess::assertAvailable($document);
        $signed = (bool) ($document->client_signed_at || $document->client_signature_path);

        return inertia('track/EnglishAgreement', ['document' => [
            'id' => $document->id, 'title' => 'English Agreement', 'original_name' => $document->original_name,
            'signed' => $signed, 'signed_at' => $document->client_signed_at?->toIso8601String(),
            'view_url' => $this->url($request, $document, 'download'),
            'download_url' => $this->url($request, $document, 'download'),
            'sign_url' => $signed ? null : $this->url($request, $document, 'sign'),
            'issued_agreement_hash' => $signed ? null : IssuedEducationAgreement::checksum($document),
        ]])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function download(Request $request, LeadDocument $document)
    {
        EnglishAgreementAccess::assertAvailable($document);
        $headers = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];

        // Stream only the verified document, never a caller-supplied path.
        return response()->file(Storage::disk('local')->path($document->file_path), $headers + [
            'Content-Type' => 'application/pdf',
        ])->setContentDisposition('inline', $document->original_name ?: 'English-agreement.pdf');
    }

    public function sign(Request $request, LeadDocument $document, IssuedEducationAgreement $agreements)
    {
        EnglishAgreementAccess::assertAvailable($document);
        abort_if($document->client_signed_at || $document->client_signature_path, 409, 'This agreement has already been signed.');
        $data = $request->validate([
            'acknowledged' => 'accepted', 'signature_data' => 'required|string|max:2800000',
            'issued_agreement_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $parts = [];
        $valid = preg_match('/^data:image\/(png|jpeg);base64,(.+)$/sD', $data['signature_data'], $parts);
        $bytes = $valid ? base64_decode($parts[2], true) : false;
        $image = $bytes && strlen($bytes) <= 2097152 ? @getimagesizefromstring($bytes) : false;
        if (! $image || $image[2] !== ($parts[1] === 'jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG)
            || max($image[0], $image[1]) > 4096 || ! @imagecreatefromstring($bytes)) {
            throw ValidationException::withMessages(['signature_data' => 'Draw a valid signature before signing.']);
        }
        try {
            $agreements->sign($document->lead, $document, $data['issued_agreement_hash'], $bytes, $parts[1], null, $request->ip());
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);

            return back()->withErrors(['signature_data' => 'Could not save your signature. Your issued agreement is unchanged; please try again.']);
        }

        return redirect($this->url($request, $document, 'show'))->with('success', 'Thank you — your English agreement has been signed.');
    }
}
