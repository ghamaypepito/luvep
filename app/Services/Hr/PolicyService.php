<?php

namespace App\Services\Hr;

use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\HrPolicyVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyService
{
    public function saveDraft(User $actor, string $title, string $body, ?HrPolicy $policy = null, int $revision = 0, ?UploadedFile $file = null): HrPolicy
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'policies.publish'), 403);
        $data = $this->validate($title, $body);
        $attachment = $file ? $this->storeAttachment($file) : null;
        try {
            return DB::transaction(function () use ($actor, $data, $policy, $revision, $attachment) {
                $draft = $policy ? HrPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail() : new HrPolicy;
                abort_unless((int) $draft->revision === $revision, 409, 'This policy draft changed. Refresh before saving.');
                $previous = $draft->draft_attachment;
                $draft->forceFill(['draft_title' => $data['title'], 'draft_body' => $data['body'], 'draft_attachment' => $attachment ?? $draft->draft_attachment, 'revision' => $revision + 1])->save();
                app(HrAudit::class)->record($actor, 'policy.draft_saved', $draft, ['version' => $draft->revision]);
                $this->pruneDraftAttachment($previous, $draft->draft_attachment);

                return $draft;
            });
        } catch (\Throwable $error) {
            if ($attachment) {
                Storage::disk('hr_private')->delete($attachment['storage_key']);
            }
            throw $error;
        }
    }

    public function publish(User $actor, HrPolicy $policy, string $title, string $body, ?UploadedFile $file = null): HrPolicyVersion
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'policies.publish'), 403);
        $data = $this->validate($title, $body);
        $attachment = $file ? $this->storeAttachment($file) : null;
        try {
            return DB::transaction(function () use ($actor, $policy, $data, $attachment) {
                $current = HrPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->revision === $policy->revision, 409, 'The policy changed. Refresh before publishing.');
                $snapshot = $attachment ?? $current->draft_attachment;
                $version = HrPolicyVersion::create(['hr_policy_id' => $policy->id, 'version' => (int) $current->versions()->max('version') + 1, ...$data, 'attachment' => $snapshot, 'published_by' => $actor->id, 'published_at' => now()]);
                $current->update(['draft_title' => $data['title'], 'draft_body' => $data['body'], 'draft_attachment' => $snapshot, 'revision' => $current->revision + 1]);
                app(HrAudit::class)->record($actor, 'policy.published', $version, ['version' => $version->version]);
                $this->pruneDraftAttachment($policy->draft_attachment, $snapshot);

                return $version;
            });
        } catch (\Throwable $error) {
            if ($attachment) {
                Storage::disk('hr_private')->delete($attachment['storage_key']);
            }
            throw $error;
        }
    }

    private function pruneDraftAttachment(?array $previous, ?array $current): void
    {
        if (! $previous || $previous['storage_key'] === ($current['storage_key'] ?? null)) {
            return;
        }
        DB::afterCommit(function () use ($previous) {
            try {
                $key = $previous['storage_key'];
                if (! HrPolicy::where('draft_attachment->storage_key', $key)->exists()
                    && ! HrPolicyVersion::where('attachment->storage_key', $key)->exists()) {
                    Storage::disk('hr_private')->delete($key);
                }
            } catch (\Throwable $error) {
                // Cleanup must not invalidate an already committed attachment.
                report($error);
            }
        });
    }

    private function storeAttachment(UploadedFile $file): array
    {
        $attributes = app(HrFile::class)->validate($file);
        $key = 'policies/'.Str::uuid().'.'.$attributes['extension'];
        try {
            if (! Storage::disk('hr_private')->put($key, file_get_contents($file->getRealPath()))) {
                throw new \RuntimeException('The private policy file could not be stored.');
            }
        } catch (\Throwable $error) {
            Storage::disk('hr_private')->delete($key);
            throw $error;
        }

        return ['storage_key' => $key, ...array_diff_key($attributes, ['extension' => true])];
    }

    public function attachment(?array $attachment, string $path): ?array
    {
        return $attachment ? ['name' => $attachment['original_filename'], 'mime' => $attachment['mime'], 'size' => $attachment['size'], 'url' => app(HrContext::class)->url($path)] : null;
    }

    public function download(User $actor, HrPolicyVersion|HrPolicy $policy): StreamedResponse
    {
        $access = app(HrAccess::class);
        $draft = $policy instanceof HrPolicy;
        abort_unless($access->internal($actor) && ($draft ? $access->allows($actor, 'policies.publish') : ($access->employee($actor) || $access->allows($actor, 'policies.publish'))), 404);
        $attachment = $draft ? $policy->draft_attachment : $policy->attachment;
        abort_unless($attachment && Storage::disk('hr_private')->exists($attachment['storage_key']), 404);
        app(HrAudit::class)->record($actor, 'policy.downloaded', $policy, ['version' => $draft ? $policy->revision : $policy->version]);

        return Storage::disk('hr_private')->download($attachment['storage_key'], $attachment['original_filename'], ['Content-Type' => $attachment['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function acknowledge(User $actor, HrPolicyVersion $version): HrPolicyAcknowledgement
    {
        abort_unless(app(HrAccess::class)->employee($actor), 403);

        return DB::transaction(function () use ($actor, $version) {
            HrPolicy::whereKey($version->hr_policy_id)->lockForUpdate()->firstOrFail();
            $ack = HrPolicyAcknowledgement::firstOrCreate(['hr_policy_version_id' => $version->id, 'user_id' => $actor->id], ['acknowledged_at' => now()]);
            if ($ack->wasRecentlyCreated) {
                app(HrAudit::class)->record($actor, 'policy.acknowledged', $version, ['version' => $version->version]);
            }

            return $ack;
        });
    }

    private function validate(string $title, string $body): array
    {
        return Validator::make(compact('title', 'body'), ['title' => 'required|string|max:200', 'body' => 'required|string|max:100000'])->validate();
    }
}
