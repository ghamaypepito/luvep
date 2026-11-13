<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadDocument;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pushes APPROVED client documents into a per-client folder inside a Google
 * Workspace Shared Drive, using a service account. Each client gets one
 * folder (named by client name); every approved document lands inside it.
 *
 * Dormant until BOTH `services.google_drive.key_file` (a readable path to the
 * service-account JSON) and `services.google_drive.shared_drive_id` are set —
 * `isConfigured()` gates all callers, so the app runs normally without it.
 *
 * All Drive calls pass `supportsAllDrives`/Shared-Drive params because a
 * service account can only own files inside a Shared Drive, never My Drive.
 */
class GoogleDriveService
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private ?Drive $drive = null;

    public static function isConfigured(): bool
    {
        $keyFile = self::keyFilePath();

        return $keyFile !== null
            && is_file($keyFile)
            && filled(config('services.google_drive.shared_drive_id'));
    }

    /** Absolute path to the service-account JSON, or null. Relative paths resolve from base_path(). */
    private static function keyFilePath(): ?string
    {
        $path = config('services.google_drive.key_file');
        if (blank($path)) {
            return null;
        }

        // Allow a repo-relative path (e.g. storage/app/google/sa.json).
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path)
            ? $path
            : base_path($path);
    }

    protected function drive(): Drive
    {
        if ($this->drive) {
            return $this->drive;
        }

        $client = new GoogleClient;
        $client->setAuthConfig(self::keyFilePath());
        $client->setScopes([Drive::DRIVE]);

        return $this->drive = new Drive($client);
    }

    private function sharedDriveId(): string
    {
        return (string) config('services.google_drive.shared_drive_id');
    }

    /**
     * The single entry point the job calls: ensure the client folder exists,
     * then upload (or overwrite) this document inside it. Stored file IDs are
     * reused; record metadata supports recovery after interrupted uploads.
     */
    public function syncApprovedDocument(LeadDocument $doc): bool
    {
        // Serialize local workers for the same record; names are never identity.
        return DB::transaction(function () use ($doc) {
            $lead = Lead::whereKey($doc->lead_id)->lockForUpdate()->first();
            $doc = LeadDocument::whereKey($doc->id)->lockForUpdate()->first();
            if (! $lead || ! $doc || (int) $doc->lead_id !== (int) $lead->id || $doc->status !== LeadDocument::STATUS_APPROVED) {
                return false;
            }

            [$disk, $path] = $this->resolveFile($doc);
            if (! $disk) {
                // Nothing on disk to upload — skip quietly (the caller logs).
                return false;
            }

            $folderId = $this->ensureClientFolder($lead);
            $bytes = Storage::disk($disk)->get($path);
            if (! is_string($bytes)) {
                throw new RuntimeException('Stored document could not be read; no Drive upload was completed.');
            }
            $name = $doc->original_name ?: ('document-'.$doc->id.'.pdf');
            $mime = $doc->mime ?: 'application/octet-stream';

            // Recover a successful remote create if the local transaction previously failed.
            $fileId = $doc->gdrive_file_id ?: $this->findDocument($doc, $folderId);
            if ($fileId) {
                $remote = $this->drive()->files->get($fileId, [
                    'supportsAllDrives' => true, 'fields' => 'id,parents,driveId,trashed,appProperties',
                ]);
                $properties = $remote->getAppProperties() ?? [];
                if ($remote->getTrashed() || $remote->getDriveId() !== $this->sharedDriveId()
                    || ! in_array($folderId, $remote->getParents() ?? [], true)
                    || (isset($properties['luvep_document_id']) && $properties['luvep_document_id'] !== (string) $doc->id)
                || (isset($properties['luvep_lead_id']) && $properties['luvep_lead_id'] !== (string) $lead->id)
                    || LeadDocument::where('gdrive_file_id', $fileId)->whereKeyNot($doc->id)->exists()) {
                    throw new RuntimeException('Drive file mapping requires authorised record review before upload.');
                }
                // Overwrite the existing Drive file's contents in place.
                $this->drive()->files->update(
                    $fileId,
                    new DriveFile(['name' => $name]),
                    ['data' => $bytes, 'mimeType' => $mime, 'uploadType' => 'multipart', 'supportsAllDrives' => true],
                );
            } else {
                $created = $this->drive()->files->create(
                    new DriveFile(['name' => $name, 'parents' => [$folderId],
                        'appProperties' => ['luvep_document_id' => (string) $doc->id, 'luvep_lead_id' => (string) $lead->id]]),
                    ['data' => $bytes, 'mimeType' => $mime, 'uploadType' => 'multipart', 'supportsAllDrives' => true, 'fields' => 'id'],
                );
                $fileId = $created->getId();
            }

            $doc->forceFill([
                'gdrive_file_id' => $fileId,
                'gdrive_synced_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Find (or create) this client's folder in the Shared Drive, cache its id
     * on the lead, optionally share it with the configured recipient, and
     * store the human folder URL on `student_gdrive_link` so the existing
     * "Open GDrive" buttons work.
     */
    public function ensureClientFolder(Lead $lead): string
    {
        return DB::transaction(function () use ($lead) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $folderId = $lead->gdrive_folder_id ?: ($this->findFolder($lead) ?? $this->createFolder($lead));
            $folder = $this->drive()->files->get($folderId, [
                'supportsAllDrives' => true, 'fields' => 'id,mimeType,parents,driveId,trashed,appProperties',
            ]);
            $properties = $folder->getAppProperties() ?? [];
            if ($folder->getTrashed() || $folder->getMimeType() !== self::FOLDER_MIME
                || $folder->getDriveId() !== $this->sharedDriveId()
                || (isset($properties['luvep_lead_id']) && $properties['luvep_lead_id'] !== (string) $lead->id)
                || Lead::withTrashed()->where('gdrive_folder_id', $folderId)->whereKeyNot($lead->id)->exists()) {
                throw new RuntimeException('Drive folder mapping requires authorised record review before upload.');
            }

            // Existing untagged mappings are retained, not adopted by a name search.
            // Their historical contents/ACLs still require owner verification.
            // Complete initial sharing before saving the new mapping. Do not
            // re-grant a reader's access after the Drive owner later revokes it.
            if (! $lead->gdrive_folder_id) {
                $this->shareFolderIfConfigured($folderId);
            }

            $webLink = 'https://drive.google.com/drive/folders/'.$folderId;
            $lead->forceFill([
                'gdrive_folder_id' => $folderId,
                // Only backfill the link if staff hasn't set one manually.
                'student_gdrive_link' => $lead->student_gdrive_link ?: $webLink,
            ])->save();

            return $folderId;
        });
    }

    private function folderName(Lead $lead): string
    {
        $client = trim("{$lead->first_name} {$lead->last_name}") ?: ('Client '.$lead->id);
        $prefix = (string) config('services.google_drive.folder_prefix', '');

        return trim($prefix.$client).' [Lead '.$lead->id.']';
    }

    private function findFolder(Lead $lead): ?string
    {
        $result = $this->drive()->files->listFiles([
            'q' => "appProperties has { key='luvep_lead_id' and value='{$lead->id}' } and '".$this->queryValue($this->sharedDriveId())."' in parents and mimeType = '".self::FOLDER_MIME."' and trashed = false",
            'corpora' => 'drive',
            'driveId' => $this->sharedDriveId(),
            'includeItemsFromAllDrives' => true,
            'supportsAllDrives' => true,
            'fields' => 'nextPageToken,files(id,name)',
            'pageSize' => 2,
        ]);

        $files = $result->getFiles();

        if (count($files) > 1 || $result->getNextPageToken()) {
            throw new RuntimeException('Multiple Drive folders match this record; authorised review is required.');
        }

        return $files ? $files[0]->getId() : null;
    }

    private function createFolder(Lead $lead): string
    {
        $folder = $this->drive()->files->create(
            new DriveFile([
                'name' => $this->folderName($lead),
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$this->sharedDriveId()],
                'appProperties' => ['luvep_lead_id' => (string) $lead->id],
            ]),
            ['supportsAllDrives' => true, 'fields' => 'id'],
        );

        return $folder->getId();
    }

    private function shareFolderIfConfigured(string $folderId): void
    {
        $email = config('services.google_drive.share_with');
        if (blank($email)) {
            return;
        }

        $page = null;
        do {
            $permissions = $this->drive()->permissions->listPermissions($folderId, array_filter([
                'supportsAllDrives' => true, 'fields' => 'nextPageToken,permissions(type,emailAddress,role,deleted)', 'pageToken' => $page,
            ]));
            foreach ($permissions->getPermissions() as $permission) {
                if (! $permission->getDeleted() && $permission->getType() === 'user'
                    && strcasecmp((string) $permission->getEmailAddress(), trim($email)) === 0
                    && in_array($permission->getRole(), ['reader', 'commenter', 'writer', 'fileOrganizer', 'organizer', 'owner'], true)) {
                    return;
                }
            }
            $page = $permissions->getNextPageToken();
        } while ($page);

        $this->drive()->permissions->create(
            $folderId,
            new Permission(['type' => 'user', 'role' => 'reader', 'emailAddress' => $email]),
            ['supportsAllDrives' => true, 'sendNotificationEmail' => false],
        );
    }

    private function queryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function findDocument(LeadDocument $doc, string $folderId): ?string
    {
        $result = $this->drive()->files->listFiles([
            'q' => "appProperties has { key='luvep_document_id' and value='{$doc->id}' } and '".$this->queryValue($folderId)."' in parents and trashed = false",
            'corpora' => 'drive', 'driveId' => $this->sharedDriveId(),
            'includeItemsFromAllDrives' => true, 'supportsAllDrives' => true,
            'fields' => 'nextPageToken,files(id)', 'pageSize' => 2,
        ]);
        $files = $result->getFiles();
        if (count($files) > 1 || $result->getNextPageToken()) {
            throw new RuntimeException('Multiple Drive files match this document; authorised review is required.');
        }

        return $files ? $files[0]->getId() : null;
    }

    /**
     * Resolve which disk holds the document file. Mirrors the download
     * controller's local-first, public-fallback resolution so it works
     * regardless of where the upload originally landed.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveFile(LeadDocument $doc): array
    {
        $path = (string) $doc->file_path;
        if ($path === '') {
            return [null, ''];
        }
        if (Storage::disk('local')->exists($path)) {
            return ['local', $path];
        }
        if (Storage::disk('public')->exists($path)) {
            return ['public', $path];
        }

        return [null, $path];
    }
}
