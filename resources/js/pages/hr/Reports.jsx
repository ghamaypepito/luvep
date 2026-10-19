import { hrPath } from "@/lib/hrPath";
import { Head, router, useForm } from "@inertiajs/react";
import { useEffect } from "react";
import { Errors } from "./Dtr";
const label = (s) => s.replaceAll("_", " ");
export default function Reports({
    type,
    types,
    filters,
    rows,
    truncated,
    employees,
    exports,
    preview,
}) {
    const f = useForm({
        type,
        ...filters,
        employee_id: filters.employee_id || "",
        department: filters.department || "",
        status: filters.status || "",
    });
    useEffect(() => {
        if (!exports.some((x) => x.status === "queued")) return;
        const id = setInterval(
            () => router.reload({ only: ["exports"] }),
            5000,
        );
        return () => clearInterval(id);
    }, [exports]);
    const statuses =
        type === "employee_status"
            ? ["candidate", "active", "archived"]
            : type === "leave"
              ? ["pending", "approved", "rejected", "deferred", "cancelled"]
              : type === "approved_hours"
                ? ["reviewed"]
                : type === "attendance"
                  ? ["legacy", "draft", "submitted", "reviewed", "returned"]
                  : [];
    return (
        <>
            <Head title="Reports · Luvep" />
            <div className="hr-page-heading">
                <h1>Reports</h1>
                <p>
                    Verified records within your access. Approved hours include
                    reviewed daily records only.
                </p>
            </div>
            <section className="hr-panel">
                <form
                    className="hr-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        f.get(hrPath("/reports"), { preserveState: false });
                    }}
                >
                    <Errors errors={f.errors} />
                    <label>
                        Report
                        <select
                            value={f.data.type}
                            onChange={(e) =>
                                router.get(hrPath("/reports"), {
                                    type: e.target.value,
                                    from: filters.from,
                                    to: filters.to,
                                })
                            }
                        >
                            {types.map((t) => (
                                <option key={t} value={t}>
                                    {label(t)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="hr-form-grid">
                        <label>
                            From
                            <input
                                required
                                type="date"
                                value={f.data.from}
                                onChange={(e) =>
                                    f.setData("from", e.target.value)
                                }
                            />
                        </label>
                        <label>
                            To
                            <input
                                required
                                type="date"
                                value={f.data.to}
                                onChange={(e) =>
                                    f.setData("to", e.target.value)
                                }
                            />
                        </label>
                        <label>
                            Employee
                            <select
                                value={f.data.employee_id}
                                onChange={(e) =>
                                    f.setData("employee_id", e.target.value)
                                }
                            >
                                <option value="">
                                    All permitted employees
                                </option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Department
                            <select
                                value={f.data.department}
                                onChange={(e) =>
                                    f.setData("department", e.target.value)
                                }
                            >
                                <option value="">
                                    All permitted departments
                                </option>
                                {[
                                    ...new Set(
                                        employees
                                            .map((e) => e.department)
                                            .filter(Boolean),
                                    ),
                                ].map((d) => (
                                    <option key={d}>{d}</option>
                                ))}
                            </select>
                        </label>
                        {statuses.length > 0 && (
                            <label>
                                Status
                                <select
                                    value={f.data.status}
                                    onChange={(e) =>
                                        f.setData("status", e.target.value)
                                    }
                                >
                                    <option value="">
                                        All applicable statuses
                                    </option>
                                    {statuses.map((s) => (
                                        <option key={s}>{s}</option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    <div className="hr-actions">
                        <button className="hr-button" disabled={f.processing}>
                            Apply filters
                        </button>
                        {!preview && (
                            <button
                                type="button"
                                className="hr-button-secondary"
                                disabled={f.processing}
                                onClick={() =>
                                    router.post(
                                        hrPath("/reports/exports"),
                                        { type, ...filters },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Export displayed report
                            </button>
                        )}
                    </div>
                </form>
            </section>
            <section className="hr-panel">
                <h2>{label(type)}</h2>
                {type === "employee_status" && (
                    <p>
                        Current employee status snapshot; the date range applies
                        to dated report types.
                    </p>
                )}
                {truncated && (
                    <p>
                        Showing the first 500 rows. Export to include all
                        matching records.
                    </p>
                )}
                {rows.length ? (
                    <div className="hr-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    {Object.keys(rows[0]).map((k) => (
                                        <th key={k}>{label(k)}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, i) => (
                                    <tr key={i}>
                                        {Object.entries(row).map(([k, v]) => (
                                            <td key={k}>
                                                {k.endsWith("_seconds")
                                                    ? `${Math.floor(v / 3600)}h ${Math.floor((v % 3600) / 60)}m`
                                                    : v || "—"}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p>No records match these filters.</p>
                )}
            </section>
            {!preview && (
                <section className="hr-panel">
                    <h2>Private exports</h2>
                    <p>
                        Downloads expire after 24 hours and remain subject to
                        your current access.
                    </p>
                    {exports.length ? (
                        <ul className="hr-employee-list">
                            {exports.map((x) => (
                                <li key={x.id}>
                                    <div>
                                        <strong>{label(x.type)}</strong>
                                        <p>
                                            {new Date(
                                                x.created_at,
                                            ).toLocaleString()}{" "}
                                            · {x.status}
                                        </p>
                                    </div>
                                    {x.status === "ready" && (
                                        <a
                                            className="hr-text-link"
                                            href={hrPath(`/reports/exports/${x.id}`)}
                                        >
                                            Download CSV
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p>Your requested exports will appear here.</p>
                    )}
                </section>
            )}
        </>
    );
}
