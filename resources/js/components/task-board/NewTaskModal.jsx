import { useEffect, useMemo, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import {
    X, Check, CheckCircle2, Search, Paperclip, FileText, Film, FileImage, Music,
} from "lucide-react";
import Avatar from "@/components/ui/Avatar";

// New Task modal — used by every portal Task Board's "+ New Task" button and
// by record-detail "Add task" entry points (via `lockedRecord`). Single-screen
// two-column layout: the task itself on the left, who/what it's about on the
// right. Persistence is unchanged:
//   POST /api/tasks                     → App\Http\Controllers\TaskController@store
//   GET  /api/tasks/related-records?q=  → related-record autocomplete
//
// Backend contract preserved: a task WITH related records is a `linked` task
// (lead_ids[]); a task with none is a `dept` task, which the server requires a
// `category` for — we send a plain "General" so the simplified UI still saves.

const ROLE_LABEL = {
    super_admin: "Super admin", admin: "Admin", sales: "Sales", education: "Education",
    english: "English", immigration: "Immigration", immigration_manager: "Immigration manager",
    immigration_adviser: "Licensed adviser", accommodation: "Accommodation", finance: "Finance",
    agent: "Affiliate Partner", sub_agent: "Sub-agent",
};
const roleLabel = (r) => ROLE_LABEL[r] || (r ? r.replace(/_/g, " ") : "Staff");
const portalLabel = (d) => `${ROLE_LABEL[d] || (d ? d.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()) : "Task")} Portal`;

const REPEAT_OPTIONS = [
    ["never", "Never"],
    ["daily", "Daily"],
    ["weekly", "Weekly"],
    ["monthly", "Monthly"],
];
const PRIORITY_OPTIONS = [
    { value: "normal", label: "Normal", dot: "bg-gray-400", active: "border-gray-800 bg-gray-50 text-gray-900" },
    { value: "high", label: "High", dot: "bg-amber-500", active: "border-amber-300 bg-amber-50 text-amber-800" },
    { value: "urgent", label: "Urgent", dot: "bg-red-500", active: "border-red-300 bg-red-50 text-red-700" },
];
const RECORD_BADGE = { case: "CASE", student: "STUDENT", client: "CLIENT", lead: "LEAD" };

const todayIso = () => new Date().toISOString().slice(0, 10);
const isoPlus = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };

// "Tue, 25 Aug at 5pm · today" — the human due-date preview line.
const humanDue = (dateIso, timeStr) => {
    if (!dateIso) return "";
    const d = new Date(`${dateIso}T${timeStr || "09:00"}`);
    if (Number.isNaN(d.getTime())) return "";
    const wd = d.toLocaleDateString(undefined, { weekday: "short" });
    const mon = d.toLocaleDateString(undefined, { month: "short" });
    let hr = d.getHours();
    const min = d.getMinutes();
    const ampm = hr >= 12 ? "pm" : "am";
    hr = hr % 12 || 12;
    const t = min ? `${hr}:${String(min).padStart(2, "0")}${ampm}` : `${hr}${ampm}`;
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const target = new Date(`${dateIso}T00:00`);
    const diff = Math.round((target - today) / 86400000);
    const rel = diff === 0 ? "today" : diff === 1 ? "tomorrow" : diff > 1 ? `in ${diff} days` : diff === -1 ? "yesterday" : `${-diff} days ago`;
    return `${wd}, ${d.getDate()} ${mon} at ${t} · ${rel}`;
};

export default function NewTaskModal({
    open,
    onClose,
    department,          // current portal's department
    staffOptions = [],   // [{id, name, role?, avatar_url?}]
    lockedRecord = null, // {id, lead_id, name, record_type} — from a record detail page
    onCreated,           // optional callback after a successful create
}) {
    const isAdminPortal = department === "admin";
    const currentUser = usePage().props?.auth?.user || null;

    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    const [taskDepartment, setTaskDepartment] = useState(isAdminPortal ? "sales" : department);
    const [title, setTitle] = useState("");
    const [description, setDescription] = useState("");
    const [priority, setPriority] = useState("normal");
    const [repeat, setRepeat] = useState("never");
    const [dueDate, setDueDate] = useState(todayIso());
    const [dueTime, setDueTime] = useState("17:00");
    const [assigneeIds, setAssigneeIds] = useState([]);
    const [relatedRecords, setRelatedRecords] = useState(lockedRecord ? [lockedRecord] : []);
    const [files, setFiles] = useState([]);
    const [filePreviews, setFilePreviews] = useState([]);

    // Reset on open so re-opening never shows stale state.
    useEffect(() => {
        if (!open) return;
        setSubmitting(false);
        setErrors({});
        setTaskDepartment(isAdminPortal ? "sales" : department);
        setTitle("");
        setDescription("");
        setPriority("normal");
        setRepeat("never");
        setDueDate(todayIso());
        setDueTime("17:00");
        setAssigneeIds([]);
        setRelatedRecords(lockedRecord ? [lockedRecord] : []);
        setFilePreviews((prev) => { prev.forEach((u) => u && URL.revokeObjectURL(u)); return []; });
        setFiles([]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => () => {
        filePreviews.forEach((u) => u && URL.revokeObjectURL(u));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const addFiles = (selected) => {
        const list = Array.from(selected || []);
        if (list.length === 0) return;
        const newPreviews = list.map((f) => (f.type.startsWith("image/") ? URL.createObjectURL(f) : null));
        setFiles((prev) => [...prev, ...list].slice(0, 8));
        setFilePreviews((prev) => [...prev, ...newPreviews].slice(0, 8));
    };
    const removeFile = (idx) => {
        if (filePreviews[idx]) URL.revokeObjectURL(filePreviews[idx]);
        setFiles((prev) => prev.filter((_, i) => i !== idx));
        setFilePreviews((prev) => prev.filter((_, i) => i !== idx));
    };

    const selectedStaff = useMemo(
        () => assigneeIds.map((id) => staffOptions.find((s) => String(s.id) === String(id))).filter(Boolean),
        [assigneeIds, staffOptions],
    );

    if (!open) return null;

    // ── Submit ────────────────────────────────────────────────────────────
    const buildPayload = () => {
        const isLinked = relatedRecords.length > 0;
        const dueAt = `${dueDate} ${dueTime || "17:00"}:00`;
        const recurrence_config = repeat === "never" ? null : {
            frequency: repeat,
            day_of_week: repeat === "weekly" ? new Date(`${dueDate}T00:00`).toLocaleDateString(undefined, { weekday: "long" }) : null,
            day_of_month: repeat === "monthly" ? new Date(`${dueDate}T00:00`).getDate() : null,
            time: dueTime || "17:00",
            end: { type: "never" },
        };
        return {
            task_type: isLinked ? "linked" : "dept",
            lead_ids: isLinked ? relatedRecords.map((r) => r.id) : null,
            // Record-less tasks are `dept` tasks, which the server requires a
            // category for. The simplified UI has no category picker, so send a
            // neutral default that keeps the task savable.
            category: isLinked ? null : "General",
            title,
            description: description || null,
            priority,
            due_at: dueAt,
            assignee_ids: assigneeIds.length ? assigneeIds : null,
            // Non-admins are locked to their own dept server-side; admins pick
            // which department the task belongs to. Sending the dept keeps the
            // board filters working exactly as before.
            department: isAdminPortal ? taskDepartment : department,
            recurrence_config,
            attachments: files,
        };
    };

    const handleCreate = () => {
        setSubmitting(true);
        setErrors({});
        router.post("/api/tasks", buildPayload(), {
            forceFormData: files.length > 0,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success("Task created");
                onCreated?.();
                onClose?.();
            },
            onError: (e) => {
                setErrors(e || {});
                toast.error((e && Object.values(e)[0]) || "Could not create task.");
            },
            onFinish: () => setSubmitting(false),
        });
    };

    const canCreate = title.trim().length > 0 && !submitting;
    const summaryNames = selectedStaff.map((s) => (s.name || "").split(" ")[0]).join(", ");
    const dueShort = `${String(new Date(`${dueDate}T00:00`).getDate()).padStart(2, "0")}/${String(new Date(`${dueDate}T00:00`).getMonth() + 1).padStart(2, "0")}`;

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6">
            <div
                className="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[94vh] overflow-hidden flex flex-col border-t-4 border-teal-600"
                style={{ width: "100%", maxWidth: "min(880px, 96vw)" }}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center">
                            <CheckCircle2 size={18} />
                        </div>
                        <div>
                            <h2 className="text-base font-bold text-gray-900 leading-tight">New task</h2>
                            <p className="text-[11px] text-gray-400">Task Board · {portalLabel(department)}</p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="p-1.5 rounded-md hover:bg-gray-100 text-gray-400" aria-label="Close">
                        <X size={18} />
                    </button>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto">
                    <div className="grid grid-cols-1 lg:grid-cols-2">
                        {/* ── Left: the task ─────────────────────────────── */}
                        <div className="px-6 py-5 space-y-5 lg:border-r border-gray-100">
                            {isAdminPortal && (
                                <Field label="Department">
                                    <select
                                        value={taskDepartment}
                                        onChange={(e) => setTaskDepartment(e.target.value)}
                                        className="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                                    >
                                        {["sales", "education", "immigration", "accommodation", "finance", "admin"].map((d) => (
                                            <option key={d} value={d}>{ROLE_LABEL[d] || d}</option>
                                        ))}
                                    </select>
                                </Field>
                            )}
                            <div>
                                <input
                                    type="text"
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    maxLength={200}
                                    placeholder="What needs doing?"
                                    autoFocus
                                    className="w-full px-3.5 py-3 rounded-xl border border-gray-200 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                                />
                                {errors.title && <p className="mt-1 text-[11px] text-red-600">{errors.title}</p>}
                            </div>

                            <textarea
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                rows={3}
                                placeholder="Add detail — optional"
                                className="w-full px-3.5 py-3 rounded-xl border border-gray-200 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                            />

                            {/* Due date + time */}
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Due">
                                    <input
                                        type="date"
                                        value={dueDate}
                                        min={todayIso()}
                                        onChange={(e) => setDueDate(e.target.value)}
                                        className="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                                    />
                                </Field>
                                <Field label="Time">
                                    <input
                                        type="time"
                                        value={dueTime}
                                        onChange={(e) => setDueTime(e.target.value)}
                                        className="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                                    />
                                </Field>
                            </div>

                            {/* Quick date chips */}
                            <div className="flex flex-wrap gap-2 -mt-1">
                                {[["Today", 0], ["Tomorrow", 1], ["Next week", 7]].map(([label, days]) => {
                                    const iso = isoPlus(days);
                                    const active = dueDate === iso;
                                    return (
                                        <button
                                            key={label}
                                            type="button"
                                            onClick={() => setDueDate(iso)}
                                            className={`px-3 py-1 rounded-full text-xs font-semibold border transition-colors ${active ? "border-gray-900 bg-gray-900 text-white" : "border-gray-200 text-gray-600 hover:bg-gray-50"}`}
                                        >
                                            {label}
                                        </button>
                                    );
                                })}
                            </div>
                            {humanDue(dueDate, dueTime) && (
                                <p className="text-[11px] text-gray-400 -mt-2">{humanDue(dueDate, dueTime)}</p>
                            )}
                            {errors.due_at && <p className="-mt-2 text-[11px] text-red-600">{errors.due_at}</p>}

                            {/* Priority */}
                            <div>
                                <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-2">Priority</p>
                                <div className="grid grid-cols-3 gap-2">
                                    {PRIORITY_OPTIONS.map((p) => {
                                        const active = priority === p.value;
                                        return (
                                            <button
                                                key={p.value}
                                                type="button"
                                                onClick={() => setPriority(p.value)}
                                                className={`inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold border transition-all ${active ? p.active : "border-gray-200 text-gray-600 hover:bg-gray-50"}`}
                                            >
                                                <span className={`w-1.5 h-1.5 rounded-full ${p.dot}`} />
                                                {p.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Repeats */}
                            <div>
                                <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-2">Repeats</p>
                                <div className="flex flex-wrap gap-2">
                                    {REPEAT_OPTIONS.map(([value, label]) => {
                                        const active = repeat === value;
                                        return (
                                            <button
                                                key={value}
                                                type="button"
                                                onClick={() => setRepeat(value)}
                                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors ${active ? "border-gray-900 bg-gray-900 text-white" : "border-gray-200 text-gray-600 hover:bg-gray-50"}`}
                                            >
                                                {label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Attachments */}
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">Attachments</p>
                                    <span className="text-[10px] text-gray-300">optional</span>
                                </div>
                                <label
                                    onDragOver={(e) => e.preventDefault()}
                                    onDrop={(e) => { e.preventDefault(); addFiles(e.dataTransfer.files); }}
                                    className="block rounded-xl border-2 border-dashed border-gray-200 hover:border-gray-300 hover:bg-gray-50 transition-colors cursor-pointer px-4 py-5 text-center"
                                >
                                    <input
                                        type="file"
                                        multiple
                                        onChange={(e) => { addFiles(e.target.files); e.target.value = ""; }}
                                        className="sr-only"
                                        disabled={files.length >= 8}
                                    />
                                    <p className="text-sm font-semibold text-gray-600">Add file or drop it here</p>
                                    <p className="text-[11px] text-gray-400 mt-0.5">image · video · pdf · doc — up to 20 MB, max 8</p>
                                </label>
                                {files.length > 0 && (
                                    <div className="grid grid-cols-3 sm:grid-cols-4 gap-2 mt-2">
                                        {files.map((file, idx) => (
                                            <FilePreviewCard key={idx} file={file} previewUrl={filePreviews[idx]} onRemove={() => removeFile(idx)} />
                                        ))}
                                    </div>
                                )}
                                {(errors["attachments.0"] || errors.attachments) && (
                                    <p className="mt-1 text-[11px] text-red-600">{errors["attachments.0"] || errors.attachments}</p>
                                )}
                            </div>
                        </div>

                        {/* ── Right: who & what ─────────────────────────── */}
                        <div className="px-6 py-5 space-y-6 bg-gray-50/40">
                            <AssignedPanel
                                selected={selectedStaff}
                                staffOptions={staffOptions}
                                currentUser={currentUser}
                                onToggle={(id) => setAssigneeIds((prev) => (prev.map(String).includes(String(id)) ? prev.filter((v) => String(v) !== String(id)) : [...prev, Number(id)]))}
                                onClear={() => setAssigneeIds([])}
                            />

                            <RelatedPanel
                                selected={relatedRecords}
                                lockedRecord={lockedRecord}
                                onToggle={(rec) => setRelatedRecords((prev) => (prev.find((r) => r.id === rec.id) ? prev.filter((r) => r.id !== rec.id) : [...prev, rec]))}
                                onClear={() => setRelatedRecords(lockedRecord ? [lockedRecord] : [])}
                            />

                            {relatedRecords.length > 0 && (
                                <p className="text-[11px] text-gray-400">
                                    This task will show on {relatedRecords.length === 1 ? `${relatedRecords[0].name}'s` : `${relatedRecords.length} records'`} timeline.
                                </p>
                            )}
                            {(errors.lead_ids || errors["lead_ids.0"]) && (
                                <p className="text-[11px] text-red-600">{errors.lead_ids || errors["lead_ids.0"]}</p>
                            )}
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 bg-white">
                    <p className="text-[11px] text-gray-400 truncate">
                        {[summaryNames, dueDate ? `due ${dueShort} ${dueTime}` : null, priority !== "normal" ? priority : null].filter(Boolean).join(" · ") || "New task"}
                    </p>
                    <div className="flex items-center gap-2 shrink-0">
                        <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-100 transition-colors">Cancel</button>
                        <button
                            type="button"
                            onClick={handleCreate}
                            disabled={!canCreate}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            <Check size={15} /> {submitting ? "Creating…" : "Create task"}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Assigned-to panel ──────────────────────────────────────────────────
function AssignedPanel({ selected, staffOptions, currentUser, onToggle, onClear }) {
    const [q, setQ] = useState("");
    const [open, setOpen] = useState(true);
    const selectedIds = selected.map((s) => String(s.id));

    const list = useMemo(() => {
        const needle = q.trim().toLowerCase();
        // Current user first, then the rest — mirrors "assign to myself".
        const sorted = [...staffOptions].sort((a, b) => {
            if (currentUser && String(a.id) === String(currentUser.id)) return -1;
            if (currentUser && String(b.id) === String(currentUser.id)) return 1;
            return (a.name || "").localeCompare(b.name || "");
        });
        return sorted.filter((s) => !needle || (s.name || "").toLowerCase().includes(needle) || roleLabel(s.role).toLowerCase().includes(needle)).slice(0, 60);
    }, [q, staffOptions, currentUser]);

    return (
        <div>
            <div className="flex items-center justify-between mb-2">
                <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">Assigned to</p>
            </div>
            <div className="flex flex-wrap items-center gap-1.5 mb-2">
                {selected.length === 0 && <span className="text-[12px] text-gray-400">You, unless you pick someone</span>}
                {selected.map((s) => (
                    <span key={s.id} className="inline-flex items-center gap-1.5 pl-1 pr-2 py-1 rounded-full bg-white border border-gray-200 text-xs font-semibold text-gray-800">
                        <Avatar name={s.name} src={s.avatar_url} colorKey={s.id || s.name} size={18} />
                        {s.name}
                        <button type="button" onClick={() => onToggle(s.id)} className="w-4 h-4 inline-flex items-center justify-center rounded-full hover:bg-gray-100" aria-label={`Remove ${s.name}`}><X size={10} /></button>
                    </span>
                ))}
                {selected.length > 0 && (
                    <button type="button" onClick={() => setOpen((o) => !o)} className="px-2.5 py-1 rounded-full border border-gray-200 bg-white text-xs font-semibold text-gray-500 hover:bg-gray-50">
                        {open ? "Close" : "Edit"}
                    </button>
                )}
            </div>

            {open && (
                <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
                    <div className="relative border-b border-gray-100">
                        <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search staff…" className="w-full pl-8 pr-3 py-2.5 text-sm outline-none" />
                    </div>
                    <div className="max-h-48 overflow-y-auto">
                        {list.length === 0 && <div className="px-3 py-3 text-[12px] text-gray-400 text-center">No staff match.</div>}
                        {list.map((s) => {
                            const checked = selectedIds.includes(String(s.id));
                            const isMe = currentUser && String(s.id) === String(currentUser.id);
                            return (
                                <button key={s.id} type="button" onClick={() => onToggle(s.id)} className={`w-full text-left px-3 py-2 flex items-center gap-2.5 hover:bg-gray-50 ${checked ? "bg-gray-50" : ""}`}>
                                    <Avatar name={s.name} src={s.avatar_url} colorKey={s.id || s.name} size={26} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-900 truncate">{s.name}{isMe ? " (me)" : ""}</p>
                                        <p className="text-[11px] text-gray-400 truncate">{roleLabel(s.role)}</p>
                                    </div>
                                    {checked && <Check size={15} className="text-teal-600 shrink-0" />}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Related-records panel ──────────────────────────────────────────────
function RelatedPanel({ selected, lockedRecord, onToggle, onClear }) {
    const [q, setQ] = useState("");
    const [open, setOpen] = useState(true);
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef(null);

    useEffect(() => {
        if (!open) return;
        if (debounceRef.current) clearTimeout(debounceRef.current);
        setLoading(true);
        debounceRef.current = setTimeout(() => {
            fetch(`/api/tasks/related-records?q=${encodeURIComponent(q)}`, { headers: { Accept: "application/json" } })
                .then((r) => (r.ok ? r.json() : { records: [] }))
                .then((d) => setResults(d.records || []))
                .catch(() => setResults([]))
                .finally(() => setLoading(false));
        }, q.trim() === "" ? 0 : 250);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [q, open]);

    const selectedIds = selected.map((r) => r.id);

    return (
        <div>
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-2">Related records</p>
            <div className="flex flex-wrap items-center gap-1.5 mb-2">
                {selected.length === 0 && <span className="text-[12px] text-gray-400">None — a general task</span>}
                {selected.map((r) => (
                    <span key={r.id} className="inline-flex items-center gap-1.5 pl-2 pr-2 py-1 rounded-full bg-white border border-gray-200 text-xs font-semibold text-gray-800">
                        <span className="text-[8px] font-bold tracking-wide text-teal-700 bg-teal-50 rounded px-1 py-0.5">{RECORD_BADGE[r.record_type] || "LEAD"}</span>
                        {r.name}
                        {!(lockedRecord && lockedRecord.id === r.id) && (
                            <button type="button" onClick={() => onToggle(r)} className="w-4 h-4 inline-flex items-center justify-center rounded-full hover:bg-gray-100" aria-label={`Remove ${r.name}`}><X size={10} /></button>
                        )}
                    </span>
                ))}
                {selected.length > 0 && (
                    <button type="button" onClick={() => setOpen((o) => !o)} className="px-2.5 py-1 rounded-full border border-gray-200 bg-white text-xs font-semibold text-gray-500 hover:bg-gray-50">
                        {open ? "Close" : "Edit"}
                    </button>
                )}
            </div>

            {open && (
                <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
                    <div className="relative border-b border-gray-100">
                        <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name or reference…" className="w-full pl-8 pr-3 py-2.5 text-sm outline-none" />
                    </div>
                    <div className="max-h-48 overflow-y-auto">
                        {loading && <div className="px-3 py-2 text-[11px] text-gray-400">Loading…</div>}
                        {!loading && results.length === 0 && <div className="px-3 py-3 text-[12px] text-gray-400 text-center">{q.trim() ? "No matches." : "No records found."}</div>}
                        {results.map((r) => {
                            const checked = selectedIds.includes(r.id);
                            return (
                                <button key={r.id} type="button" onClick={() => onToggle(r)} className={`w-full text-left px-3 py-2 flex items-center gap-2.5 hover:bg-gray-50 ${checked ? "bg-gray-50" : ""}`}>
                                    <span className="text-[8px] font-bold tracking-wide text-gray-500 bg-gray-100 rounded px-1.5 py-1 shrink-0">{RECORD_BADGE[r.record_type] || "LEAD"}</span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-900 truncate">{r.name}</p>
                                        <p className="text-[11px] text-gray-400 font-mono truncate">{r.lead_id}</p>
                                    </div>
                                    {checked && <Check size={15} className="text-teal-600 shrink-0" />}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Small helpers ──────────────────────────────────────────────────────
function Field({ label, children }) {
    return (
        <div>
            <label className="block text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-1.5">{label}</label>
            {children}
        </div>
    );
}

function fmtBytes(b) {
    if (!b && b !== 0) return "";
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
}
function FileTypeIcon({ mime }) {
    if (!mime) return <FileText size={18} />;
    if (mime.startsWith("image/")) return <FileImage size={18} />;
    if (mime.startsWith("video/")) return <Film size={18} />;
    if (mime.startsWith("audio/")) return <Music size={18} />;
    return <FileText size={18} />;
}
function FilePreviewCard({ file, previewUrl, onRemove }) {
    const isImage = file.type?.startsWith("image/") && previewUrl;
    return (
        <div className="relative rounded-lg border border-gray-200 bg-gray-50 overflow-hidden group">
            <button type="button" onClick={onRemove} className="absolute top-1 right-1 z-10 bg-black/60 hover:bg-black/80 text-white rounded p-0.5" aria-label="Remove attachment"><X size={10} /></button>
            {isImage ? (
                <div className="aspect-square"><img src={previewUrl} alt={file.name} className="w-full h-full object-cover" /></div>
            ) : (
                <div className="aspect-square flex flex-col items-center justify-center text-gray-500 px-2 text-center">
                    <FileTypeIcon mime={file.type} />
                    <p className="mt-1 text-[9px] font-semibold text-gray-700 leading-tight line-clamp-2 break-all">{file.name}</p>
                    <p className="mt-0.5 text-[8px] text-gray-400 tabular-nums">{fmtBytes(file.size)}</p>
                </div>
            )}
        </div>
    );
}
