<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SocialMediaFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Choose a valid media file.');

            return;
        }
        $mime = $value->getMimeType();
        if ($mime === 'video/mp4') {
            if ($value->getSize() >= 50 * 1024 * 1024) {
                $fail('Choose an MP4 smaller than 50MB.');
            }

            return;
        }
        $pixels = @getimagesize($value->getRealPath());
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) || ! $pixels
            || $pixels['mime'] !== $mime || $value->getSize() >= 10 * 1024 * 1024
            || $pixels[0] > 8192 || $pixels[1] > 8192 || $pixels[0] * $pixels[1] > 40000000) {
            $fail('Upload PNG, JPEG, WebP or GIF under 10MB, 8192px per side and 40 megapixels, or MP4 under 50MB.');
        }
    }
}
