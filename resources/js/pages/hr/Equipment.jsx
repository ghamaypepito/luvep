import { hrPath } from "@/lib/hrPath";
import { Head, Link, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
function Return({ item }) {
    const form = useForm({ version: item.version, condition: "" });
    return (
        <form
            className="hr-form"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(hrPath(`/equipment/${item.id}/return`), {
                    preserveScroll: true,
                });
            }}
        >
            <Errors errors={form.errors} />
            <label>
                {item.source === "self_reported" ? "Condition / note" : "Condition at return"}
                <textarea
                    required
                    maxLength={2000}
                    value={form.data.condition}
                    onChange={(e) => form.setData("condition", e.target.value)}
                />
            </label>
            <div>
                <button
                    className="hr-button-secondary"
                    disabled={form.processing}
                >
                    {item.source === "self_reported" ? "Mark no longer in use" : "Record return"}
                </button>
            </div>
        </form>
    );
}
function AddOwnEquipment() {
    const form = useForm({ description: '' });
    return <details className="hr-panel"><summary><strong>Add my equipment</strong></summary>
        <p>Record equipment you use for work. Staff-added items are kept separate from HR-issued asset assignments.</p>
        <form className="hr-form" onSubmit={e => { e.preventDefault(); form.post(hrPath('/equipment/self'), { preserveScroll: true, onSuccess: () => form.reset() }); }}>
            <fieldset disabled={form.processing}><Errors errors={form.errors} />
                <label>Equipment details<textarea required maxLength={500} rows={3} value={form.data.description} onChange={e => form.setData('description', e.target.value)} placeholder="For example, laptop model, monitor or headset" /></label>
                <button className="hr-button">{form.processing ? 'Adding…' : 'Add equipment'}</button>
            </fieldset>
        </form>
    </details>;
}
export default function Equipment({ items, history, employees, scope, canAddOwn = false, canManageEquipment = false }) {
    const form = useForm({ employee_id: "", asset_tag: "", description: "" });
    return (
        <>
            <Head title="Equipment · Luvep" />
            <div className="hr-page-heading">
                <h1>Equipment</h1>
                <p>
                    Track issued equipment and retain a clear custody history.
                </p>
            </div>
            {scope === 'mine' && canManageEquipment && <p><Link className="hr-text-link" href={hrPath('/equipment')}>Manage employee equipment</Link></p>}
            {canAddOwn && <AddOwnEquipment />}
            {employees.length > 0 && (
                <details className="hr-panel">
                    <summary>
                        <strong>Issue equipment</strong>
                    </summary>
                    <form
                        className="hr-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(hrPath("/equipment"), {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Errors errors={form.errors} />
                        <label>
                            Employee
                            <select
                                required
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
                            Asset tag
                            <input
                                required
                                maxLength={80}
                                value={form.data.asset_tag}
                                onChange={(e) =>
                                    form.setData("asset_tag", e.target.value)
                                }
                            />
                        </label>
                        <label>
                            Description
                            <input
                                required
                                maxLength={500}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData("description", e.target.value)
                                }
                            />
                        </label>
                        <div>
                            <button
                                className="hr-button"
                                disabled={form.processing}
                            >
                                Record assignment
                            </button>
                        </div>
                    </form>
                </details>
            )}
            {items.length ? (
                items.map((i) => (
                    <section className="hr-panel" key={i.id}>
                        <div className="hr-section-bar">
                            <div>
                                <h2>{i.source === "self_reported" ? i.description : i.asset_tag}</h2>
                                <p>
                                    {i.source !== "self_reported" && <>{i.description} · </>}{i.employee_name}
                                    <br />{i.source === "self_reported" ? "Staff-added equipment" : "HR-issued equipment"}
                                </p>
                            </div>
                            <span className="hr-status">{i.source === "self_reported" ? (i.status === "issued" ? "In use" : "No longer in use") : i.status}</span>
                        </div>
                        <p>
                            {i.source === "self_reported" ? "Recorded" : "Issued"}{" "}
                            {i.issued_at
                                ? new Date(i.issued_at).toLocaleString()
                                : "Not recorded"}
                            {i.returned_at
                                ? ` · Returned ${new Date(i.returned_at).toLocaleString()}`
                                : ""}
                        </p>
                        {i.condition && <p>Condition: {i.condition}</p>}
                        {i.can_return && i.status === "issued" && (
                            <Return key={`${i.id}:${i.version}`} item={i} />
                        )}
                    </section>
                ))
            ) : (
                <section className="hr-panel">
                    <p>No current equipment records are available to you.</p>
                </section>
            )}
            <section className="hr-panel">
                <h2>Custody history</h2>
                {history.length ? (
                    <div className="hr-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Action</th>
                                    <th>Date</th>
                                    <th>Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            {e.asset_tag} · {e.description}
                                        </td>
                                        <td>{e.action.replaceAll("_", " ")}</td>
                                        <td>
                                            {new Date(
                                                e.created_at,
                                            ).toLocaleString()}
                                        </td>
                                        <td>{e.condition || "—"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p>Assignment and return events will appear here.</p>
                )}
            </section>
        </>
    );
}
