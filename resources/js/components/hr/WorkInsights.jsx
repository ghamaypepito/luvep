import { useCallback, useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { hrPath } from "@/lib/hrPath";

const statuses = { not_started: "Not started", in_progress: "In progress", in_review: "In review", completed: "Completed" };
const reportActions = { "dtr.draft_saved": "Draft saved", "dtr.submitted": "Report submitted", "dtr.returned": "Returned for correction", "dtr.reviewed": "Report reviewed", "dtr.corrected": "Attendance correction recorded" };

export default function WorkInsights({ data }) {
    const { hr } = usePage().props;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);
    const inFlight = useRef(false);
    const load = useCallback((period, page, refresh = false) => {
        if (inFlight.current) return;
        inFlight.current = true;
        setBusy(true);
        const options = {
            headers: { "X-Luvep-HR-Insights": "1" },
            only: ["workInsights"], preserveScroll: true, preserveState: true, replace: true,
            onSuccess: () => setError(false),
            onError: () => setError(true),
            onFinish: () => { inFlight.current = false; setBusy(false); },
        };
        if (refresh) router.reload(options);
        else router.get(hrPath("/dashboard"), { work_period: period, team_page: page }, options);
    }, []);
    useEffect(() => {
        const failed = event => {
            const response = event.detail?.response;
            const headers = (response?.config || event.detail?.exception?.config)?.headers;
            const marker = headers?.get?.("X-Luvep-HR-Insights") ?? headers?.["X-Luvep-HR-Insights"];
            if (!inFlight.current || marker !== "1") return;
            // Authentication and access-denied responses keep the application's normal handling.
            if (response && response.status < 500) return;
            event.preventDefault();
            setError(true);
        };
        const stopInvalid = router.on("invalid", failed);
        const stopException = router.on("exception", failed);
        return () => { stopInvalid(); stopException(); };
    }, []);
    useEffect(() => {
        if (!data) return;
        const refresh = () => {
            if (document.visibilityState !== "hidden") load(data.period, data.members.current_page, true);
        };
        const timer = window.setInterval(refresh, 60000);
        window.addEventListener("focus", refresh);
        document.addEventListener("visibilitychange", refresh);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener("focus", refresh);
            document.removeEventListener("visibilitychange", refresh);
        };
    }, [data?.period, data?.members.current_page, load]);
    if (!data) return null;
    const timestamp = value => new Intl.DateTimeFormat("en-NZ", { timeZone: data.timezone, dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
    const periodLabel = { today: "Today", week: "This week", month: "This month" }[data.period];
    const team = data.scope !== "self";
    const report = data.daily_reports;
    return <section className="hr-panel hr-work-insights" aria-labelledby="hr-work-title" aria-busy={busy}>
        <div className="hr-section-bar">
            <div><h2 id="hr-work-title">{team ? "Team work insights" : "Your work insights"}</h2>
                <p>{data.scope === "all_staff" ? "All active internal staff" : team ? "Your assigned team and your own work" : "Your assigned work"} · {data.from} – {data.to}</p>
            </div>
            <div className="hr-actions">
                <div className="hr-work-periods" role="group" aria-label="Work insight period">
                    {[["today", "Today"], ["week", "This week"], ["month", "This month"]].map(([key, label]) => <button key={key} type="button" aria-pressed={data.period === key} disabled={busy} onClick={() => load(key, 1)}>{label}</button>)}
                </div>
                <button type="button" className="hr-button-secondary" disabled={busy} onClick={() => load(data.period, data.members.current_page, true)}>Refresh insights</button>
            </div>
        </div>
        <p className="hr-work-context" role="status">{busy ? "Updating insights…" : `Updated ${timestamp(data.as_of)}`} · {data.timezone}. Refreshes every minute while this page is visible.</p>
        {error && <p className="hr-error" role="alert">Unable to refresh insights. The previous figures remain visible; use Refresh insights to try again.</p>}
        <dl className="hr-work-summary" aria-label="Task Board summary">
            {[["In progress now", data.summary.in_progress, `${data.summary.in_review} more awaiting review`], ["Completed in period", data.summary.completed, periodLabel], ["Overdue now", data.summary.overdue, "Open tasks past their due time"], ["Tasks updated", data.summary.updated, `During ${periodLabel.toLowerCase()}`]].map(([label, count, note]) => <div key={label}><dt>{label}</dt><dd>{count}</dd><small>{note}</small></div>)}
        </dl>
        <p className="hr-work-context">Task Board counts. Current workload is a live snapshot; completions use the recorded completion date. Shared tasks count once overall and once for each assigned team member.</p>
        {data.undated_completions > 0 && <p className="hr-notice">{data.undated_completions} completed task(s) have no completion date and are excluded from the period completion count.</p>}
        {team && <>
            <h3 className="hr-work-subheading">Progress by team member</h3>
            <div className="hr-table-wrap"><table className="hr-work-team">
                <caption className="sr-only">Current workload and completions during {periodLabel.toLowerCase()}</caption>
                <thead><tr><th>Team member</th><th>Not started</th><th>In progress</th><th>In review</th><th>Completed in period</th><th>Overdue now</th></tr></thead>
                <tbody>{data.members.data.map(member => <tr key={member.id}><th scope="row">{member.name}<small>{member.role.replaceAll("_", " ")}</small></th>{["not_started", "in_progress", "in_review", "completed", "overdue"].map(key => <td key={key}>{member[key]}</td>)}</tr>)}</tbody>
            </table></div>
            {!data.members.total && <p>No active team members are available in your view yet.</p>}
            {data.members.last_page > 1 && <nav className="hr-actions hr-work-pagination" aria-label="Team progress pages">
                <button type="button" className="hr-button-secondary" disabled={busy || data.members.current_page <= 1} onClick={() => load(data.period, data.members.current_page - 1)}>Previous team members</button>
                <span>Page {data.members.current_page} of {data.members.last_page} · {data.members.total} people</span>
                <button type="button" className="hr-button-secondary" disabled={busy || data.members.current_page >= data.members.last_page} onClick={() => load(data.period, data.members.current_page + 1)}>Next team members</button>
            </nav>}
        </>}
        <div className="hr-work-feeds">
            <div>
                <h3 className="hr-work-subheading">Recent task updates</h3>
                {data.recent_updates.length ? <ul className="hr-work-updates">{data.recent_updates.map(update => <li key={update.id}>
                    <div className="hr-section-bar"><strong>{update.title}</strong><span className="hr-status">{statuses[update.status]}</span></div>
                    <p>{update.assignees.join(", ")}</p>
                    <p><time dateTime={update.updated_at}>{timestamp(update.updated_at)}</time>{update.comments > 0 && ` · ${update.comments} comment(s) in period`}{update.progress > 0 && update.status !== "completed" && ` · ${update.progress}% recorded progress`}</p>
                    {update.url && !hr.preview && <a className="hr-text-link" href={new URL(update.url, hr.main_url).href}>Open task</a>}
                </li>)}</ul> : <p>No task updates during these dates. Assignment, status changes and comments will appear here.</p>}
                <p className="hr-work-context">Latest 8 tasks with activity. Task details remain limited to your existing task access.</p>
            </div>
            <div>
                <h3 className="hr-work-subheading">Daily-report work</h3>
                <p>{report.records} report(s) for the selected work dates · {report.submitted} submitted or reviewed.</p>
                <p><strong>{report.completed_tasks} reported tasks completed</strong> · {report.in_progress_tasks} reported in progress.</p>
                <p className="hr-work-context">Self-reported work, including drafts. These counts are separate from Task Board completions.</p>
                <h4>Recent report changes</h4>
                {report.recent_updates.length ? <ul className="hr-work-updates">{report.recent_updates.map(update => <li key={update.id}>
                    <strong>{update.name}</strong><p>{reportActions[update.action] || "Report updated"} · work date {update.work_date}</p>
                    <p><time dateTime={update.recorded_at}>{timestamp(update.recorded_at)}</time> · {update.changed_fields} field(s) changed</p>
                </li>)}</ul> : <p>No daily-report updates recorded during this period. Change tracking starts from its release.</p>}
                {!hr.preview && (hr.can_view_dtr_changes || hr.employee) && <a className="hr-text-link" href={hrPath(hr.can_view_dtr_changes ? "/dtr/changes" : "/dtr")}>View {hr.can_view_dtr_changes ? "DTR changes" : "your time records"}</a>}
            </div>
        </div>
    </section>;
}
