<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EducationWeeklyNotes
{
    public static function entries(string $monday): array
    {
        return DB::table('education_weekly_notes')->where('week_start', $monday)
            ->orderBy('created_at')->orderBy('id')->get()->map(fn ($entry) => [
                'id' => $entry->id,
                'note' => $entry->note,
                'author' => $entry->author_name,
                'created_at' => CarbonImmutable::parse($entry->created_at, 'UTC')->toIso8601String(),
                'can_edit' => $entry->user_id !== null && (int) $entry->user_id === auth()->id(),
            ])->all();
    }

    public static function save(Request $request)
    {
        abort_unless(ReportWeek::canUseNotes($request->user()), 403);
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString()],
            'entry_action' => ['required', 'in:add,edit'],
            'entry_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:4000'],
            'expected_note' => ['required_if:entry_action,edit', 'nullable', 'string', 'max:4000'],
        ]);
        if (! CarbonImmutable::parse($data['week_start'], ReportPeriod::TIMEZONE)->isMonday()) {
            throw ValidationException::withMessages(['week_start' => 'Select a report week starting on Monday.']);
        }
        $user = $request->user();
        if ($data['entry_action'] === 'add') {
            // A stable client UUID makes a retried submission safe without merging independent entries.
            DB::table('education_weekly_notes')->insertOrIgnore([
                'id' => $data['entry_id'], 'week_start' => $data['week_start'],
                'user_id' => $user->id, 'author_name' => $user->name,
                'note' => $data['note'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved = DB::table('education_weekly_notes')->find($data['entry_id']);
            if (! $saved || (int) $saved->user_id !== $user->id || $saved->week_start !== $data['week_start'] || $saved->note !== $data['note']) {
                throw ValidationException::withMessages(['note' => 'This submission has already changed. Reload before adding another note.']);
            }
        } else {
            $query = DB::table('education_weekly_notes')->where('id', $data['entry_id'])->where('week_start', $data['week_start']);
            $entry = (clone $query)->first();
            abort_unless($entry && (int) $entry->user_id === $user->id, 403);
            $updated = $query->where('note', $data['expected_note'])->update(['note' => $data['note'], 'updated_at' => now()]);
            if (! $updated) {
                throw ValidationException::withMessages(['note' => 'This note changed since you opened it. Reload before editing again.']);
            }
        }

        return back()->with('success', 'Weekly note saved.');
    }
}
