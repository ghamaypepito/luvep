import { CheckCheck, Clock3, Inbox, MessageSquare } from "lucide-react";
import { TicketTrendChart, TicketStatusChart } from "@/components/tickets/TicketCharts";
import { creativeStatuses } from "./AssignedCreativeWork";

const deliverySeries = [{ key: "created", label: "Created" }, { key: "delivered", label: "Delivered" }];
const openStatuses = ["not_started", "in_progress", "in_review"];
const number = (value) => Number(value ?? 0).toLocaleString();

export default function CreativeAnalytics({ analytics, owner, filtered, isAdmin }) {
    const counts = analytics.status_counts;
    const open = openStatuses.reduce((total, status) => total + counts[status], 0);
    const owned = analytics.owner_counts;
    const ownerOpen = owned ? openStatuses.reduce((total, status) => total + owned[status], 0) : 0;
    const cards = [
        ["created", "Creative requests", analytics.total, "All matching briefs", Inbox],
        ["resolved", "Delivered requests", counts.completed, "Briefs marked Delivered", CheckCheck],
        ["unresolved", "Open workload", open, "Submitted, in design or for review", Clock3],
        ["unassigned", "For review", counts.in_review, "Designs awaiting approval", MessageSquare],
    ];
    return <section className="cw-analytics" aria-label="Creative request analytics">
        <div className="tw-section-heading"><div><h2>Creative overview</h2><p>{filtered ? "Status filter applies to the analytics and queue." : "All statuses, across every page of the active queue."}{!isAdmin && " Only requests you can access are included."} Deleted requests are excluded.</p></div></div>
        <section className="tw-summary" aria-label="Creative summary metrics">{cards.map(([key, title, count, hint, Icon]) => <article className={`tw-card tw-metric tw-metric-${key}`} key={key}><div><span className="tw-metric-icon"><Icon size={20} aria-hidden="true" /></span></div><h3>{title}</h3><p className="tw-metric-value">{number(count)}</p><p className="tw-muted">{hint}</p></article>)}</section>
        <div className="tw-chart-grid"><TicketTrendChart trends={analytics.trends} title="Requests & deliveries" description="Last 8 weeks · week starting Monday · Asia/Manila" series={deliverySeries} /><TicketStatusChart rows={Object.entries(counts).map(([status, count]) => ({ label: creativeStatuses[status], count }))} title="Creative request status" description="Current status of all matching briefs" totalLabel="Total requests" /></div>
        {analytics.undated_deliveries > 0 && <p className="tw-muted">{number(analytics.undated_deliveries)} delivered {analytics.undated_deliveries === 1 ? "request has" : "requests have"} no recorded delivery date. Included in the total, excluded from the weekly chart.</p>}
        <section className="tw-card cw-owner-workload" aria-label="Creative owner workload"><div><span className="cw-avatar" aria-hidden="true">{owner?.name?.charAt(0) || "?"}</span><h2>{owner ? `${owner.name}’s workload` : "Creative owner workload"}</h2><p className="tw-muted">{owned ? "Assigned requests in this view. Delivered requests are excluded from open workload." : "The creative request owner is unavailable."}</p>{owned && <p className="cw-owner-open"><strong>{number(ownerOpen)}</strong> open requests</p>}</div>{owned && <dl className="cw-owner-stages">{openStatuses.map(status => <div key={status}><div><dt>{creativeStatuses[status]}</dt><dd>{number(owned[status])}</dd></div><div className="tw-track" aria-hidden="true"><div style={{ width: `${owned[status] / Math.max(1, ownerOpen) * 100}%` }} /></div></div>)}</dl>}</section>
    </section>;
}
