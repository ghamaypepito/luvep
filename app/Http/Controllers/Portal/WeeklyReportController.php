<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportWeek;
use Illuminate\Http\Request;

class WeeklyReportController extends Controller
{
    public function save(Request $request, string $department)
    {
        $scope = $department === 'immigration-adviser' ? $department.':'.$request->user()->id : $department;

        return ReportWeek::save($request, $scope);
    }

    public function accommodation(Request $request)
    {
        abort_unless(ReportWeek::canUseNotes($request->user()), 403);

        $week = ReportWeek::forPreset($request->input('preset')) ?? ReportWeek::forPreset('this_week');

        return inertia('portal/accommodation/Reports', [
            'preset' => $week->preset,
            'weeklyReport' => $week->notes('accommodation') + ['tasks' => $week->tasks('accommodation')],
        ]);
    }
}
