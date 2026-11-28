<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Support\RentRoll;
use App\Support\WeeklyRentCharge;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RentUtilitiesController extends Controller
{
    public function index(Request $request)
    {
        $weeksCount = max(1, min(30, (int) $request->integer('weeks', 3)));

        $filter = $request->validate(['start' => ['nullable', 'date_format:Y-m-d']]);
        $start = isset($filter['start'])
            ? Carbon::parse($filter['start'], 'Pacific/Auckland')
            : Carbon::today('Pacific/Auckland')->subWeeks($weeksCount - 1);
        $start = RentRoll::snapToMonday($start);

        $columns = RentRoll::weekColumns($start, $weeksCount);
        $weekStarts = array_column($columns, 'start');
        $firstWeek = $weekStarts[0];
        $end = $start->copy()->addWeeks($weeksCount)->toDateString();
        $inWindow = fn ($q) => $q->where('week_start', '>=', $firstWeek)->where('week_start', '<', $end);
        $currentWeek = WeeklyRentCharge::currentWeek();
        $properties = Property::inPortfolioOrder()->get(['id', 'code', 'address', 'name'])->keyBy('id');
        $propertyOrder = $properties->keys()->flip();
        $charges = $inWindow(DB::table(WeeklyRentCharge::TABLE))->get()->groupBy('tenant_id');
        $tenants = Tenant::withTrashed()->where(fn ($q) => $q
            ->where(fn ($active) => $active->active()->whereNull('deleted_at'))
            ->orWhereHas('rentPayments', $inWindow)->orWhereIn('id', $charges->keys()))
            ->with(['rentPayments' => $inWindow])->get();

        $rows = [];
        foreach ($tenants as $tenant) {
            $active = ! $tenant->trashed() && in_array($tenant->current_status, Tenant::ACTIVE_STATUSES, true);
            $weekly = $charges->get($tenant->id, collect())->keyBy('week_start');
            $payments = $tenant->rentPayments->keyBy(fn ($payment) => $payment->week_start->toDateString());
            $startDate = $tenant->residency_started_on ?? $tenant->contract_start;
            $firstTenancyWeek = $startDate ? RentRoll::snapToMonday($startDate)->toDateString() : null;
            $currentGroup = $tenant->property_id ?? 'unknown';
            if ($active && (! $firstTenancyWeek || $firstTenancyWeek < $end)) {
                $rows[$currentGroup][$tenant->id] = [
                    'id' => $tenant->id, 'display_name' => $tenant->display_name,
                    'weekly_rent_nzd' => (float) $tenant->weekly_rent_nzd,
                    'weekly_utilities_nzd' => (float) $tenant->weekly_utilities_nzd,
                    'can_edit_rates' => true, 'can_edit_payments' => true,
                    'first_tenancy_week' => $firstTenancyWeek,
                    'weekly' => [],
                ];
            }
            foreach ($weekStarts as $week) {
                $charge = $weekly->get($week);
                $payment = $payments->get($week);
                $preview = $active && $week >= $currentWeek && (! $firstTenancyWeek || $firstTenancyWeek <= $week);
                if (! $charge && ! $payment && ! $preview) {
                    continue;
                }
                $propertyId = $charge ? $charge->property_id : $tenant->property_id;
                $key = $propertyId ?? 'unknown';
                $rows[$key][$tenant->id] ??= [
                    'id' => $tenant->id, 'display_name' => $tenant->display_name,
                    'weekly_rent_nzd' => (float) $tenant->weekly_rent_nzd,
                    'weekly_utilities_nzd' => (float) $tenant->weekly_utilities_nzd,
                    'can_edit_rates' => false,
                    'can_edit_payments' => ! $tenant->trashed(),
                    'weekly' => [],
                ];
                $rows[$key][$tenant->id]['weekly'][$week] = [
                    'due' => $charge ? (float) $charge->due_nzd : ($preview ? (float) $tenant->weekly_total_due : null),
                    'received' => $payment ? (float) $payment->amount_nzd : null,
                    'captured' => $charge !== null,
                ];
                if ($payment?->received_on) {
                    $rows[$key][$tenant->id]['weekly'][$week]['received_on'] = $payment->received_on->toDateString();
                }
            }
        }

        $groups = collect($rows)
            ->sortBy(fn ($group, $id) => $propertyOrder->get($id, PHP_INT_MAX))
            ->map(function ($group, $id) use ($properties) {
                $property = $properties->get($id);

                return [
                    'property_id' => $id,
                    'property_code' => $property?->code,
                    'property_address' => $property ? ($property->address ?: $property->name) : 'Historical payments without a verified property',
                    'tenants' => collect($group)->sortBy('display_name')->values(),
                ];
            })->values();

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
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::today('Pacific/Auckland')->toDateString()],
        ]);

        $weekStart = RentRoll::snapToMonday(Carbon::parse($data['week_start'], 'Pacific/Auckland'));
        $amount = $data['amount'] ?? null;

        DB::transaction(function () use ($tenant, $weekStart, $amount, $data) {
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($weekStart->toDateString() === WeeklyRentCharge::currentWeek()) {
                WeeklyRentCharge::captureCurrent($current);
            }
            if ($amount === null || (float) $amount === 0.0) {
                RentPayment::where('tenant_id', $tenant->id)->where('week_start', $weekStart)->delete();
            } else {
                RentPayment::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'week_start' => $weekStart],
                    ['amount_nzd' => $amount, ...array_intersect_key($data, ['received_on' => true])],
                );
            }
        });

        return redirect()->back();
    }

    /** Set a tenant's weekly rent + utilities directly from the rent roll. */
    public function saveRentUtilities(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'weekly_rent_nzd' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'weekly_utilities_nzd' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        DB::transaction(fn () => Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail()->update([
            'weekly_rent_nzd' => $data['weekly_rent_nzd'] ?? 0,
            'weekly_utilities_nzd' => $data['weekly_utilities_nzd'] ?? 0,
        ]));

        return redirect()->back();
    }
}
