import React, { useState } from "react";
import { router } from "@inertiajs/react";
import { immBase } from "@/lib/caseRoutes";
import { ChevronDown, MapPin } from "lucide-react";

// The five INZ activity statuses → pill styling. One control per row (a small
// coloured dropdown that both shows and sets the status).
const STATUS = {
    not_started:    { label: "Not started",    pill: "bg-slate-100 text-slate-500 border-slate-200" },
    in_progress:    { label: "In progress",    pill: "bg-blue-50 text-blue-700 border-blue-200" },
    info_requested: { label: "Info requested", pill: "bg-amber-50 text-amber-700 border-amber-200" },
    completed:      { label: "Completed",      pill: "bg-green-50 text-green-700 border-green-200" },
    not_applicable: { label: "Not applicable", pill: "bg-slate-600 text-white border-slate-600" },
};
const STATUS_ORDER = ["not_started", "in_progress", "info_requested", "completed", "not_applicable"];

function fmt(dt) {
    if (!dt) return null;
    try {
        return new Date(dt).toLocaleString(undefined, { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });
    } catch { return dt; }
}

// One compact single-line activity: label (left) + status dropdown (right).
// Description shows on hover; past statuses expand when the label is clicked.
function ActivityRow({ leadId, activity }) {
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState(false);
    const s = STATUS[activity.status] ?? STATUS.not_started;
    const history = activity.history ?? [];
    const hasHistory = history.length > 1;

    const setStatus = (status) => {
        if (status === activity.status) return;
        setSaving(true);
        router.post(`${immBase()}/cases/${leadId}/visa-progress`, { activity: activity.key, status }, {
            preserveScroll: true, onFinish: () => setSaving(false),
        });
    };

    return (
        <>
            <div className="flex items-center justify-between gap-3 px-3 py-1.5 border-t border-gray-100 first:border-t-0">
                <button type="button" title={activity.description}
                    onClick={() => hasHistory && setOpen((o) => !o)}
                    className={`text-left text-xs font-medium text-gray-700 truncate ${hasHistory ? "hover:text-[#009688]" : "cursor-default"}`}>
                    {activity.label}
                    {hasHistory && <span className="ml-1 text-[10px] text-gray-300">({history.length})</span>}
                </button>
                <div className="relative shrink-0">
                    <select value={activity.status} disabled={saving} onChange={(e) => setStatus(e.target.value)}
                        className={`appearance-none cursor-pointer text-[11px] font-semibold rounded-full border pl-2.5 pr-6 py-0.5 focus:outline-none disabled:opacity-50 ${s.pill}`}>
                        {STATUS_ORDER.map((k) => <option key={k} value={k} className="bg-white text-gray-700">{STATUS[k].label}</option>)}
                    </select>
                    <ChevronDown size={11} className="absolute right-1.5 top-1/2 -translate-y-1/2 pointer-events-none opacity-60" />
                </div>
            </div>
            {open && (
                <div className="px-3 pb-1.5 -mt-0.5 text-[10px] text-gray-400 flex flex-wrap gap-x-1.5">
                    {history.map((h, i) => (
                        <span key={i}>{(STATUS[h.status] ?? STATUS.not_started).label} · {fmt(h.at)}{i < history.length - 1 ? "  →" : ""}</span>
                    ))}
                </div>
            )}
        </>
    );
}

// The INZ sub-status tracker: activities grouped by phase, compact, no inputs.
function SubStatusTracker({ leadId, visaProgress }) {
    const groups = [];
    (visaProgress.activities ?? []).forEach((a) => {
        let g = groups.find((x) => x.name === a.group);
        if (!g) { g = { name: a.group, items: [] }; groups.push(g); }
        g.items.push(a);
    });

    return (
        <div className="mt-2 rounded-lg border border-gray-200 bg-white overflow-hidden">
            {groups.map((g) => (
                <div key={g.name}>
                    <div className="px-3 py-1 bg-gray-50 border-t border-gray-100 first:border-t-0">
                        <p className="text-[9px] font-bold uppercase tracking-[0.15em] text-gray-400">{g.name}</p>
                    </div>
                    {g.items.map((a) => <ActivityRow key={a.key} leadId={leadId} activity={a} />)}
                </div>
            ))}
        </div>
    );
}

/**
 * Stages tab — dated timeline of every immigration stage, with the CURRENT
 * stage expanding into the compact INZ sub-status tracker (Visa Lodged → outcome).
 */
export default function StagesTab({ lead = {}, visaProgress = { tracked: false, activities: [] }, stageTimeline = [] }) {
    const entries = [...(stageTimeline ?? [])].reverse(); // newest first; [0] is current
    const currentStage = visaProgress.stage ?? lead.immigration_stage;

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-4">
            <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2 mb-3">
                <MapPin size={14} className="text-[#009688]" /> Case stages
            </h3>

            {entries.length === 0 && !visaProgress.tracked && (
                <p className="text-sm text-gray-400">No stage changes recorded yet.</p>
            )}

            <ol className="relative border-l-2 border-gray-100 ml-1.5 space-y-3">
                {entries.map((e, i) => {
                    const isCurrent = i === 0 && e.stage === currentStage;
                    return (
                        <li key={i} className="ml-4 relative">
                            <span className={`absolute -left-[23px] top-1 w-2.5 h-2.5 rounded-full border-2 border-white ${isCurrent ? "bg-[#009688]" : "bg-gray-300"}`} />
                            <div className="flex flex-wrap items-center gap-2">
                                <p className={`text-[13px] font-semibold ${isCurrent ? "text-[#009688]" : "text-gray-900"}`}>{e.stage ?? "—"}</p>
                                {isCurrent && <span className="text-[9px] font-bold uppercase tracking-wide text-[#009688] bg-[#009688]/10 px-1.5 py-0.5 rounded-full">Current</span>}
                            </div>
                            <p className="text-[10px] text-gray-400 mt-0.5">{fmt(e.at)}{e.by_name ? ` · ${e.by_name}` : ""}</p>

                            {isCurrent && visaProgress.tracked && (
                                <SubStatusTracker leadId={lead.id} visaProgress={visaProgress} />
                            )}
                        </li>
                    );
                })}
            </ol>

            {entries.length === 0 && visaProgress.tracked && (
                <SubStatusTracker leadId={lead.id} visaProgress={visaProgress} />
            )}
        </div>
    );
}
