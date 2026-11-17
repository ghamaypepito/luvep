<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\ResidentIntake;
use Illuminate\Support\Facades\Storage;

/**
 * Surfaces applicant uploads that were captured *outside* the LeadDocument
 * table into a case's Documents tab.
 *
 * Two legacy upload paths never created LeadDocument rows, so their files
 * silently missed the case profile:
 *   1. Resident intakes store files on the intake as `document_files`
 *      (local disk, resident-intakes/{id}/…).
 *   2. Free-assessment / education-enrolment uploads land in the Lead's
 *      `education_notes['uploaded_files']` (public disk, enrolment-docs/…),
 *      plus a standalone `passport_pdf`.
 *
 * Each migrate call is idempotent — a file already linked to the lead (same
 * file_path) is skipped — so it is safe to re-run.
 */
class IntakeDocumentMigrator
{
    private const LABELS = [
        'passport' => 'Passport',
        'visa_copies' => 'NZ visa copies',
        'contracts' => 'Employment contracts + JD',
        'payslips' => 'Payslips',
        'ird_summary' => 'IRD summary of earnings',
        'education_certs' => 'Education certificates',
        'cv' => 'CV',
        'diploma' => 'Diploma',
        'transcript' => 'Transcript',
        'other' => 'Other supporting documents',
    ];

    // ── Resident intake uploads (document_files, local disk) ────────────────

    public static function fromResidentIntake(ResidentIntake $intake, Lead $lead): int
    {
        return self::migratePaths($lead, self::normalize($intake->document_files), 'local');
    }

    public static function residentFileCount(ResidentIntake $intake): int
    {
        return self::countPaths(self::normalize($intake->document_files));
    }

    // ── Work intake uploads (document_files, local disk) ────────────────────

    public static function fromWorkIntake(\App\Models\WorkIntake $intake, Lead $lead): int
    {
        return self::migratePaths($lead, self::normalize($intake->document_files), 'local');
    }

    /**
     * Generic private-disk document_files carry-over for any intake that has the
     * shared document tab (Student / Visitor / Family — same shape as Resident).
     */
    public static function fromIntake($intake, Lead $lead): int
    {
        return self::migratePaths($lead, self::normalize($intake->document_files ?? null), 'local');
    }

    // ── Lead-level uploads (education_notes.uploaded_files + passport, public) ─

    public static function fromLeadUploads(Lead $lead): int
    {
        return self::migratePaths($lead, self::leadPaths($lead), 'public');
    }

    public static function leadUploadCount(Lead $lead): int
    {
        return self::countPaths(self::leadPaths($lead));
    }

    /** [key => [paths...]] of every non-LeadDocument file recorded on the lead. */
    public static function leadPaths(Lead $lead): array
    {
        $notes = $lead->education_notes;
        $out = self::normalize(is_array($notes) ? ($notes['uploaded_files'] ?? []) : []);

        if (! empty($lead->passport_pdf) && is_string($lead->passport_pdf)) {
            $out['passport'] = array_merge($out['passport'] ?? [], [$lead->passport_pdf]);
        }

        return $out;
    }

    // ── Shared helpers ──────────────────────────────────────────────────────

    /** Coerce a stored files value into [key => [paths...]]. */
    private static function normalize($files): array
    {
        if (! is_array($files) || $files === []) {
            return [];
        }
        $out = [];
        foreach ($files as $key => $paths) {
            $out[$key] = array_values(array_filter((array) $paths, fn ($p) => is_string($p) && $p !== ''));
        }

        return $out;
    }

    private static function countPaths(array $map): int
    {
        return collect($map)->reduce(fn ($n, $paths) => $n + count((array) $paths), 0);
    }

    private static function migratePaths(Lead $lead, array $map, string $disk): int
    {
        $created = 0;
        foreach ($map as $key => $paths) {
            foreach ($paths as $path) {
                if (LeadDocument::where('lead_id', $lead->id)->where('file_path', $path)->exists()) {
                    continue;
                }
                $onDisk = Storage::disk($disk)->exists($path);
                $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'pdf';
                $label = self::LABELS[$key] ?? ucwords(str_replace(['_', '-'], ' ', (string) $key));

                LeadDocument::create([
                    'lead_id' => $lead->id,
                    'checklist_key' => is_string($key) ? $key : null,
                    'original_name' => $label.'.'.$ext,
                    'file_path' => $path,
                    'mime' => $onDisk ? (Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream') : 'application/octet-stream',
                    'size' => $onDisk ? (int) Storage::disk($disk)->size($path) : 0,
                    'status' => LeadDocument::STATUS_SUBMITTED,
                    'source' => LeadDocument::SOURCE_UPLOAD,
                ]);
                $created++;
            }
        }

        return $created;
    }
}
