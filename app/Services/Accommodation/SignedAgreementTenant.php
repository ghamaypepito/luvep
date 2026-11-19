<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use App\Models\Tenant;

class SignedAgreementTenant
{
    /** Called in the signing transaction; source lock serializes separate agreements for one applicant. */
    public function link(AccommodationAgreement $agreement): void
    {
        if (($agreement->data['workflow_version'] ?? null) !== 2) {
            return; // Historical issued documents and legacy signing retain their original behavior.
        }
        $source = $agreement->eoi_submission_id
            ? EoiSubmission::whereKey($agreement->eoi_submission_id)->lockForUpdate()->firstOrFail()
            : PreTenancyForm::whereKey($agreement->pre_tenancy_form_id)->lockForUpdate()->firstOrFail();
        abort_unless(\App\Models\Property::whereKey($agreement->property_id)->where('is_active', true)->lockForUpdate()->first(), 422, 'This property is no longer active. Ask staff to review the agreement.');
        $prior = AccommodationAgreement::whereNotNull('tenant_id')
            ->when($agreement->eoi_submission_id,
                fn ($q) => $q->where('eoi_submission_id', $source->id),
                fn ($q) => $q->where('pre_tenancy_form_id', $source->id))->lockForUpdate()->first();
        $tenantId = $source instanceof EoiSubmission ? $source->converted_to_tenant_id : null;
        $tenantId ??= $prior?->tenant_id;
        if ($tenantId) {
            // Reuse only explicit source relationships. Never match confidential records by name/email.
            $tenant = Tenant::withTrashed()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            abort_if($tenant->trashed() || $tenant->property_id !== $agreement->property_id
                || ! in_array($tenant->current_status, [...Tenant::ACTIVE_STATUSES, 'pending_payment'], true), 422, 'The source is linked to another or inactive tenancy. Ask staff to review it before signing.');
        } else {
            $person = $agreement->data['subtenants'][0];
            $t = $agreement->data['tenancy'];
            $tenant = Tenant::create([
                'property_id' => $agreement->property_id,
                'first_name' => $person['first_name'], 'family_name' => $person['last_name'] ?? '',
                'email' => $person['email'] ?? $source->email, 'phone' => $person['mobile'] ?? $source->mobile,
                'contract_type' => 'fixed_term', 'contract_start' => $t['commence_date'], 'contract_end' => $t['end_date'],
                'weekly_rent_nzd' => $t['weekly_rent'],
                'weekly_utilities_nzd' => ($t['utility_option'] ?? '') === '1' ? ($t['utility_amount'] ?? null) : null,
                'current_status' => 'pending_payment',
                'converted_from_viewer_id' => $source instanceof EoiSubmission ? $source->id : null,
                'notes' => 'Created from signed agreement #'.$agreement->id.'. Payment and move-in are not confirmed.',
            ]);
        }
        $agreement->tenant_id = $tenant->id;
        $agreement->save();
        if ($source instanceof EoiSubmission && ! $source->converted_to_tenant_id) {
            $source->update(['converted_to_tenant_id' => $tenant->id]);
        }
    }
}
