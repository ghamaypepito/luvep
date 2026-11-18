import { useMemo, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import { ChevronDown, Clock, Filter, Plus, Search, X } from "lucide-react";
import AssignedTaskFocus from "./AssignedTaskFocus";
import NewTaskModal from "./NewTaskModal";
import KanbanBoard from "./KanbanBoard";
import CalendarView from "./CalendarView";
import ListView from "./ListView";
import Avatar from "@/components/ui/Avatar";

// Single Task Board UI shared across every staff portal. The `department`
// prop scopes labels, default filters, and category lists. Data still comes
// from each portal's controller in the same `today/overdue/this_week/
// undated/recently_done` buckets the existing Sales/Education tasks() method
// returns — see App\Http\Controllers\Portal\*Controller::tasks().
//
// Filter dropdowns that depend on fields the LeadTask model doesn't carry
// yet (task type, category, tags, created-by, cross-dept origin) render but
// don't filter against backend data. The next two prompts (modal + kanban)
// will introduce that infrastructure; the UI is structured so wiring it
// later is a one-line swap from "filter only the data we have" to "send
// query params through router.get".

const DEPARTMENT_LABEL = {
    sales:         "Sales",
    education:     "Education",
    immigration:   "Immigration",
    accommodation: "Accommodation",
    finance:       "Finance",
    admin:         "Admin",
};

const PERIOD_LABEL = {
    all:     "All tasks",
    daily:   "Daily tasks",
    weekly:  "Weekly tasks",
    monthly: "Monthly tasks",
};

// Department/personal task categories per spec. Visible only when Task type
// filter is "Department/personal only". Empty list → filter not shown.
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
    // Finance categorises by which department's work the task relates to,
    // so a finance staffer marking "Education" means "Education-team
    // billing / invoicing / reconciliation". The "Other" option opens a
    // free-text input for anything outside these buckets.
    finance: [
        "Education", "English", "Immigration", "Accommodation", "Sales",
        "Invoicing", "Receivables", "Payments", "Reconciliation",
        "Reporting", "Department meeting prep", "Other",
    ],
};

export default function TaskBoardPage({
    department = "sales",
    today = [],
    overdue = [],
    this_week = [],
    recently_done = [],
    undated = [], // still received from existing controllers; merged into All Upcoming
    all_tasks = [], // full set used by the kanban view — supersedes the date buckets when present
    recent_activity = [],
    scope = "mine",
    portal,
    staffOptions = [],
}) {
    const { url = "" } = usePage();
    const isAdmin = department === "admin";
    const portalKey = portal || department;
    const portalBase = isAdmin ? "/admin" : `/portal/${portalKey}`;

    const title    = isAdmin ? "All Tasks" : "Task Board";
    const subtitle = "All your tasks in one place — record-linked work and department tasks.";

    // ── Filter state ────────────────────────────────────────────────────
    // Most of these don't yet have backing fields on LeadTask; they're
    // wired into the UI now so the next prompt can connect them to query
    // params without re-architecting the page.
    const [taskType,    setTaskType]    = useState("all");        // all | linked | dept
    const [status,      setStatus]      = useState("active");     // active | completed | all
    const [priority,    setPriority]    = useState("all");
    const [category,    setCategory]    = useState("all");
    const [createdBy,   setCreatedBy]   = useState(isAdmin ? "all" : "all");
    const [tagsQuery,   setTagsQuery]   = useState("");
    const [deptFilter,  setDeptFilter]  = useState("all");        // admin-only
    const [searchQuery, setSearchQuery] = useState("");           // title + description substring
    const [period,      setPeriod]      = useState("all");        // all | daily | weekly | monthly — date-scope filter

    const categoryOptions = CATEGORIES_BY_DEPT[department] || [];

    // ── Buckets + KPI counts ────────────────────────────────────────────
    // Filters that CAN run against existing fields (priority, status,
    // completed). Everything else is a no-op until the schema grows.
    const filterRows = (rows) =>
        rows.filter((r) => {
            if (priority !== "all" && r.priority !== priority) return false;
            if (status === "active"    && r.completed)        return false;
            if (status === "completed" && ! r.completed)      return false;
            if (taskType === "linked" && ! r.lead)            return false;
            if (taskType === "dept"   &&   r.lead)            return false;
            if (tagsQuery.trim() && Array.isArray(r.tags)) {
                const q = tagsQuery.trim().toLowerCase();
                if (! r.tags.some((t) => String(t).toLowerCase().includes(q))) return false;
            }
            if (searchQuery.trim()) {
                const q = searchQuery.trim().toLowerCase();
                const hay = `${r.title || ""} ${r.description || ""}`.toLowerCase();
                if (! hay.includes(q)) return false;
            }
            return true;
        });

    // Kanban needs the union of every bucket (it groups by status, not due
    // date) — dedupe by id since the same row can appear in multiple
    // server-side query buckets. The kanban view explicitly skips the
    // Status filter because the columns ARE the status — applying both
    // would empty the Completed column whenever the row filter is "Active".
    const kanbanTasks = useMemo(() => {
        // Prefer the controller-provided full set; fall back to the union of
        // legacy date buckets so older renders still work.
        const source = all_tasks.length > 0
            ? all_tasks
            : [...overdue, ...today, ...this_week, ...undated, ...recently_done];

        const seen = new Set();
        const all = [];
        for (const row of source) {
            if (seen.has(row.id)) continue;
            seen.add(row.id);
            all.push(row);
        }

        // Period filter — narrows by due-date scope. Tasks with no due_at
        // are kept for "all" only (they can't be scoped to a window).
        const now = new Date();
        const startOfDay = new Date(now); startOfDay.setHours(0, 0, 0, 0);
        const endOfDay   = new Date(now); endOfDay.setHours(23, 59, 59, 999);
        const startOfWeek = new Date(startOfDay);
        startOfWeek.setDate(startOfDay.getDate() - ((startOfDay.getDay() + 6) % 7)); // Monday
        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6); endOfWeek.setHours(23, 59, 59, 999);
        const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
        const endOfMonth   = new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59, 999);

        const inPeriod = (iso) => {
            if (period === "all") return true;
            if (! iso) return false;
            const d = new Date(iso);
            if (period === "daily")   return d >= startOfDay   && d <= endOfDay;
            if (period === "weekly")  return d >= startOfWeek  && d <= endOfWeek;
            if (period === "monthly") return d >= startOfMonth && d <= endOfMonth;
            return true;
        };

        return all.filter((r) => {
            if (! inPeriod(r.due_at)) return false;
            if (priority !== "all" && r.priority !== priority) return false;
            if (taskType === "linked" && ! r.lead) return false;
            if (taskType === "dept"   &&   r.lead) return false;
            if (tagsQuery.trim() && Array.isArray(r.tags)) {
                const q = tagsQuery.trim().toLowerCase();
                if (! r.tags.some((t) => String(t).toLowerCase().includes(q))) return false;
            }
            if (searchQuery.trim()) {
                const q = searchQuery.trim().toLowerCase();
                const hay = `${r.title || ""} ${r.description || ""}`.toLowerCase();
                if (! hay.includes(q)) return false;
            }
            return true;
        });
    }, [all_tasks, overdue, today, this_week, undated, recently_done, priority, taskType, tagsQuery, searchQuery, period]);

    const resetFilters = () => {
        setTaskType("all");
        setStatus("all");
        setPriority("all");
        setCategory("all");
        setCreatedBy("all");
        setTagsQuery("");
        setDeptFilter("all");
        setSearchQuery("");
    };

    const [newTaskOpen,  setNewTaskOpen]  = useState(false);
    const [filtersOpen,  setFiltersOpen]  = useState(false);
    const [view,         setView]         = useState("kanban"); // kanban | calendar | list
    const [activityOpen, setActivityOpen] = useState(false);

    const setScope = (next) => {
        router.get(`${portalBase}/tasks`, { scope: next }, { preserveScroll: true });
    };

    const openNewTaskModal = () => setNewTaskOpen(true);

    const activeFilterCount =
        (taskType !== "all" ? 1 : 0)
        + (status !== "active" ? 1 : 0)
        + (priority !== "all" ? 1 : 0)
        + (category !== "all" ? 1 : 0)
        + (createdBy !== "all" ? 1 : 0)
        + (tagsQuery.trim() ? 1 : 0)
        + (isAdmin && deptFilter !== "all" ? 1 : 0);

    return (
        <div className="pb-12">
            <Head title={`${title} — ${DEPARTMENT_LABEL[department] || department}`} />

            {/* Compact toolbar — title left, controls right. Filter row is
                collapsed by default; the Filters button toggles it. KPI
                tiles + subtitle removed so the kanban dominates the page. */}
            <div className="px-2 sm:px-4 pt-1 pb-3">
                <div className="flex items-center justify-between gap-3 flex-wrap">
                    {/* Left cluster — date + period dropdown */}
                    <div className="flex items-center gap-4 flex-wrap">
                        <div className="min-w-0">
                            <p className="text-lg sm:text-xl font-bold text-gray-900 tracking-tight leading-tight">
                                {new Date().toLocaleDateString("en-NZ", { month: "long" })}
                            </p>
                            <p className="text-[11px] text-gray-500">
                                Today is {new Date().toLocaleDateString("en-NZ", { weekday: "long", day: "numeric", month: "short", year: "numeric" })}
                            </p>
                        </div>
                        {/* Board mode — bold label + dropdown current value
                            + chevron, styled as a single button-pill. */}
                        <div className="relative inline-flex items-center gap-2 bg-white rounded-lg border border-gray-200 px-3 py-2 hover:border-gray-300 transition-colors">
                            <span className="text-[14px] font-bold text-gray-900">{title}</span>
                            <span className="text-gray-300">—</span>
                            <span className="text-[13px] text-gray-500">{PERIOD_LABEL[period]}</span>
                            <ChevronDown size={14} className="text-gray-400" />
                            <select
                                value={period}
                                onChange={(e) => setPeriod(e.target.value)}
                                className="absolute inset-0 opacity-0 cursor-pointer"
                                aria-label="Period filter"
                            >
                                <option value="all">All tasks</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                    </div>

                    <div className="flex items-center gap-1.5 flex-wrap">
                        {/* Staff working on this board — decorative for now;
                            future click handler can scope to that staff. */}
                        <AvatarStack users={staffOptions} max={4} />

                        {/* Inline search */}
                        <div className="flex items-center gap-1.5 bg-white rounded-lg border border-gray-200 px-2.5 py-1.5 w-[220px]">
                            <Search size={13} className="text-gray-400" />
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search tasks"
                                className="flex-1 outline-none text-[13px] placeholder:text-gray-400 bg-transparent"
                            />
                            {searchQuery && (
                                <button
                                    type="button"
                                    onClick={() => setSearchQuery("")}
                                    className="text-gray-400 hover:text-gray-700 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Clear
                                </button>
                            )}
                        </div>

                        {/* Filters toggle */}
                        <button
                            type="button"
                            onClick={() => setFiltersOpen((x) => !x)}
                            className={`inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border text-[11px] font-bold uppercase tracking-wider transition-colors ${
                                filtersOpen || activeFilterCount > 0
                                    ? "bg-gray-900 text-white border-gray-900"
                                    : "bg-white text-gray-700 border-gray-200 hover:bg-gray-50"
                            }`}
                            aria-expanded={filtersOpen}
                        >
                            <Filter size={12} />
                            Filters
                            {activeFilterCount > 0 && (
                                <span className="inline-flex items-center justify-center min-w-[14px] h-[14px] px-1 rounded-full bg-white/20 text-[9px] font-bold tabular-nums">
                                    {activeFilterCount}
                                </span>
                            )}
                        </button>

                        {/* Mine / Department / All scope. The Department option
                            (this portal's own tasks) is hidden on the admin
                            board, which spans every department already and has
                            its own department dropdown in Filters. */}
                        <div className="flex items-center gap-0.5 bg-white rounded-lg border border-gray-200 p-0.5">
                            <button
                                type="button"
                                onClick={() => setScope("mine")}
                                className={`px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider transition-colors ${
                                    scope === "mine" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                                }`}
                            >
                                Mine
                            </button>
                            {!isAdmin && (
                                <button
                                    type="button"
                                    onClick={() => setScope("department")}
                                    className={`px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider transition-colors ${
                                        scope === "department" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                                    }`}
                                >
                                    Department
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => setScope("all")}
                                className={`px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider transition-colors ${
                                    scope === "all" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                                }`}
                            >
                                All
                            </button>
                        </div>

                        <button
                            type="button"
                            onClick={openNewTaskModal}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors"
                        >
                            <Plus size={12} /> New Task
                        </button>
                    </div>
                </div>

                {/* Collapsible filter row */}
                {filtersOpen && (
                    <div className="mt-3 bg-white rounded-xl border border-gray-100 shadow-sm p-3 flex flex-wrap items-center gap-2">
                        <FilterSelect label="Task type" value={taskType} onChange={setTaskType} options={[
                            ["all",    "All"],
                            ["linked", "Record-linked only"],
                            ["dept",   "Department/personal only"],
                        ]} />
                        <FilterSelect label="Status" value={status} onChange={setStatus} options={[
                            ["active",    "Active"],
                            ["completed", "Completed"],
                            ["all",       "All"],
                        ]} />
                        <FilterSelect label="Priority" value={priority} onChange={setPriority} options={[
                            ["all", "All"], ["low", "Low"], ["normal", "Normal"], ["high", "High"], ["urgent", "Urgent"],
                        ]} />
                        {taskType === "dept" && categoryOptions.length > 0 && (
                            <FilterSelect label="Category" value={category} onChange={setCategory} options={[
                                ["all", "All categories"],
                                ...categoryOptions.map((c) => [c, c]),
                            ]} />
                        )}
                        <FilterSelect label="Created by" value={createdBy} onChange={setCreatedBy} options={[
                            ["all",    "All"],
                            ["me",     "Me"],
                            ["system", "System"],
                            ...staffOptions.map((s) => [`u:${s.id}`, s.name]),
                        ]} />
                        <label className="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border border-gray-200 bg-white">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Tags</span>
                            <input
                                type="text"
                                value={tagsQuery}
                                onChange={(e) => setTagsQuery(e.target.value)}
                                placeholder="add tag…"
                                className="w-24 text-[11px] outline-none placeholder:text-gray-300"
                            />
                        </label>
                        {isAdmin && (
                            <FilterSelect label="Department" value={deptFilter} onChange={setDeptFilter} options={[
                                ["all", "All departments"],
                                ["sales", "Sales"],
                                ["education", "Education"],
                                ["immigration", "Immigration"],
                                ["accommodation", "Accommodation"],
                                ["admin", "Admin"],
                            ]} />
                        )}
                        {activeFilterCount > 0 && (
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="ml-auto text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-900"
                            >
                                Clear all
                            </button>
                        )}
                    </div>
                )}
            </div>

            {/* Kanban / Calendar / List view switcher — own row above the
                board: switcher left-aligned over the first column, Activity
                log pinned to the right end. */}
            <div className="px-2 sm:px-4 flex items-center justify-between gap-2">
                <div className="inline-flex items-center gap-1 bg-white rounded-xl border border-gray-200 p-1">
                    <button
                        type="button"
                        onClick={() => setView("kanban")}
                        className={`px-3.5 py-1.5 rounded-lg text-[12px] font-bold uppercase tracking-wider transition-colors ${
                            view === "kanban" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                        }`}
                    >
                        Kanban
                    </button>
                    <button
                        type="button"
                        onClick={() => setView("calendar")}
                        className={`px-3.5 py-1.5 rounded-lg text-[12px] font-bold uppercase tracking-wider transition-colors ${
                            view === "calendar" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                        }`}
                    >
                        Calendar
                    </button>
                    <button
                        type="button"
                        onClick={() => setView("list")}
                        className={`px-3.5 py-1.5 rounded-lg text-[12px] font-bold uppercase tracking-wider transition-colors ${
                            view === "list" ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-50"
                        }`}
                    >
                        List
                    </button>
                </div>

                {/* Activity log drawer toggle — right end of the view row */}
                <button
                    type="button"
                    onClick={() => setActivityOpen(true)}
                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border text-[11px] font-bold uppercase tracking-wider bg-white text-gray-700 border-gray-200 hover:bg-gray-50 transition-colors"
                    aria-haspopup="dialog"
                >
                    <Clock size={12} />
                    Activity
                    {recent_activity.length > 0 && (
                        <span className="inline-flex items-center justify-center min-w-[14px] h-[14px] px-1 rounded-full bg-gray-100 text-gray-600 text-[9px] font-bold tabular-nums">
                            {recent_activity.length}
                        </span>
                    )}
                </button>
            </div>

            <AssignedTaskFocus key={url} tasks={[...all_tasks, ...overdue, ...today, ...this_week, ...undated, ...recently_done]} />

            {/* The board — kanban, calendar, or list depending on the toggle. */}
            {view === "kanban" && (
                <KanbanBoard
                    tasks={kanbanTasks}
                    department={department}
                    portalBase={portalBase}
                    onNewTask={openNewTaskModal}
                    onClearFilters={resetFilters}
                />
            )}
            {view === "calendar" && (
                <CalendarView
                    tasks={kanbanTasks}
                    onTaskClick={(t) => {
                        // eslint-disable-next-line no-console
                        console.log(`Open task detail for ${t.id}`);
                    }}
                />
            )}
            {view === "list" && (
                <ListView
                    tasks={kanbanTasks}
                    department={department}
                    onNewTask={openNewTaskModal}
                />
            )}

            {/* Activity log slides in from the right; opened by the
                Activity button in the toolbar. */}
            <ActivityLogDrawer
                open={activityOpen}
                onClose={() => setActivityOpen(false)}
                entries={recent_activity}
            />

            {/* Inertia's POST → back() in TaskController re-renders this page
                with fresh task buckets automatically; no explicit reload needed. */}
            <NewTaskModal
                open={newTaskOpen}
                onClose={() => setNewTaskOpen(false)}
                department={department}
                staffOptions={staffOptions}
            />
        </div>
    );
}

function FilterSelect({ label, value, onChange, options }) {
    return (
        <label className="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border border-gray-200 bg-white">
            <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="text-[11px] bg-transparent outline-none font-medium text-gray-700 cursor-pointer"
            >
                {options.map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                ))}
            </select>
        </label>
    );
}

// KpiTile + TaskList + TaskRow removed — the kanban board IS the view now.

// ─── Avatar stack ───────────────────────────────────────────────────────
// Decorative for now — shows staff visible to this board. Stable color
// per user via a small palette so the same person reads the same on every
// render. Future: clicking an avatar can scope tasks to that assignee.

const AVATAR_PALETTE = [
    "bg-blue-500", "bg-pink-500", "bg-orange-500", "bg-teal-500",
    "bg-purple-500", "bg-amber-500", "bg-emerald-500", "bg-rose-500",
    "bg-indigo-500", "bg-fuchsia-500",
];

const avatarColor = (key) => {
    const str = String(key || "");
    let hash = 0;
    for (let i = 0; i < str.length; i++) hash = ((hash << 5) - hash) + str.charCodeAt(i);
    return AVATAR_PALETTE[Math.abs(hash) % AVATAR_PALETTE.length];
};

const initialsOf = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase();

function AvatarStack({ users = [], max = 4 }) {
    if (! users || users.length === 0) return null;
    const visible = users.slice(0, max);
    const overflow = users.length - visible.length;
    return (
        <div className="flex items-center -space-x-2">
            {visible.map((u) => (
                <Avatar
                    key={u.id}
                    name={u.name}
                    src={u.avatar_url}
                    colorKey={u.id || u.name}
                    size={32}
                    ring
                    className="shadow-sm"
                />
            ))}
            {overflow > 0 && (
                <span
                    title={`${overflow} more`}
                    className="w-8 h-8 rounded-full bg-gray-200 text-gray-700 text-[10px] font-bold inline-flex items-center justify-center ring-2 ring-white shadow-sm"
                >
                    +{overflow}
                </span>
            )}
        </div>
    );
}

// ─── Activity log panel ────────────────────────────────────────────────────
// Recent task changes across all departments, fed by ActivityLog rows whose
// action starts with "lead_task.". Collapsed by default so it doesn't push
// the board off-screen; click the header to expand.

const ACTION_LABELS = {
    "lead_task.created": "created",
    "lead_task.updated": "updated",
    "lead_task.deleted": "deleted",
};

const fmtAgo = (iso) => {
    if (! iso) return "";
    const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1)    return "just now";
    if (mins < 60)   return `${mins} min ago`;
    if (mins < 1440) return `${Math.floor(mins / 60)} hr${mins >= 120 ? "s" : ""} ago`;
    return `${Math.floor(mins / 1440)} day${mins >= 2880 ? "s" : ""} ago`;
};

const summariseChanges = (changes) => {
    if (! changes || typeof changes !== "object") return [];
    const out = [];
    for (const [field, diff] of Object.entries(changes)) {
        const before = diff?.before ?? diff?.old;
        const after  = diff?.after  ?? diff?.new ?? diff;
        const label = field.replace(/_/g, " ");
        if (before == null && after != null) {
            out.push(`${label} → ${String(after)}`);
        } else if (after == null && before != null) {
            out.push(`${label} cleared`);
        } else {
            out.push(`${label}: ${String(before ?? "—")} → ${String(after ?? "—")}`);
        }
    }
    return out;
};

function ActivityLogDrawer({ open, onClose, entries = [] }) {
    if (! open) return null;

    return (
        <div
            className="fixed inset-0 z-50 flex justify-end"
            onClick={(e) => { if (e.target === e.currentTarget) onClose?.(); }}
        >
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/30" aria-hidden="true" />

            {/* Side panel — ~1/4 of viewport, capped so it stays compact
                on ultrawide and still readable on mid-size screens. */}
            <aside
                className="relative bg-white w-full sm:w-[28vw] sm:min-w-[340px] sm:max-w-[420px] h-full shadow-2xl flex flex-col animate-[slideInRight_180ms_ease-out]"
                role="dialog"
                aria-label="Activity log"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <div className="inline-flex items-center gap-2 min-w-0">
                        <Clock size={14} className="text-gray-500" />
                        <h3 className="text-sm font-bold text-gray-900">Activity log</h3>
                        <span className="inline-flex items-center justify-center min-w-[22px] h-[20px] px-1.5 rounded-full bg-gray-100 text-[10px] font-bold tabular-nums text-gray-600">
                            {entries.length}
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-md hover:bg-gray-100 text-gray-500"
                        aria-label="Close activity log"
                    >
                        <X size={16} />
                    </button>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto">
                    {entries.length === 0 ? (
                        <p className="px-5 py-10 text-center text-[12px] text-gray-400">
                            No task activity yet — moves, edits, and creates will appear here.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {entries.map((e) => {
                                const props        = e.properties || {};
                                const changes      = props.changes || {};
                                const summary      = summariseChanges(changes);
                                const taskLabel    = props.subject_label || "task";
                                const actionVerb   = ACTION_LABELS[e.action] || e.action;
                                const actorInitial = initialsOf(e.actor_name || "");
                                return (
                                    <li key={e.id} className="px-5 py-3 flex items-start gap-3 hover:bg-gray-50/60">
                                        <span className="w-8 h-8 rounded-full bg-gray-200 text-gray-700 text-[10px] font-bold inline-flex items-center justify-center flex-shrink-0 mt-0.5">
                                            {actorInitial || "?"}
                                        </span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[12.5px] text-gray-800 leading-snug">
                                                <span className="font-semibold">{e.actor_name || "Unknown"}</span>
                                                {e.actor_role && (
                                                    <span className="ml-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                                        {e.actor_role}
                                                    </span>
                                                )}
                                                <span className="mx-1 text-gray-500">{actionVerb}</span>
                                                <span className="font-semibold">"{taskLabel}"</span>
                                            </p>
                                            {summary.length > 0 && (
                                                <ul className="mt-1 space-y-0.5 text-[11px] text-gray-500">
                                                    {summary.slice(0, 5).map((s, idx) => (
                                                        <li key={idx} className="truncate">{s}</li>
                                                    ))}
                                                    {summary.length > 5 && (
                                                        <li className="text-gray-400">+{summary.length - 5} more</li>
                                                    )}
                                                </ul>
                                            )}
                                            <p className="mt-1 text-[10px] text-gray-400 tabular-nums">
                                                {fmtAgo(e.created_at)}
                                            </p>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </aside>

            {/* Slide-in keyframe — scoped inline so we don't depend on a
                global stylesheet. */}
            <style>{`
                @keyframes slideInRight {
                    from { transform: translateX(100%); }
                    to   { transform: translateX(0); }
                }
            `}</style>
        </div>
    );
}
