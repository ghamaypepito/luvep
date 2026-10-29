<?php

namespace App\Support;

use App\Models\SystemTicket;
use App\Models\SystemTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Private ticket evidence. Raw storage paths never enter a response payload. */
class TicketAttachments
{
    public const MAX_FILES = 10;

    public const MAX_KB = 51200;

    public const TYPES = 'jpg,jpeg,png,webp,gif,mp4,webm,mov,pdf,doc,docx,xls,xlsx,csv,txt,zip';

    public static function rules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['required', 'file', 'mimes:'.self::TYPES, 'extensions:'.self::TYPES, 'max:'.self::MAX_KB],
        ];
    }

    public static function store(array $files, string $directory): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $path = $file->store($directory, 'local');
                if (! $path) {
                    throw new \RuntimeException('Could not store ticket attachment.');
                }
                $stored[] = ['path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize()];
            }
        } catch (\Throwable $e) {
            self::delete($stored);
            throw $e;
        }

        return $stored;
    }

    public static function delete(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            Storage::disk('local')->delete($attachment['path']);
        }
    }

    public static function serialize(array $attachments, string $base): array
    {
        return array_map(function ($file, $index) use ($base) {
            $url = url($base.'/'.$index);

            return ['name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size'], 'url' => $url.'?inline=1', 'download_url' => $url];
        }, $attachments, array_keys($attachments));
    }

    public static function message(SystemTicketMessage $message): array
    {
        return array_merge($message->toArray(), [
            'attachments' => self::serialize($message->attachments ?? [], "/system-tickets/{$message->ticket_id}/messages/{$message->id}/attachments"),
        ]);
    }

    public static function authorize(Request $request, SystemTicket $ticket): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ! in_array($user->role, ['lead', 'revoked_lead'], true), 403);
        abort_unless($user->isAdmin() || (int) $ticket->submitted_by === (int) $user->id, 403);
    }

    public static function stream(Request $request, array $files, int $index)
    {
        $file = $files[$index] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        $canPreview = in_array($file['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'], true);
        if ($request->boolean('inline') && $canPreview) {
            return response()->file(Storage::disk('local')->path($file['path']), array_merge($headers, ['Content-Type' => $file['mime']]));
        }

        return Storage::disk('local')->download($file['path'], $file['name'], $headers);
    }
}
