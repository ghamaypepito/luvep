<?php

namespace App\Services\Accommodation;

use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use Illuminate\Support\Collection;

/**
 * Bridges the two pre-tenancy submission sources — standalone PreTenancyForm
 * rows and onboarding EoiSubmission records — into a single client list for the
 * Agreements module, and turns a chosen submission into the editable fill-data
 * for an Exalt flat/house-sharing agreement (sub-tenant blocks, tenancy terms,
 * move-in costs, bank particulars). All values are pre-fill only; staff edit
 * them in the module before generating.
 */
class AgreementBuilder
{
    /** Unified, de-normalised client list for the picker (name + source ref). */
    public function clients(): Collection
    {
        $general = PreTenancyForm::query()
            ->whereNotNull('submitted_at')
            ->latest('submitted_at')
            ->get()
            ->map(fn (PreTenancyForm $f) => [
                'source' => 'general',
                'id' => $f->id,
                'name' => $f->full_legal_name ?: 'Unnamed applicant',
                'email' => $f->email,
                'mobile' => $f->mobile,
                'property_address' => $f->property_address,
                'submitted_at' => optional($f->submitted_at)->toIso8601String(),
            ]);

        // Only onboarding records that have reached the agreement step — ready to
        // have an agreement generated (pre_tenancy_form_completed) or one already
        // in progress (agreement_sent). Signed/earlier stages drop off the list.
        $onboarding = EoiSubmission::query()
            ->whereNotNull('pre_tenancy_form_data')
            ->whereIn('status', ['pre_tenancy_form_completed', 'agreement_sent'])
            ->with('property')
            ->latest('pre_tenancy_form_completed_at')
            ->get()
            ->map(fn (EoiSubmission $s) => [
                'source' => 'onboarding',
                'id' => $s->id,
                'name' => $s->full_legal_name ?: 'Unnamed applicant',
                'email' => $s->email,
                'mobile' => $s->mobile,
                'property_address' => optional($s->property)->address ?: $s->property_interested,
                'submitted_at' => optional($s->pre_tenancy_form_completed_at)->toIso8601String(),
            ]);

        return $general->concat($onboarding)
            ->sortByDesc('submitted_at')
            ->values();
    }

    /** Resolve a source ref to its raw pre-tenancy `data` payload + context. */
    public function resolve(string $source, int $id): ?array
    {
        if ($source === 'general') {
            $f = PreTenancyForm::find($id);

            return $f ? [
                'data' => $f->data ?? [],
                'name' => $f->full_legal_name,
                'email' => $f->email,
                'property_address' => $f->property_address,
                'pre_tenancy_form_id' => $f->id,
                'eoi_submission_id' => null,
            ] : null;
        }

        if ($source === 'onboarding') {
            $s = EoiSubmission::with('property')->find($id);

            return $s ? [
                'data' => $s->pre_tenancy_form_data ?? [],
                'name' => $s->full_legal_name,
                'email' => $s->email,
                'property_address' => optional($s->property)->address ?: $s->property_interested,
                'pre_tenancy_form_id' => null,
                'eoi_submission_id' => $s->id,
            ] : null;
        }

        return null;
    }

    /** Build editable agreement fill-data from a resolved submission. */
    public function prefill(string $source, int $id): ?array
    {
        $ctx = $this->resolve($source, $id);
        if (! $ctx) {
            return null;
        }

        $data = $ctx['data'];
        $main = $data['main'] ?? [];
        $tenancy = $data['tenancy'] ?? [];
        $bank = $data['bank'] ?? [];
        $occupants = ($data['has_additional_occupants'] ?? false) ? ($data['occupants'] ?? []) : [];

        $subtenants = [];
        // Sub-Tenant 1 = the main applicant.
        [$first, $last] = $this->splitName($main['full_legal_name'] ?? $ctx['name']);
        $subtenants[] = [
            'first_name' => $first,
            'last_name' => $last,
            'passport_id' => $main['id_number'] ?? '',
            'expiry_date' => '',
            'dob' => '',
            'current_address' => $main['current_address'] ?? '',
            'email' => $main['email'] ?? '',
            'mobile' => $main['mobile'] ?? '',
            'visa_type' => $main['visa_type'] ?? '',
            'relationship' => '',
        ];
        // Sub-Tenant 2..N = the additional occupants they listed.
        foreach ($occupants as $o) {
            [$of, $ol] = $this->splitName($o['full_name'] ?? '');
            $subtenants[] = [
                'first_name' => $of,
                'last_name' => $ol,
                'passport_id' => $o['id_number'] ?? '',
                'expiry_date' => '',
                'dob' => $o['dob'] ?? '',
                'current_address' => '',
                'email' => $o['email'] ?? '',
                'mobile' => $o['mobile'] ?? '',
                'visa_type' => $o['visa_type'] ?? '',
                'relationship' => $o['relationship'] ?? '',
            ];
        }

        return [
            'property_address' => $tenancy['property_address'] ?? $ctx['property_address'] ?? '',
            'subtenants' => $subtenants,
            'tenancy' => [
                'commence_date' => $tenancy['move_in_date'] ?? '',
                'term' => $tenancy['length_of_stay'] ?? '',
                'end_date' => '',
                'move_in_time' => '',
                'move_in_date' => $tenancy['move_in_date'] ?? '',
                'move_out_time' => '',
                'move_out_date' => '',
                'weekly_rent' => '',
                'rent_day' => 'Friday',
                'utility_option' => '',
                'room_shared' => false,
                'room_shared_max' => '',
                'room_type' => $tenancy['room_type'] ?? '',
            ],
            'costs' => [
                'bond' => '', 'deposit' => '', 'rent_advance' => '',
                'prorated_rent' => '', 'prorated_days' => '', 'total_move_in' => '',
                'rent_advance_from' => '', 'rent_advance_to' => '',
            ],
            'bank' => [
                'particular' => $main['full_legal_name'] ?? $ctx['name'] ?? '',
                'reference' => '',
                'account_name' => $bank['account_name'] ?? '',
            ],
        ];
    }

    private function splitName(?string $full): array
    {
        $full = trim((string) $full);
        if ($full === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $full);
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}
