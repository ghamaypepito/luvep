<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveTenantRequest;
use App\Http\Requests\RenewTenantRequest;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response;

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
            'documents' => $tenant->documents()->latest()->get(['id', 'tenant_id', 'name', 'category', 'size', 'created_at']),
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
        $tenant->update($this->cleanData($request, false));

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Tenant updated successfully.');
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
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        $tenant->update([
            'current_status' => 'notice_given',
            'notes' => $this->appendNote($tenant->notes, 'Notice given: '.$data['reason']),
        ]);

        return redirect()->back()->with('success', 'Tenant marked as notice given.');
    }

    public function markVacated(Tenant $tenant, Request $request)
    {
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
