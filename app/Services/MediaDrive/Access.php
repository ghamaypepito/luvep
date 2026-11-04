<?php

namespace App\Services\MediaDrive;

use App\Models\Lead;
use App\Models\MediaFolder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public function canEnter(User $user): bool
    {
        return $user->is_active && in_array($user->role, [...config('hr.internal_roles'), 'agent', 'sub_agent'], true);
    }

    public function canShare(User $user): bool
    {
        return $this->canEnter($user) && ! in_array($user->role, ['agent', 'sub_agent'], true);
    }

    public function leads(User $user): Builder
    {
        $query = Lead::query();
        if (! $this->canEnter($user)) {
            return $query->whereRaw('1=0');
        }
        if ($user->isAdmin()) {
            return $query;
        }
        if (in_array($user->role, ['agent', 'sub_agent'], true)) {
            return $query->where('agent_id', $user->role === 'agent' ? $user->id : ($user->parent_agent_id ?: -1));
        }

        return $query->where(function ($q) use ($user) {
            $q->whereRaw('1=0');
            $education = $user->canAccessPortal('education');
            $immigration = $user->canAccessPortal('immigration');
            if ($user->canAccessPortal('sales') || $education || $immigration) {
                $q->orWhere(fn ($w) => $w->inLeadPipeline());
            }
            if ($education || $immigration) {
                $q->orWhere(fn ($w) => $w->inStudentsRegister());
            }
            if ($immigration) {
                $q->orWhere(fn ($w) => $w->immigrationCase());
            }
            if ($user->canAccessPortal('english')) {
                $q->orWhere('is_english_student', true);
            }
            if ($user->canAccessPortal('accommodation')) {
                $q->orWhere('is_accommodation_client', true);
            }
            if ($user->canAccessPortal('finance')) {
                $q->orWhereExists(fn ($w) => $w->selectRaw('1')->from('case_financials')->whereColumn('case_financials.lead_id', 'leads.id'));
                $q->orWhereExists(fn ($w) => $w->selectRaw('1')->from('lead_documents')->whereColumn('lead_documents.lead_id', 'leads.id')->where(fn ($v) => $v->where('source_variant', 'invoice')->orWhere('source_variant', 'like', 'invoice:%')));
                $q->orWhereExists(fn ($w) => $w->selectRaw('1')->from('lead_tasks')->whereColumn('lead_tasks.lead_id', 'leads.id')->where('assignee_id', $user->id));
            }
        });
    }

    public function tenants(User $user): Builder
    {
        return Tenant::query()->when(! $this->canEnter($user) || (! $user->isAdmin() && $user->role !== 'accommodation'), fn ($q) => $q->whereRaw('1=0'));
    }

    public function roots(User $user): Builder
    {
        return MediaFolder::whereNull('parent_id')->where(fn ($q) => $q->whereIn('lead_id', $this->leads($user)->select('id'))->orWhereIn('tenant_id', $this->tenants($user)->select('id')));
    }

    public function assertFolder(User $user, MediaFolder $folder): MediaFolder
    {
        abort_unless($this->canEnter($user), 403);
        $root = $folder->ownerRoot();
        abort_unless($root && ($root->lead || $root->tenant), 404);
        abort_unless($this->roots($user)->whereKey($root->id)->exists(), 403);

        return $root;
    }

    public function client(User $user, string $type, int $id): Lead|Tenant
    {
        abort_unless($this->canEnter($user), 403);
        $record = match ($type) {
            'lead' => Lead::findOrFail($id),'tenant' => Tenant::findOrFail($id),default => abort(422)
        };
        abort_unless(($type === 'lead' ? $this->leads($user) : $this->tenants($user))->whereKey($id)->exists(), 403);

        return $record;
    }
}
