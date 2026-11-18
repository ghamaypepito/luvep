<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;

/**
 * Discussion follows the existing portal lists, including explicitly granted
 * staff portals. Kept separate from LeadAccess so conversion, bulk sends and
 * other workflows retain their own authorization contracts.
 */
class LeadDiscussionAccess
{
    public static function staffCanDiscuss(User $user, Lead $lead): bool
    {
        if (! $user->is_active || ! in_array($user->role, [
            'super_admin', 'admin', 'sales', 'education', 'english',
            'immigration', 'immigration_manager', 'immigration_adviser',
            'accommodation', 'finance',
        ], true)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $education = $user->canAccessPortal('education');
        $immigration = $user->canAccessPortal('immigration');
        $pipeline = $user->canAccessPortal('sales') || $education || $immigration;
        $english = $user->canAccessPortal('english');
        $accommodation = $user->canAccessPortal('accommodation');

        if (! $pipeline && ! $english && ! $accommodation) {
            return false;
        }

        return Lead::whereKey($lead->id)->where(function ($query) use ($pipeline, $education, $immigration, $english, $accommodation) {
            // Both Education and Immigration expose the shared Students
            // register as well as the unconverted Leads pipeline.
            $query->whereRaw('1 = 0');
            if ($pipeline) {
                $query->orWhere(fn ($q) => $q->inLeadPipeline());
            }
            if ($education || $immigration) {
                $query->orWhere(fn ($q) => $q->inStudentsRegister());
            }
            if ($immigration) {
                $query->orWhere(fn ($q) => $q->immigrationCase());
            }
            if ($english) {
                $query->orWhere('is_english_student', true);
            }
            if ($accommodation) {
                $query->orWhere('is_accommodation_client', true);
            }
        })->exists();
    }

    public static function canUseNotes(User $user, Lead $lead): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // Recruiting accounts keep their existing own-referral notebook;
        // portal grants never turn an external account into staff.
        $ownerId = match ($user->role) {
            'agent' => $user->id,
            'sub_agent' => $user->parent_agent_id,
            default => null,
        };
        if ($ownerId !== null && (int) $lead->agent_id === (int) $ownerId) {
            return true;
        }

        return self::staffCanDiscuss($user, $lead);
    }
}
