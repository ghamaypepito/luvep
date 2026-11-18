<?php

namespace App\Services;

use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AffiliatePerformance
{
    public function report(Request $request): array
    {
        $filters = $request->validate([
            'agent_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'agent')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $timezone = config('app.timezone', 'UTC');
        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth()->toDateString(), $timezone)->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString(), $timezone)->startOfDay();
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start date.']);
        }
        $partner = 'COALESCE(original_affiliate_id, agent_id)';
        $converted = "status = 'Converted' OR is_student = 1 OR is_english_student = 1 OR is_immigration_case = 1 OR is_accommodation_client = 1";
        $status = "COALESCE(NULLIF(education_stage, ''), NULLIF(english_stage, ''), CASE WHEN is_immigration_case = 1 THEN NULLIF(immigration_stage, '') END, NULLIF(status, ''), 'No status')";
        $query = Lead::query()->whereRaw("{$partner} IN (SELECT id FROM users WHERE role = ? AND deleted_at IS NULL)", ['agent'])
            ->where('created_at', '>=', $from)->where('created_at', '<', $to->copy()->addDay());
        if (! empty($filters['agent_id'])) {
            $query->whereRaw("{$partner} = ?", [(int) $filters['agent_id']]);
        }
        $totals = (clone $query)->selectRaw("{$partner} AS affiliate_id, COUNT(*) AS leads_count, SUM(CASE WHEN {$converted} THEN 1 ELSE 0 END) AS converted_count")
            ->groupByRaw($partner)->get()->keyBy('affiliate_id');
        $breakdown = (clone $query)->selectRaw("{$partner} AS affiliate_id, {$status} AS current_status, COUNT(*) AS total")
            ->groupByRaw("{$partner}, {$status}")->get();
        $daily = (clone $query)->selectRaw('DATE(created_at) AS date, COUNT(*) AS total')->groupByRaw('DATE(created_at)')->orderBy('date')->get()
            ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->total])->values();
        $agreements = AgentAgreement::whereRaw('version = (SELECT MAX(latest.version) FROM agent_agreements latest WHERE latest.agent_id = agent_agreements.agent_id)')->get()->keyBy('agent_id');
        $agents = User::where('role', 'agent')->orderBy('name')->get(['id', 'name', 'email', 'phone', 'location', 'avatar_path', 'referral_code'])
            ->map(function (User $agent) use ($totals, $breakdown, $agreements) {
                $counts = $totals->get($agent->id);

                return [
                    'id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email, 'phone' => $agent->phone,
                    'location' => $agent->location, 'avatar_url' => $agent->avatar_url, 'referral_code' => $agent->referral_code,
                    'leads_count' => (int) ($counts?->leads_count ?? 0), 'converted_count' => (int) ($counts?->converted_count ?? 0),
                    'status_counts' => $breakdown->where('affiliate_id', $agent->id)->map(fn ($row) => ['status' => $row->current_status, 'count' => (int) $row->total])->values(),
                    'has_agreement' => isset($agreements[$agent->id]),
                    'agreement_status' => $agreements->get($agent->id)?->statusLabel(),
                ];
            });
        $statusCounts = $breakdown->groupBy('current_status')->map(fn ($rows, $name) => ['status' => $name, 'count' => (int) $rows->sum('total')])->sortByDesc('count')->values();

        return [
            'agents' => $agents,
            'filters' => ['agent_id' => $filters['agent_id'] ?? '', 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => ['registered_partners' => $agents->count(), 'leads' => (int) $totals->sum('leads_count'), 'converted' => (int) $totals->sum('converted_count')],
            'daily' => $daily, 'statusCounts' => $statusCounts, 'timezone' => $timezone,
        ];
    }
}
