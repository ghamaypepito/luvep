import { useForm } from "@inertiajs/react";

const base = "/portal/accommodation/dtr/shared-tasks";
const inputClass = "min-w-0 rounded-lg border border-gray-200 px-3 py-2 text-sm";
const buttonClass = "rounded-lg px-3 py-2 text-sm font-semibold disabled:opacity-50";
const date = (value) => new Date(value).toLocaleDateString("en-NZ", { timeZone: "Pacific/Auckland" });

function TaskForm({ members, userId, task }) {
    const form = useForm({
        title: task?.title ?? "",
        assignee_id: task?.assignee_id ?? userId,
        status: task?.status ?? "pending",
        ...(task ? { version: task.version } : {}),
    });
    const save = (event) => {
        event.preventDefault();
        if (task) {
            form.patch(`${base}/${task.id}`, { preserveScroll: true });
        } else {
            form.post(base, { preserveScroll: true, onSuccess: () => form.reset() });
        }
    };

    return <form onSubmit={save} className="space-y-2 rounded-xl border border-gray-100 p-3" aria-label={task ? `Edit ${task.title}` : "Add shared task"}>
        {task && <p className="text-xs text-gray-500">Added {date(task.created_at)}</p>}
        <div className="flex flex-wrap items-center gap-2">
            <input aria-label="Task" required maxLength={255} className={`${inputClass} flex-1 basis-56`} value={form.data.title}
                placeholder="Describe the task" onChange={(event) => form.setData("title", event.target.value)} />
            <select aria-label="Responsible person" className={inputClass} value={form.data.assignee_id}
                onChange={(event) => form.setData("assignee_id", Number(event.target.value))}>
                {members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
            </select>
            <select aria-label="Status" className={inputClass} value={form.data.status}
                onChange={(event) => form.setData("status", event.target.value)}>
                <option value="pending">Pending</option>
                <option value="ongoing">Ongoing</option>
            </select>
            <button type="submit" disabled={form.processing} className={`${buttonClass} bg-gray-100 text-gray-900`}>{task ? "Save changes" : "Add task"}</button>
            {task && <button type="button" disabled={form.processing}
                className={`${buttonClass} bg-emerald-700 text-white`}
                onClick={() => form.post(`${base}/${task.id}/complete`, { preserveScroll: true })}>Task done</button>}
        </div>
        {Object.values(form.errors).map((error) => <p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}
    </form>;
}

export default function SharedDtrTasks({ board }) {
    const { members, userId, tasks, completed } = board;
    const memberName = (id) => members.find((member) => member.id === id)?.name ?? "Unavailable";

    return <section aria-label="Accommodation shared tasks" className="mx-auto mb-6 max-w-[1400px] space-y-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
        <div>
            <h2 className="text-lg font-bold text-gray-900">Accommodation shared tasks</h2>
            <p className="text-sm text-gray-600">Either person can complete a task. Luvep records who marked it done.</p>
        </div>
        <TaskForm members={members} userId={userId} />
        <div className="space-y-2">
            <h3 className="font-semibold">Pending and ongoing</h3>
            {tasks.length ? tasks.map((task) => <TaskForm key={`${task.id}:${task.version}`} task={task} members={members} userId={userId} />)
                : <p className="text-sm text-gray-500">No open shared tasks.</p>}
        </div>
        <div className="space-y-2">
            <h3 className="font-semibold">Recently completed</h3>
            {completed.length ? <ul className="space-y-2">{completed.map((task) => <li key={task.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 p-3 text-sm">
                <span className="flex-1 break-words">{task.title}</span>
                <span className="text-gray-500">Assigned to {memberName(task.assignee_id)}</span>
                <strong className="rounded bg-emerald-100 px-2 py-1 text-emerald-900">Done by {task.completed_by_name}</strong>
                <span className="text-gray-500">{date(task.completed_at)}</span>
            </li>)}</ul> : <p className="text-sm text-gray-500">No completed shared tasks yet.</p>}
        </div>
    </section>;
}
