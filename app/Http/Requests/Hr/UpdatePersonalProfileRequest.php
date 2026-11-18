<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePersonalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['phone' => ['nullable', 'string', 'max:40'], 'emergency_contact' => ['nullable', 'string', 'max:1000']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['phone', 'emergency_contact', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field is managed by HR.');
            }
        }];
    }
}
