<?php

namespace App\Console\Commands;

use App\Models\LeadDocument;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;

/**
 * One-shot backfill: pushes every APPROVED LeadDocument into its client's
 * Google Drive folder. Needed because documents approved before Drive was
 * configured had their background job run and no-op, and those jobs never
 * retry. Skips stored Drive IDs by default; --all reuses their existing files.
 * New remote objects carry record metadata for interrupted-upload recovery.
 */
class SyncApprovedDocumentsToDrive extends Command
{
    protected $signature = 'gdrive:sync-approved
        {--document= : Restrict the run to one approved document ID}
        {--all : Re-sync every approved document, including ones already in Drive}';

    protected $description = 'Push approved client documents into Google Drive (backfill for the pre-configuration backlog)';

    public function handle(GoogleDriveService $drive): int
    {
        if (! GoogleDriveService::isConfigured()) {
            $this->error('Google Drive is not configured (key file and/or shared drive id missing). Nothing to do.');

            return self::FAILURE;
        }

        $documentId = $this->option('document');
        if ($documentId !== null && (! ctype_digit((string) $documentId) || (int) $documentId < 1)) {
            $this->error('The document option must be a positive document ID.');

            return self::FAILURE;
        }

        $query = LeadDocument::with('lead')
            ->where('status', LeadDocument::STATUS_APPROVED)
            ->when($documentId !== null, fn ($q) => $q->whereKey((int) $documentId));

        if (! $this->option('all')) {
            // Default: only documents not yet in Drive.
            $query->whereNull('gdrive_file_id');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No approved documents need syncing in the requested scope.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$total} approved document(s) to Google Drive...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];

        $query->orderBy('id')->chunkById(50, function ($docs) use ($drive, &$synced, &$skipped, &$failed, &$failures, $bar) {
            foreach ($docs as $doc) {
                try {
                    if ($drive->syncApprovedDocument($doc)) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $failures[] = "  #{$doc->id} ({$doc->original_name}): {$e->getMessage()}";
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Done. Synced: {$synced}  Skipped: {$skipped}  Failed: {$failed}");

        if ($failed > 0) {
            $this->warn('Failures:');
            foreach ($failures as $line) {
                $this->line($line);
            }

            return self::FAILURE;
        }

        if ($skipped > 0) {
            $this->warn('Skipped documents were not uploaded. Check their current approval, client and stored file before retrying.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
