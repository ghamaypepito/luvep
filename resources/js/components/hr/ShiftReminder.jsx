import { useEffect, useRef, useState } from "react";
import axios from "axios";
export default function ShiftReminder({ state, endpoint, preview }) {
    const [notice, setNotice] = useState(null);
    const checking = useRef(false);
    useEffect(() => {
        if (
            preview ||
            !state ||
            state.status === "off_shift" ||
            !state.scheduled_end_at ||
            checking.current
        )
            return;
        const seconds =
            (Date.parse(state.scheduled_end_at) -
                Date.parse(state.server_now)) /
            1000;
        if (seconds <= 0 || seconds > 900) return;
        checking.current = true;
        axios
            .post(
                `${endpoint}/reminder`,
                {},
                { timeout: 15000, headers: { Accept: "application/json" } },
            )
            .then(({ data }) => {
                if (data.notice) setNotice(data.notice);
            })
            .catch(() => {})
            .finally(() => {
                checking.current = false;
            });
    }, [state?.server_now, state?.status, endpoint, preview]);
    if (!notice || state?.status === "off_shift") return null;
    return (
        <aside className="hr-shift-reminder" role="status">
            <strong>Your shift finishes soon</strong>
            <p>
                Your scheduled finish is in {notice.minutes_remaining} minutes.
                Wrap up when ready and remember to clock out.
            </p>
            <button
                type="button"
                onClick={() => setNotice(null)}
                aria-label="Dismiss shift reminder"
            >
                Got it
            </button>
        </aside>
    );
}
