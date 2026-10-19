<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $context = app(HrContext::class);
        $employee = $context->isPreview($request) ? $context->subject($request) : app(HrAccess::class)->employee($request->user());

        return Inertia::render('hr/Dashboard', ['profile' => EmployeeController::profileData($employee, $employee?->user ?? $request->user())]);
    }
}
