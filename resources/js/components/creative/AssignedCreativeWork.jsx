import { ArrowUpRight, Palette } from "lucide-react";

export const creativeStatuses = { not_started: "Submitted", in_progress: "In design", in_review: "For review", completed: "Delivered" };

export default function AssignedCreativeWork({ requests = [], preview = false, onAddToRecord }) {
    if (!requests.length) return null;
    return <section className="creative-workspace creative-assigned" aria-label="Assigned creative requests">
        <div className="cw-section-heading"><div><p className="cw-eyebrow"><Palette size={15} /> Digital requests</p><h2>Your creative work</h2><p className="cw-muted">Assigned briefs, separate from the time you record below.</p></div><span className="cw-count">{requests.length} open</span></div>
        <div>{requests.map(request => <article key={request.id} className="cw-assigned-row">
            <div><span className="cw-ref">{request.request_ref}</span><h3>{request.title}</h3><p className="cw-muted">{request.types.join(" · ")} · {request.attachment_count} reference {request.attachment_count === 1 ? "file" : "files"}</p></div>
            <div className="cw-assigned-due"><span className={`cw-status cw-status-${request.status}`}>{creativeStatuses[request.status]}</span><p>{request.deadline ? `Due ${request.deadline}` : "No deadline"}</p></div>
            {preview ? <span className="cw-muted">Read-only preview</span> : <div className="cw-assigned-actions">{onAddToRecord && <button type="button" className="cw-button" onClick={() => onAddToRecord(request)}>Add to daily record</button>}<a className="cw-button cw-secondary" href={request.url}>Open brief <ArrowUpRight size={15} /></a></div>}
        </article>)}</div>
        <p className="cw-footnote">{onAddToRecord ? "Add to daily record pre-fills the selected draft. Enter actual work times and save the draft. No time is logged automatically." : "Opening a brief or changing its status does not clock you in or add DTR hours."}</p>
    </section>;
}
