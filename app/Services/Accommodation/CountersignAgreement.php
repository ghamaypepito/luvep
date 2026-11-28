<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

class CountersignAgreement
{
    public function eligible(AccommodationAgreement $agreement): bool
    {
        return $agreement->archived_at === null
            && $agreement->status === AccommodationAgreement::STATUS_SIGNED
            && filled($agreement->signed_pdf_path)
            && empty($agreement->data['admin_signature'])
            && empty($agreement->data['exalt_countersignature']);
    }

    public function originalHash(AccommodationAgreement $agreement): ?string
    {
        $disk = Storage::disk('local');

        return $this->eligible($agreement) && $disk->exists($agreement->signed_pdf_path)
            ? (hash_file('sha256', $disk->path($agreement->signed_pdf_path)) ?: null)
            : null;
    }

    public function sign(AccommodationAgreement $agreement, User $actor, string $reviewedHash): void
    {
        $disk = Storage::disk('local');
        $written = null;

        try {
            DB::transaction(function () use ($agreement, $actor, $reviewedHash, $disk, &$written) {
                $agreement = AccommodationAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
                if (! $this->eligible($agreement) || ! $disk->exists($agreement->signed_pdf_path)) {
                    throw ValidationException::withMessages(['original_hash' => 'This agreement is no longer available to countersign.']);
                }

                $bytes = $disk->get($agreement->signed_pdf_path);
                if (! $bytes || ! hash_equals(hash('sha256', $bytes), $reviewedHash)) {
                    throw ValidationException::withMessages(['original_hash' => 'The original changed. Reopen and review the client-signed PDF.']);
                }

                $signatory = app(AgreementTerms::class)->signatory();
                abort_unless($signatory && $signatory->is($actor), 403);
                $signature = $signatory->signatureDataUriTrimmed();
                if (! $signature) {
                    throw ValidationException::withMessages(['confirmed' => 'The approved profile signature could not be loaded.']);
                }

                $at = now();
                $page = Pdf::loadView('agreements.flatmate.countersignature', [
                    'agreement' => $agreement,
                    'name' => AgreementTerms::SIGNATORY,
                    'signedAt' => $at->copy()->timezone('Pacific/Auckland')->format('j M Y, H:i T'),
                    'signature' => $signature,
                    'hash' => $reviewedHash,
                ])->setPaper('a4')->output();

                $pdf = new Fpdi;
                $pdf->SetAutoPageBreak(false);
                foreach ([$bytes, $page] as $source) {
                    $count = $pdf->setSourceFile(StreamReader::createByString($source));
                    for ($number = 1; $number <= $count; $number++) {
                        $template = $pdf->importPage($number);
                        $size = $pdf->getTemplateSize($template);
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($template);
                    }
                }

                $written = 'accommodation-agreements/countersigned-'.$agreement->id.'-'.Str::random(16).'.pdf';
                if (! $disk->put($written, $pdf->Output('S'))) {
                    throw new \RuntimeException('Could not store the countersigned copy.');
                }

                $agreement->update(['data' => array_merge($agreement->data ?? [], ['exalt_countersignature' => [
                    'path' => $written, 'original_hash' => $reviewedHash,
                    'user_id' => $actor->id, 'name' => AgreementTerms::SIGNATORY, 'signed_at' => $at->toIso8601String(),
                ]])]);
            });
        } catch (Throwable $error) {
            if ($written !== null) {
                $disk->delete($written);
            }
            if ($error instanceof PdfParserException) {
                throw ValidationException::withMessages(['original_hash' => 'The original PDF could not be copied. Keep it and ask support to review the file.']);
            }

            throw $error;
        }
    }
}
