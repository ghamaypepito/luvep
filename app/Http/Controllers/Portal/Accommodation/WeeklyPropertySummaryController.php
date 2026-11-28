<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\RentRoll;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WeeklyPropertySummaryController extends Controller
{
    private const TABLE = 'accommodation_weekly_property_finances';

    public function index(Request $request)
    {
        $filter = $request->validate([
            'week' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'category' => ['sometimes', Rule::in(['rent', 'utilities'])],
        ]);
        $week = $this->monday($filter['week'] ?? null);
        $category = $filter['category'] ?? 'rent';
        $entries = DB::table(self::TABLE)->where('week_start', $week->toDateString())
            ->where('category', $category)->get()->keyBy('property_id');

        $rows = Property::where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $entries->keys()))
            ->inPortfolioOrder()->get(['id', 'name'])->map(fn (Property $property) => [
                'id' => $property->id,
                'name' => $property->name,
                'revenue_nzd' => $entries->get($property->id)?->revenue_nzd,
                'cost_nzd' => $entries->get($property->id)?->cost_nzd,
                'version' => $entries->get($property->id)?->version ?? 0,
            ]);

        return inertia('portal/accommodation/WeeklyPropertySummary', [
            'rows' => $rows,
            'week' => [
                'start' => $week->toDateString(), 'end' => $week->copy()->addDays(6)->toDateString(),
                'previous' => $week->copy()->subWeek()->toDateString(), 'next' => $week->copy()->addWeek()->toDateString(),
            ],
            'category' => $category,
        ]);
    }

    public function update(Request $request, Property $property)
    {
        $data = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in(['rent', 'utilities'])],
            'revenue_nzd' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'cost_nzd' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $key = [
            'week_start' => $this->monday($data['week'])->toDateString(),
            'category' => $data['category'],
            'property_id' => $property->id,
        ];

        DB::transaction(function () use ($property, $key, $data) {
            $current = Property::whereKey($property->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table(self::TABLE)->where($key)->first();
            abort_unless($current->is_active || $existing !== null, 404);
            if ((int) $data['version'] !== (int) ($existing?->version ?? 0)) {
                throw ValidationException::withMessages(['version' => 'This property summary changed. Reload before saving.']);
            }
            if (! $existing && $data['revenue_nzd'] === null && $data['cost_nzd'] === null) {
                return;
            }
            DB::table(self::TABLE)->upsert([
                [...$key, 'revenue_nzd' => $data['revenue_nzd'], 'cost_nzd' => $data['cost_nzd'],
                    'version' => (int) $data['version'] + 1, 'created_at' => now(), 'updated_at' => now()],
            ], array_keys($key), ['revenue_nzd', 'cost_nzd', 'version', 'updated_at']);
        });

        return back()->with('success', 'Weekly property summary saved.');
    }

    private function monday(?string $date): Carbon
    {
        return RentRoll::snapToMonday($date ? Carbon::parse($date, 'Pacific/Auckland') : Carbon::today('Pacific/Auckland'));
    }
}
