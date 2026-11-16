<?php

namespace App\Http\Controllers;

use App\Jobs\SendEducationSchoolMail;
use App\Models\ActivityLog;
use App\Models\EducationSchoolMailing;
use App\Models\EducationVisit;
use App\Models\MessageTemplate;
use App\Services\EducationSchoolMailAudience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EducationSchoolMailController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && $u->is_active && $u->canAccessPortal('education') && ($u->role === 'education' || $u->isAdmin()), 403);
    }

    private function filters(Request $request, bool $required = false): array
    {
        return $request->validate([
            'visit_ids' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'visit_ids.*' => ['required', 'integer', 'distinct', 'exists:education_visits,id'],
            'audience' => ['required', Rule::in(['registered', 'attended'])],
            'purpose' => ['required', Rule::in(['email_follow_up', 'email_newsletter'])],
        ]);
    }

    public function index(Request $request, EducationSchoolMailAudience $audience)
    {
        $this->authorizeStaff($request);
        $filters = $request->has('audience') ? $this->filters($request) : ['visit_ids' => [], 'audience' => 'registered', 'purpose' => 'email_follow_up'];
        $rows = empty($filters['visit_ids']) ? collect() : $audience->rows($filters);

        return inertia('portal/education/SchoolMail', [
            'visits' => EducationVisit::where('status', '!=', 'cancelled')->orderBy('school')->get()->map(fn ($v) => ['id' => $v->id, 'school' => $v->school, 'activity' => $v->activity, 'summary' => $v->summary()]),
            'filters' => $filters, 'recipients' => $rows->map(fn ($r) => collect($r)->except('source_refs')->all()),
            'templates' => $audience->templates()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'subject' => $t->email_subject, 'body' => $t->email_body]),
            'mailings' => EducationSchoolMailing::latest()->limit(20)->get()->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'created_at' => $m->created_at->toIso8601String(),
                'counts' => $m->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'recipients' => $m->recipients()->get(['name', 'email', 'status', 'detail']),
            ]),
        ]);
    }

    public function store(Request $request, EducationSchoolMailAudience $audience)
    {
        $this->authorizeStaff($request);
        $filters = $this->filters($request, true);
        $data = $request->validate(['submission_id' => 'required|uuid', 'name' => 'required|string|max:160',
            'template_id' => 'required|integer|exists:message_templates,id', 'recipient_keys' => 'required|array|min:1|max:1000',
            'recipient_keys.*' => 'required|string|size:64|distinct', 'confirmed' => 'required|accepted']);
        $prior = EducationSchoolMailing::where('submission_id', $data['submission_id'])->first();
        if ($prior) {
            abort_unless($prior->created_by === $request->user()->id, 409);

            return redirect('/portal/education/school-mail')->with('success', 'This mailing was already created. Check its history.');
        }
        $template = MessageTemplate::active()->findOrFail($data['template_id']);
        abort_unless($template->hasChannel('email') && $audience->compatible($template), 422, 'Choose a school-visit compatible email template.');
        $rows = $audience->selected($filters, $data['recipient_keys']);
        DB::transaction(function () use ($request, $data, $filters, $template, $rows) {
            $mailing = EducationSchoolMailing::create(['submission_id' => $data['submission_id'], 'created_by' => $request->user()->id, 'name' => $data['name'], 'filters' => $filters,
                'template_snapshot' => ['id' => $template->id, 'subject' => $template->email_subject, 'body' => $template->email_body,
                    'banner' => $template->banner_image, 'footer' => $template->footer_image, 'from_email' => $template->from_email, 'from_name' => $template->from_name,
                    'reply_to_email' => $template->reply_to_email, 'reply_to_name' => $template->reply_to_name]]);
            foreach ($rows as $r) {
                $recipient = $mailing->recipients()->create(['email' => $r['email'], 'name' => $r['name'], 'source_refs' => $r['source_refs']]);
                DB::afterCommit(fn () => SendEducationSchoolMail::enqueue($recipient->id));
            }
            ActivityLog::record('education.school_mail.created', ['portal' => 'education', 'properties' => ['mailing_id' => $mailing->id, 'filters' => $filters, 'recipient_count' => $rows->count(), 'template_id' => $template->id]]);
        });

        return redirect('/portal/education/school-mail')->with('success', 'School-visit mailing saved for delivery. Check history for worker results.');
    }
}
