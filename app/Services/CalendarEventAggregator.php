<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\EoiSubmission;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Merges accommodation events from several sources into one common DTO shape.
 * Each source is a private method so future modules (rent, inspections, PM
 * payments) can be activated by filling in their stub.
 */
class CalendarEventAggregator
{
    public const DEFAULT_TYPES = ['viewing', 'contract_end', 'custom'];

    public function getEvents(Carbon $start, Carbon $end, array $filters = []): array
    {
        $types = $filters['event_types'] ?? self::DEFAULT_TYPES;

        $events = [];
        if (in_array('viewing', $types, true)) {
            $events = array_merge($events, $this->getViewingEvents($start, $end, $filters));
        }
        if (in_array('contract_end', $types, true)) {
            $events = array_merge($events, $this->getContractEndEvents($start, $end, $filters));
        }
        if (in_array('custom', $types, true)) {
            $events = array_merge($events, $this->getCustomEvents($start, $end, $filters));
        }
        // Extension points (no source modules yet).
        $events = array_merge($events, $this->getRentDueEvents($start, $end, $filters));
        $events = array_merge($events, $this->getInspectionEvents($start, $end, $filters));
        $events = array_merge($events, $this->getPmPaymentEvents($start, $end, $filters));

        usort($events, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $events;
    }

    private function getViewingEvents(Carbon $start, Carbon $end, array $filters): array
    {
        $propertyIds = $filters['property_ids'] ?? null;
        $statuses = $filters['statuses'] ?? null;

        return EoiSubmission::query()
            ->with('property')
            ->whereNull('viewing_attendee_id')
            ->whereNotNull('viewing_scheduled_at')
            ->whereBetween('viewing_scheduled_at', [$start, $end])
            ->when($propertyIds, fn ($q) => $q->where(fn ($w) => $w->whereIn('property_id', $propertyIds)->orWhereNull('property_id')))
            // Only show viewings that are still pending. Once the applicant moves
            // past "viewing_booked" (completed, onboarded, became a tenant, or
            // declined) the scheduled-viewing timestamp lingers but the upcoming
            // viewing should disappear from the calendar.
            ->when(
                $statuses,
                fn ($q) => $q->whereIn('status', $statuses),
                fn ($q) => $q->where('status', 'viewing_booked'),
            )
            ->get()
            ->map(fn (EoiSubmission $s) => [
                'id' => 'viewing-'.$s->id,
                'source_type' => 'viewing',
                'source_id' => $s->id,
                'title' => "{$s->full_legal_name} viewing",
                'subtitle' => $s->property?->address ?: $s->property_interested,
                'starts_at' => $s->viewing_scheduled_at->toIso8601String(),
                'ends_at' => $s->viewing_scheduled_at->copy()->addMinutes(30)->toIso8601String(),
                'is_all_day' => false,
                'color' => '#7C3AED',
                'icon' => 'Home',
                'property_id' => $s->property_id,
                'property_address' => $s->property?->address,
                'meta' => [
                    'status' => $s->status,
                    'lead_temperature' => $s->lead_temperature,
                    'applicant_email' => $s->email,
                    'applicant_phone' => $s->mobile,
                ],
            ])
            ->all();
    }

    private function getContractEndEvents(Carbon $start, Carbon $end, array $filters): array
    {
        $propertyIds = $filters['property_ids'] ?? null;
        $today = Carbon::today();

        return Tenant::query()
            ->with('property')
            ->whereNotNull('contract_end')
            ->whereBetween('contract_end', [$start, $end])
            // Past contract ends always show (historical); future ones are
            // hidden for already-vacated tenants (no action needed).
            ->where(fn ($q) => $q->whereDate('contract_end', '<', $today)->orWhere('current_status', '!=', 'vacated'))
            ->when($propertyIds, fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->get()
            ->map(function (Tenant $t) {
                $days = $t->days_to_end;
                $color = $days !== null && $days <= 0 ? '#DC2626'
                    : ($days !== null && $days <= 25 ? '#F59E0B' : '#6B7280');

                return [
                    'id' => 'contract_end-'.$t->id,
                    'source_type' => 'contract_end',
                    'source_id' => $t->id,
                    'title' => "{$t->display_name} contract ends",
                    'subtitle' => $t->property?->address,
                    'starts_at' => $t->contract_end->copy()->setTime(9, 0)->toIso8601String(),
                    'ends_at' => $t->contract_end->copy()->setTime(17, 0)->toIso8601String(),
                    'is_all_day' => true,
                    'color' => $color,
                    'icon' => 'FileText',
                    'property_id' => $t->property_id,
                    'property_address' => $t->property?->address,
                    'meta' => [
                        'days_to_end' => $days,
                        'current_status' => $t->current_status,
                        'weekly_rent_nzd' => $t->weekly_rent_nzd,
                    ],
                ];
            })
            ->all();
    }

    private function getCustomEvents(Carbon $start, Carbon $end, array $filters): array
    {
        $propertyIds = $filters['property_ids'] ?? null;

        return CalendarEvent::query()
            ->with(['property', 'creator'])
            ->between($start, $end)
            ->when($propertyIds, fn ($q) => $q->where(fn ($w) => $w->whereIn('property_id', $propertyIds)->orWhereNull('property_id')))
            ->get()
            ->map(fn (CalendarEvent $e) => $this->mapCustomEvent($e))
            ->all();
    }

    /** Map a stored custom event to the unified DTO (reused by the controller). */
    public function mapCustomEvent(CalendarEvent $e): array
    {
        return [
            'id' => 'custom-'.$e->id,
            'source_type' => 'custom',
            'source_id' => $e->id,
            'title' => $e->title,
            'subtitle' => $e->location ?: $e->property?->address,
            'starts_at' => $e->starts_at->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'is_all_day' => $e->is_all_day,
            'color' => $e->color_hex ?: '#6B7280',
            'icon' => 'CalendarDays',
            'property_id' => $e->property_id,
            'property_address' => $e->property?->address,
            'meta' => [
                'description' => $e->description,
                'location' => $e->location,
                'created_by' => $e->creator?->name,
                'created_by_user_id' => $e->created_by_user_id,
            ],
        ];
    }

    // ---- Extension points (activate when the source modules are built) ----

    private function getRentDueEvents(Carbon $start, Carbon $end, array $filters): array
    {
        return [];
    }

    private function getInspectionEvents(Carbon $start, Carbon $end, array $filters): array
    {
        return [];
    }

    private function getPmPaymentEvents(Carbon $start, Carbon $end, array $filters): array
    {
        return [];
    }
}
