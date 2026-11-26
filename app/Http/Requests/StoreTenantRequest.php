<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind portal:accommodation middleware
    }

    public function rules(): array
    {
        $tenant = $this->route('tenant') instanceof Tenant ? $this->route('tenant') : null;
        $sameDay = $tenant && ($tenant->current_status === 'pending_payment'
            || ($tenant->contract_start && $tenant->contract_end && $tenant->contract_start->equalTo($tenant->contract_end)));

        return [
            // Assignment — property must exist and still be in the portfolio.
            'property_id' => ['required', Rule::exists('accommodation_properties', 'id')->where('is_active', true)],
            'unit' => 'nullable|string|max:50',
            'assigned_room_ids' => 'sometimes|array|list|max:50',
            'assigned_room_ids.*' => 'required|uuid|distinct',
            'room_assignment_version' => 'sometimes|nullable|string|size:64',

            // Identity
            'first_name' => 'required|string|max:100',
            'family_name' => 'required|string|max:100',
            'display_name_override' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:50',
            'nationality' => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
            'passport_number' => 'nullable|string|max:50',

            // Contract — end must be after start only when both are supplied.
            'contract_type' => ['required', Rule::in(Tenant::CONTRACT_TYPES)],
            'contract_start' => 'nullable|date',
            'contract_end' => array_values(array_filter(['nullable', 'date', $this->filled('contract_start') ? ($sameDay ? 'after_or_equal:contract_start' : 'after:contract_start') : null])),
            'open_contract_notice_weeks' => 'nullable|integer|min:0|max:520',

            // Financial
            'weekly_rent_nzd' => 'nullable|numeric|min:0',
            'weekly_utilities_nzd' => 'nullable|numeric|min:0',
            'bond_paid_nzd' => 'nullable|numeric|min:0',
            'advance_paid_nzd' => 'nullable|numeric|min:0',

            // Documents
            'has_passport_in_drive' => 'boolean',
            'has_tenancy_agreement_in_drive' => 'boolean',
            'has_inspection_report_in_drive' => 'boolean',

            // Lifecycle
            'current_status' => ['nullable', Rule::in(Tenant::STATUSES)],
            'notes' => 'nullable|string',
        ];
    }
}
