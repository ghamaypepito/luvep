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
const DRAFT_KEY = 'epathways_student_intake_draft';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Maps every field rendered in each step to its step number. Used so that
// when the SERVER rejects a field we still know which step to navigate the
// applicant to. (Client validation only tracks required fields — this map
// covers every input.)
// Every step in the form. The submit sweep and the error-clearing effect
// both walk 1..TOTAL_STEPS, so a validator on a step beyond this would
// silently never run.
const TOTAL_STEPS = 9;

const FIELD_TO_STEP = {
    // Step 1 — Privacy & Terms
    terms_accepted: 1,
    // Step 2 — Identity
    family_name: 2, first_name: 2, other_names: 2, gender: 2, dob: 2,
    country_of_birth: 2, place_of_birth: 2, country_of_citizenship: 2,
    other_citizenships: 2, national_id: 2, passport_number: 2, passport_expiry: 2,
    partnership_status: 2, current_address: 2, overseas_address: 2, email: 2, phone: 2,
    // Step 3 — NZ Immigration
    current_country: 3, travelled_nz: 3, last_nz_departure: 3, over_24_months: 3,
    // Step 4 — Character & Health
    character_convicted: 4, character_investigation: 4, character_deported: 4,
    character_visa_refused: 4, lived_other_country_5y: 4, lived_other_country_details: 4,
    health_tb: 4, health_renal: 4, health_hospital: 4, health_residential: 4, health_pregnant: 4,
    // Step 5 — Employment
    currently_working: 5, current_job_title: 5, current_job_duties: 5,
    current_job_start: 5, current_job_finish: 5, current_job_country: 5, current_job_region: 5,
    current_employer_name: 5, current_employer_address: 5,
    current_employer_phone: 5, current_employer_email: 5,
    // Step 6 — Study Plan
    programmes: 6, study_period_from: 6, study_period_to: 6, school_name: 6, has_offer: 6,
    // Step 7 — Funds
    has_enough_funds: 7, tuition_fee_nzd: 7, living_expenses_nzd: 7,
    has_sponsor: 7, sponsor_relationship: 7, sponsor_income_source: 7,
    can_provide_statements: 7, has_other_assets: 7, other_assets_details: 7,
    // Step 8 — Declaration
    declaration_accepted: 9, signature_name: 9, signature_date: 9,
};

const FIELD_LABELS = {
    family_name: 'Family name', first_name: 'First name', dob: 'Date of birth',
    email: 'Email', phone: 'Phone', country_of_citizenship: 'Country of citizenship',
    passport_number: 'Passport number', programmes: 'Programme(s)',
    school_name: 'School name', tuition_fee_nzd: 'Tuition fee',
    declaration_accepted: 'Declaration',
};

function loadDraft() {
    try {
        const raw = window.localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

export default function StudentInterestPage() {
    const draft = loadDraft();
    const { data, setData, post, processing, errors: serverErrors } = useForm({
        family_name: '', first_name: '', other_names: '', gender: '', dob: '',
        country_of_birth: '', place_of_birth: '', current_address: '', overseas_address: '',
        email: '', phone: '', country_of_citizenship: '', other_citizenships: '',
        national_id: '', passport_number: '', passport_expiry: '', partnership_status: '',
        birth_certificate_available: '', birth_certificate_reason: '', face_image_consent: false,

        current_country: '', travelled_nz: '', last_nz_departure: '', over_24_months: '',

        character_convicted: '', character_investigation: '', character_deported: '',
        character_visa_refused: '', lived_other_country_5y: '', lived_other_country_details: '',
        police_certificate_available: '',

        health_tb: '', health_renal: '', health_hospital: '', health_residential: '', health_pregnant: '',
        medical_cert_recent: '', medical_cert_submitted_last: '', medical_cert_type: '',

        currently_working: '', current_job_title: '', current_job_duties: '',
        current_job_start: '', current_job_finish: '', current_job_country: '', current_job_region: '',
        current_employer_name: '', current_employer_address: '',
        current_employer_phone: '', current_employer_email: '',

        has_nz_contacts: '',
        military_compulsory: '', military_undertaken: '', military_details: '',
        travelled_internationally: '',

        programmes: '', study_period_from: '', study_period_to: '', school_name: '', has_offer: '',

        has_enough_funds: '', tuition_fee_nzd: '', living_expenses_nzd: '',
        has_sponsor: '', sponsor_relationship: '', sponsor_income_source: '',
        can_provide_statements: '', has_other_assets: '', other_assets_details: '',

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
                ['character_convicted','character_investigation','character_deported','character_visa_refused','lived_other_country_5y'].forEach((k) => {
                    if (!data[k]) errs[k] = 'Please answer';
                });
                ['health_tb','health_renal','health_hospital','health_residential'].forEach((k) => {
                    if (!data[k]) errs[k] = 'Please answer';
                });
                if (data.medical_cert_submitted_last === 'Yes' && !data.medical_cert_type) errs.medical_cert_type = 'Please select the certificate type';
                break;
            case 5:
                if (!data.currently_working) errs.currently_working = 'Please answer';
                break;
            case 6:
                if (!data.programmes?.trim()) errs.programmes = 'Programme(s) are required';
                if (!data.school_name?.trim()) errs.school_name = 'School name is required';
                if (!data.study_period_from) errs.study_period_from = 'Start date is required';
                if (!data.has_offer) errs.has_offer = 'Please answer';
                break;
            case 7:
                if (!data.has_enough_funds) errs.has_enough_funds = 'Please answer';
                if (data.tuition_fee_nzd === '' || data.tuition_fee_nzd === null) errs.tuition_fee_nzd = 'Tuition fee is required';
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
        post('/student-interest', {
            onSuccess: () => {
                setShowConfirm(false);
                try { window.localStorage.removeItem(DRAFT_KEY); } catch {}
            },
            onError: (errs) => {
                setShowConfirm(false);
                // Map each rejected field back to its step via FIELD_TO_STEP
                // (covers every input, not just required ones). Jump to the
                // lowest step number that holds any error.
                const badKeys = Object.keys(errs);
                let firstInvalid = null;
                for (const k of badKeys) {
                    const stepNum = FIELD_TO_STEP[k];
                    if (stepNum && (firstInvalid === null || stepNum < firstInvalid)) {
                        firstInvalid = stepNum;
                    }
                }
                if (firstInvalid) setStep(firstInvalid);
                // Pull the error messages into local state so they ALSO survive
                // a navigation away and back. The merged `errors` already
                // reflects them inline.
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
                    visaLabel="Student Visa (SV)"
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
                        <TextField label="Other citizenships" value={data.other_citizenships} onChange={set('other_citizenships')} />
                        <TextField label="National ID" value={data.national_id} onChange={set('national_id')} />
                        <TextField label="Passport number" value={data.passport_number} onChange={set('passport_number')} required error={errors.passport_number} />
                        <DateField label="Passport expiry" value={data.passport_expiry} onChange={set('passport_expiry')} />
                        <SelectField label="Partnership status" value={data.partnership_status} onChange={set('partnership_status')} options={PARTNERSHIP} />
                    </FieldGrid>
                    <div className="mt-4 space-y-4">
                        <TextareaField label="Current physical address" value={data.current_address} onChange={set('current_address')} />
                        <TextareaField label="Most recent overseas address" value={data.overseas_address} onChange={set('overseas_address')} />
                    </div>
                    <FieldGrid>
                        <TextField label="Email address" type="email" value={data.email} onChange={set('email')} required error={errors.email} />
                        <TextField label="Contact number" value={data.phone} onChange={set('phone')} required hint="Include country code" error={errors.phone} />
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
                        <YesNoField label="Have you ever travelled to New Zealand?" value={data.travelled_nz} onChange={set('travelled_nz')} error={errors.travelled_nz} />
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
                    <SectionTitle title="Current / Previous Employment" subtitle="List your most recent employment if not currently working." />
                    <FieldGrid>
                        <YesNoField label="Are you currently working?" value={data.currently_working} onChange={set('currently_working')} error={errors.currently_working} />
                        <TextField label="Job position / title" value={data.current_job_title} onChange={set('current_job_title')} />
                        <DateField label="Start date" value={data.current_job_start} onChange={set('current_job_start')} />
                        <DateField label="Finish date" value={data.current_job_finish} onChange={set('current_job_finish')} hint="Leave blank if current" />
                        <TextField label="Country of work" value={data.current_job_country} onChange={set('current_job_country')} />
                        <TextField label="Region of work" value={data.current_job_region} onChange={set('current_job_region')} />
                        <TextField label="Name of organisation" value={data.current_employer_name} onChange={set('current_employer_name')} />
                        <TextField label="Employer phone" value={data.current_employer_phone} onChange={set('current_employer_phone')} />
                        <TextField label="Employer email" type="email" value={data.current_employer_email} onChange={set('current_employer_email')} />
                    </FieldGrid>
                    <div className="mt-4 space-y-4">
                        <TextareaField label="Detailed job duties" value={data.current_job_duties} onChange={set('current_job_duties')} />
                        <TextareaField label="Employer address" value={data.current_employer_address} onChange={set('current_employer_address')} />
                    </div>
                </>
            ),
        },
        {
            title: 'Study Plan',
            render: () => (
                <>
                    <SectionTitle title="Study Plan" />
                    <TextareaField label="Programme(s) you want to study in New Zealand" value={data.programmes} onChange={set('programmes')} error={errors.programmes} />
                    <FieldGrid>
                        <DateField label="Intended study from" value={data.study_period_from} onChange={set('study_period_from')} required error={errors.study_period_from} />
                        <DateField label="Intended study to" value={data.study_period_to} onChange={set('study_period_to')} />
                        <TextField label="School / institution name" value={data.school_name} onChange={set('school_name')} required error={errors.school_name} />
                        <YesNoField label="Already obtained a (un)conditional offer?" value={data.has_offer} onChange={set('has_offer')} error={errors.has_offer} />
                    </FieldGrid>
                </>
            ),
        },
        {
            title: 'Funds',
            render: () => (
                <>
                    <SectionTitle title="Study Funds and Assets" subtitle="State currency, amount, and where the funds are held." />
                    <FieldGrid>
                        <YesNoField label="Enough funds to finance your study?" value={data.has_enough_funds} onChange={set('has_enough_funds')} error={errors.has_enough_funds} />
                        <TextField label="Tuition fee amount (NZD)" type="number" value={data.tuition_fee_nzd} onChange={set('tuition_fee_nzd')} hint="Min NZD 20,000/yr" required error={errors.tuition_fee_nzd} />
                        <TextField label="Living expenses (NZD)" type="number" value={data.living_expenses_nzd} onChange={set('living_expenses_nzd')} hint="NZD 20,000/yr or 1,667/mo for >12 mo courses" />
                        <YesNoField label="Do you have a sponsor?" value={data.has_sponsor} onChange={set('has_sponsor')} />
                        {data.has_sponsor === 'Yes' && (
                            <>
                                <TextField label="Relationship to sponsor" value={data.sponsor_relationship} onChange={set('sponsor_relationship')} />
                                <TextField label="Sponsor's source of income" value={data.sponsor_income_source} onChange={set('sponsor_income_source')} />
                            </>
                        )}
                        <YesNoField label="Can provide last 6 months of bank statements?" value={data.can_provide_statements} onChange={set('can_provide_statements')} />
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
                            I declare that the information I have provided above is true, correct and complete.
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
                title="Student Visa (SV) — Applicant Information"
                visaLabel="SV — Student"
                steps={steps}
                onSubmit={submit}
                processing={processing}
                submitLabel="Submit"
                data={data}
                draftKey={DRAFT_KEY}
                draftEndpoint="/visa-interest/student/draft"
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
                visaLabel="Student Visa (SV)"
                submitLabel="Submit intake"
                summaryItems={[
                    ['Name', `${data.first_name} ${data.family_name}`.trim()],
                    ['Email', data.email],
                    ['Phone', data.phone],
                    ['Programme', data.programmes],
                    ['School', data.school_name],
                ]}
            />
            <IntakeSuccessModal
                open={showSuccess}
                onClose={() => setShowSuccess(false)}
                visaLabel={flash?.intake_submitted || 'Student Visa'}
            />
        </>
    );
}
