import { useEffect, useRef, useState } from "react";
import axios from "axios";
export default function ShiftReminder({ state, endpoint, preview }) {
    const [notice, setNotice] = useState(null);
    const [dismissed, setDismissed] = useState(null);
    const checking = useRef(false);
    const seconds = state?.scheduled_end_at
        ? (Date.parse(state.scheduled_end_at) -
              Date.parse(state.display_server_now || state.server_now)) /
          1000
        : 0;
    const eligible =
        !preview &&
        state &&
        state.status !== "off_shift" &&
        !state.clock_stale &&
        seconds > 0 &&
        seconds <= 900;
    useEffect(() => {
        if (
            !eligible ||
            state.reminder_claimed ||
            notice?.shift_id === state.shift_id ||
            checking.current
        )
            return;
        checking.current = true;
        let cancelled = false;
        axios
            .post(
                `${endpoint}/reminder`,
                {},
                { timeout: 15000, headers: { Accept: "application/json" } },
            )
            .then(({ data }) => {
                if (!cancelled && data.notice) setNotice(data.notice);
            })
            .catch(() => {})
            .finally(() => {
                checking.current = false;
            });
        return () => {
            cancelled = true;
        };
    }, [state?.server_now, state?.status, endpoint, preview]);
    if (!eligible) return null;
    const minutes = Math.ceil(seconds / 60);
    const phrase = `Scheduled finish in ${minutes} minute${minutes === 1 ? "" : "s"}.`;
    if (
        !notice ||
        notice.shift_id !== state.shift_id ||
        dismissed === state.shift_id
    ) {
        return state.reminder_claimed || dismissed === state.shift_id ? (
            <p className="hr-clock-paused">
                {phrase} Remember to clock out when you finish.
            </p>
        ) : null;
    }
    return (
        <aside className="hr-shift-reminder" role="status">
            <strong>Your shift finishes soon</strong>
            <p>{phrase} Wrap up when ready and remember to clock out.</p>
            <button
                type="button"
                onClick={() => setDismissed(state.shift_id)}
                aria-label="Dismiss shift reminder"
            >
                Got it
            </button>
        </aside>
    );
}
