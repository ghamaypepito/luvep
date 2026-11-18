<?php

namespace App\Support;

use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

/** Removing a contextual document never removes immutable Media Drive bytes. */
class DocumentFiles
{
    public static function delete(?string $path, string $disk = 'local'): void
    {
        if (! $path) {
            return;
        }
        if (str_starts_with($path, 'media-drive/') && MediaFile::where('file_path', $path)->exists()) {
            return;
        }
        Storage::disk($disk)->delete($path);
    }
}
