<?php

namespace App\EpAgent;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Access
{
    public const STAFF_ROLES = ['super_admin', 'admin', 'sales', 'education', 'english', 'immigration', 'immigration_manager', 'immigration_adviser', 'accommodation', 'finance'];

    public function assertStaff(User $user): void
    {
        abort_if(request()->attributes->has('user_view_actor'), 403, 'ep_private_identity_required');
        // Reload identity so queued or long-lived callers cannot use stale grants.
        $fresh = User::find($user->id);
        abort_unless(config('ep_agent.enabled') && $fresh && $fresh->is_active, 403, 'ep_unavailable');
        abort_unless(in_array($fresh->role, self::STAFF_ROLES, true), 403, 'ep_access_denied');
    }

    public function assertPortal(User $user, string $portal): void
    {
        $this->assertStaff($user);
        // Hard allowlist: a configuration typo must not authorize an unmapped portal.
        abort_unless(in_array($portal, ['education', 'immigration'], true)
            && in_array($portal, config('ep_agent.portals', []), true)
            && User::findOrFail($user->id)->canAccessPortal($portal), 403, 'ep_access_denied');
    }

    public function assertLead(User $user, string $portal, Lead $lead): void
    {
        $this->assertPortal($user, $portal);
        $lead = Lead::findOrFail($lead->id);
        abort_unless($portal === 'education' ? $lead->is_student : $lead->is_immigration_case, 403, 'ep_access_denied');
        $fresh = User::findOrFail($user->id);
        abort_unless($fresh->isAdmin() || (int) $lead->assigned_to === $fresh->id
            || DB::table('ep_record_grants')->where('user_id', $fresh->id)
                ->where('lead_id', $lead->id)->where('portal', $portal)->where('expires_at', '>', now())->exists(), 403, 'ep_access_denied');
    }

    public function leads(User $user, string $portal): \Illuminate\Database\Eloquent\Builder
    {
        $this->assertPortal($user, $portal);
        $fresh = User::findOrFail($user->id);
        $query = Lead::query()->where($portal === 'education' ? 'is_student' : 'is_immigration_case', true);
        if (! $fresh->isAdmin()) {
            $query->where(function ($scope) use ($fresh, $portal) {
                $scope->where('assigned_to', $fresh->id)->orWhereExists(function ($grant) use ($fresh, $portal) {
                    $grant->selectRaw('1')->from('ep_record_grants')->whereColumn('ep_record_grants.lead_id', 'leads.id')
                        ->where('ep_record_grants.user_id', $fresh->id)->where('ep_record_grants.portal', $portal)->where('expires_at', '>', now());
                });
            });
        }

        return $query;
    }

    /** Scope is applied in SQL before private conversation titles are selected. */
    public function conversations(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $this->assertStaff($user);
        $fresh = User::findOrFail($user->id);
        $portals = array_values(array_filter(
            array_intersect(['education', 'immigration'], config('ep_agent.portals', [])),
            fn ($portal) => $fresh->canAccessPortal($portal),
        ));

        return Conversation::query()->where('user_id', $fresh->id)->whereIn('portal', $portals)
            ->where(function ($scope) use ($fresh) {
                $scope->whereNull('lead_id')->orWhereExists(function ($lead) use ($fresh) {
                    $lead->selectRaw('1')->from('leads')->whereColumn('leads.id', 'ep_conversations.lead_id')
                        ->whereNull('leads.deleted_at')
                        ->where(function ($department) {
                            $department->where(fn ($q) => $q->where('ep_conversations.portal', 'education')->where('leads.is_student', true))
                                ->orWhere(fn ($q) => $q->where('ep_conversations.portal', 'immigration')->where('leads.is_immigration_case', true));
                        });
                    if (! $fresh->isAdmin()) {
                        $lead->where(function ($grant) use ($fresh) {
                            $grant->where('leads.assigned_to', $fresh->id)->orWhereExists(function ($q) use ($fresh) {
                                $q->selectRaw('1')->from('ep_record_grants')->where('ep_record_grants.user_id', $fresh->id)
                                    ->whereColumn('ep_record_grants.lead_id', 'leads.id')
                                    ->whereColumn('ep_record_grants.portal', 'ep_conversations.portal')->where('expires_at', '>', now());
                            });
                        });
                    }
                });
            });
    }
}
