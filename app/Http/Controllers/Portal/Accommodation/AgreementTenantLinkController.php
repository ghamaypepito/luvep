<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationAgreement;
use App\Models\ActivityLog;
use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Deliberate historical reconciliation. Never changes issued terms or signs a document. */
class AgreementTenantLinkController extends Controller
{
    private function authorizeReview(Request $request): void
    {
        abort_unless($request->user()->is_active && ($request->user()->isAdmin() || $request->user()->hasRole('accommodation'))
            && ! $request->attributes->get('user_view_actor'), 403);
    }

    private function eligible(AccommodationAgreement $agreement): void
    {
        abort_unless($agreement->status === 'signed' && $agreement->signed_at && $agreement->signed_pdf_path
            && Storage::disk('local')->exists($agreement->signed_pdf_path), 422, 'A stored signed agreement must be reviewed before linking.');
        abort_unless((bool) $agreement->eoi_submission_id !== (bool) $agreement->pre_tenancy_form_id, 422, 'The agreement needs one unambiguous source record.');
    }

    private function requireReview(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['agreement' => $message]);
        }
    }

    private function version(AccommodationAgreement $agreement, $source): string
    {
        return hash('sha256', json_encode([$agreement->getRawOriginal(), $source->getRawOriginal()], JSON_THROW_ON_ERROR));
    }

    private function source(AccommodationAgreement $agreement, bool $lock = false)
    {
        $query = $agreement->eoi_submission_id ? EoiSubmission::query() : PreTenancyForm::query();

        return $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($agreement->eoi_submission_id ?? $agreement->pre_tenancy_form_id);
    }

    public function show(Request $request, AccommodationAgreement $agreement)
    {
        $this->authorizeReview($request);
        $this->eligible($agreement);
        $source = $this->source($agreement);

        return inertia('portal/accommodation/forms/AgreementTenantLink', [
            'agreement' => ['id' => $agreement->id, 'name' => $agreement->client_name, 'signed_at' => $agreement->signed_at->toIso8601String(),
                'property_id' => $agreement->property_id, 'tenant_id' => $agreement->tenant_id,
                'property_address' => $agreement->property_address, 'terms' => $agreement->data['tenancy'] ?? []],
            'source' => ['id' => $source->id, 'name' => $source->full_legal_name,
                'url' => $agreement->eoi_submission_id ? route('portal.accommodation.applications.show', $source) : route('portal.accommodation.forms.pre-tenancy.show', $source)],
            'version' => $this->version($agreement, $source),
            'properties' => Property::where('is_active', true)->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->address ?: $p->name]),
            'tenants' => Tenant::with('property')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->display_name,
                'version' => $t->move_in_version, 'property_id' => $t->property_id, 'property' => $t->property?->name, 'status' => $t->current_status, 'unit' => $t->unit]),
        ]);
    }

    public function store(Request $request, AccommodationAgreement $agreement)
    {
        $this->authorizeReview($request);
        $data = $request->validate([
            'version' => 'required|string|size:64', 'property_id' => 'required|integer',
            'tenant_version' => 'required_if:mode,existing|nullable|string|size:64',
            'mode' => 'required|in:existing,create', 'tenant_id' => 'required_if:mode,existing|nullable|integer',
            'confirmed' => 'required|accepted', 'reason' => 'required|string|max:1000',
        ]);
        $tenant = DB::transaction(function () use ($agreement, $data) {
            // Same source-first lock order as signing and paid onboarding.
            $source = $this->source($agreement, true);
            $current = AccommodationAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $this->eligible($current);
            if (! hash_equals($this->version($current, $source), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The agreement or source changed. Reload and review again.']);
            }
            $property = Property::whereKey($data['property_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->requireReview(! $current->property_id || $current->property_id === $property->id, 'Keep the property already linked to this agreement.');
            $linkedIds = AccommodationAgreement::where('status', 'signed')->whereNotNull('tenant_id')
                ->when($current->eoi_submission_id, fn ($q) => $q->where('eoi_submission_id', $source->id),
                    fn ($q) => $q->where('pre_tenancy_form_id', $source->id))
                ->lockForUpdate()->pluck('tenant_id')->all();
            if ($source instanceof EoiSubmission && $source->converted_to_tenant_id) {
                $linkedIds[] = $source->converted_to_tenant_id;
            }
            $linkedIds = array_values(array_unique($linkedIds));
            $this->requireReview(count($linkedIds) <= 1, 'Conflicting existing source links require review; no records were changed.');
            $targetId = $data['mode'] === 'existing' ? $data['tenant_id'] : null;
            $this->requireReview(! $linkedIds || ($targetId && (int) $targetId === (int) $linkedIds[0]), 'Select the tenant already linked to this source. Do not create a duplicate.');
            if ($targetId) {
                $tenant = Tenant::whereKey($targetId)->lockForUpdate()->firstOrFail();
                if (! hash_equals($tenant->move_in_version, $data['tenant_version'])) {
                    throw ValidationException::withMessages(['tenant_id' => 'The tenant changed. Reload and review again.']);
                }
                $this->requireReview($tenant->property_id === $property->id, 'The selected tenant belongs to a different property.');
                // Preserve verified identity, occupancy, payments and historical status.
            } else {
                $person = $current->data['subtenants'][0] ?? [];
                $terms = $current->data['tenancy'] ?? [];
                $fields = Validator::make([
                    'first_name' => $person['first_name'] ?? null, 'family_name' => $person['last_name'] ?? '',
                    'contract_start' => $terms['commence_date'] ?? null, 'contract_end' => $terms['end_date'] ?? null,
                    'weekly_rent_nzd' => $terms['weekly_rent'] ?? null,
                    'email' => $person['email'] ?? $source->email, 'phone' => $person['mobile'] ?? $source->mobile,
                    'weekly_utilities_nzd' => $current->agreement_type !== 'transient_flatmate' && ($terms['utility_option'] ?? '') === '1' ? ($terms['utility_amount'] ?? null) : null,
                ], ['first_name' => 'required|string|max:100', 'family_name' => 'nullable|string|max:100',
                    'contract_start' => 'required|date_format:Y-m-d', 'contract_end' => 'required|date_format:Y-m-d|after_or_equal:contract_start',
                    'email' => 'nullable|email|max:200', 'phone' => 'nullable|string|max:50',
                    'weekly_utilities_nzd' => 'nullable|numeric|min:0|max:9999999.99|decimal:0,2',
                    'weekly_rent_nzd' => 'required|numeric|min:0|max:9999999.99|decimal:0,2'])->validate();
                $tenant = Tenant::create($fields + [
                    'property_id' => $property->id, 'contract_type' => 'fixed_term',
                    'current_status' => 'pending_payment',
                    'converted_from_viewer_id' => $source instanceof EoiSubmission ? $source->id : null,
                    'notes' => 'Reconciled from signed agreement #'.$current->id.'. Payment and move-in require separate review.',
                ]);
            }
            $before = ['tenant_id' => $current->tenant_id, 'property_id' => $current->property_id];
            $current->update(['tenant_id' => $tenant->id, 'property_id' => $property->id]);
            if ($source instanceof EoiSubmission && ! $source->converted_to_tenant_id) {
                $source->update(['converted_to_tenant_id' => $tenant->id]);
            }
            ActivityLog::record('accommodation.agreement.tenant_linked', [
                'portal' => 'accommodation', 'entity_type' => AccommodationAgreement::class, 'entity_id' => $current->id,
                'properties' => ['before' => $before, 'tenant_id' => $tenant->id, 'mode' => $data['mode'], 'reason' => $data['reason']],
            ]);

            return $tenant;
        });

        return redirect()->route('portal.accommodation.tenants.show', $tenant)->with('success', 'Signed agreement linked. Payment, move-in and existing documents were not changed.');
    }
}
