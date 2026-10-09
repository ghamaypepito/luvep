import React, { useState, useEffect, useRef } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle,
    AlertCircle,
    ChevronRight,
    ChevronLeft,
    ShieldCheck,
    User,
    Globe,
    Briefcase,
    GraduationCap,
    Award,
    Languages,
    FileCheck2,
    MessageSquare,
    Send,
    Plus,
    Trash2,
    Calendar,
    Eye,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { toast } from 'sonner';
import Navbar from "@/components/layout/Navbar";
import Footer from "@/components/layout/Footer";
import ResidentIntakeStepper from "@/components/visa/ResidentIntakeStepper";
import IntakeSuccessModal from "@/components/visa/IntakeSuccessModal";

const TEAL = '#00A693';
const TEAL_DARK = '#008c7c';
const DARK = '#0c1611';

const TOTAL_STEPS = 9;
const DRAFT_STORAGE_KEY = 'epathways_resident_intake_draft';
// Tracks the user's in-flight assessment after a successful form submit, so
// that revisiting /resident-interest forwards them to the right next step
// (pay / book / booked) instead of starting a brand-new intake from scratch.
const PENDING_ASSESSMENT_KEY = 'epathways_pending_assessment';

// ─── Date helpers (form uses DD/MM/YYYY; backend wants YYYY-MM-DD) ──────────
const isoToDmy = (s) => {
    if (!s) return '';
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(s)) return s;
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s));
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
};

const dmyToIso = (s) => {
    if (!s) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(String(s))) return String(s).slice(0, 10); // already ISO
    const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(String(s));
    return m ? `${m[3]}-${m[2]}-${m[1]}` : '';
};

const isValidDmy = (s) => {
    if (typeof s !== 'string') return false;
    const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(s);
    if (!m) return false;
    const d = +m[1], mo = +m[2], y = +m[3];
    const dt = new Date(y, mo - 1, d);
    return dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === d;
};

// Typed dd/mm/yyyy field — auto-inserts slashes as the user types digits.
// A trailing calendar icon opens the browser's native date picker so users
// can choose visually instead of typing.
function DateField({ value, onChange, className, ...rest }) {
    const pickerRef = useRef(null);

    const handleChange = (e) => {
        const digits = e.target.value.replace(/\D/g, '').slice(0, 8);
        let formatted = digits;
        if (digits.length > 2) formatted = digits.slice(0, 2) + '/' + digits.slice(2);
        if (digits.length > 4) formatted = digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
        onChange(formatted);
    };

    const handlePick = (e) => {
        onChange(isoToDmy(e.target.value));
    };

    const openPicker = () => {
        const el = pickerRef.current;
        if (!el) return;
        // showPicker is the supported way in modern browsers; fall back to a click
        // for older ones (the hidden date input still allows typing as a backstop).
        if (typeof el.showPicker === 'function') {
            try { el.showPicker(); return; } catch { /* user-activation lost — fall through */ }
        }
        el.focus();
        el.click();
    };

    return (
        <div className="relative flex items-center">
            <input
                type="text"
                inputMode="numeric"
                placeholder="DD/MM/YYYY"
                maxLength={10}
                // isoToDmy converts a stored ISO date for display; for in-progress typing
                // (e.g. "15/05") it returns '', so fall back to the raw value so the input
                // doesn't get cleared on every keystroke.
                value={isoToDmy(value || '') || (value || '')}
                onChange={handleChange}
                className={`${className || ''} pr-10`}
                {...rest}
            />
            <button
                type="button"
                onClick={openPicker}
                aria-label="Open calendar"
                tabIndex={-1}
                className="absolute right-0 p-1.5 rounded-md text-gray-400 hover:text-[#00A693] hover:bg-[#00A693]/10 transition-colors"
            >
                <Calendar size={16} />
            </button>
            {/* Hidden native date input — driven by the calendar button, kept in sync
                with the form value so the picker opens on the right date. */}
            <input
                ref={pickerRef}
                type="date"
                tabIndex={-1}
                aria-hidden="true"
                value={dmyToIso(value || '')}
                onChange={handlePick}
                className="absolute right-2 bottom-0 w-0 h-0 opacity-0 pointer-events-none"
            />
        </div>
    );
}

// Each checklist key + the free-form "other" bucket holds an array of File objects.
const freshDocumentFiles = () => ({
    passport: [],
    visa_copies: [],
    contracts: [],
    payslips: [],
    ird_summary: [],
    education_certs: [],
    cv: [],
    other: [],
});

const FORM_DEFAULTS = {
    terms_accepted: false,
    // Personal
    first_name: '',
    last_name: '',
    dob: '',
    nationality: '',
    email: '',
    phone: '',
    // Passport
    passport_number: '',
    passport_expiry: '',
    issuing_country: '',
    // Visa status
    current_visa_type: '',
    current_visa_other: '',
    current_visa_expiry: '',
    nz_arrival_date: '',
    previous_nz_visa_history: '',
    // Employment
    job_title: '',
    employment_start: '',
    employment_type: '',
    hourly_rate: '',
    // Qualifications
    highest_qualification: '',
    institution_name: '',
    country_of_study: '',
    nzqa_status: '',
    nzqa_iqa_reference: '',
    // Work experience
    nz_skilled_years: '',
    total_skilled_years: '',
    career_summary: '',
    // English
    english_evidence: '',
    english_test_score: '',
    english_test_date: '',
    // Family
    include_family: '',
    family_members: [],
    // Documents checklist
    documents: {
        passport: false,
        visa_copies: false,
        contracts: false,
        payslips: false,
        ird_summary: false,
        education_certs: false,
        cv: false,
    },
    // Documents — actual uploaded PDFs (checklist key -> File). Never persisted.
    document_files: freshDocumentFiles(),
    // Disclosures
    character_health_disclosure: '',
    other_notes: '',
};

// Build the form's initial values, restoring a saved draft from this device if one exists.
function buildInitialFormData() {
    const base = {
        ...FORM_DEFAULTS,
        documents: { ...FORM_DEFAULTS.documents },
        document_files: freshDocumentFiles(),
        family_members: [],
    };
    try {
        const raw = window.localStorage.getItem(DRAFT_STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object' && parsed.data && typeof parsed.data === 'object') {
                const { document_files, ...restorable } = parsed.data; // never restore File objects
                return { ...base, ...restorable, document_files: freshDocumentFiles() };
            }
        }
    } catch {
        /* corrupt / unavailable storage — fall through to defaults */
    }
    return base;
}

function loadDraftStep() {
    try {
        const raw = window.localStorage.getItem(DRAFT_STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            const s = parsed?.step;
            if (Number.isInteger(s) && s >= 1 && s <= TOTAL_STEPS) return s;
        }
    } catch {
        /* ignore */
    }
    return 1;
}

// Project an existing intake record (from /resident-interest/edit/{token}) onto
// the form's data shape. Document File objects can't be rehydrated — only the
// checklist ticks and on-server file paths are kept (and the latter are shown
// to the applicant as "already attached"; their own new uploads append on top).
function editIntakeToFormData(intake) {
    if (!intake) return null;
    return {
        terms_accepted: true,
        first_name: intake.first_name || '',
        last_name: intake.last_name || '',
        dob: intake.dob || '',
        nationality: intake.nationality || '',
        email: intake.email || '',
        phone: intake.phone || '',
        passport_number: intake.passport_number || '',
        passport_expiry: intake.passport_expiry || '',
        issuing_country: intake.issuing_country || '',
        current_visa_type: intake.current_visa_type || '',
        current_visa_other: intake.current_visa_other || '',
        current_visa_expiry: intake.current_visa_expiry || '',
        nz_arrival_date: intake.nz_arrival_date || '',
        previous_nz_visa_history: intake.previous_nz_visa_history || '',
        job_title: intake.job_title || '',
        employment_start: intake.employment_start || '',
        employment_type: intake.employment_type || '',
        hourly_rate: intake.hourly_rate != null ? String(intake.hourly_rate) : '',
        highest_qualification: intake.highest_qualification || '',
        institution_name: intake.institution_name || '',
        country_of_study: intake.country_of_study || '',
        nzqa_status: intake.nzqa_status || '',
        nzqa_iqa_reference: intake.nzqa_iqa_reference || '',
        nz_skilled_years: intake.nz_skilled_years != null ? String(intake.nz_skilled_years) : '',
        total_skilled_years: intake.total_skilled_years != null ? String(intake.total_skilled_years) : '',
        career_summary: intake.career_summary || '',
        english_evidence: intake.english_evidence || '',
        english_test_score: intake.english_test_score || '',
        english_test_date: intake.english_test_date || '',
        include_family: intake.include_family || '',
        family_members: Array.isArray(intake.family_members) ? intake.family_members : [],
        documents: { ...FORM_DEFAULTS.documents, ...(intake.documents || {}) },
        document_files: freshDocumentFiles(), // never re-hydrate File objects
        character_health_disclosure: intake.character_health_disclosure || '',
        other_notes: intake.other_notes || '',
    };
}

export default function ResidentIntakePage({ editIntake = null, editToken = null }) {
    const { flash } = usePage().props;
    const isEditing = !!editToken;

    // ── PAYMENT + BOOKING TEMPORARILY DISABLED ────────────────────
    // Resume-to-pay bounce is off while the pricing / Stripe / booking
    // flow is paused; re-enable when the controllers wire the
    // Assessment + assessment.pay redirect back in.
    const [resumeChecked, setResumeChecked] = useState(true);
    // useEffect(() => {
    //     if (isEditing) return;
    //     try {
    //         const raw = window.localStorage.getItem(PENDING_ASSESSMENT_KEY);
    //         if (!raw) { setResumeChecked(true); return; }
    //         const { token, status } = JSON.parse(raw);
    //         if (!token) { setResumeChecked(true); return; }
    //         // booked = journey complete; let the user start a new intake.
    //         if (status === 'booked') {
    //             window.localStorage.removeItem(PENDING_ASSESSMENT_KEY);
    //             setResumeChecked(true);
    //             return;
    //         }
    //         const target = status === 'paid'
    //             ? `/assessment/${token}/book`
    //             : `/assessment/${token}/pay`;
    //         window.location.replace(target);
    //     } catch {
    //         setResumeChecked(true);
    //     }
    // }, [isEditing]);

    // Snapshot of files already on the server for this intake — shown to the
    // applicant in Step 8 as "already attached" so they know what's on file.
    const existingFiles = editIntake?.document_files && typeof editIntake.document_files === 'object'
        ? editIntake.document_files
        : {};
    const [step, setStep] = useState(isEditing ? 1 : loadDraftStep);
    // Steps the user has actually opened — a step only shows the completed
    // checkmark when it has been visited AND its required fields validate.
    // Jumping forward through the stepper does NOT mark intermediate steps as
    // visited; only the destination is added.
    const [visitedSteps, setVisitedSteps] = useState(() => new Set([isEditing ? 1 : loadDraftStep()]));
    const [isSuccess, setIsSuccess] = useState(false);
    const [intakeId, setIntakeId] = useState(null);
    const [localErrors, setLocalErrors] = useState({});
    const [showConfirm, setShowConfirm] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);

    // Persistent post-submit modal — driven by the controller's
    // `intake_submitted` flash. Stays until the user manually dismisses
    // it, so they don't think the submission silently failed.
    useEffect(() => {
        if (flash?.intake_submitted) {
            setShowSuccess(true);
            try { window.localStorage.removeItem(DRAFT_STORAGE_KEY); } catch {}
        }
    }, [flash?.intake_submitted]);

    useEffect(() => {
        if (flash?.success) {
            setIsSuccess(true);
            if (flash?.intake_id) setIntakeId(flash.intake_id);
        }
    }, [flash]);

    // In edit mode we pre-fill with the canonical intake record; otherwise we try
    // to restore a draft from this device, falling back to defaults.
    const [initialFormData] = useState(() => (
        isEditing
            ? { ...FORM_DEFAULTS, documents: { ...FORM_DEFAULTS.documents }, document_files: freshDocumentFiles(), family_members: [], ...editIntakeToFormData(editIntake) }
            : buildInitialFormData()
    ));
    const { data, setData, post, processing, transform } = useForm(initialFormData);

    // Auto-save a draft to this device on every change so a refresh keeps everything.
    // Uploaded PDF files can't be serialised, so they're left out (the checklist ticks are kept).
    // Edit mode skips this entirely — the canonical record lives on the server.
    useEffect(() => {
        if (isEditing) return;
        if (isSuccess) {
            try { window.localStorage.removeItem(DRAFT_STORAGE_KEY); } catch { /* ignore */ }
            return;
        }
        try {
            const { document_files, ...persistable } = data;
            window.localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify({ data: persistable, step, savedAt: Date.now() }));
        } catch {
            /* storage full or unavailable — non-fatal */
        }
    }, [data, step, isSuccess, isEditing]);

    // Silent server-side draft save. The other four visa forms do this via
    // IntakeFormShell (draftEndpoint); the Resident form doesn't use that shell,
    // so without this a half-filled Resident assessment stayed in localStorage
    // only and never appeared in Visa Assessment. Gated on a first name + valid
    // email (the minimum the endpoint needs to identify/de-dupe the row) and
    // debounced; skipped while editing an existing intake.
    const serverDraft = useRef({ inFlight: false, lastHash: '' });
    useEffect(() => {
        if (isEditing || isSuccess) return;
        const okName = String(data.first_name ?? '').trim();
        const okEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(data.email ?? '').trim());
        if (!okName || !okEmail) return;

        // Files can't be serialised and are only persisted on final submit.
        const { document_files, ...payload } = data;
        let hash;
        try { hash = JSON.stringify(payload); } catch { hash = String(Math.random()); }
        if (hash === serverDraft.current.lastHash) return;

        const t = setTimeout(async () => {
            if (serverDraft.current.inFlight) return;
            serverDraft.current.inFlight = true;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/visa-interest/resident/draft', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin',
                });
                if (res.ok) serverDraft.current.lastHash = hash;
            } catch {
                /* silent — the applicant never asked for this; their local copy is safe */
            } finally {
                serverDraft.current.inFlight = false;
            }
        }, 4000);

        return () => clearTimeout(t);
    }, [data, isSuccess, isEditing]);

    const handleStartOver = () => {
        if (!window.confirm('Clear everything you have entered and start over?')) return;
        try { window.localStorage.removeItem(DRAFT_STORAGE_KEY); } catch { /* ignore */ }
        setData({
            ...FORM_DEFAULTS,
            documents: { ...FORM_DEFAULTS.documents },
            document_files: freshDocumentFiles(),
            family_members: [],
        });
        setStep(1);
        setLocalErrors({});
        setVisitedSteps(new Set([1]));
    };

    const steps = [
        { id: 1, title: 'Terms', icon: ShieldCheck },
        { id: 2, title: 'Personal', icon: User },
        { id: 3, title: 'Passport & Visa', icon: Globe },
        { id: 4, title: 'Employment', icon: Briefcase },
        { id: 5, title: 'Qualifications', icon: GraduationCap },
        { id: 6, title: 'Experience', icon: Award },
        { id: 7, title: 'English & Family', icon: Languages },
        { id: 8, title: 'Documents', icon: FileCheck2 },
        { id: 9, title: 'Additional Info', icon: MessageSquare },
    ];

    const formRef = useRef(null);

    const validateStep = (n) => {
        const errs = {};
        switch (n) {
            case 1:
                if (!data.terms_accepted) errs.terms_accepted = 'You must accept the terms to continue';
                break;
            case 2:
                if (!data.first_name.trim()) errs.first_name = 'First name is required';
                if (!data.last_name.trim()) errs.last_name = 'Last name is required';
                if (!isValidDmy(isoToDmy(data.dob))) errs.dob = 'Date of birth is required (DD/MM/YYYY)';
                if (!data.nationality.trim()) errs.nationality = 'Nationality is required';
                if (!data.email.trim()) errs.email = 'Email is required';
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) errs.email = 'Enter a valid email address';
                if (!data.phone.trim()) errs.phone = 'Phone number is required';
                break;
            case 3:
                if (!data.passport_number.trim()) errs.passport_number = 'Passport number is required';
                if (!isValidDmy(isoToDmy(data.passport_expiry))) errs.passport_expiry = 'Passport expiry is required (DD/MM/YYYY)';
                if (!data.issuing_country.trim()) errs.issuing_country = 'Issuing country is required';
                if (!data.current_visa_type) errs.current_visa_type = 'Select your current NZ visa type';
                if (data.current_visa_type === 'Other' && !data.current_visa_other.trim()) errs.current_visa_other = 'Please describe the visa type';
                if (!isValidDmy(isoToDmy(data.current_visa_expiry))) errs.current_visa_expiry = 'Current visa expiry is required (DD/MM/YYYY)';
                if (!isValidDmy(isoToDmy(data.nz_arrival_date))) errs.nz_arrival_date = 'NZ arrival date is required (DD/MM/YYYY)';
                break;
            case 4:
                if (!data.job_title.trim()) errs.job_title = 'Job title is required';
                if (!isValidDmy(isoToDmy(data.employment_start))) errs.employment_start = 'Employment start date is required (DD/MM/YYYY)';
                if (!data.employment_type) errs.employment_type = 'Employment type is required';
                if (!data.hourly_rate.toString().trim()) errs.hourly_rate = 'Hourly rate is required';
                break;
            case 5:
                if (!data.highest_qualification) errs.highest_qualification = 'Highest qualification is required';
                if (data.nzqa_status === 'Yes — IQA completed' && !data.nzqa_iqa_reference.trim()) {
                    errs.nzqa_iqa_reference = 'IQA reference number is required';
                }
                break;
            case 6:
                if (!data.nz_skilled_years.toString().trim()) errs.nz_skilled_years = 'NZ skilled years is required';
                if (!data.total_skilled_years.toString().trim()) errs.total_skilled_years = 'Total years is required';
                break;
            case 7:
                if (!data.english_evidence) errs.english_evidence = 'Please select an English evidence type';
                if (['IELTS', 'TOEFL', 'PTE Academic'].includes(data.english_evidence)) {
                    if (!data.english_test_score.toString().trim()) errs.english_test_score = 'Test score is required';
                    if (!isValidDmy(isoToDmy(data.english_test_date))) errs.english_test_date = 'Test date is required (DD/MM/YYYY)';
                }
                if (!data.include_family) errs.include_family = 'Please indicate whether family will be included';
                if (data.include_family === 'Yes') {
                    if (!data.family_members || data.family_members.length === 0) {
                        errs.family_members = 'Add at least one family member';
                    } else {
                        data.family_members.forEach((m, i) => {
                            if (!m.full_name || !m.full_name.trim()) errs[`family_members.${i}.full_name`] = 'Full name is required';
                            if (!m.relationship) errs[`family_members.${i}.relationship`] = 'Relationship is required';
                        });
                    }
                }
                break;
        }
        return errs;
    };

    const validateRange = (from, to) => {
        const aggregated = {};
        let firstInvalid = null;
        for (let n = from; n <= to; n++) {
            const errs = validateStep(n);
            if (Object.keys(errs).length > 0 && firstInvalid === null) firstInvalid = n;
            Object.assign(aggregated, errs);
        }
        return { aggregated, firstInvalid };
    };

    const scrollFormToTop = () => {
        if (formRef.current) formRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    useEffect(() => {
        if (Object.keys(localErrors).length === 0) return;
        const { aggregated } = validateRange(1, TOTAL_STEPS);
        const next = {};
        let changed = false;
        for (const k of Object.keys(localErrors)) {
            if (aggregated[k]) next[k] = aggregated[k];
            else changed = true;
        }
        if (changed) setLocalErrors(next);
    }, [data]);

    // Record every step the user opens so the stepper can distinguish
    // "visited and valid" (checkmark) from "skipped past" (still a number).
    useEffect(() => {
        setVisitedSteps((prev) => {
            if (prev.has(step)) return prev;
            const next = new Set(prev);
            next.add(step);
            return next;
        });
    }, [step]);

    // Free navigation between steps — the user can move around freely; missing
    // fields are flagged inline when they hit submit, and we auto-jump them to
    // the first step that needs attention.
    const nextStep = () => {
        setLocalErrors({});
        setStep(s => Math.min(s + 1, TOTAL_STEPS));
    };

    const prevStep = () => {
        setLocalErrors({});
        setStep(s => Math.max(s - 1, 1));
    };

    const handleSidebarClick = (id) => {
        if (id === step) return;
        setLocalErrors({});
        setStep(id);
    };

    // Clicking "Submit Intake Form" doesn't post immediately — it walks every
    // step, and if any required fields are missing it jumps the user straight
    // to the first offending step so they can fill it in inline. Confirmation
    // modal only opens once all steps validate.
    const submitFinal = () => {
        const { aggregated, firstInvalid } = validateRange(1, TOTAL_STEPS);
        if (firstInvalid !== null) {
            setLocalErrors(aggregated);
            setStep(firstInvalid);
            toast.error(`Some fields in Step ${firstInvalid} (${steps[firstInvalid - 1].title}) need your attention.`);
            scrollFormToTop();
            return;
        }
        setLocalErrors({});
        setShowConfirm(true);
    };

    // Called from the confirmation modal's "Confirm & Submit" button.
    const confirmSubmit = () => {
        // Convert DD/MM/YYYY values to ISO (YYYY-MM-DD) for the server, and only
        // send document_files entries that actually hold a File.
        transform((d) => ({
            ...d,
            dob:                  dmyToIso(d.dob),
            passport_expiry:      dmyToIso(d.passport_expiry),
            current_visa_expiry:  dmyToIso(d.current_visa_expiry),
            nz_arrival_date:      dmyToIso(d.nz_arrival_date),
            employment_start:     dmyToIso(d.employment_start),
            english_test_date:    dmyToIso(d.english_test_date),
            family_members: (d.family_members || []).map((m) => ({
                ...m,
                dob: dmyToIso(m.dob),
            })),
            document_files: Object.fromEntries(
                Object.entries(d.document_files || {})
                    .map(([k, arr]) => [k, (Array.isArray(arr) ? arr : []).filter((f) => f instanceof File)])
                    .filter(([, arr]) => arr.length > 0)
            ),
        }));

        const submitUrl = isEditing ? `/resident-interest/edit/${editToken}` : '/resident-interest';
        post(submitUrl, {
            forceFormData: true, // always multipart so attached PDFs are sent reliably
            onSuccess: () => {
                setShowConfirm(false);
                setIsSuccess(true);
            },
            onError: (errs) => {
                setShowConfirm(false);
                toast.error(
                    errs?.error
                        || Object.values(errs || {})[0]
                        || 'Submission failed. Please review your details and try again.'
                );
            },
        });
    };

    const handleSubmit = (e) => {
        if (e && e.preventDefault) e.preventDefault();
        if (step !== TOTAL_STEPS) return nextStep();
        submitFinal();
    };

    if (isSuccess) return <SuccessMessage intakeId={intakeId} edited={isEditing} />;

    // Don't paint the form until we've decided whether to resume an existing
    // submission — otherwise the user sees the form flash before the redirect.
    if (!resumeChecked) {
        return (
            <div className="min-h-screen bg-white flex items-center justify-center">
                <p className="text-xs font-bold uppercase tracking-[0.3em] text-gray-400">Loading…</p>
            </div>
        );
    }

    const stepLabel = step < 10 ? `0${step}` : `${step}`;
    const docTotal = Object.keys(data.documents).length;
    const docChecked = Object.values(data.documents).filter(Boolean).length;

    return (
        <div className="min-h-screen bg-[#f8f9fb] font-urbanist text-[#212121] overflow-x-hidden">
            <Head title="NZ Resident Visa — Client Interest" />
            <Navbar />

            {/* Hero Header removed — the navbar + form sidebar give enough
                context; the giant "New Zealand Resident Visa Client Interest"
                hero was duplicating the CTA on /immigration above the
                actual intake form. */}

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32 pt-12">
                {/* 11-step funnel: steps 1-9 live here, 10 (Pay) and 11
                    (Book) on dedicated pages after submit. The stepper
                    component is shared so the funnel feels continuous. */}
                <div className="mb-10">
                    <ResidentIntakeStepper
                        currentStep={step}
                        completedSteps={steps
                            .filter(s => s.id !== step
                                && visitedSteps.has(s.id)
                                && Object.keys(validateStep(s.id)).length === 0)
                            .map(s => s.id)}
                        onStepClick={(id) => { if (id <= TOTAL_STEPS) handleSidebarClick(id); }}
                    />

                    <div className="flex items-center justify-between mt-5 px-1 text-[11px]">
                        <p className="text-gray-400">
                            {isEditing
                                ? 'Your intake is pre-filled — any changes update the original record.'
                                : 'Answers save automatically — close or refresh and pick up where you left off.'}
                        </p>
                        {isEditing ? (
                            <span className="font-medium text-[#00A693] flex-shrink-0 ml-4">Edit mode</span>
                        ) : (
                            <button
                                type="button"
                                onClick={handleStartOver}
                                className="font-medium text-gray-400 hover:text-red-500 transition-colors flex-shrink-0 ml-4"
                            >
                                Start over
                            </button>
                        )}
                    </div>
                </div>

                {/* Form Content */}
                <div className="bg-white rounded-3xl border border-gray-100 shadow-xl shadow-gray-100/80 overflow-hidden">
                        <div className="px-8 pt-8 pb-6 border-b border-gray-50">
                            <div className="flex items-center justify-between mb-4">
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.4em] text-gray-500 mb-1">Section {stepLabel} of {TOTAL_STEPS}</p>
                                    <p className="text-base font-black uppercase tracking-tight text-[#282728]">{steps[step - 1]?.title}</p>
                                </div>
                                <div className="flex items-center gap-1.5 flex-wrap justify-end max-w-[160px]">
                                    {steps.map((s) => (
                                        <div
                                            key={s.id}
                                            className={`transition-all duration-300 rounded-full ${s.id === step ? 'w-5 h-2 bg-[#282728]' : s.id < step ? 'w-2 h-2 bg-[#00A693]' : 'w-2 h-2 bg-gray-100'}`}
                                        />
                                    ))}
                                </div>
                            </div>
                            <div className="h-0.5 w-full bg-gray-100 overflow-hidden">
                                <motion.div
                                    className="h-full bg-[#00A693]"
                                    initial={{ width: 0 }}
                                    animate={{ width: `${(step / TOTAL_STEPS) * 100}%` }}
                                    transition={{ duration: 0.8, ease: 'circOut' }}
                                />
                            </div>
                        </div>

                        <div className="flex-1">
                            <form ref={formRef} onSubmit={handleSubmit} className="h-full flex flex-col">
                                <div className="flex-1 px-8 py-10">
                                    <AnimatePresence mode="wait">
                                        <motion.div
                                            key={step}
                                            initial={{ opacity: 0, y: 14 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            exit={{ opacity: 0, y: -14 }}
                                            transition={{ duration: 0.35, ease: 'easeOut' }}
                                        >
                                            {step === 1 && <StepTerms data={data} setData={setData} errors={localErrors} />}
                                            {step === 2 && <StepPersonal data={data} setData={setData} errors={localErrors} />}
                                            {step === 3 && <StepPassportVisa data={data} setData={setData} errors={localErrors} />}
                                            {step === 4 && <StepEmployment data={data} setData={setData} errors={localErrors} />}
                                            {step === 5 && <StepQualifications data={data} setData={setData} errors={localErrors} />}
                                            {step === 6 && <StepExperience data={data} setData={setData} errors={localErrors} />}
                                            {step === 7 && <StepEnglishFamily data={data} setData={setData} errors={localErrors} />}
                                            {step === 8 && <StepDocuments data={data} setData={setData} docChecked={docChecked} docTotal={docTotal} />}
                                            {step === 9 && <StepDisclosures data={data} setData={setData} />}
                                        </motion.div>
                                    </AnimatePresence>
                                </div>

                                <div className="flex items-center justify-between px-8 py-6 border-t border-gray-50 bg-gray-50/40">
                                    <button
                                        type="button"
                                        onClick={prevStep}
                                        disabled={step === 1}
                                        className={`flex items-center gap-2 px-6 py-2.5 text-xs font-bold uppercase tracking-[0.2em] transition-all border ${step === 1 ? 'opacity-0 cursor-default border-transparent' : 'border-gray-300 text-gray-500 hover:border-[#282728] hover:text-[#282728]'}`}
                                    >
                                        <ChevronLeft size={13} /> Back
                                    </button>

                                    <div className="flex items-center gap-3">
                                        <span className="text-xs font-black uppercase tracking-[0.3em] text-gray-300">{stepLabel} / {TOTAL_STEPS}</span>
                                        {step < TOTAL_STEPS ? (
                                            <button
                                                type="button"
                                                onClick={nextStep}
                                                className="group flex items-center gap-2 px-8 py-2.5 bg-[#282728] text-white text-xs font-bold uppercase tracking-[0.2em] transition-all hover:bg-[#00A693] active:scale-95"
                                            >
                                                Continue <ChevronRight size={13} className="transition-transform group-hover:translate-x-0.5" />
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={submitFinal}
                                                disabled={processing}
                                                className="flex items-center gap-2 px-8 py-2.5 bg-[#00A693] text-white text-xs font-bold uppercase tracking-[0.2em] transition-all hover:bg-[#008c7c] active:scale-95 disabled:opacity-60"
                                            >
                                                <Send size={13} />
                                                {processing
                                                    ? (isEditing ? 'Saving...' : 'Submitting...')
                                                    : (isEditing ? 'Save Changes' : 'Submit')}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
            </main>
            <Footer />

            {/* ── Persistent post-submit thank-you modal ────────────────── */}
            <IntakeSuccessModal
                open={showSuccess}
                onClose={() => setShowSuccess(false)}
                visaLabel={flash?.intake_submitted || 'Resident Visa (SMC)'}
            />

            {/* ── Pre-submit confirmation modal ─────────────────────────── */}
            <AnimatePresence>
                {showConfirm && (
                    <div
                        className="fixed inset-0 z-[110] flex items-center justify-center p-4 sm:p-6 bg-[#282728]/60 backdrop-blur-sm"
                        onClick={() => { if (!processing) setShowConfirm(false); }}
                    >
                        <motion.div
                            initial={{ scale: 0.92, opacity: 0, y: 24 }}
                            animate={{ scale: 1, opacity: 1, y: 0 }}
                            exit={{ scale: 0.92, opacity: 0, y: 24 }}
                            transition={{ duration: 0.3, ease: [0.16, 1, 0.3, 1] }}
                            onClick={(e) => e.stopPropagation()}
                            className="w-full max-w-[520px] overflow-hidden rounded-2xl bg-white max-h-[90vh] overflow-y-auto"
                        >
                            {/* ── Light "review" header — intentionally NOT a
                                dark success-style hero, to avoid the previous
                                UX issue of clients thinking they had already
                                submitted at this point. ── */}
                            <div className="bg-amber-50/70 border-b border-amber-100 px-8 pt-8 pb-6">
                                <div className="flex items-center justify-end mb-4">
                                    <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700/60">
                                        Last step
                                    </span>
                                </div>
                                <div className="flex items-start gap-4">
                                    <div className="w-11 h-11 bg-white border border-amber-200 rounded-xl flex items-center justify-center text-amber-700 flex-shrink-0">
                                        <Eye size={20} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-black text-[#282728] tracking-tight mb-1">
                                            {isEditing ? 'Review your changes' : 'Review your details before submitting'}
                                        </h3>
                                        <p className="text-xs text-gray-600 leading-relaxed">
                                            {isEditing
                                                ? 'Take a quick look below. Nothing is saved until you click "Save changes".'
                                                : 'Take a quick look below. Nothing is submitted until you click "Submit intake".'}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* ── White body ── */}
                            <div className="bg-white px-8 py-8">
                                {/* Legal notice */}
                                <div className="flex items-start gap-3 bg-gray-50 border border-gray-100 rounded-lg p-4 mb-6">
                                    <AlertCircle size={16} className="text-[#00A693] flex-shrink-0 mt-0.5" />
                                    <p className="text-xs text-gray-600 leading-relaxed">
                                        By submitting this intake you confirm the information you have provided is{' '}
                                        <span className="text-[#282728] font-bold">true, complete and accurate</span>.
                                        Providing false or misleading information may affect your visa eligibility.
                                    </p>
                                </div>

                                {/* Summary grid */}
                                <div className="mb-6">
                                    <span className="text-[9px] font-black uppercase tracking-[0.3em] text-gray-400 mb-3 block">Your details</span>
                                    <div className="border border-gray-100 rounded-lg divide-y divide-gray-100">
                                        {[
                                            ['Name', `${data.first_name} ${data.last_name}`.trim()],
                                            ['Email', data.email],
                                            ['Phone', data.phone],
                                            ['Visa Type', data.current_visa_type === 'Other' ? `Other — ${data.current_visa_other}` : data.current_visa_type],
                                            ['Job Title', data.job_title],
                                        ].map(([label, value]) => (
                                            <div key={label} className="flex items-center justify-between gap-4 px-4 py-3">
                                                <span className="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 flex-shrink-0">{label}</span>
                                                <span className="text-sm text-[#282728] font-medium text-right truncate">{value || '—'}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Doc stats */}
                                <div className="flex items-center gap-6 mb-8">
                                    <div className="flex items-center gap-2">
                                        <div className="w-2 h-2 rounded-full bg-[#00A693]" />
                                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-500">
                                            {docChecked}/{docTotal} docs ticked
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="w-2 h-2 rounded-full bg-[#282728]" />
                                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-500">
                                            {Object.keys(data.document_files || {}).reduce((n, k) => n + ((data.document_files?.[k] || []).filter(f => f instanceof File)).length, 0)} files attached
                                        </span>
                                    </div>
                                </div>

                                {/* Action buttons */}
                                <div className="flex flex-col-reverse sm:flex-row gap-3">
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirm(false)}
                                        disabled={processing}
                                        className="flex-1 py-4 rounded-xl border border-gray-200 text-[#282728] text-[10px] font-black uppercase tracking-[0.25em] hover:border-[#282728] transition-all active:scale-[0.98] disabled:opacity-50"
                                    >
                                        Keep editing
                                    </button>
                                    <button
                                        type="button"
                                        onClick={confirmSubmit}
                                        disabled={processing}
                                        className="flex-1 py-4 rounded-xl bg-[#282728] text-white text-[10px] font-black uppercase tracking-[0.25em] hover:bg-[#00A693] transition-all active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2 shadow-xl shadow-[#282728]/15"
                                    >
                                        <Send size={12} />
                                        {processing
                                            ? (isEditing ? 'Saving...' : 'Submitting...')
                                            : (isEditing ? 'Save changes' : 'Submit intake')}
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>
        </div>
    );
}

/* ─── Helper components ──────────────────────────────────────────────── */

function Field({ label, error, hint, children, full }) {
    const hasError = !!error;
    return (
        <div className={`space-y-2 ${full ? 'col-span-full' : ''}`}>
            <div className="flex justify-between items-end px-1">
                <label className={`text-xs font-bold uppercase tracking-[0.2em] transition-colors ${hasError ? 'text-red-500' : 'text-gray-500'}`}>
                    {label}
                </label>
                {hint && <span className="text-[10px] text-gray-400 normal-case tracking-normal">{hint}</span>}
            </div>
            {children}
            {hasError && (
                <p className="text-[10px] font-black text-red-500 uppercase tracking-widest mt-1.5 pl-1">{error}</p>
            )}
        </div>
    );
}

const inputCls = "w-full bg-transparent border-b border-gray-200 py-3 text-[#282728] focus:outline-none focus:border-[#00A693] transition-colors";

function PillRadio({ value, current, onClick, children }) {
    const active = current === value;
    return (
        <button
            type="button"
            onClick={onClick}
            className={`px-5 py-2.5 rounded-lg text-xs font-bold uppercase tracking-widest border transition-all ${active ? 'bg-[#282728] text-white border-[#282728]' : 'bg-white text-gray-500 border-gray-200 hover:border-[#00A693] hover:text-[#00A693]'}`}
        >
            {children}
        </button>
    );
}

/* ─── Step components ────────────────────────────────────────────────── */

function StepTerms({ data, setData, errors }) {
    return (
        <div className="space-y-10">
            <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter leading-tight">Privacy & Terms</h2>
            <div className="bg-gray-50/50 rounded-3xl p-10 text-sm text-gray-600 leading-[2] font-medium h-80 overflow-y-auto border border-gray-100/50 space-y-4">
                <p>This Skilled Migrant Category (SMC) Resident Visa intake form collects the information our licensed advisers need to assess your eligibility prior to engagement.</p>
                <p>Information you provide will be held in confidence and used only to prepare your engagement agreement and assess your visa pathway.</p>
                <p>Submitting this form does not constitute legal advice or an engagement. A formal engagement agreement will be issued upon review.</p>
                <p>All data is handled in line with our Privacy Policy and IAA Code of Conduct.</p>
            </div>
            <label className={`flex items-center gap-5 p-4 cursor-pointer group rounded-2xl transition-all ${errors.terms_accepted ? 'bg-red-50 ring-2 ring-red-500/20' : ''}`}>
                <input
                    type="checkbox"
                    className={`w-6 h-6 rounded ${errors.terms_accepted ? 'border-red-500' : 'border-gray-300'} text-[#00A693] focus:ring-[#00A693] cursor-pointer`}
                    checked={data.terms_accepted}
                    onChange={e => setData('terms_accepted', e.target.checked)}
                />
                <span className={`text-sm font-black uppercase tracking-[0.15em] ${errors.terms_accepted ? 'text-red-500' : 'text-[#282728]'}`}>
                    I have read and agree to the intake terms *
                </span>
            </label>
            {errors.terms_accepted && <p className="text-sm font-black text-red-500 uppercase tracking-widest mt-2 pl-4">{errors.terms_accepted}</p>}
        </div>
    );
}

function StepPersonal({ data, setData, errors }) {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Personal Details</h2>
                <p className="text-sm text-gray-500">As per your passport.</p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Field label="First Name *" error={errors.first_name} hint="As per passport">
                    <input type="text" className={inputCls} value={data.first_name} onChange={e => setData('first_name', e.target.value)} />
                </Field>
                <Field label="Last Name *" error={errors.last_name} hint="As per passport">
                    <input type="text" className={inputCls} value={data.last_name} onChange={e => setData('last_name', e.target.value)} />
                </Field>
                <Field label="Date of Birth *" error={errors.dob}>
                    <DateField className={inputCls} value={data.dob} onChange={(v) => setData('dob', v)} />
                </Field>
                <Field label="Nationality *" error={errors.nationality} hint="Country of citizenship">
                    <input type="text" className={inputCls} value={data.nationality} onChange={e => setData('nationality', e.target.value)} />
                </Field>
                <Field label="Email Address *" error={errors.email}>
                    <input type="email" className={inputCls} placeholder="email@example.com" value={data.email} onChange={e => setData('email', e.target.value)} />
                </Field>
                <Field label="Phone Number *" error={errors.phone}>
                    <input type="tel" className={inputCls} placeholder="+64 ..." value={data.phone} onChange={e => setData('phone', e.target.value)} />
                </Field>
            </div>
        </div>
    );
}

function StepPassportVisa({ data, setData, errors }) {
    const visaTypes = ['AEWV', 'Essential Skills', 'Work to Residence', 'Other'];
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Passport & Visa Details</h2>
                <p className="text-sm text-gray-500">Travel document and current NZ immigration status.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Field label="Passport Number *" error={errors.passport_number}>
                    <input type="text" className={inputCls} placeholder="e.g. A12345678" value={data.passport_number} onChange={e => setData('passport_number', e.target.value)} />
                </Field>
                <Field label="Passport Expiry *" error={errors.passport_expiry}>
                    <DateField className={inputCls} value={data.passport_expiry} onChange={(v) => setData('passport_expiry', v)} />
                </Field>
                <Field label="Issuing Country *" error={errors.issuing_country} full>
                    <input type="text" className={inputCls} value={data.issuing_country} onChange={e => setData('issuing_country', e.target.value)} />
                </Field>
            </div>

            <Field label="Current NZ Visa Type *" error={errors.current_visa_type}>
                <div className="flex flex-wrap gap-3 mt-2">
                    {visaTypes.map(v => (
                        <PillRadio key={v} value={v} current={data.current_visa_type} onClick={() => setData('current_visa_type', v)}>{v}</PillRadio>
                    ))}
                </div>
            </Field>

            {data.current_visa_type === 'Other' && (
                <Field label="Please specify *" error={errors.current_visa_other}>
                    <input type="text" className={inputCls} value={data.current_visa_other} onChange={e => setData('current_visa_other', e.target.value)} />
                </Field>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Field label="Current Visa Expiry Date *" error={errors.current_visa_expiry}>
                    <DateField className={inputCls} value={data.current_visa_expiry} onChange={(v) => setData('current_visa_expiry', v)} />
                </Field>
                <Field label="NZ Arrival Date *" error={errors.nz_arrival_date}>
                    <DateField className={inputCls} value={data.nz_arrival_date} onChange={(v) => setData('nz_arrival_date', v)} />
                </Field>
            </div>

            <Field label="Previous NZ Visa History" hint="List any prior visa types and dates">
                <textarea
                    rows={4}
                    className="w-full bg-gray-50/50 border border-gray-100 rounded-xl p-4 text-sm text-[#282728] focus:outline-none focus:border-[#00A693] transition-colors"
                    placeholder="e.g. Student Visa 2019–2021, Work Visa 2021–present"
                    value={data.previous_nz_visa_history}
                    onChange={e => setData('previous_nz_visa_history', e.target.value)}
                />
            </Field>
        </div>
    );
}

function StepEmployment({ data, setData, errors }) {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Employment</h2>
                <p className="text-sm text-gray-500">Your current role in New Zealand.</p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Field label="Job Title *" error={errors.job_title}>
                    <input type="text" className={inputCls} value={data.job_title} onChange={e => setData('job_title', e.target.value)} />
                </Field>
                <Field label="Employment Start Date *" error={errors.employment_start}>
                    <DateField className={inputCls} value={data.employment_start} onChange={(v) => setData('employment_start', v)} />
                </Field>
                <Field label="Employment Type *" error={errors.employment_type}>
                    <select className={inputCls} value={data.employment_type} onChange={e => setData('employment_type', e.target.value)}>
                        <option value="">Select...</option>
                        <option value="Permanent full-time">Permanent full-time</option>
                        <option value="Fixed-term (12+ months)">Fixed-term (12+ months)</option>
                        <option value="Contract">Contract</option>
                    </select>
                </Field>
                <Field label="Hourly Rate (NZD) *" error={errors.hourly_rate} hint="Median: $35.00">
                    <input type="number" step="0.01" min="0" className={inputCls} placeholder="e.g. 45.00" value={data.hourly_rate} onChange={e => setData('hourly_rate', e.target.value)} />
                </Field>
            </div>
        </div>
    );
}

function StepQualifications({ data, setData, errors }) {
    const nzqaOptions = [
        { value: 'Yes — IQA completed', label: 'Yes — IQA completed' },
        { value: 'No', label: 'No' },
        { value: 'On LQEA (exempt)', label: 'On LQEA (exempt)' },
        { value: 'Not sure', label: 'Not sure' },
    ];
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Qualifications</h2>
                <p className="text-sm text-gray-500">Your highest completed qualification.</p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Field label="Highest Qualification *" error={errors.highest_qualification}>
                    <select className={inputCls} value={data.highest_qualification} onChange={e => setData('highest_qualification', e.target.value)}>
                        <option value="">Select...</option>
                        <option value="phd">Doctoral degree (PhD) — Level 10</option>
                        <option value="masters">Master's degree — Level 9</option>
                        <option value="postgrad">Postgraduate diploma/certificate — Level 8</option>
                        <option value="bachelor">Bachelor's degree — Level 7</option>
                        <option value="diploma">Diploma/Certificate — Level 5–6</option>
                        <option value="none">No tertiary qualification</option>
                    </select>
                </Field>
                <Field label="Institution Name" hint="University / polytechnic">
                    <input type="text" className={inputCls} value={data.institution_name} onChange={e => setData('institution_name', e.target.value)} />
                </Field>
                <Field label="Country of Study" hint="New Zealand or overseas" full>
                    <input type="text" className={inputCls} value={data.country_of_study} onChange={e => setData('country_of_study', e.target.value)} />
                </Field>
            </div>

            <Field label="Has the qualification been assessed by NZQA (IQA)?">
                <div className="flex flex-wrap gap-3 mt-2">
                    {nzqaOptions.map(o => (
                        <PillRadio key={o.value} value={o.value} current={data.nzqa_status} onClick={() => setData('nzqa_status', o.value)}>
                            {o.label}
                        </PillRadio>
                    ))}
                </div>
            </Field>

            {data.nzqa_status === 'Yes — IQA completed' && (
                <Field label="NZQA IQA Reference Number *" error={errors.nzqa_iqa_reference}>
                    <input
                        type="text"
                        className={inputCls}
                        placeholder="IQA-XXXXXXXX"
                        value={data.nzqa_iqa_reference}
                        onChange={e => setData('nzqa_iqa_reference', e.target.value)}
                    />
                </Field>
            )}
        </div>
    );
}

function StepExperience({ data, setData, errors }) {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Work Experience</h2>
                <p className="text-sm text-gray-500">Skilled experience in NZ and overseas.</p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Field label="Years of NZ Skilled Work Experience *" error={errors.nz_skilled_years}>
                    <input type="number" step="0.5" min="0" className={inputCls} placeholder="e.g. 2" value={data.nz_skilled_years} onChange={e => setData('nz_skilled_years', e.target.value)} />
                </Field>
                <Field label="Total Years Skilled Work Experience *" error={errors.total_skilled_years}>
                    <input type="number" step="0.5" min="0" className={inputCls} placeholder="e.g. 7" value={data.total_skilled_years} onChange={e => setData('total_skilled_years', e.target.value)} />
                </Field>
            </div>
            <Field label="Brief Career Summary" hint="NZ and overseas history">
                <textarea
                    rows={5}
                    className="w-full bg-gray-50/50 border border-gray-100 rounded-xl p-4 text-sm text-[#282728] focus:outline-none focus:border-[#00A693] transition-colors"
                    placeholder="e.g. 3 years as software engineer in India, 2 years as senior developer in Auckland..."
                    value={data.career_summary}
                    onChange={e => setData('career_summary', e.target.value)}
                />
            </Field>
        </div>
    );
}

function StepEnglishFamily({ data, setData, errors }) {
    const options = ['IELTS', 'TOEFL', 'PTE Academic', 'Passport (exempt country)', 'Occupational registration'];
    return (
        <div className="space-y-12">
            <section className="space-y-8">
                <div>
                    <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">English Language</h2>
                    <p className="text-sm text-gray-500">Evidence you can rely on for the application.</p>
                </div>
                <Field label="English Language Evidence *" error={errors.english_evidence}>
                    <div className="flex flex-wrap gap-3 mt-2">
                        {options.map(o => (
                            <PillRadio key={o} value={o} current={data.english_evidence} onClick={() => setData('english_evidence', o)}>{o}</PillRadio>
                        ))}
                    </div>
                </Field>

                {['IELTS', 'TOEFL', 'PTE Academic'].includes(data.english_evidence) && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <Field label="Test Score / Band *" error={errors.english_test_score}>
                            <input
                                type="text"
                                className={inputCls}
                                placeholder="e.g. 7.0"
                                value={data.english_test_score}
                                onChange={e => setData('english_test_score', e.target.value)}
                            />
                        </Field>
                        <Field label="Test Date *" error={errors.english_test_date}>
                            <DateField
                                className={inputCls}
                                value={data.english_test_date}
                                onChange={(v) => setData('english_test_date', v)}
                            />
                        </Field>
                    </div>
                )}
            </section>

            <section className="space-y-8 pt-8 border-t border-gray-100">
                <div>
                    <h2 className="text-2xl font-black text-[#282728] uppercase tracking-tighter mb-3">Family Members to Include</h2>
                    <p className="text-sm text-gray-500">Indicate if family will be part of this application.</p>
                </div>
                <Field label="Will any family members be included in this application? *" error={errors.include_family}>
                    <div className="flex flex-wrap gap-3 mt-2">
                        <PillRadio
                            value="No — applying alone"
                            current={data.include_family}
                            onClick={() => {
                                setData((prev) => ({ ...prev, include_family: 'No — applying alone', family_members: [] }));
                            }}
                        >
                            No — applying alone
                        </PillRadio>
                        <PillRadio
                            value="Yes"
                            current={data.include_family}
                            onClick={() => {
                                setData((prev) => ({
                                    ...prev,
                                    include_family: 'Yes',
                                    family_members: prev.family_members && prev.family_members.length > 0
                                        ? prev.family_members
                                        : [{ full_name: '', relationship: '', dob: '', passport_number: '' }],
                                }));
                            }}
                        >
                            Yes
                        </PillRadio>
                    </div>
                </Field>

                {data.include_family === 'Yes' && (
                    <FamilyMembersList data={data} setData={setData} errors={errors} />
                )}
            </section>
        </div>
    );
}

function FamilyMembersList({ data, setData, errors }) {
    const members = data.family_members || [];

    const updateMember = (i, key, value) => {
        const next = members.map((m, idx) => (idx === i ? { ...m, [key]: value } : m));
        setData('family_members', next);
    };

    const addMember = () => {
        setData('family_members', [
            ...members,
            { full_name: '', relationship: '', dob: '', passport_number: '' },
        ]);
    };

    const removeMember = (i) => {
        setData('family_members', members.filter((_, idx) => idx !== i));
    };

    return (
        <div className="space-y-5">
            {errors.family_members && (
                <p className="text-[10px] font-black text-red-500 uppercase tracking-widest">{errors.family_members}</p>
            )}

            {members.map((m, i) => (
                <div key={i} className="rounded-2xl border border-gray-100 bg-gray-50/40 p-6">
                    <div className="flex items-center justify-between mb-5">
                        <h3 className="text-sm font-black uppercase tracking-[0.2em] text-[#00A693]">
                            Family Member {i + 1}
                        </h3>
                        {members.length > 1 && (
                            <button
                                type="button"
                                onClick={() => removeMember(i)}
                                className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-400 hover:text-red-500 transition-colors"
                            >
                                <Trash2 size={12} /> Remove
                            </button>
                        )}
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <Field label="Full Name" hint="As per passport" error={errors[`family_members.${i}.full_name`]}>
                            <input
                                type="text"
                                className={inputCls}
                                value={m.full_name || ''}
                                onChange={(e) => updateMember(i, 'full_name', e.target.value)}
                            />
                        </Field>
                        <Field label="Relationship" error={errors[`family_members.${i}.relationship`]}>
                            <select
                                className={inputCls}
                                value={m.relationship || ''}
                                onChange={(e) => updateMember(i, 'relationship', e.target.value)}
                            >
                                <option value="">Select...</option>
                                <option value="Spouse / partner">Spouse / partner</option>
                                <option value="Dependent child">Dependent child</option>
                            </select>
                        </Field>
                        <Field label="Date of Birth">
                            <DateField
                                className={inputCls}
                                value={m.dob || ''}
                                onChange={(v) => updateMember(i, 'dob', v)}
                            />
                        </Field>
                        <Field label="Passport Number">
                            <input
                                type="text"
                                className={inputCls}
                                placeholder="Passport number"
                                value={m.passport_number || ''}
                                onChange={(e) => updateMember(i, 'passport_number', e.target.value)}
                            />
                        </Field>
                    </div>
                </div>
            ))}

            <button
                type="button"
                onClick={addMember}
                className="inline-flex items-center gap-2 px-5 py-3 border border-dashed border-gray-300 text-[11px] font-bold uppercase tracking-[0.2em] text-gray-600 hover:border-[#00A693] hover:text-[#00A693] hover:bg-[#00A693]/5 rounded-xl transition-all"
            >
                <Plus size={14} /> Add Family Member
            </button>
        </div>
    );
}

function StepDocuments({ data, setData, docChecked, docTotal }) {
    const items = [
        { key: 'passport', title: 'Passport', desc: 'Valid passport, all pages including blank pages' },
        { key: 'visa_copies', title: 'All NZ visa copies', desc: 'Every visa label or e-visa received in New Zealand' },
        { key: 'contracts', title: 'All NZ employment contracts + job description', desc: 'Original contract and any variations or renewals' },
        { key: 'payslips', title: 'Payslips — first 2 months + latest 1 month', desc: '3 payslips total showing gross/net pay and hours' },
        { key: 'ird_summary', title: 'IRD summary of earnings (monthly breakdown)', desc: 'Available from myIR — select monthly breakdown version' },
        { key: 'education_certs', title: 'Education certificates, transcripts, graduation documents', desc: 'All degrees and postgraduate qualifications if applicable' },
        { key: 'cv', title: 'CV covering both NZ and overseas employment', desc: 'Dates, employer names, roles and key responsibilities' },
    ];

    const MAX_BYTES = 10 * 1024 * 1024; // 10 MB per file
    const [fileErrors, setFileErrors] = useState({});

    const toggle = (k) => setData('documents', { ...data.documents, [k]: !data.documents[k] });

    const clearFileError = (k) => setFileErrors((p) => { const n = { ...p }; delete n[k]; return n; });

    // Accept one *or more* PDFs and append them to whatever's already attached for that key.
    const handleFiles = (k, fileList) => {
        const incoming = Array.from(fileList || []);
        if (incoming.length === 0) return;

        const accepted = [];
        let firstError = null;
        for (const file of incoming) {
            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            if (!isPdf) { firstError = firstError || `${file.name}: only PDFs are accepted.`; continue; }
            if (file.size > MAX_BYTES) { firstError = firstError || `${file.name}: file is larger than 10 MB.`; continue; }
            accepted.push(file);
        }

        if (firstError) setFileErrors((p) => ({ ...p, [k]: firstError }));
        else clearFileError(k);

        if (accepted.length === 0) return;

        setData((prev) => ({
            ...prev,
            document_files: {
                ...prev.document_files,
                [k]: [...(prev.document_files?.[k] || []), ...accepted],
            },
            // a non-"other" upload also satisfies the checklist tick
            ...(k !== 'other'
                ? { documents: { ...prev.documents, [k]: true } }
                : null),
        }));
    };

    const removeFileAt = (k, index) => {
        clearFileError(k);
        setData((prev) => ({
            ...prev,
            document_files: {
                ...prev.document_files,
                [k]: (prev.document_files?.[k] || []).filter((_, i) => i !== index),
            },
        }));
    };

    const formatSize = (bytes) => {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    };

    const filesOf = (k) => (data.document_files?.[k] || []).filter((f) => f instanceof File);
    const uploadedCount = items.reduce((n, it) => n + (filesOf(it.key).length > 0 ? 1 : 0), 0);
    const totalFiles = Object.keys(data.document_files || {}).reduce((n, k) => n + filesOf(k).length, 0);
    const otherFiles = filesOf('other');

    const renderFileChip = (k, f, index) => (
        <div key={index} className="inline-flex items-center gap-3 bg-white border border-gray-200 rounded-xl px-3 py-2 max-w-full">
            <FileCheck2 size={14} className="text-[#00A693] flex-shrink-0" />
            <span className="text-xs text-gray-700 truncate max-w-[200px]">{f.name}</span>
            <span className="text-[10px] text-gray-400 flex-shrink-0">{formatSize(f.size)}</span>
            <button
                type="button"
                onClick={() => removeFileAt(k, index)}
                className="text-[10px] font-bold uppercase tracking-widest text-gray-400 hover:text-red-500 transition-colors flex-shrink-0"
            >
                Remove
            </button>
        </div>
    );

    return (
        <div className="space-y-8">
            <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Document Checklist</h2>
                    <p className="text-sm text-gray-500">Tick each item you have, and attach one or more PDFs where you can — uploads (max 10 MB each) speed up your assessment.</p>
                </div>
                <div className="flex items-center gap-8">
                    <div className="text-center">
                        <div className="text-3xl font-black text-[#00A693]">{docChecked}<span className="text-base text-gray-300"> / {docTotal}</span></div>
                        <span className="text-[10px] font-bold uppercase tracking-[0.3em] text-gray-400">ticked</span>
                    </div>
                    <div className="text-center">
                        <div className="text-3xl font-black text-[#282728]">{totalFiles}</div>
                        <span className="text-[10px] font-bold uppercase tracking-[0.3em] text-gray-400">{totalFiles === 1 ? 'file' : 'files'}</span>
                    </div>
                </div>
            </div>

            <div className="space-y-3">
                {items.map((it) => {
                    const checked = !!data.documents[it.key];
                    const files = filesOf(it.key);
                    const err = fileErrors[it.key];
                    const active = checked || files.length > 0;
                    return (
                        <div
                            key={it.key}
                            className={`p-5 border rounded-2xl transition-all ${active ? 'border-[#00A693] bg-[#00A693]/5' : 'border-gray-100 bg-white'}`}
                        >
                            <div className="flex items-start gap-4">
                                <input
                                    type="checkbox"
                                    className="w-5 h-5 mt-0.5 text-[#00A693] focus:ring-[#00A693] cursor-pointer rounded"
                                    checked={checked}
                                    onChange={() => toggle(it.key)}
                                />
                                <div className="flex-1 min-w-0">
                                    <div className="text-sm font-bold uppercase tracking-wide leading-tight text-[#282728]">{it.title}</div>
                                    <p className="text-xs text-gray-500 mt-1 leading-relaxed">{it.desc}</p>

                                    {files.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {files.map((f, i) => renderFileChip(it.key, f, i))}
                                        </div>
                                    )}

                                    <div className="mt-3">
                                        <label className="inline-flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-xl text-[11px] font-bold uppercase tracking-[0.15em] text-gray-600 hover:border-[#00A693] hover:text-[#00A693] hover:bg-[#00A693]/5 cursor-pointer transition-all">
                                            <Plus size={14} /> {files.length > 0 ? 'Attach another PDF' : 'Attach PDF'}
                                            <input
                                                type="file"
                                                accept="application/pdf,.pdf"
                                                multiple
                                                className="hidden"
                                                onChange={(e) => { handleFiles(it.key, e.target.files); e.target.value = ''; }}
                                            />
                                        </label>
                                        {err && <p className="text-[10px] font-black text-red-500 uppercase tracking-widest mt-2">{err}</p>}
                                    </div>
                                </div>
                                {active && <CheckCircle size={18} className="text-[#00A693] flex-shrink-0 mt-0.5" />}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* ── Other supporting documents ───────────────────────────── */}
            <div className={`rounded-2xl border-2 border-dashed p-5 ${otherFiles.length > 0 ? 'border-[#00A693] bg-[#00A693]/5' : 'border-gray-200 bg-gray-50/50'}`}>
                <div className="flex items-start gap-4">
                    <div className="w-9 h-9 rounded-xl bg-white border border-gray-200 flex items-center justify-center flex-shrink-0">
                        <FileCheck2 size={16} className="text-[#00A693]" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="text-sm font-bold uppercase tracking-wide text-[#282728]">Other supporting documents</div>
                        <p className="text-xs text-gray-500 mt-1 leading-relaxed">
                            Anything else worth sharing — reference letters, NZQA assessments, marriage / birth certificates, supplementary payslips, etc. PDFs only, max 10 MB each.
                        </p>

                        {otherFiles.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-2">
                                {otherFiles.map((f, i) => renderFileChip('other', f, i))}
                            </div>
                        )}

                        <div className="mt-3">
                            <label className="inline-flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-xl text-[11px] font-bold uppercase tracking-[0.15em] text-gray-600 hover:border-[#00A693] hover:text-[#00A693] hover:bg-[#00A693]/5 cursor-pointer transition-all bg-white">
                                <Plus size={14} /> {otherFiles.length > 0 ? 'Add another document' : 'Add documents'}
                                <input
                                    type="file"
                                    accept="application/pdf,.pdf"
                                    multiple
                                    className="hidden"
                                    onChange={(e) => { handleFiles('other', e.target.files); e.target.value = ''; }}
                                />
                            </label>
                            {fileErrors.other && <p className="text-[10px] font-black text-red-500 uppercase tracking-widest mt-2">{fileErrors.other}</p>}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function StepDisclosures({ data, setData }) {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-3">Additional Information</h2>
                <p className="text-sm text-gray-500">Anything else your adviser should know.</p>
            </div>
            <Field label="Character / Health Matters to Disclose" hint="Confidential">
                <textarea
                    rows={4}
                    className="w-full bg-gray-50/50 border border-gray-100 rounded-xl p-4 text-sm text-[#282728] focus:outline-none focus:border-[#00A693] transition-colors"
                    placeholder="Please disclose any relevant character or health matters. These will be assessed in confidence."
                    value={data.character_health_disclosure}
                    onChange={e => setData('character_health_disclosure', e.target.value)}
                />
            </Field>
            <Field label="Other Notes or Questions for Your Adviser">
                <textarea
                    rows={4}
                    className="w-full bg-gray-50/50 border border-gray-100 rounded-xl p-4 text-sm text-[#282728] focus:outline-none focus:border-[#00A693] transition-colors"
                    placeholder="Anything else you'd like us to know before we send the engagement agreement..."
                    value={data.other_notes}
                    onChange={e => setData('other_notes', e.target.value)}
                />
            </Field>
        </div>
    );
}

function SuccessMessage({ intakeId, edited = false }) {
    return (
        <div className="min-h-screen bg-white flex items-center justify-center p-6 font-urbanist">
            <div className="max-w-[480px] w-full bg-white rounded-[3rem] shadow-[0_64px_128px_-24px_rgba(40,39,40,0.08)] p-16 text-center border border-[#282728]/5">
                <motion.div
                    initial={{ scale: 0.8, opacity: 0 }}
                    animate={{ scale: 1, opacity: 1 }}
                    className="w-24 h-24 bg-[#00A693] rounded-[2.5rem] flex items-center justify-center text-white mx-auto mb-10 shadow-2xl shadow-[#00A693]/20"
                >
                    <CheckCircle size={48} />
                </motion.div>
                <h2 className="text-3xl font-black text-[#282728] uppercase tracking-tighter mb-6">
                    {edited ? 'Changes Saved' : 'Interest Received'}
                </h2>
                <p className="text-gray-500 text-sm leading-[2] mb-10 font-medium px-4">
                    {edited
                        ? 'Thank you. Your updated details and any new documents have been saved. Your licensed adviser will see the latest information.'
                        : 'Thank you. Your Resident Visa interest has been received. A licensed adviser will review your information and follow up with your engagement agreement.'}
                </p>
                {intakeId && (
                    <div className="bg-gray-50/50 rounded-2xl p-6 mb-8 border border-gray-100/50">
                        <p className="text-xs font-black text-gray-300 uppercase tracking-[0.4em] mb-2">Reference ID</p>
                        <p className="text-base font-mono font-black text-[#282728]">{intakeId}</p>
                    </div>
                )}
                <a href="/" className="inline-block w-full bg-[#282728] text-white py-6 rounded-2xl text-xs font-black uppercase tracking-[0.4em] shadow-2xl shadow-[#282728]/10 hover:bg-black transition-all active:scale-95">
                    Return to Portal
                </a>
            </div>
        </div>
    );
}
