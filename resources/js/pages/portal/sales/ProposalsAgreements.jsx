import ApprovalReviewLink from '@/components/verification/ApprovalReviewLink';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Head, Link, router } from '@inertiajs/react';
import {
    FileText, Plus, Search, Download, Eye, Loader, X, Check,
    ChevronRight, ChevronDown, Users, Lightbulb, FileSignature, Wand2,
    Mail, Send, AlertCircle, MoreVertical, Pencil, Trash2, StickyNote, ShieldCheck, Flag,
    MessageSquare, RefreshCw,
} from 'lucide-react';

// Portal → URL prefix. Same shape as the other portal-scoped pages.
const PORTAL_BASE = {
    sales:       '/portal/sales',
    education:   '/portal/education',
    immigration: '/portal/immigration',
    admin:       '/admin',
};

// ─── Proposal & Agreements — sidebar page ──────────────────────────────
//
// Lists every lead that has at least one system-generated Proposal or
// Agreement (Consultancy Single/Partner, English Engagement). A "+ New"
// button opens a modal to generate one for any pipeline lead. Generated
// files still appear in the lead's Documents tab (they're LeadDocument
// rows keyed by checklist_key — see agreements section of the shared
// CHECKLIST config).
//
export default function ProposalsAgreements({
    portal = 'sales',
    suggestions = [],
    proposals = [],
    agreements = [],
    picker = [],
    programs = [],
}) {
    const portalBase = PORTAL_BASE[portal] || PORTAL_BASE.sales;
    const [search, setSearch] = useState('');
    const [showNew, setShowNew] = useState(false);
    // Prefill state — used when clicking "Generate" on a suggestion row so
    // the New modal opens with that lead + doc type pre-selected.
    const [prefill, setPrefill] = useState(null);
    // Suggestions moved off the tab strip into a header popover.
    const [tab, setTab] = useState('proposals');
    const [showSuggestions, setShowSuggestions] = useState(false);
    // Dismissed suggestions hide for 30 days — per-viewer, localStorage only
    // (no backend). Expired entries are pruned on load.
    const [dismissedSug, setDismissedSug] = useState(() => {
        try {
            const raw = JSON.parse(localStorage.getItem('pa.dismissedSuggestions') || '{}');
            const now = Date.now();
            const keep = {};
            Object.entries(raw).forEach(([k, ts]) => { if (now - ts < 30 * 86400000) keep[k] = ts; });
            return keep;
        } catch { return {}; }
    });
    const dismissSuggestion = (id) => setDismissedSug((d) => {
        const next = { ...d, [id]: Date.now() };
        try { localStorage.setItem('pa.dismissedSuggestions', JSON.stringify(next)); } catch { /* storage unavailable */ }
        return next;
    });
    const visibleSuggestions = suggestions.filter((s) => ! dismissedSug[s.id]);
    // Notify-lead flow — opens a small confirm dialog with an email
    // preview, then fires the queued mailable pointing at /track/{code}.
    const [notifyTarget, setNotifyTarget] = useState(null); // { lead, kind }
    const openNotify = (row, kind) => setNotifyTarget({ lead: row, kind });
    const closeNotify = () => setNotifyTarget(null);

    const activeRows = tab === 'agreements' ? agreements : proposals;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (! q) return activeRows;
        return activeRows.filter((r) =>
            (r.name || '').toLowerCase().includes(q)
            || (r.email || '').toLowerCase().includes(q)
            || (r.lead_id || '').toLowerCase().includes(q)
        );
    }, [activeRows, search]);

    const openNewWithPrefill = (leadId, suggestion, exactType = null) => {
        // Sensible defaults per suggestion kind. The 6-way consultancy
        // dropdown means "agreement" no longer has an obvious default —
        // Standard · Single · 100,000 is the most common starting point;
        // staff can flip to Couple / Voucher / 150k from the dropdown.
        // `exactType` skips the mapping and prefills with a specific
        // DOC_TYPES key — used by the Agreements "Edit" action to
        // re-open the modal at the same variant the doc was generated
        // with.
        const type = exactType
            ? exactType
            : suggestion === 'proposal'
                ? 'proposal'
                : 'consultancy_std_single_100';
        setPrefill({ leadId, type });
        setShowNew(true);
    };
    const closeNew = () => { setShowNew(false); setPrefill(null); };

    const fmtSize = (b) => {
        if (! b && b !== 0) return '—';
        if (b < 1024) return `${b} B`;
        if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
        return `${(b / (1024 * 1024)).toFixed(2)} MB`;
    };
    const fmtDate = (iso) => iso
        ? new Date(iso).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '—';

    return (
        <div className="space-y-6 pb-12">
            <Head title="Proposal & Agreements" />

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400 mb-1">
                        Leads
                    </p>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Proposal & Agreements</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Every lead with a system-generated proposal or agreement. Generated files still appear on the lead's Documents tab.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <div className="w-full sm:w-72 relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 w-4 h-4" />
                        <input
                            type="text"
                            placeholder="Search name, email or LP ID…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-xl bg-white focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-gray-900 transition-all"
                        />
                    </div>
                    {/* Suggestions — a header popover (no longer a tab). */}
                    <div className="relative">
                        <button
                            type="button"
                            onClick={() => setShowSuggestions((v) => ! v)}
                            className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-semibold border transition-colors ${
                                showSuggestions ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                            }`}
                        >
                            <Lightbulb size={14} className="text-amber-500" /> Suggestions
                            {visibleSuggestions.length > 0 && (
                                <span className="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-white text-[10px] font-bold">{visibleSuggestions.length}</span>
                            )}
                        </button>
                        {showSuggestions && (
                            <SuggestionsPopover
                                suggestions={visibleSuggestions}
                                portalBase={portalBase}
                                onGenerate={openNewWithPrefill}
                                onDismiss={dismissSuggestion}
                                onClose={() => setShowSuggestions(false)}
                            />
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowNew(true)}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl text-sm font-bold hover:bg-black transition-colors shadow-sm whitespace-nowrap flex-shrink-0"
                    >
                        <Plus size={14} className="flex-shrink-0" /> New document
                    </button>
                </div>
            </div>

            {/* Tab strip */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="flex items-center gap-1 px-4 pt-2 border-b border-gray-100">
                    <TabButton
                        active={tab === 'proposals'}
                        onClick={() => { setTab('proposals'); setSearch(''); }}
                        icon={<FileText size={13} />}
                        label="Proposals"
                        count={proposals.length}
                    />
                    <TabButton
                        active={tab === 'agreements'}
                        onClick={() => { setTab('agreements'); setSearch(''); }}
                        icon={<FileSignature size={13} />}
                        label="Agreements"
                        count={agreements.length}
                    />
                </div>

                {filtered.length === 0 ? (
                    <div className="p-16 text-center text-gray-400">
                        <FileText size={26} className="mx-auto mb-2 text-gray-300" />
                        <p className="text-sm font-medium">
                            {tab === 'proposals' ? 'No program shortlists yet' : 'No agreements generated yet'}
                        </p>
                        <p className="text-xs mt-1">
                            {tab === 'proposals'
                                ? <>Click <span className="font-semibold text-gray-600">+ New document</span> and pick up to 5 programs to shortlist for a lead.</>
                                : <>Click <span className="font-semibold text-gray-600">+ New document</span> to generate one for a lead.</>}
                        </p>
                    </div>
                ) : tab === 'proposals' ? (
                    <ProposalsTable rows={filtered} portalBase={portalBase} fmtDate={fmtDate} onNotify={(row) => openNotify(row, 'proposal')} />
                ) : (
                    <DocumentsTable
                        rows={filtered}
                        portalBase={portalBase}
                        fmtSize={fmtSize}
                        fmtDate={fmtDate}
                        onNotify={(row) => openNotify(row, 'agreement')}
                        onEdit={openNewWithPrefill}
                    />
                )}
            </div>

            <NewDocumentModal
                open={showNew}
                onClose={closeNew}
                picker={picker}
                programs={programs}
                prefill={prefill}
            />

            <NotifyLeadModal target={notifyTarget} onClose={closeNotify} />

        </div>
    );
}

// ── Tab button — count badge tinted for suggestions. ──────────────────
function TabButton({ active, onClick, icon, label, count = 0, countTone = 'default' }) {
    const badgeCls = countTone === 'amber'
        ? (active ? 'bg-amber-500 text-white' : 'bg-amber-100 text-amber-700')
        : (active ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600');
    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex items-center gap-1.5 px-3 py-3 text-xs font-bold transition-colors -mb-px border-b-2 ${
                active ? 'text-gray-900 border-gray-900' : 'text-gray-400 border-transparent hover:text-gray-700'
            }`}
        >
            {icon}
            {label}
            <span className={`ml-1 min-w-[20px] text-center px-1.5 py-0.5 rounded-full text-[10px] font-bold tabular-nums ${badgeCls}`}>
                {count}
            </span>
        </button>
    );
}

// ── Suggestions table — leads the system suggests need a doc. Each row
//    has a "Generate" action that opens the New modal pre-filled. ──────
function SuggestionsTable({ rows, portalBase, onGenerate }) {
    const stageChip = (stage) => {
        // Mirror the pipeline stage colours from the Leads table so the
        // suggestion column reads consistently.
        if (! stage) return 'bg-gray-100 text-gray-600 border-gray-200';
        if (stage === 'Consultation Done')     return 'bg-purple-100 text-purple-700 border-purple-200';
        if (stage === 'Proposal Sent')         return 'bg-teal-100 text-teal-700 border-teal-200';
        if (stage === 'Consultancy Agreement') return 'bg-indigo-100 text-indigo-700 border-indigo-200';
        return 'bg-gray-100 text-gray-600 border-gray-200';
    };

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
                <thead>
                    <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                        <th className="px-4 py-3">Lead</th>
                        <th className="px-3 py-3">Current stage</th>
                        <th className="px-3 py-3">Suggested</th>
                        <th className="px-3 py-3 text-right pr-4">Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {rows.map((r) => (
                        <tr key={r.id} className="hover:bg-gray-50/60 transition-colors align-top">
                            <td className="px-4 py-3">
                                <div className="font-semibold text-gray-900">{r.name}</div>
                                <div className="text-[10px] text-gray-400 font-mono">{r.lead_id}</div>
                                {r.email && (
                                    <div className="text-[11px] text-gray-500 truncate max-w-[220px]">{r.email}</div>
                                )}
                            </td>
                            <td className="px-3 py-3">
                                <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border ${stageChip(r.status)}`}>
                                    {r.status || '—'}
                                </span>
                            </td>
                            <td className="px-3 py-3">
                                <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold border ${
                                    r.suggestion === 'proposal'
                                        ? 'bg-blue-50 text-blue-700 border-blue-200'
                                        : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                }`}>
                                    {r.suggestion === 'proposal' ? 'Proposal' : 'Agreement'}
                                </span>
                            </td>
                            <td className="px-3 py-3 text-right pr-4">
                                <div className="inline-flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => onGenerate(r.id, r.suggestion)}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black transition-colors"
                                    >
                                        <Wand2 size={12} /> Generate
                                    </button>
                                    <Link
                                        href={`${portalBase}/leads/${r.id}`}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                                    >
                                        Open lead <ChevronRight size={12} />
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Suggested next documents — header popover (replaces the old Suggestions
//    tab). Each lead the system flags gets a card: current stage, the next
//    document in the pathway, and Generate / Open lead / Dismiss. ──────────
function SuggestionsPopover({ suggestions = [], portalBase, onGenerate, onDismiss, onClose }) {
    const ref = useRef(null);
    useEffect(() => {
        const onClick = (e) => { if (ref.current && ! ref.current.contains(e.target)) onClose(); };
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('mousedown', onClick); document.removeEventListener('keydown', onKey); };
    }, [onClose]);

    const stageChip = (stage) => {
        if (stage === 'Consultation Done')     return 'bg-purple-100 text-purple-700 border-purple-200';
        if (stage === 'Proposal Sent')         return 'bg-teal-100 text-teal-700 border-teal-200';
        if (stage === 'Consultancy Agreement') return 'bg-indigo-100 text-indigo-700 border-indigo-200';
        return 'bg-gray-100 text-gray-600 border-gray-200';
    };
    const initials = (name = '') => name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase() || '—';

    return (
        <div ref={ref} className="absolute right-0 top-full mt-2 w-[360px] max-h-[70vh] overflow-y-auto bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 z-40">
            <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                <div className="flex items-center gap-2">
                    <span className="text-[12px] font-bold text-gray-900">Suggested next documents</span>
                    <span className="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold">{suggestions.length}</span>
                </div>
                <button type="button" onClick={onClose} className="p-1 rounded-md text-gray-400 hover:bg-gray-100"><X size={14} /></button>
            </div>

            {suggestions.length === 0 ? (
                <div className="px-4 py-8 text-center text-[12px] text-gray-400">No suggestions right now.</div>
            ) : (
                <div className="divide-y divide-gray-50">
                    {suggestions.map((r) => {
                        const suggested = r.suggestion === 'proposal' ? 'a study proposal' : 'a consultancy agreement';
                        return (
                            <div key={r.id} className="px-4 py-3">
                                <div className="flex items-center gap-2.5">
                                    <span className="w-8 h-8 rounded-lg inline-flex items-center justify-center text-white text-[10px] font-bold bg-[#14532d] flex-shrink-0">{initials(r.name)}</span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13px] font-semibold text-gray-900 truncate">{r.name}</div>
                                        <div className="text-[10px] text-gray-400 font-mono">{r.lead_id}</div>
                                    </div>
                                    {r.status && (
                                        <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[9px] font-bold border ${stageChip(r.status)}`}>{r.status}</span>
                                    )}
                                </div>
                                <p className="text-[11px] text-gray-500 mt-1.5 leading-snug">
                                    {r.status ? `${r.status} — ` : ''}the next document in the pathway is {suggested}.
                                </p>
                                <div className="flex items-center gap-2 mt-2">
                                    <button type="button" onClick={() => { onGenerate(r.id, r.suggestion); onClose(); }}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#14532d] text-white text-[11px] font-bold hover:bg-[#0f3d21]">
                                        <Wand2 size={12} /> {r.suggestion === 'proposal' ? 'Generate proposal' : 'Generate agreement'}
                                    </button>
                                    <Link href={`${portalBase}/leads/${r.id}`} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-200 text-[11px] font-semibold text-gray-700 hover:bg-gray-50">
                                        Open lead
                                    </Link>
                                    <button type="button" onClick={() => onDismiss(r.id)} className="ml-auto text-[11px] font-semibold text-gray-400 hover:text-gray-700">Dismiss</button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            <div className="px-4 py-2.5 border-t border-gray-100 text-[10px] text-gray-400">
                Suggestions come from each lead's current stage. Dismissing one hides it for 30 days.
            </div>
        </div>
    );
}

// ── Proposals table — leads with a saved program shortlist. Each row
//    lists the picked programs as badges; the tab is intentionally
//    read-only from here (staff manage picks via the New modal). ──
function ProposalsTable({ rows, portalBase, fmtDate, onNotify }) {
    const [sort, setSort] = useState('needs'); // 'needs' | 'newest'

    const byNewest = (a, b) => new Date(b.updated_at || 0) - new Date(a.updated_at || 0);
    const groups = useMemo(() => {
        if (sort === 'newest') {
            return [{ key: 'all', rows: [...rows].sort(byNewest) }];
        }
        const needs = rows.filter((r) => r.group === 'needs_you').sort(byNewest);
        const client = rows.filter((r) => r.group !== 'needs_you').sort(byNewest);

        return [
            { key: 'needs_you', label: 'Needs you', sub: 'action sits with EP', tone: 'amber', rows: needs },
            { key: 'with_client', label: 'With the client', sub: 'waiting on the lead', tone: 'gray', rows: client },
        ];
    }, [rows, sort]);

    return (
        <div className="text-xs">
            {/* Toolbar */}
            <div className="flex items-center justify-end gap-4 px-4 py-2 border-b border-gray-100">
                <button
                    type="button"
                    onClick={() => setSort((s) => (s === 'needs' ? 'newest' : 'needs'))}
                    className="text-[11px] font-semibold text-gray-600 hover:text-gray-900"
                >
                    Sort: {sort === 'needs' ? 'needs me first' : 'newest'}
                </button>
            </div>

            {/* Column header (desktop) */}
            <div className="hidden md:flex items-center gap-4 px-4 py-2.5 bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                <div className="w-[210px] shrink-0">Profile</div>
                <div className="flex-1 grid grid-cols-[minmax(190px,1fr)_minmax(260px,1.15fr)] gap-x-6">
                    <span>Programs</span>
                    <span>Notes</span>
                </div>
                <div className="w-[140px] shrink-0">Status &amp; created</div>
                <div className="w-[32px] shrink-0 text-right">Actions</div>
            </div>

            {groups.map((g) => (g.rows.length === 0 ? null : (
                <div key={g.key}>
                    {g.label && (
                        <div className="flex items-center gap-2 px-4 py-2 bg-gray-50/40 border-b border-gray-100">
                            <span className={`w-1.5 h-1.5 rounded-full ${g.tone === 'amber' ? 'bg-amber-500' : 'bg-gray-400'}`} />
                            <span className="text-[10px] font-bold uppercase tracking-wider text-gray-600">{g.label}</span>
                            <span className="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-gray-200 text-gray-600 text-[10px] font-bold">{g.rows.length}</span>
                            <span className="text-[11px] text-gray-400">· {g.sub}</span>
                        </div>
                    )}
                    {g.rows.map((r) => (
                        <ProposalReviewRow key={r.id} r={r} portalBase={portalBase} fmtDate={fmtDate} onNotify={onNotify} />
                    ))}
                </div>
            )))}

            {rows.length === 0 && (
                <div className="p-12 text-center text-gray-400 text-sm">No proposals yet.</div>
            )}
        </div>
    );
}

// One lead in the Proposals review inbox: profile + aligned programme/note grid.
function ProposalReviewRow({ r, portalBase, fmtDate, onNotify }) {
    return (
        <div className="flex flex-col md:flex-row md:items-stretch gap-4 px-4 py-4 border-b border-gray-100 hover:bg-gray-50/40 transition-colors">
            {/* Profile */}
            <div className="md:w-[210px] shrink-0 flex items-start gap-2.5">
                <div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-gray-100 text-gray-500 text-[11px] font-bold ring-1 ring-gray-200 shrink-0">
                    {r.avatar_url
                        ? <img src={r.avatar_url} alt={r.name} className="w-full h-full object-cover" />
                        : rowInitials(r.name)}
                </div>
                <div className="min-w-0">
                    <Link href={`${portalBase}/leads/${r.id}`} className="font-semibold text-gray-900 hover:text-emerald-700 hover:underline">{r.name}</Link>
                    <div className="text-[10px] text-gray-400 font-mono">{r.lead_id}</div>
                    {r.email && <div className="text-[11px] text-gray-600 truncate max-w-[190px] mt-1">{r.email}</div>}
                    {r.phone && <div className="text-[11px] text-gray-500 truncate max-w-[190px]">{r.phone}</div>}
                </div>
            </div>

            {/* Programs + Notes (aligned per programme) */}
            <div className="flex-1 min-w-0">
                <div className="grid grid-cols-[minmax(190px,1fr)_minmax(260px,1.15fr)] max-md:grid-cols-1 gap-x-6 gap-y-5 items-start">
                    {r.programs.map((p) => {
                        const chosen = !! r.preferred_program_id && p.id === r.preferred_program_id;
                        const dimmed = !! r.preferred_program_id && ! chosen;
                        const flagged = (r.changes_requested?.program_ids || []).includes(p.id);
                        return (
                            <Fragment key={p.id}>
                                {/* Programme */}
                                <div className={`${dimmed ? 'opacity-40' : ''}`}>
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className={`text-[13px] ${chosen ? 'font-bold text-emerald-900' : 'font-semibold text-gray-900'}`} title={p.title}>{p.title}</span>
                                        {p.verify_status === 'verified' && (
                                            <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0"><Check size={8} /> Verified</span>
                                        )}
                                        {p.verify_status === 'needs_check' && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200 shrink-0">Needs check</span>
                                        )}
                                        {flagged && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-rose-50 text-rose-600 border border-rose-200 shrink-0">Change requested</span>
                                        )}
                                        {chosen && (
                                            <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-emerald-600 text-white shrink-0"><Check size={8} /> Chosen</span>
                                        )}
                                    </div>
                                    <div className="text-[11px] text-gray-400 mt-0.5">
                                        <span className="font-medium text-gray-500">Level {p.level}</span>
                                        {p.school ? <span> · {p.school}</span> : null}
                                        {p.intake ? <span> · {p.intake}</span> : null}
                                    </div>
                                </div>
                                {/* Note thread */}
                                <div className={dimmed ? 'opacity-40' : ''}>
                                    <ProgramNoteThread leadId={r.id} program={p} title={p.title} subtitle={`${r.name}${r.lead_id ? ` · ${r.lead_id}` : ''}`} />
                                </div>
                            </Fragment>
                        );
                    })}
                </div>

                {/* Reviewer's overall "request changes" note (proposal-level). */}
                {r.changes_requested?.message && (
                    <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                        <div className="flex items-start gap-1.5">
                            <AlertCircle size={13} className="mt-0.5 shrink-0 text-rose-500" />
                            <div className="min-w-0">
                                <div className="text-[10px] font-bold uppercase tracking-wider text-rose-600">Changes requested</div>
                                <p className="text-[12px] text-gray-800 leading-snug mt-0.5 [overflow-wrap:anywhere] whitespace-pre-wrap">{r.changes_requested.message}</p>
                                <div className="text-[10px] text-rose-700/80 mt-1">
                                    {r.changes_requested.by ? `${r.changes_requested.by} · ` : ''}{fmtNoteTime(r.changes_requested.at)}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Previous proposals (version history) */}
                {Array.isArray(r.previous) && r.previous.length > 0 && (
                    <details className="mt-3 group/hist">
                        <summary className="cursor-pointer list-none inline-flex items-center gap-1 text-[10px] font-semibold text-gray-400 hover:text-gray-600 select-none">
                            <ChevronRight size={10} className="transition-transform group-open/hist:rotate-90" />
                            {r.previous.length} previous proposal{r.previous.length === 1 ? '' : 's'}
                        </summary>
                        <div className="mt-2 space-y-2 border-l-2 border-gray-100 pl-3">
                            {r.previous.map((v) => (
                                <div key={v.id}>
                                    <div className="text-[9px] font-bold uppercase tracking-wider text-gray-400">{fmtDate(v.created_at)}{v.created_by ? ` · ${v.created_by}` : ''}</div>
                                    {v.programs.map((p) => (
                                        <div key={p.id} className="text-[11px] text-gray-600">L{p.level} · {p.title}</div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </details>
                )}
            </div>

            {/* Status & created */}
            <div className="md:w-[140px] shrink-0 whitespace-nowrap">
                <ProposalStatusBadge status={r.proposal_status} />
                <div><ApprovalReviewLink leadId={r.id} status={r.proposal_status} /></div>
                <div className="text-[11px] text-gray-500 mt-1.5">{fmtDate(r.updated_at)}</div>
                <div className="text-[10px] text-gray-400 mt-0.5">{r.created_by_label}</div>
            </div>

            {/* Actions */}
            <div className="md:w-[32px] shrink-0 md:text-right">
                <ProposalRowActions row={r} portalBase={portalBase} onNotify={onNotify} />
            </div>
        </div>
    );
}

// Format a note timestamp as "Today, 12:40 pm" / "Yesterday, …" / "05 Sept, …".
function fmtNoteTime(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const t = d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return `Today, ${t}`;
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return `Yesterday, ${t}`;
    return `${d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short' })}, ${t}`;
}

// Author role → little badge shown beside a note author, matching the redesign
// (ADMIN in dark, everyone else reads as REVIEWER).
function roleBadge(role) {
    const r = String(role || '').toLowerCase();
    if (r === 'admin' || r === 'super_admin') return { label: 'ADMIN', cls: 'bg-gray-900 text-white' };
    return { label: 'REVIEWER', cls: 'bg-amber-100 text-amber-700' };
}

// Flatten a thread into chronological messages (each note followed by its replies).
function threadMessages(notes) {
    const out = [];
    [...notes].forEach((n) => {
        out.push({ id: n.id, author: n.author, role: n.role, body: n.body, created_at: n.created_at, tag: n.tag });
        (n.replies || []).forEach((rp) => out.push({ id: rp.id, author: rp.author, role: rp.role, body: rp.body, created_at: rp.created_at, reply: true }));
    });
    return out;
}

// Right-side slide-out thread panel — the full conversation for one row plus a
// reply composer. Replies + "Reply & resolve" reuse the existing note endpoints.
function NoteThreadDrawer({ notes = [], base, title, subtitle, onClose }) {
    const [reply, setReply] = useState('');
    const [busy, setBusy] = useState(false);
    const target = [...notes].reverse()[0]; // reply onto the latest note
    const msgs = threadMessages(notes);

    const doReply = (resolve) => {
        if (! reply.trim() || ! target) return;
        setBusy(true);
        router.post(`${base}/${target.id}/reply`, { body: reply }, {
            preserveScroll: true,
            onSuccess: () => {
                setReply('');
                if (resolve) router.post(`${base}/${target.id}/actioned`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
                else setBusy(false);
            },
            onError: () => setBusy(false),
        });
    };

    return createPortal(
        <div className="fixed inset-0 z-[60] flex justify-end" onClick={onClose}>
            <div className="absolute inset-0 bg-black/20" />
            <div className="relative w-full max-w-[380px] h-full bg-white shadow-2xl flex flex-col" onClick={(e) => e.stopPropagation()}>
                {/* Header */}
                <div className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Note thread</p>
                        <h3 className="text-sm font-bold text-gray-900 truncate">{title || 'Notes'}</h3>
                        {subtitle && <p className="text-[11px] text-gray-500 truncate">{subtitle}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="p-1.5 rounded-md hover:bg-gray-100 text-gray-500"><X size={16} /></button>
                </div>

                {/* Messages */}
                <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    {msgs.length === 0 && <p className="text-[12px] text-gray-400 italic">No messages yet.</p>}
                    {msgs.map((m) => {
                        return (
                            <div key={m.id} className={m.reply ? 'pl-4 border-l-2 border-gray-100' : ''}>
                                <div className="flex items-center gap-1.5 flex-wrap">
                                    <span className="text-[12px] font-bold text-gray-900">{m.author}</span>
                                    <span className="text-[10px] text-gray-400">{fmtNoteTime(m.created_at)}</span>
                                </div>
                                <div className={`mt-1 rounded-lg px-3 py-2 text-[12px] leading-snug [overflow-wrap:anywhere] whitespace-pre-wrap ${m.tag === 'change_requested' ? 'bg-rose-50 text-rose-900 border border-rose-100' : 'bg-amber-50 text-gray-800 border border-amber-100'}`}>
                                    {m.body}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* Composer */}
                <div className="px-5 py-3 border-t border-gray-100">
                    <textarea
                        value={reply}
                        onChange={(e) => setReply(e.target.value)}
                        rows={2}
                        placeholder="Reply to the reviewer…"
                        className="w-full rounded-lg border border-gray-200 px-3 py-2 text-[13px] outline-none focus:ring-2 focus:ring-[#14532d]/20 focus:border-[#14532d] resize-y"
                    />
                    <div className="flex items-center justify-between gap-2 mt-2">
                        <div className="flex items-center gap-2">
                            <button type="button" disabled={busy || ! reply.trim()} onClick={() => doReply(false)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#14532d] text-white text-[12px] font-bold hover:bg-[#0f3d21] disabled:opacity-40">
                                <Send size={12} /> Send reply
                            </button>
                            <button type="button" disabled={busy || ! reply.trim()} onClick={() => doReply(true)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-[12px] font-bold hover:bg-gray-50 disabled:opacity-40">
                                Reply &amp; resolve
                            </button>
                        </div>
                        <span className="text-[10px] text-gray-400">Visible to staff &amp; reviewers</span>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}

// Compact note cell for a table row — shows the latest note as a preview with a
// "View thread" link that opens the slide-out drawer. Reuses the note endpoints
// at `base` (POST base = add, base/{id}/reply, base/{id}/actioned).
function NoteThreadCell({ notes = [], base, title, subtitle, extraCard = null, emptyLabel = 'No note yet', addLabel = 'Add note', placeholder = 'Add a note…' }) {
    const ordered = [...notes].reverse(); // newest first
    const [drawer, setDrawer] = useState(false);
    const [adding, setAdding] = useState(false);
    const [body, setBody] = useState('');
    const addNote = () => {
        if (! body.trim()) return;
        router.post(base, { body, tag: 'note' }, { preserveScroll: true, onSuccess: () => { setBody(''); setAdding(false); } });
    };

    if (notes.length === 0) {
        return (
            <div className="space-y-2 min-w-[240px]">
                {extraCard}
                {adding ? (
                    <NoteComposer value={body} onChange={setBody} onSubmit={addNote} onCancel={() => { setBody(''); setAdding(false); }} placeholder={placeholder} />
                ) : (
                    <div className="flex items-center gap-2">
                        {! extraCard && <span className="text-[11px] text-gray-400 italic">{emptyLabel}</span>}
                        <button type="button" onClick={() => setAdding(true)} className="text-[11px] font-semibold text-emerald-700 hover:text-emerald-900">+ {addLabel}</button>
                    </div>
                )}
            </div>
        );
    }

    const n = ordered[0];
    const cr = n.tag === 'change_requested';
    const replyCount = notes.reduce((s, x) => s + ((x.replies || []).length), 0);
    const needsReply = cr && ! n.actioned_at;

    return (
        <div className="space-y-2 min-w-[240px]">
            {extraCard}
            <div className={`rounded-lg border px-3 py-2 ${cr ? 'border-rose-200 bg-rose-50' : 'border-amber-200 bg-amber-50'}`}>
                <div className="flex items-center gap-1.5 flex-wrap">
                    <span className="text-[11px] font-semibold text-gray-800">{n.author}</span>
                    <span className="text-[10px] text-gray-400">{fmtNoteTime(n.created_at)}</span>
                </div>
                <p className={`mt-1 text-[12px] leading-snug text-gray-800 [overflow-wrap:anywhere] whitespace-pre-wrap ${n.actioned_at ? 'line-through text-gray-400' : ''}`}>{n.body}</p>
                <div className="mt-1.5 flex items-center gap-2 text-[11px] font-semibold">
                    {replyCount > 0 && <span className="text-gray-400">{replyCount} repl{replyCount === 1 ? 'y' : 'ies'} ·</span>}
                    <button type="button" onClick={() => setDrawer(true)} className="text-[#14532d] hover:underline">View thread</button>
                    {needsReply && <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 border border-amber-200">Reply needed</span>}
                </div>
            </div>
            <button type="button" onClick={() => setDrawer(true)} className="text-[11px] font-semibold text-emerald-700 hover:text-emerald-900">Reply / add note</button>
            {drawer && <NoteThreadDrawer notes={notes} base={base} title={title} subtitle={subtitle} onClose={() => setDrawer(false)} />}
        </div>
    );
}

// Threaded notes for one programme — display + Add note / Reply / Mark actioned.
function ProgramNoteThread({ leadId, program, title, subtitle }) {
    const notes = program.notes || [];
    // The single free-text staff note captured on the Program Verification
    // screen (leads.proposed_program_meta[<program>].note), surfaced as a
    // pinned card above the thread.
    const vnote = (program.note || '').trim();
    const base = `/admin/leads/${leadId}/program-notes/${program.id}`;

    const verificationCard = vnote ? (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
            <div className="flex items-start gap-1.5">
                <Flag size={12} className="mt-0.5 shrink-0 text-amber-500" />
                <span className="text-[12px] font-medium leading-snug text-gray-800 [overflow-wrap:anywhere] whitespace-pre-wrap">{vnote}</span>
            </div>
            <div className="text-[10px] mt-1 ml-[18px] text-amber-700/80">From program verification</div>
        </div>
    ) : null;

    return (
        <NoteThreadCell
            notes={notes}
            base={base}
            title={title || program.title}
            subtitle={subtitle}
            extraCard={verificationCard}
            emptyLabel="No note on this program"
            addLabel="Add note"
            placeholder="Add a note on this programme…"
        />
    );
}

function NoteTag({ tag }) {
    if (tag === 'change_requested') {
        return <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 shrink-0">Change requested</span>;
    }
    return <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-gray-100 text-gray-500 shrink-0">Note</span>;
}

function NoteComposer({ value, onChange, onSubmit, onCancel, placeholder, small }) {
    return (
        <div>
            <textarea
                autoFocus
                rows={small ? 2 : 2}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-[11px] focus:outline-none focus:border-gray-900 resize-y"
            />
            <div className="flex items-center gap-2 mt-1">
                <button type="button" onClick={onSubmit} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-gray-900 text-white text-[11px] font-bold hover:bg-black">
                    <Send size={11} /> Post
                </button>
                <button type="button" onClick={onCancel} className="text-[11px] font-semibold text-gray-500 hover:text-gray-800">Cancel</button>
            </div>
        </div>
    );
}

// ── Documents table — leads that already have generated docs. Shared by
//    the Proposals + Agreements tabs (they just receive different rows). ──
// Map a stored LeadDocument (source_variant + checklist_key) back to the
// DOC_TYPES key so the "Edit" action can pre-open the modal at the same
// variant the doc was generated with.
function variantToTypeKey(doc) {
    if (doc.checklist_key === 'agree.engagement_english') {
        if (doc.variant === 'engagement-english-offshore-1000') return 'english_offshore_1000';
        return doc.variant === 'engagement-english-offshore' ? 'english_offshore' : 'english_engagement';
    }
    if (doc.checklist_key === 'agree.consultancy') {
        const parts = (doc.variant || '').split(':');
        const scenario = parts[1] || 'std_100';
        const mode = parts[2] === 'couple' ? 'couple' : 'single';
        // Onshore engagement + offshore each map to their own single DOC_TYPE.
        if (scenario === 'onshore') return 'consultancy_onshore';
        if (scenario === 'offshore_zero') return 'consultancy_offshore_zero';
        if (scenario === 'offshore') return 'consultancy_offshore';
        const backend = `consultancy_${scenario}`;
        const match = DOC_TYPES.find((t) => t.backendType === backend && t.applicantMode === mode);
        return match?.value || 'consultancy_std_single_100';
    }
    return 'consultancy_std_single_100';
}

// Verification status of a study proposal. pending/verified = still in Program
// Verification ("Verifying"); approved = live on the client's tracker. Null =
// legacy proposal (predates the workflow) — treated as live.
function ProposalStatusBadge({ status }) {
    const map = {
        pending:  { label: 'Verifying', cls: 'bg-amber-50 text-amber-700 border-amber-200' },
        verified: { label: 'Verifying', cls: 'bg-blue-50 text-blue-700 border-blue-200' },
        approved: { label: 'Approved',  cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    };
    const m = map[status] || { label: 'Live', cls: 'bg-gray-100 text-gray-600 border-gray-200' };
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border ${m.cls}`}>
            {m.label}
        </span>
    );
}

// Initials fallback for the profile avatar when there's no face image.
const rowInitials = (name = '') =>
    (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join('') || '?';

// Threaded staff notes on one generated agreement — a straight copy of the
// Proposals tab's per-programme note thread (ProgramNoteThread): collapsed amber
// card for the latest note, expandable full thread with author/time, tags,
// Acknowledge, Reply + composer, "N notes ›" collapse, and Add note. Lets the
// generation staff and the verification staff talk on the same thread.
function DocumentNoteThread({ docId, notes = [], title, subtitle }) {
    return (
        <NoteThreadCell
            notes={notes}
            base={`/admin/documents/${docId}/notes`}
            title={title || 'Agreement'}
            subtitle={subtitle}
            emptyLabel="No note on this agreement"
            addLabel="Add note"
            placeholder="Add a note on this agreement…"
        />
    );
}

// Agreements tab — same review-inbox design/layout as the Proposals tab:
// a sort toolbar, a desktop column header, and grouped profile/document/notes
// rows. Grouped by whether the agreement still needs verification vs. is live.
function DocumentsTable({ rows, portalBase, fmtSize, fmtDate, onNotify, onEdit }) {
    const [sort, setSort] = useState('needs'); // 'needs' | 'newest'

    // One row per PERSON — a lead's agreements are stacked inside their row
    // (re-generations no longer split into separate rows).
    const leadRows = useMemo(
        () => rows.map((r) => ({ ...r, documents: [...(r.documents || [])].sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0)) })),
        [rows],
    );
    const byNewest = (a, b) => new Date(b.latest_generated_at || 0) - new Date(a.latest_generated_at || 0);
    const hasPending = (r) => (r.documents || []).some((d) => d.pending_verification);

    const groups = useMemo(() => {
        if (sort === 'newest') {
            return [{ key: 'all', rows: [...leadRows].sort(byNewest) }];
        }
        const pending = leadRows.filter(hasPending).sort(byNewest);
        const live = leadRows.filter((r) => ! hasPending(r)).sort(byNewest);

        return [
            { key: 'pending', label: 'Needs verification', sub: 'action sits with EP', tone: 'amber', rows: pending },
            { key: 'live', label: 'Generated & sent', sub: 'live agreements', tone: 'gray', rows: live },
        ];
    }, [leadRows, sort]);

    return (
        <div className="text-xs">
            {/* Toolbar */}
            <div className="flex items-center justify-end gap-4 px-4 py-2 border-b border-gray-100">
                <button
                    type="button"
                    onClick={() => setSort((s) => (s === 'needs' ? 'newest' : 'needs'))}
                    className="text-[11px] font-semibold text-gray-600 hover:text-gray-900"
                >
                    Sort: {sort === 'needs' ? 'needs verification first' : 'newest'}
                </button>
            </div>

            {/* Column header (desktop) */}
            <div className="hidden md:flex items-center gap-4 px-4 py-2.5 bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                <div className="w-[210px] shrink-0">Profile</div>
                <div className="flex-1 grid grid-cols-[minmax(190px,1fr)_minmax(260px,1.15fr)] gap-x-6">
                    <span>Document</span>
                    <span>Notes</span>
                </div>
                <div className="w-[110px] shrink-0">Total amount</div>
                <div className="w-[140px] shrink-0">Status &amp; created</div>
                <div className="w-[32px] shrink-0 text-right">Actions</div>
            </div>

            {groups.map((g) => (g.rows.length === 0 ? null : (
                <div key={g.key}>
                    {g.label && (
                        <div className="flex items-center gap-2 px-4 py-2 bg-gray-50/40 border-b border-gray-100">
                            <span className={`w-1.5 h-1.5 rounded-full ${g.tone === 'amber' ? 'bg-amber-500' : 'bg-gray-400'}`} />
                            <span className="text-[10px] font-bold uppercase tracking-wider text-gray-600">{g.label}</span>
                            <span className="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-gray-200 text-gray-600 text-[10px] font-bold">{g.rows.length}</span>
                            <span className="text-[11px] text-gray-400">· {g.sub}</span>
                        </div>
                    )}
                    {g.rows.map((lead) => (
                        <DocumentRow key={lead.id} lead={lead} portalBase={portalBase} fmtSize={fmtSize} fmtDate={fmtDate} onNotify={onNotify} onEdit={onEdit} />
                    ))}
                </div>
            )))}

            {leadRows.length === 0 && (
                <div className="p-12 text-center text-gray-400 text-sm">No agreements yet.</div>
            )}
        </div>
    );
}

// Lifecycle badge for one agreement — pending reviews show their verification
// state; live agreements read the lead's pipeline stage.
function agreementBadge(doc, lead) {
    if (doc.pending_verification) {
        return doc.verification_status === 'verified'
            ? { label: 'Verified',         cls: 'bg-blue-50 text-blue-700 border-blue-200' }
            : { label: 'For verification', cls: 'bg-amber-50 text-amber-700 border-amber-200' };
    }
    if (lead.status === 'Consultancy Agreement Signed') return { label: 'Signed', cls: 'bg-emerald-100 text-emerald-800 border-emerald-200' };
    // An approved agreement is live with the client → "Sent", even if the
    // lead's pipeline status predates the approve→sent stage bump.
    if (lead.status === 'Consultancy Agreement Sent' || doc.review_approved) return { label: 'Sent', cls: 'bg-indigo-100 text-indigo-800 border-indigo-200' };
    return { label: 'Generated', cls: 'bg-gray-100 text-gray-600 border-gray-200' };
}

// One row per PERSON — the profile shows once on the left, and each of the
// lead's agreements is stacked on the right (document · notes · status · ⋮).
function DocumentRow({ lead, portalBase, fmtSize, fmtDate, onNotify, onEdit }) {
    const docs = lead.documents || [];
    return (
        <div className="flex flex-col md:flex-row md:items-stretch gap-4 px-4 py-4 border-b border-gray-100 hover:bg-gray-50/40 transition-colors">
            {/* Profile — once for the person */}
            <div className="md:w-[210px] shrink-0 flex items-start gap-2.5">
                <div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-gray-100 text-gray-500 text-[11px] font-bold ring-1 ring-gray-200 shrink-0">
                    {lead.avatar_url
                        ? <img src={lead.avatar_url} alt={lead.name} className="w-full h-full object-cover" />
                        : rowInitials(lead.name)}
                </div>
                <div className="min-w-0">
                    <Link href={`${portalBase}/leads/${lead.id}`} className="font-semibold text-gray-900 hover:text-emerald-700 hover:underline">{lead.name}</Link>
                    <div className="text-[10px] text-gray-400 font-mono">{lead.lead_id}</div>
                    {lead.email && <div className="text-[11px] text-gray-600 truncate max-w-[190px] mt-1">{lead.email}</div>}
                    {lead.phone && <div className="text-[11px] text-gray-500 truncate max-w-[190px]">{lead.phone}</div>}
                    {docs.length > 1 && (
                        <div className="text-[10px] text-gray-400 mt-1">{docs.length} agreements</div>
                    )}
                </div>
            </div>

            {/* Agreements — one sub-row per document, columns aligned to the header */}
            <div className="flex-1 min-w-0 flex flex-col divide-y divide-gray-100">
                {docs.map((doc) => {
                    const mode = doc.applicant_mode; // 'single' | 'couple' | null
                    const pending = doc.pending_verification;
                    const badge = agreementBadge(doc, lead);
                    // Generic document category, shown under the specific type.
                    const category = doc.checklist_key === 'agree.engagement_english'
                        ? 'English agreement'
                        : doc.checklist_key === 'agree.proposal'
                            ? 'Study proposal'
                            : 'Consultancy agreement';
                    return (
                        <div key={doc.id} className="flex flex-col md:flex-row md:items-stretch gap-4 py-3 first:pt-0 last:pb-0">
                            {/* Document + Notes (aligned) */}
                            <div className="flex-1 min-w-0">
                                <div className="grid grid-cols-[minmax(190px,1fr)_minmax(260px,1.15fr)] max-md:grid-cols-1 gap-x-6 gap-y-3 items-start">
                                    <div>
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <FileText size={13} className="text-gray-400 shrink-0" />
                                            {pending ? (
                                                <span className="text-[13px] font-semibold text-gray-900">{doc.type}</span>
                                            ) : (
                                                <a
                                                    href={`/admin/documents/${doc.id}/download?inline=1`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    title="View PDF"
                                                    className="text-[13px] font-semibold text-gray-900 hover:text-emerald-700 hover:underline cursor-pointer"
                                                >
                                                    {doc.type}
                                                </a>
                                            )}
                                            {mode && (
                                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider ${
                                                    mode === 'couple'
                                                        ? 'bg-purple-50 text-purple-700 border border-purple-100'
                                                        : 'bg-blue-50 text-blue-700 border border-blue-100'
                                                }`}>
                                                    {mode}
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-[11px] text-gray-500 mt-0.5 ml-[21px]">{category}</div>
                                    </div>
                                    <div>
                                        {pending
                                            ? <span className="text-[11px] text-gray-400 italic">Notes in verification</span>
                                            : <DocumentNoteThread docId={doc.id} notes={doc.notes || []} title={`${doc.type}${doc.applicant_mode ? ` (${doc.applicant_mode})` : ''}`} subtitle={`${lead.name}${lead.lead_id ? ` · ${lead.lead_id}` : ''}`} />}
                                    </div>
                                </div>
                            </div>

                            {/* Total amount */}
                            <div className="md:w-[110px] shrink-0 whitespace-nowrap">
                                {doc.total_amount != null ? (
                                    <span className="text-[13px] font-bold text-gray-900">
                                        {doc.currency_symbol || 'Php'} {Number(doc.total_amount).toLocaleString()}
                                    </span>
                                ) : (
                                    <span className="text-[11px] text-gray-300">—</span>
                                )}
                            </div>

                            {/* Status & created */}
                            <div className="md:w-[140px] shrink-0 whitespace-nowrap">
                                <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border ${badge.cls}`}>
                                    {badge.label}
                                </span>
                                <div className="text-[11px] text-gray-500 mt-1.5">{fmtDate(doc.created_at)}</div>
                                {doc.uploader?.name && (
                                    <div className="text-[10px] text-gray-400">by {doc.uploader.name}</div>
                                )}
                            </div>

                            {/* Actions */}
                            {pending && <ApprovalReviewLink leadId={lead.id} kind="consultancy" status="pending" />}
                            <div className="md:w-[32px] shrink-0 md:text-right">
                                <DocumentRowActions doc={doc} lead={lead} onNotify={onNotify} onEdit={onEdit} pending={pending} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// 3-dot menu for the Agreements table — closes on outside click and Esc.
// Menu items call into the same endpoints as the old row buttons; Delete
// hits the DELETE route with a confirm.
function DocumentRowActions({ doc, lead, onNotify, onEdit, pending = false }) {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef(null);

    useEffect(() => {
        if (! open) return;
        const onClick = (e) => {
            if (wrapRef.current && ! wrapRef.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const canEdit = doc.checklist_key === 'agree.consultancy' || doc.checklist_key === 'agree.engagement_english';
    const canEmail = !! lead.email;
    // English agreements can be pushed into the Consultancy Agreement
    // Verification queue (older ones were generated straight to a PDF).
    const isEnglishAgreement = doc.checklist_key === 'agree.engagement_english';
    // Consultancy agreements carry a Sent → Signed lifecycle staff can set by
    // hand (there's no e-sign for these generated docs). Reuses the same
    // stage endpoint the Leads list uses.
    const isConsultancy = doc.checklist_key === 'agree.consultancy';
    const markStage = (status) => {
        setOpen(false);
        router.post(`/admin/leads/${lead.id}/stage`, { status }, { preserveScroll: true });
    };

    const handleDelete = () => {
        setOpen(false);
        if (! confirm(`Delete this ${doc.type}? This removes the file from ${lead.name}'s documents.`)) return;
        router.delete(`/admin/leads/${lead.id}/documents/${doc.id}`, { preserveScroll: true });
    };

    const handleEdit = () => {
        setOpen(false);
        onEdit(lead.id, 'agreement', variantToTypeKey(doc));
    };

    const handleEmail = () => {
        setOpen(false);
        onNotify(lead);
    };

    const handleSendForVerification = () => {
        setOpen(false);
        router.post(`/admin/leads/${lead.id}/documents/${doc.id}/to-verification`, {}, { preserveScroll: true });
    };

    // Re-render the PDF in place from the current stored amount — no re-verify.
    const handleRegenerate = () => {
        setOpen(false);
        router.post(`/admin/leads/${lead.id}/documents/${doc.id}/regenerate`, {}, { preserveScroll: true });
    };

    return (
        <div ref={wrapRef} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((v) => ! v)}
                className={`w-8 h-8 rounded-lg flex items-center justify-center transition-colors ${
                    open ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                }`}
                aria-label="Row actions"
                aria-expanded={open}
            >
                <MoreVertical size={15} />
            </button>

            {open && pending && (
                <div className="absolute right-0 top-full mt-1 w-52 bg-white rounded-xl shadow-xl ring-1 ring-black/5 py-1.5 z-30 text-[12px]">
                    {/* Pending verification has no file yet — only the review action. */}
                    <Link
                        href="/consultancy-verification"
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2.5 px-3 py-2 text-gray-700 hover:bg-gray-50 hover:text-indigo-700 transition-colors"
                    >
                        <ShieldCheck size={13} className="text-gray-400" /> Review in verification
                    </Link>
                </div>
            )}

            {open && ! pending && (
                <div className="absolute right-0 top-full mt-1 w-52 bg-white rounded-xl shadow-xl ring-1 ring-black/5 py-1.5 z-30 text-[12px]">
                    <a
                        href={`/admin/documents/${doc.id}/download?inline=1`}
                        target="_blank"
                        rel="noreferrer"
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2.5 px-3 py-2 text-gray-700 hover:bg-gray-50 hover:text-blue-700 transition-colors"
                    >
                        <Eye size={13} className="text-gray-400" /> View
                    </a>
                    <a
                        href={`/admin/documents/${doc.id}/download`}
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2.5 px-3 py-2 text-gray-700 hover:bg-gray-50 hover:text-emerald-700 transition-colors"
                    >
                        <Download size={13} className="text-gray-400" /> Download
                    </a>
                    {canEdit && (
                        <button
                            type="button"
                            onClick={handleEdit}
                            className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition-colors"
                        >
                            <Pencil size={13} className="text-gray-400" /> Edit / regenerate
                        </button>
                    )}
                    {canEdit && (
                        <button
                            type="button"
                            onClick={handleRegenerate}
                            title="Re-render the PDF from the current amount — no re-verification"
                            className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition-colors"
                        >
                            <RefreshCw size={13} className="text-gray-400" /> Regenerate PDF
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={handleEmail}
                        disabled={! canEmail}
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 hover:text-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-gray-700"
                        title={canEmail ? '' : 'Lead has no email on file'}
                    >
                        <Mail size={13} className="text-gray-400" /> Email lead
                    </button>
                    {isEnglishAgreement && (
                        <>
                            <div className="my-1 border-t border-gray-100" />
                            <button
                                type="button"
                                onClick={handleSendForVerification}
                                className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 hover:text-emerald-700 transition-colors"
                            >
                                <ShieldCheck size={13} className="text-gray-400" /> Send for verification
                            </button>
                        </>
                    )}
                    {isConsultancy && (
                        <>
                            <div className="my-1 border-t border-gray-100" />
                            <button
                                type="button"
                                onClick={() => markStage('Consultancy Agreement Sent')}
                                className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 hover:text-indigo-700 transition-colors"
                            >
                                <Send size={13} className="text-gray-400" /> Mark as sent
                            </button>
                            <button
                                type="button"
                                onClick={() => markStage('Consultancy Agreement Signed')}
                                className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 hover:text-emerald-700 transition-colors"
                            >
                                <Check size={13} className="text-gray-400" /> Mark as signed
                            </button>
                        </>
                    )}
                    <div className="my-1 border-t border-gray-100" />
                    <button
                        type="button"
                        onClick={handleDelete}
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-left text-red-600 hover:bg-red-50 transition-colors"
                    >
                        <Trash2 size={13} /> Delete
                    </button>
                </div>
            )}
        </div>
    );
}

// ── Program picker — replaces the PDF preview on the left of the New
//    modal when Proposal type is selected. Cap is enforced in the parent
//    (togglePicked drops the oldest pick to make room for a fourth). ───
function ProgramPicker({ allPrograms = [], programs, search, setSearch, pickedIds, togglePicked, max, reasons = {}, setReason }) {
    const pickedSet = new Set(pickedIds);
    // Look up picked-program details from the FULL catalogue so the
    // "Selected" chips keep their titles even when an active search
    // hides those rows from the list below. `programs` is filtered;
    // `allPrograms` is the raw catalogue.
    const pickedById = new Map(allPrograms.map((p) => [p.id, p]));
    return (
        <div className="bg-white flex flex-col min-h-0 flex-1">
            <div className="px-5 pt-4 pb-3 border-b border-gray-100 flex flex-col gap-3">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-widest text-gray-500">Programs</p>
                        <p className="text-[11px] text-gray-500 mt-0.5">Tick up to {max}. The lead sees these on their tracker.</p>
                    </div>
                    <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold tabular-nums ${
                        pickedIds.length === max ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-700'
                    }`}>
                        {pickedIds.length} / {max}
                    </span>
                </div>

                {/* Selected programs — each with a "why this program" reason the
                    client sees on their tracker. Removable per row. */}
                {pickedIds.length > 0 && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2.5">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-emerald-700 mb-2">
                            Selected · {pickedIds.length}
                        </p>
                        <div className="space-y-2">
                            {pickedIds.map((id) => {
                                const p = pickedById.get(id);
                                return (
                                    <div key={id} className="rounded-md bg-white border border-emerald-200 shadow-sm p-2">
                                        <div className="flex items-center gap-1.5">
                                            {p ? (
                                                <>
                                                    <span className="text-[9px] font-bold uppercase tracking-wider px-1 py-0.5 rounded bg-emerald-100 text-emerald-700 shrink-0">
                                                        L{p.level}
                                                    </span>
                                                    <span className="text-[12px] font-semibold text-gray-800 truncate" title={p.title}>{p.title}</span>
                                                </>
                                            ) : (
                                                <span className="text-[12px] text-gray-500">Program #{id}</span>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => togglePicked(id)}
                                                className="ml-auto w-5 h-5 flex items-center justify-center rounded hover:bg-red-50 text-gray-400 hover:text-red-600 transition-colors shrink-0"
                                                title="Remove from proposal"
                                            >
                                                <X size={12} />
                                            </button>
                                        </div>
                                        <textarea
                                            rows={2}
                                            value={reasons[id] ?? ''}
                                            onChange={(e) => setReason?.(id, e.target.value)}
                                            placeholder="Why this program? (the client sees this on their tracker)"
                                            maxLength={1000}
                                            className="w-full mt-1.5 px-2.5 py-1.5 text-[12px] border border-gray-200 rounded-md focus:outline-none focus:border-gray-400 resize-y"
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search title, school, location or level…"
                        className="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none focus:border-gray-900"
                    />
                </div>
            </div>

            <div className="flex-1 overflow-y-auto p-3 space-y-1.5">
                {programs.length === 0 ? (
                    <p className="text-xs text-gray-400 italic px-2 py-6 text-center">No matching programs.</p>
                ) : programs.map((p) => {
                    const picked = pickedSet.has(p.id);
                    return (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => togglePicked(p.id)}
                            className={`w-full text-left px-3 py-2.5 rounded-lg border transition-colors ${
                                picked ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 hover:border-gray-400 hover:bg-gray-50'
                            }`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2 mb-0.5">
                                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider ${
                                            picked ? 'bg-white/20 text-white' : 'bg-emerald-50 text-emerald-700 border border-emerald-100'
                                        }`}>
                                            Level {p.level}
                                        </span>
                                        {p.category && (
                                            <span className={`text-[10px] font-medium capitalize ${picked ? 'text-gray-300' : 'text-gray-500'}`}>
                                                {p.category}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-sm font-semibold truncate">{p.title}</p>
                                    {p.school && (
                                        <p className={`text-[11px] font-medium truncate ${picked ? 'text-gray-200' : 'text-gray-600'}`} title={p.school}>
                                            {p.school}
                                        </p>
                                    )}
                                    <div className={`flex items-center gap-2 mt-1 text-[11px] ${picked ? 'text-gray-300' : 'text-gray-500'}`}>
                                        {p.location && <span>{p.location}</span>}
                                        {p.price_text && <span>· {p.price_text}</span>}
                                    </div>
                                </div>
                                {picked && (
                                    <Check size={14} className="text-white mt-1 shrink-0" />
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

// ── Small design primitives used by the modal's right column ─────────

// Numbered section header with an emerald pill, tight label + optional
// muted hint on the same row, plus a trailing slot (chip / status / etc).
function StepHeader({ n, label, hint, trailing = null }) {
    return (
        <div className="flex items-center justify-between mb-2">
            <div className="flex items-baseline gap-2">
                <span className="inline-flex items-center justify-center w-5 h-5 rounded-md bg-[#436235] text-white text-[10px] font-black tabular-nums shadow-sm">
                    {n}
                </span>
                <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-700">{label}</span>
                {hint && <span className="text-[10px] text-gray-400 italic">{hint}</span>}
            </div>
            {trailing}
        </div>
    );
}

// Currency-prefixed number input with an inline symbol pill that matches
// the brand emerald. Handles the parseInt-guard so the caller stays clean.
function FeeInput({ label, value, onChange, step = 1000, symbol = '₱' }) {
    return (
        <label className="block">
            <span className="block text-[10px] font-semibold text-gray-600 mb-1">{label}</span>
            <div className="relative flex items-center rounded-lg bg-white ring-1 ring-gray-200 shadow-sm focus-within:ring-2 focus-within:ring-emerald-500/30 focus-within:border-emerald-600 transition-all overflow-hidden">
                <span className="pl-2.5 pr-1.5 py-1.5 text-[11px] font-black text-[#436235] bg-emerald-50 border-r border-emerald-100 tabular-nums">
                    {symbol}
                </span>
                <input
                    type="number"
                    min="0"
                    step={step}
                    value={value}
                    onChange={(e) => onChange(parseInt(e.target.value, 10) || 0)}
                    className="w-full px-2.5 py-1.5 text-sm bg-transparent focus:outline-none tabular-nums font-semibold text-gray-900"
                />
            </div>
        </label>
    );
}

// Editable single-line bank-detail field with an optional prefix pill
// (used for the "#reference" line). Always editable — presets just fill it.
function BankField({ label, value, onChange, placeholder = '', prefix = null }) {
    return (
        <label className="block">
            <span className="block text-[10px] font-semibold text-gray-600 mb-1">{label}</span>
            <div className="flex items-center rounded-lg bg-white ring-1 ring-gray-200 shadow-sm focus-within:ring-2 focus-within:ring-emerald-500/30 focus-within:border-emerald-600 transition-all overflow-hidden">
                {prefix && <span className="pl-2.5 pr-0.5 py-1.5 text-[12px] font-bold text-gray-400">{prefix}</span>}
                <input
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                    maxLength={120}
                    className={`w-full ${prefix ? 'pl-0' : 'pl-2.5'} pr-2.5 py-1.5 text-sm bg-transparent focus:outline-none text-gray-900`}
                />
            </div>
        </label>
    );
}

// Category resolver — colour-codes the doc-type dropdown so staff can
// see at a glance which "family" this document belongs to (Proposal /
// Consultancy / English Engagement).
function documentCategory(type) {
    if (! type) {
        return {
            label: 'Not selected',
            chip: 'bg-gray-100 text-gray-500 ring-1 ring-gray-200',
            dot: 'bg-gray-400',
            border: 'border-gray-200',
        };
    }
    if (type === 'proposal') {
        return {
            label: 'Proposal',
            chip: 'bg-violet-100 text-violet-700 ring-1 ring-violet-200',
            dot: 'bg-violet-500',
            border: 'border-violet-200',
        };
    }
    if (type === 'english_engagement' || type === 'english_offshore' || type === 'english_offshore_1000') {
        return {
            label: 'English',
            chip: 'bg-sky-100 text-sky-700 ring-1 ring-sky-200',
            dot: 'bg-sky-500',
            border: 'border-sky-200',
        };
    }
    // Everything else is a consultancy scenario.
    return {
        label: 'Consultancy',
        chip: 'bg-emerald-100 text-[#436235] ring-1 ring-emerald-200',
        dot: 'bg-[#436235]',
        border: 'border-emerald-200',
    };
}

// ── 3-dot actions menu for a Proposals row — Notify + Open lead.
//    Mirrors the Agreements row menu; closes on outside click / Esc. ──
function ProposalRowActions({ row, portalBase, onNotify }) {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef(null);
    const hasEmail = !! row.email;

    const resubmit = () => {
        setOpen(false);
        router.post(`/admin/leads/${row.id}/proposal/resubmit`, {}, { preserveScroll: true });
    };

    useEffect(() => {
        if (! open) return;
        const onClick = (e) => { if (wrapRef.current && ! wrapRef.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div ref={wrapRef} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((v) => ! v)}
                className={`w-8 h-8 rounded-lg flex items-center justify-center transition-colors ${
                    open ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                }`}
                aria-label="Row actions"
                aria-expanded={open}
            >
                <MoreVertical size={15} />
            </button>

            {open && (
                <div className="absolute right-0 top-full mt-1 w-44 bg-white rounded-xl shadow-xl ring-1 ring-black/5 py-1.5 z-30 text-[12px]">
                    <button
                        type="button"
                        disabled={! hasEmail}
                        onClick={() => { setOpen(false); onNotify && onNotify(row); }}
                        title={hasEmail ? `Email ${row.name} about their tracker` : 'Lead has no email on file'}
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-gray-700 hover:bg-gray-50 hover:text-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                    >
                        <Mail size={13} className="text-gray-400" /> Notify
                    </button>
                    <button
                        type="button"
                        onClick={resubmit}
                        title="Send this proposal back to the Program Verification queue"
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-gray-700 hover:bg-gray-50 hover:text-amber-700 transition-colors"
                    >
                        <ShieldCheck size={13} className="text-gray-400" /> Send for verification
                    </button>
                    <Link
                        href={`${portalBase}/leads/${row.id}`}
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2.5 px-3 py-2 text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition-colors"
                    >
                        <ChevronRight size={13} className="text-gray-400" /> Open lead
                    </Link>
                </div>
            )}
        </div>
    );
}

// ── Notify button — small pill on each Proposals / Agreements row.
//    Only enabled when the lead has an email on file. ─────────────────
function NotifyButton({ row, onNotify }) {
    const hasEmail = !! row.email;
    return (
        <button
            type="button"
            disabled={! hasEmail}
            onClick={() => onNotify && onNotify(row)}
            title={hasEmail
                ? `Send ${row.name} an email about their tracker`
                : 'Lead has no email on file — cannot notify'}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold text-emerald-800 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 hover:text-emerald-900 transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-emerald-50"
        >
            <Mail size={12} /> Notify
        </button>
    );
}

// ── Notify Lead modal — compact confirm dialog with an email preview
//    (subject + body) and a Send button. Queues the mail via the
//    /notify-document-ready endpoint. ─────────────────────────────────
function NotifyLeadModal({ target, onClose }) {
    const [sending, setSending] = useState(false);
    // Which shortlisted program the lead chose — only used for proposals.
    // Seeded from whatever's already saved so re-notifying doesn't wipe it.
    const [selectedProgramId, setSelectedProgramId] = useState(null);
    useEffect(() => {
        setSelectedProgramId(target?.lead?.preferred_program_id ?? null);
        setSending(false);
    }, [target]);

    if (! target) return null;
    const { lead, kind } = target;
    const isProposal = kind === 'proposal';
    const programs   = lead.programs || [];
    const nounTitle  = isProposal ? 'Proposal' : 'Agreement';
    const noun       = nounTitle.toLowerCase();
    const firstName  = (lead.name || '').split(' ')[0] || 'there';
    const hasEmail   = !! lead.email;
    const subject    = `Your ${nounTitle} is ready — EP`;

    const send = () => {
        setSending(true);
        const payload = { kind };
        // Record the lead's program pick alongside the notification so the
        // list highlights it. Sent only for proposals; null clears it.
        if (isProposal) payload.preferred_program_id = selectedProgramId;
        router.post(`/admin/leads/${lead.id}/notify-document-ready`, payload, {
            preserveScroll: true,
            onFinish: () => { setSending(false); onClose(); },
        });
    };

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                            <Mail size={16} className="text-emerald-700 shrink-0" />
                            Notify lead
                        </h3>
                        <p className="text-xs text-gray-500 mt-0.5">
                            Sends {lead.name || 'the lead'} an email pointing at their tracker.
                        </p>
                    </div>
                    <button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center shrink-0">
                        <X size={16} />
                    </button>
                </div>

                <div className="p-5 space-y-3">
                    {! hasEmail && (
                        <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 text-xs">
                            <AlertCircle size={14} className="mt-0.5 shrink-0" />
                            <div>
                                <p className="font-semibold">No email on file</p>
                                <p className="mt-0.5">Add an email on the lead profile before you can notify them.</p>
                            </div>
                        </div>
                    )}

                    {/* To */}
                    <div className="grid grid-cols-[68px_1fr] items-baseline gap-2 border-b border-gray-100 pb-2">
                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">To</span>
                        <span className="text-xs text-gray-800 truncate">
                            <span className="font-semibold">{lead.name}</span>
                            {lead.email && <span className="text-gray-500"> &lt;{lead.email}&gt;</span>}
                        </span>
                    </div>

                    {/* Subject */}
                    <div className="grid grid-cols-[68px_1fr] items-baseline gap-2 border-b border-gray-100 pb-2">
                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Subject</span>
                        <span className="text-xs font-semibold text-gray-900">{subject}</span>
                    </div>

                    {/* Program the lead chose — proposals only. Highlights in
                        the list once saved; leave on "Not decided yet" to skip. */}
                    {isProposal && programs.length > 0 && (
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">
                                Program the lead chose
                            </p>
                            <div className="space-y-1.5">
                                {programs.map((p) => {
                                    const active = selectedProgramId === p.id;
                                    return (
                                        <label
                                            key={p.id}
                                            className={`flex items-center gap-2.5 px-3 py-2 rounded-lg border cursor-pointer transition-colors ${
                                                active ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name="preferred_program"
                                                checked={active}
                                                onChange={() => setSelectedProgramId(p.id)}
                                                className="accent-emerald-600"
                                            />
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-700 shrink-0">
                                                L{p.level}
                                            </span>
                                            <span className="text-[12px] font-medium text-gray-800 truncate" title={p.title}>
                                                {p.title}
                                            </span>
                                        </label>
                                    );
                                })}
                                <label
                                    className={`flex items-center gap-2.5 px-3 py-2 rounded-lg border cursor-pointer transition-colors ${
                                        selectedProgramId === null ? 'border-gray-400 bg-gray-50' : 'border-gray-200 hover:border-gray-300'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="preferred_program"
                                        checked={selectedProgramId === null}
                                        onChange={() => setSelectedProgramId(null)}
                                        className="accent-gray-600"
                                    />
                                    <span className="text-[12px] font-medium text-gray-500">Not decided yet</span>
                                </label>
                            </div>
                        </div>
                    )}

                    {/* Preview */}
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Preview</p>
                        <div className="rounded-lg border border-gray-100 bg-gray-50/60 px-4 py-3 text-[12px] text-gray-700 leading-relaxed space-y-2">
                            <p><span className="font-semibold">Hi {firstName},</span></p>
                            <p>We've prepared your {noun} and it's waiting on your personal tracker. Open the link below to review it — no login required.</p>
                            <div className="inline-block px-3 py-1.5 rounded-md bg-gray-900 text-white text-[11px] font-bold">
                                Open my tracker →
                            </div>
                            <p className="text-[11px] text-gray-500 italic">Ngā mihi, The EP team</p>
                        </div>
                    </div>
                </div>

                <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-between gap-2 bg-gray-50/50">
                    <p className="text-[11px] text-gray-500 italic">
                        Sends via the EP queue — usually within a minute.
                    </p>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={! hasEmail || sending}
                            onClick={send}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl text-sm font-bold hover:bg-black disabled:opacity-60 transition-colors"
                        >
                            {sending ? <Loader size={14} className="animate-spin" /> : <Send size={14} />}
                            Send notification
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── New Document modal — pick lead + type, then either save program picks
//    (Proposal type) or POST to the generate endpoint (Agreements). ─────
// 6 flat consultancy options (Single / Couple baked into each) + 1
// English Engagement + Proposal. `backendType` is the type key the
// server route knows; `applicantMode` gets sent alongside so the PDF
// swaps the applicant line + cost breakdown. `defaultSchoolFee` is
// the fee that pre-fills the Settings panel — staff can override.
// `category` groups doc types under the Category selector. `all` = shown in
// every category (Study Proposal). `free` doc types have no fee/bank panel.
const DOC_TYPES = [
    { value: 'proposal',                          label: 'Study Proposal',                                 category: 'all',         hint: 'Suggest up to 5 programs — the lead picks one on their tracker.' },

    { value: 'consultancy_std_single_100',        label: 'Standard · Single · 100,000',                    category: 'philippines', hint: 'Sole applicant. School Enrolment + Documentation Fee.',                                       backendType: 'consultancy_std_100',     applicantMode: 'single', defaultSchoolFee: 100000 },
    { value: 'consultancy_std_single_150',        label: 'Standard · Single · 150,000',                    category: 'philippines', hint: 'Sole applicant. School Enrolment + INZ visa application fee.',                                backendType: 'consultancy_std_150',     applicantMode: 'single', defaultSchoolFee: 150000 },
    { value: 'consultancy_std_couple_150',        label: 'Standard · Couple · 150,000',                    category: 'philippines', hint: 'Applicant + partner. School Enrolment + INZ visa application fee.',                           backendType: 'consultancy_std_150',     applicantMode: 'couple', defaultSchoolFee: 150000 },
    { value: 'consultancy_voucher_single_150',    label: 'With Voucher · Single · 150,000',                category: 'philippines', hint: 'Sole applicant. Inclusive of the INZ visa application fee (voucher).',                        backendType: 'consultancy_voucher_150', applicantMode: 'single', defaultSchoolFee: 150000 },
    { value: 'consultancy_voucher_couple_150',    label: 'With Voucher · Couple · 150,000',                category: 'philippines', hint: 'Applicant + partner. Inclusive of the INZ visa application fee (voucher).',                   backendType: 'consultancy_voucher_150', applicantMode: 'couple', defaultSchoolFee: 150000 },
    { value: 'consultancy_english_single_100',    label: 'With English · Single · 100,000',                category: 'philippines', hint: 'Sole applicant with English review add-on.',                                                  backendType: 'consultancy_english_100', applicantMode: 'single', defaultSchoolFee: 100000 },
    { value: 'english_engagement',                label: 'Offshore - Philippines',                         category: 'english',     hint: 'English Engagement Agreement (PTE prep + exam) — Php. Editable English Review + PTE Exam fees.', backendType: 'english_engagement', englishFee: true, hasPte: true, defaultEnglishFee: 14500, defaultPteFee: 240, currency: 'php' },
    { value: 'english_offshore',                  label: 'Offshore - English',                             category: 'english',     hint: 'English Proficiency Test (IELTS/PTE) Review Agreement — NZD. Editable package fee.', backendType: 'english_offshore', englishFee: true, defaultEnglishFee: 550, currency: 'nzd' },
    { value: 'english_offshore_1000',             label: 'Offshore - English ($1000)',                     category: 'english',     hint: 'IELTS/PTE Review — NZD $1,000 package (20h coaching + unlimited mock + PTE exam fee). Editable.', backendType: 'english_offshore_1000', englishFee: true, defaultEnglishFee: 1000, currency: 'nzd' },

    { value: 'consultancy_onshore',               label: 'Onshore Engagement (free)',                      category: 'onshore',     hint: 'Applicant already in NZ. Education engagement — FREE OF CHARGE (no consultancy fees). Refers to a Licensed Immigration Adviser.', backendType: 'consultancy_onshore', free: true },

    { value: 'consultancy_offshore',              label: 'Standard · Offshore',                            category: 'offshore',    hint: 'Applicant offshore. Single package fee — Documentation, School Enrolment & Visa Application. NZ$ + ANZ.', backendType: 'consultancy_offshore', applicantMode: 'single', defaultSchoolFee: 3500, singleFee: true },
    { value: 'consultancy_offshore_zero',         label: 'Standard · Offshore — Zero fees',                category: 'offshore',    hint: 'Applicant offshore. Same document as Standard · Offshore, but all fees waived (NZ$0).', backendType: 'consultancy_offshore_zero', applicantMode: 'single', defaultSchoolFee: 0, singleFee: true, zeroFees: true },
];
// Consultancy types that carry a fee/bank panel (excludes the free onshore).
// Consultancy scenarios only — a paid backend type that ISN'T an English
// engagement (those carry `englishFee` and route through their own fee/preview
// branch, so they must not be treated as consultancy).
const CONSULTANCY_TYPES = new Set(DOC_TYPES.filter((t) => t.backendType && ! t.free && ! t.englishFee).map((t) => t.value));
const MAX_PROPOSED_PROGRAMS = 5;
const DEFAULT_ENGLISH_FEE = 14500;

// Category → currency. Selecting a category sets the document currency
// automatically (no separate currency picker). Philippines = PhP; Onshore &
// Offshore are NZ-based = NZ$.
const CATEGORIES = {
    philippines: { code: 'philippines', label: 'Philippines', currency: 'php' },
    onshore:     { code: 'onshore',     label: 'Onshore',     currency: 'nzd' },
    offshore:    { code: 'offshore',    label: 'Offshore',    currency: 'nzd' },
    english:     { code: 'english',     label: 'English',     currency: 'nzd' },
};

// Currency metadata keyed by code — drives the fee symbol + locale in the UI.
const CURRENCIES = {
    php: { code: 'php', label: 'Philippine Peso', short: 'PhP', symbol: '₱',   locale: 'en-PH' },
    nzd: { code: 'nzd', label: 'NZ Dollar',       short: 'NZ$', symbol: 'NZ$', locale: 'en-NZ' },
};

// Bank-detail presets for the consultancy agreement's payment block. Picking
// one fills the fields (which stay editable); "Other" blanks them for a fully
// custom bank. RCBC is the default so existing documents are unchanged. The
// reference is left to the per-client default (their slug) unless staff type one.
const BANK_PRESETS = {
    rcbc:  { label: 'RCBC',  heading: 'Payment for School Enrollment and Documentation Fee',              bank_name: 'RCBC', account_name: 'Dinah Suarin',          account_number: '9045440503' },
    anz:   { label: 'ANZ',   heading: 'Payment for Documentation, School Enrolment, and Visa Application Fee', bank_name: 'ANZ',  account_name: 'EMPLOYMENT PATHWAYS LTD', account_number: '06-0185-0987269-01' },
    bpi:   { label: 'BPI',   heading: 'PAYMENT DETAILS',                                                  bank_name: 'BPI',  account_name: 'Dinah Suarin',          account_number: '9269224808' },
    other: { label: 'Other', heading: '',                                                                 bank_name: '',     account_name: '',                     account_number: '' },
};

function NewDocumentModal({ open, onClose, picker, programs = [], prefill = null }) {
    const [leadId, setLeadId] = useState('');
    const [downloading, setDownloading] = useState(false);
    const [downloadError, setDownloadError] = useState('');
    // No default — staff must actively pick a doc type. Empty string
    // keeps the preview area empty and the Generate button disabled.
    const [type, setType] = useState('');
    // Category chosen before the doc type — filters which doc types show and
    // sets the currency automatically (Philippines = PhP, Onshore/Offshore = NZ$).
    const [category, setCategory] = useState('philippines');
    // Currency: a document type may pin its own (e.g. Offshore - Philippines is
    // always Php even under the English category); otherwise the category sets it.
    const currency = (DOC_TYPES.find((t) => t.value === type)?.currency)
        || (CATEGORIES[category] || CATEGORIES.philippines).currency;
    const cur = CURRENCIES[currency] || CURRENCIES.php;
    // Editable bank details (consultancy agreements). A preset fills the
    // fields; every field stays editable. RCBC default = unchanged docs.
    const [bank, setBank] = useState(() => ({ preset: 'rcbc', ...BANK_PRESETS.rcbc, reference: '' }));
    const applyBankPreset = (preset) => setBank((b) => ({ ...b, preset, ...BANK_PRESETS[preset] }));
    const setBankField = (key, val) => setBank((b) => ({ ...b, [key]: val }));
    const [leadSearch, setLeadSearch] = useState('');
    // Server-side search results for the current query — the page's `picker`
    // prop is a capped roster, so a lead late in the alphabet isn't in it; this
    // finds any eligible lead. Keyed by the query it answered so stale results
    // aren't shown after the box changes.
    const [remote, setRemote] = useState({ q: '', leads: [] });
    useEffect(() => {
        const q = leadSearch.trim();
        if (! q) return;
        let cancelled = false;
        const t = setTimeout(() => {
            fetch(`/admin/leads/doc-picker-search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : { leads: [] }))
                .then((d) => { if (! cancelled) setRemote({ q, leads: d.leads || [] }); })
                .catch(() => { if (! cancelled) setRemote({ q, leads: [] }); });
        }, 250);
        return () => { cancelled = true; clearTimeout(t); };
    }, [leadSearch]);
    const [programSearch, setProgramSearch] = useState('');
    const [pickedProgramIds, setPickedProgramIds] = useState([]);
    // Per-program "why this program" reasons, keyed by program id.
    const [pickedReasons, setPickedReasons] = useState({});
    const setReason = (id, text) => setPickedReasons((r) => ({ ...r, [id]: text }));
    const [submitting, setSubmitting] = useState(false);
    // Editable amounts on the Settings panel — only surfaced for the 4
    // consultancy scenarios. Defaults come from the selected DOC_TYPE.
    const [schoolFee, setSchoolFee] = useState(100000);
    const [englishFee, setEnglishFee] = useState(DEFAULT_ENGLISH_FEE);
    // Offshore - Philippines carries a second, USD-priced PTE Examination fee.
    const [pteFee, setPteFee] = useState(240);
    // Preview iframe loading state — flipped to true whenever the URL
    // changes, back to false when the iframe fires `onLoad`. Gives staff
    // a spinner instead of a suspicious white A4 while dompdf renders.
    const [previewLoading, setPreviewLoading] = useState(false);
    // Double-confirm dialog before an amount-bearing agreement is submitted
    // for verification — surfaces client name + amount + document type.
    const [confirmOpen, setConfirmOpen] = useState(false);
    // Email opt-in — checked by default. When true and the chosen lead
    // has an email, the modal fires the notify endpoint right after the
    // generate/save so the client gets a nudge on the same click.
    const [notify, setNotify] = useState(true);

    // When the modal opens with a prefill (from the Suggestions tab's
    // Generate action) pre-select that lead + doc type so staff can just
    // hit "Generate" without re-picking.
    useEffect(() => {
        if (! open) return;
        if (prefill) {
            setLeadId(prefill.leadId);
            setType(prefill.type);
            // Jump to the category the prefilled type belongs to.
            const meta = DOC_TYPES.find((t) => t.value === prefill.type);
            setCategory(meta && meta.category !== 'all' ? meta.category : 'philippines');
        } else {
            setLeadId('');
            setType('');
            setCategory('philippines');
        }
        setPickedProgramIds([]);
        setPickedReasons({});
        setProgramSearch('');
        setNotify(true);
        setBank({ preset: 'rcbc', ...BANK_PRESETS.rcbc, reference: '' });
    }, [open, prefill]);

    // Reset the fee amounts to the scenario's defaults whenever the type
    // changes. Staff can then edit before generating; edits stay put
    // while they flip around within the same scenario.
    useEffect(() => {
        const meta = DOC_TYPES.find((t) => t.value === type);
        // For the English engagement the single package fee reuses schoolFee.
        setSchoolFee(meta?.defaultSchoolFee ?? meta?.defaultEnglishFee ?? 100000);
        setEnglishFee(DEFAULT_ENGLISH_FEE);
        setPteFee(meta?.defaultPteFee ?? 240);
        // Offshore uses the ANZ bank block; English agreements use BPI; other
        // PH consultancy uses RCBC.
        if (type === 'consultancy_offshore') {
            setBank({ preset: 'anz', ...BANK_PRESETS.anz, reference: '' });
        } else if (meta?.englishFee) {
            setBank({ preset: 'bpi', ...BANK_PRESETS.bpi, reference: '' });
        } else if (meta?.backendType && ! meta.free) {
            setBank({ preset: 'rcbc', ...BANK_PRESETS.rcbc, reference: '' });
        }
    }, [type]);

    // Resolve the selected doc-type entry once — used for backendType +
    // applicantMode when building the preview URL and the submit body.
    const typeMeta = useMemo(() => DOC_TYPES.find((t) => t.value === type) || null, [type]);

    // Doc types available for the chosen category ('all' shows everywhere).
    const visibleTypes = useMemo(
        () => DOC_TYPES.filter((t) => t.category === category || t.category === 'all'),
        [category],
    );

    const filteredPicker = useMemo(() => {
        const q = leadSearch.trim().toLowerCase();
        if (! q) return picker.slice(0, 50);
        // Immediate client-side matches from the loaded roster…
        const client = picker.filter((p) =>
            (p.name || '').toLowerCase().includes(q)
            || (p.email || '').toLowerCase().includes(q)
            || (p.lead_id || '').toLowerCase().includes(q)
        );
        // …plus server results for this query (leads beyond the capped roster),
        // deduped by id. Falls back to the client list until the fetch returns.
        if (remote.q.trim().toLowerCase() === q) {
            const seen = new Set(remote.leads.map((l) => l.id));
            return [...remote.leads, ...client.filter((c) => ! seen.has(c.id))].slice(0, 50);
        }
        return client.slice(0, 50);
    }, [picker, leadSearch, remote]);

    // Proposal path saves a program shortlist; every other type kicks off
    // the templated PDF generator. Keep both paths behind the same
    // "Generate" button so staff don't have to think about it.
    // Chosen lead — used by the email opt-in row + the case chip.
    const chosenLead = useMemo(
        () => picker.find((p) => p.id === leadId) || null,
        [picker, leadId],
    );
    const canNotify = !! chosenLead?.email;

    const submit = () => {
        if (! leadId || ! type) return;
        setSubmitting(true);
        const isProposal = type === 'proposal';
        const routeType = typeMeta?.backendType || type;
        const url = isProposal
            ? `/admin/leads/${leadId}/proposal`
            : `/admin/leads/${leadId}/generate/${routeType}`;
        // Consultancy scenarios carry the editable fees + baked-in
        // applicant_mode along so the saved PDF reflects whatever staff
        // typed on the Settings panel and which Single/Couple option they
        // originally chose.
        // Agreements email the client on generation itself (the generate
        // endpoint sends `consultancy_agreement`), so the notify preference
        // rides along in the payload instead of a second POST.
        const wantNotify = notify && canNotify;
        const payload = isProposal
            ? {
                program_ids: pickedProgramIds,
                // Only reasons for currently-picked programs, blanks dropped.
                reasons: Object.fromEntries(
                    pickedProgramIds
                        .filter((id) => (pickedReasons[id] || '').trim())
                        .map((id) => [id, pickedReasons[id].trim()]),
                ),
            }
            : (isConsultancyType
                ? {
                    school_enrolment_fee: schoolFee,
                    english_proficiency_fee: englishFee,
                    applicant_mode: typeMeta?.applicantMode || 'single',
                    currency,
                    bank_heading: bank.heading,
                    bank_name: bank.bank_name,
                    bank_account_name: bank.account_name,
                    bank_account_number: bank.account_number,
                    bank_reference: bank.reference,
                    notify: wantNotify,
                }
                : (isEnglishType
                    ? {
                        currency,
                        english_fee: schoolFee,
                        ...(typeMeta?.hasPte ? { pte_fee: pteFee } : {}),
                        bank_heading: bank.heading,
                        bank_name: bank.bank_name,
                        bank_account_name: bank.account_name,
                        bank_account_number: bank.account_number,
                        bank_reference: bank.reference,
                        notify: wantNotify,
                    }
                    : { currency, notify: wantNotify }));

        const finish = () => {
            setSubmitting(false);
            onClose();
            setLeadId('');
            setType('');
            setLeadSearch('');
            setPickedProgramIds([]);
            setPickedReasons({});
            setNotify(true);
        };

        router.post(url, payload, {
            preserveScroll: true,
            onSuccess: () => {
                // Consultancy/onshore/offshore agreements already emailed the
                // client inside the generate endpoint. Study proposals now defer
                // their email to the Program Verification approval step, so they
                // skip it here too. English Engagement still notifies immediately.
                if (wantNotify && ! isAgreementType && ! isProposal) {
                    router.post(`/admin/leads/${leadId}/notify-document-ready`, {
                        kind: isProposal ? 'proposal' : 'agreement',
                    }, {
                        preserveScroll: true,
                        onFinish: finish,
                    });
                } else {
                    finish();
                }
            },
            onError: finish,
        });
    };

    // Filtered programs for the picker (case-insensitive title / school / level / location).
    const filteredPrograms = useMemo(() => {
        const q = programSearch.trim().toLowerCase();
        if (! q) return programs;
        return programs.filter((p) =>
            (p.title || '').toLowerCase().includes(q)
            || (p.school || '').toLowerCase().includes(q)
            || (p.location || '').toLowerCase().includes(q)
            || String(p.level ?? '').includes(q)
        );
    }, [programs, programSearch]);

    const togglePickedProgram = (id) => {
        setPickedProgramIds((prev) => {
            if (prev.includes(id)) {
                // Unpicking — drop its reason too.
                setPickedReasons((r) => { const n = { ...r }; delete n[id]; return n; });
                return prev.filter((x) => x !== id);
            }
            if (prev.length >= MAX_PROPOSED_PROGRAMS) {
                // Hard cap — drop the oldest pick (and its reason) to make room.
                const dropped = prev[0];
                setPickedReasons((r) => { const n = { ...r }; delete n[dropped]; return n; });
                return [...prev.slice(1), id];
            }
            return [...prev, id];
        });
    };

    const isProposalType = type === 'proposal';
    // Must have a lead AND an explicitly chosen type. Proposals also
    // require at least one picked program.
    const canSubmit = leadId && type && (! isProposalType || pickedProgramIds.length > 0);

    // Consultancy = has a fee/bank panel (excludes the free onshore engagement).
    const isConsultancyType = CONSULTANCY_TYPES.has(type);
    const isEnglishType = type === 'english_engagement' || type === 'english_offshore' || type === 'english_offshore_1000';
    // Any agreement whose generate endpoint self-emails the client (all
    // consultancy scenarios + onshore + offshore) — so the modal skips the
    // second notify POST for these.
    const isAgreementType = !! typeMeta?.backendType;

    // Amount-bearing types get a confirm-before-submit dialog.
    const needsAmountConfirm = isConsultancyType || isEnglishType;
    // Human amount summary shown in the confirm dialog + settings.
    const amountText = useMemo(() => {
        const n = (v) => Number(v || 0).toLocaleString(cur.locale);
        if (typeMeta?.hasPte) return `${cur.symbol}${n(schoolFee)} · PTE US$${Number(pteFee || 0).toLocaleString()}`;
        if (typeMeta?.singleFee || isEnglishType) return `${cur.symbol}${n(schoolFee)}`;
        return `${cur.symbol}${n(Number(schoolFee) + Number(englishFee))}`;
    }, [typeMeta, schoolFee, englishFee, pteFee, cur, isEnglishType]);
    // While the live preview is still rendering the freshly-typed amount, keep
    // the submit button read-only; it re-enables once the preview has settled.
    const amountSettling = needsAmountConfirm && previewLoading;

    // Live iframe preview URL — same lead + type params the generate
    // endpoint uses, but hits the preview route which renders the Blade
    // as HTML (skipping dompdf) so it's fast enough for interactive use.
    // For consultancy scenarios, the editable fees ride along as query
    // params so the preview updates the moment staff type a new amount.
    const previewUrl = useMemo(() => {
        if (! leadId || ! type) return null;
        // Consultancy dropdown items map to a backend `type` + a baked-in
        // applicant_mode; everything else passes through as-is.
        const routeType = typeMeta?.backendType || type;
        const base = `/admin/leads/${leadId}/generate/${routeType}/preview`;
        // Currency rides on every preview (not just consultancy) so the symbol
        // updates live for English Engagement too.
        if (! isConsultancyType) {
            // English engagement carries its editable package fee into the preview.
            const q = { currency };
            if (isEnglishType) {
                q.english_fee = String(schoolFee || 0);
                q.bank_heading = bank.heading || '';
                q.bank_name = bank.bank_name || '';
                q.bank_account_name = bank.account_name || '';
                q.bank_account_number = bank.account_number || '';
                q.bank_reference = bank.reference || '';
            }
            if (typeMeta?.hasPte) q.pte_fee = String(pteFee || 0);
            return `${base}?${new URLSearchParams(q).toString()}`;
        }
        const params = new URLSearchParams({
            school_enrolment_fee: String(schoolFee || 0),
            english_proficiency_fee: String(englishFee || 0),
            applicant_mode: typeMeta?.applicantMode || 'single',
            currency,
            bank_heading: bank.heading || '',
            bank_name: bank.bank_name || '',
            bank_account_name: bank.account_name || '',
            bank_account_number: bank.account_number || '',
            bank_reference: bank.reference || '',
        });
        return `${base}?${params.toString()}`;
    }, [leadId, type, isConsultancyType, schoolFee, englishFee, pteFee, typeMeta, currency, bank]);

    // Reset the loading flag every time the URL swings — the iframe's
    // onLoad callback will clear it once the new content is painted.
    useEffect(() => {
        if (previewUrl) setPreviewLoading(true);
        setDownloadError('');
    }, [previewUrl]);

    async function downloadPdf() {
        if (!canSubmit || downloading || !previewUrl) return;
        setDownloading(true);
        setDownloadError('');
        try {
            const url = new URL(previewUrl, window.location.origin);
            const body = new URLSearchParams(url.search);
            const csrfCookie = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
            if (isProposalType) {
                pickedProgramIds.forEach((id) => {
                    body.append('program_ids[]', String(id));
                    if (pickedReasons[id]?.trim()) body.append(`reasons[${id}]`, pickedReasons[id].trim());
                });
            }
            const response = await fetch(url.pathname.replace(/\/preview$/, '/download-preview'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfCookie ? decodeURIComponent(csrfCookie.slice('XSRF-TOKEN='.length)) : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body,
            });
            if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) {
                let message = 'Could not download the PDF. Please refresh the page and try again.';
                if (response.headers.get('Content-Type')?.includes('application/json')) {
                    const error = await response.json();
                    message = Object.values(error.errors || {}).flat()[0] || error.message || message;
                }
                throw new Error(message);
            }
            const file = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = file;
            link.download = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || 'proposal-agreement-draft.pdf';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(file), 60000);
        } catch (error) {
            setDownloadError(error.message || 'Could not download the PDF. Please try again.');
        } finally {
            setDownloading(false);
        }
    }

    if (! open) return null;
    const currentTypeLabel = typeMeta?.label || 'Select a document type';
    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-3">
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-[1500px] h-[94vh] flex flex-col overflow-hidden">

                {/* ── HEADER ───────────────────────────────────────────── */}
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                    <div className="flex items-center gap-2.5">
                        <div className="w-9 h-9 rounded-lg bg-gray-900 flex items-center justify-center">
                            <FileSignature size={16} className="text-white" />
                        </div>
                        <div>
                            <h3 className="text-[15px] font-bold text-gray-900 leading-tight">New proposal or agreement</h3>
                            <p className="text-[11px] text-gray-500 mt-0.5">
                                {isProposalType
                                    ? 'Pick up to 5 programs; submit for verification — the client sees them once approved.'
                                    : 'Preview updates as you change lead or type. Generate attaches a PDF to the lead\'s documents.'}
                            </p>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-colors"
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* ── BODY: fixed 380px sidebar + fluid preview ─────── */}
                <div className="flex-1 flex min-h-0">

                    {/* ── LEFT SIDEBAR ─────────────────────────────── */}
                    <div className="w-[380px] border-r border-gray-100 flex flex-col min-h-0 flex-shrink-0 overflow-y-auto">

                        {/* Lead section */}
                        <div className="px-5 py-4 border-b border-gray-100">
                            <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-2">Lead</div>
                            {chosenLead ? (
                                <div className="bg-gray-900 border border-gray-900 rounded-lg px-3 py-2.5 flex items-center justify-between gap-2">
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-bold text-white truncate leading-tight">
                                            {chosenLead.name || `Lead #${leadId}`}
                                        </div>
                                        <div className="text-[11px] text-gray-300 font-mono truncate mt-0.5">
                                            {chosenLead.lead_id}{chosenLead.email ? ` · ${chosenLead.email}` : ''}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => { setLeadId(''); setLeadSearch(''); }}
                                        className="w-6 h-6 rounded-full hover:bg-white/10 flex items-center justify-center flex-shrink-0 transition-colors"
                                        aria-label="Clear lead"
                                    >
                                        <X size={13} className="text-gray-300" />
                                    </button>
                                </div>
                            ) : (
                                <>
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" />
                                        <input
                                            type="text"
                                            value={leadSearch}
                                            onChange={(e) => setLeadSearch(e.target.value)}
                                            placeholder="Search name, email or LP ID…"
                                            className="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400 transition-all"
                                        />
                                    </div>
                                    <div className="mt-2 max-h-52 overflow-y-auto rounded-lg border border-gray-100 divide-y divide-gray-50 bg-white">
                                        {filteredPicker.length === 0 ? (
                                            <p className="text-xs text-gray-400 italic px-3 py-3 text-center">No matching leads.</p>
                                        ) : filteredPicker.map((p) => (
                                            <button
                                                key={p.id}
                                                type="button"
                                                onClick={() => setLeadId(p.id)}
                                                className="w-full flex items-center gap-2 px-3 py-2 text-left text-xs hover:bg-gray-50 transition-colors"
                                            >
                                                <span className="font-semibold text-gray-800 truncate">{p.name}</span>
                                                <span className="ml-auto text-[10px] font-mono text-gray-400 shrink-0">{p.lead_id}</span>
                                            </button>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Category section — chosen before the doc type. Filters
                            which document types show, and sets the currency
                            automatically (Philippines = PhP, Onshore/Offshore = NZ$). */}
                        <div className="px-5 py-4 border-b border-gray-100">
                            <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-2">Category</div>
                            <div className="relative">
                                <select
                                    value={category}
                                    onChange={(e) => { setCategory(e.target.value); setType(''); }}
                                    className="w-full appearance-none pl-3 pr-9 py-2 rounded-lg text-sm bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400 transition-all font-semibold text-gray-900"
                                >
                                    {Object.values(CATEGORIES).map((c) => (
                                        <option key={c.code} value={c.code}>{c.label}</option>
                                    ))}
                                </select>
                                <ChevronDown size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                            </div>
                            <p className="text-[11px] text-gray-500 mt-1.5 leading-relaxed">
                                Sets the available documents and the currency ({cur.short}). Amounts aren&rsquo;t converted.
                            </p>
                        </div>

                        {/* Document type section */}
                        <div className="px-5 py-4 border-b border-gray-100">
                            <div className="flex items-center justify-between mb-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500">Document type</div>
                                {type && (() => {
                                    const cat = documentCategory(type);
                                    return (
                                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider ${cat.chip}`}>
                                            <span className={`w-1.5 h-1.5 rounded-full ${cat.dot}`} />
                                            {cat.label}
                                        </span>
                                    );
                                })()}
                            </div>
                            <div className="relative">
                                <select
                                    value={type}
                                    onChange={(e) => setType(e.target.value)}
                                    className={`w-full appearance-none pl-3 pr-9 py-2 rounded-lg text-sm bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400 transition-all font-semibold ${type ? 'text-gray-900' : 'text-gray-400'}`}
                                >
                                    <option value="" disabled>Select a document type…</option>
                                    {visibleTypes.map((t) => (
                                        <option key={t.value} value={t.value}>{t.label}</option>
                                    ))}
                                </select>
                                <ChevronDown size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                            </div>
                            <p className="text-[11px] text-gray-500 mt-1.5 leading-relaxed">
                                {type
                                    ? (DOC_TYPES.find((t) => t.value === type) || {}).hint
                                    : 'Nothing generates until you pick a type.'}
                            </p>
                        </div>

                        {/* Fees section — consultancy + English engagement */}
                        {(isConsultancyType || isEnglishType) && (
                            <div className="px-5 py-4 border-b border-gray-100">
                                <div className="flex items-center justify-between mb-2">
                                    <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500">Fees · {cur.short}</div>
                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-gray-500">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                        LIVE
                                    </span>
                                </div>
                                {typeMeta?.hasPte ? (
                                    // Offshore - Philippines: an English Review package (Php) plus
                                    // a separate PTE Examination fee (USD), both editable.
                                    <div className="grid grid-cols-2 gap-2">
                                        <FeeInput
                                            label="English Review"
                                            value={schoolFee}
                                            onChange={setSchoolFee}
                                            step={500}
                                            symbol={cur.symbol}
                                        />
                                        <FeeInput
                                            label="PTE Examination Fee"
                                            value={pteFee}
                                            onChange={setPteFee}
                                            step={10}
                                            symbol="US$"
                                        />
                                    </div>
                                ) : (typeMeta?.singleFee || isEnglishType) ? (
                                    // Single package fee (offshore / onshore / offshore-english).
                                    // The "Zero fees" variant defaults this to 0 — staff can still
                                    // type an amount, and $0 keeps the waived wording.
                                    <>
                                        <FeeInput
                                            label="Package fee"
                                            value={schoolFee}
                                            onChange={setSchoolFee}
                                            step={500}
                                            symbol={cur.symbol}
                                        />
                                        {typeMeta?.zeroFees && Number(schoolFee) === 0 && (
                                            <p className="text-[10px] text-gray-500 mt-1.5">Defaults to {cur.symbol}0 (fees waived) — type an amount to charge.</p>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        <div className="grid grid-cols-2 gap-2">
                                            <FeeInput
                                                label="School Enrolment"
                                                value={schoolFee}
                                                onChange={setSchoolFee}
                                                step={1000}
                                                symbol={cur.symbol}
                                            />
                                            <FeeInput
                                                label="English Proficiency"
                                                value={englishFee}
                                                onChange={setEnglishFee}
                                                step={500}
                                                symbol={cur.symbol}
                                            />
                                        </div>
                                        <div className="mt-3 flex items-baseline justify-between border-t border-gray-100 pt-2.5">
                                            <span className="text-[10px] font-bold uppercase tracking-widest text-gray-500">Combined</span>
                                            <span className="text-base font-black text-gray-900 tabular-nums">
                                                {cur.symbol}{(schoolFee + englishFee).toLocaleString(cur.locale)}
                                            </span>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}

                        {/* Bank details section — consultancy + English agreements.
                            A preset fills the fields; every field stays editable.
                            "Other" blanks them for a fully custom bank. */}
                        {(isConsultancyType || isEnglishType) && (
                            <div className="px-5 py-4 border-b border-gray-100">
                                <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-2">Bank details</div>
                                <div className="grid grid-cols-4 gap-2 mb-3">
                                    {Object.entries(BANK_PRESETS).map(([key, p]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => applyBankPreset(key)}
                                            className={`px-2 py-1.5 rounded-lg border text-[11px] font-bold transition-colors ${
                                                bank.preset === key
                                                    ? 'border-gray-900 bg-gray-900 text-white'
                                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'
                                            }`}
                                        >
                                            {p.label}
                                        </button>
                                    ))}
                                </div>
                                <div className="space-y-2">
                                    <BankField label="Heading" value={bank.heading} onChange={(v) => setBankField('heading', v)} placeholder="Payment for…" />
                                    <BankField label="Bank name" value={bank.bank_name} onChange={(v) => setBankField('bank_name', v)} placeholder="e.g. ANZ" />
                                    <BankField label="Account name" value={bank.account_name} onChange={(v) => setBankField('account_name', v)} placeholder="Account holder" />
                                    <BankField label="Account number" value={bank.account_number} onChange={(v) => setBankField('account_number', v)} placeholder="00-0000-0000000-00" />
                                    <BankField label="Reference" value={bank.reference} onChange={(v) => setBankField('reference', v)} placeholder="Defaults to the client's name" prefix="#" />
                                </div>
                                <p className="text-[11px] text-gray-500 mt-2 leading-relaxed">
                                    Pick a preset to fill these, then edit any field. Leave Reference blank to use the client&rsquo;s name.
                                </p>
                            </div>
                        )}

                    </div>

                    {/* ── RIGHT: preview area OR program picker ────── */}
                    <div className="flex-1 flex flex-col min-h-0 bg-gray-50">
                        {/* Top strip — label + selection pill. Swaps between
                            "Preview" (agreements) and "Shortlist" (proposal). */}
                        <div className="px-4 py-2.5 border-b border-gray-100 bg-white flex items-center gap-3 flex-wrap flex-shrink-0">
                            <span className="text-[11px] font-bold uppercase tracking-[0.14em] text-gray-500">
                                {isProposalType ? 'Shortlist' : 'Preview'}
                            </span>
                            <div className="flex items-center gap-1.5 flex-wrap">
                                {type ? (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-900 text-white text-[11px] font-semibold">
                                        {isProposalType ? <Lightbulb size={11} /> : <FileText size={11} />}
                                        {isProposalType
                                            ? `${pickedProgramIds.length} / ${MAX_PROPOSED_PROGRAMS} programs`
                                            : currentTypeLabel}
                                    </span>
                                ) : (
                                    <span className="text-[11px] text-gray-400 italic">nothing selected yet</span>
                                )}
                            </div>
                            <button type="button" onClick={downloadPdf}
                                disabled={!canSubmit || downloading || amountSettling}
                                title="Download a draft of the current selection. This does not send an email."
                                className="ml-auto inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">
                                {downloading ? <Loader size={14} className="animate-spin" /> : <Download size={14} />}
                                {downloading ? 'Preparing PDF…' : 'Download PDF'}
                            </button>
                        </div>
                        {downloadError && <p role="alert" className="px-4 py-2 text-xs text-red-700 bg-red-50">{downloadError}</p>}

                        {/* Body — proposal shows the full-width picker so
                            staff has real room to browse; agreements show
                            the iframe preview. */}
                        <div className="flex-1 relative min-h-0">
                            {isProposalType ? (
                                <div className="absolute inset-0 bg-white flex flex-col min-h-0">
                                    <ProgramPicker
                                        allPrograms={programs}
                                        programs={filteredPrograms}
                                        search={programSearch}
                                        setSearch={setProgramSearch}
                                        pickedIds={pickedProgramIds}
                                        togglePicked={togglePickedProgram}
                                        max={MAX_PROPOSED_PROGRAMS}
                                        reasons={pickedReasons}
                                        setReason={setReason}
                                    />
                                </div>
                            ) : previewUrl ? (
                                <>
                                    <iframe
                                        key={previewUrl}
                                        src={previewUrl}
                                        title="Document preview"
                                        sandbox="allow-same-origin"
                                        onLoad={() => setPreviewLoading(false)}
                                        className="absolute inset-0 w-full h-full bg-white"
                                    />
                                    {previewLoading && (
                                        <div className="absolute inset-0 flex flex-col items-center justify-center bg-white/85 backdrop-blur-sm z-10">
                                            <div className="relative">
                                                <div className="w-11 h-11 rounded-full border-2 border-gray-200" />
                                                <div className="absolute inset-0 w-11 h-11 rounded-full border-2 border-transparent border-t-gray-900 border-r-gray-900 animate-spin" />
                                                <FileText size={16} className="absolute inset-0 m-auto text-gray-900" />
                                            </div>
                                            <p className="mt-3 text-[11px] font-bold uppercase tracking-[0.22em] text-gray-900">Rendering preview</p>
                                            <p className="mt-0.5 text-[10px] text-gray-500 italic">a few seconds…</p>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <div className="absolute inset-0 flex flex-col items-center justify-center text-center px-8">
                                    <div className="w-14 h-14 rounded-2xl bg-white shadow-sm border border-gray-100 flex items-center justify-center mb-3">
                                        <FileText size={22} className="text-gray-400" />
                                    </div>
                                    <p className="text-sm font-bold text-gray-800">Preview will appear here</p>
                                    <p className="text-xs text-gray-500 mt-1">Pick a lead + document type in the left panel.</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* ── FOOTER ───────────────────────────────────────── */}
                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-4 flex-shrink-0 bg-white">
                    {/* Email opt-in — sits on the left of the footer.
                        Disabled when the chosen lead has no email since
                        the notify endpoint would fail. */}
                    {(isConsultancyType || isEnglishType) ? (
                        // Consultancy AND English agreements go through verification
                        // first — the client is emailed only once a reviewer approves
                        // it, so there's no "email now" opt-in here.
                        <span className="flex items-center gap-2 text-xs text-gray-500">
                            <ShieldCheck size={13} className="text-emerald-600" />
                            Submits for verification — the client is emailed once it's approved.
                        </span>
                    ) : (
                        <label className={`flex items-center gap-2 text-xs ${canNotify ? 'text-gray-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed'}`}>
                            <button
                                type="button"
                                onClick={() => canNotify && setNotify((v) => ! v)}
                                disabled={! canNotify}
                                className={`w-4 h-4 rounded border flex items-center justify-center transition-colors flex-shrink-0 ${
                                    notify && canNotify
                                        ? 'bg-gray-900 border-gray-900'
                                        : 'bg-white border-gray-300'
                                } ${canNotify ? '' : 'opacity-60'}`}
                                aria-checked={notify && canNotify}
                                role="checkbox"
                            >
                                {notify && canNotify && <Check size={11} className="text-white" strokeWidth={3} />}
                            </button>
                            <Mail size={13} className={canNotify ? 'text-gray-500' : 'text-gray-300'} />
                            <span>
                                Email the client that their documents are available in the application tracker
                                {chosenLead?.email && <span className="text-gray-400"> · {chosenLead.email}</span>}
                                {chosenLead && ! chosenLead.email && <span className="text-gray-400 italic"> · no email on file</span>}
                            </span>
                        </label>
                    )}

                    <div className="flex items-center gap-3 flex-shrink-0">
                        <span className="text-[11px] text-gray-500">
                            {isProposalType
                                ? `${pickedProgramIds.length} program${pickedProgramIds.length === 1 ? '' : 's'} selected`
                                : type
                                    ? '1 document selected'
                                    : 'no document selected'}
                        </span>
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900 transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={! canSubmit || submitting || amountSettling}
                            onClick={() => (needsAmountConfirm ? setConfirmOpen(true) : submit())}
                            title={amountSettling ? 'Rendering the amount — hold on…' : ''}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-lg text-sm font-bold hover:bg-black disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                        >
                            {submitting ? <Loader size={14} className="animate-spin" />
                                : amountSettling ? <Loader size={14} className="animate-spin" />
                                : <Plus size={14} />}
                            {amountSettling ? 'Rendering…'
                                : (isProposalType || isConsultancyType || isEnglishType) ? 'Submit for verification' : 'Generate'}
                        </button>
                    </div>
                </div>
            </div>

            {/* Double-confirm before submitting an amount-bearing agreement. */}
            {confirmOpen && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4" onClick={(e) => { e.stopPropagation(); setConfirmOpen(false); }}>
                    <div className="w-full max-w-sm bg-white rounded-2xl shadow-2xl p-5" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center gap-2 mb-3">
                            <ShieldCheck size={18} className="text-emerald-600" />
                            <h3 className="text-base font-bold text-gray-900">Confirm before submitting</h3>
                        </div>
                        <p className="text-[12px] text-gray-500 mb-3">Please double-check these details — this goes to verification.</p>
                        <div className="rounded-xl border border-gray-200 divide-y divide-gray-100 text-sm">
                            <div className="flex items-start justify-between gap-3 px-3 py-2">
                                <span className="text-gray-500">Client</span>
                                <span className="font-semibold text-gray-900 text-right">{chosenLead?.name || `Lead #${leadId}`}</span>
                            </div>
                            <div className="flex items-start justify-between gap-3 px-3 py-2">
                                <span className="text-gray-500">Document</span>
                                <span className="font-semibold text-gray-900 text-right">{typeMeta?.label || currentTypeLabel}{typeMeta?.applicantMode ? ` · ${typeMeta.applicantMode}` : ''}</span>
                            </div>
                            <div className="flex items-start justify-between gap-3 px-3 py-2">
                                <span className="text-gray-500">Amount</span>
                                <span className="font-bold text-gray-900 text-right">{amountText}</span>
                            </div>
                        </div>
                        <div className="flex items-center justify-end gap-2 mt-4">
                            <button type="button" onClick={() => setConfirmOpen(false)} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                            <button
                                type="button"
                                disabled={submitting}
                                onClick={() => { setConfirmOpen(false); submit(); }}
                                className="inline-flex items-center gap-2 px-4 py-2 bg-[#14532d] text-white rounded-lg text-sm font-bold hover:bg-[#0f3d21] disabled:opacity-60"
                            >
                                <Check size={14} /> Confirm &amp; submit
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
