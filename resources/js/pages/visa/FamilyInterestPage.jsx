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

// Public Family Visa (Partner or Child) assessment — the official Family Visa
// Information Form (sections A–H) as a multi-step wizard, matching the other
// visa intakes.

const PARTNERSHIP = ['Single', 'Married', 'Partnership', 'Divorced', 'Widowed', 'Separated'];
const APPLYING_AS = ['Partner', 'Dependent child'];
const DRAFT_KEY = 'epathways_family_intake_draft';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Every step in the form. The submit sweep and the error-clearing effect
// both walk 1..TOTAL_STEPS, so a validator on a step beyond this would
// silently never run.
const TOTAL_STEPS = 8;

const FIELD_TO_STEP = {
    terms_accepted: 1,
    family_name: 2, first_name: 2, other_names: 2, gender: 2, dob: 2, partnership_status: 2,
    country_of_birth: 2, place_of_birth: 2, country_of_citizenship: 2, other_citizenships: 2, national_id: 2,
    current_country: 3, previous_nz_visa: 3, current_address: 3, email: 3, phone: 3,
    applying_as: 4, visa_type: 4, partner_living_together: 4, partner_12_months: 4,
    partner_same_period: 4, partner_close_relatives: 4, child_dependent: 4,
    character_convicted: 5, character_removed: 5, character_investigation: 5, character_visa_refused: 5,
    lived_other_country_5y: 5, previous_police_certificate: 5,
    health_tb: 5, health_renal: 5, health_hospital: 5, health_residential: 5, health_pregnant: 5,
    countries_visited_3m: 5, previous_xray: 5, previous_medical_cert: 5,
    currently_working: 6, current_employer_name: 6, current_employer_address: 6,
    current_employer_phone: 6, current_employer_email: 6, current_occupation: 6,
    current_start: 6, current_end: 6,
    nz_contacts: 8, declaration_accepted: 8, signature_name: 8, signature_date: 8,
};

const FIELD_LABELS = {
    family_name: 'Family name', first_name: 'First name', dob: 'Date of birth',
    email: 'Email', phone: 'Contact number', terms_accepted: 'Terms',
};

function loadDraft() {
    try {
        const raw = window.localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

export default function FamilyInterestPage() {
    const draft = loadDraft();
    const { data, setData, post, processing, errors: serverErrors } = useForm({
        terms_accepted: false,
        family_name: '', first_name: '', other_names: '', gender: '', dob: '',
        partnership_status: '', country_of_birth: '', place_of_birth: '',
        country_of_citizenship: '', other_citizenships: '', national_id: '',
        birth_certificate_available: '', birth_certificate_reason: '', face_image_consent: false,
        current_country: '', previous_nz_visa: '', current_address: '', email: '', phone: '',
        applying_as: '', visa_type: '',
        partner_living_together: '', partner_12_months: '', partner_same_period: '', partner_close_relatives: '',
        child_dependent: '',
        character_convicted: '', character_removed: '', character_investigation: '',
        character_visa_refused: '', lived_other_country_5y: '', previous_police_certificate: '',
        health_tb: '', health_renal: '', health_hospital: '', health_residential: '', health_pregnant: '',
        countries_visited_3m: '', previous_xray: '', previous_medical_cert: '',
        currently_working: '', current_employer_name: '', current_employer_address: '',
        current_employer_phone: '', current_employer_email: '', current_occupation: '',
        current_start: '', current_end: '',
        nz_contacts: '', declaration_accepted: false, signature_name: '', signature_date: '',
        // Shared document tab — files can't serialise to the local draft.
        documents: {}, document_files: {},
        ...(draft || {}),
    });
    const set = (k) => (v) => setData(k, v);

    const [step, setStep] = useState(1);
    const [localErrors, setLocalErrors] = useState({});
    const [showConfirm, setShowConfirm] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);
    const [visitedSteps, setVisitedSteps] = useState(() => new Set([1]));

    const { flash } = usePage().props;
    useEffect(() => {
        if (flash?.intake_submitted) {
            setShowSuccess(true);
            try { window.localStorage.removeItem(DRAFT_KEY); } catch {}
        }
    }, [flash?.intake_submitted]);
    useEffect(() => {
        setVisitedSteps((prev) => (prev.has(step) ? prev : new Set(prev).add(step)));
    }, [step]);
    useEffect(() => { if (draft) toast.success('Restored your saved draft.', { duration: 3000 }); /* eslint-disable-next-line */ }, []);

    const errors = { ...localErrors, ...serverErrors };

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
                if (data.birth_certificate_available === 'No' && !data.birth_certificate_reason?.trim()) errs.birth_certificate_reason = 'Please tell us why';
                break;
            case 3:
                if (!data.email?.trim()) errs.email = 'Email is required';
                else if (!EMAIL_RE.test(data.email)) errs.email = 'Enter a valid email address';
                if (!data.phone?.trim()) errs.phone = 'Contact number is required';
                break;
            case 8:
                if (!data.declaration_accepted) errs.declaration_accepted = 'You must accept the declaration to continue';
                break;
            default:
                break;
        }
        return errs;
    };

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
        post('/family-interest', {
            onSuccess: () => { setShowConfirm(false); try { window.localStorage.removeItem(DRAFT_KEY); } catch {} },
            onError: (errs) => {
                setShowConfirm(false);
                const badKeys = Object.keys(errs);
                let firstInvalid = null;
                for (const k of badKeys) {
                    const s = FIELD_TO_STEP[k];
                    if (s && (firstInvalid === null || s < firstInvalid)) firstInvalid = s;
                }
                if (firstInvalid) setStep(firstInvalid);
                setLocalErrors(errs);
                const labels = badKeys.slice(0, 3).map((k) => FIELD_LABELS[k] || k.replace(/_/g, ' ')).join(', ');
                const more = badKeys.length > 3 ? ` (+${badKeys.length - 3} more)` : '';
                toast.error(firstInvalid ? `Step ${firstInvalid} needs attention — ${labels}${more}.` : `Please fix: ${labels}${more}.`);
            },
        });
    };

    // Auto-save draft.
    useEffect(() => {
        try { window.localStorage.setItem(DRAFT_KEY, JSON.stringify(data)); } catch {}
    }, [data]);

    const isPartner = data.applying_as === 'Partner';
    const isChild = data.applying_as === 'Dependent child';

    const steps = [
        {
            title: 'Terms',
            render: () => (
                <IntakeTermsStep visaLabel="Family Visa (Partner / Child)" accepted={data.terms_accepted} onAccept={set('terms_accepted')} error={errors.terms_accepted} />
            ),
        },
        {
            title: 'Identity',
            render: () => (
                <>
                    <SectionTitle title="Identity information" subtitle="Write N/A if a field doesn't apply." />
                    <FieldGrid>
                        <TextField label="Family name" value={data.family_name} onChange={set('family_name')} required error={errors.family_name} />
                        <TextField label="First name" value={data.first_name} onChange={set('first_name')} required error={errors.first_name} />
                        <TextField label="Other names used" value={data.other_names} onChange={set('other_names')} />
                        <SelectField label="Gender" value={data.gender} onChange={set('gender')} options={['Female', 'Male', 'Other', 'Prefer not to say']} />
                        <DateField label="Date of birth" value={data.dob} onChange={set('dob')} required error={errors.dob} />
                        <SelectField label="Partnership status" value={data.partnership_status} onChange={set('partnership_status')} options={PARTNERSHIP} />
                        <TextField label="Country of birth" value={data.country_of_birth} onChange={set('country_of_birth')} />
                        <TextField label="Place of birth (town/city)" value={data.place_of_birth} onChange={set('place_of_birth')} />
                        <TextField label="Country of citizenship" value={data.country_of_citizenship} onChange={set('country_of_citizenship')} />
                        <TextField label="Other citizenships" value={data.other_citizenships} onChange={set('other_citizenships')} />
                        <TextField label="National ID number (if applicable)" value={data.national_id} onChange={set('national_id')} />
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
                    <SectionTitle title="New Zealand immigration history" />
                    <FieldGrid>
                        <TextField label="Country you'll be in when this application is submitted" value={data.current_country} onChange={set('current_country')} />
                        <YesNoField label="Have you previously applied for a NZ visa?" value={data.previous_nz_visa} onChange={set('previous_nz_visa')} />
                    </FieldGrid>
                    <div className="mt-4"><TextareaField label="Current physical address" value={data.current_address} onChange={set('current_address')} /></div>
                    <FieldGrid>
                        <TextField label="Email address" type="email" value={data.email} onChange={set('email')} required error={errors.email} />
                        <TextField label="Contact number" value={data.phone} onChange={set('phone')} required hint="Include country code" error={errors.phone} />
                    </FieldGrid>
                </>
            ),
        },
        {
            title: 'Visa Details',
            render: () => (
                <>
                    <SectionTitle title="Visa details" />
                    <FieldGrid>
                        <SelectField label="Are you applying as a partner or dependent child?" value={data.applying_as} onChange={set('applying_as')} options={APPLYING_AS} />
                        <TextField label="What type of visa are you applying for?" value={data.visa_type} onChange={set('visa_type')} />
                    </FieldGrid>
                    {isPartner && (
                        <div className="mt-4 border-l-2 border-rose-200 pl-4">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-rose-500 mb-2">Partner visa questions</p>
                            <FieldGrid>
                                <YesNoField label="Currently living together in a genuine and stable partnership?" value={data.partner_living_together} onChange={set('partner_living_together')} />
                                <YesNoField label="Living together for at least 12 months in total?" value={data.partner_12_months} onChange={set('partner_12_months')} />
                                <YesNoField label="Will you both be in NZ for the same period of time?" value={data.partner_same_period} onChange={set('partner_same_period')} />
                                <YesNoField label="Are you close relatives?" value={data.partner_close_relatives} onChange={set('partner_close_relatives')} />
                            </FieldGrid>
                        </div>
                    )}
                    {isChild && (
                        <div className="mt-4 border-l-2 border-rose-200 pl-4">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-rose-500 mb-2">Dependent child questions</p>
                            <YesNoField label="Are you 19 or under, single and financially reliant on your parent(s)?" value={data.child_dependent} onChange={set('child_dependent')} />
                        </div>
                    )}
                </>
            ),
        },
        {
            title: 'Character & Health',
            render: () => (
                <>
                    <SectionTitle title="Character" />
                    <FieldGrid>
                        <YesNoField label="Convicted of any offence (incl. driving)?" value={data.character_convicted} onChange={set('character_convicted')} />
                        <YesNoField label="Ever removed, deported or refused entry (excl. NZ)?" value={data.character_removed} onChange={set('character_removed')} />
                        <YesNoField label="Currently under investigation or facing charges?" value={data.character_investigation} onChange={set('character_investigation')} />
                        <YesNoField label="Ever refused a visa by any country (excl. NZ)?" value={data.character_visa_refused} onChange={set('character_visa_refused')} />
                        <YesNoField label="Lived in any country 5+ years since 17 (not citizenship)?" value={data.lived_other_country_5y} onChange={set('lived_other_country_5y')} />
                        <YesNoField label="Provided a police certificate with a previous application?" value={data.previous_police_certificate} onChange={set('previous_police_certificate')} />
                    </FieldGrid>
                    <SectionTitle title="Health" />
                    <FieldGrid>
                        <YesNoField label="Do you have tuberculosis?" value={data.health_tb} onChange={set('health_tb')} />
                        <YesNoField label="Require renal dialysis?" value={data.health_renal} onChange={set('health_renal')} />
                        <YesNoField label="Require hospital care?" value={data.health_hospital} onChange={set('health_hospital')} />
                        <YesNoField label="Require residential care?" value={data.health_residential} onChange={set('health_residential')} />
                        <YesNoField label="Are you pregnant?" value={data.health_pregnant} onChange={set('health_pregnant')} />
                        <YesNoField label="Previously provided a chest X-ray certificate?" value={data.previous_xray} onChange={set('previous_xray')} />
                        <YesNoField label="Previously provided a medical certificate (INZ 1007 / 1201)?" value={data.previous_medical_cert} onChange={set('previous_medical_cert')} />
                    </FieldGrid>
                    <div className="mt-4"><TextField label="Countries visited or lived in 3+ months in the last 5 years" value={data.countries_visited_3m} onChange={set('countries_visited_3m')} /></div>
                </>
            ),
        },
        {
            title: 'Work History',
            render: () => (
                <>
                    <SectionTitle title="Work history" subtitle="Your current work (or last paid work if retired)." />
                    <YesNoField label="Are you currently working?" value={data.currently_working} onChange={set('currently_working')} />
                    <FieldGrid>
                        <TextField label="Name of organisation" value={data.current_employer_name} onChange={set('current_employer_name')} />
                        <TextField label="Occupation / job title" value={data.current_occupation} onChange={set('current_occupation')} />
                        <TextField label="Employer phone number" value={data.current_employer_phone} onChange={set('current_employer_phone')} />
                        <TextField label="Employer email address" value={data.current_employer_email} onChange={set('current_employer_email')} />
                        <TextField label="Start date (year and month)" value={data.current_start} onChange={set('current_start')} placeholder="e.g. 2021-03" />
                        <TextField label="End date (year and month)" value={data.current_end} onChange={set('current_end')} placeholder="e.g. present" />
                    </FieldGrid>
                    <div className="mt-4"><TextareaField label="Address of organisation" rows={2} value={data.current_employer_address} onChange={set('current_employer_address')} /></div>
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
            title: 'Contacts & Declaration',
            render: () => (
                <>
                    <SectionTitle title="Other contacts" />
                    <TextareaField label="Contacts in New Zealand? (relationship, name, address, phone)" value={data.nz_contacts} onChange={set('nz_contacts')} />
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
                title="Family Visa (Partner / Child) — Information Form"
                visaLabel="Family Visa"
                steps={steps}
                onSubmit={submit}
                processing={processing}
                submitLabel="Submit"
                data={data}
                draftKey={DRAFT_KEY}
                draftEndpoint="/visa-interest/family/draft"
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
                visaLabel="Family Visa (Partner / Child)"
                submitLabel="Submit assessment"
                summaryItems={[
                    ['Name', `${data.first_name} ${data.family_name}`.trim()],
                    ['Email', data.email],
                    ['Phone', data.phone],
                    ['Applying as', data.applying_as],
                    ['Visa type', data.visa_type],
                ]}
            />
            <IntakeSuccessModal
                open={showSuccess}
                onClose={() => setShowSuccess(false)}
                visaLabel={flash?.intake_submitted || 'Family Visa (Partner / Child)'}
            />
        </>
    );
}
