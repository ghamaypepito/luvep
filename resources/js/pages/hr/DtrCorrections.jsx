import { hrPath } from "@/lib/hrPath";
import { Head, useForm } from "@inertiajs/react";
import { Errors, RecordDetails } from "./Dtr";
function Editor({ record }) {
    const f = useForm({
        version: record.hr_version,
        reason: "",
        intervals: record.shifts,
    });
    const set = (i, k, v) =>
        f.setData(
            "intervals",
            f.data.intervals.map((s, j) => (j === i ? { ...s, [k]: v } : s)),
        );
    const updateBreak = (i, b, k, v) =>
        set(
            i,
            "breaks",
            f.data.intervals[i].breaks.map((x, j) =>
                j === b ? { ...x, [k]: v } : x,
            ),
        );
    return (
        <form
            className="hr-form"
            onSubmit={(e) => {
                e.preventDefault();
                f.transform((data) => ({
                    ...data,
                    intervals: data.intervals.map((s) => ({
                        ...s,
                        started_at: s.started_at + "Z",
                        ended_at: s.ended_at + "Z",
                        breaks: s.breaks.map((b) => ({
                            started_at: b.started_at + "Z",
                            ended_at: b.ended_at + "Z",
                        })),
                    })),
                }));
                f.post(hrPath(`/dtr/${record.id}/correct`), { preserveScroll: true });
            }}
        >
            <Errors errors={f.errors} />
            <p>
                Times below are UTC. Employee time zone: {record.timezone}. A
                correction preserves the previous record and returns the DTR to
                the employee for a new declaration and review.
            </p>
            {f.data.intervals.map((s, i) => (
                <fieldset className="hr-panel" key={s.shift_id}>
                    <legend>Shift {i + 1}</legend>
                    <div className="hr-form-grid">
                        {["started_at", "ended_at"].map((k) => (
                            <label key={k}>
                                {k === "started_at"
                                    ? "Clock in (UTC)"
                                    : "Clock out (UTC)"}
                                <input
                                    required
                                    type="datetime-local"
                                    step="1"
                                    value={s[k]}
                                    onChange={(e) => set(i, k, e.target.value)}
                                />
                            </label>
                        ))}
                    </div>
                    {s.breaks.map((b, j) => (
                        <div className="hr-form-grid" key={j}>
                            {["started_at", "ended_at"].map((k) => (
                                <label key={k}>
                                    {k === "started_at"
                                        ? "Break out (UTC)"
                                        : "Break in (UTC)"}
                                    <input
                                        required
                                        type="datetime-local"
                                        step="1"
                                        value={b[k]}
                                        onChange={(e) =>
                                            updateBreak(i, j, k, e.target.value)
                                        }
                                    />
                                </label>
                            ))}
                            <button
                                type="button"
                                className="hr-text-link"
                                onClick={() =>
                                    set(
                                        i,
                                        "breaks",
                                        s.breaks.filter((_, n) => n !== j),
                                    )
                                }
                            >
                                Remove break {j + 1}
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        className="hr-button-secondary"
                        onClick={() =>
                            set(i, "breaks", [
                                ...s.breaks,
                                { started_at: "", ended_at: "" },
                            ])
                        }
                    >
                        Add break interval
                    </button>
                </fieldset>
            ))}
            <label>
                Reason for correction
                <textarea
                    required
                    maxLength={5000}
                    value={f.data.reason}
                    onChange={(e) => f.setData("reason", e.target.value)}
                />
            </label>
            <div>
                <button className="hr-button" disabled={f.processing}>
                    Save correction and return DTR
                </button>
            </div>
        </form>
    );
}
export default function DtrCorrections({ employees, record, filters }) {
    const f = useForm({
        employee_id: filters.employee_id || "",
        date: filters.date || "",
    });
    return (
        <>
            <Head title="Time corrections · Luvep" />
            <div className="hr-page-heading">
                <h1>Time corrections</h1>
                <p>
                    Correct recorded clock intervals with an explanation and a
                    preserved history.
                </p>
            </div>
            <section className="hr-panel">
                <form
                    className="hr-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        f.get(hrPath("/dtr/corrections"), { preserveState: false });
                    }}
                >
                    <Errors errors={f.errors} />
                    <div className="hr-form-grid">
                        <label>
                            Employee
                            <select
                                required
                                value={f.data.employee_id}
                                onChange={(e) =>
                                    f.setData("employee_id", e.target.value)
                                }
                            >
                                <option value="">Choose employee</option>
                                {employees.map((e) => (
                                    <option value={e.id} key={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Work date
                            <input
                                required
                                type="date"
                                value={f.data.date}
                                onChange={(e) =>
                                    f.setData("date", e.target.value)
                                }
                            />
                        </label>
                    </div>
                    <div>
                        <button
                            className="hr-button-secondary"
                            disabled={f.processing}
                        >
                            Find record
                        </button>
                    </div>
                </form>
            </section>
            {record ? (
                <section className="hr-panel">
                    <h2>
                        {record.employee_name} · {record.work_date}
                    </h2>
                    <RecordDetails entry={record} />
                    {record.can_correct ? (
                        <Editor
                            key={`${record.id}:${record.hr_version}`}
                            record={record}
                        />
                    ) : (
                        <p>
                            Only closed live clock records can be corrected
                            here.
                        </p>
                    )}
                </section>
            ) : (
                filters.employee_id && (
                    <section className="hr-panel">
                        <p>No time record exists for this employee and date.</p>
                    </section>
                )
            )}
        </>
    );
}
