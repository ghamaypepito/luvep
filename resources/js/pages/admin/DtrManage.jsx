import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Clock, ArrowLeft, Settings, CheckCircle2, CircleDashed, X, Save, Search, Download, History, ArrowRight, Sparkles, Archive, RotateCcw, CheckSquare, Square } from "lucide-react";

// Known teams → their timezone. Picking a team auto-fills the tz so PH/NZ
// staff can't mismatch them. Add teams here as they're onboarded.
const TEAM_TZ = { "Philippines": "Asia/Manila", "New Zealand": "Pacific/Auckland" };
const KNOWN_TEAMS = Object.keys(TEAM_TZ);

const ROLE_LABEL = {
    super_admin: "Super Admin", admin: "Admin", sales: "Sales", education: "Education",
    english: "English", immigration: "Immigration", accommodation: "Accommodation",
    finance: "Finance", agent: "Affiliate Partner", immigration_manager: "Immigration Mgr",
    immigration_adviser: "Immigration Adviser",
};
const roleName = (r) => ROLE_LABEL[r] || r;

const to12h = (hhmm) => {
    if (!hhmm) return "—";
    const [h, m] = String(hhmm).split(":").map(Number);
    const ap = h >= 12 ? "PM" : "AM";
    const hh = ((h + 11) % 12) + 1;
    return `${hh}:${String(m).padStart(2, "0")} ${ap}`;
};

// Days of the week, Monday first, for the per-day schedule circles.
const WEEK_DAYS = [
    { key: "mon", label: "M", title: "Monday" },
    { key: "tue", label: "T", title: "Tuesday" },
    { key: "wed", label: "W", title: "Wednesday" },
    { key: "thu", label: "T", title: "Thursday" },
    { key: "fri", label: "F", title: "Friday" },
    { key: "sat", label: "S", title: "Saturday" },
    { key: "sun", label: "S", title: "Sunday" },
];

// Build the two editable schedule groups from a saved setting. With no
// weekly_schedule, seed the current single schedule as Weekdays (Mon–Fri) +
// Weekends (Sat–Sun) at the same time — so existing setups open unchanged.
function groupsFromSetting(setting) {
    const inD = setting?.sched_in || "09:00";
    const outD = setting?.sched_out || "18:00";
    const ws = setting?.weekly_schedule;
    if (!ws) {
        return [
            { days: new Set(["mon", "tue", "wed", "thu", "fri"]), in: inD, out: outD },
            { days: new Set(["sat", "sun"]), in: inD, out: outD },
        ];
    }
    // Group the "on" days by their (in,out) pair — our UI only ever makes two.
    const pairs = [];
    for (const { key } of WEEK_DAYS) {
        const d = ws[key];
        if (!d || !d.on) continue;
        const pk = `${d.in}|${d.out}`;
        let g = pairs.find((p) => p.pk === pk);
        if (!g) { g = { pk, in: d.in || inD, out: d.out || outD, days: new Set() }; pairs.push(g); }
        g.days.add(key);
    }
    const at = (i) => (pairs[i] ? { days: pairs[i].days, in: pairs[i].in, out: pairs[i].out } : { days: new Set(), in: inD, out: outD });
    return [at(0), at(1)];
}

// Flatten the two groups into the mon..sun map the server stores. A day in no
// group is a day off (on:false).
function groupsToWeekly(groups) {
    const map = {};
    for (const { key } of WEEK_DAYS) map[key] = { on: false, in: null, out: null };
    groups.forEach((g) => g.days.forEach((d) => { map[d] = { on: true, in: g.in, out: g.out }; }));
    return map;
}

// True when a saved weekly_schedule differs from a plain all-week single
// schedule — i.e. there's a day off or a second distinct time set. Drives the
// "per-day" hint in the staff table.
function weeklyIsCustom(setting) {
    const ws = setting?.weekly_schedule;
    if (!ws) return false;
    const pairs = new Set();
    let anyOff = false;
    for (const { key } of WEEK_DAYS) {
        const d = ws[key];
        if (!d || !d.on) { anyOff = true; continue; }
        pairs.add(`${d.in}|${d.out}`);
    }
    return anyOff || pairs.size > 1;
}

// Module-level so the inputs keep a stable identity across renders (defining
// this inside the form component remounts inputs on every keystroke).
function Field({ label, hint, children }) {
    return (
        <label className="block">
            <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">{label}</span>
            {children}
            {hint && <span className="block text-[11px] text-gray-400 mt-1">{hint}</span>}
        </label>
    );
}

// Setup modal for one staffer — create or edit their yellow cells.
function SetupModal({ person, onClose }) {
    const setting = person.setting;
    const tzOptions = useMemo(() => {
        try { return Intl.supportedValuesOf("timeZone"); }
        catch { return ["Asia/Manila", "Pacific/Auckland", "Australia/Sydney", "UTC"]; }
    }, []);
    const [f, setF] = useState({
        label: setting?.label || `${person.name} · DTR`,
        position: setting?.position || "",
        employment_type: setting?.employment_type || "full_time",
        team: setting?.team || "",
        timezone: setting?.timezone || "Asia/Manila",
        schedule_type: setting?.schedule_type || "fixed",
        sched_in: setting?.sched_in || "09:00",
        sched_out: setting?.sched_out || "18:00",
        break_hours: setting?.break_hours ?? 1,
        reports_to: setting?.reports_to || "",
        std_hours: setting?.std_hours ?? 8,
        grace_mins: setting?.grace_mins ?? 10,
        break_after: setting?.break_after ?? 6,
    });
    const isFlexi = f.schedule_type === "flexi";
    const [saving, setSaving] = useState(false);
    const [otherTeam, setOtherTeam] = useState(!!setting?.team && !KNOWN_TEAMS.includes(setting.team));
    const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target.value }));

    // Two editable schedule groups (Weekdays / Weekends). A day belongs to at
    // most one group; a day in neither is a day off.
    const [groups, setGroups] = useState(() => groupsFromSetting(setting));
    const toggleDay = (gi, dayKey) => setGroups((prev) => {
        const next = prev.map((g) => ({ ...g, days: new Set(g.days) }));
        const already = next[gi].days.has(dayKey);
        next.forEach((g) => g.days.delete(dayKey)); // a day lives in one group only
        if (!already) next[gi].days.add(dayKey);      // click again = remove (day off)
        return next;
    });
    const setGroupTime = (gi, field, value) => setGroups((prev) => prev.map((g, i) => (i === gi ? { ...g, [field]: value } : g)));
    const offDays = WEEK_DAYS.filter((d) => !groups.some((g) => g.days.has(d.key)));

    const onTeamChange = (e) => {
        const v = e.target.value;
        if (v === "__other") { setOtherTeam(true); setF((p) => ({ ...p, team: "" })); return; }
        setOtherTeam(false);
        setF((p) => ({ ...p, team: v, timezone: TEAM_TZ[v] || p.timezone }));
    };

    const save = () => {
        setSaving(true);
        // Fixed schedules carry the per-day map; flexi ignores it. Keep the flat
        // sched_in/out in sync with the primary (first non-empty) group so legacy
        // displays still show the main schedule.
        const weekly = isFlexi ? null : groupsToWeekly(groups);
        const primary = groups.find((g) => g.days.size > 0);
        const flat = (!isFlexi && primary) ? { sched_in: primary.in, sched_out: primary.out } : {};
        router.post("/dtr/setup", { ...f, ...flat, weekly_schedule: weekly, user_id: person.id }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
            onSuccess: () => onClose(),
        });
    };

    const input = "w-full px-3 py-2 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-[#436235]/30 focus:border-[#436235]";

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 overflow-y-auto bg-black/40 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-3xl bg-white rounded-2xl shadow-2xl overflow-hidden my-8" onClick={(e) => e.stopPropagation()}>
                <div className="px-6 py-5 border-b border-gray-100 bg-gradient-to-br from-gray-50 to-white flex items-start justify-between">
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400 mb-1">{setting ? "Edit DTR setup" : "New DTR setup"}</p>
                        <h2 className="text-xl font-bold text-gray-900">{person.name}</h2>
                        <p className="text-sm text-gray-500 mt-0.5">{roleName(person.role)} · {person.email}</p>
                    </div>
                    <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition-colors"><X size={18} /></button>
                </div>

                <div className="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <Field label="DTR name"><input className={input} value={f.label} onChange={set("label")} placeholder="e.g. Angelika · DTR" /></Field>
                    <Field label="Position"><input className={input} value={f.position} onChange={set("position")} /></Field>
                    <Field label="Employment" hint="Part-timers just carry a lower std hrs / day">
                        <select className={input} value={f.employment_type} onChange={set("employment_type")}>
                            <option value="full_time">Full-time</option>
                            <option value="part_time">Part-time</option>
                        </select>
                    </Field>
                    <Field label="Team" hint="Sets their holidays & default timezone">
                        <select className={input} value={otherTeam ? "__other" : f.team} onChange={onTeamChange}>
                            <option value="">Select team…</option>
                            {KNOWN_TEAMS.map((t) => <option key={t} value={t}>{t}</option>)}
                            <option value="__other">Other…</option>
                        </select>
                        {otherTeam && (
                            <input className={`${input} mt-2`} value={f.team} onChange={set("team")} placeholder="Custom team name" />
                        )}
                    </Field>

                    <Field label="Time zone">
                        <select className={input} value={f.timezone} onChange={set("timezone")}>
                            {tzOptions.map((t) => <option key={t} value={t}>{t.replace(/_/g, " ")}</option>)}
                        </select>
                    </Field>
                    <Field label="Schedule type" hint={isFlexi ? "Any hours — never marked late" : "Follows the clock-in / out times"}>
                        <select className={input} value={f.schedule_type} onChange={set("schedule_type")}>
                            <option value="fixed">Fixed — follows a set time</option>
                            <option value="flexi">Flexi — clock in/out anytime</option>
                        </select>
                    </Field>

                    {isFlexi ? (
                        <div className="md:col-span-2 lg:col-span-3 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3 text-[12px] text-indigo-800 leading-relaxed">
                            <span className="font-bold">Flexi time.</span> No fixed clock-in — {person.name.split(" ")[0]} can time in and out whenever, and won't be flagged late. They work toward the <span className="font-bold">Std hrs / day</span> target below, but it isn't strictly enforced.
                        </div>
                    ) : (
                        <div className="md:col-span-2 lg:col-span-3 rounded-xl border border-gray-200 p-4">
                            <div className="flex items-baseline justify-between mb-1">
                                <span className="text-[11px] font-bold uppercase tracking-wider text-gray-600">Weekly schedule</span>
                                <span className="text-[11px] text-gray-400">Tap days to assign them · a day in neither group is a day off</span>
                            </div>
                            <div className="space-y-3 mt-3">
                                {groups.map((g, gi) => (
                                    <div key={gi} className="rounded-lg bg-gray-50 border border-gray-100 p-3">
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <span className="text-xs font-bold text-gray-600 w-20 shrink-0">{gi === 0 ? "Weekdays" : "Weekends"}</span>
                                            <div className="flex items-center gap-1.5">
                                                {WEEK_DAYS.map((d) => {
                                                    const on = g.days.has(d.key);
                                                    return (
                                                        <button
                                                            key={d.key}
                                                            type="button"
                                                            title={d.title}
                                                            onClick={() => toggleDay(gi, d.key)}
                                                            className={`w-8 h-8 rounded-full text-xs font-bold border transition-colors ${on ? "bg-[#436235] text-white border-[#436235]" : "bg-white text-gray-400 border-gray-200 hover:border-[#436235] hover:text-[#436235]"}`}
                                                        >
                                                            {d.label}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                            <div className="flex items-center gap-1.5 ml-auto">
                                                <input type="time" value={g.in} onChange={(e) => setGroupTime(gi, "in", e.target.value)} className="px-2 py-1.5 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-[#436235]/30" />
                                                <span className="text-gray-400 text-xs">to</span>
                                                <input type="time" value={g.out} onChange={(e) => setGroupTime(gi, "out", e.target.value)} className="px-2 py-1.5 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-[#436235]/30" />
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            {offDays.length > 0 && (
                                <p className="text-[11px] text-gray-400 mt-2">Day off: <span className="font-semibold text-gray-500">{offDays.map((d) => d.title).join(", ")}</span></p>
                            )}
                        </div>
                    )}

                    <Field label="Break (hrs)"><input type="number" step="0.25" min="0" className={input} value={f.break_hours} onChange={set("break_hours")} /></Field>
                    <Field label="Break after (hrs)" hint="Break is deducted once they work past this"><input type="number" step="0.5" min="0" className={input} value={f.break_after} onChange={set("break_after")} /></Field>
                    <Field label="Std hrs / day" hint={isFlexi ? "Daily target (not enforced for flexi)" : undefined}><input type="number" step="0.5" min="0" className={input} value={f.std_hours} onChange={set("std_hours")} /></Field>

                    {!isFlexi && (
                        <Field label="Grace (mins)" hint="Late is counted after this"><input type="number" step="1" min="0" className={input} value={f.grace_mins} onChange={set("grace_mins")} /></Field>
                    )}
                    <Field label="Reports to"><input className={input} value={f.reports_to} onChange={set("reports_to")} /></Field>
                </div>

                <div className="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50/50">
                    <button onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-gray-600 rounded-xl hover:bg-gray-100 transition-colors">Cancel</button>
                    <button onClick={save} disabled={saving} className="inline-flex items-center gap-2 px-5 py-2.5 bg-[#436235] text-white text-sm font-bold rounded-xl hover:bg-[#375029] disabled:opacity-60 transition-colors">
                        <Save size={15} /> {saving ? "Saving…" : (setting ? "Save changes" : "Create DTR")}
                    </button>
                </div>
            </div>
        </div>
    );
}

// Pick a date, then download that staffer's daily-report PDF.
function ReportModal({ person, onClose }) {
    const [date, setDate] = useState(() => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    });
    const download = () => {
        if (!date) return;
        window.open(`/dtr/report?date=${date}&user=${person.id}`, "_blank");
        onClose();
    };
    const input = "w-full px-3 py-2 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-[#436235]/30 focus:border-[#436235]";
    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 overflow-y-auto bg-black/40 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden my-16" onClick={(e) => e.stopPropagation()}>
                <div className="px-6 py-4 border-b border-gray-100 bg-gradient-to-br from-gray-50 to-white flex items-start justify-between">
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.24em] text-gray-400 mb-1">Generate daily report</p>
                        <h2 className="text-lg font-bold text-gray-900">{person.name}</h2>
                    </div>
                    <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"><X size={18} /></button>
                </div>
                <div className="p-6">
                    <label className="block">
                        <span className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Report date</span>
                        <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={input} />
                    </label>
                    <p className="text-[11px] text-gray-400 mt-2">Downloads a PDF of {person.name.split(" ")[0]}'s time, tasks and remarks for the selected day.</p>
                </div>
                <div className="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50/50">
                    <button onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-gray-600 rounded-xl hover:bg-gray-100 transition-colors">Cancel</button>
                    <button onClick={download} disabled={!date} className="inline-flex items-center gap-2 px-5 py-2.5 bg-[#436235] text-white text-sm font-bold rounded-xl hover:bg-[#375029] disabled:opacity-60 transition-colors">
                        <Download size={15} /> Download PDF
                    </button>
                </div>
            </div>
        </div>
    );
}

// Audit trail for one staffer's DTR setup — every change to their schedule /
// timezone / hours, with before → after and who made it.
function HistoryModal({ person, onClose }) {
    const [state, setState] = useState({ loading: true, history: [] });

    React.useEffect(() => {
        let alive = true;
        fetch(`/admin/dtr/history/${person.id}`, { headers: { Accept: "application/json" } })
            .then((r) => r.json())
            .then((d) => { if (alive) setState({ loading: false, history: d.history || [] }); })
            .catch(() => { if (alive) setState({ loading: false, history: [] }); });
        return () => { alive = false; };
    }, [person.id]);

    const val = (v) => (v === null || v === undefined || v === "" ? <span className="text-gray-300 italic">empty</span> : <span className="font-semibold text-gray-800">{v}</span>);

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 overflow-y-auto bg-black/40 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden my-8" onClick={(e) => e.stopPropagation()}>
                <div className="px-6 py-4 border-b border-gray-100 bg-gradient-to-br from-gray-50 to-white flex items-start justify-between">
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.24em] text-gray-400 mb-1">DTR change history</p>
                        <h2 className="text-lg font-bold text-gray-900">{person.name}</h2>
                        <p className="text-sm text-gray-500 mt-0.5">Every change to their schedule &amp; setup, for audit.</p>
                    </div>
                    <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"><X size={18} /></button>
                </div>
                <div className="p-6 max-h-[70vh] overflow-y-auto">
                    {state.loading ? (
                        <div className="py-12 text-center text-sm text-gray-400">Loading history…</div>
                    ) : state.history.length === 0 ? (
                        <div className="py-12 text-center text-sm text-gray-400">No changes recorded yet.</div>
                    ) : (
                        <ol className="relative border-l-2 border-gray-100 ml-2 space-y-6">
                            {state.history.map((h) => (
                                <li key={h.id} className="ml-6">
                                    <span className={`absolute -left-[9px] flex items-center justify-center w-4 h-4 rounded-full ring-4 ring-white ${h.action === "created" ? "bg-[#436235]" : "bg-amber-400"}`} />
                                    <div className="flex flex-wrap items-center gap-2 mb-2">
                                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide ${h.action === "created" ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"}`}>
                                            {h.action === "created" ? <Sparkles size={10} /> : <History size={10} />} {h.action}
                                        </span>
                                        <span className="text-xs font-semibold text-gray-700">{h.by}</span>
                                        <span className="text-[11px] text-gray-400">· {h.at}</span>
                                    </div>
                                    <div className="rounded-xl border border-gray-100 bg-gray-50/60 divide-y divide-gray-100 overflow-hidden">
                                        {h.changes.map((c, i) => (
                                            <div key={i} className="px-3 py-2 flex flex-wrap items-center gap-2 text-xs">
                                                <span className="min-w-[110px] font-bold text-gray-500">{c.field}</span>
                                                {h.action === "created" ? (
                                                    val(c.to)
                                                ) : (
                                                    <span className="inline-flex items-center gap-2">
                                                        <span className="line-through text-gray-400">{c.from ?? "empty"}</span>
                                                        <ArrowRight size={12} className="text-gray-300" />
                                                        {val(c.to)}
                                                    </span>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function DtrManage({ staff = [] }) {
    const [editing, setEditing] = useState(null);
    const [reportFor, setReportFor] = useState(null);
    const [historyFor, setHistoryFor] = useState(null);
    const [q, setQ] = useState("");
    // Archive: default shows active staff; toggle to review/restore archived
    // (ex-employees). Selection drives the bulk Archive/Restore bar.
    const [showArchived, setShowArchived] = useState(false);
    const [selected, setSelected] = useState(() => new Set());
    const [busy, setBusy] = useState(false);

    const archivedCount = staff.filter((s) => s.archived).length;

    const filtered = useMemo(() => {
        const t = q.trim().toLowerCase();
        return staff.filter((s) => {
            if (showArchived ? !s.archived : s.archived) return false;
            if (!t) return true;
            return `${s.name} ${s.email} ${roleName(s.role)} ${s.setting?.team || ""}`.toLowerCase().includes(t);
        });
    }, [staff, q, showArchived]);

    const setUpCount = staff.filter((s) => s.setting?.is_complete && !s.archived).length;

    // Selection helpers — scoped to the rows currently visible.
    const toggleOne = (id) => setSelected((prev) => {
        const n = new Set(prev);
        n.has(id) ? n.delete(id) : n.add(id);
        return n;
    });
    const visibleIds = filtered.map((s) => s.id);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
    const toggleAll = () => setSelected((prev) => {
        const n = new Set(prev);
        if (allVisibleSelected) visibleIds.forEach((id) => n.delete(id));
        else visibleIds.forEach((id) => n.add(id));
        return n;
    });
    const clearSelection = () => setSelected(new Set());

    const doArchive = (restore) => {
        const ids = [...selected];
        if (ids.length === 0) return;
        if (!restore && !confirm(`Archive ${ids.length} staff member${ids.length === 1 ? "" : "s"}? They'll be hidden from the active list (their records are kept).`)) return;
        setBusy(true);
        router.post("/admin/dtr/archive", { user_ids: ids, restore }, {
            preserveScroll: true,
            onSuccess: () => clearSelection(),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="space-y-6 max-w-[1400px] mx-auto pb-12">
            <Head title="DTR — Setup Manager" />

            {/* Header */}
            <div className="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                <div>
                    <Link href="/admin/dtr" className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-gray-900 mb-2">
                        <ArrowLeft size={14} /> Back to my DTR
                    </Link>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">DTR Setup Manager</h1>
                    <p className="text-sm text-gray-500 mt-1">Set each staff member's schedule, timezone and hours. They clock in/out and log tasks against it — they don't configure their own.</p>
                </div>
                <div className="flex items-center gap-3">
                    <div className="bg-white border border-gray-100 shadow-sm rounded-2xl px-4 py-3 text-center">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Set up</p>
                        <p className="text-lg font-bold text-[#436235]">{setUpCount}<span className="text-gray-300"> / {staff.length}</span></p>
                    </div>
                    <button
                        onClick={() => { setShowArchived((v) => !v); clearSelection(); }}
                        className={`inline-flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-sm font-semibold border transition-colors ${showArchived ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}
                        title="Show archived staff"
                    >
                        <Archive size={15} /> {showArchived ? "Viewing archived" : `Archived${archivedCount ? ` (${archivedCount})` : ""}`}
                    </button>
                    <div className="relative">
                        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search staff…"
                            className="pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-[#436235]/30 focus:border-[#436235] w-56" />
                    </div>
                </div>
            </div>

            {/* Bulk action bar — appears once staff are selected. */}
            {selected.size > 0 && (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5">
                    <p className="text-sm font-semibold text-gray-700">{selected.size} selected</p>
                    <div className="flex items-center gap-2">
                        <button onClick={clearSelection} className="px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-500 hover:text-gray-900">Clear</button>
                        {showArchived ? (
                            <button onClick={() => doArchive(true)} disabled={busy} className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#436235] text-white hover:bg-[#375029] disabled:opacity-50">
                                <RotateCcw size={13} /> Restore selected
                            </button>
                        ) : (
                            <button onClick={() => doArchive(false)} disabled={busy} className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-gray-900 text-white hover:bg-black disabled:opacity-50">
                                <Archive size={13} /> Archive selected
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Staff table */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider">
                                <th className="pl-4 pr-1 py-3 w-8">
                                    <button onClick={toggleAll} title={allVisibleSelected ? "Deselect all" : "Select all"} className="align-middle text-white/80 hover:text-white">
                                        {allVisibleSelected ? <CheckSquare size={15} /> : <Square size={15} />}
                                    </button>
                                </th>
                                <th className="px-4 py-3">Staff</th>
                                <th className="px-3 py-3">Role</th>
                                <th className="px-3 py-3">Position</th>
                                <th className="px-3 py-3">Team</th>
                                <th className="px-3 py-3">Schedule</th>
                                <th className="px-3 py-3 text-right">Std / Grace</th>
                                <th className="px-3 py-3">Status</th>
                                <th className="px-3 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filtered.length === 0 ? (
                                <tr><td colSpan={9} className="px-4 py-12 text-center text-gray-400">{showArchived ? "No archived staff." : "No staff match your search."}</td></tr>
                            ) : filtered.map((s) => {
                                const set = s.setting;
                                const ready = set?.is_complete;
                                const checked = selected.has(s.id);
                                return (
                                    <tr key={s.id} className={`hover:bg-gray-50/60 ${checked ? "bg-[#436235]/[0.04]" : ""}`}>
                                        <td className="pl-4 pr-1 py-3">
                                            <button onClick={() => toggleOne(s.id)} className={checked ? "text-[#436235]" : "text-gray-300 hover:text-gray-500"}>
                                                {checked ? <CheckSquare size={16} /> : <Square size={16} />}
                                            </button>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-gray-800 flex items-center gap-1.5">
                                                {s.name}
                                                {set?.employment_type === "part_time" && <span className="px-1.5 py-0.5 rounded text-[9px] font-bold bg-sky-100 text-sky-700">PART-TIME</span>}
                                            </p>
                                            <p className="text-[11px] text-gray-400">{s.email}</p>
                                        </td>
                                        <td className="px-3 py-3 text-gray-500">{roleName(s.role)}</td>
                                        <td className="px-3 py-3 text-gray-500">{set?.position || "—"}</td>
                                        <td className="px-3 py-3 text-gray-500">{set?.team || "—"}</td>
                                        <td className="px-3 py-3 text-gray-500 whitespace-nowrap">
                                            {!set ? "—" : set.schedule_type === "flexi"
                                                ? <span className="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-100 text-indigo-700">Flexi</span>
                                                : <span className="inline-flex items-center gap-1.5">{to12h(set.sched_in)} – {to12h(set.sched_out)}{weeklyIsCustom(set) && <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-100 text-amber-700" title="Weekends/some days use a different schedule">PER-DAY</span>}</span>}
                                        </td>
                                        <td className="px-3 py-3 text-right tabular-nums text-gray-500">{set ? (set.schedule_type === "flexi" ? `${Number(set.std_hours).toFixed(1)}h` : `${Number(set.std_hours).toFixed(1)}h / ${set.grace_mins}m`) : "—"}</td>
                                        <td className="px-3 py-3">
                                            {ready ? (
                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-700"><CheckCircle2 size={11} /> Set up</span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-100 text-amber-700"><CircleDashed size={11} /> Not set up</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-right">
                                            <div className="inline-flex items-center gap-2 justify-end">
                                                {ready && (
                                                    <button onClick={() => setHistoryFor(s)} title="Change history" className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                                                        <History size={13} /> History
                                                    </button>
                                                )}
                                                {ready && (
                                                    <button onClick={() => setReportFor(s)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                                                        <Download size={13} /> Report
                                                    </button>
                                                )}
                                                <button onClick={() => setEditing(s)} className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ${ready ? "bg-white border border-gray-200 text-gray-700 hover:bg-gray-50" : "bg-[#436235] text-white hover:bg-[#375029]"}`}>
                                                    <Settings size={13} /> {ready ? "Edit" : "Set up"}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            <p className="text-[11px] text-gray-400 flex items-center gap-1.5">
                <Clock size={12} /> Staff see their DTR under their own portal once you've set it up here. Picking a known team auto-fills the correct timezone.
            </p>

            {editing && <SetupModal person={editing} onClose={() => setEditing(null)} />}
            {reportFor && <ReportModal person={reportFor} onClose={() => setReportFor(null)} />}
            {historyFor && <HistoryModal person={historyFor} onClose={() => setHistoryFor(null)} />}
        </div>
    );
}
