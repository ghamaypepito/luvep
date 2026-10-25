<?php

namespace App\Http\Controllers;

use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Services\EducationRsvpWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class EducationRsvpController extends Controller
{
    public function show(Request $request)
    {
        return response()->view('education.rsvp', ['visits' => EducationVisit::where('status', 'open')->orderBy('id')->get()->map->publicData()->values(), 'initialKind' => $request->query('type') === 'partnership' ? 'partnership' : 'school'])->header('Cache-Control', 'private, no-store');
    }

    public function catalogue()
    {
        return response()->json(['visits' => EducationVisit::where('status', 'open')->orderBy('id')->get()->map->publicData()->values()]);
    }

    public function store(Request $request, EducationRsvpWorkflow $workflow)
    {
        $d = $request->validate(['submission_id' => 'required|uuid', 'visit_id' => 'required|integer|exists:education_visits,id', 'full_name' => 'required|string|max:150', 'email' => 'required|email:rfc|max:254', 'phone' => 'nullable|string|max:40', 'role' => 'nullable|string|max:100', 'consent' => 'required|accepted', 'email_reminders' => 'sometimes|boolean', 'future_invites' => 'sometimes|boolean', 'website' => 'nullable|string|max:0']);
        $d['email'] = strtolower(trim($d['email']));
        $d['full_name'] = preg_replace('/\s+/u', ' ', trim($d['full_name']));
        abort_unless(EducationRsvpWorkflow::nameKey($d['full_name']), 422, 'Please enter your name.');
        $v = EducationVisit::findOrFail($d['visit_id']);
        abort_unless($v->status === 'open', 422, 'This visit is not accepting RSVPs.');
        $fields = ['full_name' => $d['full_name'], 'name_key' => EducationRsvpWorkflow::nameKey($d['full_name']), 'email' => $d['email'], 'phone' => trim($d['phone'] ?? '') ?: null, 'role' => $v->kind === 'partnership' ? (trim($d['role'] ?? '') ?: null) : null, 'email_reminders' => (bool) ($d['email_reminders'] ?? false), 'future_invites' => (bool) ($d['future_invites'] ?? false), 'education_visit_id' => $v->id];
        $hash = hash('sha256', json_encode($fields));

        return Cache::lock('rsvp:email:'.hash('sha256', $d['email']), 20)->block(5, function () use ($d, $fields, $hash, $v, $workflow) {
            $prior = EducationRsvp::where('submission_id', $d['submission_id'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->payload_hash, $hash), 409, 'Please reload before changing a completed submission.');

                return response()->json(['ok' => true]);
            }if (EducationRsvp::where('education_visit_id', $v->id)->where('email', $d['email'])->exists()) {
                return response()->json(['ok' => true]);
            }$key = 'rsvp-rate:'.hash('sha256', $d['email']);
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Please wait before submitting another RSVP.');
            DB::transaction(function () use ($fields, $d, $hash, $workflow, $v) {
                $r = EducationRsvp::create($fields + ['submission_id' => $d['submission_id'], 'public_id' => (string) Str::uuid(), 'payload_hash' => $hash, 'consented_at' => now(), 'schedule_version_seen' => $v->schedule_confirmed ? $v->schedule_version : 0]);
                $workflow->queueRsvp($r);
            });
            RateLimiter::hit($key, 3600);

            return response()->json(['ok' => true], 201);
        });
    }

    public function verify(Request $request, EducationRsvp $rsvp, EducationRsvpWorkflow $workflow)
    {
        $rsvp->update(['verified_at' => $rsvp->verified_at ?: now()]);
        $request->session()->put('education_rsvp_verified_email', hash('sha256', $rsvp->email));
        $workflow->matchVerified($rsvp);

        return redirect('/education/rsvp?verified=1&type='.$rsvp->visit->kind.'&school='.$rsvp->visit->slug);
    }

    public function recognize(Request $request)
    {
        $d = $request->validate(['email' => 'required|email:rfc|max:254', 'full_name' => 'required|string|max:150']);
        $email = strtolower(trim($d['email']));
        $proof = $request->session()->get('education_rsvp_verified_email');
        $ok = $proof && hash_equals($proof, hash('sha256', $email)) && EducationRsvp::where('email', $email)->where('name_key', EducationRsvpWorkflow::nameKey($d['full_name']))->whereNotNull('verified_at')->exists();

        return response()->json(['recognized' => (bool) $ok])->header('Cache-Control', 'private, no-store');
    }
}
