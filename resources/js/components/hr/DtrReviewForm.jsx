import { hrPath } from "@/lib/hrPath";
import { useForm } from "@inertiajs/react";
import { Errors } from "@/pages/hr/Dtr";
export default function DtrReviewForm({ record, weekly = false, onSaved }) {
    const form = useForm({
        version: weekly ? record.version : record.hr_version,
        decision: "reviewed",
        comment: "",
    });
    return (
        <section className="hr-panel">
            <h3>
                <strong>{record.employee_name}</strong> ·{" "}
                {weekly ? `Week of ${record.week_start}` : record.work_date}
            </h3>
            {weekly ? (
                <p>
                    All included daily records have been reviewed. This confirms
                    the employee’s weekly declaration.
                </p>
            ) : null}
            {weekly && <a
                className="hr-button-secondary"
                href={hrPath(`/dtr/report?employee=${record.employee_id}&${weekly ? `week=${record.week_start}` : `date=${record.work_date}`}`)}
                target="_blank"
                rel="noreferrer"
            >
                Open printable record
            </a>}
            <form
                className="hr-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(
                        hrPath(`/dtr/${weekly ? "week/" : ""}${record.id}/review`),
                        { preserveScroll: true, onSuccess: onSaved },
                    );
                }}
            >
                <Errors errors={form.errors} />
                <label>
                    Decision
                    <select
                        value={form.data.decision}
                        onChange={(e) =>
                            form.setData("decision", e.target.value)
                        }
                    >
                        <option value="reviewed">Mark reviewed</option>
                        <option value="returned">Return for correction</option>
                    </select>
                </label>
                <label>
                    Review comment
                    <textarea
                        value={form.data.comment}
                        required={form.data.decision === "returned"}
                        maxLength={5000}
                        onChange={(e) =>
                            form.setData("comment", e.target.value)
                        }
                    />
                </label>
                <div>
                    <button className="hr-button" disabled={form.processing}>
                        Save decision
                    </button>
                </div>
            </form>
        </section>
    );
}
