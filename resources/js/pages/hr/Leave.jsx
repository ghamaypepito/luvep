import { Head, useForm, usePage, router } from "@inertiajs/react";
import { Errors } from "./Dtr";
export function LeaveDetails({ leave }) {
    return (
        <>
            <dl className="hr-profile-grid">
                {[
                    ["Period", `${leave.start_date} – ${leave.end_date}`],
                    [
                        "Working days",
                        `${leave.total_days ?? "Historical"}${leave.half_day && leave.half_day !== "N/A" ? ` · ${leave.half_day}` : ""}`,
                    ],
                    [
                        "Type",
                        `${leave.type}${leave.other_specify ? ` · ${leave.other_specify}` : ""}`,
                    ],
                    ["Decision", leave.decision || leave.status],
                    ["Cover person", leave.cover_person || "Not provided"],
                    [
                        "Response target",
                        leave.response_due || "Historical record",
                    ],
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
            {leave.emergency_required && (
                <p className="hr-notice">
                    {leave.emergency_approved_at
                        ? "Management exception recorded. Department Head decision required."
                        : "Short-notice annual leave requires a Management exception before approval."}
                </p>
            )}
            {[
                ["Reason or comments", leave.reason],
                ["Handover", leave.handover],
                ["Comments from reviewer", leave.manager_comments],
            ].map(
                ([label, value]) =>
                    value && (
                        <p key={label}>
                            <strong>{label}: </strong>
                            {value}
                        </p>
                    ),
            )}
            {leave.sick_for && (
                <p>
                    Supervisor notified via {leave.notified_via} ·{" "}
                    {leave.notified_at
                        ? new Date(leave.notified_at).toLocaleString()
                        : "Not recorded"}
                    . Certificate:{" "}
                    {leave.certificate_status?.replaceAll("_", " ")}.
                </p>
            )}
        </>
    );
}
export default function Leave({ leaves, types, manager, today }) {
    const { hr } = usePage().props;
    const form = useForm({
        request_id: crypto.randomUUID(),
        type: "Annual Leave",
        other_specify: "",
        start_date: today,
        end_date: today,
        half_day: "N/A",
        sick_for: "self",
        notified_via: "",
        notified_at: "",
        certificate_status: "not_needed",
        team_checked: false,
        travel_acknowledged: false,
        auto_reply_planned: false,
        cover_person: "",
        handover: "",
        reason: "",
        declaration: false,
    });
    const text = (key, label, type = "text") => (
        <label>
            {label}
            <input
                type={type}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </label>
    );
    const check = (key, label) => (
        <label className="hr-checkbox" key={key}>
            <input
                type="checkbox"
                checked={form.data[key]}
                onChange={(e) => form.setData(key, e.target.checked)}
            />
            {label}
        </label>
    );
    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            notified_at: data.notified_at
                ? new Date(data.notified_at).toISOString()
                : null,
        }));
        form.post("/leave", {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData("request_id", crypto.randomUUID());
            },
        });
    };
    return (
        <>
            <Head title="My leave · Luvep" />
            <div className="hr-page-heading">
                <h1>My leave</h1>
                <p>
                    Plan time away, arrange cover and follow your request
                    through to a decision.
                </p>
            </div>
            {!hr.preview && (
                <section className="hr-panel">
                    <h2>File a leave request</h2>
                    <p>
                        Department Head: {manager || "Awaiting HR assignment"}.
                        Sick leave can be filed on the day or when you return.
                        Annual leave normally needs 14 calendar days’ notice.
                    </p>
                    <form className="hr-form" onSubmit={submit}>
                        <Errors errors={form.errors} />
                        <label>
                            Leave type
                            <select
                                value={form.data.type}
                                onChange={(e) =>
                                    form.setData("type", e.target.value)
                                }
                            >
                                {types.map((t) => (
                                    <option key={t}>{t}</option>
                                ))}
                            </select>
                        </label>
                        {form.data.type === "Other" &&
                            text("other_specify", "Specify leave type")}
                        <div className="hr-field-grid">
                            {text("start_date", "First day", "date")}
                            {text("end_date", "Last day", "date")}
                        </div>
                        <label>
                            Time off
                            <select
                                value={form.data.half_day}
                                onChange={(e) =>
                                    form.setData("half_day", e.target.value)
                                }
                            >
                                <option value="N/A">Full day(s)</option>
                                <option value="AM">Half day · morning</option>
                                <option value="PM">Half day · afternoon</option>
                            </select>
                        </label>
                        <p>
                            Working days are calculated from your schedule and
                            the configured holiday calendar.
                        </p>
                        {form.data.type === "Personal / Sick Leave" && (
                            <>
                                <label>
                                    Leave is for
                                    <select
                                        value={form.data.sick_for}
                                        onChange={(e) =>
                                            form.setData(
                                                "sick_for",
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="self">Myself</option>
                                        <option value="partner">
                                            My spouse or partner
                                        </option>
                                        <option value="dependant">
                                            A dependant
                                        </option>
                                    </select>
                                </label>
                                {text(
                                    "notified_via",
                                    "How you notified your supervisor",
                                )}
                                {text(
                                    "notified_at",
                                    "When you notified them (your device’s local time)",
                                    "datetime-local",
                                )}
                                <label>
                                    Medical certificate
                                    <select
                                        value={form.data.certificate_status}
                                        onChange={(e) =>
                                            form.setData(
                                                "certificate_status",
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="not_needed">
                                            Not needed
                                        </option>
                                        <option value="will_provide">
                                            I will provide one
                                        </option>
                                        <option value="provided_to_hr">
                                            Already provided to HR
                                        </option>
                                    </select>
                                </label>
                                <p>
                                    You do not need to include medical details
                                    here.
                                </p>
                            </>
                        )}
                        {form.data.type === "Annual Leave" && (
                            <>
                                {check(
                                    "team_checked",
                                    "I checked with my team for deadlines and overlapping leave.",
                                )}
                                {check(
                                    "travel_acknowledged",
                                    "I understand I should not book travel until approved.",
                                )}
                                {check(
                                    "auto_reply_planned",
                                    "I will set my email auto reply and WhatsApp status with my return date and cover person.",
                                )}
                            </>
                        )}
                        {text("cover_person", "Cover person")}
                        {[
                            [
                                "handover",
                                "Urgent work, meetings or appointments to hand over",
                            ],
                            ["reason", "Reason or comments (optional)"],
                        ].map(([key, label]) => (
                            <label key={key}>
                                {label}
                                <textarea
                                    value={form.data[key]}
                                    maxLength={key === "reason" ? 2000 : 5000}
                                    onChange={(e) =>
                                        form.setData(key, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                        {check(
                            "declaration",
                            "The information is correct, and I have read the Leave of Absence memo (LUV-MEMO-2026-001).",
                        )}
                        <p>
                            Your account name and submission time will record
                            your declaration.
                        </p>
                        <div>
                            <button
                                className="hr-button"
                                disabled={
                                    form.processing || !form.data.declaration
                                }
                            >
                                {form.processing
                                    ? "Submitting…"
                                    : "Submit request"}
                            </button>
                        </div>
                    </form>
                </section>
            )}
            <h2 className="hr-section-title">Request history</h2>
            {leaves.length ? (
                leaves.map((l) => (
                    <details key={l.id} className="hr-panel">
                        <summary>
                            <strong>
                                {l.start_date} · {l.type}
                            </strong>{" "}
                            · {l.status}
                        </summary>
                        <LeaveDetails leave={l} />
                        <a
                            className="hr-text-link"
                            href={`/leave/${l.id}/print`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Print / Save as PDF
                        </a>
                        {!hr.preview &&
                            ["pending", "deferred"].includes(l.status) && (
                                <button
                                    className="hr-button-secondary"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                "Cancel this pending leave request?",
                                            )
                                        )
                                            router.post(
                                                `/leave/${l.id}/cancel`,
                                                { version: l.hr_version },
                                                { preserveScroll: true },
                                            );
                                    }}
                                >
                                    Cancel request
                                </button>
                            )}
                    </details>
                ))
            ) : (
                <section className="hr-panel">
                    <p>You have no leave requests yet.</p>
                </section>
            )}
        </>
    );
}
