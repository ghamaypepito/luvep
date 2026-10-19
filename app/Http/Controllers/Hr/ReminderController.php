<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function claim(Request $request)
    {
        $context = app(HrContext::class);
        abort_if($context->isPreview($request), 403);
        $employee = $context->subject($request);
        $notice = DB::transaction(function () use ($employee) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $shift = HrShift::where('hr_employee_id', $employee->id)->whereNull('ended_at')->first();
            $notice = $this->notice($shift);
            if (! $notice) {
                return null;
            }
            $claimed = DB::table('hr_shift_reminders')->insertOrIgnore(['hr_shift_id' => $shift->id, 'claimed_at' => now()]);

            return $claimed ? $notice : null;
        });

        return response()->json(compact('notice'));
    }

    public function preview(Request $request)
    {
        $context = app(HrContext::class);
        abort_unless($context->isPreview($request), 403);
        $shift = HrShift::where('hr_employee_id', $context->subject($request)->id)->whereNull('ended_at')->first();

        return response()->json(['notice' => $this->notice($shift), 'simulation' => true]);
    }

    private function notice(?HrShift $shift): ?array
    {
        if (! $shift || ! $shift->scheduled_end_at || $shift->ended_at) {
            return null;
        }$seconds = now('UTC')->diffInSeconds($shift->scheduled_end_at, false);
        if ($seconds <= 0 || $seconds > 900) {
            return null;
        }

        return ['shift_id' => $shift->id, 'minutes_remaining' => (int) ceil($seconds / 60), 'scheduled_end_at' => $shift->scheduled_end_at->toISOString()];
    }
}
