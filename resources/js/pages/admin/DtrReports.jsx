import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { ArrowLeft, ChevronLeft, ChevronRight, ChevronDown, CheckCircle2, CircleDashed, CalendarDays, Clock, AlertTriangle, Download, Pencil, Trash2, Plus, X, Save, Mail, Send, FileDown, Users } from "lucide-react";

const DOW = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];
const pad = (n) => String(n).padStart(2, "0");
const to12h = (hhmm) => {
    if (!hhmm) return "—";
    const [h, m] = String(hhmm).split(":").map(Number);
    const ap = h >= 12 ? "PM" : "AM";
    const hh = ((h + 11) % 12) + 1;
    return `${hh}:${String(m).padStart(2, "0")} ${ap}`;
};

// Month calendar whose cells show a submitted/total submission badge, coloured
// by how many of the team have handed in that day's report.
function ReportCalendar({ date, today, dayCounts, staffCount, onPick }) {
    const [cur, setCur] = useState(() => { const d = new Date(date + "T00:00:00"); return { y: d.getFullYear(), m: d.getMonth() }; });
    const first = new Date(cur.y, cur.m, 1);
    const daysInMonth = new Date(cur.y, cur.m + 1, 0).getDate();
    const cells = [...Array(first.getDay()).fill(null), ...Array.from({ length: daysInMonth }, (_, i) => i + 1)];
    const step = (delta) => setCur((c) => { let m = c.m + delta, y = c.y; if (m < 0) { m = 11; y--; } if (m > 11) { m = 0; y++; } return { y, m }; });

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-bold text-gray-900">{first.toLocaleString("en-US", { month: "long" })} {cur.y}</h3>
                <div className="flex items-center gap-1">
                    <button onClick={() => step(-1)} className="p-2 rounded-lg hover:bg-gray-100"><ChevronLeft size={18} /></button>
                    <button onClick={() => step(1)} className="p-2 rounded-lg hover:bg-gray-100"><ChevronRight size={18} /></button>
                </div>
            </div>
            <div className="grid grid-cols-7 gap-1.5 text-center text-[11px] font-bold text-gray-400 mb-1.5">{DOW.map((d) => <div key={d}>{d}</div>)}</div>
            <div className="grid grid-cols-7 gap-1.5">
                {cells.map((d, i) => {
                    if (!d) return <div key={i} />;
                    const ds = `${cur.y}-${pad(cur.m + 1)}-${pad(d)}`;
                    const isFuture = ds > today;
                    const isSelected = ds === date;
                    const submitted = dayCounts[ds] || 0;
                    const showBadge = !isFuture && staffCount > 0;
                    const all = submitted >= staffCount && submitted > 0;
                    const cellCls = isFuture ? "bg-gray-50/40 border-gray-100"
                        : all ? "bg-emerald-50 border-emerald-200"
                        : submitted > 0 ? "bg-amber-50 border-amber-200"
                        : "bg-white border-gray-100 hover:border-gray-300";
                    const inner = (
                        <>
                            <span className={`text-sm font-bold ${isFuture ? "text-gray-300" : "text-gray-800"}`}>{d}</span>
                            {showBadge && (
                                <span className={`absolute bottom-2 left-2.5 text-[10px] font-bold ${all ? "text-emerald-700" : submitted > 0 ? "text-amber-600" : "text-gray-400"}`}>
                                    {submitted}/{staffCount}
                                </span>
                            )}
                        </>
                    );
                    const base = `relative h-24 rounded-xl border p-2.5 text-left ${cellCls} ${isSelected ? "ring-2 ring-gray-900 ring-offset-1" : ds === today ? "ring-2 ring-[#436235]" : ""}`;
                    return isFuture ? (
                        <div key={i} className={`${base} cursor-default`}>{inner}</div>
                    ) : (
                        <button key={i} onClick={() => onPick(ds)} className={`${base} transition-all hover:shadow-sm cursor-pointer`}>{inner}</button>
                    );
                })}
            </div>
            <div className="flex flex-wrap gap-4 mt-4 text-[11px] text-gray-500">
                <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded bg-emerald-100 inline-block" /> All submitted</span>
                <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded bg-amber-100 inline-block" /> Some submitted</span>
                <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded bg-white border border-gray-200 inline-block" /> None yet</span>
            </div>
        </div>
    );
}

// One staffer's row — expands to show their full report for the day, with
// admin edit / delete of that day's record.
function RosterRow({ r, date }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState(null);
    const [baseline, setBaseline] = useState(null);
    const filled = (r.tasks || []).filter((t) => (t.task || "").trim() || (t.pending || "").trim());

    const startEdit = () => {
        const initial = {
            time_in: r.time_in || "",
            time_out: r.time_out || "",
            remarks: r.remarks || "",
            tasks: (r.tasks || []).length
                ? (r.tasks || []).map((t) => ({ task: t.task || "", pending: t.pending || "", pending_done: !!t.pending_done }))
                : [{ task: "", pending: "", pending_done: false }],
        };
        setForm(initial);
        setBaseline({ ...initial, exists: r.entry_id != null, entry_id: r.entry_id ?? null });
        setEditing(true);
        setOpen(true);
    };
    const setTask = (i, key, val) => setForm((f) => ({ ...f, tasks: f.tasks.map((t, j) => (j === i ? { ...t, [key]: val } : t)) }));
    const addTask = () => setForm((f) => ({ ...f, tasks: [...f.tasks, { task: "", pending: "", pending_done: false }] }));
    const removeTask = (i) => setForm((f) => ({ ...f, tasks: f.tasks.filter((_, j) => j !== i) }));

    const save = () => {
        const fields = Object.keys(form).filter(key => !baseline.exists || JSON.stringify(form[key]) !== JSON.stringify(baseline[key]));
        if (!fields.length) { setEditing(false); return; }
        const changes = Object.fromEntries(fields.map(key => [key, key === "tasks"
            ? form.tasks.filter(t => (t.task || "").trim() || (t.pending || "").trim()) : form[key] || null]));
        setSaving(true);
        router.post("/admin/dtr/entry", {
            user_id: r.user_id,
            work_date: date,
            expected_entry_id: baseline.entry_id,
            ...changes,
        }, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
            onFinish: () => setSaving(false),
        });
    };

    const destroy = () => {
        if (!window.confirm(`Delete ${r.name}'s DTR record for this day? This cannot be undone.`)) return;
        router.delete("/admin/dtr/entry", {
            data: { user_id: r.user_id, work_date: date },
            preserveScroll: true,
            onSuccess: () => { setEditing(false); setOpen(false); },
        });
    };

    const hasRecord = r.submitted || r.time_in || (r.tasks || []).length > 0;
    const chip = r.on_leave
        ? <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-100 text-indigo-700">On leave · {r.on_leave}</span>
        : r.submitted
            ? <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-700"><CheckCircle2 size={11} /> Submitted</span>
            : <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-100 text-amber-700"><CircleDashed size={11} /> Not submitted</span>;

    const inputCls = "w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 focus:border-[#436235] focus:ring-1 focus:ring-[#436235] outline-none";

    return (
        <>
            <tr onClick={() => hasRecord && !editing && setOpen((o) => !o)} className={`${hasRecord && !editing ? "cursor-pointer hover:bg-gray-50/60" : ""}`}>
                <td className="px-4 py-3">
                    <p className="font-semibold text-gray-800">{r.name}</p>
                    <p className="text-[11px] text-gray-400">{r.team}</p>
                </td>
                <td className="px-3 py-3">{chip}</td>
                <td className="px-3 py-3 text-gray-600 whitespace-nowrap">{to12h(r.time_in)} – {to12h(r.time_out)}</td>
                <td className="px-3 py-3 tabular-nums font-semibold text-gray-800">{r.net_hrs != null ? Number(r.net_hrs).toFixed(2) : "—"}</td>
                <td className="px-3 py-3">
                    {r.attendance
                        ? <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold ${r.attendance === "Late" ? "bg-rose-100 text-rose-700" : r.attendance === "Flexi" ? "bg-indigo-100 text-indigo-700" : "bg-emerald-100 text-emerald-700"}`}>{r.attendance === "Late" ? <AlertTriangle size={10} /> : r.attendance === "Flexi" ? <Clock size={10} /> : <CheckCircle2 size={10} />} {r.attendance}</span>
                        : <span className="text-gray-300">—</span>}
                </td>
                <td className="px-3 py-3 tabular-nums text-gray-600">{r.tasks_count}</td>
                <td className="px-3 py-3">
                    <div className="flex items-center justify-end gap-1">
                        <button onClick={(e) => { e.stopPropagation(); startEdit(); }} title={hasRecord ? "Edit this day" : "Add a record for this day"} className="p-1.5 rounded-md text-gray-400 hover:text-[#436235] hover:bg-emerald-50 transition-colors"><Pencil size={14} /></button>
                        {hasRecord && (
                            <button onClick={(e) => { e.stopPropagation(); destroy(); }} title="Delete this day" className="p-1.5 rounded-md text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition-colors"><Trash2 size={14} /></button>
                        )}
                        {hasRecord
                            ? <ChevronDown size={14} className={`text-gray-300 transition-transform ${open ? "rotate-180" : ""}`} />
                            : <span className="w-3.5" />}
                    </div>
                </td>
            </tr>
            {open && (
                <tr>
                    <td colSpan={7} className="p-0 bg-gray-50/50 border-t border-gray-100">
                        <div className="p-5 space-y-4">
                            {editing ? (
                                <div className="space-y-4">
                                    <div className="flex flex-wrap items-end gap-4">
                                        <div>
                                            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Time in</label>
                                            <input type="time" value={form.time_in} onChange={(e) => setForm((f) => ({ ...f, time_in: e.target.value }))} className={inputCls} />
                                        </div>
                                        <div>
                                            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Time out</label>
                                            <input type="time" value={form.time_out} onChange={(e) => setForm((f) => ({ ...f, time_out: e.target.value }))} className={inputCls} />
                                        </div>
                                    </div>
                                    <div className="rounded-xl border border-gray-200 overflow-hidden bg-white">
                                        <div className="grid grid-cols-[36px_1fr_1fr_36px] bg-gray-50 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                            <div className="px-2 py-2 text-center">#</div>
                                            <div className="px-3 py-2 border-l border-gray-200">Task completed</div>
                                            <div className="px-3 py-2 border-l border-gray-200">Pending / for tomorrow</div>
                                            <div className="border-l border-gray-200" />
                                        </div>
                                        {form.tasks.map((t, i) => (
                                            <div key={i} className="grid grid-cols-[36px_1fr_1fr_36px] border-b border-gray-100 last:border-b-0 items-center">
                                                <div className="flex items-center justify-center text-[11px] text-gray-400 tabular-nums">{i + 1}</div>
                                                <div className="px-2 py-1.5 border-l border-gray-100"><input value={t.task} onChange={(e) => setTask(i, "task", e.target.value)} placeholder="Task completed" className={inputCls} /></div>
                                                <div className="px-2 py-1.5 border-l border-gray-100"><input value={t.pending} onChange={(e) => setTask(i, "pending", e.target.value)} placeholder="Pending / for tomorrow" className={inputCls} /></div>
                                                <div className="flex items-center justify-center border-l border-gray-100">
                                                    <button onClick={() => removeTask(i)} className="p-1 rounded text-gray-300 hover:text-rose-600 hover:bg-rose-50"><X size={14} /></button>
                                                </div>
                                            </div>
                                        ))}
                                        <button onClick={addTask} className="w-full flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-[#436235] hover:bg-emerald-50 transition-colors"><Plus size={13} /> Add row</button>
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Remarks</label>
                                        <textarea value={form.remarks} onChange={(e) => setForm((f) => ({ ...f, remarks: e.target.value }))} rows={2} className={inputCls} />
                                    </div>
                                    <div className="flex items-center justify-end gap-2">
                                        <button onClick={() => setEditing(false)} disabled={saving} className="px-4 py-2 text-xs font-bold text-gray-600 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-50">Cancel</button>
                                        <button onClick={save} disabled={saving} className="inline-flex items-center gap-2 px-4 py-2 bg-[#436235] text-white text-xs font-bold rounded-lg hover:bg-[#375029] transition-colors disabled:opacity-50">
                                            <Save size={13} /> {saving ? "Saving…" : "Save changes"}
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="rounded-xl border border-gray-200 overflow-hidden bg-white">
                                        <div className="grid grid-cols-[36px_1fr_1fr] bg-gray-50 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                            <div className="px-2 py-2 text-center">#</div>
                                            <div className="px-3 py-2 border-l border-gray-200">Task completed</div>
                                            <div className="px-3 py-2 border-l border-gray-200">Pending / for tomorrow</div>
                                        </div>
                                        {filled.length === 0 ? (
                                            <div className="px-3 py-4 text-center text-xs text-gray-400">No tasks logged.</div>
                                        ) : filled.map((t, i) => (
                                            <div key={i} className="grid grid-cols-[36px_1fr_1fr] border-b border-gray-100 last:border-b-0">
                                                <div className="flex items-center justify-center text-[11px] text-gray-400 tabular-nums">{i + 1}</div>
                                                <div className="px-3 py-2 text-sm text-gray-800 border-l border-gray-100">{t.task || <span className="text-gray-300">—</span>}</div>
                                                <div className="px-3 py-2 text-sm text-gray-800 border-l border-gray-100">{t.pending || <span className="text-gray-300">—</span>}</div>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="flex items-end justify-between gap-3">
                                        <div>
                                            <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Remarks</p>
                                            <p className="text-sm text-gray-700">{r.remarks || <span className="text-gray-300">—</span>}</p>
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <button onClick={startEdit} className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 text-xs font-bold rounded-lg hover:border-[#436235] hover:text-[#436235] transition-colors">
                                                <Pencil size={13} /> Edit
                                            </button>
                                            <a href={`/dtr/report?date=${date}&user=${r.user_id}`} className="inline-flex items-center gap-2 px-4 py-2 bg-[#436235] text-white text-xs font-bold rounded-lg hover:bg-[#375029] transition-colors">
                                                <Download size={13} /> Generate report
                                            </a>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

// Monday (as YYYY-MM-DD) of the week containing `ds`.
function mondayOf(ds) {
    const d = new Date(ds + "T00:00:00");
    const dow = (d.getDay() + 6) % 7; // 0 = Monday
    d.setDate(d.getDate() - dow);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
const addDaysStr = (ds, n) => {
    const d = new Date(ds + "T00:00:00");
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const TEAMS = [
    { key: "all", label: "Both teams" },
    { key: "nz", label: "New Zealand" },
    { key: "ph", label: "Philippines" },
];

// Admin/super_admin: pick a week, type recipient emails, generate the weekly
// DTR PDF and email it — or preview the PDF in a modal first.
function WeeklyReportPanel({ weekStart }) {
    const [open, setOpen] = useState(false);
    const [week, setWeek] = useState(() => mondayOf(weekStart || new Date().toISOString().slice(0, 10)));
    const [team, setTeam] = useState("all");
    const [emails, setEmails] = useState([]);
    const [emailInput, setEmailInput] = useState("");
    const [greeting, setGreeting] = useState("Team");
    const [note, setNote] = useState("");
    const [sending, setSending] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);

    const weekEnd = addDaysStr(week, 6);
    const rangeLabel = useMemo(() => {
        try {
            const s = new Date(week + "T00:00:00");
            const e = new Date(weekEnd + "T00:00:00");
            const sM = s.toLocaleDateString("en-US", { month: "long", day: "numeric" });
            const eM = e.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" });
            return `${sM} – ${eM}`;
        } catch { return `${week} – ${weekEnd}`; }
    }, [week, weekEnd]);

    // Add one or more emails from the input (supports comma/space separated).
    const addEmails = () => {
        const parts = emailInput.split(/[\s,;]+/).map((x) => x.trim().toLowerCase()).filter(Boolean);
        const valid = parts.filter((e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e));
        if (valid.length) setEmails((l) => Array.from(new Set([...l, ...valid])));
        setEmailInput("");
    };
    const removeEmail = (e) => setEmails((l) => l.filter((x) => x !== e));

    const recipients = emails;
    const previewUrl = `/admin/dtr/weekly-report?week=${week}&team=${team}&inline=1`;

    const send = () => {
        if (recipients.length === 0) return;
        setSending(true);
        router.post("/admin/dtr/weekly-report", {
            week, team, recipients,
            greeting: greeting.trim() || "Team",
            note: note.trim() || null,
        }, {
            preserveScroll: true,
            onFinish: () => setSending(false),
        });
    };

    const inputCls = "w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:border-[#436235] focus:ring-1 focus:ring-[#436235] outline-none";

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <button onClick={() => setOpen((o) => !o)} className="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-gray-50/60 transition-colors">
                <div className="flex items-center gap-2.5">
                    <span className="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center"><Mail size={17} className="text-[#436235]" /></span>
                    <div>
                        <h2 className="text-base font-bold text-gray-900">Generate weekly report</h2>
                        <p className="text-xs text-gray-500">Build the week's DTR as a PDF and email it to whoever should receive it.</p>
                    </div>
                </div>
                <ChevronDown size={18} className={`text-gray-400 transition-transform ${open ? "rotate-180" : ""}`} />
            </button>

            {open && (
                <div className="px-6 pb-6 pt-2 border-t border-gray-100 space-y-5">
                    {/* Week + team */}
                    <div className="grid sm:grid-cols-2 gap-5">
                        <div>
                            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Week</label>
                            <div className="flex items-center gap-2">
                                <button onClick={() => setWeek((w) => addDaysStr(w, -7))} className="p-2 rounded-lg border border-gray-200 hover:bg-gray-50"><ChevronLeft size={16} /></button>
                                <div className="flex-1 text-center rounded-lg border border-gray-200 bg-gray-50/60 px-3 py-2 text-sm font-semibold text-gray-800">{rangeLabel}</div>
                                <button onClick={() => setWeek((w) => addDaysStr(w, 7))} className="p-2 rounded-lg border border-gray-200 hover:bg-gray-50"><ChevronRight size={16} /></button>
                            </div>
                            <p className="text-[11px] text-gray-400 mt-1.5">Monday–Sunday. Use the arrows to pick which week to report on.</p>
                        </div>
                        <div>
                            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Team</label>
                            <div className="flex gap-2">
                                {TEAMS.map((t) => (
                                    <button key={t.key} onClick={() => setTeam(t.key)}
                                        className={`flex-1 px-3 py-2 rounded-lg text-xs font-bold border transition-colors ${team === t.key ? "bg-[#436235] text-white border-[#436235]" : "bg-white text-gray-600 border-gray-200 hover:border-gray-300"}`}>
                                        {t.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Recipients — type any email(s) */}
                    <div>
                        <label className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5 flex items-center gap-1.5"><Users size={12} /> Send to</label>
                        {emails.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 mb-2">
                                {emails.map((e) => (
                                    <span key={e} className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-50 text-emerald-700 text-xs font-semibold">
                                        {e}
                                        <button onClick={() => removeEmail(e)} className="hover:text-emerald-900"><X size={12} /></button>
                                    </span>
                                ))}
                            </div>
                        )}
                        <div className="flex gap-2">
                            <input value={emailInput} onChange={(e) => setEmailInput(e.target.value)}
                                onKeyDown={(e) => { if (e.key === "Enter" || e.key === ",") { e.preventDefault(); addEmails(); } }}
                                onBlur={() => emailInput.trim() && addEmails()}
                                type="email" placeholder="Type an email and press Enter…" className={inputCls} />
                            <button onClick={addEmails} className="px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-600 hover:border-[#436235] hover:text-[#436235] shrink-0">Add</button>
                        </div>
                        <p className="text-[11px] text-gray-400 mt-1.5">Enter any email address. Separate several with a comma or Enter.</p>
                    </div>

                    {/* Greeting + note */}
                    <div className="grid sm:grid-cols-2 gap-5">
                        <div>
                            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Greeting</label>
                            <div className="flex items-center gap-1.5">
                                <span className="text-sm text-gray-500 shrink-0">Hi</span>
                                <input value={greeting} onChange={(e) => setGreeting(e.target.value)} placeholder="Dev and Dinah" className={inputCls} />
                            </div>
                            <p className="text-[11px] text-gray-400 mt-1.5">Shown as "Hi &lt;greeting&gt;," in the email.</p>
                        </div>
                        <div>
                            <label className="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Extra note <span className="text-gray-300 normal-case tracking-normal">(optional)</span></label>
                            <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Add a line to the message…" className={inputCls} />
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-1">
                        <p className="text-xs text-gray-500">
                            {recipients.length > 0
                                ? <><strong className="text-gray-700">{recipients.length}</strong> recipient{recipients.length === 1 ? "" : "s"} · {TEAMS.find((t) => t.key === team)?.label} · {rangeLabel}</>
                                : "Add at least one recipient to send."}
                        </p>
                        <div className="flex items-center gap-2">
                            <button onClick={() => setPreviewOpen(true)} className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 text-xs font-bold rounded-lg hover:border-[#436235] hover:text-[#436235] transition-colors">
                                <FileDown size={14} /> Preview PDF
                            </button>
                            <button onClick={send} disabled={sending || recipients.length === 0}
                                className="inline-flex items-center gap-2 px-5 py-2 bg-[#436235] text-white text-xs font-bold rounded-lg hover:bg-[#375029] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                <Send size={14} /> {sending ? "Sending…" : "Generate & email"}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Preview modal */}
            {previewOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" onClick={() => setPreviewOpen(false)}>
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-[88vh] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                            <div className="flex items-center gap-2">
                                <FileDown size={16} className="text-[#436235]" />
                                <h3 className="text-sm font-bold text-gray-900">Weekly DTR — {rangeLabel}</h3>
                                <span className="text-xs text-gray-400">{TEAMS.find((t) => t.key === team)?.label}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <a href={previewUrl.replace("&inline=1", "")} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                                    <Download size={13} /> Download
                                </a>
                                <button onClick={() => setPreviewOpen(false)} className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100"><X size={18} /></button>
                            </div>
                        </div>
                        <iframe key={previewUrl} src={previewUrl} title="Weekly DTR preview" className="flex-1 w-full bg-gray-100" />
                    </div>
                </div>
            )}
        </div>
    );
}

export default function DtrReports({ date = "", today = "", staffCount = 0, dayCounts = {}, roster = [], weekStart = "" }) {
    const pick = (ds) => router.get("/admin/dtr/reports", { date: ds }, { preserveState: true, preserveScroll: true, replace: true });

    const submittedCount = useMemo(() => roster.filter((r) => r.submitted).length, [roster]);
    const onLeaveCount = useMemo(() => roster.filter((r) => r.on_leave).length, [roster]);
    const prettyDate = useMemo(() => {
        try { return new Date(date + "T00:00:00").toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric", year: "numeric" }); }
        catch { return date; }
    }, [date]);

    return (
        <div className="space-y-6 max-w-[1400px] mx-auto pb-12">
            <Head title="DTR — Team Daily Reports" />

            <div>
                <Link href="/admin/dtr" className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-gray-900 mb-2">
                    <ArrowLeft size={14} /> Back to my DTR
                </Link>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Team Daily Reports</h1>
                <p className="text-sm text-gray-500 mt-1">See who has submitted their end-of-day report. Pick a day on the calendar, then open a staffer's row to read their report.</p>
            </div>

            <WeeklyReportPanel weekStart={weekStart} />

            <ReportCalendar date={date} today={today} dayCounts={dayCounts} staffCount={staffCount} onPick={pick} />

            {/* Selected day roster */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <CalendarDays size={16} className="text-[#436235]" />
                        <h2 className="text-base font-bold text-gray-900">{prettyDate}</h2>
                    </div>
                    <div className="flex items-center gap-2 text-[11px]">
                        <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold"><CheckCircle2 size={12} /> {submittedCount} submitted</span>
                        <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-amber-50 text-amber-700 font-bold"><CircleDashed size={12} /> {staffCount - submittedCount - onLeaveCount} pending</span>
                        {onLeaveCount > 0 && <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold">{onLeaveCount} on leave</span>}
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                <th className="px-4 py-3">Staff</th>
                                <th className="px-3 py-3">Report</th>
                                <th className="px-3 py-3">In – Out</th>
                                <th className="px-3 py-3">Net</th>
                                <th className="px-3 py-3">Attendance</th>
                                <th className="px-3 py-3">Tasks</th>
                                <th className="px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {roster.length === 0 ? (
                                <tr><td colSpan={7} className="px-4 py-12 text-center text-gray-400">No staff have a DTR set up yet.</td></tr>
                            ) : roster.map((r) => <RosterRow key={`${date}:${r.user_id}`} r={r} date={date} />)}
                        </tbody>
                    </table>
                </div>
                <p className="px-6 py-3 text-[11px] text-gray-400 flex items-center gap-1.5 border-t border-gray-100">
                    <Clock size={12} /> A report counts as submitted once the staffer clocks out or logs tasks for the day. Click a submitted row to read the report.
                </p>
            </div>
        </div>
    );
}
