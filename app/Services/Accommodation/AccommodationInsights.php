<?php

namespace App\Services\Accommodation;

use App\Models\Booking;
use App\Models\LeadTask;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportWeek;
use Carbon\CarbonImmutable;

class AccommodationInsights
{
    public static function forWeek(ReportWeek $week, ?string $month = null): array
    {
        $today = CarbonImmutable::now(ReportPeriod::TIMEZONE)->startOfDay();
        $monthStart = $month ? CarbonImmutable::createFromFormat('!Y-m', $month, ReportPeriod::TIMEZONE) : $today->startOfMonth();
        $days = [];
        for ($day = $week->start; $day->lt($week->end); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'created' => 0, 'completed' => 0];
        }

        LeadTask::where('department', 'accommodation')
            ->where(fn ($query) => $query
                ->where(fn ($created) => $created->where('created_at', '>=', $week->start->utc())->where('created_at', '<', $week->end->utc()))
                ->orWhere(fn ($completed) => $completed->where('completed', true)->where('completed_at', '>=', $week->start->utc())->where('completed_at', '<', $week->end->utc())))
            ->select(['id', 'created_at', 'completed', 'completed_at'])->each(function (LeadTask $task) use (&$days) {
                $created = $task->created_at?->copy()->setTimezone(ReportPeriod::TIMEZONE)->toDateString();
                $completed = $task->completed_at?->copy()->setTimezone(ReportPeriod::TIMEZONE)->toDateString();
                if (isset($days[$created])) {
                    $days[$created]['created']++;
                }
                if ($task->completed && isset($days[$completed])) {
                    $days[$completed]['completed']++;
                }
            });

        $bookings = Booking::where('service_type', 'like', '%Accommodation Viewing%')
            ->whereRaw('LOWER(COALESCE(status, ?)) NOT IN (?, ?)', ['pending', 'cancelled', 'canceled'])
            ->whereDate('appointment_date', '>=', $monthStart->toDateString())
            ->whereDate('appointment_date', '<', $monthStart->addMonth()->toDateString())
            ->with('property:id,name')->orderBy('appointment_date')->orderBy('appointment_time')->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'property_id', 'status', 'appointment_date', 'appointment_time'])
            ->map(fn (Booking $booking) => [
                'id' => $booking->id, 'name' => trim($booking->first_name.' '.$booking->last_name),
                'property' => $booking->property?->name, 'date' => $booking->appointment_date->toDateString(),
                'time' => $booking->appointment_time, 'status' => $booking->status ?: 'Pending',
            ])->all();

        return [
            'inventoryStats' => RoomInventory::dashboard(),
            'insights' => [
                'as_of' => $today->toDateString(), 'timezone' => ReportPeriod::TIMEZONE,
                'period' => ['from' => $week->dates()[0], 'to' => $week->dates()[1]],
                'daily_tasks' => array_values($days),
                'calendar' => ['month' => $monthStart->format('Y-m'), 'bookings' => $bookings],
            ],
        ];
    }
}
