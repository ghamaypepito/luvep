<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AddNoteRequest;
use App\Http\Requests\Onboarding\AssignRequest;
use App\Http\Requests\Onboarding\ConvertToTenantRequest;
use App\Http\Requests\Onboarding\LinkPropertyRequest;
use App\Http\Requests\Onboarding\UpdateStatusRequest;
use App\Models\EoiSubmission;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accommodation\RoomInventory;
use App\Services\OnboardingTransitionService;
use App\Support\OnboardingPipeline;
use Illuminate\Http\Request;

class EoiSubmissionController extends Controller
{
    /** Kept for backwards compatibility; the pipeline is the source of truth now. */
    public const STATUSES = ['new', 'reviewed', 'shortlisted', 'declined'];

    public function index(Request $request)
    {
        $view = $request->query('view') === 'pipeline' ? 'pipeline' : 'table';

        $shared = [
            'view' => $view,
            'filters' => $request->only([
                'search', 'status', 'form_type', 'lead_temperature',
                'assigned_to', 'property_id', 'active_pipeline', 'days_at_stage_min', 'sort',
            ]),
            'options' => $this->options(),
        ];

        if ($view === 'pipeline') {
            // Whole board (incl. terminal states), capped — grouped client-side.
            $board = $this->filteredQuery($request, forBoard: true)
                ->with(['property', 'assignedTo', 'convertedTenant'])
                ->limit(500)
                ->get()
                ->each(fn (EoiSubmission $s) => $s->property?->makeVisible(['code', 'address']));

            return inertia('portal/accommodation/Applications', array_merge($shared, ['board' => $board]));
        }

        $submissions = $this->filteredQuery($request)
            ->with(['property', 'assignedTo', 'convertedTenant'])
            ->paginate(15)
            ->withQueryString()
            ->through(function (EoiSubmission $s) {
                $s->property?->makeVisible(['code', 'address']);

                return $s;
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
            'options' => $this->options(withRooms: true),
            'allowedTransitions' => OnboardingPipeline::allowedFrom($submission->status),
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

    public function updateStatus(UpdateStatusRequest $request, EoiSubmission $submission, OnboardingTransitionService $service)
    {
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

        $service->transition($submission, $request->input('status'), $request->stageData());

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
        $submission->update(['assigned_to_user_id' => $request->input('user_id')]);

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
                RoomInventory::authorizeStaff($request);
                RoomInventory::validateAssignment($property, $data['assigned_room_ids']);
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

    private function filteredQuery(Request $request, bool $forBoard = false)
    {
        $search = $request->query('search');
        $status = $request->query('status');
        $formType = $request->query('form_type');
        $temperature = $request->query('lead_temperature');
        $assigned = $request->query('assigned_to');
        $propertyId = $request->query('property_id');
        $daysMin = $request->query('days_at_stage_min');

        $query = EoiSubmission::query()
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('full_legal_name', 'like', "%{$search}%")
                ->orWhere('preferred_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")))
            ->when(OnboardingPipeline::isValidStatus((string) $status), fn ($q) => $q->where('status', $status))
            ->when(in_array($formType, ['hot', 'cold'], true), fn ($q) => $q->where('form_type', $formType))
            ->when(in_array($temperature, ['hot', 'cold'], true), fn ($q) => $q->where('lead_temperature', $temperature))
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->when($assigned === 'me', fn ($q) => $q->where('assigned_to_user_id', $request->user()?->id))
            ->when($assigned === 'unassigned', fn ($q) => $q->whereNull('assigned_to_user_id'))
            ->when($assigned && ! in_array($assigned, ['me', 'unassigned'], true), fn ($q) => $q->where('assigned_to_user_id', $assigned))
            ->when($daysMin !== null && $daysMin !== '', fn ($q) => $q->where('updated_at', '<=', now()->subDays((int) $daysMin)));

        // Table view defaults to active-pipeline-only; the board shows everything.
        if (! $forBoard && $request->query('active_pipeline', '1') !== '0') {
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
            'team' => $this->teamOptions(),
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

    private function teamOptions(): array
    {
        return User::whereIn('role', ['accommodation', 'admin'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }
}
