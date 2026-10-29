import { useMemo, useState, useEffect, useRef } from "react";
import { Head, Link, router } from "@inertiajs/react";
import {
    ChevronRight, ChevronLeft, ChevronDown, ClipboardCheck, FileEdit, Globe, Send, Search,
    Users, Briefcase, GraduationCap, Plane, Heart,
    Check, FileText, UserCheck, ArrowRightCircle, AlertTriangle,
    X, Mail, Phone, ExternalLink, Loader2, MessageCircle, User, Trash2,
} from "lucide-react";
import { toast } from "sonner";
import PortalPageHeader from "@/components/portal/PortalPageHeader";
import { AiAssessmentReviewModal } from "@/components/immigration/AiAssessmentReview";
import { Sparkles } from "lucide-react";

// ── Visa-type metadata ─────────────────────────────────────────────────────
const VISA_TYPES = [
    { key: "all",      label: "All Talents",  icon: <Users size={13} />          },
    { key: "resident", label: "Resident",     icon: <ClipboardCheck size={13} /> },
    { key: "work",     label: "Work",         icon: <Briefcase size={13} />      },
    { key: "student",  label: "Student",      icon: <GraduationCap size={13} />  },
    { key: "visitor",  label: "Visitor",      icon: <Plane size={13} />          },
    { key: "family",   label: "Family",       icon: <Heart size={13} />          },
];

const VISA_AVATAR_BG = {
    resident: "bg-amber-500",
    work:     "bg-blue-500",
    student:  "bg-purple-500",
    visitor:  "bg-emerald-500",
    family:   "bg-rose-500",
    free:     "bg-[#009688]",
};

const VISA_LABEL = {
    resident: "Resident",
    work:     "Work",
    student:  "Student",
    visitor:  "Visitor",
    family:   "Family",
    free:     "Free Assessment",
};

// Status buckets — only an explicit "Submitted" counts as Submitted.
// Anything else (including the legacy "New" default) is treated as Draft
// until the applicant actually clicks the Submit button on the form.
const SUBMITTED_STATUSES = new Set(["Submitted", "submitted"]);
const COMPLETED_STATUSES = new Set(["Completed", "Engaged", "Converted", "Approved", "completed", "engaged", "converted"]);

const isCompleted = (i) => COMPLETED_STATUSES.has(i.status);
// A row counts as "submitted" once the applicant actually submitted the
// form. `journey.submitted` is the authoritative signal (the string status
// can still be a legacy "New" default even though the form was submitted),
// so the collapsed status pill matches the expanded journey's Submitted step.
const hasSubmitted = (i) => !! (i.journey?.submitted) || SUBMITTED_STATUSES.has(i.status);
const isSubmitted = (i) => ! isCompleted(i) && hasSubmitted(i);
const isDraft     = (i) => ! isCompleted(i) && ! hasSubmitted(i);

// Returns { stage: 'draft' | 'submitted' | 'completed', pct: 33|66|100 }
const progressOf = (i) => {
    if (isCompleted(i)) return { stage: "completed", pct: 100 };
    if (isSubmitted(i)) return { stage: "submitted", pct: 66  };
    return                     { stage: "draft",     pct: 33  };
};

const STAGE_STYLES = {
    draft:     { fill: "bg-amber-500",   text: "text-amber-700",   pill: "bg-amber-50 text-amber-700 border-amber-200",     label: "Draft" },
    submitted: { fill: "bg-blue-500",    text: "text-blue-700",    pill: "bg-blue-50 text-blue-700 border-blue-200",        label: "Submitted" },
    completed: { fill: "bg-emerald-500", text: "text-emerald-700", pill: "bg-emerald-50 text-emerald-700 border-emerald-200", label: "Completed" },
};

const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—";

// Relative "time ago" for the Status column, so the newest submissions read at a glance.
const timeAgo = (iso) => {
    if (!iso) return "";
    const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return "just now";
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    if (s < 604800) return `${Math.floor(s / 86400)}d ago`;
    return fmtDate(iso);
};
// A submission within the last 24h is flagged "New" so fresh applicants stand out.
const isRecent = (iso) => !! iso && Date.now() - new Date(iso).getTime() < 86400000;

const initials = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase() || "—";

// ── Pre-screening (potential-client vetting) ────────────────────────────────
export const PRE_STATUS = {
    new:          { label: "New",          cls: "bg-slate-100 text-slate-600 border-slate-200" },
    consultation: { label: "Consultation", cls: "bg-blue-50 text-blue-700 border-blue-200" },
    consent:      { label: "Agreement",    cls: "bg-amber-50 text-amber-700 border-amber-200" },
    eligible:     { label: "Eligible",     cls: "bg-emerald-50 text-emerald-700 border-emerald-200" },
    converted:    { label: "Converted",    cls: "bg-green-50 text-green-700 border-green-200" },
    declined:     { label: "Declined",     cls: "bg-slate-100 text-slate-400 border-slate-200" },
};

// Small pre-screening strip shown in the assessment detail: the stage, the
// private /application link, and the context-appropriate vetting actions.
// Eligible = flag as a potential client; Convert (the existing footer button) =
// make an official case.
export function PreScreenBar({ i }) {
    const p = i?.pre_screen;
    if (!p) return null;
    const base = `/portal/immigration/pre-screening/${p.token}`;
    const act = (path, data) => router.post(`${base}/${path}`, data || {}, { preserveScroll: true });
    const st = PRE_STATUS[p.status] || PRE_STATUS.new;
    const terminal = p.status === "converted" || p.status === "declined";
    const copyLink = () => {
        try { navigator.clipboard.writeText(window.location.origin + i.application_link); toast.success("Application link copied"); }
        catch { toast.error("Couldn't copy"); }
    };
    const Btn = ({ onClick, children, primary }) => (
        <button type="button" onClick={onClick}
            className={`text-[11px] font-semibold px-2.5 py-1 rounded-md border ${primary ? "bg-[#009688] text-white border-[#009688] hover:bg-[#00796b]" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}>
            {children}
        </button>
    );

    return (
        <div className="px-6 py-2.5 border-t border-gray-100 bg-[#f7faf8]">
            <div className="flex flex-wrap items-center gap-2.5">
                <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Pre-screen</span>
                <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full border ${st.cls}`}>{st.label}</span>
                {i.application_link && (
                    <button type="button" onClick={copyLink} className="inline-flex items-center gap-1 text-[11px] text-gray-500 hover:text-[#009688]">
                        <ExternalLink size={12} /> Copy application link
                    </button>
                )}
                {!terminal && (
                    <div className="ml-auto flex flex-wrap items-center gap-1.5">
                        {! p.consent_sent && <Btn onClick={() => act("consent-sent")}>Send agreement</Btn>}
                        {p.consent_sent && ! p.consent_ack && <Btn onClick={() => act("consent-ack")}>Client acknowledged</Btn>}
                        {! p.skipped && ! p.gate_passed && (
                            <Btn onClick={() => { const r = window.prompt("Reason to skip the consultation/agreement steps?"); if (r) act("skip", { reason: r }); }}>Skip steps</Btn>
                        )}
                        {p.status !== "eligible" && p.gate_passed && <Btn primary onClick={() => act("eligible")}>Mark eligible</Btn>}
                        {p.status === "eligible" && <span className="text-[11px] text-emerald-600 font-semibold">Eligible ✓ potential client</span>}
                        <Btn onClick={() => { const r = window.prompt("Reason for declining?"); if (r) act("decline", { reason: r }); }}>Decline</Btn>
                    </div>
                )}
                {terminal && p.status === "declined" && <span className="text-[11px] text-gray-400">Declined{p.decline_reason ? ` · ${p.decline_reason}` : ""}</span>}
            </div>
        </div>
    );
}

// ── Page ───────────────────────────────────────────────────────────────────

// Readiness prioritisation — how complete/clean the submission is (NOT an
// eligibility or outcome signal). Higher rank sorts first.
const READINESS = {
    ready:      { label: "Ready",      chip: "bg-emerald-50 text-emerald-700 border-emerald-200", dot: "bg-emerald-500", rank: 3 },
    minor:      { label: "Minor gaps", chip: "bg-amber-50 text-amber-700 border-amber-200",       dot: "bg-amber-500",   rank: 2 },
    needs_info: { label: "Needs info", chip: "bg-rose-50 text-rose-700 border-rose-200",           dot: "bg-rose-500",    rank: 1 },
};

export default function ImmigrationAssessments({ intakes = [] }) {
    const [activeVisa,      setActiveVisa]      = useState("all");
    const [statusFilter,    setStatusFilter]    = useState("all"); // all | submitted | draft | completed
    const [readinessFilter, setReadinessFilter] = useState("all"); // all | ready | minor | needs_info
    const [search,          setSearch]          = useState("");
    const [expanded,        setExpanded]        = useState(() => new Set());

    const toggleExpanded = (key) => {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else                next.add(key);
            return next;
        });
    };

    const visaCounts = useMemo(() => {
        const c = { all: intakes.length, resident: 0, work: 0, student: 0, visitor: 0, family: 0 };
        for (const i of intakes) c[i.visa_type] = (c[i.visa_type] || 0) + 1;
        return c;
    }, [intakes]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return intakes.filter((i) => {
            if (activeVisa !== "all" && i.visa_type !== activeVisa) return false;
            if (statusFilter === "submitted" && ! isSubmitted(i))   return false;
            if (statusFilter === "draft"     && ! isDraft(i))       return false;
            if (statusFilter === "completed" && ! isCompleted(i))   return false;
            if (readinessFilter !== "all" && (i.readiness || "needs_info") !== readinessFilter) return false;
            if (q) {
                const hay = `${i.name} ${i.email || ""} ${i.phone || ""} ${i.intake_id || ""} ${i.status || ""}`.toLowerCase();
                if (! hay.includes(q)) return false;
            }
            return true;
        // Prioritise: most-ready first, then most-recent.
        }).sort((a, b) => {
            const ra = READINESS[a.readiness]?.rank ?? 0;
            const rb = READINESS[b.readiness]?.rank ?? 0;
            if (rb !== ra) return rb - ra;
            // Last activity where we have it (drafts autosave), else arrival.
            return new Date(b.saved_at || b.created_at || 0) - new Date(a.saved_at || a.created_at || 0);
        });
    }, [intakes, activeVisa, statusFilter, readinessFilter, search]);

    const readinessCounts = useMemo(() => {
        const scope = activeVisa === "all" ? intakes : intakes.filter((i) => i.visa_type === activeVisa);
        return {
            all:        scope.length,
            ready:      scope.filter((i) => i.readiness === "ready").length,
            minor:      scope.filter((i) => i.readiness === "minor").length,
            needs_info: scope.filter((i) => (i.readiness || "needs_info") === "needs_info").length,
        };
    }, [intakes, activeVisa]);

    const statusCounts = useMemo(() => {
        const scope = activeVisa === "all"
            ? intakes
            : intakes.filter((i) => i.visa_type === activeVisa);
        return {
            all:       scope.length,
            submitted: scope.filter(isSubmitted).length,
            draft:     scope.filter(isDraft).length,
            completed: scope.filter(isCompleted).length,
        };
    }, [intakes, activeVisa]);

    return (
        <div className="space-y-5 max-w-[1400px] mx-auto pb-12">
            <Head title="Visa Assessment — Immigration" />
            <PortalPageHeader
                eyebrow="Work"
                title="Visa Assessment"
                description="Public visa-assessment submissions — free enquiries plus paid bookings — awaiting adviser triage."
            />

            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                {/* Tab strip — minimal text + count pill, active gets a purple
                    underline */}
                <div className="flex items-center border-b border-gray-100 px-3 overflow-x-auto">
                    {VISA_TYPES.map((t) => (
                        <VisaTabButton
                            key={t.key}
                            tab={t}
                            count={visaCounts[t.key] || 0}
                            active={activeVisa === t.key}
                            onClick={() => setActiveVisa(t.key)}
                        />
                    ))}
                </div>

                {/* Status pill row + search */}
                <div className="flex flex-col lg:flex-row lg:items-center gap-3 px-4 py-2.5 border-b border-gray-100">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <StatusPill label="All"       count={statusCounts.all}       active={statusFilter === "all"}       onClick={() => setStatusFilter("all")} />
                        <StatusPill label="Submitted" count={statusCounts.submitted} active={statusFilter === "submitted"} onClick={() => setStatusFilter("submitted")} icon={<Send size={11} />}      tone="blue" />
                        <StatusPill label="Draft"     count={statusCounts.draft}     active={statusFilter === "draft"}     onClick={() => setStatusFilter("draft")}     icon={<FileEdit size={11} />}  tone="amber" />
                        <StatusPill label="Completed" count={statusCounts.completed} active={statusFilter === "completed"} onClick={() => setStatusFilter("completed")} icon={<ClipboardCheck size={11} />} tone="emerald" />
                    </div>

                    <div className="flex items-center gap-2 flex-1 min-w-0 lg:justify-end">
                        <Search size={14} className="text-gray-400 flex-shrink-0" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by name, email, phone, intake ID…"
                            className="flex-1 lg:max-w-sm outline-none text-sm placeholder:text-gray-400 bg-transparent"
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
                </div>

                {/* Readiness prioritisation — how ready each submission is to
                    action (completeness/consistency), so the adviser can pick the
                    ready ones first. Not an eligibility/outcome signal. */}
                <div className="flex items-center gap-1.5 flex-wrap px-4 py-2 border-b border-gray-100">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mr-1">Priority</span>
                    <StatusPill label="Any"        count={readinessCounts.all}        active={readinessFilter === "all"}        onClick={() => setReadinessFilter("all")} />
                    <StatusPill label="Ready"      count={readinessCounts.ready}      active={readinessFilter === "ready"}      onClick={() => setReadinessFilter("ready")}      icon={<Check size={11} />}         tone="emerald" />
                    <StatusPill label="Minor gaps" count={readinessCounts.minor}      active={readinessFilter === "minor"}      onClick={() => setReadinessFilter("minor")}      icon={<FileEdit size={11} />}      tone="amber" />
                    <StatusPill label="Needs info" count={readinessCounts.needs_info} active={readinessFilter === "needs_info"} onClick={() => setReadinessFilter("needs_info")} icon={<AlertTriangle size={11} />} tone="rose" />
                    <span className="text-[10px] text-gray-400 ml-1">by completeness — not an eligibility signal</span>
                </div>

                {/* Table / list */}
                {intakes.length === 0 ? (
                    <EmptyState icon={<ClipboardCheck size={26} />} title="No assessments yet." />
                ) : filtered.length === 0 ? (
                    <EmptyState
                        icon={<Search size={26} />}
                        title="Nothing matches your filters."
                        action={
                            <button
                                type="button"
                                onClick={() => { setSearch(""); setStatusFilter("all"); setActiveVisa("all"); setReadinessFilter("all"); }}
                                className="mt-3 text-[11px] font-bold uppercase tracking-wider text-gray-600 hover:text-gray-900"
                            >
                                Clear filters
                            </button>
                        }
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th className="px-2 py-2.5 w-8"></th>
                                    <th className="px-4 py-2.5">Applicant</th>
                                    <th className="px-4 py-2.5">Visa</th>
                                    <th className="px-4 py-2.5 w-[220px]">Progress</th>
                                    <th className="px-4 py-2.5">Priority</th>
                                    <th className="px-4 py-2.5">Status</th>
                                    <th className="px-4 py-2.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {filtered.map((i) => {
                                    const key = `${i.visa_type}-${i.id}`;
                                    const isOpen = expanded.has(key);
                                    return (
                                        <IntakeRow
                                            key={key}
                                            intake={i}
                                            expanded={isOpen}
                                            onToggle={() => toggleExpanded(key)}
                                        />
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Components ─────────────────────────────────────────────────────────────

function VisaTabButton({ tab, count, active, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`px-3 py-3 inline-flex items-center gap-2 text-[13px] font-semibold transition-colors border-b-2 whitespace-nowrap ${
                active
                    ? "text-gray-900 border-purple-500"
                    : "text-gray-500 border-transparent hover:text-gray-700"
            }`}
        >
            {tab.label}
            <span className={`inline-flex items-center justify-center min-w-[26px] h-[20px] px-2 rounded-full text-[10px] font-semibold tabular-nums ${
                active ? "bg-purple-100 text-purple-700" : "bg-gray-100 text-gray-500"
            }`}>
                {count}
            </span>
        </button>
    );
}

function StatusPill({ label, count, active, onClick, icon, tone = "gray" }) {
    const TONES = {
        gray:    "bg-gray-900 text-white border-gray-900",
        blue:    "bg-blue-600 text-white border-blue-600",
        amber:   "bg-amber-600 text-white border-amber-600",
        emerald: "bg-emerald-600 text-white border-emerald-600",
        rose:    "bg-rose-600 text-white border-rose-600",
    };
    const activeClass = TONES[tone] || TONES.gray;
    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider border transition-colors ${
                active ? activeClass : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"
            }`}
        >
            {icon}
            {label}
            <span className={`inline-flex items-center justify-center min-w-[16px] h-[16px] px-1 rounded-full text-[9px] font-bold tabular-nums ${
                active ? "bg-white/20" : "bg-gray-100 text-gray-600"
            }`}>
                {count}
            </span>
        </button>
    );
}

/**
 * In-app confirmation dialog — replaces the browser's native confirm() so the
 * prompt matches the app's styling. Click the backdrop or Cancel to dismiss.
 */
function ConfirmModal({ open, title, message, confirmLabel = "Confirm", onConfirm, onCancel, danger = false }) {
    if (! open) return null;
    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 px-4" onClick={onCancel}>
            <div className="w-full max-w-sm rounded-2xl bg-white shadow-xl p-6" onClick={(e) => e.stopPropagation()}>
                <h3 className="text-base font-bold text-gray-900">{title}</h3>
                <p className="mt-2 text-[13px] text-gray-600 leading-relaxed">{message}</p>
                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" onClick={onCancel}
                        className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="button" onClick={onConfirm}
                        className={`inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-white text-sm font-semibold ${danger ? "bg-rose-600 hover:bg-rose-700" : "bg-[#009688] hover:bg-[#00796b]"}`}>
                        {danger ? <Trash2 size={15} /> : <ArrowRightCircle size={15} />} {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

function IntakeRow({ intake: i, expanded = false, onToggle }) {
    const { stage, pct } = progressOf(i);
    const stageStyle     = STAGE_STYLES[stage];
    const [aiOpen, setAiOpen] = useState(false);
    const [viewOpen, setViewOpen] = useState(false);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [convertOpen, setConvertOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const runConvert = () => {
        setConvertOpen(false);
        // Always name the exact intake (type + id) so the server resolves THIS
        // submission's assessment via the morph link — never by guessing an
        // Assessment id from the url, which converted the wrong case.
        const id = i.assessment_id ?? i.id;
        router.post(`/portal/immigration/assessments/${id}/convert-to-case`, { intake_type: i.visa_type, intake_id: i.id }, { preserveScroll: true });
    };

    const runDelete = () => {
        setDeleteOpen(false);
        router.delete("/portal/immigration/assessments", {
            // `record` tells the server which table the id belongs to — visa
            // drafts and free assessments are Lead rows, real submissions are
            // intake rows. Defaults to "intake" for older payloads.
            data: { record: i.record || "intake", intake_type: i.visa_type, intake_id: i.id },
            preserveScroll: true,
        });
    };

    const openModal = () => {
        setViewOpen(true);
        if (data) return;
        // Prefer the server-provided URL; fall back to constructing it so a
        // stale payload (loaded before data_url existed) still works.
        const url = i.data_url || `/portal/immigration/intakes/${i.visa_type}/${i.id}/data`;
        setLoading(true);
        fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setData(d))
            .catch(() => {})
            .finally(() => setLoading(false));
    };

    return (
        <>
        <tr
            className={`text-sm transition-colors cursor-pointer ${expanded ? "bg-gray-50/60" : "hover:bg-gray-50/40"}`}
            onClick={onToggle}
        >
            {/* Expand chevron */}
            <td className="px-2 py-3 align-middle">
                <span className={`inline-flex items-center justify-center w-6 h-6 text-gray-400 transition-transform ${expanded ? "rotate-90" : ""}`}>
                    <ChevronRight size={14} />
                </span>
            </td>

            {/* Applicant */}
            <td className="px-4 py-3">
                <div className="flex items-center gap-3 min-w-0">
                    <span className={`w-9 h-9 rounded-full inline-flex items-center justify-center text-white text-[11px] font-bold flex-shrink-0 ${VISA_AVATAR_BG[i.visa_type] || "bg-gray-500"}`}>
                        {initials(i.name)}
                    </span>
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-gray-900 truncate">{i.name}</p>
                        {i.email && <p className="text-[11px] text-gray-600 truncate max-w-[240px]">{i.email}</p>}
                        {i.phone && <p className="text-[10.5px] text-gray-400 truncate max-w-[240px]">{i.phone}</p>}
                        {!i.email && !i.phone && i.intake_id && (
                            <p className="text-[10px] text-gray-400 font-mono mt-0.5">{i.intake_id}</p>
                        )}
                    </div>
                </div>
            </td>

            {/* Visa */}
            <td className="px-4 py-3 align-middle">
                <p className="text-[12px] font-medium text-gray-700">{VISA_LABEL[i.visa_type] || i.visa_type}</p>
                {i.extra && (
                    <p className="text-[10px] text-gray-400 truncate max-w-[200px] mt-0.5">{i.extra}</p>
                )}
            </td>

            {/* Progress */}
            <td className="px-4 py-3 align-middle">
                <div className="flex items-center justify-between mb-1">
                    <span className={`text-[10px] font-bold uppercase tracking-wider ${stageStyle.text}`}>
                        {stageStyle.label}
                    </span>
                    <span className="text-[10px] tabular-nums text-gray-500">{pct}%</span>
                </div>
                <div className="h-1.5 w-full bg-gray-100 rounded-full overflow-hidden">
                    <div
                        className={`h-full rounded-full transition-all ${stageStyle.fill}`}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            </td>

            {/* Priority — readiness by completeness (not an eligibility signal) */}
            <td className="px-4 py-3 align-middle">
                <ReadinessChip readiness={i.readiness} pct={i.readiness_pct} reviewed={i.readiness_reviewed} />
            </td>

            {/* Status — draft vs submitted, plus how recently, so new applicants stand out */}
            <td className="px-4 py-3 align-middle">
                {(() => {
                    const stage = progressOf(i).stage;
                    const s = STAGE_STYLES[stage];
                    const fresh = stage === "submitted" && isRecent(i.created_at);
                    return (
                        <div className="flex flex-col gap-1 items-start">
                            <span className="inline-flex items-center gap-1.5">
                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${s.pill}`}>{s.label}</span>
                                {fresh && <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-emerald-500 text-white">New</span>}
                            </span>
                            <span className="text-[10.5px] text-gray-500 tabular-nums">
                                {stage === "draft" ? `Saved ${timeAgo(i.saved_at || i.created_at)}` : timeAgo(i.created_at)}
                            </span>
                            {i.pre_screen?.status && i.pre_screen.status !== "new" && (
                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border ${(PRE_STATUS[i.pre_screen.status] || PRE_STATUS.new).cls}`}>
                                    {(PRE_STATUS[i.pre_screen.status] || PRE_STATUS.new).label}
                                </span>
                            )}
                        </div>
                    );
                })()}
            </td>

            {/* Actions — stops click propagation so action buttons don't
                toggle the row's expansion. */}
            <td className="px-4 py-3 align-middle" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-end gap-1">
                    <button
                        type="button"
                        onClick={() => setAiOpen(true)}
                        title="AI completeness review (internal, indicative — not advice)"
                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border border-indigo-200 text-indigo-600 hover:bg-indigo-50 transition-colors"
                    >
                        <Sparkles size={10} /> AI review
                    </button>
                    {aiOpen && (
                        <AiAssessmentReviewModal
                            type={i.visa_type}
                            id={i.id}
                            name={i.name}
                            onClose={() => setAiOpen(false)}
                        />
                    )}
                    {i.can_convert && (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); setConvertOpen(true); }}
                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider bg-amber-600 text-white hover:bg-amber-700 transition-colors"
                        >
                            <Globe size={10} /> Convert
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            // Open the full pre-screening profile page. Fall back to
                            // the inline modal only if the row has no pre-screen token.
                            if (i.pre_screen?.token) router.visit(`/portal/immigration/assessments/profile/${i.pre_screen.token}`);
                            else openModal();
                        }}
                        className="inline-flex items-center gap-1 px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-wider text-white bg-gray-900 hover:bg-black transition-colors"
                    >
                        Open <ChevronRight size={10} />
                    </button>
                    <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); setDeleteOpen(true); }}
                        title="Delete this assessment"
                        className="inline-flex items-center justify-center p-1.5 rounded-md border border-rose-200 text-rose-500 hover:bg-rose-50 transition-colors"
                    >
                        <Trash2 size={11} />
                    </button>
                </div>
            </td>
        </tr>

        {viewOpen && (
            <IntakeViewModal intake={i} data={data} loading={loading} onClose={() => setViewOpen(false)} />
        )}

        <ConfirmModal
            open={convertOpen}
            title="Convert to case"
            message="Convert this assessment to an immigration case? A lead will be created (or matched on email) and flagged as a case."
            confirmLabel="Convert to case"
            onConfirm={runConvert}
            onCancel={() => setConvertOpen(false)}
        />

        <ConfirmModal
            open={deleteOpen}
            danger
            title="Delete assessment"
            message={`Delete ${i.name || "this"} assessment? This permanently removes the submission and can't be undone.`}
            confirmLabel="Delete"
            onConfirm={runDelete}
            onCancel={() => setDeleteOpen(false)}
        />

        {expanded && (
            <tr className="bg-gray-50/60 border-b border-gray-100">
                <td colSpan={7} className="px-6 py-5">
                    <JourneyRow intake={i} />
                </td>
            </tr>
        )}
        </>
    );
}

const normKey = (s) => (s || "").toLowerCase().replace(/[^a-z0-9]/g, "");
const SEV_RANK = { critical: 3, high: 3, warning: 2, medium: 2, info: 1 };

// How a flagged field is highlighted on the official form, by AI severity.
const FIELD_SEV = {
    critical: { border: "border-red-400",   value: "text-red-700",   label: "text-red-600",   note: "text-red-600" },
    high:     { border: "border-red-400",   value: "text-red-700",   label: "text-red-600",   note: "text-red-600" },
    warning:  { border: "border-amber-400", value: "text-amber-700", label: "text-amber-600", note: "text-amber-600" },
    medium:   { border: "border-amber-400", value: "text-amber-700", label: "text-amber-600", note: "text-amber-600" },
    info:     { border: "border-blue-300",  value: "text-blue-700",  label: "text-blue-600",  note: "text-blue-600" },
};

// Verdict tone → colours for the overall-assessment card.
const VERDICT_TONE = {
    emerald: { bg: "bg-emerald-50", text: "text-emerald-700", border: "border-emerald-200", bar: "bg-emerald-500" },
    teal:    { bg: "bg-[#009688]/10", text: "text-[#009688]", border: "border-[#009688]/30", bar: "bg-[#009688]" },
    amber:   { bg: "bg-amber-50", text: "text-amber-700", border: "border-amber-200", bar: "bg-amber-500" },
    red:     { bg: "bg-red-50", text: "text-red-700", border: "border-red-200", bar: "bg-red-500" },
    gray:    { bg: "bg-gray-50", text: "text-gray-600", border: "border-gray-200", bar: "bg-gray-400" },
};
// Drop AI observations that merely restate a blank field — the blanks already
// show as "—" on the form, so they add nothing here.
const substantiveObs = (o) => o && o.note && !/not\s*(provided|submitted|supplied|given|available)|missing|blank|no\s+\w+\s+(provided|given)/i.test(o.note);

function assessCsrf() {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || "");
    // Laravel checks X-CSRF-TOKEN first; the initial HTML token can be stale
    // after an Inertia login rotates the session, so prefer the current cookie.
    if (xsrf) return { "X-XSRF-TOKEN": xsrf };
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    return { "X-CSRF-TOKEN": meta };
}

// Applicant review modal: LEFT = the submitted visa-assessment form in an
// official, sectioned layout — the AI runs automatically and highlights the
// fields it flags (red/amber). RIGHT = the adviser's panel: notes + actions.
export function IntakeViewModal({ intake: i, data: providedData, loading: providedLoading, onClose, notesOverride, onPostNote, asPage = false, chromeless = false }) {
    const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "long", year: "numeric" }) : "");

    // Self-fetch the payload when the parent didn't pre-load it — lets the case
    // profile's "Preview VIF" open this same modal with just { visa_type, id }.
    const selfFetch = providedData === undefined;
    const [fetched, setFetched] = useState(null);
    const [fetching, setFetching] = useState(selfFetch);
    useEffect(() => {
        if (! selfFetch) return;
        const url = i.data_url || `/portal/immigration/intakes/${i.visa_type}/${i.id}/data`;
        setFetching(true);
        fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setFetched(d))
            .catch(() => {})
            .finally(() => setFetching(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const dataProp = selfFetch ? fetched : providedData;
    const loading = selfFetch ? fetching : providedLoading;

    // Inline-edit refresh merged over the fetched payload — workspace fields
    // (sections / readiness / flags) update in place; notes & ai_review stay so
    // locally-posted notes aren't lost.
    const [dataPatch, setDataPatch] = useState(null);
    const data = useMemo(() => (dataPatch ? { ...dataProp, ...dataPatch } : dataProp), [dataProp, dataPatch]);
    const sections = data?.sections || [];

    const [review, setReview] = useState(null);
    const [aiRunning, setAiRunning] = useState(false);
    const [notes, setNotes] = useState([]);
    const [noteDraft, setNoteDraft] = useState("");
    const [postingNote, setPostingNote] = useState(false);
    const [noteError, setNoteError] = useState("");
    const [convertOpen, setConvertOpen] = useState(false);
    const [rightTab, setRightTab] = useState("notes");
    const [editSection, setEditSection] = useState(null);
    const [draft, setDraft] = useState({});
    const [savingSection, setSavingSection] = useState(false);
    const triedRef = useRef(false);

    const runConvert = () => {
        setConvertOpen(false);
        router.post(`/portal/immigration/assessments/${i.assessment_id ?? i.id}/convert-to-case`, { intake_type: i.visa_type, intake_id: i.id }, { preserveScroll: true, onSuccess: onClose });
    };
    const base = `/portal/immigration/assessments/${i.visa_type}/${i.id}/ai-review`;
    const notesBase = `/portal/immigration/assessments/${i.visa_type}/${i.id}/notes`;

    // Lock the page behind the modal so scrolling a pane doesn't scroll the list.
    // Skipped in page mode — there's no list underneath to isolate.
    useEffect(() => {
        if (asPage) return;
        const prev = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        return () => { document.body.style.overflow = prev; };
    }, [asPage]);

    // Adopt the review + notes. When a caller supplies its own notes (e.g. the
    // case profile's VIF-row thread), those win over the assessment notes.
    useEffect(() => {
        if (dataProp?.ai_review) setReview(dataProp.ai_review);
        if (notesOverride !== undefined) setNotes(notesOverride);
        else if (dataProp) setNotes(dataProp.notes || []);
    }, [dataProp, notesOverride]);

    // Run the AI automatically the first time — no manual button needed.
    useEffect(() => {
        if (loading || !data || review || aiRunning || triedRef.current) return;
        triedRef.current = true;
        setAiRunning(true);
        fetch(base, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...assessCsrf() } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => { if (d?.review) setReview(d.review); })
            .catch(() => {})
            .finally(() => setAiRunning(false));
    }, [loading, data, review, aiRunning, base]);

    const flags = data?.flags || [];
    const readiness = data?.readiness || null;
    const observations = (review?.observations || []).filter(substantiveObs);
    const risks = (review?.risks || []).filter(substantiveObs);
    // Deterministic checks first, then the AI's substantive read — both mark the form.
    const allMarks = [...flags, ...observations];
    // Real issues only (blanks are excluded — they already show as "—").
    const points = [...flags, ...observations, ...risks.map((r) => ({ field: r.area, severity: r.severity, note: r.note }))]
        .filter((p) => p && p.note);

    // Highest-severity mark for a field (fuzzy name match), or null.
    const markFor = (label) => {
        const nl = normKey(label);
        if (!nl) return null;
        const hits = allMarks.filter((o) => { const nf = normKey(o.field); return nf && (nf.includes(nl) || nl.includes(nf)) && nf.length > 2; });
        if (!hits.length) return null;
        return [...hits].sort((a, b) => (SEV_RANK[b.severity] || 1) - (SEV_RANK[a.severity] || 1))[0];
    };

    const postNote = async () => {
        const body = noteDraft.trim();
        if (!body || postingNote) return;
        setPostingNote(true);
        setNoteError("");
        const failedMessage = "Could not save the note. Your draft is still here; please try again.";
        try {
            // A caller-supplied poster (case profile) handles persistence + refresh;
            // otherwise post to the assessment's own notes.
            if (onPostNote) {
                if (await onPostNote(body) === false) throw new Error(failedMessage);
            } else {
                const response = await fetch(notesBase, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...assessCsrf() }, body: JSON.stringify({ body }) });
                const result = await response.json().catch(() => null);
                if (!response.ok) {
                    const validation = response.status === 422 && Object.values(result?.errors || {}).flat()[0];
                    throw new Error(response.status === 419
                        ? "Your session expired. Copy your note, refresh the page, and try again."
                        : validation || failedMessage);
                }
                if (!result?.note) throw new Error(failedMessage);
                setNotes((prev) => [result.note, ...prev]);
            }
            setNoteDraft("");
        } catch (error) {
            setNoteError(error instanceof TypeError ? failedMessage : (error.message || failedMessage));
        } finally {
            setPostingNote(false);
        }
    };

    // Humanise a role slug ("immigration_adviser" → "Immigration adviser").
    const humanRole = (r) => (r ? String(r).replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase()) : "");
    const relTime = (iso) => {
        if (!iso) return "";
        const d = new Date(iso), s = Math.floor((Date.now() - d.getTime()) / 1000);
        if (s < 60) return "just now";
        if (s < 3600) return `${Math.floor(s / 60)}m ago`;
        if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
        if (s < 604800) return `${Math.floor(s / 86400)}d ago`;
        return d.toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" });
    };
    const initials = (n) => (n || "?").split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();

    // ── Readiness-workspace derived data (completeness only — NOT eligibility) ──
    const sectionStats = sections.map((s) => {
        const total = s.fields.length;
        const filled = s.fields.filter((f) => f.provided !== false).length;
        return { title: s.title, filled, total, complete: filled >= total, empty: filled === 0 };
    });
    const gaps = sections.flatMap((s) => s.fields.filter((f) => f.provided === false).map((f) => ({ section: s.title, ...f })));
    // Blockers = deterministic adviser checks (passport/visa validity, English…)
    // at high/critical severity — reliable, computed, never AI-guessed.
    const blockers = flags.filter((f) => ["critical", "high"].includes(f.severity));
    const docCount = data?.documents_count ?? 0;
    const firstName = (data?.name || i.name || "").split(" ")[0];
    const scrollToSection = (idx) => document.getElementById(`vif-sec-${idx}`)?.scrollIntoView({ behavior: "smooth", block: "start" });
    // Display-only affordance — sending answer requests to the applicant from
    // here isn't wired up; use the applicant edit-link / document request flow.
    const requestAnswers = () => toast("Send answer requests via the applicant's edit link — not from here.");
    const ring = 2 * Math.PI * 24;

    // Inline "Edit section" — seed a draft from the section's raw values, save
    // the whole section to the staff update endpoint, then merge the fresh data.
    const startEdit = (idx, sec) => {
        const d = {};
        sec.fields.forEach((f) => { if (f.editable) d[f.key] = f.raw ?? ""; });
        setDraft(d);
        setEditSection(idx);
    };
    const cancelEdit = () => { setEditSection(null); setDraft({}); };
    const saveSection = () => {
        if (savingSection) return;
        setSavingSection(true);
        fetch(`/portal/immigration/intakes/${i.visa_type}/${i.id}`, {
            method: "PATCH",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...assessCsrf() },
            body: JSON.stringify({ fields: draft }),
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((fresh) => {
                if (fresh?.sections) {
                    // Merge the whole fresh payload (keys the endpoint omits keep
                    // their existing value — e.g. free assessments have no docs count).
                    setDataPatch(fresh);
                    setEditSection(null);
                    setDraft({});
                    toast.success("Saved.");
                } else {
                    toast.error("Could not save changes.");
                }
            })
            .catch(() => toast.error("Could not save changes."))
            .finally(() => setSavingSection(false));
    };

    return (
        <div
            className={asPage ? "" : "fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"}
            onClick={asPage ? undefined : onClose}
        >
            <div
                className={chromeless
                    ? "w-full rounded-2xl bg-white border border-gray-100 shadow-sm flex flex-col overflow-visible"
                    : asPage
                        ? "w-full h-[calc(100vh-6.5rem)] min-h-[560px] rounded-2xl bg-white border border-gray-100 shadow-sm flex flex-col overflow-hidden"
                        : "w-[94vw] max-w-[1440px] h-[92vh] rounded-2xl bg-white shadow-xl flex flex-col overflow-hidden"}
                onClick={asPage ? undefined : (e) => e.stopPropagation()}
            >
                {!chromeless && (
                <>
                {/* Header — progress ring, gap / blocker badges, official export */}
                <div className="px-6 py-4 border-b border-gray-100 flex items-start gap-4">
                    <div className="relative flex-shrink-0" style={{ width: 56, height: 56 }}>
                        <svg width="56" height="56" viewBox="0 0 56 56" className="-rotate-90">
                            <circle cx="28" cy="28" r="24" fill="none" stroke="#e5e7eb" strokeWidth="5" />
                            <circle cx="28" cy="28" r="24" fill="none" stroke="#009688" strokeWidth="5" strokeLinecap="round"
                                strokeDasharray={ring} strokeDashoffset={ring * (1 - (readiness?.pct ?? 0) / 100)} />
                        </svg>
                        <span className="absolute inset-0 flex flex-col items-center justify-center leading-none">
                            <span className="text-[13px] font-bold text-gray-900 tabular-nums">{readiness?.pct ?? 0}%</span>
                            {readiness && <span className="text-[8px] text-gray-400 tabular-nums">{readiness.filled}/{readiness.total}</span>}
                        </span>
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-[#009688] mb-0.5">
                            Visa assessment · {data?.visa_label || VISA_LABEL[i.visa_type] || i.visa_type}
                        </p>
                        <div className="flex items-center gap-2.5 flex-wrap">
                            <h2 className="text-lg font-bold text-gray-900 truncate">{data?.name || i.name}</h2>
                            {gaps.length > 0 && (
                                <span className="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-gray-900 text-white">{gaps.length} gaps</span>
                            )}
                            {blockers.length > 0 && (
                                <span className="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-red-100 text-red-700 border border-red-200">{blockers.length} block lodgement</span>
                            )}
                            {aiRunning && <Loader2 size={12} className="animate-spin text-indigo-400" />}
                        </div>
                        <p className="text-[11.5px] text-gray-500 mt-1 leading-snug break-words">
                            <span className="font-mono text-gray-600">{data?.reference || i.intake_id}</span>
                            {data?.submitted_at && <span> · Submitted {fmtDate(data.submitted_at)}</span>}
                            {(data?.email || i.email) && <span> · {data?.email || i.email}</span>}
                            {(data?.phone || i.phone) && <span> · {data?.phone || i.phone}</span>}
                            <span className="text-gray-400"> · Internal &amp; indicative — not immigration advice</span>
                        </p>
                    </div>
                    <div className="flex items-center gap-2 flex-shrink-0">
                        <a href={`/portal/immigration/intakes/${i.visa_type}/${i.id}/pdf`} target="_blank" rel="noreferrer"
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" title="Official Visa Information Form (PDF)"><FileText size={14} /> PDF</a>
                        <a href={`/portal/immigration/intakes/${i.visa_type}/${i.id}/word`}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" title="Official Visa Information Form (Word)"><FileText size={14} /> Word</a>
                        {asPage ? (
                            <button type="button" onClick={onClose} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 ml-1"><ChevronLeft size={14} /> Back</button>
                        ) : (
                            <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700 ml-1"><X size={18} /></button>
                        )}
                    </div>
                </div>
                </>
                )}

                {/* Body — readiness workspace: section nav · gaps · notes/docs */}
                {loading ? (
                    <div className="flex-1 flex items-center justify-center gap-2 text-gray-400 text-sm">
                        <Loader2 size={18} className="animate-spin" /> Loading submission…
                    </div>
                ) : (
                <div className={chromeless ? "flex" : "flex-1 min-h-0 flex overflow-hidden"}>
                    {/* LEFT rail — form-section nav + completeness */}
                    <aside className={`hidden md:flex flex-col w-56 flex-shrink-0 border-r border-gray-100 p-4 gap-4 ${chromeless ? "self-start sticky top-4" : "overflow-y-auto"}`}>
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Form sections</p>
                            <nav className="space-y-0.5">
                                {sectionStats.map((s, idx) => (
                                    <button key={s.title} type="button" onClick={() => scrollToSection(idx)}
                                        className="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-left hover:bg-gray-50">
                                        <span className="flex items-center gap-2 min-w-0">
                                            <span className={`w-1.5 h-1.5 rounded-full flex-shrink-0 ${s.empty ? "bg-red-400" : s.complete ? "bg-emerald-500" : "bg-amber-400"}`} />
                                            <span className="text-[12.5px] text-gray-700 truncate">{s.title}</span>
                                        </span>
                                        <span className="text-[11px] tabular-nums text-gray-400 flex-shrink-0">{s.filled}/{s.total}</span>
                                    </button>
                                ))}
                            </nav>
                        </div>
                        <div className="mt-auto rounded-xl border border-gray-100 bg-gray-50 p-3">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Completeness</p>
                            <p className="text-[22px] font-bold text-gray-900 mt-1 tabular-nums">{readiness ? `${readiness.filled} / ${readiness.total}` : "—"}</p>
                            <p className="text-[11px] text-gray-500 mt-0.5">fields answered{gaps.length ? ` · ${gaps.length} gap${gaps.length === 1 ? "" : "s"}` : ""}</p>
                            {readiness?.recommendation && <p className="text-[11px] text-gray-500 mt-2 leading-snug">{readiness.recommendation}</p>}
                        </div>
                    </aside>

                    {/* CENTER — blockers + section field cards */}
                    <div className={`flex-1 min-w-0 p-6 bg-gray-100 space-y-5 ${chromeless ? "" : "overflow-y-auto overscroll-contain"}`}>
                        {sections.length === 0 ? (
                            <div className="text-center py-14">
                                <FileText size={24} className="mx-auto text-gray-300" />
                                <p className="mt-3 text-sm text-gray-600">No form details to show for this submission.</p>
                            </div>
                        ) : (
                        <>
                            {blockers.length > 0 && (
                                <div className="rounded-2xl bg-gray-900 text-white p-5">
                                    <div className="flex items-center justify-between gap-3 mb-3 flex-wrap">
                                        <p className="text-[13px] font-bold">Chase these before lodging
                                            <span className="font-medium text-gray-400"> · {blockers.length} flagged{gaps.length ? ` of ${gaps.length} gaps` : ""}</span>
                                        </p>
                                        {gaps.length > 0 && (
                                            <button type="button" onClick={requestAnswers}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white text-gray-900 text-[12px] font-bold hover:bg-gray-100">
                                                Request {gaps.length} answer{gaps.length === 1 ? "" : "s"}{firstName ? ` from ${firstName}` : ""}
                                            </button>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        {blockers.slice(0, 6).map((b, k) => (
                                            <div key={k} className="rounded-xl border border-white/10 bg-white/5 p-3">
                                                <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 truncate">{b.field}</p>
                                                <p className="text-[12.5px] font-semibold text-white mt-1 leading-snug">{b.note}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {sections.map((sec, idx) => {
                                const missing = sec.fields.filter((f) => f.provided === false).length;
                                const editing = editSection === idx;
                                return (
                                    <section key={sec.title} id={`vif-sec-${idx}`} className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5 scroll-mt-4">
                                        <div className="flex items-center justify-between gap-2 mb-4 pb-2 border-b border-gray-100">
                                            <div className="flex items-center gap-2">
                                                <h3 className="text-[14px] font-bold text-gray-900">{sec.title}</h3>
                                                {!editing && missing > 0 && <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-100">{missing} missing</span>}
                                            </div>
                                            {editing ? (
                                                <div className="flex items-center gap-2 flex-shrink-0">
                                                    <button type="button" onClick={cancelEdit} disabled={savingSection} className="text-[11px] font-semibold text-gray-500 hover:underline disabled:opacity-50">Cancel</button>
                                                    <button type="button" onClick={saveSection} disabled={savingSection} className="inline-flex items-center gap-1 px-3 py-1 rounded-md bg-[#009688] text-white text-[11px] font-bold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                                                        {savingSection && <Loader2 size={11} className="animate-spin" />} Save
                                                    </button>
                                                </div>
                                            ) : (
                                                <button type="button" onClick={() => startEdit(idx, sec)} className="text-[11px] font-semibold text-[#009688] hover:underline flex-shrink-0">Edit section</button>
                                            )}
                                        </div>
                                        <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3">
                                            {sec.fields.map((f) => {
                                                const m = markFor(f.label);
                                                const sev = m ? (FIELD_SEV[m.severity] || FIELD_SEV.info) : null;
                                                const unanswered = f.provided === false;
                                                if (editing && f.editable) {
                                                    const isDate = /^\d{4}-\d{2}-\d{2}/.test(f.raw || "") || /(date|expiry|dob|arrival)/i.test(f.key);
                                                    return (
                                                        <div key={f.key} className="min-w-0">
                                                            <dt className="text-[10px] font-bold uppercase tracking-wider mb-1 text-gray-400">{f.label}</dt>
                                                            <input
                                                                type={isDate ? "date" : "text"}
                                                                value={draft[f.key] ?? ""}
                                                                onChange={(e) => setDraft((d) => ({ ...d, [f.key]: e.target.value }))}
                                                                className="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688] focus:ring-1 focus:ring-[#009688]"
                                                            />
                                                        </div>
                                                    );
                                                }
                                                return (
                                                    <div key={f.key} className={`min-w-0 rounded-lg ${unanswered ? "border border-dashed border-amber-200 bg-amber-50/50 px-3 py-2" : sev ? `pl-3 border-l-2 ${sev.border}` : ""}`}>
                                                        <dt className={`text-[10px] font-bold uppercase tracking-wider mb-0.5 ${unanswered ? "text-amber-700" : sev ? sev.label : "text-gray-400"}`}>{f.label}{editing && !f.editable && <span className="ml-1 font-normal normal-case text-gray-300">· read-only</span>}</dt>
                                                        {unanswered ? (
                                                            <dd className="text-[12.5px] italic text-amber-700/80">Not answered</dd>
                                                        ) : (
                                                            <dd className={`text-[13px] whitespace-pre-line break-words ${sev ? `font-semibold ${sev.value}` : "text-gray-800"}`}>{f.value}</dd>
                                                        )}
                                                        {m && !unanswered && <p className={`text-[11px] mt-0.5 ${sev.note}`}>{m.note}</p>}
                                                    </div>
                                                );
                                            })}
                                        </dl>
                                    </section>
                                );
                            })}
                        </>
                        )}
                    </div>

                    {/* RIGHT rail — notes & activity / documents */}
                    <aside className={`hidden lg:flex flex-col w-[340px] flex-shrink-0 border-l border-gray-100 overflow-hidden ${chromeless ? "self-start sticky top-4 h-[72vh]" : ""}`}>
                        <div className="flex items-center gap-4 px-4 pt-3 border-b border-gray-100">
                            <button type="button" onClick={() => setRightTab("notes")} className={`pb-2 text-[12.5px] font-bold border-b-2 -mb-px ${rightTab === "notes" ? "border-[#009688] text-gray-900" : "border-transparent text-gray-400 hover:text-gray-600"}`}>Notes &amp; activity</button>
                            <button type="button" onClick={() => setRightTab("docs")} className={`pb-2 text-[12.5px] font-bold border-b-2 -mb-px ${rightTab === "docs" ? "border-[#009688] text-gray-900" : "border-transparent text-gray-400 hover:text-gray-600"}`}>Documents{docCount ? ` (${docCount})` : ""}</button>
                        </div>

                        {rightTab === "notes" ? (
                            <div className="flex-1 min-h-0 flex flex-col">
                                <div className="p-4 border-b border-gray-100">
                                    <div className="rounded-xl border border-gray-200 bg-gray-50 focus-within:bg-white focus-within:border-[#009688] transition-colors">
                                        <textarea value={noteDraft} onChange={(e) => setNoteDraft(e.target.value)} rows={2}
                                            placeholder="Add a note…"
                                            className="w-full bg-transparent px-3 py-2 text-[13px] outline-none resize-y placeholder:text-gray-400" />
                                    </div>
                                    {noteError && <p role="alert" className="mt-2 text-xs text-rose-600">{noteError}</p>}
                                    <div className="flex items-center justify-between mt-2 gap-2">
                                        <div className="flex flex-wrap gap-1">
                                            {["Chased applicant", "Waiting on employer", "Needs adviser review", "Log a call"].map((t) => (
                                                <button key={t} type="button" onClick={() => setNoteDraft((d) => d || t)} className="px-2 py-0.5 rounded-full text-[10.5px] font-medium text-gray-500 border border-gray-200 hover:bg-gray-50">{t}</button>
                                            ))}
                                        </div>
                                        <button type="button" onClick={postNote} disabled={postingNote || !noteDraft.trim()}
                                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-[#009688] text-white text-[12px] font-bold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400 flex-shrink-0">
                                            {postingNote ? <Loader2 size={12} className="animate-spin" /> : <Send size={12} />} Post
                                        </button>
                                    </div>
                                </div>
                                <div className="flex-1 overflow-y-auto overscroll-contain p-4 space-y-4">
                                    {notes.length > 0 ? [...notes].map((n) => (
                                        <div key={n.id} className="flex items-start gap-2.5">
                                            <div className="flex-shrink-0 w-7 h-7 rounded-full bg-[#009688]/15 text-[#00796b] flex items-center justify-center text-[10px] font-bold">{initials(n.author)}</div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-baseline gap-1.5 flex-wrap">
                                                    <span className="text-[12.5px] font-bold text-gray-900">{n.author}</span>
                                                    {n.role && <span className="text-[10px] font-medium text-gray-400">{humanRole(n.role)}</span>}
                                                    <span className="text-[10.5px] text-gray-400" title={n.at ? new Date(n.at).toLocaleString("en-NZ") : ""}>{relTime(n.at)}</span>
                                                </div>
                                                <p className="text-[13px] text-gray-700 whitespace-pre-line break-words mt-0.5">{n.body}</p>
                                            </div>
                                        </div>
                                    )) : <p className="text-center text-[12px] text-gray-400 pt-6">No notes yet — be the first to comment.</p>}
                                </div>
                            </div>
                        ) : (
                            <div className="flex-1 overflow-y-auto p-4 space-y-2">
                                {(data?.documents || []).length > 0 ? (
                                    data.documents.map((doc, k) => {
                                        const isImg = doc.is_image || /\.(jpe?g|png|gif|webp|bmp|heic)$/i.test(doc.label || "") || /\.(jpe?g|png|gif|webp|bmp|heic)(\?|$)/i.test(doc.url || "");
                                        const meta = [doc.ext, doc.status].filter(Boolean).join(" · ");
                                        return (
                                            <a key={k} href={doc.url} target="_blank" rel="noreferrer"
                                                className="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-2.5 hover:border-[#009688] hover:bg-gray-50 transition-colors">
                                                <span className="flex-shrink-0 w-10 h-10 rounded-lg overflow-hidden bg-gray-50 flex items-center justify-center">
                                                    {isImg
                                                        ? <img src={doc.url} alt={doc.label} loading="lazy" className="w-full h-full object-cover" />
                                                        : <FileText size={16} className="text-[#00796b]" />}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-[12.5px] font-semibold text-gray-800 truncate">{doc.label}</span>
                                                    {meta && <span className="block text-[10.5px] text-gray-400 truncate">{meta}</span>}
                                                </span>
                                                <ExternalLink size={13} className="text-gray-400 flex-shrink-0" />
                                            </a>
                                        );
                                    })
                                ) : (
                                    <p className="text-center text-[12px] text-gray-400 pt-6">No documents uploaded.</p>
                                )}
                            </div>
                        )}
                    </aside>
                </div>
                )}

                {!chromeless && (
                <>
                {/* Pre-screening (potential-client vetting) strip. */}
                <PreScreenBar i={i} />

                {/* Footer — indicative note + primary actions. */}
                <div className="px-6 py-3 border-t border-gray-100 flex items-center justify-between gap-3">
                    <p className="text-[11px] text-gray-400 truncate">
                        {readiness ? `${readiness.verdict} · ${readiness.pct}% complete` : "Completeness only"} — internal &amp; indicative, not immigration advice
                    </p>
                    <div className="flex items-center gap-2 flex-shrink-0">
                        {i.can_convert && (
                            <button type="button"
                                onClick={() => setConvertOpen(true)}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-sm font-semibold hover:bg-[#00796b]">
                                <ArrowRightCircle size={15} /> Refer / Convert to case
                            </button>
                        )}
                        <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">Done</button>
                    </div>
                </div>
                </>
                )}
            </div>

            <ConfirmModal
                open={convertOpen}
                title="Convert to case"
                message="Convert this assessment to an immigration case? A case will be created (or matched on email)."
                confirmLabel="Convert to case"
                onConfirm={runConvert}
                onCancel={() => setConvertOpen(false)}
            />
        </div>
    );
}

/**
 * Readiness chip — how complete/clean this submission is, so the adviser can
 * prioritise. Completeness-based, NOT an eligibility or outcome prediction.
 */
function ReadinessChip({ readiness, pct, reviewed }) {
    const meta = READINESS[readiness] || READINESS.needs_info;
    const title = `${pct ?? 0}% of the form filled${reviewed ? " · AI review flags included" : ""} — completeness only, not an eligibility signal`;
    return (
        <span
            title={title}
            className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${meta.chip}`}
        >
            <span className={`w-1.5 h-1.5 rounded-full ${meta.dot}`} />
            {meta.label}
            {typeof pct === "number" && <span className="font-semibold tabular-nums opacity-70">{pct}%</span>}
        </span>
    );
}

// ── Journey timeline ───────────────────────────────────────────────────────

// Three-step applicant journey shown in the expanded row. Payment +
// booking are intentionally omitted while payment intake stays disabled;
// when AssessmentController::simulatePay gets a real Stripe body, Pay
// and Booked can return as a separate strip without touching this one.
function JourneyRow({ intake: i }) {
    const j = i.journey || {};
    const fmt = (iso) => iso ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : null;

    const steps = [
        {
            key: "submitted",
            label: "Submitted",
            icon: <FileText size={14} />,
            done: !! j.submitted,
            sub:  j.submitted_at ? fmt(j.submitted_at) : "Not yet submitted",
            tip:  "Applicant submitted the visa-interest form. Always true for any visible row.",
        },
        {
            key: "triaged",
            label: "Triaged",
            icon: <UserCheck size={14} />,
            done: !! j.triaged,
            sub:  j.triaged ? `Status: ${i.status}` : "Awaiting adviser triage",
            tip:  "Staff have changed the intake status away from the default Submitted/New state.",
        },
        {
            key: "converted",
            label: "Converted to Case",
            icon: <ArrowRightCircle size={14} />,
            done: !! j.converted,
            sub:  j.converted ? "Linked Lead is an immigration case" : "Not yet converted",
            tip:  "A Lead with this applicant's email is flagged as an immigration case (is_immigration_case = true).",
        },
    ];

    return (
        <div>
            <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-400 mb-3">
                Applicant journey
            </p>

            <div className="flex items-start">
                {steps.map((s, idx) => {
                    const lastOne   = idx === steps.length - 1;
                    const nextDone  = ! lastOne && steps[idx + 1].done;
                    return (
                        <div key={s.key} className="flex-1 min-w-0">
                            <div className="flex items-center">
                                {/* Step circle */}
                                <span
                                    title={s.tip}
                                    className={`flex-shrink-0 w-9 h-9 rounded-full inline-flex items-center justify-center border-2 transition-colors cursor-help ${
                                        s.done
                                            ? "bg-emerald-500 border-emerald-500 text-white"
                                            : "bg-white border-gray-300 text-gray-400"
                                    }`}
                                >
                                    {s.done ? <Check size={15} strokeWidth={3} /> : s.icon}
                                </span>

                                {/* Connector to next step */}
                                {! lastOne && (
                                    <div className="flex-1 h-0.5 mx-1.5">
                                        <div className={`h-full rounded-full transition-colors ${
                                            s.done && nextDone ? "bg-emerald-500"
                                            : s.done           ? "bg-gradient-to-r from-emerald-500 to-gray-200"
                                            :                    "bg-gray-200"
                                        }`} />
                                    </div>
                                )}
                            </div>

                            {/* Label + subtext */}
                            <div className="mt-2 pr-3">
                                <p
                                    title={s.tip}
                                    className={`text-[11.5px] font-bold cursor-help ${s.done ? "text-gray-900" : "text-gray-500"}`}
                                >
                                    {s.label}
                                </p>
                                <p className={`text-[10.5px] mt-0.5 ${s.done ? "text-gray-600" : "text-gray-400 italic"}`}>
                                    {s.sub}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function EmptyState({ icon, title, action }) {
    return (
        <div className="py-14 text-center text-gray-400">
            <div className="inline-flex items-center justify-center mb-3 text-gray-300">{icon}</div>
            <p className="text-sm font-medium text-gray-600">{title}</p>
            {action}
        </div>
    );
}
