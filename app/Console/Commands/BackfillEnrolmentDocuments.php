<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill: registration / free-assessment document uploads (CV, Passport,
 * Diploma, Transcript) that were saved to the private disk and referenced in
 * education_notes['uploaded_files'] but were never recorded as LeadDocument
 * rows — so they never showed in the staff Documents tab. This turns each
 * still-on-disk file into a checklist-keyed LeadDocument. Idempotent: a file
 * already backed by a LeadDocument (matched on file_path) is skipped.
 *
 *   php artisan leads:backfill-enrolment-docs --dry-run   # report only
 *   php artisan leads:backfill-enrolment-docs             # create the rows
 */
class BackfillEnrolmentDocuments extends Command
{
    protected $signature = 'leads:backfill-enrolment-docs {--dry-run : List what would be created without writing} {--lead=* : Restrict to these numeric lead IDs}';

    protected $description = 'Create LeadDocument rows for registration/assessment uploads that only live in education_notes so they surface in the Documents tab.';

    // education_notes folder => checklist_key (mirrors persistEnrolmentUploads).
    private const FOLDER_KEYS = [
        'cv' => 'acad.cv',
        'passport' => 'pers.passport',
        'diploma' => 'acad.degree_diploma',
        'transcript' => 'acad.transcript',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $leadIds = $this->option('lead');
        if (collect($leadIds)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('--lead must contain positive numeric lead IDs.');

            return self::INVALID;
        }

        $created = 0;
        $skippedExisting = 0;
        $missingOnDisk = 0;
        $leadsTouched = 0;

        Lead::whereNotNull('education_notes')->when($leadIds, fn ($query) => $query->whereIn('id', $leadIds))->chunkById(200, function ($leads) use ($dry, &$created, &$skippedExisting, &$missingOnDisk, &$leadsTouched) {
            foreach ($leads as $lead) {
                $notes = $lead->education_notes;
                if (! is_array($notes) || empty($notes['uploaded_files']) || ! is_array($notes['uploaded_files'])) {
                    continue;
                }

                $leadHad = false;
                foreach ($notes['uploaded_files'] as $folder => $paths) {
                    $key = self::FOLDER_KEYS[$folder] ?? null;
                    if (! $key || ! is_array($paths)) {
                        continue;
                    }
                    foreach ($paths as $path) {
                        if (! is_string($path) || $path === '') {
                            continue;
                        }
                        // Idempotency — never duplicate a file we already track.
                        if (LeadDocument::where('lead_id', $lead->id)->where('file_path', $path)->exists()) {
                            $skippedExisting++;

                            continue;
                        }
                        // Historical intake uploads used public/enrolment-docs;
                        // newer ones use the private disk. Never recover arbitrary
                        // paths supplied outside the intake's storage namespace.
                        if (! str_starts_with($path, 'enrolment-docs/') || str_contains($path, '..') || str_contains($path, '\\')) {
                            $this->warn("  unsupported intake path (lead {$lead->lead_id})");
                            continue;
                        }
                        if (LeadDocument::where('file_path', $path)->where('lead_id', '!=', $lead->id)->exists()) {
                            $this->warn("  file already belongs to another lead (lead {$lead->lead_id})");
                            continue;
                        }
                        $disk = Storage::disk('local');
                        if (! $disk->exists($path) && Storage::disk('public')->exists($path)) {
                            $disk = Storage::disk('public');
                        }
                        if (! $disk->exists($path)) {
                            $missingOnDisk++;
                            $this->warn("  missing on disk: {$path} (lead {$lead->lead_id})");

                            continue;
                        }

                        $leadHad = true;
                        $created++;
                        $this->line(sprintf('  + lead %s  %s  ->  %s', $lead->lead_id, $key, basename($path)));

                        if ($dry) {
                            continue;
                        }

                        LeadDocument::create([
                            'lead_id' => $lead->id,
                            'checklist_key' => $key,
                            'original_name' => basename($path),
                            'file_path' => $path,
                            'mime' => rescue(fn () => $disk->mimeType($path), null),
                            'size' => rescue(fn () => $disk->size($path), null),
                            'status' => LeadDocument::STATUS_SUBMITTED,
                            'source' => LeadDocument::SOURCE_UPLOAD,
                        ]);
                    }
                }

                if ($leadHad) {
                    $leadsTouched++;
                }
            }
        });

        $this->newLine();
        $this->info(($dry ? '[dry-run] would create' : 'created').": {$created} document row(s) across {$leadsTouched} lead(s).");
        if ($skippedExisting) {
            $this->line("skipped (already tracked): {$skippedExisting}");
        }
        if ($missingOnDisk) {
            $this->warn("missing on disk (skipped): {$missingOnDisk}");
        }

        return self::SUCCESS;
    }
}
