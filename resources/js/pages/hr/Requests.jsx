import { hrPath } from "@/lib/hrPath";
import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import { Errors } from "./Dtr";
function NewRequest() {
    const form = useForm({
        category: "general",
        subject: "",
        body: "",
        request_id: crypto.randomUUID(),
    });
    return (
        <details className="hr-panel">
            <summary>
                <strong>Open a private HR request</strong>
            </summary>
            <form
                className="hr-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(hrPath("/requests"));
                }}
            >
                <Errors errors={form.errors} />
                <label>
                    Category
                    <select
                        value={form.data.category}
                        onChange={(e) =>
                            form.setData("category", e.target.value)
                        }
                    >
                        {[
                            ["general", "General HR assistance"],
                            ["employment_letter", "Employment letter"],
                            ["contact_correction", "Contact correction"],
                            ["confidential", "Confidential matter"],
                        ].map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    Subject
                    <input
                        required
                        maxLength={200}
                        value={form.data.subject}
                        onChange={(e) =>
                            form.setData("subject", e.target.value)
                        }
                    />
                </label>
                <label>
                    How can HR help?
                    <textarea
                        required
                        rows={6}
                        maxLength={20000}
                        value={form.data.body}
                        onChange={(e) => form.setData("body", e.target.value)}
                    />
                </label>
                <p>
                    Your request content is available to you and authorized HR
                    case reviewers. Managers and Admins without case permission
                    receive only a generic notice.
                </p>
                <div>
                    <button className="hr-button" disabled={form.processing}>
                        Send request to HR
                    </button>
                </div>
            </form>
        </details>
    );
}
function Conversation({ record, assignees }) {
    const { hr } = usePage().props;
    const reply = useForm({ body: "", internal: false });
    const assign = useForm({
        assignee_id: record.assigned_user_id || "",
        version: record.version,
    });
    return (
        <>
            <Link className="hr-text-link" href={hrPath("/requests")}>
                ← All HR requests
            </Link>
            <section className="hr-panel">
                <div className="hr-section-bar">
                    <div>
                        <h2>{record.subject}</h2>
                        <p>
                            Request #{record.id} ·{" "}
                            {record.status.replaceAll("_", " ")}
                            {record.employee_name
                                ? ` · ${record.employee_name}`
                                : ""}
                        </p>
                    </div>
                    {record.can_manage && record.status !== "closed" && (
                        <button
                            className="hr-button-secondary"
                            onClick={() => {
                                if (window.confirm("Close this HR request?"))
                                    router.post(
                                        hrPath(`/requests/${record.id}/close`),
                                        { version: record.version },
                                        { preserveScroll: true },
                                    );
                            }}
                        >
                            Close request
                        </button>
                    )}
                </div>
                {record.can_assign && record.status !== "closed" && (
                    <form
                        className="hr-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            assign.post(hrPath(`/requests/${record.id}/assign`), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Errors errors={assign.errors} />
                        <label>
                            Assigned case reviewer
                            <select
                                required
                                value={assign.data.assignee_id}
                                onChange={(e) =>
                                    assign.setData(
                                        "assignee_id",
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    Choose a permitted reviewer
                                </option>
                                {assignees.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div>
                            <button
                                className="hr-button-secondary"
                                disabled={assign.processing}
                            >
                                Save assignment
                            </button>
                        </div>
                    </form>
                )}
                <ol className="hr-conversation">
                    {record.messages.map((m) => (
                        <li
                            key={m.id}
                            className={m.internal ? "hr-internal-message" : ""}
                        >
                            <div>
                                <strong>{m.actor_name}</strong>
                                <span>
                                    {new Date(m.created_at).toLocaleString()}
                                    {m.internal ? " · Internal HR note" : ""}
                                </span>
                            </div>
                            <p>{m.body}</p>
                        </li>
                    ))}
                </ol>
                {!hr.preview && record.status !== "closed" && (
                    <form
                        className="hr-form hr-form-wide"
                        onSubmit={(e) => {
                            e.preventDefault();
                            reply.post(hrPath(`/requests/${record.id}/reply`), {
                                preserveScroll: true,
                                onSuccess: () => reply.reset(),
                            });
                        }}
                    >
                        <Errors errors={reply.errors} />
                        <label>
                            {reply.data.internal ? "Internal HR note" : "Reply"}
                            <textarea
                                required
                                rows={5}
                                maxLength={20000}
                                value={reply.data.body}
                                onChange={(e) =>
                                    reply.setData("body", e.target.value)
                                }
                            />
                        </label>
                        {record.can_manage && (
                            <label className="hr-checkbox">
                                <input
                                    type="checkbox"
                                    checked={reply.data.internal}
                                    onChange={(e) =>
                                        reply.setData(
                                            "internal",
                                            e.target.checked,
                                        )
                                    }
                                />
                                Internal note · hidden from the employee
                            </label>
                        )}
                        <div>
                            <button
                                className="hr-button"
                                disabled={reply.processing}
                            >
                                {reply.data.internal
                                    ? "Save internal note"
                                    : "Send reply"}
                            </button>
                        </div>
                    </form>
                )}
            </section>
        </>
    );
}
export default function Requests({ requests, case: record, assignees }) {
    const { hr } = usePage().props;
    return (
        <>
            <Head title="HR support · Luvep" />
            <div className="hr-page-heading">
                <h1>HR support</h1>
                <p>
                    A private place for questions, employment letters and help
                    from HR.
                </p>
            </div>
            {record ? (
                <Conversation
                    key={`${record.id}:${record.version ?? record.messages.at(-1)?.id}`}
                    record={record}
                    assignees={assignees}
                />
            ) : (
                <>
                    {hr.employee && !hr.preview && <NewRequest />}
                    {requests.length ? (
                        <section className="hr-panel">
                            <ul className="hr-employee-list">
                                {requests.map((r) => (
                                    <li key={r.id}>
                                        <div>
                                            {r.can_view ? (
                                                <Link
                                                    className="hr-text-link"
                                                    href={hrPath(`/requests/${r.id}`)}
                                                >
                                                    {r.subject}
                                                </Link>
                                            ) : (
                                                <strong>
                                                    HR request #{r.id} · details
                                                    restricted
                                                </strong>
                                            )}
                                            <p>
                                                {r.category.replaceAll(
                                                    "_",
                                                    " ",
                                                )}{" "}
                                                ·{" "}
                                                {new Date(
                                                    r.created_at,
                                                ).toLocaleDateString()}
                                            </p>
                                        </div>
                                        <span className="hr-status">
                                            {r.status.replaceAll("_", " ")}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : (
                        <section className="hr-panel">
                            <h2>No requests available</h2>
                            <p>Your accessible HR requests will appear here.</p>
                        </section>
                    )}
                </>
            )}
        </>
    );
}
