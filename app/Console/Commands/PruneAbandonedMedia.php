<?php

namespace App\Console\Commands;

use App\Models\LeadDocument;
use App\Models\MediaFile;
use App\Models\TenantDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PruneAbandonedMedia extends Command
{
    protected $signature = 'media-drive:prune-abandoned {--dry-run : Count abandoned files without removing them}';

    protected $description = 'Remove up to 100 unreferenced Media Drive staging files older than 24 hours';

    public function handle(): int
    {
        $lock = Cache::lock('media-drive-prune', 600);
        if (! $lock->get()) {
            $this->info('Media Drive cleanup is already running.');

            return self::SUCCESS;
        }
        try {
            $disk = Storage::disk('local');
            $directory = $disk->path('media-drive/assets');
            if (! is_dir($directory)) {
                $this->info('No abandoned uploads.');

                return self::SUCCESS;
            }
            $cutoff = now()->subDay()->timestamp;
            $count = 0;
            $batch = [];
            $prune = function (array $paths) use ($disk, &$count) {
                $used = array_fill_keys(array_merge(MediaFile::whereIn('file_path', $paths)->pluck('file_path')->all(), LeadDocument::whereIn('file_path', $paths)->pluck('file_path')->all(), TenantDocument::whereIn('path', $paths)->pluck('path')->all()), true);
                foreach ($paths as $path) {
                    if ($count >= 100) {
                        break;
                    } if (isset($used[$path])) {
                        continue;
                    } if ($this->option('dry-run') || $disk->delete($path)) {
                        $count++;
                    }
                }
            };
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink() || $file->getMTime() >= $cutoff) {
                    continue;
                }
                $relative = 'media-drive/assets/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
                $batch[] = $relative;
                if (count($batch) >= 200) {
                    $prune($batch);
                    $batch = [];
                    if ($count >= 100) {
                        break;
                    }
                }
            }
            if ($batch && $count < 100) {
                $prune($batch);
            }
            $this->info($this->option('dry-run') ? "Eligible abandoned uploads: {$count}." : "Removed {$count} abandoned uploads.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
