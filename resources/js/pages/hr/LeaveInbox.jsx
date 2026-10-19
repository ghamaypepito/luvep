import { Head, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
import { LeaveDetails } from "./Leave";
function Decision({ leave }) {
    const form = useForm({
        version: leave.hr_version,
        decision: "approved",
        comment: "",
    });
    return (
        <details className="hr-panel">
            <summary>
                <strong>{leave.employee_name}</strong> · {leave.start_date} ·{" "}
                {leave.type}
            </summary>
            <LeaveDetails leave={leave} />
            <form
                className="hr-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/leave/${leave.id}/decision`, {
                        preserveScroll: true,
                    });
                }}
            >
                <Errors errors={form.errors} />
                {leave.can_decide && (
                    <label>
                        Decision
                        <select
                            value={form.data.decision}
                            onChange={(e) =>
                                form.setData("decision", e.target.value)
                            }
                        >
                            <option value="approved">Approve</option>
                            <option value="rejected">Decline</option>
                            <option value="deferred">
                                Defer for clarification
                            </option>
                        </select>
                    </label>
                )}
                <label>
                    Comments to employee
                    <textarea
                        value={form.data.comment}
                        maxLength={5000}
                        onChange={(e) =>
                            form.setData("comment", e.target.value)
                        }
                    />
                </label>
                <div className="hr-actions">
                    {leave.can_decide && (
                        <button
                            className="hr-button"
                            disabled={form.processing}
                        >
                            Record decision
                        </button>
                    )}
                    {leave.can_emergency &&
                        leave.emergency_required &&
                        !leave.emergency_approved_at && (
                            <button
                                type="button"
                                className="hr-button-secondary"
                                disabled={
                                    form.processing || !form.data.comment.trim()
                                }
                                onClick={() =>
                                    form.post(`/leave/${leave.id}/emergency`, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Approve Management exception
                            </button>
                        )}
                </div>
            </form>
        </details>
    );
}
export default function LeaveInbox({ leaves }) {
    return (
        <>
            <Head title="Leave decisions · Luvep" />
            <div className="hr-page-heading">
                <h1>Leave decisions</h1>
                <p>
                    Department Heads decide requests. HR can oversee and record
                    an authorized fallback when needed.
                </p>
            </div>
            {leaves.length ? (
                leaves.map((l) => (
                    <Decision key={`${l.id}:${l.hr_version}`} leave={l} />
                ))
            ) : (
                <section className="hr-panel">
                    <h2>You’re up to date</h2>
                    <p>No leave requests are waiting for your decision.</p>
                </section>
            )}
        </>
    );
}
