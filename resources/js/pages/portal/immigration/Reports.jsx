import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { toast } from "sonner";
import {
    Layers, TrendingUp, UserPlus, Handshake, Plane, ThumbsUp, XCircle, CheckCircle2,
    CalendarRange, Activity as ActivityIcon, FileText, BadgeCheck, Send, Clock, Globe,
    HelpCircle, Target, Inbox, Save, RotateCcw, Award, Ban,
} from "lucide-react";
import PortalPageHeader from "@/components/portal/PortalPageHeader";

// Weekly management report — the slide-deck format (Pipeline position → Intake &
// engagements → Submissions & RFIs → Decision outcomes → Conclusion), followed
// by the retained analytics blocks (Activity, 6-month trend, Cases by stage,
// Year-to-date outcomes). All figures come from live case data for the chosen
// period; nothing is generated.

const TEAL = "#0f766e";

const PRESETS = [
    { key: "today", label: "Today" },
    { key: "this_week", label: "This week" },
    { key: "two_weeks", label: "Last 2 weeks" },
    { key: "this_month", label: "This month" },
    { key: "last_month", label: "Last month" },
    { key: "quarter", label: "Last 3 months" },
    { key: "custom", label: "Custom" },
];

const stageColor = (stage) =>
    stage === "Approved Visa" ? "bg-emerald-500"
        : stage === "Decline Visa" ? "bg-rose-400"
        : stage === "Unassigned" ? "bg-gray-300"
        : "bg-gray-700";

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short" }) : "");

export default function ImmigrationReports({
    range = { preset: "this_week", label: "This week" },
    pipeline = {}, namedIntake = {}, submissions = {}, outcomes = {}, conclusion = {},
    activity = {}, stageDistribution = [], totalCases = 0, trend = [], ytd = {},
    generated_at, generated_by,
}) {
    const [from, setFrom] = useState(range.from || "");
    const [to, setTo] = useState(range.to || "");

    const go = (params) => router.get("/portal/immigration/reports", params, { preserveScroll: true, preserveState: true });
    const setPreset = (preset) => (preset === "custom" ? go({ preset: "custom", from, to }) : go({ preset }));
    const applyCustom = () => go({ preset: "custom", from, to });

    const maxStage = Math.max(1, ...stageDistribution.map((s) => s.count || 0));
    const pool = pipeline.pre_lodgement || { paid: 0, awaiting: 0, total: 0 };

    return (
        <div className="space-y-6 max-w-[1180px] mx-auto pb-16">
            <Head title="Immigration report" />
            <PortalPageHeader eyebrow="Updates and reporting" title="Immigration report" description="Pipeline, intake, submissions and outcomes for the period you choose — straight from live case data." />

            {/* Range control */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-2.5 flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-1 flex-wrap">
                    {PRESETS.map((p) => (
                        <button
                            key={p.key}
                            type="button"
                            onClick={() => setPreset(p.key)}
                            className={`px-3.5 py-2 text-[11px] font-bold uppercase tracking-wider rounded-lg transition-colors ${
                                range.preset === p.key ? "bg-gray-900 text-white" : "text-gray-400 hover:text-gray-700 hover:bg-gray-50"
                            }`}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
                <p className="text-[11px] text-gray-400">
                    Showing <span className="font-semibold text-gray-600">{range.label}</span>
                    {range.days ? <span className="text-gray-300"> · {range.days} days</span> : null}
                </p>
            </div>

            {range.preset === "custom" && (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-3 flex items-center gap-3 flex-wrap">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400 inline-flex items-center gap-1.5"><CalendarRange size={13} /> From</span>
                    <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm" />
                    <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400">To</span>
                    <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm" />
                    <button type="button" onClick={applyCustom} className="px-3.5 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-bold uppercase tracking-wider hover:bg-black">Apply</button>
                </div>
            )}

            {/* ── 01 · Pipeline position ─────────────────────────────────── */}
            <DeckHeader n="01" eyebrow="Updates and reporting" title="Pipeline position" />
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <StatCard icon={<UserPlus size={16} />} value={pipeline.new_clients ?? 0} label="New clients" hint="Opened this period" />
                <StatCard icon={<ActivityIcon size={16} />} value={pipeline.in_progress ?? 0} label="In progress" hint="Active, pre-INZ" />
                <StatCard icon={<FileText size={16} />} value={pipeline.for_quotation ?? 0} label="For quotation" hint="Awaiting pricing / invoice" />
                <StatCard icon={<BadgeCheck size={16} />} value={pipeline.endorsed_lia ?? 0} label="Endorsed to LIA" hint="Recorded this period" />
                <StatCard icon={<Handshake size={16} />} value={pipeline.agreements_sent ?? 0} label="Agreements sent" hint="Awaiting signature" />
                <StatCard icon={<Clock size={16} />} value={pipeline.with_inz ?? 0} label="With INZ" hint="Awaiting decision" tone="dark" />
                <StatCard icon={<Inbox size={16} />} value={pool.total} label="Pre-lodgement pool" hint={`${pool.paid} paid · ${pool.awaiting} awaiting`} />
            </div>
            <div className="rounded-2xl bg-teal-50/60 border border-teal-100 px-5 py-3.5">
                <p className="text-[12.5px] text-teal-900 leading-relaxed">
                    <span className="font-bold">Pre-lodgement pool:</span> {pool.total} file{pool.total === 1 ? "" : "s"} —{" "}
                    <span className="font-semibold">{pool.paid} invoiced and paid</span> and {pool.awaiting} awaiting engagement and invoice. Movement out of this pool is the constraint on next period's lodgement count.
                </p>
            </div>

            {/* ── 02 · Intake and engagements — named detail ─────────────── */}
            <DeckHeader n="02" eyebrow="Updates and reporting" title="Intake and engagements — named detail" />
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <NamedCard icon={<UserPlus size={17} />} title="New client records" count={(namedIntake.new_clients || []).length} meta="opened this period" items={namedIntake.new_clients} showDate />
                <NamedCard icon={<Send size={17} />} title="Agreements issued" count={(namedIntake.agreements_issued || []).length} meta="in this period" items={namedIntake.agreements_issued} showDate />
                <NamedCard icon={<BadgeCheck size={17} />} title="Endorsed to LIA" count={(namedIntake.endorsed_lia || []).length} meta="in this period" items={namedIntake.endorsed_lia} showDate />
                <NamedCard icon={<Handshake size={17} />} title="Engagement signed" count={(namedIntake.engagement_signed || []).length} meta="in this period" items={namedIntake.engagement_signed} showDate />
            </div>

            {/* ── 03 · Submissions and information requests ──────────────── */}
            <DeckHeader n="03" eyebrow="Updates and reporting" title="Submissions and information requests" />
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <NamedCard icon={<Plane size={17} />} title="Lodged with INZ" count={(submissions.lodged || []).length} meta="in this period" items={submissions.lodged} showDate />
                <div className="space-y-4">
                    <NamedCard icon={<HelpCircle size={17} />} title="Requests for Information" count={(submissions.rfis || []).length} meta="open" items={submissions.rfis} showAssignee empty="No open information requests." tone="amber" />
                    <NamedCard icon={<Layers size={17} />} title="Also under assessment" count={(submissions.also_assessing || []).length} meta="interim / in principle" items={submissions.also_assessing} showAssignee />
                </div>
            </div>

            {/* ── 04 · Decision outcomes ─────────────────────────────────── */}
            <DeckHeader n="04" eyebrow="Updates and reporting" title="Decision outcomes" />
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <OutcomeCard icon={<Award size={18} />} value={(outcomes.approved || []).length} label="Visas approved" items={outcomes.approved} hint={outcomes.approved?.length ? outcomes.approved.map((o) => o.visa).filter(Boolean).slice(0, 2).join(", ") : "None in the period"} tone="emerald" />
                <OutcomeCard icon={<Clock size={18} />} value={(outcomes.interim || []).length} label="Interim visa granted" items={outcomes.interim} hint={outcomes.interim?.[0]?.visa || "—"} tone="teal" />
                <OutcomeCard icon={<HelpCircle size={18} />} value={(outcomes.rfis || []).length} label="Request for Information (RFI)" items={outcomes.rfis} hint="Requested this period" tone="teal" />
                <OutcomeCard icon={<Ban size={18} />} value={(outcomes.declined || []).length} label="Visa declined" items={outcomes.declined} align="right" hint={outcomes.declined?.[0]?.visa || "—"} tone="rose" />
            </div>
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <div className="flex items-center gap-2.5 mb-4">
                    <span className="w-8 h-8 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center"><Target size={15} /></span>
                    <div>
                        <h3 className="text-sm font-bold uppercase tracking-[0.14em] text-gray-800">What is sitting with INZ</h3>
                        <p className="text-[11px] text-gray-400 mt-0.5">{pipeline.with_inz ?? 0} files awaiting a decision, by state</p>
                    </div>
                </div>
                {(outcomes.with_inz_breakdown || []).length === 0 ? (
                    <p className="text-[12px] text-gray-400">Nothing with INZ right now.</p>
                ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1">
                        {outcomes.with_inz_breakdown.map((b, i) => (
                            <HoverNames key={b.stage} items={b.cases} align={i % 2 === 1 ? "right" : "left"}>
                                <div className="flex items-center gap-3 py-1.5 rounded-lg hover:bg-gray-50 px-1 -mx-1">
                                    <span className="text-lg font-bold text-teal-700 tabular-nums w-8 text-right">{b.count}</span>
                                    <span className="text-[13px] text-gray-700">{b.stage}</span>
                                </div>
                            </HoverNames>
                        ))}
                    </div>
                )}
            </section>

            {/* ── 05 · Conclusion ────────────────────────────────────────── */}
            <DeckHeader n="05" eyebrow="Updates and reporting" title="Conclusion" />
            <ConclusionEditor key={conclusion.note_key} conclusion={conclusion} />

            {/* ══ Retained analytics ═════════════════════════════════════ */}
            <div className="pt-2">
                <p className="text-[10px] font-bold uppercase tracking-[0.24em] text-gray-400 px-1 mb-3">Analytics</p>
            </div>

            {/* Activity in period */}
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <h2 className="text-sm font-bold uppercase tracking-[0.14em] text-gray-800 mb-4">Activity — {range.label?.toLowerCase()}</h2>
                <ul className="divide-y divide-gray-50">
                    <ActivityRow icon={<UserPlus size={15} />} label="New clients onboarded" value={activity.new_clients ?? 0} />
                    <ActivityRow icon={<CheckCircle2 size={15} />} label="Files endorsed" value={activity.files_endorsed ?? 0} />
                    <ActivityRow icon={<Handshake size={15} />} label="Agreements signed" value={activity.agreements_signed ?? 0} />
                    <ActivityRow icon={<Plane size={15} />} label="Applications lodged" value={activity.apps_lodged ?? 0} />
                    <ActivityRow icon={<ThumbsUp size={15} />} label="Visas approved" value={activity.visas_approved ?? 0} accent />
                    <ActivityRow icon={<XCircle size={15} />} label="Visas declined" value={activity.visas_declined ?? 0} />
                </ul>
            </section>

            {/* Trend — new cases vs approvals over 6 months */}
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
                    <div className="flex items-center gap-2.5">
                        <span className="w-8 h-8 rounded-lg bg-gray-100 text-gray-700 flex items-center justify-center"><TrendingUp size={15} /></span>
                        <h2 className="text-sm font-bold uppercase tracking-[0.14em] text-gray-800">New cases vs approvals — 6 months</h2>
                    </div>
                    <div className="flex items-center gap-4 text-[11px] text-gray-500">
                        <span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-gray-700" /> New cases</span>
                        <span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-emerald-500" /> Visas approved</span>
                    </div>
                </div>
                <TrendChart data={trend} />
            </section>

            {/* Cases by stage */}
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <div className="flex items-center gap-2.5 mb-5">
                    <span className="w-8 h-8 rounded-lg bg-gray-100 text-gray-700 flex items-center justify-center"><Layers size={15} /></span>
                    <div>
                        <h2 className="text-sm font-bold uppercase tracking-[0.14em] text-gray-800">Cases by stage</h2>
                        <p className="text-[11px] text-gray-400 mt-0.5">
                            <span className="font-semibold text-gray-600 tabular-nums">{totalCases}</span> immigration cases across the pipeline
                        </p>
                    </div>
                </div>
                <ul className="space-y-2.5">
                    {stageDistribution.map((s) => {
                        const pct = totalCases > 0 ? Math.round((s.count / totalCases) * 100) : 0;
                        return (
                            <li key={s.stage} className="flex items-center gap-3">
                                <span className="w-40 sm:w-48 text-[12px] font-medium text-gray-700 truncate flex-shrink-0">{s.stage}</span>
                                <div className="flex-1 h-5 rounded-md bg-gray-50 overflow-hidden">
                                    <div className={`h-full rounded-md ${stageColor(s.stage)} transition-all`} style={{ width: `${Math.max(s.count > 0 ? 4 : 0, Math.round((s.count / maxStage) * 100))}%` }} />
                                </div>
                                <span className="w-10 text-right text-sm font-bold text-gray-900 tabular-nums">{s.count}</span>
                                <span className="w-9 text-right text-[11px] text-gray-400 tabular-nums">{pct}%</span>
                            </li>
                        );
                    })}
                </ul>
            </section>

            {/* Year-to-date outcomes */}
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <div className="flex items-center justify-between gap-3 mb-5 flex-wrap">
                    <h2 className="text-sm font-bold uppercase tracking-[0.14em] text-gray-800">Year-to-date outcomes</h2>
                    <span className="text-[11px] text-gray-400">
                        Approval rate <span className="font-bold text-emerald-600 tabular-nums text-sm">{ytd.approval_rate ?? 0}%</span>
                        <span className="text-gray-300"> · {ytd.approved ?? 0} of {ytd.decided ?? 0} decided</span>
                    </span>
                </div>
                <div className="grid grid-cols-1 lg:grid-cols-[1fr_240px] gap-6 items-center">
                    <div>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <Outcome label="Endorsed" value={ytd.endorsed ?? 0} icon={<CheckCircle2 size={16} />} />
                            <Outcome label="Visas lodged" value={ytd.lodged ?? 0} icon={<Plane size={16} />} />
                            <Outcome label="Approved" value={ytd.approved ?? 0} icon={<ThumbsUp size={16} />} tone="emerald" />
                            <Outcome label="Declined" value={ytd.declined ?? 0} icon={<XCircle size={16} />} tone="rose" />
                        </div>
                        <div className="mt-5">
                            <div className="flex items-center justify-between text-[11px] text-gray-400 mb-1.5">
                                <span>{ytd.approved ?? 0} approved</span>
                                <span>{ytd.declined ?? 0} declined</span>
                            </div>
                            <div className="h-2.5 rounded-full bg-gray-100 overflow-hidden flex">
                                <div className="h-full bg-emerald-500" style={{ width: `${ytd.approval_rate ?? 0}%` }} />
                                <div className="h-full bg-rose-400" style={{ width: `${100 - (ytd.approval_rate ?? 0)}%` }} />
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-col items-center justify-center border-l border-gray-100 lg:pl-6">
                        <Donut pct={ytd.approval_rate ?? 0} />
                        <p className="text-[11px] text-gray-400 mt-2 text-center">{ytd.approved ?? 0} of {ytd.decided ?? 0} decided approved</p>
                    </div>
                </div>
            </section>

            <footer className="text-[11px] text-gray-400 flex items-center gap-4 flex-wrap px-1">
                <span>Generated {new Date(generated_at || Date.now()).toLocaleString("en-NZ", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })}</span>
                {generated_by && <span>by <span className="font-semibold text-gray-600">{generated_by}</span></span>}
            </footer>
        </div>
    );
}

// ── Sub-components ──────────────────────────────────────────────────────

function DeckHeader({ n, eyebrow, title }) {
    return (
        <div className="flex items-center gap-3 pt-2">
            <span className="w-11 h-11 rounded-full text-white flex items-center justify-center text-sm font-bold flex-shrink-0" style={{ backgroundColor: TEAL }}>{n}</span>
            <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.24em]" style={{ color: TEAL }}>Section · {eyebrow}</p>
                <h2 className="text-xl font-semibold text-gray-900 tracking-tight">{title}</h2>
            </div>
        </div>
    );
}

function StatCard({ icon, value, label, hint, tone = "teal" }) {
    const iconCls = tone === "dark" ? "text-white" : "text-white";
    const iconBg = tone === "dark" ? "#374151" : TEAL;
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div className="flex items-center gap-3">
                <span className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${iconCls}`} style={{ backgroundColor: iconBg }}>{icon}</span>
                <span className="text-3xl font-bold tabular-nums text-gray-900">{value}</span>
            </div>
            <p className="text-[13px] font-bold text-gray-800 mt-3">{label}</p>
            {hint && <p className="text-[11px] text-gray-400 mt-0.5">{hint}</p>}
        </div>
    );
}

function NamedCard({ icon, title, count, meta, items = [], showDate = false, showAssignee = false, tone = "teal", empty = "None in this period" }) {
    const bg = tone === "amber" ? "bg-amber-50 text-amber-600" : "bg-teal-50 text-teal-700";
    const metaCol = tone === "amber" ? "text-amber-600" : "text-teal-700";
    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div className="flex items-start gap-3 mb-3">
                <span className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${bg}`}>{icon}</span>
                <div className="min-w-0">
                    <h3 className="text-sm font-bold text-gray-900">{title}</h3>
                    <p className={`text-[11px] font-bold uppercase tracking-wider mt-0.5 ${metaCol}`}>{count} · {meta}</p>
                </div>
            </div>
            {(!items || items.length === 0) ? (
                <p className="text-[12px] text-gray-400">{empty}</p>
            ) : (
                <ul className="space-y-1.5">
                    {items.map((it) => (
                        <li key={it.id} className="text-[12.5px] text-gray-700 flex items-start gap-2">
                            <span className="mt-1.5 w-1 h-1 rounded-full bg-gray-300 flex-shrink-0" />
                            <span className="min-w-0">
                                <span className="font-medium text-gray-800">{it.name}</span>
                                {it.visa && <span className="text-gray-500"> — {it.visa}</span>}
                                {showAssignee && it.assignee && <span className="text-gray-400"> · Waiting on {it.assignee}</span>}
                                {showDate && it.date && <span className="text-gray-400"> · {fmtDate(it.date)}</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

// A hover-reveal panel listing the named cases behind a figure — so staff can
// see WHO the cases are without leaving the report. `align` flips it to the
// right edge for cards near the right of the grid.
function NamesPopover({ items = [], align = "left" }) {
    return (
        <div className={`absolute z-30 top-full mt-1.5 ${align === "right" ? "right-0" : "left-0"} w-72 max-h-72 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl p-2`}>
            <p className="px-2 pt-1 pb-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                {items.length} case{items.length === 1 ? "" : "s"}
            </p>
            <ul className="space-y-0.5">
                {items.map((it) => (
                    <li key={it.id} className="text-[12px] px-2 py-1 rounded hover:bg-gray-50 flex items-start gap-1.5">
                        <span className="mt-1 w-1 h-1 rounded-full bg-gray-300 flex-shrink-0" />
                        <span className="min-w-0">
                            <span className="font-medium text-gray-800">{it.name}</span>
                            {it.visa && <span className="text-gray-500"> — {it.visa}</span>}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// Wraps any trigger so hovering it reveals the named cases behind it.
function HoverNames({ items = [], align = "left", children, className = "" }) {
    const [open, setOpen] = useState(false);
    const has = items && items.length > 0;
    return (
        <div
            className={`relative ${has ? "cursor-help" : ""} ${className}`}
            onMouseEnter={() => has && setOpen(true)}
            onMouseLeave={() => setOpen(false)}
        >
            {children}
            {open && has && <NamesPopover items={items} align={align} />}
        </div>
    );
}

function OutcomeCard({ icon, value, label, hint, items = [], align = "left", tone = "teal" }) {
    const map = {
        emerald: { bg: "bg-emerald-50", text: "text-emerald-600", card: "bg-white border-gray-100" },
        teal:    { bg: "bg-teal-50", text: "text-teal-700", card: "bg-white border-gray-100" },
        rose:    { bg: "bg-rose-50", text: "text-rose-600", card: "bg-rose-50/40 border-rose-100" },
    }[tone];
    const zero = value === 0;
    const has = items && items.length > 0;
    return (
        <HoverNames items={items} align={align}>
            <div className={`rounded-2xl border shadow-sm p-5 transition-shadow ${map.card} ${has ? "hover:shadow-md" : ""}`}>
                <span className={`w-10 h-10 rounded-xl flex items-center justify-center ${zero ? "bg-gray-100 text-gray-400" : `${map.bg} ${map.text}`}`}>{icon}</span>
                <p className={`text-3xl font-bold tabular-nums mt-3 ${zero ? "text-gray-400" : map.text}`}>{zero ? "None" : value}</p>
                <p className="text-[13px] font-bold text-gray-800 mt-0.5">{label}</p>
                {hint && <p className="text-[11px] text-gray-400 mt-0.5 truncate" title={hint}>{hint}</p>}
                {has && <ul className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                    {items.map((item) => <li key={item.id} className="text-xs text-gray-700">
                        <span className="font-semibold">{item.name}</span>
                        {item.visa && <span className="block text-gray-500">{item.visa}</span>}
                        {item.date && <span className="block text-gray-400">{fmtDate(item.date)}</span>}
                    </li>)}
                </ul>}
            </div>
        </HoverNames>
    );
}

function ConclusionEditor({ conclusion = {} }) {
    const [note, setNote] = useState(conclusion.note ?? conclusion.auto ?? "");
    const [saving, setSaving] = useState(false);
    const stats = conclusion.stats || {};
    const dirty = note !== (conclusion.note ?? conclusion.auto ?? "");

    const save = () => {
        setSaving(true);
        router.post("/portal/immigration/reports/note", { note_key: conclusion.note_key, note }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success("Conclusion saved"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not save"),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <section className="rounded-2xl border border-gray-100 shadow-sm overflow-hidden" style={{ backgroundColor: "#0f5c55" }}>
            <div className="p-5 sm:p-6 text-white">
                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-teal-200 mb-2">Auto summary · editable</p>
                <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={4}
                    className="w-full bg-white/10 border border-white/20 rounded-xl px-4 py-3 text-[14px] leading-relaxed text-white placeholder-teal-200/60 focus:outline-none focus:border-white/50 resize-y"
                    placeholder="Write the period's commentary…"
                />
                <div className="flex items-center gap-2 mt-3">
                    <button type="button" onClick={save} disabled={saving || !dirty}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white text-teal-800 text-[12px] font-bold hover:bg-teal-50 disabled:opacity-50">
                        <Save size={13} /> {saving ? "Saving…" : "Save"}
                    </button>
                    <button type="button" onClick={() => setNote(conclusion.auto ?? "")}
                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-white/25 text-teal-100 text-[12px] font-semibold hover:bg-white/10">
                        <RotateCcw size={13} /> Reset to auto
                    </button>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-5 border-t border-white/15">
                    <ConcStat value={stats.lodged ?? 0} label="Visas lodged" />
                    <ConcStat value={stats.approved ?? 0} label="Approved" />
                    <ConcStat value={stats.declined ?? 0} label="Declined" />
                    <ConcStat value={stats.new_records ?? 0} label="New records" />
                </div>
            </div>
        </section>
    );
}

function ConcStat({ value, label }) {
    return (
        <div>
            <p className="text-3xl font-bold tabular-nums text-teal-100">{value}</p>
            <p className="text-[11px] text-teal-200/80 mt-0.5">{label}</p>
        </div>
    );
}

function ActivityRow({ icon, label, value, accent = false }) {
    return (
        <li className="flex items-center gap-3 py-2.5">
            <span className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${accent ? "bg-emerald-50 text-emerald-600" : "bg-gray-100 text-gray-500"}`}>{icon}</span>
            <span className="flex-1 text-[13px] text-gray-700">{label}</span>
            <span className={`text-lg font-bold tabular-nums ${accent ? "text-emerald-600" : "text-gray-900"}`}>{value}</span>
        </li>
    );
}

function Outcome({ label, value, icon, tone = "gray" }) {
    const iconTone = tone === "emerald" ? "bg-emerald-50 text-emerald-600" : tone === "rose" ? "bg-rose-50 text-rose-500" : "bg-gray-100 text-gray-500";
    const valueTone = tone === "emerald" ? "text-emerald-600" : tone === "rose" ? "text-rose-500" : "text-gray-900";
    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50/40 p-4">
            <span className={`w-8 h-8 rounded-lg flex items-center justify-center ${iconTone}`}>{icon}</span>
            <p className={`text-2xl font-bold tabular-nums mt-3 ${valueTone}`}>{value}</p>
            <p className="text-[11px] text-gray-500 mt-0.5">{label}</p>
        </div>
    );
}

function TrendChart({ data = [] }) {
    if (!data.length) return <p className="text-sm text-gray-400 py-8 text-center">No trend data yet.</p>;

    const W = 720, H = 200, padX = 34, padTop = 16, padBottom = 28;
    const chartW = W - padX * 2;
    const chartH = H - padTop - padBottom;
    const baseY = padTop + chartH;
    const peak = Math.max(1, ...data.flatMap((d) => [d.new_cases || 0, d.approved || 0]));

    const xFor = (i) => padX + (i / Math.max(1, data.length - 1)) * chartW;
    const yFor = (n) => padTop + (1 - n / peak) * chartH;
    const line = (key) => data.map((d, i) => `${xFor(i)},${yFor(d[key] || 0)}`).join(" ");
    const area = (key) =>
        `M${xFor(0)},${baseY} L` + data.map((d, i) => `${xFor(i)},${yFor(d[key] || 0)}`).join(" L") + ` L${xFor(data.length - 1)},${baseY} Z`;

    return (
        <svg viewBox={`0 0 ${W} ${H}`} className="w-full" style={{ maxHeight: 220 }} role="img" aria-label="New cases vs approvals trend">
            {[0, 0.5, 1].map((t, i) => (
                <line key={i} x1={padX} x2={W - padX} y1={padTop + (1 - t) * chartH} y2={padTop + (1 - t) * chartH} stroke="#f1f5f9" strokeWidth="1" />
            ))}
            <defs>
                <linearGradient id="rep-area" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#10b981" stopOpacity="0.22" />
                    <stop offset="100%" stopColor="#10b981" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={area("approved")} fill="url(#rep-area)" />
            <polyline points={line("new_cases")} fill="none" stroke="#374151" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="4 4" />
            <polyline points={line("approved")} fill="none" stroke="#10b981" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
            {data.map((d, i) => (
                <g key={i}>
                    <circle cx={xFor(i)} cy={yFor(d.approved || 0)} r="3.5" fill="white" stroke="#10b981" strokeWidth="2" />
                    <text x={xFor(i)} y={baseY + 18} textAnchor="middle" fontSize="10" fontWeight="600" fill="#94a3b8">{d.label}</text>
                </g>
            ))}
        </svg>
    );
}

function Donut({ pct }) {
    const r = 54;
    const c = 2 * Math.PI * r;
    const dash = (c * Math.min(100, Math.max(0, pct))) / 100;
    return (
        <svg viewBox="0 0 140 140" className="w-36 h-36">
            <circle cx="70" cy="70" r={r} fill="none" stroke="#fecdd3" strokeWidth="14" />
            <circle
                cx="70" cy="70" r={r} fill="none" stroke="#10b981" strokeWidth="14" strokeLinecap="round"
                strokeDasharray={`${dash} ${c - dash}`} strokeDashoffset={c / 4} transform="rotate(-90 70 70)"
            />
            <text x="70" y="68" textAnchor="middle" fontSize="26" fontWeight="700" fill="#111827">{pct}%</text>
            <text x="70" y="86" textAnchor="middle" fontSize="8" letterSpacing="1.5" fontWeight="700" fill="#9ca3af">APPROVAL</text>
        </svg>
    );
}
