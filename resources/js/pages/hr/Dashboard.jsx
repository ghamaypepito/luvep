import { hrPath } from "@/lib/hrPath";
import { Head, Link, usePage } from "@inertiajs/react";
import {
    ArrowRight,
    CalendarDays,
    ClipboardCheck,
    UserRound,
    Clock3,
    FileText,
    History,
} from "lucide-react";
export default function Dashboard({
    profile,
    ownSummary = {},
    upcomingLeave = [],
    dueMilestones = 0,
}) {
    const { hr } = usePage().props;
    const firstName = profile.user?.name?.split(" ")[0] || "there";
    return (
        <>
            <Head title="Your workplace · Luvep" />
            <div className="hr-page-heading">
                <h1>Hello, {firstName}.</h1>
                <p>
                    {hr.employee
                        ? "A clear view of your working day, your time away and your next steps."
                        : "Your personal HR records and next steps, together."}
                </p>
            </div>
            {hr.employee && (
                <div className="hr-metrics">
                    {[
                        [
                            "Leave requests",
                            ownSummary.pending_leave,
                            "Waiting for a decision",
                            hrPath("/leave"),
                            CalendarDays,
                        ],
                        [
                            "Time records",
                            ownSummary.submitted_dtr,
                            "Submitted for review",
                            hrPath("/dtr"),
                            ClipboardCheck,
                        ],
                        [
                            "Needs your attention",
                            ownSummary.returned_dtr,
                            "Records returned for correction",
                            hrPath("/dtr"),
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
            {hr.employee && <section className="hr-panel hr-shortcuts-panel" aria-labelledby="hr-shortcuts-title">
                <h2 id="hr-shortcuts-title">My workspace</h2>
                <p>Your time records, report changes and leave, together.</p>
                <div className="hr-shortcuts">
                    {[
                        ["My time records", "Review your daily records and reports", "/dtr", Clock3],
                        ["My DTR changes", "Saved report changes and Head acknowledgements", "/dtr/changes?scope=mine", History],
                        ["My leave", "Plan time away and follow your requests", "/leave", CalendarDays],
                        ["My reports", "Attendance and leave for your employee record", `/reports?employee_id=${hr.employee.id}`, FileText],
                    ].map(([title, detail, path, Icon]) => <Link key={path} href={hrPath(path)} className="hr-shortcut">
                        <Icon size={20} aria-hidden="true" />
                        <span><strong>{title}</strong><small>{detail}</small></span>
                        <ArrowRight size={17} aria-hidden="true" />
                    </Link>)}
                </div>
            </section>}
            <section className="hr-panel">
                <div className="hr-section-bar">
                    <h2>Next steps</h2>
                    <span className="hr-status">
                        {dueMilestones} due milestones
                    </span>
                </div>
                <div className="hr-actions">
                    <Link href={hrPath("/milestones")} className="hr-text-link">
                        View milestones <ArrowRight size={16} />
                    </Link>
                    <Link href={hrPath("/checklists")} className="hr-text-link">
                        Onboarding & exit tasks <ArrowRight size={16} />
                    </Link>
                </div>
            </section>
            <div className="hr-dashboard-grid">
                {hr.employee && <section className="hr-panel">
                    <div className="hr-section-bar">
                        <h2>Your workplace</h2>
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
                    <Link className="hr-text-link" href={hrPath("/profile")}>
                        View your profile <ArrowRight size={16} />
                    </Link>
                </section>}
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
                        <Link className="hr-text-link" href={hrPath("/leave")}>
                            {hr.preview
                                ? "View leave records"
                                : "Plan time away"}{" "}
                            <ArrowRight size={16} />
                        </Link>
                    </section>
                )}
            </div>
            {hr.can_view_staff_status && <section className="hr-panel hr-team-invitation">
                <div><h2>Team workspace</h2><p>Staff clock states, team reviews and reporting.</p></div>
                <Link href={hrPath("/team")} className="hr-button-secondary">Open team workspace <ArrowRight size={16} /></Link>
            </section>}
        </>
    );
}
