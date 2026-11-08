import WeeklyReport, { WeekButtons } from "@/components/WeeklyReport";
import { useMemo, useState } from "react";
import { Head, router, Link } from "@inertiajs/react";
import {
    ChevronLeft, ChevronRight, Calendar, Download, Share2, TrendingUp,
    TrendingDown, Minus, AlertTriangle, CheckCircle2, Snowflake, Users,
    PieChart, Activity, Sparkles, Star, MapPin, Clock, Lock,
} from "lucide-react";

const fmtRange = (a, b) => {
    const s = new Date(a + "T00:00:00"), e = new Date(b + "T00:00:00");
    const sameMonth = s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear();
    return sameMonth
        ? `${s.toLocaleDateString("en-NZ", { day: "numeric" })} – ${e.toLocaleDateString("en-NZ", { day: "numeric", month: "long", year: "numeric" })}`
        : `${s.toLocaleDateString("en-NZ", { day: "numeric", month: "short" })} – ${e.toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" })}`;
};

const fmtIso = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
};

const BUCKET_COLOR = {
    Early:     "bg-rose-400",
    Nurture:   "bg-amber-400",
    Qualified: "bg-blue-500",
    Closed:    "bg-emerald-500",
};

export default function SalesReports({
    week_start, week_end, preset, weeklyReport,
    glance = {}, pipeline = {}, cold_buckets = {}, per_agent = null,
    by_source = [], bookings = {}, lost_analysis = {}, ai_activity = null,
    trend = [], notable = {}, generated_at, generated_by,
}) {
    const stepWeek = (delta) => {
        const d = new Date(week_start + "T00:00:00");
        d.setDate(d.getDate() + (delta * 7));
        router.get('/portal/sales/reports', { week_start: fmtIso(d) }, { preserveScroll: true });
    };
    const selectWeek = (preset) => router.get("/portal/sales/reports", { preset }, { preserveScroll: true });

    return (
        <div className="space-y-6 max-w-[1500px] mx-auto pb-12">
            <Head title="Sales Weekly Report" />

            {/* Top bar */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center justify-between gap-4 flex-wrap">
                <div className="flex items-center gap-3">
                    <button type="button" onClick={() => stepWeek(-1)} className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                        <ChevronLeft size={16} />
                    </button>
                    <div className="min-w-[280px]">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400">Week of</p>
                        <h1 className="text-xl font-bold text-gray-900 tracking-tight tabular-nums">{fmtRange(week_start, week_end)}</h1>
                    </div>
                    <button type="button" onClick={() => stepWeek(1)} className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                        <ChevronRight size={16} />
                    </button>

                </div>

                <WeekButtons preset={preset} onChange={selectWeek} />

                <div className="flex items-center gap-2">
                    <DisabledAction icon={<Download size={13} />} label="Export PDF" />
                    <DisabledAction icon={<Share2 size={13} />}   label="Share with…" />
                </div>
            </div>

            <WeeklyReport report={weeklyReport} saveUrl="/portal/sales/reports/note" />

            {/* Section 1 — Week at a glance */}
            <Section title="Week at a glance">
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                    <GlanceTile label="New leads"          value={glance.new_leads?.value ?? 0} prev={glance.new_leads?.prev} />
                    <GlanceTile label="Converted"          value={glance.converted?.value ?? 0} prev={glance.converted?.prev} tone="success" />
                    <GlanceTile label="Conversion rate"    value={`${glance.conversion_rate?.value ?? 0}%`} prev={glance.conversion_rate?.prev != null ? `${glance.conversion_rate.prev}%` : null} numeric={glance.conversion_rate} />
                    <GlanceTile label="Lost"               value={glance.lost?.value ?? 0} hint={glance.lost?.top_reason ? `Top: ${glance.lost.top_reason}` : null} tone="danger" />
                    <GlanceTile label="Avg response time"  value={glance.avg_response_time_hrs ?? "—"} hint={glance.avg_response_time_hrs == null ? "Needs first-touch tracking" : "hrs"} muted={glance.avg_response_time_hrs == null} />
                    <GlanceTile label="Cold actioned"      value={glance.cold_actioned ?? "—"} hint={glance.cold_actioned == null ? "Needs cold-action audit" : "leads"} muted={glance.cold_actioned == null} />
                </div>
            </Section>

            {/* Section 2 — Pipeline health */}
            <Section title="Pipeline health" subtitle="Lead volume by stage, grouped into 4 buckets. Aging shows leads stuck in their current stage.">
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div className="lg:col-span-2 bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-4">Leads by stage</h3>
                        <StageBars stages={pipeline.by_stage || []} />
                    </div>
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-4">Aging</h3>
                        <ul className="space-y-3">
                            <AgingRow label="Stuck 7+ days"  value={pipeline.aging?.stuck_7 ?? 0}  tone="default" />
                            <AgingRow label="Stuck 14+ days" value={pipeline.aging?.stuck_14 ?? 0} tone="warning" />
                            <AgingRow label="Stuck 30+ days" value={pipeline.aging?.stuck_30 ?? 0} tone="danger"  />
                        </ul>
                        <p className="text-[10px] italic text-gray-400 mt-4 leading-relaxed">
                            Stage drop-off chart needs per-transition audit aggregation — coming next.
                        </p>
                    </div>
                </div>
            </Section>

            {/* Section 3 — Going cold */}
            <Section title="Going cold" subtitle="Open leads with no update for 7+ days. Hover a bar to drill in (workflow actions coming).">
                <ColdChart buckets={cold_buckets} />
            </Section>

            {/* Section 4 — Per-agent activity */}
            <Section title="Per-agent activity" subtitle="Visibility scoped to role. Per-agent table requires a Lead→Agent assignment relation we don't have yet.">
                <NeedsInfraPanel
                    icon={<Users size={18} />}
                    title="Needs lead-assignment infrastructure"
                    lines={[
                        "Add an assignee_id column on leads (an FK to users).",
                        "Sales staff see their own row + team aggregates; managers/admin see all rows.",
                        "Columns: active leads, new assigned, conversions, lost, avg response, tasks done, tasks overdue, last activity.",
                    ]}
                />
            </Section>

            {/* Section 5 — Lead sources */}
            <Section title="Lead sources" subtitle="Where this week's leads came from.">
                <div className="bg-white rounded-2xl border border-gray-100 p-5">
                    {by_source.length === 0 ? (
                        <p className="text-sm text-gray-400 italic text-center py-6">No leads in the selected week.</p>
                    ) : (
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-center">
                            <DonutSvg slices={by_source} />
                            <ul className="space-y-1.5">
                                {by_source.map((s, i) => (
                                    <li key={i} className="flex items-center justify-between gap-3 px-3 py-1.5 rounded-lg hover:bg-gray-50">
                                        <span className="inline-flex items-center gap-2 text-sm text-gray-800">
                                            <span className={`w-2.5 h-2.5 rounded-full ${donutSliceColor(i)}`}></span>
                                            {s.label}
                                        </span>
                                        <span className="text-sm font-bold text-gray-900 tabular-nums">{s.count}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </Section>

            {/* Section 6 — Bookings & meetings */}
            <Section title="Bookings & meetings" subtitle="Where this week's consultations landed, and what's already on the calendar for next week.">
                <BookingsChart bookings={bookings} />
            </Section>

            {/* Section 7 — Lost lead analysis */}
            <Section title="Lost lead analysis">
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-4">Total lost</h3>
                        <p className="text-4xl font-bold text-rose-700 tabular-nums">{lost_analysis.total ?? 0}</p>
                        <p className="text-xs text-gray-500 mt-1">leads this week</p>
                    </div>
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-4">By reason</h3>
                        {(lost_analysis.breakdown || []).length === 0
                            ? <p className="text-sm text-gray-400 italic">No lost leads this week.</p>
                            : <SimpleBarList rows={lost_analysis.breakdown} tone="rose" />}
                    </div>
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-4">By source</h3>
                        {(lost_analysis.by_source || []).length === 0
                            ? <p className="text-sm text-gray-400 italic">No lost leads this week.</p>
                            : <SimpleBarList rows={lost_analysis.by_source} tone="amber" />}
                    </div>
                </div>
            </Section>

            {/* Section 8 — AI activity */}
            <Section title="AI activity">
                <NeedsInfraPanel
                    icon={<Sparkles size={18} />}
                    title="Needs AI-suggestion audit tracking"
                    lines={[
                        "Capture: suggested · accepted · modified · rejected · summarisation requests.",
                        "Derive AI acceptance rate from those counts.",
                        "Requires a small ai_activity_logs table the suggestion flow writes into.",
                    ]}
                />
            </Section>

            {/* Section 9 — 8-week trend */}
            <Section title="Trends (last 8 weeks)">
                <div className="bg-white rounded-2xl border border-gray-100 p-5">
                    <TrendLines data={trend} />
                </div>
            </Section>

            {/* Section 10 — Notable items */}
            <Section title="Notable items">
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <NotableColumn title="Wins"      icon={<Star size={14} />}            rows={notable.wins || []}     tone="success" />
                    <NotableColumn title="Concerns"  icon={<AlertTriangle size={14} />}   rows={notable.concerns || []} tone="danger" />
                </div>
            </Section>

            {/* Section 11 — Footer */}
            <footer className="bg-white rounded-2xl border border-gray-100 px-5 py-4 flex items-center justify-between gap-3 flex-wrap text-[11px] text-gray-500">
                <div className="flex items-center gap-4">
                    <span>Generated {new Date(generated_at || Date.now()).toLocaleString("en-NZ", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })}</span>
                    {generated_by && <span>by <span className="font-semibold text-gray-700">{generated_by}</span></span>}
                </div>
                <span className="italic text-gray-400">Not shared with any other team</span>
            </footer>
        </div>
    );
}

// ── Building blocks ────────────────────────────────────────────────────────

function Section({ eyebrow, title, subtitle, children }) {
    return (
        <section className="space-y-3">
            <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.32em] text-blue-600">{eyebrow}</p>
                <h2 className="text-lg font-bold text-gray-900 tracking-tight">{title}</h2>
                {subtitle && <p className="text-[12px] text-gray-500 mt-0.5 max-w-3xl">{subtitle}</p>}
            </div>
            {children}
        </section>
    );
}

function GlanceTile({ label, value, prev = null, hint = null, tone = "default", muted = false, numeric = null }) {
    const showDelta = prev !== null && prev !== undefined;
    const numValue  = numeric?.value ?? (typeof value === "number" ? value : null);
    const numPrev   = numeric?.prev  ?? (typeof prev === "number" ? prev : null);
    const delta     = (numValue != null && numPrev != null) ? numValue - numPrev : null;
    const deltaPct  = (delta != null && numPrev) ? Math.round((delta / numPrev) * 100) : null;

    const TONES = {
        default: { num: "text-gray-900",   ring: "border-gray-100" },
        success: { num: "text-emerald-700",ring: "border-emerald-100" },
        warning: { num: "text-amber-700",  ring: "border-amber-100" },
        danger:  { num: "text-rose-700",   ring: "border-rose-100" },
    };
    const t = TONES[tone] || TONES.default;

    return (
        <div className={`bg-white rounded-2xl border ${t.ring} p-4 ${muted ? "opacity-70" : ""}`}>
            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400">{label}</p>
            <p className={`text-3xl font-bold tabular-nums mt-1 ${t.num}`}>{value}</p>
            {showDelta && delta != null && (
                <p className={`text-[11px] mt-1 inline-flex items-center gap-1 font-semibold ${delta > 0 ? "text-emerald-600" : delta < 0 ? "text-rose-600" : "text-gray-400"}`}>
                    {delta > 0 ? <TrendingUp size={11} /> : delta < 0 ? <TrendingDown size={11} /> : <Minus size={11} />}
                    {delta > 0 ? "+" : ""}{delta}{deltaPct != null ? ` (${deltaPct > 0 ? "+" : ""}${deltaPct}%)` : ""} vs prior week
                </p>
            )}
            {hint && <p className="text-[10px] text-gray-400 mt-1 italic">{hint}</p>}
        </div>
    );
}

function StageBars({ stages }) {
    const max = Math.max(1, ...stages.map((s) => s.count));
    return (
        <ul className="space-y-1.5">
            {stages.map((s) => (
                <li key={s.stage} className="flex items-center gap-3">
                    <span className="w-44 truncate text-[11px] font-semibold text-gray-700">{s.stage}</span>
                    <div className="flex-1 h-3 bg-gray-100 rounded-full overflow-hidden">
                        <div className={`h-full ${BUCKET_COLOR[s.bucket] || "bg-gray-400"} rounded-full transition-all`} style={{ width: `${(s.count / max) * 100}%` }} />
                    </div>
                    <span className="w-10 text-right text-xs font-bold text-gray-700 tabular-nums">{s.count}</span>
                </li>
            ))}
            <li className="flex items-center gap-3 pt-3 mt-3 border-t border-gray-100 text-[10px] uppercase tracking-widest font-bold text-gray-400">
                <span className="inline-flex items-center gap-1"><span className="w-2 h-2 bg-rose-400 rounded-full"></span> Early</span>
                <span className="inline-flex items-center gap-1"><span className="w-2 h-2 bg-amber-400 rounded-full"></span> Nurture</span>
                <span className="inline-flex items-center gap-1"><span className="w-2 h-2 bg-blue-500 rounded-full"></span> Qualified</span>
                <span className="inline-flex items-center gap-1"><span className="w-2 h-2 bg-emerald-500 rounded-full"></span> Closed</span>
            </li>
        </ul>
    );
}

function AgingRow({ label, value, tone }) {
    const TONES = { default: "text-gray-700", warning: "text-amber-700", danger: "text-rose-700" };
    return (
        <li className="flex items-center justify-between gap-3 py-2 border-b border-gray-100 last:border-0">
            <span className="text-sm text-gray-700">{label}</span>
            <span className={`text-2xl font-bold tabular-nums ${TONES[tone]}`}>{value}</span>
        </li>
    );
}

// Going-cold chart — horizontal bar comparing the 3 age buckets so staff
// see at a glance which bucket the bulk of cold leads live in.
function ColdChart({ buckets }) {
    const rows = [
        { key: '7_to_14',  label: '7 – 14 days',  value: buckets['7_to_14']  ?? 0, bar: 'bg-blue-400',  num: 'text-blue-700',  ring: 'border-blue-200',  glyphBg: 'bg-blue-100  text-blue-700'  },
        { key: '15_to_30', label: '15 – 30 days', value: buckets['15_to_30'] ?? 0, bar: 'bg-amber-400', num: 'text-amber-700', ring: 'border-amber-200', glyphBg: 'bg-amber-100 text-amber-700' },
        { key: '30_plus',  label: '30+ days',     value: buckets['30_plus']  ?? 0, bar: 'bg-rose-500',  num: 'text-rose-700',  ring: 'border-rose-200',  glyphBg: 'bg-rose-100  text-rose-700'  },
    ];
    const total = rows.reduce((t, r) => t + r.value, 0);
    const max = Math.max(1, ...rows.map((r) => r.value));

    return (
        <div className="bg-white rounded-2xl border border-gray-100 p-5 sm:p-6">
            <div className="flex items-baseline justify-between mb-5 flex-wrap gap-2">
                <div className="flex items-center gap-2 text-gray-500">
                    <Snowflake size={14} />
                    <p className="text-[11px] font-bold uppercase tracking-[0.22em]">Cold pipeline</p>
                </div>
                <p className="text-[11px] font-bold uppercase tracking-widest text-gray-400 tabular-nums">
                    {total} total · {rows[2].value} need urgent action
                </p>
            </div>

            <ul className="space-y-4">
                {rows.map((r) => {
                    const pctOfMax   = (r.value / max) * 100;
                    const pctOfTotal = total > 0 ? Math.round((r.value / total) * 100) : 0;
                    return (
                        <li key={r.key} className="group">
                            <div className="flex items-baseline justify-between mb-1.5">
                                <div className="flex items-center gap-2.5">
                                    <span className={`w-6 h-6 rounded-md flex items-center justify-center ${r.glyphBg}`}>
                                        <Snowflake size={11} />
                                    </span>
                                    <p className="text-sm font-semibold text-gray-700">{r.label}</p>
                                </div>
                                <div className="flex items-baseline gap-2">
                                    <span className={`text-2xl font-bold tabular-nums ${r.num}`}>{r.value}</span>
                                    <span className="text-[11px] text-gray-400 tabular-nums">{pctOfTotal}%</span>
                                </div>
                            </div>
                            <div className="h-3 bg-gray-100 rounded-full overflow-hidden">
                                <div className={`h-full ${r.bar} rounded-full transition-all duration-700 group-hover:opacity-90`} style={{ width: `${pctOfMax}%` }} />
                            </div>
                        </li>
                    );
                })}
            </ul>

            <p className="text-[10px] italic text-gray-400 mt-5">
                Workflow actions (Re-engage · Reassign · Snooze · Mark Lost) coming next iteration.
            </p>
        </div>
    );
}

// Bookings funnel — shows where THIS week's booked consults ended up
// (completed / no-shows / cancelled / still pending), plus a side card
// for what's already scheduled next week.
function BookingsChart({ bookings }) {
    const booked    = bookings.booked_this_week    ?? 0;
    const completed = bookings.completed_this_week ?? 0;
    const noShows   = bookings.no_shows_this_week  ?? 0;
    const cancelled = bookings.cancelled_this_week ?? 0;
    const pending   = Math.max(0, booked - completed - noShows - cancelled);
    const nextWeek  = bookings.scheduled_next_week ?? 0;

    const completionRate = booked > 0 ? Math.round((completed / booked) * 100) : 0;
    const noShowRate     = booked > 0 ? Math.round((noShows / booked) * 100) : 0;

    const rows = [
        { key: 'completed', label: 'Completed',  value: completed, bar: 'bg-emerald-500', num: 'text-emerald-700', glyphBg: 'bg-emerald-100 text-emerald-700' },
        { key: 'pending',   label: 'Still pending / upcoming', value: pending,   bar: 'bg-blue-400',    num: 'text-blue-700',    glyphBg: 'bg-blue-100 text-blue-700' },
        { key: 'no_shows',  label: 'No-shows',   value: noShows,   bar: 'bg-rose-500',    num: 'text-rose-700',    glyphBg: 'bg-rose-100 text-rose-700' },
        { key: 'cancelled', label: 'Cancelled',  value: cancelled, bar: 'bg-amber-400',   num: 'text-amber-700',   glyphBg: 'bg-amber-100 text-amber-700' },
    ];
    const total = Math.max(1, booked);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {/* Funnel — what happened to this week's bookings */}
            <div className="lg:col-span-2 bg-white rounded-2xl border border-gray-100 p-5 sm:p-6">
                <div className="flex items-baseline justify-between mb-1 flex-wrap gap-2">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500">This week's bookings</p>
                        <p className="text-4xl font-bold text-gray-900 tabular-nums mt-1">{booked}</p>
                    </div>
                    <div className="flex items-center gap-4 text-[11px] font-bold uppercase tracking-widest tabular-nums">
                        <span className="text-emerald-700">{completionRate}% completed</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-rose-700">{noShowRate}% no-show</span>
                    </div>
                </div>

                {/* Stacked progress bar — the whole week's booked split out */}
                {booked > 0 && (
                    <div className="mt-5 h-4 rounded-full overflow-hidden flex bg-gray-100">
                        {rows.filter(r => r.value > 0).map((r) => (
                            <div
                                key={r.key}
                                className={`${r.bar} transition-all`}
                                style={{ width: `${(r.value / total) * 100}%` }}
                                title={`${r.label}: ${r.value}`}
                            />
                        ))}
                    </div>
                )}

                {/* Detail rows */}
                <ul className="mt-5 space-y-3">
                    {rows.map((r) => {
                        const pct = booked > 0 ? Math.round((r.value / booked) * 100) : 0;
                        return (
                            <li key={r.key} className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2.5 min-w-0">
                                    <span className={`w-2.5 h-2.5 rounded-full ${r.bar}`}></span>
                                    <p className="text-sm text-gray-700">{r.label}</p>
                                </div>
                                <div className="flex items-baseline gap-2">
                                    <span className={`text-xl font-bold tabular-nums ${r.num}`}>{r.value}</span>
                                    <span className="text-[11px] text-gray-400 tabular-nums">{pct}%</span>
                                </div>
                            </li>
                        );
                    })}
                </ul>

                {booked === 0 && (
                    <p className="text-sm text-gray-400 italic text-center py-6">No bookings this week yet.</p>
                )}
            </div>

            {/* Looking ahead — scheduled next week */}
            <div className="bg-gradient-to-br from-blue-50 via-white to-white rounded-2xl border border-blue-200 p-5 sm:p-6 flex flex-col">
                <div className="flex items-center gap-2 text-blue-700 mb-1">
                    <Calendar size={14} />
                    <p className="text-[11px] font-bold uppercase tracking-[0.22em]">Looking ahead</p>
                </div>
                <p className="text-5xl font-bold tabular-nums text-blue-700 mt-2">{nextWeek}</p>
                <p className="text-sm text-blue-900/70 mt-1 font-medium">scheduled next week</p>

                <div className="flex-1" />

                <Link
                    href="/portal/sales/bookings"
                    className="mt-5 inline-flex items-center justify-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-xl text-[11px] font-bold uppercase tracking-wider hover:bg-blue-700 transition-colors"
                >
                    <Calendar size={12} /> Open calendar
                </Link>
            </div>
        </div>
    );
}

function ColdTile({ label, value, tone }) {
    const TONES = {
        default: { ring: "border-gray-100",   num: "text-gray-900" },
        warning: { ring: "border-amber-200",  num: "text-amber-700" },
        danger:  { ring: "border-rose-200",   num: "text-rose-700" },
    };
    const t = TONES[tone];
    return (
        <button type="button" className={`bg-white rounded-2xl border ${t.ring} p-5 text-left hover:shadow-md transition-shadow group`}>
            <div className="flex items-center gap-2 mb-2 text-gray-500">
                <Snowflake size={14} />
                <p className="text-[10px] font-bold uppercase tracking-[0.22em]">{label}</p>
            </div>
            <p className={`text-4xl font-bold tabular-nums ${t.num}`}>{value}</p>
            <p className="text-[11px] text-gray-400 mt-2 group-hover:text-blue-600 italic transition-colors">
                Workflow actions (Re-engage / Reassign / Snooze / Mark Lost) — coming next.
            </p>
        </button>
    );
}

function NeedsInfraPanel({ icon, title, lines }) {
    return (
        <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-6 flex items-start gap-4">
            <div className="w-10 h-10 rounded-xl bg-gray-100 text-gray-500 flex items-center justify-center flex-shrink-0">{icon}</div>
            <div className="flex-1">
                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400 mb-1">Needs infrastructure</p>
                <h3 className="text-base font-bold text-gray-800 mb-2">{title}</h3>
                <ul className="text-[12px] text-gray-600 leading-relaxed space-y-1">
                    {lines.map((l, i) => (
                        <li key={i} className="flex items-start gap-2">
                            <span className="text-gray-400 mt-1.5">•</span>
                            <span>{l}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function DonutSvg({ slices }) {
    const total = slices.reduce((t, s) => t + s.count, 0);
    if (total === 0) return null;
    const radius = 70, stroke = 22, circumference = 2 * Math.PI * radius;
    let offset = 0;
    return (
        <div className="relative w-[180px] h-[180px] mx-auto">
            <svg width="180" height="180" viewBox="0 0 180 180" className="-rotate-90">
                {slices.map((s, i) => {
                    const pct = s.count / total;
                    const dash = pct * circumference;
                    const el = (
                        <circle
                            key={i}
                            cx="90" cy="90" r={radius}
                            fill="none"
                            stroke={`var(--tw-slice-${i})`}
                            strokeWidth={stroke}
                            strokeDasharray={`${dash} ${circumference - dash}`}
                            strokeDashoffset={-offset}
                            className={donutSliceStroke(i)}
                        />
                    );
                    offset += dash;
                    return el;
                })}
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <p className="text-3xl font-bold text-gray-900 tabular-nums">{total}</p>
                <p className="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Leads</p>
            </div>
        </div>
    );
}

const DONUT_COLORS = [
    { bg: "bg-blue-500",    stroke: "stroke-blue-500" },
    { bg: "bg-emerald-500", stroke: "stroke-emerald-500" },
    { bg: "bg-violet-500",  stroke: "stroke-violet-500" },
    { bg: "bg-amber-500",   stroke: "stroke-amber-500" },
    { bg: "bg-pink-500",    stroke: "stroke-pink-500" },
    { bg: "bg-cyan-500",    stroke: "stroke-cyan-500" },
    { bg: "bg-orange-500",  stroke: "stroke-orange-500" },
    { bg: "bg-rose-500",    stroke: "stroke-rose-500" },
];
function donutSliceColor(i)  { return DONUT_COLORS[i % DONUT_COLORS.length].bg; }
function donutSliceStroke(i) { return DONUT_COLORS[i % DONUT_COLORS.length].stroke; }

function SimpleBarList({ rows, tone = "gray" }) {
    const max = Math.max(1, ...rows.map((r) => r.count));
    const TONES = { rose: "bg-rose-400", amber: "bg-amber-400", gray: "bg-gray-400" };
    const c = TONES[tone] || TONES.gray;
    return (
        <ul className="space-y-2">
            {rows.map((r, i) => (
                <li key={i}>
                    <div className="flex items-center justify-between text-[11px] text-gray-700 mb-1">
                        <span className="truncate">{r.label}</span>
                        <span className="font-bold tabular-nums">{r.count}</span>
                    </div>
                    <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div className={`h-full ${c} rounded-full`} style={{ width: `${(r.count / max) * 100}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function TrendLines({ data }) {
    const [series, setSeries] = useState({ new_leads: true, conversions: true, active: false });
    const toggle = (k) => setSeries((s) => ({ ...s, [k]: !s[k] }));

    const w = 720, h = 220, pad = 36;
    const max = Math.max(1, ...data.flatMap((d) => [
        series.new_leads ? d.new_leads : 0,
        series.conversions ? d.conversions : 0,
        series.active ? d.active : 0,
    ]));
    const x = (i) => pad + (i * (w - 2 * pad)) / Math.max(1, data.length - 1);
    const y = (v) => h - pad - ((v / max) * (h - 2 * pad));

    const line = (key) => data.map((d, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(d[key])}`).join(" ");
    const COLORS = { new_leads: "stroke-blue-500", conversions: "stroke-emerald-500", active: "stroke-violet-500" };
    const LABELS = { new_leads: "New leads", conversions: "Conversions", active: "Active pipeline" };

    return (
        <div>
            <div className="flex items-center gap-3 mb-3 flex-wrap">
                {Object.entries(LABELS).map(([k, label]) => (
                    <button key={k} type="button" onClick={() => toggle(k)} className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border ${series[k] ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-500 border-gray-200 hover:bg-gray-50"}`}>
                        <span className={`w-2 h-2 rounded-full ${COLORS[k].replace("stroke-", "bg-")}`}></span>
                        {label}
                    </button>
                ))}
            </div>
            <svg viewBox={`0 0 ${w} ${h}`} className="w-full h-auto">
                {/* gridlines */}
                {[0.25, 0.5, 0.75, 1].map((p) => (
                    <line key={p} x1={pad} x2={w - pad} y1={h - pad - p * (h - 2 * pad)} y2={h - pad - p * (h - 2 * pad)} className="stroke-gray-100" />
                ))}
                {/* lines */}
                {series.new_leads   && <path d={line("new_leads")}   fill="none" strokeWidth="2.5" className={COLORS.new_leads} />}
                {series.conversions && <path d={line("conversions")} fill="none" strokeWidth="2.5" className={COLORS.conversions} />}
                {series.active      && <path d={line("active")}      fill="none" strokeWidth="2.5" className={COLORS.active} />}
                {/* x labels */}
                {data.map((d, i) => (
                    <text key={i} x={x(i)} y={h - pad + 16} textAnchor="middle" className="text-[10px] fill-gray-400">
                        {d.label}
                    </text>
                ))}
            </svg>
        </div>
    );
}

function NotableColumn({ title, icon, rows, tone }) {
    const TONES = {
        success: "bg-emerald-50 border-emerald-200 text-emerald-700",
        danger:  "bg-rose-50 border-rose-200 text-rose-700",
    };
    return (
        <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
            <div className={`px-5 py-3 border-b border-gray-100 flex items-center gap-2 ${TONES[tone].split(" ").slice(0,2).join(" ")}`}>
                {icon}
                <h3 className={`text-[11px] font-bold uppercase tracking-[0.22em] ${TONES[tone].split(" ")[2]}`}>{title}</h3>
            </div>
            {rows.length === 0 ? (
                <p className="px-5 py-6 text-sm text-gray-400 italic text-center">Nothing notable this week.</p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {rows.map((r, i) => (
                        <li key={i} className="px-5 py-3">
                            {r.href ? (
                                <Link href={r.href} className="block hover:bg-gray-50 -mx-2 px-2 py-1 rounded">
                                    <p className="text-sm font-semibold text-gray-900">{r.name}</p>
                                    <p className="text-[11px] text-gray-500 mt-0.5">{r.detail}</p>
                                </Link>
                            ) : (
                                <>
                                    <p className="text-sm font-semibold text-gray-900">{r.name}</p>
                                    <p className="text-[11px] text-gray-500 mt-0.5">{r.detail}</p>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function DisabledAction({ icon, label }) {
    return (
        <button
            type="button"
            disabled
            title="Coming soon"
            className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-[11px] font-bold uppercase tracking-wider text-gray-400 bg-gray-50 border border-gray-200 cursor-not-allowed"
        >
            <Lock size={11} />
            {icon}
            {label}
        </button>
    );
}
