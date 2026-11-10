import React, { useState, useRef } from 'react';
import { Head, useForm } from '@inertiajs/react';
import {
    CheckCircle, AlertCircle, ChevronRight, Lock, Calendar, MapPin,
    Mail, Phone, Clock, FileText, Users, ArrowRight, X,
} from 'lucide-react';
import { motion } from 'framer-motion';
import assessImg from "@assets/Services/education.png";
import consultImg from "@assets/Services/agents.png";

// Public event registration page — served at /register/{event_code}.
// Monochrome dark-gray/white treatment: a solid dark hero block, a
// generous white form card, and quiet supporting cards in the sidebar.
// No coloured accents — the brand reads as understated and professional.

const DARK = '#1a1a1a';

// Section heading subtitles — small copy under each section title in
// the form. Keys match the canonical section names; sections not listed
// (e.g. admin-created custom sections) just don't get a subtitle.
const SECTION_SUBTITLES = {
    'Personal information': 'Who should we expect at the session?',
    'Education':            'Your study background helps us tailor the pathway.',
    'Background':            'A bit about your current situation.',
    'NZ pathway':            "Tell us about your move and the timeline you're working to.",
    'Anything else?':        'Optional — share questions or goals you want covered.',
};

// Frontend fallback schema. Mirrors Event::DEFAULT_FIELDS so the page
// keeps working if an event somehow ships without `effective_fields`
// in its props (legacy event row, controller bypass, etc.).
const CANONICAL_FIELDS = [
    { key: 'first_name', label: 'First name', type: 'text', required: true, locked: true, enabled: true, placeholder: 'e.g. Maria', section: 'Personal information', order: 1 },
    { key: 'last_name',  label: 'Last name',  type: 'text', required: true, locked: true, enabled: true, placeholder: 'e.g. Santos', section: 'Personal information', order: 2 },
    { key: 'email',      label: 'Email address', type: 'email', required: true, locked: true, enabled: true, placeholder: 'you@example.com', section: 'Personal information', order: 3 },
    { key: 'phone',      label: 'Phone number', type: 'tel', required: true, locked: true, enabled: true, placeholder: '+63 …', section: 'Personal information', order: 4 },
    { key: 'city',       label: 'City', type: 'text', required: true, enabled: true, placeholder: 'e.g. Davao', section: 'Personal information', order: 5 },
    { key: 'country',    label: 'Country', type: 'text', required: true, enabled: true, placeholder: 'e.g. Philippines', section: 'Personal information', order: 6 },
    { key: 'education_level', label: 'Highest education level', type: 'select', required: true, enabled: true, section: 'Education', order: 1,
        options: ['High School Graduate', 'Associate / Vocational', "Bachelor's Degree", "Master's Degree", 'Doctorate / PhD'] },
    { key: 'field_of_study', label: 'Field of study or profession', type: 'text', required: true, enabled: true, placeholder: 'e.g. Nursing, IT, Engineering', section: 'Education', order: 2 },
    { key: 'employment_status', label: 'Employment status', type: 'pills', required: true, enabled: true, section: 'Background', order: 1,
        options: ['Employed', 'Self-Employed', 'Unemployed', 'Student', 'OFW'] },
    { key: 'interest', label: 'Pathway of interest', type: 'select', required: true, enabled: true, section: 'NZ pathway', order: 1,
        options: ['Work Visa / Job Support', 'Student Visa', 'Skilled Migrant', 'Partner / Family Visa', 'Investor Visa', 'Not sure yet'] },
    { key: 'planning_timeline', label: 'Planning timeline', type: 'select', required: true, enabled: true, section: 'NZ pathway', order: 2,
        options: ['Within 3 months', '3–6 months', '6–12 months', '1–2 years', 'Just exploring'] },
    { key: 'funding_source', label: 'How will you fund your move?', type: 'pills', required: true, enabled: true, section: 'NZ pathway', order: 3,
        options: ['Personal Savings', 'Family Support', 'Scholarship', 'Student Loan', 'Employer-Sponsored', 'Not yet decided'] },
    { key: 'remarks', label: 'Questions or goals', type: 'textarea', required: false, enabled: true, placeholder: 'Anything specific you want addressed during the session…', section: 'Anything else?', order: 1 },
];

// ── Primitives ──────────────────────────────────────────────────────

const INPUT_CLASS =
    'w-full px-4 py-3 bg-white border border-gray-200 text-[14px] text-gray-900 placeholder:text-gray-400 ' +
    'focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 outline-none transition-colors';

function Field({ label, error, required, children, hint }) {
    const hasError = !!error;
    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
                <label className={`text-[10px] font-bold uppercase tracking-[0.18em] ${hasError ? 'text-red-600' : 'text-gray-500'}`}>
                    {label}
                    {required && <span className={`ml-1 ${hasError ? 'text-red-600' : 'text-gray-400'}`}>*</span>}
                </label>
                {hasError && (
                    <span className="text-[10px] font-semibold text-red-600 inline-flex items-center gap-1">
                        <AlertCircle size={10} /> {typeof error === 'string' ? error : 'Required'}
                    </span>
                )}
            </div>
            <div className={hasError ? 'ring-2 ring-red-200' : ''}>
                {children}
            </div>
            {hint && !hasError && (
                <p className="text-[11px] text-gray-400 leading-snug">{hint}</p>
            )}
        </div>
    );
}

/**
 * Render a single schema-driven field. Switches on `field.type` to produce
 * the right control. `error` and `value` are passed in; `setValue(v)` is
 * called with the new value on user input.
 */
function DynamicField({ field, value, setValue, error }) {
    // Truthy that treats ints (0/1) and strings ("0"/"1") the same as
    // booleans — same coercion used by the parent component.
    const truthy = (v) => v !== undefined && v !== null && v !== false && v !== 0 && v !== '0' && v !== 'false';
    const required = truthy(field.required) || truthy(field.locked);
    const placeholder = field.placeholder || '';
    const hint = field.hint || '';
    const fullWidth = field.type === 'textarea' || field.type === 'pills';

    let control;
    switch (field.type) {
        case 'textarea':
            control = (
                <textarea
                    rows={4}
                    required={required}
                    className={`${INPUT_CLASS} resize-y leading-relaxed`}
                    value={value || ''}
                    onChange={(e) => setValue(e.target.value)}
                    placeholder={placeholder}
                />
            );
            break;
        case 'select':
            control = (
                <select
                    required={required}
                    className={INPUT_CLASS}
                    value={value || ''}
                    onChange={(e) => setValue(e.target.value)}
                >
                    <option value="">{placeholder || `Select ${field.label.toLowerCase()}`}</option>
                    {(field.options || []).map((o) => <option key={o} value={o}>{o}</option>)}
                </select>
            );
            break;
        case 'pills':
            control = (
                <>
                    <PillGroup
                        options={field.options || []}
                        value={value || ''}
                        onChange={(v) => setValue(v)}
                    />
                    {/* Hidden input to satisfy the form's `required` check
                        when no pill is selected — the browser's
                        reportValidity() walks real inputs only. */}
                    {required && !value && (
                        <input
                            type="text" required aria-hidden tabIndex={-1}
                            style={{ opacity: 0, height: 0, padding: 0, border: 'none', position: 'absolute', zIndex: -1 }}
                        />
                    )}
                </>
            );
            break;
        case 'email':
        case 'tel':
        case 'text':
        default:
            control = (
                <input
                    type={field.type === 'email' ? 'email' : (field.type === 'tel' ? 'tel' : 'text')}
                    required={required}
                    className={INPUT_CLASS}
                    value={value || ''}
                    onChange={(e) => setValue(e.target.value)}
                    placeholder={placeholder}
                />
            );
            break;
    }

    return (
        <div id={`field-${field.key}`} className={fullWidth ? 'sm:col-span-2 scroll-mt-24' : 'scroll-mt-24'}>
            <Field label={field.label} required={required} error={error} hint={hint || undefined}>
                {control}
            </Field>
        </div>
    );
}

function PillGroup({ options, value, onChange }) {
    return (
        <div className="flex flex-wrap gap-2">
            {options.map((opt) => {
                const selected = value === opt;
                return (
                    <button
                        key={opt}
                        type="button"
                        onClick={() => onChange(opt)}
                        className={`px-4 py-2 text-[12px] font-semibold border transition-colors ${
                            selected
                                ? 'bg-gray-900 text-white border-gray-900'
                                : 'bg-white text-gray-700 border-gray-300 hover:border-gray-900 hover:text-gray-900'
                        }`}
                    >
                        {opt}
                    </button>
                );
            })}
        </div>
    );
}

function SectionTitle({ index, title, subtitle }) {
    return (
        <header className="border-b border-gray-100 pb-4 mb-6">
            <div className="flex items-baseline gap-4">
                <span className="text-[11px] font-mono font-semibold text-gray-400 tabular-nums">
                    {String(index).padStart(2, '0')}
                </span>
                <div>
                    <h3 className="text-[15px] font-bold text-gray-900 tracking-tight">{title}</h3>
                    {subtitle && (
                        <p className="text-[12px] text-gray-500 mt-1 leading-relaxed">{subtitle}</p>
                    )}
                </div>
            </div>
        </header>
    );
}

function MetaChip({ icon, children }) {
    return (
        <span className="inline-flex items-center gap-2 px-3.5 py-2 text-[12px] font-semibold text-gray-100 bg-white/5 border border-white/10 backdrop-blur-sm">
            <span className="text-gray-300">{icon}</span>
            {children}
        </span>
    );
}

function SidebarCard({ children, dark = false, className = '' }) {
    return (
        <article
            className={`${dark ? 'text-white' : 'bg-white border border-gray-200 text-gray-900'} ${className}`}
            style={dark ? { backgroundColor: DARK } : undefined}
        >
            {children}
        </article>
    );
}

// ── Success view ────────────────────────────────────────────────────

function SuccessScreen({ eventName }) {
    return (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
            <div className="max-w-md w-full bg-white border border-gray-200 overflow-hidden">
                <div className="px-10 pt-12 pb-8 text-center border-b border-gray-100">
                    <motion.div
                        initial={{ scale: 0.85, opacity: 0 }}
                        animate={{ scale: 1, opacity: 1 }}
                        transition={{ type: 'spring', stiffness: 220, damping: 18 }}
                        className="w-14 h-14 mx-auto bg-gray-900 text-white flex items-center justify-center mb-6"
                    >
                        <CheckCircle size={26} strokeWidth={2.2} />
                    </motion.div>
                    <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400 mb-2">
                        Registered
                    </p>
                    <h2 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Thank you for registering
                    </h2>
                    <p className="mt-3 text-[13px] text-gray-600 leading-relaxed">
                        Your seat for <span className="font-semibold text-gray-900">{eventName}</span> is reserved.
                        We've sent a confirmation to your email — a member of our team will reach out within 24 hours.
                    </p>
                </div>
                <div className="px-10 py-8">
                    <a
                        href="/"
                        className="inline-flex items-center justify-center gap-2 w-full bg-gray-900 text-white py-3.5 text-[13px] font-semibold tracking-wide hover:bg-black transition-colors"
                    >
                        Return to home <ArrowRight size={14} />
                    </a>
                </div>
            </div>
        </div>
    );
}

// ── Main ────────────────────────────────────────────────────────────

export default function Registration({ event }) {
    if (!event) {
        return (
            <div className="min-h-screen flex items-center justify-center p-10 text-center bg-gray-50">
                <div>
                    <AlertCircle size={28} className="mx-auto text-gray-400 mb-3" />
                    <p className="text-sm font-semibold text-gray-700">Event data missing.</p>
                </div>
            </div>
        );
    }

    const contact = event.event_code === 'bhutanese-association-of-new-zealand-fz35m'
        ? { phone: '+64 21 227 8811', email: 'eireen@dimmigration.com' }
        : { phone: '+63 (82) 297-5000', email: 'hello@epathways.com.ph' };

    const [success, setSuccess] = useState(false);
    // Top-of-form error banner. Replaces the modal — keeping a passive
    // notice up top so the user knows something went wrong even after
    // scrolling, but not blocking the page with a popover.
    const [bannerError, setBannerError] = useState(null);
    const formRef = useRef(null);

    // Effective field schema: server-provided when available (admin used
    // the field builder), else falls back to the hardcoded canonical
    // 13-field set. The form state is computed off this list so adding /
    // hiding fields in the admin UI flows through automatically.
    const fields = Array.isArray(event.effective_fields) && event.effective_fields.length > 0
        ? event.effective_fields
        : CANONICAL_FIELDS;
    // Truthy that treats ints (0/1) and strings ("0"/"1") the same as
    // booleans — form_fields round-trip through JSON which can produce
    // either depending on how the admin POSTed them.
    const isOn = (v, defaultTrue = false) => {
        if (v === undefined || v === null) return defaultTrue;
        if (v === false || v === 0 || v === '0' || v === 'false') return false;
        return Boolean(v);
    };
    const visibleFields = fields.filter((f) => isOn(f.locked, false) || isOn(f.enabled, true));

    const initialFormState = React.useMemo(() => {
        const base = { event_session_id: '' };
        for (const f of visibleFields) base[f.key] = '';
        return base;
    }, [visibleFields]);

    const { data, setData, post, processing, errors } = useForm(initialFormState);

    // Group visible fields by section so the page still reads as four
    // tidy blocks even when admin adds/removes individual fields.
    const sectionGroups = React.useMemo(() => {
        const map = new Map();
        const order = [];
        for (const f of visibleFields) {
            const sec = f.section || 'Additional';
            if (!map.has(sec)) { map.set(sec, []); order.push(sec); }
            map.get(sec).push(f);
        }
        // Sort each section's fields by `order`, preserving authored order
        // when ties happen.
        for (const sec of order) {
            map.get(sec).sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
        }
        return order.map((s) => ({ section: s, items: map.get(s) }));
    }, [visibleFields]);

    // When the server returns validation errors, scroll the first
    // offending field into view and focus its input. The Field component
    // already shows the inline red badge next to the input, and we keep a
    // discreet banner at the top of the form so the user sees the
    // failure even if they happened to be mid-scroll.
    const scrollToFirstError = (errs) => {
        const keys = Object.keys(errs || {});
        if (keys.length === 0) return;
        // Prefer field-key matches; fall back to the generic 'error' /
        // 'message' keys (controller catch-all).
        const fieldKey = keys.find((k) => k !== 'error' && k !== 'message');
        if (! fieldKey) return;
        const wrapper = document.getElementById(`field-${fieldKey}`);
        if (! wrapper) return;
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Focus the first input/select/textarea inside the wrapper.
        // setTimeout lets the smooth scroll start before focus snaps.
        setTimeout(() => {
            const ctrl = wrapper.querySelector('input:not([aria-hidden]), select, textarea');
            if (ctrl && typeof ctrl.focus === 'function') ctrl.focus({ preventScroll: true });
        }, 120);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (formRef.current && !formRef.current.reportValidity()) return;
        setBannerError(null);
        post(`/register/${event.event_code}`, {
            onSuccess: () => setSuccess(true),
            preserveScroll: true,
            onError: (errs) => {
                const keys = Object.keys(errs || {});
                const catchAll = errs?.error || errs?.message;
                if (catchAll) {
                    setBannerError(catchAll);
                } else {
                    setBannerError(
                        keys.length === 1
                            ? 'Please review the highlighted field below.'
                            : `Please review the ${keys.length} highlighted fields below.`
                    );
                }
                scrollToFirstError(errs);
            },
        });
    };

    if (success) return <SuccessScreen eventName={event.name} />;

    const fmtDate = (iso) => iso
        ? new Date(iso).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
        : null;

    // Format a 'HH:MM' or 'HH:MM:SS' string as 12-hour with am/pm.
    // Returns null if the input can't be parsed.
    const fmtTime = (raw) => {
        if (! raw || typeof raw !== 'string') return null;
        const [hStr, mStr] = raw.split(':');
        const h = parseInt(hStr, 10);
        const m = parseInt(mStr ?? '0', 10);
        if (isNaN(h) || isNaN(m)) return null;
        const hour12 = ((h % 12) || 12);
        const ampm = h < 12 ? 'am' : 'pm';
        return `${hour12}:${String(m).padStart(2, '0')}${ampm}`;
    };

    const timeChip = (() => {
        const start = fmtTime(event.time_start);
        const end = fmtTime(event.time_end);
        if (start && end) return `${start} – ${end}`;
        if (start) return start;
        return null;
    })();

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900">
            <Head title={`Register — ${event.name}`} />

            {/* ── HERO — banner image if uploaded, else solid dark ──── */}
            <section className="relative overflow-hidden" style={{ backgroundColor: DARK }}>
                {/* Uploaded banner becomes the hero backdrop (controller
                    sets banner_image_url when an image exists on the event).
                    A dark overlay keeps headline + meta chips readable
                    regardless of the banner's brightness. */}
                {event.banner_image_url && (
                    <>
                        <img
                            src={event.banner_image_url}
                            alt=""
                            className="absolute inset-0 w-full h-full object-cover"
                            onError={(e) => { e.currentTarget.style.display = 'none'; }}
                        />
                        <div className="absolute inset-0 bg-gradient-to-br from-black/85 via-black/70 to-black/55" />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-transparent to-transparent" />
                    </>
                )}
                {/* Subtle vignette (always present) so the slab doesn't
                    read as flat on the no-banner path. */}
                <div
                    aria-hidden
                    className="absolute inset-0 pointer-events-none opacity-40"
                    style={{
                        background:
                            'radial-gradient(ellipse at top right, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0) 55%)',
                    }}
                />
                <div className="relative max-w-6xl mx-auto px-6 pt-20 pb-16 sm:pt-28 sm:pb-20">
                    <p className="text-[10px] font-bold uppercase tracking-[0.4em] text-gray-400 mb-6">
                        {event.type || 'Event registration'}
                    </p>
                    <motion.h1
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4 }}
                        className="text-4xl sm:text-5xl md:text-6xl font-bold text-white tracking-tight leading-[1.05] max-w-3xl"
                    >
                        {event.name || 'Register for our event'}
                    </motion.h1>

                    {event.description && (
                        <motion.p
                            initial={{ opacity: 0, y: 8 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.4, delay: 0.05 }}
                            className="mt-5 text-[15px] sm:text-base text-gray-300 max-w-2xl leading-relaxed"
                        >
                            {event.description}
                        </motion.p>
                    )}

                    <motion.div
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4, delay: 0.1 }}
                        className="mt-10 flex flex-wrap items-center gap-2"
                    >
                        {event.date_from && (
                            <MetaChip icon={<Calendar size={13} />}>{fmtDate(event.date_from)}</MetaChip>
                        )}
                        {timeChip && (
                            <MetaChip icon={<Clock size={13} />}>{timeChip}</MetaChip>
                        )}
                        {(event.location || event.mode) && (
                            <MetaChip icon={<MapPin size={13} />}>{event.location || event.mode}</MetaChip>
                        )}
                        {event.sessions && event.sessions.length > 0 && (
                            <MetaChip icon={<Users size={13} />}>
                                {event.sessions.length} session{event.sessions.length === 1 ? '' : 's'}
                            </MetaChip>
                        )}
                        <MetaChip icon={<Clock size={13} />}>Registration open</MetaChip>
                    </motion.div>
                </div>
            </section>

            {/* ── BODY ───────────────────────────────────────────────── */}
            <div className="max-w-6xl mx-auto px-6 py-12 lg:py-16">
                <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-8 lg:gap-10 items-start">

                    {/* ── FORM CARD ──────────────────────────────────── */}
                    <div className="bg-white border border-gray-200">
                        <div className="px-8 py-7 sm:px-10 sm:py-8 border-b border-gray-100">
                            <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400 mb-2">
                                Registration form
                            </p>
                            <h2 className="text-xl font-bold text-gray-900 tracking-tight">
                                Reserve your seat
                            </h2>
                            <p className="text-[13px] text-gray-500 mt-2 leading-relaxed max-w-md">
                                A few quick details so we can tailor the session to your situation.
                            </p>
                        </div>

                        {/* Inline error banner — replaces the old blocking
                            modal. Stays at the top of the form so the
                            user sees it even after scrolling, but never
                            blocks input. */}
                        {bannerError && (
                            <div className="mx-8 mt-6 sm:mx-10 px-4 py-3 bg-red-50 border border-red-200 text-[13px] text-red-700 flex items-start gap-3">
                                <AlertCircle size={16} className="text-red-600 flex-shrink-0 mt-0.5" />
                                <div className="flex-1">{bannerError}</div>
                                <button
                                    type="button"
                                    onClick={() => setBannerError(null)}
                                    className="text-red-400 hover:text-red-700 flex-shrink-0"
                                    aria-label="Dismiss"
                                >
                                    <X size={14} />
                                </button>
                            </div>
                        )}

                        <form ref={formRef} onSubmit={handleSubmit} className="px-8 py-8 sm:px-10 sm:py-10 space-y-10">

                            {/* Sections are driven by the field schema —
                                the four canonical groups (Personal, Background,
                                Pathway, Anything else) plus an "Additional"
                                bucket for any custom field admins added with
                                no section assignment. The "Preferred session"
                                control is injected into the first group when
                                the event has upcoming sessions. */}
                            {sectionGroups.map(({ section, items }, sectionIdx) => (
                                <section key={section}>
                                    <SectionTitle
                                        index={sectionIdx + 1}
                                        title={section}
                                        subtitle={SECTION_SUBTITLES[section] || null}
                                    />
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        {items.map((f) => (
                                            <DynamicField
                                                key={f.key}
                                                field={f}
                                                value={data[f.key]}
                                                setValue={(v) => setData(f.key, v)}
                                                error={errors[f.key]}
                                            />
                                        ))}
                                        {sectionIdx === 0 && event.sessions && event.sessions.length > 0 && (
                                            <div className="sm:col-span-2">
                                                <Field
                                                    label="Preferred session"
                                                    hint="Pick the date/time that works best — leave blank to let us choose."
                                                    error={errors.event_session_id}
                                                >
                                                    <select
                                                        className={INPUT_CLASS}
                                                        value={data.event_session_id}
                                                        onChange={(e) => setData('event_session_id', e.target.value)}
                                                    >
                                                        <option value="">Any session</option>
                                                        {event.sessions.map((s) => (
                                                            <option key={s.id} value={s.id}>
                                                                {s.venue_name || s.city}
                                                                {s.time_start ? ` — ${s.time_start.slice(0, 5)}` : ''}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </Field>
                                            </div>
                                        )}
                                    </div>
                                </section>
                            ))}


                            {/* Submit */}
                            <div className="pt-6 border-t border-gray-100 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-4">
                                <p className="text-[11px] text-gray-400 inline-flex items-center gap-1.5">
                                    <Lock size={11} /> Your details are secured and never sold.
                                </p>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center gap-2 px-8 py-3.5 bg-gray-900 text-white text-[13px] font-semibold tracking-wide hover:bg-black transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {processing ? 'Submitting…' : 'Confirm registration'}
                                    {!processing && <ChevronRight size={15} />}
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* ── SIDEBAR ────────────────────────────────────── */}
                    <aside className="space-y-4">

                        {/* Contact — dark, friendly card with tappable links */}
                        <div className="relative bg-[#1a1a1a] text-white overflow-hidden">
                            {/* subtle decorative glow so it isn't a flat block */}
                            <div className="pointer-events-none absolute -top-16 -right-16 w-48 h-48 rounded-full bg-white/[0.06] blur-3xl" />
                            <div className="relative px-6 py-6">
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/50 mb-1.5">Get in touch</p>
                                <h3 className="text-lg font-bold tracking-tight">Have a question?</h3>
                                <p className="mt-1 text-[12.5px] text-gray-400 leading-relaxed">Our team is happy to help — reach out anytime.</p>

                                <div className="mt-5 space-y-2.5">
                                    <a
                                        href={`tel:${contact.phone.replace(/[^\d+]/g, '')}`}
                                        className="group flex items-center gap-3 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 px-3.5 py-3 transition-colors"
                                    >
                                        <span className="w-9 h-9 rounded-md bg-white/10 text-white flex items-center justify-center shrink-0">
                                            <Phone size={15} />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-[9.5px] font-bold uppercase tracking-[0.2em] text-white/45">Phone</p>
                                            <p className="text-[13.5px] font-semibold leading-snug truncate">{contact.phone}</p>
                                        </div>
                                        <ChevronRight size={15} className="ml-auto text-white/40 group-hover:text-white group-hover:translate-x-0.5 transition-all" />
                                    </a>
                                    <a
                                        href={`mailto:${contact.email}`}
                                        className="group flex items-center gap-3 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 px-3.5 py-3 transition-colors"
                                    >
                                        <span className="w-9 h-9 rounded-md bg-white/10 text-white flex items-center justify-center shrink-0">
                                            <Mail size={15} />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-[9.5px] font-bold uppercase tracking-[0.2em] text-white/45">Email</p>
                                            <p className="text-[13.5px] font-semibold leading-snug truncate">{contact.email}</p>
                                        </div>
                                        <ChevronRight size={15} className="ml-auto text-white/40 group-hover:text-white group-hover:translate-x-0.5 transition-all" />
                                    </a>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>

                {/* ── Explore more — Free Assessment + Book Consultation, side by
                    side with imagery so the page doesn't feel flat. ──────── */}
                <div className="mt-14 grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Free Assessment */}
                    <div className="bg-white border border-gray-200 overflow-hidden flex flex-col">
                        <div className="relative h-44 overflow-hidden">
                            <img src={assessImg} alt="" className="w-full h-full object-cover" />
                            <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />
                            <div className="absolute bottom-4 left-5 right-5 text-white">
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/70 mb-1">Complimentary</p>
                                <h3 className="text-xl font-bold tracking-tight">Free assessment</h3>
                            </div>
                        </div>
                        <div className="p-6 flex-1 flex flex-col">
                            <p className="text-[13px] text-gray-500 leading-relaxed">A personalised eligibility read on your NZ pathway.</p>
                            <ul className="mt-4 space-y-2.5 flex-1">
                                {['Visa eligibility mapping', 'Qualification recognition check', 'Step-by-step action plan'].map((txt) => (
                                    <li key={txt} className="flex items-start gap-2.5 text-[12.5px] text-gray-700">
                                        <CheckCircle size={13} className="text-gray-900 shrink-0 mt-0.5" />
                                        <span>{txt}</span>
                                    </li>
                                ))}
                            </ul>
                            <a
                                href="/free-assessment"
                                className="mt-5 inline-flex items-center justify-between w-full px-4 py-3 border border-gray-900 text-gray-900 text-[13px] font-semibold hover:bg-gray-900 hover:text-white transition-colors"
                            >
                                Start assessment
                                <ChevronRight size={14} />
                            </a>
                        </div>
                    </div>

                    {/* Book a Consultation — dark */}
                    <div className="bg-[#1a1a1a] text-white overflow-hidden flex flex-col">
                        <div className="relative h-44 overflow-hidden">
                            <img src={consultImg} alt="" className="w-full h-full object-cover opacity-90" />
                            <div className="absolute inset-0 bg-gradient-to-t from-[#1a1a1a] via-[#1a1a1a]/50 to-transparent" />
                            <div className="absolute bottom-4 left-5 right-5">
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/60 mb-1">No cost</p>
                                <h3 className="text-xl font-bold tracking-tight">Book a consultation</h3>
                            </div>
                        </div>
                        <div className="p-6 flex-1 flex flex-col">
                            <p className="text-[13px] text-gray-300 leading-relaxed">A 30-minute private session with a certified EP adviser.</p>
                            <ul className="mt-4 space-y-2.5 flex-1">
                                {['30-min private session', 'Discuss your specific situation', 'No sales pressure'].map((txt) => (
                                    <li key={txt} className="flex items-start gap-2.5 text-[12.5px] text-gray-200">
                                        <CheckCircle size={13} className="text-white shrink-0 mt-0.5" />
                                        <span>{txt}</span>
                                    </li>
                                ))}
                            </ul>
                            <a
                                href="/booking"
                                className="mt-5 inline-flex items-center justify-between w-full px-4 py-3 bg-white text-gray-900 text-[13px] font-semibold hover:bg-gray-100 transition-colors"
                            >
                                View schedule
                                <ChevronRight size={14} />
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    );
}
