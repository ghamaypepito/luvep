import { Head, Link } from "@inertiajs/react";
import { DollarSign, CreditCard, AlertTriangle, ArrowUpCircle } from "lucide-react";

const money = (v, cur) => `${cur || ""} ${Number(v || 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();
const BUCKET_LABEL = { current: "Current", "1-30": "1–30d", "31-60": "31–60d", "61-90": "61–90d", "90+": "90+d" };

export default function FinanceDashboard({ currency = "NZD", metrics = {}, aging = {}, trend = [] }) {
    const tiles = [
        { label: "Revenue collected", icon: <DollarSign className="w-5 h-5 text-emerald-600" />, value: money(metrics.revenue_collected, currency), hint: "Receivable payments this month." },
        { label: "Outstanding (AR)", icon: <AlertTriangle className="w-5 h-5 text-amber-600" />, value: money(metrics.ar_outstanding, currency), hint: `${money(metrics.ar_overdue, currency)} of it overdue.` },
        { label: "In progress", icon: <CreditCard className="w-5 h-5 text-indigo-600" />, value: metrics.in_progress ?? 0, hint: "Invoices sent, partially/awaiting payment." },
        { label: "Payables due (AP)", icon: <ArrowUpCircle className="w-5 h-5 text-blue-600" />, value: money(metrics.ap_outstanding, currency), hint: "What you owe vendors." },
    ];

    const trendMax = Math.max(1, ...trend.map((t) => t.value));
    const agingMax = Math.max(1, ...Object.values(aging));

    return (
        <div className="max-w-[1400px] mx-auto pb-12 space-y-6">
            <Head title="Finance Dashboard" />

            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Finance Dashboard</h1>
                    <p className="text-sm text-gray-500 mt-1">Overview of fees, payments, and outstanding balances in {currency}.</p>
                </div>
                <div className="flex items-center gap-2">
                    <Link href="/portal/finance/receivables" className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50">Receivables</Link>
                    <Link href="/portal/finance/payables" className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50">Payables</Link>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {tiles.map((t) => (
                    <div key={t.label} className="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                        <div className="flex items-center justify-between mb-3">
                            <span className="text-[11px] font-bold tracking-[0.18em] uppercase text-gray-500">{t.label}</span>
                            <div className="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center">{t.icon}</div>
                        </div>
                        <p className="text-2xl font-bold text-gray-900 tracking-tight tabular-nums">{t.value}</p>
                        <p className="text-[11px] text-gray-400 mt-2 leading-snug">{t.hint}</p>
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {/* Revenue trend */}
                <div className="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                    <h2 className="text-sm font-bold text-gray-900 tracking-tight mb-1">Revenue trend</h2>
                    <p className="text-[12px] text-gray-500 mb-6">Receivable payments collected, last 6 months.</p>
                    <div className="h-48 flex items-end gap-3">
                        {trend.map((t) => (
                            <div key={t.label} className="flex-1 flex flex-col items-center justify-end h-full">
                                <span className="text-[10px] font-bold text-gray-500 tabular-nums mb-1">{t.value ? Math.round(t.value / 1000) + "k" : ""}</span>
                                <div className="w-full rounded-t-lg bg-emerald-500/80" style={{ height: `${Math.max(2, (t.value / trendMax) * 100)}%` }} />
                                <span className="text-[10px] font-bold uppercase tracking-wide text-gray-400 mt-1.5">{t.label}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Aged receivables */}
                <div className="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                    <h2 className="text-sm font-bold text-gray-900 tracking-tight mb-1">Aged receivables</h2>
                    <p className="text-[12px] text-gray-500 mb-6">Outstanding balance buckets, by age.</p>
                    <div className="h-48 grid grid-cols-5 gap-3 items-end">
                        {["current", "1-30", "31-60", "61-90", "90+"].map((b) => (
                            <div key={b} className="flex flex-col items-center justify-end h-full">
                                <div className={`w-full rounded-t-lg ${b === "current" ? "bg-indigo-500" : b === "90+" ? "bg-rose-500" : "bg-indigo-300"}`}
                                    style={{ height: `${Math.max(2, ((aging[b] || 0) / agingMax) * 100)}%` }} />
                                <span className="text-[10px] font-bold uppercase tracking-wide text-gray-400 mt-1.5">{BUCKET_LABEL[b]}</span>
                                <span className="text-[10px] font-bold text-gray-700 tabular-nums">{money(aging[b], currency).replace(currency, "").trim()}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
