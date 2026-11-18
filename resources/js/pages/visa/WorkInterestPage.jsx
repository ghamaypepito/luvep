import React, { useState, useEffect } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import IntakeFormShell from '@/components/visa/IntakeFormShell';
import IntakeConfirmModal from '@/components/visa/IntakeConfirmModal';
import IntakeSuccessModal from '@/components/visa/IntakeSuccessModal';
import IntakeTermsStep from '@/components/visa/IntakeTermsStep';
import {
    Field, TextField, TextareaField, DateField, SelectField, YesNoField, FieldGrid, SectionTitle,
} from '@/components/visa/IntakeFields';

const PARTNERSHIP = ['Single', 'Married', 'Partnership', 'Divorced', 'Widowed', 'Separated'];
const DRAFT_KEY = 'epathways_work_intake_draft';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Server can reject any field, not just the required-by-client ones. This
// map sends the applicant straight to whichever step holds the bad field.
// Step 1 is the shared Privacy & Terms step; all real form fields start at
// step 2 and onward.
// Every step in the form. The submit sweep and the error-clearing effect
// both walk 1..TOTAL_STEPS, so a validator on a step beyond this would
// silently never run.
const TOTAL_STEPS = 9;

const FIELD_TO_STEP = {
    terms_accepted: 1,
    family_name: 2, first_name: 2, other_names: 2, gender: 2, dob: 2,
    country_of_birth: 2, place_of_birth: 2, current_address: 2, email: 2, phone: 2,
    country_of_citizenship: 2, other_citizenships: 2, national_id: 2, partnership_status: 2,
    current_country: 3, previous_nz_visa: 3, previous_nz_visa_details: 3, previous_nzeta: 3,
    australian_pr: 3, travelled_nz: 3, last_nz_departure: 3, over_24_months: 3,
    employer_name: 4, employer_is_family: 4, employer_family_relation: 4, self_employed: 4,
    job_start_date: 4, hourly_rate: 4, supports_dependent_children: 4,
    character_convicted: 5, character_investigation: 5, character_deported: 5,
    character_visa_refused: 5, lived_other_country_5y: 5, lived_other_country_details: 5,
    health_tb: 5, health_renal: 5, health_hospital: 5, health_residential: 5, health_pregnant: 5,
    currently_working: 6, current_job_title: 6, current_job_duties: 6,
    current_job_start: 6, current_job_country: 6, current_job_region: 6,
    current_employer_name: 6, current_employer_address: 6,
    current_employer_phone: 6, current_employer_email: 6,
    military_compulsory: 7, military_undertaken: 7, military_details: 7,
    travelled_internationally: 7,
    declaration_accepted: 9, signature_name: 9, signature_date: 9,
};

const FIELD_LABELS = {
    family_name: 'Family name', first_name: 'First name', dob: 'Date of birth',
    email: 'Email', phone: 'Phone', country_of_citizenship: 'Country of citizenship',
    employer_name: 'Employer name', employer_is_family: 'Employer family question',
    job_start_date: 'Job start date', hourly_rate: 'Hourly rate',
    declaration_accepted: 'Declaration',
};

function loadDraft() {
    try {
        const raw = window.localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

// Document rows for the "Work Interest & Qualifications" tab. Keys are the
// contract with the backend (WorkIntakeController::DOCUMENT_KEYS) — don't rename.
const WORK_INTEREST_DOCS = [
    { key: 'passport', label: 'Passport' },
    { key: 'job_offer', label: 'Job Offer' },
    { key: 'job_token', label: 'Job Token / Job Check' },
    { key: 'employment_contract', label: 'Employment Contract' },
];
const QUALIFICATION_DOCS = [
    { key: 'current_nz_visa', label: 'Current NZ Visa Copy' },
    { key: 'anzsco_skills', label: 'ANZSCO Skills 3, 4, 5 — supporting evidence', hint: 'If your work falls under ANZSCO Skill Level 3, 4 or 5.' },
    { key: 'english_test', label: 'English Proficiency Test Result' },
];

// One upload row — PDF or image. Files are held in form state and posted on
// submit (Inertia switches to multipart automatically when files are present).
function DocRow({ id, label, hint, files = [], onChange }) {
    const chosen = Array.isArray(files) ? files : (files ? [files] : []);
    return (
        <div className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div className="min-w-0">
                <p className="text-sm font-semibold text-[#282728]">{label}</p>
                {hint && <p className="text-[11px] text-gray-500 mt-0.5">{hint}</p>}
                {chosen.length > 0 && (
                    <p className="text-[11px] text-emerald-600 font-medium mt-1 truncate">{chosen.map((f) => f.name).join(', ')}</p>
                )}
            </div>
            <label className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold border border-gray-200 text-[#282728] hover:bg-gray-50 flex-shrink-0 cursor-pointer">
                {chosen.length > 0 ? 'Replace' : 'Upload'}
                <input id={id} type="file" accept="application/pdf,image/*" multiple className="hidden"
                    onChange={(e) => onChange(Array.from(e.target.files || []))} />
            </label>
        </div>
    );
}

export default function WorkInterestPage() {
    const draft = loadDraft();
    const { data, setData, post, processing, errors: serverErrors } = useForm({
        family_name: '', first_name: '', other_names: '', gender: '', dob: '',
        country_of_birth: '', place_of_birth: '', current_address: '',
        email: '', phone: '', country_of_citizenship: '', other_citizenships: '',
        national_id: '', partnership_status: '',
        birth_certificate_available: '', birth_certificate_reason: '', face_image_consent: false,

        current_country: '', previous_nz_visa: '', previous_nz_visa_details: '',
        previous_nzeta: '', australian_pr: '', travelled_nz: '', last_nz_departure: '',
        over_24_months: '',

        employer_name: '', employer_is_family: '', employer_family_relation: '',
        self_employed: '', job_start_date: '', hourly_rate: '',
        supports_dependent_children: '', job_skills_level: '',

        character_convicted: '', character_investigation: '', character_deported: '',
        character_visa_refused: '', lived_other_country_5y: '', lived_other_country_details: '',
        police_certificate_available: '',

        health_tb: '', health_renal: '', health_hospital: '', health_residential: '', health_pregnant: '',
        medical_cert_recent: '', medical_cert_submitted_last: '', medical_cert_type: '',

        currently_working: '', current_job_title: '', current_job_duties: '',
        current_job_start: '', current_job_country: '', current_job_region: '',
        current_employer_name: '', current_employer_address: '',
        current_employer_phone: '', current_employer_email: '',

        previous_roles: [],
        family_members: [],
        has_nz_contacts: '', nz_contacts: [],

        military_compulsory: '', military_undertaken: '', military_details: '',

        travelled_internationally: '', travel_trips: [],

        // Work Interest & Qualifications document tab. `documents` holds answers
        // (e.g. the PCC yes/no); `document_files` holds picked files keyed by doc.
        // Files can't serialise to the local draft, so they're re-selected on reload.
        documents: {}, document_files: {},

        declaration_accepted: false, signature_name: '', signature_date: '',
        // Privacy & Terms — gated by step 1.
        terms_accepted: false,
        // Restored values from any previously-saved local draft. Spread last
        // so they override the empty defaults above.
        ...(draft || {}),
    });

    const [step, setStep] = useState(1);
    const [localErrors, setLocalErrors] = useState({});
    const [showConfirm, setShowConfirm] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);

    // Persistent post-submit modal — driven by the controller's
    // `intake_submitted` flash, not auto-dismissed.
    const { flash } = usePage().props;
    useEffect(() => {
        if (flash?.intake_submitted) {
            setShowSuccess(true);
            try { window.localStorage.removeItem(DRAFT_KEY); } catch {}
        }
    }, [flash?.intake_submitted]);
    // Steps the user has actually opened. The stepper only paints a checkmark
    // for a step the user has visited AND whose required fields validate —
    // matches the resident-intake behaviour where skipping forward leaves the
    // step number visible, not a misleading "done" tick.
    const [visitedSteps, setVisitedSteps] = useState(() => new Set([1]));
    useEffect(() => {
        setVisitedSteps((prev) => {
            if (prev.has(step)) return prev;
            const next = new Set(prev);
            next.add(step);
            return next;
        });
    }, [step]);
    // Merged so existing `error={errors.foo}` calls show whichever is set
    // (server validation messages override our client-side ones).
    const errors = { ...localErrors, ...serverErrors };

    useEffect(() => {
        if (draft) toast.success('Restored your saved draft.', { duration: 3000 });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Per-step required-field validation. Each step returns a {key: msg} map
    // of fields that aren't acceptable yet. Optional/visa-specific fields
    // are intentionally NOT here — submit-time validation only flags fields
    // the applicant has to fill out before we can even create the assessment.
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
                if (data.birth_certificate_available === 'No' && !data.birth_certificate_reason?.trim()) errs.birth_certificate_reason = 'Please tell us why';
                break;
            case 3:
                if (!data.travelled_nz) errs.travelled_nz = 'Please answer';
                if (!data.over_24_months) errs.over_24_months = 'Please answer';
                break;
            case 4:
                if (!data.employer_name?.trim()) errs.employer_name = 'Employer name is required';
                if (!data.employer_is_family) errs.employer_is_family = 'Please answer';
                if (!data.job_start_date) errs.job_start_date = 'Start date is required';
                if (data.hourly_rate === '' || data.hourly_rate === null) errs.hourly_rate = 'Hourly rate is required';
                break;
            case 5:
                ['character_convicted','character_investigation','character_deported','character_visa_refused','lived_other_country_5y'].forEach((k) => {
                    if (!data[k]) errs[k] = 'Please answer';
                });
                ['health_tb','health_renal','health_hospital','health_residential'].forEach((k) => {
                    if (!data[k]) errs[k] = 'Please answer';
                });
                if (data.medical_cert_submitted_last === 'Yes' && !data.medical_cert_type) errs.medical_cert_type = 'Please select the certificate type';
                break;
            case 6:
                if (!data.currently_working) errs.currently_working = 'Please answer';
                break;
            case 7:
                if (!data.military_compulsory) errs.military_compulsory = 'Please answer';
                if (!data.military_undertaken) errs.military_undertaken = 'Please answer';
                if (!data.travelled_internationally) errs.travelled_internationally = 'Please answer';
                break;
            case 9:
                if (!data.declaration_accepted) errs.declaration_accepted = 'You must accept the declaration to continue';
                break;
        }
        return errs;
    };

    const set = (k) => (v) => setData(k, v);

    // Submit click → walk every step, jump to the first invalid one and show
    // inline errors there. Only when everything passes do we open the review
    // modal — the actual POST happens in confirmSubmit.
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
        post('/work-interest', {
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

    // Clean local errors live as fields are filled, so users get instant
    // feedback on the inline red text disappearing.
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
                    visaLabel="Work Visa (AEWV)"
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
                        <TextField label="Other names used" value={data.other_names} onChange={set('other_names')} hint="If Yes, list all" />
                        <SelectField label="Gender" value={data.gender} onChange={set('gender')} options={['Female', 'Male', 'Other', 'Prefer not to say']} />
                        <DateField label="Date of birth" value={data.dob} onChange={set('dob')} required error={errors.dob} />
                        <TextField label="Country of birth" value={data.country_of_birth} onChange={set('country_of_birth')} />
                        <TextField label="Place of birth (town/city)" value={data.place_of_birth} onChange={set('place_of_birth')} />
                        <TextField label="Country of citizenship" value={data.country_of_citizenship} onChange={set('country_of_citizenship')} />
                        <TextField label="Other citizenships" value={data.other_citizenships} onChange={set('other_citizenships')} />
                        <TextField label="National ID number" value={data.national_id} onChange={set('national_id')} hint="If applicable" />
                        <SelectField label="Partnership status" value={data.partnership_status} onChange={set('partnership_status')} options={PARTNERSHIP} />
                    </FieldGrid>
                    <div className="mt-4">
                        <TextareaField label="Current physical address" value={data.current_address} onChange={set('current_address')} placeholder="Room no., building no., street, suburb, city, postcode, country" />
                    </div>
                    <FieldGrid>
                        <TextField label="Email address" type="email" value={data.email} onChange={set('email')} required error={errors.email} />
                        <TextField label="Contact number" value={data.phone} onChange={set('phone')} required hint="Include country code, e.g. +63 912 345 6789" error={errors.phone} />
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
                        <YesNoField label="Have you ever travelled to New Zealand?" value={data.travelled_nz} onChange={set('travelled_nz')} error={errors.travelled_nz} />
                        <DateField label="If Yes, when did you last leave NZ?" value={data.last_nz_departure} onChange={set('last_nz_departure')} />
                        <YesNoField label="Will your total time in NZ for all visits equal 24 months or more?" value={data.over_24_months} onChange={set('over_24_months')} error={errors.over_24_months} />
                    </FieldGrid>
                    {data.previous_nz_visa === 'Yes' && (
                        <div className="mt-4">
                            <TextareaField label="Previous NZ visa details" value={data.previous_nz_visa_details} onChange={set('previous_nz_visa_details')} />
                        </div>
                    )}
                </>
            ),
        },
        {
            title: 'NZ Employer',
            render: () => (
                <>
                    <SectionTitle title="New Zealand Employer Details" />
                    <FieldGrid>
                        <TextField label="Employer name" value={data.employer_name} onChange={set('employer_name')} required error={errors.employer_name} />
                        <YesNoField label="Is your employer a family member?" value={data.employer_is_family} onChange={set('employer_is_family')} error={errors.employer_is_family} />
                        {data.employer_is_family === 'Yes' && (
                            <TextField label="Relationship" value={data.employer_family_relation} onChange={set('employer_family_relation')} />
                        )}
                        <YesNoField label="Will you be self-employed?" value={data.self_employed} onChange={set('self_employed')} />
                        <DateField label="Date job is available / work starts" value={data.job_start_date} onChange={set('job_start_date')} required error={errors.job_start_date} />
                        <TextField label="Minimum hourly rate of pay (NZD)" type="number" value={data.hourly_rate} onChange={set('hourly_rate')} hint="e.g. 29.66" required error={errors.hourly_rate} />
                        <YesNoField label="Do you intend to support any dependent children on a visitor/student visa?" value={data.supports_dependent_children} onChange={set('supports_dependent_children')} />
                        <SelectField label="Do you know your Job Skills Level as per ANZSCO or NOL?" value={data.job_skills_level} onChange={set('job_skills_level')} options={['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5', 'No']} />
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
                        <YesNoField label="Under investigation or facing charges anywhere?" value={data.character_investigation} onChange={set('character_investigation')} error={errors.character_investigation} />
                        <YesNoField label="Ever expelled, deported or refused entry?" value={data.character_deported} onChange={set('character_deported')} error={errors.character_deported} />
                        <YesNoField label="Ever refused a visa by any country (excl. NZ)?" value={data.character_visa_refused} onChange={set('character_visa_refused')} error={errors.character_visa_refused} />
                        <YesNoField label="Lived in any country 5+ yrs since age 17 (excl. citizenship)?" value={data.lived_other_country_5y} onChange={set('lived_other_country_5y')} error={errors.lived_other_country_5y} />
                        <YesNoField label="Can you provide a Police Certificate (country of citizenship and any country you have lived in more than 1 year in the last 10 years)?" value={data.police_certificate_available} onChange={set('police_certificate_available')} />
                    </FieldGrid>
                    {data.lived_other_country_5y === 'Yes' && (
                        <div className="mt-4">
                            <TextareaField label="Country and years" value={data.lived_other_country_details} onChange={set('lived_other_country_details')} />
                        </div>
                    )}
                    <div className="mt-8">
                        <SectionTitle title="Health Details" />
                        <FieldGrid>
                            <YesNoField label="Do you have tuberculosis?" value={data.health_tb} onChange={set('health_tb')} error={errors.health_tb} />
                            <YesNoField label="Receiving renal dialysis?" value={data.health_renal} onChange={set('health_renal')} error={errors.health_renal} />
                            <YesNoField label="Receiving hospital care?" value={data.health_hospital} onChange={set('health_hospital')} error={errors.health_hospital} />
                            <YesNoField label="Receiving residential care?" value={data.health_residential} onChange={set('health_residential')} error={errors.health_residential} />
                            <YesNoField label="Are you pregnant?" value={data.health_pregnant} onChange={set('health_pregnant')} />
                            <YesNoField label="Do you have a medical certificate (unsubmitted) less than 3 months old from the date of issuance?" value={data.medical_cert_recent} onChange={set('medical_cert_recent')} />
                            <YesNoField label="Have you submitted a medical certificate in your last visa application?" value={data.medical_cert_submitted_last} onChange={set('medical_cert_submitted_last')} />
                        </FieldGrid>
                        {data.medical_cert_submitted_last === 'Yes' && (
                            <div className="mt-4">
                                <SelectField label="Which type?" value={data.medical_cert_type} onChange={set('medical_cert_type')} options={['General Medical', 'X-Ray Medical Examination']} error={errors.medical_cert_type} />
                            </div>
                        )}
                    </div>
                </>
            ),
        },
        {
            title: 'Employment',
            render: () => (
                <>
                    <SectionTitle title="Current Employment" subtitle="Use the previous-roles step for prior roles." />
                    <FieldGrid>
                        <YesNoField label="Are you currently working?" value={data.currently_working} onChange={set('currently_working')} error={errors.currently_working} />
                        <TextField label="Job position / title" value={data.current_job_title} onChange={set('current_job_title')} />
                        <DateField label="Start date" value={data.current_job_start} onChange={set('current_job_start')} />
                        <TextField label="Country of work" value={data.current_job_country} onChange={set('current_job_country')} />
                        <TextField label="Region of work" value={data.current_job_region} onChange={set('current_job_region')} />
                        <TextField label="Name of organisation" value={data.current_employer_name} onChange={set('current_employer_name')} />
                        <TextField label="Employer phone" value={data.current_employer_phone} onChange={set('current_employer_phone')} />
                        <TextField label="Employer email" type="email" value={data.current_employer_email} onChange={set('current_employer_email')} />
                    </FieldGrid>
                    <div className="mt-4">
                        <TextareaField label="Detailed job duties" value={data.current_job_duties} onChange={set('current_job_duties')} placeholder="Describe your main responsibilities." />
                        <div className="mt-4">
                            <TextareaField label="Employer address" value={data.current_employer_address} onChange={set('current_employer_address')} />
                        </div>
                    </div>
                </>
            ),
        },
        {
            title: 'Travel & Military',
            render: () => (
                <>
                    <SectionTitle title="Military Service" />
                    <FieldGrid>
                        <YesNoField label="Was military service compulsory in your home country?" value={data.military_compulsory} onChange={set('military_compulsory')} error={errors.military_compulsory} />
                        <YesNoField label="Have you ever undertaken military service?" value={data.military_undertaken} onChange={set('military_undertaken')} error={errors.military_undertaken} />
                    </FieldGrid>
                    {data.military_undertaken === 'Yes' && (
                        <div className="mt-4">
                            <TextareaField label="Military service details" value={data.military_details} onChange={set('military_details')} />
                        </div>
                    )}
                    <div className="mt-8">
                        <SectionTitle title="Travel History" subtitle="International travel in the last 5 years (excl. NZ)." />
                        <YesNoField label="Have you ever travelled internationally (excl. NZ)?" value={data.travelled_internationally} onChange={set('travelled_internationally')} error={errors.travelled_internationally} />
                    </div>
                </>
            ),
        },
        {
            title: 'Documents',
            render: () => {
                const df = data.document_files || {};
                const dq = data.documents || {};
                const setFile = (key, files) => setData('document_files', { ...df, [key]: files });
                const setAns = (key, val) => setData('documents', { ...dq, [key]: val });
                return (
                    <>
                        <SectionTitle title="Work Interest & Qualifications" subtitle="Upload any documents you already have (PDF or image). You can provide the rest later." />

                        <p className="text-[11px] font-bold uppercase tracking-wider text-[#009688] mt-2 mb-2">Work interest</p>
                        <div className="space-y-2">
                            {WORK_INTEREST_DOCS.map((d) => (
                                <DocRow key={d.key} id={`wd_${d.key}`} label={d.label} files={df[d.key]} onChange={(f) => setFile(d.key, f)} />
                            ))}
                        </div>

                        <p className="text-[11px] font-bold uppercase tracking-wider text-[#009688] mt-6 mb-2">Qualifications</p>
                        <div className="space-y-2">
                            <div className="rounded-xl border border-gray-200 bg-white px-4 py-3">
                                <YesNoField
                                    label="Do you have a valid PCC? (valid 2 years from the date of your previous visa lodgement)"
                                    value={dq.valid_pcc || ''}
                                    onChange={(v) => setAns('valid_pcc', v)}
                                />
                                <div className="mt-2">
                                    <DocRow id="wd_valid_pcc" label="Upload PCC (if available)" files={df.valid_pcc} onChange={(f) => setFile('valid_pcc', f)} />
                                </div>
                            </div>
                            {QUALIFICATION_DOCS.map((d) => (
                                <DocRow key={d.key} id={`wd_${d.key}`} label={d.label} hint={d.hint} files={df[d.key]} onChange={(f) => setFile(d.key, f)} />
                            ))}

                            <p className="text-[11px] font-semibold text-gray-500 mt-3 mb-1">Under employment</p>
                            <DocRow id="wd_ird_earnings" label="IRD Earnings Summary (Monthly Breakdown)" files={df.ird_earnings} onChange={(f) => setFile('ird_earnings', f)} />
                        </div>
                    </>
                );
            },
        },
        {
            title: 'Declaration',
            render: () => (
                <>
                    <SectionTitle title="Declaration" />
                    <div className="bg-gray-50/60 border border-gray-100 rounded-2xl p-6 text-sm text-gray-600 leading-relaxed">
                        <p className="font-bold text-[#282728] mb-2">
                            I declare that the information I have provided above is true, correct and complete.
                        </p>
                        <p>
                            I understand that I must inform Immigration New Zealand of any relevant change of
                            circumstances that may affect the decision on my application. Providing false or
                            misleading information may result in my application being declined, removal from
                            New Zealand, or prosecution under the Immigration Act 2009.
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
                title="Work Visa (AEWV) — Applicant Information"
                visaLabel="AEWV — Work"
                steps={steps}
                onSubmit={submit}
                processing={processing}
                submitLabel="Submit"
                data={data}
                draftKey={DRAFT_KEY}
                draftEndpoint="/visa-interest/work/draft"
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
                visaLabel="Work Visa (AEWV)"
                submitLabel="Submit intake"
                summaryItems={[
                    ['Name', `${data.first_name} ${data.family_name}`.trim()],
                    ['Email', data.email],
                    ['Phone', data.phone],
                    ['Employer', data.employer_name],
                    ['Job starts', data.job_start_date],
                ]}
            />
            <IntakeSuccessModal
                open={showSuccess}
                onClose={() => setShowSuccess(false)}
                visaLabel={flash?.intake_submitted || 'Work Visa (AEWV)'}
            />
        </>
    );
}
