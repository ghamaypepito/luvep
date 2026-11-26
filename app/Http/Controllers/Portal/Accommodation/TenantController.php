<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveTenantRequest;
use App\Http\Requests\RenewTenantRequest;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Accommodation\RoomInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $tenants = $this->filteredQuery($request)
            ->with('property')
            ->paginate(20)
            ->withQueryString()
            // Property hides code/address by default ($hidden); surface them
            // for the (portal-only) tenant list.
            ->through(function (Tenant $t) {
                $t->property?->makeVisible(['code', 'address']);

                return $t;
            });

        return inertia('portal/accommodation/Tenants', [
            'tenants' => $tenants,
            'filters' => $request->only(['search', 'property_id', 'status', 'missing_docs', 'has_email', 'bond_paid', 'sort', 'archived']),
            'options' => [
                'properties' => $this->propertyOptions(),
                'statuses' => Tenant::STATUSES,
            ],
        ]);
    }

    public function show(Tenant $tenant)
    {
        \App\Models\TenantDocument::authorizeStaff(request()->user());
        $tenant->load('property');
        $tenant->property?->makeVisible(['code', 'address']);

        $historical = Tenant::where('property_id', $tenant->property_id)
            ->where('id', '!=', $tenant->id)
            ->where('current_status', 'vacated')
            ->orderByDesc('ended_at')
            ->get();

        return inertia('portal/accommodation/TenantDetail', [
            'tenant' => $tenant,
            'canActivate' => $tenant->current_status === 'pending_payment' && ! $this->hasOnboardingSource($tenant)
                && $this->standaloneAgreements($tenant)->exists(),
            'onboardingUrl' => ($source = EoiSubmission::where('converted_to_tenant_id', $tenant->id)->first(['id']))
                ? route('portal.accommodation.applications.show', $source) : null,
            'documents' => $tenant->documents()->latest()->get(['id', 'tenant_id', 'name', 'category', 'size', 'created_at']),
            'preTenancySources' => AccommodationAgreement::where('tenant_id', $tenant->id)->where('status', 'signed')->get()
                ->map(fn ($a) => $a->eoi_submission_id
                    ? ['key' => 'onboarding-'.$a->eoi_submission_id, 'url' => route('portal.accommodation.applications.show', $a->eoi_submission_id), 'label' => 'Completed pre-tenancy / onboarding #'.$a->eoi_submission_id]
                    : ($a->pre_tenancy_form_id ? ['key' => 'general-'.$a->pre_tenancy_form_id, 'url' => route('portal.accommodation.forms.pre-tenancy.show', $a->pre_tenancy_form_id), 'label' => 'Completed pre-tenancy #'.$a->pre_tenancy_form_id] : null))
                ->filter()->unique('key')->values(),
            'signedAgreements' => \App\Models\AccommodationAgreement::where('tenant_id', $tenant->id)
                ->where('status', 'signed')->latest('signed_at')->get(['id', 'agreement_type', 'signed_at']),
            'historical' => $historical,
            'properties' => $this->propertyOptions(withRooms: true), // for the "move" modal
        ]);
    }

    public function create(Request $request)
    {
        return inertia('portal/accommodation/TenantForm', [
            'tenant' => null,
            'property_id' => $request->query('property_id'),
            'properties' => $this->propertyOptions(withRooms: true),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreTenantRequest $request)
    {
        $data = $this->cleanData($request, true);
        unset($data['room_assignment_version']);
        $tenant = DB::transaction(function () use ($request, $data) {
            $property = Property::where('is_active', true)->lockForUpdate()->findOrFail($data['property_id']);
            if (! empty($data['assigned_room_ids'])) {
                RoomInventory::authorizeStaff($request);
                RoomInventory::validateAssignment($property, $data['assigned_room_ids']);
            }

            return Tenant::create($data);
        });

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Tenant created successfully.');
    }

    public function edit(Tenant $tenant)
    {
        return inertia('portal/accommodation/TenantForm', [
            'tenant' => $tenant,
            'property_id' => null,
            'properties' => $this->propertyOptions(withRooms: true),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $data = $this->cleanData($request, false);
        DB::transaction(function () use ($request, $tenant, $data) {
            $property = Property::where('is_active', true)->lockForUpdate()->findOrFail($data['property_id']);
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (($current->current_status === 'pending_payment' || ($data['current_status'] ?? null) === 'pending_payment')
                && array_key_exists('current_status', $data) && $data['current_status'] !== $current->current_status) {
                throw ValidationException::withMessages(['current_status' => 'Use the payment and move-in review to activate a signed tenant.']);
            }
            if ($current->current_status === 'pending_payment' && (int) $data['property_id'] !== $current->property_id) {
                throw ValidationException::withMessages(['property_id' => 'Keep the property on the signed agreement until payment and move-in are reviewed.']);
            }
            $previousRooms = $current->assigned_room_ids ?? [];
            $rooms = $data['assigned_room_ids'] ?? ($property->id === $current->property_id ? $previousRooms : []);
            if ($rooms !== $previousRooms || ($rooms && $property->id !== $current->property_id)) {
                RoomInventory::authorizeStaff($request);
                if (! hash_equals($current->move_in_version, $data['room_assignment_version'] ?? '')) {
                    throw ValidationException::withMessages(['assigned_room_ids' => 'The tenancy changed. Reload before updating its rooms.']);
                }
                RoomInventory::validateAssignment($property, $rooms);
                $data['assigned_room_ids'] = $rooms;
            } else {
                unset($data['assigned_room_ids']);
            }
            unset($data['room_assignment_version']);
            $current->update($data);
        });

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Tenant updated successfully.');
    }

    public function activate(Request $request, Tenant $tenant)
    {
        \App\Models\TenantDocument::authorizeStaff($request->user());
        $data = $request->validate([
            'payment_confirmed' => 'required|accepted',
            'assigned_room_ids' => 'sometimes|array|list|max:50',
            'assigned_room_ids.*' => 'required|uuid|distinct',
            'room_assignment_version' => 'sometimes|nullable|string|size:64',
        ]);
        $agreement = $this->standaloneAgreements($tenant)->first();
        abort_unless($agreement, 422, 'Use the onboarding payment-confirmed move-in flow for this tenant.');
        DB::transaction(function () use ($agreement, $tenant, $request, $data) {
            // Match signing's source -> agreement -> property -> tenant lock order.
            PreTenancyForm::whereKey($agreement->pre_tenancy_form_id)->lockForUpdate()->firstOrFail();
            $signed = AccommodationAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $property = Property::whereKey($signed->property_id)->lockForUpdate()->firstOrFail();
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($signed->status === 'signed' && $signed->tenant_id === $current->id
                && $current->current_status === 'pending_payment' && ! $this->hasOnboardingSource($current)
                && $property->is_active && $current->property_id === $property->id, 422, 'This tenant is not eligible for standalone payment review.');
            if (array_key_exists('assigned_room_ids', $data) && $data['assigned_room_ids'] !== ($current->assigned_room_ids ?? [])) {
                RoomInventory::authorizeStaff($request);
                if (! hash_equals($current->move_in_version, $data['room_assignment_version'] ?? '')) {
                    throw ValidationException::withMessages(['assigned_room_ids' => 'The tenancy changed. Reload before updating its rooms.']);
                }
                RoomInventory::validateAssignment($property, $data['assigned_room_ids']);
                $current->assigned_room_ids = $data['assigned_room_ids'];
            }
            $current->update([
                'current_status' => 'active',
                'notes' => $this->appendNote($current->notes, 'Payment and move-in reviewed by '.$request->user()->name.' at '.now()->toIso8601String()),
            ]);
        });

        return back()->with('success', 'Payment reviewed — the existing tenant is now active.');
    }

    private function hasOnboardingSource(Tenant $tenant): bool
    {
        return $tenant->converted_from_viewer_id !== null
            || EoiSubmission::withTrashed()->where('converted_to_tenant_id', $tenant->id)->exists();
    }

    private function standaloneAgreements(Tenant $tenant)
    {
        return AccommodationAgreement::where('tenant_id', $tenant->id)->where('status', 'signed')
            ->where(fn ($q) => $q->where('data->workflow_version', 2)->orWhereExists(fn ($audit) => $audit
                ->selectRaw('1')->from('activity_logs')
                ->whereColumn('activity_logs.entity_id', 'accommodation_agreements.id')
                ->where('activity_logs.entity_type', AccommodationAgreement::class)
                ->where('activity_logs.action', 'accommodation.agreement.tenant_linked')))
            ->whereNotNull('pre_tenancy_form_id')->whereNull('eoi_submission_id')->latest('id');
    }

    public function destroy(Tenant $tenant)
    {
        if ($tenant->current_status !== 'vacated') {
            return redirect()->back()
                ->with('error', 'Only vacated tenants can be deleted. Mark the tenant as vacated first.');
        }

        $tenant->delete();

        return redirect()->route('portal.accommodation.tenants.index')
            ->with('success', 'Tenant deleted.');
    }

    /** Archive a tenant (soft delete) — hides them from the list but keeps the record. */
    public function archive(Tenant $tenant)
    {
        $tenant->delete();

        return redirect()->back()->with('success', 'Tenant archived.');
    }

    /** Restore a previously archived (soft-deleted) tenant. */
    public function restore(Tenant $tenant)
    {
        $tenant->restore();

        return redirect()->back()->with('success', 'Tenant restored.');
    }

    // ---- Custom lifecycle actions ----------------------------------------

    public function markNoticeGiven(Tenant $tenant, Request $request)
    {
        abort_if($tenant->current_status === 'pending_payment', 422, 'Complete payment and move-in review before using resident actions.');
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        $tenant->update([
            'current_status' => 'notice_given',
            'notes' => $this->appendNote($tenant->notes, 'Notice given: '.$data['reason']),
        ]);

        return redirect()->back()->with('success', 'Tenant marked as notice given.');
    }

    public function markVacated(Tenant $tenant, Request $request)
    {
        abort_if($tenant->current_status === 'pending_payment', 422, 'Complete payment and move-in review before using resident actions.');
        $data = $request->validate([
            'vacate_date' => 'required|date|before_or_equal:'.now('Pacific/Auckland')->toDateString(),
            'reason' => 'nullable|string|max:1000',
        ]);

        $note = 'Vacated on '.Carbon::parse($data['vacate_date'])->toDateString();
        if (! empty($data['reason'])) {
            $note .= ' — '.$data['reason'];
        }

        // ended_at is intentionally non-fillable — set it directly.
        $tenant->current_status = 'vacated';
        $tenant->ended_at = now();
        $tenant->notes = $this->appendNote($tenant->notes, $note);
        $tenant->save();

        return redirect()->back()->with('success', 'Tenant marked as vacated.');
    }

    public function markRenewed(Tenant $tenant, RenewTenantRequest $request)
    {
        abort_if($tenant->current_status === 'pending_payment', 422, 'Complete payment and move-in review before using resident actions.');
        $data = $request->validated();

        $tenant->update([
            'contract_start' => $data['new_contract_start'],
            'contract_end' => $data['new_contract_end'],
            'current_status' => $tenant->current_status === 'notice_given' ? 'active' : $tenant->current_status,
            'notes' => $this->appendNote(
                $tenant->notes,
                'Renewed: '.$data['new_contract_start'].' → '.$data['new_contract_end']
            ),
        ]);

        return redirect()->back()->with('success', 'Tenancy renewed.');
    }

    public function moveToProperty(Tenant $tenant, MoveTenantRequest $request)
    {
        $data = $request->validated();
        $newTenant = DB::transaction(function () use ($tenant, $data, $request) {
            $property = Property::where('is_active', true)->lockForUpdate()->findOrFail($data['new_property_id']);
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($current->current_status, Tenant::ACTIVE_STATUSES, true) && $property->id !== $current->property_id, 422, 'Only a current resident can move to a different property.');
            if (! empty($data['assigned_room_ids'])) {
                RoomInventory::authorizeStaff($request);
                RoomInventory::validateAssignment($property, $data['assigned_room_ids']);
            }

            $current->moved_to_property_id = $property->id;
            $current->current_status = 'vacated';
            $current->ended_at = Carbon::parse($data['move_date']);
            $current->notes = $this->appendNote($current->notes, 'Moved to property #'.$property->id.' on '.$data['move_date']);
            $current->save();

            return Tenant::create([
                ...$current->only(['first_name', 'family_name', 'display_name_override', 'email', 'phone', 'whatsapp', 'nationality', 'date_of_birth', 'passport_number']),
                'property_id' => $property->id,
                'assigned_room_ids' => $data['assigned_room_ids'] ?? null,
                'contract_type' => 'not_yet_defined',
                'current_status' => 'active',
                'notes' => $this->appendNote(null, 'Moved from property #'.$current->property_id.' on '.$data['move_date']),
            ]);
        });

        return redirect()->route('portal.accommodation.tenants.show', $newTenant)
            ->with('success', 'Tenant moved — a new tenancy was created at the destination property.');
    }

    // ---- Export -----------------------------------------------------------

    public function export(Request $request)
    {
        $columns = [
            'property_address', 'unit', 'display_name', 'email', 'phone',
            'contract_start', 'contract_end', 'days_to_end', 'contract_status',
            'weekly_rent', 'weekly_utilities', 'bond_paid', 'current_status', 'notes',
        ];

        $query = $this->filteredQuery($request)->with('property');

        return Response::streamDownload(function () use ($columns, $query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            $query->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $t) {
                    fputcsv($out, [
                        $t->property?->address ?? $t->property?->name,
                        $t->unit,
                        $t->display_name,
                        $t->email,
                        $t->phone,
                        optional($t->contract_start)->toDateString(),
                        optional($t->contract_end)->toDateString(),
                        $t->days_to_end,
                        $t->contract_status,
                        $t->weekly_rent_nzd,
                        $t->weekly_utilities_nzd,
                        $t->bond_paid_nzd,
                        $t->current_status,
                        $t->notes,
                    ]);
                }
            });

            fclose($out);
        }, 'tenants.csv', ['Content-Type' => 'text/csv']);
    }

    // ---- Helpers ----------------------------------------------------------

    /** Apply list filters + sort. Shared by index() and export(). */
    private function filteredQuery(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $propertyId = $request->query('property_id');
        $status = $request->query('status');
        $sort = $request->query('sort', 'days_to_end');

        $query = ($request->boolean('archived') ? Tenant::onlyTrashed() : Tenant::query())
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('family_name', 'like', "%{$search}%")
                ->orWhere('display_name_override', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere(function ($names) use ($search) {
                    foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $names->where(fn ($part) => $part->where('first_name', 'like', "%{$word}%")->orWhere('family_name', 'like', "%{$word}%"));
                    }
                })
                ->orWhereIn('id', AccommodationAgreement::select('tenant_id')->where('status', 'signed')->whereNotNull('tenant_id')->where('client_name', 'like', "%{$search}%"))))
            ->when($propertyId, fn ($q) => $q->whereIn('property_id', (array) $propertyId))
            ->when($status, function ($q) use ($status) {
                if (in_array($status, Tenant::STATUSES, true)) {
                    $q->where('current_status', $status);
                } elseif ($status === 'active_group') {
                    $q->active();
                } elseif ($status === 'ending_soon') {
                    $q->endingSoon();
                } elseif (in_array($status, ['overdue', 'ended'], true)) {
                    $q->overdue();
                }
            })
            ->when($request->boolean('missing_docs'), fn ($q) => $q->withMissingDocs())
            ->when($request->boolean('has_email'), fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->when($request->boolean('bond_paid'), fn ($q) => $q->where('bond_paid_nzd', '>', 0));

        return $this->applySort($query, $sort);
    }

    private function applySort($query, string $sort)
    {
        return match ($sort) {
            'name' => $query->orderBy('family_name')->orderBy('first_name'),
            'property' => $query->select('accommodation_tenants.*')
                ->leftJoin('accommodation_properties', 'accommodation_properties.id', '=', 'accommodation_tenants.property_id')
                ->orderByRaw('accommodation_properties.code IS NULL, CAST(accommodation_properties.code AS UNSIGNED)'),
            // days_to_end / contract_end — soonest end first, no-end-date last.
            default => $query->orderByRaw('contract_end IS NULL, contract_end ASC'),
        };
    }

    /** Validated data with checkbox booleans + create-time status default. */
    private function cleanData(StoreTenantRequest $request, bool $creating): array
    {
        $data = $request->validated();

        $data['has_passport_in_drive'] = $request->boolean('has_passport_in_drive');
        $data['has_tenancy_agreement_in_drive'] = $request->boolean('has_tenancy_agreement_in_drive');
        $data['has_inspection_report_in_drive'] = $request->boolean('has_inspection_report_in_drive');

        if ($creating && empty($data['current_status'])) {
            $data['current_status'] = 'active';
        }

        return $data;
    }

    /** Active properties for filter + assignment dropdowns (bypasses $hidden). */
    private function propertyOptions(bool $withRooms = false): array
    {
        return Property::where('is_active', true)
            ->orderByRaw('code IS NULL, CAST(code AS UNSIGNED)')
            ->get()
            ->map(fn (Property $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'address' => $p->address ?: $p->name,
            ] + ($withRooms ? ['rooms' => $p->room_register ?? []] : []))
            ->all();
    }

    private function formOptions(): array
    {
        return [
            'contract_types' => Tenant::CONTRACT_TYPES,
            'statuses' => Tenant::STATUSES,
        ];
    }

    private function appendNote(?string $existing, string $line): string
    {
        $stamp = '['.now()->toDateString().'] '.$line;

        return $existing ? trim($existing)."\n".$stamp : $stamp;
    }
}
