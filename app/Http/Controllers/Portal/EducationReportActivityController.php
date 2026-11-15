<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\EducationReportActivity;
use App\Models\Lead;
use App\Models\Setting;
use App\Services\Reports\EducationPersonalReport;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportWeek;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EducationReportActivityController extends Controller
{
    private function authorize(Request $request): void
    {
        abort_unless(ReportWeek::canUseNotes($request->user()) && $request->user()->canAccessPortal('education'), 403);
    }

    public function clients(Request $request)
    {
        $this->authorize($request);
        $data = $request->validate(['q' => 'required|string|min:2|max:80']);
        $query = trim($data['q']);
        if (mb_strlen($query) < 2) {
            return response()->json(['clients' => []]);
        }
        $clients = EducationPersonalReport::clients();
        foreach (preg_split('/\s+/', $query) as $word) {
            $clients->where(fn ($q) => $q->where('first_name', 'like', '%'.$word.'%')
                ->orWhere('last_name', 'like', '%'.$word.'%')->orWhere('lead_id', 'like', '%'.$word.'%'));
        }
        return response()->json(['clients' => $clients->orderBy('first_name')->orderBy('id')->limit(25)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($lead) => ['id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name)])]);
    }

    public function store(Request $request)
    {
        $this->authorize($request);
        $data = $request->validate([
            'lead_id' => 'nullable|required_without:client_name|integer',
            'client_name' => 'nullable|required_without:lead_id|string|max:160',
            'reported_status' => ['nullable', 'required_if:kind,status_update', Rule::in(Lead::EDUCATION_STAGES)],
            'kind' => ['required', Rule::in(array_keys(EducationPersonalReport::KINDS))],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString()],
            'submission_key' => 'required|uuid',
            'details' => [Rule::requiredIf(in_array($request->input('kind'), ['enrolment', 'offer', 'proposal', 'status_update'], true)), 'nullable', 'string', 'max:1000'],
        ]);
        $leadId = $data['lead_id'] ?? null;
        $clientName = isset($data['client_name']) ? trim(preg_replace('/\s+/u', ' ', $data['client_name'])) : null;
        if ($leadId !== null && $clientName !== null) {
            throw ValidationException::withMessages(['client_name' => 'Choose a portal client or enter a name, not both.']);
        }
        if ($leadId === null && ($clientName === null || $clientName === '')) {
            throw ValidationException::withMessages(['client_name' => 'Enter a client name or choose a portal client.']);
        }
        if ($leadId !== null) {
            EducationPersonalReport::clients()->whereKey($leadId)->firstOrFail();
        }
        $status = $data['kind'] === 'status_update' ? $data['reported_status'] : null;
        $details = trim($data['details'] ?? '') ?: null;
        $entry = EducationReportActivity::withTrashed()->firstOrCreate(
            ['user_id' => $request->user()->id, 'submission_key' => $data['submission_key']],
            ['lead_id' => $leadId, 'client_name' => $clientName, 'reported_status' => $status,
                'kind' => $data['kind'], 'occurred_on' => $data['occurred_on'], 'details' => $details],
        );
        abort_if($entry->trashed() || (int) $entry->lead_id !== (int) $leadId || $entry->client_name !== $clientName
            || $entry->reported_status !== $status || $entry->kind !== $data['kind']
            || $entry->occurred_on->toDateString() !== $data['occurred_on'] || $entry->details !== $details,
            409, 'This submission was already used. Refresh the form before recording another activity.');

        return back()->with('success', 'Education accomplishment recorded.');
    }

    public function destroy(Request $request, int $activity)
    {
        $this->authorize($request);
        EducationReportActivity::where('user_id', $request->user()->id)->findOrFail($activity)->delete();
        return back()->with('success', 'Accomplishment removed from your report.');
    }

    public function notes(Request $request)
    {
        $this->authorize($request);
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString()],
            'accomplishments' => 'present|nullable|string|max:4000',
            'other_activities' => 'present|nullable|string|max:4000',
            'next_week_tasks' => 'present|nullable|string|max:4000',
        ]);
        if (! CarbonImmutable::parse($data['week_start'], ReportPeriod::TIMEZONE)->isMonday()) {
            throw ValidationException::withMessages(['week_start' => 'Choose a week starting on Monday.']);
        }
        $week = $data['week_start'];
        unset($data['week_start']);
        Setting::set(EducationPersonalReport::noteKey($request->user()->id, $week), $data, 'json', 'Personal Education report', 'education');
        return back()->with('success', 'Your Education report notes were saved.');
    }
}
