<?php

namespace App\Http\Controllers;

use App\Jobs\SendEducationSchoolMail;
use App\Models\ActivityLog;
use App\Models\EducationRecipientList;
use App\Models\EducationSchoolMailing;
use App\Models\EducationVisit;
use App\Models\MessageTemplate;
use App\Services\EducationSchoolMailAudience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EducationSchoolMailController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && $u->is_active && $u->canAccessPortal('education') && ($u->role === 'education' || $u->isAdmin()), 403);
    }

    private function filters(Request $request, bool $required = false): array
    {
        $request->validate(['recipient_list_id' => ['nullable', 'integer'], 'load_saved_list' => ['sometimes', 'boolean'], 'edit_members' => ['sometimes', 'boolean']]);
        $recipientList = $request->filled('recipient_list_id') ? EducationRecipientList::where('created_by', $request->user()->id)
            ->where('source', 'school')->findOrFail($request->input('recipient_list_id')) : null;
        if ($recipientList && $request->boolean('load_saved_list')) {
            $request->merge($recipientList->filters + ['purpose' => $request->input('purpose', 'email_follow_up')]);
        }
        $filters = $request->validate([
            'visit_ids' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'visit_ids.*' => ['required', 'integer', 'distinct', 'exists:education_visits,id'],
            'audience' => ['required', Rule::in(['registered', 'attended'])],
            'purpose' => ['required', Rule::in(['email_follow_up', 'email_newsletter'])],
            'list_id' => ['nullable', 'integer', 'exists:education_rsvp_saved_lists,id'],
            'folder_id' => ['nullable', 'integer', Rule::prohibitedIf($request->filled('list_id')), 'exists:education_rsvp_folders,id'],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('registered_from'), 'after_or_equal:registered_from')],
        ]);
        $rsvpFilters = empty($filters['list_id']) ? [] : \App\Models\EducationRsvpSavedList::findOrFail($filters['list_id'])->filters;
        if (! empty($filters['folder_id'])) {
            $rsvpFilters['folder_id'] = $filters['folder_id'];
        }
        $filters['rsvp_filters'] = app(\App\Services\EducationAttendance::class)->filters($rsvpFilters);
        if ($recipientList) {
            $filters['recipient_list_id'] = $recipientList->id;
            if ($recipientList->mode === 'custom') {
                if ($request->boolean('edit_members')) {
                    $filters['edit_members'] = 1;
                } else {
                    $filters['member_refs'] = $recipientList->source_refs;
                }
            }
        }

        return $filters;
    }

    public function index(Request $request, EducationSchoolMailAudience $audience)
    {
        $this->authorizeStaff($request);
        $filters = $request->has('audience') || $request->filled('recipient_list_id') ? $this->filters($request) + ['visit_ids' => []] : ['visit_ids' => [], 'audience' => 'registered', 'purpose' => 'email_follow_up'];
        $rows = empty($filters['visit_ids']) ? collect() : $audience->rows($filters);
        $savedList = isset($filters['recipient_list_id']) ? EducationRecipientList::findOrFail($filters['recipient_list_id']) : null;
        $savedRefs = collect($savedList?->source_refs ?? [])->map(fn ($ref) => $ref['type'].':'.$ref['id'])->flip();

        return inertia('portal/education/SchoolMail', [
            'visits' => EducationVisit::where('status', '!=', 'cancelled')->orderBy('school')->get()->map(fn ($v) => ['id' => $v->id, 'school' => $v->school, 'activity' => $v->activity, 'summary' => $v->summary()]),
            'filters' => $filters, 'recipients' => $rows->map(fn ($row) => collect($row)->except(['source_refs', 'member_refs', 'context'])->all() + [
                'selected' => $row['eligible'] && collect($row['member_refs'])->contains(fn ($ref) => $savedRefs->has($ref['type'].':'.$ref['id'])),
            ]),
            'audienceLists' => \App\Models\EducationRsvpSavedList::with('folder:id,name')->orderBy('name')->get(['id', 'name', 'folder_id', 'filters']),
            'audienceGroups' => \App\Models\EducationRsvpFolder::orderBy('name')->get(['id', 'name']),
            'recipientLists' => EducationRecipientList::where('created_by', $request->user()->id)->where('source', 'school')->orderBy('name')->get(['id', 'name', 'mode']),
            'templates' => $audience->templates()->map(function ($t) use ($audience) {
                $snapshot = $audience->templateSnapshot($t);

                return ['id' => $t->id, 'name' => $t->name, 'subject' => $snapshot['subject'], 'body' => $snapshot['body_html'],
                    'shell' => $snapshot['shell'], 'from_email' => $snapshot['from_email'], 'from_name' => $snapshot['from_name'],
                    'reply_to_email' => $snapshot['reply_to_email'], 'reply_to_name' => $snapshot['reply_to_name'],
                    'review' => $audience->templateReview($snapshot)];
            }),
            'mailings' => EducationSchoolMailing::latest()->limit(20)->get()->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'created_at' => $m->created_at->toIso8601String(),
                'counts' => $m->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'recipients' => $m->recipients()->get(['name', 'email', 'status', 'detail']),
            ]),
        ]);
    }

    public function saveRecipientList(Request $request, EducationSchoolMailAudience $audience)
    {
        $this->authorizeStaff($request);
        $filters = $this->filters($request, true);
        $request->validate(['save_list_id' => ['nullable', 'integer']]);
        $list = $request->filled('save_list_id') ? EducationRecipientList::where('created_by', $request->user()->id)->where('source', 'school')->findOrFail($request->input('save_list_id'))
            : new EducationRecipientList(['created_by' => $request->user()->id, 'source' => 'school']);
        $request->merge(['list_name' => is_string($request->input('list_name')) ? trim($request->input('list_name')) : $request->input('list_name')]);
        $data = $request->validate([
            'list_name' => ['required', 'string', 'max:100', Rule::unique('education_recipient_lists', 'name')->where('created_by', $request->user()->id)->ignore($list->id)],
            'mode' => ['required', Rule::in(['smart', 'custom'])],
            'recipient_keys' => ['exclude_unless:mode,custom', 'required', 'array', 'min:1', 'max:1000'],
            'recipient_keys.*' => ['string', 'size:64', 'distinct'],
        ]);
        $refs = $data['mode'] === 'custom' ? $audience->selected($filters, $data['recipient_keys'])->pluck('member_refs')->flatten(1)->unique(fn ($ref) => $ref['type'].':'.$ref['id'])->values()->all() : [];
        try {
            $list->fill(['name' => $data['list_name'], 'mode' => $data['mode'],
                'filters' => collect($filters)->except(['purpose', 'recipient_list_id', 'member_refs', 'edit_members'])->all(), 'lead_ids' => [], 'source_refs' => $refs])->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['list_name' => 'You already have a recipient list with this name.']);
        }
        ActivityLog::record('education.recipient_list.saved', ['portal' => 'education', 'properties' => ['list_id' => $list->id, 'mode' => $list->mode, 'source' => 'school']]);

        return back()->with('success', 'Event recipient list saved.');
    }

    public function store(Request $request, EducationSchoolMailAudience $audience)
    {
        $this->authorizeStaff($request);
        $filters = $this->filters($request, true);
        $data = $request->validate(['submission_id' => 'required|uuid', 'name' => 'required|string|max:160',
            'template_id' => 'required|integer|exists:message_templates,id', 'template_review' => 'required|string|size:64', 'recipient_keys' => 'required|array|min:1|max:1000',
            'recipient_keys.*' => 'required|string|size:64|distinct', 'confirmed' => 'required|accepted']);
        $prior = EducationSchoolMailing::where('submission_id', $data['submission_id'])->first();
        if ($prior) {
            abort_unless($prior->created_by === $request->user()->id, 409);

            return redirect('/portal/education/school-mail')->with('success', 'This mailing was already created. Check its history.');
        }
        $template = MessageTemplate::active()->find($data['template_id']);
        if (! $template || ! $template->hasChannel('email') || ! $audience->compatible($template)) {
            throw ValidationException::withMessages(['template_id' => 'Choose an active school-visit email template with a subject and message body.']);
        }
        $snapshot = $audience->templateSnapshot($template);
        if (! hash_equals($audience->templateReview($snapshot), $data['template_review'])) {
            throw ValidationException::withMessages(['template_review' => 'The template or email branding changed. Review the updated message and confirm again.']);
        }
        $rows = $audience->selected($filters, $data['recipient_keys']);
        if (! $rows->every(fn ($row) => $audience->renderedMessage($snapshot, $row['context']) !== null)) {
            throw ValidationException::withMessages(['template_id' => 'The template produces a blank subject or message for a selected recipient. Choose another template.']);
        }
        DB::transaction(function () use ($request, $data, $filters, $snapshot, $rows) {
            $mailing = EducationSchoolMailing::create(['submission_id' => $data['submission_id'], 'created_by' => $request->user()->id, 'name' => $data['name'], 'filters' => $filters,
                'template_snapshot' => $snapshot]);
            foreach ($rows as $r) {
                $recipient = $mailing->recipients()->create(['email' => $r['email'], 'name' => $r['name'], 'source_refs' => $r['source_refs'], 'context_snapshot' => $r['context']]);
                DB::afterCommit(fn () => SendEducationSchoolMail::enqueue($recipient->id));
            }
            ActivityLog::record('education.school_mail.created', ['portal' => 'education', 'properties' => ['mailing_id' => $mailing->id, 'filters' => $filters, 'recipient_count' => $rows->count(), 'template_id' => $snapshot['id']]]);
        });

        return redirect('/portal/education/school-mail')->with('success', 'School-visit mailing saved for delivery. Check history for worker results.');
    }
}
