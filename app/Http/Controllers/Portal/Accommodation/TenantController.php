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
            'signedAgreements' => \App\Models\AccommodationAgreement::where('tenant_id', $tenant->id)
                ->where('status', 'signed')->latest('signed_at')->get(['id', 'agreement_type', 'signed_at']),
            'historical' => $historical,
            'properties' => $this->propertyOptions(), // for the "move" modal
        ]);
    }

    public function create(Request $request)
    {
        return inertia('portal/accommodation/TenantForm', [
            'tenant' => null,
            'property_id' => $request->query('property_id'),
            'properties' => $this->propertyOptions(),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreTenantRequest $request)
    {
        $tenant = Tenant::create($this->cleanData($request, true));

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Tenant created successfully.');
    }

    public function edit(Tenant $tenant)
    {
        return inertia('portal/accommodation/TenantForm', [
            'tenant' => $tenant,
            'property_id' => null,
            'properties' => $this->propertyOptions(),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $data = $this->cleanData($request, false);
        DB::transaction(function () use ($tenant, $data) {
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (($current->current_status === 'pending_payment' || ($data['current_status'] ?? null) === 'pending_payment')
                && array_key_exists('current_status', $data) && $data['current_status'] !== $current->current_status) {
                throw ValidationException::withMessages(['current_status' => 'Use the payment and move-in review to activate a signed tenant.']);
            }
            if ($current->current_status === 'pending_payment' && (int) $data['property_id'] !== $current->property_id) {
                throw ValidationException::withMessages(['property_id' => 'Keep the property on the signed agreement until payment and move-in are reviewed.']);
            }
            $current->update($data);
        });

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Tenant updated successfully.');
    }

    public function activate(Request $request, Tenant $tenant)
    {
        \App\Models\TenantDocument::authorizeStaff($request->user());
        $request->validate(['payment_confirmed' => ['required', 'accepted']]);
        $agreement = $this->standaloneAgreements($tenant)->first();
        abort_unless($agreement, 422, 'Use the onboarding payment-confirmed move-in flow for this tenant.');
        DB::transaction(function () use ($agreement, $tenant, $request) {
            // Match signing's source -> agreement -> property -> tenant lock order.
            PreTenancyForm::whereKey($agreement->pre_tenancy_form_id)->lockForUpdate()->firstOrFail();
            $signed = AccommodationAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $property = Property::whereKey($signed->property_id)->lockForUpdate()->firstOrFail();
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($signed->status === 'signed' && $signed->tenant_id === $current->id
                && $current->current_status === 'pending_payment' && ! $this->hasOnboardingSource($current)
                && $property->is_active && $current->property_id === $property->id, 422, 'This tenant is not eligible for standalone payment review.');
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
            ->where('data->workflow_version', 2)
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
            'vacate_date' => 'required|date',
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
        abort_if($tenant->current_status === 'pending_payment', 422, 'Complete payment and move-in review before using resident actions.');
        $data = $request->validated();

        // Close out the current tenancy. ended_at is non-fillable — set directly.
        $tenant->moved_to_property_id = $data['new_property_id'];
        $tenant->current_status = 'vacated';
        $tenant->ended_at = Carbon::parse($data['move_date']);
        $tenant->notes = $this->appendNote($tenant->notes, 'Moved to property #'.$data['new_property_id'].' on '.$data['move_date']);
        $tenant->save();

        // Open a fresh tenancy at the new property, carrying personal details
        // but starting with blank contract dates/financials.
        $newTenant = Tenant::create([
            'property_id' => $data['new_property_id'],
            'first_name' => $tenant->first_name,
            'family_name' => $tenant->family_name,
            'display_name_override' => $tenant->display_name_override,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'whatsapp' => $tenant->whatsapp,
            'nationality' => $tenant->nationality,
            'date_of_birth' => $tenant->date_of_birth,
            'passport_number' => $tenant->passport_number,
            'contract_type' => 'not_yet_defined',
            'current_status' => 'active',
            'notes' => $this->appendNote(null, 'Moved from property #'.$tenant->property_id.' on '.$data['move_date']),
        ]);

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
        $search = $request->query('search');
        $propertyId = $request->query('property_id');
        $status = $request->query('status');
        $sort = $request->query('sort', 'days_to_end');

        $query = ($request->boolean('archived') ? Tenant::onlyTrashed() : Tenant::query())
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('family_name', 'like', "%{$search}%")
                ->orWhere('display_name_override', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
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
    private function propertyOptions(): array
    {
        return Property::where('is_active', true)
            ->orderByRaw('code IS NULL, CAST(code AS UNSIGNED)')
            ->get()
            ->map(fn (Property $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'address' => $p->address ?: $p->name,
            ])
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
