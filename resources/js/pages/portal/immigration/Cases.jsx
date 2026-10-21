import React, { useState, useRef, useEffect, useMemo } from "react";
import { createPortal } from "react-dom";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { toast } from "sonner";
import {
    Globe, ChevronRight, ChevronDown, AlertTriangle, Search,
    FileText, ExternalLink, Users, Calendar, ArrowUpDown,
    ArrowUp, ArrowDown, Plus, X, TrendingUp, Copy, MoreHorizontal,
    Archive, Pencil, Mail, Phone, Paperclip, KeyRound,
    ArrowLeftRight, UserPlus, Loader2, Check, Eye, Link2,
    Sparkles, Trash2,
} from "lucide-react";
import { AvatarPhoto } from "@/components/ui/Avatar";
import CaseFilesModal from "@/components/immigration/CaseFilesModal";
import { priorityRing, priorityRank } from "@/utils/priority";
import { immBase, leadBase, isAgentCasePortal } from "@/lib/caseRoutes";

// This board is shared by the manager (/portal/immigration), the adviser
// (/portal/immigration-adviser), and a First-Immigration recruiting agent
// (/portal/agent, their own referrals). Route the case-profile link to the
// current portal so opening a case keeps its own sidebar chrome. immBase()
// resolves /portal/immigration vs /portal/agent from the URL; the adviser
// portal is handled explicitly.
const caseProfileHref = (id, tab) => {
    const onAdviser = typeof window !== "undefined" && window.location.pathname.startsWith("/portal/immigration-adviser");
    const base = onAdviser
        ? `/portal/immigration-adviser/cases/${id}`
        : `${immBase()}/cases/${id}/profile`;
    return tab ? `${base}?tab=${tab}` : base;
};

/**
 * Distribution palette — colour per immigration stage. Kept distinct from
 * the global app palette so the bar chart reads as its own object. Order
 * matches Lead::IMMIGRATION_STAGES + the trailing "Unassigned" bucket.
 */
const STAGE_COLORS = {
    'Endorsed':                'bg-sky-500',
    'Agreement Sent':          'bg-purple-500',
    'Agreement Signed':        'bg-teal-500',
    'For Agreement & Invoice':             'bg-orange-500',
    'Invoice Paid':            'bg-lime-500',
    'Request to Lodge':       'bg-cyan-500',
    'Visa Lodged':             'bg-indigo-500',
    'Interim Visa Issued':     'bg-blue-500',
    'Request for Medical': 'bg-amber-500',
    'Request for Information': 'bg-amber-500',
    'RFI Responded':           'bg-indigo-500',
    'Approved in Principle':   'bg-violet-500',
    'Approved Visa':           'bg-emerald-500',
    'Decline Visa':            'bg-rose-500',
    'Unassigned':              'bg-gray-300',
};

// Hex tones for the line graph — picked to match the Education page's
// equivalent palette so an admin looking at both pages gets the same
// visual story (sky → amber → indigo → emerald → rose).
const STAGE_HEX = {
    'Endorsed':                '#0ea5e9',
    'Agreement Sent':          '#a855f7',
    'Agreement Signed':        '#14b8a6',
    'For Agreement & Invoice':             '#f97316',
    'Invoice Paid':            '#84cc16',
    'Request to Lodge':       '#06b6d4',
    'Visa Lodged':             '#6366f1',
    'Interim Visa Issued':     '#3b82f6',
    'Request for Medical': '#f59e0b',
    'Request for Information': '#f59e0b',
    'RFI Responded':           '#6366f1',
    'Approved in Principle':   '#8b5cf6',
    'Approved Visa':           '#22c55e',
    'Decline Visa':            '#ef4444',
    'Withdrawn':               '#78716c',
    'Unassigned':              '#9ca3af',
};
const stageHex = (s) => STAGE_HEX[s] || '#9ca3af';

const STAGE_CHIP = {
    'Endorsed':                'bg-sky-50 text-sky-700 border-sky-200',
    'Agreement Sent':          'bg-purple-50 text-purple-700 border-purple-200',
    'Agreement Signed':        'bg-teal-50 text-teal-700 border-teal-200',
    'For Agreement & Invoice':             'bg-orange-50 text-orange-700 border-orange-200',
    'Invoice Paid':            'bg-lime-50 text-lime-700 border-lime-200',
    'Request to Lodge':       'bg-cyan-50 text-cyan-700 border-cyan-200',
    'Visa Lodged':             'bg-indigo-50 text-indigo-700 border-indigo-200',
    'Interim Visa Issued':     'bg-blue-50 text-blue-700 border-blue-200',
    'Request for Medical': 'bg-amber-50 text-amber-700 border-amber-200',
    'Request for Information': 'bg-amber-50 text-amber-700 border-amber-200',
    'RFI Responded':           'bg-indigo-50 text-indigo-700 border-indigo-200',
    'Approved in Principle':   'bg-violet-50 text-violet-700 border-violet-200',
    'Approved Visa':           'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Decline Visa':            'bg-rose-50 text-rose-700 border-rose-200',
    'Withdrawn':               'bg-stone-100 text-stone-600 border-stone-200',
};

const stageChipClass = (stage) =>
    STAGE_CHIP[stage] || 'bg-gray-100 text-gray-500 border-gray-200 border-dashed';

/**
 * The Cases list is split into four tabs that follow the case through the
 * pipeline. Between them they cover every immigration stage, so a case
 * always lives in exactly one tab — nothing is hidden.
 */
const CASE_TABS = [
    { key: 'applications', label: 'Assessment',   hint: 'For Assessment',     stages: ['For Assessment'] },
    { key: 'advisers',     label: 'Advisers',     hint: 'Endorsed → RFI',     stages: ['Endorsed', 'Agreement Sent', 'Agreement Signed', 'For Agreement & Invoice', 'Request to Lodge', 'Request for Medical', 'Request for Information'] },
    { key: 'invoice',      label: 'Invoice',      hint: 'Invoice paid',       stages: ['Invoice Paid'] },
    { key: 'lodged',       label: 'Lodged',       hint: 'Submitted to INZ',   stages: ['Visa Lodged', 'Interim Visa Issued', 'RFI Responded'] },
    { key: 'visa',         label: 'Visa',         hint: 'Outcome',            stages: ['Approved in Principle', 'Approved Visa', 'Decline Visa'] },
    { key: 'withdrawn',    label: 'Withdrawn',    hint: 'Applicant withdrew', stages: ['Withdrawn'] },
];

// Leftmost "All" tab — shows every case regardless of stage. Kept out of
// CASE_TABS so it doesn't act as a stage bucket in tabKeyForStage().
const ALL_TAB = { key: 'all', label: 'All Assessments', hint: 'Every case' };
const RENDER_TABS = [ALL_TAB, ...CASE_TABS];

// Which tab a stage belongs to. Cases with no stage yet fall into
// Applications — that's where the journey starts.
// Fields a case is searchable by — name/contacts, visa + INZ reference, stage,
// and identity (passport number/expiry).
const caseMatchesQuery = (c, q) =>
    [
        c.name, c.lead_id, c.email, c.phone, c.country,
        c.inz_visa_type, c.inz_reference, c.inz_status, c.immigration_stage,
        c.passport_number, c.passport_expiry,
        c.inz_client_number, c.inz_application_number, c.inz_medical_ref, c.nzer_number,
    ].some((v) => (v || '').toString().toLowerCase().includes(q));

const tabKeyForStage = (stage) => {
    if (! stage) return 'applications';
    const hit = CASE_TABS.find((t) => t.stages.includes(stage));
    return hit ? hit.key : 'applications';
};

export default function ImmigrationCases({ cases = [], distribution = [], priorities = {}, stages = [], visaTypes = [], agents = [], me_id = null, staff = [], total: totalCount = null, loaded = null, readOnly = false }) {
    // True queue size vs. how many the server actually loaded. When these
    // differ, the safety ceiling truncated the list — warn loudly rather than
    // silently hiding cases (the "For Assessment cases vanished" bug).
    // (Aliased to totalCount — the component already has a local `total` that
    // sums the distribution graph.)
    const trueTotal = totalCount ?? cases.length;
    const truncated = loaded != null && totalCount != null && loaded < totalCount;
    const [search, setSearch] = useState("");
    const [stageFilter, setStageFilter] = useState(null); // click a bar/legend → filter
    const [custodyFilter, setCustodyFilter] = useState('all'); // all | mine | unassigned
    const [sortKey, setSortKey] = useState("updated_at");
    const [sortDir, setSortDir] = useState("desc");
    const [openStageMenuId, setOpenStageMenuId] = useState(null);
    const [expandedId, setExpandedId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [editingCase, setEditingCase] = useState(null); // case row being edited
    const [filesCase, setFilesCase] = useState(null);     // case whose files are open
    const [handoffCase, setHandoffCase] = useState(null);  // case being handed off / claimed
    const [tab, setTab] = useState('all');

    // "In my queue" = I own it OR someone put an answer-requiring question to me
    // on it (Build 12 phase 6). The latter lands the case in my queue even when
    // I don't own it.
    const inMyQueue = (c) => (me_id && c.owner?.id === me_id) || (c.awaiting_my_answer > 0);

    // Custody counts for the My-queue / Unassigned filter chips.
    const custodyCounts = useMemo(() => ({
        mine: me_id ? cases.filter(inMyQueue).length : 0,
        unassigned: cases.filter((c) => !c.owner).length,
    }), [cases, me_id]);

    const tabCounts = useMemo(() => {
        const counts = { all: cases.length };
        for (const t of CASE_TABS) counts[t.key] = 0;
        for (const c of cases) counts[tabKeyForStage(c.immigration_stage)] += 1;
        return counts;
    }, [cases]);

    // Filtered + sorted slice the table actually renders.
    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return cases.filter((c) => {
            // The "All" tab shows every stage; the others bucket by stage.
            if (tab !== 'all' && tabKeyForStage(c.immigration_stage) !== tab) return false;
            if (custodyFilter === 'mine' && ! inMyQueue(c)) return false;
            if (custodyFilter === 'unassigned' && c.owner) return false;
            if (stageFilter) {
                const s = c.immigration_stage || 'Unassigned';
                if (s !== stageFilter) return false;
            }
            if (!q) return true;
            return caseMatchesQuery(c, q);
        });
    }, [cases, search, stageFilter, tab, custodyFilter, me_id]);

    // Searching auto-navigates to the tab that holds the match, so staff don't
    // have to click through each tab. Only jumps when the current tab shows no
    // match (respects a tab you're already on if it contains a result).
    useEffect(() => {
        const q = search.trim().toLowerCase();
        if (!q) return;
        const inCurrentTab = (c) => tab === 'all' || tabKeyForStage(c.immigration_stage) === tab;
        if (cases.some((c) => caseMatchesQuery(c, q) && inCurrentTab(c))) return;
        const first = cases.find((c) => caseMatchesQuery(c, q));
        if (first) setTab(tabKeyForStage(first.immigration_stage));
    }, [search, cases]); // eslint-disable-line react-hooks/exhaustive-deps

    const sorted = useMemo(() => {
        const arr = [...filtered];
        arr.sort((a, b) => {
            // In the Advisers tab, "Request to Lodge" cases are pinned to the
            // very top — that's the queue the adviser acts on next.
            if (tab === 'advisers') {
                const ra = a.immigration_stage === 'Request to Lodge' ? 0 : 1;
                const rb = b.immigration_stage === 'Request to Lodge' ? 0 : 1;
                if (ra !== rb) return ra - rb;
            }

            // Priority always leads: urgent → medium → low → no-priority,
            // regardless of the active column sort.
            const pr = priorityRank(a.immigration_priority) - priorityRank(b.immigration_priority);
            if (pr !== 0) return pr;

            const pull = (row) => {
                if (sortKey === 'name') return (row.name || '').toLowerCase();
                if (sortKey === 'stage') return row.immigration_stage || 'zzzz';
                if (sortKey === 'visa') return row.inz_visa_type || '';
                if (sortKey === 'country') return row.country || '';
                if (sortKey === 'lodged_at') return row.inz_lodged_at || '';
                return row.updated_at || '';
            };
            const av = pull(a), bv = pull(b);
            if (av < bv) return sortDir === 'asc' ? -1 : 1;
            if (av > bv) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });
        return arr;
    }, [filtered, sortKey, sortDir, tab]);

    const toggleSort = (key) => {
        if (sortKey === key) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortDir('desc');
        }
    };

    const total = distribution.reduce((sum, d) => sum + (d.count || 0), 0);

    return (
        <div className="space-y-5 max-w-[1400px] mx-auto pb-12">
            <Head title="Cases — Immigration" />

            {/* Overview: stage graph fills the left, priority cards sit in a
                2×3 grid on the right (matches the requested layout). */}
            <div className="flex flex-col lg:flex-row gap-4 items-stretch">
                <div className="lg:flex-1 min-w-0">
                    <DistributionGraph
                        distribution={distribution}
                        total={total}
                        activeStage={stageFilter}
                        onPick={(stage) => setStageFilter(stageFilter === stage ? null : stage)}
                    />
                </div>
                <div className="lg:w-[340px] flex-shrink-0">
                    <PriorityBreakdown priorities={priorities} />
                </div>
            </div>

            {/* Search + Add-new share one row, outside the table. Search runs
                across the active tab (use the "All Assessments" tab to search
                every case by name). */}
            <div className="flex items-center gap-3">
                <div className="relative flex-1 max-w-md">
                    <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search by name, lead ID, INZ reference, visa type…"
                        className="w-full pl-9 pr-3 py-2.5 bg-white border border-gray-200 rounded-xl text-sm text-gray-800 shadow-sm focus:outline-none focus:border-gray-400 transition-colors"
                    />
                </div>
                {! readOnly && ! isAgentCasePortal() && (
                    <button
                        type="button"
                        onClick={() => setCreating(true)}
                        className="px-4 py-2.5 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black transition-colors flex items-center gap-2 flex-shrink-0"
                    >
                        <Plus size={14} strokeWidth={2.5} /> Add new case
                    </button>
                )}
            </div>

            {/* Toolbar */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                {/* Tabs — follow the case through the stage pipeline */}
                <div className="flex px-4 sm:px-5 border-b border-gray-100 overflow-x-auto">
                    {RENDER_TABS.map((t) => {
                        const active = tab === t.key;
                        return (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => { setTab(t.key); setStageFilter(null); }}
                                className={`inline-flex items-center gap-2 px-4 py-3 text-[13px] font-semibold whitespace-nowrap transition-colors border-b-2 -mb-px ${
                                    active
                                        ? 'text-gray-900 border-gray-900'
                                        : 'text-gray-500 border-transparent hover:text-gray-800'
                                }`}
                                aria-current={active ? 'page' : undefined}
                            >
                                <span>{t.label}</span>
                                {t.hint && (
                                    <span className="text-[10.5px] font-medium text-gray-400 hidden lg:inline">({t.hint})</span>
                                )}
                                <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${
                                    active ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600'
                                }`}>
                                    {tabCounts[t.key] ?? 0}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="px-4 sm:px-5 py-2.5 flex items-center gap-3 border-b border-gray-100">
                    {stageFilter && (
                        <button
                            type="button"
                            onClick={() => setStageFilter(null)}
                            className="text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-900 px-2 py-1 flex items-center gap-1"
                        >
                            Clear filter: {stageFilter}
                            <span className="text-gray-300">×</span>
                        </button>
                    )}

                    {/* Custody filter — My queue / Unassigned are first-class
                        triage views. Unassigned is where dropped cases surface. */}
                    <div className="flex items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 p-0.5">
                        {[
                            { key: 'all', label: 'All' },
                            { key: 'mine', label: 'My queue', count: custodyCounts.mine },
                            { key: 'unassigned', label: 'Unassigned', count: custodyCounts.unassigned },
                        ].map((f) => (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => setCustodyFilter(f.key)}
                                className={`px-2.5 py-1 rounded-md text-[11px] font-semibold transition-colors flex items-center gap-1.5 ${
                                    custodyFilter === f.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'
                                }`}
                            >
                                {f.label}
                                {f.count !== undefined && (
                                    <span className={`text-[10px] tabular-nums ${custodyFilter === f.key ? 'text-gray-400' : 'text-gray-400'}`}>{f.count}</span>
                                )}
                            </button>
                        ))}
                    </div>

                    <div className="text-[11px] font-semibold text-gray-500 ml-auto">
                        Showing <span className="text-gray-900">{sorted.length}</span> of {trueTotal} cases
                    </div>
                </div>

                {truncated && (
                    <div className="mx-4 sm:mx-5 mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-800">
                        <AlertTriangle size={15} className="flex-shrink-0 mt-0.5" />
                        <span>
                            <span className="font-semibold">{trueTotal - loaded} case{trueTotal - loaded === 1 ? "" : "s"} not loaded.</span>{" "}
                            This view is capped and older, less-active cases (often those still in
                            <span className="font-semibold"> For Assessment</span>) aren't shown here — search can't reach them either.
                            Ask an admin to enable server-side paging.
                        </span>
                    </div>
                )}

                {/* Table */}
                <div className="overflow-x-auto overflow-y-visible">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="bg-gray-50/60 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                <th className="pl-4 pr-2 py-3 w-6" />
                                <SortableTh label="Case"        sortKey="name"      current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Stage"       sortKey="stage"     current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Visa"        sortKey="visa"      current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Country"     sortKey="country"   current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <th className="px-3 py-3">Docs</th>
                                <th className="px-3 py-3">Refer</th>
                                <SortableTh label="Updated" sortKey="updated_at" current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <th className="px-3 py-3 text-right pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {sorted.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-6 py-20 text-center">
                                        <div className="flex flex-col items-center gap-2 text-gray-400">
                                            <Globe size={22} />
                                            <p className="text-sm font-medium">
                                                {cases.length === 0 ? "No active cases yet." : "No cases match your filters."}
                                            </p>
                                            {cases.length === 0 && (
                                                <p className="text-[11px]">Leads in Visa Process or Consultancy Agreement appear here.</p>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : sorted.map((c) => (
                                <CaseRow
                                    key={c.id}
                                    c={c}
                                    meId={me_id}
                                    stages={stages}
                                    visaTypes={visaTypes}
                                    readOnly={readOnly}
                                    isExpanded={expandedId === c.id}
                                    onExpand={() => setExpandedId(expandedId === c.id ? null : c.id)}
                                    stageMenuOpen={openStageMenuId === c.id}
                                    onStageMenuToggle={() => setOpenStageMenuId(openStageMenuId === c.id ? null : c.id)}
                                    onStageMenuClose={() => setOpenStageMenuId(null)}
                                    onEdit={() => setEditingCase(c)}
                                    onViewFiles={() => setFilesCase(c)}
                                    onHandoff={() => setHandoffCase(c)}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {(creating || editingCase) && (
                <CreateCaseModal
                    key={editingCase?.id || "new"}
                    editing={editingCase}
                    stages={stages}
                    visaTypes={visaTypes}
                    agents={agents}
                    onClose={() => { setCreating(false); setEditingCase(null); }}
                />
            )}

            {filesCase && (
                <CaseFilesModal
                    leadId={filesCase.id}
                    leadName={filesCase.name}
                    onClose={() => setFilesCase(null)}
                />
            )}

            {handoffCase && (
                <HandoffModal
                    c={handoffCase}
                    meId={me_id}
                    staff={staff}
                    onClose={() => setHandoffCase(null)}
                />
            )}
        </div>
    );
}

// ─── Create case modal ─────────────────────────────────────────────────

function CreateCaseModal({ stages, visaTypes, agents = [], editing = null, onClose }) {
    const isEdit = !!editing;
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        first_name: editing?.first_name || '',
        middle_name: editing?.middle_name || '',
        last_name: editing?.last_name || '',
        suffix: editing?.suffix || '',
        gender: editing?.gender || '',
        email: editing?.email || '',
        phone: editing?.phone || '',
        immigration_stage: editing?.immigration_stage || stages[0] || '',
        immigration_priority: editing?.immigration_priority || '',
        internal_note: '',
        payment: editing?.payment || '',
        agent_id: editing?.agent_id || '',
        // The case row stores the visa *name* (inz_visa_type); map it back to
        // the matching option id so the dropdown pre-selects on edit.
        visa_type_id: editing
            ? (visaTypes.find((v) => v.name === editing.inz_visa_type)?.id ?? '')
            : '',
    });

    const [tab, setTab] = useState("personal"); // personal | documents (edit only)

    const submit = (e) => {
        e?.preventDefault();
        clearErrors();
        const url = isEdit ? `${immBase()}/cases/${editing.id}` : `${immBase()}/cases`;
        post(url, {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    const fullName = `${data.first_name} ${data.last_name}`.trim() || editing?.name || "New case";

    return (
        <div
            className="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm flex items-center justify-center p-4"
            onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden">
                {/* Title bar */}
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between shrink-0">
                    <div>
                        <h2 className="text-lg font-bold text-gray-900">{isEdit ? 'Edit case' : 'New case'}</h2>
                        <p className="text-xs text-gray-500 mt-0.5">
                            {isEdit ? "Update this case's details." : 'Adds a new immigration case directly to the queue.'}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="p-1 text-gray-400 hover:text-gray-700">
                        <X size={18} />
                    </button>
                </div>

                {/* Profile header — name · contacts · status */}
                <div className="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60 flex items-center justify-between gap-4 flex-wrap shrink-0">
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-gray-900 truncate">{fullName}</p>
                        <p className="text-[11px] text-gray-500 mt-0.5 flex items-center gap-3 flex-wrap">
                            {data.email && <span className="inline-flex items-center gap-1"><Mail size={11} className="text-gray-400" />{data.email}</span>}
                            {data.phone && <span className="inline-flex items-center gap-1"><Phone size={11} className="text-gray-400" />{data.phone}</span>}
                        </p>
                    </div>
                    {data.immigration_stage && (
                        <span className={`inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border ${stageChipClass(data.immigration_stage)}`}>
                            {data.immigration_stage}
                        </span>
                    )}
                </div>

                {/* Tabs — Documents only exists for a saved case */}
                {isEdit && (
                    <div className="px-6 border-b border-gray-100 flex items-center gap-1 shrink-0">
                        {[
                            { k: 'personal', label: 'Personal info' },
                            { k: 'documents', label: 'Documents' },
                        ].map((t) => (
                            <button key={t.k} type="button" onClick={() => setTab(t.k)}
                                className={`px-3 py-3 text-xs font-bold -mb-px border-b-2 transition-colors ${
                                    tab === t.k ? 'text-gray-900 border-gray-900' : 'text-gray-400 border-transparent hover:text-gray-700'
                                }`}>
                                {t.label}
                            </button>
                        ))}
                    </div>
                )}

                {/* Scrollable content */}
                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {(!isEdit || tab === 'personal') && (
                    <form id="caseModalForm" onSubmit={submit} className="space-y-4">
                    {/* Name row */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <CaseField label="First name" required error={errors.first_name}>
                            <input
                                type="text"
                                value={data.first_name}
                                onChange={(e) => setData('first_name', e.target.value)}
                                maxLength={80}
                                autoFocus
                                className={caseInput(errors.first_name)}
                            />
                        </CaseField>
                        <CaseField label="Middle name" error={errors.middle_name}>
                            <input
                                type="text"
                                value={data.middle_name}
                                onChange={(e) => setData('middle_name', e.target.value)}
                                maxLength={80}
                                className={caseInput(errors.middle_name)}
                            />
                        </CaseField>
                        <CaseField label="Last name" required error={errors.last_name}>
                            <input
                                type="text"
                                value={data.last_name}
                                onChange={(e) => setData('last_name', e.target.value)}
                                maxLength={80}
                                className={caseInput(errors.last_name)}
                            />
                        </CaseField>
                        <CaseField label="Suffix" hint="Optional (Jr., Sr., III…)" error={errors.suffix}>
                            <input
                                type="text"
                                value={data.suffix}
                                onChange={(e) => setData('suffix', e.target.value)}
                                maxLength={20}
                                className={caseInput(errors.suffix)}
                            />
                        </CaseField>
                    </div>

                    <CaseField label="Gender" hint="Optional" error={errors.gender}>
                        <select
                            value={data.gender}
                            onChange={(e) => setData('gender', e.target.value)}
                            className={caseInput(errors.gender)}
                        >
                            <option value="">—</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                            <option value="Prefer not to say">Prefer not to say</option>
                        </select>
                    </CaseField>

                    {/* Contact row */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <CaseField label="Email" error={errors.email}>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                maxLength={120}
                                className={caseInput(errors.email)}
                            />
                        </CaseField>
                        <CaseField label="Contact number" error={errors.phone}>
                            <input
                                type="tel"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                maxLength={40}
                                className={caseInput(errors.phone)}
                            />
                        </CaseField>
                    </div>

                    {/* Stage + visa */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <CaseField label="Stage" required error={errors.immigration_stage}>
                            <select
                                value={data.immigration_stage}
                                onChange={(e) => setData('immigration_stage', e.target.value)}
                                className={caseInput(errors.immigration_stage)}
                            >
                                {stages.map((s) => (
                                    <option key={s} value={s}>{s}</option>
                                ))}
                            </select>
                        </CaseField>
                        <CaseField label="Visa type" error={errors.visa_type_id}>
                            <select
                                value={data.visa_type_id}
                                onChange={(e) => setData('visa_type_id', e.target.value)}
                                className={caseInput(errors.visa_type_id)}
                            >
                                <option value="">— pick a visa type —</option>
                                {visaTypes.map((v) => (
                                    <option key={v.id} value={v.id}>{v.name}</option>
                                ))}
                            </select>
                        </CaseField>
                    </div>

                    {/* Priority + payment */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <CaseField label="Priority" hint="Sets the colour of the case avatar" error={errors.immigration_priority}>
                            <select
                                value={data.immigration_priority}
                                onChange={(e) => setData('immigration_priority', e.target.value)}
                                className={caseInput(errors.immigration_priority)}
                            >
                                <option value="">— none —</option>
                                <option value="urgent">🔴 Urgent — needs action today</option>
                                <option value="high">🟠 High — action this week</option>
                                <option value="medium">🟡 Medium — scheduled / on track</option>
                                <option value="low">🟢 Low — no rush</option>
                                <option value="done">✅ Done — completed</option>
                            </select>
                        </CaseField>
                        <CaseField label="Payment" hint="Optional — free-form (e.g. amount, reference, status)" error={errors.payment}>
                            <input
                                type="text"
                                value={data.payment}
                                onChange={(e) => setData('payment', e.target.value)}
                                maxLength={120}
                                placeholder="e.g. $500 deposit · ref 12345"
                                className={caseInput(errors.payment)}
                            />
                        </CaseField>
                    </div>

                    <CaseField label="Referring agent" hint="The recruiter credited with this case" error={errors.agent_id}>
                        <select
                            value={data.agent_id}
                            onChange={(e) => setData('agent_id', e.target.value)}
                            className={caseInput(errors.agent_id)}
                        >
                            <option value="">— none —</option>
                            {agents.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}{a.referral_code ? ` (${a.referral_code})` : ''}{a.role === 'sub_agent' ? ' · sub-agent' : ''}
                                </option>
                            ))}
                        </select>
                    </CaseField>

                    <CaseField label="Internal note" hint="Optional — only staff see this" error={errors.internal_note}>
                        <textarea
                            value={data.internal_note}
                            onChange={(e) => setData('internal_note', e.target.value)}
                            maxLength={5000}
                            rows={3}
                            placeholder="Anything the next adviser opening this case should know."
                            className={caseInput(errors.internal_note)}
                        />
                    </CaseField>

                    </form>
                    )}

                    {isEdit && tab === 'documents' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <DocStat label="Total"    value={editing?.docs_total ?? 0} />
                                <DocStat label="Approved" value={editing?.docs_approved ?? 0} tone="emerald" />
                                <DocStat label="Pending"  value={editing?.docs_pending ?? 0} tone="amber" />
                                <DocStat label="Rejected" value={editing?.docs_rejected ?? 0} tone="rose" />
                            </div>
                            <Link
                                href={caseProfileHref(editing?.id, "documents")}
                                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-xs font-bold hover:bg-black transition-colors"
                            >
                                <FileText size={13} /> Manage documents
                            </Link>
                            <p className="text-[11px] text-gray-400">
                                Upload, review and organise this case's documents in the full documents workspace.
                            </p>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-3 shrink-0">
                    <button type="button" onClick={onClose} disabled={processing}
                        className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900 transition-colors">
                        Cancel
                    </button>
                    <button type="button" onClick={submit} disabled={processing}
                        className="inline-flex items-center gap-2 px-5 py-2 bg-gray-900 text-white text-sm font-bold rounded-xl hover:bg-black transition-colors disabled:opacity-50">
                        {isEdit ? <Pencil size={14} strokeWidth={2.5} /> : <Plus size={14} strokeWidth={2.5} />}
                        {processing ? 'Saving…' : (isEdit ? 'Save changes' : 'Create case')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function DocStat({ label, value, tone = "gray" }) {
    const tones = {
        gray:    "bg-gray-50 text-gray-700 border-gray-100",
        emerald: "bg-emerald-50 text-emerald-700 border-emerald-100",
        amber:   "bg-amber-50 text-amber-700 border-amber-100",
        rose:    "bg-rose-50 text-rose-700 border-rose-100",
    };
    return (
        <div className={`rounded-xl border px-3 py-3 text-center ${tones[tone]}`}>
            <div className="text-xl font-bold tabular-nums">{value}</div>
            <div className="text-[10px] font-bold uppercase tracking-wider opacity-70 mt-0.5">{label}</div>
        </div>
    );
}

function CaseField({ label, required, hint, error, children }) {
    return (
        <label className="block">
            <div className="flex items-center justify-between mb-1">
                <span className="text-xs font-semibold text-gray-700">
                    {label}{required && <span className="text-rose-500"> *</span>}
                </span>
                {hint && <span className="text-[10px] text-gray-400">{hint}</span>}
            </div>
            {children}
            {error && <p className="text-[11px] text-rose-600 mt-1">{error}</p>}
        </label>
    );
}

function caseInput(error) {
    return `w-full rounded-xl border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10 transition-colors ${
        error ? 'border-rose-300 bg-rose-50/40' : 'border-gray-200 bg-white'
    }`;
}

// ─── Priority breakdown ─────────────────────────────────────────────────
// Compact counter beside the stage graph — number of cases per priority
// level (urgent / medium / low) plus a "no priority" bucket. Colours match
// the case avatar (red / orange / green / gray).
function PriorityBreakdown({ priorities = {} }) {
    const rows = [
        { key: 'urgent', label: 'Urgent',      solid: 'bg-red-500',     soft: 'bg-red-50',     ring: 'ring-red-100',     text: 'text-red-600' },
        { key: 'high',   label: 'High',        solid: 'bg-orange-500',  soft: 'bg-orange-50',  ring: 'ring-orange-100',  text: 'text-orange-600' },
        { key: 'medium', label: 'Medium',      solid: 'bg-yellow-400',  soft: 'bg-yellow-50',  ring: 'ring-yellow-100',  text: 'text-yellow-600' },
        { key: 'low',    label: 'Low',         solid: 'bg-emerald-500', soft: 'bg-emerald-50', ring: 'ring-emerald-100', text: 'text-emerald-600' },
        { key: 'done',   label: 'Done',        solid: 'bg-emerald-600', soft: 'bg-emerald-50', ring: 'ring-emerald-100', text: 'text-emerald-700' },
        { key: 'none',   label: 'No priority', solid: 'bg-gray-300',    soft: 'bg-gray-50',    ring: 'ring-gray-100',     text: 'text-gray-500' },
    ];
    const total = rows.reduce((sum, r) => sum + (priorities[r.key] || 0), 0);

    return (
        <div className="grid grid-cols-2 grid-rows-3 gap-3 w-full h-full">
            {rows.map((r) => {
                const count = priorities[r.key] || 0;
                const pct = total > 0 ? Math.round((count / total) * 100) : 0;
                return (
                    <div key={r.key} className="bg-white rounded-2xl border border-gray-100 shadow-sm p-3.5 flex flex-col justify-between">
                        <div className="flex items-center justify-between">
                            <span className="text-[10.5px] font-bold uppercase tracking-[0.1em] text-gray-400">
                                {r.label}
                            </span>
                            <span className={`w-6 h-6 rounded-lg ${r.soft} ring-1 ${r.ring} flex items-center justify-center flex-shrink-0`}>
                                <span className={`w-2 h-2 rounded-full ${r.solid}`} />
                            </span>
                        </div>
                        <p className="text-[24px] leading-none font-bold text-gray-900 tabular-nums mt-2">{count}</p>
                        <div className="flex items-center gap-2 mt-2">
                            <div className="flex-1 h-1 rounded-full bg-gray-100 overflow-hidden">
                                <div className={`h-full rounded-full ${r.solid}`} style={{ width: `${pct}%` }} />
                            </div>
                            <span className={`text-[10px] font-semibold ${r.text} tabular-nums`}>{pct}%</span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ─── Distribution graph ─────────────────────────────────────────────────

/**
 * Line graph of case count per immigration stage — same component shape
 * as `StageDistribution` on the Education Students page so admins
 * looking at both pages see one consistent visual idiom.
 *
 * Each stage gets a coloured dot + label; the polyline connects them
 * with a soft area fill underneath. Rotated x-axis labels carry the
 * stage names; the count sits above each dot in matching colour.
 */
function DistributionGraph({ distribution = [], total = 0, activeStage = null, onPick }) {
    // Trailing "Unassigned" bucket — keep it visible but split out from
    // the headline counts the way Education's "unstaged" does, so the
    // chart's metric matches the placed-vs-unstaged narrative.
    const placedStages = distribution.filter((d) => d.stage !== 'Unassigned');
    const unstaged = distribution.find((d) => d.stage === 'Unassigned')?.count || 0;
    const placed = placedStages.reduce((sum, d) => sum + d.count, 0);

    // Empty state — still render the card so the graph column keeps its
    // place beside the priority cards instead of collapsing the layout.
    if (total === 0) {
        return (
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col w-full h-full min-h-[220px]">
                <div className="px-5 pt-4 pb-3 flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-50 to-teal-50 text-emerald-600 flex items-center justify-center ring-1 ring-emerald-100/70">
                        <TrendingUp size={14} />
                    </div>
                    <h2 className="text-[12px] font-bold uppercase tracking-[0.14em] text-gray-700">
                        Immigration stage distribution
                    </h2>
                </div>
                <div className="flex-1 flex flex-col items-center justify-center text-center px-6 pb-6">
                    <TrendingUp size={26} className="text-gray-300" />
                    <p className="text-sm font-semibold text-gray-500 mt-2">No cases to chart yet</p>
                    <p className="text-xs text-gray-400 mt-1">Cases will appear here once they're assigned an immigration stage.</p>
                </div>
            </section>
        );
    }

    const stages = placedStages.map((d) => d.stage);
    const data   = placedStages.map((d) => d.count);
    const peak   = Math.max(1, ...data);

    // SVG viewBox geometry — taken from the Education chart.
    const W         = 920;
    const H         = 170;
    const padX      = 60;
    const padTop    = 22;
    const padBottom = 60;
    const chartW    = W - padX * 2;
    const chartH    = H - padTop - padBottom;
    const baselineY = padTop + chartH;

    const xFor = (i) => padX + (i / Math.max(1, stages.length - 1)) * chartW;
    const yFor = (n) => padTop + (1 - n / peak) * chartH;

    const barW = Math.min(26, (chartW / Math.max(1, stages.length)) * 0.55);

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col w-full h-full">
            <div className="px-5 pt-4 pb-3 flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-50 to-teal-50 text-emerald-600 flex items-center justify-center ring-1 ring-emerald-100/70">
                        <TrendingUp size={14} />
                    </div>
                    <div>
                        <h2 className="text-[12px] font-bold uppercase tracking-[0.14em] text-gray-700">
                            Immigration stage distribution
                        </h2>
                        <p className="text-[10.5px] text-gray-400 mt-0.5">
                            <span className="font-semibold text-gray-600 tabular-nums">{placed}</span> placed ·{' '}
                            <span className="font-semibold text-gray-600 tabular-nums">{unstaged}</span> unstaged ·{' '}
                            <span className="font-semibold text-gray-600 tabular-nums">{total}</span> total
                            {activeStage && (
                                <span className="ml-2">
                                    · filtered by <span className="font-semibold text-gray-700">{activeStage}</span>
                                </span>
                            )}
                        </p>
                    </div>
                </div>
                <span className="text-[10.5px] text-gray-400 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-50 ring-1 ring-gray-100">
                    <span className="w-2 h-2 rounded-full bg-emerald-500" />
                    Cases at stage
                </span>
            </div>

            <div className="px-3 py-3 flex-1 flex items-center">
                <svg
                    viewBox={`0 0 ${W} ${H}`}
                    className="w-full"
                    style={{ maxHeight: 260 }}
                    role="img"
                    aria-label="Bar graph of case count per Immigration stage"
                >
                    {/* Soft horizontal gridlines */}
                    {[0, 0.25, 0.5, 0.75, 1].map((t, i) => (
                        <line
                            key={i}
                            x1={padX}
                            x2={W - padX}
                            y1={padTop + (1 - t) * chartH}
                            y2={padTop + (1 - t) * chartH}
                            stroke="#f1f5f9"
                            strokeWidth="1"
                        />
                    ))}

                    {/* Per-stage bars, count labels, rotated x-axis labels.
                        Whole stage column is clickable to filter the
                        table below. */}
                    {data.map((n, i) => {
                        const tone = stageHex(stages[i]);
                        const isActive = activeStage === stages[i];
                        const barH = baselineY - yFor(n);
                        const w = isActive ? barW + 4 : barW;
                        return (
                            <g
                                key={stages[i]}
                                onClick={() => onPick?.(stages[i])}
                                style={{ cursor: 'pointer' }}
                            >
                                {n > 0 && (
                                    <rect
                                        x={xFor(i) - w / 2}
                                        y={yFor(n)}
                                        width={w}
                                        height={barH}
                                        rx="4"
                                        fill={tone}
                                        fillOpacity={isActive ? 1 : 0.85}
                                    />
                                )}
                                <text
                                    x={xFor(i)}
                                    y={yFor(n) - 9}
                                    textAnchor="middle"
                                    fontSize="10.5"
                                    fontWeight="700"
                                    fill={tone}
                                >
                                    {n}
                                </text>
                                <text
                                    x={xFor(i)}
                                    y={baselineY + 14}
                                    textAnchor="end"
                                    fontSize="9.5"
                                    fontWeight={isActive ? '700' : '500'}
                                    fill={isActive ? '#111827' : '#94a3b8'}
                                    transform={`rotate(-28 ${xFor(i)} ${baselineY + 14})`}
                                >
                                    {stages[i]}
                                </text>
                            </g>
                        );
                    })}
                </svg>
            </div>
        </section>
    );
}

// ─── Table row ──────────────────────────────────────────────────────────

function CaseRow({ c, meId = null, stages, visaTypes = [], readOnly = false, isExpanded, onExpand, stageMenuOpen, onStageMenuToggle, onStageMenuClose, onEdit, onViewFiles, onHandoff }) {
    // Recruiting agents get this exact board read-only (list of their referrals'
    // cases). When readOnly, the name isn't a link (no case profile for agents),
    // the inline editors render as static chips, and the Actions menu is hidden.
    const CaseNameWrapper = readOnly ? 'div' : Link;
    const caseNameProps = readOnly
        ? { className: 'flex items-center gap-2.5 min-w-[200px]' }
        : { href: caseProfileHref(c.id), className: 'flex items-center gap-2.5 min-w-[200px] group/case' };
    // DOCS progress reflects the visa checklist: how many required items
    // the case has submitted, out of the total required.
    const chkTotal = c.checklist_total || 0;
    const chkDone = c.checklist_submitted || 0;
    const pct = chkTotal > 0 ? Math.round((chkDone / chkTotal) * 100) : 0;
    const hasDocs = chkTotal > 0;
    // Immigration theme colour (emerald) for the fill.
    const barColor = 'bg-emerald-500';
    const needsAttention = c.docs_pending > 0 || c.docs_rejected > 0;

    return (
        <React.Fragment>
            <tr className={`group transition-colors ${isExpanded ? 'bg-amber-50/30' : 'hover:bg-gray-50/50'}`}>
                <td className="pl-4 pr-2 py-2.5">
                    <button
                        type="button"
                        onClick={onExpand}
                        className="inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                        title={isExpanded ? 'Collapse' : 'Expand'}
                    >
                        <ChevronRight size={14} className={`transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
                    </button>
                </td>

                {/* Case (avatar + name) */}
                <td className="px-3 py-2.5">
                    <CaseNameWrapper {...caseNameProps}>
                        <div
                            className={`w-8 h-8 rounded-full flex items-center justify-center text-white text-[11px] font-bold flex-shrink-0 overflow-hidden ${c.avatar_url ? `ring-2 ring-offset-1 ${priorityRing(c.immigration_priority)}` : priorityColor(c.immigration_priority)}`}
                            title={c.immigration_priority ? `Priority: ${c.immigration_priority}` : 'No priority set'}
                        >
                            <AvatarPhoto src={c.avatar_url} title={c.name}>
                                {initials(c.name)}
                            </AvatarPhoto>
                        </div>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 min-w-0">
                                <span className="font-semibold text-gray-900 text-sm truncate group-hover/case:text-amber-700 transition-colors">
                                    {c.name}
                                </span>
                                {c.converted_at && Date.now() - new Date(c.converted_at).getTime() < 86400000 && (
                                    <span title={`Converted ${c.converted_by ? `by ${c.converted_by}` : ""}`}
                                        className="flex-shrink-0 inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-emerald-500 text-white">
                                        New
                                    </span>
                                )}
                            </div>
                            {c.dependents?.length > 0 && (
                                <div className="mt-1 flex items-center gap-1 flex-wrap" title={`Related: ${c.dependents.map((d) => d.full_name).join(", ")}`}>
                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded border bg-[#009688]/10 text-[#009688] border-[#009688]/30">
                                        <Users size={10} /> {c.dependents.length} dependant{c.dependents.length > 1 ? "s" : ""}
                                    </span>
                                    <span className="text-[10px] text-gray-400 truncate max-w-[160px]">{c.dependents.map((d) => d.full_name).join(", ")}</span>
                                </div>
                            )}
                            {c.tied_to && (
                                <div className="mt-1 flex items-center gap-1 flex-wrap" title={`This case is included as a dependant on ${c.tied_to.name}'s case`}>
                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded border bg-sky-50 text-sky-700 border-sky-200">
                                        <Link2 size={10} /> Tied to {c.tied_to.name}
                                    </span>
                                </div>
                            )}
                            {c.agent && (
                                <div className="mt-1 flex items-center gap-1" title={`Referring agent: ${c.agent.name}`}>
                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded border bg-violet-50 text-violet-700 border-violet-200">
                                        <UserPlus size={10} /> {c.agent.name}
                                    </span>
                                </div>
                            )}
                            <AttentionChip openedAt={c.attention_opened_at} />
                        </div>
                        {needsAttention && (
                            <span title="Needs attention" className="flex-shrink-0">
                                <AlertTriangle size={11} className="text-rose-500" />
                            </span>
                        )}
                    </CaseNameWrapper>
                </td>

                {/* Stage picker + endorser subtitle */}
                <td className="px-3 py-2.5">
                    <StagePicker
                        caseId={c.id}
                        stages={stages}
                        value={c.immigration_stage || ''}
                        fallback={c.inz_status}
                        readOnly={readOnly}
                        open={stageMenuOpen}
                        onToggle={onStageMenuToggle}
                        onClose={onStageMenuClose}
                    />
                </td>

                {/* Visa picker (inline inz_visa_type edit) */}
                <td className="px-3 py-2.5">
                    <VisaPicker caseId={c.id} visaTypes={visaTypes} value={c.inz_visa_type || ''} readOnly={readOnly} />
                </td>

                {/* Country */}
                <td className="px-3 py-2.5">
                    {c.country
                        ? <span className="text-sm text-gray-700">{c.country}</span>
                        : <span className="text-gray-300">—</span>}
                </td>

                {/* Docs — checklist progress only. File history lives in the
                    row menu / the case's Documents tab so the two aren't
                    conflated. */}
                <td className="px-3 py-2.5">
                    {hasDocs ? (
                        <div className="flex items-center gap-2 min-w-[100px]">
                            <div className="h-1.5 flex-1 rounded-full bg-gray-100 overflow-hidden">
                                <div className={`h-full rounded-full ${barColor}`} style={{ width: `${pct}%` }} />
                            </div>
                            <span className="text-xs font-semibold tabular-nums whitespace-nowrap text-emerald-600"
                                title={`${chkDone} of ${chkTotal} checklist items submitted`}>
                                {pct}%
                            </span>
                        </div>
                    ) : (
                        <span className="text-gray-300">—</span>
                    )}
                </td>

                {/* With — current owner + how long they've held it (plain
                    text). The colour is a separate staleness dot driven by days
                    since last activity, so "long" and "stuck" don't get
                    conflated. */}
                <td className="px-3 py-2.5 whitespace-nowrap">
                    <CustodyCell c={c} meId={meId} />
                </td>

                {/* Updated — the last staff activity on this case: when, who,
                    and what they changed. `updated_at` already carries the
                    last-activity stamp from the server. */}
                <td className="px-3 py-2.5 whitespace-nowrap">
                    {c.updated_at ? (
                        <div className="flex flex-col">
                            <span className="text-xs text-gray-700 tabular-nums">
                                {fmtDateTime(c.updated_at)}
                            </span>
                            {c.updated_by && (
                                <span className="text-[11px] text-gray-400 truncate max-w-[180px]">
                                    by {c.updated_by}
                                </span>
                            )}
                            {c.updated_desc && (
                                <span className="text-[11px] text-gray-500 truncate max-w-[180px]" title={c.updated_desc}>
                                    {c.updated_desc}
                                </span>
                            )}
                        </div>
                    ) : (
                        <span className="text-gray-300">—</span>
                    )}
                </td>

                {/* Actions — collapsed into a three-dot menu. Mirrors
                    the row-menu pattern in portal/sales/Leads.jsx. */}
                <td className="px-3 py-2.5 pr-4 text-right">
                    {readOnly ? null : <RowMenu
                        items={[
                            c.tracking_code && {
                                key: 'copy',
                                label: 'Copy tracking link',
                                icon: Copy,
                                onClick: () => copyTrackingLink(c.tracking_code),
                            },
                            {
                                key: 'docs',
                                label: 'Open documents',
                                icon: FileText,
                                href: caseProfileHref(c.id, "documents"),
                            },
                            {
                                key: 'files',
                                label: `File history (${c.docs_total || 0})`,
                                icon: Paperclip,
                                onClick: () => onViewFiles?.(),
                            },
                            {
                                key: 'open',
                                label: 'Open case',
                                icon: ExternalLink,
                                href: caseProfileHref(c.id),
                            },
                            {
                                key: 'handoff',
                                label: c.owner ? 'Hand off case' : 'Claim / assign',
                                icon: ArrowLeftRight,
                                onClick: () => onHandoff?.(),
                            },
                            portalRequestItem(c, requestCasePortal),
                            {
                                key: 'edit',
                                label: 'Edit case',
                                icon: Pencil,
                                onClick: () => onEdit?.(),
                            },
                            // A recruiting agent works their referrals but can't
                            // archive/delete the case — that stays with staff.
                            ! isAgentCasePortal() && {
                                key: 'archive',
                                label: 'Archive case',
                                icon: Archive,
                                danger: true,
                                onClick: () => archiveCase(c.id, c.name),
                            },
                        ].filter(Boolean)}
                    />}
                </td>
            </tr>

            {isExpanded && (
                <tr className="bg-amber-50/20 border-t border-amber-100/60">
                    <td colSpan={9} className="px-6 py-4">
                        <CaseDetail c={c} readOnly={readOnly} />
                    </td>
                </tr>
            )}
        </React.Fragment>
    );
}

// Compact "owned for" duration — plain text, no colour. "12d", "3h", "just now".
function ownedForShort(iso) {
    if (!iso) return null;
    const ms = Date.now() - new Date(iso).getTime();
    if (ms < 0) return "just now";
    const mins = Math.floor(ms / 60000);
    if (mins < 60) return mins <= 1 ? "just now" : `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h`;
    return `${Math.floor(hrs / 24)}d`;
}

const STALE_DOT = { amber: "bg-amber-400", red: "bg-rose-500" };

/**
 * The "With" cell — owner + how long they've held the case (plain text), plus a
 * separate staleness dot from days-since-last-activity. Ownership duration and
 * "stuck" are deliberately not the same signal.
 */
/**
 * Build 12 phase 4 — attention chip. Shows whether a licensed adviser has
 * opened this case, and when. Deliberately carries no time-on-case — the signal
 * is "has the adviser looked", not how long they spent. Staff board only.
 */
function AttentionChip({ openedAt }) {
    if (! openedAt) {
        return (
            <span className="mt-0.5 inline-flex items-center gap-1 text-[10px] font-semibold text-gray-400">
                <Eye size={10} className="opacity-60" /> Not opened
            </span>
        );
    }
    return (
        <span
            title={`Adviser last opened ${fmtDateTime(openedAt)}`}
            className="mt-0.5 inline-flex items-center gap-1 text-[10px] font-semibold text-teal-600"
        >
            <Eye size={10} /> Opened {fmtDate(openedAt)}
        </span>
    );
}

function CustodyCell({ c, meId = null }) {
    const owner = c.owner;
    const isMine = owner && owner.id === meId;
    const staleTitle = c.custody_stale
        ? `No activity for ${c.idle_days} day${c.idle_days === 1 ? "" : "s"}`
        : null;

    return (
        <div className="flex items-center gap-2 min-w-[120px]">
            {owner ? (
                <>
                    <div className="w-6 h-6 rounded-full overflow-hidden bg-gray-100 ring-1 ring-gray-200 flex items-center justify-center text-[9px] font-bold text-gray-500 flex-shrink-0">
                        <AvatarPhoto src={owner.avatar_url} title={owner.name}>
                            {initials(owner.name)}
                        </AvatarPhoto>
                    </div>
                    <div className="min-w-0">
                        <div className={`text-xs font-medium truncate max-w-[110px] ${isMine ? "text-amber-700" : "text-gray-800"}`}>
                            {isMine ? "You" : owner.name}
                        </div>
                        {c.owner_since && (
                            <div className="text-[10px] text-gray-400 tabular-nums">{ownedForShort(c.owner_since)}</div>
                        )}
                    </div>
                </>
            ) : (
                <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Unassigned</span>
            )}
            {/* Build 12 phase 6 — an open question addressed to me on this case.
                It's in my queue even if I don't own it. */}
            {c.awaiting_my_answer > 0 && (
                <span
                    title={`${c.awaiting_my_answer} open question${c.awaiting_my_answer === 1 ? "" : "s"} for you`}
                    className="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold flex-shrink-0"
                >
                    {c.awaiting_my_answer} ask{c.awaiting_my_answer === 1 ? "" : "s"}
                </span>
            )}
            {c.custody_stale && (
                <span title={staleTitle} className="ml-auto flex-shrink-0">
                    <span className={`block w-2 h-2 rounded-full ${STALE_DOT[c.custody_stale]}`} />
                </span>
            )}
        </div>
    );
}

/**
 * Handoff / claim modal. Picks a new owner (self = claim, same endpoint) and
 * carries an optional note that reaches the recipient in-app + email and lands
 * on the case's Notes tab.
 */
function HandoffModal({ c, meId = null, staff = [], onClose }) {
    const [toUserId, setToUserId] = useState(null);
    const [note, setNote] = useState("");
    const [search, setSearch] = useState("");
    const [submitting, setSubmitting] = useState(false);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return q ? staff.filter((s) => s.name.toLowerCase().includes(q)) : staff;
    }, [staff, search]);

    const alreadyMine = c.owner?.id === meId;

    const submit = (targetId) => {
        if (!targetId) return;
        setSubmitting(true);
        router.post(`${immBase()}/cases/${c.id}/handoff`, { to_user_id: targetId, note }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => toast.error("Could not hand off the case."),
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <div className="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm flex items-center justify-center p-4" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                        <div className="w-8 h-8 rounded-lg bg-gray-900 text-white flex items-center justify-center">
                            <ArrowLeftRight size={15} />
                        </div>
                        <div>
                            <h3 className="text-sm font-bold text-gray-900">Hand off case</h3>
                            <p className="text-[11px] text-gray-500">{c.name}</p>
                        </div>
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                </div>

                <div className="px-5 py-4 space-y-3">
                    <p className="text-[11px] text-gray-500">
                        Currently {c.owner ? <span className="font-semibold text-gray-700">{c.owner.name}</span> : <span className="font-semibold text-gray-500">unassigned</span>}.
                        The new owner is notified with your note.
                    </p>

                    {meId && !alreadyMine && (
                        <button
                            type="button"
                            disabled={submitting}
                            onClick={() => submit(meId)}
                            className="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold hover:bg-amber-700 transition-colors disabled:opacity-50"
                        >
                            <UserPlus size={14} /> Claim — assign to me
                        </button>
                    )}

                    <div>
                        <div className="relative">
                            <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search staff…"
                                className="w-full pl-8 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-xs focus:outline-none focus:bg-white focus:border-gray-300"
                            />
                        </div>
                        <div className="mt-2 max-h-44 overflow-y-auto rounded-lg border border-gray-100 divide-y divide-gray-50">
                            {filtered.length === 0 && <div className="px-3 py-3 text-center text-[11px] text-gray-400">No staff found.</div>}
                            {filtered.map((s) => {
                                const isCurrent = s.id === c.owner?.id;
                                return (
                                    <button
                                        key={s.id}
                                        type="button"
                                        disabled={isCurrent}
                                        onClick={() => setToUserId(s.id)}
                                        className={`w-full flex items-center gap-2.5 px-3 py-2 text-left transition-colors ${
                                            toUserId === s.id ? "bg-amber-50" : "hover:bg-gray-50"
                                        } ${isCurrent ? "opacity-40 cursor-not-allowed" : ""}`}
                                    >
                                        <div className="w-6 h-6 rounded-full overflow-hidden bg-gray-100 ring-1 ring-gray-200 flex items-center justify-center text-[9px] font-bold text-gray-500 flex-shrink-0">
                                            <AvatarPhoto src={s.avatar_url} title={s.name}>{initials(s.name)}</AvatarPhoto>
                                        </div>
                                        <span className="text-xs font-medium text-gray-800 truncate flex-1">
                                            {s.id === meId ? `${s.name} (you)` : s.name}
                                        </span>
                                        {isCurrent && <span className="text-[10px] text-gray-400">current</span>}
                                        {toUserId === s.id && <Check size={14} className="text-amber-600" />}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={3}
                        placeholder="Handoff note (optional) — what the new owner needs to know…"
                        className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-xs resize-y focus:outline-none focus:bg-white focus:border-gray-300"
                    />
                </div>

                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button onClick={onClose} className="px-4 py-2 text-xs font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button
                        type="button"
                        disabled={!toUserId || submitting}
                        onClick={() => submit(toUserId)}
                        className="px-4 py-2 bg-gray-900 text-white text-xs font-bold rounded-xl hover:bg-black transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {submitting ? <Loader2 size={14} className="animate-spin" /> : <ArrowLeftRight size={14} />}
                        Hand off
                    </button>
                </div>
            </div>
        </div>
    );
}

function CaseDetail({ c, readOnly = false }) {
    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <PriorityField c={c} readOnly={readOnly} />
            <DetailField label="Email"        value={c.email} />
            <DetailField label="Phone"        value={c.phone} />
            <DetailField label="INZ Status"   value={c.inz_status} />
            <DetailField label="INZ Reference" value={c.inz_reference} />
            <DetailField label="Documents"
                value={c.docs_total
                    ? `${c.docs_approved} approved · ${c.docs_pending} pending · ${c.docs_rejected} rejected`
                    : null} />
            <DetailField label="Last activity"
                value={c.updated_at ? fmtDate(c.updated_at) : null} />
            {/* Staff member who last moved the stage. Surfaced here in the
                expander rather than the column itself so the row stays
                compact. */}
            <DetailField label="Endorsed by" value={c.endorsed_by} />
        </div>
    );
}

// Inline priority selector shown in the expanded row — posts immediately
// on change. Coloured dot mirrors the case avatar (red / orange / green /
// gray) so the level reads at a glance.
function PriorityField({ c, readOnly = false }) {
    const [saving, setSaving] = useState(false);
    const value = c.immigration_priority || '';

    // Read-only (recruiting agent view): static priority label, no <select>.
    if (readOnly) {
        return (
            <div className="bg-white rounded-lg border border-gray-200 px-3 py-2">
                <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Priority</p>
                <div className="flex items-center gap-1.5">
                    <span className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${priorityColor(value)}`} />
                    <span className="flex-1 text-xs font-semibold text-gray-900 capitalize">{value || 'No priority'}</span>
                </div>
            </div>
        );
    }

    const change = (e) => {
        const v = e.target.value;
        setSaving(true);
        router.post(
            `${immBase()}/cases/${c.id}/priority`,
            { immigration_priority: v || null },
            { preserveScroll: true, preserveState: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-2">
            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                Priority
            </p>
            <div className="flex items-center gap-1.5">
                <span className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${priorityColor(value)}`} />
                <select
                    value={value}
                    onChange={change}
                    disabled={saving}
                    className="flex-1 text-xs font-semibold text-gray-900 bg-transparent outline-none cursor-pointer disabled:opacity-60"
                >
                    <option value="">No priority</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                    <option value="done">Done</option>
                </select>
            </div>
        </div>
    );
}

function DetailField({ label, value }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-2">
            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                {label}
            </p>
            {value ? (
                <p className="text-xs font-semibold text-gray-900 truncate">{value}</p>
            ) : (
                <span className="text-xs text-gray-300 italic">—</span>
            )}
        </div>
    );
}

// ─── Stage picker (inline immigration_stage edit) ───────────────────────

function StagePicker({ caseId, stages, value, fallback, open, onToggle, onClose, readOnly = false }) {
    const [saving, setSaving] = useState(false);
    const [declineOpen, setDeclineOpen] = useState(false);
    // Stages that capture an optional note when the case is moved into them.
    const [noteStage, setNoteStage] = useState(null);
    // Positive/interim outcomes open the same attach-document + note modal.
    const [outcomeStage, setOutcomeStage] = useState(null);
    // "Request for Information" opens a modal for the deadline + RFI PDFs.
    const [rfiOpen, setRfiOpen] = useState(false);
    const [medicalOpen, setMedicalOpen] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0, openUp: false });
    const triggerRef = useRef(null);
    const menuRef = useRef(null);
    const MENU_W = 240;
    const MENU_MAX_H = 360;

    useEffect(() => {
        if (!open || !triggerRef.current) return;
        const place = () => {
            const r = triggerRef.current.getBoundingClientRect();
            const spaceBelow = window.innerHeight - r.bottom;
            const openUp = spaceBelow < Math.min(MENU_MAX_H, 240) && r.top > spaceBelow;
            const left = Math.min(r.left, window.innerWidth - MENU_W - 8);
            setCoords({
                top:  openUp ? r.top - 6 : r.bottom + 6,
                left: Math.max(8, left),
                openUp,
            });
        };
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const onDocClick = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            onClose();
        };
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('mousedown', onDocClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, onClose]);

    const label = value || fallback || 'Set stage';
    const chipClass = value ? stageChipClass(value) : 'bg-gray-100 text-gray-500 border-gray-200 border-dashed';

    // Read-only (recruiting agent view): the stage renders as a static chip —
    // same look, no dropdown.
    if (readOnly) {
        return (
            <span className={`inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold border whitespace-nowrap uppercase ${chipClass}`}>
                <span className="truncate max-w-[180px]">{label}</span>
            </span>
        );
    }

    const select = (stage) => {
        // Declining a visa is more than a stage flip — it opens a modal to
        // attach the decline letter, add a note, and email the client.
        if (stage === "Decline Visa") {
            onClose();
            setDeclineOpen(true);
            return;
        }
        // "Request to Lodge" / "Withdrawn" — offer an optional note before moving.
        if (stage === "Request to Lodge" || stage === "Withdrawn") {
            onClose();
            setNoteStage(stage);
            return;
        }
        // Positive/interim outcomes — attach a document + optional note, then the
        // configured stage automation emails the client.
        if (["Approved Visa", "Interim Visa Issued", "Approved in Principle"].includes(stage)) {
            onClose();
            setOutcomeStage(stage);
            return;
        }
        // Request for Information — capture a response deadline + attach the RFI
        // PDF(s), then the configured RFI automation emails the client.
        if (stage === "Request for Medical") {
            onClose();
            setMedicalOpen(true);
            return;
        }
        if (stage === "Request for Information") {
            onClose();
            setRfiOpen(true);
            return;
        }
        setSaving(true);
        onClose();
        router.post(
            `${immBase()}/cases/${caseId}/stage`,
            { immigration_stage: stage },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            }
        );
    };

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                disabled={saving}
                onClick={onToggle}
                className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-bold border whitespace-nowrap uppercase hover:shadow-sm transition-all disabled:opacity-60 ${chipClass}`}
            >
                <span className="truncate max-w-[180px]">{label}</span>
                <ChevronDown size={10} strokeWidth={2.5} className="opacity-60 flex-shrink-0" />
            </button>

            {open && typeof document !== 'undefined' && createPortal(
                <div
                    ref={menuRef}
                    role="listbox"
                    style={{
                        position: 'fixed',
                        top:      coords.openUp ? 'auto' : coords.top,
                        bottom:   coords.openUp ? (window.innerHeight - coords.top) : 'auto',
                        left:     coords.left,
                        width:    MENU_W,
                        maxHeight: MENU_MAX_H,
                    }}
                    className="z-[60] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5 overflow-y-auto"
                >
                    <p className="px-3 pt-2 pb-1.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                        Move to stage
                    </p>
                    {stages.filter((s) => s !== "Request for Medical" || !isAgentCasePortal()).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => select(s)}
                            className={`w-full text-left flex items-center gap-2 px-3 py-1.5 text-xs hover:bg-gray-50 ${
                                value === s ? 'bg-gray-50 font-semibold text-gray-900' : 'text-gray-700'
                            }`}
                        >
                            <span className={`w-2 h-2 rounded-sm ${STAGE_COLORS[s] || 'bg-gray-300'}`} />
                            {s}
                        </button>
                    ))}
                    {value && (
                        <>
                            <div className="border-t border-gray-100 my-1" />
                            <button
                                type="button"
                                onClick={() => select(null)}
                                className="w-full text-left px-3 py-1.5 text-xs text-gray-500 hover:bg-gray-50"
                            >
                                Clear stage
                            </button>
                        </>
                    )}
                </div>,
                document.body
            )}

            {declineOpen && (
                <DeclineVisaModal caseId={caseId} onClose={() => setDeclineOpen(false)} />
            )}

            {noteStage && (
                <StageNoteModal caseId={caseId} stage={noteStage} onClose={() => setNoteStage(null)} />
            )}

            {outcomeStage && (
                <OutcomeModal caseId={caseId} stage={outcomeStage} onClose={() => setOutcomeStage(null)} />
            )}

            {medicalOpen && (
                <RequestInfoModal caseId={caseId} medical onClose={() => setMedicalOpen(false)} />
            )}

            {rfiOpen && (
                <RequestInfoModal caseId={caseId} onClose={() => setRfiOpen(false)} />
            )}
        </>
    );
}

// ─── Stage note modal ───────────────────────────────────────────────────
// Opens when a case is moved to a stage that captures an optional note
// ("Request to Lodge", "Withdrawn"). The note is stored as an internal case
// note; leaving it blank simply moves the stage.
function StageNoteModal({ caseId, stage, onClose }) {
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    const submit = () => {
        if (saving) return;
        setSaving(true);
        router.post(
            `${immBase()}/cases/${caseId}/stage`,
            { immigration_stage: stage, stage_note: note },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => { toast.success(`Case moved to ${stage}`); onClose(); },
                onError: (e) => toast.error(Object.values(e)[0] || "Could not save"),
                onFinish: () => setSaving(false),
            }
        );
    };

    return createPortal(
        <div className="fixed inset-0 z-[70] bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg" onClick={(e) => e.stopPropagation()}>
                <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                            <span className={`w-2 h-2 rounded-full ${STAGE_COLORS[stage] || 'bg-gray-400'}`} /> Move to {stage}
                        </h2>
                        <p className="text-[11px] text-gray-500 mt-0.5">Add an optional note for the record. You can leave it blank.</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={16} /></button>
                </header>

                <div className="px-5 py-4">
                    <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Note (optional)</label>
                    <textarea
                        autoFocus
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={4}
                        maxLength={2000}
                        placeholder={stage === "Withdrawn" ? "Reason for withdrawal…" : "Anything worth recording about the lodgement…"}
                        className="w-full text-sm px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                    />
                </div>

                <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={saving}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                    >
                        {saving ? <Loader2 size={12} className="animate-spin" /> : null}
                        Move to {stage}
                    </button>
                </footer>
            </div>
        </div>,
        document.body
    );
}

// ─── Decline Visa modal ─────────────────────────────────────────────────
// Opens when a case is moved to "Decline Visa". Attaches an optional decline
// letter (shared to the client), an optional note, and optionally emails the
// client. Posts to the decline endpoint which does the stage move + record.
function DeclineVisaModal({ caseId, onClose }) {
    const [file, setFile] = useState(null);
    const [note, setNote] = useState("");
    const [notify, setNotify] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    const submit = () => {
        if (saving) return;
        setSaving(true);
        router.post(
            `${immBase()}/cases/${caseId}/decline`,
            { document: file, note, notify: notify ? 1 : 0 },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => { toast.success("Case moved to Decline Visa"); onClose(); },
                onError: (e) => toast.error(Object.values(e)[0] || "Could not save"),
                onFinish: () => setSaving(false),
            }
        );
    };

    return createPortal(
        <div className="fixed inset-0 z-[70] bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg" onClick={(e) => e.stopPropagation()}>
                <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                            <AlertTriangle size={15} className="text-rose-500" /> Decline visa
                        </h2>
                        <p className="text-[11px] text-gray-500 mt-0.5">Attach the decline letter and note. Both are optional.</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={16} /></button>
                </header>

                <div className="px-5 py-4 space-y-4">
                    {/* Document upload */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Decline letter (optional)</label>
                        <label className="flex items-center gap-2 px-3 py-2.5 rounded-lg border border-dashed border-gray-300 cursor-pointer hover:border-gray-400 text-[12px] text-gray-600">
                            <Paperclip size={14} className="text-gray-400" />
                            <span className="truncate">{file ? file.name : "Choose a file (PDF, DOC, image)"}</span>
                            <input
                                type="file"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                className="hidden"
                                onChange={(e) => setFile(e.target.files?.[0] || null)}
                            />
                        </label>
                        {file && (
                            <button type="button" onClick={() => setFile(null)} className="mt-1 text-[11px] text-gray-400 hover:text-rose-600">Remove file</button>
                        )}
                        <p className="text-[10px] text-gray-400 mt-1">Shared to the client's portal and shown on the Documents tab.</p>
                    </div>

                    {/* Note */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Note to include (optional)</label>
                        <textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            rows={4}
                            maxLength={2000}
                            placeholder="Any message for the record and the client email…"
                            className="w-full text-sm px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                        />
                    </div>

                    {/* Email toggle */}
                    <label className="inline-flex items-center gap-2 text-[12px] text-gray-700 cursor-pointer">
                        <input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} className="rounded border-gray-300" />
                        <Mail size={13} className="text-gray-400" /> Email the client this update
                    </label>
                </div>

                <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={saving}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50"
                    >
                        {saving ? <Loader2 size={12} className="animate-spin" /> : <AlertTriangle size={12} />}
                        Confirm decline
                    </button>
                </footer>
            </div>
        </div>,
        document.body
    );
}

// ─── Outcome modal (Approved / Interim / Approved in Principle) ──────────
// Same shape as the decline modal — attach a document + optional note — but the
// client email is the configured stage automation (staff-authored template),
// so the note rides along as {{status_detail}}.
function OutcomeModal({ caseId, stage, onClose }) {
    const [file, setFile] = useState(null);
    const [note, setNote] = useState("");
    const [notify, setNotify] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    const submit = () => {
        if (saving) return;
        setSaving(true);
        router.post(
            `${immBase()}/cases/${caseId}/outcome`,
            { stage, document: file, note, notify: notify ? 1 : 0 },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => { toast.success(`Case moved to ${stage}`); onClose(); },
                onError: (e) => toast.error(Object.values(e)[0] || "Could not save"),
                onFinish: () => setSaving(false),
            }
        );
    };

    return createPortal(
        <div className="fixed inset-0 z-[70] bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg" onClick={(e) => e.stopPropagation()}>
                <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                            <span className={`w-2 h-2 rounded-full ${STAGE_COLORS[stage] || 'bg-gray-400'}`} /> Move to {stage}
                        </h2>
                        <p className="text-[11px] text-gray-500 mt-0.5">Attach a document and note. Both are optional.</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={16} /></button>
                </header>

                <div className="px-5 py-4 space-y-4">
                    {/* Document upload */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Document (optional)</label>
                        <label className="flex items-center gap-2 px-3 py-2.5 rounded-lg border border-dashed border-gray-300 cursor-pointer hover:border-gray-400 text-[12px] text-gray-600">
                            <Paperclip size={14} className="text-gray-400" />
                            <span className="truncate">{file ? file.name : "Choose a file (PDF, DOC, image)"}</span>
                            <input
                                type="file"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                className="hidden"
                                onChange={(e) => setFile(e.target.files?.[0] || null)}
                            />
                        </label>
                        {file && (
                            <button type="button" onClick={() => setFile(null)} className="mt-1 text-[11px] text-gray-400 hover:text-rose-600">Remove file</button>
                        )}
                        <p className="text-[10px] text-gray-400 mt-1">Shared to the client's portal and shown on the Documents tab.</p>
                    </div>

                    {/* Note */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Note to include (optional)</label>
                        <textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            rows={4}
                            maxLength={2000}
                            placeholder="Any message for the record and the client email…"
                            className="w-full text-sm px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                        />
                        <p className="text-[10px] text-gray-400 mt-1">Included in the email as {"{{status_detail}}"} if your template uses it.</p>
                    </div>

                    {/* Email toggle */}
                    <label className="inline-flex items-center gap-2 text-[12px] text-gray-700 cursor-pointer">
                        <input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} className="rounded border-gray-300" />
                        <Mail size={13} className="text-gray-400" /> Email the client (uses the “Moved to {stage}” automation)
                    </label>
                </div>

                <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={saving}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                    >
                        {saving ? <Loader2 size={12} className="animate-spin" /> : null}
                        Move to {stage}
                    </button>
                </footer>
            </div>
        </div>,
        document.body
    );
}

// ─── Request for Information modal ──────────────────────────────────────
// Opens when a case is moved to "Request for Information" (INZ asked for more).
// Captures the response DEADLINE, attaches the RFI PDF(s) (shared to the case +
// client portal), an optional note, and fires the configured RFI stage
// automation (PDFs attached to the email) when "notify" is ticked.
export function RequestInfoModal({ caseId, onClose, medical = false }) {
    const STAGE = medical ? "Request for Medical" : "Request for Information";
    const today = new Date().toISOString().slice(0, 10);
    const [files, setFiles] = useState([]);
    const [deadline, setDeadline] = useState("");
    const [note, setNote] = useState("");
    const [notify, setNotify] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notificationRetry, setNotificationRetry] = useState(false);

    // AI reads the uploaded RFI letter and lists the documents INZ is asking
    // for. Staff review / edit this list; on submit each becomes a request the
    // client uploads against. Nothing is created until staff confirm here.
    const [analyzing, setAnalyzing] = useState(false);
    const [analyzed, setAnalyzed] = useState(false); // a run has completed
    const [aiUsed, setAiUsed] = useState(false);
    const [textFound, setTextFound] = useState(true);
    const [reqDocs, setReqDocs] = useState([]); // [{ label, description, required, include }]

    // PDF preview shown in the left pane so staff can read the letter beside the
    // extracted documents. Object URLs are created/revoked as the selection changes.
    const [previewIdx, setPreviewIdx] = useState(0);
    const [previewUrl, setPreviewUrl] = useState(null);

    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    useEffect(() => {
        const f = files[previewIdx];
        if (!f) { setPreviewUrl(null); return; }
        const url = URL.createObjectURL(f);
        setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [files, previewIdx]);

    const onFilesChosen = (chosen) => {
        setFiles(chosen);
        setPreviewIdx(0);
        setAnalyzed(false);
        setReqDocs([]);
        if (chosen.length && !medical) analyze(chosen);
    };

    // Ask the server to read the PDF(s) and extract the requested documents.
    const analyze = (chosen) => {
        const fd = new FormData();
        chosen.forEach((f) => fd.append("documents[]", f));
        setAnalyzing(true);
        window.axios
            .post(`${immBase()}/cases/${caseId}/rfi/analyze`, fd)
            .then(({ data }) => {
                setAiUsed(!!data.ai_used);
                setTextFound(data.text_found !== false);
                // Auto-fill the deadline the letter sets (staff can still change it).
                if (data.deadline) setDeadline(data.deadline);
                setReqDocs((data.documents || []).map((d) => ({
                    label: d.label || "", description: d.description || "",
                    required: true, include: true,
                })));
                setAnalyzed(true);
                if (data.ai_used && (data.documents || []).length) {
                    toast.success(`Found ${data.documents.length} requested document${data.documents.length > 1 ? "s" : ""}${data.deadline ? " + deadline" : ""}`);
                }
            })
            .catch(() => toast.error("Couldn't read the RFI letter — add the documents manually."))
            .finally(() => setAnalyzing(false));
    };

    const updateDoc = (i, patch) => setReqDocs((list) => list.map((d, j) => (j === i ? { ...d, ...patch } : d)));
    const removeDoc = (i) => setReqDocs((list) => list.filter((_, j) => j !== i));
    const addDoc = () => setReqDocs((list) => [...list, { label: "", description: "", required: true, include: true }]);

    const submit = () => {
        if (saving) return;
        if (!notificationRetry && !deadline) { toast.error("Set a response deadline."); return; }
        if (!notificationRetry && medical && !files.length) { toast.error("Attach the INZ medical notice PDF."); return; }
        // Only included rows with a real label are sent to the client.
        const requested_documents = reqDocs
            .filter((d) => d.include && d.label.trim())
            .map((d) => ({ label: d.label.trim(), description: d.description.trim(), required: d.required ? 1 : 0 }));
        setSaving(true);
        router.post(
            `${immBase()}/cases/${caseId}/${medical ? "medical" : "rfi"}`,
            notificationRetry ? { retry_notification: 1 } : { deadline, note, notify: notify ? 1 : 0, documents: files, ...(medical ? {} : { requested_documents }) },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => { toast.success(notificationRetry ? "Medical notification queued" : `Case moved to ${STAGE}`); onClose(); },
                onError: (e) => { if (medical && e.notification) setNotificationRetry(true); toast.error(Object.values(e)[0] || "Could not save"); },
                onFinish: () => setSaving(false),
            }
        );
    };

    const includedCount = reqDocs.filter((d) => d.include && d.label.trim()).length;

    return createPortal(
        <div className="fixed inset-0 z-[70] bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={onClose}>
            <div className={`bg-white rounded-2xl shadow-2xl w-full flex flex-col transition-all duration-200 ${files.length ? "max-w-7xl h-[92vh]" : "max-w-lg max-h-[90vh]"}`} onClick={(e) => e.stopPropagation()}>
                <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                            <span className={`w-2 h-2 rounded-full ${STAGE_COLORS[STAGE] || 'bg-amber-500'}`} /> {STAGE}
                        </h2>
                        <p className="text-[11px] text-gray-500 mt-0.5">{medical ? "Attach INZ’s medical notice and set its response deadline." : "Set the response deadline and attach INZ’s RFI letter(s)."}</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={16} /></button>
                </header>

                <div className="flex flex-col md:flex-row min-h-0 flex-1 overflow-hidden">
                    {/* Left pane — the RFI letter, so staff read it beside the
                        documents extracted from it. Only when a file is chosen. */}
                    {files.length > 0 && (
                        <div className="md:w-1/2 md:border-r border-b md:border-b-0 border-gray-100 bg-gray-100 flex flex-col min-h-0 h-64 md:h-auto">
                            {files.length > 1 && (
                                <div className="flex items-center gap-1 px-2 py-1.5 bg-white border-b border-gray-100 overflow-x-auto">
                                    {files.map((f, i) => (
                                        <button
                                            key={i}
                                            type="button"
                                            onClick={() => setPreviewIdx(i)}
                                            className={`px-2 py-1 rounded text-[10px] font-semibold whitespace-nowrap max-w-[140px] truncate ${i === previewIdx ? "bg-gray-900 text-white" : "text-gray-500 hover:bg-gray-100"}`}
                                            title={f.name}
                                        >
                                            {f.name}
                                        </button>
                                    ))}
                                </div>
                            )}
                            {previewUrl
                                ? <iframe src={previewUrl} title="RFI letter" className="flex-1 w-full border-0 bg-white" />
                                : <div className="flex-1 grid place-items-center text-[11px] text-gray-400">Preview unavailable</div>}
                        </div>
                    )}

                    {/* Right pane — the form + extracted documents. */}
                    <div className={`px-5 py-4 space-y-4 overflow-y-auto min-h-0 flex-1 ${files.length ? "md:w-1/2" : ""}`}>
                    {notificationRetry && <p role="alert" className="text-sm text-amber-800">The medical request and notice are saved. The email could not be queued. Check the client email and active Request for Medical template, then retry notification. This will not upload the notice again.</p>}
                    {/* Sequence: 1) upload the letter, 2) deadline (auto-filled
                        from it), 3) the documents it requests. */}

                    {/* 1 — RFI PDFs (multiple) */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">{medical ? "INZ medical notice — PDF (required)" : "RFI document(s) — PDF"}</label>
                        <label className="flex items-center gap-2 px-3 py-2.5 rounded-lg border border-dashed border-gray-300 cursor-pointer hover:border-gray-400 text-[12px] text-gray-600">
                            <Paperclip size={14} className="text-gray-400" />
                            <span className="truncate">{files.length ? `${files.length} file${files.length > 1 ? "s" : ""} selected` : "Choose PDF file(s)"}</span>
                            <input
                                type="file"
                                accept="application/pdf"
                                multiple
                                className="hidden"
                                onChange={(e) => onFilesChosen(Array.from(e.target.files || []))}
                            />
                        </label>
                        {files.length > 0 && (
                            <ul className="mt-1.5 space-y-0.5">
                                {files.map((f, i) => (
                                    <li key={i} className="text-[11px] text-emerald-600 font-medium truncate">• {f.name}</li>
                                ))}
                            </ul>
                        )}
                        <p className="text-[10px] text-gray-400 mt-1">Shared to the client&apos;s portal, shown on the Documents tab, and attached to the email.</p>
                    </div>

                    {/* 2 — Deadline (auto-filled from the letter when found) */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">
                            Response deadline <span className="text-rose-500">*</span>
                        </label>
                        <div className="relative">
                            <Calendar size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                            <input
                                type="date"
                                value={deadline}
                                min={today}
                                onChange={(e) => setDeadline(e.target.value)}
                                className="w-full text-sm pl-9 pr-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900"
                            />
                        </div>
                        {analyzed && aiUsed && deadline && (
                            <p className="text-[10px] text-indigo-500 mt-1 inline-flex items-center gap-1">
                                <Sparkles size={10} /> Read from the letter — adjust if needed.
                            </p>
                        )}
                    </div>

                    {/* 3 — AI-read requested documents. Appears once a letter is
                        chosen; staff review / edit before confirming. */}
                    {(analyzing || analyzed || reqDocs.length > 0) && (
                        <div className="rounded-lg border border-indigo-100 bg-indigo-50/40 px-3 py-3">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-[11px] font-semibold text-indigo-900 inline-flex items-center gap-1.5">
                                    <Sparkles size={13} className="text-indigo-500" /> Documents requested by INZ
                                </p>
                                {analyzing && <Loader2 size={13} className="animate-spin text-indigo-400" />}
                            </div>

                            {analyzing && (
                                <p className="text-[11px] text-indigo-700/80 mt-1.5">Reading the RFI letter…</p>
                            )}

                            {! analyzing && analyzed && aiUsed && reqDocs.length === 0 && (
                                <p className="text-[11px] text-gray-500 mt-1.5">No documents were detected in the letter. Add them manually below.</p>
                            )}
                            {! analyzing && analyzed && ! textFound && (
                                <p className="text-[11px] text-amber-700 mt-1.5">This PDF looks scanned (no readable text). Add the requested documents manually below.</p>
                            )}
                            {! analyzing && analyzed && textFound && ! aiUsed && (
                                <p className="text-[11px] text-gray-500 mt-1.5">Automatic reading is unavailable. Add the requested documents manually below.</p>
                            )}

                            {reqDocs.length > 0 && (
                                <ul className="mt-2 space-y-2">
                                    {reqDocs.map((d, i) => (
                                        <li key={i} className={`rounded-md border px-2.5 py-2 ${d.include ? "border-indigo-200 bg-white" : "border-gray-200 bg-gray-50 opacity-60"}`}>
                                            <div className="flex items-start gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={d.include}
                                                    onChange={(e) => updateDoc(i, { include: e.target.checked })}
                                                    className="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    title="Include this document"
                                                />
                                                <div className="min-w-0 flex-1 space-y-1">
                                                    <input
                                                        type="text"
                                                        value={d.label}
                                                        onChange={(e) => updateDoc(i, { label: e.target.value })}
                                                        maxLength={120}
                                                        placeholder="Document name"
                                                        className="w-full text-[12px] font-medium text-gray-900 bg-transparent border-0 border-b border-transparent focus:border-indigo-300 focus:outline-none px-0 py-0.5"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={d.description}
                                                        onChange={(e) => updateDoc(i, { description: e.target.value })}
                                                        maxLength={500}
                                                        placeholder="Note (optional)"
                                                        className="w-full text-[11px] text-gray-500 bg-transparent border-0 focus:outline-none px-0"
                                                    />
                                                </div>
                                                <button type="button" onClick={() => removeDoc(i)} className="mt-0.5 text-gray-300 hover:text-rose-500" title="Remove">
                                                    <Trash2 size={13} />
                                                </button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <button
                                type="button"
                                onClick={addDoc}
                                className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                <Plus size={12} /> Add a document
                            </button>
                            {includedCount > 0 && (
                                <p className="text-[10px] text-gray-500 mt-2">
                                    {includedCount} document{includedCount > 1 ? "s" : ""} will be requested from the client in their portal.
                                </p>
                            )}
                        </div>
                    )}

                    {/* Note */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1.5">Note (optional)</label>
                        <textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            rows={3}
                            maxLength={2000}
                            placeholder={medical ? "Optional case note…" : "What INZ is asking for…"}
                            className="w-full text-sm px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                        />
                    </div>

                    {/* Email toggle */}
                    <label className="inline-flex items-center gap-2 text-[12px] text-gray-700 cursor-pointer">
                        <input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} className="rounded border-gray-300" />
                        <Mail size={13} className="text-gray-400" /> {medical ? "Email the client the notice and deadline" : "Email the client (uses the RFI stage automation)"}
                    </label>
                    </div>
                </div>

                <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={saving}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                    >
                        {saving ? <Loader2 size={12} className="animate-spin" /> : null}
                        {notificationRetry ? "Retry notification" : `Move to ${STAGE}`}
                    </button>
                </footer>
            </div>
        </div>,
        document.body
    );
}

// ─── Visa picker (inline inz_visa_type edit) ────────────────────────────
// Mirrors StagePicker but self-manages its open state. Posts the chosen
// visa_type_id; the controller stamps the matching VisaType name onto
// inz_visa_type (or clears it when "None" is chosen).
function VisaPicker({ caseId, visaTypes = [], value, readOnly = false }) {
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0, openUp: false });
    const triggerRef = useRef(null);
    const menuRef = useRef(null);
    const MENU_W = 260;
    const MENU_MAX_H = 360;

    useEffect(() => {
        if (! open || ! triggerRef.current) return;
        const place = () => {
            const r = triggerRef.current.getBoundingClientRect();
            const spaceBelow = window.innerHeight - r.bottom;
            const openUp = spaceBelow < Math.min(MENU_MAX_H, 240) && r.top > spaceBelow;
            const left = Math.min(r.left, window.innerWidth - MENU_W - 8);
            setCoords({ top: openUp ? r.top - 6 : r.bottom + 6, left: Math.max(8, left), openUp });
        };
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open]);

    useEffect(() => {
        if (! open) return;
        const onDocClick = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            setOpen(false);
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onDocClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const select = (visaTypeId) => {
        setSaving(true);
        setOpen(false);
        router.post(
            `${immBase()}/cases/${caseId}/visa`,
            { visa_type_id: visaTypeId },
            { preserveScroll: true, preserveState: true, onFinish: () => setSaving(false) },
        );
    };

    const hasValue = !! value;

    // Read-only (recruiting agent view): static visa-type chip, no dropdown.
    if (readOnly) {
        return (
            <span className={`inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border whitespace-nowrap ${
                hasValue ? 'bg-white text-gray-700 border-gray-200' : 'bg-gray-100 text-gray-400 border-gray-200 border-dashed'
            }`}>
                <span className="truncate max-w-[190px]">{value || '—'}</span>
            </span>
        );
    }

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                disabled={saving}
                onClick={(e) => { e.stopPropagation(); setOpen((o) => ! o); }}
                className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium border whitespace-nowrap hover:shadow-sm transition-all disabled:opacity-60 ${
                    hasValue ? 'bg-white text-gray-700 border-gray-200' : 'bg-gray-100 text-gray-400 border-gray-200 border-dashed'
                }`}
            >
                <span className="truncate max-w-[190px]">{value || 'Set visa'}</span>
                <ChevronDown size={10} strokeWidth={2.5} className="opacity-60 flex-shrink-0" />
            </button>

            {open && typeof document !== 'undefined' && createPortal(
                <div
                    ref={menuRef}
                    role="listbox"
                    style={{
                        position: 'fixed',
                        top:    coords.openUp ? 'auto' : coords.top,
                        bottom: coords.openUp ? (window.innerHeight - coords.top) : 'auto',
                        left:   coords.left,
                        width:  MENU_W,
                        maxHeight: MENU_MAX_H,
                    }}
                    className="z-[60] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5 overflow-y-auto"
                >
                    <p className="px-3 pt-2 pb-1.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                        Set visa type
                    </p>
                    {visaTypes.length === 0 && (
                        <p className="px-3 py-2 text-[11px] text-gray-400">No visa types available.</p>
                    )}
                    {visaTypes.map((v) => (
                        <button
                            key={v.id}
                            type="button"
                            onClick={() => select(v.id)}
                            className={`w-full text-left flex items-center gap-2 px-3 py-1.5 text-xs hover:bg-gray-50 ${
                                value === v.name ? 'bg-gray-50 font-semibold text-gray-900' : 'text-gray-700'
                            }`}
                        >
                            {v.name}
                        </button>
                    ))}
                    {hasValue && (
                        <>
                            <div className="border-t border-gray-100 my-1" />
                            <button
                                type="button"
                                onClick={() => select(null)}
                                className="w-full text-left px-3 py-1.5 text-xs text-gray-500 hover:bg-gray-50"
                            >
                                Clear visa
                            </button>
                        </>
                    )}
                </div>,
                document.body,
            )}
        </>
    );
}

// ─── Helpers ─────────────────────────────────────────────────────────────

function SortableTh({ label, sortKey, current, dir, onSort }) {
    const active = current === sortKey;
    const Icon = !active ? ArrowUpDown : dir === 'asc' ? ArrowUp : ArrowDown;
    return (
        <th className="px-3 py-3">
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={`inline-flex items-center gap-1 hover:text-gray-700 transition-colors ${active ? 'text-gray-700' : 'text-gray-500'}`}
            >
                {label}
                <Icon size={11} className="opacity-60" />
            </button>
        </th>
    );
}

function initials(name = '') {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() || '')
        .join('') || '?';
}

function avatarColor(id) {
    const palette = [
        'bg-rose-500', 'bg-amber-500', 'bg-emerald-500',
        'bg-sky-500', 'bg-indigo-500', 'bg-violet-500',
        'bg-fuchsia-500', 'bg-teal-500',
    ];
    return palette[Math.abs((id || 0) * 31) % palette.length];
}

// Priority → avatar colour: urgent (red), high (orange), medium (yellow),
// low (green), done (completed). No priority set → neutral gray circle.
function priorityColor(priority) {
    switch (priority) {
        case 'urgent': return 'bg-red-500';
        case 'high':   return 'bg-orange-500';
        case 'medium': return 'bg-yellow-400';
        case 'low':    return 'bg-emerald-500';
        case 'done':   return 'bg-emerald-600';
        default:       return 'bg-gray-400';
    }
}

// priorityRing / priorityRank now live in @/utils/priority — see the import
// at the top of this file. Shared with Leads and Students so the avatar ring
// means the same thing on every staff table.

function fmtDate(d) {
    if (!d) return '—';
    try {
        const dt = new Date(d);
        return dt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    } catch {
        return d;
    }
}

function fmtDateTime(d) {
    if (!d) return '—';
    try {
        const dt = new Date(d);
        return dt.toLocaleString(undefined, {
            day: 'numeric', month: 'short', year: 'numeric',
            hour: 'numeric', minute: '2-digit',
        });
    } catch {
        return d;
    }
}

/**
 * Three-dot row action menu. Items are `{ key, label, icon, onClick?,
 * href? }`. Renders the popover in a React portal at body level so it
 * escapes any ancestor `overflow-hidden` on the table.
 */
function RowMenu({ items = [] }) {
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0, openUp: false });
    const triggerRef = useRef(null);
    const menuRef = useRef(null);
    const MENU_W = 220;

    useEffect(() => {
        if (!open || !triggerRef.current) return;
        const place = () => {
            const r = triggerRef.current.getBoundingClientRect();
            // Anchor the menu's right edge to the trigger so it opens
            // leftward (the actions column lives at the right of the row).
            // Flip upward when there isn't room below (row near the viewport
            // bottom) so the menu never gets clipped by the window edge.
            const menuH = Math.min(360, items.length * 38 + 16);
            const spaceBelow = window.innerHeight - r.bottom;
            const openUp = spaceBelow < menuH + 12 && r.top > spaceBelow;
            setCoords({
                top:  openUp ? r.top - 6 : r.bottom + 6,
                left: Math.min(r.right - MENU_W, window.innerWidth - MENU_W - 8),
                openUp,
            });
        };
        place();
        const onScroll = () => place();
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll);
        return () => {
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        };
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const onDocClick = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            setOpen(false);
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onDocClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const handleClick = (item) => {
        setOpen(false);
        item.onClick?.();
    };

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                onClick={() => setOpen(!open)}
                title="More actions"
                className={`inline-flex items-center justify-center w-7 h-7 rounded-md transition-colors ${open ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'}`}
            >
                <MoreHorizontal size={14} />
            </button>

            {open && typeof document !== 'undefined' && createPortal(
                <div
                    ref={menuRef}
                    role="menu"
                    style={{
                        position: 'fixed',
                        top:    coords.openUp ? 'auto' : coords.top,
                        bottom: coords.openUp ? (window.innerHeight - coords.top) : 'auto',
                        left:   Math.max(8, coords.left),
                        width:  MENU_W,
                        maxHeight: 360,
                        overflowY: 'auto',
                    }}
                    className="z-[60] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5"
                >
                    {items.map((it, idx) => {
                        const Icon = it.icon;
                        if (it.disabled) {
                            return (
                                <div key={it.key} className="flex items-center gap-2.5 px-3 py-2 text-xs text-gray-400 cursor-default select-none">
                                    {Icon && <Icon size={13} className="text-gray-300" />}
                                    {it.label}
                                </div>
                            );
                        }
                        // Destructive items (Archive, Delete) sit at the
                        // bottom of the menu separated by a divider.
                        const showDivider = it.danger && items[idx - 1] && ! items[idx - 1].danger;
                        const itemTone = it.danger
                            ? 'text-red-700 hover:bg-red-50'
                            : 'text-gray-700 hover:bg-gray-50';
                        const iconTone = it.danger ? 'text-red-400' : 'text-gray-400';
                        const inner = (
                            <span className={`flex items-center gap-2.5 px-3 py-2 text-xs cursor-pointer ${itemTone}`}>
                                {Icon && <Icon size={13} className={iconTone} />}
                                {it.label}
                            </span>
                        );
                        return (
                            <React.Fragment key={it.key}>
                                {showDivider && <div className="my-1 border-t border-gray-100" />}
                                {it.href ? (
                                    <Link href={it.href} onClick={() => setOpen(false)} className="block">
                                        {inner}
                                    </Link>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => handleClick(it)}
                                        className="w-full text-left block"
                                    >
                                        {inner}
                                    </button>
                                )}
                            </React.Fragment>
                        );
                    })}
                </div>,
                document.body
            )}
        </>
    );
}

// Write the public /track link + raw code to the clipboard in a single
// payload so staff can paste it straight into WhatsApp / email / SMS.
// Mirrors the same helper used in Sales/Leads + Education/Students.
function copyTrackingLink(code) {
    if (!code) return;
    const url = `${window.location.origin}/track/${code}`;
    navigator.clipboard?.writeText(url).then(
        () => toast.success('Tracking link copied', { description: url }),
        () => toast.error('Could not copy — your browser blocked clipboard access')
    );
}

// Request Lead Portal access for a case (same flow as the Sales Leads page).
// A case is a lead, so this posts to the immigration portal's own request
// route; admin then reviews and approves from Portal Invitations.
const PORTAL_STATUS_LABEL = {
    requested: "Portal access requested",
    sent: "Portal invitation sent",
    active: "Portal account active",
    rejected: "Portal request rejected",
};
const portalRequestItem = (row, onRequest) => {
    const st = row.portal_invitation_status || "none";
    const canRequest = st === "none" || st === "revoked";
    return {
        key: "portal",
        label: canRequest ? "Request portal access" : (PORTAL_STATUS_LABEL[st] || "Portal access"),
        icon: KeyRound,
        ...(canRequest ? { onClick: () => onRequest(row) } : { disabled: true }),
    };
};
function requestCasePortal(c) {
    if (! window.confirm(`Request Lead Portal access for ${c.name || "this client"}? Admin will review and approve.`)) return;
    router.post(`${immBase()}/leads/${c.id}/portal-invitation/request`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toast.success("Portal access requested."),
        // Surface the specific reason from the server (e.g. no email on file)
        // instead of a generic "could not request".
        onError: (errors) => toast.error(errors?.error || "Could not request portal access."),
    });
}

// Soft-delete (archive) a case. The Lead row is soft-deleted via the
// admin/leads/{id} DELETE endpoint; notes/tasks/documents survive and
// the case can be restored from the archive view. A confirm dialog
// gates the destructive action — there's no undo button in the table.
function archiveCase(id, name) {
    const label = (name || 'this case').trim();
    if (! window.confirm(`Archive ${label}?\n\nThe case will be hidden from the Cases list. Notes, tasks, and documents are preserved and the case can be restored later.`)) return;
    router.delete(`${leadBase()}/${id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success(`${label} archived`),
        onError: () => toast.error('Could not archive — please try again.'),
    });
}
