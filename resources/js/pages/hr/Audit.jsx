import { hrPath } from "@/lib/hrPath";
import { Head, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
export default function Audit({
    events,
    employees,
    actions,
    failures,
    filters,
}) {
    const f = useForm({
        employee_id: filters.employee_id || "",
        action: filters.action || "",
        from: filters.from,
        to: filters.to,
    });
    return (
        <>
            <Head title="HR audit · Luvep" />
            <div className="hr-page-heading">
                <h1>Activity & delivery</h1>
                <p>
                    Trace access changes and HR actions without exposing
                    confidential document or case content.
                </p>
            </div>
            <section className="hr-panel">
                <form
                    className="hr-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        f.get(hrPath("/audit"), { preserveState: false });
                    }}
                >
                    <Errors errors={f.errors} />
                    <div className="hr-form-grid">
                        <label>
                            Employee
                            <select
                                value={f.data.employee_id}
                                onChange={(e) =>
                                    f.setData("employee_id", e.target.value)
                                }
                            >
                                <option value="">All employees</option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Action
                            <select
                                value={f.data.action}
                                onChange={(e) =>
                                    f.setData("action", e.target.value)
                                }
                            >
                                <option value="">All actions</option>
                                {actions.map((a) => (
                                    <option key={a}>{a}</option>
                                ))}
                            </select>
                        </label>
                        {["from", "to"].map((k) => (
                            <label key={k}>
                                {k === "from" ? "From" : "To"}
                                <input
                                    required
                                    type="date"
                                    value={f.data[k]}
                                    onChange={(e) =>
                                        f.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <div>
                        <button className="hr-button" disabled={f.processing}>
                            Apply filters
                        </button>
                    </div>
                </form>
            </section>
            <section className="hr-panel">
                <h2>Audit trail</h2>
                <p>
                    Latest 200 matching events. Narrow the dates or action to
                    find earlier records.
                </p>
                {events.length ? (
                    <div className="hr-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Record</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            {new Date(
                                                e.created_at,
                                            ).toLocaleString()}
                                        </td>
                                        <td>{e.actor}</td>
                                        <td>{e.action}</td>
                                        <td>{e.subject}</td>
                                        <td>
                                            {Object.entries(e.metadata).map(
                                                ([k, v]) => (
                                                    <p key={k}>
                                                        {k.replaceAll("_", " ")}
                                                        :{" "}
                                                        {Array.isArray(v)
                                                            ? v.join(", ")
                                                            : String(v)}
                                                    </p>
                                                ),
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p>No events match these filters.</p>
                )}
            </section>
            <section className="hr-panel">
                <h2>Delivery follow-up</h2>
                {failures.length ? (
                    <ul>
                        {failures.map((f) => (
                            <li key={f.id}>
                                Delivery #{f.id} · {f.channel} · Event #
                                {f.event_id} · Account #{f.user_id} ·{" "}
                                {f.attempts} attempts. Contact the system
                                administrator to inspect delivery configuration.
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p>No HR deliveries have exhausted their retry limit.</p>
                )}
            </section>
        </>
    );
}
