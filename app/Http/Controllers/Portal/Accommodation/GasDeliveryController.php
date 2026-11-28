<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationGasOrder;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GasDeliveryController extends Controller
{
    private function property(): ?Property
    {
        $properties = Property::where('is_active', true)
            ->whereIn('code', ['2', '02', '#02', '#2'])
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(TRIM(address)) = ?', ['31 vazey way'])
                ->orWhereRaw('LOWER(TRIM(address)) LIKE ?', ['31 vazey way %'])
                ->orWhereRaw('LOWER(TRIM(address)) LIKE ?', ['31 vazey way,%']))
            ->limit(2)->get();

        return $properties->count() === 1 ? $properties->first() : null;
    }

    public function index(Request $request)
    {
        $today = CarbonImmutable::today('Pacific/Auckland');
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? $today->format('Y-m');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Pacific/Auckland');
        $property = $this->property();
        $orders = $property ? AccommodationGasOrder::where('property_id', $property->id)
            ->where('ordered_on', '>=', $start->toDateString())
            ->where('ordered_on', '<', $start->addMonth()->toDateString())
            ->orderByDesc('ordered_on')->orderByDesc('id')
            ->get(['id', 'ordered_on', 'bottles', 'paid_cents']) : collect();

        return inertia('portal/accommodation/GasDelivery', [
            'property' => $property ? ['address' => $property->address, 'legacy_purchase_date' => $property->last_gas_purchase?->toDateString()] : null,
            'orders' => $orders,
            'month' => $month,
            'today' => $today->toDateString(),
            'total_paid_cents' => $orders->sum('paid_cents'),
        ]);
    }

    public function store(Request $request)
    {
        $property = $this->property();
        abort_unless($property, 404);

        $today = CarbonImmutable::today('Pacific/Auckland');
        $data = $request->validate([
            'ordered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:1900-01-01'],
            'bottles' => ['required', 'integer', 'min:1', 'max:10000'],
            'amount_paid' => ['required', 'numeric', 'min:0', 'max:999999', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'submission_key' => ['required', 'uuid'],
        ]);

        [$dollars, $cents] = array_pad(explode('.', (string) $data['amount_paid'], 2), 2, '');
        $paidCents = (int) $dollars * 100 + (int) str_pad($cents, 2, '0');
        $order = AccommodationGasOrder::firstOrCreate(['submission_key' => $data['submission_key']], [
            'property_id' => $property->id, 'ordered_on' => $data['ordered_on'],
            'bottles' => $data['bottles'], 'paid_cents' => $paidCents,
        ]);
        if ($order->property_id !== $property->id || $order->ordered_on !== $data['ordered_on']
            || $order->bottles !== (int) $data['bottles'] || $order->paid_cents !== $paidCents) {
            throw ValidationException::withMessages(['submission_key' => 'This order was already saved with different details. Reload the tracker before adding another order.']);
        }

        return redirect()->route('portal.accommodation.gas-delivery', ['month' => substr($order->ordered_on, 0, 7)])
            ->with('success', 'Gas order saved.');
    }
}
