<?php

namespace App\Services\Accommodation;

use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgreementTerms
{
    public const SIGNATORY = 'Alane Beryl Lozada';

    public const SIGNATORY_EMAIL = 'alane@ferdzservices.com';

    public function signatory(): ?User
    {
        $matches = User::where('email', self::SIGNATORY_EMAIL)->where('is_active', true)
            ->whereIn('role', ['accommodation', 'admin', 'super_admin'])->get();

        return $matches->count() === 1 && $matches->first()->hasSignature() ? $matches->first() : null;
    }

    public function calculate(array $data, bool $issuing = false, bool $standardUtilityBilling = false): array
    {
        $data = Arr::only($data, ['property_id', 'notes', 'subtenants', 'tenancy', 'costs', 'bank']);
        if (is_array($data['tenancy'] ?? null)) {
            $data['tenancy'] = Arr::only($data['tenancy'], ['commence_date', 'move_in_date', 'duration', 'duration_unit', 'weekly_rent', 'utility_amount', 'utility_option', 'move_in_time', 'move_out_time', 'room_shared', 'room_shared_max', 'room_type', 'rent_day']);
        }
        if (is_array($data['costs'] ?? null)) {
            $data['costs'] = Arr::only($data['costs'], ['bond', 'deposit', 'advance_weeks']);
        }
        if (is_array($data['bank'] ?? null)) {
            $data['bank'] = Arr::only($data['bank'], ['particular', 'reference', 'account_name']);
        }
        $required = $issuing ? 'required' : 'nullable';
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'];
        $includesUtilities = $standardUtilityBilling && (string) ($data['tenancy']['utility_option'] ?? '') === '1';
        Validator::make(['data' => $data], [
            'data.property_id' => [$required, 'integer', Rule::exists('accommodation_properties', 'id')->where('is_active', true)],
            'data.notes' => 'nullable|string|max:3000',
            'data.subtenants' => [$required, 'array', 'list', 'min:1', 'max:10'],
            'data.subtenants.*' => 'required|array',
            'data.subtenants.*.first_name' => [$required, 'string', 'max:100'],
            'data.subtenants.*.last_name' => 'nullable|string|max:100',
            'data.subtenants.*.email' => 'nullable|email|max:200',
            'data.subtenants.*.mobile' => 'nullable|string|max:50',
            'data.subtenants.*.passport_id' => 'nullable|string|max:100',
            'data.subtenants.*.expiry_date' => 'nullable|date_format:Y-m-d',
            'data.subtenants.*.dob' => 'nullable|date_format:Y-m-d',
            'data.subtenants.*.current_address' => 'nullable|string|max:500',
            'data.subtenants.*.visa_type' => 'nullable|string|max:200',
            'data.subtenants.*.relationship' => 'nullable|string|max:200',
            'data.tenancy' => 'nullable|array',
            'data.tenancy.commence_date' => [$required, 'date_format:Y-m-d'],
            'data.tenancy.move_in_date' => [$required, 'date_format:Y-m-d'],
            'data.tenancy.duration' => [$required, 'integer', 'min:1', 'max:120'],
            'data.tenancy.duration_unit' => [$required, 'in:days,weeks,months'],
            'data.tenancy.weekly_rent' => [$required, ...array_slice($money, 1)],
            'data.tenancy.utility_amount' => $includesUtilities && $issuing ? ['required', ...array_slice($money, 1)] : $money,
            'data.tenancy.utility_option' => 'nullable|in:1,2',
            'data.tenancy.move_in_time' => 'nullable|date_format:H:i',
            'data.tenancy.move_out_time' => 'nullable|date_format:H:i',
            'data.tenancy.room_shared' => 'nullable|boolean',
            'data.tenancy.room_shared_max' => 'nullable|integer|min:0|max:20',
            'data.tenancy.room_type' => 'nullable|string|max:100',
            'data.tenancy.rent_day' => 'nullable|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'data.bank' => 'nullable|array',
            'data.bank.particular' => 'nullable|string|max:200',
            'data.bank.reference' => 'nullable|string|max:200',
            'data.bank.account_name' => 'nullable|string|max:200',
            'data.costs' => 'nullable|array',
            'data.costs.bond' => $includesUtilities ? ['exclude'] : $money,
            'data.costs.deposit' => $includesUtilities ? ['exclude'] : $money,
            'data.costs.advance_weeks' => [$required, 'integer', 'min:1', 'max:52'],
        ])->validate();
        $data['subtenants'] = array_map(fn ($person) => Arr::only($person, ['first_name', 'last_name', 'email', 'mobile', 'passport_id', 'expiry_date', 'dob', 'current_address', 'visa_type', 'relationship']), $data['subtenants'] ?? []);

        $t = $data['tenancy'] ?? [];
        $c = $data['costs'] ?? [];
        $t['end_date'] = '';
        if (! empty($t['commence_date']) && ! empty($t['duration']) && ! empty($t['duration_unit'])) {
            $start = CarbonImmutable::parse($t['commence_date'], 'Pacific/Auckland');
            $end = match ($t['duration_unit']) {
                'months' => $start->addMonthsNoOverflow((int) $t['duration']),
                'weeks' => $start->addWeeks((int) $t['duration']),
                'days' => $start->addDays((int) $t['duration']),
            };
            $t['end_date'] = $end->subDay()->toDateString();
            $t['move_out_date'] = $t['end_date'];
            $t['term'] = $t['duration'].' '.((int) $t['duration'] === 1 ? rtrim($t['duration_unit'], 's') : $t['duration_unit']);
        }
        if ($t['end_date'] && ! empty($t['move_in_date'])
            && ($t['move_in_date'] < $t['commence_date'] || $t['move_in_date'] > $t['end_date'])) {
            throw ValidationException::withMessages(['data.tenancy.move_in_date' => 'Move-in must fall within the agreed tenancy dates.']);
        }
        $c['rent_advance_from'] = $c['rent_advance_to'] = '';
        $c['prorated_days'] = 0;
        $weekly = $this->cents($t['weekly_rent'] ?? 0);
        if ($standardUtilityBilling) {
            $utilityCents = $includesUtilities ? $this->cents($t['utility_amount'] ?? 0) * count($data['subtenants']) : 0;
            $weekly += $utilityCents;
            $t['weekly_utility_total'] = $this->money($utilityCents);
            $t['weekly_payable'] = $this->money($weekly);
            $data['move_in_includes_utilities'] = $includesUtilities;
            if ($includesUtilities) {
                $c['bond'] = $c['deposit'] = $this->money($weekly);
            }
        }
        if (! empty($t['move_in_date'])) {
            $move = CarbonImmutable::parse($t['move_in_date'], 'Pacific/Auckland');
            // First complete Monday-Sunday period; any preceding partial week is separate.
            $from = $move->isMonday() ? $move : $move->next(CarbonImmutable::MONDAY);
            $c['prorated_days'] = (int) $move->diffInDays($from);
            if (! empty($c['advance_weeks'])) {
                $c['rent_advance_from'] = $from->toDateString();
                $c['rent_advance_to'] = $from->addWeeks((int) $c['advance_weeks'])->subDay()->toDateString();
                // Never quote rent beyond a short tenancy. The final week is pro-rated too.
                if ($t['end_date'] && $c['rent_advance_to'] > $t['end_date']) {
                    $c['rent_advance_to'] = $t['end_date'];
                }
                if ($t['end_date'] && $c['rent_advance_from'] > $t['end_date']) {
                    $c['rent_advance_from'] = $c['rent_advance_to'] = '';
                    $c['prorated_days'] = (int) $move->diffInDays(CarbonImmutable::parse($t['end_date'], 'Pacific/Auckland')->addDay());
                }
            }
        }
        $advanceDays = $c['rent_advance_from'] && $c['rent_advance_to']
            ? (int) CarbonImmutable::parse($c['rent_advance_from'])->diffInDays(CarbonImmutable::parse($c['rent_advance_to'])->addDay()) : 0;
        $advance = (int) round($weekly * $advanceDays / 7);
        $prorated = (int) round($weekly * $c['prorated_days'] / 7);
        $c['rent_advance'] = $this->money($advance);
        $c['prorated_rent'] = $this->money($prorated);
        $c['total_move_in'] = $this->money($this->cents($c['bond'] ?? 0) + $this->cents($c['deposit'] ?? 0) + $advance + $prorated);
        $data['tenancy'] = $t;
        $data['costs'] = $c;
        $data['workflow_version'] = 2;
        // Only the public signing transaction may populate signature evidence.
        $data['tenant_signatures'] = [];
        if (! empty($data['property_id'])) {
            $property = Property::findOrFail($data['property_id']);
            $data['property_address'] = $property->address ?: $property->name;
        }

        return $data;
    }

    /** Build the invoice from the already calculated terms and selected package snapshot. */
    public function moveInInvoice(array $data): array
    {
        $costs = $data['costs'];
        $utilitySuffix = !empty($data['move_in_includes_utilities']) ? ' (rent + utilities)' : '';
        $lines = [
            ['description' => 'Bond'.$utilitySuffix, 'amount' => $costs['bond'] ?? '0.00'],
            ['description' => 'Deposit'.$utilitySuffix, 'amount' => $costs['deposit'] ?? '0.00'],
            ['description' => 'Advance rent'.$utilitySuffix, 'amount' => $costs['rent_advance']],
        ];
        if ($this->cents($costs['prorated_rent']) > 0) {
            $lines[] = ['description' => 'Pro-rated rent'.$utilitySuffix, 'amount' => $costs['prorated_rent']];
        }
        $packageTotal = 0;
        foreach ($data['welcome_packages']['packages'] ?? [] as $package) {
            $price = $this->cents($package['price']);
            $lines[] = ['description' => $package['name'], 'amount' => $this->money($price)];
            $packageTotal += $price;
        }

        return [
            'lines' => $lines,
            'move_in_subtotal' => $costs['total_move_in'],
            'package_subtotal' => $this->money($packageTotal),
            'total' => $this->money($this->cents($costs['total_move_in']) + $packageTotal),
        ];
    }

    private function cents(mixed $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) ($value ?: '0'), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
