import { Head, Link, usePage } from "@inertiajs/react";
import { ArrowRight, ArrowUpRight, CalendarDays, ClipboardCheck, FileText, History } from "lucide-react";
import ClockWidget from "@/components/hr/ClockWidget";
import OverviewCalendar, { dateLabel } from "@/components/hr/OverviewCalendar";
import { hrPath } from "@/lib/hrPath";

const attendance = [
    ["working", "Working", "Clocked in"],
    ["on_break", "On break", "Break running"],
    ["off_shift", "Off shift", "No open shift"],
];

function Avatar({ name }) {
    return <span className="hr-avatar" aria-hidden="true">{name.trim().split(/\s+/).slice(0, 2).map(word => word[0]).join("")}</span>;
}

function QueueLink({ href, icon: Icon, title, detail, count, warm }) {
    const Arrow = count == null ? ArrowUpRight : ArrowRight;
    return <Link href={hrPath(href)} className="hr-queue-link">
        <span className={`hr-icon-tile${warm ? " warm" : ""}`}><Icon size={21} aria-hidden="true" /></span>
        <span><strong>{title}</strong><small>{detail}</small></span>
        {count != null && <span className="hr-status">{count}</span>}
        <Arrow size={18} aria-hidden="true" />
    </Link>;
}

function TeamAttendance({ team }) {
    return <section className="hr-panel">
        <div className="hr-section-heading">
            <div><h2>Team attendance</h2><p>Current clock states</p></div>
            <Link className="hr-text-link" href={hrPath("/team")}>View team <ArrowUpRight size={16} /></Link>
        </div>
        <div className="hr-overview-team-summary">
            {attendance.map(([status, label, detail]) => <div key={status}>
                <span className={`hr-staff-state hr-staff-${status}`}>{label}</span>
                <strong>{team.summary[status]}</strong><small>{detail}</small>
            </div>)}
        </div>
        <div className="hr-overview-staff">
            {team.members.slice(0, 3).map(member => <div key={member.id}>
                <Avatar name={member.name} /><span>{member.name}</span>
                <span className={`hr-staff-state hr-staff-${member.status}`}>{attendance.find(([status]) => status === member.status)?.[1]}</span>
            </div>)}
        </div>
        {team.members.length === 0 && <p>No staff are available in your team view.</p>}
    </section>;
}

function ReviewQueue({ reviews, hr }) {
    const permissions = hr.permissions || {};
    const rows = [
        hr.can_view_dtr_changes && { href: "/dtr/changes", icon: FileText, title: "DTR change summaries", count: reviews.acknowledgements, detail: reviews.acknowledgements ? "Awaiting acknowledgement" : "All caught up" },
        permissions["leave.review"] && { href: "/leave/review", icon: CalendarDays, title: "Leave requests", count: reviews.leave, detail: reviews.leave ? "Awaiting a decision" : "No pending requests", warm: true },
        permissions["dtr.review"] && { href: "/dtr/review", icon: ClipboardCheck, title: "Time records", count: reviews.dtr, detail: reviews.dtr ? "Awaiting review" : "No records awaiting review" },
    ].filter(Boolean);
    return <section className="hr-panel">
        <div className="hr-section-heading"><div><h2>Needs attention</h2><p>Your personnel review queue</p></div></div>
        {rows.length ? rows.map(row => <QueueLink key={row.href} {...row} />) : <p>No review queues are assigned to you.</p>}
    </section>;
}

function PersonalOverview({ summary, dueMilestones, preview }) {
    const { week_reports, week_from, week_to, pending_changes, returned_dtr } = summary;
    const weekRange = week_from && week_to && [
        ...(week_from === week_to ? [] : [dateLabel(week_from, { day: "numeric", ...(week_from.slice(0, 7) !== week_to.slice(0, 7) && { month: "long" }) })]),
        dateLabel(week_to, { day: "numeric", month: "long" }),
    ].join("–");
    const reportLink = "/dtr/changes?scope=mine";
    return <>
        <section className="hr-personal-summary" aria-label="Personal summary">
            <div>
                <span><FileText size={17} />This week</span>
                <strong>{week_reports} <small>{week_reports === 1 ? "daily report" : "daily reports"}</small></strong>
                {weekRange && <p>{weekRange}</p>}
            </div>
            <div>
                <span><History size={17} />Report changes</span>
                <strong>{pending_changes} <small>awaiting acknowledgement</small></strong>
                {!preview && <Link className="hr-text-link" href={hrPath(reportLink)}>View changes <ArrowRight size={15} /></Link>}
            </div>
        </section>
        <section className="hr-panel">
            <div className="hr-section-heading"><h2>Your next steps</h2><small>Personal records only</small></div>
            <QueueLink href="/dtr" icon={FileText} title="Review your daily records"
                detail={returned_dtr ? `${returned_dtr} returned for correction` : "See your attendance and the work behind your hours."} />
            {!preview && <QueueLink href={reportLink} icon={History} title="Review your report changes"
                detail={pending_changes ? `${pending_changes} awaiting acknowledgement` : "See saved report changes and acknowledgements."} warm />}
            {dueMilestones > 0 && <QueueLink href="/milestones" icon={ClipboardCheck} title="View milestones" detail="Due HR milestones" count={dueMilestones} />}
        </section>
    </>;
}

export default function Dashboard({ profile, ownSummary = {}, upcomingLeave = [], dueMilestones = 0, overview, teamOverview }) {
    const { hr } = usePage().props;
    const enrolled = !!hr.employee;
    const { mode, today, server_now: serverNow, timezone } = overview;
    const firstName = profile.user?.name?.trim().split(/\s+/)[0] || "there";
    const hour = Number(new Intl.DateTimeFormat("en-GB", { hour: "numeric", hourCycle: "h23", timeZone: timezone }).format(new Date(serverNow)));
    const greeting = hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
    const team = mode !== "employee" && !hr.preview && teamOverview;
    const heading = {
        admin: "Your people, at a glance.",
        head: "A clear view of your team.",
        employee: `${greeting}, ${firstName}.`,
    }[mode];
    return <>
        <Head title="Your workplace · Luvep" />
        <header className="hr-page-heading hr-overview-heading">
            <div>
                <h1>{heading}</h1>
                <p>{mode === "employee" ? "Your day, your records, and what needs your attention." : "Staff attendance, report changes and leave."}</p>
            </div>
            <time className="hr-date-label" dateTime={today}>{dateLabel(today, { weekday: "short", day: "numeric", month: "short", year: "numeric" })}</time>
        </header>
        <div className="hr-overview-grid">
            <div className="hr-main-column">
                {team && <TeamAttendance team={team} />}
                {enrolled ? mode === "employee" ? <ClockWidget variant="hero" overview={overview} /> : <section className="hr-panel">
                    <div className="hr-section-heading">
                        <h2>Your working day</h2>
                        <Link className="hr-text-link" href={hrPath("/dtr")}>My time records <ArrowRight size={16} /></Link>
                    </div>
                    <ClockWidget variant="hero" overview={overview} />
                </section> : <section className="hr-panel">
                    <h2>Your working day</h2>
                    <div className="hr-empty"><p>Clock and personal records are available after HR sets up your employee profile.</p></div>
                </section>}
                {mode === "employee" && enrolled && <PersonalOverview summary={ownSummary} dueMilestones={dueMilestones} preview={hr.preview} />}
                {team && <ReviewQueue reviews={team.reviews} hr={hr} />}
            </div>
            <aside className="hr-context-rail" aria-label="Overview context">
                <OverviewCalendar key={today} today={today} upcomingLeave={upcomingLeave} personal={enrolled} />
                {enrolled && profile.manager && <section className="hr-rail-section hr-rail-contact">
                    <Avatar name={profile.manager} />
                    <div><strong>{profile.manager}</strong><span>Your Department Head</span></div>
                </section>}
                {team && hr.can_view_dtr_changes && <section className="hr-rail-section">
                    <h3>Stay in the loop</h3>
                    <p>Review saved report changes and acknowledgement history.</p>
                    <Link className="hr-text-link" href={hrPath("/dtr/changes")}>Open DTR changes <ArrowRight size={16} /></Link>
                </section>}
            </aside>
        </div>
    </>;
}
