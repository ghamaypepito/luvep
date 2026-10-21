import { Head, Link, router } from "@inertiajs/react";
import { createPortal } from "react-dom";
import React, { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import {
    BookOpen, ChevronRight, ChevronLeft, Clock, FileCheck, FolderOpen,
    GraduationCap, Search, Users, Mail, Phone, MapPin, Calendar, School,
    CreditCard, FileText, ExternalLink, Languages, ClipboardList,
    Save, Edit2, ArrowUpDown, ArrowUp, ArrowDown,
    ChevronDown, Check, TrendingUp, Globe,
    UserPlus, Pencil, Trash2, Copy, MoreHorizontal, Paperclip, KeyRound, Flag, Briefcase,
} from "lucide-react";
import { AvatarPhoto } from "@/components/ui/Avatar";
import AddEditStudentModal from "./AddEditStudentModal";
import CaseFilesModal from "@/components/immigration/CaseFilesModal";
import { priorityRing } from "@/utils/priority";

const PAGE_SIZE = 25;

// Per-user palette for the colored avatar — same hash always picks the
// same colour so the eye learns the student.
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

// Write the public /track link + raw code to the clipboard in a single
// payload so staff can paste it straight into WhatsApp / email / SMS.
// Mirrors the same helper used in Sales/Leads + Immigration/Cases.
function copyTrackingLink(code) {
    if (!code) return;
    const url = `${window.location.origin}/track/${code}`;
    navigator.clipboard?.writeText(url).then(
        () => toast.success("Tracking link copied", { description: url }),
        () => toast.error("Could not copy — your browser blocked clipboard access")
    );
}

/**
 * Three-dot row action menu. Items are `{ key, label, icon, onClick?,
 * href?, external?, danger? }`. Portal-anchored so it escapes table
 * overflow. Mirrors the menu used in Immigration/Cases.
 */
function RowMenu({ items = [] }) {
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0 });
    const triggerRef = useRef(null);
    const menuRef = useRef(null);

    useEffect(() => {
        if (!open || !triggerRef.current) return;
        const place = () => {
            const r = triggerRef.current.getBoundingClientRect();
            setCoords({ top: r.bottom + 6, left: r.right - 220 });
        };
        place();
        const onScroll = () => place();
        window.addEventListener("scroll", onScroll, true);
        window.addEventListener("resize", onScroll);
        return () => {
            window.removeEventListener("scroll", onScroll, true);
            window.removeEventListener("resize", onScroll);
        };
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const onDocClick = (e) => {
            if (menuRef.current?.contains(e.target) || triggerRef.current?.contains(e.target)) return;
            setOpen(false);
        };
        const onKey = (e) => { if (e.key === "Escape") setOpen(false); };
        document.addEventListener("mousedown", onDocClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onDocClick);
            document.removeEventListener("keydown", onKey);
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
                className={`inline-flex items-center justify-center w-7 h-7 rounded-md transition-colors ${open ? "bg-gray-100 text-gray-900" : "text-gray-500 hover:text-gray-900 hover:bg-gray-100"}`}
            >
                <MoreHorizontal size={14} />
            </button>

            {open && typeof document !== "undefined" && createPortal(
                <div
                    ref={menuRef}
                    role="menu"
                    style={{
                        position: "fixed",
                        top: coords.top,
                        left: Math.max(8, coords.left),
                        width: 220,
                    }}
                    className="z-[60] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5"
                >
                    {items.map((it) => {
                        const Icon = it.icon;
                        if (it.disabled) {
                            return (
                                <div key={it.key} className="flex items-center gap-2.5 px-3 py-2 text-xs text-gray-400 cursor-default select-none">
                                    {Icon && <Icon size={13} className="text-gray-300" />}
                                    {it.label}
                                </div>
                            );
                        }
                        const tone = it.danger
                            ? "text-red-600 hover:bg-red-50"
                            : "text-gray-700 hover:bg-gray-50";
                        const iconTone = it.danger ? "text-red-400" : "text-gray-400";
                        const inner = (
                            <span className={`flex items-center gap-2.5 px-3 py-2 text-xs ${tone} cursor-pointer`}>
                                {Icon && <Icon size={13} className={iconTone} />}
                                {it.label}
                            </span>
                        );
                        if (it.href) {
                            // External links bypass Inertia so target+rel work.
                            if (it.external) {
                                return (
                                    <a
                                        key={it.key}
                                        href={it.href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        onClick={() => setOpen(false)}
                                        className="block"
                                    >
                                        {inner}
                                    </a>
                                );
                            }
                            return (
                                <Link key={it.key} href={it.href} onClick={() => setOpen(false)} className="block">
                                    {inner}
                                </Link>
                            );
                        }
                        return (
                            <button
                                key={it.key}
                                type="button"
                                onClick={() => handleClick(it)}
                                className="w-full text-left block"
                            >
                                {inner}
                            </button>
                        );
                    })}
                </div>,
                document.body
            )}
        </>
    );
}

// Stage chip colours mirror the Leads palette so a student's stage reads
// the same here as it does on the lead detail screen.
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
};
const stageClass = (s) => STAGE_STYLES[s] || "bg-gray-100 text-gray-700 border-gray-200";

// Education-team lifecycle stages — single source of truth on the
// frontend, mirrored to PHP via Lead::EDUCATION_STAGES. Order matters
// (it's the dropdown order).
const EDUCATION_STAGES = [
    "New Lead",
    "Pre-Screening Done",
    "For Proposal",
    "Proposal Sent",
    "Engagement Sent",
    "Goal Setting Done",
    "School Enrolment",
    "Conditional Offer",
    "Unconditional Offer",
    "Endorsed to Immigration",
    "Visa Lodged",
    "Approved Visa",
    "Started Course",
    // Education-only end-stages — the Education team keeps progressing a
    // student after the visa outcome without touching Immigration status.
    // Deliberately NOT in IMMIGRATION_EDUCATION_STAGES (no handoff).
    "Course Ongoing",
    "Completed",
    "For Relodgement",
    "Declined Visa",
];

// Subset of EDUCATION_STAGES that hand the lead off to Immigration.
// Mirrors Lead::EDUCATION_STAGES_IMMIGRATION server-side. Set, not
// array, so departmentsOf() is an O(1) lookup per row.
const IMMIGRATION_EDUCATION_STAGES = new Set([
    "Endorsed to Immigration",
    "Visa Lodged",
    "Approved Visa",
    "For Relodgement",
    "Declined Visa",
]);

// English-team sub-stage list (mirrors Lead::ENGLISH_STAGES).
const ENGLISH_STAGES = [
    "PTE Review",
    "DIY Review",
    "For PTE Mocktest",
    "For PTE Exam",
];

const ENGLISH_STAGE_STYLES = {
    "PTE Review":       "bg-purple-100 text-purple-800 border-purple-200",
    "DIY Review":       "bg-violet-100 text-violet-800 border-violet-200",
    "For PTE Mocktest": "bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200",
    "For PTE Exam":     "bg-pink-100 text-pink-800 border-pink-200",
};
const englishStageClass = (s) => ENGLISH_STAGE_STYLES[s] || "bg-gray-100 text-gray-700 border-gray-200";

// Immigration-team sub-stage list (mirrors Lead::IMMIGRATION_STAGES).
const IMMIGRATION_STAGES = [
    "For Assessment",
    "Endorsed",
    "Agreement Sent",
    "Agreement Signed",
    "For Agreement & Invoice",
    "Invoice Paid",
    "Request to Lodge",
    "Visa Lodged",
    "Interim Visa Issued",
    "Request for Information",
    "RFI Responded",
    "Approved in Principle",
    "Approved Visa",
    "Decline Visa",
    "Withdrawn",
];

// Named people who can own a lead at an English / Immigration stage
// (mirrors Lead::ENGLISH_STAGE_ASSIGNEES / IMMIGRATION_STAGE_ASSIGNEES).
const STAGE_ASSIGNEES = {
    english:     ["Paula", "Frank", "DIY"],
    immigration: ["Hendry", "Tarun", "Dev"],
};

const IMMIGRATION_STAGE_STYLES = {
    "For Assessment":         "bg-amber-100 text-amber-800 border-amber-200",
    "Endorsed":               "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Visa Lodged":            "bg-violet-100 text-violet-800 border-violet-200",
    "Request for Information":"bg-orange-100 text-orange-800 border-orange-200",
    "Approved in Principle":  "bg-cyan-100 text-cyan-800 border-cyan-200",
    "Approved Visa":          "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Decline Visa":           "bg-red-100 text-red-700 border-red-200",
};
const immigrationStageClass = (s) => IMMIGRATION_STAGE_STYLES[s] || "bg-gray-100 text-gray-700 border-gray-200";

// Each tab maps to a (column, stages list, styler) triple so the same
// picker + filter pills can serve all three departments without N copies.
const TAB_STAGE_CONFIG = {
    education: {
        field:  "education_stage",
        stages: EDUCATION_STAGES,
        styler: (s) => EDUCATION_STAGE_STYLES[s] || "bg-gray-100 text-gray-700 border-gray-200",
        label:  "Move to stage",
    },
    english: {
        field:  "english_stage",
        stages: ENGLISH_STAGES,
        styler: englishStageClass,
        label:  "Move to English stage",
    },
    immigration: {
        field:  "immigration_stage",
        stages: IMMIGRATION_STAGES,
        styler: immigrationStageClass,
        label:  "Move to Immigration stage",
    },
};

// Each Education stage gets its own chip palette so the eye can pick
// out the stage at a glance. Falls through to the global stageClass
// helper for any other (legacy) lead status that might appear.
const EDUCATION_STAGE_STYLES = {
    "Endorsed to School":       "bg-sky-100 text-sky-800 border-sky-200",
    "Conditional Offer":        "bg-amber-100 text-amber-800 border-amber-200",
    "Unconditional Offer":      "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Endorsed to Immigration":  "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Visa Lodged":              "bg-violet-100 text-violet-800 border-violet-200",
    "Approved in Principle":    "bg-cyan-100 text-cyan-800 border-cyan-200",
    "Request for Information":  "bg-orange-100 text-orange-800 border-orange-200",
    "Approved Visa":            "bg-green-100 text-green-800 border-green-200",
    "Started Course":           "bg-teal-100 text-teal-800 border-teal-200",
    "Course Ongoing":           "bg-blue-100 text-blue-800 border-blue-200",
    "Completed":                "bg-lime-100 text-lime-800 border-lime-200",
};
const educationStageClass = (s) => EDUCATION_STAGE_STYLES[s] || stageClass(s);

const fmtPct = (a, t) => (t > 0 ? Math.round((a / t) * 100) : 0);

// General lead priority (mirrors the Leads / Cases board): urgent | medium |
// low. Unset reads light gray. Edited inline via the role-agnostic
// /admin/leads/{id}/priority endpoint (a student is just a lead).
const PRIORITY_OPTIONS = [
    { value: "urgent", label: "Urgent", dot: "bg-red-500" },
    { value: "medium", label: "Medium", dot: "bg-amber-500" },
    { value: "low", label: "Low", dot: "bg-emerald-500" },
];
const priorityDot = (p) => PRIORITY_OPTIONS.find((o) => o.value === p)?.dot || "bg-gray-300";

/**
 * Education owns this screen, but Sales and Immigration render it too. Every
 * link and POST has to stay inside the portal the staffer is actually in —
 * each portal's routes are gated to its own roles, so a hardcoded
 * /portal/education/… URL would 403 for a sales user.
 */
const portalBase = () => {
    const path = typeof window === "undefined" ? "" : window.location.pathname;
    // agent / sub-agent included so the "view lead" links stay inside the
    // portal the staffer opened this screen from.
    const match = path.match(/^\/portal\/(sales|education|immigration|sub-agent|agent)\b/);
    return match ? `/portal/${match[1]}` : "/portal/education";
};

/**
 * Whether this screen is being viewed by a portal that cannot write to it.
 *
 * The recruiting portals (agent / sub-agent) render the same Students screen to
 * follow their own referrals through, but every write here belongs to education
 * or sales and none of those endpoints exist under their prefix — so the
 * controls come off rather than 403 on click. Decided server-side.
 */
const ReadOnlyContext = React.createContext(false);
const useReadOnly = () => React.useContext(ReadOnlyContext);

// Shared "Request portal access" row-menu item (same flow as the Sales Leads
// page). Shows the request action while the client has no active invitation;
// otherwise shows the current status as a disabled line.
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

function StudentPriority({ student }) {
    const readOnly = useReadOnly();
    const [saving, setSaving] = useState(false);
    const value = student.priority || "";
    const change = (e) => {
        const v = e.target.value;
        setSaving(true);
        router.post(`/admin/leads/${student.id}/priority`, { priority: v || null }, {
            preserveScroll: true,
            preserveState: false,
            onError: () => toast.error("Could not update priority."),
            onFinish: () => setSaving(false),
        });
    };
    return (
        <span className="inline-flex items-center gap-1" title="Priority" onClick={(e) => e.stopPropagation()}>
            <span className={`w-2 h-2 rounded-full flex-shrink-0 ${priorityDot(value)}`} />
            {readOnly ? (
                <span className="text-[10px] font-semibold text-gray-500">
                    {PRIORITY_OPTIONS.find((o) => o.value === value)?.label || "Priority"}
                </span>
            ) : (
            <select
                value={value}
                onChange={change}
                disabled={saving}
                className="text-[10px] font-semibold text-gray-500 bg-transparent outline-none cursor-pointer disabled:opacity-60 hover:text-gray-800"
            >
                <option value="">Priority</option>
                {PRIORITY_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            )}
        </span>
    );
}

const fmtDate = (iso) => {
    if (!iso) return "";
    try {
        return new Date(iso).toLocaleDateString("en-NZ", { day: "2-digit", month: "short", year: "numeric" });
    } catch { return ""; }
};

// Intake may be a proper date (from the date picker → "11 May 2026") or a
// legacy free-text value ("31 October", "February 2027"). Reformat only real
// ISO dates; keep everything else verbatim. Parse the date parts by hand so a
// date-only string isn't shifted a day by the local timezone.
const fmtIntake = (val) => {
    if (!val) return "";
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(val));
    if (m) {
        try {
            return new Date(+m[1], +m[2] - 1, +m[3])
                .toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" });
        } catch { /* fall through to raw */ }
    }
    return val;
};

export default function EducationStudents({ students = [], schoolOptions = [], programOptions = [], agentOptions = [], readOnly = false }) {
    // Add / edit student modal — null = closed, {} = new, object = edit.
    const [editingStudent, setEditingStudent] = useState(null);
    const [studentModalOpen, setStudentModalOpen] = useState(false);
    const [filesStudent, setFilesStudent] = useState(null); // student whose file history is open

    const openNewStudent = () => { setEditingStudent(null); setStudentModalOpen(true); };
    const openEditStudent = (s) => { setEditingStudent(s); setStudentModalOpen(true); };
    const closeStudentModal = () => { setStudentModalOpen(false); setEditingStudent(null); };

    const deleteStudent = (s) => {
        if (! window.confirm(`Delete "${s.name}"? This archives the record — it drops off every list but notes, documents and history are kept and can be restored.`)) return;
        router.post(`${portalBase()}/students/${s.id}/destroy`, {}, { preserveScroll: true, preserveState: true });
    };

    // Request Lead Portal access for a student — routed through whichever
    // portal the staffer is in (each portal gates its own request route).
    const requestPortal = (s) => {
        if (! window.confirm(`Request Lead Portal access for ${s.name || "this client"}? Admin will review and approve.`)) return;
        router.post(`${portalBase()}/leads/${s.id}/portal-invitation/request`, {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success("Portal access requested."),
            onError: () => toast.error("Could not request portal access."),
        });
    };

    const [search, setSearch] = useState("");
    const [expandedId, setExpandedId] = useState(null);
    const [selectedIds, setSelectedIds] = useState(new Set());
    // Which row's stage popover is open. One at a time, matching the
    // sales-portal Leads StagePicker behaviour.
    const [openStageMenuId, setOpenStageMenuId] = useState(null);
    // Toolbar state. `view` is no longer table/kanban — it's the
    // department tab the user is on. Precedence Immigration > English >
    // Education guarantees a lead only appears under one tab even when
    // multiple flags are set, so "moving" a lead to another department
    // hides it from its old tab automatically.
    const [view, setView] = useState("education");
    const [stageFilter, setStageFilter] = useState("All");

    // Which department tabs this lead belongs to. A lead can be under MORE THAN
    // ONE at once — e.g. a student the Immigration team has also taken on stays
    // visible under Education (they are still a student) AND appears under
    // Immigration. Membership, not single-owner precedence:
    //   - Immigration: is_immigration_case, an immigration_stage, or an
    //     Education stage in the immigration-handoff set.
    //   - English:     an english_stage, or the pipeline stage = "English Pro".
    //   - Education:    an explicit student (is_student), or a lead that isn't
    //     owned by either other department (so pure-education leads still land
    //     here).
    const departmentsOf = (s) => {
        const set = new Set();
        const inImmigration = s.is_immigration_case
            || !! s.immigration_stage
            || IMMIGRATION_EDUCATION_STAGES.has(s.education_stage);
        const inEnglish = !! s.english_stage || s.stage === "English Pro";
        if (inImmigration) set.add("immigration");
        if (inEnglish) set.add("english");
        if (s.is_student || (! inImmigration && ! inEnglish)) set.add("education");
        return set;
    };

    // Count badges on the tabs — total leads per department, regardless
    // of stage-pill filter or search. A dual-department lead counts in each.
    const deptCounts = useMemo(() => {
        const c = { education: 0, english: 0, immigration: 0 };
        students.forEach((s) => departmentsOf(s).forEach((d) => { c[d]++; }));
        return c;
    }, [students]);
    const [sortKey, setSortKey] = useState("date_engaged");
    const [sortDir, setSortDir] = useState("desc");
    const [page, setPage] = useState(1);

    const toggleExpand = (id) => setExpandedId(expandedId === id ? null : id);

    const toggleSort = (key) => {
        if (sortKey === key) setSortDir(sortDir === "asc" ? "desc" : "asc");
        else { setSortKey(key); setSortDir("desc"); }
    };

    const tabConfig = TAB_STAGE_CONFIG[view];
    const searching = search.trim().length > 0;

    // When a search is active the three tabs act as one — resolve each result
    // to its own primary department (Immigration > English > Education) so its
    // status chip stays correct even though it surfaced from another tab.
    const primaryDeptOf = (s) => {
        const set = departmentsOf(s);
        if (set.has("immigration")) return "immigration";
        if (set.has("english")) return "english";
        return "education";
    };

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const rows = students.filter((s) => {
            if (q) {
                // A search spans ALL three department tabs — a name that lives
                // under English or Immigration still surfaces here.
                // Intake is matched both raw ("2026-05-11") and formatted
                // ("11 May 2026") so either spelling finds it; school covers the
                // FK name too.
                const hay = `${s.name || ""} ${s.email || ""} ${s.lead_id || ""} ${s.phone || ""} ${s.program || ""} ${s.school || ""} ${s.school_name || ""} ${s.intake || ""} ${fmtIntake(s.intake)} ${s.location || ""} ${s.status || ""} ${s.education_stage || ""} ${s.english_stage || ""} ${s.immigration_stage || ""}`.toLowerCase();
                return hay.includes(q);
            }
            // No search — scope to the active department tab. Membership, not
            // single-owner: a student the Immigration team also handles stays
            // under Education (and shows under Immigration too).
            if (! departmentsOf(s).has(view)) return false;
            // Stage pill — matches against the tab's own stage column.
            if (stageFilter !== "All") {
                const stage = s[tabConfig.field];
                if (stage !== stageFilter) return false;
            }
            return true;
        });

        const dir = sortDir === "asc" ? 1 : -1;
        return [...rows].sort((a, b) => {
            const av = a[sortKey] ?? "";
            const bv = b[sortKey] ?? "";
            if (av < bv) return -1 * dir;
            if (av > bv) return 1 * dir;
            return 0;
        });
    }, [students, search, stageFilter, view, sortKey, sortDir, tabConfig.field]);

    // Auto-switch tabs on search: if the active tab holds no match but another
    // department does, jump to the first department that does — so searching a
    // name that lives under English/Immigration surfaces it (and highlights the
    // right tab) without switching by hand.
    useEffect(() => {
        const q = search.trim().toLowerCase();
        if (!q) return;
        const matches = (s) => `${s.name || ""} ${s.email || ""} ${s.lead_id || ""} ${s.phone || ""} ${s.program || ""} ${s.school || ""} ${s.school_name || ""} ${s.intake || ""} ${fmtIntake(s.intake)} ${s.location || ""} ${s.status || ""} ${s.education_stage || ""} ${s.english_stage || ""} ${s.immigration_stage || ""}`.toLowerCase().includes(q);
        if (students.some((s) => departmentsOf(s).has(view) && matches(s))) return;
        for (const d of ["education", "english", "immigration"]) {
            if (students.some((s) => departmentsOf(s).has(d) && matches(s))) {
                setView(d);
                setStageFilter("All");
                setPage(1);
                return;
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, students]);

    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const safePage = Math.min(page, totalPages);
    const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

    const toggleSelect = (id) => {
        const next = new Set(selectedIds);
        next.has(id) ? next.delete(id) : next.add(id);
        setSelectedIds(next);
    };
    const toggleSelectAll = () => {
        if (selectedIds.size === paged.length && paged.length > 0) setSelectedIds(new Set());
        else setSelectedIds(new Set(paged.map((s) => s.id)));
    };

    const stats = useMemo(() => {
        const total       = students.length;
        const withPlan    = students.filter((s) => !! s.program).length;
        const pendingDocs = students.filter((s) => (s.docs_total || 0) > 0 && (s.docs_approved || 0) < s.docs_total).length;

        // Stage breakdown — count students by which stage they're parked
        // at right now. Builds one row per canonical stage even if zero
        // so the bar layout stays the same shape across renders. Also
        // computes a "Not started" bucket for students with no stage yet.
        const tally = (stages, field, department) => {
            const counts = Object.fromEntries(stages.map((s) => [s, 0]));
            let notStarted = 0;
            const members = students.filter((s) => departmentsOf(s).has(department));
            for (const s of members) {
                const v = s[field];
                if (v && counts[v] !== undefined) counts[v]++;
                else if (! v) notStarted++;
            }
            return { counts, notStarted, total: members.length };
        };

        return {
            total,
            withPlan,
            pendingDocs,
            education:   tally(EDUCATION_STAGES,   "education_stage", "education"),
            english:     tally(ENGLISH_STAGES,     "english_stage", "english"),
            immigration: tally(IMMIGRATION_STAGES, "immigration_stage", "immigration"),
        };
    }, [students]);

    return (
        <ReadOnlyContext.Provider value={readOnly}>
        <div className="space-y-4">
            <Head title="Students — Education" />

            {/* Header */}
            <div className="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Students</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Pipeline · {filtered.length} {filtered.length === 1 ? "student" : "students"}
                    </p>
                </div>
            </div>

            {/* Stage distribution — small line graph for Education only. */}
            <StageDistribution stats={stats} students={students} department={view} />

            {/* Toolbar — tabs + filter pills + sort + search, mirroring the
                sales-portal Leads toolbar so the two surfaces feel paired. */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                {/* Department tabs — Education / English / Immigration.
                    Each tab filters to leads currently owned by that
                    department; precedence is Immigration > English >
                    Education so a single lead can't appear in more than
                    one tab. Count badges read from the unfiltered set. */}
                <div className="flex items-center border-b border-gray-100 px-4 sm:px-5">
                    <div className="flex items-center gap-1">
                        {[
                            ["education",   "Education"],
                            ["english",     "English"],
                            ["immigration", "Immigration"],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => { setView(key); setStageFilter("All"); setPage(1); }}
                                className={`inline-flex items-center gap-1.5 px-3 py-3 text-xs font-bold transition-colors -mb-px ${
                                    view === key
                                        ? "text-gray-900 border-b-2 border-gray-900"
                                        : "text-gray-400 border-b-2 border-transparent hover:text-gray-700"
                                }`}
                            >
                                {label}
                                <span className={`text-[10px] tabular-nums px-1.5 py-0.5 rounded ${view === key ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-500'}`}>
                                    {deptCounts[key] || 0}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Pills + sort + search */}
                <div className="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between p-4 sm:p-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex flex-wrap gap-1.5 max-w-3xl">
                            <button
                                type="button"
                                onClick={() => { setStageFilter("All"); setPage(1); }}
                                className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all border ${
                                    stageFilter === "All"
                                        ? "bg-gray-900 text-white border-gray-900"
                                        : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
                                }`}
                            >
                                All
                            </button>
                            {tabConfig.stages.map((s) => (
                                <button
                                    key={s}
                                    type="button"
                                    onClick={() => { setStageFilter(s); setPage(1); }}
                                    className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all border ${
                                        stageFilter === s
                                            ? "bg-gray-900 text-white border-gray-900"
                                            : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
                                    }`}
                                >
                                    {s}
                                </button>
                            ))}
                        </div>
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
                                placeholder="Search name, school, intake…"
                                className="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-200 bg-white text-xs placeholder-gray-400 focus:outline-none focus:border-gray-400 transition-all"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {/* New student — placed below the toolbar, above the table. */}
            {! readOnly && (
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={openNewStudent}
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 transition-colors shadow-sm"
                    >
                        <UserPlus size={15} /> New student
                    </button>
                </div>
            )}

            {/* Table — always rendered now; the `view` state selects the
                department tab rather than swapping into a kanban. */}
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
                                <th className="pr-2 py-3 w-6" />
                                <SortableTh label="Student"   sortKey="name"         current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Status"    sortKey="status"       current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Programme & School" sortKey="program" current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Intake"    sortKey="intake"       current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <SortableTh label="Engaged"   sortKey="date_engaged" current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <th className="px-3 py-3">Docs</th>
                                <SortableTh label="Updated"   sortKey="updated_at"   current={sortKey} dir={sortDir} onSort={toggleSort} />
                                <th className="px-3 py-3 text-right pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {paged.length === 0 ? (
                                <tr>
                                    <td colSpan={10} className="px-6 py-20 text-center">
                                        <div className="flex flex-col items-center gap-2 text-gray-400">
                                            <Users size={22} />
                                            <p className="text-sm font-medium">
                                                {students.length === 0 ? "No students yet." : "No students match your search."}
                                            </p>
                                            {students.length === 0 && (
                                                <p className="text-[11px]">Leads who sign their consultancy agreement appear here automatically.</p>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : paged.map((s) => {
                                const isSelected = selectedIds.has(s.id);
                                const isExpanded = expandedId === s.id;
                                // Docs progress mirrors Immigration Cases / Leads:
                                // % of the visible checklist that's been submitted.
                                const hasDocs  = (s.checklist_total || 0) > 0;
                                const pct      = fmtPct(s.checklist_submitted, s.checklist_total);

                                return (
                                    <React.Fragment key={s.id}>
                                        <tr
                                            className={`group transition-colors ${
                                                isExpanded ? "bg-indigo-50/30" : isSelected ? "bg-amber-50/30" : "hover:bg-gray-50/50"
                                            }`}
                                        >
                                            <td className="pl-4 pr-2 py-2.5">
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => toggleSelect(s.id)}
                                                    className="w-3.5 h-3.5 rounded border-gray-300 text-gray-900 focus:ring-gray-400 cursor-pointer"
                                                />
                                            </td>

                                            {/* Expand toggle */}
                                            <td className="pr-2 py-2.5">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpand(s.id)}
                                                    className="inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                                                    title={isExpanded ? "Collapse details" : "Expand details"}
                                                >
                                                    <ChevronRight
                                                        size={14}
                                                        className={`transition-transform ${isExpanded ? "rotate-90" : ""}`}
                                                    />
                                                </button>
                                            </td>

                                            {/* Student — avatar tinted by priority (mirrors the
                                                Leads/Cases board); priority also editable inline
                                                just below the name. */}
                                            <td className="px-3 py-2.5">
                                                <div className="min-w-[180px]">
                                                <Link
                                                    href={`${portalBase()}/leads/${s.id}?nav=students`}
                                                    className="flex items-center gap-2.5 group/student"
                                                >
                                                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0 overflow-hidden ${s.avatar_url ? `ring-2 ring-offset-1 ${priorityRing(s.priority)}` : (s.priority ? priorityDot(s.priority) : avatarColor(s.id))}`}>
                                                        <AvatarPhoto src={s.avatar_url} title={s.name}>
                                                            {initials(s.name)}
                                                        </AvatarPhoto>
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-1.5">
                                                            <div className="font-semibold text-gray-900 text-xs truncate group-hover/student:text-indigo-600 transition-colors">
                                                                {s.name}
                                                            </div>
                                                            <StudentCaseBadge student={s} />
                                                        </div>
                                                        {s.location ? (
                                                            <div className="flex items-center gap-1 text-[10px] text-gray-500 truncate">
                                                                <MapPin size={10} className="text-gray-300 flex-shrink-0" /> {s.location}
                                                            </div>
                                                        ) : s.lead_id ? (
                                                            <div className="text-[10px] text-gray-400 font-mono truncate">{s.lead_id}</div>
                                                        ) : null}
                                                        {(s.agent_name || s.referral) && (
                                                            <div className="flex items-center gap-1 text-[10px] text-indigo-500 truncate" title="Referring agent">
                                                                <Users size={10} className="text-indigo-300 flex-shrink-0" /> {s.agent_name || s.referral}
                                                            </div>
                                                        )}
                                                    </div>
                                                </Link>
                                                </div>
                                            </td>

                                            {/* Status — department-aware stage picker. The
                                                column read (education_stage / english_stage /
                                                immigration_stage) and the available stage list
                                                come from the active tab's config. The
                                                "Endorsed by [Name]" subtitle below the
                                                chip surfaces the staff member who created
                                                or endorsed this record. */}
                                            <td className="px-3 py-2.5 relative">
                                                {(() => {
                                                    // While searching, results span every tab. Keep the
                                                    // status chip anchored to the tab the staffer is on
                                                    // whenever this row actually belongs to it — a
                                                    // student+case on the Education tab must still read
                                                    // its education_stage, not the immigration one. Only
                                                    // rows that surfaced from another department fall back
                                                    // to their own primary department's stage set.
                                                    const rc = searching
                                                        ? (departmentsOf(s).has(view) ? tabConfig : TAB_STAGE_CONFIG[primaryDeptOf(s)])
                                                        : tabConfig;
                                                    const val = s[rc.field] || "";
                                                    // Immigration owns its own stages — the Education team
                                                    // can't change them here, so show a read-only chip. The
                                                    // Immigration team updates them from their portal.
                                                    if (rc.field === "immigration_stage") {
                                                        return val ? (
                                                            <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border ${rc.styler(val)}`}>
                                                                {val}
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-300 text-xs">—</span>
                                                        );
                                                    }
                                                    return (
                                                        <StagePicker
                                                            leadId={s.id}
                                                            field={rc.field}
                                                            stages={rc.stages}
                                                            styler={rc.styler}
                                                            heading={rc.label}
                                                            value={val}
                                                            fallbackLabel={rc.field === "education_stage" ? s.status : null}
                                                            open={openStageMenuId === s.id}
                                                            onToggle={() => setOpenStageMenuId(openStageMenuId === s.id ? null : s.id)}
                                                            onClose={() => setOpenStageMenuId(null)}
                                                        />
                                                    );
                                                })()}
                                            </td>

                                            {/* Programme & School — program name with its school underneath, paired per line */}
                                            <td className="px-3 py-2.5 align-top">
                                                {(() => {
                                                    const programs = s.program ? s.program.split(" · ") : [];
                                                    const schools = (s.school || s.school_name) ? (s.school || s.school_name).split(" · ") : [];
                                                    if (!programs.length && !schools.length) {
                                                        return <span className="text-gray-300">—</span>;
                                                    }
                                                    const rows = Math.max(programs.length, schools.length);
                                                    return (
                                                        <div className="flex flex-col gap-1.5 max-w-[260px]">
                                                            {Array.from({ length: rows }).map((_, i) => (
                                                                <div key={i} className="flex flex-col">
                                                                    {programs[i] && (
                                                                        <span className="text-gray-800 text-[12px] font-medium leading-tight truncate" title={programs[i]}>{programs[i]}</span>
                                                                    )}
                                                                    {schools[i] && (
                                                                        <span className="text-gray-500 text-[11px] leading-tight truncate" title={schools[i]}>{schools[i]}</span>
                                                                    )}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    );
                                                })()}
                                            </td>

                                            {/* Intake */}
                                            <td className="px-3 py-2.5 whitespace-nowrap">
                                                {s.intake ? <span className="text-gray-700">{fmtIntake(s.intake)}</span> : <span className="text-gray-300">—</span>}
                                            </td>

                                            {/* Engaged */}
                                            <td className="px-3 py-2.5 whitespace-nowrap">
                                                {s.date_engaged ? (
                                                    <div className="text-gray-600">{fmtDate(s.date_engaged)}</div>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>

                                            {/* Docs — % of the visible checklist submitted,
                                                same as the Immigration Cases / Leads column. */}
                                            <td className="px-3 py-2.5">
                                                {hasDocs ? (
                                                    <div className="flex items-center gap-2 min-w-[100px]" title={`${s.checklist_submitted} of ${s.checklist_total} checklist items submitted`}>
                                                        <div className="h-1.5 flex-1 rounded-full bg-gray-100 overflow-hidden">
                                                            <div className="h-full rounded-full bg-emerald-500" style={{ width: `${pct}%` }} />
                                                        </div>
                                                        <span className="text-xs font-semibold tabular-nums whitespace-nowrap text-emerald-600">
                                                            {pct}%
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>

                                            {/* Updated — last staff activity on this record:
                                                when, who, and what they changed. */}
                                            <td className="px-3 py-2.5 whitespace-nowrap">
                                                {s.updated_at ? (
                                                    <div className="flex flex-col">
                                                        <span className="text-xs text-gray-700 tabular-nums">
                                                            {fmtDate(s.updated_at)}
                                                        </span>
                                                        {s.updated_by && (
                                                            <span className="text-[10px] text-gray-400 truncate max-w-[160px]">
                                                                by {s.updated_by}
                                                            </span>
                                                        )}
                                                        {s.updated_desc && (
                                                            <span className="text-[10px] text-gray-500 truncate max-w-[160px]" title={s.updated_desc}>
                                                                {s.updated_desc}
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>

                                            {/* Actions — collapsed into a three-dot menu */}
                                            <td className="px-3 py-2.5 pr-4 text-right">
                                                <RowMenu
                                                    items={[
                                                        s.tracking_code && {
                                                            key: 'copy',
                                                            label: 'Copy tracking link',
                                                            icon: Copy,
                                                            onClick: () => copyTrackingLink(s.tracking_code),
                                                        },
                                                        {
                                                            key: 'docs',
                                                            label: 'Open documents',
                                                            icon: FileText,
                                                            // No /leads/{id}/documents route exists in any
                                                            // portal — the profile's Documents tab is the
                                                            // real destination.
                                                            href: `${portalBase()}/leads/${s.id}?tab=documents&nav=students`,
                                                        },
                                                        {
                                                            key: 'files',
                                                            label: `File history${s.docs_total ? ` (${s.docs_total})` : ''}`,
                                                            icon: Paperclip,
                                                            onClick: () => setFilesStudent(s),
                                                        },
                                                        s.gdrive_link && {
                                                            key: 'gdrive',
                                                            label: 'Open GDrive',
                                                            icon: ExternalLink,
                                                            href: s.gdrive_link,
                                                            external: true,
                                                        },
                                                        readOnly ? null : portalRequestItem(s, requestPortal),
                                                        readOnly ? null : {
                                                            key: 'edit',
                                                            label: 'Edit student',
                                                            icon: Pencil,
                                                            onClick: () => openEditStudent(s),
                                                        },
                                                        readOnly ? null : {
                                                            key: 'delete',
                                                            label: 'Delete student',
                                                            icon: Trash2,
                                                            onClick: () => deleteStudent(s),
                                                            danger: true,
                                                        },
                                                    ].filter(Boolean)}
                                                />
                                            </td>
                                        </tr>

                                        {/* Expander — full Students-Dashboard schema */}
                                        {isExpanded && (
                                            <tr className="bg-indigo-50/20 border-t border-indigo-100/60">
                                                <td colSpan={10} className="px-6 py-4">
                                                    <StudentDashboardPanel student={s} />
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
                        <div>
                            Page {safePage} of {totalPages}
                            {selectedIds.size > 0 && (
                                <span className="ml-3 text-gray-900 font-semibold">· {selectedIds.size} selected</span>
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

            <AddEditStudentModal
                open={studentModalOpen}
                student={editingStudent}
                onClose={closeStudentModal}
                schoolOptions={schoolOptions}
                programOptions={programOptions}
                agentOptions={agentOptions}
            />

            {filesStudent && (
                <CaseFilesModal
                    leadId={filesStudent.id}
                    leadName={filesStudent.name}
                    onClose={() => setFilesStudent(null)}
                />
            )}
        </div>
        </ReadOnlyContext.Provider>
    );
}

// ─── Expander panel — Students Dashboard mirror ───────────────────────────
function StudentDashboardPanel({ student: s }) {
    const pct       = fmtPct(s.docs_approved, s.docs_total);
    const hasDocs   = (s.docs_total || 0) > 0;
    const docsDone  = hasDocs && s.docs_approved >= s.docs_total;
    const barColor  = docsDone ? "bg-emerald-500" : pct >= 50 ? "bg-amber-500" : "bg-gray-400";

    // Prefer the school FK lookup; fall back to the legacy free-text
    // column on older rows that haven't been re-linked yet.
    const schoolDisplay = s.school_name || s.school;
    const englishTest   = s.english_test
        ? `${s.english_test}${s.english_test_score ? ` · ${s.english_test_score}` : ""}${s.english_test_taken ? "" : " · pending"}`
        : null;

    return (
        <div className="space-y-4">

            {/* ── Profile · contact, engagement ───────────────────── */}
            <section>
                <PanelTitle>Profile</PanelTitle>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div className="bg-white rounded-lg border border-gray-200 px-3 py-2">
                        <div className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                            <Flag size={11} className="text-gray-400" />
                            Priority
                        </div>
                        <StudentPriority student={s} />
                    </div>
                    <ReadOnlyField icon={Calendar}  label="Date engaged"   value={fmtDate(s.date_engaged)} />
                    <ReadOnlyField icon={MapPin}    label="Location"       value={s.location} />
                    <ReadOnlyField icon={Phone}     label="Contact number" value={s.phone}    href={s.phone ? `tel:${s.phone}` : null} />
                    <ReadOnlyField icon={Mail}      label="Email"          value={s.email}    href={s.email ? `mailto:${s.email}` : null} truncate />
                    {/* Endorsed by — staff member who last moved the
                        stage. Lives in the expander so the row column
                        stays compact. */}
                    <ReadOnlyField icon={UserPlus}  label="Endorsed by"    value={s.endorsed_by} />
                    <ReadOnlyField icon={Users}     label="Referring agent" value={s.agent_name || s.referral} />
                    <ReadOnlyField icon={UserPlus}  label="Assigned person" value={s.immigration_assignee || s.english_assignee} />
                </div>
            </section>

            {/* ── Status timeline · dated history of every stage move ─ */}
            {Array.isArray(s.stage_history) && s.stage_history.length > 0 && (
                <section>
                    <PanelTitle>Status timeline</PanelTitle>
                    <StatusTimeline history={s.stage_history} />
                </section>
            )}

            {/* ── Journey · unified Education → Immigration stepper ─ */}
            <section>
                <PanelTitle>Journey</PanelTitle>
                <UnifiedJourney student={s} />
                {s.english_stage && (
                    <div className="mt-3">
                        <JourneyTrack
                            icon={<Languages size={11} />}
                            label="English (parallel)"
                            stages={ENGLISH_STAGES}
                            current={s.english_stage}
                            toneClass="english"
                        />
                    </div>
                )}
            </section>

            {/* ── Student details · academic + operations stacked in
                a single 4-column grid so it reads as one block. The old
                "Status & offers" wrapper and the duplicate "School
                (Override)" tile are gone — the School FK supersedes the
                legacy free-text column. */}
            <section>
                <PanelTitle>Student details</PanelTitle>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    {/* Row 1 — academic */}
                    <ReadOnlyField icon={BookOpen}  label="Program"     value={s.program ? `${s.program}${s.level ? ` · ${s.level}` : ""}` : null} />
                    <ReadOnlyField icon={School}    label="School"      value={schoolDisplay} />
                    <ReadOnlyField icon={Calendar}  label="Intake"      value={fmtIntake(s.intake)} />
                    <ReadOnlyField icon={Languages} label="PTE / IELTS" value={englishTest} />

                    {/* Row 2 — operations */}
                    <EditableField leadId={s.id} fieldKey="payment" icon={CreditCard} label="Payment" value={s.payment} placeholder="e.g. PhP 150,000" />
                    <EditableField leadId={s.id} fieldKey="coop"    icon={FileCheck}  label="COOP"    value={s.coop}    placeholder="Yes / No / Date" />
                    <EditableField leadId={s.id} fieldKey="oop"     icon={FileCheck}  label="OOP"     value={s.oop}     placeholder="Yes / No / Date" />

                    {/* Documents progress slotted into the 4th column
                        of row 2 — keeps the grid tight and surfaces the
                        same metric without a dedicated section header. */}
                    <div className="bg-white rounded-lg border border-gray-200 px-3 py-2.5 flex flex-col justify-between">
                        <div className="flex items-center justify-between">
                            <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <FileCheck size={10} /> Documents
                            </span>
                            <span className="text-[11px] tabular-nums font-semibold text-gray-700">
                                {s.docs_approved || 0} / {s.docs_total || 0}
                                {hasDocs && <span className="ml-1 text-gray-400">· {pct}%</span>}
                            </span>
                        </div>
                        <div className="h-1.5 w-full rounded-full bg-gray-100 overflow-hidden mt-2">
                            <div className={`h-full rounded-full ${barColor}`} style={{ width: `${pct}%` }} />
                        </div>
                    </div>
                </div>
            </section>

            {/* ── Notes & links · comments (wide) + GDrive ─────────── */}
            <section>
                <PanelTitle>Notes &amp; links</PanelTitle>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                    <div className="lg:col-span-2">
                        <EditableField
                            leadId={s.id}
                            fieldKey="comments"
                            icon={ClipboardList}
                            label="Comments"
                            value={s.comments}
                            placeholder="Latest update / next action…"
                            multiline
                        />
                    </div>
                    <EditableField
                        leadId={s.id}
                        fieldKey="gdrive_link"
                        icon={ExternalLink}
                        label="GDrive link"
                        value={s.gdrive_link}
                        placeholder="https://drive.google.com/…"
                        isLink
                    />
                </div>
            </section>

            {/* 6 — Quick actions */}
            <section className="flex flex-wrap gap-2">
                <Link
                    href={`${portalBase()}/leads/${s.id}?tab=documents&nav=students`}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-900 text-white hover:bg-gray-800 transition-colors"
                >
                    <FolderOpen size={12} /> Open profile
                </Link>
                <Link
                    href={`${portalBase()}/leads/${s.id}?nav=students`}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors"
                >
                    <FileText size={12} /> Lead detail
                </Link>
                {s.email && (
                    <a href={`mailto:${s.email}`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                        <Mail size={12} /> Send email
                    </a>
                )}
                {s.phone && (
                    <a href={`tel:${s.phone}`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                        <Phone size={12} /> Call
                    </a>
                )}
                {s.gdrive_link && (
                    <a href={s.gdrive_link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                        <ExternalLink size={12} /> Open GDrive
                    </a>
                )}
            </section>
        </div>
    );
}

// ─── Small UI helpers ──────────────────────────────────────────────────────
// The canonical end-to-end student journey from initial school
// endorsement through visa approval to course start. Each node carries
// the team that owns that stage so the stepper can colour each segment
// accordingly — green = Education team, indigo = Immigration team.
const UNIFIED_JOURNEY = [
    { stage: "New Lead",                 team: "education"   },
    { stage: "Pre-Screening Done",       team: "education"   },
    { stage: "For Proposal",             team: "education"   },
    { stage: "Proposal Sent",            team: "education"   },
    { stage: "Engagement Sent",          team: "education"   },
    { stage: "Goal Setting Done",        team: "education"   },
    { stage: "School Enrolment",         team: "education"   },
    { stage: "Conditional Offer",        team: "education"   },
    { stage: "Unconditional Offer",      team: "education"   },
    { stage: "Endorsed to Immigration",  team: "immigration" },
    { stage: "Visa Lodged",              team: "immigration" },
    { stage: "Approved Visa",            team: "immigration" },
    { stage: "Started Course",           team: "education"   },
    { stage: "Course Ongoing",           team: "education"   },
    { stage: "Completed",                team: "education"   },
    { stage: "For Relodgement",          team: "immigration" },
    { stage: "Declined Visa",            team: "immigration" },
];

// Immigration team uses "Endorsed" as a shorter alias for "Endorsed to
// Immigration"; its interim visa sub-stages fold onto "Visa Lodged" in the
// unified education journey (which doesn't carry them as separate nodes).
const IMMIGRATION_TO_UNIFIED = {
    "Endorsed":                "Endorsed to Immigration",
    "Visa Lodged":             "Visa Lodged",
    "Request for Information": "Visa Lodged",
    "Approved in Principle":   "Visa Lodged",
    "Approved Visa":           "Approved Visa",
};

// Resolve which node on the unified sequence the student is parked
// at. Immigration stage takes precedence (it's the more granular
// downstream signal). Returns null when nothing is set yet.
function resolveJourneyPosition(student) {
    if (student.immigration_stage) {
        if (student.immigration_stage === "Decline Visa") return { declined: true };
        const mapped = IMMIGRATION_TO_UNIFIED[student.immigration_stage];
        const idx = UNIFIED_JOURNEY.findIndex((n) => n.stage === mapped);
        if (idx > -1) return { index: idx };
    }
    if (student.education_stage) {
        const idx = UNIFIED_JOURNEY.findIndex((n) => n.stage === student.education_stage);
        if (idx > -1) return { index: idx };
    }
    return null;
}

const TEAM_COLOURS = {
    education:   { fill: "bg-emerald-500", text: "text-emerald-700", soft: "bg-emerald-100 border-emerald-200", line: "bg-emerald-200" },
    immigration: { fill: "bg-indigo-500",  text: "text-indigo-700",  soft: "bg-indigo-100 border-indigo-200",   line: "bg-indigo-200"  },
};

// The unified stepper. Renders all 9 canonical stages in order, with
// the team-owned segment of the line and dot colour-coded. The current
// stage gets a larger filled dot + bold label; everything before is
// shown as completed (filled), everything after as muted gray.
function UnifiedJourney({ student }) {
    const position = resolveJourneyPosition(student);
    const currentIdx = position?.index ?? -1;
    const declined = !! position?.declined;
    const currentNode = currentIdx > -1 ? UNIFIED_JOURNEY[currentIdx] : null;
    const currentTeam = declined ? null : (currentNode?.team || "education");
    const currentLabel = declined
        ? "Visa Declined"
        : currentNode?.stage || "Not started yet";

    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-3">
            {/* Header: end-to-end framing + current stage on the right */}
            <div className="flex items-center justify-between gap-2 mb-3">
                <span className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-600">
                    <span className="w-4 h-4 rounded-md bg-gray-100 flex items-center justify-center">
                        <TrendingUp size={11} className="text-gray-500" />
                    </span>
                    Education → Immigration
                </span>
                <span
                    className={`text-[10px] font-bold ${
                        declined
                            ? "text-red-700"
                            : currentTeam
                                ? TEAM_COLOURS[currentTeam].text
                                : "text-gray-400"
                    }`}
                >
                    {currentLabel}
                </span>
            </div>

            {/* Stepper — flex row, each cell rendering a node. */}
            <ol className="relative flex items-start justify-between gap-1">
                {UNIFIED_JOURNEY.map((node, i) => {
                    const isCurrent = i === currentIdx && ! declined;
                    const passed    = currentIdx > -1 && i < currentIdx;
                    const team      = TEAM_COLOURS[node.team];
                    // The connector line to the left of this node uses
                    // this node's team colour when passed/current, gray
                    // otherwise. Drawn as an absolute-positioned segment
                    // so the colour transitions land between dots.
                    const showLine = i > 0;
                    const prevPassed = currentIdx > -1 && i - 1 < currentIdx;
                    const lineActive = passed || isCurrent || prevPassed;
                    return (
                        <li
                            key={node.stage}
                            title={`${node.stage} · ${node.team}`}
                            className="relative flex-1 flex flex-col items-center gap-1.5 min-w-0"
                        >
                            {showLine && (
                                <span
                                    className={`absolute top-[6px] right-[50%] w-full h-px ${
                                        lineActive ? team.line : "bg-gray-200"
                                    }`}
                                    aria-hidden
                                />
                            )}
                            <span
                                className={`relative z-10 inline-flex items-center justify-center rounded-full ring-2 ring-white transition-all ${
                                    isCurrent
                                        ? `w-3.5 h-3.5 ${team.fill} shadow-sm`
                                        : passed
                                            ? `w-3 h-3 ${team.fill}`
                                            : "w-3 h-3 bg-gray-200"
                                }`}
                            />
                            <span
                                className={`text-[9px] text-center leading-tight truncate max-w-full ${
                                    isCurrent
                                        ? `${team.text} font-bold`
                                        : passed
                                            ? "text-gray-600"
                                            : "text-gray-400"
                                }`}
                            >
                                {node.stage}
                            </span>
                        </li>
                    );
                })}
            </ol>

            {declined && (
                <p className="mt-3 text-[11px] text-red-700 bg-red-50 border border-red-100 rounded-md px-2.5 py-1.5 inline-flex items-center gap-1.5">
                    <Globe size={11} /> Visa application declined — see immigration team for next steps.
                </p>
            )}

            {/* Tiny legend so the colour story is unambiguous. */}
            <div className="mt-3 flex items-center gap-4 text-[9.5px] text-gray-500">
                <span className="inline-flex items-center gap-1">
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-500" /> Education
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="w-2.5 h-2.5 rounded-full bg-indigo-500" /> Immigration
                </span>
            </div>
        </div>
    );
}

// One row in the student Journey panel — renders a single track
// (Education, English, or Immigration) as a horizontal stepper across
// the canonical stage sequence. Stages before the current one show as
// "passed" (filled tone), the current stage as the active node (bigger
// dot + tinted background), and stages after as muted gray.
const JOURNEY_TONE = {
    education:   { fill: "bg-emerald-500", soft: "bg-emerald-100 text-emerald-700 border-emerald-200", text: "text-emerald-700" },
    english:     { fill: "bg-purple-500",  soft: "bg-purple-100 text-purple-700 border-purple-200",    text: "text-purple-700" },
    immigration: { fill: "bg-indigo-500",  soft: "bg-indigo-100 text-indigo-700 border-indigo-200",    text: "text-indigo-700" },
};

function JourneyTrack({ icon, label, stages, current, toneClass }) {
    const tone = JOURNEY_TONE[toneClass] || JOURNEY_TONE.education;
    const currentIdx = current ? stages.indexOf(current) : -1;

    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-2.5">
            <div className="flex items-center justify-between gap-2 mb-2">
                <span className={`inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider ${tone.text}`}>
                    <span className={`w-4 h-4 rounded-md ${tone.soft} flex items-center justify-center`}>
                        {icon}
                    </span>
                    {label}
                </span>
                <span className={`text-[10px] font-bold ${current ? tone.text : "text-gray-400"}`}>
                    {current || "Not started"}
                </span>
            </div>

            {/* Stepper — connector line + numbered dots, one per stage in
                the canonical sequence. Hover any dot to read its label. */}
            <div className="relative">
                <div className="absolute top-1.5 left-1 right-1 h-px bg-gray-200" aria-hidden />
                <ol className="relative flex items-start justify-between gap-1">
                    {stages.map((stage, i) => {
                        const passed = currentIdx > -1 && i < currentIdx;
                        const isCurrent = i === currentIdx;
                        return (
                            <li
                                key={stage}
                                title={stage}
                                className="flex-1 flex flex-col items-center gap-1 min-w-0"
                            >
                                <span
                                    className={`relative z-10 inline-flex items-center justify-center rounded-full ring-2 ring-white transition-all ${
                                        isCurrent
                                            ? `w-3.5 h-3.5 ${tone.fill} shadow-sm`
                                            : passed
                                                ? `w-3 h-3 ${tone.fill}`
                                                : "w-3 h-3 bg-gray-200"
                                    }`}
                                />
                                <span
                                    className={`text-[9px] text-center leading-tight truncate max-w-full ${
                                        isCurrent
                                            ? `${tone.text} font-bold`
                                            : passed
                                                ? "text-gray-600"
                                                : "text-gray-400"
                                    }`}
                                >
                                    {stage}
                                </span>
                            </li>
                        );
                    })}
                </ol>
            </div>
        </div>
    );
}

// Summary chart — horizontal bar rows per stage, one block per
// department track. The bar width is proportional to the busiest
// stage in that track so a small department doesn't get crushed
// against a large one. Click a row to filter the table.
// Per-stage hex tone for the line graph's dots + count labels. Each
// stage in the Education flow gets its own colour so a glance at the
// chart tells you which "milestone" the cluster sits at, not just the
// numeric count.
const EDUCATION_STAGE_HEX = {
    "Endorsed to School":      "#0ea5e9", // sky
    "Conditional Offer":       "#f59e0b", // amber
    "Unconditional Offer":     "#10b981", // emerald
    "Endorsed to Immigration": "#6366f1", // indigo
    "Visa Lodged":             "#8b5cf6", // violet
    "Approved in Principle":   "#06b6d4", // cyan
    "Request for Information": "#f97316", // orange
    "Approved Visa":           "#22c55e", // green
    "Started Course":          "#14b8a6", // teal
};
const educationStageHex = (s) => EDUCATION_STAGE_HEX[s] || "#9ca3af";

// Small line graph showing how many students sit at each Education
// stage for the selected department, using the same membership as its tab.
function StageDistribution({ stats, students, department }) {
    if (students.length === 0) return null;

    const label = { education: "Education", english: "English", immigration: "Immigration" }[department];
    const stages = { education: EDUCATION_STAGES, english: ENGLISH_STAGES, immigration: IMMIGRATION_STAGES }[department];
    const counts = stats[department].counts;
    const data      = stages.map((s) => counts[s] || 0);
    const peak      = Math.max(1, ...data);
    const placed    = data.reduce((sum, n) => sum + n, 0);
    const unstaged  = stats[department].notStarted;

    // SVG dimensions — viewBox units. Compact sparkline-style chart;
    // the rotated stage labels eat the bottom band. Extra horizontal
    // padding keeps the leftmost / rightmost rotated label from
    // bleeding outside the card.
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

    const linePoints = data.map((n, i) => `${xFor(i)},${yFor(n)}`).join(" ");
    // Closed path for the soft area fill under the line.
    const areaPath = `M${xFor(0)},${baselineY} L` +
        data.map((n, i) => `${xFor(i)},${yFor(n)}`).join(" L") +
        ` L${xFor(data.length - 1)},${baselineY} Z`;

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                        <TrendingUp size={13} />
                    </div>
                    <div>
                        <h2 className="text-[12px] font-bold uppercase tracking-wider text-gray-800">
                            {label} stage distribution
                        </h2>
                        <p className="text-[10.5px] text-gray-500 mt-0.5">
                            {placed} placed · {unstaged} unstaged · {stats[department].total} total
                        </p>
                    </div>
                </div>
                <span className="text-[10.5px] text-gray-400 inline-flex items-center gap-1.5">
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-500" />
                    Students at stage
                </span>
            </div>

            <div className="px-3 py-3">
                <svg
                    viewBox={`0 0 ${W} ${H}`}
                    className="w-full"
                    style={{ maxHeight: 160 }}
                    role="img"
                    aria-label={`Line graph of student count per ${label} stage`}
                >
                    {/* Soft horizontal gridlines (4 evenly-spaced bands). */}
                    {[0, 0.25, 0.5, 0.75, 1].map((t, i) => (
                        <line
                            key={i}
                            x1={padX}
                            x2={W - padX}
                            y1={padTop + (1 - t) * chartH}
                            y2={padTop + (1 - t) * chartH}
                            stroke="#f3f4f6"
                            strokeWidth="1"
                        />
                    ))}

                    {/* Area fill under the line. */}
                    <defs>
                        <linearGradient id="stage-area" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%"   stopColor="#10b981" stopOpacity="0.28" />
                            <stop offset="100%" stopColor="#10b981" stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    <path d={areaPath} fill="url(#stage-area)" />

                    {/* The line itself. */}
                    <polyline
                        points={linePoints}
                        stroke="#10b981"
                        strokeWidth="2"
                        fill="none"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />

                    {/* Dots + count above + rotated stage label below.
                        Each stage gets its own hex tone so the visual
                        story matches the chip palette used elsewhere. */}
                    {data.map((n, i) => {
                        const tone = educationStageHex(stages[i]);
                        return (
                            <g key={stages[i]}>
                                <circle
                                    cx={xFor(i)}
                                    cy={yFor(n)}
                                    r="4"
                                    fill="white"
                                    stroke={tone}
                                    strokeWidth="2.5"
                                />
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
                                    fontSize="10"
                                    fill="#6b7280"
                                    transform={`rotate(-30 ${xFor(i)} ${baselineY + 14})`}
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

function PanelTitle({ children }) {
    return <h4 className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-2.5">{children}</h4>;
}

// Dated timeline of every status change across departments. Newest first.
// Each entry: { department, stage, assignee, at, by_name }.
function StatusTimeline({ history = [] }) {
    const DEPT_LABEL = { education: "Education", english: "English", immigration: "Immigration" };
    const ordered = [...history].reverse();
    return (
        <ol className="relative border-l border-gray-200 ml-2 space-y-3">
            {ordered.map((h, i) => (
                <li key={i} className="ml-4">
                    <span className="absolute -left-1.5 mt-1 w-3 h-3 rounded-full bg-indigo-400 border-2 border-white" />
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span className="text-xs font-semibold text-gray-900">{h.stage || "Not started"}</span>
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            {DEPT_LABEL[h.department] || h.department}
                        </span>
                        {h.assignee && (
                            <span className="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 font-semibold">
                                {h.assignee}
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-1 text-[11px] text-gray-500 mt-0.5">
                        <Clock size={10} className="text-gray-300" />
                        {fmtDate(h.at)}{h.by_name ? ` · ${h.by_name}` : ""}
                    </div>
                </li>
            ))}
        </ol>
    );
}

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

// Small marker next to a student's name that tells staff, at a glance, whether
// this person is also being handled as an immigration case. A study client on a
// Student visa legitimately sits under BOTH departments — the amber "Student +
// Case" pill flags that dual membership; a plain indigo "Case" marks a
// student-visa case that hasn't been converted to a student record.
function StudentCaseBadge({ student: s }) {
    const isCase = !! (s.is_immigration_case || s.immigration_stage);
    if (! isCase) return null;

    const dual = isCase && !! s.is_student;
    return (
        <span
            className={`inline-flex items-center gap-1 flex-shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border ${
                dual
                    ? "bg-amber-50 text-amber-700 border-amber-200"
                    : "bg-indigo-50 text-indigo-700 border-indigo-200"
            }`}
            title={dual ? "Both a student and an immigration case" : "Immigration case (Student visa)"}
        >
            <Briefcase size={9} className="flex-shrink-0" />
            {dual ? "Student + Case" : "Case"}
        </span>
    );
}

function ReadOnlyField({ icon: Icon, label, value, href = null, truncate = false }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-2">
            <div className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                {Icon && <Icon size={11} className="text-gray-400" />}
                {label}
            </div>
            {value ? (
                href ? (
                    <a href={href} className={`text-xs font-semibold text-gray-900 hover:text-indigo-600 ${truncate ? "block truncate" : ""}`}>
                        {value}
                    </a>
                ) : (
                    <p className={`text-xs font-semibold text-gray-900 ${truncate ? "truncate" : ""}`}>{value}</p>
                )
            ) : (
                <span className="text-xs text-gray-300 italic">—</span>
            )}
        </div>
    );
}

// Education-stage picker — chip + popover menu. UI mirrors the sales
// portal's StagePicker in resources/js/pages/portal/sales/Leads.jsx so
// the two surfaces feel consistent. Saves inline to leads.education_stage
// via the dashboard-field endpoint other inline edits use.
// Maps tailwind colour family names → hex, used by the StagePicker's
// dropdown rows. Inline `style` instead of `bg-XXX-500` so JIT purge
// can't drop dynamically-built classes.
const STAGE_DOT_HEX = {
    sky:     "#0ea5e9", amber:  "#f59e0b", emerald: "#10b981",
    indigo:  "#6366f1", violet: "#8b5cf6", orange:  "#f97316",
    cyan:    "#06b6d4", red:    "#ef4444", purple:  "#a855f7",
    fuchsia: "#d946ef", pink:   "#ec4899", teal:    "#14b8a6",
    rose:    "#f43f5e", slate:  "#64748b", blue:    "#3b82f6",
    yellow:  "#eab308", lime:   "#84cc16", stone:   "#78716c",
    gray:    "#9ca3af",
};

// Parse the styler's class string (e.g. "bg-cyan-100 text-cyan-800
// border-cyan-200") and pull the colour family from any token. Falls
// back to a neutral grey when nothing matches.
function stageDotHex(styler, stage) {
    const cls = (typeof styler === "function" ? styler(stage) : "") || "";
    const match = cls.match(/(?:text|bg|border)-([a-z]+)-\d+/);
    return STAGE_DOT_HEX[match?.[1]] || "#9ca3af";
}

function StagePicker({ leadId, field, stages, styler, heading, value, fallbackLabel, open, onToggle, onClose }) {
    const [saving, setSaving] = useState(false);
    // Where to position the popover. Computed from the trigger button's
    // bounding rect each time we open. Fixed positioning + react portal
    // lets the menu escape ancestor `overflow-x-auto` (which silently
    // also clips overflow-y per the CSS spec) without breaking layout.
    const [coords, setCoords] = useState({ top: 0, left: 0, openUp: false });
    const menuRef    = useRef(null);
    const triggerRef = useRef(null);

    const MENU_W = 260;
    const MENU_MAX_H = 420;

    // Compute position whenever the menu opens; recompute on scroll/resize
    // so the popover follows the trigger if the page moves underneath it.
    useEffect(() => {
        if (! open || ! triggerRef.current) return;
        const place = () => {
            const r = triggerRef.current.getBoundingClientRect();
            const spaceBelow = window.innerHeight - r.bottom;
            const openUp = spaceBelow < Math.min(MENU_MAX_H, 240) && r.top > spaceBelow;
            // Clamp horizontally so it doesn't run off the right edge.
            const left = Math.min(r.left, window.innerWidth - MENU_W - 8);
            setCoords({
                top:  openUp ? r.top - 6 : r.bottom + 6,
                left: Math.max(8, left),
                openUp,
            });
        };
        place();
        window.addEventListener("scroll", place, true);
        window.addEventListener("resize", place);
        return () => {
            window.removeEventListener("scroll", place, true);
            window.removeEventListener("resize", place);
        };
    }, [open]);

    // Close on outside-click + ESC, matching StagePicker.
    useEffect(() => {
        if (! open) return;
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

    const showFallback = ! value && fallbackLabel && ! stages.includes(fallbackLabel);
    const label = value || (showFallback ? fallbackLabel : "Set stage");
    const chipClass = value
        ? styler(value)
        : "bg-gray-100 text-gray-500 border-gray-200 border-dashed";

    const select = (stage) => {
        setSaving(true);
        onClose();
        router.post(
            `${portalBase()}/students/${leadId}/dashboard-field`,
            { [field]: stage },
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
                className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold border whitespace-nowrap uppercase hover:shadow-sm transition-all disabled:opacity-60 disabled:cursor-not-allowed ${chipClass}`}
            >
                <span className="truncate max-w-[180px]">{label}</span>
                <ChevronDown size={10} strokeWidth={2.5} className="flex-shrink-0 opacity-60" />
            </button>

            {open && typeof document !== "undefined" && createPortal(
                <div
                    ref={menuRef}
                    role="listbox"
                    style={{
                        position:  "fixed",
                        top:       coords.openUp ? "auto" : coords.top,
                        bottom:    coords.openUp ? (window.innerHeight - coords.top) : "auto",
                        left:      coords.left,
                        width:     MENU_W,
                        maxHeight: MENU_MAX_H,
                    }}
                    className="z-[60] bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5 overflow-y-auto"
                >
                    <p className="px-3 pt-2 pb-1.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                        {heading || "Move to stage"}
                    </p>
                    {stages.map((s) => {
                        const active = s === value;
                        return (
                            <button
                                key={s}
                                type="button"
                                role="option"
                                aria-selected={active}
                                onClick={() => select(s)}
                                className={`w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 transition-colors ${active ? "bg-indigo-50/60" : ""}`}
                            >
                                <span
                                    className="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                    style={{ backgroundColor: stageDotHex(styler, s) }}
                                />
                                <span className={`flex-1 text-[12.5px] truncate ${active ? "font-bold text-gray-900" : "font-medium text-gray-700"}`}>
                                    {s}
                                </span>
                                {active && <Check size={12} className="text-gray-900 flex-shrink-0" strokeWidth={3} />}
                            </button>
                        );
                    })}
                </div>,
                document.body,
            )}
        </>
    );
}

// Inline-editable cell — click value to switch into an input, Save posts to
// /portal/education/students/{id}/dashboard-field and refreshes the page so
// the latest props come back from the server.
function EditableField({ leadId, fieldKey, icon: Icon, label, value, placeholder, multiline = false, isLink = false }) {
    const readOnly = useReadOnly();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft]     = useState(value || "");
    const [saving, setSaving]   = useState(false);

    const save = () => {
        setSaving(true);
        router.post(
            `${portalBase()}/students/${leadId}/dashboard-field`,
            { [fieldKey]: draft || null },
            {
                preserveScroll: true,
                onFinish: () => { setSaving(false); setEditing(false); },
            }
        );
    };

    const cancel = () => { setDraft(value || ""); setEditing(false); };

    return (
        <div className="bg-white rounded-lg border border-gray-200 px-3 py-2 group">
            <div className="flex items-center justify-between gap-1.5 mb-1">
                <div className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                    {Icon && <Icon size={11} className="text-gray-400" />}
                    {label}
                </div>
                {!editing && (
                    <button
                        type="button"
                        onClick={() => setEditing(true)}
                        className="text-gray-300 hover:text-indigo-600 opacity-0 group-hover:opacity-100 transition-opacity"
                        title="Edit"
                    >
                        <Edit2 size={11} />
                    </button>
                )}
            </div>

            {editing ? (
                <div className="space-y-1.5">
                    {multiline ? (
                        <textarea
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            placeholder={placeholder}
                            rows={3}
                            className="w-full text-xs border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y"
                            autoFocus
                        />
                    ) : (
                        <input
                            type={isLink ? "url" : "text"}
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            placeholder={placeholder}
                            className="w-full text-xs border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            autoFocus
                            onKeyDown={(e) => {
                                if (e.key === "Enter") { e.preventDefault(); save(); }
                                if (e.key === "Escape") { e.preventDefault(); cancel(); }
                            }}
                        />
                    )}
                    <div className="flex items-center gap-1.5">
                        <button
                            type="button"
                            onClick={save}
                            disabled={saving}
                            className="inline-flex items-center gap-1 px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50"
                        >
                            <Save size={10} /> {saving ? "Saving" : "Save"}
                        </button>
                        <button
                            type="button"
                            onClick={cancel}
                            className="px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-900"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            ) : value ? (
                isLink ? (
                    <a
                        href={value}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs font-semibold text-indigo-600 hover:underline truncate block"
                    >
                        {value}
                    </a>
                ) : (
                    <p className={`text-xs text-gray-900 ${multiline ? "whitespace-pre-wrap leading-relaxed" : "font-semibold truncate"}`}>
                        {value}
                    </p>
                )
            ) : (
                <button
                    type="button"
                    onClick={() => setEditing(true)}
                    className="text-xs text-gray-300 italic hover:text-indigo-600"
                >
                    {placeholder || "—"}
                </button>
            )}
        </div>
    );
}

function StatTile({ label, value, icon, tone = "indigo" }) {
    const TONES = {
        indigo:  { bg: "bg-indigo-50",  glyph: "bg-indigo-100 text-indigo-700",   num: "text-indigo-900" },
        emerald: { bg: "bg-emerald-50", glyph: "bg-emerald-100 text-emerald-700", num: "text-emerald-900" },
        amber:   { bg: "bg-amber-50",   glyph: "bg-amber-100 text-amber-700",     num: "text-amber-900" },
    };
    const t = TONES[tone] || TONES.indigo;
    return (
        <div className={`${t.bg} rounded-2xl p-4 flex items-center gap-3`}>
            <div className={`w-11 h-11 rounded-xl flex items-center justify-center ${t.glyph}`}>
                {icon}
            </div>
            <div className="min-w-0">
                <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-500">{label}</p>
                <p className={`text-2xl font-bold tabular-nums ${t.num}`}>{value}</p>
            </div>
        </div>
    );
}
