<?php

namespace App\Services\Education;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CostBreakdownRates
{
    public function quote(string $source, string $target): array
    {
        $rates = [];
        foreach (array_unique(['NZD', $source, $target]) as $currency) {
            $rates[$currency] = $currency === 'NZD'
                ? ['rate' => 1.0, 'date' => now('UTC')->toDateString()]
                : $this->rate($currency);
        }
        $snapshot = ['source_currency' => $source, 'target_currency' => $target, 'rates' => $rates,
            'provider' => 'Frankfurter reference rates', 'provider_url' => 'https://frankfurter.dev/',
            'fetched_at' => now('UTC')->toIso8601String(), 'expires_at' => now()->addHour()->timestamp];

        return [...$snapshot, 'token' => Crypt::encryptString(json_encode($snapshot))];
    }

    private function rate(string $currency): array
    {
        try {
            $value = Cache::remember('education-cost-rate-v1:'.$currency, now()->addMinutes(30), function () use ($currency) {
                $row = Http::acceptJson()->connectTimeout(3)->timeout(8)
                    ->get('https://api.frankfurter.dev/v2/rate/nzd/'.strtolower($currency))->throw()->json();
                if (! is_array($row) || ($row['base'] ?? null) !== 'NZD' || ($row['quote'] ?? null) !== $currency
                    || ! is_numeric($row['rate'] ?? null) || ! is_finite((float) $row['rate'])
                    || (float) $row['rate'] <= 0 || (float) $row['rate'] > 1000000000
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['date'] ?? '')) {
                    throw new \RuntimeException('Invalid reference rate');
                }

                return ['rate' => (float) $row['rate'], 'date' => $row['date']];
            });
            $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $value['date'], 'UTC');
            if (! $date || $date->toDateString() !== $value['date'] || $date->isFuture()
                || $date->lt(now('UTC')->startOfDay()->subDays(7))) {
                Cache::forget('education-cost-rate-v1:'.$currency);
                throw new \RuntimeException('Reference rate is out of date');
            }

            return $value;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['rate' => 'Automatic reference rates are unavailable for '.$currency.'. Please retry later or choose a supported currency. Original fees have not changed.']);
        }
    }

    public function snapshot(string $token, string $source, string $target): array
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if (($snapshot['source_currency'] ?? null) !== $source || ($snapshot['target_currency'] ?? null) !== $target
                || ($snapshot['expires_at'] ?? 0) < now()->timestamp) {
                throw new \RuntimeException('Expired or changed currencies');
            }

            return $snapshot;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['rate' => 'Refresh the exchange rate before generating this breakdown.']);
        }
    }
}
