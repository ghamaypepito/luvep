<?php

namespace App\Services\MediaDrive;

use App\Models\Lead;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Recover a Drive display name without attaching its file or changing access. */
class UploadNames
{
    public function __construct(private Access $access) {}

    public function forUpload(Lead $lead, UploadedFile $file, ?User $actor): ?string
    {
        // Client and public-token uploads must not probe private Drive names.
        if (! $actor || ! $this->access->canEnter($actor)
            || ! $this->access->leads($actor)->whereKey($lead->id)->exists()) {
            return null;
        }

        $roots = MediaFolder::whereNull('parent_id')->where('lead_id', $lead->id)->select('id');
        $names = MediaFile::where('sha256', hash_file('sha256', $file->getRealPath()))
            ->where('size', $file->getSize())->where('original_name', '!=', '')
            ->whereHas('folder', fn ($query) => $query->whereIn('id', clone $roots)->orWhereIn('root_id', clone $roots))
            // Compare names exactly even on databases with case-insensitive collations.
            ->pluck('original_name')->uniqueStrict()->values();

        // Identical bytes may legitimately have several names. Do not guess.
        return $names->count() === 1 ? Uploads::safeName($names->first()) : null;
    }
}
