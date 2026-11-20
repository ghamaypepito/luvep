import { createContext, useContext } from "react";
import { usePage } from "@inertiajs/react";
import { Clock3, Coffee, LogIn, LogOut, Play, RefreshCw } from "lucide-react";
import useHrClock from "../../hooks/useHrClock";
import BreakDialog from "./BreakDialog";
import ShiftReminder from "./ShiftReminder";
import "../../../css/hr.css";

const ClockContext = createContext(null);
const staleMessage = "Clock status may be out of date. Refresh before recording another action.";
const time = (seconds) =>
    [Math.floor(seconds / 3600), Math.floor((seconds % 3600) / 60), seconds % 60]
        .map(value => String(value).padStart(2, "0")).join(":");
const duration = (seconds) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return hours ? `${hours}h ${minutes}m` : `${minutes}m`;
};

export function HrClockProvider({ hr, children }) {
    const enabled = !!(hr?.enabled && hr?.employee);
    const preview = !!hr?.preview;
    const onHrHost = hr?.url && new URL(hr.url).host === window.location.host;
    const endpoint = hr?.portal_mode ? "/portal/hr/clock" : onHrHost ? "/clock" : "/hr/clock";
    const clock = useHrClock({ enabled, preview, endpoint });
    const { state, pending, stale, transition, error, refresh } = clock;
    return (
        <ClockContext.Provider value={{ ...clock, enabled, preview }}>
            {children}
            {enabled && !preview && <>
                <BreakDialog
                    state={state}
                    pending={pending || stale || !state}
                    resume={() => transition("break_in")}
                    error={stale ? staleMessage : error}
                    refresh={refresh}
                />
                <ShiftReminder state={state} endpoint={endpoint} preview={preview} />
            </>}
        </ClockContext.Provider>
    );
}

export default function ClockWidget({ variant = "compact", overview }) {
    const clock = useContext(ClockContext);
    if (clock) return clock.enabled ? <Attendance clock={clock} variant={variant} overview={overview} /> : null;
    return <StandaloneClock variant={variant} overview={overview} />;
}

function StandaloneClock({ variant, overview }) {
    const { hr } = usePage().props;
    if (!hr?.enabled || !hr?.employee) return null;
    return (
        <HrClockProvider key={`${hr.employee.id}:${hr.preview ? "preview" : "self"}`} hr={hr}>
            <ClockWidget variant={variant} overview={overview} />
        </HrClockProvider>
    );
}

function ClockTotal({ label, seconds, hero, testId }) {
    return <div>
        <span>{label}</span>
        <strong>
            {hero && seconds != null && <b aria-hidden="true">{duration(seconds)}</b>}
            <output aria-live="off" className={hero && seconds != null ? "sr-only" : undefined} data-testid={testId}>
                {seconds == null ? "—" : time(seconds)}
            </output>
        </strong>
    </div>;
}

function Attendance({ clock, variant, overview }) {
    const { state, pending, error, stale, refresh, transition, preview } = clock;
    const hero = variant === "hero";
    const disabled = pending || stale || !state;
    const status = state?.status;
    const limitReached = status === "working" && state?.max_working_seconds != null
        && state.display_working_seconds >= state.max_working_seconds;
    const label = {
        working: "Working",
        on_break: "On break",
        off_shift: state?.completed_today ? "Shift complete" : "Off shift",
    }[status] || "Loading your clock…";
    const timezone = state?.timezone || overview?.timezone || "UTC";
    const date = state?.display_server_now || overview?.server_now;
    const actions = !preview && <div className="hr-actions">
        {status === "off_shift" ? <button
            type="button"
            className="hr-button"
            disabled={disabled || !state.can_clock_in}
            onClick={() => transition("clock_in")}
        >
            {hero && <LogIn size={16} />} Clock in
        </button> : <>
            <button
                type="button"
                className={hero ? "hr-button" : "hr-button-secondary"}
                disabled={disabled || limitReached}
                onClick={() => transition(status === "on_break" ? "break_in" : "break_out")}
            >
                {hero && (status === "on_break" ? <Play size={16} /> : <Coffee size={16} />)}
                {status === "on_break" ? "Resume work" : "Start break"}
            </button>
            <button
                type="button"
                className={hero ? "hr-button-secondary" : "hr-button"}
                disabled={disabled}
                onClick={() => {
                    if (window.confirm("Finish your shift and clock out?")) transition("clock_out");
                }}
            >
                {hero && <LogOut size={16} />} Clock out
            </button>
        </>}
    </div>;
    const workAndBreak = <>
        <ClockTotal label="Work time" seconds={state?.display_working_seconds} hero={hero} testId="work-timer" />
        <ClockTotal label="Break time" seconds={state?.display_break_seconds} hero={hero} />
    </>;
    return (
        <section className={`hr-attendance${hero ? " hr-attendance-hero" : ""}`} aria-label="Attendance clock">
            {hero ? <>
                <div className="hr-clock-top">
                    <span className="hr-icon-tile"><Clock3 size={23} aria-hidden="true" /></span>
                    <span className={`hr-staff-state hr-staff-${status || "off_shift"}`}>{label}</span>
                </div>
                <h2>{{
                    working: "A good day, in progress.",
                    on_break: "Take a little breather.",
                    off_shift: state?.completed_today ? "Your working day is complete." : "Ready for your working day?",
                }[status] || "Your working day"}</h2>
                <p>{date && `${new Intl.DateTimeFormat("en-NZ", { weekday: "long", day: "numeric", month: "long", timeZone: timezone }).format(new Date(date))} · `}{timezone}{preview && " · Read-only preview"}</p>
                <div className="hr-clock-totals">
                    {workAndBreak}
                    <div>
                        <span>Clocked in</span>
                        <strong>{state?.started_at ? new Intl.DateTimeFormat("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: timezone }).format(new Date(state.started_at)) : "—"}</strong>
                    </div>
                </div>
                {actions}
            </> : <div className="hr-clock-row">
                <div className="hr-clock-state">
                    <Clock3 size={20} />
                    <div>
                        <strong>{label}</strong>
                        <small>{preview ? "Employee preview · read-only" : state?.timezone || "Your working day"}</small>
                    </div>
                </div>
                <div className="hr-clock-times">{workAndBreak}</div>
                {actions}
            </div>}
            {(state?.completed_today || state?.automatically_clocked_out) && (
                <p className="hr-clock-paused">
                    {state.automatically_clocked_out ? "Automatically clocked out after 10 hours of work." : "Your shift is complete."} Open My time records to finish your DTR, or ask HR for a clock correction.
                </p>
            )}
            {status && status !== "off_shift" && state?.max_working_seconds != null && <p className="hr-clock-paused" role="status">
                {limitReached ? "10-hour work limit reached. Waiting for the server to confirm automatic clock-out." : "Automatic clock-out after 10 hours of work. Break time does not count toward this limit."}
            </p>}
            {status === "on_break" && <p className="hr-clock-paused">Work time is paused.</p>}
            {(error || stale) && <div className="hr-clock-error" role="alert">
                <span>{stale ? staleMessage : error}</span>
                <button type="button" className="hr-button-secondary" onClick={refresh}>
                    <RefreshCw size={15} /> Refresh clock
                </button>
            </div>}
        </section>
    );
}
