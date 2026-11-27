import { Head, Link } from "@inertiajs/react";
import {
    User, Mail, Phone, MapPin, Hash, Clock, FileText, ClipboardList,
    Calendar, Megaphone, ArrowRight, ChevronRight, Check, AlertCircle,
    Sparkles, MapPinned, Users, TrendingUp, FolderCheck, CalendarClock,
    Heart, Baby, UserCircle2, ShieldCheck, Download, Eye,
} from "lucide-react";

// ── Theme ─────────────────────────────────────────────────────────────────────
// Immigration cyan/teal on white — matches the Immigration portal (#009688).
const T = "#009688";       // primary teal
const T_DARK = "#00796b";  // deep teal (hero gradient)
const T_SOFT = "#b2dfdb";  // pale teal (on-dark accents)
const INK = "#0f172a";     // slate-900 text

const STATUS_CHIP = {
    New: "bg-sky-50 text-sky-700 border-sky-200",
    Contacted: "bg-amber-50 text-amber-700 border-amber-200",
    Qualified: "bg-violet-50 text-violet-700 border-violet-200",
    Processing: "bg-indigo-50 text-indigo-700 border-indigo-200",
    Closed: "bg-emerald-50 text-emerald-700 border-emerald-200",
};
const statusChip = (s) => STATUS_CHIP[s] || "bg-gray-50 text-gray-600 border-gray-200";

const REL_META = {
    child:   { label: "Child",   Icon: Baby },
    partner: { label: "Partner", Icon: Heart },
    parent:  { label: "Parent",  Icon: UserCircle2 },
    sibling: { label: "Sibling", Icon: Users },
    other:   { label: "Other",   Icon: Users },
};

const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "long", year: "numeric" }) : "—";
const fmtShort = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short" }) : "";
const initials = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase() || "C";

export default function LeadDashboard({
    lead,
    submissionsCounts = {},
    nextActivity = null,
    latestAnnouncement = null,
    documentSummary = { total: 0, pending: 0, approved: 0, rejected: 0 },
    roadmap = [],
    currentPhase = null,
    preEngagement = false,
    family = [],
    nextAppointment = null,
    analytics = {},
    requestedDocuments = { total: 0, outstanding: 0, items: [] },
    sharedDocuments = [],
}) {
    return (
        <div className="space-y-8 max-w-6xl mx-auto pb-16">
            <Head title="My EP Portal" />

            {/* ── Hero ───────────────────────────────────────────────────── */}
            <section
                className="relative rounded-[24px] overflow-hidden text-white shadow-[0_30px_60px_-30px_rgba(0,150,136,0.55)]"
                style={{ background: `linear-gradient(135deg, ${T_DARK} 0%, ${T} 55%, #26a69a 100%)` }}
            >
                <div className="absolute -top-16 -right-10 w-72 h-72 rounded-full bg-white/10 blur-2xl" />
                <div className="absolute -bottom-24 -left-10 w-72 h-72 rounded-full bg-black/10 blur-2xl" />
                <div className="relative p-8 sm:p-10 lg:p-12 grid grid-cols-1 lg:grid-cols-3 gap-8 items-center">
                    <div className="lg:col-span-2 flex items-center gap-5">
                        <div className="w-16 h-16 rounded-2xl bg-white/15 ring-1 ring-white/30 backdrop-blur flex items-center justify-center font-black text-2xl flex-shrink-0">
                            {initials(`${lead.first_name} ${lead.last_name}`)}
                        </div>
                        <div className="min-w-0">
                            <p className="text-[10px] font-bold uppercase tracking-[0.35em] mb-1.5" style={{ color: T_SOFT }}>
                                Welcome back
                            </p>
                            <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight leading-[1.1]">
                                Hi, {lead.first_name}.
                            </h1>
                            <p className="text-sm text-white/75 font-light leading-relaxed mt-2.5 max-w-xl">
                                Here&apos;s where your application stands today — your progress, your documents, and your family, all in one place.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                        <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-[0.18em] border ${statusChip(lead.status)}`}>
                            <Clock size={11} strokeWidth={2.5} /> {lead.status || "New"}
                        </span>
                        {lead.stage && (
                            <span className="inline-flex items-center px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-[0.18em] bg-white/15 text-white border border-white/25">
                                {lead.stage}
                            </span>
                        )}
                        <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-[0.18em] bg-white/10 text-white/80 border border-white/15">
                            <Hash size={10} strokeWidth={2.5} /> {lead.lead_id}
                        </span>
                    </div>
                </div>
            </section>

            {/* ── Documents your adviser requested (action needed) ───────── */}
            {requestedDocuments.outstanding > 0 && (
                <RequestedDocumentsCard data={requestedDocuments} />
            )}

            {/* ── Documents your adviser shared with you (RFI letters etc.) ─ */}
            {sharedDocuments.length > 0 && (
                <SharedDocumentsCard docs={sharedDocuments} />
            )}

            {/* ── Analytics band ─────────────────────────────────────────── */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <StatCard
                    icon={<TrendingUp size={16} />}
                    label="Application progress"
                    value={`${analytics.journey_pct ?? 0}%`}
                    sub={analytics.journey_total ? `Step ${analytics.journey_step} of ${analytics.journey_total}` : "Getting started"}
                    pct={analytics.journey_pct ?? 0}
                />
                <StatCard
                    icon={<FolderCheck size={16} />}
                    label="My documents"
                    value={`${documentSummary.approved ?? 0}/${documentSummary.total ?? 0}`}
                    sub={documentSummary.pending ? `${documentSummary.pending} awaiting review` : documentSummary.total ? "All reviewed" : "None yet"}
                    tone={documentSummary.pending ? "amber" : "teal"}
                    href="/portal/lead/requirements"
                />
                <StatCard
                    icon={<Users size={16} />}
                    label="My family"
                    value={String(analytics.family_count ?? family.length ?? 0)}
                    sub={analytics.family_docs_total ? `${analytics.family_pct}% documents ready` : "No members added"}
                    pct={analytics.family_docs_total ? analytics.family_pct : null}
                    href="/portal/lead/family"
                />
                <StatCard
                    icon={<CalendarClock size={16} />}
                    label="Next appointment"
                    value={nextAppointment?.appointment_date ? fmtShort(nextAppointment.appointment_date) : "—"}
                    sub={nextAppointment ? (nextAppointment.service_type || "Consultation") : "None booked"}
                    tone="teal"
                    href={nextAppointment ? "/portal/lead/appointments" : "/booking"}
                />
            </div>

            {/* ── My family status ───────────────────────────────────────── */}
            {family.length > 0 && (
                <>
                    <SectionHeader title="My family" eyebrow="Included in my application" href="/portal/lead/family" cta="Manage family" />
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {family.map((m) => <FamilyCard key={m.id} m={m} />)}
                    </div>
                </>
            )}

            {/* ── My submissions ─────────────────────────────────────────── */}
            <SectionHeader title="My submissions" eyebrow="At a glance" href="/portal/lead/submissions" cta="View all" />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <SubmissionCard
                    icon={<ClipboardList size={18} />}
                    title="Free Assessment"
                    state={submissionsCounts.assessment_submitted ? "Submitted" : "Not submitted"}
                    detail={
                        submissionsCounts.assessment_submitted
                            ? (submissionsCounts.ai_status === "completed" ? "Reviewed by our team" : "In review")
                            : "Start when ready"
                    }
                    href={submissionsCounts.assessment_submitted ? "/portal/lead/submissions" : "/free-assessment"}
                    tone={submissionsCounts.assessment_submitted ? "success" : "muted"}
                />
                <SubmissionCard
                    icon={<Calendar size={18} />}
                    title="Consultations"
                    state={submissionsCounts.bookings ? `${submissionsCounts.bookings} booked` : "None yet"}
                    detail={submissionsCounts.bookings ? "Check status in My Submissions" : "Book a 1:1 anytime"}
                    href={submissionsCounts.bookings ? "/portal/lead/submissions" : "/booking"}
                    tone={submissionsCounts.bookings ? "success" : "muted"}
                />
                <SubmissionCard
                    icon={<FileText size={18} />}
                    title="Documents"
                    state={documentSummary.total ? `${documentSummary.total} uploaded` : "None yet"}
                    detail={
                        documentSummary.pending
                            ? `${documentSummary.pending} awaiting review`
                            : documentSummary.approved
                                ? `${documentSummary.approved} approved`
                                : "When your adviser requests them"
                    }
                    href="/portal/lead/requirements"
                    tone={documentSummary.pending ? "pending" : documentSummary.total ? "success" : "muted"}
                />
            </div>

            {/* ── Two-col: next activity + latest announcement ───────────── */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <TeaserCard
                    eyebrow="Next activity"
                    icon={<Calendar size={14} />}
                    href="/portal/lead/activities"
                    cta="See all activities"
                    empty={!nextActivity}
                    emptyText="No upcoming activities just yet. Check back soon."
                >
                    {nextActivity && (
                        <>
                            <div className="flex items-baseline gap-3 mb-3">
                                <span className="text-[10px] font-bold uppercase tracking-[0.22em]" style={{ color: T }}>
                                    {nextActivity.type || "Event"}
                                </span>
                                <span className="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">
                                    {fmtShort(nextActivity.date_from)}
                                </span>
                            </div>
                            <h3 className="text-xl font-semibold leading-snug tracking-tight mb-2" style={{ color: INK }}>
                                {nextActivity.name}
                            </h3>
                            {nextActivity.description && (
                                <p className="text-sm text-gray-500 font-light leading-relaxed line-clamp-3">
                                    {nextActivity.description}
                                </p>
                            )}
                        </>
                    )}
                </TeaserCard>

                <TeaserCard
                    eyebrow="Latest from EP"
                    icon={<Megaphone size={14} />}
                    href="/portal/lead/announcements"
                    cta="All announcements"
                    empty={!latestAnnouncement}
                    emptyText="Nothing new right now."
                >
                    {latestAnnouncement && (
                        <>
                            <div className="flex items-baseline gap-3 mb-3">
                                <span className="text-[10px] font-bold uppercase tracking-[0.22em]" style={{ color: T }}>
                                    {latestAnnouncement.subtitle}
                                </span>
                                <span className="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">
                                    {fmtShort(latestAnnouncement.date)}
                                </span>
                            </div>
                            <h3 className="text-xl font-semibold leading-snug tracking-tight line-clamp-3" style={{ color: INK }}>
                                {latestAnnouncement.title}
                            </h3>
                        </>
                    )}
                </TeaserCard>
            </div>

            {/* ── My details ─────────────────────────────────────────────── */}
            <SectionHeader title="My details" eyebrow="On file" href="/portal/lead/profile" cta="Edit profile" />
            <section className="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-px bg-gray-100">
                    <DetailRow icon={<User size={14} />} label="Full name" value={lead.full_name} />
                    <DetailRow icon={<Mail size={14} />} label="Email" value={lead.email || "—"} />
                    <DetailRow icon={<Phone size={14} />} label="Phone" value={lead.phone || "—"} />
                    <DetailRow icon={<MapPin size={14} />} label="Country" value={lead.residence_country || "—"} />
                    <DetailRow icon={<Sparkles size={14} />} label="Joined EP" value={fmtDate(lead.created_at)} fullWidth />
                </dl>
            </section>
        </div>
    );
}

// ── Helpers ─────────────────────────────────────────────────────────────────

// Prominent "your adviser needs documents" call-to-action on the dashboard.
// Lists what's outstanding and sends the client to the Documents page to upload.
// Documents the adviser SHARED with the client (RFI letters, outcome letters).
// View opens inline in a new tab; Download saves the file.
function SharedDocumentsCard({ docs = [] }) {
    return (
        <section className="rounded-[20px] border border-[#282728]/15 bg-white p-6 sm:p-7">
            <div className="flex items-center gap-3 mb-4">
                <div className="w-10 h-10 rounded-xl bg-[#009688]/10 text-[#009688] flex items-center justify-center flex-shrink-0">
                    <FolderCheck size={18} strokeWidth={2.5} />
                </div>
                <div>
                    <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-[#009688] mb-0.5">From your adviser</p>
                    <h2 className="text-lg font-semibold text-[#0f172a]">Shared with you</h2>
                </div>
            </div>
            <ul className="divide-y divide-gray-100">
                {docs.map((d) => (
                    <li key={d.id} className="py-3 flex items-center justify-between gap-3">
                        <div className="flex items-center gap-3 min-w-0">
                            <FileText size={16} className="text-gray-400 flex-shrink-0" />
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-[#282728] truncate">{d.title}</p>
                                {d.created_at && (
                                    <p className="text-[11px] text-gray-400">{new Date(d.created_at).toLocaleDateString()}</p>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-1.5 flex-shrink-0">
                            <a href={d.view_url} target="_blank" rel="noreferrer"
                                className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-[#009688] hover:bg-[#009688]/10">
                                <Eye size={13} /> View
                            </a>
                            <a href={d.download_url}
                                className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-gray-600 hover:bg-gray-100">
                                <Download size={13} /> Download
                            </a>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function RequestedDocumentsCard({ data }) {
    const { outstanding, items = [] } = data;
    return (
        <section className="rounded-[20px] border-2 border-amber-300 bg-amber-50/60 p-6 sm:p-7">
            <div className="flex items-start gap-4 flex-wrap">
                <div className="w-11 h-11 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                    <AlertCircle size={20} strokeWidth={2.5} />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-amber-700 mb-1">Action needed</p>
                    <h2 className="text-lg font-semibold text-[#0f172a]">
                        Your adviser requested {outstanding} document{outstanding === 1 ? "" : "s"}
                    </h2>
                    <p className="text-sm text-gray-600 font-light mt-1">
                        Upload {outstanding === 1 ? "it" : "them"} to keep your application moving.
                    </p>

                    <ul className="mt-4 flex flex-wrap gap-2">
                        {items.map((it) => (
                            <li key={it.id}
                                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[12px] font-semibold border ${
                                    it.status === "Rejected"
                                        ? "bg-red-50 text-red-700 border-red-200"
                                        : "bg-white text-gray-700 border-amber-200"
                                }`}>
                                <FileText size={12} />
                                {it.label}
                                {it.status === "Rejected" && <span className="text-[10px] font-bold uppercase">· needs new file</span>}
                            </li>
                        ))}
                    </ul>
                </div>
                <Link
                    href="/portal/lead/documents"
                    className="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-[#009688] text-white text-sm font-bold hover:bg-[#00796b] transition-colors flex-shrink-0"
                >
                    Upload documents <ArrowRight size={16} />
                </Link>
            </div>
        </section>
    );
}

function StatCard({ icon, label, value, sub, pct = null, tone = "teal", href = null }) {
    const TONES = {
        teal:  { ic: "bg-[#009688]/10 text-[#009688]", bar: "#009688" },
        amber: { ic: "bg-amber-50 text-amber-600",     bar: "#f59e0b" },
    };
    const t = TONES[tone] || TONES.teal;
    const body = (
        <>
            <div className="flex items-center justify-between mb-3">
                <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${t.ic}`}>{icon}</div>
                {href && <ChevronRight size={15} className="text-gray-300 group-hover:text-[#009688] transition-colors" />}
            </div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-400 mb-1">{label}</p>
            <p className="text-2xl font-bold tracking-tight" style={{ color: INK }}>{value}</p>
            <p className="text-[11px] text-gray-500 mt-0.5 truncate">{sub}</p>
            {pct !== null && (
                <div className="mt-3 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                    <div className="h-full rounded-full transition-all" style={{ width: `${Math.min(100, pct)}%`, backgroundColor: t.bar }} />
                </div>
            )}
        </>
    );
    const cls = "group block bg-white rounded-2xl border border-gray-200 p-5 shadow-sm transition-all hover:shadow-[0_20px_40px_-24px_rgba(0,150,136,0.4)] hover:border-[#009688]/40";
    return href ? <Link href={href} className={cls}>{body}</Link> : <div className={cls}>{body}</div>;
}

function FamilyCard({ m }) {
    const rel = REL_META[m.relationship] || REL_META.other;
    const p = m.progress || { required_done: 0, required_total: 0 };
    const pct = p.required_total > 0 ? Math.round((p.required_done / p.required_total) * 100) : 0;
    const complete = m.complete;
    return (
        <Link
            href="/portal/lead/family"
            className="group block bg-white rounded-2xl border border-gray-200 p-5 shadow-sm transition-all hover:shadow-[0_20px_40px_-24px_rgba(0,150,136,0.4)] hover:border-[#009688]/40"
        >
            <div className="flex items-center gap-3">
                {m.photo_url ? (
                    <img src={m.photo_url} alt={m.full_name} className="w-11 h-11 rounded-xl object-cover flex-shrink-0 border border-gray-100" />
                ) : (
                    <div className="w-11 h-11 rounded-xl bg-[#009688]/10 flex items-center justify-center flex-shrink-0">
                        <rel.Icon size={18} className="text-[#009688]" />
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-bold truncate" style={{ color: INK }}>{m.full_name}</p>
                    <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-[#009688]">
                        <rel.Icon size={10} /> {rel.label}
                    </span>
                </div>
                {complete ? (
                    <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <ShieldCheck size={11} /> Ready
                    </span>
                ) : (
                    <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md bg-amber-50 text-amber-700 border border-amber-200">
                        In progress
                    </span>
                )}
            </div>
            <div className="mt-4">
                <div className="flex items-center justify-between mb-1.5">
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Documents</span>
                    <span className="text-[11px] font-bold" style={{ color: complete ? "#059669" : T }}>{p.required_done}/{p.required_total}</span>
                </div>
                <div className="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                    <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, backgroundColor: complete ? "#059669" : T }} />
                </div>
            </div>
        </Link>
    );
}

function SectionHeader({ title, eyebrow, href = null, cta = null }) {
    return (
        <div className="flex items-end justify-between gap-4">
            <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.32em] mb-1.5" style={{ color: T }}>
                    {eyebrow}
                </p>
                <h2 className="text-xl sm:text-2xl font-semibold tracking-tight" style={{ color: INK }}>{title}</h2>
            </div>
            {href && cta && (
                <Link
                    href={href}
                    className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 hover:text-[#009688] border-b border-gray-300 hover:border-[#009688] pb-1 transition-colors"
                >
                    {cta} <ArrowRight size={12} strokeWidth={2.5} />
                </Link>
            )}
        </div>
    );
}

function SubmissionCard({ icon, title, state, detail, href, tone }) {
    const TONES = {
        success: { ring: "border-[#009688]/30 hover:border-[#009688]", dot: "bg-[#009688]", chip: "bg-[#009688]/10 text-[#009688]" },
        pending: { ring: "border-amber-200 hover:border-amber-300",   dot: "bg-amber-500",   chip: "bg-amber-50 text-amber-700" },
        muted:   { ring: "border-gray-200 hover:border-gray-300",     dot: "bg-gray-300",    chip: "bg-gray-50 text-gray-500" },
    };
    const t = TONES[tone] || TONES.muted;

    return (
        <Link
            href={href}
            className={`group block bg-white rounded-2xl border ${t.ring} p-6 shadow-sm transition-all hover:shadow-[0_24px_48px_-24px_rgba(0,150,136,0.2)]`}
        >
            <div className="flex items-start justify-between mb-6">
                <div className="w-10 h-10 rounded-xl bg-[#009688]/10 text-[#009688] flex items-center justify-center">
                    {icon}
                </div>
                <span className={`inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.18em] px-2.5 py-1 rounded-md ${t.chip}`}>
                    <span className={`w-1.5 h-1.5 rounded-full ${t.dot}`}></span>
                    {state}
                </span>
            </div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.22em] text-gray-400 mb-1.5">{title}</p>
            <p className="text-base font-medium leading-snug mb-4" style={{ color: INK }}>{detail}</p>
            <span className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 group-hover:text-[#009688] transition-colors">
                Open <ChevronRight size={12} strokeWidth={2.5} />
            </span>
        </Link>
    );
}

function TeaserCard({ eyebrow, icon, href, cta, empty, emptyText, children }) {
    return (
        <Link
            href={href}
            className="group block bg-white rounded-2xl border border-gray-200 p-7 sm:p-8 shadow-sm hover:border-[#009688]/40 transition-all hover:shadow-[0_24px_48px_-24px_rgba(0,150,136,0.2)]"
        >
            <div className="flex items-center gap-2 mb-5">
                <span style={{ color: T }}>{icon}</span>
                <p className="text-[10px] font-bold uppercase tracking-[0.32em]" style={{ color: T }}>{eyebrow}</p>
            </div>
            {empty ? (
                <div className="py-6 text-center">
                    <AlertCircle size={20} className="mx-auto text-gray-300 mb-2" />
                    <p className="text-sm text-gray-400 font-light">{emptyText}</p>
                </div>
            ) : (
                children
            )}
            <div className="mt-7 pt-5 border-t border-gray-100">
                <span className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 group-hover:text-[#009688] transition-colors">
                    {cta} <ArrowRight size={12} strokeWidth={2.5} />
                </span>
            </div>
        </Link>
    );
}

function JourneyRoadmap({ roadmap = [], currentPhase = null, preEngagement = false }) {
    if (preEngagement) {
        return (
            <section className="bg-white border border-[#009688]/30 rounded-2xl p-8 sm:p-10 text-center shadow-sm">
                <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-[#009688]/10 text-[#009688] mb-4">
                    <Sparkles size={20} />
                </div>
                <p className="text-[10px] font-bold uppercase tracking-[0.32em] mb-2" style={{ color: T }}>
                    Engagement being set up
                </p>
                <h3 className="text-2xl font-semibold tracking-tight mb-3" style={{ color: INK }}>
                    Welcome to EP
                </h3>
                <p className="text-sm text-gray-600 font-light leading-relaxed max-w-xl mx-auto">
                    We&apos;re still finalising your consultancy agreement and payment. Once those are sorted, your full journey roadmap — Agreement, Enrolment, and Visa — will unlock here. Your adviser will be in touch shortly.
                </p>
            </section>
        );
    }

    return (
        <div className="space-y-5">
            {currentPhase && (
                <section
                    className="rounded-2xl overflow-hidden text-white shadow-[0_24px_48px_-28px_rgba(0,150,136,0.6)]"
                    style={{ background: `linear-gradient(135deg, ${T_DARK}, ${T})` }}
                >
                    <div className="p-7 sm:p-8 grid grid-cols-1 lg:grid-cols-12 gap-5 items-center">
                        <div className="lg:col-span-9">
                            <div className="flex items-center gap-2.5 mb-3">
                                <MapPinned size={14} style={{ color: T_SOFT }} />
                                <p className="text-[10px] font-bold uppercase tracking-[0.32em]" style={{ color: T_SOFT }}>
                                    {currentPhase.department ? `With ${currentPhase.department}` : 'Outcome'}
                                </p>
                            </div>
                            <h3 className="text-2xl sm:text-3xl font-semibold tracking-tight text-white leading-tight">
                                {currentPhase.label}
                            </h3>
                            <p className="text-sm sm:text-base text-white/85 font-light mt-3 leading-relaxed max-w-2xl">
                                {currentPhase.lead_copy}
                            </p>
                        </div>
                        <div className="lg:col-span-3 flex lg:justify-end">
                            <div className="inline-flex items-center gap-2.5 px-4 py-2.5 rounded-xl bg-white/15 border border-white/25">
                                <span className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/75">
                                    Step
                                </span>
                                <span className="text-base font-semibold text-white">
                                    {currentPhase.index + 1} / {roadmap.length}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>
            )}

            <ol className="grid grid-cols-1 lg:grid-cols-4 gap-3">
                {roadmap.map((p, i) => {
                    const isDone     = p.state === 'done';
                    const isCurrent  = p.state === 'current';
                    const isUpcoming = p.state === 'upcoming';

                    return (
                        <li
                            key={p.key}
                            className={`relative rounded-2xl p-5 border transition-all ${
                                isCurrent
                                    ? 'bg-white border-[#009688] shadow-[0_24px_48px_-24px_rgba(0,150,136,0.35)]'
                                    : isDone
                                        ? 'bg-[#009688]/5 border-[#009688]/20'
                                        : 'bg-white border-gray-200 opacity-70'
                            }`}
                        >
                            <div className="flex items-center justify-between mb-3">
                                <span
                                    className={`w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold ${
                                        isDone
                                            ? 'bg-[#009688] text-white'
                                            : isCurrent
                                                ? 'bg-[#009688] text-white ring-4 ring-[#009688]/15'
                                                : 'bg-gray-100 text-gray-400 border border-gray-200'
                                    }`}
                                >
                                    {isDone ? <Check size={14} strokeWidth={3} /> : i + 1}
                                </span>
                                <span
                                    className={`text-[9px] font-bold uppercase tracking-[0.22em] ${
                                        isCurrent ? 'text-[#009688]' : isDone ? 'text-[#009688]/70' : 'text-gray-300'
                                    }`}
                                >
                                    {isCurrent ? 'You are here' : isDone ? 'Done' : 'Up next'}
                                </span>
                            </div>

                            <p className={`text-[10px] font-bold uppercase tracking-[0.22em] mb-1 ${isCurrent ? 'text-[#009688]' : 'text-gray-400'}`}>
                                {p.department || 'Outcome'}
                            </p>
                            <h4 className={`text-base font-semibold leading-snug tracking-tight ${isCurrent ? 'text-slate-900' : isUpcoming ? 'text-gray-500' : 'text-gray-700'}`}>
                                {p.label}
                            </h4>
                            <p className={`text-[11px] mt-1.5 leading-relaxed ${isCurrent ? 'text-gray-600' : 'text-gray-400'}`}>
                                {p.description}
                            </p>
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function DetailRow({ icon, label, value, fullWidth = false }) {
    return (
        <div className={`bg-white px-6 py-5 flex items-start gap-4 ${fullWidth ? "sm:col-span-2" : ""}`}>
            <div className="w-9 h-9 rounded-xl bg-[#009688]/10 text-[#009688] flex items-center justify-center flex-shrink-0">
                {icon}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-[10px] font-semibold uppercase tracking-[0.22em] text-gray-400 mb-1">{label}</p>
                <p className="text-sm font-medium truncate" style={{ color: INK }}>{value}</p>
            </div>
        </div>
    );
}
