import { postTaskComment } from "@/lib/taskComments";
import { useEffect, useMemo, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import {
    X, User as UserIcon, Tag, Paperclip, FileText, Film, FileImage, Music,
    AlertTriangle, Save, Eye, Download, CheckCircle2, MessageCircle, Hash,
    Calendar, ChevronDown, TrendingUp, Building2, Link2, Send,
} from "lucide-react";
import AssigneeMultiPicker from "./AssigneeMultiPicker";
import LeadMultiPicker from "./LeadMultiPicker";
import Avatar from "@/components/ui/Avatar";

// Editable task detail modal — the same look as the New Task modal so the
// create + edit flows feel identical. Pre-populated from the task on open;
// Save PATCHes /api/tasks/{id}; new attachments POST to /api/tasks/{id}/
// attachments via the separate attach endpoint.

const DEPARTMENT_LABEL = {
    sales:         "Sales",
    education:     "Education",
    immigration:   "Immigration",
    accommodation: "Accommodation",
    admin:         "Admin",
};

const ALL_DEPARTMENTS = ["sales", "education", "immigration", "accommodation", "admin"];

const TYPE_OPTIONS = [
    ["call",      "Call"],
    ["email",     "Email"],
    ["meeting",   "Meeting"],
    ["document",  "Document"],
    ["follow_up", "Follow-up"],
    ["internal",  "Internal"],
    ["other",     "Other"],
];

// Mirror of CATEGORIES_BY_DEPT in NewTaskModal / TaskBoardPage so the edit
// modal offers the same picker. Kept inline (rather than imported) so this
// file stays self-contained.
const CATEGORIES_BY_DEPT = {
    sales: [
        "Pipeline review", "Reporting", "Marketing coordination",
        "Lead source maintenance", "Email template updates", "Training",
        "Department meeting prep", "Internal process improvement", "Other",
    ],
    education: [
        "Institution relationship management", "Program catalog maintenance",
        "Fee guide updates", "Intake planning", "Reporting",
        "Counselor training", "Department meeting prep", "Other",
    ],
    immigration: [
        "IAA compliance and renewal", "INZ form updates",
        "Visa type library updates", "Adviser training", "Reporting",
        "Department meeting prep", "Audit prep", "Other",
    ],
    accommodation: [
        "Template updates", "Task library maintenance", "Reporting",
        "Coordinator training", "Vendor management", "Other",
    ],
    admin: [
        "User and role management", "System configuration",
        "Reporting consolidation", "Branding updates",
        "Email provider management", "Vendor management",
        "Compliance audits", "Other",
    ],
};

const STATUS_OPTIONS = [
    ["not_started", "Not Started"],
    ["in_progress", "In Progress"],
    ["in_review",   "In Review"],
    ["completed",   "Done"],
];

const isoDate = (iso) => iso ? new Date(iso).toISOString().slice(0, 10) : "";
const isoTime = (iso) => {
    if (! iso) return "17:00";
    const d = new Date(iso);
    return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
};

// If the saved category isn't in the predefined list for this department,
// treat it as a "Other" custom value so the dropdown reflects that correctly.
const splitCategory = (saved, options) => {
    if (! saved)                  return { dropdown: "", custom: "" };
    if (options.includes(saved))  return { dropdown: saved,  custom: "" };
    return { dropdown: "Other", custom: saved };
};

export default function TaskDetailModal({ task, onClose }) {
    const { props: pageProps } = usePage();
    const staffOptions = pageProps?.staffOptions || [];
    const currentUser  = pageProps?.auth?.user || null;

    const initialAssigneeIds = useMemo(() => {
        if (! task) return [];
        const primary = task.assignee?.id ? [Number(task.assignee.id)] : [];
        const extras  = Array.isArray(task.additional_assignee_ids)
            ? task.additional_assignee_ids.map(Number)
            : [];
        return Array.from(new Set([...primary, ...extras]));
    }, [task?.id, task?.assignee?.id, task?.additional_assignee_ids]);

    const initialDepartment = task?.department || "";
    const categoryOptions   = CATEGORIES_BY_DEPT[initialDepartment] || [];

    // Local form state
    const [title,       setTitle]       = useState("");
    const [description, setDescription] = useState("");
    const [note,        setNote]        = useState("");
    const [status,      setStatus]      = useState("not_started");
    const [priority,    setPriority]    = useState("normal");
    const [type,        setType]        = useState("other");
    const [dueDate,     setDueDate]     = useState("");
    const [dueTime,     setDueTime]     = useState("17:00");
    const [progress,    setProgress]    = useState(0);
    const [assigneeIds, setAssigneeIds] = useState([]);
    const [department,  setDepartment]  = useState("");
    const [category,    setCategory]    = useState("");
    const [categoryOther, setCategoryOther] = useState("");
    const [tagsInput,   setTagsInput]   = useState("");
    const [showOtherDepts, setShowOtherDepts] = useState(true);
    // Related leads — chip multi-picker, replaces the old single-locked
    // chip. Hydrated on open from task.lead + task.additional_lead_ids.
    const [relatedRecords, setRelatedRecords] = useState([]);
    const [newFiles,    setNewFiles]    = useState([]);     // File[]
    const [newPreviews, setNewPreviews] = useState([]);     // matching object URLs (null for non-image)
    const [saving,      setSaving]      = useState(false);
    const [error,       setError]       = useState(null);

    // Bottom-of-body tabs — Comments + Activity. Comments are fetched
    // from the same /api/tasks/{id}/comments endpoint CommentsPopover
    // uses, so the count + thread stay in sync wherever you open it.
    const [activeTab,        setActiveTab]        = useState("comments");
    const [comments,         setComments]         = useState([]);
    const [loadingComments,  setLoadingComments]  = useState(false);

    // Seed from task whenever a different one opens.
    useEffect(() => {
        if (! task) return;
        const taskCategoryOpts = CATEGORIES_BY_DEPT[task.department || ""] || [];
        const split = splitCategory(task.category, taskCategoryOpts);

        setTitle(task.title || "");
        setDescription(task.description || "");
        setNote(task.note || "");
        setStatus(task.status || (task.completed ? "completed" : "not_started"));
        setPriority(task.priority || "normal");
        setType(task.type || "other");
        setDueDate(isoDate(task.due_at));
        setDueTime(isoTime(task.due_at));
        setProgress(Number(task.progress || 0));
        setAssigneeIds(initialAssigneeIds);
        setDepartment(task.department || "");
        setCategory(split.dropdown);
        setCategoryOther(split.custom);
        setTagsInput(Array.isArray(task.tags) ? task.tags.join(", ") : "");
        setShowOtherDepts(true);
        setNewPreviews((prev) => { prev.forEach((u) => u && URL.revokeObjectURL(u)); return []; });
        setNewFiles([]);
        setSaving(false);
        setError(null);

        // Hydrate the Related-to picker. The primary lead arrives as a full
        // object on `task.lead`; additional leads come as bare ids — we fetch
        // their display info from /api/tasks/related-records?ids=… so they
        // render as proper chips. The primary lead stays at index 0 so the
        // task keeps the same "primary" record after a save round-trip.
        const primary = task.lead
            ? [{
                id: task.lead.id,
                lead_id: task.lead.lead_id,
                name: task.lead.name,
                email: task.lead.email || null,
                record_type: task.lead.record_type || "lead",
            }]
            : [];
        const extraIds = Array.isArray(task.additional_lead_ids)
            ? task.additional_lead_ids.filter((id) => id && Number(id) !== Number(task.lead?.id))
            : [];
        if (extraIds.length === 0) {
            setRelatedRecords(primary);
        } else {
            // Tentatively show the primary chip; replace with the full list
            // once the extras come back from the server.
            setRelatedRecords(primary);
            fetch(`/api/tasks/related-records?ids=${encodeURIComponent(extraIds.join(","))}`, {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
            })
                .then((r) => r.ok ? r.json() : { records: [] })
                .then((d) => {
                    const extras = (d.records || []).filter(
                        (r) => Number(r.id) !== Number(task.lead?.id),
                    );
                    setRelatedRecords([...primary, ...extras]);
                })
                .catch(() => { /* keep primary-only on failure */ });
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [task?.id]);

    // Clean up object URLs on unmount.
    useEffect(() => () => {
        newPreviews.forEach((u) => u && URL.revokeObjectURL(u));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Fetch comment thread when the modal opens for a different task.
    useEffect(() => {
        if (! task?.id) return;
        setLoadingComments(true);
        fetch(`/api/tasks/${task.id}/comments`, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        })
            .then((r) => r.ok ? r.json() : { comments: [] })
            .then((d) => setComments(d.comments || []))
            .catch(() => setComments([]))
            .finally(() => setLoadingComments(false));
    }, [task?.id]);

    // ESC closes when not saving.
    useEffect(() => {
        if (! task) return;
        const onKey = (e) => { if (e.key === "Escape" && ! saving) onClose?.(); };
        document.addEventListener("keydown", onKey);
        return () => document.removeEventListener("keydown", onKey);
    }, [task, saving, onClose]);

    // Assignee list is filtered to the task's department by default unless
    // the user explicitly opens it up across the org.
    const visibleStaff = useMemo(() => {
        if (showOtherDepts || ! department) return staffOptions;
        return staffOptions.filter((s) => ! s.role || s.role === department || s.role === "admin");
    }, [staffOptions, showOtherDepts, department]);

    const addFiles = (selected) => {
        const list = Array.from(selected || []);
        if (list.length === 0) return;
        const previews = list.map((f) =>
            f.type.startsWith("image/") ? URL.createObjectURL(f) : null
        );
        setNewFiles((prev) => [...prev, ...list].slice(0, 8));
        setNewPreviews((prev) => [...prev, ...previews].slice(0, 8));
    };

    const removeNewFile = (idx) => {
        if (newPreviews[idx]) URL.revokeObjectURL(newPreviews[idx]);
        setNewFiles((prev) => prev.filter((_, i) => i !== idx));
        setNewPreviews((prev) => prev.filter((_, i) => i !== idx));
    };

    if (! task) return null;

    const existingFiles  = task.attachments || [];
    const effectiveCategoryOptions = CATEGORIES_BY_DEPT[department] || [];
    // A task is considered "linked" when any record is in the picker —
    // the primary link is whichever chip sits at index 0.
    const isLinked = relatedRecords.length > 0;

    const onSave = () => {
        if (! title.trim()) {
            setError("Title is required.");
            return;
        }
        setSaving(true);
        setError(null);

        const tags = tagsInput.split(",").map((s) => s.trim()).filter(Boolean);
        const dueAt = dueDate ? `${dueDate} ${dueTime || "17:00"}:00` : null;

        const resolvedCategory = isLinked
            ? null
            : (category === "Other" && categoryOther.trim()
                ? categoryOther.trim()
                : (category || null));

        const leadIds = relatedRecords.map((r) => Number(r.id)).filter(Boolean);

        const payload = {
            title:        title.trim(),
            description:  description || null,
            note:         note || null,
            status,
            priority,
            type,
            progress:     Number(progress) || 0,
            due_at:       dueAt,
            assignee_ids: assigneeIds.length ? assigneeIds : null,
            department:   department || null,
            tags,
            category:     resolvedCategory,
            // TaskController::update reads `lead_ids[]`; the first becomes
            // the primary `lead_id`, the rest land in `additional_lead_ids`.
            lead_ids:     leadIds,
        };

        router.patch(`/api/tasks/${task.id}`, payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // After the PATCH succeeds, upload any new attachments. We
                // do this as a second request so the field-edit form stays
                // simple and validation errors don't get tangled together.
                if (newFiles.length > 0) {
                    const form = new FormData();
                    newFiles.forEach((f) => form.append("attachments[]", f));
                    router.post(`/api/tasks/${task.id}/attachments`, form, {
                        preserveScroll: true,
                        preserveState: true,
                        forceFormData: true,
                        onSuccess: () => {
                            toast.success("Task updated");
                            onClose?.();
                        },
                        onError: () => {
                            toast.warning("Task saved, but some attachments failed.");
                            onClose?.();
                        },
                        onFinish: () => setSaving(false),
                    });
                } else {
                    toast.success("Task updated");
                    setSaving(false);
                    onClose?.();
                }
            },
            onError: (errs) => {
                const first = errs && Object.values(errs)[0];
                setError(first || "Could not save changes.");
                setSaving(false);
            },
        });
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6"
            onClick={(e) => { if (e.target === e.currentTarget && ! saving) onClose?.(); }}
        >
            <div
                className="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[92vh] overflow-hidden flex flex-col"
                style={{ width: '100%', maxWidth: 'min(560px, 95vw)' }}
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header — breadcrumb left, decorative action icons +
                    close right. The pencil/share/more icons match the
                    Craftboard reference visually; none are wired to
                    functionality yet. */}
                <div className="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-gray-100">
                    <div className="flex items-center gap-1.5 min-w-0 flex-1 text-[12px] text-gray-500">
                        <span className="font-medium text-gray-700">Tasks</span>
                        <span className="text-gray-300">/</span>
                        <span className="truncate">{STATUS_OPTIONS.find(([v]) => v === status)?.[1] || "Not Started"}</span>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={saving}
                        className="p-1.5 rounded-md hover:bg-gray-100 text-gray-500 disabled:opacity-40 flex-shrink-0"
                        aria-label="Close"
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* Body — Craftboard-style 2-column layout:
                    left column owns the editable properties (title, status,
                    schedule, assignee, description, attachments). The right
                    column is a sticky activity / comments rail. */}
                <div className="flex-1 overflow-y-auto">
                    {error && (
                        <div className="mx-5 mt-4 mb-0 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-2">
                            <AlertTriangle size={14} className="mt-0.5 flex-shrink-0" />
                            <span>{error}</span>
                        </div>
                    )}

                    <div className="px-5 py-5">

                    {/* ── Title + note ────────────────────────────────── */}
                    <input
                        type="text"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        maxLength={200}
                        placeholder="What needs doing?"
                        className="w-full text-[26px] leading-tight font-bold text-gray-900 placeholder:text-gray-300 bg-transparent border-0 outline-none px-0 mb-2 tracking-tight"
                    />
                    <input
                        type="text"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        maxLength={500}
                        placeholder="Add a short note…"
                        className="w-full text-xs italic text-gray-500 placeholder:text-gray-300 bg-transparent border-0 outline-none px-0 mb-5"
                    />

                    {/* ── Property rows ───────────────────────────────── */}
                    <div className="space-y-1.5">
                        <PropertyRow icon={<Eye size={13} className="text-gray-400" />} label="Status">
                            <IndicatorSelect
                                value={status}
                                onChange={setStatus}
                                options={STATUS_OPTIONS}
                                indicator={<RingDot tone={statusTone(status)} />}
                                tone={statusTone(status)}
                            />
                        </PropertyRow>

                        <PropertyRow icon={<AlertTriangle size={13} className="text-gray-400" />} label="Priority">
                            <IndicatorSelect
                                value={priority}
                                onChange={setPriority}
                                options={[["low", "Low"], ["normal", "Normal"], ["high", "High"], ["urgent", "Urgent"]]}
                                indicator={<RingDot tone={priorityTone(priority)} />}
                                tone={priorityTone(priority)}
                            />
                        </PropertyRow>

                        <PropertyRow icon={<Tag size={13} className="text-gray-400" />} label="Type">
                            <IndicatorSelect
                                value={type}
                                onChange={setType}
                                options={TYPE_OPTIONS}
                                indicator={<RingDot tone={typeTone(type)} />}
                                tone={typeTone(type)}
                            />
                        </PropertyRow>

                        <PropertyRow icon={<Calendar size={13} className="text-gray-400" />} label="Due date">
                            <div className="flex items-center gap-3 text-[13px] text-gray-800">
                                <input
                                    type="date"
                                    value={dueDate}
                                    onChange={(e) => setDueDate(e.target.value)}
                                    className="bg-transparent border-0 outline-none p-0 text-[13px] text-gray-800 cursor-pointer hover:text-gray-600 transition-colors"
                                />
                                <input
                                    type="time"
                                    value={dueTime}
                                    onChange={(e) => setDueTime(e.target.value)}
                                    className="bg-transparent border-0 outline-none p-0 text-[13px] text-gray-800 cursor-pointer hover:text-gray-600 transition-colors"
                                />
                            </div>
                        </PropertyRow>

                        <PropertyRow icon={<UserIcon size={13} className="text-gray-400" />} label="Assignee">
                            <div className="space-y-1">
                                <AssigneeMultiPicker
                                    value={assigneeIds}
                                    onChange={setAssigneeIds}
                                    visibleStaff={visibleStaff}
                                    allStaff={staffOptions}
                                    currentUser={currentUser}
                                />
                                <label className="inline-flex items-center gap-2 text-[10.5px] text-gray-500 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={showOtherDepts}
                                        onChange={(e) => setShowOtherDepts(e.target.checked)}
                                        className="rounded"
                                    />
                                    Show staff from every department
                                </label>
                            </div>
                        </PropertyRow>

                        <PropertyRow icon={<Tag size={13} className="text-gray-400" />} label="Tags">
                            <input
                                type="text"
                                value={tagsInput}
                                onChange={(e) => setTagsInput(e.target.value)}
                                placeholder="urgent, q3-launch"
                                className="w-full px-2.5 py-1 rounded-md border border-gray-200 text-[12px]"
                            />
                        </PropertyRow>

                        {/* Department + Category share a row — both are
                            short selects so they fit side-by-side at the
                            modal's narrow width. Category is suppressed
                            when the task is linked to lead records. */}
                        <PropertyRow icon={<Building2 size={13} className="text-gray-400" />} label={relatedRecords.length === 0 ? "Dept · Cat" : "Department"}>
                            <div className="flex items-center gap-2 flex-wrap">
                                <select
                                    value={department}
                                    onChange={(e) => setDepartment(e.target.value)}
                                    className="px-2.5 py-1 rounded-md border border-gray-200 text-[12px] bg-white min-w-[120px]"
                                >
                                    <option value="">Unset</option>
                                    {ALL_DEPARTMENTS.map((d) => (
                                        <option key={d} value={d}>{DEPARTMENT_LABEL[d]}</option>
                                    ))}
                                </select>
                                {relatedRecords.length === 0 && (
                                    <select
                                        value={category}
                                        onChange={(e) => setCategory(e.target.value)}
                                        className="px-2.5 py-1 rounded-md border border-gray-200 text-[12px] bg-white min-w-[140px]"
                                    >
                                        <option value="">Pick a category…</option>
                                        {effectiveCategoryOptions.map((c) => (
                                            <option key={c} value={c}>{c}</option>
                                        ))}
                                    </select>
                                )}
                            </div>
                            {category === "Other" && relatedRecords.length === 0 && (
                                <input
                                    type="text"
                                    value={categoryOther}
                                    onChange={(e) => setCategoryOther(e.target.value)}
                                    maxLength={100}
                                    placeholder="Type your custom category…"
                                    className="mt-1.5 w-full px-2.5 py-1 rounded-md border border-gray-200 text-[12px]"
                                />
                            )}
                        </PropertyRow>

                        <PropertyRow icon={<Link2 size={13} className="text-gray-400" />} label="Related to">
                            <div className="w-full space-y-1">
                                <LeadMultiPicker
                                    value={relatedRecords}
                                    onChange={setRelatedRecords}
                                />
                                <p className="text-[10px] text-gray-400">First chip is the primary link.</p>
                            </div>
                        </PropertyRow>
                    </div>

                    {/* ── Description (bordered) ──────────────────────── */}
                    <div className="mt-6">
                        <div className="flex items-center gap-1.5 text-[11px] text-gray-500 mb-2">
                            <FileText size={13} className="text-gray-400" />
                            <span>Description</span>
                        </div>
                        <textarea
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            rows={3}
                            placeholder="Optional context, links, instructions…"
                            className="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm resize-y leading-relaxed bg-gray-50/40 focus:bg-white focus:border-gray-400 outline-none transition-colors"
                        />
                    </div>

                    {/* ── Attachments ─────────────────────────────────── */}
                    <div className="mt-6">
                        <div className="flex items-center justify-between mb-2.5">
                            <div className="flex items-center gap-1.5 text-[12px] text-gray-500">
                                <Paperclip size={13} className="text-gray-400" />
                                <span>
                                    Attachment
                                    {(existingFiles.length + newFiles.length) > 0 && (
                                        <span className="ml-1 text-gray-700 font-semibold">({existingFiles.length + newFiles.length})</span>
                                    )}
                                </span>
                            </div>
                            {existingFiles.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() => existingFiles.forEach((f) => window.open(f.url, "_blank"))}
                                    className="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 hover:text-blue-700"
                                >
                                    <Download size={11} /> Download All
                                </button>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {existingFiles.map((a) => (
                                <FileLineCard key={`existing-${a.id}`} attachment={a} />
                            ))}
                            {newFiles.map((file, idx) => (
                                <NewFileLineCard
                                    key={`new-${idx}`}
                                    file={file}
                                    onRemove={() => removeNewFile(idx)}
                                />
                            ))}
                            {newFiles.length < 8 && (
                                <label className="w-14 h-[60px] rounded-xl border-2 border-dashed border-gray-300 flex items-center justify-center cursor-pointer hover:border-gray-400 hover:bg-gray-50 transition-colors text-gray-400 flex-shrink-0"
                                    title="Add attachment"
                                >
                                    <input
                                        type="file"
                                        multiple
                                        onChange={(e) => { addFiles(e.target.files); e.target.value = ""; }}
                                        className="sr-only"
                                    />
                                    <span className="text-2xl font-light leading-none">+</span>
                                </label>
                            )}
                        </div>
                    </div>

                    {/* ── Tabs — Comments + Activity inline at the bottom
                        of the body, matching the Craftboard reference. */}
                    <div className="mt-6 border-t border-gray-100 pt-3">
                        <div className="flex items-center gap-5 text-[13px] font-semibold mb-3">
                            <button
                                type="button"
                                onClick={() => setActiveTab("comments")}
                                className={`pb-2 transition-colors ${activeTab === "comments" ? "text-gray-900 border-b-2 border-gray-900" : "text-gray-400 hover:text-gray-700"}`}
                            >
                                Comments
                                <span className="ml-1.5 inline-flex items-center justify-center text-[10px] font-bold bg-gray-100 text-gray-600 rounded-full px-1.5 py-0.5 align-middle min-w-[18px]">
                                    {comments.length || Number(task.comments_count) || 0}
                                </span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab("activity")}
                                className={`pb-2 transition-colors ${activeTab === "activity" ? "text-gray-900 border-b-2 border-gray-900" : "text-gray-400 hover:text-gray-700"}`}
                            >
                                Activity
                            </button>
                        </div>

                        {activeTab === "comments" ? (
                            <CommentsTab
                                taskId={task.id}
                                comments={comments}
                                setComments={setComments}
                                loadingComments={loadingComments}
                                currentUser={currentUser}
                            />
                        ) : (
                            <ActivityTab task={task} />
                        )}
                    </div>

                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between gap-3 px-5 py-4 border-t border-gray-100 bg-gray-50">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={saving}
                        className="px-4 py-2 rounded-lg text-[12px] font-bold uppercase tracking-wider text-gray-700 hover:bg-gray-200 transition-colors disabled:opacity-40"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={onSave}
                        disabled={saving}
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors disabled:opacity-40"
                    >
                        <Save size={12} />
                        {saving ? "Saving…" : "Save changes"}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ─── Shared helpers ─────────────────────────────────────────────────────

function Section({ title, subtitle, children }) {
    return (
        <section className="space-y-3">
            <header className="border-b border-gray-100 pb-2">
                <h3 className="text-[11px] font-bold uppercase tracking-[0.15em] text-gray-900">
                    {title}
                </h3>
                {subtitle && (
                    <p className="mt-0.5 text-[11px] text-gray-500">{subtitle}</p>
                )}
            </header>
            <div className="space-y-4">{children}</div>
        </section>
    );
}

function FormRow({ label, required, hint, children }) {
    return (
        <div>
            <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            {children}
            {hint && <p className="mt-1 text-[10px] text-gray-400">{hint}</p>}
        </div>
    );
}

// Compact field label with leading icon, used inside the post-style
// single-column body so each section reads at a glance.
function SubLabel({ icon, children }) {
    return (
        <div className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">
            {icon}
            {children}
        </div>
    );
}

// Comments tab — full thread + a composer at the bottom. Hits the
// same /api/tasks/{id}/comments endpoints the kanban-card popover
// uses (CommentsPopover.jsx), so the count and content stay in sync.
function CommentsTab({ taskId, comments, setComments, loadingComments, currentUser }) {

    const [body, setBody]       = useState("");
    const [sending, setSending] = useState(false);

    const submit = async (e) => {
        e?.preventDefault?.();
        const text = body.trim();
        if (! text || sending) return;
        setSending(true);
        try {
            const data = await postTaskComment(taskId, text);
            setComments((prev) => [...prev, data.comment]);
            setBody("");
        } catch {
            toast.error("Could not add comment");
        } finally {
            setSending(false);
        }
    };


    return (
        <div className="space-y-3">
            {/* Thread */}
            {loadingComments ? (
                <p className="text-[12px] text-gray-400 italic">Loading comments…</p>
            ) : comments.length === 0 ? (
                <p className="text-[12px] text-gray-400 italic">No comments yet. Add the first one below.</p>
            ) : (
                <div className="space-y-3 max-h-[260px] overflow-y-auto pr-1">
                    {comments.map((c) => (
                        <CommentRow key={c.id} comment={c} />
                    ))}
                </div>
            )}

            {/* Composer */}
            <form onSubmit={submit} className="flex items-start gap-2 pt-1">
                <Avatar
                    name={currentUser?.name || ""}
                    src={currentUser?.avatar_url}
                    colorKey={currentUser?.id || currentUser?.name}
                    size={28}
                    className="flex-shrink-0"
                />
                <div className="flex-1 flex items-end gap-2 rounded-xl border border-gray-200 bg-white px-2.5 py-1.5 focus-within:border-gray-400 transition-colors">
                    <textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        onKeyDown={(e) => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) submit(e); }}
                        rows={1}
                        placeholder="Add a comment…"
                        className="flex-1 bg-transparent text-[12.5px] resize-none outline-none py-1 leading-snug"
                    />
                    <button
                        type="submit"
                        disabled={! body.trim() || sending}
                        className="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gray-900 text-white hover:bg-gray-800 transition-colors disabled:opacity-40"
                        aria-label="Send comment"
                    >
                        <Send size={12} />
                    </button>
                </div>
            </form>
        </div>
    );
}

// One row in the comments thread — avatar + name + relative time +
// body. Kept compact so a few comments fit in the modal without
// dwarfing the property panel above.
function CommentRow({ comment: c }) {
    const when = c.created_at
        ? new Date(c.created_at).toLocaleString("en-NZ", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })
        : "";
    return (
        <div className="flex items-start gap-2">
            <Avatar
                name={c.author?.name || ""}
                src={c.author?.avatar_url}
                colorKey={c.author?.id || c.author?.name}
                size={28}
                className="flex-shrink-0"
            />
            <div className="flex-1 min-w-0">
                <div className="flex items-baseline gap-2">
                    <span className="text-[12px] font-semibold text-gray-900">{c.author?.name || "Unknown"}</span>
                    <span className="text-[10px] text-gray-400 tabular-nums">{when}</span>
                </div>
                <p className="text-[12.5px] text-gray-700 whitespace-pre-wrap mt-0.5 leading-relaxed">{c.body}</p>
            </div>
        </div>
    );
}

// Activity tab — completion notes + creator/assignee credit lines.
// Hooks into ActivityEntry so future server-side audit-log entries
// can be threaded in here without changing the modal.
function ActivityTab({ task }) {
    return (
        <div className="space-y-3">
            {task.completion_notes && (
                <div className="text-[12px] text-gray-700 whitespace-pre-wrap leading-relaxed bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-2.5 flex items-start gap-2">
                    <CheckCircle2 size={13} className="text-emerald-600 mt-0.5 flex-shrink-0" />
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-wider text-emerald-700 mb-0.5">Completion notes</div>
                        {task.completion_notes}
                    </div>
                </div>
            )}

            {task.creator && (
                <ActivityEntry name={task.creator.name} src={task.creator.avatar_url} colorKey={task.creator.id} text="created this task" />
            )}
            {task.assignee && (
                <ActivityEntry name={task.assignee.name} src={task.assignee.avatar_url} colorKey={task.assignee.id} text="is the primary assignee" />
            )}

            {! task.creator && ! task.assignee && ! task.completion_notes && (
                <p className="text-[12px] text-gray-400 italic">No activity yet.</p>
            )}
        </div>
    );
}

// One line in the right-column Activity rail — small avatar bubble + a
// "<name> <verb-phrase>" sentence. Kept dead simple; richer activity
// items can be added later as the audit-log endpoint surfaces them.
function ActivityEntry({ name, text, src, colorKey }) {
    return (
        <div className="flex items-start gap-2 text-[12px] text-gray-600">
            <Avatar name={name || ""} src={src} colorKey={colorKey || name} size={24} className="flex-shrink-0" />
            <div className="flex-1 leading-snug pt-0.5">
                <span className="font-semibold text-gray-900">{name}</span> {text}
            </div>
        </div>
    );
}

// Craftboard-style property row — icon + label + value on one line.
// Mirrors the "Notion / Linear properties panel" look. Value can be
// any editor (PillSelect, input, multi-picker) and wraps when long.
function PropertyRow({ icon, label, children }) {
    return (
        <div className="flex items-start gap-3 py-1.5">
            <div className="flex items-center gap-2 w-[112px] flex-shrink-0 pt-1 text-[12px] text-gray-500">
                <span className="w-4 flex-shrink-0 flex items-center justify-center">{icon}</span>
                <span>{label}</span>
            </div>
            <div className="flex-1 min-w-0 pt-0.5">{children}</div>
        </div>
    );
}

// Coloured dot indicator next to Status — same colours the status
// pill itself uses, so the row reads at a glance.
function StatusDot({ status }) {
    const colour =
        status === "completed" ? "bg-emerald-500" :
        status === "in_review" ? "bg-purple-500" :
        status === "in_progress" ? "bg-blue-500" :
        "bg-gray-400";
    return <span className={`block w-2.5 h-2.5 rounded-full ${colour}`} />;
}

// Hollow ring used as the leading indicator on status/priority/type
// values. Reads as a quiet status badge rather than a clickable pill.
function RingDot({ tone }) {
    return (
        <span
            className="inline-block w-3 h-3 rounded-full border-[1.5px] flex-shrink-0"
            style={{ borderColor: tone }}
        />
    );
}

// Inline select rendered as plain colored text + indicator + chevron.
// No pill background, no border — looks like a read-only property
// until clicked. The native <select> overlays the whole row so the
// hit target is generous.
function IndicatorSelect({ value, onChange, options, indicator, tone }) {
    const label = options.find(([v]) => v === value)?.[1] ?? value;
    return (
        <div
            className="relative inline-flex items-center gap-1.5 -ml-1 px-1.5 py-1 rounded-md hover:bg-gray-50 transition-colors cursor-pointer"
        >
            {indicator}
            <span
                className="text-[13px] font-medium"
                style={{ color: tone || "#374151" }}
            >
                {label}
            </span>
            <ChevronDown size={11} className="opacity-40 flex-shrink-0" style={{ color: tone || "#374151" }} />
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                style={{ outline: "none", boxShadow: "none" }}
                aria-label="Change value"
            >
                {options.map(([v, l]) => (
                    <option
                        key={v}
                        value={v}
                        className="bg-white text-gray-900 normal-case font-normal tracking-normal"
                    >
                        {l}
                    </option>
                ))}
            </select>
        </div>
    );
}

// Hex tones used by RingDot border + IndicatorSelect text color. Hex
// instead of Tailwind classes so they can be inlined via `style` and
// avoid the purge problem when interpolating dynamic class names.
function statusTone(status) {
    switch (status) {
        case "completed":   return "#059669"; // emerald-600
        case "in_review":   return "#9333ea"; // purple-600
        case "in_progress": return "#2563eb"; // blue-600
        default:            return "#6b7280"; // gray-500
    }
}

function priorityTone(priority) {
    switch (priority) {
        case "urgent": return "#dc2626"; // red-600
        case "high":   return "#d97706"; // amber-600
        case "low":    return "#64748b"; // slate-500
        default:       return "#0891b2"; // cyan-600
    }
}

function typeTone(type) {
    switch (type) {
        case "call":      return "#ea580c"; // orange-600
        case "email":     return "#2563eb"; // blue-600
        case "meeting":   return "#9333ea"; // purple-600
        case "document":  return "#059669"; // emerald-600
        case "follow_up": return "#db2777"; // pink-600
        case "internal":  return "#4b5563"; // gray-600
        default:          return "#64748b"; // slate-500
    }
}

// Tailwind palette helpers — kept saturated (bg-100 / text-700) so the
// chips read as filled colour tabs rather than ghost outlines. Each
// value gets its own hue so the kanban-card-ish feel stays consistent
// across the board, this modal, and the property rows.
function statusAccent(status) {
    switch (status) {
        case "completed":   return "bg-emerald-100 text-emerald-800 border-emerald-200";
        case "in_review":   return "bg-purple-100 text-purple-800 border-purple-200";
        case "in_progress": return "bg-blue-100 text-blue-800 border-blue-200";
        default:            return "bg-gray-200 text-gray-700 border-gray-300";
    }
}

function priorityAccent(priority) {
    switch (priority) {
        case "urgent": return "bg-red-100 text-red-700 border-red-200";
        case "high":   return "bg-amber-100 text-amber-700 border-amber-200";
        case "low":    return "bg-slate-100 text-slate-600 border-slate-200";
        default:       return "bg-cyan-100 text-cyan-700 border-cyan-200";
    }
}

function typeAccent(type) {
    switch (type) {
        case "call":      return "bg-orange-100 text-orange-700 border-orange-200";
        case "email":     return "bg-blue-100 text-blue-700 border-blue-200";
        case "meeting":   return "bg-purple-100 text-purple-700 border-purple-200";
        case "document":  return "bg-emerald-100 text-emerald-700 border-emerald-200";
        case "follow_up": return "bg-pink-100 text-pink-700 border-pink-200";
        case "internal":  return "bg-gray-100 text-gray-700 border-gray-200";
        default:          return "bg-slate-100 text-slate-600 border-slate-200";
    }
}

// File card for an already-uploaded attachment. Horizontal layout:
// a coloured icon tile on the left (palette picked by extension —
// red for PDF, orange for design files, etc.), filename in bold, then
// "size · Download" link below. Fixed minimum width so the cards line
// up in a tidy row.
function FileLineCard({ attachment: a }) {
    const palette = fileTypePalette(a.original_filename, a.mime_type);
    return (
        <a
            href={a.url}
            target="_blank"
            rel="noopener noreferrer"
            download={a.original_filename}
            className="flex items-center gap-2.5 px-2.5 py-2 rounded-xl border border-gray-200 hover:border-gray-300 hover:shadow-sm transition-all bg-white min-w-[180px] flex-1 max-w-[220px]"
        >
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${palette.bg}`}>
                <span className={`text-[10px] font-extrabold tracking-tight ${palette.text}`}>{palette.label}</span>
            </div>
            <div className="flex-1 min-w-0">
                <div className="text-[12px] font-bold text-gray-900 truncate" title={a.original_filename}>
                    {a.original_filename}
                </div>
                <div className="text-[10.5px] text-gray-500 inline-flex items-center gap-1">
                    <span>{fmtBytes(a.size)}</span>
                    <span className="text-gray-300">·</span>
                    <span className="text-blue-600 font-semibold">Download</span>
                </div>
            </div>
        </a>
    );
}

// Per-extension colour + label so PDFs read as PDFs, design files as AI,
// images as IMG, etc. Falls back to a neutral grey + the first 3
// characters of whatever extension the file has.
function fileTypePalette(filename, mime) {
    const ext = (filename || "").split(".").pop()?.toLowerCase() || "";
    const m = (mime || "").toLowerCase();
    if (ext === "pdf" || m === "application/pdf")
        return { bg: "bg-red-100",     text: "text-red-700",     label: "PDF" };
    if (["ai", "psd", "fig", "sketch", "xd"].includes(ext))
        return { bg: "bg-orange-100",  text: "text-orange-700",  label: ext.slice(0, 3).toUpperCase() };
    if (ext === "doc" || ext === "docx" || m.includes("wordprocessing"))
        return { bg: "bg-blue-100",    text: "text-blue-700",    label: "DOC" };
    if (ext === "xls" || ext === "xlsx" || ext === "csv" || m.includes("spreadsheet"))
        return { bg: "bg-emerald-100", text: "text-emerald-700", label: ext === "csv" ? "CSV" : "XLS" };
    if (ext === "ppt" || ext === "pptx" || m.includes("presentation"))
        return { bg: "bg-amber-100",   text: "text-amber-700",   label: "PPT" };
    if (ext === "zip" || ext === "rar" || ext === "7z")
        return { bg: "bg-purple-100",  text: "text-purple-700",  label: ext.toUpperCase() };
    if (m.startsWith("image/"))
        return { bg: "bg-pink-100",    text: "text-pink-700",    label: "IMG" };
    if (m.startsWith("video/"))
        return { bg: "bg-indigo-100",  text: "text-indigo-700",  label: "VID" };
    if (m.startsWith("audio/"))
        return { bg: "bg-violet-100",  text: "text-violet-700",  label: "AUD" };
    return { bg: "bg-gray-100", text: "text-gray-700", label: (ext || "FILE").slice(0, 3).toUpperCase() };
}

// Same horizontal card style as FileLineCard, but for files the user
// just picked and hasn't saved yet — gets the dashed blue border and a
// remove (×) button.
function NewFileLineCard({ file, onRemove }) {
    const palette = fileTypePalette(file.name, file.type);
    return (
        <div className="flex items-center gap-2.5 px-2.5 py-2 rounded-xl border-2 border-dashed border-blue-200 bg-blue-50/30 min-w-[180px] flex-1 max-w-[220px]">
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${palette.bg}`}>
                <span className={`text-[10px] font-extrabold tracking-tight ${palette.text}`}>{palette.label}</span>
            </div>
            <div className="flex-1 min-w-0">
                <div className="text-[12px] font-bold text-gray-900 truncate" title={file.name}>
                    {file.name}
                </div>
                <div className="text-[10.5px] text-gray-500">
                    {fmtBytes(file.size)} · <span className="text-blue-600 font-semibold">Pending</span>
                </div>
            </div>
            <button
                type="button"
                onClick={onRemove}
                className="p-1 text-gray-400 hover:text-red-600 transition-colors flex-shrink-0"
                aria-label="Remove attachment"
            >
                <X size={12} />
            </button>
        </div>
    );
}

// Native <select> rendered as a coloured pill — keyboard-accessible,
// touch-friendly, and avoids the popover-positioning churn of a custom
// picker. Each <option> sets its own bg + colour explicitly so the
// browser dropdown stays legible even when the pill itself is dark
// (e.g. status="bg-gray-900 text-white" would otherwise inherit white
// option text and the menu shows white-on-white).
function PillSelect({ value, onChange, options, accent = "bg-gray-100 text-gray-700 border-gray-200" }) {
    return (
        <div className={`relative inline-flex items-center rounded-full border shadow-sm ${accent}`}>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="appearance-none bg-transparent pl-3 pr-7 py-1.5 text-[11px] font-bold uppercase tracking-wider cursor-pointer text-inherit outline-none focus:outline-none focus:ring-0 focus-visible:outline-none"
                style={{ outline: "none", boxShadow: "none" }}
                aria-label="Change value"
            >
                {options.map(([v, l]) => (
                    <option
                        key={v}
                        value={v}
                        className="bg-white text-gray-900 normal-case font-normal tracking-normal"
                    >
                        {l}
                    </option>
                ))}
            </select>
            <ChevronDown size={11} className="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none opacity-60" />
        </div>
    );
}

function fmtBytes(b) {
    if (! b && b !== 0) return "";
    if (b < 1024)        return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
}

function FileTypeIcon({ mime }) {
    if (! mime) return <FileText size={18} />;
    if (mime.startsWith("image/")) return <FileImage size={18} />;
    if (mime.startsWith("video/")) return <Film size={18} />;
    if (mime.startsWith("audio/")) return <Music size={18} />;
    return <FileText size={18} />;
}

// New-file preview tile in the uploader grid.
function FilePreviewCard({ file, previewUrl, onRemove }) {
    const isImage = file.type?.startsWith("image/") && previewUrl;

    return (
        <div className="relative rounded-lg border border-gray-200 bg-gray-50 overflow-hidden">
            <button
                type="button"
                onClick={onRemove}
                className="absolute top-1 right-1 z-10 bg-black/60 hover:bg-black/80 text-white rounded p-0.5"
                aria-label="Remove attachment"
            >
                <X size={10} />
            </button>

            {isImage ? (
                <div className="aspect-square">
                    <img src={previewUrl} alt={file.name} className="w-full h-full object-cover" />
                </div>
            ) : (
                <div className="aspect-square flex flex-col items-center justify-center text-gray-500 px-2 text-center">
                    <FileTypeIcon mime={file.type} />
                    <p className="mt-1.5 text-[10px] font-semibold text-gray-700 leading-tight line-clamp-2 break-all">
                        {file.name}
                    </p>
                    <p className="mt-0.5 text-[9px] text-gray-400 tabular-nums">
                        {fmtBytes(file.size)}
                    </p>
                </div>
            )}
        </div>
    );
}

// Already-saved attachment with explicit View + Download actions.
function AttachmentCard({ attachment }) {
    const isImage = !! attachment.is_image;
    const name    = attachment.original_filename || "attachment";
    const size    = fmtBytes(attachment.size);

    return (
        <div className="rounded-lg border border-gray-200 bg-white overflow-hidden flex flex-col">
            {isImage ? (
                <a
                    href={attachment.url}
                    target="_blank"
                    rel="noreferrer"
                    className="block aspect-[4/3] bg-gray-50 hover:opacity-90 transition-opacity"
                    title={name}
                >
                    <img src={attachment.url} alt={name} className="w-full h-full object-cover" />
                </a>
            ) : (
                <div className="aspect-[4/3] bg-gray-50 flex flex-col items-center justify-center text-gray-500 px-3 text-center">
                    <FileTypeIcon mime={attachment.mime_type} />
                    <p className="mt-1.5 text-[10.5px] font-semibold text-gray-700 leading-tight line-clamp-2 break-all">
                        {name}
                    </p>
                </div>
            )}

            <div className="px-2.5 py-1.5 border-t border-gray-100 flex items-center gap-2 text-[10.5px]">
                <span className="truncate font-medium text-gray-700" title={name}>{name}</span>
                {size && <span className="text-gray-400 tabular-nums flex-shrink-0">{size}</span>}
                <span className="ml-auto inline-flex items-center gap-1 flex-shrink-0">
                    <a
                        href={attachment.url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded hover:bg-gray-100 text-gray-600 hover:text-gray-900"
                        title="View"
                    >
                        <Eye size={11} /> View
                    </a>
                    <a
                        href={attachment.url}
                        download={name}
                        className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded hover:bg-gray-100 text-gray-600 hover:text-gray-900"
                        title="Download"
                    >
                        <Download size={11} /> Save
                    </a>
                </span>
            </div>
        </div>
    );
}
