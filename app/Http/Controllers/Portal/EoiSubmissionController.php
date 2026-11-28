<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AddNoteRequest;
use App\Http\Requests\Onboarding\AssignRequest;
use App\Http\Requests\Onboarding\ConvertToTenantRequest;
use App\Http\Requests\Onboarding\LinkPropertyRequest;
use App\Http\Requests\Onboarding\UpdateStatusRequest;
use App\Models\ActivityLog;
use App\Models\EoiSubmission;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accommodation\RoomInventory;
use App\Services\Accommodation\OnboardingOwners;
use App\Services\Accommodation\OnboardingEmailMilestones;
use App\Services\Accommodation\ViewingSchedule;
use App\Services\OnboardingTransitionService;
use App\Support\OnboardingPipeline;
use App\Support\AccommodationOnboardingStages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EoiSubmissionController extends Controller
{
    /** Kept for backwards compatibility; the pipeline is the source of truth now. */
    public const STATUSES = ['new', 'reviewed', 'shortlisted', 'declined'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'stage' => 'nullable|string|max:60',
            'assigned_to' => 'nullable|string|max:24',
        ]);
        $shared = [
            'filters' => $filters,
            'options' => ['stages' => AccommodationOnboardingStages::options(), 'team' => OnboardingOwners::options()],
        ];

        $submissions = $this->filteredQuery($request)
            ->with(['property', 'assignedTo'])
            ->paginate(15)
            ->withQueryString()
            ->through(function (EoiSubmission $s) {
                return [
                    'id' => $s->id, 'full_legal_name' => $s->full_legal_name, 'email' => $s->email,
                    'mobile' => $s->mobile, 'created_at' => $s->created_at,
                    'property_label' => $s->property?->address ?: ($s->property?->name ?: $s->property_interested),
                    'assigned_to' => $s->assignedTo?->only(['id', 'name']),
                    'stage_label' => AccommodationOnboardingStages::label($s),
                ];
            });

        return inertia('portal/accommodation/Applications', array_merge($shared, ['submissions' => $submissions]));
    }

    public function show(EoiSubmission $submission)
    {
        $submission->load(['property', 'assignedTo', 'convertedTenant']);
        // address/code for display; internet_passcode + house_code feed the
        // move-in welcome email preview (staff-only portal page).
        $submission->property?->makeVisible(['code', 'address', 'internet_passcode', 'house_code']);
        // Native Pre-Tenancy form link (replaces the Google Form) — drives the
        // stage email + the copy-link button on the detail page.
        $submission->setAttribute('pre_tenancy_form_url', $submission->preTenancyFormUrl());

        // The generated flat/house-sharing agreement built for this client in
        // Forms → Agreements, so the Agreement stage can source & show it.
        $agreement = \App\Models\AccommodationAgreement::where('eoi_submission_id', $submission->id)
            ->when(! (request()->user()->isAdmin() || request()->user()->hasRole('accommodation')), fn ($q) => $q->whereRaw('1 = 0'))
            ->latest()->first();

        return inertia('portal/accommodation/ApplicationDetails', [
            'submission' => $submission,
            'stageLabel' => AccommodationOnboardingStages::label($submission),
            'history' => $this->history($submission),
            'onboardingComposeUrl' => ! $submission->welcome_email_sent_at
                && isset(OnboardingEmailMilestones::TEMPLATE_BY_STATUS[$submission->status])
                && ViewingSchedule::staffCanManage(request()->user())
                ? route('portal.accommodation.compose', ['onboarding' => $submission->id], false) : null,
            'options' => $this->options(withRooms: true),
            'allowedTransitions' => array_values(array_diff(OnboardingPipeline::allowedFrom($submission->status), ['agreement_signed', 'invoice_sent'])),
            'agreement' => $agreement ? [
                'id' => $agreement->id,
                'type_label' => $agreement->typeLabel(),
                'status' => $agreement->status,
                'signed' => $agreement->status === \App\Models\AccommodationAgreement::STATUS_SIGNED,
                'signing_url' => $agreement->signingUrl(),
                'created_at' => optional($agreement->created_at)->toIso8601String(),
                'download_url' => route('portal.accommodation.forms.agreements.download', $agreement),
                'resend_url' => route('portal.accommodation.forms.agreements.resend', $agreement),
            ] : null,
        ]);
    }

    public function reviewRegistration(Request $request, EoiSubmission $submission, OnboardingTransitionService $service)
    {
        $decision = $request->validate(['decision' => 'required|in:reviewed,approved,not_approved'])['decision'];
        DB::transaction(function () use ($submission, $service, $decision) {
            $source = EoiSubmission::lockForUpdate()->findOrFail($submission->id);
            if (! in_array($source->status, ['new', 'reviewed', 'declined'], true)) {
                throw ValidationException::withMessages(['decision' => 'This registration has already moved forward.']);
            }

            $source->registration_reviewed_at ??= now();
            if ($source->status === 'new' || ($source->status === 'declined' && $decision === 'approved')) {
                $service->transition($source, 'reviewed');
            }
            if ($decision === 'approved') {
                $service->transition($source, 'shortlisted');
            } elseif ($decision === 'not_approved' && $source->status === 'reviewed') {
                $service->transition($source, 'declined');
            }
            if ($source->isDirty()) {
                $source->save();
            }
        }, 3);

        return back()->with('success', 'Registration review saved.');
    }

    public function updateStatus(UpdateStatusRequest $request, EoiSubmission $submission, OnboardingTransitionService $service)
    {
        if (in_array($request->input('status'), ['agreement_signed', 'invoice_sent'], true)) {
            throw ValidationException::withMessages(['status' => 'This stage advances when the agreement is fully signed or the issued invoice is emailed.']);
        }

        // Reaching moved_in creates a Tenant, which needs contract data — that
        // goes through the dedicated Convert to Tenant flow, not a bare status flip.
        if ($request->input('status') === 'moved_in') {
            return redirect()->back()->with('error', 'Use “Convert to Tenant” to complete move-in.');
        }

        // The pre-tenancy stage is completed by the tenant submitting the form
        // (which auto-advances the stage) — staff can't mark it done manually.
        if ($request->input('status') === 'pre_tenancy_form_completed' && empty($submission->pre_tenancy_form_data)) {
            return redirect()->back()->with('error', 'The tenant has not submitted their pre-tenancy form yet — this stage completes automatically once they do.');
        }

        DB::transaction(function () use ($request, $submission, $service) {
            $source = EoiSubmission::lockForUpdate()->findOrFail($submission->id);
            $data = $request->stageData();
            if ($request->input('status') === 'viewing_completed' && $source->viewing_scheduled_at?->isFuture()) {
                throw ValidationException::withMessages(['status' => 'The scheduled viewing has not started yet.']);
            }
            if ($request->input('status') === 'payment_confirmed') {
                $received = (int) round((float) $data['verified_move_in_amount_nzd'] * 100);
                $due = (int) round((float) $source->invoice_amount_nzd * 100);
                if ($due <= 0 || $received < $due) {
                    throw ValidationException::withMessages(['verified_move_in_amount_nzd' => 'Enter a verified amount that covers the full sent invoice.']);
                }
            } else {
                unset($data['verified_move_in_amount_nzd']);
            }
            $service->transition($source, $request->input('status'), $data);
        }, 3);

        // Actually send the staff-composed stage email (e.g. the Pre-Tenancy
        // form invite with its native link) to the applicant, if one was
        // supplied and we have an address to send to.
        $email = $request->emailPayload();
        if ($email && $submission->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($submission->email)
                    ->queue(new \App\Mail\OnboardingStageEmail($email['subject'], $email['body']));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Onboarding stage email failed', ['submission' => $submission->id, 'error' => $e->getMessage()]);

                return redirect()->back()->with('error', 'Stage updated, but the email could not be sent.');
            }

            return redirect()->back()->with('success', 'Stage updated and email sent.');
        }

        return redirect()->back()->with('success', 'Stage updated.');
    }

    public function assignTo(AssignRequest $request, EoiSubmission $submission)
    {
        DB::transaction(function () use ($request, $submission) {
            EoiSubmission::lockForUpdate()->findOrFail($submission->id)
                ->update(['assigned_to_user_id' => $request->validated('user_id')]);
        }, 3);

        // NOTE: assignee notification is deferred until the messaging system exists.

        return redirect()->back()->with('success', 'Assigned.');
    }

    public function linkToProperty(LinkPropertyRequest $request, EoiSubmission $submission)
    {
        $property = Property::findOrFail($request->input('property_id'));

        $submission->property_id = $property->id;
        // Backfill the legacy free-text field if it was empty.
        if (empty($submission->property_interested)) {
            $submission->property_interested = $property->address ?: $property->name;
        }
        $submission->save();

        return redirect()->back()->with('success', 'Linked to property.');
    }

    public function addInternalNote(AddNoteRequest $request, EoiSubmission $submission)
    {
        $stamp = '['.now()->toDateTimeString().'] '.$request->input('note');
        $submission->internal_notes = $submission->internal_notes
            ? trim($submission->internal_notes)."\n".$stamp
            : $stamp;
        $submission->save();

        return redirect()->back()->with('success', 'Note added.');
    }

    public function convertToTenant(ConvertToTenantRequest $request, EoiSubmission $submission)
    {
        $data = $request->validated();
        $version = $data['expected_tenant_version'] ?? null;
        unset($data['expected_tenant_version']);
        $tenant = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $submission, $data, $version) {
            // Both signing and move-in acquire the source first. Never decide to
            // create a tenant from the stale route-bound snapshot.
            $source = EoiSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (! in_array($source->status, ['payment_confirmed', 'moved_in'], true)) {
                return null;
            }
            $property = Property::where('is_active', true)->lockForUpdate()->findOrFail($data['property_id']);
            if (array_key_exists('assigned_room_ids', $data)) {
                if ($data['assigned_room_ids'] === [] && ! RoomInventory::tenantTrackingAvailable()) {
                    unset($data['assigned_room_ids']);
                } else {
                    RoomInventory::authorizeStaff($request);
                    RoomInventory::validateAssignment($property, $data['assigned_room_ids']);
                }
            }
            if ($source->converted_to_tenant_id) {
                $tenant = Tenant::whereKey($source->converted_to_tenant_id)->lockForUpdate()->firstOrFail();
                if ($tenant->current_status !== 'pending_payment') {
                    return null;
                }
                abort_unless($source->status === 'payment_confirmed', 422, 'Payment must be confirmed before move-in.');
                if (! is_string($version) || ! hash_equals($tenant->move_in_version, $version)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['expected_tenant_version' => 'This tenant changed after the form opened. Close and reopen the move-in form to review the latest payments and details.']);
                }
                abort_unless((int) $data['property_id'] === $tenant->property_id, 422, 'Use the property linked to the signed agreement.');
                $tenant->fill([...$data, 'current_status' => 'active'])->save();
            } else {
                $tenant = Tenant::create([
                    ...$data,
                    'email' => $data['email'] ?? $source->email,
                    'phone' => $data['phone'] ?? $source->mobile,
                    'nationality' => $source->nationality,
                    'current_status' => 'active',
                    'converted_from_viewer_id' => $source->id,
                    'notes' => $data['notes'] ?? "Converted from EOI submission #{$source->id}",
                ]);
            }
            $source->update([
                'converted_to_tenant_id' => $tenant->id, 'status' => 'moved_in',
                'move_in_date' => $source->move_in_date ?: $data['contract_start'],
            ]);

            return $tenant;
        });
        if (! $tenant) {
            return redirect()->back()->with('error', 'Conversion requires confirmed payment and an applicant who has not already moved in.');
        }

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Payment confirmed — the tenant is now active.');
    }

    public function destroy(EoiSubmission $submission)
    {
        if (! in_array($submission->status, OnboardingPipeline::TERMINALS, true)) {
            return redirect()->back()
                ->with('error', 'Only declined or not-proceeding applications can be deleted.');
        }

        $submission->delete(); // soft delete

        return redirect()->route('portal.accommodation.applications.index')
            ->with('success', 'Application removed.');
    }

    // ---- Helpers ----------------------------------------------------------

    private function filteredQuery(Request $request)
    {
        $search = $request->query('search');
        $status = $request->query('status');
        $formType = $request->query('form_type');
        $temperature = $request->query('lead_temperature');
        $assigned = $request->query('assigned_to');
        $propertyId = $request->query('property_id');
        $daysMin = $request->query('days_at_stage_min');
        $stage = $request->query('stage');

        $query = EoiSubmission::query()
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('full_legal_name', 'like', "%{$search}%")
                ->orWhere('preferred_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")))
            ->when(OnboardingPipeline::isValidStatus((string) $status), fn ($q) => $q->where('status', $status))
            ->when(is_string($stage) && isset(AccommodationOnboardingStages::STAGES[$stage]), fn ($q) => $stage === 'welcome_sent'
                ? $q->whereNotNull('welcome_email_sent_at')
                : $q->whereNull('welcome_email_sent_at')->whereIn('status', AccommodationOnboardingStages::STAGES[$stage][1]))
            ->when(in_array($formType, ['hot', 'cold'], true), fn ($q) => $q->where('form_type', $formType))
            ->when(in_array($temperature, ['hot', 'cold'], true), fn ($q) => $q->where('lead_temperature', $temperature))
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->when($assigned === 'me', fn ($q) => $q->where('assigned_to_user_id', $request->user()?->id))
            ->when($assigned === 'unassigned', fn ($q) => $q->whereNull('assigned_to_user_id'))
            ->when($assigned && ! in_array($assigned, ['me', 'unassigned'], true), fn ($q) => $q->where('assigned_to_user_id', $assigned))
            ->when($daysMin !== null && $daysMin !== '', fn ($q) => $q->where('updated_at', '<=', now()->subDays((int) $daysMin)));

        if ($request->query('active_pipeline') === '1') {
            $query->inActivePipeline();
        }

        return $this->applySort($query, $request->query('sort'));
    }

    private function applySort($query, ?string $sort)
    {
        if ($sort === 'days_at_stage') {
            return $query->orderBy('updated_at', 'asc'); // oldest update = most stalling
        }

        if ($sort === 'stage_order') {
            $cases = collect(OnboardingPipeline::STAGES)
                ->map(fn ($s, $i) => "WHEN '".$s."' THEN ".$i)
                ->implode(' ');

            return $query->orderByRaw("CASE status {$cases} ELSE 999 END");
        }

        return $query->latest();
    }

    private function options(bool $withRooms = false): array
    {
        return [
            'stages' => OnboardingPipeline::STAGES,
            'terminals' => OnboardingPipeline::TERMINALS,
            'statuses' => OnboardingPipeline::allStatuses(),
            'transitions' => OnboardingPipeline::TRANSITIONS,
            'auto_timestamp' => OnboardingPipeline::AUTO_TIMESTAMP,
            'properties' => $this->propertyOptions($withRooms),
            'team' => OnboardingOwners::options(),
            'contract_types' => Tenant::CONTRACT_TYPES,
        ];
    }

    private function propertyOptions(bool $withRooms): array
    {
        return Property::where('is_active', true)
            ->orderByRaw('code IS NULL, CAST(code AS UNSIGNED)')
            ->get()
            ->map(fn (Property $p) => ['id' => $p->id, 'code' => $p->code, 'address' => $p->address ?: $p->name]
                + ($withRooms ? ['rooms' => $p->room_register ?? []] : []))
            ->all();
    }

    private function history(EoiSubmission $submission): array
    {
        $events = ActivityLog::where('action', 'eoi_submission.updated')->where('properties->subject_id', $submission->id)
            ->latest('id')->get(['id', 'actor_name', 'created_at', 'properties'])
            ->filter(fn (ActivityLog $log) => isset($log->properties['changes']['assigned_to_user_id']) || isset($log->properties['changes']['status']));
        $owners = User::whereIn('id', $events->flatMap(fn (ActivityLog $log) => array_values($log->properties['changes']['assigned_to_user_id'] ?? []))
            ->filter()->unique())->pluck('name', 'id');

        return $events->map(function (ActivityLog $log) use ($owners) {
            $changes = $log->properties['changes'];
            $assignment = $changes['assigned_to_user_id'] ?? null;
            $name = fn ($id) => $id ? ($owners[$id] ?? "Staff #{$id}") : 'Unassigned';

            return [
                'id' => $log->id, 'actor' => $log->actor_name, 'at' => $log->created_at,
                'assignment' => $assignment ? ['old' => $name($assignment['old']), 'new' => $name($assignment['new'])] : null,
                'stage' => $changes['status'] ?? null,
            ];
        })->values()->all();
    }
}
