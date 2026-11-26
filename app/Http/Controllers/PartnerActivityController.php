<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\PartnerActivityFile;
use App\Models\PartnerActivityRecord;
use App\Models\User;
use App\Services\PartnerActivityTracker;
use App\Support\LeadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerActivityController extends Controller
{
    public function __construct(private readonly PartnerActivityTracker $tracker) {}

    public function index(Request $request)
    {
        $this->tracker->authorize($request);

        return inertia('portal/education/PartnerActivityTracker', $this->tracker->report($request));
    }

    public function clients(Request $request)
    {
        $this->tracker->authorize($request);
        $data = $request->validate(['agent_id' => 'required|integer', 'q' => 'nullable|string|max:100']);
        $partner = User::withTrashed()->where('role', 'agent')->findOrFail($data['agent_id']);
        $query = Lead::referredByAffiliate($partner->id)
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('is_student', true));
        foreach (preg_split('/\s+/', trim($data['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn ($q) => $q->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%")->orWhere('lead_id', 'like', "%{$word}%"));
        }

        return response()->json(['clients' => $query->latest('id')->limit(30)->get(['id', 'first_name', 'last_name', 'education_stage', 'status'])
            ->map(fn ($lead) => ['id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name), 'status' => $lead->education_stage ?: $lead->status])]);
    }

    public function save(Request $request)
    {
        $this->tracker->authorize($request);
        abort_unless($this->tracker->installed(), 503, 'Partner activity storage is not installed yet.');
        $data = $request->validate([
            'source_key' => ['required', 'string', 'max:80', 'regex:/^(manual:[a-f0-9-]{36}|agreement:[0-9]+|message:[0-9]+|task:[0-9]+)$/'],
            'version' => 'nullable|string|size:64', 'agent_id' => 'required|integer',
            'category' => ['required', Rule::in(array_keys(PartnerActivityTracker::STATUSES))],
            'activity_on' => 'required|date_format:Y-m-d', 'title' => 'required|string|max:255',
            'status' => 'required|string|max:40', 'assignee_id' => 'nullable|integer',
            'follow_up_on' => 'nullable|date_format:Y-m-d', 'lead_id' => 'nullable|integer',
            'details' => 'nullable|array', 'details.purpose' => 'nullable|string|max:3000',
            'details.remarks' => 'nullable|string|max:5000', 'details.sent_on' => 'nullable|date_format:Y-m-d',
            'details.received_on' => 'nullable|date_format:Y-m-d',
            'details.expiry_on' => 'nullable|date_format:Y-m-d', 'details.facilitator' => 'nullable|string|max:200',
            'details.attendance' => 'nullable|in:attended,absent,not_recorded',
            'details.client_name' => 'nullable|string|max:255', 'details.method' => 'nullable|string|max:100',
            'details.outcome' => 'nullable|string|max:3000', 'details.application_status' => 'nullable|string|max:200',
            'details.next_action' => 'nullable|string|max:3000', 'details.next_follow_up_on' => 'nullable|date_format:Y-m-d',
            'details.review_state' => 'nullable|in:under_review,updated',
        ]);
        $partner = User::withTrashed()->where('role', 'agent')->findOrFail($data['agent_id']);
        if (! empty($data['lead_id'])) {
            $lead = Lead::findOrFail($data['lead_id']);
            abort_unless(LeadAccess::canView($request->user(), $lead), 403);
            if ((int) ($lead->original_affiliate_id ?? $lead->agent_id) !== $partner->id) {
                throw ValidationException::withMessages(['lead_id' => 'The client is not attributed to this partner.']);
            }
        }
        $manual = str_starts_with($data['source_key'], 'manual:');
        $details = array_intersect_key($data['details'] ?? [], array_flip(['purpose', 'remarks', 'sent_on', 'received_on', 'expiry_on', 'facilitator', 'attendance', 'client_name', 'method', 'outcome', 'application_status', 'next_action', 'next_follow_up_on', 'review_state']));
        if (! empty($data['lead_id']) && $data['category'] === 'follow_up') {
            $details['client_name'] = trim($lead->first_name.' '.$lead->last_name);
        }
        if (! empty($details['sent_on']) && ! empty($details['received_on']) && $details['received_on'] < $details['sent_on']) {
            throw ValidationException::withMessages(['details.received_on' => 'Received date cannot precede sent date.']);
        }
        if ($manual) {
            if (! in_array($data['status'], PartnerActivityTracker::STATUSES[$data['category']], true)) {
                throw ValidationException::withMessages(['status' => 'Choose a status for this activity category.']);
            }
            $required = match ($data['category']) {
                'email' => ['purpose'], 'training' => ['facilitator', 'attendance'],
                'follow_up' => ['client_name', 'method', 'purpose'], default => [],
            };
            foreach ($required as $field) {
                if (empty(trim((string) ($details[$field] ?? '')))) {
                    throw ValidationException::withMessages(['details.'.$field => 'This field is required for '.$data['category'].'.']);
                }
            }
            $source = null;
        } else {
            $source = $this->tracker->sources($request->user())->get($data['source_key']);
            abort_unless($source && ! ($source['event_only'] ?? false) && $source['agent_id'] === $partner->id, 404);
            $data = array_merge($data, array_intersect_key($source, array_flip(['agent_id', 'category', 'title', 'activity_on', 'lead_id'])));
            $data['status'] = $source['category'] === 'email' && in_array($data['status'], PartnerActivityTracker::STATUSES['email'], true) ? $data['status'] : $source['status'];
            if ($source['category'] === 'follow_up') {
                $data['assignee_id'] = $source['assignee_id'];
                $data['follow_up_on'] = $source['follow_up_on'];
            }
            // Metadata cannot rewrite original contract dates, source outcome or client identity.
            $details = array_intersect_key($details, array_flip(match ($source['category']) {
                'email' => ['purpose', 'remarks'], 'agreement' => ['remarks', 'review_state'], 'follow_up' => ['remarks', 'next_action', 'next_follow_up_on'], default => ['remarks']
            }));
        }
        $prior = PartnerActivityRecord::where('source_key', $data['source_key'])->first();
        $keptAssignee = $prior?->assignee_id ?? ($source['assignee_id'] ?? null);
        if (! empty($data['assignee_id']) && (int) $data['assignee_id'] !== (int) $keptAssignee && ! $this->tracker->staff()->contains('id', $data['assignee_id'])) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose active staff with access to this tracker.']);
        }
        if ($manual && $data['activity_on'] > now()->timezone(config('app.timezone'))->toDateString()
            && ($data['category'] === 'email' || in_array($data['status'], ['completed', 'responded', 'no_response', 'signed'], true))) {
            throw ValidationException::withMessages(['activity_on' => 'Completed/sent activity cannot be dated in the future. Use a planned status or follow-up date.']);
        }
        DB::transaction(function () use ($data, $details, $request, $manual) {
            // Serialize creation/retries for one partner without touching their account.
            User::withTrashed()->whereKey($data['agent_id'])->lockForUpdate()->firstOrFail();
            $record = PartnerActivityRecord::where('source_key', $data['source_key'])->lockForUpdate()->first();
            if (($record && ! hash_equals($record->version(), $data['version'] ?? '')) || (! $record && ! empty($data['version']))) {
                throw ValidationException::withMessages(['version' => 'This activity changed. Reload before saving.']);
            }
            if ($record && ($record->agent_id !== (int) $data['agent_id'] || $record->category !== $data['category'])) {
                throw ValidationException::withMessages(['agent_id' => 'Keep the original partner and category; add a separate activity for different work.']);
            }
            if ($record?->lead_id) {
                $existingLead = Lead::find($record->lead_id);
                abort_unless($existingLead && LeadAccess::canView($request->user(), $existingLead), 403);
                if ($record->lead_id !== (int) ($data['lead_id'] ?? 0)) {
                    throw ValidationException::withMessages(['lead_id' => 'Keep the original linked client. Record separate work as a new activity.']);
                }
            }
            $before = $record?->getRawOriginal();
            $record ??= new PartnerActivityRecord(['created_by' => $request->user()->id, 'source_key' => $data['source_key']]);
            $record->fill(array_intersect_key($data, array_flip(['agent_id', 'category', 'activity_on', 'title', 'status', 'assignee_id', 'lead_id', 'follow_up_on'])));
            $record->details = $details;
            $record->save();
            ActivityLog::record('partner_activity.saved', ['portal' => 'education', 'entity_type' => PartnerActivityRecord::class, 'entity_id' => $record->id,
                'properties' => ['source_key' => $record->source_key, 'automatic_source' => ! $manual, 'before' => $before, 'after' => $record->getRawOriginal()]]);
        });

        return back()->with('success', 'Partner activity saved. Original emails, agreements and tasks were not changed.');
    }

    private function accessibleRecord(Request $request, PartnerActivityRecord $record): void
    {
        abort_unless($this->tracker->rows($request->user())->contains('source_key', $record->source_key), 404);
    }

    public function upload(Request $request, PartnerActivityRecord $record)
    {
        $this->tracker->authorize($request);
        $this->accessibleRecord($request, $record);
        $request->validate(['file' => 'required|file|mimes:pdf,docx,xlsx,jpg,jpeg,png,txt|max:10240']);
        $path = null;
        try {
            DB::transaction(function () use ($request, $record, &$path) {
                PartnerActivityRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
                if (PartnerActivityFile::where('activity_id', $record->id)->count() >= 10) {
                    throw ValidationException::withMessages(['file' => 'An activity can hold up to ten files.']);
                }
                $upload = $request->file('file');
                $path = $upload->store('partner-activity/'.$record->id, 'local');
                if (! $path) {
                    throw new \RuntimeException('Private upload was not stored.');
                }
                $file = PartnerActivityFile::create(['activity_id' => $record->id, 'path' => $path,
                    'name' => Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $upload->getClientOriginalName()))), 240, ''),
                    'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'uploaded_by' => $request->user()->id]);
                ActivityLog::record('partner_activity.file_uploaded', ['portal' => 'education', 'entity_type' => PartnerActivityFile::class, 'entity_id' => $file->id]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return back()->with('success', 'Private activity attachment uploaded.');
    }

    public function download(Request $request, PartnerActivityFile $file)
    {
        $this->tracker->authorize($request);
        $this->accessibleRecord($request, PartnerActivityRecord::findOrFail($file->activity_id));
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        if ($request->boolean('inline') && in_array($file->mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            return response()->file(Storage::disk('local')->path($file->path), ['Content-Type' => $file->mime, 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return Storage::disk('local')->download($file->path, $file->name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function agreementDocument(Request $request, AgentAgreement $agreement)
    {
        $this->tracker->authorize($request);
        abort_unless(User::withTrashed()->whereKey($agreement->agent_id)->where('role', 'agent')->exists(), 404);
        abort_unless($agreement->file_path && Storage::disk('local')->exists($agreement->file_path), 404);

        return Storage::disk('local')->download($agreement->file_path, basename($agreement->original_name ?: 'partner-agreement.pdf'), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request)
    {
        $this->tracker->authorize($request);
        $format = $request->validate(['format' => 'required|in:xlsx,pdf'])['format'];
        $report = $this->tracker->report($request);
        if ($report['rows']->count() + $report['agreements']->count() + $report['workload']->count() > ($format === 'pdf' ? 500 : 5000)) {
            throw ValidationException::withMessages(['from' => 'Narrow the filters to export a smaller report (PDF: 500 rows; Excel: 5,000 rows).']);
        }
        if ($format === 'pdf') {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.partner-activities', $report)->setPaper('a4', 'landscape')->download('partner-activities.pdf');
        }

        return app(\App\Services\PartnerActivityWorkbook::class)->download($report);
    }
}
