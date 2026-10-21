<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Mail\CaseAssessmentInvitation;
use App\Models\{Assessment, FamilyIntake, Lead, ResidentIntake, StudentIntake, User, VisaType, VisitorIntake, WorkIntake};
use App\Support\AssessmentFormSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaseAssessmentInvitationController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->role === 'immigration' || in_array($user->role, User::IMMIGRATION_ROLES, true)), 403);
        abort_unless($lead->is_immigration_case, 404);

        $message = DB::transaction(function () use ($lead, $user) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $status = $lead->portal_invitation_status ?: 'none';
            $this->require($status !== 'revoked', 'Portal access was revoked. An administrator must review access before an assessment can be sent.');
            $this->require((bool) filter_var($lead->email, FILTER_VALIDATE_EMAIL), 'Add a valid client email before requesting an assessment.');
            $this->require((bool) $lead->first_name && (bool) $lead->last_name, 'Add the client first and last names before requesting an assessment.');
            $client = $lead->portalUser;
            if ($status === 'accepted') {
                $this->require($client && $client->role === User::ROLE_LEAD && $client->is_active, 'The linked client portal account must be active before sending an assessment.');
            }

            $assessment = $lead->assessment_id ? Assessment::find($lead->assessment_id) : null;
            if ($lead->assessment_id) {
                $this->require($assessment && $assessment->intakeable && AssessmentFormSchema::typeForClass($assessment->intakeable::class), 'The linked assessment is unavailable. Review this case before requesting another assessment.');
                $this->require(! Lead::withTrashed()->where('assessment_id', $assessment->id)->whereKeyNot($lead->id)->exists(), 'This assessment is linked to another case. Review the case link first.');
            } else {
                $visa = VisaType::where('name', $lead->inz_visa_type)->first();
                $class = match (strtolower($visa?->category ?? '')) {
                    'resident' => ResidentIntake::class,
                    'work' => WorkIntake::class,
                    'student' => StudentIntake::class,
                    'visitor' => VisitorIntake::class,
                    'family', 'partnership' => FamilyIntake::class,
                    default => null,
                };
                $this->require($class !== null, 'Choose a visa type with a supported assessment category before sending the form.');
                $intake = $class::create([
                    'intake_id' => 'CASE-'.Str::uuid(),
                    'status' => 'Draft',
                    'first_name' => $lead->first_name,
                    $class === ResidentIntake::class ? 'last_name' : 'family_name' => $lead->last_name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'dob' => $lead->dob,
                ]);
                $assessment = Assessment::create([
                    'visa_type_id' => $visa->id,
                    'intakeable_type' => $class,
                    'intakeable_id' => $intake->id,
                    'applicant_first_name' => $lead->first_name,
                    'applicant_last_name' => $lead->last_name,
                    'applicant_email' => $lead->email,
                    'applicant_phone' => $lead->phone,
                    'status' => 'draft',
                    'payment_status' => 'pending',
                ]);
                $lead->forceFill(['assessment_id' => $assessment->id])->save();
            }

            if ($status === 'accepted') {
                if ($assessment->invitation_sent_at) {
                    return 'The assessment invitation has already been sent. The same form remains available in the client portal.';
                }
                // Send only to the account linked by lead_id, never an email-matched account.
                try {
                    Mail::to($client->email)->send(new CaseAssessmentInvitation($lead));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Case assessment invitation transport failed', ['lead_id' => $lead->id]);
                    throw ValidationException::withMessages(['assessment' => 'The assessment email could not be sent. Please try again.']);
                }
                $assessment->forceFill(['invitation_sent_at' => now()])->save();
                return 'Assessment invitation sent. Responses will stay linked to this case.';
            }
            if ($status === 'none') {
                $lead->update([
                    'portal_invitation_status' => 'pending',
                    'portal_invitation_requested_by' => $user->id,
                    'portal_invitation_requested_at' => now(),
                    'portal_invitation_approved_by' => null,
                    'portal_invitation_approved_at' => null,
                    'portal_invitation_token' => null,
                    'portal_invitation_expires_at' => null,
                ]);
            }
            return $status === 'sent'
                ? 'Assessment prepared. The client can complete it in the portal after accepting their existing access invitation.'
                : 'Assessment prepared. Portal access is awaiting administrator approval; no client email has been sent.';
        });

        return back()->with('success', $message);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['assessment' => $message]);
        }
    }
}
