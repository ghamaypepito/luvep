<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Support\RentRoll;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RentUtilitiesController extends Controller
{
    public function index(Request $request)
    {
        $weeksCount = max(1, min(30, (int) $request->integer('weeks', 18)));

        $start = $request->filled('start')
            ? Carbon::parse($request->query('start'))
            : Carbon::today();
        $start = RentRoll::snapToMonday($start);

        $columns = RentRoll::weekColumns($start, $weeksCount);
        $weekStarts = array_column($columns, 'start');
        $firstWeek = $weekStarts[0];
        $lastWeek = $weekStarts[count($weekStarts) - 1];
        $propertyOrder = Property::inPortfolioOrder()->pluck('id')->flip();

        $tenants = Tenant::active()
            ->with([
                'property',
                'rentPayments' => fn ($q) => $q->whereBetween('week_start', [$firstWeek, $lastWeek]),
            ])
            ->get();

        $groups = $tenants
            ->groupBy('property_id')
            ->map(function ($group) use ($weekStarts, $weeksCount) {
                $property = $group->first()->property;

                $rows = $group->sortBy('display_name')->values()->map(function (Tenant $t) use ($weekStarts, $weeksCount) {
                    $payments = [];
                    foreach ($t->rentPayments as $p) {
                        $payments[$p->week_start->toDateString()] = (float) $p->amount_nzd;
                    }

                    $weeklyDue = (float) $t->weekly_total_due;
                    $totalDue = round($weeklyDue * $weeksCount, 2);

                    $totalPaid = 0.0;
                    foreach ($weekStarts as $ws) {
                        $totalPaid += $payments[$ws] ?? 0.0;
                    }
                    $totalPaid = round($totalPaid, 2);

                    return [
                        'id' => $t->id,
                        'display_name' => $t->display_name,
                        'weekly_rent_nzd' => (float) $t->weekly_rent_nzd,
                        'weekly_utilities_nzd' => (float) $t->weekly_utilities_nzd,
                        'weekly_total_due' => $weeklyDue,
                        'payments' => $payments,
                        'total_due' => $totalDue,
                        'total_paid' => $totalPaid,
                        'balance' => round($totalPaid - $totalDue, 2),
                        'status' => RentRoll::status($totalDue, $totalPaid),
                    ];
                });

                return [
                    'property_id' => $property?->id,
                    'property_code' => $property?->code,
                    // Fall back to the listing name when no street address is set.
                    'property_address' => $property ? ($property->address ?: $property->name) : '—',
                    'tenants' => $rows,
                ];
            })
            ->sortBy(fn ($group) => $propertyOrder->get($group['property_id'], PHP_INT_MAX))
            ->values();

        return inertia('portal/accommodation/RentUtilities', [
            'groups' => $groups,
            'weeks' => $columns,
            'window' => ['start' => $start->toDateString(), 'weeks' => $weeksCount],
        ]);
    }

    public function savePayment(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $weekStart = RentRoll::snapToMonday(Carbon::parse($data['week_start']));
        $amount = $data['amount'] ?? null;

        if ($amount === null || (float) $amount === 0.0) {
            RentPayment::where('tenant_id', $tenant->id)->where('week_start', $weekStart)->delete();
        } else {
            RentPayment::updateOrCreate(
                ['tenant_id' => $tenant->id, 'week_start' => $weekStart],
                ['amount_nzd' => $amount],
            );
        }

        return redirect()->back();
    }

    /** Set a tenant's weekly rent + utilities directly from the rent roll. */
    public function saveRentUtilities(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'weekly_rent_nzd' => ['nullable', 'numeric', 'min:0'],
            'weekly_utilities_nzd' => ['nullable', 'numeric', 'min:0'],
        ]);

        $tenant->update([
            'weekly_rent_nzd' => $data['weekly_rent_nzd'] ?? 0,
            'weekly_utilities_nzd' => $data['weekly_utilities_nzd'] ?? 0,
        ]);

        return redirect()->back();
    }
}
