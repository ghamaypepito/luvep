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
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $week = ReportWeek::forPreset($request->input('preset')) ?? ReportWeek::forPreset('this_week');

        try {
            $insights = \App\Services\Accommodation\AccommodationInsights::forWeek($week, $request->input('month'));
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Accommodation report insights failed', ['error' => $exception->getMessage()]);
            $insights = ['inventoryStats' => null, 'insights' => null,
                'inventoryError' => 'The portfolio and viewing figures could not be loaded. Retry to refresh them.'];
        }

        return inertia('portal/accommodation/Reports', [
            ...$insights,
            'preset' => $week->preset,
            'weeklyReport' => $week->notes('accommodation') + ['tasks' => $week->tasks('accommodation')],
        ]);
    }
}
