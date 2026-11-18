import { hasProgramCost } from '@/utils/programCosts.mjs';
import React, { useEffect, useState } from 'react';
import {
    X, MapPin, Clock, Award, GraduationCap, BookOpen, Building2, TrendingUp, Loader2,
} from 'lucide-react';
import { renderSections, hasSections } from '@/utils/programSections';

/**
 * Full detail of one shortlisted program, in a modal on the tracker.
 *
 * Clients used to be sent off to the public /program-details page for this,
 * which lost their place on the tracker and — worse — 404'd whenever the
 * adviser had shortlisted a program that wasn't published on the marketing
 * site. Both problems go away by reading the record through the tracker's own
 * scoped endpoint instead.
 *
 * Content is fetched on open rather than shipped with the page: a tracker may
 * carry several proposal versions, and there is no reason to send every
 * program's full body text to a client who may open none of them.
 */
export default function ProgramDetailsModal({ code, programId, endpoint, onClose }) {
    const [program, setProgram] = useState(null);
    const [error, setError] = useState(null);

    // The tracker passes a code + programId (its scoped endpoint); staff pass an
    // explicit `endpoint` (the /admin/programs/{id}/details JSON route).
    const url = endpoint || `/track/${encodeURIComponent(code)}/programs/${programId}`;

    useEffect(() => {
        let cancelled = false;
        setProgram(null);
        setError(null);

        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((d) => { if (!cancelled) setProgram(d.program); })
            .catch(() => { if (!cancelled) setError('We could not load this program right now.'); });

        return () => { cancelled = true; };
    }, [url]);

    // Escape to close, and hold the page still behind the modal.
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;
        };
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto p-3 sm:p-6">
            <div className="fixed inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />

            <div
                role="dialog"
                aria-modal="true"
                aria-label={program?.title || 'Program details'}
                className="relative w-full max-w-3xl my-auto flex flex-col rounded-2xl bg-white shadow-2xl max-h-[calc(100vh-3rem)] overflow-hidden"
            >
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close"
                    className="absolute top-3 right-3 z-10 p-2 rounded-full bg-white/90 text-gray-600 hover:text-gray-900 hover:bg-white shadow-sm transition-colors"
                >
                    <X size={16} />
                </button>

                {error ? (
                    <div className="px-6 py-16 text-center">
                        <p className="text-[14px] font-bold text-gray-900">{error}</p>
                        <p className="text-[12.5px] text-gray-500 mt-1">Please try again, or ask your adviser to resend the details.</p>
                    </div>
                ) : !program ? (
                    <div className="px-6 py-20 flex flex-col items-center justify-center gap-3">
                        <Loader2 size={22} className="text-gray-400 animate-spin" />
                        <p className="text-[12.5px] text-gray-400">Loading program details…</p>
                    </div>
                ) : (
                    <Body program={program} />
                )}
            </div>
        </div>
    );
}

function Body({ program: p }) {
    const paragraphs = (p.description || '').split(/\n\n+/).filter(Boolean);

    // The tuition table takes the structured rows when present and otherwise
    // falls back to the single legacy tuition_fee column.
    const tuitionRows = (Array.isArray(p.tuition_fees) && p.tuition_fees.length > 0)
        ? p.tuition_fees
        : (p.tuition_fee !== null && p.tuition_fee !== undefined && p.tuition_fee !== ''
            ? [{ label: '', amount: p.tuition_fee, notes: p.tuition_fee_notes }]
            : []).filter(row => hasProgramCost(row.amount));

    const costs = [
        ['Insurance', p.insurance_fee],
        ['Visa processing', p.visa_processing_fee],
        ['Living expenses', p.living_expense],
        ['Accommodation', p.accommodation],
    ].filter(([, value]) => hasProgramCost(value));

    const facts = [
        [MapPin, 'Location', p.location],
        [Clock, 'Duration', p.duration_months ? `${p.duration_months} months` : null],
        [Award, 'Intake', p.intake_months],
        [BookOpen, 'Credits', p.credits],
        [Clock, 'Hours per week', p.hours_per_week],
        [TrendingUp, 'Residency points', p.residency_points],
    ].filter(([, , v]) => v !== null && v !== undefined && v !== '');

    return (
        <>
            {/* Hero */}
            {p.image_url ? (
                <div className="h-40 sm:h-48 w-full shrink-0 overflow-hidden bg-gray-100">
                    <img src={p.image_url} alt="" className="w-full h-full object-cover" />
                </div>
            ) : (
                <div className="h-28 w-full shrink-0 bg-gradient-to-br from-gray-100 via-gray-50 to-white flex items-center justify-center">
                    <GraduationCap size={34} className="text-gray-300" />
                </div>
            )}

            <div className="px-5 sm:px-7 pt-5 pb-4 border-b border-gray-100 shrink-0">
                <div className="flex flex-wrap items-center gap-1.5 mb-2">
                    {p.level != null && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-gray-900 text-white">
                            Level {p.level}
                        </span>
                    )}
                    {p.category && <span className="text-[11px] font-medium text-gray-500 capitalize">{p.category}</span>}
                    {p.industry && <span className="text-[11px] text-gray-400">· {p.industry}</span>}
                </div>
                <h2 className="text-[19px] sm:text-[21px] font-bold text-gray-900 leading-snug tracking-tight">{p.title}</h2>
                {p.institution && (
                    <p className="mt-1 inline-flex items-center gap-1.5 text-[12.5px] text-gray-500">
                        <Building2 size={13} className="text-gray-400" /> {p.institution}
                    </p>
                )}
                {p.price_text && (
                    <p className="mt-2 text-[13px] font-semibold text-gray-800">{p.price_text}</p>
                )}
            </div>

            {/* Scrolling body */}
            <div className="flex-1 overflow-y-auto px-5 sm:px-7 py-5 space-y-6">
                {facts.length > 0 && (
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                        {facts.map(([Icon, label, value]) => (
                            <div key={label} className="rounded-xl border border-gray-200 px-3 py-2.5">
                                <p className="inline-flex items-center gap-1.5 text-[10.5px] text-gray-400">
                                    <Icon size={11} className="text-gray-400" /> {label}
                                </p>
                                <p className="text-[13px] font-semibold text-gray-900 mt-0.5 leading-snug">{value}</p>
                            </div>
                        ))}
                    </div>
                )}

                {paragraphs.length > 0 && (
                    <Section title="About this program">
                        <div className="space-y-3">
                            {paragraphs.map((t, i) => <p key={i}>{t}</p>)}
                        </div>
                    </Section>
                )}

                {hasSections(p.entry_requirements) && (
                    <Section title="Entry requirements">{renderSections(p.entry_requirements, '', 'marker:text-gray-400')}</Section>
                )}

                {p.english_requirements && String(p.english_requirements).trim() && (
                    <Section title="English requirements">
                        <p className="whitespace-pre-line">{p.english_requirements}</p>
                    </Section>
                )}

                {hasSections(p.employment_outcomes) && (
                    <Section title="Employment outcomes">{renderSections(p.employment_outcomes, '', 'marker:text-gray-400')}</Section>
                )}

                {p.post_study && String(p.post_study).trim() && (
                    <Section title="Post study">
                        <p className="whitespace-pre-line">{p.post_study}</p>
                    </Section>
                )}

                {p.specialization && String(p.specialization).trim() && (
                    <Section title="Specialization">
                        <p className="whitespace-pre-line">{p.specialization}</p>
                    </Section>
                )}

                {hasSections(p.other_benefits) && (
                    <Section title="Other benefits">{renderSections(p.other_benefits, '', 'marker:text-gray-400')}</Section>
                )}

                {(tuitionRows.length > 0 || costs.length > 0) && (
                    <Section title="Fee guide">
                        {tuitionRows.length > 0 && (
                            <div className="rounded-xl border border-gray-200 overflow-hidden mb-3">
                                <div className="flex items-center justify-between px-3.5 py-2 bg-gray-50 border-b border-gray-200">
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Tuition</span>
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Amount (NZD)</span>
                                </div>
                                <div className="divide-y divide-gray-100">
                                    {tuitionRows.map((row, i) => (
                                        <div key={i} className="flex items-baseline justify-between gap-4 px-3.5 py-2.5">
                                            <span className="text-[12.5px] text-gray-600">{row.label || 'Tuition'}</span>
                                            <span className="text-right shrink-0">
                                                <span className="text-[15px] font-bold text-gray-900 tabular-nums">{money(row.amount)}</span>
                                                {row.notes && <span className="block text-[11px] text-gray-500">{row.notes}</span>}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {costs.length > 0 && (
                            <div className="grid grid-cols-2 gap-2.5">
                                {costs.map(([label, value]) => (
                                    <div key={label} className="rounded-xl bg-gray-900 text-white px-3.5 py-3">
                                        <p className="text-[10.5px] text-white/50">{label} · NZD</p>
                                        <p className="text-[16px] font-bold tabular-nums mt-0.5">{money(value)}</p>
                                    </div>
                                ))}
                            </div>
                        )}

                        <p className="mt-3 text-[11.5px] text-gray-400 leading-relaxed">
                            Figures are indicative and set by the provider — your adviser will confirm the exact
                            amounts that apply to you before anything is payable.
                        </p>
                    </Section>
                )}
            </div>
        </>
    );
}

function Section({ title, children }) {
    return (
        <div>
            <h3 className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-2">{title}</h3>
            <div className="text-[13px] text-gray-700 leading-relaxed">{children}</div>
        </div>
    );
}

/** Format a stored fee. A non-numeric value is shown as written, never guessed at. */
function money(value) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    return n.toLocaleString('en-NZ', { style: 'currency', currency: 'NZD', maximumFractionDigits: 0 });
}
