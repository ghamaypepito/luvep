import { ArrowDownLeft, ArrowUpRight, CircleAlert, Wallet } from "lucide-react";

export const money = (value, currency) => `${currency} ${Number(value ?? 0).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const date = (value) => new Date(`${value}T12:00:00Z`).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });
const buckets = { current: "Current", "1-30": "1–30 days overdue", "31-60": "31–60 days overdue", "61-90": "61–90 days overdue", "90+": "Over 90 days overdue" };

export default function FinanceAnalytics({ data, currency, start, end, onReport }) {
    const ar = data.receivables ?? {};
    const ap = data.payables ?? {};
    const previous = data.previous ?? {};
    const metrics = [
        ["Cash received", data.received, previous.received, ArrowDownLeft, `${data.receipt_count ?? 0} recorded receipts`, "collections"],
        ["Cash paid out", data.paid, previous.paid, ArrowUpRight, `${data.payment_count ?? 0} recorded payments`, "collections"],
        ["Net cash movement", data.net, previous.net, Wallet, "Cash received less cash paid out", "income"],
        ["Overdue receivables", ar.overdue_total, null, CircleAlert, `${ar.overdue_count ?? 0} overdue invoices · live balance`, "receivables_aging"],
    ];
    const topExpense = data.expense_breakdown?.[0];
    return <div className="finance-analytics">
        <section className="tw-summary" aria-label="Finance summary">
            {metrics.map(([title, value, prior, Icon, hint, report]) => <article className="tw-card tw-metric" key={title}>
                <div><span className="tw-metric-icon"><Icon size={20} aria-hidden="true" /></span><button className="finance-open" onClick={() => onReport(report)} aria-label={`View ${title.toLowerCase()} details`}><ArrowUpRight size={18} aria-hidden="true" /></button></div>
                <h2>{title}</h2><p className={`tw-metric-value ${Number(value) < 0 ? "finance-negative" : ""}`}>{money(value, currency)}</p>
                <p className="tw-muted">{hint}</p>{prior != null && <p className="finance-comparison">{comparison(value, prior, currency)}</p>}
            </article>)}
        </section>
        <p className="finance-caption">Cash metrics: {date(start)} – {date(end)}. Comparison: {date(previous.start ?? start)} – {date(previous.end ?? end)} (the preceding equal-length period). Balances below are live as of {date(data.as_of ?? end)}.</p>
        <div className="tw-chart-grid">
            <CashTrend rows={data.trends ?? []} currency={currency} />
            <section className="tw-card finance-insights" aria-labelledby="finance-insights-title">
                <div className="tw-section-heading"><div><h2 id="finance-insights-title">What needs attention</h2><p>Facts from the selected currency’s recorded ledger.</p></div></div>
                <ul>
                    <li><strong>{money(data.net, currency)} net cash movement</strong><p>{Number(data.net) < 0 ? "Recorded payments exceed receipts in this period." : Number(data.net) > 0 ? "Recorded receipts exceed payments in this period." : "Receipts and payments are equal in this period."}</p></li>
                    <li><strong>{money(ar.overdue_total, currency)} to follow up</strong><p>{ar.overdue_count ?? 0} overdue invoices{ar.oldest_days ? `; the oldest is ${ar.oldest_days} days past due` : ""}.</p></li>
                    <li><strong>{money(ap.due_soon_total, currency)} payable in the next 7 days</strong><p>{ap.due_soon_count ?? 0} bills due today through six days from today. Separately, {money(ap.overdue_total, currency)} is already overdue.</p></li>
                    {topExpense && <li><strong>{topExpense.category} is the largest paid category</strong><p>{money(topExpense.amount, currency)}{Number(data.paid) > 0 ? ` · ${(topExpense.amount / data.paid * 100).toFixed(1)}% of cash paid out` : ""} in this period.</p></li>}
                </ul>
            </section>
        </div>
        <div className="finance-ledger-grid">
            <LedgerStatus title="Receivables" snapshot={ar} currency={currency} asOf={data.as_of ?? end} />
            <LedgerStatus title="Payables" snapshot={ap} currency={currency} asOf={data.as_of ?? end} />
        </div>
        <div className="finance-ledger-grid">
            <AgingPanel title="Receivables" snapshot={ar} currency={currency} onOpen={() => onReport("receivables_aging")} />
            <AgingPanel title="Payables" snapshot={ap} currency={currency} onOpen={() => onReport("payables_aging")} />
        </div>
        <section className="tw-card tw-breakdown">
            <div className="tw-section-heading"><div><h2>Cash paid by expense category</h2><p>Recorded supplier payments in the selected period. Gross amounts, including any recorded tax.</p></div></div>
            <FinancePie rows={(data.expense_breakdown ?? []).filter(row => Number(row.amount) > 0).map(row => ({ label: row.category, amount: row.amount }))} currency={currency} title="Expense category" groupLabel="Category" totalLabel="Total paid" empty="No supplier payments recorded in this period." />
        </section>
        <div className="finance-ledger-grid">
            <Attention title="Largest overdue invoices" snapshot={ar} kind="receivables" currency={currency} />
            <Attention title="Largest overdue bills" snapshot={ap} kind="payables" currency={currency} />
        </div>
    </div>;
}

function FinancePie({ rows, currency, title, groupLabel, totalLabel, empty }) {
    const categories = rows.filter(row => Number(row.amount) > 0);
    const total = categories.reduce((sum, row) => sum + Number(row.amount), 0);
    const colors = ["#214f43", "#b47b45", "#739c83", "#6a829b", "#b5c77c", "#986783", "#c59a73", "#78806b"];
    let position = 0;
    if (!total) return <p className="tw-empty">{empty}</p>;
    return <div className="finance-pie">
        <figure>
            <svg viewBox="0 0 100 100" role="img" aria-label={`${title} pie chart. ${totalLabel}: ${money(total, currency)}. Exact amounts and shares are listed alongside.`}>
                {categories.map((row, i) => {
                    const share = Number(row.amount) / total;
                    const start = position * Math.PI * 2 - Math.PI / 2;
                    position += share;
                    const end = position * Math.PI * 2 - Math.PI / 2;
                    const sliceTitle = `${row.label}: ${money(row.amount, currency)} (${(share * 100).toFixed(1)}%)`;
                    return categories.length === 1 ? <circle key={row.label} cx="50" cy="50" r="49" fill={row.color ?? colors[i % colors.length]}><title>{sliceTitle}</title></circle> :
                        <path key={row.label} fill={row.color ?? colors[i % colors.length]} stroke="white" strokeWidth="0.5" d={`M 50 50 L ${50 + 49 * Math.cos(start)} ${50 + 49 * Math.sin(start)} A 49 49 0 ${share > 0.5 ? 1 : 0} 1 ${50 + 49 * Math.cos(end)} ${50 + 49 * Math.sin(end)} Z`}><title>{sliceTitle}</title></path>;
                })}
            </svg>
            <figcaption>{totalLabel} <strong>{money(total, currency)}</strong></figcaption>
        </figure>
        <div className="finance-pie-legend">
            <DataTable title={`${title} amounts and shares`} columns={[groupLabel, "Amount", "Share"]} rows={rows.map((row, i) => [<span className="finance-pie-category" key={row.label}><i aria-hidden="true" style={{ background: row.color ?? colors[i % colors.length] }} />{row.label}</span>, money(row.amount, currency), `${(Number(row.amount) / total * 100).toFixed(1)}%`])} />
        </div>
    </div>;
}

function LedgerStatus({ title, snapshot, currency, asOf }) {
    return <section className="tw-card finance-ledger-status">
        <div className="tw-section-heading"><div><h2>{title} payment status</h2><p>All issued records · live as of {date(asOf)} · {currency}.</p></div></div>
        <FinancePie title={`${title} payment status`} groupLabel="Status" totalLabel="Total issued" currency={currency} empty={`No issued ${title.toLowerCase()} in this currency.`} rows={[
            { label: "Paid", amount: snapshot.paid_total ?? 0, color: "#214f43" },
            { label: "Outstanding (not overdue)", amount: snapshot.not_overdue_total ?? 0, color: "#b47b45" },
            { label: "Overdue", amount: snapshot.overdue_total ?? 0, color: "#a65245" },
        ]} />
        <p className="finance-caption">Live document balances across all dates. Drafts and voids excluded. Paid is capped at each document’s total; excess payments are excluded.</p>
    </section>;
}

function comparison(current, previous, currency) {
    const change = Math.round((Number(current ?? 0) - Number(previous)) * 100) / 100;
    if (change === 0) return "Unchanged from previous period";
    return `${money(Math.abs(change), currency)} ${change > 0 ? "higher" : "lower"} than previous period`;
}

function CashTrend({ rows, currency }) {
    const max = Math.max(1, ...rows.flatMap(r => [r.received, r.paid]));
    const hasCash = rows.some(r => r.received !== 0 || r.paid !== 0);
    return <section className="tw-card tw-trend">
        <div className="tw-section-heading"><div><h2>Cash flow over time</h2><p>Weekly totals by actual payment date. First and last weeks include only the selected dates.</p></div><span className="tw-weekly">{currency} · weekly</span></div>
        <div className="tw-legend"><span><i style={{ background: "#214f43" }} />Received</span><span><i style={{ background: "#b47b45" }} />Paid out</span></div>
        {hasCash ? <div className="tw-chart-scroll" tabIndex={0} role="region" aria-label="Weekly cash flow chart; scroll for more weeks"><div className="tw-bars" style={{ minWidth: `${Math.max(260, rows.length * 70)}px` }} aria-hidden="true">{rows.map(r => <div className="tw-week" key={r.period}><div className="tw-bar-pair">{["received", "paid"].map((key, i) => <div className="tw-bar-column" key={key} title={`${key === "received" ? "Received" : "Paid out"}: ${money(r[key], currency)}`}><span>{new Intl.NumberFormat("en-NZ", { notation: "compact", maximumFractionDigits: 1 }).format(r[key])}</span><div style={{ height: `${Math.max(0, r[key]) / max * 150}px`, background: i ? "#b47b45" : "#214f43" }} /></div>)}</div><time dateTime={r.period}>{new Date(`${r.period}T12:00:00Z`).toLocaleDateString("en-NZ", { month: "short", day: "numeric", timeZone: "UTC" })}</time></div>)}</div></div> : <p className="tw-empty">No cash movements recorded in this period.</p>}
        <details className="tw-chart-data"><summary>View weekly cash data</summary><DataTable title="Weekly cash data" columns={["Week starting", "Received", "Paid out", "Net cash"]} rows={rows.map(r => [r.period, money(r.received, currency), money(r.paid, currency), money(r.received - r.paid, currency)])} empty="No cash movements recorded." /></details>
    </section>;
}

function AgingPanel({ title, snapshot, currency, onOpen }) {
    return <section className="tw-card tw-breakdown">
        <div className="tw-section-heading"><div><h2>{title} aging</h2><p>Live balance · {snapshot.count ?? 0} outstanding records. Drafts and voids excluded.</p></div><button className="finance-text-button" onClick={onOpen}>View report</button></div>
        <p className="finance-balance">{money(snapshot.total, currency)}</p>
        <Bars rows={Object.entries(buckets).map(([key, label]) => ({ label, amount: snapshot.buckets?.[key] ?? 0 }))} currency={currency} />
        <p className="finance-caption">{money(snapshot.due_soon_total, currency)} due in the next 7 days.</p>
    </section>;
}

function Bars({ rows, currency, empty }) {
    const total = rows.reduce((sum, r) => sum + Number(r.amount), 0);
    return rows.length ? <ul>{rows.map((r, i) => <li key={`${r.label}-${i}`}><div><span>{r.label}</span><strong>{money(r.amount, currency)}</strong></div><div className="tw-track" aria-hidden="true"><div style={{ width: `${total > 0 ? r.amount / total * 100 : 0}%` }} /></div></li>)}</ul> : <p className="tw-empty">{empty}</p>;
}

function Attention({ title, snapshot, kind, currency }) {
    return <section className="tw-card tw-table-card"><div className="tw-section-heading"><div><h2>{title}</h2><p>Top five by remaining balance, as of today.</p></div></div><DataTable title={title} columns={["Reference / party", "Due", "Overdue", "Balance"]} rows={(snapshot.attention ?? []).map(r => [<a className="finance-record" href={`/portal/finance/${kind}?q=${encodeURIComponent(r.ref || r.party || "")}`} key={r.id}>{r.ref || "No reference"}<small>{r.party}</small></a>, r.due_date, `${r.days_past_due} days`, money(r.balance, currency)])} empty="No overdue records in this currency." /></section>;
}

function DataTable({ title, columns, rows, empty }) {
    return <div className="tw-table-scroll" role="region" tabIndex={0} aria-label={title}><table><thead><tr>{columns.map(c => <th key={c} scope="col">{c}</th>)}</tr></thead><tbody>{rows.length ? rows.map((r, i) => <tr key={i}>{r.map((cell, j) => <td key={j}>{cell}</td>)}</tr>) : <tr><td colSpan={columns.length} className="tw-empty">{empty}</td></tr>}</tbody></table></div>;
}
