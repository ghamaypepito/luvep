import { Head, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
function Editor({ milestone, employees, owners }) {
    const form = useForm({
        employee_id: milestone?.hr_employee_id || "",
        kind: milestone?.kind || "probation",
        title: milestone?.title || "",
        due_date: milestone?.due_date || "",
        owner_user_id: milestone?.owner_user_id || "",
        employee_visible: milestone?.employee_visible || false,
        private_note: milestone?.private_note || "",
        version: milestone?.version || 0,
    });
    return (
        <form
            className="hr-form"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(
                    milestone ? `/milestones/${milestone.id}` : "/milestones",
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            if (!milestone) form.reset();
                        },
                    },
                );
            }}
        >
            <Errors errors={form.errors} />
            <label>
                Employee
                <select
                    required
                    disabled={!!milestone}
                    value={form.data.employee_id}
                    onChange={(e) =>
                        form.setData("employee_id", e.target.value)
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
                Milestone type
                <select
                    value={form.data.kind}
                    onChange={(e) => form.setData("kind", e.target.value)}
                >
                    {[
                        "probation",
                        "contract",
                        "onboarding",
                        "exit",
                        "other",
                    ].map((k) => (
                        <option key={k} value={k}>
                            {k}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                Title
                <input
                    required
                    maxLength={200}
                    value={form.data.title}
                    onChange={(e) => form.setData("title", e.target.value)}
                />
            </label>
            <label>
                Due date
                <input
                    type="date"
                    required
                    value={form.data.due_date}
                    onChange={(e) => form.setData("due_date", e.target.value)}
                />
            </label>
            <label>
                Owner
                <select
                    required
                    value={form.data.owner_user_id}
                    onChange={(e) =>
                        form.setData("owner_user_id", e.target.value)
                    }
                >
                    <option value="">Choose owner</option>
                    {owners.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.name}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                <input
                    type="checkbox"
                    checked={form.data.employee_visible}
                    onChange={(e) =>
                        form.setData("employee_visible", e.target.checked)
                    }
                />{" "}
                Share with employee
            </label>
            <label>
                Private HR note
                <textarea
                    maxLength={5000}
                    value={form.data.private_note}
                    onChange={(e) =>
                        form.setData("private_note", e.target.value)
                    }
                />
            </label>
            <div>
                <button className="hr-button" disabled={form.processing}>
                    Save milestone
                </button>
            </div>
        </form>
    );
}
function Complete({ milestone }) {
    const f = useForm({ version: milestone.version });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                f.post(`/milestones/${milestone.id}/complete`, {
                    preserveScroll: true,
                });
            }}
        >
            <button className="hr-button-secondary" disabled={f.processing}>
                Mark complete
            </button>
        </form>
    );
}
export default function Milestones({ milestones, employees, owners, manage }) {
    return (
        <>
            <Head title="Milestones · Luvep" />
            <div className="hr-page-heading">
                <h1>Milestones</h1>
                <p>
                    Keep track of employment reviews, contract dates and agreed
                    next steps.
                </p>
            </div>
            {manage && (
                <details className="hr-panel">
                    <summary>
                        <strong>Add milestone</strong>
                    </summary>
                    <Editor employees={employees} owners={owners} />
                </details>
            )}
            {milestones.length ? (
                milestones.map((m) => (
                    <section className="hr-panel" key={`${m.id}:${m.version}`}>
                        <div className="hr-section-bar">
                            <div>
                                <h2>{m.title}</h2>
                                <p>
                                    {m.employee_name} · {m.kind}
                                </p>
                            </div>
                            <span className="hr-status">
                                {m.completed_at ? "Completed" : "Open"}
                            </span>
                        </div>
                        <p>
                            Due {m.due_date} · Owner:{" "}
                            {m.owner_name || "Unassigned"}
                        </p>
                        {m.private_note && (
                            <p>Private HR note: {m.private_note}</p>
                        )}
                        {!m.completed_at && m.can_complete && (
                            <Complete milestone={m} />
                        )}{" "}
                        {manage && !m.completed_at && (
                            <details>
                                <summary>Edit milestone</summary>
                                <Editor
                                    milestone={m}
                                    employees={employees}
                                    owners={owners}
                                />
                            </details>
                        )}
                    </section>
                ))
            ) : (
                <section className="hr-panel">
                    <p>No milestones are available to you yet.</p>
                </section>
            )}
        </>
    );
}
