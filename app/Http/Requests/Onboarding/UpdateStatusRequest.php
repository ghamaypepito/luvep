<?php

namespace App\Http\Requests\Onboarding;

use App\Support\OnboardingPipeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind portal:accommodation
    }

    public function rules(): array
    {
        $target = $this->input('status');

        return [
            'status' => ['required', Rule::in(OnboardingPipeline::allStatuses())],
            // Stage-specific required data.
            'viewing_scheduled_at' => [Rule::requiredIf($target === 'viewing_booked'), 'nullable', 'date'],
            'viewing_outcome' => ['nullable', 'string', 'max:5000'],
            'pre_tenancy_form_data' => ['nullable', 'array'],
            'verified_move_in_amount_nzd' => [Rule::requiredIf($target === 'payment_confirmed'), 'nullable', 'numeric', 'min:0.01', 'max:9999999999', 'decimal:0,2'],
            'move_in_date' => [Rule::requiredIf($target === 'moved_in'), 'nullable', 'date'],
            'declined_reason' => [Rule::requiredIf($target === 'declined'), 'nullable', 'string', 'max:2000'],
            'not_proceeding_reason' => [Rule::requiredIf($target === 'not_proceeding'), 'nullable', 'string', 'max:2000'],
            // The (staff-editable) stage email to actually send on this move.
            'email_subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /** Stage payload to merge into the submission (everything but the status). */
    public function stageData(): array
    {
        return collect($this->validated())
            ->except('status', 'email_subject', 'email_body')
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();
    }

    /** The stage email to send on this transition, or null when none supplied. */
    public function emailPayload(): ?array
    {
        $subject = trim((string) $this->input('email_subject'));
        $body = trim((string) $this->input('email_body'));

        return ($subject !== '' && $body !== '') ? ['subject' => $subject, 'body' => $body] : null;
    }
}
