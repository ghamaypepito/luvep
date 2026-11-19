import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import { ArrowUpRight, CalendarDays, Plus, Palette, Paperclip } from "lucide-react";
import RoleLayout from "@/components/layout/RoleLayout";
import TicketTypeTabs from "@/components/creative/TicketTypeTabs";
import CreativeBriefForm from "@/components/creative/CreativeBriefForm";
import { creativeStatuses } from "@/components/creative/AssignedCreativeWork";
import CreativeRequestActions from "@/components/creative/CreativeRequestActions";
import CreativeAnalytics from "@/components/creative/CreativeAnalytics";
import Pagination from "@/components/ui/Pagination";

export default function CreativeRequests({ requests, filters = {}, owner, designTypes, isAdmin, analytics = null }) {
    const [creating, setCreating] = useState(() => new URLSearchParams(window.location.search).has("new"));
    const rows = requests.data;
    const deleted = filters.view === "deleted";
    const queuePath = isAdmin ? "/admin/system-tickets?type=digital" : "/creative-requests";
    const due = [...rows].filter(row => row.deadline && row.status !== "completed").sort((a, b) => a.deadline.localeCompare(b.deadline)).slice(0, 3);
    return <div className="creative-workspace ticket-workspace"><Head title="Digital · Creative Requests" />
        <header className="cw-page-heading"><div><p className="cw-eyebrow">Luvep workspace</p><h1>System Tickets</h1><p className="cw-muted">One place to request support and creative work.</p></div><button className="cw-button" type="button" onClick={() => setCreating(true)}><Plus size={17} /> New creative request</button></header>
        <TicketTypeTabs active="digital" isAdmin={isAdmin} />
        {creating && <CreativeBriefForm owner={owner} designTypes={designTypes} onCancel={() => setCreating(false)} />}
        <>
            <div className="cw-queue-heading"><div><h2>{deleted ? "Deleted requests" : "Creative requests"}</h2><p className="cw-muted">{deleted ? "Briefs, files and tasks are kept here for recovery." : "Designs, videos and everything in between."}</p></div><label className="cw-filter">Status<select value={filters.status || ""} onChange={e => router.get(isAdmin ? "/admin/system-tickets" : "/creative-requests", { ...(isAdmin ? { type: "digital" } : {}), ...(deleted ? { view: "deleted" } : {}), ...(e.target.value ? { status: e.target.value } : {}) }, { preserveScroll: true })}><option value="">All requests</option>{Object.entries(creativeStatuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
            {isAdmin && <nav className="cw-queue-views" aria-label="Creative request view"><Link href={queuePath} aria-current={!deleted ? "page" : undefined}>Active requests</Link><Link href={`${queuePath}&view=deleted`} aria-current={deleted ? "page" : undefined}>Deleted requests</Link></nav>}
            {!deleted && analytics && <CreativeAnalytics analytics={analytics} owner={owner} filtered={!!filters.status} isAdmin={isAdmin} />}
            <div className={`cw-queue-grid${deleted ? " cw-queue-deleted" : ""}`}><section className="cw-panel tw-card cw-request-list" aria-label="Digital request queue"><div className="cw-list-heading"><span>Project</span><span>Status</span><span>Deadline</span></div>
                {rows.length ? rows.map(row => <article key={row.id} className="cw-request-row"><div><span className="cw-ref">{row.request_ref}</span>{deleted ? <strong className="cw-project-link">{row.title}</strong> : <Link className="cw-project-link" href={`/creative-requests/${row.id}`}>{row.title}<ArrowUpRight size={15} /></Link>}<p className="cw-muted">{row.types.join(" · ")}</p><p className="cw-row-meta">From {row.submitter} · Assigned to {row.assignee}{row.attachment_count > 0 && <span><Paperclip size={12} /> {row.attachment_count}</span>}</p><CreativeRequestActions creative={row} /></div><span className={`cw-status cw-status-${row.status}`}>{creativeStatuses[row.status]}</span>{row.deadline ? <time dateTime={row.deadline}>{row.deadline}</time> : <span className="cw-muted cw-small">No deadline</span>}</article>) : <div className="cw-empty"><Palette size={28} /><h3>{deleted ? "No deleted requests." : "No creative requests here yet."}</h3>{!deleted && <><p className="cw-muted">Create a brief to get your next design started.</p><button type="button" className="cw-button cw-secondary" onClick={() => setCreating(true)}>New creative request</button></>}</div>}
                <Pagination links={requests.links} />
            </section>{!deleted && <aside className="cw-side-rail"><section className="cw-panel tw-card"><div className="cw-rail-heading"><CalendarDays size={18} /><h3>Coming up</h3></div><p className="cw-muted cw-small">Deadlines on this page</p>{due.length ? due.map(row => <Link key={row.id} href={`/creative-requests/${row.id}`} className="cw-deadline"><time>{row.deadline}</time><strong>{row.title}</strong><span className="cw-muted">{creativeStatuses[row.status]}</span></Link>) : <p className="cw-muted cw-rail-empty">No upcoming requests in this view.</p>}</section><section className="cw-routing"><span className="cw-avatar">S</span><div><strong>{owner?.name || "Sharry"}</strong><p className="cw-muted">Creative request owner</p></div><p>Submitted briefs appear in the assigned task board and DTR creative-work panel.</p></section></aside>}</div>
        </>
    </div>;
}
CreativeRequests.layout = page => <RoleLayout>{page}</RoleLayout>;
