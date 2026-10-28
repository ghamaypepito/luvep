import { useMemo, useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import {
    BadgeCheck, CheckCircle2, ShieldCheck, GraduationCap, Mail, Clock, Loader2,
    ExternalLink, Search, X, Check, Plus, School, CalendarDays, Send,
    Trash2, ArrowLeftRight, DollarSign, AlertCircle, ChevronRight, Flag,
} from "lucide-react";

// ── Program Verification ────────────────────────────────────────────────────
// Reviewer (super admin / education) works a queue of submitted study proposals:
// pick a proposal on the left, confirm each program's fee / school / intake and
// verify it, run the pre-approval checks, then approve — with or without the
// client email. Only on approval does the shortlist reach the client's tracker.

const money = (n) => {
    const v = Number(n);
    return Number.isFinite(v) && v > 0 ? v.toLocaleString("en-NZ", { maximumFractionDigits: 0 }) : "—";
};

const timeAgo = (iso) => {
    if (!iso) return "";
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 3600) return `${Math.floor(s / 60) || 1}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    const d = Math.floor(s / 86400);
    return d === 1 ? "Yesterday" : `${d} days ago`;
};

const fmtDateTime = (iso) => iso
    ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })
    : "—";

export default function ProgramVerification({
    selectedLeadId = null, proposals = [], counts = { pending: 0, verified: 0, approved_today: 0 },
    programs = [], schools = [], leadBase = "/admin", selectedStatus = "pending", pagination = {},
}) {
    const filter = selectedStatus;
    const setFilter = (status) => router.get(window.location.pathname, { status }, { preserveScroll: true });
    const [activeId, setActiveId] = useState(selectedLeadId ?? proposals[0]?.id ?? null);

    const list = useMemo(() => proposals.filter((p) => {
        if (filter === "approved") return !!p.approved_at;
        return p.status === filter;
    }), [proposals, filter]);

    // Keep a valid active row as the filter / data changes.
    useEffect(() => {
        if (!list.find((p) => p.id === activeId)) setActiveId(list[0]?.id ?? null);
    }, [list]); // eslint-disable-line react-hooks/exhaustive-deps

    const active = proposals.find((p) => p.id === activeId) || null;

    return (
        <div className="max-w-[1500px] mx-auto pb-10">
            <Head title="Program Verification" />
            {selectedLeadId && <Link href="/program-verification" className="inline-block mb-3 text-sm font-semibold text-emerald-800 hover:underline">← All reviews</Link>}

            <div className="flex items-center gap-3 flex-wrap mb-4">
                <h1 className="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <BadgeCheck size={20} /> Program Verification
                </h1>
                <span className="text-[12px] text-gray-500">
                    {counts.pending} pending · {counts.verified} verified · {counts.approved_today} approved today
                </span>
            </div>

            <div className="flex gap-5 items-start">
                {/* ── Left rail — filters + queue ─────────────────────────── */}
                <aside className="w-[300px] shrink-0 space-y-3">
                    <div className="grid grid-cols-3 gap-2">
                        <FilterStat active={filter === "pending"} onClick={() => setFilter("pending")} value={counts.pending} label="Pending" />
                        <FilterStat active={filter === "verified"} onClick={() => setFilter("verified")} value={counts.verified} label="Verified" />
                        <FilterStat active={filter === "approved"} onClick={() => setFilter("approved")} value={counts.approved ?? 0} label="Approved" />
                    </div>

                    <div className="flex items-center justify-between px-1">
                        <span className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">Recently updated first</span>
                    </div>

                    <div className="flex items-center justify-between text-xs text-gray-600 px-1">
                        <span>{pagination.total ?? list.length} proposals · Page {pagination.current_page ?? 1} of {pagination.last_page ?? 1}</span>
                        <div className="flex gap-3">
                            {pagination.prev_url && <Link href={pagination.prev_url} preserveScroll className="underline">Previous</Link>}
                            {pagination.next_url && <Link href={pagination.next_url} preserveScroll className="underline">Next</Link>}
                        </div>
                    </div>
                    <div className="space-y-2 max-h-[calc(100vh-230px)] overflow-y-auto pr-1">
                        {list.length === 0 ? (
                            <div className="rounded-2xl border border-gray-100 bg-white p-8 text-center text-gray-400">
                                <ShieldCheck size={22} className="mx-auto mb-2 text-gray-300" />
                                <p className="text-[12px] font-medium">Nothing here</p>
                            </div>
                        ) : list.map((p) => (
                            <QueueCard key={p.id} p={p} active={p.id === activeId} onClick={() => setActiveId(p.id)} />
                        ))}
                    </div>
                </aside>

                {/* ── Main — selected proposal ─────────────────────────────── */}
                <main className="flex-1 min-w-0">
                    {active ? (
                        <ProposalPanel key={active.id} p={active} catalogue={programs} schools={schools} leadBase={leadBase} />
                    ) : (
                        <div className="rounded-2xl border border-gray-100 bg-white p-16 text-center text-gray-400">
                            <ShieldCheck size={26} className="mx-auto mb-2 text-gray-300" />
                            <p className="text-sm font-medium">Select a proposal to review</p>
                        </div>
                    )}
                </main>
            </div>
        </div>
    );
}

function FilterStat({ active, onClick, value, label }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-xl border px-3 py-2.5 text-left transition-colors ${
                active ? "bg-gray-900 border-gray-900 text-white" : "bg-white border-gray-200 text-gray-900 hover:border-gray-300"
            }`}
        >
            <div className="text-lg font-bold leading-none tabular-nums">{value}</div>
            <div className={`text-[9px] font-bold uppercase tracking-wider mt-1 ${active ? "text-gray-300" : "text-gray-400"}`}>{label}</div>
        </button>
    );
}

function QueueCard({ p, active, onClick }) {
    const noteTone = /transcript|duplicate|missing/i.test(p.note || "")
        ? "text-rose-600"
        : /ready/i.test(p.note || "") ? "text-emerald-600" : "text-amber-600";
    return (
        <button
            type="button"
            onClick={onClick}
            className={`w-full text-left rounded-xl border bg-white p-3 transition-all ${
                active ? "border-l-[3px] border-l-emerald-500 border-y-gray-100 border-r-gray-100 shadow-sm ring-1 ring-emerald-100" : "border-gray-100 hover:border-gray-200"
            }`}
        >
            <div className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                    <span className="text-[13px] font-bold text-gray-900 truncate">{p.name}</span>
                    {p.agent
                        ? <span className="text-[10px] font-semibold text-violet-700 ml-1.5">· {p.agent}</span>
                        : <span className="text-[10px] text-gray-400 font-mono ml-1.5">{p.lead_id}</span>}
                </div>
                <StatusPill status={p.status} small />
            </div>
            <div className="text-[11px] text-gray-500 mt-0.5">
                {p.programs_count} program{p.programs_count === 1 ? "" : "s"} · {timeAgo(p.submitted_at || p.verified_at || p.approved_at)}
            </div>
            {p.note && <div className={`text-[11px] font-medium mt-0.5 ${noteTone}`}>{p.note}</div>}
        </button>
    );
}

function StatusPill({ status, small }) {
    const map = {
        pending: { label: "Pending", cls: "bg-amber-50 text-amber-700 border-amber-200" },
        verified: { label: "Verified", cls: "bg-blue-50 text-blue-700 border-blue-200" },
        approved: { label: "Approved", cls: "bg-emerald-50 text-emerald-700 border-emerald-200" },
    };
    const m = map[status] || map.pending;
    return (
        <span className={`inline-flex items-center rounded-md font-bold uppercase tracking-wider border ${m.cls} ${small ? "px-1.5 py-0.5 text-[9px]" : "px-2 py-0.5 text-[10px]"}`}>
            {m.label}
        </span>
    );
}

// ── The selected proposal panel ─────────────────────────────────────────────
function ProposalPanel({ p, catalogue, schools, leadBase }) {
    const [tab, setTab] = useState("programs");
    const [busy, setBusy] = useState(null);
    const [adding, setAdding] = useState(false);
    const [requesting, setRequesting] = useState(false);

    const post = (url, data, opts = {}) => {
        setBusy(opts.key || url);
        router.post(url, data, { preserveScroll: true, ...opts, onFinish: () => setBusy(null) });
    };

    // ── Actions ──────────────────────────────────────────────────────────
    const metaUpdate = (metaMap, key) => post(`/program-verification/${p.id}/programs-meta`, { meta: metaMap }, { key });

    const toggleRowStatus = (row) => metaUpdate(
        { [row.id]: { status: row.p_status === "verified" ? "needs_check" : "verified" } }, `row-${row.id}`
    );

    const removeRow = (id) => {
        const remaining = p.programs.filter((x) => x.id !== id);
        if (!remaining.length) return;
        post(`/program-verification/${p.id}/programs`, {
            program_ids: remaining.map((x) => x.id),
            reasons: Object.fromEntries(remaining.filter((x) => x.reason).map((x) => [x.id, x.reason])),
        }, { key: `row-${id}` });
    };

    const addPrograms = (ids) => {
        const merged = [...new Set([...p.programs.map((x) => x.id), ...ids])].slice(0, 5);
        post(`/program-verification/${p.id}/programs`, {
            program_ids: merged,
            reasons: Object.fromEntries(p.programs.filter((x) => x.reason).map((x) => [x.id, x.reason])),
        }, { key: "add", onSuccess: () => setAdding(false) });
    };
    const approve = (opts) => post(`/program-verification/${p.id}/approve`, opts, { key: opts.verify_all ? "verify-all" : opts.send_email === false ? "approve-noemail" : "approve" });

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            {/* Header */}
            <div className="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4 flex-wrap">
                <div className="flex items-start gap-3 min-w-0">
                    <div className="w-10 h-10 rounded-full bg-gray-900 text-white flex items-center justify-center text-[12px] font-bold shrink-0">
                        {p.initials}
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h2 className="text-[16px] font-bold text-gray-900 truncate">{p.name}</h2>
                            <StatusPill status={p.status} />
                        </div>
                        <div className="text-[11px] text-gray-500 mt-0.5 flex items-center gap-x-3 gap-y-0.5 flex-wrap">
                            {p.agent
                                ? <span className="font-semibold text-violet-700">Agent · {p.agent}</span>
                                : <span className="font-mono">{p.lead_id}</span>}
                            {p.email && <span>{p.email}</span>}
                            <span>Submitted {fmtDateTime(p.submitted_at)}{p.submitted_by ? ` by ${p.submitted_by}` : ""}</span>
                        </div>
                        <Link href={`${leadBase}/leads/${p.id}`} className="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 mt-1">
                            View lead profile <ExternalLink size={11} />
                        </Link>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setRequesting(true)}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50"
                    >
                        Request changes
                    </button>
                    <button
                        type="button"
                        onClick={() => approve({ verify_all: true, send_email: true })}
                        disabled={busy === "verify-all" || p.status === "approved"}
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black disabled:opacity-40"
                    >
                        {busy === "verify-all" ? <Loader2 size={14} className="animate-spin" /> : <BadgeCheck size={14} />} Verify &amp; approve all
                    </button>
                </div>
            </div>

            {/* Changes-requested banner — visible regardless of the active tab. */}
            {p.changes_requested?.message && (
                <div className="mx-6 mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                    <div className="flex items-start gap-2">
                        <AlertCircle size={15} className="mt-0.5 shrink-0 text-rose-500" />
                        <div className="min-w-0">
                            <div className="text-[10px] font-bold uppercase tracking-wider text-rose-600">Changes requested</div>
                            <p className="text-[12.5px] text-gray-800 leading-snug mt-0.5 whitespace-pre-wrap">{p.changes_requested.message}</p>
                            {p.changes_requested.at && (
                                <div className="text-[10.5px] text-rose-700/80 mt-1">{fmtDateTime(p.changes_requested.at)}</div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Tabs */}
            <div className="px-6 border-b border-gray-100 flex items-center gap-1 mt-4">
                <Tab active={tab === "programs"} onClick={() => setTab("programs")}>Programs ({p.programs_count})</Tab>
                <Tab active={tab === "documents"} onClick={() => setTab("documents")}>Documents ({p.documents_count})</Tab>
                <Tab active={tab === "activity"} onClick={() => setTab("activity")}>Activity</Tab>
                <Tab active={tab === "preview"} onClick={() => setTab("preview")}>Client preview</Tab>
            </div>

            {tab === "programs" && (
                <div className="p-6 space-y-5">
                    {/* Table */}
                    <div className="overflow-x-auto -mx-2">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="text-[10px] font-bold uppercase tracking-wider text-gray-400 border-b border-gray-100">
                                    <th className="py-2 pl-2 pr-3">Programme &amp; school</th>
                                    <th className="py-2 px-3">Intake</th>
                                    <th className="py-2 px-3">Notes</th>
                                    <th className="py-2 px-3 text-right pr-2">Status</th>
                                    <th className="w-8 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {p.programs.map((row) => (
                                    <ProgramRow
                                        key={row.id} row={row} leadId={p.id}
                                        flaggedForChange={(p.changes_requested?.program_ids || []).includes(row.id)}
                                        onStatus={() => toggleRowStatus(row)}
                                        onSchool={(school) => metaUpdate({ [row.id]: { school } }, `row-${row.id}`)}
                                        onNote={(text) => metaUpdate({ [row.id]: { note: text } }, `row-${row.id}`)}
                                        onRemove={() => removeRow(row.id)}
                                        removable={p.programs.length > 1}
                                        schools={schools}
                                        busy={busy === `row-${row.id}`}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between text-[11px]">
                        <button type="button" onClick={() => setAdding(true)} className="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-900">
                            <Plus size={13} /> Add programme
                        </button>
                        <span className="text-gray-400">
                            Staff proposed {p.staff_proposed_count} · you edited {p.edited_count}
                        </span>
                    </div>
                </div>
            )}

            {tab === "documents" && <DocsTab p={p} leadBase={leadBase} />}
            {tab === "activity" && <ActivityTab p={p} />}
            {tab === "preview" && <PreviewTab p={p} />}

            {adding && (
                <AddProgramModal
                    catalogue={catalogue}
                    existing={p.programs.map((x) => x.id)}
                    onClose={() => setAdding(false)}
                    onAdd={addPrograms}
                    busy={busy === "add"}
                />
            )}
            {requesting && (
                <RequestChangesModal
                    programs={p.programs}
                    onClose={() => setRequesting(false)}
                    onSend={(message, programIds) => post(`/program-verification/${p.id}/request-changes`, { message, program_ids: programIds }, { key: "req", onSuccess: () => setRequesting(false) })}
                    busy={busy === "req"}
                />
            )}
        </div>
    );
}

function Tab({ active, onClick, children }) {
    return (
        <button type="button" onClick={onClick}
            className={`px-1 py-3 text-[13px] font-semibold border-b-2 -mb-px transition-colors ${
                active ? "border-gray-900 text-gray-900" : "border-transparent text-gray-400 hover:text-gray-700"
            } mr-5`}>
            {children}
        </button>
    );
}

function ProgramRow({ row, leadId, flaggedForChange, onStatus, onSchool, onNote, onRemove, removable, schools, busy }) {
    const [editingSchool, setEditingSchool] = useState(false);
    const verified = row.p_status === "verified";

    return (
        <tr className="align-top group">
            <td className="py-3 pl-2 pr-3">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-[13px] font-semibold text-gray-900">{row.title}</span>
                    {row.level != null && (
                        <span className="inline-flex items-center px-1 py-0.5 rounded text-[9px] font-bold uppercase bg-gray-100 text-gray-600">L{row.level}</span>
                    )}
                    {row.is_first_choice && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">First choice</span>
                    )}
                    {flaggedForChange && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-rose-50 text-rose-600 border border-rose-200">Revise</span>
                    )}
                </div>
                {/* School — editable, shown under the programme name (merged column). */}
                <div className="mt-1 text-[12px] text-gray-500">
                    {editingSchool ? (
                        <select autoFocus defaultValue={row.school || ""} onChange={(e) => { onSchool(e.target.value); setEditingSchool(false); }} onBlur={() => setEditingSchool(false)}
                            className="px-2 py-1 border border-gray-200 rounded-md text-[12px] bg-white focus:outline-none focus:border-gray-900">
                            <option value="">—</option>
                            {schools.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    ) : (
                        <button type="button" onClick={() => setEditingSchool(true)} className="inline-flex items-center gap-1 hover:text-gray-900 hover:underline">
                            <School size={11} className="text-gray-400 shrink-0" />
                            {row.school || <span className="text-gray-300">Assign school</span>}
                        </button>
                    )}
                </div>
            </td>
            <td className="py-3 px-3 text-[12px] text-gray-700">{row.intake || <span className="text-gray-300">—</span>}</td>
            <td className="py-3 px-3 max-w-[300px]">
                <ProgramNoteThread leadId={leadId} program={row} />
            </td>
            <td className="py-3 px-3 text-right">
                <button type="button" onClick={onStatus} disabled={busy}
                    className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border transition-colors disabled:opacity-50 ${
                        verified ? "bg-emerald-50 text-emerald-700 border-emerald-200" : "bg-rose-50 text-rose-600 border-rose-200"
                    }`}>
                    {busy ? <Loader2 size={11} className="animate-spin" /> : verified ? <Check size={11} /> : <AlertCircle size={11} />}
                    {verified ? "Verified" : "Needs check"}
                </button>
            </td>
            <td className="py-3 pr-2 text-right">
                {removable && (
                    <button type="button" onClick={onRemove} disabled={busy} title="Remove programme"
                        className="text-gray-300 hover:text-rose-600 opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50">
                        <Trash2 size={13} />
                    </button>
                )}
            </td>
        </tr>
    );
}

function DocsTab({ p, leadBase }) {
    return (
        <div className="p-6">
            <div className="rounded-xl border border-gray-100 p-8 text-center text-gray-500">
                <p className="text-[13px]">{p.documents_count} document{p.documents_count === 1 ? "" : "s"} on file for {p.name}.</p>
                <Link href={`${leadBase}/leads/${p.id}?tab=documents`} className="inline-flex items-center gap-1.5 mt-2 text-[12px] font-semibold text-indigo-600 hover:text-indigo-800">
                    Open documents <ExternalLink size={12} />
                </Link>
            </div>
        </div>
    );
}

function ActivityTab({ p }) {
    const items = [
        p.submitted_at && ["Submitted for verification", p.submitted_by, p.submitted_at],
        p.verified_at && ["Verified", p.verified_by, p.verified_at],
        p.approved_at && ["Approved", p.approved_by, p.approved_at],
    ].filter(Boolean);
    return (
        <div className="p-6 space-y-3">
            {p.changes_requested && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[12.5px] text-amber-800">
                    <span className="font-bold">Changes requested:</span> {p.changes_requested.message}
                </div>
            )}
            {items.length === 0 ? <p className="text-[12.5px] text-gray-400">No activity yet.</p> : items.map(([label, who, at], i) => (
                <div key={i} className="flex items-center gap-3 text-[12.5px]">
                    <ChevronRight size={13} className="text-gray-300" />
                    <span className="font-semibold text-gray-800">{label}</span>
                    <span className="text-gray-400">{who ? `by ${who} · ` : ""}{fmtDateTime(at)}</span>
                </div>
            ))}
        </div>
    );
}

function PreviewTab({ p }) {
    return (
        <div className="p-6">
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-3">What the client will see</p>
            <div className="space-y-2">
                {p.programs.map((row) => (
                    <div key={row.id} className="rounded-xl border border-gray-100 bg-gray-50/60 p-4">
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-emerald-100 text-emerald-700">Level {row.level}</span>
                            <GraduationCap size={13} className="text-gray-400" />
                            <span className="text-[14px] font-semibold text-gray-900">{row.title}</span>
                        </div>
                        <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-600">
                            {row.school && <span className="inline-flex items-center gap-1"><School size={11} className="text-gray-400" /> {row.school}</span>}
                            {row.intake && <span className="inline-flex items-center gap-1"><CalendarDays size={11} className="text-gray-400" /> {row.intake}</span>}
                            {row.fee > 0 && <span className="inline-flex items-center gap-1"><DollarSign size={11} className="text-gray-400" /> NZD {money(row.fee)}</span>}
                        </div>
                        {row.reason && <p className="mt-2 text-[12px] text-emerald-900 bg-emerald-50/70 border border-emerald-100 rounded-lg px-3 py-2">{row.reason}</p>}
                    </div>
                ))}
            </div>
        </div>
    );
}

// ── Modals ──────────────────────────────────────────────────────────────────
function AddProgramModal({ catalogue, existing, onClose, onAdd, busy }) {
    const [search, setSearch] = useState("");
    const [picked, setPicked] = useState([]);
    const room = 5 - existing.length;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return catalogue
            .filter((c) => !existing.includes(c.id))
            .filter((c) => !q || (c.title || "").toLowerCase().includes(q) || (c.school || "").toLowerCase().includes(q))
            .slice(0, 100);
    }, [catalogue, existing, search]);

    return (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-3" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-xl h-[80vh] flex flex-col overflow-hidden">
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 className="text-[15px] font-bold text-gray-900">Add programme</h3>
                        <p className="text-[11px] text-gray-500 mt-0.5">Up to {room} more can be added.</p>
                    </div>
                    <button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center"><X size={18} /></button>
                </div>
                <div className="p-4 border-b border-gray-100">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search title or school…"
                            className="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none focus:border-gray-900" />
                    </div>
                </div>
                <div className="flex-1 overflow-y-auto p-3 space-y-1.5">
                    {filtered.map((c) => {
                        const on = picked.includes(c.id);
                        const full = !on && picked.length >= room;
                        return (
                            <button key={c.id} type="button" disabled={full}
                                onClick={() => setPicked((prev) => on ? prev.filter((x) => x !== c.id) : [...prev, c.id])}
                                className={`w-full text-left px-3 py-2.5 rounded-lg border transition-colors ${on ? "border-gray-900 bg-gray-900 text-white" : full ? "border-gray-100 opacity-40 cursor-not-allowed" : "border-gray-200 hover:border-gray-400 hover:bg-gray-50"}`}>
                                <div className="flex items-center justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-[13px] font-semibold truncate">{c.title}</p>
                                        <p className={`text-[11px] ${on ? "text-gray-300" : "text-gray-500"}`}>L{c.level} · {c.school || "—"} · {c.intake || "—"}</p>
                                    </div>
                                    {on && <Check size={15} className="shrink-0" />}
                                </div>
                            </button>
                        );
                    })}
                </div>
                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-3">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button type="button" onClick={() => onAdd(picked)} disabled={busy || picked.length === 0}
                        className="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-gray-900 text-white text-sm font-bold hover:bg-black disabled:opacity-50">
                        {busy ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />} Add {picked.length || ""}
                    </button>
                </div>
            </div>
        </div>
    );
}

function RequestChangesModal({ programs = [], onClose, onSend, busy }) {
    const [msg, setMsg] = useState("");
    const [flagged, setFlagged] = useState([]); // program ids that need changes

    const toggle = (id) => setFlagged((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);

    return (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-3" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 className="text-[15px] font-bold text-gray-900">Request changes</h3>
                    <button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center"><X size={18} /></button>
                </div>
                <div className="p-5 space-y-4">
                    {/* Which proposed programs need changing */}
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-2">Programmes to revise</p>
                        <div className="space-y-1.5 max-h-52 overflow-y-auto">
                            {programs.map((row) => {
                                const on = flagged.includes(row.id);
                                return (
                                    <button key={row.id} type="button" onClick={() => toggle(row.id)}
                                        className={`w-full text-left px-3 py-2 rounded-lg border transition-colors flex items-start gap-2.5 ${on ? "border-rose-300 bg-rose-50" : "border-gray-200 hover:border-gray-300"}`}>
                                        <span className={`mt-0.5 w-4 h-4 rounded flex items-center justify-center shrink-0 border ${on ? "bg-rose-500 border-rose-500 text-white" : "border-gray-300"}`}>
                                            {on && <Check size={11} strokeWidth={3} />}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="text-[13px] font-semibold text-gray-900">{row.title}</span>
                                            {row.level != null && <span className="ml-1.5 text-[9px] font-bold uppercase text-gray-500">L{row.level}</span>}
                                            <span className="block text-[11px] text-gray-500">{row.school || "—"} · {row.intake || "—"}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <p className="text-[11px] text-gray-400 mt-1.5">
                            {flagged.length ? `${flagged.length} flagged for revision` : "Optional — tick the programmes that need changing."}
                        </p>
                    </div>

                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-2">Message</p>
                        <textarea rows={3} value={msg} onChange={(e) => setMsg(e.target.value)} placeholder="What needs revising? (the submitter sees this)"
                            className="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-gray-900 resize-y" />
                    </div>
                </div>
                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-3">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button type="button" onClick={() => onSend(msg, flagged)} disabled={busy}
                        className="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-gray-900 text-white text-sm font-bold hover:bg-black disabled:opacity-50">
                        {busy ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />} Send
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Threaded per-programme notes ─────────────────────────────────────────────
// The SAME thread the Proposals tab uses (leads.proposed_program_meta[<program>]
// .notes) — notes/replies added there show here and vice versa. Add / reply /
// acknowledge, collapsed to the latest note with a "N notes" expander.
function fmtNoteTime(iso) {
    if (! iso) return "";
    const d = new Date(iso);
    const now = new Date();
    const t = d.toLocaleTimeString("en-NZ", { hour: "2-digit", minute: "2-digit" });
    if (d.toDateString() === now.toDateString()) return `Today, ${t}`;
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return `Yesterday, ${t}`;
    return `${d.toLocaleDateString("en-NZ", { day: "2-digit", month: "short" })}, ${t}`;
}

function NoteTag({ tag }) {
    if (tag === "change_requested") {
        return <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 shrink-0">Change requested</span>;
    }
    return <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider bg-gray-100 text-gray-500 shrink-0">Note</span>;
}

function NoteComposer({ value, onChange, onSubmit, onCancel, placeholder, small }) {
    return (
        <div>
            <textarea autoFocus rows={small ? 2 : 2} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder}
                className="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-[11px] focus:outline-none focus:border-gray-900 resize-y" />
            <div className="flex items-center gap-2 mt-1">
                <button type="button" onClick={onSubmit} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-gray-900 text-white text-[11px] font-bold hover:bg-black">
                    <Send size={11} /> Post
                </button>
                <button type="button" onClick={onCancel} className="text-[11px] font-semibold text-gray-500 hover:text-gray-800">Cancel</button>
            </div>
        </div>
    );
}

function ProgramNoteThread({ leadId, program }) {
    const notes = program.notes || [];
    const vnote = (program.note || "").trim();
    const ordered = [...notes].reverse();
    const [expanded, setExpanded] = useState(false);
    const [adding, setAdding] = useState(false);
    const [body, setBody] = useState("");
    const [replyTo, setReplyTo] = useState(null);
    const [replyBody, setReplyBody] = useState("");
    const base = `/admin/leads/${leadId}/program-notes/${program.id}`;
    const send = (url, data, done) => router.post(url, data, { preserveScroll: true, onSuccess: done });
    const addNote = () => { if (! body.trim()) return; send(base, { body, tag: "note" }, () => { setBody(""); setAdding(false); }); };
    const reply = (id) => { if (! replyBody.trim()) return; send(`${base}/${id}/reply`, { body: replyBody }, () => { setReplyBody(""); setReplyTo(null); }); };
    const toggle = (id) => send(`${base}/${id}/actioned`, {});

    const verificationCard = vnote ? (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
            <div className="flex items-start gap-1.5">
                <Flag size={12} className="mt-0.5 shrink-0 text-amber-500" />
                <span className="text-[12px] font-medium leading-snug text-gray-800 [overflow-wrap:anywhere] whitespace-pre-wrap">{vnote}</span>
            </div>
        </div>
    ) : null;

    if (notes.length === 0) {
        return (
            <div className="space-y-2">
                {verificationCard}
                {! verificationCard && ! adding && (
                    <div className="rounded-lg border border-dashed border-gray-200 px-3 py-2">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[11px] text-gray-400 italic">No note on this program</span>
                            <button type="button" onClick={() => setAdding(true)} className="text-[11px] font-semibold text-emerald-700 hover:text-emerald-900">Add note</button>
                        </div>
                    </div>
                )}
                {verificationCard && ! adding && (
                    <button type="button" onClick={() => setAdding(true)} className="text-[11px] font-semibold text-emerald-700 hover:text-emerald-900">Add note</button>
                )}
                {adding && <NoteComposer value={body} onChange={setBody} onSubmit={addNote} onCancel={() => { setBody(""); setAdding(false); }} placeholder="Add a note on this programme…" />}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {verificationCard}
            {! expanded ? (
                (() => {
                    const n = ordered[0];
                    const done = !! n.actioned_at;
                    const cr = n.tag === "change_requested";
                    return (
                        <div className={`rounded-lg border px-3 py-2 ${cr ? "border-rose-200 bg-rose-50" : "border-amber-200 bg-amber-50"}`}>
                            <div className="flex items-start gap-1.5">
                                <Flag size={12} className={`mt-0.5 shrink-0 ${cr ? "text-rose-500" : "text-amber-500"}`} />
                                <span className={`text-[12px] font-medium leading-snug [overflow-wrap:anywhere] whitespace-pre-wrap ${done ? "line-through text-gray-400" : "text-gray-800"}`}>{n.body}</span>
                            </div>
                            <div className={`text-[10px] mt-1 ml-[18px] ${cr ? "text-rose-700/80" : "text-amber-700/80"}`}>
                                {n.author} · {fmtNoteTime(n.created_at)}
                                {n.replies.length ? <span className="text-gray-400"> · {n.replies.length} repl{n.replies.length === 1 ? "y" : "ies"}</span> : null}
                            </div>
                        </div>
                    );
                })()
            ) : (
                ordered.map((n) => {
                    const done = !! n.actioned_at;
                    const cr = n.tag === "change_requested";
                    return (
                        <div key={n.id} className="text-[11px]">
                            <div className="flex items-center gap-1.5">
                                <NoteTag tag={n.tag} />
                                <span className="font-bold text-gray-800 truncate">{n.author}</span>
                                <span className="text-gray-400 whitespace-nowrap">{fmtNoteTime(n.created_at)}</span>
                            </div>
                            <p className={`mt-1 text-gray-700 leading-snug [overflow-wrap:anywhere] whitespace-pre-wrap ${done ? "line-through text-gray-400" : ""}`}>{n.body}</p>
                            {n.replies.map((rp) => (
                                <div key={rp.id} className="mt-1.5 ml-3 border-l-2 border-gray-100 pl-2.5">
                                    <span className="font-semibold text-gray-700">{rp.author}</span>
                                    <span className="text-gray-400"> · {fmtNoteTime(rp.created_at)}</span>
                                    <p className="text-gray-600 leading-snug [overflow-wrap:anywhere] whitespace-pre-wrap">{rp.body}</p>
                                </div>
                            ))}
                            <div className="mt-1.5 flex items-center gap-3 font-semibold">
                                <button type="button" onClick={() => toggle(n.id)} className={done ? "text-gray-400 hover:text-gray-600" : "text-emerald-700 hover:text-emerald-900"}>
                                    {done ? "Actioned ✓" : (cr ? "Mark as actioned" : "Acknowledge")}
                                </button>
                                <button type="button" onClick={() => { setReplyTo(replyTo === n.id ? null : n.id); setReplyBody(""); }} className="text-gray-500 hover:text-gray-800">Reply</button>
                            </div>
                            {replyTo === n.id && (
                                <div className="mt-1.5">
                                    <NoteComposer value={replyBody} onChange={setReplyBody} onSubmit={() => reply(n.id)} onCancel={() => { setReplyBody(""); setReplyTo(null); }} placeholder="Write a reply…" small />
                                </div>
                            )}
                        </div>
                    );
                })
            )}

            <div className="flex items-center gap-3">
                <button type="button" onClick={() => setExpanded((v) => ! v)} className="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-500 hover:text-gray-800">
                    {expanded ? "Show less" : `${notes.length} note${notes.length === 1 ? "" : "s"}`}
                    <ChevronRight size={12} className={`transition-transform ${expanded ? "rotate-90" : ""}`} />
                </button>
                {! adding && <button type="button" onClick={() => setAdding(true)} className="text-[11px] font-semibold text-emerald-700 hover:text-emerald-900">Add note</button>}
            </div>

            {adding && <NoteComposer value={body} onChange={setBody} onSubmit={addNote} onCancel={() => { setBody(""); setAdding(false); }} placeholder="Add a note on this programme…" />}
        </div>
    );
}
