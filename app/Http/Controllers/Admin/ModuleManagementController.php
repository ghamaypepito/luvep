<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module Management — super-admin-only surface for granting per-user access to
 * RESTRICTED modules (config/modules.php `restricted`). Every other module is
 * grandfathered and stays visible by role; only the modules listed here are
 * hidden by default and toggled on per user.
 *
 * Access is gated by `portal:super_admin` on the routes.
 */
class ModuleManagementController extends Controller
{
    /**
     * Department portals a staff user can be granted access to beyond their own
     * role. Keys are portal keys (a subset of User::PORTAL_ROLES). A user always
     * keeps their own role's portal; these are the EXTRA ones.
     */
    private const GRANTABLE_PORTALS = [
        'sales' => 'Sales',
        'education' => 'Education',
        'english' => 'English',
        'immigration' => 'Immigration',
        'accommodation' => 'Accommodation',
        'finance' => 'Finance',
    ];

    public function index(Request $request)
    {
        $restricted = config('modules.restricted', []);

        $modules = collect($restricted)
            ->map(fn ($cfg, $key) => [
                'key' => $key,
                'label' => $cfg['label'] ?? ucfirst($key),
                'description' => $cfg['description'] ?? '',
                // Grantable sub-parts. Granting the whole module implies them all.
                'features' => collect($cfg['features'] ?? [])
                    ->map(fn ($f, $fk) => [
                        'key' => "{$key}.{$fk}",
                        'label' => $f['label'] ?? ucfirst($fk),
                        'description' => $f['description'] ?? '',
                    ])->values()->all(),
            ])
            ->values()
            ->all();

        // Every valid grantable key (parents + dotted features).
        $validKeys = $this->validKeys();

        // Staff accounts only — leads/clients don't have a module sidebar to gate.
        $users = User::query()
            ->whereNotIn('role', [User::ROLE_LEAD, User::ROLE_REVOKED_LEAD])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'module_permissions', 'portal_access'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                // Super admins implicitly hold every module + feature.
                'is_super_admin' => $u->isSuperAdmin(),
                // Admins already reach every portal — nothing extra to grant.
                'is_admin' => $u->isAdmin(),
                'modules' => $u->isSuperAdmin()
                    ? $validKeys
                    : array_values(array_intersect((array) ($u->module_permissions ?? []), $validKeys)),
                // Extra portals granted beyond the user's role (never includes
                // their own role's portal — that's implicit).
                'portals' => array_values(array_intersect(
                    (array) ($u->portal_access ?? []),
                    array_keys(self::GRANTABLE_PORTALS)
                )),
            ])
            ->values();

        $portals = collect(self::GRANTABLE_PORTALS)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();

        return inertia('admin/ModuleManagement', [
            'modules' => $modules,
            'users' => $users,
            'portals' => $portals,
        ]);
    }

    /**
     * Grant a staff user access to extra department portals (beyond their own
     * role). A user's own role portal is always available and is never stored
     * here. Admins already reach everything, so this is a no-op for them.
     */
    public function updatePortals(Request $request, User $user)
    {
        $data = $request->validate([
            'portals' => ['array'],
            'portals.*' => [Rule::in(array_keys(self::GRANTABLE_PORTALS))],
        ]);

        if ($user->isAdmin()) {
            return back()->with('error', 'Admins already have access to every portal.');
        }

        // Keep valid, de-duped keys; drop the user's own role portal (implicit).
        $granted = array_values(array_unique(array_intersect(
            $data['portals'] ?? [],
            array_keys(self::GRANTABLE_PORTALS)
        )));
        $granted = array_values(array_filter($granted, fn ($p) => $p !== $user->role));

        $user->update(['portal_access' => $granted]);

        return back()->with('success', "Portal access updated for {$user->name}.");
    }

    public function update(Request $request, User $user)
    {
        $validKeys = $this->validKeys();

        $data = $request->validate([
            'modules' => ['array'],
            'modules.*' => [Rule::in($validKeys)],
        ]);

        // Super admins already see everything — nothing to store for them.
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Super admins already have every module.');
        }

        // Keep valid, de-duped keys; drop a redundant feature key when its whole
        // parent module is granted (the grant already implies every feature).
        $granted = array_values(array_unique(array_intersect($data['modules'] ?? [], $validKeys)));
        $granted = array_values(array_filter($granted, function ($k) use ($granted) {
            if (! str_contains($k, '.')) {
                return true;
            }

            return ! in_array(explode('.', $k, 2)[0], $granted, true);
        }));

        $nameCorrection = in_array('case_name_correction', $granted, true);
        if ($nameCorrection && ! $user->isAdmin() && $user->role !== User::ROLE_IMMIGRATION_MANAGER) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'modules' => 'Name Correction can only be granted to an Immigration manager.',
            ]);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $granted, $nameCorrection) {
            $previous = in_array('case_name_correction', (array) $user->module_permissions, true);
            $user->update(['module_permissions' => $granted]);
            if ($previous !== $nameCorrection) {
                \App\Models\ActivityLog::record('case.name_correction_permission_changed', ['properties' => [
                    'subject_type' => 'User', 'subject_id' => $user->id, 'old' => $previous, 'new' => $nameCorrection,
                ]]);
            }
        });

        // The page acknowledges this save only after checking returned stored grants.
        return back();
    }

    /** Every grantable key: each restricted module plus its dotted feature keys. */
    private function validKeys(): array
    {
        $keys = [];
        foreach (config('modules.restricted', []) as $key => $cfg) {
            $keys[] = $key;
            foreach (array_keys($cfg['features'] ?? []) as $fk) {
                $keys[] = "{$key}.{$fk}";
            }
        }

        return $keys;
    }
}
