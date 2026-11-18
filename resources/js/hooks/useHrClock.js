import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
export default function useHrClock({ enabled, preview, endpoint }) {
    const [state, setState] = useState(null);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState("");
    const [tick, setTick] = useState(Date.now());
    const inFlight = useRef(false);
    const retry = useRef(null);
    const accept = useCallback(
        (data) =>
            setState((previous) => {
                if (
                    previous &&
                    (data.version < previous.version ||
                        (data.version === previous.version &&
                            Date.parse(data.server_now) <
                                Date.parse(previous.server_now)))
                )
                    return previous;
                return { ...data, receivedAt: Date.now() };
            }),
        [],
    );
    const refresh = useCallback(async () => {
        if (!enabled) return;
        try {
            const { data } = await axios.get(endpoint, {
                timeout: 15000,
                headers: { Accept: "application/json" },
            });
            accept(data);
            setError((previous) =>
                previous.startsWith("Clock unavailable") ? "" : previous,
            );
        } catch {
            setError(
                "Clock unavailable. Check your connection and refresh before recording time.",
            );
        }
    }, [enabled, endpoint, accept]);
    useEffect(() => {
        if (!enabled) return;
        refresh();
        const poll = setInterval(() => {
            if (!document.hidden) refresh();
        }, 15000);
        const onVisible = () => {
            if (!document.hidden) refresh();
        };
        window.addEventListener("focus", refresh);
        window.addEventListener("online", refresh);
        document.addEventListener("visibilitychange", onVisible);
        return () => {
            clearInterval(poll);
            window.removeEventListener("focus", refresh);
            window.removeEventListener("online", refresh);
            document.removeEventListener("visibilitychange", onVisible);
        };
    }, [enabled, refresh]);
    useEffect(() => {
        if (!enabled) return;
        const timer = setInterval(() => setTick(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [enabled]);
    const transition = useCallback(
        async (action) => {
            if (!enabled || preview || !state || inFlight.current) return;
            inFlight.current = true;
            setPending(true);
            setError("");
            const request =
                retry.current?.action === action
                    ? retry.current
                    : {
                          action,
                          expected_version: state.version,
                          request_id: crypto.randomUUID(),
                      };
            retry.current = request;
            try {
                const { data } = await axios.post(endpoint, request, {
                    timeout: 15000,
                    headers: { Accept: "application/json" },
                });
                accept(data);
                retry.current = null;
                window.dispatchEvent(
                    new CustomEvent("hr-clock-changed", { detail: data }),
                );
            } catch (failure) {
                const data = failure.response?.data;
                setError(
                    Object.values(data?.errors || {}).flat()[0] ||
                        (failure.response?.status === 409
                            ? "Your clock changed in another tab. The latest state is loading; try again."
                            : data?.message) ||
                        "We could not confirm this action. Refresh the clock or retry; the same action will not be recorded twice.",
                );
                if (failure.response && failure.response.status < 500)
                    retry.current = null;
                await refresh();
            } finally {
                inFlight.current = false;
                setPending(false);
            }
        },
        [enabled, preview, state, endpoint, accept, refresh],
    );
    const age = state ? Math.max(0, tick - state.receivedAt) : 0;
    const stale = age > 30000;
    const elapsed = Math.floor(Math.min(age, 30000) / 1000);
    return {
        state: state
            ? {
                  ...state,
                  clock_stale: stale,
                  display_server_now: new Date(
                      Date.parse(state.server_now) + Math.min(age, 30000),
                  ).toISOString(),
                  display_working_seconds:
                      state.working_seconds +
                      (state.status === "working" ? elapsed : 0),
                  display_break_seconds:
                      state.break_seconds +
                      (state.status === "on_break" ? elapsed : 0),
              }
            : null,
        stale,
        pending,
        error,
        transition,
        refresh,
    };
}
