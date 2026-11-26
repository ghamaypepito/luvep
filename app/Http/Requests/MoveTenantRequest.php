<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentPropertyId = $this->route('tenant')?->property_id;

        return [
            'new_property_id' => [
                'required',
                Rule::exists('accommodation_properties', 'id')->where('is_active', true),
                Rule::notIn([$currentPropertyId]),
            ],
            'move_date' => 'required|date|before_or_equal:today',
            'assigned_room_ids' => 'sometimes|array|list|max:50',
            'assigned_room_ids.*' => 'required|uuid|distinct',
        ];
    }

    public function messages(): array
    {
        return [
            'new_property_id.not_in' => 'Choose a different property than the tenant is currently in.',
        ];
    }
}
