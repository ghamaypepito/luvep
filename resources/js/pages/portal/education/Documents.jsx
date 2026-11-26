import { useEffect, useMemo, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    AlertTriangle, CheckCircle2, ChevronRight, Clock, FileText,
    Filter, FolderOpen, Hash, Inbox, Search, XCircle, Download, Eye,
} from "lucide-react";

const BUCKET_META = {
    pending:  {
        label: "Pending my review",
        icon:  <Clock size={13} />,
        iconBg: "bg-amber-100 text-amber-700",
        header: "text-amber-700",
        cardRing: "ring-amber-100",
    },
    stale: {
        label: "Under review longer than 7 days",
        icon:  <AlertTriangle size={13} />,
        iconBg: "bg-orange-100 text-orange-700",
        header: "text-orange-700",
        cardRing: "ring-orange-100",
    },
    rejected: {
        label: "Recently rejected — awaiting resubmission",
        icon:  <XCircle size={13} />,
        iconBg: "bg-rose-100 text-rose-700",
        header: "text-rose-700",
        cardRing: "ring-rose-100",
    },
};

const fmtRel = (iso) => {
    if (! iso) return "—";
    const d    = new Date(iso);
    const mins = Math.floor((Date.now() - d.getTime()) / 60000);
    if (mins < 1)    return "just now";
    if (mins < 60)   return `${mins} min ago`;
    if (mins < 1440) return `${Math.floor(mins / 60)} hr${mins >= 120 ? "s" : ""} ago`;
    return `${Math.floor(mins / 1440)} day${mins >= 2880 ? "s" : ""} ago`;
};

// Per-student avatar palette (stable per id) for the queue cards.
const AVATAR_PALETTE = [
    "bg-blue-500", "bg-pink-500", "bg-orange-500", "bg-teal-500",
    "bg-purple-500", "bg-amber-500", "bg-emerald-500", "bg-rose-500",
    "bg-indigo-500", "bg-fuchsia-500",
];
const avatarColor = (key) => {
    const str = String(key || "");
    let hash = 0;
    for (let i = 0; i < str.length; i++) hash = ((hash << 5) - hash) + str.charCodeAt(i);
    return AVATAR_PALETTE[Math.abs(hash) % AVATAR_PALETTE.length];
};
const initials = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase();

export default function EducationDocuments({
    pending = [], stale = [], rejected = [], folders = [], folderFilters = null, folderPagination = null,
}) {
    const url = usePage().url || "";
    const [view, setView] = useState(new URLSearchParams(url.split("?")[1]).get("view") === "folders" ? "folders" : "queue"); // queue | folders
    const totalAttention  = pending.length + stale.length + rejected.length;

    // Derive the portal base from the URL so Immigration can re-export
    // this page and the lead-detail links land in the right portal.
    const portalBase = url.startsWith("/portal/immigration") ? "/portal/immigration" : "/portal/education";

    return (
        <div className="space-y-5 pb-12">
            <Head title="Documents — Education" />

            {/* Header */}
            <div className="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Documents</h1>
                    <p className="text-sm text-gray-500 mt-1.5">
                        {view === "queue"
                            ? `What needs your attention across all students — ${totalAttention} item${totalAttention === 1 ? "" : "s"}.`
                            : `Folder per student. Click a folder to see their categorised documents.`}
                    </p>
                </div>

                {/* View toggle */}
                <div className="inline-flex items-center bg-white rounded-xl border border-gray-200 p-1">
                    <button
                        type="button"
                        onClick={() => setView("queue")}
                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wider transition-colors ${
                            view === "queue" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                        }`}
                    >
                        <Inbox size={12} /> Queue
                    </button>
                    <button
                        type="button"
                        onClick={() => setView("folders")}
                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wider transition-colors ${
                            view === "folders" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                        }`}
                    >
                        <FolderOpen size={12} /> Folders
                    </button>
                </div>
            </div>

            {view === "queue"
                ? <QueueView pending={pending} stale={stale} rejected={rejected} portalBase={portalBase} />
                : <FoldersView folders={folders} portalBase={portalBase} filters={folderFilters} pagination={folderPagination} />
            }
        </div>
    );
}

// ── Queue view ─────────────────────────────────────────────────────────────

function QueueView({ pending, stale, rejected, portalBase = "/portal/education" }) {
    const [search, setSearch] = useState("");
    const [activeTab, setActiveTab] = useState(() => {
        // Auto-pick the first non-empty bucket so the page opens on
        // something useful instead of an empty tab.
        if (pending.length  > 0) return "pending";
        if (stale.length    > 0) return "stale";
        if (rejected.length > 0) return "rejected";
        return "pending";
    });

    const filterRows = (rows) => {
        const q = search.trim().toLowerCase();
        if (! q) return rows;
        return rows.filter((d) =>
            (d.lead?.name    || "").toLowerCase().includes(q) ||
            (d.original_name || "").toLowerCase().includes(q) ||
            (d.lead?.lead_id || "").toLowerCase().includes(q) ||
            (d.checklist_key || "").toLowerCase().includes(q)
        );
    };

    const bucketRows = { pending, stale, rejected };
    const filteredRows = filterRows(bucketRows[activeTab] || []);
    const allEmpty = pending.length + stale.length + rejected.length === 0;

    const TABS = [
        { key: "pending",  label: "Pending review",    count: pending.length,  icon: <Clock        size={13} />, tone: "amber"  },
        { key: "stale",    label: "Over 7 days",       count: stale.length,    icon: <AlertTriangle size={13} />, tone: "orange" },
        { key: "rejected", label: "Rejected recently", count: rejected.length, icon: <XCircle     size={13} />, tone: "rose"   },
    ];

    return (
        <div className="space-y-4">
            {/* Tabs */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <div className="flex items-center border-b border-gray-100 px-2 overflow-x-auto">
                    {TABS.map((t) => (
                        <TabButton
                            key={t.key}
                            tab={t}
                            active={activeTab === t.key}
                            onClick={() => setActiveTab(t.key)}
                        />
                    ))}
                </div>

                {/* Search inside the same surface as the tabs */}
                <div className="flex items-center gap-2 px-4 py-2.5 border-b border-gray-100">
                    <Search size={14} className="text-gray-400" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search by student, filename, LP-ID, or checklist key…"
                        className="flex-1 outline-none text-sm placeholder:text-gray-400 bg-transparent"
                    />
                    {search && (
                        <button
                            type="button"
                            onClick={() => setSearch("")}
                            className="text-gray-400 hover:text-gray-700 text-[11px] font-bold uppercase tracking-wider"
                        >
                            Clear
                        </button>
                    )}
                </div>

                {/* Body */}
                <div className="p-4">
                    {allEmpty ? (
                        <div className="py-10 text-center">
                            <div className="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 mb-3">
                                <CheckCircle2 size={22} />
                            </div>
                            <p className="text-base font-semibold text-gray-800">All caught up</p>
                            <p className="text-sm text-gray-500 mt-1">No documents currently need your attention.</p>
                        </div>
                    ) : filteredRows.length === 0 ? (
                        <div className="py-10 text-center text-gray-400">
                            <Inbox size={26} className="mx-auto mb-3 text-gray-300" />
                            <p className="text-sm font-medium">
                                {search
                                    ? "Nothing matches your search in this tab."
                                    : "Nothing in this tab right now."}
                            </p>
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch("")}
                                    className="mt-3 text-[11px] font-bold uppercase tracking-wider text-gray-600 hover:text-gray-900"
                                >
                                    Clear search
                                </button>
                            )}
                        </div>
                    ) : (
                        <QueueBucket bucketKey={activeTab} rows={filteredRows} portalBase={portalBase} />
                    )}
                </div>
            </div>
        </div>
    );
}

function TabButton({ tab, active, onClick }) {
    const TONES = {
        amber:  { ring: "border-amber-500",  badgeActive: "bg-amber-500"  },
        orange: { ring: "border-orange-500", badgeActive: "bg-orange-500" },
        rose:   { ring: "border-rose-500",   badgeActive: "bg-rose-500"   },
    };
    const t = TONES[tab.tone] || TONES.amber;
    return (
        <button
            type="button"
            onClick={onClick}
            className={`px-4 py-3 text-xs font-bold uppercase tracking-wider transition-colors border-b-2 inline-flex items-center gap-2 whitespace-nowrap ${
                active
                    ? `text-gray-900 ${t.ring}`
                    : "text-gray-400 border-transparent hover:text-gray-700"
            }`}
        >
            {tab.icon}
            {tab.label}
            {tab.count > 0 && (
                <span className={`inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[9px] font-bold tabular-nums ${
                    active ? `${t.badgeActive} text-white` : "bg-gray-100 text-gray-600"
                }`}>
                    {tab.count}
                </span>
            )}
        </button>
    );
}

function QueueBucket({ bucketKey, rows, portalBase }) {
    const meta = BUCKET_META[bucketKey];

    // Group documents by lead — one card per student with all their docs
    // listed inside. Preserves the original order of leads as they first
    // appear in the bucket.
    const groups = [];
    const byLeadId = new Map();
    for (const d of rows) {
        const lid = d.lead?.id ?? `unknown-${d.id}`;
        if (! byLeadId.has(lid)) {
            const g = { leadId: d.lead?.id, lead: d.lead, docs: [] };
            byLeadId.set(lid, g);
            groups.push(g);
        }
        byLeadId.get(lid).docs.push(d);
    }

    return (
        <section>
            <div className="flex items-center gap-2.5 mb-2 px-1">
                <span className={`inline-flex items-center justify-center w-6 h-6 rounded ${meta.iconBg}`}>
                    {meta.icon}
                </span>
                <h3 className={`text-[11px] font-bold uppercase tracking-[0.18em] ${meta.header}`}>
                    {meta.label}
                </h3>
                <span className="ml-auto text-[10px] font-bold uppercase tracking-widest text-gray-400 tabular-nums">
                    {rows.length} doc{rows.length === 1 ? "" : "s"} · {groups.length} student{groups.length === 1 ? "" : "s"}
                </span>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                {groups.map((g) => (
                    <LeadDocumentsCard
                        key={g.leadId ?? g.docs[0].id}
                        lead={g.lead}
                        leadId={g.leadId}
                        docs={g.docs}
                        meta={meta}
                        portalBase={portalBase}
                    />
                ))}
            </div>
        </section>
    );
}

function LeadDocumentsCard({ lead, leadId, docs, meta, portalBase }) {
    const leadHref = leadId
        ? `${portalBase}/leads/${leadId}?tab=documents`
        : null;

    return (
        <div className={`bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-col ring-1 ${meta.cardRing}`}>
            {/* Lead header */}
            <div className="flex items-start gap-3">
                <span className={`w-9 h-9 rounded-full inline-flex items-center justify-center text-white text-[11px] font-bold flex-shrink-0 ${avatarColor(leadId)}`}>
                    {initials(lead?.name || "")}
                </span>
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-gray-900 truncate">
                        {lead?.name || "Unknown"}
                    </p>
                    {lead?.lead_id && (
                        <p className="text-[10px] text-gray-500 font-mono mt-0.5 inline-flex items-center gap-0.5">
                            <Hash size={9} />{lead.lead_id}
                        </p>
                    )}
                </div>
                <span className="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full bg-gray-100 text-[10px] font-bold tabular-nums text-gray-700">
                    {docs.length}
                </span>
            </div>

            {/* Document list */}
            <ul className="mt-3 divide-y divide-gray-50 border-t border-gray-50">
                {docs.map((d) => (
                    <DocRow key={d.id} doc={d} leadId={leadId} portalBase={portalBase} />
                ))}
            </ul>

            {/* Footer — open the whole lead */}
            {leadHref && (
                <Link
                    href={leadHref}
                    className="mt-3 pt-3 border-t border-gray-50 flex items-center justify-end text-[10px] font-bold uppercase tracking-wider text-gray-400 hover:text-gray-900 transition-colors"
                >
                    Open lead
                    <ChevronRight size={11} className="ml-0.5" />
                </Link>
            )}
        </div>
    );
}

function DocRow({ doc: d, leadId, portalBase }) {
    const viewHref = leadId
        ? `${portalBase}/leads/${leadId}?tab=documents&doc=${d.id}`
        : null;
    const timeLabel = d.bucket === "rejected"
        ? `rejected ${fmtRel(d.reviewed_at)}`
        : `uploaded ${fmtRel(d.created_at)}`;

    return (
        <li className="py-2.5 flex items-start gap-2.5 hover:bg-gray-50/40 rounded-md -mx-1 px-1">
            <FileText size={13} className="text-gray-400 mt-0.5 flex-shrink-0" />

            <div className="flex-1 min-w-0">
                <p className="text-[12.5px] font-medium text-gray-700 break-words leading-snug">
                    {d.original_name}
                </p>
                <div className="mt-1 flex items-center gap-2 flex-wrap text-[10.5px] text-gray-500">
                    {d.checklist_key && (
                        <span className="font-mono bg-gray-50 border border-gray-100 px-1.5 py-0.5 rounded text-gray-600">
                            {d.checklist_key}
                        </span>
                    )}
                    <span>{timeLabel}</span>
                </div>
                {d.note && (
                    <p className="mt-1.5 text-[11px] italic text-rose-700 bg-rose-50 border border-rose-100 rounded-md px-2 py-1 leading-snug">
                        "{d.note}"
                    </p>
                )}
            </div>

            {/* Actions: View (navigate) + Download */}
            <div className="flex items-center gap-1 flex-shrink-0">
                {viewHref && (
                    <Link
                        href={viewHref}
                        title="View document"
                        className="inline-flex items-center justify-center w-8 h-8 rounded-md text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                    >
                        <Eye size={13} />
                    </Link>
                )}
                <a
                    href={`/admin/documents/${d.id}/download`}
                    title="Download file"
                    className="inline-flex items-center justify-center w-8 h-8 rounded-md text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                >
                    <Download size={13} />
                </a>
            </div>
        </li>
    );
}

function StatTile({ label, value, icon, tone = "amber", active = false, onClick }) {
    const TONES = {
        amber:  { bg: "bg-amber-50",  glyph: "bg-amber-100 text-amber-700",   num: "text-amber-900",  ring: "ring-amber-400"  },
        orange: { bg: "bg-orange-50", glyph: "bg-orange-100 text-orange-700", num: "text-orange-900", ring: "ring-orange-400" },
        rose:   { bg: "bg-rose-50",   glyph: "bg-rose-100 text-rose-700",     num: "text-rose-900",   ring: "ring-rose-400"   },
    };
    const t = TONES[tone] || TONES.amber;
    return (
        <button
            type="button"
            onClick={onClick}
            className={`${t.bg} rounded-2xl p-4 flex items-center gap-3 text-left transition-all hover:shadow-sm ${
                active ? `ring-2 ${t.ring} shadow-md` : "shadow-sm"
            }`}
        >
            <div className={`w-11 h-11 rounded-xl flex items-center justify-center ${t.glyph}`}>{icon}</div>
            <div className="min-w-0">
                <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-500">{label}</p>
                <p className={`text-2xl font-bold tabular-nums ${t.num}`}>{value}</p>
            </div>
        </button>
    );
}

// ── Folders view (unchanged) ───────────────────────────────────────────────

function FoldersView({ folders, portalBase = "/portal/education", filters = null, pagination = null }) {
    const [search, setSearch] = useState(filters?.search || "");
    const [statusFilter, setStatusFilter] = useState(filters?.status || "All");
    useEffect(() => { setSearch(filters?.search || ""); setStatusFilter(filters?.status || "All"); }, [filters?.search, filters?.status]);
    const visit = (status = statusFilter) => router.get(`${portalBase}/documents`, {
        view: 'folders', search, folder_status: status,
    }, { preserveState: true, preserveScroll: true });

    const filtered = useMemo(() => {
        if (pagination) return folders;
        const q = search.toLowerCase().trim();
        return folders.filter((f) => {
            const hay = `${f.name || ""} ${f.lead_id || ""}`.toLowerCase();
            const matchSearch = ! q || hay.includes(q);
            const matchStatus =
                statusFilter === "All" ||
                (statusFilter === "Needs attention" && (f.pending > 0 || f.rejected > 0)) ||
                (statusFilter === "Complete"        && f.total > 0 && f.approved === f.total) ||
                (statusFilter === "In progress"     && f.total > 0 && f.approved < f.total && f.pending === 0 && f.rejected === 0);
            return matchSearch && matchStatus;
        });
    }, [folders, search, statusFilter, pagination]);

    return (
        <div className="space-y-4">
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <form onSubmit={(event) => { event.preventDefault(); if (pagination) visit(); }} className="relative w-full lg:w-80 flex gap-2">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label="Search students"
                        placeholder="Search students…"
                        className="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm placeholder-gray-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    />
                    {pagination && <button type="submit" className="px-3 py-1.5 rounded-lg text-[11px] font-bold border border-gray-200">Search</button>}
                </form>
                <div className="flex items-center gap-2 flex-wrap">
                    <Filter size={12} className="text-gray-400" />
                    {["All", "Needs attention", "In progress", "Complete"].map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => { setStatusFilter(s); if (pagination) visit(s); }}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold border transition-all ${
                                statusFilter === s ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
                            }`}
                        >
                            {s}
                        </button>
                    ))}
                </div>
            </div>

            {pagination && <nav aria-label="Student folder pages" className="flex items-center justify-between text-sm text-gray-600">
                <span>{pagination.from || 0}–{pagination.to || 0} of {pagination.total} folders</span>
                <div className="flex gap-3">
                    {pagination.previous && <Link href={pagination.previous} preserveState preserveScroll>Previous</Link>}
                    {pagination.next && <Link href={pagination.next} preserveState preserveScroll>Next</Link>}
                </div>
            </nav>}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                {filtered.length === 0 ? (
                    <div className="p-14 text-center text-gray-400">
                        <FolderOpen size={28} className="mx-auto mb-3 text-gray-300" />
                        <p className="text-sm font-medium">No students match your filters.</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {filtered.map((f) => (
                            <FolderRow key={f.id} folder={f} portalBase={portalBase} />
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function FolderRow({ folder, portalBase = "/portal/education" }) {
    const pct = folder.total > 0 ? Math.round((folder.approved / folder.total) * 100) : 0;
    const needsAttention = folder.pending > 0 || folder.rejected > 0;

    return (
        <Link
            href={`${portalBase}/leads/${folder.id}?tab=documents`}
            className="block px-5 py-4 hover:bg-gray-50/50 group"
        >
            <div className="flex items-center gap-4 flex-wrap">
                <div className="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0">
                    <FolderOpen size={18} />
                </div>

                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <p className="text-sm font-semibold text-gray-900">{folder.name}</p>
                        <span className="text-[10px] font-mono text-gray-400">{folder.lead_id}</span>
                        {needsAttention && (
                            <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-rose-100 text-rose-700 border border-rose-200">
                                <AlertTriangle size={9} /> Needs attention
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-3 mt-1.5 text-[11px] text-gray-500">
                        <span className="tabular-nums">{folder.approved} / {folder.total} approved</span>
                        {folder.pending > 0  && <span className="text-amber-700">{folder.pending} pending</span>}
                        {folder.rejected > 0 && <span className="text-rose-700">{folder.rejected} rejected</span>}
                    </div>
                </div>

                <div className="w-40 hidden sm:block">
                    <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            className={`h-full rounded-full transition-all ${pct === 100 ? "bg-emerald-500" : "bg-indigo-500"}`}
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                    <p className="text-[10px] font-bold text-gray-500 tabular-nums mt-1 text-right">{pct}%</p>
                </div>

                <ChevronRight size={16} className="text-gray-300 group-hover:text-gray-600 transition-colors" />
            </div>
        </Link>
    );
}
