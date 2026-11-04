import { useEffect, useState } from "react";
import { hrPath } from "@/lib/hrPath";

const labels = {
    tasks: "Tasks", work_location: "Work location", meetings: "Meetings",
    people_assisted: "People assisted", blockers: "Blockers or support needed",
    tomorrow: "Plans for tomorrow", remarks: "Additional notes", hr_status: "Status",
    declared_name: "Declared by", submitted_at: "Submitted at",
};
const actions = {
    "dtr.draft_saved": "Draft saved", "dtr.submitted": "Daily report submitted",
    "dtr.returned": "Returned for correction", "dtr.reviewed": "Marked reviewed",
    "dtr.corrected": "Attendance correction recorded",
};
function Value({ field, value }) {
    if (value === null || value === undefined || value === "") return <span>Not set</span>;
    if (field === "tasks") return value.length ? <ul>{value.map((task, index) => <li key={index}>
        <strong>{task.task || task.description}</strong><br />
        {task.start || "—"} – {task.end || "—"} · {task.status?.replaceAll("_", " ")} · {task.category || "other"}
        {task.reference && <span> · {task.reference}</span>}
    </li>)}</ul> : <span>No tasks</span>;
    return <span>{field === "submitted_at" ? new Date(value).toLocaleString() : field === "hr_status" ? String(value).replaceAll("_", " ") : String(value)}</span>;
}
export default function DtrHistory({ entry }) {
    const [open, setOpen] = useState(false);
    const [page, setPage] = useState(1);
    const [result, setResult] = useState(null);
    const [error, setError] = useState("");
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setResult(null); setError("");
        fetch(hrPath(`/dtr/${entry.id}/history?page=${page}`), {
            credentials: "same-origin", headers: { Accept: "application/json" }, signal: controller.signal,
        }).then(async response => {
            if (!response.ok) throw new Error("Unable to load this change history. Your access may have changed.");
            const data = await response.json();
            if (!controller.signal.aborted) setResult(data.history);
        }).catch(e => { if (!controller.signal.aborted) setError(e.message); });
        return () => controller.abort();
    }, [open, entry.id, entry.hr_version, page, retry]);
    return <details className="hr-dtr-history" onToggle={e => setOpen(e.currentTarget.open)}>
        <summary>Change history</summary>
        {open && <>
            <p>Work date: <strong>{entry.work_date}</strong>. Times below show when changes were actually recorded. History starts when this feature was released.</p>
            {error ? <div role="alert"><p>{error}</p><button type="button" className="hr-button-secondary" onClick={() => setRetry(r => r + 1)}>Retry history</button></div>
                : !result ? <p role="status">Loading change history…</p> : <>
                    {!result.data.length && <p>No changes recorded since change tracking was enabled.</p>}
                    {result.data.map(revision => <section key={revision.id} className="hr-dtr-revision">
                        <h3>{actions[revision.action] || "Record updated"} · version {revision.version}</h3>
                        <p>{revision.actor} · <time dateTime={revision.created_at}>{new Date(revision.created_at).toLocaleString()}</time></p>
                        {Object.keys(revision.changes).length ? <div className="hr-table-wrap"><table>
                            <caption className="sr-only">Changes in version {revision.version}</caption>
                            <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                            <tbody>{Object.entries(revision.changes).map(([field, change]) => <tr key={field}>
                                <th scope="row">{labels[field] || field}</th><td><Value field={field} value={change.before} /></td><td><Value field={field} value={change.after} /></td>
                            </tr>)}</tbody>
                        </table></div> : <p>Saved without changes to report details.</p>}
                    </section>)}
                    {result.last_page > 1 && <nav className="hr-actions" aria-label="Change history pages">
                        <button type="button" className="hr-button-secondary" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Newer changes</button>
                        <span>Page {result.current_page} of {result.last_page}</span>
                        <button type="button" className="hr-button-secondary" disabled={page >= result.last_page} onClick={() => setPage(p => p + 1)}>Older changes</button>
                    </nav>}
                </>}
        </>}
    </details>;
}
