<?php

namespace App\Services\Reports;

use App\Models\EducationDatedReportNote;
use App\Models\User;

class EducationDatedNotes
{
    public static function forPeriod(ReportPeriod $period, User $user): array
    {
        $base = EducationDatedReportNote::where('user_id', $user->id)
            ->where('occurred_on', '<=', $period->now->toDateString())
            ->when(! $period->isAll(), fn ($q) => $q
                ->where('occurred_on', '>=', $period->start->toDateString())
                ->where('occurred_on', '<', $period->end->toDateString()));
        $counts = (clone $base)->selectRaw('occurred_on, COUNT(*) AS total')->groupBy('occurred_on')
            ->pluck('total', 'occurred_on')->map(fn ($total) => (int) $total)->all();
        $query = (clone $base)->orderByDesc('occurred_on')->orderByDesc('id');
        $page = (clone $query)->paginate(30, ['*'], 'note_page')->withQueryString();
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $query)->paginate(30, ['*'], 'note_page', $page->lastPage())->withQueryString();
        }
        $page->through(fn ($note) => [
            'id' => $note->id, 'date' => $note->occurred_on->toDateString(),
            'category' => $note->category, 'body' => $note->body, 'revision' => $note->revision,
        ]);

        return ['entries' => $page, 'day_counts' => $counts, 'categories' => EducationDatedReportNote::CATEGORIES];
    }
}
