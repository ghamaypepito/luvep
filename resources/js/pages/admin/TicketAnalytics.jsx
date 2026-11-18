import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { ArrowUpRight, CheckCheck, Clock3, Inbox, SlidersHorizontal, UserRound } from "lucide-react";
import TicketTypeTabs from "@/components/creative/TicketTypeTabs";

const label = (s) => String(s ?? "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
const number = (v, hours = false) => v == null ? "Unavailable" : `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })}${hours ? " h" : ""}`;
const colors = ["#214f43", "#adc978", "#e9b861", "#a59bcc", "#83b9b7", "#bd8c7d"];
const metricLabels = { documentation_coverage: "Resolution report coverage", first_response_avg_hours: "First response · average", first_response_median_hours: "First response · median", assignment_avg_hours: "Assignment · average", resolution_avg_hours: "Resolution · average", resolution_median_hours: "Resolution · median", overdue_response: "Responses over target", total_with_response_target: "Tickets with response target" };

export default function TicketAnalytics({ filters = {}, options = {}, metrics = {}, trends = [], groups = {}, workload = [], timings = [], errors = {} }) {
    const [form, setForm] = useState(filters);
    const set = (key, value) => setForm((old) => ({ ...old, [key]: value }));
    const selects = { department: options.departments ?? [], priority: ["low", "normal", "high", "urgent"], status: ["open", "in_review", "planned", "in_progress", "done", "declined"], assigned_to: options.assignees ?? [] };
    const headline = [
        ["created", "Tickets created", "Includes archived tickets", Inbox],
        ["resolved", "Resolved", "Tickets marked done", CheckCheck],
        ["unresolved", "Current workload", "Active, unresolved tickets", Clock3],
        ["unassigned", "Needs an owner", "Unassigned current workload", UserRound],
    ];
    return <div className="ticket-workspace ticket-analytics">
        <Head title="Ticket Analytics" />
        <header className="tw-heading"><div><p className="tw-kicker">System Tickets</p><h1>Ticket analytics</h1><p>A clearer picture of requests, progress and team workload.</p></div><a className="tw-button" href="/admin/system-tickets">View ticket queue <ArrowUpRight size={17} aria-hidden="true" /></a></header>
        <TicketTypeTabs />
        <nav className="tw-nav" aria-label="Ticket views"><a href="/admin/system-tickets">Tickets</a><a href="/admin/system-tickets?tab=archived&status=all">Archive</a><a href="/admin/system-tickets/analytics" aria-current="page">Analytics</a><a href="/admin/system-tickets/documentation">Documentation</a></nav>
        <form className="tw-card tw-filters" onSubmit={(e) => { e.preventDefault(); router.get("/admin/system-tickets/analytics", form, { preserveState: true, replace: true }); }}>
            <div className="tw-section-heading"><h2><SlidersHorizontal size={17} aria-hidden="true" /> Report filters</h2><span>Dates select when tickets were created</span></div>
            <div className="tw-filter-fields">{["from", "to"].map((key) => <label key={key}>{key === "from" ? "From date" : "To date"}<input type="date" value={form[key] ?? ""} onChange={(e) => set(key, e.target.value)} /></label>)}
                {Object.entries(selects).map(([key, values]) => <label key={key}>{key === "assigned_to" ? "Owner" : label(key)}<select value={form[key] ?? ""} onChange={(e) => set(key, e.target.value)}><option value="">All</option>{values.map((v) => <option key={typeof v === "object" ? v.id ?? v.value : v} value={typeof v === "object" ? v.id ?? v.value : v}>{typeof v === "object" ? v.name ?? v.label : label(v)}</option>)}</select></label>)}
            </div><div className="tw-filter-actions"><button className="tw-button">Apply filters</button><a href="/admin/system-tickets/analytics">Reset filters</a><p>Historical reporting includes archived tickets.</p></div>
        </form>
        {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>}
        <section aria-label="Summary metrics" className="tw-summary">{headline.map(([key, title, hint, Icon]) => <article className={`tw-card tw-metric tw-metric-${key}`} key={key}><div><span className="tw-metric-icon"><Icon size={20} aria-hidden="true" /></span><ArrowUpRight size={16} className="tw-muted" aria-hidden="true" /></div><h2>{title}</h2><p className="tw-metric-value">{number(metrics[key])}</p><p className="tw-muted">{hint}</p></article>)}</section>
        <div className="tw-chart-grid"><TrendChart trends={trends} /><StatusChart rows={groups.status ?? []} /></div>
        <section className="tw-card tw-service" aria-labelledby="service-title"><div className="tw-section-heading"><div><h2 id="service-title">Response &amp; resolution</h2><p>Timing uses recorded tracking history. Older activity may be unavailable.</p></div></div><dl className="tw-service-metrics">{Object.entries(metrics).filter(([key]) => !headline.some(([name]) => name === key)).map(([key, value]) => <div key={key}><dt>{metricLabels[key] ?? label(key)}</dt><dd>{number(value, key.endsWith("_hours"))}{key === "documentation_coverage" && value != null ? "%" : ""}</dd></div>)}</dl></section>
        <section aria-label="Ticket breakdowns" className="tw-breakdowns">{Object.entries(groups).filter(([key]) => key !== "status").map(([key, rows]) => <Breakdown key={key} title={key === "aging" ? "Current workload age" : `By ${key}`} rows={rows} />)}</section>
        <Table title="Owner workload" description="Current open workload alongside historical resolutions." columns={["Owner", "Open", "Resolved", "Average resolution"]} rows={workload.map((r) => [r.name, number(r.open), number(r.resolved), number(r.avg_resolution_hours, true)])} />
        <Table title="Ticket timing" description="Up to 100 latest non-archived tickets matching these filters. Durations reflect recorded tracking history." columns={["Ticket", "Status", "Age", "Tracking began", "Time by status", "Time waiting"]} rows={timings.map((t) => [`${t.ticket_ref} · ${t.title}`, label(t.status), number(t.age_hours, true), t.tracking_started_at ? new Date(t.tracking_started_at).toLocaleDateString() : "Unavailable", durationList(t.status_hours), durationList(t.waiting_hours)])} />
    </div>;
}

function TrendChart({ trends: recordedWeeks }) {
    // The report omits inactive weeks; keep elapsed time honest in the chart.
    const trends = [];
    if (recordedWeeks.length) {
        const byPeriod = new Map(recordedWeeks.map((week) => [week.period, week]));
        const last = new Date(`${recordedWeeks.at(-1).period}T00:00:00Z`);
        for (const date = new Date(`${recordedWeeks[0].period}T00:00:00Z`); date <= last; date.setUTCDate(date.getUTCDate() + 7)) {
            const period = date.toISOString().slice(0, 10);
            trends.push(byPeriod.get(period) ?? { period, created: 0, resolved: 0 });
        }
    }
    const max = Math.max(1, ...trends.flatMap((t) => [Number(t.created), Number(t.resolved)]));
    return <section className="tw-card tw-trend"><div className="tw-section-heading"><div><h2>Tickets over time</h2><p>Creation and resolution weeks for the filtered tickets</p></div><span className="tw-weekly">Weekly</span></div><div className="tw-legend"><span><i style={{ background: colors[0] }} />Created</span><span><i style={{ background: colors[1] }} />Resolved</span></div>
        {trends.length ? <><div className="tw-chart-scroll" tabIndex={0} role="region" aria-label="Weekly ticket chart; scroll for more weeks"><div className="tw-bars" style={{ minWidth: `${Math.max(260, trends.length * 76)}px` }} aria-hidden="true">{trends.map((t) => <div className="tw-week" key={t.period}><div className="tw-bar-pair">{["created", "resolved"].map((key, i) => <div className="tw-bar-column" key={key}><span>{number(t[key])}</span><div style={{ height: `${Number(t[key]) / max * 150}px`, background: colors[i] }} /></div>)}</div><time dateTime={t.period}>{new Date(`${t.period}T00:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric" })}</time></div>)}</div></div><details className="tw-chart-data"><summary>View weekly data table</summary><Table columns={["Week starting", "Created", "Resolved"]} rows={trends.map((t) => [t.period, number(t.created), number(t.resolved)])} /></details></> : <p className="tw-empty">No matching tickets for this period.</p>}
    </section>;
}
function StatusChart({ rows }) {
    const total = rows.reduce((sum, row) => sum + Number(row.count), 0);
    let position = 0;
    const segments = rows.map((row, i) => { const start = position; position += total ? Number(row.count) / total * 100 : 0; return `${colors[i % colors.length]} ${start}% ${position}%`; });
    return <section className="tw-card tw-status"><div className="tw-section-heading"><div><h2>Status overview</h2><p>All tickets matching your filters</p></div></div>{total ? <><div className="tw-donut" style={{ background: `conic-gradient(${segments.join(",")})` }} aria-hidden="true"><div><strong>{number(total)}</strong><span>Total tickets</span></div></div><ul className="tw-status-legend">{rows.map((row, i) => <li key={row.label}><span><i style={{ background: colors[i % colors.length] }} />{label(row.label)}</span><strong>{number(row.count)}</strong></li>)}</ul></> : <p className="tw-empty">No matching tickets.</p>}</section>;
}
function Breakdown({ title, rows }) {
    const max = Math.max(1, ...rows.map((r) => Number(r.count)));
    return <section className="tw-card tw-breakdown"><h2>{title}</h2>{rows.length ? <ul>{rows.map((row, i) => <li key={`${row.label}-${i}`}><div><span>{label(row.label) || "Unspecified"}</span><strong>{number(row.count)}</strong></div><div className="tw-track" aria-hidden="true"><div style={{ width: `${Number(row.count) / max * 100}%` }} /></div></li>)}</ul> : <p className="tw-empty">No matching tickets.</p>}</section>;
}
function durationList(values) { return values && Object.keys(values).length ? Object.entries(values).map(([k, v]) => `${label(k)}: ${number(v, true)}`).join("; ") : "Unavailable"; }
function Table({ title, description, columns, rows }) { return <section className="tw-card tw-table-card">{title && <div className="tw-section-heading"><div><h2>{title}</h2>{description && <p>{description}</p>}</div></div>}<div className="tw-table-scroll" tabIndex={0} role="region" aria-label={title ?? "Weekly ticket data"}><table><thead><tr>{columns.map((c) => <th key={c} scope="col">{c}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, i) => <tr key={i}>{row.map((cell, j) => <td key={j}>{cell}</td>)}</tr>) : <tr><td colSpan={columns.length} className="tw-empty">No matching tickets.</td></tr>}</tbody></table></div></section>; }
