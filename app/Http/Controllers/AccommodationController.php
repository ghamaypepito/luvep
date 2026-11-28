<?php

namespace App\Http\Controllers;

use App\Models\EoiSubmission;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccommodationController extends Controller
{
    public function index()
    {
        // property_type is internal by default (see Property::MANAGEMENT_FIELDS
        // + $hidden) — surface it here so the public grid can filter on
        // House / Studio / Room without exposing the rest of the internal
        // management columns.
        $properties = Property::with('images')
            ->where('status', 'available')
            ->latest()
            ->get()
            ->each->makeVisible(['property_type']);

        return inertia('accommodation/AccommodationPage', [
            'properties' => $properties,
            // Viewing scheduler shares the immigration booking's fixed business
            // timezone; the client-side calendar shows each slot in the visitor's
            // own timezone. No per-staff availability yet — the component falls
            // back to Mon–Fri 9–5.
            'bookingTimezone' => config('services.booking.timezone', 'Pacific/Auckland'),
        ]);
    }

    public function show($slug)
    {
        $property = Property::with('images')
            ->where('status', 'available')
            ->where('slug', $slug)
            ->firstOrFail();

        return inertia('accommodation/PropertyDetails', ['property' => $property]);
    }

    /**
     * Shared validation rules for both the COLD and HOT EOI forms.
     * The HOT form adds 'property_interested' on top of these.
     */
    private function eoiRules(): array
    {
        return [
            // Section 1 — Personal
            'full_legal_name' => 'required|string|max:255',
            'id_number' => 'required|string|max:255',
            'visa_status' => ['required', Rule::in(self::VISA_STATUSES)],
            'visa_status_other' => 'nullable|required_if:visa_status,Other|string|max:255',
            'nationality' => ['required', Rule::in(self::NATIONALITIES)],
            'nationality_other' => 'nullable|required_if:nationality,Other|string|max:255',
            'preferred_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'mobile' => 'required|string|max:50',
            'age' => 'required|integer|min:16|max:120',

            // Section 2 — Property & Room Interest
            'room_type_interest' => ['required', Rule::in(self::ROOM_TYPES)],
            'tenancy_start_date' => 'required|date',
            'stay_duration' => ['required', Rule::in(self::STAY_DURATIONS)],

            // Section 3 — Occupancy
            'occupants' => ['required', Rule::in(self::OCCUPANTS)],
            'occupant_ages' => 'required|string|max:255',
            'has_children' => 'required|boolean',
            'children_ages' => 'nullable|string|max:255',
            'has_pets' => 'required|boolean',
            'pet_details' => 'nullable|string|max:255',

            // Section 4 — Employment / Study
            'rent_funding' => ['nullable', Rule::in(self::RENT_FUNDING)],
            'rent_funding_other' => 'nullable|required_if:rent_funding,Other|string|max:255',
            'employment_status' => ['required', Rule::in(self::EMPLOYMENT_STATUSES)],
            'employment_status_other' => 'nullable|required_if:employment_status,Other|string|max:255',

            // Section 5 — Rental Background
            'current_address' => 'required|string|max:500',
            'has_rented_before' => 'required|boolean',
            'current_address_duration' => 'required|string|max:255',
            'living_situation' => ['required', Rule::in(self::LIVING_SITUATIONS)],
            'reason_for_moving' => 'required|string|max:1000',

            // Section 6 — Lifestyle & Compatibility
            'smokes_or_vapes' => 'required|boolean',
            'drinks_alcohol' => ['required', Rule::in(self::DRINKS)],
            'work_hours' => ['required', Rule::in(self::WORK_HOURS)],
            'flatmate_description' => 'required|string|max:1000',

            // Section 7 — Viewing Availability
            'viewing_available_7days' => 'required|boolean',
            'preferred_viewing_time' => ['required', Rule::in(self::VIEWING_TIMES)],

            // Section 8 — Declaration & Consent
            'confirm_accurate' => 'accepted',
            'consent_collection' => 'accepted',
        ];
    }

    /**
     * Expression of Interest — COLD form (general registration).
     */
    public function eoiForm()
    {
        return inertia('accommodation/ExpressionOfInterest', ['variant' => 'cold']);
    }

    public function eoiStore(Request $request)
    {
        $data = $request->validate($this->eoiRules());
        $data['form_type'] = 'cold';

        EoiSubmission::create($data);

        return redirect()->route('accommodation.eoi')
            ->with('success', 'Thank you for registering with Exalt Property Management LTD.');
    }

    /**
     * Expression of Interest — HOT form (applicant ready to view a specific room).
     * Optionally prefilled with the property the applicant came from.
     */
    public function eoiHotForm(Request $request)
    {
        $listing = $request->integer('listing');
        $property = $listing ? $this->availableListings()->findOrFail($listing) : null;

        return inertia('accommodation/ExpressionOfInterest', [
            'variant' => 'hot',
            'propertyPrefill' => $property?->name ?? $request->query('property'),
            'listingId' => $property?->id,
        ]);
    }

    public function eoiHotStore(Request $request)
    {
        $data = $request->validate(array_merge($this->eoiRules(), [
            'property_interested' => 'required_without:property_id|nullable|string|max:255',
            'property_id' => ['nullable', 'integer', Rule::exists('accommodation_properties', 'id')->where('is_active', true)->where('status', 'available')],
        ]));
        if (! empty($data['property_id'])) {
            $property = $this->availableListings()->findOrFail($data['property_id']);
            $data['property_interested'] = $property->address ?: $property->name;
        } elseif (! empty($data['property_interested'])) {
            $label = mb_strtolower(trim($data['property_interested']));
            $matches = $this->availableListings()->get(['id', 'name', 'suburb', 'address'])
                ->filter(fn ($property) => in_array($label, array_map(
                    fn ($value) => mb_strtolower(trim((string) $value)),
                    [$property->name, $property->address, implode(' — ', array_filter([$property->name, $property->suburb]))],
                ), true));
            if ($matches->count() === 1) {
                $data['property_id'] = $matches->first()->id;
            }
        }
        $data['form_type'] = 'hot';

        EoiSubmission::create($data);

        return redirect()->route('accommodation.eoi-hot')
            ->with('success', 'Thank you for registering with Exalt Property Management LTD.');
    }

    private function availableListings()
    {
        return Property::where('is_active', true)->where('status', 'available');
    }

    // Allowed option values — shared by validation and surfaced to the React form.
    public const VISA_STATUSES = [
        'New Zealand Citizen', 'Permanent Resident', 'Resident Visa',
        'Work Visa', 'Student Visa', 'Visitor Visa', 'Other',
    ];

    public const NATIONALITIES = [
        'New Zealander/Kiwi', 'Australian', 'Filipino', 'Indian', 'Chinese',
        'Korean', 'Japanese', 'British', 'American', 'South African', 'Brazilian', 'Other',
    ];

    public const ROOM_TYPES = [
        'One Single Room (shared toilet and bathroom)',
        'One Ensuite Room (private toilet and bathroom)',
        'Two Single Room (shared toilet and bathroom)',
        'Two Ensuite Room (private toilet and bathroom)',
        'One Single Room (shared toilet and bathroom) & One Ensuite Room (private toilet and bathroom)',
    ];

    public const STAY_DURATIONS = ['3 Months', '6 months', '12 months', '12+ months'];

    public const OCCUPANTS = ['Just me', 'Me and My Partner'];

    public const RENT_FUNDING = ['Employment / Work Income', 'Family Funded', 'Savings', 'Other'];

    public const EMPLOYMENT_STATUSES = [
        'Full-time employment', 'Part-time employment', 'Student', 'Self-employed', 'Other',
    ];

    public const LIVING_SITUATIONS = ['Renting', 'Boarding', 'Living with family'];

    public const DRINKS = ['No', 'Socially', 'Regularly'];

    public const WORK_HOURS = ['Day', 'Night', 'Shift Variables'];

    public const VIEWING_TIMES = ['Weekdays', 'Weekends', 'Flexible'];
}
