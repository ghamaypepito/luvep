<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WeeklyRentCharge
{
    public const TABLE = 'accommodation_weekly_rent_charges';

    public static function currentWeek(): string
    {
        return RentRoll::snapToMonday(Carbon::today('Pacific/Auckland'))->toDateString();
    }

    public static function captureCurrent(Tenant $tenant): void
    {
        $status = $tenant->getRawOriginal('current_status');
        $starts = $tenant->getRawOriginal('residency_started_on') ?? $tenant->getRawOriginal('contract_start');
        if (! in_array($status, Tenant::ACTIVE_STATUSES, true)
            || ($starts && substr((string) $starts, 0, 10) > Carbon::today('Pacific/Auckland')->toDateString())) {
            return;
        }

        $rent = (float) $tenant->getRawOriginal('weekly_rent_nzd');
        $utilities = (float) $tenant->getRawOriginal('weekly_utilities_nzd');
        if ($rent + $utilities <= 0) {
            return;
        }
        DB::table(self::TABLE)->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'property_id' => $tenant->getRawOriginal('property_id'),
            'week_start' => self::currentWeek(),
            'due_nzd' => number_format($rent + $utilities, 2, '.', ''),
        ]);
    }

    public static function capturePortfolio(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $week = self::currentWeek();
        Tenant::active()->whereNotExists(fn ($query) => $query->selectRaw('1')->from(self::TABLE)
            ->whereColumn(self::TABLE.'.tenant_id', 'accommodation_tenants.id')->where('week_start', $week))
            ->select('id')->eachById(function (Tenant $tenant) {
                DB::transaction(function () use ($tenant) {
                    $current = Tenant::whereKey($tenant->id)->lockForUpdate()->first();
                    if ($current) {
                        self::captureCurrent($current);
                    }
                });
            });
    }
}
