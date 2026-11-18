<?php

namespace App\Services\Hr;

use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\HrPolicyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PolicyService
{
    public function saveDraft(User $actor, string $title, string $body, ?HrPolicy $policy = null, int $revision = 0): HrPolicy
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'policies.publish'), 403);
        $data = $this->validate($title, $body);

        return DB::transaction(function () use ($actor, $data, $policy, $revision) {
            $draft = $policy ? HrPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail() : new HrPolicy;
            abort_unless((int) $draft->revision === $revision, 409, 'This policy draft changed. Refresh before saving.');
            $draft->forceFill(['draft_title' => $data['title'], 'draft_body' => $data['body'], 'revision' => $revision + 1])->save();
            app(HrAudit::class)->record($actor, 'policy.draft_saved', $draft, ['version' => $draft->revision]);

            return $draft;
        });
    }

    public function publish(User $actor, HrPolicy $policy, string $title, string $body): HrPolicyVersion
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'policies.publish'), 403);
        $data = $this->validate($title, $body);

        return DB::transaction(function () use ($actor, $policy, $data) {
            $current = HrPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->revision === $policy->revision, 409, 'The policy changed. Refresh before publishing.');
            $version = HrPolicyVersion::create(['hr_policy_id' => $policy->id, 'version' => (int) $current->versions()->max('version') + 1, ...$data, 'published_by' => $actor->id, 'published_at' => now()]);
            $current->update(['draft_title' => $data['title'], 'draft_body' => $data['body'], 'revision' => $current->revision + 1]);
            app(HrAudit::class)->record($actor, 'policy.published', $version, ['version' => $version->version]);

            return $version;
        });
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
        return Validator::make(compact('title','body'),['title' => 'required|string|max:200', 'body' => 'required|string|max:100000'])->validate();
    }
}
