const label = (s) => String(s ?? "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
const number = (v) => Number(v).toLocaleString();
const colors = ["#214f43", "#adc978", "#e9b861", "#a59bcc", "#83b9b7", "#bd8c7d"];

export function TicketTrendChart({ trends: recordedWeeks, title = "Tickets over time", description = "Creation and resolution weeks for the filtered tickets", series = [{ key: "created", label: "Created" }, { key: "resolved", label: "Resolved" }] }) {
    // The report omits inactive weeks; keep elapsed time honest in the chart.
    const trends = [];
    if (recordedWeeks.length) {
        const byPeriod = new Map(recordedWeeks.map((week) => [week.period, week]));
        const last = new Date(`${recordedWeeks.at(-1).period}T00:00:00Z`);
        for (const date = new Date(`${recordedWeeks[0].period}T00:00:00Z`); date <= last; date.setUTCDate(date.getUTCDate() + 7)) {
            const period = date.toISOString().slice(0, 10);
            trends.push(byPeriod.get(period) ?? { period, ...Object.fromEntries(series.map(({ key }) => [key, 0])) });
        }
    }
    const max = Math.max(1, ...trends.flatMap((t) => series.map(({ key }) => Number(t[key]))));
    return <section className="tw-card tw-trend"><div className="tw-section-heading"><div><h2>{title}</h2><p>{description}</p></div><span className="tw-weekly">Weekly</span></div><div className="tw-legend">{series.map(({ key, label }, i) => <span key={key}><i style={{ background: colors[i] }} />{label}</span>)}</div>
        {trends.length ? <><div className="tw-chart-scroll" tabIndex={0} role="region" aria-label="Weekly ticket chart; scroll for more weeks"><div className="tw-bars" style={{ minWidth: `${Math.max(260, trends.length * 76)}px` }} aria-hidden="true">{trends.map((t) => <div className="tw-week" key={t.period}><div className="tw-bar-pair">{series.map(({ key }, i) => <div className="tw-bar-column" key={key}><span>{number(t[key])}</span><div style={{ height: `${Number(t[key]) / max * 150}px`, background: colors[i] }} /></div>)}</div><time dateTime={t.period}>{new Date(`${t.period}T00:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric" })}</time></div>)}</div></div><details className="tw-chart-data"><summary>View weekly data table</summary><TicketDataTable columns={["Week starting", ...series.map(({ label }) => label)]} rows={trends.map((t) => [t.period, ...series.map(({ key }) => number(t[key]))])} /></details></> : <p className="tw-empty">No activity for this period.</p>}
    </section>;
}
export function TicketStatusChart({ rows, title = "Status overview", description = "All tickets matching your filters", totalLabel = "Total tickets" }) {
    const total = rows.reduce((sum, row) => sum + Number(row.count), 0);
    let position = 0;
    const segments = rows.map((row, i) => { const start = position; position += total ? Number(row.count) / total * 100 : 0; return `${colors[i % colors.length]} ${start}% ${position}%`; });
    return <section className="tw-card tw-status"><div className="tw-section-heading"><div><h2>{title}</h2><p>{description}</p></div></div>{total ? <><div className="tw-donut" style={{ background: `conic-gradient(${segments.join(",")})` }} aria-hidden="true"><div><strong>{number(total)}</strong><span>{totalLabel}</span></div></div><ul className="tw-status-legend">{rows.map((row, i) => <li key={row.label}><span><i style={{ background: colors[i % colors.length] }} />{label(row.label)}</span><strong>{number(row.count)}</strong></li>)}</ul></> : <p className="tw-empty">No matching requests.</p>}</section>;
}
export function TicketDataTable({ title, description, columns, rows }) { return <section className="tw-card tw-table-card">{title && <div className="tw-section-heading"><div><h2>{title}</h2>{description && <p>{description}</p>}</div></div>}<div className="tw-table-scroll" tabIndex={0} role="region" aria-label={title ?? "Weekly ticket data"}><table><thead><tr>{columns.map((c) => <th key={c} scope="col">{c}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, i) => <tr key={i}>{row.map((cell, j) => <td key={j}>{cell}</td>)}</tr>) : <tr><td colSpan={columns.length} className="tw-empty">No matching tickets.</td></tr>}</tbody></table></div></section>; }
