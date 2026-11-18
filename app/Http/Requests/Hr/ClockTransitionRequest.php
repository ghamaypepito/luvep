<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClockTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['clock_in', 'break_out', 'break_in', 'clock_out'])], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0']];
    }
}
