<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PageBoundaries;

final class IssuedEducationAgreement
{
    public static function checksum(LeadDocument $document): ?string
    {
        $disk = Storage::disk('local');
        if ($document->mime !== 'application/pdf' || ! $document->file_path || ! $disk->exists($document->file_path)) {
            return null;
        }

        return hash_file('sha256', $disk->path($document->file_path)) ?: null;
    }

    public function sign(Lead $lead, LeadDocument $document, string $viewedHash, string $signature, string $extension, ?string $signerName, ?string $ip): void
    {
        $disk = Storage::disk('local');
        $written = [];

        try {
            DB::transaction(function () use ($lead, $document, $viewedHash, $signature, $extension, $signerName, $ip, $disk, &$written) {
                $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                $document = $lead->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                abort_if($document->client_signed_at || $document->client_signature_path, 409, 'This agreement has already been signed.');
                abort_unless($document->isEducationAgreement() && $document->status !== LeadDocument::STATUS_REJECTED, 409, 'This agreement is unavailable for signing.');

                $issuedPath = $document->file_path;
                $issued = $document->mime === 'application/pdf' && $issuedPath ? $disk->get($issuedPath) : null;
                if (! $issued || ! hash_equals(hash('sha256', $issued), $viewedHash)) {
                    throw ValidationException::withMessages(['issued_agreement_hash' => 'The issued agreement is unavailable or has changed. Reload and review it before signing.']);
                }

                $signedAt = now();
                $name = $signerName ?: trim("{$lead->first_name} {$lead->last_name}");
                $signaturePage = Pdf::loadView('agreements.client-signature-confirmation', [
                    'name' => $name,
                    'signedAt' => $signedAt->copy()->tz('Pacific/Auckland')->format('j M Y, H:i T'),
                    'signature' => 'data:image/'.$extension.';base64,'.base64_encode($signature),
                    'hash' => $viewedHash,
                ])->setPaper('a4')->output();

                // Copy the issued pages without re-rendering their terms or price.
                $pdf = new Fpdi;
                $pdf->SetAutoPageBreak(false);
                foreach ([$issued, $signaturePage] as $source) {
                    $pages = $pdf->setSourceFile(StreamReader::createByString($source));
                    for ($page = 1; $page <= $pages; $page++) {
                        $template = $pdf->importPage($page, PageBoundaries::CROP_BOX, true, true);
                        $size = $pdf->getTemplateSize($template);
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($template);
                    }
                }
                $signedPdf = $pdf->Output('S');

                $token = Str::random(16);
                $signaturePath = "signatures/client-doc-{$document->id}-{$token}.{$extension}";
                $signedPath = "lead-documents/{$lead->id}/signed-{$document->id}-{$token}.pdf";
                foreach ([$signaturePath => $signature, $signedPath => $signedPdf] as $path => $bytes) {
                    $written[] = $path;
                    if (! $disk->put($path, $bytes)) {
                        throw new \RuntimeException('Could not store the signed agreement.');
                    }
                }

                $notes = $document->notes ?? [];
                $notes['issued_agreement'] = ['file_path' => $issuedPath, 'sha256' => $viewedHash];
                $document->forceFill([
                    'file_path' => $signedPath, 'size' => strlen($signedPdf), 'notes' => $notes,
                    'client_signature_path' => $signaturePath, 'client_signed_at' => $signedAt,
                    'client_signer_name' => $name, 'client_signer_ip' => $ip,
                ])->saveOrFail();

                if ($document->checklist_key === 'agree.consultancy') {
                    $current = array_search($lead->status, Lead::STAGES, true);
                    $signed = array_search('Consultancy Agreement Signed', Lead::STAGES, true);
                    if ($signed !== false && ($current === false || $current < $signed)) {
                        $lead->status = 'Consultancy Agreement Signed';
                        $lead->saveOrFail();
                    }
                }
            });
        } catch (\Throwable $error) {
            if ($written !== []) {
                $disk->delete($written);
            }

            throw $error;
        }
    }
}
