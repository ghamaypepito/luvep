import { Head, Link } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { CalendarDays, Search, Download } from "lucide-react";

// Intake Monitoring — a column board grouped by intake month. Each student is
// filed into the month they're set to start; anyone without a parseable date
// lands in "Unscheduled". Read-only: cards open the lead.

// Intake-only presentation order. Never write these aliases back to a client.
const CATEGORIES = [
    { key: "Started Course", label: "Started Course", dot: "bg-[#14532d]" },
    { key: "Approved Visa", label: "Approved Visa", dot: "bg-green-500" },
    { key: "Visa Lodged", label: "Visa Lodged", dot: "bg-purple-500" },
    { key: "Endorsed to Immigration", label: "Endorsed to Immigration", dot: "bg-indigo-500" },
    { key: "Unconditional Offer", label: "Unconditional Offer", dot: "bg-emerald-500" },
    { key: "Conditional Offer", label: "Conditional Offer", dot: "bg-amber-500" },
    { key: "Declined Visa", label: "Declined Visa", dot: "bg-rose-500" },
    { key: "other", label: "Other stages", dot: "bg-gray-400" },
];
const TRACKED_STAGES = ["Started Course", "Approved Visa", "Visa Lodged", "Unconditional Offer"];
const canonicalStage = value => {
    const stage = String(value || "").trim();
    if (/^(visa approved|approved visa)$/i.test(stage)) return "Approved Visa";
    if (/^declin(ed|e) visa$/i.test(stage)) return "Declined Visa";
    return CATEGORIES.find(item => item.key !== "other" && item.key.toLowerCase() === stage.toLowerCase())?.key || stage;
};
const stageRank = value => {
    const rank = CATEGORIES.findIndex(item => item.key === canonicalStage(value));
    return rank < 0 ? CATEGORIES.length - 1 : rank;
};
const compareRows = (a, b) => stageRank(a.status) - stageRank(b.status)
    || (stageRank(a.status) === CATEGORIES.length - 1 ? canonicalStage(a.status).localeCompare(canonicalStage(b.status)) : 0)
    || (a.name || "").localeCompare(b.name || "") || Number(a.id) - Number(b.id);

const TRACKS = [
    { key: "all", label: "All" },
    { key: "education", label: "Education" },
    { key: "english", label: "English" },
    { key: "immigration", label: "Immigration" },
];

// Stage chip palette — mirrors the Students page so a stage reads the same.
const STATUS_STYLES = {
    "Endorsed to School": "bg-sky-100 text-sky-800 border-sky-200",
    "School Enrolment": "bg-sky-100 text-sky-800 border-sky-200",
    "School Enrollment": "bg-green-100 text-green-800 border-green-200",
    "Conditional Offer": "bg-amber-100 text-amber-800 border-amber-200",
    "Unconditional Offer": "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Endorsed to Immigration": "bg-indigo-100 text-indigo-800 border-indigo-200",
    Endorsed: "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Visa Lodged": "bg-purple-100 text-purple-800 border-purple-200",
    "Approved in Principle": "bg-teal-100 text-teal-800 border-teal-200",
    "Request for Information": "bg-orange-100 text-orange-800 border-orange-200",
    "For Relodgement": "bg-rose-100 text-rose-700 border-rose-200",
    "Approved Visa": "bg-green-100 text-green-800 border-green-200",
    "Visa Approved": "bg-green-100 text-green-800 border-green-200",
    "Declined Visa": "bg-rose-100 text-rose-800 border-rose-200",
    "Decline Visa": "bg-rose-100 text-rose-800 border-rose-200",
    "Started Course": "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Proposal Sent": "bg-sky-100 text-sky-800 border-sky-200",
    "Pre-Screening Done": "bg-cyan-100 text-cyan-800 border-cyan-200",
    "English Pro": "bg-blue-100 text-blue-700 border-blue-200",
};
const statusCls = (s) => STATUS_STYLES[s] || "bg-gray-100 text-gray-600 border-gray-200";

const initials = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase() || "—";

// month_key is "YYYY-MM" (or "9999-99" for unscheduled). Relative label vs now.
const monthMeta = (key) => {
    if (! key || key === "9999-99") return { year: null, rel: "no date", past: false };
    const [y, m] = key.split("-").map(Number);
    const now = new Date();
    const diff = (y - now.getFullYear()) * 12 + (m - 1 - now.getMonth());
    let rel;
    if (diff === 0) rel = "this month";
    else if (diff > 0) rel = `in ${diff} month${diff === 1 ? "" : "s"}`;
    else rel = `started ${-diff} month${diff === -1 ? "" : "s"} ago`;
    return { year: y, rel, past: diff < 0 };
};

export default function IntakeMonitoring({ students = [] }) {
    const [track, setTrack] = useState("all");
    const [groupBy, setGroupBy] = useState("stage"); // stage | school
    const [search, setSearch] = useState("");

    const trackCounts = useMemo(() => {
        const c = { all: students.length, education: 0, english: 0, immigration: 0 };
        students.forEach((s) => { c[s.track] = (c[s.track] || 0) + 1; });
        return c;
    }, [students]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return students.filter((s) => {
            if (track !== "all" && s.track !== track) return false;
            if (q && ! `${s.name} ${s.school || ""} ${s.lead_id || ""}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [students, track, search]);

    // Build columns: Unscheduled first, then chronological months.
    const columns = useMemo(() => {
        const map = new Map();
        filtered.forEach((s) => {
            const key = s.month_key || "9999-99";
            if (! map.has(key)) map.set(key, { key, label: s.month_label || "Unscheduled", rows: [] });
            map.get(key).rows.push(s);
        });
        const cols = [...map.values()].sort((a, b) => a.key.localeCompare(b.key));
        cols.forEach(col => col.rows.sort(compareRows));
        // Unscheduled to the FRONT.
        const un = cols.filter((c) => c.key === "9999-99");
        const rest = cols.filter((c) => c.key !== "9999-99");
        return [...un, ...rest];
    }, [filtered]);

    const scheduledMonths = columns.filter((c) => c.key !== "9999-99").length;
    const unscheduled = filtered.filter((s) => (s.month_key || "9999-99") === "9999-99");
    const unreadable = unscheduled.filter((s) => s.intake).length; // has text but unparseable
    const empty = unscheduled.length - unreadable;

    return (
        <div className="pb-10">
            <Head title="Intake Monitoring — Education" />

            {/* Header */}
            <div className="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div className="flex items-start gap-3">
                    <div className="w-9 h-9 rounded-xl bg-[#14532d]/10 text-[#14532d] flex items-center justify-center flex-shrink-0">
                        <CalendarDays size={18} />
                    </div>
                    <div>
                        <h1 className="text-lg font-bold text-gray-900 leading-tight">Intake monitoring</h1>
                        <p className="text-[13px] text-gray-500 mt-0.5">
                            {filtered.length} student{filtered.length === 1 ? "" : "s"} across {scheduledMonths} intake month{scheduledMonths === 1 ? "" : "s"}
                            {unscheduled.length > 0 && <span> · {unscheduled.length} unscheduled</span>}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <div className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 bg-white w-56">
                        <Search size={14} className="text-gray-400 flex-shrink-0" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name or school" className="flex-1 min-w-0 outline-none text-[13px] bg-transparent placeholder:text-gray-400" />
                    </div>
                    <ExportButton rows={columns.flatMap(col => col.rows)} />
                    <Link href="/portal/education/students" className="text-sm font-semibold text-green-700 hover:text-green-900 whitespace-nowrap">All students →</Link>
                </div>
            </div>

            {/* Toolbar: track filter + group toggle + legend */}
            <div className="flex items-center justify-between gap-4 flex-wrap mb-3">
                <div className="flex items-center gap-4 flex-wrap">
                    <div className="flex items-center gap-1.5">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mr-1">Track</span>
                        {TRACKS.map((t) => (
                            <button key={t.key} type="button" onClick={() => setTrack(t.key)}
                                className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border transition-colors ${
                                    track === t.key ? "bg-[#14532d] text-white border-[#14532d]" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}>
                                {t.label}<span className={`text-[10px] ${track === t.key ? "opacity-80" : "text-gray-400"}`}>· {trackCounts[t.key] || 0}</span>
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mr-1">Group cards by</span>
                        {["stage", "school"].map((g) => (
                            <button key={g} type="button" onClick={() => setGroupBy(g)}
                                className={`px-2.5 py-1 rounded-full text-[11px] font-semibold capitalize border transition-colors ${
                                    groupBy === g ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}>
                                {g}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="flex items-center gap-3 flex-wrap">
                    {CATEGORIES.map((c) => (
                        <span key={c.key} className="inline-flex items-center gap-1 text-[10px] text-gray-500">
                            <span className={`w-2 h-2 rounded-full ${c.dot}`} /> {c.label}
                        </span>
                    ))}
                </div>
            </div>

            {/* Unschedulable banner */}
            {unscheduled.length > 0 && (
                <div className="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-[12px] text-amber-800">
                    <span className="font-semibold">{unscheduled.length} student{unscheduled.length === 1 ? "" : "s"} can't be filed to a month</span>
                    {" — "}
                    {unreadable > 0 && <>{unreadable} with text a year can't be read from (e.g. "November")</>}
                    {unreadable > 0 && empty > 0 && " and "}
                    {empty > 0 && <>{empty} with an empty intake field</>}
                    . Set a full date on the study plan and they move into the right column.
                </div>
            )}

            {/* Board */}
            {columns.length === 0 ? (
                <div className="rounded-2xl border border-gray-100 bg-white p-16 text-center text-gray-400 text-sm">No students match.</div>
            ) : (
                <div className="flex gap-4 overflow-x-auto pb-4">
                    {columns.map((col) => (
                        <IntakeColumn key={col.key} col={col} groupBy={groupBy} />
                    ))}
                </div>
            )}

            <p className="mt-4 text-[11px] text-gray-400">
                Read-only. Intake comes from each study plan's preferred intake field; cards open the lead. Monthly ratios count students currently in Started Course, Approved Visa, Visa Lodged or Unconditional Offer out of all matching students shown in that month. Search and Track filters update both numbers. Other stages remain visible; Unscheduled is excluded from monthly ratios. · Showing {filtered.length} of {students.length} students
            </p>
        </div>
    );
}

function IntakeColumn({ col, groupBy }) {
    const meta = monthMeta(col.key);
    const unscheduled = col.key === "9999-99";

    // Group by the requested individual stages, or retain alphabetical schools.
    const groups = useMemo(() => {
        if (groupBy === "school") {
            const m = new Map();
            col.rows.forEach((r) => {
                const k = r.school || "No school";
                if (! m.has(k)) m.set(k, { key: k, label: k, rows: [] });
                m.get(k).rows.push(r);
            });
            return [...m.values()].sort((a, b) => a.label.localeCompare(b.label));
        }
        const m = new Map();
        col.rows.forEach((r) => {
            const stage = canonicalStage(r.status);
            const k = CATEGORIES.some(c => c.key === stage) ? stage : "other";
            if (! m.has(k)) m.set(k, { key: k, rows: [] });
            m.get(k).rows.push(r);
        });
        return CATEGORIES.filter((c) => m.has(c.key)).map((c) => ({ key: c.key, label: c.label, dot: c.dot, rows: m.get(c.key).rows }));
    }, [col.rows, groupBy]);

    const stageCounts = TRACKED_STAGES.map(stage => ({ stage, count: col.rows.filter(row => canonicalStage(row.status) === stage).length }));
    const trackedCount = stageCounts.reduce((total, item) => total + item.count, 0);

    // School summary footer.
    const schoolFooter = useMemo(() => {
        const counts = {};
        col.rows.forEach((r) => { if (r.school) counts[r.school] = (counts[r.school] || 0) + 1; });
        const entries = Object.entries(counts).sort((a, b) => b[1] - a[1]);
        if (! entries.length) return null;
        const [topName, topCount] = entries[0];
        const others = entries.length - 1;
        return `${topName} · ${topCount} of ${col.rows.length}${others > 0 ? ` · ${others} other school${others === 1 ? "" : "s"}` : ""}`;
    }, [col.rows]);

    return (
        <section aria-label={col.label + " intake"} className={`w-[280px] flex-shrink-0 rounded-2xl border ${unscheduled ? "border-amber-200 border-dashed bg-amber-50/30" : "border-gray-100 bg-white"} shadow-sm flex flex-col`}>
            {/* Column header */}
            <div className="px-4 pt-3 pb-2 border-b border-gray-100">
                <div className="flex items-center justify-between">
                    <span className={`text-[9px] font-bold uppercase tracking-wider ${unscheduled ? "text-amber-600" : meta.past ? "text-gray-400" : "text-[#14532d]"}`}>
                        {unscheduled ? "Fix first" : meta.past ? "Past intake" : "Intake"}
                    </span>
                    <span className="text-[10px] text-gray-400">{meta.rel}</span>
                </div>
                <div className="flex items-center justify-between mt-0.5">
                    <span className="text-sm font-bold text-gray-900">{col.label}</span>
                    <span className="text-sm font-bold text-gray-900 tabular-nums">{col.rows.length}</span>
                </div>
                <div className={`mt-2 h-1 rounded-full ${unscheduled ? "bg-amber-300" : meta.past ? "bg-gray-300" : "bg-[#14532d]"}`} />
                {!unscheduled && <div aria-label={"Monthly stage summary for " + col.label} className="mt-2 text-[11px]">
                    <p className="font-semibold text-[#14532d]">{trackedCount}/{col.rows.length} in the four tracked stages</p>
                    <dl className="mt-1 grid grid-cols-2 gap-x-2 gap-y-1 text-[10px] text-gray-500">
                        {stageCounts.map(({ stage, count }) => <div key={stage} className="flex justify-between gap-1"><dt>{stage}</dt><dd className="font-semibold tabular-nums">{count}</dd></div>)}
                    </dl>
                </div>}
            </div>

            {/* Groups + cards */}
            <div className="flex-1 overflow-y-auto max-h-[62vh] px-3 py-3 space-y-3">
                {groups.map((g) => (
                    <div key={g.key} role="group" aria-label={g.label + " intake entries"}>
                        <div className="flex items-center gap-1.5 mb-1.5">
                            {g.dot && <span className={`w-1.5 h-1.5 rounded-full ${g.dot}`} />}
                            <span className="text-[9px] font-bold uppercase tracking-wider text-gray-400 truncate">{g.label}</span>
                            <span className="text-[9px] font-bold text-gray-300">{g.rows.length}</span>
                        </div>
                        <div className="space-y-2">
                            {g.rows.map((r) => <IntakeCard key={r.id} r={r} />)}
                        </div>
                    </div>
                ))}
                {col.rows.length === 0 && <p className="text-[11px] text-gray-400 italic px-1">Empty.</p>}
            </div>

            {/* Footer */}
            <div className="px-3 py-2.5 border-t border-gray-100 text-[10px] text-gray-400">
                {unscheduled ? "None of these can be planned for until a date is set" : (schoolFooter || "—")}
            </div>
        </section>
    );
}

function IntakeCard({ r }) {
    return (
        <Link href={`/portal/education/leads/${r.id}`} className="block rounded-xl border border-gray-100 bg-white px-3 py-2.5 hover:border-gray-300 hover:shadow-sm transition-all">
            <div className="flex items-center gap-2">
                <span className="w-7 h-7 rounded-lg inline-flex items-center justify-center text-white text-[10px] font-bold bg-[#14532d] flex-shrink-0 overflow-hidden">
                    {r.avatar_url ? <img src={r.avatar_url} alt={r.name} className="w-full h-full object-cover" /> : initials(r.name)}
                </span>
                <div className="min-w-0">
                    <div className="text-[12px] font-semibold text-gray-900 truncate">{r.name}</div>
                    <div className="text-[9px] text-gray-400 truncate">
                        <span className="font-mono">{r.lead_id}</span>{r.location ? ` · ${r.location}` : ""}
                    </div>
                </div>
            </div>
            {r.program && <div className="text-[11px] text-gray-700 mt-1.5 leading-snug line-clamp-2">{r.program}</div>}
            {r.school && <div className="text-[10px] text-gray-400 truncate">{r.school}</div>}
            <div className="flex items-center justify-between gap-2 mt-1.5">
                {r.status ? (
                    <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold border ${statusCls(r.status)}`}>{r.status}</span>
                ) : <span />}
                {r.intake && <span className="text-[9px] text-gray-400 whitespace-nowrap">{r.intake}</span>}
            </div>
        </Link>
    );
}

function ExportButton({ rows }) {
    const onClick = () => {
        const headers = ["Name", "Reference", "Location", "Status", "Track", "Intake", "School", "Program"];
        const esc = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
        const lines = [headers.map(esc).join(",")];
        rows.forEach((r) => lines.push([r.name, r.lead_id, r.location, r.status, r.track, r.intake, r.school, r.program].map(esc).join(",")));
        const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8;" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url; a.download = `intake-monitoring-${new Date().toISOString().slice(0, 10)}.csv`; a.click();
        URL.revokeObjectURL(url);
    };
    return (
        <button type="button" onClick={onClick} className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50 whitespace-nowrap">
            <Download size={15} /> Export CSV
        </button>
    );
}
