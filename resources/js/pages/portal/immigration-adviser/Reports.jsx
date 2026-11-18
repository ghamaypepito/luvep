import WeeklyReport, { WeekButtons } from "@/components/WeeklyReport";
import { Head, router } from "@inertiajs/react";
import { Globe, UserCheck, Clock, CheckCircle2, XCircle } from "lucide-react";

// Adviser reports — verification throughput + casework distribution at a glance.
export default function Reports({ stats = {}, byStage = [], licence = {}, preset, weeklyReport }) {
    const cards = [
        { label: "All cases", value: stats.total_cases ?? 0, icon: <Globe size={16} />, tone: "teal" },
        { label: "My cases", value: stats.my_cases ?? 0, icon: <UserCheck size={16} />, tone: "teal" },
        { label: "Awaiting verification", value: stats.pending_verification ?? 0, icon: <Clock size={16} />, tone: "amber" },
        { label: "Approved in period", value: stats.approved_by_me ?? 0, icon: <CheckCircle2 size={16} />, tone: "emerald" },
        { label: "Rejected in period", value: stats.rejected_by_me ?? 0, icon: <XCircle size={16} />, tone: "red" },
    ];
    const maxStage = Math.max(1, ...byStage.map((s) => s.total));

    return (
        <div className="max-w-[1000px] mx-auto pb-14 space-y-6">
            <Head title="Reports" />
            <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-[#009688] mb-1.5">Overview</p>
                <h1 className="text-2xl font-semibold text-gray-900 tracking-tight">Reports</h1>
                <p className="text-sm text-gray-500 mt-1">Your verification activity and the casework across the practice.</p>
            </div>

            <WeekButtons preset={preset} onChange={(selected) => router.get("/portal/immigration-adviser/reports", { preset: selected }, { preserveScroll: true })} />
            <WeeklyReport report={weeklyReport} saveUrl="/portal/immigration-adviser/reports/note" />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
                {cards.map((c) => <StatCard key={c.label} {...c} />)}
            </div>

            <section className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
                <h2 className="text-sm font-bold text-gray-900 mb-4">Cases by stage</h2>
                {byStage.length === 0 ? (
                    <p className="text-sm text-gray-400">No cases yet.</p>
                ) : (
                    <div className="space-y-3">
                        {byStage.map((s) => (
                            <div key={s.stage} className="flex items-center gap-3">
                                <span className="w-40 text-[12px] text-gray-600 truncate flex-shrink-0">{s.stage}</span>
                                <div className="flex-1 h-2.5 rounded-full bg-gray-100 overflow-hidden">
                                    <div className="h-full rounded-full bg-[#009688]" style={{ width: `${Math.round((s.total / maxStage) * 100)}%` }} />
                                </div>
                                <span className="w-8 text-right text-[12px] font-bold text-gray-700 flex-shrink-0">{s.total}</span>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {licence?.number && (
                <p className="text-[12px] text-gray-400">
                    IAA licence {licence.number}{licence.type ? ` · ${licence.type}` : ""}{licence.expiry ? ` · expires ${licence.expiry}` : ""}
                    {" · "}{licence.current ? "current" : "not current"}
                </p>
            )}
        </div>
    );
}

function StatCard({ label, value, icon, tone }) {
    const TONES = {
        teal: "bg-[#009688]/10 text-[#009688]",
        amber: "bg-amber-50 text-amber-600",
        emerald: "bg-emerald-50 text-emerald-600",
        red: "bg-red-50 text-red-600",
    };
    return (
        <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-4">
            <div className={`w-9 h-9 rounded-xl flex items-center justify-center mb-3 ${TONES[tone] || TONES.teal}`}>{icon}</div>
            <p className="text-2xl font-bold text-gray-900 tracking-tight">{value}</p>
            <p className="text-[11px] text-gray-500 mt-0.5">{label}</p>
        </div>
    );
}
