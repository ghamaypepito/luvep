import { hrPath } from "@/lib/hrPath";
import { Head, Link, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
export default function EmployeeSetup({
    employee,
    candidates,
    reviewers,
    timezones,
}) {
    const s = employee?.schedule || {};
    const form = useForm(
        employee
            ? {
                  version: employee.version,
                  apply_default_hours: false,
                  status: employee.status,
                  employee_number: employee.employee_number || "",
                  department: employee.department || "",
                  position: employee.position || "",
                  office: employee.office || "",
                  employment_type: employee.employment_type || "",
                  employment_start:
                      employee.employment_start?.slice(0, 10) || "",
                  employment_end: employee.employment_end?.slice(0, 10) || "",
                  manager_user_id: employee.manager_user_id || "",
                  reviewer_user_id: employee.reviewer_user_id || "",
                  timezone: s.timezone || "Asia/Manila",
                  team: s.team || "Philippines",
                  schedule_type: s.schedule_type || "fixed",
                  sched_in: s.sched_in?.slice(0, 5) || "08:00",
                  sched_out: s.sched_out?.slice(0, 5) || "17:00",
                  working_days: s.weekly_schedule
                      ? Object.keys(s.weekly_schedule).filter(
                            (d) => s.weekly_schedule[d].on,
                        )
                      : ["mon", "tue", "wed", "thu", "fri", "sat"],
              }
            : { user_id: "" },
    );
    const field = (key, label, type = "text") => (
        <label key={key}>
            {label}
            <input
                type={type}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </label>
    );
    return (
        <>
            <Head title="Employee setup · Luvep" />
            <div className="hr-page-heading">
                <h1>{employee ? employee.name : "Enroll an employee"}</h1>
                <p>
                    {employee
                        ? "Employment details, working schedule and review responsibilities."
                        : "Enroll an existing internal Luvep account. New records start as candidates until their setup is complete."}
                </p>
            </div>
            <section className="hr-panel">
                <form
                    className="hr-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (employee)
                            form.patch(hrPath(`/people/${employee.id}`), {
                                preserveScroll: true,
                                onSuccess: () =>
                                    form.setData(
                                        "version",
                                        form.data.version + 1,
                                    ),
                            });
                        else form.post(hrPath("/people"));
                    }}
                >
                    <Errors errors={form.errors} />
                    {!employee ? (
                        <label>
                            Existing staff account
                            <select
                                required
                                value={form.data.user_id}
                                onChange={(e) =>
                                    form.setData("user_id", e.target.value)
                                }
                            >
                                <option value="">Select a staff member</option>
                                {candidates.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name} · {u.email}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : (
                        <>
                            <label>
                                Employment status
                                <select
                                    value={form.data.status}
                                    onChange={(e) =>
                                        form.setData("status", e.target.value)
                                    }
                                >
                                    <option value="candidate">
                                        Candidate · setup in progress
                                    </option>
                                    <option value="active">
                                        Active · employee access enabled
                                    </option>
                                    <option value="archived">
                                        Archived · employee access disabled
                                    </option>
                                </select>
                            </label>
                            <div className="hr-field-grid">
                                {[
                                    ["employee_number", "Employee ID"],
                                    ["department", "Department"],
                                    ["position", "Position"],
                                    ["office", "Office"],
                                    ["employment_type", "Employment type"],
                                ].map(([k, l]) => field(k, l))}
                                {field(
                                    "employment_start",
                                    "Employment start",
                                    "date",
                                )}
                                {field(
                                    "employment_end",
                                    "Employment end",
                                    "date",
                                )}
                            </div>
                            {[
                                ["manager_user_id", "Department Head"],
                                [
                                    "reviewer_user_id",
                                    "Operations / DTR reviewer",
                                ],
                            ].map(([key, label]) => (
                                <label key={key}>
                                    {label}
                                    <select
                                        value={form.data[key]}
                                        onChange={(e) =>
                                            form.setData(key, e.target.value)
                                        }
                                    >
                                        <option value="">Not assigned</option>
                                        {reviewers.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ))}
                            <h2>Working schedule</h2>
                            <label>
                                Time zone
                                <select
                                    value={form.data.timezone}
                                    onChange={(e) =>
                                        form.setData("timezone", e.target.value)
                                    }
                                >
                                    {timezones.map((t) => (
                                        <option key={t}>{t}</option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Holiday calendar
                                <select
                                    value={form.data.team}
                                    onChange={(e) =>
                                        form.setData("team", e.target.value)
                                    }
                                >
                                    <option>Philippines</option>
                                    <option>New Zealand</option>
                                </select>
                            </label>
                            <label>
                                Schedule type
                                <select
                                    value={form.data.schedule_type}
                                    onChange={(e) =>
                                        form.setData(
                                            "schedule_type",
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="fixed">Fixed</option>
                                    <option value="flexi">
                                        Flexible · no automatic finish reminder
                                    </option>
                                </select>
                            </label>
                            <div className="hr-field-grid">
                                {field("sched_in", "Scheduled start", "time")}
                                {field("sched_out", "Scheduled finish", "time")}
                            </div>
                            <label className="hr-checkbox">
                                <input
                                    type="checkbox"
                                    checked={form.data.apply_default_hours}
                                    onChange={(e) =>
                                        form.setData(
                                            "apply_default_hours",
                                            e.target.checked,
                                        )
                                    }
                                />
                                Apply the default start and finish times to
                                every working day
                            </label>
                            <p>
                                Existing daily hours are preserved unless this
                                is selected.
                            </p>
                            <fieldset>
                                <legend>Working days</legend>
                                <div className="hr-day-picker">
                                    {[
                                        "mon",
                                        "tue",
                                        "wed",
                                        "thu",
                                        "fri",
                                        "sat",
                                        "sun",
                                    ].map((day) => (
                                        <label
                                            className="hr-checkbox"
                                            key={day}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={form.data.working_days.includes(
                                                    day,
                                                )}
                                                onChange={(e) =>
                                                    form.setData(
                                                        "working_days",
                                                        e.target.checked
                                                            ? [
                                                                  ...form.data
                                                                      .working_days,
                                                                  day,
                                                              ]
                                                            : form.data.working_days.filter(
                                                                  (d) =>
                                                                      d !== day,
                                                              ),
                                                    )
                                                }
                                            />
                                            {day}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <p>
                                Actual recorded breaks determine DTR work time.
                                Schedule changes apply to future shifts;
                                existing shift snapshots stay unchanged.
                            </p>
                        </>
                    )}
                    <div className="hr-actions">
                        <button
                            className="hr-button"
                            disabled={form.processing}
                        >
                            {employee
                                ? "Save employee setup"
                                : "Create candidate record"}
                        </button>
                        <Link className="hr-button-secondary" href={hrPath("/people")}>
                            Back to people
                        </Link>
                    </div>
                </form>
            </section>
        </>
    );
}
