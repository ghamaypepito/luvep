<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\FinancePayable;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Finance portal — placeholder dashboard + the standard cross-portal
 * Task Board. Mirrors the shape used by the other department portals
 * (sales / education / immigration / accommodation): a Dashboard view
 * with KPI tiles (currently scaffolded with "Coming soon" placeholders
 * until the payments/invoices data model is defined) and a Task Board
 * that reads from the shared `lead_tasks` table filtered by department.
 */
class FinanceController extends Controller
{
    /**
     * Dashboard landing — live KPIs derived from the AR/AP ledger: revenue
     * collected, outstanding receivables + aging, payables due, and a
     * six-month collection trend.
     */
    public function dashboard()
    {
        $currency = strtoupper(config('services.booking.currency', 'nzd'));
        $ar = FinanceReceivable::where('currency', $currency)->with('payments')->get();
        $ap = FinancePayable::where('currency', $currency)->with('payments')->get();

        $arOutstanding = 0;
        $arOverdue = 0;
        $inProgress = 0;
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($ar as $r) {
            if (in_array($r->payment_status, ['open', 'partial', 'overdue'], true)) {
                $inProgress++;
            }
            if (in_array($r->payment_status, ['paid', 'void'], true) || $r->balance <= 0.005) {
                continue;
            }
            $arOutstanding += $r->balance;
            $buckets[$r->aging_bucket] += $r->balance;
            if ($r->days_past_due > 0) {
                $arOverdue += $r->balance;
            }
        }

        $apOutstanding = 0;
        foreach ($ap as $r) {
            if (in_array($r->payment_status, ['paid', 'void'], true) || $r->balance <= 0.005) {
                continue;
            }
            $apOutstanding += $r->balance;
        }

        $monthStart = now()->startOfMonth();
        $collectedThisMonth = FinancePayment::forLedger(FinanceReceivable::class, $currency)
            ->where('paid_on', '>=', $monthStart)->sum('amount');
        $collectedAllTime = FinancePayment::forLedger(FinanceReceivable::class, $currency)->sum('amount');

        // Six-month collection trend for the revenue chart.
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $sum = FinancePayment::forLedger(FinanceReceivable::class, $currency)
                ->whereBetween('paid_on', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])
                ->sum('amount');
            $trend[] = ['label' => $m->format('M'), 'value' => round((float) $sum, 2)];
        }

        return inertia('portal/finance/Dashboard', [
            'currency' => $currency,
            'metrics' => [
                'revenue_collected' => round((float) $collectedThisMonth, 2),
                'revenue_all_time' => round((float) $collectedAllTime, 2),
                'ar_outstanding' => round($arOutstanding, 2),
                'ar_overdue' => round($arOverdue, 2),
                'ap_outstanding' => round($apOutstanding, 2),
                'in_progress' => $inProgress,
            ],
            'aging' => array_map(fn ($v) => round($v, 2), $buckets),
            'trend' => $trend,
        ]);
    }

    /**
     * Invoice workspace — same tax-invoice generator the immigration portal
     * uses, surfaced for finance. Reuses ImmigrationController::invoice() so the
     * case list, generated invoices, suggestions and next number stay in sync;
     * generation posts to the shared /admin/leads/{id}/invoice/* endpoints,
     * which the finance role can already reach.
     */
    public function invoice()
    {
        return app(\App\Http\Controllers\Portal\ImmigrationController::class)
            ->invoice('portal/finance/Invoice');
    }

    /**
     * Task Board — direct copy of the canonical pattern from
     * AccommodationController::tasks(). Scoped to `department='finance'`
     * tasks plus the user's personal queue.
     */
    public function tasks(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $scope = in_array($request->input('scope'), ['mine', 'department'], true)
                ? $request->input('scope') : 'mine';
            $now = now();
            $todayEnd = $now->copy()->endOfDay();
            $weekEnd = $now->copy()->endOfWeek();

            $base = \App\Models\LeadTask::with(['lead:id,lead_id,first_name,last_name,email,status', 'assignee:id,name,avatar_path', 'creator:id,name,avatar_path', 'attachments'])
                ->withCount('comments')
                ->when($scope === 'mine', fn ($q) => $q->assignedTo($userId))
                ->when($scope === 'department', fn ($q) => $q->where('department', 'finance'));

            $serialize = fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'note' => $t->note,
                'comments_count' => (int) ($t->comments_count ?? 0),
                'priority' => $t->priority,
                'progress' => (int) ($t->progress ?? 0),
                'due_at' => $t->due_at,
                'completed' => $t->completed,
                'completed_at' => $t->completed_at,
                'overdue' => ! $t->completed && $t->due_at && $t->due_at->isPast(),
                'type' => $t->type,
                'category' => $t->category,
                'department' => $t->department,
                'tags' => $t->tags,
                'status' => $t->status,
                'completion_notes' => $t->completion_notes,
                'attachments' => $t->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'url' => $a->url,
                    'original_filename' => $a->original_filename,
                    'is_image' => $a->is_image,
                    'mime_type' => $a->mime_type,
                    'size' => $a->size,
                ])->values(),
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name, 'avatar_url' => $t->assignee->avatar_url] : null,
                'additional_assignee_ids' => $t->additional_assignee_ids ?? [],
                'additional_lead_ids' => $t->additional_lead_ids ?? [],
                'creator' => $t->creator ? ['id' => $t->creator->id,  'name' => $t->creator->name, 'avatar_url' => $t->creator->avatar_url] : null,
                'lead' => $t->lead ? [
                    'id' => $t->lead->id,
                    'lead_id' => $t->lead->lead_id,
                    'name' => trim("{$t->lead->first_name} {$t->lead->last_name}"),
                    'status' => $t->lead->status,
                ] : null,
            ];

            $allTasks = (clone $base)->orderByDesc('created_at')->limit(1000)->get()->map($serialize);
            $today = (clone $base)->where('completed', false)->whereBetween('due_at', [$now, $todayEnd])->orderBy('due_at')->get()->map($serialize);
            $overdue = (clone $base)->where('completed', false)->whereNotNull('due_at')->where('due_at', '<', $now)->orderBy('due_at')->get()->map($serialize);
            $thisWeek = (clone $base)->where('completed', false)->whereBetween('due_at', [$todayEnd, $weekEnd])->orderBy('due_at')->get()->map($serialize);
            $undated = (clone $base)->where('completed', false)->whereNull('due_at')->orderByDesc('created_at')->limit(50)->get()->map($serialize);
            $recentlyDone = (clone $base)->where('completed', true)->where('completed_at', '>=', $now->copy()->subDays(7))->orderByDesc('completed_at')->limit(50)->get()->map($serialize);

            return inertia('portal/finance/Tasks', [
                'portal' => 'finance',
                'scope' => $scope,
                'all_tasks' => $allTasks,
                'today' => $today,
                'overdue' => $overdue,
                'this_week' => $thisWeek,
                'undated' => $undated,
                'recently_done' => $recentlyDone,
                'staffOptions' => \App\Models\User::whereNotIn('role', ['lead', 'revoked_lead'])->orderBy('name')->get(['id', 'name', 'role', 'avatar_path']),
                'recent_activity' => \App\Models\ActivityLog::where('action', 'like', 'lead_task.%')
                    ->latest()->limit(30)
                    ->get(['id', 'action', 'description', 'actor_name', 'actor_role', 'properties', 'created_at']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Finance tasks page failed', ['error' => $e->getMessage()]);

            return inertia('portal/finance/Tasks', ['portal' => 'finance', 'scope' => 'mine', 'today' => [], 'overdue' => [], 'this_week' => [], 'undated' => [], 'recently_done' => [], 'staffOptions' => []]);
        }
    }
}
