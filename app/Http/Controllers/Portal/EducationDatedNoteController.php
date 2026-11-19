<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\EducationDatedReportNote;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportWeek;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EducationDatedNoteController extends Controller
{
    private function authorize(Request $request): void
    {
        abort_unless(ReportWeek::canUseNotes($request->user()) && $request->user()->canAccessPortal('education'), 403);
    }

    private function attributes(Request $request): array
    {
        $data = $request->validate([
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString()],
            'category' => ['required', Rule::in(array_keys(EducationDatedReportNote::CATEGORIES))],
            'body' => 'required|string|max:4000',
        ]);
        $data['body'] = trim($data['body']);
        if ($data['body'] === '') {
            throw ValidationException::withMessages(['body' => 'Enter a note before saving.']);
        }

        return $data;
    }

    public function store(Request $request)
    {
        $this->authorize($request);
        $data = $this->attributes($request);
        $key = $request->validate(['submission_key' => 'required|uuid'])['submission_key'];
        $note = EducationDatedReportNote::withTrashed()->firstOrCreate(
            ['user_id' => $request->user()->id, 'submission_key' => $key], $data,
        );
        abort_if($note->trashed() || $note->occurred_on->toDateString() !== $data['occurred_on']
            || $note->category !== $data['category'] || $note->body !== $data['body'],
            409, 'This submission was already used. Copy your draft and refresh before adding another note.');

        return back()->with('success', 'Dated Education note saved.');
    }

    public function update(Request $request, int $note)
    {
        $this->authorize($request);
        DB::transaction(function () use ($request, $note) {
            $entry = $this->ownedNote($request, $note);
            $this->checkRevision($request, $entry);
            $entry->update($this->attributes($request) + ['revision' => $entry->revision + 1]);
        });

        return back()->with('success', 'Dated Education note updated.');
    }

    public function destroy(Request $request, int $note)
    {
        $this->authorize($request);
        DB::transaction(function () use ($request, $note) {
            $entry = $this->ownedNote($request, $note);
            $this->checkRevision($request, $entry);
            $entry->delete();
        });

        return back()->with('success', 'Dated note removed from your report.');
    }

    private function ownedNote(Request $request, int $id): EducationDatedReportNote
    {
        return EducationDatedReportNote::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
    }

    private function checkRevision(Request $request, EducationDatedReportNote $note): void
    {
        $revision = $request->validate(['revision' => 'required|integer|min:1'])['revision'];
        if ((int) $revision !== $note->revision) {
            throw ValidationException::withMessages(['revision' => 'This note changed in another window. Copy your draft, then refresh and reopen it before trying again.']);
        }
    }
}
