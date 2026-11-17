import React, { useState, useMemo, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { X, Upload, ArrowRight, Trash2, Check } from 'lucide-react';
import { initials, priorityMeta } from '@/pages/portal/sales/Leads';

/**
 * The referral-lead record, as a modal. Three tabs, in the order a sub-agent
 * actually works: Overview (where is this lead up to), Personal (the facts I
 * collected), Documents (the paperwork I still owe).
 *
 * The licensing line matters here: everything on these tabs is fact collection.
 * Nothing on this screen assesses eligibility or offers advice — that stays with
 * the licensed adviser, which the Personal tab says out loud.
 */

const NOTE_TAGS = [
    { key: 'general', label: 'General' },
    { key: 'client_contact', label: 'Call' },
    { key: 'goal_setting', label: 'Milestone' },
];
const KIND_LABEL = { general: 'General', client_contact: 'Call', goal_setting: 'Milestone', pre_screen: 'Pre-screen', risk: 'Risk' };

export const TAG_UNRESPONSIVE = 'unresponsive';
export const TAG_READY = 'ready-for-review';

// ─── Formatting helpers ───────────────────────────────────────────────
const fmtLongDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
};
const daysUntil = (iso) => {
    if (!iso) return null;
    const t = new Date(iso).getTime();
    return Number.isNaN(t) ? null : Math.ceil((t - Date.now()) / 86400000);
};
const idleDays = (iso) => {
    if (!iso) return 0;
    const t = new Date(iso).getTime();
    return Number.isNaN(t) ? 0 : Math.max(0, Math.floor((Date.now() - t) / 86400000));
};
const relTime = (iso) => {
    if (!iso) return '';
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return '';
    const days = Math.round((Date.now() - t) / 86400000);
    if (days < 1) return 'today';
    if (days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;
    if (days < 35) return `${Math.round(days / 7)} week${Math.round(days / 7) === 1 ? '' : 's'} ago`;
    if (days < 365) return `${Math.round(days / 30)} month${Math.round(days / 30) === 1 ? '' : 's'} ago`;
    return `${Math.round(days / 365)} year${Math.round(days / 365) === 1 ? '' : 's'} ago`;
};

export default function LeadModal({
    lead, portalBase, nextStep, referralValue, adviceOwner,
    onClose, onPriority, draft, setDraft, noteKind, setNoteKind, onPost, posting, me,
}) {
    const [tab, setTab] = useState('overview');
    const [docs, setDocs] = useState(null);
    const [form, setForm] = useState(null); // non-null = Personal tab is in edit mode
    const [saving, setSaving] = useState(false);
    const [busyFlag, setBusyFlag] = useState(null);

    // Documents load once for the whole modal — the tab badge and the footer
    // "Ready" button both need the count, not just the Documents tab.
    const loadDocs = () => {
        fetch(`${portalBase}/leads/${lead.id}/documents`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then(setDocs)
            .catch(() => setDocs(null));
    };
    useEffect(() => { setDocs(null); setForm(null); loadDocs(); }, [lead.id]); // eslint-disable-line react-hooks/exhaustive-deps

    // Close on Escape, but never while an edit is half-typed.
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && !form) onClose(); };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [form, onClose]);

    const filled = docs?.filled ?? lead.docs_in ?? 0;
    const total = docs?.total ?? lead.docs_total ?? 4;

    const tags = lead.tags || [];
    const isUnresponsive = tags.includes(TAG_UNRESPONSIVE);
    const isReady = tags.includes(TAG_READY);

    const raw = lead.personal?.raw || {};
    const dirty = useMemo(
        () => !!form && Object.keys(form).some((k) => (form[k] ?? '') !== (raw[k] ?? '')),
        [form, raw],
    );

    const save = () => {
        if (!form || saving) return;
        setSaving(true);
        router.post(`${portalBase}/leads/${lead.id}/profile`, form, {
            preserveScroll: true,
            onSuccess: () => setForm(null),
            onFinish: () => setSaving(false),
        });
    };

    const mark = (flag, on) => {
        setBusyFlag(flag);
        router.post(`${portalBase}/leads/${lead.id}/mark`, { flag, on }, {
            preserveScroll: true,
            onFinish: () => setBusyFlag(null),
        });
    };

    const TABS = [
        { key: 'overview', label: 'Overview' },
        { key: 'personal', label: 'Personal' },
        { key: 'documents', label: 'Documents', badge: `${filled}/${total}` },
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-3 sm:p-8">
            <div className="fixed inset-0 bg-black/30 backdrop-blur-sm" onClick={() => (form ? null : onClose())} />

            <div className="relative w-full max-w-3xl my-auto flex flex-col rounded-2xl bg-white shadow-2xl max-h-[calc(100vh-4rem)]">
                {/* Header */}
                <div className="flex items-start gap-3 px-6 pt-5 pb-4">
                    <div className={`w-10 h-10 rounded-full flex items-center justify-center text-[12px] font-bold text-white overflow-hidden shrink-0 ${priorityMeta(lead.priority)?.dot || 'bg-gray-800'}`}>
                        {lead.avatar_url ? <img src={lead.avatar_url} alt={lead.name} className="w-full h-full object-cover" /> : initials(lead.name)}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="font-bold text-gray-900 text-[16px] truncate">{lead.name}</p>
                        {lead.location && <p className="text-[12.5px] text-gray-400 truncate">{lead.location}</p>}
                        <p className="text-[10.5px] text-gray-400 font-mono mt-0.5">{lead.lead_id}</p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        {isUnresponsive && <StatusChip tone="gray">Unresponsive</StatusChip>}
                        {isReady && <StatusChip tone="emerald">Ready</StatusChip>}
                        <button type="button" onClick={onClose} className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100">
                            <X size={18} />
                        </button>
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex items-center gap-5 px-6 border-b border-gray-100">
                    {TABS.map((t) => {
                        const active = tab === t.key;
                        return (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => setTab(t.key)}
                                className={`relative pb-2.5 text-[13.5px] font-semibold transition-colors ${active ? 'text-gray-900' : 'text-gray-400 hover:text-gray-600'}`}
                            >
                                {t.label}
                                {t.badge && <span className={`ml-1.5 text-[11px] font-bold ${active ? 'text-purple-600' : 'text-gray-300'}`}>{t.badge}</span>}
                                {active && <span className="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-purple-600" />}
                            </button>
                        );
                    })}
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {tab === 'overview' && (
                        <OverviewTab
                            lead={lead} nextStep={nextStep} referralValue={referralValue} onPriority={onPriority}
                            draft={draft} setDraft={setDraft} noteKind={noteKind} setNoteKind={setNoteKind}
                            onPost={onPost} posting={posting} me={me}
                        />
                    )}
                    {tab === 'personal' && (
                        <PersonalTab
                            lead={lead} adviceOwner={adviceOwner}
                            form={form}
                            onEdit={() => setForm({ ...raw })}
                            onCancel={() => setForm(null)}
                            onChange={(k, v) => setForm((f) => ({ ...f, [k]: v }))}
                        />
                    )}
                    {tab === 'documents' && (
                        <DocumentsTab lead={lead} portalBase={portalBase} docs={docs} reload={loadDocs} />
                    )}
                </div>

                {/* Footer */}
                <div className="flex flex-wrap items-center justify-between gap-2 px-6 py-4 border-t border-gray-100">
                    <button
                        type="button"
                        onClick={form ? () => setForm(null) : onClose}
                        className="px-4 py-2 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-600 hover:bg-gray-50"
                    >
                        {form ? 'Cancel' : 'Close'}
                    </button>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => mark('unresponsive', !isUnresponsive)}
                            disabled={busyFlag === 'unresponsive'}
                            title={isUnresponsive ? 'Put this lead back in the pipeline' : 'Stop chasing and close the open follow-ups'}
                            className="px-3.5 py-2 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                        >
                            {isUnresponsive ? 'Reactivate lead' : 'Mark unresponsive'}
                        </button>

                        <button
                            type="button"
                            onClick={save}
                            disabled={!dirty || saving}
                            className="px-3.5 py-2 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            {saving ? 'Saving…' : 'Save changes'}
                        </button>

                        {/* Handover flag. Gated on the paperwork actually being in
                            — the server re-checks, this just explains the block. */}
                        <button
                            type="button"
                            onClick={() => mark('ready', !isReady)}
                            disabled={busyFlag === 'ready' || (!isReady && filled < total)}
                            title={!isReady && filled < total ? `All ${total} required documents must be in first` : undefined}
                            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-gray-900 text-white text-[13px] font-semibold hover:bg-black disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            {isReady ? <><Check size={13} /> Ready</> : 'Ready'}
                            <span className="text-white/50 font-normal">{filled} of {total} docs</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function StatusChip({ tone, children }) {
    const cls = tone === 'emerald' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-100 text-gray-500 border-gray-200';
    return <span className={`px-2 py-0.5 rounded-md border text-[10px] font-bold uppercase tracking-wide ${cls}`}>{children}</span>;
}

function SectionLabel({ children, right }) {
    return (
        <div className="flex items-baseline justify-between gap-3 mb-2.5">
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">{children}</p>
            {right}
        </div>
    );
}

/** A label/value line. `Not set — add` is a prompt, never a fabricated value. */
function Row({ label, value, children }) {
    return (
        <div className="grid grid-cols-[130px_1fr] gap-3 py-[5px] text-[13px] items-center">
            <dt className="text-gray-400">{label}</dt>
            <dd className={value ? 'text-gray-900' : 'text-amber-600'}>
                {children || value || 'Not set — add'}
            </dd>
        </div>
    );
}

// ─── Overview ─────────────────────────────────────────────────────────
function OverviewTab({ lead, nextStep, referralValue, onPriority, draft, setDraft, noteKind, setNoteKind, onPost, posting, me }) {
    const notes = lead.recent_notes || [];
    const idle = idleDays(lead.updated_at || lead.created_at);
    const attempts = lead.attempts || 0;

    const PRIORITIES = [
        { value: 'urgent', label: 'Urgent', on: 'bg-red-50 text-red-700 border-red-200' },
        { value: 'medium', label: 'Normal', on: 'bg-gray-900 text-white border-gray-900' },
        { value: 'low', label: 'Low', on: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    ];
    const cur = lead.priority || 'medium';

    const amount = referralValue?.amount;
    const detail = [
        ['Source', lead.source],
        ['Interest', lead.course || lead.visa],
        ['Program', lead.program_offered],
        ['Owner', 'Sub-agent (you)'],
    ];

    return (
        <div className="space-y-6">
            <div>
                <SectionLabel>Priority</SectionLabel>
                <div className="inline-flex gap-1.5">
                    {PRIORITIES.map((p) => (
                        <button
                            key={p.value}
                            type="button"
                            onClick={() => onPriority(p.value)}
                            className={`px-3.5 py-1.5 rounded-lg text-[12.5px] font-semibold border transition-all ${cur === p.value ? p.on : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50'}`}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* At-a-glance cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <StatCard
                    tone={idle >= 12 ? 'danger' : 'plain'}
                    label="Next step"
                    value={nextStep}
                    caption={`${idle} day${idle === 1 ? '' : 's'} idle · ${attempts} attempt${attempts === 1 ? '' : 's'}`}
                />
                <StatCard
                    label="Stage"
                    value={lead.status}
                    caption={lead.stage_added_at ? `updated ${fmtLongDate(lead.stage_added_at)}` : `added ${fmtLongDate(lead.created_at)}`}
                />
                <StatCard
                    label="Referral value"
                    value={amount ? `$${Number(amount).toLocaleString('en-NZ')}` : null}
                    caption={amount ? `${referralValue.currency || 'NZD'} · ${referralValue.caption || ''}` : 'not set — ask your agent'}
                />
            </div>

            <div>
                <SectionLabel>Lead detail</SectionLabel>
                <dl>{detail.map(([k, v]) => <Row key={k} label={k} value={v} />)}</dl>
            </div>

            <div>
                <SectionLabel right={<span className="text-[11px] text-gray-300">team only</span>}>Internal notes</SectionLabel>

                <div className="rounded-xl border border-gray-200 bg-white focus-within:ring-2 focus-within:ring-purple-500 focus-within:border-purple-500 transition-all">
                    <textarea
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        rows={2}
                        placeholder="What happened on this lead…"
                        className="w-full px-3 py-2.5 text-[13px] bg-transparent resize-none focus:outline-none placeholder-gray-400"
                    />
                    <div className="flex items-center justify-between gap-2 px-2.5 pb-2.5">
                        <div className="flex gap-1">
                            {NOTE_TAGS.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => setNoteKind(t.key)}
                                    className={`px-2.5 py-1 rounded-md text-[11px] font-semibold transition-colors ${noteKind === t.key ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={onPost}
                            disabled={!draft.trim() || posting}
                            className="px-3.5 py-1.5 rounded-lg bg-purple-600 text-white text-[12px] font-semibold hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            {posting ? 'Posting…' : 'Post'}
                        </button>
                    </div>
                </div>

                <div className="mt-3 space-y-2">
                    {notes.map((n) => (
                        <div key={n.id} className="rounded-xl bg-gray-50 border border-gray-100 p-3.5">
                            <div className="flex items-center gap-2 mb-1">
                                <div className="w-5 h-5 rounded-full bg-gray-800 text-white flex items-center justify-center text-[8px] font-bold shrink-0">{initials(n.author_name)}</div>
                                <span className="text-[12px] font-semibold text-gray-800">{n.author_name}</span>
                                <span className="text-[9px] font-bold uppercase tracking-wide text-gray-400">{KIND_LABEL[n.kind] || 'General'}</span>
                                <span className="text-[11px] text-gray-300 ml-auto shrink-0">{relTime(n.created_at)}</span>
                            </div>
                            <p className="text-[13px] text-gray-700 leading-snug whitespace-pre-wrap">{n.body}</p>
                        </div>
                    ))}
                    {notes.length === 0 && <p className="text-[12.5px] text-gray-400 py-1">No notes yet — add the first update above.</p>}
                </div>
            </div>
        </div>
    );
}

function StatCard({ tone = 'plain', label, value, caption }) {
    const cls = tone === 'danger' ? 'bg-red-50/70 border-red-100' : 'bg-white border-gray-200';
    return (
        <div className={`rounded-xl border p-3.5 ${cls}`}>
            <p className="text-[11px] text-gray-400">{label}</p>
            <p className={`text-[14px] font-bold mt-0.5 ${value ? 'text-gray-900' : 'text-gray-300'}`}>{value || 'Not set'}</p>
            {caption && <p className="text-[11px] text-gray-400 mt-0.5">{caption}</p>}
        </div>
    );
}

// ─── Personal ─────────────────────────────────────────────────────────
function PersonalTab({ lead, adviceOwner, form, onEdit, onCancel, onChange }) {
    const p = lead.personal || {};
    const editing = !!form;
    const F = (name, type = 'text') => (
        <input
            type={type}
            value={form?.[name] ?? ''}
            onChange={(e) => onChange(name, e.target.value)}
            className="w-full px-2 py-1 border border-gray-200 rounded-lg text-[13px] bg-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
        />
    );

    const expiryDays = daysUntil(p.passport_expiry);
    const expirySoon = expiryDays !== null && expiryDays < 180;

    return (
        <div className="space-y-6">
            {/* Contact */}
            <div>
                <SectionLabel
                    right={editing
                        ? <button type="button" onClick={onCancel} className="text-[12px] font-semibold text-gray-400 hover:text-gray-700">Done editing</button>
                        : <button type="button" onClick={onEdit} className="text-[12px] font-semibold text-purple-600 hover:text-purple-800">Edit</button>}
                >
                    Contact
                </SectionLabel>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <BoxField label="Email" value={p.email}>{editing && F('email', 'email')}</BoxField>
                    <BoxField label="Mobile · WhatsApp" value={p.whatsapp || p.phone}>
                        {editing && (
                            <div className="grid grid-cols-2 gap-1.5">
                                {F('phone')}
                                {F('whatsapp')}
                            </div>
                        )}
                    </BoxField>
                    <BoxField label="Best time to call" value={p.best_time_to_call}>{editing && F('best_time_to_call')}</BoxField>
                    <BoxField label="Preferred channel" value={p.preferred_channel}>{editing && F('preferred_channel')}</BoxField>
                </div>
            </div>

            {/* Identity */}
            <div>
                <SectionLabel>Identity</SectionLabel>
                <dl>
                    <Row label="Full legal name" value={p.full_legal_name}>
                        {editing && !p.case_name_locked && (
                            <div className="grid grid-cols-3 gap-1.5">
                                {F('first_name')}{F('middle_name')}{F('last_name')}
                            </div>
                        )}
                    </Row>
                    <Row label="Date of birth" value={p.dob ? `${fmtLongDate(p.dob)}${p.age ? ` (${p.age})` : ''}` : null}>
                        {editing && F('dob', 'date')}
                    </Row>
                    <Row label="Nationality" value={p.nationality}>{editing && F('citizenship')}</Row>
                    <Row label="Passport no." value={p.passport_number}>{editing && F('passport_number')}</Row>
                    <Row label="Passport expiry" value={p.passport_expiry}>
                        {editing
                            ? F('passport_expiry', 'date')
                            : p.passport_expiry
                                ? <span className={expirySoon ? 'text-amber-600 font-semibold' : 'text-gray-900'}>
                                    {fmtLongDate(p.passport_expiry)}
                                    {expiryDays !== null && ` · ${expiryDays < 0 ? 'expired' : `${expiryDays} days`}`}
                                </span>
                                : null}
                    </Row>
                    <Row label="Address" value={p.address}>
                        {editing && (
                            <div className="space-y-1.5">
                                {F('residence_address_line_1')}
                                <div className="grid grid-cols-3 gap-1.5">
                                    {F('residence_city')}{F('residence_address_postcode')}{F('residence_country')}
                                </div>
                            </div>
                        )}
                    </Row>
                    <Row label="Languages" value={p.languages}>{editing && F('languages')}</Row>
                    <Row
                        label="Marital status"
                        value={[p.marital_status, p.dependants].filter(Boolean).join(' · ') || null}
                    >
                        {editing && F('marital_status')}
                    </Row>
                </dl>
            </div>

            {/* Background & intent */}
            <div>
                <SectionLabel>Background &amp; intent</SectionLabel>
                <dl>
                    {/* Derived from residence — read-only even in edit mode, since
                        it follows the address rather than being typed. */}
                    <Row label="Current status" value={p.current_status} />
                    <Row label="Goal" value={p.goal}>{editing && F('goal')}</Row>
                    <Row label="Target intake" value={p.target_intake}>{editing && F('preferred_intake')}</Row>
                    <Row label="Highest study" value={p.highest_study}>
                        {editing && (
                            <div className="grid grid-cols-[1fr_1fr_80px] gap-1.5">
                                {F('highest_qualification')}{F('highest_qualification_field')}{F('highest_qualification_year_completed')}
                            </div>
                        )}
                    </Row>
                    <Row label="English test" value={p.english_test}>
                        {editing && (
                            <div className="grid grid-cols-[1fr_80px_1fr] gap-1.5">
                                {F('english_test_type')}{F('english_test_overall_score')}{F('english_test_date', 'date')}
                            </div>
                        )}
                    </Row>
                    {/* Declared visa history is an assessment input — recorded on
                        the intake forms, not editable from a referral screen. */}
                    <Row label="Previous declines" value={p.previous_declines} />
                    <Row label="Emergency contact" value={p.emergency_contact}>{editing && F('emergency_contact')}</Row>
                </dl>
            </div>

            <p className="rounded-xl bg-purple-50 border border-purple-100 px-3.5 py-2.5 text-[12.5px] text-purple-800">
                Collect facts only — eligibility and advice stay with {adviceOwner || 'the licensed adviser'}.
            </p>
        </div>
    );
}

function BoxField({ label, value, children }) {
    return (
        <div className="rounded-xl border border-gray-200 px-3 py-2">
            <p className="text-[10.5px] text-gray-400">{label}</p>
            {children || <p className={`text-[13px] font-semibold mt-0.5 ${value ? 'text-gray-900' : 'text-amber-600'}`}>{value || 'Not set — add'}</p>}
        </div>
    );
}

// ─── Documents ────────────────────────────────────────────────────────
function DocumentsTab({ lead, portalBase, docs, reload }) {
    const [busyType, setBusyType] = useState(null);
    const [requesting, setRequesting] = useState(false);
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');
    const [expiry, setExpiry] = useState('');
    const [file, setFile] = useState(null);

    const slots = docs?.slots || [];
    const required = slots.filter((s) => s.required);
    const extras = slots.filter((s) => !s.required);
    const missing = required.filter((s) => !s.file);
    const filled = docs?.filled ?? 0;
    const total = docs?.total ?? 4;
    const presets = docs?.presets || [];

    const upload = (type, f) => {
        if (!f) return;
        setBusyType(type);
        router.post(`${portalBase}/leads/${lead.id}/documents`, { type, file: f }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: reload, onFinish: () => setBusyType(null),
        });
    };
    const request = (types) => {
        if (!types.length || requesting) return;
        setRequesting(true);
        router.post(`${portalBase}/leads/${lead.id}/documents/request`, { types }, {
            preserveScroll: true, onSuccess: reload, onFinish: () => setRequesting(false),
        });
    };
    const addExtra = (withFile) => {
        if (!name.trim() || adding) return;
        setAdding(true);
        router.post(`${portalBase}/leads/${lead.id}/documents/custom`, {
            name: name.trim(), expires_at: expiry || null, file: withFile ? file : null,
        }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => { setName(''); setExpiry(''); setFile(null); reload(); },
            onFinish: () => setAdding(false),
        });
    };
    const removeExtra = (key) => {
        router.delete(`${portalBase}/leads/${lead.id}/documents/custom/${key}`, {
            preserveScroll: true, onSuccess: reload,
        });
    };

    if (!docs) return <p className="text-[12.5px] text-gray-400">Loading documents…</p>;

    return (
        <div className="space-y-5">
            <div>
                <SectionLabel right={<span className="text-[11.5px] text-gray-400">{filled} of {total} in</span>}>Documents</SectionLabel>
                <div className="h-[3px] rounded-full bg-gray-100 mb-3 overflow-hidden">
                    <div className="h-full rounded-full bg-purple-600 transition-all" style={{ width: total ? `${(filled / total) * 100}%` : '0%' }} />
                </div>

                <div className="space-y-1.5">
                    {[...required, ...extras].map((s) => (
                        <DocRow
                            key={s.type}
                            slot={s}
                            busy={busyType === s.type}
                            requesting={requesting}
                            onUpload={(f) => upload(s.type, f)}
                            onRequest={() => request([s.type])}
                            onRemove={s.required ? null : () => removeExtra(s.type)}
                        />
                    ))}
                </div>

                {missing.length > 1 && (
                    <button
                        type="button"
                        onClick={() => request(missing.map((s) => s.type))}
                        disabled={requesting}
                        className="inline-flex items-center gap-1 mt-2.5 text-[12px] font-semibold text-purple-600 hover:text-purple-800 disabled:text-gray-300"
                    >
                        {requesting ? 'Sending…' : `Request all ${missing.length} missing in one email`}
                        <ArrowRight size={12} />
                    </button>
                )}
            </div>

            {/* Add another */}
            <div className="rounded-xl bg-gray-50 border border-gray-100 p-4">
                <div className="flex items-baseline justify-between gap-3 mb-3">
                    <p className="text-[13px] font-bold text-gray-900">Add another document</p>
                    <span className="text-[11px] text-gray-400">beyond the {total} required</span>
                </div>

                <div className="flex flex-wrap gap-1.5 mb-3">
                    {presets.map((preset) => (
                        <button
                            key={preset}
                            type="button"
                            onClick={() => setName(preset)}
                            className={`px-2.5 py-1.5 rounded-lg border text-[12px] font-semibold transition-colors ${name === preset ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-100'}`}
                        >
                            {preset}
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={() => setName('')}
                        className="px-2.5 py-1.5 rounded-lg border border-dashed border-gray-300 text-[12px] font-semibold text-gray-500 hover:bg-white"
                    >
                        Custom name…
                    </button>
                </div>

                <div className="flex gap-2 mb-2.5">
                    <input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Document name"
                        className="flex-1 min-w-0 px-3 py-2 border border-gray-200 rounded-lg text-[13px] bg-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                    />
                    <input
                        type="date"
                        value={expiry}
                        onChange={(e) => setExpiry(e.target.value)}
                        title="Expiry (optional)"
                        className="w-[150px] px-3 py-2 border border-gray-200 rounded-lg text-[13px] bg-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                    />
                </div>

                <label className="block rounded-lg border border-dashed border-gray-300 bg-white px-4 py-5 text-center cursor-pointer hover:border-purple-400 transition-colors">
                    <p className="text-[13px] font-semibold text-purple-600">{file ? file.name : 'Drop a file or browse'}</p>
                    <p className="text-[11px] text-gray-400 mt-0.5">PDF, JPG or PNG · up to 10 MB</p>
                    <input
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        className="hidden"
                        onChange={(e) => setFile(e.target.files?.[0] || null)}
                    />
                </label>

                <div className="flex flex-wrap gap-2 mt-3">
                    <button
                        type="button"
                        onClick={() => addExtra(true)}
                        disabled={!name.trim() || adding}
                        className="px-3.5 py-2 rounded-lg bg-purple-600 text-white text-[12.5px] font-semibold hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        {adding ? 'Adding…' : 'Add document'}
                    </button>
                    <button
                        type="button"
                        onClick={() => addExtra(false)}
                        disabled={!name.trim() || adding}
                        title="Create the slot and email the lead asking them to upload it"
                        className="px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-[12.5px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Request from lead instead
                    </button>
                </div>
            </div>
        </div>
    );
}

function DocRow({ slot, busy, requesting, onUpload, onRequest, onRemove }) {
    const days = daysUntil(slot.expires_at);
    const soon = days !== null && days < 180;
    const input = (
        <input
            type="file"
            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
            className="hidden"
            disabled={busy}
            onChange={(e) => onUpload(e.target.files?.[0])}
        />
    );

    return (
        <div className={`flex items-center gap-2.5 rounded-lg px-3 py-2.5 border ${slot.file ? 'bg-emerald-50/40 border-emerald-100' : 'bg-white border-gray-200'}`}>
            <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${slot.file ? 'bg-emerald-500' : 'bg-gray-300'}`} />
            <div className="min-w-0 flex-1">
                <span className="block text-[13px] text-gray-800 truncate">
                    {slot.label}
                    {!slot.required && <span className="ml-1.5 text-[10px] font-bold uppercase tracking-wide text-gray-300">extra</span>}
                </span>
                {slot.expires_at && (
                    <span className={`block text-[11px] truncate ${soon ? 'text-amber-600' : 'text-gray-400'}`}>
                        expires {fmtLongDate(slot.expires_at)}{days !== null && ` · ${days < 0 ? 'expired' : `${days} days`}`}
                    </span>
                )}
                {slot.file && !slot.expires_at && <span className="block text-[11px] text-gray-400 truncate">{slot.file.name}</span>}
                {!slot.file && slot.requested_at && <span className="block text-[11px] text-gray-400">requested {relTime(slot.requested_at)}</span>}
            </div>

            <div className="flex items-center gap-2.5 shrink-0">
                {slot.file ? (
                    <>
                        <a href={slot.file.url} className="text-[12px] font-semibold text-gray-500 hover:text-gray-900">View</a>
                        <label className="text-[12px] font-semibold text-gray-300 hover:text-gray-600 cursor-pointer">
                            {busy ? '…' : 'Replace'}{input}
                        </label>
                    </>
                ) : (
                    <>
                        <button type="button" onClick={onRequest} disabled={requesting} className="text-[12px] font-semibold text-gray-500 hover:text-gray-900 disabled:text-gray-300">
                            Request
                        </button>
                        <label className={`inline-flex items-center gap-1 text-[12px] font-semibold cursor-pointer ${busy ? 'text-gray-300' : 'text-purple-600 hover:text-purple-800'}`}>
                            <Upload size={12} />{busy ? '…' : 'Upload'}{input}
                        </label>
                    </>
                )}
                {onRemove && (
                    <button type="button" onClick={onRemove} aria-label="Remove this document slot" className="text-gray-300 hover:text-red-600">
                        <Trash2 size={13} />
                    </button>
                )}
            </div>
        </div>
    );
}
