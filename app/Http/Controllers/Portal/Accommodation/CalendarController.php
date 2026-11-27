<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Requests\Calendar\UpdateCalendarEventRequest;
use App\Models\CalendarEvent;
use App\Models\EoiSubmission;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Accommodation\ViewingSchedule;
use App\Services\CalendarEventAggregator;
use App\Support\OnboardingPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    public function index(Request $request, ViewingSchedule $schedule)
    {
        $shared = ViewingSchedule::staffCanManage($request->user()) ? $schedule->upcomingCount() : 0;

        return inertia('portal/accommodation/Calendar', [
            'eventTypes' => [
                ['key' => 'viewing', 'label' => 'Viewings', 'color' => '#7C3AED', 'enabled' => true],
                ['key' => 'contract_end', 'label' => 'Contract Ends', 'color' => '#F59E0B', 'enabled' => true],
                ['key' => 'custom', 'label' => 'Custom Events', 'color' => '#6B7280', 'enabled' => true],
                ['key' => 'rent_due', 'label' => 'Rent Due', 'color' => '#9CA3AF', 'enabled' => false],
                ['key' => 'inspection', 'label' => 'Inspections', 'color' => '#9CA3AF', 'enabled' => false],
                ['key' => 'pm_payment', 'label' => 'PM Payments', 'color' => '#9CA3AF', 'enabled' => false],
            ],
            'properties' => $this->propertyOptions(),
            'viewingStatuses' => OnboardingPipeline::allStatuses(),
            'kpis' => [
                // Clients currently scheduled to view (booked, not yet completed).
                'upcoming_viewings' => EoiSubmission::where('status', 'viewing_booked')->count() + $shared,
                // Active tenants whose contract ends within the "ending soon" window.
                'ending_soon' => Tenant::active()->endingSoon()->count(),
            ],
        ]);
    }

    public function events(Request $request, CalendarEventAggregator $aggregator, ViewingSchedule $schedule)
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'event_types' => ['sometimes', 'array'],
            'event_types.*' => ['string'],
            'property_ids' => ['sometimes', 'array'],
            'property_ids.*' => ['integer'],
            'statuses' => ['sometimes', 'array'],
        ]);

        $start = Carbon::parse($data['start'])->startOfDay();
        $end = Carbon::parse($data['end'])->endOfDay();

        // Compare whole days (Carbon 3 diffInDays returns a float, so the
        // end-of-day fraction would otherwise push an exactly-90-day span over).
        if ($start->diffInDays($end->copy()->startOfDay()) > 90) {
            throw ValidationException::withMessages(['end' => 'The date range cannot exceed 90 days.']);
        }

        $filters = array_filter([
            'event_types' => $data['event_types'] ?? null,
            'property_ids' => $data['property_ids'] ?? null,
            'statuses' => $data['statuses'] ?? null,
        ]);

        // Cache for 60s in real environments; bypass during tests for
        // determinism (the array cache store would otherwise leak across tests).
        if (app()->environment('testing')) {
            $events = $aggregator->getEvents($start, $end, $filters);
        } else {
            $key = 'accommodation_calendar:'.$this->cacheVersion().':'.md5(json_encode([$data['start'], $data['end'], $filters]));
            $events = Cache::remember($key, 60, fn () => $aggregator->getEvents($start, $end, $filters));
        }

        if (ViewingSchedule::staffCanManage($request->user())
            && in_array('viewing', $filters['event_types'] ?? CalendarEventAggregator::DEFAULT_TYPES, true)) {
            $events = array_merge($events, $schedule->calendarEvents(
                $start->toDateString(), $end->toDateString(), $filters['property_ids'] ?? null,
            ));
            usort($events, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));
        }

        return response()->json($events);
    }

    public function storeCustomEvent(StoreCalendarEventRequest $request, CalendarEventAggregator $aggregator)
    {
        $event = CalendarEvent::create(array_merge($request->validated(), [
            'is_all_day' => $request->boolean('is_all_day'),
            'created_by_user_id' => $request->user()->id,
        ]));

        $this->bumpCache();

        return response()->json($aggregator->mapCustomEvent($event->load(['property', 'creator'])), 201);
    }

    public function updateCustomEvent(UpdateCalendarEventRequest $request, CalendarEvent $event, CalendarEventAggregator $aggregator)
    {
        $this->authorizeOwner($request, $event);

        $data = $request->validated();
        if ($request->has('is_all_day')) {
            $data['is_all_day'] = $request->boolean('is_all_day');
        }
        $event->update($data);

        $this->bumpCache();

        return response()->json($aggregator->mapCustomEvent($event->load(['property', 'creator'])));
    }

    public function destroyCustomEvent(Request $request, CalendarEvent $event)
    {
        $this->authorizeOwner($request, $event);

        $event->delete();
        $this->bumpCache();

        return response()->json(['deleted' => true]);
    }

    private function authorizeOwner(Request $request, CalendarEvent $event): void
    {
        $user = $request->user();
        abort_unless($event->created_by_user_id === $user->id || $user->role === 'admin', 403);
    }

    private function cacheVersion(): int
    {
        return (int) Cache::get('accommodation_calendar_version', 0);
    }

    private function bumpCache(): void
    {
        Cache::forever('accommodation_calendar_version', $this->cacheVersion() + 1);
    }

    private function propertyOptions(): array
    {
        return Property::where('is_active', true)
            ->orderByRaw('code IS NULL, CAST(code AS UNSIGNED)')
            ->get()
            ->map(fn (Property $p) => ['id' => $p->id, 'code' => $p->code, 'address' => $p->address ?: $p->name])
            ->all();
    }
}
