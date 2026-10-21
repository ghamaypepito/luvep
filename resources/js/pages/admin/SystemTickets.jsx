import { useEffect, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Ticket, Search, X } from "lucide-react";
import TicketConversation from "@/components/TicketConversation";
import Pagination from "@/components/ui/Pagination";

const STATUS_META = {
    open:        { label: "Open",        cls: "bg-blue-50 text-blue-700 border-blue-100" },
    in_review:   { label: "In review",   cls: "bg-indigo-50 text-indigo-700 border-indigo-100" },
    planned:     { label: "Planned",     cls: "bg-purple-50 text-purple-700 border-purple-100" },
    in_progress: { label: "In progress", cls: "bg-amber-50 text-amber-700 border-amber-100" },
    done:        { label: "Done",        cls: "bg-emerald-50 text-emerald-700 border-emerald-100" },
    declined:    { label: "Declined",    cls: "bg-gray-100 text-gray-500 border-gray-200" },
};
const PRIORITY_CLS = { low: "text-gray-400", normal: "text-gray-600", high: "text-amber-600", urgent: "text-rose-600" };
const CAT_LABEL = { change: "Change", feature: "Feature", bug: "Bug", other: "Other" };
const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString("en-US", { day: "2-digit", month: "short", year: "numeric" }) : "—");
const titleCase = (s) => (s || "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

export default function SystemTickets({ tickets = { data: [], links: [] }, filters = {}, meta = {}, counts = {}, assignees = [] }) {
    const rows = tickets?.data ?? [];
    const [search, setSearch] = useState(filters.search ?? "");
    const [active, setActive] = useState(null); // ticket open in the detail drawer

    const setFilter = (next = {}) => {
        const params = {
            tab: next.tab ?? filters.tab ?? "active",
            status: next.status !== undefined ? next.status : filters.status ?? "",
            search: next.search !== undefined ? next.search : search,
        };
        Object.keys(params).forEach((k) => !params[k] && delete params[k]);
        router.get("/admin/system-tickets", params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const statusTabs = ["", ...(meta.statuses ?? [])];

    return (
        <div className="space-y-6 max-w-6xl mx-auto">
            <Head title="System Tickets" />

            <header className="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Ticket className="w-6 h-6 text-gray-700" /> System Tickets
                        {(counts.open ?? 0) > 0 && <span className="text-xs font-bold bg-blue-600 text-white rounded-full px-2 py-0.5">{counts.open} open</span>}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Change &amp; feature tickets raised by the departments.</p>
                </div>
            </header>

            <nav className="flex flex-wrap gap-4 text-sm font-medium" aria-label="Ticket views">
                <button onClick={() => setFilter({ tab: "active" })} className={(filters.tab ?? "active") === "active" ? "underline" : "text-gray-500"}>Active tickets</button>
                <button onClick={() => setFilter({ tab: "archived" })} className={filters.tab === "archived" ? "underline" : "text-gray-500"}>Archive</button>
                <a href="/admin/system-tickets/analytics" className="text-blue-700">Analytics</a>
                <a href="/admin/system-tickets/documentation" className="text-blue-700">Documentation</a>
            </nav>
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex gap-1.5 flex-wrap">
                    {statusTabs.map((s) => (
                        <button key={s || "all"} onClick={() => setFilter({ status: s })}
                            className={`px-3 py-1.5 rounded-lg text-sm font-medium ${(filters.status ?? "") === s ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-100"}`}>
                            {s ? STATUS_META[s]?.label ?? titleCase(s) : "All"}
                        </button>
                    ))}
                </div>
                <form onSubmit={(e) => { e.preventDefault(); setFilter({ search }); }} className="relative flex-1 min-w-[200px] max-w-xs ml-auto">
                    <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search tickets…" className="w-full pl-9 pr-3 py-2 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300" />
                </form>
            </div>

            <div className="bg-white rounded-3xl border border-gray-50 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="bg-gray-50/50 border-y border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <th className="px-6 py-3">Request</th>
                                <th className="px-6 py-3">Department</th>
                                <th className="px-6 py-3">Type</th>
                                <th className="px-6 py-3">Status</th>
                                <th className="px-6 py-3">Submitted</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {rows.length === 0 ? (
                                <tr><td colSpan={5} className="px-6 py-16 text-center text-sm text-gray-400">No tickets yet.</td></tr>
                            ) : rows.map((t) => (
                                <tr key={t.id} onClick={() => setActive(t)} className="hover:bg-gray-50/40 cursor-pointer">
                                    <td className="px-6 py-3">
                                        <button onClick={() => setActive(t)} className="font-semibold text-gray-900 text-sm text-left hover:underline">{t.title}</button>
                                        <p className="text-xs text-gray-500">Owner: {t.assignee || "Unassigned"}</p>
                                        <div className="text-xs text-gray-400">{t.ticket_ref}{t.submitter ? ` · ${t.submitter}` : ""}</div>
                                    </td>
                                    <td className="px-6 py-3 text-sm text-gray-600 capitalize">{t.department || "—"}</td>
                                    <td className="px-6 py-3 text-sm">
                                        <span className="text-gray-600">{CAT_LABEL[t.category] ?? t.category}</span>
                                        <span className={`ml-2 text-[11px] font-semibold ${PRIORITY_CLS[t.priority] ?? ""}`}>{titleCase(t.priority)}</span>
                                    </td>
                                    <td className="px-6 py-3">
                                        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${STATUS_META[t.status]?.cls ?? "bg-gray-100 text-gray-500"}`}>{STATUS_META[t.status]?.label ?? titleCase(t.status)}</span>
                                    </td>
                                    <td className="px-6 py-3 text-sm text-gray-500">{fmt(t.created_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination links={tickets?.links ?? []} />

            {active && <TicketDrawer ticket={rows.find((row) => row.id === active.id) ?? active} statuses={meta.statuses ?? []} assignees={assignees} onClose={() => setActive(null)} />}
        </div>
    );
}

const fieldClass = "w-full px-3 py-2 rounded-lg border border-gray-300 text-sm bg-white";
const reportFields = { issue_summary: "Issue summary", root_cause: "Root cause", resolution: "Resolution", verification: "Verification", references: "References", follow_up: "Follow-up", documentation_summary: "Documentation summary" };
function TicketDrawer({ ticket, statuses, assignees, onClose }) {
    const dialogRef = useRef(null);
    useEffect(() => {
        const previous = document.activeElement;
        const oldOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        return () => { document.body.style.overflow = oldOverflow; previous?.focus?.(); };
    }, []);
    const trapFocus = (e) => {
        if (e.key === "Escape") { onClose(); return; }
        if (e.key !== "Tab") return;
        const nodes = [...dialogRef.current.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), textarea:not([disabled]), select:not([disabled])')];
        const first = nodes[0], last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
        if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
    };
    const [form, setForm] = useState({ status: ticket.status, admin_response: ticket.admin_response ?? "", assigned_to: ticket.assigned_to ?? "", waiting_on: ticket.waiting_on ?? "support", topic: ticket.topic ?? "", response_target_hours: ticket.response_target_hours ?? "" });
    const [report, setReport] = useState(ticket.resolution_report ?? {});
    const [publication, setPublication] = useState({ title: ticket.title, body: "", reviewed: false });
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [notice, setNotice] = useState("");
    const change = (key, value) => setForm((old) => ({ ...old, [key]: value }));
    const post = (url, data, message, close = false) => {
        setSaving(true); setErrors({}); setNotice("");
        router.post(url, data, { preserveScroll: true, onError: setErrors, onFinish: () => setSaving(false), onSuccess: () => { setNotice(message); if (close) onClose(); } });
    };
    return <div className="fixed inset-0 bg-black/40 z-50 flex justify-end" onMouseDown={onClose}>
        <div ref={dialogRef} role="dialog" aria-modal="true" aria-label={ticket.title} className="w-full max-w-2xl bg-white h-full shadow-xl overflow-y-auto" onMouseDown={(e) => e.stopPropagation()} onKeyDown={trapFocus}>
            <header className="flex items-start justify-between gap-3 px-6 py-4 border-b border-gray-100">
                <div><h2 className="text-lg font-bold text-gray-900">{ticket.title}</h2><p className="text-xs text-gray-500">{ticket.ticket_ref} · {ticket.department} · {ticket.submitter}</p></div>
                <button autoFocus onClick={onClose} aria-label="Close ticket" className="p-2"><X size={20} /></button>
            </header>
            <div className="p-6 space-y-6">
                {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>}
                {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}
                <section><h3 className="text-sm font-semibold mb-2">Request</h3><p className="text-sm text-gray-700 whitespace-pre-wrap">{ticket.description}</p></section>
                {!!ticket.images?.length && <div className="flex flex-wrap gap-2">{ticket.images.map((src, i) => <a key={src} href={src} target="_blank" rel="noopener noreferrer"><img src={src} alt={`Screenshot ${i + 1}`} className="w-24 h-24 rounded-lg object-cover border" /></a>)}</div>}
                {ticket.admin_response && <section className="rounded-xl bg-gray-50 p-3"><h3 className="text-xs font-semibold text-gray-500">Saved admin response</h3><p className="whitespace-pre-wrap text-sm mt-1">{ticket.admin_response}</p></section>}
                <TicketConversation ticket={ticket} />
                {!ticket.archived_at && <section className="space-y-4 border-t pt-5">
                    <h3 className="font-semibold">Ticket management</h3>
                    <div className="grid sm:grid-cols-2 gap-3">
                        <label className="text-sm">Status<select className={fieldClass} value={form.status} onChange={(e) => change("status", e.target.value)}>{statuses.map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}</select></label>
                        <label className="text-sm">Owner<select className={fieldClass} value={form.assigned_to} onChange={(e) => change("assigned_to", e.target.value)}><option value="">Unassigned</option>{assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select></label>
                        <label className="text-sm">Waiting on<select className={fieldClass} value={form.waiting_on} onChange={(e) => change("waiting_on", e.target.value)}>{["support", "requester", "external"].map((s) => <option key={s}>{s}</option>)}</select></label>
                        <label className="text-sm">Topic<input className={fieldClass} value={form.topic} onChange={(e) => change("topic", e.target.value)} maxLength={120} /></label>
                        <label className="text-sm">Response target (hours)<input type="number" min="1" max="8760" step="1" className={fieldClass} value={form.response_target_hours} onChange={(e) => change("response_target_hours", e.target.value)} /></label>
                    </div>
                    <p className="text-xs text-gray-500">Assignment tracks ownership. It does not grant access to this conversation.</p>
                    <label className="block text-sm">Response to the department<textarea rows={3} className={fieldClass} value={form.admin_response} onChange={(e) => change("admin_response", e.target.value)} /></label>
                    <p className="text-xs text-gray-500">Save a completed resolution report below before setting this ticket to Done.</p>
                    <button disabled={saving} className="rounded-lg bg-gray-900 text-white px-4 py-2 text-sm disabled:opacity-50" onClick={() => post(`/admin/system-tickets/${ticket.id}`, { ...form, assigned_to: form.assigned_to || null, topic: form.topic || null, response_target_hours: form.response_target_hours || null }, "Ticket updated.", true)}>Save & notify department</button>
                </section>}
                <section className="space-y-3 border-t pt-5">
                    <div className="flex justify-between gap-2"><h3 className="font-semibold">Resolution report</h3>{ticket.resolution_report && <a className="text-sm text-blue-700 underline" href={`/system-tickets/${ticket.id}/resolution`}>Download report</a>}</div>
                    {Object.entries(reportFields).map(([key, label]) => <label key={key} className="block text-sm">{label}<textarea readOnly={!!ticket.archived_at} rows={key === "resolution" ? 4 : 2} className={fieldClass} value={report[key] ?? ""} onChange={(e) => setReport((old) => ({ ...old, [key]: e.target.value }))} /></label>)}
                    {!ticket.archived_at && <button disabled={saving} onClick={() => post(`/admin/system-tickets/${ticket.id}/resolution`, { report }, "Resolution report saved.")} className="border border-gray-300 px-4 py-2 rounded-lg text-sm disabled:opacity-50">Save resolution report</button>}
                </section>
                {!ticket.archived_at && <section className="space-y-3 border-t pt-5">
                    <h3 className="font-semibold">Publish documentation</h3><p className="text-sm text-gray-500">Write a reusable, sanitized article. Remove personal details, credentials, and private ticket information before publishing.</p>
                    <label className="block text-sm">Article title<input className={fieldClass} value={publication.title} onChange={(e) => setPublication({ ...publication, title: e.target.value })} /></label>
                    <label className="block text-sm">Article body<textarea rows={6} className={fieldClass} value={publication.body} onChange={(e) => setPublication({ ...publication, body: e.target.value })} /></label>
                    <label className="flex gap-2 text-sm"><input type="checkbox" checked={publication.reviewed} onChange={(e) => setPublication({ ...publication, reviewed: e.target.checked })} />I reviewed this article and removed sensitive information.</label>
                    <button disabled={saving || !publication.reviewed || !publication.body.trim() || !publication.title.trim()} onClick={() => post(`/admin/system-tickets/${ticket.id}/publish`, publication, "Documentation published.")} className="rounded-lg bg-gray-900 text-white px-4 py-2 text-sm disabled:opacity-50">Publish documentation</button>
                </section>}

            </div>
        </div>
    </div>;
}
