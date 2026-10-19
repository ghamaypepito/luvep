import { Head, Link, usePage } from "@inertiajs/react";
import {
    ArrowRight,
    CalendarDays,
    ClipboardCheck,
    UserRound,
} from "lucide-react";
import AbsenceCalendar from "../../components/hr/AbsenceCalendar";
export default function Dashboard({
    profile,
    ownSummary = {},
    upcomingLeave = [],
    reviews = {},
    absences = [],
}) {
    const { hr } = usePage().props;
    const firstName = profile.user?.name?.split(" ")[0] || "there";
    const reviewer =
        hr.permissions["leave.review"] || hr.permissions["dtr.review"];
    return (
        <>
            <Head title="Your workplace · Luvep" />
            <div className="hr-page-heading">
                <span className="hr-eyebrow">LUV, EP. · PEOPLE & CULTURE</span>
                <h1>Hello, {firstName}.</h1>
                <p>
                    {hr.employee
                        ? "A clear view of your working day, your time away and your next steps."
                        : "Your people, their working day and the decisions that need your attention."}
                </p>
            </div>
            {hr.employee && (
                <div className="hr-metrics">
                    {[
                        [
                            "Leave requests",
                            ownSummary.pending_leave,
                            "Waiting for a decision",
                            "/leave",
                            CalendarDays,
                        ],
                        [
                            "Time records",
                            ownSummary.submitted_dtr,
                            "Submitted for review",
                            "/dtr",
                            ClipboardCheck,
                        ],
                        [
                            "Needs your attention",
                            ownSummary.returned_dtr,
                            "Records returned for correction",
                            "/dtr",
                            ArrowRight,
                        ],
                    ].map(([label, value, detail, href, Icon]) => (
                        <Link key={label} href={href} className="hr-metric">
                            <div>
                                <span>{label}</span>
                                <Icon size={19} />
                            </div>
                            <strong>{value || 0}</strong>
                            <small>{detail}</small>
                        </Link>
                    ))}
                </div>
            )}
            {reviewer && (
                <section className="hr-panel hr-review-summary">
                    <div>
                        <span className="hr-eyebrow">YOUR REVIEW QUEUE</span>
                        <h2>Keep your team moving</h2>
                        <p>
                            {reviews.overdue_leave
                                ? `${reviews.overdue_leave} leave request(s) are past the three-working-day response target.`
                                : "Review requests and give your team a clear next step."}
                        </p>
                    </div>
                    <div className="hr-actions">
                        {hr.permissions["leave.review"] && (
                            <Link
                                href="/leave/review"
                                className="hr-button-secondary"
                            >
                                Leave decisions{" "}
                                <strong>{reviews.leave || 0}</strong>
                            </Link>
                        )}
                        {hr.permissions["dtr.review"] && (
                            <Link
                                href="/dtr/review"
                                className="hr-button-secondary"
                            >
                                Time reviews <strong>{reviews.dtr || 0}</strong>
                            </Link>
                        )}
                    </div>
                </section>
            )}
            <div className="hr-dashboard-grid">
                <section className="hr-panel">
                    <div className="hr-section-bar">
                        <h2>
                            {hr.employee
                                ? "Your workplace"
                                : "People workspace"}
                        </h2>
                        <UserRound size={20} />
                    </div>
                    <dl className="hr-detail-list">
                        <div>
                            <dt>Department</dt>
                            <dd>{profile.department || "Awaiting HR setup"}</dd>
                        </div>
                        <div>
                            <dt>Position</dt>
                            <dd>{profile.position || "Not yet recorded"}</dd>
                        </div>
                        <div>
                            <dt>Department Head</dt>
                            <dd>{profile.manager || "Not yet assigned"}</dd>
                        </div>
                        <div>
                            <dt>Operations reviewer</dt>
                            <dd>{profile.reviewer || "Not yet assigned"}</dd>
                        </div>
                    </dl>
                    <Link className="hr-text-link" href="/profile">
                        View your profile <ArrowRight size={16} />
                    </Link>
                    {hr.permissions.people && (
                        <Link className="hr-text-link" href="/people">
                            Manage employee records <ArrowRight size={16} />
                        </Link>
                    )}
                </section>
                {hr.employee && (
                    <section className="hr-panel">
                        <div className="hr-section-bar">
                            <h2>Your upcoming leave</h2>
                            <CalendarDays size={20} />
                        </div>
                        {upcomingLeave.length ? (
                            <ul className="hr-employee-list">
                                {upcomingLeave.map((l) => (
                                    <li key={l.id}>
                                        <div>
                                            <strong>{l.start_date}</strong>
                                            <p>
                                                Through {l.end_date} ·{" "}
                                                {l.total_days} working day(s)
                                            </p>
                                        </div>
                                        <span className="hr-status">
                                            {l.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="hr-empty">
                                <p>No upcoming leave recorded.</p>
                                <p>
                                    When you need time away, file a request and
                                    follow its progress here.
                                </p>
                            </div>
                        )}
                        <Link className="hr-text-link" href="/leave">
                            {hr.preview
                                ? "View leave records"
                                : "Plan time away"}{" "}
                            <ArrowRight size={16} />
                        </Link>
                    </section>
                )}
            </div>
            {hr.permissions["leave.review"] && (
                <AbsenceCalendar absences={absences} />
            )}
        </>
    );
}
