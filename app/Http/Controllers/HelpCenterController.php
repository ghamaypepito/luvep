<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HelpCenterController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAccessHelpCenter(), 403);

        $catalogue = require resource_path('help/guides.php');
        $guides = array_values(array_filter($catalogue['guides'], fn ($guide) => ! $guide['admin_only'] || $user->isAdmin()));
        $departmentIds = array_unique(array_merge(...array_column($guides, 'departments')));

        return inertia('help/Index', [
            'guides' => $guides,
            'departments' => array_values(array_filter($catalogue['departments'], fn ($department) => in_array($department['id'], $departmentIds, true))),
        ]);
    }
}
