<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\PermissionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccessController extends Controller
{
    public function index(Request $r)
    {
        abort_if(app(HrContext::class)->isPreview($r), 403);
        $access = app(HrAccess::class);
        abort_unless($access->allows($r->user(), 'access.manage'), 403);
        $accounts = User::whereIn('role', config('hr.internal_roles'))->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'active' => $u->is_active, 'version' => (int) $u->hr_access_version, 'grants' => collect((array) $u->module_permissions)->filter(fn ($g) => str_starts_with($g, 'hr.'))->map(fn ($g) => substr($g, 3))->values(), 'effective' => collect(config('hr.abilities'))->filter(fn ($g) => $access->allows($u, $g))->values(), 'editable' => ! $u->isSuperAdmin()]);

        return Inertia::render('hr/Access', ['accounts' => $accounts, 'abilities' => config('hr.grant_labels'), 'actorId' => $r->user()->id]);
    }

    public function update(Request $r, User $user)
    {
        $data = $r->validate(['grants' => 'present|array', 'version' => 'required|integer|min:0']);
        app(PermissionService::class)->replace($r->user(), $user, $data['grants'], (int) $data['version']);

        return $r->expectsJson() ? response()->json(['version' => $user->fresh()->hr_access_version]) : back()->with('success', 'HR access updated. Changes take effect on the next request.');
    }
}
