<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ClockTransitionRequest;
use App\Services\Hr\ClockService;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;

class ClockController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(app(ClockService::class)->state(app(HrContext::class)->subject($request)));
    }

    public function store(ClockTransitionRequest $request)
    {
        $employee = app(HrContext::class)->subject($request);
        $data = $request->validated();
        app(ClockService::class)->transition($employee, $data['action'], $data['request_id'], (int) $data['expected_version']);

        return response()->json(app(ClockService::class)->state($employee));
    }
}
