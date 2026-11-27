<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement;
use App\Models\AccommodationRentalInvoice;
use App\Models\Tenant;
use App\Support\WeeklyRentCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalInvoices
{
    public const BANK = ['name' => 'EXALT PROPERTY MANAGEMENT LTD', 'account' => '01-0288-0332767-00'];

    public function agreements(Tenant $tenant): Collection
    {
        $issued = $this->issued($tenant, 'move-in')->pluck('agreement_id')->all();

        return AccommodationAgreement::where('tenant_id', $tenant->id)
            ->where('status', AccommodationAgreement::STATUS_SIGNED)
            ->latest('signed_at')->orderByDesc('id')->get()
            ->filter(fn ($agreement) => ! empty($agreement->data['costs']) && ! in_array($agreement->id, $issued, true))
            ->map(fn ($agreement) => [
                'id' => $agreement->id, 'label' => $agreement->typeLabel(), 'signed_on' => $agreement->signed_at?->toDateString(),
                'bill_to' => $agreement->client_name, 'property' => $agreement->property_address,
                'lines' => $this->agreementLines($agreement->data),
            ])->values();
    }

    public function weeks(Tenant $tenant): Collection
    {
        $billed = $this->issued($tenant, 'overdue')->flatMap(fn ($details) => array_column($details['weeks'], 'start'))->flip();
        $charges = DB::table(WeeklyRentCharge::TABLE)->where('tenant_id', $tenant->id)->where('property_id', $tenant->property_id)
            ->where('week_start', '<=', WeeklyRentCharge::currentWeek())->orderByDesc('week_start')->get();
        $payments = $tenant->rentPayments()->where('week_start', '<', CarbonImmutable::parse(WeeklyRentCharge::currentWeek())->addWeek()->toDateString())
            ->get()->keyBy(fn ($payment) => $payment->week_start->toDateString());

        return $charges->map(function ($charge) use ($payments) {
            $start = CarbonImmutable::parse($charge->week_start, 'Pacific/Auckland');
            $payment = $payments->get($start->toDateString());
            $due = $this->cents($charge->due_nzd);
            $received = $this->cents($payment?->amount_nzd ?? 0);
            $row = [
                'start' => $start->toDateString(), 'end' => $start->addDays(6)->toDateString(),
                'original_due' => $start->subDays(3)->toDateString(),
                'due_cents' => $due, 'received_cents' => $received,
                'received_on' => $payment?->received_on?->toDateString(),
                'outstanding_cents' => max(0, $due - $received),
            ];

            return [...$row, 'version' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))];
        })->filter(fn ($row) => $row['outstanding_cents'] > 0 && ! $billed->has($row['start']))->values();
    }

    public function prepare(Tenant $tenant, string $kind, array $data): array
    {
        $property = $tenant->property()->firstOrFail();
        $invoice = [
            'kind' => $kind, 'bill_to' => $tenant->display_name, 'property' => $property->address ?: $property->name,
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? CarbonImmutable::parse($data['issue_date'], 'Pacific/Auckland')->addDays(2)->toDateString(),
            'bank' => self::BANK,
        ];

        if ($kind === 'move-in') {
            $agreement = $this->agreements($tenant)->firstWhere('id', (int) $data['agreement_id']);
            if (! $agreement) {
                throw ValidationException::withMessages(['agreement_id' => 'Select a linked agreement with agreed costs and no issued move-in invoice.']);
            }
            $lines = $data['lines'];
            $packageSubtotal = collect($lines)->where('group', 'package')->sum('amount_cents');
            $total = array_sum(array_column($lines, 'amount_cents'));

            return [...$invoice, 'agreement_id' => $agreement['id'], 'bill_to' => $agreement['bill_to'],
                'property' => $agreement['property'] ?: $invoice['property'], 'lines' => $lines,
                'move_in_subtotal' => $total - $packageSubtotal, 'package_subtotal' => $packageSubtotal, 'total_cents' => $total];
        }

        $available = $this->weeks($tenant)->keyBy('start');
        $weeks = collect($data['weeks'])->map(function ($selected) use ($available, $data) {
            $row = $available->get($selected['start']);
            if (! $row || $row['start'] > $data['issue_date'] || ! hash_equals($row['version'], $selected['version'])
                || $selected['amount_cents'] > $row['outstanding_cents']) {
                throw ValidationException::withMessages(['weeks' => 'A selected week has changed or exceeds the balance due. Reload the invoice and review the current payments.']);
            }
            unset($row['version']);

            return [...$row, 'amount_cents' => $selected['amount_cents']];
        })->sortBy('start')->values()->all();

        return [...$invoice, 'weeks' => $weeks, 'total_cents' => array_sum(array_column($weeks, 'amount_cents'))];
    }

    private function agreementLines(array $data): array
    {
        $costs = $data['costs'];
        $lines = collect(['bond' => 'Bond', 'deposit' => 'Deposit', 'rent_advance' => 'Rent in advance', 'prorated_rent' => 'Pro-rated rent'])
            ->map(fn ($label, $key) => ['description' => $label, 'amount_cents' => $this->cents($costs[$key] ?? 0), 'group' => 'move-in'])
            ->filter(fn ($line) => $line['amount_cents'] > 0)->values();

        foreach ($data['welcome_packages']['packages'] ?? [] as $package) {
            $lines->push(['description' => $package['name'], 'amount_cents' => $this->cents($package['price']), 'group' => 'package']);
        }

        return $lines->all();
    }

    private function issued(Tenant $tenant, string $kind): Collection
    {
        return AccommodationRentalInvoice::where('tenant_id', $tenant->id)->where('kind', $kind)->get(['details'])->pluck('details');
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
