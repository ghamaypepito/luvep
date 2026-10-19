import { Head, useForm, router, usePage } from "@inertiajs/react";
import { Errors } from "./Dtr";
const label = (value) => value.replaceAll("_", " ");
export default function Documents({ documents, employees, classifications }) {
    const { hr } = usePage().props;
    const form = useForm({
        employee_id: "",
        document_id: "",
        title: "",
        classification: classifications.includes("hr_private")
            ? "hr_private"
            : classifications[0] || "",
        file: null,
    });
    return (
        <>
            <Head title="Documents · Luvep" />
            <div className="hr-page-heading">
                <h1>Documents</h1>
                <p>
                    Private personnel files and versions explicitly shared with
                    you.
                </p>
            </div>
            {classifications.length > 0 && !hr.preview && (
                <details className="hr-panel">
                    <summary>
                        <strong>Upload a private document</strong>
                    </summary>
                    <form
                        className="hr-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post("/documents", {
                                forceFormData: true,
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
                                    form.setData({
                                        ...form.data,
                                        employee_id: e.target.value,
                                        document_id: "",
                                    })
                                }
                            >
                                <option value="">Select employee</option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Document
                            <select
                                value={form.data.document_id}
                                onChange={(e) => {
                                    const doc = documents.find(
                                        (d) => d.id === Number(e.target.value),
                                    );
                                    form.setData({
                                        ...form.data,
                                        document_id: e.target.value,
                                        title: doc?.title || "",
                                        classification:
                                            doc?.classification ||
                                            form.data.classification,
                                    });
                                }}
                            >
                                <option value="">Create a new document</option>
                                {documents
                                    .filter(
                                        (d) =>
                                            d.employee_id ===
                                                Number(form.data.employee_id) &&
                                            d.can_manage &&
                                            !d.archived,
                                    )
                                    .map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.title} · add version
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
                                disabled={!!form.data.document_id}
                                onChange={(e) =>
                                    form.setData("title", e.target.value)
                                }
                            />
                        </label>
                        <label>
                            Classification
                            <select
                                value={form.data.classification}
                                disabled={!!form.data.document_id}
                                onChange={(e) =>
                                    form.setData(
                                        "classification",
                                        e.target.value,
                                    )
                                }
                            >
                                {classifications.map((c) => (
                                    <option key={c} value={c}>
                                        {label(c)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            File · PDF, PNG or JPEG, up to 10 MiB
                            <input
                                type="file"
                                required
                                accept="application/pdf,image/png,image/jpeg"
                                onChange={(e) =>
                                    form.setData(
                                        "file",
                                        e.target.files[0] || null,
                                    )
                                }
                            />
                        </label>
                        <p>
                            New versions remain private until an authorized
                            reviewer explicitly releases them.
                        </p>
                        <div>
                            <button
                                className="hr-button"
                                disabled={form.processing}
                            >
                                {form.processing
                                    ? "Uploading…"
                                    : "Upload private version"}
                            </button>
                        </div>
                    </form>
                </details>
            )}
            {documents.length ? (
                documents.map((d) => (
                    <section className="hr-panel" key={d.id}>
                        <div className="hr-section-bar">
                            <div>
                                <h2>{d.title}</h2>
                                <p>
                                    {d.employee_name} ·{" "}
                                    {label(d.classification)}
                                    {d.archived ? " · Archived" : ""}
                                </p>
                            </div>
                            {d.can_manage && !d.archived && (
                                <button
                                    className="hr-button-secondary"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                "Archive this document and remove employee access to every version?",
                                            )
                                        )
                                            router.post(
                                                `/documents/${d.id}/archive`,
                                                {},
                                                { preserveScroll: true },
                                            );
                                    }}
                                >
                                    Archive document
                                </button>
                            )}
                        </div>
                        <ul className="hr-employee-list">
                            {d.versions.map((v) => (
                                <li key={v.id}>
                                    <div>
                                        <strong>
                                            Version {v.version} ·{" "}
                                            {v.original_filename}
                                        </strong>
                                        <p>
                                            {Math.ceil(v.size / 1024)} KB ·{" "}
                                            {new Date(
                                                v.created_at,
                                            ).toLocaleDateString()}{" "}
                                            ·{" "}
                                            {v.released_at
                                                ? "Shared with employee"
                                                : "Private"}
                                        </p>
                                    </div>
                                    <div className="hr-actions">
                                        <a
                                            className="hr-button-secondary"
                                            href={`/documents/versions/${v.id}`}
                                        >
                                            Download
                                        </a>
                                        {d.can_manage && !d.archived && (
                                            <button
                                                className="hr-button-secondary"
                                                onClick={() =>
                                                    router.post(
                                                        `/documents/versions/${v.id}/release`,
                                                        {
                                                            released:
                                                                !v.released_at,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {v.released_at
                                                    ? "Revoke employee access"
                                                    : "Release to employee"}
                                            </button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))
            ) : (
                <section className="hr-panel">
                    <h2>No documents available</h2>
                    <p>
                        Files appear here when you have permission to access
                        them.
                    </p>
                </section>
            )}
        </>
    );
}
