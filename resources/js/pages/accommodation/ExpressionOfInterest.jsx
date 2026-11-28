import React, { useState } from 'react';
import { useForm, usePage, Link } from '@inertiajs/react';
import Navbar from '@/components/layout/Navbar';
import Footer from '@/components/layout/Footer';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
const PRIMARY = '#1F5A8B';

const SECTIONS = [
    'Personal Details',
    'Property & Room Interest',
    'Occupancy Details',
    'Employment / Study',
    'Rental Background',
    'Lifestyle & Compatibility',
    'Viewing Availability',
    'Declaration & Consent',
];

// Fields that are required in each section (static list; conditional fields handled separately)
const SECTION_REQUIRED = [
    // Section 0 — Personal Details
    ['full_legal_name', 'id_number', 'visa_status', 'nationality', 'preferred_name', 'email', 'mobile', 'age'],
    // Section 1 — Property & Room Interest (property_interested added conditionally via validateSection)
    ['room_type_interest', 'tenancy_start_date', 'stay_duration'],
    // Section 2 — Occupancy Details
    ['occupants', 'occupant_ages', 'has_children', 'has_pets'],
    // Section 3 — Employment / Study
    ['employment_status'],
    // Section 4 — Rental Background
    ['current_address', 'has_rented_before', 'current_address_duration', 'living_situation', 'reason_for_moving'],
    // Section 5 — Lifestyle & Compatibility
    ['smokes_or_vapes', 'drinks_alcohol', 'work_hours', 'flatmate_description'],
    // Section 6 — Viewing Availability
    ['viewing_available_7days', 'preferred_viewing_time'],
    // Section 7 — Declaration & Consent
    ['confirm_accurate', 'consent_collection'],
];

// Maps every server-validatable field to its wizard section. The wizard only
// renders the current section, so a server validation error targeting a field
// on an earlier section would otherwise be invisible. On submit failure we use
// this to jump back to the earliest section that has an error.
const FIELD_SECTION = {
    full_legal_name: 0, id_number: 0, visa_status: 0, visa_status_other: 0,
    nationality: 0, nationality_other: 0, preferred_name: 0, email: 0, mobile: 0, age: 0,
    property_interested: 1, room_type_interest: 1, tenancy_start_date: 1, stay_duration: 1,
    occupants: 2, occupant_ages: 2, has_children: 2, children_ages: 2, has_pets: 2, pet_details: 2,
    rent_funding: 3, rent_funding_other: 3, employment_status: 3, employment_status_other: 3,
    current_address: 4, has_rented_before: 4, current_address_duration: 4, living_situation: 4, reason_for_moving: 4,
    smokes_or_vapes: 5, drinks_alcohol: 5, work_hours: 5, flatmate_description: 5,
    viewing_available_7days: 6, preferred_viewing_time: 6,
    confirm_accurate: 7, consent_collection: 7,
};

const ROOM_OPTIONS = [
    'One Single Room (shared toilet and bathroom)',
    'One Ensuite Room (private toilet and bathroom)',
    'Two Single Room (shared toilet and bathroom)',
    'Two Ensuite Room (private toilet and bathroom)',
    'One Single Room (shared toilet and bathroom) & One Ensuite Room (private toilet and bathroom)',
];

// ---------------------------------------------------------------------------
// Validate a single section; returns error map { fieldName: message }
// ---------------------------------------------------------------------------
function validateSection(sectionIndex, data, isHot = false) {
    const errors = {};

    const required = [
        ...SECTION_REQUIRED[sectionIndex],
        ...(sectionIndex === 1 && isHot ? ['property_interested'] : []),
    ];

    const isEmpty = (val) => {
        if (val === null || val === undefined) return true;
        if (typeof val === 'string') return val.trim() === '';
        if (typeof val === 'boolean') return false; // booleans are always "answered"
        return false;
    };

    required.forEach((field) => {
        // Boolean fields (null = unanswered)
        if (['has_children', 'has_pets', 'has_rented_before', 'smokes_or_vapes', 'viewing_available_7days'].includes(field)) {
            if (data[field] === null || data[field] === undefined) {
                errors[field] = 'Please select an option.';
            }
            return;
        }
        // Checkbox fields
        if (['confirm_accurate', 'consent_collection'].includes(field)) {
            if (!data[field]) {
                errors[field] = 'You must agree to continue.';
            }
            return;
        }
        if (isEmpty(data[field])) {
            errors[field] = 'This field is required.';
        }
    });

    // Conditional: visa_status_other required when visa_status === 'Other'
    if (sectionIndex === 0 && data.visa_status === 'Other' && isEmpty(data.visa_status_other)) {
        errors.visa_status_other = 'Please specify your visa status.';
    }
    // Conditional: nationality_other required when nationality === 'Other'
    if (sectionIndex === 0 && data.nationality === 'Other' && isEmpty(data.nationality_other)) {
        errors.nationality_other = 'Please specify your nationality.';
    }
    // Conditional: children_ages required when has_children === true
    if (sectionIndex === 2 && data.has_children === true && isEmpty(data.children_ages)) {
        errors.children_ages = "Please provide the child(ren)'s age(s).";
    }
    // Conditional: rent_funding_other required when rent_funding === 'Other'
    if (sectionIndex === 3 && data.rent_funding === 'Other' && isEmpty(data.rent_funding_other)) {
        errors.rent_funding_other = 'Please specify how you will fund your rent.';
    }
    // Conditional: employment_status_other required when employment_status === 'Other'
    if (sectionIndex === 3 && data.employment_status === 'Other' && isEmpty(data.employment_status_other)) {
        errors.employment_status_other = 'Please specify your employment status.';
    }

    return errors;
}

// ---------------------------------------------------------------------------
// Reusable field components
// ---------------------------------------------------------------------------

function FieldError({ msg }) {
    if (!msg) return null;
    return <p className="mt-1 text-sm text-red-500">{msg}</p>;
}

function Label({ children, required }) {
    return (
        <label className="block text-sm font-semibold text-gray-800 mb-1">
            {children}
            {required && <span className="text-red-500 ml-1">*</span>}
        </label>
    );
}

function HelpText({ children }) {
    return <p className="text-xs text-gray-400 mt-0.5 mb-1">{children}</p>;
}

function TextInput({ value, onChange, type = 'text', placeholder, min, className = '' }) {
    return (
        <input
            type={type}
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            min={min}
            className={`w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]/30 focus:border-[#1F5A8B] transition ${className}`}
        />
    );
}

function Textarea({ value, onChange, rows = 4, placeholder }) {
    return (
        <textarea
            value={value}
            onChange={onChange}
            rows={rows}
            placeholder={placeholder}
            className="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]/30 focus:border-[#1F5A8B] transition resize-none"
        />
    );
}

function RadioGroup({ options, value, onChange, name }) {
    return (
        <div className="flex flex-col gap-2 mt-1">
            {options.map((opt) => (
                <label
                    key={opt}
                    className={`flex items-start gap-3 cursor-pointer rounded-xl border px-4 py-3 text-sm transition ${
                        value === opt
                            ? 'border-[#1F5A8B] bg-[#1F5A8B]/5 font-medium text-[#1F5A8B]'
                            : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300'
                    }`}
                >
                    <input
                        type="radio"
                        name={name}
                        value={opt}
                        checked={value === opt}
                        onChange={() => onChange(opt)}
                        className="mt-0.5 accent-[#1F5A8B] shrink-0"
                    />
                    {opt}
                </label>
            ))}
        </div>
    );
}

function YesNoRadio({ value, onChange, name }) {
    return (
        <div className="flex gap-3 mt-1">
            {[{ label: 'Yes', val: true }, { label: 'No', val: false }].map(({ label, val }) => (
                <label
                    key={label}
                    className={`flex items-center gap-2 cursor-pointer rounded-xl border px-5 py-2.5 text-sm font-medium transition ${
                        value === val
                            ? 'border-[#1F5A8B] bg-[#1F5A8B]/5 text-[#1F5A8B]'
                            : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300'
                    }`}
                >
                    <input
                        type="radio"
                        name={name}
                        checked={value === val}
                        onChange={() => onChange(val)}
                        className="accent-[#1F5A8B]"
                    />
                    {label}
                </label>
            ))}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Section components
// ---------------------------------------------------------------------------

function Section1({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];

    const visaOptions = ['New Zealand Citizen', 'Permanent Resident', 'Resident Visa', 'Work Visa', 'Student Visa', 'Visitor Visa', 'Other'];
    const nationalityOptions = ['New Zealander/Kiwi', 'Australian', 'Filipino', 'Indian', 'Chinese', 'Korean', 'Japanese', 'British', 'American', 'South African', 'Brazilian', 'Other'];

    return (
        <div className="space-y-6">
            {/* full_legal_name */}
            <div>
                <Label required>Full Legal Name (First, Middle, Surname)</Label>
                <HelpText>As per Passport or Driver License</HelpText>
                <TextInput
                    value={data.full_legal_name}
                    onChange={(e) => setData('full_legal_name', e.target.value)}
                />
                <FieldError msg={err('full_legal_name')} />
            </div>

            {/* id_number */}
            <div>
                <Label required>Passport / Driver Licence Number</Label>
                <HelpText>(As per Passport or Driver Licence)</HelpText>
                <TextInput
                    value={data.id_number}
                    onChange={(e) => setData('id_number', e.target.value)}
                />
                <FieldError msg={err('id_number')} />
            </div>

            {/* visa_status */}
            <div>
                <Label required>What is your current visa status in New Zealand?</Label>
                <RadioGroup
                    name="visa_status"
                    options={visaOptions}
                    value={data.visa_status}
                    onChange={(v) => setData('visa_status', v)}
                />
                <FieldError msg={err('visa_status')} />
                {data.visa_status === 'Other' && (
                    <div className="mt-3">
                        <Label required>Please specify your visa status</Label>
                        <TextInput
                            value={data.visa_status_other}
                            onChange={(e) => setData('visa_status_other', e.target.value)}
                            placeholder="Specify visa status..."
                        />
                        <FieldError msg={err('visa_status_other')} />
                    </div>
                )}
            </div>

            {/* nationality */}
            <div>
                <Label required>Nationality</Label>
                <RadioGroup
                    name="nationality"
                    options={nationalityOptions}
                    value={data.nationality}
                    onChange={(v) => setData('nationality', v)}
                />
                <FieldError msg={err('nationality')} />
                {data.nationality === 'Other' && (
                    <div className="mt-3">
                        <Label required>Please specify your nationality</Label>
                        <TextInput
                            value={data.nationality_other}
                            onChange={(e) => setData('nationality_other', e.target.value)}
                            placeholder="Specify nationality..."
                        />
                        <FieldError msg={err('nationality_other')} />
                    </div>
                )}
            </div>

            {/* preferred_name */}
            <div>
                <Label required>Preferred Name</Label>
                <TextInput
                    value={data.preferred_name}
                    onChange={(e) => setData('preferred_name', e.target.value)}
                />
                <FieldError msg={err('preferred_name')} />
            </div>

            {/* email */}
            <div>
                <Label required>Email Address</Label>
                <TextInput
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    placeholder="you@example.com"
                />
                <FieldError msg={err('email')} />
            </div>

            {/* mobile */}
            <div>
                <Label required>Mobile Number</Label>
                <TextInput
                    value={data.mobile}
                    onChange={(e) => setData('mobile', e.target.value)}
                    placeholder="+64 21 000 0000"
                />
                <FieldError msg={err('mobile')} />
            </div>

            {/* age */}
            <div>
                <Label required>Age</Label>
                <TextInput
                    type="number"
                    min={16}
                    value={data.age}
                    onChange={(e) => setData('age', e.target.value)}
                    placeholder="e.g. 25"
                />
                <FieldError msg={err('age')} />
            </div>
        </div>
    );
}

function Section2({ data, setData, localErrors, serverErrors, isHot }) {
    const err = (f) => localErrors[f] || serverErrors[f];
    const stayOptions = ['3 Months', '6 months', '12 months', '12+ months'];

    return (
        <div className="space-y-6">
            {/* property_interested — HOT only */}
            {isHot && (
                <div>
                    <Label required>Which property/accommodation are you interested in viewing?</Label>
                    <HelpText>
                        If you are unsure which property to select, please view our current available Auckland
                        accommodation listings first.{" "}
                        <a
                            href="/accommodation"
                            className="underline"
                            style={{ color: PRIMARY }}
                        >
                            Click here to view available rooms for rent
                        </a>
                    </HelpText>
                    <TextInput
                        value={data.property_interested}
                        readOnly={Boolean(data.property_id)}
                        onChange={(e) => setData('property_interested', e.target.value)}
                    />
                    <FieldError msg={err('property_interested')} />
                </div>
            )}

            {/* room_type_interest */}
            <div>
                <Label required>Room type Interested in</Label>
                <RadioGroup
                    name="room_type_interest"
                    options={ROOM_OPTIONS}
                    value={data.room_type_interest}
                    onChange={(v) => setData('room_type_interest', v)}
                />
                <FieldError msg={err('room_type_interest')} />
            </div>

            {/* tenancy_start_date */}
            <div>
                <Label required>What is your preferred tenancy start date?</Label>
                <TextInput
                    type="date"
                    value={data.tenancy_start_date}
                    onChange={(e) => setData('tenancy_start_date', e.target.value)}
                />
                <FieldError msg={err('tenancy_start_date')} />
            </div>

            {/* stay_duration */}
            <div>
                <Label required>How long are you planning to stay?</Label>
                <RadioGroup
                    name="stay_duration"
                    options={stayOptions}
                    value={data.stay_duration}
                    onChange={(v) => setData('stay_duration', v)}
                />
                <FieldError msg={err('stay_duration')} />
            </div>
        </div>
    );
}

function Section3({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];
    const occupantOptions = ['Just me', 'Me and My Partner'];

    return (
        <div className="space-y-6">
            {/* occupants */}
            <div>
                <Label required>How many people will occupy the room?</Label>
                <RadioGroup
                    name="occupants"
                    options={occupantOptions}
                    value={data.occupants}
                    onChange={(v) => setData('occupants', v)}
                />
                <FieldError msg={err('occupants')} />
            </div>

            {/* occupant_ages */}
            <div>
                <Label required>Please list the age(s) of all occupants</Label>
                <TextInput
                    value={data.occupant_ages}
                    onChange={(e) => setData('occupant_ages', e.target.value)}
                    placeholder="e.g. 28, 26"
                />
                <FieldError msg={err('occupant_ages')} />
            </div>

            {/* has_children */}
            <div>
                <Label required>Any children?</Label>
                <YesNoRadio
                    name="has_children"
                    value={data.has_children}
                    onChange={(v) => setData('has_children', v)}
                />
                <FieldError msg={err('has_children')} />
            </div>

            {/* children_ages — conditional */}
            {data.has_children === true && (
                <div>
                    <Label required>If yes, how old is/are the child(ren)?</Label>
                    <TextInput
                        value={data.children_ages}
                        onChange={(e) => setData('children_ages', e.target.value)}
                        placeholder="e.g. 3, 7"
                    />
                    <FieldError msg={err('children_ages')} />
                </div>
            )}

            {/* has_pets */}
            <div>
                <Label required>Any pets?</Label>
                <YesNoRadio
                    name="has_pets"
                    value={data.has_pets}
                    onChange={(v) => setData('has_pets', v)}
                />
                <FieldError msg={err('has_pets')} />
            </div>

            {/* pet_details — conditional, optional */}
            {data.has_pets === true && (
                <div>
                    <Label>If yes, please specify</Label>
                    <TextInput
                        value={data.pet_details}
                        onChange={(e) => setData('pet_details', e.target.value)}
                        placeholder="e.g. 1 small dog"
                    />
                    <FieldError msg={err('pet_details')} />
                </div>
            )}
        </div>
    );
}

function Section4({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];
    const fundingOptions = ['Employment / Work Income', 'Family Funded', 'Savings', 'Other'];
    const employmentOptions = ['Full-time employment', 'Part-time employment', 'Student', 'Self-employed', 'Other'];

    return (
        <div className="space-y-6">
            {/* rent_funding — optional */}
            <div>
                <Label>How will you primarily fund your rent payments?</Label>
                <RadioGroup
                    name="rent_funding"
                    options={fundingOptions}
                    value={data.rent_funding}
                    onChange={(v) => setData('rent_funding', v)}
                />
                <FieldError msg={err('rent_funding')} />
                {data.rent_funding === 'Other' && (
                    <div className="mt-3">
                        <Label required>Please specify</Label>
                        <TextInput
                            value={data.rent_funding_other}
                            onChange={(e) => setData('rent_funding_other', e.target.value)}
                            placeholder="Specify funding source..."
                        />
                        <FieldError msg={err('rent_funding_other')} />
                    </div>
                )}
            </div>

            {/* employment_status */}
            <div>
                <Label required>Current status</Label>
                <RadioGroup
                    name="employment_status"
                    options={employmentOptions}
                    value={data.employment_status}
                    onChange={(v) => setData('employment_status', v)}
                />
                <FieldError msg={err('employment_status')} />
                {data.employment_status === 'Other' && (
                    <div className="mt-3">
                        <Label required>Please specify</Label>
                        <TextInput
                            value={data.employment_status_other}
                            onChange={(e) => setData('employment_status_other', e.target.value)}
                            placeholder="Specify employment status..."
                        />
                        <FieldError msg={err('employment_status_other')} />
                    </div>
                )}
            </div>
        </div>
    );
}

function Section5({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];
    const livingOptions = ['Renting', 'Boarding', 'Living with family'];

    return (
        <div className="space-y-6">
            {/* current_address */}
            <div>
                <Label required>Current Address</Label>
                <HelpText>Full Address (Street, Suburb, City)</HelpText>
                <TextInput
                    value={data.current_address}
                    onChange={(e) => setData('current_address', e.target.value)}
                    placeholder="e.g. 12 Sample St, Grey Lynn, Auckland"
                />
                <FieldError msg={err('current_address')} />
            </div>

            {/* has_rented_before */}
            <div>
                <Label required>Have you rented before?</Label>
                <YesNoRadio
                    name="has_rented_before"
                    value={data.has_rented_before}
                    onChange={(v) => setData('has_rented_before', v)}
                />
                <FieldError msg={err('has_rented_before')} />
            </div>

            {/* current_address_duration */}
            <div>
                <Label required>How long have you lived at your current address?</Label>
                <TextInput
                    value={data.current_address_duration}
                    onChange={(e) => setData('current_address_duration', e.target.value)}
                    placeholder="e.g. 2 years"
                />
                <FieldError msg={err('current_address_duration')} />
            </div>

            {/* living_situation */}
            <div>
                <Label required>Current living situation</Label>
                <RadioGroup
                    name="living_situation"
                    options={livingOptions}
                    value={data.living_situation}
                    onChange={(v) => setData('living_situation', v)}
                />
                <FieldError msg={err('living_situation')} />
            </div>

            {/* reason_for_moving */}
            <div>
                <Label required>Reason for moving</Label>
                <Textarea
                    value={data.reason_for_moving}
                    onChange={(e) => setData('reason_for_moving', e.target.value)}
                    placeholder="Tell us why you are looking to move..."
                />
                <FieldError msg={err('reason_for_moving')} />
            </div>
        </div>
    );
}

function Section6({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];
    const drinkOptions = ['No', 'Socially', 'Regularly'];
    const workHoursOptions = ['Day', 'Night', 'Shift Variables'];

    return (
        <div className="space-y-6">
            {/* smokes_or_vapes */}
            <div>
                <Label required>Do you smoke or vape?</Label>
                <YesNoRadio
                    name="smokes_or_vapes"
                    value={data.smokes_or_vapes}
                    onChange={(v) => setData('smokes_or_vapes', v)}
                />
                <FieldError msg={err('smokes_or_vapes')} />
            </div>

            {/* drinks_alcohol */}
            <div>
                <Label required>Do you drink alcohol?</Label>
                <RadioGroup
                    name="drinks_alcohol"
                    options={drinkOptions}
                    value={data.drinks_alcohol}
                    onChange={(v) => setData('drinks_alcohol', v)}
                />
                <FieldError msg={err('drinks_alcohol')} />
            </div>

            {/* work_hours */}
            <div>
                <Label required>Work hours</Label>
                <RadioGroup
                    name="work_hours"
                    options={workHoursOptions}
                    value={data.work_hours}
                    onChange={(v) => setData('work_hours', v)}
                />
                <FieldError msg={err('work_hours')} />
            </div>

            {/* flatmate_description */}
            <div>
                <Label required>How would you describe yourself as a flatmate?</Label>
                <Textarea
                    value={data.flatmate_description}
                    onChange={(e) => setData('flatmate_description', e.target.value)}
                    placeholder="e.g. I'm tidy, respectful of shared spaces, and quiet in the evenings..."
                />
                <FieldError msg={err('flatmate_description')} />
            </div>
        </div>
    );
}

function Section7({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];
    const viewingTimeOptions = ['Weekdays', 'Weekends', 'Flexible'];

    return (
        <div className="space-y-6">
            {/* viewing_available_7days */}
            <div>
                <Label required>Are you available for a viewing within the next 7 days?</Label>
                <YesNoRadio
                    name="viewing_available_7days"
                    value={data.viewing_available_7days}
                    onChange={(v) => setData('viewing_available_7days', v)}
                />
                <FieldError msg={err('viewing_available_7days')} />
            </div>

            {/* preferred_viewing_time */}
            <div>
                <Label required>Preferred viewing time</Label>
                <RadioGroup
                    name="preferred_viewing_time"
                    options={viewingTimeOptions}
                    value={data.preferred_viewing_time}
                    onChange={(v) => setData('preferred_viewing_time', v)}
                />
                <FieldError msg={err('preferred_viewing_time')} />
            </div>
        </div>
    );
}

function Section8({ data, setData, localErrors, serverErrors }) {
    const err = (f) => localErrors[f] || serverErrors[f];

    return (
        <div className="space-y-6">
            {/* Disclaimer box */}
            <div className="rounded-xl bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600 leading-relaxed">
                Submitting this form does not guarantee a room. Approved applicants will be contacted by Exalt
                Property Management LTD via the email address or mobile number supplied. Please ensure all
                information provided is correct.
            </div>

            {/* confirm_accurate */}
            <div>
                <p className="text-sm text-gray-700 mb-2">
                    I confirm that the information provided is true and accurate.
                </p>
                <label className="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        checked={data.confirm_accurate}
                        onChange={(e) => setData('confirm_accurate', e.target.checked)}
                        className="w-4 h-4 accent-[#1F5A8B] rounded"
                    />
                    <span className="text-sm font-medium text-gray-800">I agree</span>
                </label>
                <FieldError msg={err('confirm_accurate')} />
            </div>

            {/* consent_collection */}
            <div>
                <p className="text-sm text-gray-700 mb-2">
                    I consent to Exalt Property Management collecting my information for tenancy assessment purposes.
                </p>
                <label className="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        checked={data.consent_collection}
                        onChange={(e) => setData('consent_collection', e.target.checked)}
                        className="w-4 h-4 accent-[#1F5A8B] rounded"
                    />
                    <span className="text-sm font-medium text-gray-800">I agree</span>
                </label>
                <FieldError msg={err('consent_collection')} />
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export default function ExpressionOfInterest({ variant = "cold", propertyPrefill = null, listingId = null }) {
    const isHot = variant === "hot";
    const { flash } = usePage().props;

    const [currentStep, setCurrentStep] = useState(0); // 0-indexed
    const [localErrors, setLocalErrors] = useState({});

    const { data, setData, post, processing, errors: serverErrors, reset } = useForm({
        // Section 1 — Personal
        full_legal_name: '',
        id_number: '',
        visa_status: '',
        visa_status_other: '',
        nationality: '',
        nationality_other: '',
        preferred_name: '',
        email: '',
        mobile: '',
        age: '',
        // Section 2 — Property & Room Interest
        property_interested: propertyPrefill ?? "",
        property_id: listingId,
        room_type_interest: '',
        tenancy_start_date: '',
        stay_duration: '',
        // Section 3 — Occupancy
        occupants: '',
        occupant_ages: '',
        has_children: null,
        children_ages: '',
        has_pets: null,
        pet_details: '',
        // Section 4 — Employment / Study
        rent_funding: '',
        rent_funding_other: '',
        employment_status: '',
        employment_status_other: '',
        // Section 5 — Rental Background
        current_address: '',
        has_rented_before: null,
        current_address_duration: '',
        living_situation: '',
        reason_for_moving: '',
        // Section 6 — Lifestyle & Compatibility
        smokes_or_vapes: null,
        drinks_alcohol: '',
        work_hours: '',
        flatmate_description: '',
        // Section 7 — Viewing Availability
        viewing_available_7days: null,
        preferred_viewing_time: '',
        // Section 8 — Declaration & Consent
        confirm_accurate: false,
        consent_collection: false,
    });

    const totalSteps = SECTIONS.length;
    const progressPercent = Math.round(((currentStep + 1) / totalSteps) * 100);

    function handleNext() {
        const errs = validateSection(currentStep, data, isHot);
        if (Object.keys(errs).length > 0) {
            setLocalErrors(errs);
            return;
        }
        setLocalErrors({});
        setCurrentStep((s) => Math.min(s + 1, totalSteps - 1));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function handleBack() {
        setLocalErrors({});
        setCurrentStep((s) => Math.max(s - 1, 0));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function handleSubmit(e) {
        e.preventDefault();
        const errs = validateSection(currentStep, data, isHot);
        if (Object.keys(errs).length > 0) {
            setLocalErrors(errs);
            return;
        }
        setLocalErrors({});
        post(isHot ? '/accommodation/expression-of-interest-hot' : '/accommodation/expression-of-interest-cold', {
            onError: (errBag) => {
                // Server validation can reject a field on an earlier section,
                // which isn't rendered. Jump to the earliest section with an
                // error so the message is visible instead of Submit silently
                // doing nothing.
                const sections = Object.keys(errBag)
                    .map((f) => FIELD_SECTION[f])
                    .filter((s) => s !== undefined);
                if (sections.length > 0) {
                    setCurrentStep(Math.min(...sections));
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },
        });
    }

    const sectionProps = { data, setData, localErrors, serverErrors };

    const sectionComponents = [
        <Section1 {...sectionProps} />,
        <Section2 {...sectionProps} isHot={isHot} />,
        <Section3 {...sectionProps} />,
        <Section4 {...sectionProps} />,
        <Section5 {...sectionProps} />,
        <Section6 {...sectionProps} />,
        <Section7 {...sectionProps} />,
        <Section8 {...sectionProps} />,
    ];

    // ---------------------------------------------------------------------------
    // Thank-you screen
    // ---------------------------------------------------------------------------
    if (flash?.success) {
        return (
            <div className="bg-[#fafafa] min-h-screen font-urbanist text-black flex flex-col">
                <Navbar />
                <div className="flex-1 flex items-center justify-center px-4 py-12">
                    <div className="w-full max-w-xl">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-10 text-center">
                        <div
                            className="w-14 h-14 sm:w-16 sm:h-16 rounded-full flex items-center justify-center mx-auto mb-5 sm:mb-6"
                            style={{ backgroundColor: `${PRIMARY}15` }}
                        >
                            <svg
                                className="w-7 h-7 sm:w-8 sm:h-8"
                                style={{ color: PRIMARY }}
                                fill="none"
                                stroke="currentColor"
                                strokeWidth={2}
                                viewBox="0 0 24 24"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h1 className="text-xl sm:text-2xl font-bold mb-3 sm:mb-4 text-gray-900 text-balance">
                            Thank you for registering with Exalt Property Management LTD.
                        </h1>
                        <p className="text-gray-500 text-sm leading-relaxed mb-8">
                            Submitting this form does not guarantee a room. Approved applicants will be contacted
                            by Exalt Property Management LTD via the email address or mobile number supplied.
                            Please ensure all information provided is correct.
                        </p>
                        <Link
                            href="/accommodation"
                            className="inline-block w-full sm:w-auto rounded-xl px-6 py-3 text-sm font-semibold text-white transition hover:opacity-90"
                            style={{ backgroundColor: PRIMARY }}
                        >
                            Back to accommodation
                        </Link>
                    </div>
                    </div>
                </div>
            </div>
        );
    }

    // ---------------------------------------------------------------------------
    // Wizard form
    // ---------------------------------------------------------------------------
    return (
        <div className="bg-[#fafafa] min-h-screen font-urbanist text-black">
            <Navbar />

            {/* Page header */}
            <div className="mx-auto w-full max-w-3xl px-4 pt-16 pb-4">
                <h1 className="text-2xl md:text-3xl font-bold text-gray-900 mb-3">
                    Exalt Property Management LTD – Expression of Interest ({isHot ? "HOT" : "COLD"})
                </h1>
                {isHot ? (
                    <div className="text-gray-500 text-sm leading-relaxed space-y-2">
                        <p>Thank you for your interest in renting with Exalt Property Management.</p>
                        <p>
                            Please do not continue with this form unless you already know which property has a room
                            available that you are interested in viewing. This registration form is intended for
                            applicants who are ready to proceed with a specific room viewing.
                        </p>
                        <p>
                            Once submitted, our team will review your details and send a direct calendar booking
                            link for your selected room/property if suitable.
                        </p>
                        <p>
                            If you have not yet viewed our available Auckland accommodation listings and rooms for
                            rent, please check our current available rooms first before completing this form:{" "}
                            <a
                                href="/accommodation"
                                className="font-semibold underline"
                                style={{ color: PRIMARY }}
                            >
                                View available rooms →
                            </a>
                        </p>
                    </div>
                ) : (
                    <p className="text-gray-500 text-sm leading-relaxed">
                        Thank you for your interest in renting with Exalt Property Management. Please complete this
                        registration form so we can assess suitability and arrange a viewing if applicable.
                    </p>
                )}
            </div>

            {/* Wizard card */}
            <div className="mx-auto w-full max-w-3xl px-4 pb-24">
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

                    {/* Progress header */}
                    <div className="px-6 md:px-10 pt-8 pb-6 border-b border-gray-100">
                        <div className="flex items-center justify-between mb-3">
                            <span className="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                                Section {currentStep + 1} of {totalSteps}
                            </span>
                            <span className="text-xs font-semibold" style={{ color: PRIMARY }}>
                                {progressPercent}%
                            </span>
                        </div>
                        {/* Progress bar */}
                        <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div
                                className="h-full rounded-full transition-all duration-500"
                                style={{ width: `${progressPercent}%`, backgroundColor: PRIMARY }}
                            />
                        </div>
                        {/* Section pill row */}
                        <div className="mt-4 flex gap-1 overflow-x-auto pb-1 scrollbar-none">
                            {SECTIONS.map((name, idx) => (
                                <div
                                    key={name}
                                    className={`shrink-0 rounded-full px-3 py-1 text-xs font-medium transition ${
                                        idx === currentStep
                                            ? 'text-white'
                                            : idx < currentStep
                                            ? 'bg-gray-100 text-gray-500'
                                            : 'bg-gray-50 text-gray-300'
                                    }`}
                                    style={idx === currentStep ? { backgroundColor: PRIMARY } : {}}
                                >
                                    {idx + 1}. {name}
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Section content */}
                    <form onSubmit={handleSubmit}>
                        <div className="px-6 md:px-10 py-8">
                            {/* Section heading */}
                            <h2 className="text-lg font-bold text-gray-900 mb-6">
                                SECTION {currentStep + 1}: {SECTIONS[currentStep].toUpperCase()}
                            </h2>

                            {sectionComponents[currentStep]}
                        </div>

                        {/* Navigation */}
                        <div className="px-6 md:px-10 pb-8 flex items-center justify-between gap-4">
                            {currentStep > 0 ? (
                                <button
                                    type="button"
                                    onClick={handleBack}
                                    disabled={processing}
                                    className="rounded-xl border border-gray-200 px-6 py-2.5 text-sm font-semibold text-gray-700 hover:border-gray-300 transition"
                                >
                                    Back
                                </button>
                            ) : (
                                <div />
                            )}

                            {currentStep < totalSteps - 1 ? (
                                <button
                                    type="button"
                                    onClick={handleNext}
                                    className="rounded-xl px-6 py-2.5 text-sm font-semibold text-white transition hover:opacity-90"
                                    style={{ backgroundColor: PRIMARY }}
                                >
                                    Next
                                </button>
                            ) : (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl px-8 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
                                    style={{ backgroundColor: PRIMARY }}
                                >
                                    {processing ? 'Submitting…' : 'Submit'}
                                </button>
                            )}
                        </div>
                    </form>
                </div>
            </div>

            <Footer />
        </div>
    );
}
