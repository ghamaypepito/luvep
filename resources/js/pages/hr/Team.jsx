import { Head, Link, router, usePage } from "@inertiajs/react";
import { useCallback, useEffect, useRef, useState } from "react";
import { ArrowRight, RefreshCw, Search, UsersRound } from "lucide-react";
import AbsenceCalendar from "@/components/hr/AbsenceCalendar";
import { hrPath } from "@/lib/hrPath";

const states = { working: "Active", on_break: "On break", off_shift: "Out" };
const clockTime = (value, timezone) => value ? new Date(value).toLocaleString(undefined, { timeZone: timezone, month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

export default function Team({ members = [], asOf, reviews = {}, absences = [] }) {
    const { hr } = usePage().props;
    const [filter, setFilter] = useState("all");
    const [search, setSearch] = useState("");
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState(false);
    const inFlight = useRef(false);
    const lastRefresh = useRef(Date.now());
    const refresh = useCallback(() => {
        if (inFlight.current || document.visibilityState !== "visible") return;
        inFlight.current = true;
        lastRefresh.current = Date.now();
        setRefreshing(true);
        router.reload({ only: ["members", "asOf", "reviews", "absences"], preserveScroll: true, headers: { "X-Luvep-HR-Status": "1" },
            onSuccess: () => setRefreshError(false),
            onError: () => setRefreshError(true),
            onFinish: () => { inFlight.current = false; setRefreshing(false); },
        });
    }, []);
    useEffect(() => {
        const failed = event => {
            const response = event.detail?.response;
            const headers = (response?.config || event.detail?.exception?.config)?.headers;
            const marker = headers?.get?.("X-Luvep-HR-Status") ?? headers?.["X-Luvep-HR-Status"];
            if (!inFlight.current || marker !== "1" || (response && response.status < 500)) return;
            event.preventDefault();
            setRefreshError(true);
        };
        const stopInvalid = router.on("invalid", failed);
        const stopException = router.on("exception", failed);
        return () => { stopInvalid(); stopException(); };
    }, []);
    useEffect(() => {
        const poll = () => { if (Date.now() - lastRefresh.current >= 60000) refresh(); };
        const timer = window.setInterval(poll, 60000);
        document.addEventListener("visibilitychange", poll);
        return () => { window.clearInterval(timer); document.removeEventListener("visibilitychange", poll); };
    }, [refresh]);
    const term = search.trim().toLocaleLowerCase();
    const visible = members.filter(member => (filter === "all" || member.status === filter)
        && `${member.name} ${member.department || ""}`.toLocaleLowerCase().includes(term));
    return <>
        <Head title="Team workspace · Luvep" />
        <div className="hr-page-heading"><h1>Team workspace</h1><p>Staff clock states and the HR decisions assigned to you.</p></div>
        <section className="hr-panel hr-team-tools">
            <div className="hr-section-bar"><div><h2>Team tools</h2><p>Keep personnel reviews and reports together.</p></div></div>
            <div className="hr-actions">
                {hr.permissions["leave.review"] && <Link className="hr-button-secondary" href={hrPath("/leave/review")}>Leave decisions <strong>{reviews.leave || 0}</strong></Link>}
                {hr.permissions["dtr.review"] && <Link className="hr-button-secondary" href={hrPath("/dtr/review")}>Time reviews <strong>{reviews.dtr || 0}</strong></Link>}
                {hr.can_view_dtr_changes && <Link className="hr-button-secondary" href={hrPath("/dtr/changes")}>DTR acknowledgements <ArrowRight size={16} /></Link>}
                <Link className="hr-button-secondary" href={hrPath("/reports")}>Team reports <ArrowRight size={16} /></Link>
                <Link className="hr-button-secondary" href={hrPath("/calendar")}>Team calendar <ArrowRight size={16} /></Link>
            </div>
            {reviews.overdue_leave > 0 && <p className="hr-notice">{reviews.overdue_leave} leave request(s) are past the three-working-day response target.</p>}
        </section>
        <section className="hr-panel" aria-labelledby="hr-staff-title">
            <div className="hr-section-bar"><div><h2 id="hr-staff-title">Who’s working?</h2><p>Clock states from HR shifts and breaks.</p></div><button type="button" className="hr-button-secondary" disabled={refreshing} onClick={refresh}><RefreshCw size={16} aria-hidden="true" />{refreshing ? "Refreshing…" : "Refresh status"}</button></div>
            <div className="hr-staff-filters" role="group" aria-label="Filter staff clock state">
                {[["all", "Everyone"], ...Object.entries(states)].map(([key, label]) => <button type="button" key={key} aria-pressed={filter === key} onClick={() => setFilter(key)}><span>{label}</span><strong>{key === "all" ? members.length : members.filter(member => member.status === key).length}</strong></button>)}
            </div>
            <label className="hr-staff-search"><Search size={17} aria-hidden="true" /><input type="search" aria-label="Find personnel" placeholder="Find a person or department" value={search} onChange={event => setSearch(event.target.value)} /></label>
            <div className="hr-table-wrap"><table className="hr-staff-table"><thead><tr><th>Personnel</th><th>Department</th><th>Clock state</th><th>Since</th><th>Time zone</th></tr></thead><tbody>{visible.map(member => <tr key={member.id}><th scope="row"><span className="hr-person-cell"><span className="hr-avatar" aria-hidden="true">{member.name.split(" ").map(part => part[0]).slice(0, 2).join("")}</span>{member.name}</span></th><td>{member.department || "—"}</td><td><span className={`hr-staff-state hr-staff-${member.status}`}>{states[member.status]}</span></td><td>{clockTime(member.break_started_at || member.started_at, member.timezone)}</td><td>{member.timezone}</td></tr>)}</tbody></table></div>
            {!visible.length && <div className="hr-empty"><UsersRound size={24} aria-hidden="true" /><p>{members.length ? "No personnel match your filters." : "No active personnel in your team scope."}</p>{members.length > 0 && <button className="hr-text-link" type="button" onClick={() => { setSearch(""); setFilter("all"); }}>Clear filters</button>}</div>}
            <p className="hr-staff-refresh" role="status">Last refreshed {asOf ? new Date(asOf).toLocaleString() : "—"} · Refreshes every minute while visible.</p>
            {refreshError && <p role="alert" className="hr-error">Could not refresh staff status. Showing the last confirmed clock states.</p>}
            <p className="hr-staff-refresh">Active = open shift · On break = running break · Out = no open shift. These states do not indicate browser activity.</p>
        </section>
        {hr.permissions["leave.review"] && <AbsenceCalendar absences={absences} />}
    </>;
}
