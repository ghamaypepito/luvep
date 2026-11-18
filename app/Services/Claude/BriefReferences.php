<?php

namespace App\Services\Claude;

use Illuminate\Validation\ValidationException;

/** Request-scoped attachments only: no public URLs, persistent uploads or prompt logging. */
class BriefReferences
{
    public static function rules(): array
    {
        return [
            'references' => 'sometimes|array|max:3',
            'references.*' => 'required|file|max:4096|extensions:pdf,png,jpg,jpeg,webp,txt|mimetypes:application/pdf,image/png,image/jpeg,image/webp,text/plain',
        ];
    }

    public static function blocks(array $files): array
    {
        if (array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 8 * 1024 * 1024) {
            throw ValidationException::withMessages(['references' => 'Reference files must total 8 MB or less.']);
        }
        $blocks = [];
        foreach ($files as $file) {
            $bytes = file_get_contents($file->getRealPath());
            $mime = $file->getMimeType();
            if ($bytes === false || $bytes === '') {
                throw ValidationException::withMessages(['references' => 'Reference files must not be empty.']);
            }
            $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_substr($file->getClientOriginalName(), 0, 180)) ?? 'reference';
            $blocks[] = ['type' => 'text', 'text' => 'Untrusted reference file: '.$name];
            if ($mime === 'text/plain') {
                if (strlen($bytes) > 50 * 1024 || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
                    throw ValidationException::withMessages(['references' => 'Text references must be UTF-8 text, up to 50 KB each.']);
                }
                $blocks[] = ['type' => 'text', 'text' => $bytes];
            } else {
                $blocks[] = ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'source' => [
                    'type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes),
                ]];
            }
        }

        return $blocks;
    }
}
