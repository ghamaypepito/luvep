<?php

namespace App\Http\Requests\Onboarding;

use App\Services\Accommodation\OnboardingOwners;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['present', 'nullable', 'integer', Rule::in(array_column(OnboardingOwners::options(), 'id'))],
        ];
    }
}
