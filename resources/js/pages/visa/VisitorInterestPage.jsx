import React, { useState, useEffect } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import IntakeFormShell from '@/components/visa/IntakeFormShell';
import IntakeConfirmModal from '@/components/visa/IntakeConfirmModal';
import IntakeSuccessModal from '@/components/visa/IntakeSuccessModal';
import IntakeTermsStep from '@/components/visa/IntakeTermsStep';
import IntakeDocumentsStep from '@/components/visa/IntakeDocumentsStep';
import {
    TextField, TextareaField, DateField, SelectField, YesNoField, FieldGrid, SectionTitle,
} from '@/components/visa/IntakeFields';

const PARTNERSHIP = ['Single', 'Married', 'Partnership', 'Divorced', 'Widowed', 'Separated'];
const STAY_LENGTH = ['Less than 59 days', 'More than 6 months', 'More than 12 months', 'More than 24 months'];
const DRAFT_KEY = 'epathways_visitor_intake_draft';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Every step in the form. The submit sweep and the error-clearing effect
// both walk 1..TOTAL_STEPS, so a validator on a step beyond this would
// silently never run.
const TOTAL_STEPS = 9;

const FIELD_TO_STEP = {
    terms_accepted: 1,
    family_name: 2, first_name: 2, other_names: 2, gender: 2, dob: 2,
    country_of_birth: 2, place_of_birth: 2, country_of_citizenship: 2,
    passport_number: 2, passport_expiry: 2, other_citizenships: 2, national_id: 2,
    partnership_status: 2, current_address: 2, town_city: 2, region: 2,
    postcode: 2, phone: 2, email: 2,
    current_country: 3, previous_nz_visa: 3, previous_nzeta: 3, australian_pr: 3,
    travelled_nz: 3, last_nz_departure: 3, over_24_months: 3,
    character_convicted: 4, character_deported: 4, character_investigation: 4,
    character_visa_refused: 4, lived_other_country_5y: 4, previous_police_certificate: 4,
    health_tb: 4, health_renal: 4, health_hospital: 4, health_residential: 4, health_pregnant: 4,
    previous_xray: 4, previous_inz1007: 4, inz_requested_medical: 4,
    has_tertiary: 5, qualification_duration: 5, qualification_name: 5,
    qualification_completed: 5, education_provider: 5, currently_working: 5,
    current_job_title: 5, current_job_duties: 5, current_job_start: 5, current_job_finish: 5,
    current_job_country: 5, current_job_region: 5, current_employer_name: 5,
    current_employer_address: 5, current_employer_phone: 5, current_employer_email: 5,
    purpose_of_visit: 6, intended_stay_length: 6, intended_from: 6, intended_to: 6,
    multi_entry_plans: 6, has_leave_permit: 6,
    travel_funds_description: 7, can_provide_statements: 7,
    has_other_assets: 7, other_assets_details: 7,
    declaration_accepted: 9, signature_name: 9, signature_date: 9,
};

const FIELD_LABELS = {
    family_name: 'Family name', first_name: 'First name', dob: 'Date of birth',
    email: 'Email', phone: 'Phone', country_of_citizenship: 'Country of citizenship',
    passport_number: 'Passport number', purpose_of_visit: 'Purpose of visit',
    intended_stay_length: 'Stay length', intended_from: 'Arrival date',
    intended_to: 'Departure date', travel_funds_description: 'Travel funds',
    declaration_accepted: 'Declaration',
};

function loadDraft() {
    try {
        const raw = window.localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

export default function VisitorInterestPage() {
    const draft = loadDraft();
    const { data, setData, post, processing, errors: serverErrors } = useForm({
        family_name: '', first_name: '', other_names: '', gender: '', dob: '',
        country_of_birth: '', place_of_birth: '', country_of_citizenship: '',
        passport_number: '', passport_expiry: '', other_citizenships: '',
        national_id: '', partnership_status: '',
        birth_certificate_available: '', birth_certificate_reason: '', face_image_consent: false,
        current_address: '', town_city: '', region: '', postcode: '',
        phone: '', email: '',

        current_country: '', previous_nz_visa: '', previous_nzeta: '',
        australian_pr: '', travelled_nz: '', last_nz_departure: '', over_24_months: '',

        character_convicted: '', character_deported: '', character_investigation: '',
        character_visa_refused: '', lived_other_country_5y: '', previous_police_certificate: '',

        health_tb: '', health_renal: '', health_hospital: '', health_residential: '', health_pregnant: '',
        previous_xray: '', previous_inz1007: '', inz_requested_medical: '',

        has_tertiary: '', qualification_duration: '', qualification_name: '',
        qualification_completed: '', education_provider: '',

        currently_working: '', current_job_title: '', current_job_duties: '',
        current_job_start: '', current_job_finish: '', current_job_country: '', current_job_region: '',
        current_employer_name: '', current_employer_address: '',
        current_employer_phone: '', current_employer_email: '', job_skills_level: '',

        has_nz_contacts: '',
        military_compulsory: '', military_undertaken: '',
        travelled_internationally: '',

        purpose_of_visit: '', intended_stay_length: '',
        intended_from: '', intended_to: '', multi_entry_plans: '', has_leave_permit: '',

        travel_funds_description: '', can_provide_statements: '',
        has_other_assets: '', other_assets_details: '',

        declaration_accepted: false, signature_name: '', signature_date: '',
        // Shared document tab — files can't serialise to the local draft.
        documents: {}, document_files: {},
        // Privacy & Terms — gated by step 1.
        terms_accepted: false,
        ...(draft || {}),
    });

    const [step, setStep] = useState(1);
    const [localErrors, setLocalErrors] = useState({});
    const [showConfirm, setShowConfirm] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);
    const [visitedSteps, setVisitedSteps] = useState(() => new Set([1]));

    // Persistent post-submit modal driven by `intake_submitted` flash.
    const { flash } = usePage().props;
    useEffect(() => {
        if (flash?.intake_submitted) {
            setShowSuccess(true);
            try { window.localStorage.removeItem(DRAFT_KEY); } catch {}
        }
    }, [flash?.intake_submitted]);
    useEffect(() => {
        setVisitedSteps((prev) => {
            if (prev.has(step)) return prev;
            const next = new Set(prev);
            next.add(step);
            return next;
        });
    }, [step]);
    const errors = { ...localErrors, ...serverErrors };

    useEffect(() => {
        if (draft) toast.success('Restored your saved draft.', { duration: 3000 });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const validateStep = (n) => {
        const errs = {};
        switch (n) {
            case 1:
                if (!data.terms_accepted) errs.terms_accepted = 'Please accept the terms to continue';
                break;
            case 2:
                if (!data.family_name?.trim()) errs.family_name = 'Family name is required';
                if (!data.first_name?.trim()) errs.first_name = 'First name is required';
                if (!data.dob) errs.dob = 'Date of birth is required';
                if (!data.email?.trim()) errs.email = 'Email is required';
                else if (!EMAIL_RE.test(data.email)) errs.email = 'Enter a valid email address';
                if (!data.phone?.trim()) errs.phone = 'Contact number is required';
                if (!data.country_of_citizenship?.trim()) errs.country_of_citizenship = 'Country of citizenship is required';
                if (!data.passport_number?.trim()) errs.passport_number = 'Passport number is required';
                if (data.birth_certificate_available === 'No' && !data.birth_certificate_reason?.trim()) errs.birth_certificate_reason = 'Please tell us why';
                break;
            case 3:
                if (!data.travelled_nz) errs.travelled_nz = 'Please answer';
                if (!data.over_24_months) errs.over_24_months = 'Please answer';
                break;
            case 4:
                ['character_convicted','character_deported','character_investigation','character_visa_refused','lived_other_country_5y'].forEach((k) => {
                    if (!data[k]) errs[k] = 'Please answer';
                });
                ['health_tb','health_renal','health_hospital','health_residential'].forEach((k) => {
                    if (!data[k]) errs[k] = 'Please answer';
                });
                break;
            case 5:
                if (!data.currently_working) errs.currently_working = 'Please answer';
                break;
            case 6:
                if (!data.purpose_of_visit?.trim()) errs.purpose_of_visit = 'Purpose is required';
                if (!data.intended_stay_length) errs.intended_stay_length = 'Please select';
                if (!data.intended_from) errs.intended_from = 'Arrival date is required';
                if (!data.intended_to) errs.intended_to = 'Departure date is required';
                break;
            case 7:
                if (!data.travel_funds_description?.trim()) errs.travel_funds_description = 'Funds description is required';
                if (!data.can_provide_statements) errs.can_provide_statements = 'Please answer';
                break;
            case 9:
                if (!data.declaration_accepted) errs.declaration_accepted = 'You must accept the declaration to continue';
                break;
        }
        return errs;
    };

    const set = (k) => (v) => setData(k, v);

    const submit = () => {
        const aggregated = {};
        let firstInvalid = null;
        for (let n = 1; n <= TOTAL_STEPS; n++) {
            const errs = validateStep(n);
            if (Object.keys(errs).length && firstInvalid === null) firstInvalid = n;
            Object.assign(aggregated, errs);
        }
        if (firstInvalid !== null) {
            setLocalErrors(aggregated);
            setStep(firstInvalid);
            toast.error(`Some fields in Step ${firstInvalid} need your attention.`);
            return;
        }
        setLocalErrors({});
        setShowConfirm(true);
    };

    const confirmSubmit = () => {
        post('/visitor-interest', {
            onSuccess: () => {
                setShowConfirm(false);
                try { window.localStorage.removeItem(DRAFT_KEY); } catch {}
            },
            onError: (errs) => {
                setShowConfirm(false);
                const badKeys = Object.keys(errs);
                let firstInvalid = null;
                for (const k of badKeys) {
                    const stepNum = FIELD_TO_STEP[k];
                    if (stepNum && (firstInvalid === null || stepNum < firstInvalid)) {
                        firstInvalid = stepNum;
                    }
                }
                if (firstInvalid) setStep(firstInvalid);
                setLocalErrors(errs);
                const labels = badKeys
                    .slice(0, 3)
                    .map((k) => FIELD_LABELS[k] || k.replace(/_/g, ' '))
                    .join(', ');
                const more = badKeys.length > 3 ? ` (+${badKeys.length - 3} more)` : '';
                toast.error(
                    firstInvalid
                        ? `Step ${firstInvalid} needs attention — ${labels}${more}.`
                        : `Please fix: ${labels}${more}.`
                );
            },
        });
    };

    useEffect(() => {
        if (Object.keys(localErrors).length === 0) return;
        const fresh = {};
        for (let n = 1; n <= TOTAL_STEPS; n++) Object.assign(fresh, validateStep(n));
        const next = {};
        let changed = false;
        for (const k of Object.keys(localErrors)) {
            if (fresh[k]) next[k] = fresh[k];
            else changed = true;
        }
        if (changed) setLocalErrors(next);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data]);

    const steps = [
        {
            title: 'Terms',
            render: () => (
                <IntakeTermsStep
                    visaLabel="Visitor Visa (GVV)"
                    accepted={data.terms_accepted}
                    onAccept={set('terms_accepted')}
                    error={errors.terms_accepted}
                />
            ),
        },
        {
            title: 'Identity',
            render: () => (
                <>
                    <SectionTitle title="Identity" subtitle="Write N/A if a field doesn't apply." />
                    <FieldGrid>
                        <TextField label="Family name" value={data.family_name} onChange={set('family_name')} required error={errors.family_name} />
                        <TextField label="First name" value={data.first_name} onChange={set('first_name')} required error={errors.first_name} />
                        <TextField label="Other names used" value={data.other_names} onChange={set('other_names')} />
                        <SelectField label="Gender" value={data.gender} onChange={set('gender')} options={['Female', 'Male', 'Other', 'Prefer not to say']} />
                        <DateField label="Date of birth" value={data.dob} onChange={set('dob')} required error={errors.dob} />
                        <TextField label="Country of birth" value={data.country_of_birth} onChange={set('country_of_birth')} />
                        <TextField label="Place of birth (town/city)" value={data.place_of_birth} onChange={set('place_of_birth')} />
                        <TextField label="Country of citizenship" value={data.country_of_citizenship} onChange={set('country_of_citizenship')} required error={errors.country_of_citizenship} />
                        <TextField label="Passport number" value={data.passport_number} onChange={set('passport_number')} required error={errors.passport_number} />
                        <DateField label="Passport expiry" value={data.passport_expiry} onChange={set('passport_expiry')} />
                        <TextField label="Other citizenships" value={data.other_citizenships} onChange={set('other_citizenships')} />
                        <TextField label="National ID" value={data.national_id} onChange={set('national_id')} />
                        <SelectField label="Partnership status" value={data.partnership_status} onChange={set('partnership_status')} options={PARTNERSHIP} />
                    </FieldGrid>
                    <div className="mt-4">
                        <TextareaField label="Current physical address" value={data.current_address} onChange={set('current_address')} />
                    </div>
                    <FieldGrid>
                        <TextField label="Town / City" value={data.town_city} onChange={set('town_city')} />
                        <TextField label="Region" value={data.region} onChange={set('region')} />
                        <TextField label="Post code" value={data.postcode} onChange={set('postcode')} />
                        <TextField label="Mobile number" value={data.phone} onChange={set('phone')} required hint="Include country code" error={errors.phone} />
                        <TextField label="Email address" type="email" value={data.email} onChange={set('email')} required error={errors.email} />
                    </FieldGrid>
                    <div className="mt-4 space-y-4">
                        <YesNoField label="Do you have a birth certificate you can provide at the time of processing your visa?" value={data.birth_certificate_available} onChange={set('birth_certificate_available')} error={errors.birth_certificate_available} />
                        {data.birth_certificate_available === 'No' && (
                            <TextField label="If no, please tell us why" value={data.birth_certificate_reason} onChange={set('birth_certificate_reason')} error={errors.birth_certificate_reason} />
                        )}
                        <label className="flex items-center gap-3 p-4 rounded-xl border border-gray-200 bg-white cursor-pointer">
                            <input type="checkbox" className="w-5 h-5 rounded border-gray-300 text-[#00A693] focus:ring-[#00A693]" checked={!!data.face_image_consent} onChange={(e) => set('face_image_consent')(e.target.checked)} />
                            <span className="text-sm text-gray-600">I understand you will ask me for a face image (JPG format) at the time of processing my visa.</span>
                        </label>
                    </div>
                </>
            ),
        },
        {
            title: 'NZ Immigration',
            render: () => (
                <>
                    <SectionTitle title="New Zealand Immigration History" />
                    <FieldGrid>
                        <TextField label="Country you'll be in when this application is submitted" value={data.current_country} onChange={set('current_country')} />
                        <YesNoField label="Previously applied for a NZ visa?" value={data.previous_nz_visa} onChange={set('previous_nz_visa')} />
                        <YesNoField label="Previously requested an NZeTA?" value={data.previous_nzeta} onChange={set('previous_nzeta')} />
                        <YesNoField label="Hold an Australian Permanent Resident Visa?" value={data.australian_pr} onChange={set('australian_pr')} />
                        <YesNoField label="Have you ever travelled to NZ?" value={data.travelled_nz} onChange={set('travelled_nz')} error={errors.travelled_nz} />
                        <DateField label="If Yes, when did you last leave NZ?" value={data.last_nz_departure} onChange={set('last_nz_departure')} />
                        <YesNoField label="Will total time in NZ equal 24 months or more?" value={data.over_24_months} onChange={set('over_24_months')} error={errors.over_24_months} />
                    </FieldGrid>
                </>
            ),
        },
        {
            title: 'Character & Health',
            render: () => (
                <>
                    <SectionTitle title="Character Details" />
                    <FieldGrid>
                        <YesNoField label="Convicted of any offence (incl. driving)?" value={data.character_convicted} onChange={set('character_convicted')} error={errors.character_convicted} />
                        <YesNoField label="Ever expelled, deported or refused entry?" value={data.character_deported} onChange={set('character_deported')} error={errors.character_deported} />
                        <YesNoField label="Under investigation or facing charges anywhere?" value={data.character_investigation} onChange={set('character_investigation')} error={errors.character_investigation} />
                        <YesNoField label="Ever refused a visa by any country (excl. NZ)?" value={data.character_visa_refused} onChange={set('character_visa_refused')} error={errors.character_visa_refused} />
                        <YesNoField label="Lived in any country 5+ yrs since age 17?" value={data.lived_other_country_5y} onChange={set('lived_other_country_5y')} error={errors.lived_other_country_5y} />
                        <YesNoField label="Previously provided a police certificate?" value={data.previous_police_certificate} onChange={set('previous_police_certificate')} />
                    </FieldGrid>
                    <div className="mt-8">
                        <SectionTitle title="Health Details" />
                        <FieldGrid>
                            <YesNoField label="Do you have tuberculosis?" value={data.health_tb} onChange={set('health_tb')} error={errors.health_tb} />
                            <YesNoField label="Renal dialysis?" value={data.health_renal} onChange={set('health_renal')} error={errors.health_renal} />
                            <YesNoField label="Hospital care?" value={data.health_hospital} onChange={set('health_hospital')} error={errors.health_hospital} />
                            <YesNoField label="Residential care?" value={data.health_residential} onChange={set('health_residential')} error={errors.health_residential} />
                            <YesNoField label="Are you pregnant?" value={data.health_pregnant} onChange={set('health_pregnant')} />
                            <YesNoField label="Previously provided chest X-ray?" value={data.previous_xray} onChange={set('previous_xray')} />
                            <YesNoField label="Previously provided General Medical (INZ 1007)?" value={data.previous_inz1007} onChange={set('previous_inz1007')} />
                            <YesNoField label="Did INZ request medical info last time?" value={data.inz_requested_medical} onChange={set('inz_requested_medical')} />
                        </FieldGrid>
                    </div>
                </>
            ),
        },
        {
            title: 'Education & Work',
            render: () => (
                <>
                    <SectionTitle title="Education Details (highest qualification)" />
                    <FieldGrid>
                        <YesNoField label="Any tertiary education?" value={data.has_tertiary} onChange={set('has_tertiary')} />
                        <TextField label="Duration of study" value={data.qualification_duration} onChange={set('qualification_duration')} hint="e.g. 2018 / 03 — 2022 / 11" />
                        <TextField label="Qualification and major" value={data.qualification_name} onChange={set('qualification_name')} />
                        <YesNoField label="Qualification completed?" value={data.qualification_completed} onChange={set('qualification_completed')} />
                        <TextField label="Education provider (name + location)" value={data.education_provider} onChange={set('education_provider')} />
                    </FieldGrid>
                    <div className="mt-8">
                        <SectionTitle title="Current / Previous Employment" />
                        <FieldGrid>
                            <YesNoField label="Currently working?" value={data.currently_working} onChange={set('currently_working')} error={errors.currently_working} />
                            <TextField label="Job position" value={data.current_job_title} onChange={set('current_job_title')} />
                            <DateField label="Start date" value={data.current_job_start} onChange={set('current_job_start')} />
                            <TextField label="Finish date" value={data.current_job_finish} onChange={set('current_job_finish')} hint='Or "present"' />
                            <TextField label="Country of work" value={data.current_job_country} onChange={set('current_job_country')} />
                            <TextField label="Region of work" value={data.current_job_region} onChange={set('current_job_region')} />
                            <TextField label="Organisation name" value={data.current_employer_name} onChange={set('current_employer_name')} />
                            <TextField label="Employer phone" value={data.current_employer_phone} onChange={set('current_employer_phone')} />
                            <TextField label="Employer email" type="email" value={data.current_employer_email} onChange={set('current_employer_email')} />
                            <SelectField label="Do you know your Job Skills Level as per ANZSCO or NOL?" value={data.job_skills_level} onChange={set('job_skills_level')} options={['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5', 'No']} />
                        </FieldGrid>
                        <div className="mt-4 space-y-4">
                            <TextareaField label="Detailed job duties" value={data.current_job_duties} onChange={set('current_job_duties')} />
                            <TextareaField label="Employer address" value={data.current_employer_address} onChange={set('current_employer_address')} />
                        </div>
                    </div>
                </>
            ),
        },
        {
            title: 'Travel Plan',
            render: () => (
                <>
                    <SectionTitle title="Travel Plan" />
                    <FieldGrid>
                        <TextField label="Purpose of your visit to NZ" value={data.purpose_of_visit} onChange={set('purpose_of_visit')} hint="Holiday, sightseeing, visiting family, etc." required error={errors.purpose_of_visit} />
                        <SelectField label="How long do you intend to stay?" value={data.intended_stay_length} onChange={set('intended_stay_length')} options={STAY_LENGTH} required error={errors.intended_stay_length} />
                        <DateField label="Intended arrival (from)" value={data.intended_from} onChange={set('intended_from')} required error={errors.intended_from} />
                        <DateField label="Intended departure (to)" value={data.intended_to} onChange={set('intended_to')} required error={errors.intended_to} />
                        <YesNoField label="Have you obtained a leave permit from your employer / school?" value={data.has_leave_permit} onChange={set('has_leave_permit')} />
                    </FieldGrid>
                    <div className="mt-4">
                        <TextareaField label="If you prefer a multi-entry visa, list plans for all intended travels" value={data.multi_entry_plans} onChange={set('multi_entry_plans')} />
                    </div>
                </>
            ),
        },
        {
            title: 'Travel Funds',
            render: () => (
                <>
                    <SectionTitle title="Travel Funds and Assets" />
                    <TextareaField label="How much will you provide to cover your intended travel?" value={data.travel_funds_description} onChange={set('travel_funds_description')} placeholder="Currency, amount, and location (e.g., which bank and which country)" error={errors.travel_funds_description} />
                    <FieldGrid>
                        <YesNoField label="Can provide last 6 months bank statements?" value={data.can_provide_statements} onChange={set('can_provide_statements')} error={errors.can_provide_statements} />
                        <YesNoField label="Other assets to support application?" value={data.has_other_assets} onChange={set('has_other_assets')} />
                    </FieldGrid>
                    {data.has_other_assets === 'Yes' && (
                        <div className="mt-4">
                            <TextareaField label="List type and value" value={data.other_assets_details} onChange={set('other_assets_details')} placeholder="e.g. house, vehicle, term deposits, land" />
                        </div>
                    )}
                </>
            ),
        },
        {
            title: 'Documents',
            render: () => (
                <IntakeDocumentsStep data={data} setData={setData} />
            ),
        },
        {
            title: 'Declaration',
            render: () => (
                <>
                    <SectionTitle title="Declaration" />
                    <div className="bg-gray-50/60 border border-gray-100 rounded-2xl p-6 text-sm text-gray-600 leading-relaxed">
                        <p className="font-bold text-[#282728] mb-2">
                            I declare that the information I provide is true, correct, and complete.
                        </p>
                        <p>
                            I understand that I must inform Immigration New Zealand of any relevant change of
                            circumstances. Providing false or misleading information may result in my application
                            being declined or prosecuted under the Immigration Act 2009.
                        </p>
                    </div>
                    <label className="flex items-start gap-3 mt-6 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.declaration_accepted}
                            onChange={(e) => setData('declaration_accepted', e.target.checked)}
                            className="mt-1 w-4 h-4"
                        />
                        <span className="text-sm font-semibold text-[#282728]">
                            I confirm the above and accept the declaration.
                        </span>
                    </label>
                    {errors.declaration_accepted && (
                        <p className="text-[11px] text-red-500 mt-1 ml-7">{errors.declaration_accepted}</p>
                    )}
                    <FieldGrid>
                        <TextField label="Applicant's name (printed)" value={data.signature_name} onChange={set('signature_name')} />
                        <DateField label="Date" value={data.signature_date} onChange={set('signature_date')} />
                    </FieldGrid>
                </>
            ),
        },
    ];

    return (
        <>
            <IntakeFormShell
                title="Visitor Visa (GVV) — Applicant Information"
                visaLabel="GVV — Visitor"
                steps={steps}
                onSubmit={submit}
                processing={processing}
                submitLabel="Submit"
                data={data}
                draftKey={DRAFT_KEY}
                draftEndpoint="/visa-interest/visitor/draft"
                step={step}
                setStep={setStep}
                visitedSteps={visitedSteps}
                validateStep={validateStep}
            />
            <IntakeConfirmModal
                open={showConfirm}
                onClose={() => setShowConfirm(false)}
                onConfirm={confirmSubmit}
                processing={processing}
                visaLabel="Visitor Visa (GVV)"
                submitLabel="Submit intake"
                summaryItems={[
                    ['Name', `${data.first_name} ${data.family_name}`.trim()],
                    ['Email', data.email],
                    ['Phone', data.phone],
                    ['Purpose', data.purpose_of_visit],
                    ['Stay', data.intended_stay_length],
                ]}
            />
            <IntakeSuccessModal
                open={showSuccess}
                onClose={() => setShowSuccess(false)}
                visaLabel={flash?.intake_submitted || 'Visitor Visa (GVV)'}
            />
        </>
    );
}
