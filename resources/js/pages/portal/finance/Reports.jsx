import WeeklyReport, { WeekButtons } from "@/components/WeeklyReport";
import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Printer, Download, TrendingUp, TrendingDown, Wallet } from "lucide-react";

const REPORTS = [
    { key: "income", label: "Income Statement" },
    { key: "receivables_aging", label: "Receivables Aging" },
    { key: "payables_aging", label: "Payables Aging" },
    { key: "collections", label: "Collections" },
];
const BUCKET_LABEL = { current: "Current", "1-30": "1–30d", "31-60": "31–60d", "61-90": "61–90d", "90+": "90+d" };
const money = (v, cur) => `${cur || ""} ${Number(v || 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();

function downloadCsv(filename, rows) {
    const csv = rows.map((r) => r.map((c) => {
        const s = String(c ?? "");
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    }).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

const pad = (n) => String(n).padStart(2, "0");
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

export default function Reports({ report = "income", start, end, preset: activePreset, weeklyReport, currency = "NZD", data = {} }) {
    const [range, setRange] = useState({ start, end });
    const isAging = report.endsWith("_aging");

    const go = (params) => router.get("/portal/finance/reports", { report, start: range.start, end: range.end, ...params }, { preserveScroll: true });

    const selectPreset = (kind) => {
        const now = new Date();
        let s;
        let e = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        if (kind === "month") s = new Date(now.getFullYear(), now.getMonth(), 1);
        else if (kind === "quarter") { const q = Math.floor(now.getMonth() / 3); s = new Date(now.getFullYear(), q * 3, 1); e = new Date(now.getFullYear(), q * 3 + 3, 0); }
        else if (kind === "year") { s = new Date(now.getFullYear(), 0, 1); e = new Date(now.getFullYear(), 11, 31); }
        else { s = new Date(now.getFullYear(), now.getMonth() - 1, 1); e = new Date(now.getFullYear(), now.getMonth(), 0); }
        const next = { start: iso(s), end: iso(e) };
        setRange(next);
        go(next);
    };

    const label = REPORTS.find((r) => r.key === report)?.label || "Report";

    const exportCsv = () => {
        const fname = `${report}_${start}_${end}.csv`;
        let rows = [[label], [isAging ? "Current snapshot" : `${start} to ${end}`], []];
        if (report === "income") {
            rows.push(["Revenue", data.revenue], ["Expenses", data.expenses], ["Net profit", data.net], [], ["Expense breakdown", ""], ["Category", "Amount"]);
            (data.expense_breakdown || []).forEach((b) => rows.push([b.category, b.amount]));
        } else if (isAging) {
            rows.push(["Reference", "Party", "Due date", "Amount", "Balance", "Days past due", "Bucket"]);
            (data.rows || []).forEach((r) => rows.push([r.ref, r.party, r.due_date, r.amount, r.balance, r.days_past_due, r.bucket]));
            rows.push([], ["Total outstanding", data.total]);
        } else {
            rows.push(["Received"], ["Date", "Ref", "Client", "Method", "Amount"]);
            (data.received || []).forEach((r) => rows.push([r.paid_on, r.ref, r.party, r.method, r.amount]));
            rows.push(["Total received", "", "", "", data.received_total], [], ["Paid out"], ["Date", "Ref", "Vendor", "Method", "Amount"]);
            (data.paid || []).forEach((r) => rows.push([r.paid_on, r.ref, r.party, r.method, r.amount]));
            rows.push(["Total paid", "", "", "", data.paid_total], [], ["Net cash", "", "", "", data.net]);
        }
        downloadCsv(fname, rows);
    };

    return (
        <div className="space-y-6 max-w-[1400px] mx-auto pb-12">
            <Head title="Finance Reports" />

            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Reports</h1>
                    <p className="text-sm text-gray-500 mt-1">Income statement, aging and collections — straight from the ledger.</p>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={exportCsv} className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50"><Download size={15} /> CSV</button>
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50"><Printer size={15} /> Print</button>
                </div>
            </div>

            {/* Report tabs */}
            <div className="flex items-center gap-1.5 flex-wrap">
                {REPORTS.map((r) => (
                    <button key={r.key} onClick={() => go({ report: r.key, ...(activePreset ? { preset: activePreset } : {}) })}
                        className={`px-4 py-2 rounded-lg text-sm font-bold transition-colors ${report === r.key ? "bg-indigo-600 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`}>
                        {r.label}
                    </button>
                ))}
            </div>

            {/* Date range (income & collections only) */}
            {!isAging && (
                <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3 bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
                    <div className="flex items-center gap-2">
                        <input type="date" value={range.start} onChange={(e) => setRange((p) => ({ ...p, start: e.target.value }))} className="px-3 py-2 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-indigo-500/30" />
                        <span className="text-gray-400 text-sm">to</span>
                        <input type="date" value={range.end} onChange={(e) => setRange((p) => ({ ...p, end: e.target.value }))} className="px-3 py-2 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-indigo-500/30" />
                        <button onClick={() => go({})} className="px-4 py-2 bg-indigo-600 text-white text-sm font-bold rounded-xl hover:bg-indigo-700">Apply</button>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5 sm:ml-auto">
                        <WeekButtons preset={activePreset} onChange={(preset) => go({ preset })} />
                        {[["month", "This month"], ["quarter", "This quarter"], ["year", "This year"], ["last_month", "Last month"]].map(([k, lbl]) => (
                            <button key={k} onClick={() => selectPreset(k)} className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-50 border border-gray-200 text-gray-600 hover:bg-gray-100">{lbl}</button>
                        ))}
                    </div>
                </div>
            )}
            {isAging && <p className="text-xs text-gray-400">Aging is a live snapshot of everything still outstanding, as of today.</p>}

            <WeeklyReport report={weeklyReport} saveUrl="/portal/finance/reports/note" />

            {/* Report body */}
            <div className="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">
                <div className="mb-5">
                    <h2 className="text-lg font-bold text-gray-900">{label}</h2>
                    {!isAging && <p className="text-xs text-gray-400">{start} — {end}</p>}
                </div>

                {report === "income" && <IncomeReport data={data} currency={currency} />}
                {isAging && <AgingReport data={data} currency={currency} kind={report === "receivables_aging" ? "Client" : "Vendor"} />}
                {report === "collections" && <CollectionsReport data={data} currency={currency} />}
            </div>
        </div>
    );
}

function IncomeReport({ data, currency }) {
    const net = data.net ?? 0;
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <Stat icon={<TrendingUp className="w-5 h-5 text-emerald-600" />} label="Revenue" value={money(data.revenue, currency)} tone="text-emerald-600" />
                <Stat icon={<TrendingDown className="w-5 h-5 text-rose-500" />} label="Expenses" value={money(data.expenses, currency)} tone="text-rose-500" />
                <Stat icon={<Wallet className="w-5 h-5 text-indigo-600" />} label="Net profit" value={money(net, currency)} tone={net >= 0 ? "text-emerald-600" : "text-rose-600"} />
            </div>
            <div>
                <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-2">Expenses by category</p>
                <div className="rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-100">
                    {(data.expense_breakdown || []).length === 0 ? (
                        <p className="px-4 py-4 text-sm text-gray-400">No expenses paid in this period.</p>
                    ) : data.expense_breakdown.map((b) => (
                        <div key={b.category} className="flex items-center justify-between px-4 py-2.5 text-sm">
                            <span className="text-gray-700">{b.category}</span>
                            <span className="tabular-nums font-semibold text-gray-900">{money(b.amount, currency)}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function AgingReport({ data, currency, kind }) {
    const buckets = data.buckets || {};
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 sm:grid-cols-6 gap-3">
                {["current", "1-30", "31-60", "61-90", "90+"].map((b) => (
                    <div key={b} className="rounded-xl border border-gray-200 p-3">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">{BUCKET_LABEL[b]}</p>
                        <p className={`text-sm font-bold tabular-nums ${b === "90+" ? "text-rose-600" : "text-gray-900"}`}>{money(buckets[b], currency)}</p>
                    </div>
                ))}
                <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-3">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-indigo-400 mb-1">Total</p>
                    <p className="text-sm font-bold tabular-nums text-indigo-700">{money(data.total, currency)}</p>
                </div>
            </div>
            <div className="rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider">
                            <th className="px-4 py-2.5">Ref</th><th className="px-3 py-2.5">{kind}</th>
                            <th className="px-3 py-2.5">Due</th><th className="px-3 py-2.5 text-right">Amount</th>
                            <th className="px-3 py-2.5 text-right">Balance</th><th className="px-3 py-2.5">Age</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {(data.rows || []).length === 0 ? (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Nothing outstanding — all settled.</td></tr>
                        ) : data.rows.map((r, i) => (
                            <tr key={i}>
                                <td className="px-4 py-2 font-mono text-xs text-gray-600">{r.ref}</td>
                                <td className="px-3 py-2 text-gray-800">{r.party}</td>
                                <td className="px-3 py-2 text-gray-500">{r.due_date}</td>
                                <td className="px-3 py-2 text-right tabular-nums text-gray-600">{money(r.amount, r.currency)}</td>
                                <td className="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{money(r.balance, r.currency)}</td>
                                <td className="px-3 py-2"><span className={`text-[10px] font-bold ${r.days_past_due > 0 ? "text-rose-600" : "text-gray-400"}`}>{r.days_past_due > 0 ? `${r.days_past_due}d` : "current"}</span></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function CollectionsReport({ data, currency }) {
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <Stat icon={<TrendingUp className="w-5 h-5 text-emerald-600" />} label="Received" value={money(data.received_total, currency)} tone="text-emerald-600" />
                <Stat icon={<TrendingDown className="w-5 h-5 text-rose-500" />} label="Paid out" value={money(data.paid_total, currency)} tone="text-rose-500" />
                <Stat icon={<Wallet className="w-5 h-5 text-indigo-600" />} label="Net cash" value={money(data.net, currency)} tone={(data.net ?? 0) >= 0 ? "text-emerald-600" : "text-rose-600"} />
            </div>
            <CollectionsTable title="Money received" rows={data.received} partyLabel="Client" currency={currency} />
            <CollectionsTable title="Money paid out" rows={data.paid} partyLabel="Vendor" currency={currency} />
        </div>
    );
}

function CollectionsTable({ title, rows = [], partyLabel, currency }) {
    return (
        <div>
            <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-2">{title}</p>
            <div className="rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="bg-gray-50 text-gray-500 text-[10px] font-bold uppercase tracking-wider border-b border-gray-200">
                            <th className="px-4 py-2">Date</th><th className="px-3 py-2">Ref</th>
                            <th className="px-3 py-2">{partyLabel}</th><th className="px-3 py-2">Method</th><th className="px-3 py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-400">None in this period.</td></tr>
                        ) : rows.map((r, i) => (
                            <tr key={i}>
                                <td className="px-4 py-2 text-gray-500">{r.paid_on}</td>
                                <td className="px-3 py-2 font-mono text-xs text-gray-600">{r.ref}</td>
                                <td className="px-3 py-2 text-gray-800">{r.party}</td>
                                <td className="px-3 py-2 text-gray-500">{r.method || "—"}</td>
                                <td className="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{money(r.amount, currency)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function Stat({ icon, label, value, tone }) {
    return (
        <div className="rounded-2xl border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
                <span className="text-[11px] font-bold tracking-[0.18em] uppercase text-gray-500">{label}</span>
                <div className="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center">{icon}</div>
            </div>
            <p className={`text-2xl font-bold tracking-tight tabular-nums ${tone}`}>{value}</p>
        </div>
    );
}
