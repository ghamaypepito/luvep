import { useState } from "react";
import TicketConversation from "@/components/TicketConversation";
import { Head } from "@inertiajs/react";
import { Ticket, MessageSquare } from "lucide-react";
import Pagination from "@/components/ui/Pagination";
import RequestTicketButton from "@/components/RequestTicketButton";

// Status / priority styling mirrors the admin board (admin/SystemTickets)
// so a ticket looks the same to the person who raised it and the triager.
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

export default function MyTickets({ tickets = { data: [], links: [] }, counts = {}, filters = {} }) {
    const rows = tickets?.data ?? [];
    const [expanded, setExpanded] = useState(null);

    return (
        <div className="space-y-6 max-w-4xl mx-auto">
            <Head title="My Tickets" />

            <header className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Ticket className="w-6 h-6 text-gray-700" /> My Tickets
                        {(counts.open ?? 0) > 0 && (
                            <span className="text-xs font-bold bg-blue-600 text-white rounded-full px-2 py-0.5">{counts.open} open</span>
                        )}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Changes &amp; features you've requested, and the admin team's replies.</p>
                </div>
                <RequestTicketButton />
            </header>

            <nav className="flex gap-4 text-sm" aria-label="Ticket views"><a href="/portal/tickets" className={(filters.tab ?? "active") === "active" ? "font-semibold underline" : "text-gray-500"}>Active tickets</a><a href="/portal/tickets?tab=archived" className={filters.tab === "archived" ? "font-semibold underline" : "text-gray-500"}>Archive</a></nav>
            {rows.length === 0 ? (
                <div className="bg-white rounded-3xl border border-gray-50 shadow-sm px-6 py-16 text-center">
                    <Ticket className="w-10 h-10 text-gray-200 mx-auto mb-3" />
                    <p className="text-sm font-medium text-gray-500">You haven't raised any tickets yet.</p>
                    <p className="text-xs text-gray-400 mt-1">Use the “Ticket” button (top bar) to request a change or new feature.</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {rows.map((t) => {
                        const meta = STATUS_META[t.status] ?? { label: titleCase(t.status), cls: "bg-gray-100 text-gray-600 border-gray-200" };
                        return (
                            <div key={t.id} className="bg-white rounded-2xl border border-gray-50 shadow-sm p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <h3 className="font-semibold text-gray-900 text-sm">{t.title}</h3>
                                            <span className="text-[11px] text-gray-400">{CAT_LABEL[t.category] ?? titleCase(t.category)}</span>
                                            <span className={`text-[11px] font-semibold ${PRIORITY_CLS[t.priority] ?? ""}`}>{titleCase(t.priority)}</span>
                                        </div>
                                        <p className="text-sm text-gray-600 mt-1 whitespace-pre-line">{t.description}</p>
                                        {t.images?.length > 0 && (
                                            <div className="flex flex-wrap gap-2 mt-2">
                                                {t.images.map((src, i) => (
                                                    <a key={i} href={src} target="_blank" rel="noopener noreferrer" className="block w-16 h-16 rounded-lg overflow-hidden border border-gray-200 hover:opacity-80">
                                                        <img src={src} alt={`Screenshot ${i + 1}`} className="w-full h-full object-cover" />
                                                    </a>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <span className={`shrink-0 text-[11px] font-semibold px-2.5 py-1 rounded-full border ${meta.cls}`}>{meta.label}</span>
                                </div>

                                {t.admin_response && (
                                    <div className="mt-3 flex gap-2 rounded-xl bg-gray-50 border border-gray-100 px-3 py-2.5">
                                        <MessageSquare size={15} className="text-gray-400 shrink-0 mt-0.5" />
                                        <div className="min-w-0">
                                            <p className="text-[11px] font-semibold text-gray-500">Admin reply{t.resolver ? ` · ${t.resolver}` : ""}</p>
                                            <p className="text-sm text-gray-700 whitespace-pre-line">{t.admin_response}</p>
                                        </div>
                                    </div>
                                )}

                                <p className="mt-3 text-xs text-gray-500">Owner: {t.assignee || "Unassigned"}{t.archived_at ? " · Archived" : ""}</p>
                                <div className="mt-3 flex flex-wrap gap-4 text-sm"><button className="text-blue-700 underline" aria-expanded={expanded === t.id} onClick={() => setExpanded(expanded === t.id ? null : t.id)}>{expanded === t.id ? "Hide conversation" : "Open conversation"}</button>{t.resolution_report && <a className="text-blue-700 underline" href={`/system-tickets/${t.id}/resolution`}>Download resolution report</a>}</div>
                                {expanded === t.id && <div className="mt-4 border-t pt-4"><TicketConversation ticket={t} /></div>}
                                <div className="mt-3 flex items-center justify-between text-[11px] text-gray-400">
                                    <span className="tabular-nums">{t.ticket_ref}</span>
                                    <span>Raised {fmt(t.created_at)}{t.resolved_at ? ` · closed ${fmt(t.resolved_at)}` : ""}</span>
                                </div>
                            </div>
                        );
                    })}

                    <Pagination links={tickets.links} />
                </div>
            )}
        </div>
    );
}
