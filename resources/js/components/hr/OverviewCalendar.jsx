import { useState } from "react";
import { Link } from "@inertiajs/react";
import { ArrowRight, CalendarDays, ChevronLeft, ChevronRight } from "lucide-react";
import { hrPath } from "@/lib/hrPath";

export const dateLabel = (date, options) =>
    new Intl.DateTimeFormat("en-NZ", { ...options, timeZone: "UTC" }).format(new Date(date));

const shortDate = date => dateLabel(date, { day: "numeric", month: "short" });
const statusLabel = { approved: "Approved", pending: "Awaiting leave decision", deferred: "Deferred" };

export default function OverviewCalendar({ today, upcomingLeave, personal }) {
    const [selected, setSelected] = useState(today);
    const [month, setMonth] = useState(today.slice(0, 7));
    const [year, monthNumber] = month.split("-").map(Number);
    const first = new Date(Date.UTC(year, monthNumber - 1, 1));
    const leading = (first.getUTCDay() + 6) % 7;
    const days = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();
    const leaveOnSelection = upcomingLeave.filter(leave => leave.start_date <= selected && leave.end_date >= selected);
    const leaveToShow = selected === today && !leaveOnSelection.length ? upcomingLeave.slice(0, 1) : leaveOnSelection;
    const move = amount => setMonth(new Date(Date.UTC(year, monthNumber - 1 + amount, 1)).toISOString().slice(0, 7));
    return <>
        <section className="hr-calendar" aria-label="Overview calendar">
            <div className="hr-section-heading">
                <h3>{dateLabel(first, { month: "long", year: "numeric" })}</h3>
                <div className="hr-icon-actions">
                    <button type="button" aria-label="Previous month" onClick={() => move(-1)}><ChevronLeft size={16} /></button>
                    <button type="button" aria-label="Next month" onClick={() => move(1)}><ChevronRight size={16} /></button>
                </div>
            </div>
            <div className="hr-overview-calendar-grid">
                {["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"].map(weekday =>
                    <span className="hr-weekday" key={weekday} aria-label={weekday}>{weekday[0]}</span>
                )}
                {Array.from({ length: leading }, (_, i) => <span aria-hidden="true" key={i} />)}
                {Array.from({ length: days }, (_, i) => {
                    const date = `${month}-${String(i + 1).padStart(2, "0")}`;
                    return <button key={date} type="button" aria-label={date} aria-pressed={selected === date}
                        aria-current={date === today ? "date" : undefined} onClick={() => setSelected(date)}>
                        {i + 1}
                    </button>;
                })}
            </div>
            <p className="hr-calendar-caption" aria-live="polite">
                <Link href={hrPath(`/calendar?date=${selected}&view=day`)} aria-label={`Open calendar for ${selected}`}>
                    Selected: {selected}
                </Link>
            </p>
        </section>
        {personal && <section className="hr-rail-section">
            <div className="hr-section-heading"><h3>Your leave</h3><CalendarDays size={18} /></div>
            {leaveToShow.length ? leaveToShow.map(leave => <div key={leave.id}>
                <p className="hr-leave-date">
                    <strong>{shortDate(leave.start_date)}</strong>{" "}
                    <small>{leave.end_date !== leave.start_date ? `– ${shortDate(leave.end_date)}` : dateLabel(leave.start_date, { weekday: "long" })}</small>
                </p>
                <span className={`hr-staff-state hr-staff-${leave.status === "approved" ? "working" : "on_break"}`}>{statusLabel[leave.status]}</span>
            </div>) : <p>{selected === today ? "No upcoming leave recorded." : "No upcoming leave shown for this date."}</p>}
            <Link className="hr-text-link" href={hrPath("/leave")}>View leave records <ArrowRight size={15} /></Link>
        </section>}
    </>;
}
