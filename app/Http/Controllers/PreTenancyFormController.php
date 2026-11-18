<?php

namespace App\Http\Controllers;

use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use App\Services\OnboardingTransitionService;
use App\Support\OnboardingPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Public, tokenised Pre-Tenancy form — the native replacement for the external
 * Google Form. The tenant opens it from an emailed link (the token is the bearer
 * credential, like /track/{code}); submitting saves the responses onto the
 * onboarding record and advances the stage to "Pre-tenancy form completed".
 */
class PreTenancyFormController extends Controller
{
    private const ROOM_TYPES = [
        'One Room with its own private toilet and bathroom (ensuite)',
        'One Single room with shared toilet and bathroom',
        'Two Room with own private toilet and bathroom (ensuite)',
        'Two Single room with shared toilet and bathroom',
        'One Single room with shared toilet and bathroom and One room with its own private toilet and bathroom (ensuite)',
    ];

    private const LENGTHS = ['3 months', '6 months', '9 months', '12 months'];

    private const FUNDING = ['Employment / Work Income', 'Family Funded', 'Savings', 'Other'];

    /** Maps the EOI room-interest wording onto this form's room-type options. */
    private const ROOM_MAP = [
        'One Single Room (shared toilet and bathroom)' => 'One Single room with shared toilet and bathroom',
        'One Ensuite Room (private toilet and bathroom)' => 'One Room with its own private toilet and bathroom (ensuite)',
        'Two Single Room (shared toilet and bathroom)' => 'Two Single room with shared toilet and bathroom',
        'Two Ensuite Room (private toilet and bathroom)' => 'Two Room with own private toilet and bathroom (ensuite)',
        'One Single Room (shared toilet and bathroom) & One Ensuite Room (private toilet and bathroom)' => 'One Single room with shared toilet and bathroom and One room with its own private toilet and bathroom (ensuite)',
    ];

    /** Active immigration visa types for the Visa Type dropdown. */
    private function visaTypeOptions(): array
    {
        return \App\Models\VisaType::where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    /** Distinct active property addresses for the Property Address dropdown. */
    private function propertyOptions(): array
    {
        return \App\Models\Property::where('is_active', true)
            ->get()
            ->map(fn ($p) => $p->address ?: $p->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Resolve an EOI room-interest value to a valid form room-type option. */
    public static function mapRoomType(?string $eoiRoom): string
    {
        if (! $eoiRoom) {
            return '';
        }

        return self::ROOM_MAP[$eoiRoom]
            ?? (in_array($eoiRoom, self::ROOM_TYPES, true) ? $eoiRoom : '');
    }

    public function show(string $token)
    {
        $submission = EoiSubmission::where('public_token', $token)->firstOrFail();

        return inertia('accommodation/PreTenancyForm', [
            'token' => $token,
            'completed' => $submission->preTenancyFormCompleted(),
            'prefill' => [
                'full_legal_name' => $submission->full_legal_name,
                'id_number' => $submission->id_number,
                'age' => $submission->age ? (string) $submission->age : '',
                'email' => $submission->email,
                'mobile' => $submission->mobile,
                'current_address' => $submission->current_address,
                'property_address' => optional($submission->property)->address ?: $submission->property_interested,
                // Tenancy details already captured on the EOI — only pass a value
                // for the choice fields when it exactly matches an option, so a
                // radio is never left in an invalid pre-selected state.
                'move_in_date' => optional($submission->tenancy_start_date)->format('Y-m-d'),
                'length_of_stay' => in_array($submission->stay_duration, self::LENGTHS, true) ? $submission->stay_duration : '',
                'room_type' => self::mapRoomType($submission->room_type_interest),
                'rent_funding' => in_array($submission->rent_funding, self::FUNDING, true) ? $submission->rent_funding : '',
            ],
            'options' => [
                'room_types' => self::ROOM_TYPES,
                'lengths' => self::LENGTHS,
                'funding' => self::FUNDING,
                'properties' => $this->propertyOptions(),
                'visa_types' => $this->visaTypeOptions(),
            ],
        ]);
    }

    public function submit(Request $request, string $token, OnboardingTransitionService $service)
    {
        $submission = EoiSubmission::where('public_token', $token)->firstOrFail();

        if ($submission->preTenancyFormCompleted()) {
            return back()->with('error', 'This form has already been submitted.');
        }

        $data = $request->validate([
            // Main applicant
            'full_legal_name' => 'required|string|max:191',
            'id_number' => 'required|string|max:100',
            'age' => 'required|string|max:10',
            'email' => 'required|email|max:191',
            'mobile' => 'required|string|max:40',
            'current_address' => 'required|string|max:500',
            'visa_type' => 'nullable|string|max:191',
            // Other occupants
            'has_additional_occupants' => 'required|in:no,yes',
            // No fixed cap — the tenant adds as many occupants as they need; a
            // generous ceiling only guards against abuse.
            'occupants' => 'nullable|array|max:30',
            'occupants.*.full_name' => 'nullable|string|max:191',
            'occupants.*.id_number' => 'nullable|string|max:100',
            'occupants.*.dob' => 'nullable|date',
            'occupants.*.age' => 'nullable|string|max:10',
            'occupants.*.relationship' => 'nullable|string|max:120',
            'occupants.*.email' => 'nullable|email|max:191',
            'occupants.*.mobile' => 'nullable|string|max:40',
            'occupants.*.visa_type' => 'nullable|string|max:191',
            // Documents
            'valid_id' => 'required|file|mimes:pdf,jpg,jpeg,png,heic,webp|max:20480',
            'visa' => 'required|file|mimes:pdf,jpg,jpeg,png,heic,webp|max:20480',
            // Bank details
            'account_name' => 'required|string|max:191',
            'account_number' => 'required|string|max:60',
            // Tenancy details
            'property_address' => 'required|string|max:500',
            'move_in_date' => 'required|date',
            'length_of_stay' => ['required', 'in:'.implode(',', self::LENGTHS)],
            'room_type' => ['required', 'in:'.implode(',', self::ROOM_TYPES)],
            'rent_funding' => ['required', 'in:'.implode(',', self::FUNDING)],
            'rent_funding_other' => 'nullable|string|max:191',
            // Reference
            'referee_name' => 'required|string|max:191',
            'referee_phone' => 'required|string|max:40',
            'referee_email' => 'required|email|max:191',
        ]);

        // At least the first occupant's name when they said "yes".
        if ($data['has_additional_occupants'] === 'yes' && empty($data['occupants'][0]['full_name'] ?? null)) {
            throw ValidationException::withMessages([
                'occupants.0.full_name' => "Please provide the first occupant's full name.",
            ]);
        }
        // "Other" funding needs the free-text detail.
        if ($data['rent_funding'] === 'Other' && empty($data['rent_funding_other'])) {
            throw ValidationException::withMessages([
                'rent_funding_other' => 'Please describe how you will fund your rent.',
            ]);
        }

        try {
            $dir = "pre-tenancy/{$submission->id}";
            $validIdPath = $request->file('valid_id')->store($dir, 'local');
            $visaPath = $request->file('visa')->store($dir, 'local');

            // Keep only occupant rows the tenant actually filled in.
            $occupants = collect($data['has_additional_occupants'] === 'yes' ? ($data['occupants'] ?? []) : [])
                ->filter(fn ($o) => ! empty($o['full_name']))
                ->values()
                ->all();

            $submission->pre_tenancy_form_data = [
                'main' => [
                    'full_legal_name' => $data['full_legal_name'],
                    'id_number' => $data['id_number'],
                    'age' => $data['age'],
                    'email' => $data['email'],
                    'mobile' => $data['mobile'],
                    'current_address' => $data['current_address'],
                    'visa_type' => $data['visa_type'] ?? '',
                ],
                'has_additional_occupants' => $data['has_additional_occupants'] === 'yes',
                'occupants' => $occupants,
                'documents' => [
                    'valid_id' => ['path' => $validIdPath, 'name' => $request->file('valid_id')->getClientOriginalName()],
                    'visa' => ['path' => $visaPath, 'name' => $request->file('visa')->getClientOriginalName()],
                ],
                'bank' => [
                    'account_name' => $data['account_name'],
                    'account_number' => $data['account_number'],
                ],
                'tenancy' => [
                    'property_address' => $data['property_address'],
                    'move_in_date' => $data['move_in_date'],
                    'length_of_stay' => $data['length_of_stay'],
                    'room_type' => $data['room_type'],
                    'rent_funding' => $data['rent_funding'] === 'Other'
                        ? 'Other — '.$data['rent_funding_other']
                        : $data['rent_funding'],
                ],
                'reference' => [
                    'referee_name' => $data['referee_name'],
                    'referee_phone' => $data['referee_phone'],
                    'referee_email' => $data['referee_email'],
                ],
                'submitted_at' => now()->toIso8601String(),
            ];

            // Mirror the corrected contact details + move-in onto the record so
            // staff see the latest without opening the JSON.
            $submission->full_legal_name = $data['full_legal_name'];
            $submission->email = $data['email'];
            $submission->mobile = $data['mobile'];
            $submission->current_address = $data['current_address'];
            $submission->move_in_date = $data['move_in_date'];

            // Advance the pipeline when the record is waiting on the form; else
            // just stamp completion (staff may have moved it on manually).
            if (OnboardingPipeline::canTransition($submission->status, 'pre_tenancy_form_completed')) {
                $submission->save();
                $service->transition($submission, 'pre_tenancy_form_completed');
            } else {
                $submission->pre_tenancy_form_completed_at = now();
                $submission->save();
            }

            $this->notifyStaff($submission);

            return back()->with('success', 'Your pre-tenancy form has been submitted.');
        } catch (\Throwable $e) {
            Log::error('Pre-tenancy form submit failed', ['submission' => $submission->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Something went wrong submitting the form. Please try again.');
        }
    }

    /**
     * Notify the accommodation team that the form is in — the assigned staffer
     * when there is one, otherwise every accommodation user plus admins.
     */
    private function notifyStaff(EoiSubmission $submission): void
    {
        try {
            $recipients = collect();
            if ($submission->assigned_to_user_id) {
                $recipients = \App\Models\User::whereKey($submission->assigned_to_user_id)->get();
            }
            if ($recipients->isEmpty()) {
                $recipients = \App\Models\User::whereIn('role', [
                    'accommodation', \App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_SUPER_ADMIN,
                ])->get();
            }
            if ($recipients->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send($recipients, new \App\Notifications\PreTenancyFormSubmitted($submission));
            }
        } catch (\Throwable $e) {
            Log::error('Pre-tenancy submit notify failed', ['submission' => $submission->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The standalone ("general") pre-tenancy form — one shared public link, not
     * tied to any onboarding record. For applicants who skipped the hot-lead
     * funnel. Same fields as the onboarding form; submissions land in the
     * Forms → Pre-Tenancy list.
     */
    public function general()
    {
        return inertia('accommodation/PreTenancyForm', [
            'token' => 'general',
            'general' => true,
            'completed' => false,
            'prefill' => [],
            'options' => [
                'room_types' => self::ROOM_TYPES,
                'lengths' => self::LENGTHS,
                'funding' => self::FUNDING,
                'properties' => $this->propertyOptions(),
                'visa_types' => $this->visaTypeOptions(),
            ],
        ]);
    }

    public function storeGeneral(Request $request)
    {
        $data = $request->validate([
            'full_legal_name' => 'required|string|max:191',
            'id_number' => 'required|string|max:100',
            'age' => 'required|string|max:10',
            'email' => 'required|email|max:191',
            'mobile' => 'required|string|max:40',
            'current_address' => 'required|string|max:500',
            'visa_type' => 'nullable|string|max:191',
            'has_additional_occupants' => 'required|in:no,yes',
            'occupants' => 'nullable|array|max:30',
            'occupants.*.full_name' => 'nullable|string|max:191',
            'occupants.*.id_number' => 'nullable|string|max:100',
            'occupants.*.dob' => 'nullable|date',
            'occupants.*.age' => 'nullable|string|max:10',
            'occupants.*.relationship' => 'nullable|string|max:120',
            'occupants.*.email' => 'nullable|email|max:191',
            'occupants.*.mobile' => 'nullable|string|max:40',
            'occupants.*.visa_type' => 'nullable|string|max:191',
            'valid_id' => 'required|file|mimes:pdf,jpg,jpeg,png,heic,webp|max:20480',
            'visa' => 'required|file|mimes:pdf,jpg,jpeg,png,heic,webp|max:20480',
            'account_name' => 'required|string|max:191',
            'account_number' => 'required|string|max:60',
            'property_address' => 'required|string|max:500',
            'move_in_date' => 'required|date',
            'length_of_stay' => ['required', 'in:'.implode(',', self::LENGTHS)],
            'room_type' => ['required', 'in:'.implode(',', self::ROOM_TYPES)],
            'rent_funding' => ['required', 'in:'.implode(',', self::FUNDING)],
            'rent_funding_other' => 'nullable|string|max:191',
            'referee_name' => 'required|string|max:191',
            'referee_phone' => 'required|string|max:40',
            'referee_email' => 'required|email|max:191',
        ]);

        if ($data['has_additional_occupants'] === 'yes' && empty($data['occupants'][0]['full_name'] ?? null)) {
            throw ValidationException::withMessages(['occupants.0.full_name' => "Please provide the first occupant's full name."]);
        }
        if ($data['rent_funding'] === 'Other' && empty($data['rent_funding_other'])) {
            throw ValidationException::withMessages(['rent_funding_other' => 'Please describe how you will fund your rent.']);
        }

        try {
            $form = PreTenancyForm::create([
                'full_legal_name' => $data['full_legal_name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'current_address' => $data['current_address'],
                'property_address' => $data['property_address'],
                'submitted_at' => now(),
            ]);

            $dir = "pre-tenancy-general/{$form->id}";
            $validIdPath = $request->file('valid_id')->store($dir, 'local');
            $visaPath = $request->file('visa')->store($dir, 'local');

            $occupants = collect($data['has_additional_occupants'] === 'yes' ? ($data['occupants'] ?? []) : [])
                ->filter(fn ($o) => ! empty($o['full_name']))->values()->all();

            $form->data = [
                'main' => [
                    'full_legal_name' => $data['full_legal_name'],
                    'id_number' => $data['id_number'],
                    'age' => $data['age'],
                    'email' => $data['email'],
                    'mobile' => $data['mobile'],
                    'current_address' => $data['current_address'],
                    'visa_type' => $data['visa_type'] ?? '',
                ],
                'has_additional_occupants' => $data['has_additional_occupants'] === 'yes',
                'occupants' => $occupants,
                'documents' => [
                    'valid_id' => ['path' => $validIdPath, 'name' => $request->file('valid_id')->getClientOriginalName()],
                    'visa' => ['path' => $visaPath, 'name' => $request->file('visa')->getClientOriginalName()],
                ],
                'bank' => [
                    'account_name' => $data['account_name'],
                    'account_number' => $data['account_number'],
                ],
                'tenancy' => [
                    'property_address' => $data['property_address'],
                    'move_in_date' => $data['move_in_date'],
                    'length_of_stay' => $data['length_of_stay'],
                    'room_type' => $data['room_type'],
                    'rent_funding' => $data['rent_funding'] === 'Other' ? 'Other — '.$data['rent_funding_other'] : $data['rent_funding'],
                ],
                'reference' => [
                    'referee_name' => $data['referee_name'],
                    'referee_phone' => $data['referee_phone'],
                    'referee_email' => $data['referee_email'],
                ],
                'submitted_at' => now()->toIso8601String(),
            ];
            $form->save();

            return back()->with('success', 'Your pre-tenancy form has been submitted.');
        } catch (\Throwable $e) {
            Log::error('General pre-tenancy submit failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Something went wrong submitting the form. Please try again.');
        }
    }

    /**
     * Stream a tenant-uploaded pre-tenancy document to staff (private disk).
     * Gated to the accommodation portal in routes.
     */
    public function download(Request $request, EoiSubmission $submission, string $kind)
    {
        abort_unless(in_array($kind, ['valid_id', 'visa'], true), 404);

        $doc = $submission->pre_tenancy_form_data['documents'][$kind] ?? null;
        abort_unless($doc && ! empty($doc['path']) && Storage::disk('local')->exists($doc['path']), 404);

        $name = $doc['name'] ?? basename($doc['path']);
        if ($request->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($doc['path']), ['Content-Disposition' => 'inline; filename="'.$name.'"']);
        }

        return Storage::disk('local')->download($doc['path'], $name);
    }
}
