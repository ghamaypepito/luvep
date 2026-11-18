import { useEffect, useMemo, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import {
    DndContext, DragOverlay, KeyboardSensor, PointerSensor,
    useDraggable, useDroppable, useSensor, useSensors,
} from "@dnd-kit/core";
import { CSS } from "@dnd-kit/utilities";
import { ChevronDown, X, Check } from "lucide-react";
import TaskCard from "./TaskCard";

// Kanban view for the Task Board. Renders inside TaskBoardPage when the
// KANBAN tab is active. Takes the same filtered task list the list view
// uses — so filter row changes apply here without extra wiring.
//
// Drag-and-drop:
//   - @dnd-kit/core PointerSensor + KeyboardSensor (Space/Enter pick up,
//     Esc cancel, arrow keys move once a card is grabbed)
//   - Optimistic update on drop → PATCH /api/tasks/{id} → revert on error
//   - Drop into Completed opens a slide-up modal capturing optional notes
//
// Permissions: admin can drag any card. Other staff can drag their own
// tasks or tasks belonging to their department. Non-draggable cards show
// a lock icon and stay static; the server re-checks on PATCH.

// Five-column kanban. "Overdue" sits first as an attention bucket — any
// task that's past-due and not yet completed surfaces there regardless of
// its underlying status, so it doesn't get lost mid-pipeline. The other
// four columns carry the real status. Cards take a saturated pastel in
// the same family as their column.
const COLUMNS = [
    { id: "overdue",     label: "Overdue",     colBg: "bg-white border border-red-200",  cardTone: "bg-red-50",     dot: "bg-red-500"     },
    { id: "not_started", label: "Not Started", colBg: "bg-white border border-gray-200", cardTone: "bg-sky-100",    dot: "bg-sky-500"     },
    { id: "in_progress", label: "In Progress", colBg: "bg-white border border-gray-200", cardTone: "bg-orange-100", dot: "bg-orange-500"  },
    { id: "in_review",   label: "In Review",   colBg: "bg-white border border-gray-200", cardTone: "bg-pink-100",   dot: "bg-pink-500"    },
    { id: "completed",   label: "Done",        colBg: "bg-white border border-gray-200", cardTone: "bg-emerald-100",dot: "bg-emerald-500" },
];

const EMPTY_STATE = {
    overdue:     "Nothing overdue — you're caught up.",
    not_started: "Add new tasks via + New Task",
    in_progress: "Drag tasks here when you start working on them",
    in_review:   "Drag tasks here when they need review",
    completed:   "Completed tasks appear here",
};

const PAGE_SIZE = 50;

// Overdue takes precedence over status for column placement — a past-due
// in_progress task lives in Overdue, not In Progress. Done tasks never
// count as overdue (completion overrides). The underlying `status` value
// is unchanged; this only controls which column the card renders in.
const statusOf = (t) => {
    const realStatus = t.status || (t.completed ? "completed" : "not_started");
    if (realStatus === "completed") return "completed";
    if (t.overdue) return "overdue";
    return realStatus;
};

export default function KanbanBoard({
    tasks,
    department,        // the parent portal department (sales/education/…/admin)
    onNewTask,
    onClearFilters,
    portalBase,
}) {
    const { props } = usePage();
    const currentUser = props?.auth?.user;

    // Optimistic mirror of the parent's task list. Lets a dropped card flip
    // status instantly while the PATCH is in flight; revert if PATCH fails.
    const [localTasks, setLocalTasks] = useState(tasks);
    useEffect(() => { setLocalTasks(tasks); }, [tasks]);

    const [activeId, setActiveId] = useState(null);
    const [pendingCompletion, setPendingCompletion] = useState(null);
    const [shown, setShown] = useState({
        overdue: PAGE_SIZE, not_started: PAGE_SIZE, in_progress: PAGE_SIZE,
        in_review: PAGE_SIZE, completed: PAGE_SIZE,
    });
    // Done starts collapsed so the four active columns get more breathing
    // room. Drop target still works while collapsed — drop expands.
    const [collapsed, setCollapsed] = useState({ completed: true });
    const toggleCollapsed = (id) =>
        setCollapsed((c) => ({ ...c, [id]: ! c[id] }));

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor),
    );

    const grouped = useMemo(() => {
        const out = { overdue: [], not_started: [], in_progress: [], in_review: [], completed: [] };
        for (const t of localTasks) {
            const s = statusOf(t);
            (out[s] || out.not_started).push(t);
        }
        return out;
    }, [localTasks]);

    const canDrag = (task) => {
        if (! currentUser) return false;
        if (task.category === "Creative request") return ["admin", "super_admin"].includes(currentUser.role)
            || [task.assignee?.id, ...(task.additional_assignee_ids || [])].map(Number).includes(Number(currentUser.id));
        if (currentUser.role === "admin") return true;
        if (task.assignee?.id === currentUser.id) return true;
        if (task.department && task.department === currentUser.role) return true;
        return false;
    };

    const applyStatus = (task, newStatus, completionNotes) => {
        const previousStatus = statusOf(task);

        setLocalTasks((prev) => prev.map((t) =>
            t.id === task.id
                ? {
                      ...t,
                      status: newStatus,
                      completion_notes: completionNotes ?? t.completion_notes,
                      completed: newStatus === "completed",
                  }
                : t
        ));

        const payload = { status: newStatus };
        if (completionNotes !== undefined && completionNotes !== null) {
            payload.completion_notes = completionNotes;
        }

        router.patch(`/api/tasks/${task.id}`, payload, {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setLocalTasks((prev) => prev.map((t) =>
                    t.id === task.id
                        ? { ...t, status: previousStatus, completed: previousStatus === "completed" }
                        : t
                ));
                toast.error("Could not update task");
            },
        });
    };

    const handleDragEnd = ({ active, over }) => {
        setActiveId(null);
        if (! over) return;
        const task = localTasks.find((t) => String(t.id) === String(active.id));
        if (! task) return;
        const newStatus = String(over.id);
        if (! COLUMNS.find((c) => c.id === newStatus)) return;
        if (statusOf(task) === newStatus) return;

        // "Overdue" isn't a real status — it's computed from due_at < now.
        // Reject drops onto it so the PATCH doesn't 422; users have to
        // extend the due date via the task edit modal to clear overdue.
        if (newStatus === "overdue") {
            toast("Open the task to change its due date — overdue is computed from due date.", { icon: "ℹ️" });
            return;
        }

        if (newStatus === "completed") {
            setPendingCompletion({ task, fromStatus: statusOf(task) });
            return;
        }
        applyStatus(task, newStatus, null);
    };

    const handleClickCard = (_task) => {
        // No-op: TaskCard opens its own TaskDetailModal directly on click
        // so the board doesn't need to thread modal state through here.
        // Kept as a hook in case a future flow wants to react to card
        // clicks (analytics, breadcrumb, etc.).
    };

    // Whole-board empty state. Note: per-column empty states render inside
    // each column when the column itself has no tasks.
    if (localTasks.length === 0) {
        return (
            <div className="px-6 py-20 text-center">
                <p className="text-sm font-medium text-gray-700">No tasks match your filters</p>
                <button
                    type="button"
                    onClick={onClearFilters}
                    className="mt-4 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[11px] font-bold uppercase tracking-wider text-gray-700 hover:bg-gray-100"
                >
                    Clear filters
                </button>
            </div>
        );
    }

    const activeTask = activeId ? localTasks.find((t) => String(t.id) === String(activeId)) : null;

    return (
        <DndContext
            sensors={sensors}
            onDragStart={(e) => setActiveId(e.active.id)}
            onDragEnd={handleDragEnd}
            onDragCancel={() => setActiveId(null)}
        >
            <div className="flex gap-3 p-3 overflow-x-auto md:overflow-x-visible">
                {COLUMNS.map((col) => (
                    <Column
                        key={col.id}
                        column={col}
                        tasks={grouped[col.id]}
                        shown={shown[col.id]}
                        onShowMore={() => setShown((s) => ({ ...s, [col.id]: s[col.id] + PAGE_SIZE }))}
                        canDrag={canDrag}
                        onClickCard={handleClickCard}
                        onNewTask={onNewTask}
                        currentDepartment={department}
                        portalBase={portalBase}
                        collapsed={!! collapsed[col.id]}
                        onToggleCollapsed={() => toggleCollapsed(col.id)}
                    />
                ))}
            </div>

            <DragOverlay dropAnimation={null}>
                {activeTask && (
                    <Card
                        task={activeTask}
                        draggable={false}
                        onClick={() => {}}
                        currentDepartment={department}
                        portalBase={portalBase}
                        isOverlay
                    />
                )}
            </DragOverlay>

            {pendingCompletion && (
                <CompletionModal
                    task={pendingCompletion.task}
                    onCancel={() => setPendingCompletion(null)}
                    onSkip={() => {
                        applyStatus(pendingCompletion.task, "completed", null);
                        setPendingCompletion(null);
                    }}
                    onSave={(notes) => {
                        applyStatus(pendingCompletion.task, "completed", notes);
                        setPendingCompletion(null);
                    }}
                />
            )}
        </DndContext>
    );
}

// ─── Column ─────────────────────────────────────────────────────────────

function Column({ column, tasks, shown, onShowMore, canDrag, onClickCard, onNewTask, currentDepartment, portalBase, collapsed = false, onToggleCollapsed }) {
    const { setNodeRef, isOver } = useDroppable({ id: column.id });
    const visible = tasks.slice(0, shown);
    const hidden = tasks.length - shown;

    // Collapsed rail — narrow vertical strip with the dot, sideways label,
    // count, and an expand button. Stays a drop target so users can still
    // drag a card onto Done; dropping expands so they see what happened.
    if (collapsed) {
        return (
            <div
                ref={setNodeRef}
                className={`flex-shrink-0 w-[48px] flex flex-col items-center rounded-2xl ${column.colBg} p-2 transition-colors ${
                    isOver ? "ring-2 ring-gray-900/15 bg-gray-50" : ""
                }`}
            >
                <button
                    type="button"
                    onClick={onToggleCollapsed}
                    className="w-8 h-8 rounded-md inline-flex items-center justify-center text-gray-500 hover:text-gray-900 hover:bg-gray-100"
                    aria-label={`Expand ${column.label} column`}
                    title={`Expand ${column.label}`}
                >
                    <ChevronDown size={14} className="rotate-90" />
                </button>
                <span className={`w-2 h-2 rounded-full ${column.dot} mt-3`} />
                <span className="mt-2 text-[10px] font-bold tabular-nums text-gray-700 bg-white/70 rounded-full px-1.5 py-0.5 border border-gray-200">
                    {tasks.length}
                </span>
                <span
                    className="mt-3 text-[11px] font-semibold text-gray-600 whitespace-nowrap"
                    style={{ writingMode: "vertical-rl", transform: "rotate(180deg)" }}
                >
                    {column.label}
                </span>
            </div>
        );
    }

    return (
        <div className={`flex-shrink-0 w-[280px] md:flex-1 md:min-w-0 flex flex-col rounded-2xl ${column.colBg} p-3 transition-colors ${
            isOver ? "ring-2 ring-gray-900/15" : ""
        }`}>
            {/* Column header — colored dot + label + count + quick add */}
            <div className="flex items-center justify-between px-1 mb-3">
                <h3 className="inline-flex items-center gap-2 text-[13px] font-semibold text-gray-700">
                    <span className={`w-2 h-2 rounded-full ${column.dot}`} />
                    {column.label}
                    <span className="text-gray-400 font-normal">({tasks.length})</span>
                </h3>
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={onNewTask}
                        className="w-6 h-6 rounded-md inline-flex items-center justify-center text-gray-500 hover:text-gray-900 hover:bg-gray-100"
                        aria-label={`Add task to ${column.label}`}
                        title={`Add task to ${column.label}`}
                    >
                        +
                    </button>
                    {onToggleCollapsed && (
                        <button
                            type="button"
                            onClick={onToggleCollapsed}
                            className="w-6 h-6 rounded-md inline-flex items-center justify-center text-gray-500 hover:text-gray-900 hover:bg-gray-100"
                            aria-label={`Collapse ${column.label} column`}
                            title={`Collapse ${column.label}`}
                        >
                            <ChevronDown size={12} className="-rotate-90" />
                        </button>
                    )}
                </div>
            </div>

            <div
                ref={setNodeRef}
                className="flex-1 min-h-[160px] px-0.5 pb-1 space-y-3"
                role="region"
                aria-label={`${column.label} column`}
            >
                {visible.length === 0 ? (
                    <div className="p-4 text-center bg-gray-50 rounded-2xl border border-dashed border-gray-200">
                        <p className="text-[11px] text-gray-500">{EMPTY_STATE[column.id]}</p>
                        {column.id === "not_started" && (
                            <button
                                type="button"
                                onClick={onNewTask}
                                className="mt-3 text-[11px] font-bold uppercase tracking-wider text-gray-700 hover:text-gray-900"
                            >
                                + New Task
                            </button>
                        )}
                    </div>
                ) : (
                    visible.map((t) => (
                        <Card
                            key={t.id}
                            task={t}
                            draggable={canDrag(t)}
                            onClick={() => onClickCard(t)}
                            currentDepartment={currentDepartment}
                            portalBase={portalBase}
                            cardTone={column.cardTone}
                        />
                    ))
                )}

                {hidden > 0 && (
                    <button
                        type="button"
                        onClick={onShowMore}
                        className="w-full mt-1 py-2 rounded-xl border border-dashed border-gray-300 text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:bg-white hover:text-gray-900 inline-flex items-center justify-center gap-1"
                    >
                        <ChevronDown size={11} /> Show {Math.min(hidden, PAGE_SIZE)} more
                    </button>
                )}
            </div>
        </div>
    );
}

// ─── Card ───────────────────────────────────────────────────────────────

function Card({ task, draggable, onClick, currentDepartment, isOverlay, cardTone }) {
    // useDraggable is called unconditionally to keep hook order stable; the
    // `disabled` flag does the gating when the user can't drag this card.
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: String(task.id),
        data: { task },
        disabled: ! draggable,
    });

    const style = draggable
        ? {
              transform: CSS.Translate.toString(transform),
              opacity: isDragging && ! isOverlay ? 0.4 : 1,
          }
        : undefined;

    return (
        <TaskCard
            task={task}
            currentDepartment={currentDepartment}
            onClick={onClick}
            onMenuClick={(t) => {
                // Kebab menu (edit/delete/etc.) is a future prompt.
                // eslint-disable-next-line no-console
                console.log(`Open task menu for ${t.id}`);
            }}
            locked={! draggable}
            isOverlay={isOverlay}
            dragHandleProps={draggable ? { ...attributes, ...listeners } : null}
            setNodeRef={draggable ? setNodeRef : null}
            style={style}
            tone={cardTone}
        />
    );
}

// ─── Completion modal ───────────────────────────────────────────────────

function CompletionModal({ task, onCancel, onSkip, onSave }) {
    const [notes, setNotes] = useState("");
    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6">
            <div className="bg-white w-full max-w-md rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 className="text-base font-bold text-gray-900 inline-flex items-center gap-2">
                        <Check size={16} className="text-emerald-600" />
                        Add completion notes
                    </h3>
                    <button onClick={onCancel} className="p-1 rounded hover:bg-gray-100 text-gray-500" aria-label="Close">
                        <X size={16} />
                    </button>
                </div>
                <div className="px-5 py-4">
                    <p className="text-xs text-gray-500 mb-2 truncate">
                        Completing: <span className="font-medium text-gray-800">{task.title}</span>
                    </p>
                    <textarea
                        rows={4}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="What was done? (optional)"
                        autoFocus
                        className="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm"
                    />
                </div>
                <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100 bg-gray-50">
                    <button
                        type="button"
                        onClick={onSkip}
                        className="px-4 py-2 rounded-lg text-[12px] font-bold uppercase tracking-wider text-gray-700 hover:bg-gray-200"
                    >
                        Skip
                    </button>
                    <button
                        type="button"
                        onClick={() => onSave(notes.trim() || null)}
                        className="px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors"
                    >
                        Save and complete
                    </button>
                </div>
            </div>
        </div>
    );
}
