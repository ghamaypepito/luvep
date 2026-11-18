import { hrPath } from "@/lib/hrPath";
import { Head, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
const emptyTask = () => ({
    title: "",
    kind: "general",
    owner_user_id: "",
    employee_visible: true,
    note: "",
    private_note: "",
});
function Template({ template, owners }) {
    const form = useForm({
        name: template?.name || "",
        kind: template?.kind || "onboarding",
        version: template?.version || 0,
        tasks: template?.tasks || [emptyTask()],
    });
    const update = (index, key, value) =>
        form.setData(
            "tasks",
            form.data.tasks.map((task, i) =>
                i === index ? { ...task, [key]: value } : task,
            ),
        );
    return (
        <details className="hr-panel">
            <summary>
                <strong>
                    {template
                        ? `Edit template · ${template.name}`
                        : "Create a checklist template"}
                </strong>
            </summary>
            <form
                className="hr-form hr-form-wide"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(
                        template
                            ? hrPath(`/checklists/templates/${template.id}`)
                            : hrPath("/checklists/templates"),
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                if (!template) form.reset();
                            },
                        },
                    );
                }}
            >
                <Errors errors={form.errors} />
                <label>
                    Template name
                    <input
                        required
                        value={form.data.name}
                        maxLength={200}
                        onChange={(e) => form.setData("name", e.target.value)}
                    />
                </label>
                <label>
                    Type
                    <select
                        value={form.data.kind}
                        onChange={(e) => form.setData("kind", e.target.value)}
                    >
                        <option value="onboarding">Onboarding</option>
                        <option value="exit">Exit</option>
                    </select>
                </label>
                {form.data.tasks.map((task, i) => (
                    <fieldset className="hr-task-editor" key={i}>
                        <legend>Task {i + 1}</legend>
                        <label>
                            Task title
                            <input
                                required
                                maxLength={200}
                                value={task.title}
                                onChange={(e) =>
                                    update(i, "title", e.target.value)
                                }
                            />
                        </label>
                        <div className="hr-field-grid">
                            <label>
                                Task type
                                <select
                                    value={task.kind}
                                    onChange={(e) =>
                                        update(i, "kind", e.target.value)
                                    }
                                >
                                    {[
                                        "general",
                                        "equipment_issue",
                                        "equipment_return",
                                        "access_removal",
                                    ].map((k) => (
                                        <option key={k} value={k}>
                                            {k.replaceAll("_", " ")}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Assigned owner
                                <select
                                    value={task.owner_user_id || ""}
                                    onChange={(e) =>
                                        update(
                                            i,
                                            "owner_user_id",
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">The employee</option>
                                    {owners.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <label className="hr-checkbox">
                            <input
                                type="checkbox"
                                checked={!!task.employee_visible}
                                onChange={(e) =>
                                    update(
                                        i,
                                        "employee_visible",
                                        e.target.checked,
                                    )
                                }
                            />
                            Share this task with the employee
                        </label>
                        <label>
                            Task instructions
                            <textarea
                                value={task.note || ""}
                                maxLength={5000}
                                onChange={(e) =>
                                    update(i, "note", e.target.value)
                                }
                            />
                        </label>
                        <label>
                            Private HR note
                            <textarea
                                value={task.private_note || ""}
                                maxLength={5000}
                                onChange={(e) =>
                                    update(i, "private_note", e.target.value)
                                }
                            />
                        </label>
                        <button
                            type="button"
                            className="hr-button-secondary"
                            disabled={form.data.tasks.length === 1}
                            onClick={() =>
                                form.setData(
                                    "tasks",
                                    form.data.tasks.filter((_, n) => n !== i),
                                )
                            }
                        >
                            Remove task {i + 1}
                        </button>
                    </fieldset>
                ))}
                <div className="hr-actions">
                    <button
                        type="button"
                        className="hr-button-secondary"
                        onClick={() =>
                            form.setData("tasks", [
                                ...form.data.tasks,
                                emptyTask(),
                            ])
                        }
                    >
                        Add task
                    </button>
                    <button className="hr-button" disabled={form.processing}>
                        Save template
                    </button>
                </div>
                <p>
                    Existing checklists keep their original tasks. Completing an
                    access-removal task records the action; it never changes an
                    account automatically.
                </p>
            </form>
        </details>
    );
}
function Task({ task }) {
    const form = useForm({ version: task.version });
    return (
        <li>
            <div>
                <strong>{task.title}</strong>
                <p>
                    {task.owner_name} · Due {task.due_date}
                </p>
                {task.note && <p>{task.note}</p>}
                {task.private_note && (
                    <p className="hr-notice">
                        Private HR note: {task.private_note}
                    </p>
                )}
                <Errors errors={form.errors} />
            </div>
            {task.completed_at ? (
                <span className="hr-status">Completed</span>
            ) : task.can_complete ? (
                <button
                    className="hr-button-secondary"
                    disabled={form.processing}
                    onClick={() =>
                        form.post(hrPath(`/checklists/tasks/${task.id}/complete`), {
                            preserveScroll: true,
                        })
                    }
                >
                    Mark complete
                </button>
            ) : (
                <span className="hr-status">Pending</span>
            )}
        </li>
    );
}
export default function Checklists({
    checklists,
    templates,
    employees,
    owners,
    manage,
}) {
    const start = useForm({ employee_id: "", template_id: "", due_date: "" });
    return (
        <>
            <Head title="Onboarding & exits · Luvep" />
            <div className="hr-page-heading">
                <h1>Onboarding & exits</h1>
                <p>
                    Clear owners, practical next steps and a record of every
                    completed task.
                </p>
            </div>
            {manage && (
                <>
                    <section className="hr-panel">
                        <h2>Start a checklist</h2>
                        <form
                            className="hr-form"
                            onSubmit={(e) => {
                                e.preventDefault();
                                start.post(hrPath("/checklists"), {
                                    preserveScroll: true,
                                    onSuccess: () => start.reset(),
                                });
                            }}
                        >
                            <Errors errors={start.errors} />
                            <label>
                                Employee
                                <select
                                    required
                                    value={start.data.employee_id}
                                    onChange={(e) =>
                                        start.setData(
                                            "employee_id",
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Choose employee</option>
                                    {employees.map((e) => (
                                        <option key={e.id} value={e.id}>
                                            {e.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Template
                                <select
                                    required
                                    value={start.data.template_id}
                                    onChange={(e) =>
                                        start.setData(
                                            "template_id",
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Choose template</option>
                                    {templates.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name} · {t.kind}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Due date
                                <input
                                    type="date"
                                    required
                                    value={start.data.due_date}
                                    onChange={(e) =>
                                        start.setData(
                                            "due_date",
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div>
                                <button
                                    className="hr-button"
                                    disabled={
                                        start.processing || !templates.length
                                    }
                                >
                                    Start checklist
                                </button>
                            </div>
                            {!templates.length && (
                                <p>Create a template below to begin.</p>
                            )}
                        </form>
                    </section>
                    <Template owners={owners} />
                    {templates.map((t) => (
                        <Template
                            key={`${t.id}:${t.version}`}
                            template={t}
                            owners={owners}
                        />
                    ))}
                </>
            )}
            <h2 className="hr-section-title">Your accessible checklists</h2>
            {checklists.length ? (
                checklists.map((c) => (
                    <section className="hr-panel" key={c.id}>
                        <h2>{c.name}</h2>
                        <p>
                            {c.employee_name} · {c.kind} · Due {c.due_date}
                        </p>
                        <ul className="hr-employee-list">
                            {c.tasks.map((t) => (
                                <Task key={`${t.id}:${t.version}`} task={t} />
                            ))}
                        </ul>
                    </section>
                ))
            ) : (
                <section className="hr-panel">
                    <p>No checklist tasks are assigned or shared with you.</p>
                </section>
            )}
        </>
    );
}
