import { usePage } from "@inertiajs/react";
import { Clock3, RefreshCw } from "lucide-react";
import useHrClock from "../../hooks/useHrClock";
import BreakDialog from "./BreakDialog";
import ShiftReminder from "./ShiftReminder";
import "../../../css/hr.css";
const time = (seconds) =>
    `${String(Math.floor(seconds / 3600)).padStart(2, "0")}:${String(Math.floor((seconds % 3600) / 60)).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
export default function ClockWidget() {
    const { hr } = usePage().props;
    if (!hr?.enabled || !hr?.employee) return null;
    return (
        <Attendance
            key={`${hr.employee.id}:${hr.preview ? "preview" : "self"}`}
            hr={hr}
        />
    );
}
function Attendance({ hr }) {
    const enabled = true;
    const onHrHost = hr?.url && new URL(hr.url).host === window.location.host;
    const endpoint = onHrHost ? "/clock" : "/hr/clock";
    const { state, pending, error, stale, refresh, transition } = useHrClock({
        enabled,
        preview: !!hr?.preview,
        endpoint,
    });
    if (!enabled) return null;
    const disabled = pending || stale || !state;
    return (
        <section className="hr-attendance" aria-label="Attendance clock">
            <div className="hr-clock-row">
                <div className="hr-clock-state">
                    <Clock3 size={20} />
                    <div>
                        <strong>
                            {state
                                ? {
                                      working: "Working",
                                      on_break: "On break",
                                      off_shift: state.completed_today
                                          ? "Shift complete"
                                          : "Off shift",
                                  }[state.status]
                                : "Loading your clock…"}
                        </strong>
                        <small>
                            {hr.preview
                                ? "Employee preview · read-only"
                                : state?.timezone || "Your working day"}
                        </small>
                    </div>
                </div>
                <div className="hr-clock-times">
                    <div>
                        <span>Work time</span>
                        <strong data-testid="work-timer">
                            {state ? time(state.display_working_seconds) : "—"}
                        </strong>
                    </div>
                    <div>
                        <span>Break time</span>
                        <strong>
                            {state ? time(state.display_break_seconds) : "—"}
                        </strong>
                    </div>
                </div>
                {!hr.preview && (
                    <div className="hr-actions">
                        {state?.status === "off_shift" ? (
                            <button
                                className="hr-button"
                                disabled={disabled || !state.can_clock_in}
                                onClick={() => transition("clock_in")}
                            >
                                Clock in
                            </button>
                        ) : (
                            <>
                                <button
                                    className="hr-button-secondary"
                                    disabled={disabled}
                                    onClick={() =>
                                        transition(
                                            state?.status === "on_break"
                                                ? "break_in"
                                                : "break_out",
                                        )
                                    }
                                >
                                    {state?.status === "on_break"
                                        ? "Resume work"
                                        : "Start break"}
                                </button>
                                <button
                                    className="hr-button"
                                    disabled={disabled}
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                "Finish your shift and clock out?",
                                            )
                                        )
                                            transition("clock_out");
                                    }}
                                >
                                    Clock out
                                </button>
                            </>
                        )}
                    </div>
                )}
            </div>
            {state?.completed_today && (
                <p className="hr-clock-paused">
                    Your shift is complete. Open My time records to finish your
                    DTR, or ask HR for a clock correction.
                </p>
            )}
            {state?.status === "on_break" && (
                <p className="hr-clock-paused">Work time is paused.</p>
            )}
            {(error || stale) && (
                <div className="hr-clock-error" role="alert">
                    <span>
                        {stale
                            ? "Clock status may be out of date. Refresh before recording another action."
                            : error}
                    </span>
                    <button className="hr-button-secondary" onClick={refresh}>
                        <RefreshCw size={15} />
                        Refresh clock
                    </button>
                </div>
            )}
            {!hr.preview && (
                <BreakDialog
                    state={state}
                    pending={disabled}
                    resume={() => transition("break_in")}
                    error={error}
                />
            )}
            <ShiftReminder
                state={state}
                endpoint={endpoint}
                preview={!!hr.preview}
            />
        </section>
    );
}
