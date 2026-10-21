import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import NoteProgramPicker, { NotePrograms } from '@/components/leads/NoteProgramPicker';
import BulkEmailModal from "@/components/sales/BulkEmailModal";
import Avatar from "@/components/ui/Avatar";
import { getJson } from "@/lib/http";
import {
    DndContext, DragOverlay, KeyboardSensor, PointerSensor,
    useDraggable, useDroppable, useSensor, useSensors,
} from "@dnd-kit/core";
import { CSS } from "@dnd-kit/utilities";
import {
    Search, KeyRound, Clock, Check, Mail, ShieldOff, FileText, Phone, Copy,
    Filter, ArrowUpDown, ArrowUp, ArrowDown, ChevronLeft, ChevronRight,
    MoreHorizontal, ChevronDown, ChevronRight as ChevronRightIcon, ExternalLink, UserCheck,
    Upload, Loader, Plus, X, CalendarClock, Link2, FileText as FileTextIcon,
    Pencil, StickyNote, Calendar, MapPin, Users, Eye, Trash2, Paperclip,
} from "lucide-react";
import { AvatarPhoto } from "@/components/ui/Avatar";
import CaseFilesModal from "@/components/immigration/CaseFilesModal";
import { priorityRing, priorityRank } from "@/utils/priority";

// ── Stage colour map ───────────────────────────────────────────────────────
// One palette entry per pipeline stage. Tones intentionally match the colour
// chips in the source pipeline diagram. Anything not listed falls through to
// the neutral grey style.

const STAGE_STYLES = {
    "New Leads":                      "bg-rose-100 text-rose-800 border-rose-200",
    "Contact Attempted":              "bg-orange-100 text-orange-800 border-orange-200",
    "Contacted for Booking":          "bg-yellow-100 text-yellow-800 border-yellow-200",
    "Booking Confirmation":"bg-cyan-100 text-cyan-800 border-cyan-200",
    "Missed the Meeting":             "bg-pink-100 text-pink-800 border-pink-200",
    "Qualified but Not Ready":        "bg-slate-100 text-slate-700 border-slate-200",
    "Qualified but No Funds":         "bg-slate-100 text-slate-700 border-slate-200",
    "Qualified":                      "bg-amber-100 text-amber-800 border-amber-200",
    "Booked Consultation":            "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Did Not Book Consultation":      "bg-stone-100 text-stone-700 border-stone-200",
    "No Show":                        "bg-teal-100 text-teal-800 border-teal-200",
    "Consultation Done":              "bg-purple-100 text-purple-800 border-purple-200",
    "Proposal Sent":                  "bg-sky-100 text-sky-800 border-sky-200",
    "Program Selected":               "bg-teal-100 text-teal-800 border-teal-200",
    "Consultancy Agreement Sent":     "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Consultancy Agreement Signed":   "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Consultancy Agreement":          "bg-indigo-100 text-indigo-800 border-indigo-200",
    "English Pro":                    "bg-emerald-50 text-emerald-700 border-emerald-200",
    "School Enrollment":              "bg-green-100 text-green-800 border-green-200",
    "Visa Process":                   "bg-lime-100 text-lime-800 border-lime-200",
    "Not Qualified":                  "bg-red-100 text-red-700 border-red-200",
    "Work Pathway / Other":           "bg-blue-100 text-blue-800 border-blue-200",
    // Legacy fallback values from the old enum
    "New":        "bg-rose-100 text-rose-800 border-rose-200",
    "Contacted":  "bg-orange-100 text-orange-800 border-orange-200",
    "Processing": "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Closed":     "bg-green-100 text-green-800 border-green-200",
};
export const stageClass = (s) => STAGE_STYLES[s] || "bg-gray-100 text-gray-700 border-gray-200";

// Hex tones for the StagePicker dropdown's colour dot — inline `style`
// instead of a `bg-xxx-500` class so Tailwind's JIT purge can't drop a
// dynamically-built class name. Indexed by tailwind colour family name.
const STAGE_DOT_HEX = {
    sky:     "#0ea5e9", amber:  "#f59e0b", emerald: "#10b981",
    indigo:  "#6366f1", violet: "#8b5cf6", orange:  "#f97316",
    cyan:    "#06b6d4", red:    "#ef4444", purple:  "#a855f7",
    fuchsia: "#d946ef", pink:   "#ec4899", teal:    "#14b8a6",
    rose:    "#f43f5e", slate:  "#64748b", blue:    "#3b82f6",
    yellow:  "#eab308", lime:   "#84cc16", stone:   "#78716c",
    gray:    "#9ca3af",
};

// Pull the colour family from any token in the styler's class string
// (e.g. "bg-cyan-100 text-cyan-800 border-cyan-200" → "cyan").
const stageDotHex = (styler, stage) => {
    const cls = (typeof styler === "function" ? styler(stage) : "") || "";
    const m = cls.match(/(?:text|bg|border)-([a-z]+)-\d+/);
    return STAGE_DOT_HEX[m?.[1]] || "#9ca3af";
};

// Goal-setting status colours — kept in sync with LeadDetails.jsx so the
// pill reads the same wherever it appears.
const GOAL_STATUS_STYLE = {
    "Consultation Done": "bg-purple-100 text-purple-800 border-purple-200",
    "For Proposal":      "bg-amber-100 text-amber-800 border-amber-200",
    "Proposal Sent":     "bg-sky-100 text-sky-800 border-sky-200",
    "No Show":           "bg-red-100 text-red-700 border-red-200",
};

const PORTAL_BADGE = {
    none:     null,
    pending:  { label: "Awaiting admin",  chip: "bg-amber-100 text-amber-700 border-amber-200",       icon: Clock },
    sent:     { label: "Invitation sent", chip: "bg-blue-100 text-blue-700 border-blue-200",          icon: Mail },
    accepted: { label: "Portal active",   chip: "bg-emerald-100 text-emerald-700 border-emerald-200", icon: Check },
    revoked:  { label: "Revoked",         chip: "bg-gray-100 text-gray-500 border-gray-200",          icon: ShieldOff },
};

// Presence dot for the avatar — green = recently online, gray = has portal
// but not active, blue = invitation sent (not accepted yet). Returns null
// when the lead has no portal interaction at all.
const ONLINE_THRESHOLD_MS = 5 * 60 * 1000; // 5 minutes
const presenceDot = (status, lastLoginAt) => {
    if (status === "accepted") {
        const last = lastLoginAt ? new Date(lastLoginAt).getTime() : 0;
        const online = last && Date.now() - last <= ONLINE_THRESHOLD_MS;
        return online
            ? { color: "bg-emerald-500", label: "Online" }
            : { color: "bg-gray-400",    label: "Offline" };
    }
    if (status === "sent")    return { color: "bg-blue-500",  label: "Invitation sent" };
    if (status === "pending") return { color: "bg-amber-500", label: "Awaiting admin approval" };
    return null;
};

// Deterministic avatar colour per lead.
const AVATAR_PALETTE = [
    "bg-purple-100 text-purple-700",
    "bg-blue-100 text-blue-700",
    "bg-emerald-100 text-emerald-700",
    "bg-amber-100 text-amber-700",
    "bg-rose-100 text-rose-700",
    "bg-cyan-100 text-cyan-700",
    "bg-indigo-100 text-indigo-700",
    "bg-fuchsia-100 text-fuchsia-700",
];
const avatarColor = (id) => AVATAR_PALETTE[(Number(id) || 0) % AVATAR_PALETTE.length];
export const initials = (name) =>
    (name || "")
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join("")
        .toUpperCase() || "—";

export const fmtDateShort = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-US", { day: "numeric", month: "short", year: "numeric" }) : "—";
export const fmtTime = (iso) =>
    iso ? new Date(iso).toLocaleTimeString("en-NZ", { hour: "2-digit", minute: "2-digit" }) : "";

// General lead priority — mirrors the Cases board (urgent | medium | low).
// null / "" renders as an unset "Set" placeholder.
const PRIORITY_OPTIONS = [
    { value: "urgent", label: "Urgent", dot: "bg-red-500",    pill: "bg-red-50 text-red-700 border-red-200" },
    { value: "medium", label: "Medium", dot: "bg-amber-500",  pill: "bg-amber-50 text-amber-700 border-amber-200" },
    { value: "low",    label: "Low",    dot: "bg-emerald-500",pill: "bg-emerald-50 text-emerald-700 border-emerald-200" },
];
export const priorityMeta = (p) => PRIORITY_OPTIONS.find((o) => o.value === p) || null;
// Coloured dot per priority; unset shows light gray.
export const priorityDot = (p) => priorityMeta(p)?.dot || "bg-gray-300";
// Avatar ring + sort weight live in @/utils/priority so Leads, Students and
// Cases can't drift apart. Re-exported because AgentLeads / EventRegistrants
// already import them from here.
export { priorityRing, priorityRank };

const PAGE_SIZE = 20;

// ── Component ──────────────────────────────────────────────────────────────

// Each portal hits its own /portal/{role}/leads endpoints (status update,
// portal-invitation request). Default to sales for backwards compat when the
// prop is missing.
const PORTAL_LABEL = { sales: "Sales", education: "Education", immigration: "Immigration", admin: "Admin" };

export default function SalesLeads({ leads = [], statuses = [], programs = [], staffOptions = [], events = [], allTagNames = [], tabCounts = {}, agents = [], visaOptions = [], portal = "sales", portalBase: portalBaseProp }) {
    // Department portals live at /portal/{role}; the admin screen reuses this
    // exact component but is served from /admin, so it passes its own base.
    const portalBase = portalBaseProp || `/portal/${portal}`;
    const portalLabel = PORTAL_LABEL[portal] || "Sales";
    // Sales, Education, and Immigration can all request portal invitations
    // for a lead; admin still approves before the invite email is sent (and
    // manages them from the dedicated Portal Invitations screen), so admin
    // does not surface the per-row "request access" action here.
    const canRequestInvite = ["sales", "education", "immigration"].includes(portal);
    // Bulk assign / delete / import act on an ARRAY of lead ids in the request
    // body, which the lead.scope middleware cannot check — it only guards the id
    // in the URL. Until those endpoints scope their own payload, they stay with
    // sales; everything per-lead (stage, visa, notes, kanban) is scoped and works
    // in the recruiting portals too.
    const canBulkManage = portal !== "agent";
    // The recruiting AGENT column (and the Agents tab) is a sales/education
    // concept — immigration and the agent portal itself don't surface it.
    // Kept in step with the Agents-tab visibility below.
    const showAgentColumn = ["sales", "admin", "education"].includes(portal);

    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("All");
    // Active tab is mirrored in the URL (?tab=) so navigating into a lead and
    // pressing Back returns to the same tab instead of Open opportunities.
    const VALID_TABS = ["table", "kanban", "events", "registration", "agents"];
    const [view, _setView] = useState(() => {
        if (typeof window === "undefined") return "table";
        const t = new URLSearchParams(window.location.search).get("tab");
        return VALID_TABS.includes(t) ? t : "table";
    });
    const setView = (v) => {
        _setView(v);
        if (typeof window !== "undefined") {
            const url = new URL(window.location.href);
            if (v === "table") url.searchParams.delete("tab");
            else url.searchParams.set("tab", v);
            window.history.replaceState(window.history.state, "", url);
        }
    };
    const [savingId, setSavingId] = useState(null);
    const [selectedIds, setSelectedIds] = useState(new Set());
    const [bulkOpen, setBulkOpen] = useState(false);
    // Assign-agent + delete are also bulk actions — same selection set,
    // different destinations. Confirm dialog for delete since it's terminal.
    const [bulkAgentOpen, setBulkAgentOpen] = useState(false);
    const [bulkDeleteConfirm, setBulkDeleteConfirm] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);

    const runBulkAssignAgent = (agentId) => {
        setBulkBusy(true);
        router.post(`${portalBase}/leads/bulk-agent`, {
            lead_ids: [...selectedIds],
            agent_id: agentId || null,
        }, {
            preserveScroll: true,
            onFinish: () => {
                setBulkBusy(false);
                setBulkAgentOpen(false);
                setSelectedIds(new Set());
            },
        });
    };

    const runBulkDelete = () => {
        setBulkBusy(true);
        router.post(`${portalBase}/leads/bulk-delete`, {
            lead_ids: [...selectedIds],
        }, {
            preserveScroll: true,
            onFinish: () => {
                setBulkBusy(false);
                setBulkDeleteConfirm(false);
                setSelectedIds(new Set());
            },
        });
    };
    // Default sort — most-recently-touched first. `updated_at` bumps
    // whenever any field on the row changes (stage, note attach, priority,
    // manual edit, etc.), so it's the closest single-column proxy for
    // "current activity". Priority still leads the tie-break (see the
    // comparator below) so urgent/medium leads stay pinned above no-
    // priority rows in the same time band.
    const [sortKey, setSortKey] = useState("updated_at");
    const [sortDir, setSortDir] = useState("desc");
    const [page, setPage] = useState(1);
    const [openStageMenuId, setOpenStageMenuId] = useState(null);
    const [openRowMenuId, setOpenRowMenuId] = useState(null);
    const [editingLead, setEditingLead] = useState(null);
    const [filesLead, setFilesLead] = useState(null);   // lead whose file history is open
    const [expandedId, setExpandedId] = useState(null);
    const [showFilters, setShowFilters] = useState(false);
    const EMPTY_ADV = { goal_status: "", pre_screened_by: "", program: "", portal: "", tag: "" };
    const [adv, setAdv] = useState(EMPTY_ADV);
    const activeAdvCount = Object.values(adv).filter(Boolean).length + (statusFilter !== "All" ? 1 : 0);
    const clearAdv = () => { setAdv(EMPTY_ADV); setStatusFilter("All"); setPage(1); };
    const setAdvFilter = (k) => (e) => { setAdv((a) => ({ ...a, [k]: e.target.value })); setPage(1); };

    const toggleExpand = (id) => setExpandedId(expandedId === id ? null : id);

    const filtered = useMemo(() => {
        const q = search.toLowerCase().trim();
        const rows = leads.filter((l) => {
            // The Registration tab shows only leads that came in through the
            // public /register form.
            if (view === "registration" && l.source_key !== "registration") return false;
            const hay = `${l.name || ""} ${l.email || ""} ${l.lead_id || ""} ${l.phone || ""} ${l.source || ""}`.toLowerCase();
            const matchSearch  = !q || hay.includes(q);
            const matchStatus  = statusFilter === "All" || l.status === statusFilter;
            const matchGoal    = !adv.goal_status || l.goal_setting_status === adv.goal_status;
            const matchPre     = !adv.pre_screened_by || l.pre_screened_by === adv.pre_screened_by;
            const matchProgram = !adv.program || l.program_offered === adv.program;
            const matchPortal  = !adv.portal || (l.portal_invitation_status || "none") === adv.portal;
            const matchTag     = !adv.tag || (l.tags || []).includes(adv.tag);
            return matchSearch && matchStatus && matchGoal && matchPre && matchProgram && matchPortal && matchTag;
        });

        const dir = sortDir === "asc" ? 1 : -1;
        // When sorting by pipeline activity, fall back to `updated_at` for
        // rows that have never had a stage move — otherwise brand-new
        // leads (null stage_updated_at) all pile up at the bottom.
        const readSortValue = (row) => {
            if (sortKey === "stage_updated_at") return row.stage_updated_at || row.updated_at || "";
            return row[sortKey] ?? "";
        };
        return [...rows].sort((a, b) => {
            // Priority always leads the ordering: urgent → medium → low →
            // no-priority (matches the Cases board). The chosen column only
            // breaks ties within the same priority band.
            const pr = priorityRank(a.priority) - priorityRank(b.priority);
            if (pr !== 0) return pr;

            const av = readSortValue(a);
            const bv = readSortValue(b);
            if (av < bv) return -1 * dir;
            if (av > bv) return 1 * dir;
            return 0;
        });
    }, [leads, search, statusFilter, adv, sortKey, sortDir, view]);

    // Tag filter options — union of the full dictionary from the server
    // (`allTagNames`) and any tags attached to leads currently in the list.
    // Using the dictionary means a tag stays selectable even if it's only
    // been attached to a lead that's been converted out of this pipeline.
    const tagOptions = useMemo(() => {
        const set = new Set();
        for (const t of allTagNames) if (t) set.add(t);
        for (const l of leads) for (const t of (l.tags || [])) set.add(t);
        return [...set].sort((a, b) => a.localeCompare(b));
    }, [leads, allTagNames]);

    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const safePage = Math.min(page, totalPages);
    const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

    const changeStatus = (lead, status) => {
        setOpenStageMenuId(null);
        if (status === lead.status) return;
        setSavingId(lead.id);
        router.post(`${portalBase}/leads/${lead.id}`, { status }, {
            preserveScroll: true,
            onFinish: () => setSavingId(null),
        });
    };

    // Inline "Visa applying for" change from the leads table. Posts only the
    // visa string (interest/intent — not an immigration case link). No-op when
    // unchanged.
    const changeVisa = (lead, visa) => {
        const next = visa || null;
        if ((lead.visa || null) === next) return;
        setSavingId(lead.id);
        router.post(`${portalBase}/leads/${lead.id}/visa`, { inz_visa_type: next }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSavingId(null),
        });
    };

    const requestPortal = (lead) => {
        if (!confirm(`Request Lead Portal access for ${lead.name}? Admin will review and approve.`)) return;
        setSavingId(lead.id);
        router.post(`${portalBase}/leads/${lead.id}/portal-invitation/request`, {}, {
            preserveScroll: true,
            onFinish: () => setSavingId(null),
        });
    };

    const toggleSort = (key) => {
        if (sortKey === key) setSortDir(sortDir === "asc" ? "desc" : "asc");
        else { setSortKey(key); setSortDir("desc"); }
    };

    const toggleSelect = (id) => {
        const next = new Set(selectedIds);
        next.has(id) ? next.delete(id) : next.add(id);
        setSelectedIds(next);
    };
    const toggleSelectAll = () => {
        if (selectedIds.size === paged.length && paged.length > 0) setSelectedIds(new Set());
        else setSelectedIds(new Set(paged.map((l) => l.id)));
    };

    return (
        <div className="space-y-4 max-w-[1600px] mx-auto">
            <Head title={`Leads — ${portalLabel} Portal`} />

            <div className="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Leads</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Pipeline · {filtered.length} {filtered.length === 1 ? "opportunity" : "opportunities"}
                    </p>
                </div>
                {/* Immigration (manager + adviser) works cases converted from
                    sales — it can't add or import leads directly, so the add
                    cluster is hidden there. */}
                {! ["immigration", "immigration-adviser"].includes(portal) && (
                    <div className="flex items-center gap-2">
                        <AddLeadButton portalBase={portalBase} statuses={statuses} programs={programs} staffOptions={staffOptions} agents={agents} />
                        {canBulkManage && <ImportLeadsButton />}
                    </div>
                )}
            </div>

            {/* Toolbar */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <div className="flex items-center border-b border-gray-100 px-4 sm:px-5">
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => setView("table")}
                            className={`px-3 py-3 text-xs font-bold transition-colors -mb-px ${
                                view === "table"
                                    ? "text-gray-900 border-b-2 border-gray-900"
                                    : "text-gray-400 border-b-2 border-transparent hover:text-gray-700"
                            }`}
                        >
                            Open opportunities
                        </button>
                        <button
                            type="button"
                            onClick={() => setView("kanban")}
                            className={`px-3 py-3 text-xs font-bold transition-colors -mb-px ${
                                view === "kanban"
                                    ? "text-gray-900 border-b-2 border-gray-900"
                                    : "text-gray-400 border-b-2 border-transparent hover:text-gray-700"
                            }`}
                        >
                            Kanban
                        </button>
                        {/* Events tab — only portals whose controller feeds `events`
                            + exposes /events/{id}/registrations (sales, immigration, education). */}
                        {(portal === "sales" || portal === "immigration" || portal === "education" || portal === "admin") && (
                            <button
                                type="button"
                                onClick={() => setView("events")}
                                className={`inline-flex items-center gap-1.5 px-3 py-3 text-xs font-bold transition-colors -mb-px ${
                                    view === "events"
                                        ? "text-gray-900 border-b-2 border-gray-900"
                                        : "text-gray-400 border-b-2 border-transparent hover:text-gray-700"
                                }`}
                            >
                                Events
                                <TabBadge count={tabCounts.events} />
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => { setView("registration"); setPage(1); }}
                            className={`inline-flex items-center gap-1.5 px-3 py-3 text-xs font-bold transition-colors -mb-px ${
                                view === "registration"
                                    ? "text-gray-900 border-b-2 border-gray-900"
                                    : "text-gray-400 border-b-2 border-transparent hover:text-gray-700"
                            }`}
                        >
                            Registration
                            <TabBadge count={tabCounts.registration} />
                        </button>
                        {/* Agents tab — recruiting-agent roster + their leads.
                            Sales, education portals + the admin leads screen. */}
                        {(portal === "sales" || portal === "admin" || portal === "education") && (
                            <button
                                type="button"
                                onClick={() => { setView("agents"); setPage(1); }}
                                className={`inline-flex items-center gap-1.5 px-3 py-3 text-xs font-bold transition-colors -mb-px ${
                                    view === "agents"
                                        ? "text-gray-900 border-b-2 border-gray-900"
                                        : "text-gray-400 border-b-2 border-transparent hover:text-gray-700"
                                }`}
                            >
                                Agents
                                <TabBadge count={agents.length} />
                            </button>
                        )}
                    </div>
                </div>

                {view !== "events" && view !== "agents" && (
                <div className="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between p-4 sm:p-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex flex-wrap gap-1.5 max-w-3xl">
                            <button
                                type="button"
                                onClick={() => { setStatusFilter("All"); setPage(1); }}
                                className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all border ${
                                    statusFilter === "All"
                                        ? "bg-gray-900 text-white border-gray-900"
                                        : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
                                }`}
                            >
                                All
                            </button>
                            {/* Surface 6 most pipeline-critical filters; the rest are reachable via the per-row dropdown */}
                            {["New Leads", "Contact Attempted", "Qualified", "Booked Consultation", "Consultancy Agreement", "Visa Process"].map((s) => (
                                <button
                                    key={s}
                                    type="button"
                                    onClick={() => { setStatusFilter(s); setPage(1); }}
                                    className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all border ${
                                        statusFilter === s
                                            ? "bg-gray-900 text-white border-gray-900"
                                            : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
                                    }`}
                                >
                                    {s}
                                </button>
                            ))}
                        </div>
                        <FiltersPopover
                            open={showFilters}
                            onToggle={() => setShowFilters((o) => !o)}
                            onClose={() => setShowFilters(false)}
                            activeCount={activeAdvCount}
                            adv={adv}
                            setAdvFilter={setAdvFilter}
                            clearAdv={clearAdv}
                            statuses={statuses}
                            statusFilter={statusFilter}
                            onStatusChange={(v) => { setStatusFilter(v); setPage(1); }}
                            programs={programs}
                            staffOptions={staffOptions}
                            tagOptions={tagOptions}
                        />
                        <button
                            type="button"
                            onClick={() => toggleSort(sortKey)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold text-gray-600 bg-white border border-gray-200 hover:bg-gray-50"
                        >
                            <ArrowUpDown size={12} />
                            Sort: {sortDir === "asc" ? "Oldest" : "Newest"}
                        </button>
                    </div>

                    <div className="flex items-center gap-2">
                        <div className="relative w-full sm:w-72">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                                placeholder="Search Opportunities"
                                className="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-200 bg-white text-xs placeholder-gray-400 focus:outline-none focus:border-gray-400 transition-all"
                            />
                        </div>
                    </div>
                </div>
                )}
            </div>

            {/* Bulk actions bar — shown above the table only when at least
                one lead is checked. Actions apply to whatever's currently in
                `selectedIds`, so they respect the current filter/page state. */}
            {(view === "table" || view === "registration") && selectedIds.size > 0 && (
                <BulkActionsBar
                    count={selectedIds.size}
                    onClear={() => setSelectedIds(new Set())}
                    onEmail={() => setBulkOpen(true)}
                    onAssignAgent={canBulkManage ? () => setBulkAgentOpen(true) : null}
                    onDelete={canBulkManage ? () => setBulkDeleteConfirm(true) : null}
                />
            )}

            {/* Table — shared by the Open opportunities + Registration tabs */}
            {(view === "table" || view === "registration") && (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-visible">
                <div className="overflow-x-auto overflow-y-visible">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                <th className="pl-4 pr-2 py-3 w-8">
                                    <input
                                        type="checkbox"
                                        checked={paged.length > 0 && selectedIds.size === paged.length}
                                        onChange={toggleSelectAll}
                                        className="w-3.5 h-3.5 rounded border-gray-300 text-gray-900 focus:ring-gray-400 cursor-pointer"
                                    />
                                </th>
                                {/* Expand toggle — narrow, no label. */}
                                <th className="pr-2 py-3 w-6" />
                                <SortableTh label="Lead" sortKey="name" current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Stage" sortKey="status" current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <th className="px-3 py-3">Contact</th>
                                <th className="px-3 py-3">Location</th>
                                {showAgentColumn && <th className="px-3 py-3">Agent</th>}
                                <th className="px-3 py-3">Docs</th>
                                <th className="px-3 py-3 w-[160px]">Visa</th>
                                <SortableTh label="Updated" sortKey="updated_at" current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <th className="px-3 py-3 text-right pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {paged.length === 0 ? (
                                <tr>
                                    <td colSpan={showAgentColumn ? 11 : 10} className="px-6 py-20 text-center">
                                        <div className="flex flex-col items-center gap-2 text-gray-400">
                                            <Search size={22} />
                                            <p className="text-sm font-medium">No leads match your filters</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : paged.map((l) => {
                                const portalStatus = l.portal_invitation_status || "none";
                                const badge = PORTAL_BADGE[portalStatus];
                                const presence = presenceDot(portalStatus, l.portal_last_login_at);
                                const isSaving = savingId === l.id;
                                const isSelected = selectedIds.has(l.id);
                                const goalChipClass = l.goal_setting_status && GOAL_STATUS_STYLE[l.goal_setting_status]
                                    ? GOAL_STATUS_STYLE[l.goal_setting_status]
                                    : "bg-gray-100 text-gray-600 border-gray-200";

                                const isExpanded = expandedId === l.id;

                                return (
                                    <React.Fragment key={l.id}>
                                    <tr
                                        className={`group transition-colors ${
                                            isExpanded ? "bg-blue-50/30" : isSelected ? "bg-amber-50/30" : "hover:bg-gray-50/50"
                                        }`}
                                    >
                                        <td className="pl-4 pr-2 py-2.5">
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                onChange={() => toggleSelect(l.id)}
                                                className="w-3.5 h-3.5 rounded border-gray-300 text-gray-900 focus:ring-gray-400 cursor-pointer"
                                            />
                                        </td>

                                        {/* Expand toggle — chevron rotates when open. */}
                                        <td className="pr-2 py-2.5">
                                            <button
                                                type="button"
                                                onClick={() => toggleExpand(l.id)}
                                                className="inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                                                title={isExpanded ? "Collapse details" : "Expand details"}
                                            >
                                                <ChevronRightIcon
                                                    size={14}
                                                    className={`transition-transform ${isExpanded ? "rotate-90" : ""}`}
                                                />
                                            </button>
                                        </td>

                                        <td className="px-3 py-2.5">
                                            <Link
                                                href={`${portalBase}/leads/${l.id}`}
                                                className="flex items-center gap-2.5 min-w-[180px] group/lead"
                                            >
                                                <div className="relative flex-shrink-0">
                                                    {/* Avatar circle doubles as the priority indicator (same as the
                                                        Cases board): tinted by priority when set, neutral hash colour
                                                        otherwise. */}
                                                    <div
                                                        className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold overflow-hidden ${
                                                            l.avatar_url ? `ring-2 ring-offset-1 ${priorityRing(l.priority)}` : priorityMeta(l.priority) ? `${priorityMeta(l.priority).dot} text-white` : "bg-gray-200 text-gray-500"
                                                        }`}
                                                        title={priorityMeta(l.priority) ? `Priority: ${priorityMeta(l.priority).label}` : "No priority set"}
                                                    >
                                                        <AvatarPhoto src={l.avatar_url} title={l.name}>
                                                            {initials(l.name)}
                                                        </AvatarPhoto>
                                                    </div>
                                                    {/* Presence dot — green online, gray has-portal-offline,
                                                        blue invitation-sent. Nothing when lead has no portal. */}
                                                    {presence && (
                                                        <span
                                                            className={`absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-white ${presence.color}`}
                                                            title={`Lead Portal: ${presence.label}`}
                                                        />
                                                    )}
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="font-semibold text-gray-900 text-xs truncate group-hover/lead:text-blue-600 transition-colors">
                                                        {l.name}
                                                    </div>
                                                    {l.lead_id && (
                                                        <div className="text-[10px] text-gray-400 font-mono truncate">{l.lead_id}</div>
                                                    )}
                                                </div>
                                            </Link>
                                        </td>

                                        {/* STAGE — clickable pill, opens stage picker */}
                                        <td className="px-3 py-2.5 relative">
                                            <StagePicker
                                                lead={l}
                                                stages={statuses}
                                                open={openStageMenuId === l.id}
                                                onToggle={() => setOpenStageMenuId(openStageMenuId === l.id ? null : l.id)}
                                                onClose={() => setOpenStageMenuId(null)}
                                                onSelect={(stage) => changeStatus(l, stage)}
                                                isSaving={isSaving}
                                            />
                                        </td>

                                        {/* CONTACT — email + phone merged into one column */}
                                        <td className="px-3 py-2.5">
                                            <div className="flex flex-col gap-0.5 min-w-0">
                                                <div className="inline-flex items-center gap-1.5 text-gray-600">
                                                    <Mail size={11} className="text-gray-300 flex-shrink-0" />
                                                    <span className="truncate max-w-[180px]">{l.email || "—"}</span>
                                                </div>
                                                {l.phone ? (
                                                    <div className="inline-flex items-center gap-1.5 text-gray-500 whitespace-nowrap">
                                                        <Phone size={11} className="text-gray-300 flex-shrink-0" />
                                                        <span>{l.phone}</span>
                                                    </div>
                                                ) : null}
                                            </div>
                                        </td>

                                        {/* LOCATION — city highlighted on top,
                                            country underneath. Falls back to
                                            country-only when the lead never
                                            told us their city. */}
                                        <td className="px-3 py-2.5">
                                            {l.residence_city || l.residence_country || l.country ? (
                                                <div className="flex items-start gap-1.5">
                                                    <MapPin size={11} className="text-gray-300 flex-shrink-0 mt-0.5" />
                                                    <div className="min-w-0">
                                                        {l.residence_city && (
                                                            <div className="font-semibold text-gray-900 truncate max-w-[140px]">{l.residence_city}</div>
                                                        )}
                                                        {(l.residence_country || l.country) && (
                                                            <div className="text-[11px] text-gray-500 truncate max-w-[140px]">{l.residence_country || l.country}</div>
                                                        )}
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-gray-300">—</span>
                                            )}
                                        </td>

                                        {/* AGENT — recruiting agent who added this lead (null
                                            for staff-added leads). Hidden in portals that
                                            don't deal with recruiting agents (immigration). */}
                                        {showAgentColumn && (
                                            <td className="px-3 py-2.5">
                                                {l.agent ? (
                                                    <div className="inline-flex items-center gap-1.5 text-gray-700" title={`Recruited by ${l.agent.name}`}>
                                                        <Avatar name={l.agent.name} src={l.agent.avatar_url} colorKey={l.agent.id} size={20} />
                                                        <span className="truncate max-w-[120px] font-medium">{l.agent.name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>
                                        )}

                                        {/* DOCS — % of the visible checklist this lead has
                                            submitted. Mirrors the immigration Cases column
                                            so the two tables read the same. `checklist_total`
                                            already excludes items staff hid from the tracker
                                            for this lead. */}
                                        <td className="px-3 py-2.5">
                                            {l.checklist_total > 0 ? (
                                                <div className="flex items-center gap-2 min-w-[100px]" title={`${l.checklist_submitted} of ${l.checklist_total} checklist items submitted`}>
                                                    <div className="h-1.5 flex-1 rounded-full bg-gray-100 overflow-hidden">
                                                        <div
                                                            className="h-full rounded-full bg-emerald-500"
                                                            style={{ width: `${Math.round((l.checklist_submitted / l.checklist_total) * 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-xs font-semibold tabular-nums whitespace-nowrap text-emerald-600">
                                                        {Math.round((l.checklist_submitted / l.checklist_total) * 100)}%
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-gray-300">—</span>
                                            )}
                                        </td>

                                        {/* VISA — the applicant's selected "Visa applying for"
                                            (interest/intent). Inline dropdown of the active
                                            visa catalogue; saving updates leads.inz_visa_type.
                                            Does NOT drive the tracker's document checklist —
                                            education/general leads always get the 16-doc set. */}
                                        <td className="px-3 py-2.5 w-[160px]">
                                            <VisaCell lead={l} options={visaOptions} onChange={changeVisa} disabled={isSaving} />
                                        </td>

                                        {/* UPDATED — the last staff activity on this lead:
                                            when, who, and what they changed. */}
                                        <td className="px-3 py-2.5 whitespace-nowrap">
                                            <div className="text-gray-600">
                                                {fmtDateShort(l.updated_at)}
                                                <span className="text-gray-400"> · {fmtTime(l.updated_at)}</span>
                                            </div>
                                            {l.updated_by && (
                                                <div className="text-[10px] text-gray-400">{l.updated_by}</div>
                                            )}
                                            {l.updated_desc && (
                                                <div className="text-[10px] text-gray-500 truncate max-w-[170px]" title={l.updated_desc}>
                                                    {l.updated_desc}
                                                </div>
                                            )}
                                        </td>

                                        <td className="px-3 py-2.5 pr-4 text-right">
                                            <div className="inline-flex items-center gap-1">
                                                {/* Registration snapshot — only for leads that came in via
                                                    /register. Reads the form-as-filled from lead + JSON. */}
                                                {view === "registration" && (
                                                    <Link
                                                        href={`${portalBase}/leads/${l.id}/registration`}
                                                        title="View registration form"
                                                        className="inline-flex items-center justify-center w-7 h-7 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                                                    >
                                                        <Eye size={12} />
                                                    </Link>
                                                )}
                                                <RowMenu
                                                    lead={l}
                                                    open={openRowMenuId === l.id}
                                                    onToggle={() => setOpenRowMenuId(openRowMenuId === l.id ? null : l.id)}
                                                    onClose={() => setOpenRowMenuId(null)}
                                                    onEdit={() => { setOpenRowMenuId(null); setEditingLead(l); }}
                                                    onRequestPortal={() => { setOpenRowMenuId(null); requestPortal(l); }}
                                                    onViewFiles={() => { setOpenRowMenuId(null); setFilesLead(l); }}
                                                    isSaving={isSaving}
                                                    portalBase={portalBase}
                                                    canRequestInvite={canRequestInvite}
                                                />
                                            </div>
                                        </td>
                                    </tr>

                                    {/* Expander row — all 9 dashboard-mirror fields
                                        live here so the table itself stays compact. */}
                                    {isExpanded && (
                                        <tr className="bg-blue-50/20 border-t border-blue-100/60">
                                            <td colSpan={showAgentColumn ? 11 : 10} className="px-6 py-4">
                                                <LeadDashboardPanel lead={l} goalChipClass={goalChipClass} portalBase={portalBase} staffOptions={staffOptions} />
                                            </td>
                                        </tr>
                                    )}
                                    </React.Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {filtered.length > 0 && (
                    <div className="border-t border-gray-100 px-4 sm:px-5 py-3 flex items-center justify-between text-xs text-gray-500">
                        <div className="flex items-center gap-3">
                            <span>Page {safePage} of {totalPages}</span>
                            {selectedIds.size > 0 && (
                                <>
                                    <span className="text-gray-900 font-semibold">· {selectedIds.size} selected</span>
                                    <button
                                        type="button"
                                        onClick={() => setBulkOpen(true)}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white font-semibold hover:bg-black"
                                    >
                                        <Mail size={13} /> Email selected
                                    </button>
                                </>
                            )}
                        </div>
                        <div className="flex items-center gap-1.5">
                            <span className="text-gray-400 mr-2">{PAGE_SIZE} / page</span>
                            <button
                                type="button"
                                disabled={safePage <= 1}
                                onClick={() => setPage(safePage - 1)}
                                className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md border border-gray-200 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed font-semibold"
                            >
                                <ChevronLeft size={12} /> Previous
                            </button>
                            <button
                                type="button"
                                disabled={safePage >= totalPages}
                                onClick={() => setPage(safePage + 1)}
                                className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md border border-gray-200 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed font-semibold"
                            >
                                Next <ChevronRight size={12} />
                            </button>
                        </div>
                    </div>
                )}
            </div>
            )}

            {view === "kanban" && (
                <LeadsKanban filtered={filtered} statuses={statuses} portalBase={portalBase} />
            )}

            {view === "events" && (
                <EventsTab events={events} portalBase={portalBase} statuses={statuses} />
            )}

            {view === "agents" && (
                <AgentsTab agents={agents} portalBase={portalBase} />
            )}

            <BulkEmailModal open={bulkOpen} onClose={() => setBulkOpen(false)} leadIds={[...selectedIds]} />

            <BulkAgentModal
                open={bulkAgentOpen}
                onClose={() => setBulkAgentOpen(false)}
                agents={agents}
                count={selectedIds.size}
                busy={bulkBusy}
                onApply={runBulkAssignAgent}
            />

            <BulkDeleteConfirm
                open={bulkDeleteConfirm}
                onClose={() => setBulkDeleteConfirm(false)}
                count={selectedIds.size}
                busy={bulkBusy}
                onConfirm={runBulkDelete}
            />

            {editingLead && (
                <EditLeadModal
                    lead={editingLead}
                    portalBase={portalBase}
                    statuses={statuses}
                    staffOptions={staffOptions}
                    agents={agents}
                    onClose={() => setEditingLead(null)}
                />
            )}

            {filesLead && (
                <CaseFilesModal
                    leadId={filesLead.id}
                    leadName={filesLead.name}
                    onClose={() => setFilesLead(null)}
                />
            )}
        </div>
    );
}

// ── Agents tab — recruiting-agent roster as a flat table. Each row shows
//    the agent, their contact + location, the number of leads they've added,
//    and a "View leads" action that opens the agent's leads on its own page
//    (styled like Open opportunities). Mirrors the Events tab layout so the
//    two roster tabs read the same. ────────────────────────────────────────

// ── Bulk actions bar — appears above the leads table whenever at least
//    one lead checkbox is selected. Delegates the actual work to callbacks
//    on the parent (open modals, fire router.post, etc.). ─────────────────
// A null handler means the caller's portal cannot perform that action, so the
// button is omitted rather than rendered inert.
function BulkActionsBar({ count, onClear, onEmail, onAssignAgent, onDelete }) {
    return (
        <div className="flex items-center justify-between gap-3 px-4 py-2.5 bg-gray-900 text-white rounded-2xl shadow-sm">
            <div className="flex items-center gap-3">
                <span className="text-xs font-semibold">
                    {count} lead{count === 1 ? '' : 's'} selected
                </span>
                <button
                    type="button"
                    onClick={onClear}
                    className="text-[11px] text-gray-300 hover:text-white underline underline-offset-2"
                >
                    Clear
                </button>
            </div>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={onEmail}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-xs font-semibold transition-colors"
                    title="Send email to selected leads"
                >
                    <Mail size={13} /> Email
                </button>
                {onAssignAgent && (
                    <button
                        type="button"
                        onClick={onAssignAgent}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-xs font-semibold transition-colors"
                        title="Set or edit the recruiting agent on selected leads"
                    >
                        <UserCheck size={13} /> Set / edit agent
                    </button>
                )}
                {onDelete && (
                    <button
                        type="button"
                        onClick={onDelete}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-500/90 hover:bg-red-500 text-xs font-semibold transition-colors"
                        title="Delete selected leads"
                    >
                        <Trash2 size={13} /> Delete
                    </button>
                )}
            </div>
        </div>
    );
}

// ── Bulk agent picker — assigns (or clears) a recruiting agent for every
//    lead in the current selection. Only sales-role users manage this. ──
function BulkAgentModal({ open, onClose, agents = [], count, busy, onApply }) {
    const [agentId, setAgentId] = useState('');
    useEffect(() => { if (open) setAgentId(''); }, [open]);

    if (! open) return null;
    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl w-full max-w-md shadow-2xl">
                <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 className="text-base font-bold text-gray-900">Set recruiting agent</h3>
                        <p className="text-xs text-gray-500 mt-0.5">Applies to {count} lead{count === 1 ? '' : 's'}.</p>
                    </div>
                    <button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center">
                        <X size={16} />
                    </button>
                </div>
                <div className="p-5 space-y-3">
                    <label className="block text-[11px] font-bold uppercase tracking-widest text-gray-500">Agent</label>
                    <select
                        value={agentId}
                        onChange={(e) => setAgentId(e.target.value)}
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-gray-900"
                    >
                        <option value="">— No agent (clear) —</option>
                        {agents.map((a) => (
                            <option key={a.id} value={a.id}>{a.name}</option>
                        ))}
                    </select>
                    <p className="text-[11px] text-gray-500">Leaving "No agent" applies to all selected — it clears any existing agent assignment.</p>
                </div>
                <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => onApply(agentId)}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl text-sm font-bold hover:bg-black disabled:opacity-60"
                    >
                        {busy ? <Loader size={14} className="animate-spin" /> : <Check size={14} />}
                        Apply
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Delete confirm — soft-deletes the selected leads. Confirm because it's
//    a terminal action even though rows are recoverable in the admin trash.
function BulkDeleteConfirm({ open, onClose, count, busy, onConfirm }) {
    if (! open) return null;
    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl w-full max-w-md shadow-2xl">
                <div className="p-6">
                    <div className="flex items-start gap-3">
                        <div className="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0">
                            <Trash2 size={18} />
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-base font-bold text-gray-900">Delete {count} lead{count === 1 ? '' : 's'}?</h3>
                            <p className="text-xs text-gray-600 mt-1.5">
                                The selected lead{count === 1 ? '' : 's'} will be moved to trash. Admins can restore them later.
                            </p>
                        </div>
                    </div>
                </div>
                <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2 bg-gray-50/50 rounded-b-2xl">
                    <button
                        type="button"
                        onClick={onClose}
                        className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onConfirm}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-xl text-sm font-bold hover:bg-red-700 disabled:opacity-60"
                    >
                        {busy ? <Loader size={14} className="animate-spin" /> : <Trash2 size={14} />}
                        Delete
                    </button>
                </div>
            </div>
        </div>
    );
}

function AgentsTab({ agents = [], portalBase = '/portal/sales' }) {
    if (agents.length === 0) {
        return (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-14 text-center">
                <Users size={26} className="mx-auto mb-3 text-gray-300" />
                <p className="font-semibold text-gray-700">No recruiting agents yet</p>
                <p className="text-sm text-gray-400 mt-1">Create an account with the “Agent” role in User Management and they'll appear here.</p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead>
                        <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                            <th className="px-4 py-3">Agent</th>
                            <th className="px-3 py-3">Contact</th>
                            <th className="px-3 py-3">Location</th>
                            <th className="px-3 py-3 text-center">Leads added</th>
                            <th className="px-3 py-3 text-right pr-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {agents.map((a) => (
                            <tr key={a.id} className="hover:bg-gray-50/60 transition-colors">
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2.5">
                                        <Avatar name={a.name} src={a.avatar_url} colorKey={a.id} size={32} />
                                        <div className="min-w-0">
                                            <div className="font-semibold text-gray-900 truncate">{a.name}</div>
                                            {a.email && (
                                                <div className="text-[10px] text-gray-400 truncate">{a.email}</div>
                                            )}
                                        </div>
                                    </div>
                                </td>
                                <td className="px-3 py-3 text-gray-600">
                                    <div className="flex flex-col gap-0.5">
                                        {a.email && (
                                            <span className="inline-flex items-center gap-1.5">
                                                <Mail size={11} className="text-gray-300 flex-shrink-0" />
                                                <span className="truncate max-w-[200px]">{a.email}</span>
                                            </span>
                                        )}
                                        {a.phone && (
                                            <span className="inline-flex items-center gap-1.5">
                                                <Phone size={11} className="text-gray-300 flex-shrink-0" />
                                                <span>{a.phone}</span>
                                            </span>
                                        )}
                                        {! a.email && ! a.phone && <span className="text-gray-300">—</span>}
                                    </div>
                                </td>
                                <td className="px-3 py-3 text-gray-600">
                                    {a.location ? (
                                        <span className="inline-flex items-center gap-1.5">
                                            <MapPin size={12} className="text-gray-400" />
                                            <span className="max-w-[200px] truncate">{a.location}</span>
                                        </span>
                                    ) : (
                                        <span className="text-gray-300">—</span>
                                    )}
                                </td>
                                <td className="px-3 py-3 text-center">
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 font-bold tabular-nums">
                                        <Users size={11} /> {a.leads_count ?? 0}
                                    </span>
                                </td>
                                <td className="px-3 py-3 text-right pr-4">
                                    <Link
                                        href={`${portalBase}/agents/${a.id}/leads`}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black"
                                    >
                                        View leads
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ── Events tab — list events + view their registrants in an opportunities-
//    style table (with pipeline-stage badges) ───────────────────────────────

const fmtEventDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—";

function EventsTab({ events = [], portalBase, statuses = [] }) {
    // The old modal is gone — clicking "View registrants" now Inertia-
    // navigates to a full page at ${portalBase}/events/{id}/registrants.
    // Statuses is still passed in so per-row stage editing continues
    // to work (the new page reads it directly from server props).

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            {events.length === 0 ? (
                <div className="px-6 py-20 text-center text-gray-400">
                    <Calendar size={26} className="mx-auto mb-2 text-gray-300" />
                    <p className="text-sm font-medium">No events yet</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                <th className="px-4 py-3">Event</th>
                                <th className="px-3 py-3">Date</th>
                                <th className="px-3 py-3">Where</th>
                                <th className="px-3 py-3">Type</th>
                                <th className="px-3 py-3">Agent</th>
                                <th className="px-3 py-3 text-center">Registered</th>
                                <th className="px-3 py-3 text-right pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {events.map((ev) => (
                                <tr key={ev.id} className="hover:bg-gray-50/60 transition-colors">
                                    <td className="px-4 py-3">
                                        <div className="font-semibold text-gray-900">{ev.name}</div>
                                        {ev.event_code && <div className="text-[10px] text-gray-400 tabular-nums">{ev.event_code}</div>}
                                    </td>
                                    <td className="px-3 py-3 text-gray-600 whitespace-nowrap">{fmtEventDate(ev.date_from)}</td>
                                    <td className="px-3 py-3 text-gray-600">
                                        <span className="inline-flex items-center gap-1.5">
                                            <MapPin size={12} className="text-gray-400" />
                                            <span className="max-w-[200px] truncate">{ev.location || ev.mode || "—"}</span>
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-gray-600">{ev.type || "—"}</td>
                                    <td className="px-3 py-3">
                                        {ev.agent ? (
                                            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase border bg-violet-50 text-violet-700 border-violet-200">
                                                <UserCheck size={11} className="text-violet-400" />
                                                {ev.agent}
                                            </span>
                                        ) : (
                                            <span className="text-gray-300">—</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-3 text-center">
                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 font-bold tabular-nums">
                                            <Users size={11} /> {ev.registrations_count}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-right pr-4">
                                        <Link
                                            href={`${portalBase}/events/${ev.id}/registrants`}
                                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black"
                                        >
                                            View registrants
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

        </div>
    );
}

// Legacy — kept only so any historic import doesn't break during rollout.
// Safe to delete once no callers reference RegistrantsModal.
function _RegistrantsModal_LEGACY({ event, registrants = [], loading, portalBase, statuses = [], savingId, onStageChange, onClose }) {
    const [openStageId, setOpenStageId] = useState(null);

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" onMouseDown={onClose}>
            <div className="absolute inset-0 bg-black/40" />
            <div
                className="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl h-[72vh] max-h-[85vh] flex flex-col overflow-hidden"
                onMouseDown={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div className="min-w-0">
                        <h2 className="text-base font-bold text-gray-900 truncate">Registrants · {event.name}</h2>
                        <p className="text-[11px] text-gray-400">{registrants.length} registered</p>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100"><X size={18} /></button>
                </div>

                <div className="flex-1 overflow-auto">
                    {loading ? (
                        <div className="p-10 flex items-center justify-center text-gray-400 text-sm gap-2">
                            <Loader size={16} className="animate-spin" /> Loading…
                        </div>
                    ) : registrants.length === 0 ? (
                        <div className="p-12 text-center text-gray-400">
                            <Users size={24} className="mx-auto mb-2 text-gray-300" />
                            <p className="text-sm font-medium">No one has registered yet</p>
                        </div>
                    ) : (
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th className="px-5 py-3">Lead</th>
                                    <th className="px-3 py-3">Stage</th>
                                    <th className="px-3 py-3">Email</th>
                                    <th className="px-3 py-3">Phone</th>
                                    <th className="px-3 py-3 text-right pr-5">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {registrants.map((r) => (
                                    <tr key={r.id} className="hover:bg-gray-50/60 transition-colors">
                                        <td className="px-5 py-3">
                                            <div className="font-semibold text-gray-900">{r.name}</div>
                                            {r.lead_id && <div className="text-[10px] text-gray-400 tabular-nums">{r.lead_id}</div>}
                                        </td>
                                        <td className="px-3 py-3 relative">
                                            <StagePicker
                                                lead={r}
                                                stages={statuses}
                                                open={openStageId === r.id}
                                                onToggle={() => setOpenStageId(openStageId === r.id ? null : r.id)}
                                                onClose={() => setOpenStageId(null)}
                                                onSelect={(stage) => { onStageChange(r, stage); setOpenStageId(null); }}
                                                isSaving={savingId === r.id}
                                            />
                                        </td>
                                        <td className="px-3 py-3 text-gray-600">{r.email || "—"}</td>
                                        <td className="px-3 py-3 text-gray-600">{r.phone || "—"}</td>
                                        <td className="px-3 py-3 text-right pr-5">
                                            <Link
                                                href={`${portalBase}/leads/${r.id}`}
                                                className="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-600 hover:text-gray-900"
                                            >
                                                Open <ExternalLink size={11} />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Add Lead — manual create with the dashboard-sheet fields ───────────────

const GOAL_STATUS_OPTIONS = ["Consultation Done", "For Proposal", "Proposal Sent", "No Show"];

const NAME_SUFFIXES = ["Jr.", "Sr.", "II", "III", "IV", "V"];

function AddLeadButton({ portalBase, statuses = [], programs = [], staffOptions = [], agents = [] }) {
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const blank = {
        first_name: "", last_name: "", suffix: "", email: "", phone: "", status: statuses[0] || "New Leads", assessment_date: "",
        pre_screened_by: "", pre_screening_notes: "", program_offered: "", agent_id: "",
        goal_setting_by: "", goal_setting_status: "", goal_setting_notes: "",
        calendar_date: "", client_info_link: "", call_update_form_link: "",
        // Document uploads — mirror the public registration form.
        cv_files: [], passport_files: [], diploma_files: [], transcript_files: [],
    };
    const [form, setForm] = useState(blank);
    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    const setFiles = (k, files) => setForm((f) => ({ ...f, [k]: files }));

    // Lock background scroll + close on Escape while the modal is open.
    useEffect(() => {
        if (!open) return;
        const onKey = (e) => { if (e.key === "Escape") setOpen(false); };
        document.addEventListener("keydown", onKey);
        document.body.style.overflow = "hidden";
        return () => {
            document.removeEventListener("keydown", onKey);
            document.body.style.overflow = "";
        };
    }, [open]);

    const submit = (e) => {
        e.preventDefault();
        if (!form.first_name.trim()) return;
        setSaving(true);
        router.post(`${portalBase}/leads`, form, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { setForm(blank); setOpen(false); },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-gray-800 text-sm font-semibold transition-colors shadow-sm"
            >
                <Plus size={16} /> Add Lead
            </button>

            {open && createPortal(
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4" onMouseDown={(e) => { if (e.target === e.currentTarget) setOpen(false); }}>
                    <div className="relative w-full max-w-3xl bg-white rounded-2xl shadow-xl max-h-[92vh] flex flex-col">
                        {/* Header */}
                        <div className="flex items-center justify-between px-6 py-3 border-b border-gray-100">
                            <div>
                                <h2 className="text-base font-bold text-gray-900">Add Lead</h2>
                                <p className="text-[11px] text-gray-500">Create a lead with the dashboard fields. Only the name is required.</p>
                            </div>
                            <button type="button" onClick={() => setOpen(false)} className="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                                <X size={18} />
                            </button>
                        </div>

                        <form onSubmit={submit} className="px-6 py-4 space-y-3 overflow-y-auto">
                            {/* Core details — 3 across so they sit in tidy rows. */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-3">
                                <Field label="First name" required>
                                    <input value={form.first_name} onChange={set("first_name")} required placeholder="First name" className={inputCls} />
                                </Field>
                                <Field label="Last name">
                                    <input value={form.last_name} onChange={set("last_name")} placeholder="Last name" className={inputCls} />
                                </Field>
                                <Field label="Suffix">
                                    <select value={form.suffix} onChange={set("suffix")} className={inputCls}>
                                        <option value="">—</option>
                                        {NAME_SUFFIXES.map((s) => <option key={s} value={s}>{s}</option>)}
                                    </select>
                                </Field>
                                <Field label="Email">
                                    <input type="email" value={form.email} onChange={set("email")} placeholder="name@email.com" className={inputCls} />
                                </Field>
                                <Field label="Contact number">
                                    <input type="tel" value={form.phone} onChange={set("phone")} placeholder="e.g. (63977) 626-0738" className={inputCls} />
                                </Field>
                                <Field label="Status">
                                    <select value={form.status} onChange={set("status")} className={inputCls}>
                                        {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                                    </select>
                                </Field>
                                <Field label="Program offered">
                                    <select value={form.program_offered} onChange={set("program_offered")} className={inputCls}>
                                        <option value="">— Select program —</option>
                                        {programs.map((p) => <option key={p} value={p}>{p}</option>)}
                                    </select>
                                </Field>
                                {agents.length > 0 && (
                                    <Field label="Agent">
                                        <select value={form.agent_id} onChange={set("agent_id")} className={inputCls}>
                                            <option value="">— No agent —</option>
                                            {agents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                        </select>
                                    </Field>
                                )}
                                <Field label="Assessment date">
                                    <input type="date" value={form.assessment_date} onChange={set("assessment_date")} className={inputCls} />
                                </Field>
                                <Field label="Calendar date">
                                    <input type="date" value={form.calendar_date} onChange={set("calendar_date")} className={inputCls} />
                                </Field>
                            </div>

                            {/* Pre-screening + goal-setting — styled like the
                                Internal Notes composer: gray panel, tracked
                                uppercase labels, status as toggle pills. */}
                            <div className="rounded-xl border border-gray-200 bg-gray-50/60 p-4">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    {/* Pre-screening */}
                                    <div className="space-y-3">
                                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500">Pre-screening</p>
                                        <div>
                                            <p className={noteLabelCls}>Pre-screened by</p>
                                            <select value={form.pre_screened_by} onChange={set("pre_screened_by")} className={noteInputCls}>
                                                <option value="">— Select staff —</option>
                                                {staffOptions.map((u) => <option key={u.id} value={u.name}>{u.name} · {u.role}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <p className={noteLabelCls}>Pre-screening notes</p>
                                            <textarea value={form.pre_screening_notes} onChange={set("pre_screening_notes")} rows={3} placeholder="Type here" className={noteInputCls + " resize-none"} />
                                        </div>
                                    </div>

                                    {/* Goal-setting */}
                                    <div className="space-y-3">
                                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500">Goal-setting</p>
                                        <div>
                                            <p className={noteLabelCls}>Goal-setting status</p>
                                            <div className="flex flex-wrap gap-1.5">
                                                {GOAL_STATUS_OPTIONS.map((s) => {
                                                    const active = form.goal_setting_status === s;
                                                    return (
                                                        <button
                                                            key={s}
                                                            type="button"
                                                            onClick={() => setForm((f) => ({ ...f, goal_setting_status: active ? "" : s }))}
                                                            className={`inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border transition-all ${
                                                                active ? "bg-purple-600 text-white border-purple-600" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
                                                            }`}
                                                        >
                                                            {s}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                        <div>
                                            <p className={noteLabelCls}>Goal-setting by</p>
                                            <select value={form.goal_setting_by} onChange={set("goal_setting_by")} className={noteInputCls}>
                                                <option value="">— Select staff —</option>
                                                {staffOptions.map((u) => <option key={u.id} value={u.name}>{u.name} · {u.role}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <p className={noteLabelCls}>Goal-setting notes</p>
                                            <textarea value={form.goal_setting_notes} onChange={set("goal_setting_notes")} rows={3} placeholder="Type here" className={noteInputCls + " resize-none"} />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Links */}
                            <div className="pt-3 border-t border-gray-100 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <Field label="Client information link">
                                    <input type="url" value={form.client_info_link} onChange={set("client_info_link")} placeholder="https://…" className={inputCls} />
                                </Field>
                                <Field label="Call update form link">
                                    <input type="url" value={form.call_update_form_link} onChange={set("call_update_form_link")} placeholder="https://…" className={inputCls} />
                                </Field>
                            </div>

                            {/* Documents — same four as the public registration form. */}
                            <div className="pt-3 border-t border-gray-100">
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-3">Documents</p>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <DocPick label="Attach CV" files={form.cv_files} onChange={(f) => setFiles("cv_files", f)} />
                                    <DocPick label="Passport" files={form.passport_files} onChange={(f) => setFiles("passport_files", f)} />
                                    <DocPick label="Diploma" files={form.diploma_files} onChange={(f) => setFiles("diploma_files", f)} />
                                    <DocPick label="Transcript of Record" files={form.transcript_files} onChange={(f) => setFiles("transcript_files", f)} />
                                </div>
                            </div>
                        </form>

                        {/* Footer — pinned so the actions are always visible. */}
                        <div className="flex items-center justify-end gap-2 px-6 py-3 border-t border-gray-100">
                            <button type="button" onClick={() => setOpen(false)} className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                                Cancel
                            </button>
                            <button type="button" onClick={submit} disabled={saving || !form.first_name.trim()} className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-lg text-sm font-semibold hover:bg-gray-800 transition-colors disabled:opacity-40">
                                {saving ? <Loader size={15} className="animate-spin" /> : <Plus size={15} />}
                                {saving ? "Saving…" : "Add Lead"}
                            </button>
                        </div>
                    </div>
                </div>,
                document.body
            )}
        </>
    );
}

// Multi-file picker used by the Add-Lead Documents section. Accepts the same
// file types as the public registration form; shows chosen files with a
// remove control. `files` is an array of File objects held in the form state.
function DocPick({ label, files = [], onChange }) {
    const inputId = `doc-${label.replace(/\s+/g, "-").toLowerCase()}`;
    const add = (list) => onChange([...(files || []), ...Array.from(list)]);
    const removeAt = (i) => onChange(files.filter((_, idx) => idx !== i));
    return (
        <div>
            <p className="text-[11px] font-semibold text-gray-600 mb-1.5">{label}</p>
            <label htmlFor={inputId} className="flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-dashed border-gray-300 text-xs font-semibold text-gray-600 hover:border-gray-500 hover:bg-gray-50 cursor-pointer transition-colors">
                <Upload size={13} /> {files.length ? "Add more…" : "Choose file(s)"}
            </label>
            <input
                id={inputId}
                type="file"
                multiple
                accept=".pdf,.doc,.docx,.xls,.csv,.jpg,.jpeg,.png,.gif"
                className="hidden"
                onChange={(e) => { if (e.target.files?.length) add(e.target.files); e.target.value = ""; }}
            />
            {files.length > 0 && (
                <ul className="mt-1.5 space-y-1">
                    {files.map((f, i) => (
                        <li key={i} className="flex items-center gap-1.5 text-[11px] text-gray-600 bg-gray-50 border border-gray-100 rounded px-2 py-1">
                            <FileText size={11} className="text-gray-400 flex-shrink-0" />
                            <span className="truncate flex-1">{f.name}</span>
                            <button type="button" onClick={() => removeAt(i)} className="text-gray-400 hover:text-red-600"><X size={12} /></button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const inputCls = "w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white outline-none focus:border-gray-900 transition-colors";
// Internal-notes composer styling — used for the pre-screen / goal-setting block.
const noteInputCls = "w-full px-3 py-1.5 border border-gray-200 rounded-md text-xs bg-white outline-none focus:border-gray-900 transition-colors";
const noteLabelCls = "text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-1.5";

function Field({ label, required = false, wide = false, children }) {
    return (
        <div className={wide ? "sm:col-span-2" : ""}>
            <label className="block text-[11px] font-semibold text-gray-600 mb-1">
                {label}{required && <span className="text-red-500"> *</span>}
            </label>
            {children}
        </div>
    );
}

// ── Import Leads from CSV ─────────────────────────────────────────────────

function ImportLeadsButton() {
    const fileRef = useRef(null);
    const [uploading, setUploading] = useState(false);
    const [showDetail, setShowDetail] = useState(false);
    const summary = usePage().props?.flash?.import_summary;

    const trigger = () => fileRef.current?.click();

    const onChoose = (e) => {
        const f = e.target.files?.[0];
        if (!f) return;
        if (!confirm(
            `Import leads from "${f.name}"?\n\n`
            + `• Existing leads will be matched by email or (name + phone).\n`
            + `• Matched leads will only have empty fields backfilled — nothing overwritten.\n`
            + `• Unknown columns go into the lead's family_info / work_info JSON.\n`
            + `• Call Notes become real lead notes.\n\n`
            + `Proceed?`
        )) {
            e.target.value = '';
            return;
        }

        const fd = new FormData();
        fd.append('file', f);
        setUploading(true);
        setShowDetail(false);
        router.post('/admin/leads/import', fd, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onSuccess: () => setShowDetail(true),
            onFinish: () => {
                setUploading(false);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    const total = summary ? (summary.created || 0) + (summary.updated || 0) + (summary.skipped || 0) : 0;

    return (
        <div className="flex flex-col items-end gap-2">
            <div className="inline-flex items-center gap-2">
                <input
                    ref={fileRef}
                    type="file"
                    accept=".csv,text/csv"
                    onChange={onChoose}
                    className="hidden"
                />
                <button
                    type="button"
                    onClick={trigger}
                    disabled={uploading}
                    className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl text-xs font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors disabled:opacity-50"
                >
                    {uploading ? <Loader size={13} className="animate-spin" /> : <Upload size={13} />}
                    {uploading ? 'Importing…' : 'Import CSV'}
                </button>
            </div>

            {/* Detailed result card — shows exactly where the rows went so
                "only 79 of 346 imported" isn't a mystery (most are likely
                dedupe-matches, which is expected). */}
            {summary && showDetail && (
                <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 w-[420px] max-w-full text-left space-y-3">
                    <div className="flex items-center justify-between">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500">Import result</p>
                        <button
                            type="button"
                            onClick={() => setShowDetail(false)}
                            className="text-[10px] uppercase tracking-widest font-bold text-gray-400 hover:text-gray-700"
                        >
                            Dismiss
                        </button>
                    </div>
                    <p className="text-sm text-gray-700">
                        Processed <span className="font-bold tabular-nums">{total}</span> row{total === 1 ? '' : 's'}.
                    </p>
                    <div className="grid grid-cols-3 gap-2 text-center">
                        <div className="bg-emerald-50 border border-emerald-200 rounded-lg p-2">
                            <p className="text-[9px] font-bold uppercase tracking-widest text-emerald-700">New</p>
                            <p className="text-2xl font-bold text-emerald-700 tabular-nums">{summary.created || 0}</p>
                        </div>
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-2">
                            <p className="text-[9px] font-bold uppercase tracking-widest text-blue-700">Updated</p>
                            <p className="text-2xl font-bold text-blue-700 tabular-nums">{summary.updated || 0}</p>
                        </div>
                        <div className="bg-amber-50 border border-amber-200 rounded-lg p-2">
                            <p className="text-[9px] font-bold uppercase tracking-widest text-amber-700">Skipped</p>
                            <p className="text-2xl font-bold text-amber-700 tabular-nums">{summary.skipped || 0}</p>
                        </div>
                    </div>
                    <p className="text-[11px] text-gray-500 leading-relaxed">
                        <span className="font-semibold text-gray-700">Updated</span> means the row matched an existing lead by email or (name + phone) — empty fields were backfilled, nothing overwritten.
                    </p>
                    {(summary.errors || []).length > 0 && (
                        <div className="border-t border-gray-100 pt-2">
                            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-amber-700 mb-1">Skip reasons (first {summary.errors.length})</p>
                            <ul className="space-y-0.5 max-h-32 overflow-y-auto text-[11px] text-gray-600 leading-snug">
                                {summary.errors.map((e, i) => (
                                    <li key={i} className="font-mono">{e}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Row "More" menu — portal invitation actions live here ────────────────

function RowMenu({ lead, open, onToggle, onClose, onEdit, onRequestPortal, onViewFiles, isSaving, portalBase = "/portal/sales", canRequestInvite = true }) {
    const menuRef = useRef(null);
    const triggerRef = useRef(null);
    const [menuStyle, setMenuStyle] = useState(null);

    // Position the menu as a fixed-position portal anchored to the trigger so
    // it escapes the table's overflow-x-auto scroll container (which would
    // otherwise clip it). Flip above the row when there's no room below.
    useEffect(() => {
        if (!open) { setMenuStyle(null); return; }
        const place = () => {
            const r = triggerRef.current?.getBoundingClientRect();
            if (!r) return;
            const left = Math.max(8, Math.min(r.right - 260, window.innerWidth - 268));
            const spaceBelow = window.innerHeight - r.bottom;
            setMenuStyle(spaceBelow < 260 && r.top > 260
                ? { left, bottom: window.innerHeight - r.top + 4 }
                : { left, top: r.bottom + 4 });
        };
        place();
        // Close on scroll/resize rather than chase the trigger — keeps the menu
        // from ever floating away from its row.
        const close = () => onClose();
        window.addEventListener("resize", close);
        window.addEventListener("scroll", close, true);
        return () => {
            window.removeEventListener("resize", close);
            window.removeEventListener("scroll", close, true);
        };
    }, [open, onClose]);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            onClose();
        };
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("mousedown", onDoc);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onDoc);
            document.removeEventListener("keydown", onKey);
        };
    }, [open, onClose]);

    const portalStatus = lead.portal_invitation_status || "none";
    const badge = PORTAL_BADGE[portalStatus];
    const StatusIcon = badge?.icon;
    const canRequest = portalStatus === "none" || portalStatus === "revoked";

    return (
        <div className="relative inline-block">
            <button
                ref={triggerRef}
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                aria-haspopup="menu"
                title="More actions"
                className={`inline-flex items-center justify-center w-7 h-7 rounded-md transition-colors ${open ? "bg-gray-100 text-gray-900" : "text-gray-400 hover:text-gray-900 hover:bg-gray-100"}`}
            >
                <MoreHorizontal size={12} />
            </button>

            {open && menuStyle && createPortal(
                <div
                    ref={menuRef}
                    role="menu"
                    style={{ position: "fixed", ...menuStyle }}
                    className="z-[100] bg-white rounded-xl shadow-2xl border border-gray-100 py-2 w-[260px] text-left max-h-[70vh] overflow-y-auto"
                >
                    {/* Section: Lead actions */}
                    <p className="px-3 pb-1 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">Lead</p>
                    <Link
                        href={`${portalBase}/leads/${lead.id}`}
                        className="flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50"
                    >
                        <ExternalLink size={12} className="text-gray-400" />
                        View lead details
                    </Link>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => { onClose?.(); onEdit?.(); }}
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 text-left"
                    >
                        <Pencil size={12} className="text-gray-400" />
                        Edit lead
                    </button>
                    <Link
                        href={`${portalBase}/leads/${lead.id}?tab=documents`}
                        className="flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50"
                    >
                        <FileText size={12} className="text-gray-400" />
                        Documents
                    </Link>
                    {/* Every file on the lead with its review status —
                        separate from the checklist on the detail page. */}
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => { onClose?.(); onViewFiles?.(); }}
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 text-left"
                    >
                        <Paperclip size={12} className="text-gray-400" />
                        File history{lead.documents_count ? ` (${lead.documents_count})` : ''}
                    </button>
                    {/* Copy the public /track URL to the clipboard so staff
                        can paste it straight into WhatsApp / email — the
                        client opens it to see info, documents, timeline,
                        and to edit their own information. */}
                    {lead.tracking_code && (
                        <button
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                const url = `${window.location.origin}/track/${lead.tracking_code}`;
                                // Just the URL — gets auto-linked in WhatsApp /
                                // SMS / email and the code is already in the
                                // path, so the duplicate line was noise.
                                navigator.clipboard?.writeText(url).then(
                                    () => toast.success('Tracking link copied', { description: url }),
                                    () => toast.error('Could not copy — your browser blocked clipboard access')
                                );
                                onClose?.();
                            }}
                            className="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 text-left"
                        >
                            <Copy size={12} className="text-gray-400" />
                            Copy tracking link
                        </button>
                    )}

                    {/* Section: Portal invitation — only sales staff can initiate
                        a Lead-Portal invitation (admin approves). Other portals
                        see no invite controls. */}
                    {canRequestInvite && (<>
                    <div className="border-t border-gray-100 my-1.5"></div>
                    <p className="px-3 pb-1 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">Lead Portal</p>

                    {/* Current status display */}
                    {badge && (
                        <div className="px-3 py-2">
                            <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-bold border ${badge.chip}`}>
                                <StatusIcon size={10} strokeWidth={2.5} />
                                {badge.label}
                            </span>
                        </div>
                    )}

                    {/* Conditional actions */}
                    {canRequest && (
                        <button
                            type="button"
                            disabled={isSaving || !lead.email}
                            onClick={onRequestPortal}
                            className="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                            title={lead.email ? "" : "This lead has no email on file"}
                        >
                            <KeyRound size={12} className="text-gray-400" />
                            {portalStatus === "revoked" ? "Re-request access" : "Request portal access"}
                        </button>
                    )}

                    {portalStatus === "pending" && (
                        <a
                            href="/admin/portal-invitations"
                            className="flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50"
                        >
                            <Clock size={12} className="text-amber-500" />
                            View in invitations queue
                        </a>
                    )}

                    {(portalStatus === "sent" || portalStatus === "accepted") && (
                        <a
                            href="/admin/portal-invitations"
                            className="flex items-center gap-2.5 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50"
                        >
                            <UserCheck size={12} className="text-gray-400" />
                            Manage invitation
                        </a>
                    )}

                    {!lead.email && canRequest && (
                        <p className="px-3 py-1.5 text-[10px] text-amber-700 bg-amber-50 mx-2 rounded mt-1">
                            Add an email before requesting access.
                        </p>
                    )}
                    </>)}
                </div>,
                document.body,
            )}
        </div>
    );
}

// ── Expander panel — sales-dashboard mirror ───────────────────────────────
// Renders all 9 fields from the team's Sales Dashboard sheet inside the
// collapsed row so the table itself stays compact and scannable.

// Seven visual milestones the many pipeline stages roll up into, for the
// journey stepper. Each pipeline status maps to one milestone group.
const JOURNEY_MILESTONES = [
    { key: "New",        label: "New",        statuses: ["New Leads", "Contact Attempted", "Contacted for Booking"] },
    { key: "Screened",   label: "Screened",   statuses: ["Booking Confirmation", "Qualified but Not Ready", "Qualified but No Funds", "Qualified", "Missed the Meeting"] },
    { key: "Consult",    label: "Consult",    statuses: ["Booked Consultation", "Did Not Book Consultation", "No Show", "Consultation Done"] },
    { key: "Proposal",   label: "Proposal",   statuses: ["Proposal Sent"] },
    { key: "Engagement", label: "Engagement", statuses: ["Consultancy Agreement"] },
    { key: "Enrolment",  label: "Enrolment",  statuses: ["English Pro", "School Enrollment"] },
    { key: "Visa",       label: "Visa",       statuses: ["Visa Process"] },
];
const milestoneIndex = (status) => JOURNEY_MILESTONES.findIndex((m) => m.statuses.includes(status));

export function LeadDashboardPanel({ lead, goalChipClass = "bg-gray-100 text-gray-600 border-gray-200", portalBase = "/portal/sales", staffOptions = [] }) {
    const curIdx = milestoneIndex(lead.status);
    const detailUrl = `${portalBase}/leads/${lead.id}`;

    return (
        <div className="space-y-4">
            {/* 0 — Priority selector (sits above the journey; drives the row
                   avatar colour + the urgent-first ordering). */}
            <section>
                <PriorityField lead={lead} />
            </section>

            {/* 1 — Journey progress */}
            <section>
                <PanelTitle>Journey progress</PanelTitle>
                <JourneyStepper currentIndex={curIdx} />
            </section>

            {/* 2 — Current stage + program/schedule at a glance */}
            <section>
                <PanelTitle>
                    Current stage:{" "}
                    <span className="text-gray-900 normal-case tracking-normal font-bold">{lead.status}</span>
                </PanelTitle>
                <div className="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-xs">
                    <span className="inline-flex items-center gap-1.5 text-gray-500">
                        Program:
                        {lead.program_offered
                            ? <span className="font-semibold text-gray-800">{lead.program_offered}</span>
                            : <EmptyDash />}
                    </span>
                    <span className="inline-flex items-center gap-1.5 text-gray-500">
                        <CalendarClock size={12} className="text-gray-400" /> Calendar:
                        {lead.calendar_date
                            ? <span className="font-semibold text-gray-800">{fmtDateShort(lead.calendar_date)}</span>
                            : <EmptyDash />}
                    </span>
                </div>
            </section>

            {/* 3 — Internal notes (all kinds; carry pre-screen / goal-setting) */}
            <section>
                <PanelTitle>
                    Internal notes
                    {lead.notes_count > 0 && <span className="ml-1.5 text-gray-400 font-bold">· {lead.notes_count}</span>}
                </PanelTitle>
                <AddNoteInline leadId={lead.id} portalBase={portalBase} staffOptions={staffOptions} />
                {lead.recent_notes && lead.recent_notes.length > 0 ? (
                    <div className="space-y-2.5 mt-3">
                        {lead.recent_notes.map((n) => <NoteLine key={n.id} note={n} goalChipClass={goalChipClass} />)}
                    </div>
                ) : (
                    <p className="text-xs text-gray-300 italic mt-3">No internal notes yet.</p>
                )}
            </section>

            {/* 4 — Next actions */}
            <section>
                <PanelTitle>Next actions</PanelTitle>
                <div className="flex flex-wrap gap-2">
                    <Link href={detailUrl} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-900 text-white hover:bg-gray-800 transition-colors">
                        <ExternalLink size={12} /> View lead
                    </Link>
                    <a href={`${detailUrl}/documents`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                        <FileTextIcon size={12} /> Documents
                    </a>
                    {lead.email ? (
                        <a href={`mailto:${lead.email}`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                            <Mail size={12} /> Send email
                        </a>
                    ) : null}
                    {lead.phone ? (
                        <a href={`tel:${lead.phone}`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                            <Phone size={12} /> Call
                        </a>
                    ) : null}
                    <LinkChip href={lead.client_info_link} icon={Link2} label="Client info" />
                    <LinkChip href={lead.call_update_form_link} icon={Link2} label="Call form" />
                </div>
            </section>
        </div>
    );
}

function PanelTitle({ children }) {
    return <h4 className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-2.5">{children}</h4>;
}

// A single internal note in the expander — mirrors the lead-detail Internal
// Notes styling: author avatar + role, a coloured kind badge, and the kind's
// own captures (pre-screened by / goal-setting status + with whom).
const NOTE_KIND_BADGE = {
    pre_screen:   { label: "Pre-screening", cls: "bg-amber-100 text-amber-700 border-amber-200" },
    goal_setting: { label: "Goal-setting",  cls: "bg-purple-100 text-purple-700 border-purple-200" },
    general:      { label: "General",       cls: "bg-gray-100 text-gray-600 border-gray-200" },
};
function NoteLine({ note, goalChipClass }) {
    const meta = NOTE_KIND_BADGE[note.kind] || NOTE_KIND_BADGE.general;
    return (
        <div className="bg-white rounded-lg border border-gray-200/70 px-3 py-2.5">
            <div className="flex items-center gap-2 mb-1.5">
                <span className="w-6 h-6 rounded-full bg-gray-900 text-white flex items-center justify-center text-[10px] font-bold flex-shrink-0">
                    {initials(note.author_name)}
                </span>
                <span className="text-xs font-semibold text-gray-900">{note.author_name}</span>
                {note.author_role && (
                    <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">· {note.author_role}</span>
                )}
                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${meta.cls}`}>
                    {meta.label}
                </span>
                {note.pinned && <span className="text-[9px] font-bold uppercase tracking-wider text-amber-600">Pinned</span>}
                <span className="ml-auto text-[10px] text-gray-400 whitespace-nowrap">{note.created_at ? fmtDateShort(note.created_at) : ""}</span>
            </div>

            <NotePrograms programs={note.programs} />
            {/* Kind-specific captures */}
            {(note.kind === "pre_screen" && note.pre_screened_by) && (
                <div className="mb-1.5">
                    <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200">
                        By {note.pre_screened_by}
                    </span>
                </div>
            )}
            {note.kind === "goal_setting" && (note.goal_setting_status || note.goal_setting_by) && (
                <div className="flex flex-wrap items-center gap-1.5 mb-1.5">
                    {note.goal_setting_status && (
                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${goalChipClass}`}>
                            {note.goal_setting_status}
                        </span>
                    )}
                    {note.goal_setting_by && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold text-gray-600 border border-gray-200 bg-white">
                            with {note.goal_setting_by}
                        </span>
                    )}
                </div>
            )}

            <p className="text-xs text-gray-700 leading-relaxed whitespace-pre-wrap">{note.body}</p>
        </div>
    );
}

// Horizontal milestone stepper. Completed milestones show a filled check,
// the current one is ringed + "here", future ones are hollow.
function JourneyStepper({ currentIndex }) {
    return (
        <div className="flex items-start">
            {JOURNEY_MILESTONES.map((m, i) => {
                const done = currentIndex > i;
                const current = currentIndex === i;
                return (
                    <div key={m.key} className="flex-1 flex flex-col items-center relative min-w-0">
                        {i < JOURNEY_MILESTONES.length - 1 && (
                            <span className={`absolute top-[7px] left-1/2 w-full h-0.5 ${done ? "bg-emerald-400" : "bg-gray-200"}`} />
                        )}
                        <span className={`relative z-10 w-3.5 h-3.5 rounded-full border-2 flex items-center justify-center ${
                            done ? "bg-emerald-500 border-emerald-500" : current ? "bg-white border-purple-600" : "bg-white border-gray-300"
                        }`}>
                            {done && <Check size={8} strokeWidth={3} className="text-white" />}
                            {current && <span className="w-1.5 h-1.5 rounded-full bg-purple-600" />}
                        </span>
                        <span className={`mt-1.5 text-[10px] font-semibold truncate max-w-full px-1 ${
                            current ? "text-purple-700" : done ? "text-gray-700" : "text-gray-400"
                        }`}>{m.label}</span>
                        {current && <span className="text-[9px] text-purple-600 font-bold uppercase tracking-wide">← here</span>}
                    </div>
                );
            })}
        </div>
    );
}

function EmptyDash() {
    return <span className="text-gray-300">—</span>;
}

// Compact note composer inside the expander — mirrors the lead-detail
// Internal Notes form (kind pills + kind-specific fields + body) and posts
// to the portal's notes endpoint.
const NOTE_KINDS = [
    { k: "general",      label: "General" },
    { k: "pre_screen",   label: "Pre-screening" },
    { k: "goal_setting", label: "Goal-setting" },
];
function AddNoteInline({ leadId, portalBase, staffOptions = [], onSaved }) {
    const { programs = [], programOptions = [] } = usePage().props;
    const [notePrograms, setNotePrograms] = useState([]);
    const [open, setOpen] = useState(false);
    const [kind, setKind] = useState("general");
    const [body, setBody] = useState("");
    const [preBy, setPreBy] = useState("");
    const [goalStatus, setGoalStatus] = useState("");
    const [goalBy, setGoalBy] = useState("");
    const [saving, setSaving] = useState(false);

    // Auto-grow the textarea so long notes expand the box rather than
    // scroll inside a fixed-height frame. Re-runs on every keystroke +
    // whenever the composer opens.
    const textareaRef = useRef(null);
    useEffect(() => {
        const el = textareaRef.current;
        if (! el) return;
        el.style.height = "auto";
        el.style.height = `${el.scrollHeight}px`;
    }, [body, open]);

    const reset = () => { setKind("general"); setBody(""); setPreBy(""); setGoalStatus(""); setGoalBy(""); setNotePrograms([]); };

    const submit = () => {
        if (!body.trim()) return;
        setSaving(true);
        router.post(`${portalBase}/leads/${leadId}/notes`, {
            body: body.trim(),
            programs: notePrograms,
            kind,
            pre_screened_by:     kind === "pre_screen"   ? (preBy || null) : null,
            goal_setting_status: kind === "goal_setting" ? (goalStatus || null) : null,
            goal_setting_by:     kind === "goal_setting" ? (goalBy || null) : null,
        }, {
            preserveScroll: true,
            onSuccess: () => { reset(); setOpen(false); onSaved?.(); },
            onFinish: () => setSaving(false),
        });
    };

    const fieldCls = "w-full px-2.5 py-1.5 border border-gray-200 rounded-md text-xs bg-white outline-none focus:border-gray-900";

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-700 bg-white border border-gray-200 hover:bg-gray-50 transition-colors"
            >
                <Plus size={13} /> Add note
            </button>
        );
    }

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-3 space-y-2.5">
            {/* Kind pills */}
            <div className="flex items-center gap-1.5 flex-wrap">
                <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mr-1">Type</span>
                {NOTE_KINDS.map((nk) => (
                    <button
                        key={nk.k}
                        type="button"
                        onClick={() => setKind(nk.k)}
                        className={`inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border transition-all ${
                            kind === nk.k ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-500 border-gray-200 hover:bg-gray-50"
                        }`}
                    >
                        {nk.label}
                    </button>
                ))}
            </div>

            {kind === "pre_screen" && (
                <select value={preBy} onChange={(e) => setPreBy(e.target.value)} className={fieldCls}>
                    <option value="">Pre-screened by…</option>
                    {staffOptions.map((u) => <option key={u.id} value={u.name}>{u.name}{u.role ? ` · ${u.role}` : ""}</option>)}
                </select>
            )}

            {kind === "goal_setting" && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <select value={goalStatus} onChange={(e) => setGoalStatus(e.target.value)} className={fieldCls}>
                        <option value="">Status…</option>
                        {GOAL_STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select value={goalBy} onChange={(e) => setGoalBy(e.target.value)} className={fieldCls}>
                        <option value="">Set by…</option>
                        {staffOptions.map((u) => <option key={u.id} value={u.name}>{u.name}</option>)}
                    </select>
                </div>
            )}

            <NoteProgramPicker options={programs.length ? programs : programOptions} value={notePrograms} onChange={setNotePrograms} />
            <textarea
                ref={textareaRef}
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={2}
                placeholder="Type here"
                className={fieldCls + " resize-none overflow-hidden"}
                style={{ minHeight: "3rem" }}
            />

            <div className="flex items-center justify-end gap-2">
                <button type="button" onClick={() => { reset(); setOpen(false); }} className="px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button type="button" onClick={submit} disabled={saving || !body.trim()} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-gray-900 hover:bg-gray-800 transition-colors disabled:opacity-40">
                    {saving ? <Loader size={13} className="animate-spin" /> : <Plus size={13} />}
                    {saving ? "Saving…" : "Add note"}
                </button>
            </div>
        </div>
    );
}

function LinkChip({ href, icon: Icon, label }) {
    if (!href) {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-300">
                <Icon size={12} /> {label}
            </span>
        );
    }
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-white border border-gray-200 text-[11px] font-semibold text-blue-600 hover:bg-blue-50 hover:border-blue-200 transition-colors"
        >
            <Icon size={12} /> {label} <ExternalLink size={10} className="opacity-60" />
        </a>
    );
}

// ── Advanced filters popover ───────────────────────────────────────────────

const PORTAL_FILTER_OPTIONS = [
    { v: "none", label: "No portal" },
    { v: "pending", label: "Awaiting admin" },
    { v: "sent", label: "Invitation sent" },
    { v: "accepted", label: "Portal active" },
    { v: "revoked", label: "Revoked" },
];

function FiltersPopover({ open, onToggle, onClose, activeCount, adv, setAdvFilter, clearAdv, statuses = [], statusFilter = "All", onStatusChange, programs = [], staffOptions = [], tagOptions = [] }) {
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e) => { if (ref.current && !ref.current.contains(e.target)) onClose(); };
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("mousedown", onDoc);
        document.addEventListener("keydown", onKey);
        return () => { document.removeEventListener("mousedown", onDoc); document.removeEventListener("keydown", onKey); };
    }, [open, onClose]);

    const cls = "w-full px-2.5 py-1.5 border border-gray-200 rounded-md text-xs bg-white outline-none focus:border-gray-900";
    const lbl = "block text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-1";

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={onToggle}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold border transition-all ${
                    activeCount > 0 ? "bg-gray-900 text-white border-gray-900" : "text-gray-600 bg-white border-gray-200 hover:bg-gray-50"
                }`}
            >
                <Filter size={12} />
                Filters
                {activeCount > 0 && (
                    <span className="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-white/25 text-[10px] font-bold tabular-nums">
                        {activeCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute left-0 mt-2 w-72 bg-white border border-gray-200 rounded-xl shadow-lg z-30 p-4 space-y-3">
                    <div className="flex items-center justify-between">
                        <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500">Advanced filters</p>
                        {activeCount > 0 && (
                            <button type="button" onClick={clearAdv} className="text-[10px] font-bold uppercase tracking-wider text-blue-600 hover:text-blue-800">
                                Clear all
                            </button>
                        )}
                    </div>

                    <div>
                        <label className={lbl}>Stage</label>
                        <select value={statusFilter} onChange={(e) => onStatusChange(e.target.value)} className={cls}>
                            <option value="All">All stages</option>
                            {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className={lbl}>Goal-setting status</label>
                        <select value={adv.goal_status} onChange={setAdvFilter("goal_status")} className={cls}>
                            <option value="">Any</option>
                            {GOAL_STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className={lbl}>Pre-screened by</label>
                        <select value={adv.pre_screened_by} onChange={setAdvFilter("pre_screened_by")} className={cls}>
                            <option value="">Any</option>
                            {staffOptions.map((u) => <option key={u.id} value={u.name}>{u.name}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className={lbl}>Program offered</label>
                        <select value={adv.program} onChange={setAdvFilter("program")} className={cls}>
                            <option value="">Any</option>
                            {programs.map((p) => <option key={p} value={p}>{p}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className={lbl}>Portal status</label>
                        <select value={adv.portal} onChange={setAdvFilter("portal")} className={cls}>
                            <option value="">Any</option>
                            {PORTAL_FILTER_OPTIONS.map((o) => <option key={o.v} value={o.v}>{o.label}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className={lbl}>Tag</label>
                        <select value={adv.tag} onChange={setAdvFilter("tag")} className={cls}>
                            <option value="">Any</option>
                            {tagOptions.map((t) => <option key={t} value={t}>{t}</option>)}
                        </select>
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Edit-lead modal — large (not full-screen) profile-style dialog with a
//    vertical tab rail, mirroring the Cases board. Header carries the avatar,
//    name, ID, stage picker + agent; tabs edit personal info / show documents,
//    notes-and-tags, and the journey. ─────────────────────────────────────────

const EDIT_TABS = [
    { key: "personal",  label: "Personal info",  icon: UserCheck },
    { key: "documents", label: "Documents",      icon: FileText },
    { key: "notes",     label: "Notes / Tags",   icon: StickyNote },
    { key: "journey",   label: "Journey",        icon: CalendarClock },
];

const DOC_STATUS_STYLE = {
    Approved:    "bg-emerald-50 text-emerald-700 border-emerald-200",
    Submitted:   "bg-blue-50 text-blue-700 border-blue-200",
    UnderReview: "bg-amber-50 text-amber-700 border-amber-200",
    Rejected:    "bg-red-50 text-red-700 border-red-200",
};

function EditLeadModal({ lead, portalBase, statuses = [], staffOptions = [], agents = [], onClose }) {
    // The Edit modal reads and writes personal FACTS through the admin lead
    // endpoints. The agent portal mirrors just those two under its own prefix, so
    // an agent can still fix a name or phone on a lead they recruited.
    const editBase = ["/portal/agent", "/portal/sub-agent"].includes(portalBase) ? portalBase : "/admin";
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null);
    const [tab, setTab] = useState("personal");
    const [form, setForm] = useState({});
    const [saving, setSaving] = useState(false);
    const [openStage, setOpenStage] = useState(false);
    const [stageSaving, setStageSaving] = useState(false);
    const [openAgent, setOpenAgent] = useState(false);
    const [agentSaving, setAgentSaving] = useState(false);
    // Agent assignment lives on the sales screen and the admin List of Leads
    // (both ship the recruiting-agent roster); other portals keep the read-only
    // badge.
    const canPickAgent = ["/portal/sales", "/admin"].includes(portalBase) && agents.length > 0;

    const changeAgent = (agentId) => {
        setAgentSaving(true);
        router.post(`${portalBase}/leads/${lead.id}/agent`, { agent_id: agentId || null }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const picked = agents.find((a) => String(a.id) === String(agentId)) || null;
                setData((d) => ({ ...d, agent: picked ? { id: picked.id, name: picked.name } : null }));
                toast.success(agentId ? "Agent assigned." : "Agent removed.");
            },
            onError: (errs) => toast.error(Object.values(errs || {})[0] || "Could not update the agent."),
            onFinish: () => setAgentSaving(false),
        });
    };

    // getJson returns { ok, status, data } — the lead payload lives on .data.
    const refetch = () => getJson(`${editBase}/leads/${lead.id}/edit-data`)
        .then((res) => { if (res.ok && res.data) { setData(res.data); setForm(res.data.personal || {}); } })
        .catch(() => toast.error("Could not load this lead."))
        .finally(() => setLoading(false));

    useEffect(() => {
        let alive = true;
        setLoading(true);
        getJson(`${editBase}/leads/${lead.id}/edit-data`)
            .then((res) => {
                if (!alive) return;
                if (res.ok && res.data) { setData(res.data); setForm(res.data.personal || {}); }
                else toast.error("Could not load this lead.");
            })
            .catch(() => alive && toast.error("Could not load this lead."))
            .finally(() => alive && setLoading(false));
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("keydown", onKey);
        return () => { alive = false; document.removeEventListener("keydown", onKey); };
    }, [lead.id]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    const savePersonal = () => {
        setSaving(true);
        router.post(`${editBase}/leads/${lead.id}/personal`, form, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success("Personal information updated."),
            onError: (errs) => toast.error(Object.values(errs || {})[0] || "Could not save."),
            onFinish: () => setSaving(false),
        });
    };

    const changeStage = (stage) => {
        setOpenStage(false);
        setStageSaving(true);
        router.post(`${portalBase}/leads/${lead.id}`, { status: stage }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setData((d) => ({ ...d, status: stage, stage })),
            onFinish: () => setStageSaving(false),
        });
    };

    const meta = priorityMeta(data?.priority);
    const name = data?.name || lead.name;

    return createPortal(
        <div className="fixed inset-0 z-[120] bg-black/40 flex items-center justify-center p-4" onMouseDown={onClose}>
            <div
                className="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden"
                onMouseDown={(e) => e.stopPropagation()}
            >
                {/* Header — profile identity + contact, then stage & agent controls */}
                <div className="relative px-6 pt-5 pb-4 border-b border-gray-100 bg-gradient-to-b from-gray-50/80 to-white">
                    <button
                        type="button"
                        onClick={onClose}
                        className="absolute top-4 right-4 inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                    >
                        <X size={16} />
                    </button>

                    <div className="flex items-center gap-4">
                        <div className={`w-14 h-14 rounded-full flex items-center justify-center text-base font-bold flex-shrink-0 ring-4 ring-white shadow-sm ${
                            meta ? `${meta.dot} text-white` : "bg-gradient-to-br from-gray-100 to-gray-200 text-gray-500"
                        }`}>
                            {initials(name)}
                        </div>
                        <div className="min-w-0 flex-1">
                            <h2 className="text-lg font-bold text-gray-900 tracking-tight truncate">{name}</h2>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-0.5">
                                {data?.lead_id && (
                                    <span className="text-[11px] text-gray-400 font-mono">{data.lead_id}</span>
                                )}
                                {form.email && (
                                    <span className="inline-flex items-center gap-1 text-[12px] text-gray-500">
                                        <Mail size={12} className="text-gray-300" /> {form.email}
                                    </span>
                                )}
                                {form.phone && (
                                    <span className="inline-flex items-center gap-1 text-[12px] text-gray-500">
                                        <Phone size={12} className="text-gray-300" /> {form.phone}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Stage (status colour) + agent */}
                    <div className="flex flex-wrap items-center gap-2.5 mt-4">
                        <div className="flex items-center gap-1.5">
                            <span className="text-[11px] font-medium text-gray-500">Stage</span>
                            <StagePicker
                                lead={{ status: data?.status, id: lead.id }}
                                stages={statuses}
                                open={openStage}
                                onToggle={() => setOpenStage((v) => !v)}
                                onClose={() => setOpenStage(false)}
                                onSelect={changeStage}
                                isSaving={stageSaving}
                            />
                        </div>
                        <div className="flex items-center gap-1.5 ml-auto">
                            <span className="text-[11px] font-medium text-gray-500">Agent</span>
                            {canPickAgent ? (
                                <AgentPicker
                                    agent={data?.agent}
                                    agents={agents}
                                    open={openAgent}
                                    onToggle={() => setOpenAgent((v) => !v)}
                                    onClose={() => setOpenAgent(false)}
                                    onSelect={(id) => { setOpenAgent(false); changeAgent(id); }}
                                    isSaving={agentSaving}
                                />
                            ) : (
                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase border bg-violet-50 text-violet-700 border-violet-200">
                                    <UserCheck size={11} className="text-violet-400" />
                                    {data?.agent?.name || "Unassigned"}
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Body — vertical tab rail + content */}
                <div className="flex-1 flex min-h-0">
                    <nav className="w-44 flex-shrink-0 border-r border-gray-100 p-2 space-y-0.5 overflow-y-auto">
                        {EDIT_TABS.map((t) => {
                            const Icon = t.icon;
                            const active = tab === t.key;
                            return (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => setTab(t.key)}
                                    className={`w-full flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold text-left transition-colors ${
                                        active ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-100"
                                    }`}
                                >
                                    <Icon size={13} className={active ? "text-white" : "text-gray-400"} />
                                    {t.label}
                                </button>
                            );
                        })}
                    </nav>

                    <div className="flex-1 min-w-0 overflow-y-auto p-5">
                        {loading ? (
                            <div className="flex items-center justify-center py-20 text-gray-400">
                                <Loader size={18} className="animate-spin" />
                            </div>
                        ) : tab === "personal" ? (
                            <div className="space-y-6">
                                <EditSection title="Identity">
                                    <EditField label="First name" value={form.first_name} onChange={set("first_name")} />
                                    <EditField label="Last name" value={form.last_name} onChange={set("last_name")} />
                                    <EditField label="Middle name" value={form.middle_name} onChange={set("middle_name")} />
                                    <EditSelect label="Gender" value={form.gender} onChange={set("gender")}
                                        options={["", "Male", "Female", "Other"]} />
                                    <EditSelect label="Marital status" value={form.marital_status} onChange={set("marital_status")}
                                        options={["", "Single", "Married", "De Facto", "Civil Union", "Divorced", "Widowed", "Separated"]} />
                                    <EditField label="Date of birth" type="date" value={form.dob} onChange={set("dob")} />
                                </EditSection>

                                <EditSection title="Contact">
                                    <EditField label="Email address" type="email" value={form.email} onChange={set("email")} />
                                    <EditField label="Phone number" value={form.phone} onChange={set("phone")} />
                                </EditSection>

                                <EditSection title="Origin & residence">
                                    <EditField label="Country of birth" value={form.country_of_birth} onChange={set("country_of_birth")} />
                                    <EditField label="Citizenship" value={form.citizenship} onChange={set("citizenship")} />
                                    <EditField label="Residence city" value={form.residence_city} onChange={set("residence_city")} />
                                    <EditField label="Residence country" value={form.residence_country} onChange={set("residence_country")} />
                                </EditSection>

                                <EditSection title="Passport">
                                    <EditField label="Passport number" value={form.passport_number} onChange={set("passport_number")} />
                                    <EditField label="Passport expiry" type="date" value={form.passport_expiry} onChange={set("passport_expiry")} />
                                </EditSection>
                            </div>
                        ) : tab === "documents" ? (
                            <div className="space-y-3">
                                {(data?.documents || []).length === 0 ? (
                                    <p className="text-xs text-gray-400 italic">No documents uploaded yet.</p>
                                ) : (
                                    <div className="divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden">
                                        {data.documents.map((d) => (
                                            <div key={d.id} className="flex items-center gap-3 px-3 py-2.5">
                                                <FileText size={14} className="text-gray-300 flex-shrink-0" />
                                                <span className="flex-1 text-xs text-gray-700 truncate">{d.name}</span>
                                                {d.status && (
                                                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold border ${DOC_STATUS_STYLE[d.status] || "bg-gray-50 text-gray-600 border-gray-200"}`}>
                                                        {d.status}
                                                    </span>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <a
                                    href={`${portalBase}/leads/${lead.id}/documents`}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-800"
                                >
                                    <ExternalLink size={12} /> Manage documents
                                </a>
                            </div>
                        ) : tab === "notes" ? (
                            <div className="space-y-4">
                                <div>
                                    <p className="text-[13px] font-semibold text-gray-900 mb-2">Tags</p>
                                    {(data?.tags || []).length ? (
                                        <div className="flex flex-wrap gap-1.5">
                                            {data.tags.map((t) => (
                                                <span key={t} className="px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[11px] font-medium border border-gray-200">{t}</span>
                                            ))}
                                        </div>
                                    ) : <p className="text-xs text-gray-300 italic">No tags.</p>}
                                </div>
                                <div>
                                    <p className="text-[13px] font-semibold text-gray-900 mb-2">Internal notes</p>
                                    <AddNoteInline leadId={lead.id} portalBase={portalBase} staffOptions={staffOptions} onSaved={refetch} />
                                    {(data?.notes || []).length ? (
                                        <div className="space-y-2 mt-3">
                                            {data.notes.map((n) => (
                                                <div key={n.id} className="rounded-lg border border-gray-100 bg-gray-50/50 px-3 py-2">
                                                    <NotePrograms programs={n.programs} />
                                                    <p className="text-xs text-gray-700">{n.body}</p>
                                                    <p className="text-[10px] text-gray-400 mt-1">{n.author_name} · {fmtDateShort(n.created_at)}</p>
                                                </div>
                                            ))}
                                        </div>
                                    ) : <p className="text-xs text-gray-300 italic mt-3">No notes yet.</p>}
                                </div>
                            </div>
                        ) : (
                            <div>
                                <p className="text-[13px] font-semibold text-gray-900 mb-3">Journey progress</p>
                                <JourneyStepper currentIndex={milestoneIndex(data?.status)} />
                                <p className="text-xs text-gray-500 mt-4">
                                    Current stage: <span className="font-bold text-gray-900">{data?.status || "—"}</span>
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Sticky footer — Save only applies to the Personal info tab. */}
                {tab === "personal" && !loading && (
                    <div className="border-t border-gray-100 px-6 py-3 flex items-center justify-end gap-3 bg-gray-50/60">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 rounded-lg text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={savePersonal}
                            disabled={saving}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-xs font-bold shadow-sm hover:bg-black disabled:opacity-60 transition-colors"
                        >
                            {saving ? <Loader size={13} className="animate-spin" /> : <Check size={13} />}
                            Save changes
                        </button>
                    </div>
                )}
            </div>
        </div>,
        document.body
    );
}

// Grouped section inside the Personal-info tab.
function EditSection({ title, children }) {
    return (
        <section>
            <h3 className="text-[13px] font-semibold text-gray-900 mb-3">{title}</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3.5">{children}</div>
        </section>
    );
}

const EDIT_INPUT_CLS = "w-full px-3 py-2 border border-gray-200 rounded-lg text-sm text-gray-900 bg-white outline-none transition-all focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 hover:border-gray-300";

function EditField({ label, value, onChange, type = "text" }) {
    return (
        <label className="block">
            <span className="block text-[12px] font-medium text-gray-600 mb-1.5">{label}</span>
            <input type={type} value={value ?? ""} onChange={onChange} className={EDIT_INPUT_CLS} />
        </label>
    );
}

function EditSelect({ label, value, onChange, options = [] }) {
    return (
        <label className="block">
            <span className="block text-[12px] font-medium text-gray-600 mb-1.5">{label}</span>
            <select value={value ?? ""} onChange={onChange} className={`${EDIT_INPUT_CLS} cursor-pointer`}>
                {options.map((o) => <option key={o} value={o}>{o || "—"}</option>)}
            </select>
        </label>
    );
}

// ── Tab notification badge — "new sign-ups in the last 7 days" count ───────
// Red pill shown next to the Events / Registration tabs. Hidden at zero.
function TabBadge({ count }) {
    const n = Number(count) || 0;
    if (n <= 0) return null;
    return (
        <span className="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold tabular-nums leading-none">
            {n > 99 ? "99+" : n}
        </span>
    );
}

// ── Sortable header cell ───────────────────────────────────────────────────

function SortableTh({ label, sortKey, current, dir, onSort }) {
    const active = current === sortKey;
    const Icon = !active ? ArrowUpDown : dir === "asc" ? ArrowUp : ArrowDown;
    return (
        <th className="px-3 py-3">
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={`inline-flex items-center gap-1 hover:text-gray-900 transition-colors ${active ? "text-gray-900" : "text-gray-500"}`}
            >
                {label}
                <Icon size={10} strokeWidth={2.5} className={active ? "opacity-100" : "opacity-30"} />
            </button>
        </th>
    );
}

// ── Priority field — inline selector shown in the expanded row, above the
//    journey (mirrors the Cases board). Posts immediately to the role-agnostic
//    /admin/leads/{id}/priority endpoint. The coloured dot mirrors the row
//    avatar; an unset priority reads light gray.

function PriorityField({ lead }) {
    const [saving, setSaving] = useState(false);
    const value = lead.priority || "";

    const change = (e) => {
        const v = e.target.value;
        setSaving(true);
        router.post(`/admin/leads/${lead.id}/priority`, { priority: v || null }, {
            preserveScroll: true,
            preserveState: false,
            onError: () => toast.error("Could not update priority."),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-2 max-w-xs">
            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                Priority
            </p>
            <div className="flex items-center gap-1.5">
                <span className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${priorityDot(value)}`} />
                <select
                    value={value}
                    onChange={change}
                    disabled={saving}
                    className="flex-1 text-xs font-semibold text-gray-900 bg-transparent outline-none cursor-pointer disabled:opacity-60"
                >
                    <option value="">No priority</option>
                    <option value="urgent">Urgent</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
        </div>
    );
}

// ── Visa cell — inline "Visa applying for" dropdown ────────────────────────
// A compact native <select> of the active visa catalogue. The lead's current
// value is always selectable even when it's a free-text "Other" visa not in
// the catalogue, so saving never silently drops it.
function VisaCell({ lead, options = [], onChange, disabled = false }) {
    const current = lead.visa || "";
    // Fold the current value in if the catalogue doesn't contain it.
    const opts = current && ! options.includes(current) ? [current, ...options] : options;
    return (
        <select
            value={current}
            disabled={disabled}
            onClick={(e) => e.stopPropagation()}
            onChange={(e) => onChange(lead, e.target.value)}
            title={current || "No visa selected"}
            className={`w-[150px] truncate text-[11px] rounded-md border px-2 py-1.5 outline-none transition-colors cursor-pointer disabled:opacity-50 ${
                current
                    ? "border-blue-200 bg-blue-50 text-blue-700 font-semibold"
                    : "border-gray-200 bg-white text-gray-400"
            }`}
        >
            <option value="">—</option>
            {opts.map((v) => (
                <option key={v} value={v}>{v}</option>
            ))}
        </select>
    );
}

// ── Stage picker — clickable pill with popover menu ────────────────────────

// `readOnly` renders the stage as a plain badge — no caret, no menu. The agent
// portal reuses this table but has no stage endpoint: sales owns the pipeline
// once a referral is handed over, so the control comes off rather than 403.
export function StagePicker({ lead, stages, open, onToggle, onClose, onSelect, isSaving, readOnly = false }) {
    const menuRef = useRef(null);
    const triggerRef = useRef(null);
    const [menuPos, setMenuPos] = useState(null);

    // Position the (portalled, fixed) menu at the trigger; flip up when low.
    // Close on outside click + ESC.
    useEffect(() => {
        if (!open) { setMenuPos(null); return; }
        const r = triggerRef.current?.getBoundingClientRect();
        if (r) {
            const MENU_MAX = 360;
            const spaceBelow = window.innerHeight - r.bottom;
            const flipUp = spaceBelow < MENU_MAX && r.top > spaceBelow;
            setMenuPos({
                left: Math.max(8, r.left),
                top: flipUp ? undefined : Math.round(r.bottom + 4),
                bottom: flipUp ? Math.round(window.innerHeight - r.top + 4) : undefined,
            });
        }
        const onDocClick = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            onClose();
        };
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("mousedown", onDocClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onDocClick);
            document.removeEventListener("keydown", onKey);
        };
    }, [open, onClose]);

    const current = lead.status || "New Leads";

    return (
        <>
            {readOnly ? (
                <span
                    title="Sales manages this lead's pipeline stage"
                    className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border whitespace-nowrap uppercase ${stageClass(current)}`}
                >
                    <span className="truncate max-w-[180px]">{current}</span>
                </span>
            ) : (
                <button
                    ref={triggerRef}
                    type="button"
                    disabled={isSaving}
                    onClick={onToggle}
                    className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold border whitespace-nowrap uppercase hover:shadow-sm transition-all disabled:opacity-60 disabled:cursor-not-allowed ${stageClass(current)}`}
                >
                    <span className="truncate max-w-[180px]">{current}</span>
                    <ChevronDown size={10} strokeWidth={2.5} className="flex-shrink-0 opacity-60" />
                </button>
            )}

            {open && menuPos && createPortal(
                <div
                    ref={menuRef}
                    role="listbox"
                    style={{ position: "fixed", left: menuPos.left, top: menuPos.top, bottom: menuPos.bottom }}
                    className="z-[130] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5 w-[280px] max-h-[360px] overflow-y-auto"
                >
                    <p className="px-3 pt-2 pb-1.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                        Move to stage
                    </p>
                    {stages.map((s) => {
                        const active = s === current;
                        return (
                            <button
                                key={s}
                                type="button"
                                role="option"
                                aria-selected={active}
                                onClick={() => onSelect(s)}
                                className={`w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 transition-colors ${active ? "bg-indigo-50/60" : ""}`}
                            >
                                <span
                                    className="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                    style={{ backgroundColor: stageDotHex(stageClass, s) }}
                                />
                                <span className={`flex-1 text-[12.5px] truncate ${active ? "font-bold text-gray-900" : "font-medium text-gray-700"}`}>
                                    {s}
                                </span>
                                {active && <Check size={12} className="text-gray-900 flex-shrink-0" strokeWidth={3} />}
                            </button>
                        );
                    })}
                </div>,
                document.body
            )}
        </>
    );
}

// ── Agent picker — clickable pill + popover menu, mirrors StagePicker. Used
//    in the Edit-lead modal to assign a recruiting agent. ─────────────────────

function AgentPicker({ agent, agents = [], open, onToggle, onClose, onSelect, isSaving }) {
    const menuRef = useRef(null);
    const triggerRef = useRef(null);
    const [menuPos, setMenuPos] = useState(null);

    useEffect(() => {
        if (!open) { setMenuPos(null); return; }
        const r = triggerRef.current?.getBoundingClientRect();
        if (r) {
            const MENU_MAX = 320;
            const spaceBelow = window.innerHeight - r.bottom;
            const flipUp = spaceBelow < MENU_MAX && r.top > spaceBelow;
            setMenuPos({
                left: Math.max(8, Math.min(r.left, window.innerWidth - 240)),
                top: flipUp ? undefined : Math.round(r.bottom + 4),
                bottom: flipUp ? Math.round(window.innerHeight - r.top + 4) : undefined,
            });
        }
        const onDocClick = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            onClose();
        };
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("mousedown", onDocClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onDocClick);
            document.removeEventListener("keydown", onKey);
        };
    }, [open, onClose]);

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                disabled={isSaving}
                onClick={onToggle}
                className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold border whitespace-nowrap uppercase hover:shadow-sm transition-all disabled:opacity-60 disabled:cursor-not-allowed ${
                    agent ? "bg-violet-50 text-violet-700 border-violet-200" : "bg-gray-50 text-gray-500 border-gray-200 border-dashed"
                }`}
            >
                <UserCheck size={11} className="flex-shrink-0 opacity-70" />
                <span className="truncate max-w-[120px]">{agent?.name || "Unassigned"}</span>
                <ChevronDown size={10} strokeWidth={2.5} className="flex-shrink-0 opacity-60" />
            </button>

            {open && menuPos && createPortal(
                <div
                    ref={menuRef}
                    role="listbox"
                    style={{ position: "fixed", left: menuPos.left, top: menuPos.top, bottom: menuPos.bottom }}
                    className="z-[130] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5 w-[220px] max-h-[320px] overflow-y-auto"
                >
                    <p className="px-3 pt-2 pb-1.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                        Assign agent
                    </p>
                    <button
                        type="button"
                        role="option"
                        aria-selected={!agent}
                        onClick={() => onSelect("")}
                        className={`w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 transition-colors ${!agent ? "bg-violet-50/60" : ""}`}
                    >
                        <span className="w-5 h-5 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center flex-shrink-0">
                            <UserCheck size={11} />
                        </span>
                        <span className="flex-1 text-[12.5px] font-medium text-gray-700">Unassigned</span>
                        {!agent && <Check size={12} className="text-gray-900 flex-shrink-0" strokeWidth={3} />}
                    </button>
                    {agents.map((a) => {
                        const active = String(a.id) === String(agent?.id);
                        return (
                            <button
                                key={a.id}
                                type="button"
                                role="option"
                                aria-selected={active}
                                onClick={() => onSelect(a.id)}
                                className={`w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 transition-colors ${active ? "bg-violet-50/60" : ""}`}
                            >
                                <span className="w-5 h-5 rounded-full bg-violet-100 text-violet-700 text-[9px] font-bold flex items-center justify-center flex-shrink-0">
                                    {initials(a.name)}
                                </span>
                                <span className={`flex-1 text-[12.5px] truncate ${active ? "font-bold text-gray-900" : "font-medium text-gray-700"}`}>
                                    {a.name}
                                </span>
                                {active && <Check size={12} className="text-gray-900 flex-shrink-0" strokeWidth={3} />}
                            </button>
                        );
                    })}
                </div>,
                document.body
            )}
        </>
    );
}

// ── Leads Kanban — pipeline-stage swimlanes w/ drag-and-drop ───────────────
// Each Lead::STAGES value is a column. Cards drag between columns to change
// the lead's stage — optimistic UI update, POST to /portal/{role}/leads/{id}
// with { status: newStage }, revert + toast on failure.
//
// Local state mirrors the `filtered` prop so we can flip a lead's stage
// instantly before the server response. The parent re-renders with fresh
// `filtered` (from Inertia's redirect) afterwards, which resyncs.

function LeadsKanban({ filtered = [], statuses = [], portalBase }) {
    const [local, setLocal] = useState(filtered);
    useEffect(() => { setLocal(filtered); }, [filtered]);

    const [activeId, setActiveId] = useState(null);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor),
    );

    const grouped = useMemo(() => {
        const out = {};
        for (const s of statuses) out[s] = [];
        for (const l of local) {
            const k = l.status || "New Leads";
            (out[k] = out[k] || []).push(l);
        }
        return out;
    }, [local, statuses]);

    const cols = statuses.length ? statuses : Object.keys(grouped);
    const activeLead = activeId ? local.find((l) => String(l.id) === String(activeId)) : null;

    // Shared between drag-end and the kebab menu's stage picker. Optimistic
    // flip in local state → POST → revert + toast on failure.
    const changeStage = (leadId, newStage) => {
        const lead = local.find((l) => String(l.id) === String(leadId));
        if (! lead) return;
        if (! cols.includes(newStage)) return;
        if (lead.status === newStage) return;

        const previousStage = lead.status;

        setLocal((prev) => prev.map((l) =>
            l.id === lead.id ? { ...l, status: newStage } : l
        ));

        router.post(`${portalBase}/leads/${lead.id}`, { status: newStage }, {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setLocal((prev) => prev.map((l) =>
                    l.id === lead.id ? { ...l, status: previousStage } : l
                ));
                toast.error("Could not update stage");
            },
            onSuccess: () => {
                toast.success(`Moved to ${newStage}`);
            },
        });
    };

    const handleDragEnd = ({ active, over }) => {
        setActiveId(null);
        if (! over) return;
        changeStage(active.id, String(over.id));
    };

    return (
        <DndContext
            sensors={sensors}
            onDragStart={(e) => setActiveId(e.active.id)}
            onDragEnd={handleDragEnd}
            onDragCancel={() => setActiveId(null)}
        >
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                <div className="flex gap-3 overflow-x-auto pb-2">
                    {cols.map((stage) => (
                        <LeadKanbanColumn
                            key={stage}
                            stage={stage}
                            leads={grouped[stage] || []}
                            portalBase={portalBase}
                            statuses={cols}
                            onStageChange={changeStage}
                        />
                    ))}
                </div>
            </div>

            <DragOverlay dropAnimation={null}>
                {activeLead && (
                    <LeadKanbanCard lead={activeLead} portalBase={portalBase} isOverlay />
                )}
            </DragOverlay>
        </DndContext>
    );
}

function LeadKanbanColumn({ stage, leads, portalBase, statuses = [], onStageChange }) {
    const { setNodeRef, isOver } = useDroppable({ id: stage });

    return (
        <div className="flex-shrink-0 w-[280px] flex flex-col">
            <div className="flex items-center justify-between mb-2 px-1">
                <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border ${stageClass(stage)}`}>
                    {stage}
                </span>
                <span className="text-[10px] font-bold tabular-nums text-gray-500 bg-gray-100 px-1.5 rounded-full">
                    {leads.length}
                </span>
            </div>

            <div
                ref={setNodeRef}
                className={`bg-gray-50 rounded-xl p-2 min-h-[160px] max-h-[68vh] overflow-visible space-y-2 transition-colors ${
                    isOver ? "bg-blue-50 ring-2 ring-blue-200" : ""
                }`}
                role="region"
                aria-label={`${stage} column`}
            >
                {leads.length === 0 ? (
                    <p className="text-[11px] text-gray-400 text-center py-6">Drop a lead here</p>
                ) : (
                    leads.map((l) => (
                        <LeadKanbanCard
                            key={l.id}
                            lead={l}
                            portalBase={portalBase}
                            statuses={statuses}
                            onStageChange={onStageChange}
                            draggable
                        />
                    ))
                )}
            </div>
        </div>
    );
}

function LeadKanbanCard({ lead, portalBase, statuses = [], onStageChange, draggable = false, isOverlay = false }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: String(lead.id),
        data: { lead },
        disabled: ! draggable,
    });

    const [menuOpen, setMenuOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const menuRef = useRef(null);

    // Click-outside / Esc to close the kebab menu.
    useEffect(() => {
        if (! menuOpen) return;
        const onDown = (e) => {
            if (menuRef.current && ! menuRef.current.contains(e.target)) {
                setMenuOpen(false);
            }
        };
        const onKey = (e) => { if (e.key === "Escape") setMenuOpen(false); };
        document.addEventListener("pointerdown", onDown);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("pointerdown", onDown);
            document.removeEventListener("keydown", onKey);
        };
    }, [menuOpen]);

    const style = draggable
        ? {
              transform: CSS.Translate.toString(transform),
              opacity: isDragging && ! isOverlay ? 0.35 : 1,
          }
        : undefined;

    const inner = (
        <div className="relative bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-3">
            {/* Kebab menu — only on the in-board cards, not on the drag overlay. */}
            {draggable && ! isOverlay && (
                <div ref={menuRef} className="absolute top-2 right-2 z-30">
                    <button
                        type="button"
                        onPointerDown={(e) => e.stopPropagation()}
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            setMenuOpen((o) => ! o);
                        }}
                        className="p-1 rounded hover:bg-gray-100 text-gray-400 hover:text-gray-700"
                        aria-label="Card menu"
                        aria-expanded={menuOpen}
                    >
                        <MoreHorizontal size={14} />
                    </button>

                    {menuOpen && (
                        <div
                            className="absolute right-0 top-7 bg-white rounded-lg border border-gray-200 shadow-xl w-44 py-1"
                            onPointerDown={(e) => e.stopPropagation()}
                            onClick={(e) => { e.preventDefault(); e.stopPropagation(); }}
                        >
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    setMenuOpen(false);
                                    setEditOpen(true);
                                }}
                                className="w-full text-left px-3 py-2 text-[12px] hover:bg-gray-50 flex items-center gap-2"
                            >
                                <Pencil size={11} className="text-gray-500" />
                                Edit
                            </button>
                            <Link
                                href={`${portalBase}/leads/${lead.id}`}
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={(e) => { e.stopPropagation(); setMenuOpen(false); }}
                                className="w-full text-left px-3 py-2 text-[12px] hover:bg-gray-50 flex items-center gap-2 text-gray-700"
                            >
                                <ExternalLink size={11} className="text-gray-500" />
                                Open lead
                            </Link>
                        </div>
                    )}
                </div>
            )}

            <div className="flex items-start gap-2 pr-6">
                <span className={`w-8 h-8 rounded-full inline-flex items-center justify-center text-[11px] font-bold flex-shrink-0 ${avatarColor(lead.id)}`}>
                    {initials(lead.name)}
                </span>
                <div className="flex-1 min-w-0">
                    <p className="text-[13px] font-semibold text-gray-900 truncate">{lead.name || "—"}</p>
                    <p className="text-[10px] text-gray-500 font-mono">{lead.lead_id}</p>
                </div>
            </div>

            {(lead.email || lead.phone) && (
                <div className="mt-2 space-y-0.5">
                    {lead.email && (
                        <p className="text-[10.5px] text-gray-500 truncate inline-flex items-center gap-1">
                            <Mail size={9} className="text-gray-400" />
                            <span className="truncate">{lead.email}</span>
                        </p>
                    )}
                    {lead.phone && (
                        <p className="text-[10.5px] text-gray-500 truncate inline-flex items-center gap-1">
                            <Phone size={9} className="text-gray-400" />
                            {lead.phone}
                        </p>
                    )}
                </div>
            )}

            <div className="mt-2 pt-2 border-t border-gray-50 flex items-center justify-between text-[10px] text-gray-400">
                <span className="tabular-nums">{fmtDateShort(lead.created_at)}</span>
                {lead.source && <span className="truncate max-w-[120px]">{lead.source}</span>}
            </div>
        </div>
    );

    if (! draggable) {
        // DragOverlay rendering — bare card, no link wrapper.
        return (
            <div style={style} className={isOverlay ? "rotate-1 shadow-2xl" : ""}>
                {inner}
            </div>
        );
    }

    // Draggable card wraps an Inertia Link so a plain click navigates to
    // the detail page. The kebab + its menu live inside `inner` and stop
    // both pointerdown and click propagation so they don't engage drag or
    // bubble up to the Link.
    return (
        <>
            <div
                ref={setNodeRef}
                {...attributes}
                {...listeners}
                style={style}
                className="cursor-grab active:cursor-grabbing focus:outline-none focus:ring-2 focus:ring-gray-900 rounded-xl"
                tabIndex={0}
                role="button"
                aria-label={`${lead.name || "Lead"} ${lead.lead_id || ""}, ${lead.status || ""}`}
            >
                <Link
                    href={`${portalBase}/leads/${lead.id}`}
                    onClick={(e) => {
                        if (isDragging || menuOpen || editOpen) e.preventDefault();
                    }}
                    onPointerDown={(e) => e.stopPropagation()}
                    className="block"
                >
                    {inner}
                </Link>
            </div>

            <LeadEditModal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                lead={lead}
                statuses={statuses}
                portalBase={portalBase}
            />
        </>
    );
}

// ── Lead Edit Modal — stage + add a note in one shot ───────────────────────
// Opens from the kebab "Edit" item on a kanban card. Two updates run
// sequentially: stage first (only if changed), then note (only if filled).
// After both finish, Inertia's automatic re-render pulls fresh lead data
// from the controller so the card lands in the right column on refresh.

function LeadEditModal({ open, onClose, lead, statuses = [], portalBase }) {
    const [stage, setStage]           = useState(lead?.status || "");
    const [newNote, setNewNote]       = useState("");
    const [notes, setNotes]           = useState([]);
    const [loadingNotes, setLoading]  = useState(false);
    const [saving, setSaving]         = useState(false);
    const [error, setError]           = useState(null);

    // Reset state and pull notes whenever the modal opens for a new lead.
    useEffect(() => {
        if (! open) return;
        setStage(lead?.status || "");
        setNewNote("");
        setSaving(false);
        setError(null);

        setLoading(true);
        fetch(`/admin/leads/${lead.id}/notes`, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        })
            .then((r) => r.ok ? r.json() : { notes: [] })
            .then((d) => setNotes(d.notes || []))
            .catch(() => setNotes([]))
            .finally(() => setLoading(false));
    }, [open, lead?.id, lead?.status]);

    if (! open) return null;

    const stageChanged = stage && stage !== lead?.status;
    const hasNote      = newNote.trim().length > 0;
    const canSave      = ! saving && (stageChanged || hasNote);

    const finish = () => {
        setSaving(false);
        toast.success("Lead updated");
        onClose?.();
    };

    const postNote = () => {
        if (! hasNote) {
            finish();
            return;
        }
        router.post(`${portalBase}/leads/${lead.id}/notes`, { body: newNote.trim() }, {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setSaving(false);
                setError(stageChanged
                    ? "Stage saved, but the note failed."
                    : "Could not save the note.");
            },
            onSuccess: finish,
        });
    };

    const handleSave = () => {
        if (! canSave) return;
        setSaving(true);
        setError(null);

        if (stageChanged) {
            router.post(`${portalBase}/leads/${lead.id}`, { status: stage }, {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    setSaving(false);
                    setError("Could not update the stage.");
                },
                onSuccess: postNote,
            });
        } else {
            postNote();
        }
    };

    const fmtWhen = (iso) =>
        iso ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";

    return (
        <div
            className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6"
            onPointerDown={(e) => e.stopPropagation()}
            onClick={(e) => {
                e.stopPropagation();
                if (e.target === e.currentTarget) onClose?.();
            }}
        >
            <div
                className="bg-white w-full max-w-xl rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[90vh] overflow-hidden flex flex-col"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <div className="min-w-0 flex items-center gap-3">
                        <span className={`w-10 h-10 rounded-full inline-flex items-center justify-center text-[12px] font-bold flex-shrink-0 ${avatarColor(lead?.id)}`}>
                            {initials(lead?.name)}
                        </span>
                        <div className="min-w-0">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Edit lead</p>
                            <h3 className="text-sm font-semibold text-gray-900 truncate">
                                {lead?.name || "—"}
                                <span className="ml-2 font-mono text-[11px] text-gray-500">{lead?.lead_id}</span>
                            </h3>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-md hover:bg-gray-100 text-gray-500"
                        aria-label="Close"
                    >
                        <X size={16} />
                    </button>
                </div>

                {/* Body — scrollable */}
                <div className="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                    {error && (
                        <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[12px]">
                            {error}
                        </div>
                    )}

                    {/* Lead info */}
                    <section>
                        <h4 className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Lead info</h4>
                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-[12px]">
                            <InfoRow icon={<Mail size={11} />}        label="Email"   value={lead?.email} />
                            <InfoRow icon={<Phone size={11} />}       label="Phone"   value={lead?.phone} />
                            <InfoRow icon={<FileTextIcon size={11}/>} label="Source"  value={lead?.source} />
                            <InfoRow icon={<CalendarClock size={11}/>}label="Created" value={fmtWhen(lead?.created_at)} />
                        </dl>
                    </section>

                    {/* Stage */}
                    <section>
                        <h4 className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Stage</h4>
                        <select
                            value={stage}
                            onChange={(e) => setStage(e.target.value)}
                            disabled={saving}
                            className="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm bg-white"
                        >
                            {statuses.map((s) => (
                                <option key={s} value={s}>{s}</option>
                            ))}
                        </select>
                        {stageChanged && (
                            <p className="mt-1.5 text-[10px] text-gray-500 inline-flex items-center gap-1">
                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${stageClass(lead?.status || "")}`}>
                                    {lead?.status || "—"}
                                </span>
                                →
                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${stageClass(stage)}`}>
                                    {stage}
                                </span>
                            </p>
                        )}
                    </section>

                    {/* Internal notes */}
                    <section>
                        <h4 className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2 inline-flex items-center gap-1.5">
                            <StickyNote size={11} /> Internal notes
                            <span className="text-gray-400 font-normal normal-case tracking-normal">({notes.length})</span>
                        </h4>

                        <div className="rounded-xl border border-gray-100 bg-gray-50/60 max-h-56 overflow-y-auto divide-y divide-gray-100">
                            {loadingNotes ? (
                                <p className="px-3 py-4 text-[11px] text-gray-400 text-center">Loading…</p>
                            ) : notes.length === 0 ? (
                                <p className="px-3 py-4 text-[11px] text-gray-400 text-center">No internal notes yet.</p>
                            ) : (
                                notes.map((n) => (
                                    <div key={n.id} className="px-3 py-2.5">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <div className="flex items-center gap-1.5 min-w-0">
                                                {n.pinned && (
                                                    <span className="text-[9px] font-bold uppercase tracking-wider text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">
                                                        Pinned
                                                    </span>
                                                )}
                                                <span className="text-[11.5px] font-semibold text-gray-900 truncate">
                                                    {n.author_name || "Unknown"}
                                                </span>
                                                {n.author_role && (
                                                    <span className="text-[9px] font-bold uppercase tracking-wider text-gray-400">
                                                        {n.author_role}
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-[10px] text-gray-400 tabular-nums flex-shrink-0">
                                                {fmtWhen(n.created_at)}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-[12px] text-gray-700 whitespace-pre-wrap leading-snug">
                                            {n.body}
                                        </p>
                                    </div>
                                ))
                            )}
                        </div>

                        {/* Quick add */}
                        <div className="mt-2">
                            <label className="block text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                                Add a note <span className="font-normal text-gray-400 normal-case tracking-normal">(optional)</span>
                            </label>
                            <textarea
                                value={newNote}
                                onChange={(e) => setNewNote(e.target.value)}
                                disabled={saving}
                                rows={3}
                                placeholder="Quick note — saved alongside the stage change."
                                className="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm resize-none"
                            />
                        </div>
                    </section>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100 bg-gray-50">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={saving}
                        className="px-4 py-2 rounded-lg text-[12px] font-bold uppercase tracking-wider text-gray-700 hover:bg-gray-200 disabled:opacity-40"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={! canSave}
                        className="px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors disabled:opacity-40"
                    >
                        {saving ? "Saving…" : "Save"}
                    </button>
                </div>
            </div>
        </div>
    );
}

function InfoRow({ icon, label, value }) {
    return (
        <div className="min-w-0">
            <dt className="text-[10px] font-bold uppercase tracking-wider text-gray-400 inline-flex items-center gap-1 mb-0.5">
                {icon} {label}
            </dt>
            <dd className="text-gray-800 truncate">{value || <span className="text-gray-400">—</span>}</dd>
        </div>
    );
}
