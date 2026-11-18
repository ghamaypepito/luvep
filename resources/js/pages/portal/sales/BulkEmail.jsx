import { useMemo, useState, useEffect } from "react";
import { Head, router } from "@inertiajs/react";
import { toast } from "sonner";
import {
    Send, Clock, History, Users, Search, CalendarClock,
    Mail, Smartphone, Ban, CheckCircle2, XCircle, Loader2, ChevronRight,
} from "lucide-react";

const inp = "w-full px-3 py-2 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300";
const fmt = (iso) => (iso ? new Date(iso).toLocaleString("en-US", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");
const NEW_LEADS = "New Leads";

function recipientStage(recipient, filterNewLeads) {
    if (!filterNewLeads) return recipient.stage || recipient.status;
    if (recipient.status === NEW_LEADS && !recipient.is_new_lead) return null;
    return recipient.status;
}

function matchesRecipient(recipient, filters, isSms, filterNewLeads) {
    const search = (filters.search || "").trim().toLowerCase();
    if (filters.event_id && String(recipient.event_id) !== String(filters.event_id)) return false;
    if (filters.stage && recipientStage(recipient, filterNewLeads) !== filters.stage) return false;
    if (filters.registered_from && (!recipient.registered_on || recipient.registered_on < filters.registered_from)) return false;
    if (filters.registered_to && (!recipient.registered_on || recipient.registered_on > filters.registered_to)) return false;
    return !search || `${recipient.name} ${(isSms ? recipient.phone : recipient.email) || ""}`.toLowerCase().includes(search);
}

const STATUS_CHIP = {
    scheduled: "bg-amber-50 text-amber-700",
    sending: "bg-blue-50 text-blue-700",
    sent: "bg-emerald-50 text-emerald-700",
    failed: "bg-rose-50 text-rose-700",
    canceled: "bg-gray-100 text-gray-500",
    draft: "bg-gray-100 text-gray-500",
};

const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

// Mirror of the server-side {{var}} substitution, for the live preview only.
// When the result is rendered as HTML (email body), escapeValues must be true
// so a lead's own name/email can't inject markup — the template text itself
// (admin-authored) is never escaped, only the substituted variable values.
function fillPreview(text, lead, selectedEvent, escapeValues = false) {
    if (!text) return "";
    const name = lead?.name || "Alex Taylor";
    const [first, ...rest] = name.split(" ");
    const ctx = {
        first_name: first || "Alex",
        last_name: rest.join(" ") || "Taylor",
        full_name: name,
        email: lead?.email || "alex@example.com",
        phone: lead?.phone || "",
        stage: lead?.stage || lead?.status || "Qualified",
        status: lead?.status || "Qualified",
        status_detail: "",
        tracker_url: `${window.location.origin}/track/SAMPLE`,
        assigned_staff_name: "the EP team",
        event_name: selectedEvent?.event_name || "Seminar: Studying in New Zealand",
        event_date: selectedEvent?.event_date || "Friday, 10 July 2026",
        event_time: selectedEvent?.event_time || "2:00 PM – 3:00 PM",
        event_location: selectedEvent?.event_location || "Online (Zoom)",
        document_name: "Passport",
        reason: "The image was blurry and text was not readable.",
    };
    return text.replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (_, k) => {
        const v = ctx[k.toLowerCase()] ?? "";
        return escapeValues ? escapeHtml(v) : v;
    });
}

export default function SalesBulkEmail({ templates = [], recipients = [], campaigns = [], basePath = "/portal/sales/bulk-email", channel = "email", events = [], filterNewLeads = false, recipientLists = [], educationVisits = [] }) {
    const [tab, setTab] = useState("compose");
    const isSms = channel === "sms";

    const scheduled = campaigns.filter((c) => c.status === "scheduled");
    const history = campaigns.filter((c) => c.status !== "scheduled");

    return (
        <div className="space-y-6 max-w-6xl mx-auto pb-12">
            <Head title={isSms ? "Bulk SMS" : "Bulk Email — Sales"} />

            <header>
                <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Outreach</p>
                <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    {isSms ? <Smartphone className="w-6 h-6 text-gray-700" /> : <Mail className="w-6 h-6 text-gray-700" />}
                    {isSms ? "Bulk SMS" : "Bulk Email"}
                </h1>
                <p className="text-sm text-gray-500 mt-1">
                    {isSms
                         ? "Send a personalised text to many leads — now or scheduled."
                         : "Send a personalised email to many leads — now or scheduled."}
                </p>
            </header>

            <div className="flex items-center gap-1 border-b border-gray-100">
                {[
                    { key: "compose", label: "Compose", icon: <Send size={15} /> },
                    { key: "scheduled", label: `Scheduled${scheduled.length ? ` (${scheduled.length})` : ""}`, icon: <Clock size={15} /> },
                    { key: "history", label: "History", icon: <History size={15} /> },
                ].map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setTab(t.key)}
                        className={`flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors ${tab === t.key ? "border-gray-900 text-gray-900" : "border-transparent text-gray-400 hover:text-gray-700"}`}
                    >
                        {t.icon} {t.label}
                    </button>
                ))}
            </div>

            {tab === "compose" && <Compose templates={templates} recipients={recipients} basePath={basePath} isSms={isSms} events={events} filterNewLeads={filterNewLeads} recipientLists={recipientLists} educationVisits={educationVisits} />}
            {tab === "scheduled" && <CampaignTable rows={scheduled} empty="No scheduled campaigns." showCancel basePath={basePath} />}
            {tab === "history" && <CampaignTable rows={history} empty="No campaigns sent yet." basePath={basePath} />}
        </div>
    );
}

function Compose({ templates, recipients, basePath, isSms = false, events = [], filterNewLeads, recipientLists, educationVisits }) {
    const [name, setName] = useState("");
    const [templateId, setTemplateId] = useState(templates[0]?.id ? String(templates[0].id) : "");
    const [selected, setSelected] = useState(() => new Set());
    const [search, setSearch] = useState("");
    const [stage, setStage] = useState("");
    const [eventId, setEventId] = useState("");
    const [registeredFrom, setRegisteredFrom] = useState("");
    const [registeredTo, setRegisteredTo] = useState("");
    const [listId, setListId] = useState("");
    const [listName, setListName] = useState("");
    const [listMode, setListMode] = useState("smart");
    const [savingList, setSavingList] = useState(false);
    const [listErrors, setListErrors] = useState({});
    const [action, setAction] = useState("send_now");
    const [scheduledAt, setScheduledAt] = useState("");
    const [sending, setSending] = useState(false);

    useEffect(() => {
        if (templates.length > 0) {
            const exists = templates.some((t) => String(t.id) === String(templateId));
            if (!exists) {
                setTemplateId(String(templates[0].id));
            }
        } else {
            setTemplateId("");
        }
    }, [templates, templateId]);

    const template = templates.find((t) => String(t.id) === String(templateId));
    const newLeadsOnly = filterNewLeads && stage === NEW_LEADS;

    const stages = useMemo(
        () => Array.from(new Set([
            ...(filterNewLeads ? [NEW_LEADS] : []),
            ...recipients.map((r) => recipientStage(r, filterNewLeads)).filter(Boolean),
        ])).sort(),
        [recipients, filterNewLeads],
    );

    const contactOf = (r) => (isSms ? r.phone : r.email) || "";

    const filtered = useMemo(() => {
        return recipients.filter(recipient => matchesRecipient(recipient, {
            search, stage, event_id: eventId, registered_from: registeredFrom, registered_to: registeredTo,
        }, isSms, filterNewLeads));
    }, [recipients, search, stage, eventId, registeredFrom, registeredTo, isSms, filterNewLeads]);

    const selectionFollowsFilters = newLeadsOnly || (filterNewLeads && Boolean(listId) && listMode === "smart");
    const visibleSelection = selectionFollowsFilters ? new Set(filtered.filter((r) => selected.has(r.id)).map((r) => r.id)) : selected;
    const outsideFilterCount = visibleSelection.size - filtered.filter(recipient => visibleSelection.has(recipient.id)).length;
    const previewLead = recipients.find((r) => visibleSelection.has(r.id)) || filtered[0] || null;

    const toggle = (id) => setSelected((prev) => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });
    const selectAllMatching = () => setSelected((prev) => new Set([
        ...(selectionFollowsFilters ? [] : prev), ...filtered.map((r) => r.id),
    ]));
    const clearAll = () => setSelected(new Set());
    const selectEvent = (value) => {
        const visit = educationVisits.find(item => `visit:${item.id}` === value);
        if (visit) {
            router.get('/portal/education/school-mail', {
                visit_ids: [visit.id], audience: 'registered', purpose: 'email_follow_up',
            });
            return;
        }
        setEventId(value);
    };
    const resetList = () => {
        setSelected(new Set(visibleSelection));
        setListId(""); setListName("");
    };

    const applyList = (id) => {
        const list = recipientLists.find(item => String(item.id) === id);
        const filters = list?.filters || {};
        setListId(id); setListName(list?.name || ""); setListMode(list?.mode || "smart"); setListErrors({});
        setSearch(filters.search || ""); setStage(filters.stage || ""); setEventId(String(filters.event_id || ""));
        setRegisteredFrom(filters.registered_from || ""); setRegisteredTo(filters.registered_to || "");
        const ids = list?.mode === "smart"
            ? recipients.filter(recipient => matchesRecipient(recipient, filters, isSms, filterNewLeads)).map(recipient => recipient.id)
            : (list?.lead_ids || []).filter(id => recipients.some(recipient => recipient.id === id));
        setSelected(new Set(ids));
    };

    const saveList = () => {
        if (savingList || !listName.trim()) return;
        setSavingList(true); setListErrors({});
        router.post(`${basePath}/recipient-lists`, {
            list_id: listId || null, name: listName, mode: listMode,
            filters: { search, stage, event_id: eventId, registered_from: registeredFrom, registered_to: registeredTo },
            ...(listMode === "custom" ? { lead_ids: [...visibleSelection] } : {}),
        }, {
            preserveScroll: true,
            onSuccess: resetList,
            onError: setListErrors,
            onFinish: () => setSavingList(false),
        });
    };

    const canSend = name.trim() && templateId && visibleSelection.size > 0 && (action === "send_now" || scheduledAt) && !sending;

    const submit = () => {
        if (!canSend) return;
        setSending(true);
        router.post(basePath, {
            name, template_id: templateId, recipient_lead_ids: [...visibleSelection],
            ...(newLeadsOnly ? { new_leads_only: true } : {}),
            action, scheduled_at: action === "schedule" ? scheduledAt : null,
        }, {
            preserveScroll: true,
            onError: (e) => toast.error(Object.values(e)[0] || "Could not start the campaign."),
            onFinish: () => setSending(false),
        });
    };

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Left: setup */}
            <div className="space-y-5">
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4">
                    <label className="block">
                        <span className="block text-xs font-semibold text-gray-600 mb-1">Campaign name <span className="text-gray-400 font-normal">(internal)</span></span>
                        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="June re-engagement" className={inp} />
                    </label>
                    <label className="block">
                        <span className="block text-xs font-semibold text-gray-600 mb-1">Template</span>
                        <select value={templateId} onChange={(e) => setTemplateId(e.target.value)} className={inp} disabled={!templates.length}>
                            {!templates.length && <option value="">No active {isSms ? "SMS" : "email"} templates</option>}
                            {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                        <span className="text-[11px] text-gray-400 mt-1 block">Manage templates in Email Templates. {isSms ? "Only templates with an SMS body appear here." : "Bulk email sends on the email channel only."}</span>
                    </label>

                    {/* Schedule */}
                    <div className="space-y-2">
                        <span className="block text-xs font-semibold text-gray-600">When to send</span>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setAction("send_now")} className={`flex-1 px-3 py-2 rounded-lg text-sm font-semibold border ${action === "send_now" ? "border-gray-900 bg-gray-900 text-white" : "border-gray-200 text-gray-600 hover:bg-gray-50"}`}>
                                <Send size={14} className="inline mr-1.5" /> Send now
                            </button>
                            <button type="button" onClick={() => setAction("schedule")} className={`flex-1 px-3 py-2 rounded-lg text-sm font-semibold border ${action === "schedule" ? "border-gray-900 bg-gray-900 text-white" : "border-gray-200 text-gray-600 hover:bg-gray-50"}`}>
                                <CalendarClock size={14} className="inline mr-1.5" /> Schedule
                            </button>
                        </div>
                        {action === "schedule" && (
                            <input type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} className={inp} />
                        )}
                    </div>

                    <button onClick={submit} disabled={!canSend} className="w-full px-4 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-xl hover:bg-blue-700 flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        {sending ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                        {action === "schedule" ? "Schedule campaign" : "Send"} {visibleSelection.size > 0 && `· ${visibleSelection.size} lead${visibleSelection.size === 1 ? "" : "s"}`}
                    </button>
                </div>

                {/* Recipients */}
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-bold text-gray-700 flex items-center gap-1.5"><Users size={15} /> Recipients</h3>
                        <span className="text-xs text-gray-500">{visibleSelection.size} selected · {filtered.length} shown</span>
                    </div>
                    {filterNewLeads && <label className="block text-xs font-semibold text-gray-600">Saved recipient list
                        <select aria-label="Saved recipient list" value={listId} onChange={event => applyList(event.target.value)} className={`${inp} mt-1`}>
                            <option value="">Choose a list</option>
                            {recipientLists.map(list => <option key={list.id} value={list.id}>{list.name} · {list.mode === "smart" ? "Smart" : "Custom"}</option>)}
                        </select>
                    </label>}
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div className="relative min-w-0 sm:col-span-2">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                            <input aria-label="Search recipients" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={isSms ? "Search name or phone" : "Search name or email"} className={`${inp} pl-8`} />
                        </div>
                        <select aria-label="Filter recipients by stage" value={stage} onChange={(e) => setStage(e.target.value)} className={`${inp} min-w-0`}>
                            <option value="">All stages</option>
                            {stages.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                        <select aria-label="Filter recipients by event" value={eventId} onChange={(e) => selectEvent(e.target.value)} className={`${inp} min-w-0`}>
                            <option value="">All events</option>
                            {educationVisits.length > 0 && <optgroup label="Education events — opens event mailing">
                                {educationVisits.map(visit => <option key={visit.id} value={`visit:${visit.id}`}>{visit.name}</option>)}
                            </optgroup>}
                            {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
                        </select>
                    </div>
                    {filterNewLeads && <>
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label className="text-xs text-gray-600">Lead registered from
                                <input aria-label="Lead registered from" type="date" value={registeredFrom} onChange={event => setRegisteredFrom(event.target.value)} className={`${inp} mt-1 min-w-0`} />
                            </label>
                            <label className="text-xs text-gray-600">Lead registered through
                                <input aria-label="Lead registered through" type="date" min={registeredFrom} value={registeredTo} onChange={event => setRegisteredTo(event.target.value)} className={`${inp} mt-1 min-w-0`} />
                            </label>
                        </div>
                        <p className="text-xs text-gray-500">Dates use when the lead was added, in Asia/Manila. School RSVP dates are available in School visit bulk email.</p>
                        <details className="rounded-lg border border-gray-200 p-3">
                            <summary className="cursor-pointer text-sm font-semibold text-gray-700">{listId ? "Edit recipient list" : "Save recipient list"}</summary>
                            <div className="mt-3 space-y-3">
                                <label className="block text-xs text-gray-600">List name<input aria-label="Recipient list name" value={listName} maxLength={100} onChange={event => setListName(event.target.value)} className={`${inp} mt-1`} /></label>
                                <label className="block text-xs text-gray-600">List type<select aria-label="Recipient list type" value={listMode} onChange={event => setListMode(event.target.value)} className={`${inp} mt-1`}>
                                    <option value="smart">Smart list — matches these filters</option><option value="custom">Custom list — selected leads</option>
                                </select></label>
                                <p className="text-xs text-gray-500">Smart lists include new matching leads when opened. Custom lists keep the selected leads. Your lists are private to your account.</p>
                                {Object.keys(listErrors).length > 0 && <p role="alert" className="text-xs text-rose-700">{Object.values(listErrors).join(" ")}</p>}
                                <div className="flex gap-3"><button type="button" onClick={saveList} disabled={!listName.trim() || savingList || (listMode === "custom" && !visibleSelection.size)} className="rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-40">{savingList ? "Saving…" : "Save list"}</button>
                                    {listId && <button type="button" onClick={resetList} className="text-xs text-gray-600">Save as a new list</button>}
                                </div>
                            </div>
                        </details>
                    </>}
                    <div className="flex gap-2 text-xs">
                        <button onClick={selectAllMatching} className="px-2.5 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200 font-medium">Select all matching ({filtered.length})</button>
                        <button onClick={clearAll} className="px-2.5 py-1 rounded-md text-gray-500 hover:bg-gray-50 font-medium">Clear</button>
                    </div>
                    {outsideFilterCount > 0 && <p className="text-xs text-gray-600">{outsideFilterCount} selected recipients are outside the current filters.</p>}
                    <ul className="max-h-72 overflow-y-auto divide-y divide-gray-50 -mx-2">
                        {filtered.length === 0 ? (
                            <li className="px-2 py-8 text-center text-sm text-gray-400">No leads match.</li>
                        ) : filtered.slice(0, 500).map((r) => (
                            <li key={r.id}>
                                <label className="flex items-center gap-3 px-2 py-2 hover:bg-gray-50 cursor-pointer rounded-lg">
                                    <input type="checkbox" checked={selected.has(r.id)} onChange={() => toggle(r.id)} className="rounded" />
                                    <span className="flex-1 min-w-0">
                                        <span className="block text-sm text-gray-800 truncate">{r.name}</span>
                                        <span className="block text-[11px] text-gray-400 truncate">{contactOf(r)}{recipientStage(r, filterNewLeads) && ` · ${recipientStage(r, filterNewLeads)}`}</span>
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    {filtered.length > 500 && <p className="text-[11px] text-amber-600">Showing first 500 — narrow with search/stage to reach the rest. "Select all matching" still selects every match.</p>}
                </div>
            </div>

            {/* Right: live preview */}
            {(() => {
                const selectedEvent = events.find((e) => String(e.id) === String(eventId)) || null;
                return (
                    <div className="space-y-3">
                        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden sticky top-4">
                            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                                <span className="text-xs font-bold uppercase tracking-wider text-gray-500">Live preview</span>
                                <span className="text-[11px] text-gray-400">{previewLead ? `as ${previewLead.name}` : "sample lead"}</span>
                            </div>
                            {template ? (
                                <div className="p-5 space-y-3">
                                    {!isSms && (
                                        <div>
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Subject</span>
                                            <p className="text-sm font-semibold text-gray-900">{fillPreview(template.subject, previewLead, selectedEvent)}</p>
                                        </div>
                                    )}
                                    <div>
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">{isSms ? "Message" : "Body"}</span>
                                        {isSms ? (
                                            <pre className="whitespace-pre-wrap font-sans text-sm text-gray-700 mt-1 leading-relaxed">{fillPreview(template.body, previewLead, selectedEvent)}</pre>
                                        ) : (
                                            <div className="text-sm text-gray-700 mt-1 leading-relaxed [&_a]:text-blue-600 [&_a]:underline" dangerouslySetInnerHTML={{ __html: fillPreview(template.body, previewLead, selectedEvent, true) }} />
                                        )}
                                    </div>
                                    {isSms && (() => {
                                        const len = fillPreview(template.body, previewLead, selectedEvent).length;
                                        const seg = Math.ceil(len / 160) || 0;
                                        return <p className={`text-xs ${seg > 1 ? "text-amber-600" : "text-gray-400"}`}>{len} chars · {seg} segment{seg === 1 ? "" : "s"} per recipient{seg > 1 ? " (multi-segment costs more)" : ""}</p>;
                                    })()}
                                </div>
                            ) : (
                                <div className="p-8 text-center text-sm text-gray-400">Pick a template to preview.</div>
                            )}
                        </div>
                    </div>
                );
            })()}
        </div>
    );
}

function CampaignTable({ rows, empty, showCancel = false, basePath = "/portal/sales/bulk-email" }) {
    const cancel = (c) => {
        if (!window.confirm(`Cancel "${c.name}"? It won't be sent.`)) return;
        router.post(`${basePath}/${c.id}/cancel`, {}, { preserveScroll: true });
    };

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
                <table className="w-full text-left">
                    <thead>
                        <tr className="bg-gray-50/50 border-y border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            <th className="px-5 py-3">Campaign</th>
                            <th className="px-5 py-3">Status</th>
                            <th className="px-5 py-3">Recipients</th>
                            <th className="px-5 py-3">{showCancel ? "Scheduled for" : "Completed"}</th>
                            <th className="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {rows.length === 0 ? (
                            <tr><td colSpan={5} className="px-5 py-14 text-center text-sm text-gray-400">{empty}</td></tr>
                        ) : rows.map((c) => (
                            <tr key={c.id} className="hover:bg-gray-50/40">
                                <td className="px-5 py-3">
                                    <span className="font-semibold text-gray-900 text-sm">{c.name}</span>
                                    <span className="block text-[11px] text-gray-400">by {c.created_by || "—"} · {fmt(c.created_at)}</span>
                                </td>
                                <td className="px-5 py-3">
                                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${STATUS_CHIP[c.status] || "bg-gray-100 text-gray-500"}`}>{c.status}</span>
                                </td>
                                <td className="px-5 py-3 text-sm text-gray-600">
                                    {["sent", "failed", "skipped"].includes(c.status) ? (
                                        <span className="inline-flex items-center gap-1">
                                            <CheckCircle2 size={12} className="text-emerald-500" /> {c.sent}
                                            {c.failed > 0 && <span className="text-rose-600 inline-flex items-center gap-0.5"> · <XCircle size={12} /> {c.failed}</span>}
                                            {c.skipped > 0 && <span className="text-gray-500"> · {c.skipped} skipped</span>}
                                            <span className="text-gray-400"> · {c.total} total</span>
                                        </span>
                                    ) : `${c.total} leads`}
                                </td>
                                <td className="px-5 py-3 text-sm text-gray-500">{showCancel ? fmt(c.scheduled_at) : fmt(c.completed_at)}</td>
                                <td className="px-5 py-3 text-right">
                                    {showCancel && c.cancelable ? (
                                        <button onClick={() => cancel(c)} className="inline-flex items-center gap-1 text-xs text-rose-600 hover:bg-rose-50 px-2 py-1 rounded-md font-medium"><Ban size={13} /> Cancel</button>
                                    ) : (
                                        <button onClick={() => router.visit(`${basePath}/${c.id}`)} className="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-900 px-2 py-1 rounded-md font-medium">Details <ChevronRight size={13} /></button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
