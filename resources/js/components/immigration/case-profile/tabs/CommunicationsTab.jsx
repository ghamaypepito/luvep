import { useState, useMemo } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import {
    Mail, MessageSquare, Inbox, Send, Loader2,
    CheckCircle2, Clock, XCircle, CornerUpLeft, MailOpen,
} from "lucide-react";
import { leadBase, isAgentCasePortal } from "@/lib/caseRoutes";
import CommunicationsPanel from "@/components/sales/CommunicationsPanel";

// Case-profile Communications tab — a two-pane message centre: the full thread
// (outbound sends + inbound client replies) on the left, the selected message on
// the right with its delivery trail and a reply composer. Everything shown is
// real data — outbound message_logs and IMAP-synced client email_replies. We do
// not invent open/read tracking, a recipient timezone, or channels we don't send.

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || "");

function statusMeta(status) {
    const s = (status || "").toLowerCase();
    if (["failed", "bounced", "undelivered", "error", "rejected"].includes(s)) return { label: "Failed", tone: "red", bad: true };
    if (s === "delivered") return { label: "Delivered", tone: "emerald" };
    if (s === "queued" || s === "pending") return { label: "Queued", tone: "blue" };
    if (s === "sent") return { label: "Sent", tone: "emerald" };
    return { label: status || "—", tone: "gray" };
}
const TONE = {
    red: "bg-red-50 text-red-700 border-red-200",
    emerald: "bg-emerald-50 text-emerald-700 border-emerald-200",
    blue: "bg-blue-50 text-blue-700 border-blue-200",
    teal: "bg-teal-50 text-teal-700 border-teal-200",
    gray: "bg-gray-50 text-gray-600 border-gray-200",
};
const BORDER = { red: "border-l-red-500", emerald: "border-l-emerald-500", blue: "border-l-blue-500", teal: "border-l-teal-500", gray: "border-l-gray-200" };

const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString("en-NZ", { hour: "2-digit", minute: "2-digit" }) : "");
const fmtFull = (iso) => (iso ? new Date(iso).toLocaleString("en-NZ", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");
const fmtStamp = (iso) => (iso ? new Date(iso).toLocaleTimeString("en-NZ", { hour: "2-digit", minute: "2-digit", second: "2-digit" }) : "");
const msgRef = (key) => `MSG-${String(key).replace(/\D/g, "").padStart(4, "0")}`;

function timeAgo(iso) {
    if (! iso) return "";
    const secs = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (secs < 60) return "just now";
    const mins = Math.round(secs / 60);
    if (mins < 60) return `${mins} min ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs} hour${hrs === 1 ? "" : "s"} ago`;
    const days = Math.round(hrs / 24);
    return `${days} day${days === 1 ? "" : "s"} ago`;
}

function dayHeading(iso) {
    if (! iso) return "Earlier";
    const d = new Date(iso); d.setHours(0, 0, 0, 0);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const diff = Math.round((today - d) / 86400000);
    const label = d.toLocaleDateString("en-NZ", { day: "numeric", month: "short" }).toUpperCase();
    if (diff === 0) return `TODAY · ${label}`;
    if (diff === 1) return `YESTERDAY · ${label}`;
    return label;
}

export default function CommunicationsTab(props) {
    // Staff use the same authorized conversation workflow as Education.
    // Recruiting agents retain their existing read-only case conversation.
    return isAgentCasePortal()
        ? <CaseConversationHistory {...props} />
        : <CommunicationsPanel leadId={props.lead?.id} leadEmail={props.lead?.email || ""} />;
}

function CaseConversationHistory({ communications = [], clientReplies = [], lead = {} }) {
    const [filter, setFilter] = useState("all");
    const [dismissed, setDismissed] = useState(false);
    const [selectedKey, setSelectedKey] = useState(null);

    // Normalise outbound sends + inbound replies into one message shape.
    const messages = useMemo(() => {
        const out = communications.map((m) => ({
            key: `o${m.id}`, dir: "out", channel: m.channel, subject: m.subject,
            snippet: m.snippet, body: m.body, isHtml: m.channel !== "sms",
            status: m.status, recipient: m.recipient_address,
            at: m.sent_at || m.failed_at || m.created_at,
            sent_at: m.sent_at, failed_at: m.failed_at,
        }));
        const inb = clientReplies.map((r) => ({
            key: `i${r.id}`, dir: "in", channel: "email", subject: r.subject,
            snippet: r.snippet, body: r.body, isHtml: false,
            from_name: r.from_name, from_email: r.from_email,
            is_read: r.is_read, status: r.is_read ? "read" : "unread",
            at: r.received_at,
        }));
        return [...out, ...inb].sort((a, b) => new Date(b.at || 0) - new Date(a.at || 0));
    }, [communications, clientReplies]);

    const failed = useMemo(() => messages.filter((m) => m.dir === "out" && statusMeta(m.status).bad), [messages]);

    // "Unanswered" = the newest message is an inbound reply (client wrote last).
    const unanswered = useMemo(() => {
        const newestInbound = messages.find((m) => m.dir === "in");
        if (! newestInbound) return null;
        const newestOutbound = messages.find((m) => m.dir === "out");
        if (newestOutbound && new Date(newestOutbound.at) > new Date(newestInbound.at)) return null;
        return newestInbound;
    }, [messages]);

    const filtered = useMemo(() => messages.filter((m) => {
        if (filter === "email") return m.dir === "out" && m.channel === "email";
        if (filter === "sms") return m.dir === "out" && m.channel === "sms";
        if (filter === "needs") return m.dir === "out" && statusMeta(m.status).bad;
        if (filter === "client") return m.dir === "in";
        return true;
    }), [messages, filter]);

    const selected = useMemo(
        () => messages.find((m) => m.key === selectedKey) || filtered[0] || messages[0] || null,
        [messages, filtered, selectedKey],
    );

    const groups = useMemo(() => {
        const res = [];
        let head = null;
        filtered.forEach((m) => {
            const h = dayHeading(m.at);
            if (h !== head) { res.push({ heading: h, items: [] }); head = h; }
            res[res.length - 1].items.push(m);
        });
        return res;
    }, [filtered]);

    if (messages.length === 0) {
        return (
            <div className="text-center py-12 border border-dashed border-gray-200 rounded-xl bg-gray-50/50">
                <Inbox size={32} className="mx-auto text-gray-300" />
                <p className="mt-3 text-sm font-semibold text-gray-700">No messages yet</p>
                <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">Emails and SMS to this client — and their replies — appear here.</p>
            </div>
        );
    }

    const FILTERS = [["all", "All"], ["needs", "Needs action"], ["client", "From client"], ["email", "Email"], ["sms", "SMS"]];
    const lastOutbound = messages.find((m) => m.dir === "out")?.at || null;

    return (
        <div className="space-y-3">
            {/* Undelivered banner — real failed sends. */}
            {failed.length > 0 && ! dismissed && (
                <div className="flex items-center justify-between gap-4 flex-wrap rounded-xl border border-red-200 bg-red-50/70 px-4 py-3">
                    <div className="flex items-start gap-2.5 min-w-0">
                        <span className="inline-flex items-center px-2 py-0.5 rounded-md bg-red-600 text-white text-[10px] font-bold uppercase tracking-wide flex-shrink-0">Undelivered</span>
                        <p className="text-[12.5px] text-red-800 min-w-0">
                            <span className="font-bold">{failed.length} {failed[0].channel === "sms" ? "SMS" : "message"}{failed.length === 1 ? "" : "s"} failed</span>
                            {failed[0].recipient ? <> to {failed[0].recipient}</> : null} — the client was not reached.
                        </p>
                    </div>
                    <div className="flex items-center gap-2 flex-shrink-0">
                        <button type="button" onClick={() => { setFilter("needs"); setSelectedKey(failed[0].key); }}
                            className="px-3 py-1.5 rounded-lg bg-red-600 text-white text-[12px] font-bold hover:bg-red-700">Review</button>
                        <button type="button" onClick={() => setDismissed(true)}
                            className="px-3 py-1.5 rounded-lg border border-red-200 bg-white text-red-700 text-[12px] font-semibold hover:bg-red-50">Dismiss</button>
                    </div>
                </div>
            )}

            {/* Unanswered banner — the client replied and we haven't responded. */}
            {unanswered && (
                <div className="flex items-center justify-between gap-4 flex-wrap rounded-xl border border-teal-200 bg-teal-50/70 px-4 py-3">
                    <div className="flex items-start gap-2.5 min-w-0">
                        <span className="inline-flex items-center px-2 py-0.5 rounded-md bg-teal-600 text-white text-[10px] font-bold uppercase tracking-wide flex-shrink-0">Unanswered</span>
                        <p className="text-[12.5px] text-teal-900 min-w-0">
                            <span className="font-bold">{unanswered.from_name || "The client"} replied {timeAgo(unanswered.at)}</span>
                            {unanswered.snippet ? <span className="text-teal-800"> — {unanswered.snippet}</span> : null}
                        </p>
                    </div>
                    <button type="button" onClick={() => { setFilter("all"); setSelectedKey(unanswered.key); }}
                        className="px-3 py-1.5 rounded-lg bg-teal-600 text-white text-[12px] font-bold hover:bg-teal-700 flex-shrink-0">View reply</button>
                </div>
            )}

            <div className="flex flex-col lg:flex-row gap-5 items-start">
                {/* ══ LEFT — thread ══ */}
                <div className="w-full min-w-0" style={{ flex: "1 1 0%" }}>
                    <div className="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <h3 className="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">
                            Message history <span className="text-gray-400 font-semibold ml-1">{filtered.length} of {messages.length}</span>
                        </h3>
                        {lastOutbound && <span className="text-[11px] text-gray-400">Last outbound {fmtFull(lastOutbound)}</span>}
                    </div>

                    <div className="flex flex-wrap gap-1.5 mb-4">
                        {FILTERS.map(([key, label]) => {
                            const active = filter === key;
                            const count = key === "needs" ? failed.length : key === "client" ? messages.filter((m) => m.dir === "in" && ! m.is_read).length : null;
                            return (
                                <button key={key} type="button" onClick={() => setFilter(key)}
                                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] font-semibold border transition-colors ${
                                        active ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}>
                                    {label}
                                    {count > 0 && <span className={`text-[10px] font-bold px-1.5 rounded-full ${active ? "bg-white/20" : "bg-red-100 text-red-700"}`}>{count}</span>}
                                </button>
                            );
                        })}
                    </div>

                    {filtered.length === 0 ? (
                        <p className="text-[12px] text-gray-400 px-1 py-4">No messages match this filter.</p>
                    ) : (
                        <div className="space-y-4">
                            {groups.map((g) => (
                                <div key={g.heading}>
                                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5">{g.heading}</p>
                                    <div className="space-y-1.5">
                                        {g.items.map((m) => (
                                            <MessageRow key={m.key} m={m} active={selected?.key === m.key} onSelect={() => setSelectedKey(m.key)} />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* ══ RIGHT — detail + reply ══ */}
                <div className="w-full min-w-0" style={{ flex: "1.15 1 0%" }}>
                    {selected ? <MessageDetail m={selected} lead={lead} /> : (
                        <div className="rounded-2xl border border-gray-100 bg-gray-50/50 p-10 text-center">
                            <Mail size={26} className="mx-auto text-gray-300" />
                            <p className="mt-2 text-[13px] font-semibold text-gray-600">Select a message</p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function ChannelIcon({ channel, size = 13, className = "" }) {
    const Icon = channel === "sms" ? MessageSquare : Mail;
    return <Icon size={size} className={className} />;
}

function MessageRow({ m, active, onSelect }) {
    const inbound = m.dir === "in";
    const meta = inbound ? { label: m.is_read ? "Read" : "Unread", tone: "teal" } : statusMeta(m.status);
    const who = inbound ? (m.from_name || "Client") : "EP";
    const title = m.subject || (m.channel === "sms" ? "SMS message" : "(no subject)");
    return (
        <button type="button" onClick={onSelect}
            className={`w-full text-left rounded-lg border border-l-[3px] px-3.5 py-2.5 transition-colors ${BORDER[meta.tone]} ${
                active ? "bg-gray-50 border-gray-200" : "bg-white border-gray-100 hover:bg-gray-50/70"}`}>
            <div className="flex items-center gap-2 flex-wrap">
                <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase tracking-wide text-gray-500">
                    {inbound ? <CornerUpLeft size={11} className="text-teal-500" /> : <ChannelIcon channel={m.channel} size={11} className="text-gray-400" />}
                    {inbound ? "Email" : (m.channel === "sms" ? "SMS" : "Email")}
                </span>
                <span className="text-[10px] font-semibold text-gray-500">{who}</span>
                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide border ${TONE[meta.tone]} ${inbound && ! m.is_read ? "" : ""}`}>{meta.label}</span>
                <span className="text-[10.5px] text-gray-400 ml-auto tabular-nums">{fmtTime(m.at)}</span>
            </div>
            <p className={`text-[12.5px] mt-1 truncate ${inbound && ! m.is_read ? "font-bold text-gray-900" : "font-semibold text-gray-900"}`}>{title}</p>
            {m.snippet && <p className="text-[11px] text-gray-500 mt-0.5 line-clamp-1">{m.snippet}</p>}
        </button>
    );
}

function MessageDetail({ m, lead }) {
    const inbound = m.dir === "in";
    const meta = inbound ? { label: m.is_read ? "Read" : "Unread", tone: "teal" } : statusMeta(m.status);
    const title = m.subject || (m.channel === "sms" ? "SMS message" : "(no subject)");

    return (
        <div className="rounded-2xl border border-gray-100 shadow-sm bg-white overflow-hidden">
            <div className="p-5 border-b border-gray-100">
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wide">
                            {inbound ? <MailOpen size={11} /> : <ChannelIcon channel={m.channel} size={11} />} {inbound ? "Reply" : (m.channel === "sms" ? "SMS" : "Email")}
                        </span>
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide border ${TONE[meta.tone]}`}>{meta.label}</span>
                    </div>
                    <span className="text-[10px] font-mono text-gray-400">{msgRef(m.key)}</span>
                </div>
                <h3 className="text-lg font-bold text-gray-900 mt-2.5">{title}</h3>
                <p className="text-[12px] text-gray-500 mt-1">
                    {inbound ? (
                        <>From <span className="text-gray-700 font-medium">{m.from_name || m.from_email || "the client"}</span></>
                    ) : (
                        <>To <span className="text-gray-700 font-medium">{m.recipient || "—"}</span></>
                    )}
                    <span className="text-gray-300"> · </span>{fmtFull(m.at)}
                </p>
            </div>

            {/* Body — outbound is our own template HTML; inbound is plain text (untrusted). */}
            <div className="p-5">
                {! inbound && m.isHtml && m.body ? (
                    <div className="rounded-xl border border-gray-100 p-4 text-[13px] text-gray-700 leading-relaxed max-h-[360px] overflow-y-auto"
                        dangerouslySetInnerHTML={{ __html: m.body }} />
                ) : (
                    <div className="rounded-xl border border-gray-100 p-4 text-[13px] text-gray-700 leading-relaxed whitespace-pre-wrap max-h-[360px] overflow-y-auto">
                        {m.body || m.snippet || "No content recorded."}
                    </div>
                )}
            </div>

            {/* Delivery trail — outbound only, and only steps we record. */}
            {! inbound && (
                <div className="px-5 pb-5">
                    <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-400 mb-2">Delivery trail</p>
                    <ul className="space-y-2">
                        <TrailStep ok label="Sent" at={m.sent_at || m.at} />
                        {m.failed_at ? <TrailStep bad label="Failed" at={m.failed_at} />
                            : m.status?.toLowerCase() === "delivered" ? <TrailStep ok label="Delivered" at={m.sent_at} /> : null}
                    </ul>
                </div>
            )}

            {/* Reply composer hidden for now — the send endpoint is wired
                (ReplyComposer below posts to /admin/leads/{id}/compose) but kept
                off until a live send test is signed off. Re-enable by restoring:
                <ReplyComposer lead={lead} defaultChannel={m.channel === "sms" ? "sms" : "email"} replySubject={m.subject} /> */}
        </div>
    );
}

function TrailStep({ label, at, ok, bad }) {
    const Icon = bad ? XCircle : ok ? CheckCircle2 : Clock;
    return (
        <li className="flex items-center gap-2.5">
            <Icon size={14} className={bad ? "text-red-500" : "text-emerald-500"} />
            <span className={`text-[12.5px] font-semibold ${bad ? "text-red-700" : "text-gray-800"}`}>{label}</span>
            {at && <span className="text-[11px] text-gray-400 tabular-nums ml-auto">{fmtStamp(at)}</span>}
        </li>
    );
}

function ReplyComposer({ lead, defaultChannel = "email", replySubject = "" }) {
    const [channel, setChannel] = useState(defaultChannel);
    const [subject, setSubject] = useState(replySubject && ! /^re:/i.test(replySubject) ? `Re: ${replySubject}` : (replySubject || ""));
    const [body, setBody] = useState("");
    const [sending, setSending] = useState(false);

    const send = () => {
        if (! body.trim() || sending) return;
        if (channel === "email" && ! subject.trim()) { toast.error("Add a subject for the email"); return; }
        setSending(true);
        fetch(`${leadBase()}/${lead.id}/compose`, {
            method: "POST",
            headers: { "X-XSRF-TOKEN": xsrf(), "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ channel, subject: channel === "sms" ? null : subject, body }),
        })
            .then((r) => { if (! r.ok) throw new Error(); return r.json(); })
            .then(() => {
                toast.success(`${channel === "sms" ? "SMS" : "Email"} sent to the client`);
                setBody("");
                router.reload({ only: ["communications", "clientReplies"] });
            })
            .catch(() => toast.error("Could not send — please try again."))
            .finally(() => setSending(false));
    };

    return (
        <div className="border-t border-gray-100 bg-gray-50/50 p-5">
            <div className="flex items-center gap-1.5 mb-3">
                {[["email", "Email"], ["sms", "SMS"]].map(([key, label]) => (
                    <button key={key} type="button" onClick={() => setChannel(key)}
                        className={`px-3 py-1 rounded-full text-[12px] font-semibold border transition-colors ${
                            channel === key ? "bg-teal-600 text-white border-teal-600" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}>
                        {label}
                    </button>
                ))}
            </div>

            {channel === "email" && (
                <input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="Subject"
                    className="w-full px-3 py-2 mb-2 rounded-lg border border-gray-200 text-[13px] bg-white focus:border-gray-400 outline-none" maxLength={255} />
            )}
            <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3}
                placeholder={`Write to ${lead.first_name || "the client"}…`}
                className="w-full px-3 py-2 rounded-lg border border-gray-200 text-[13px] bg-white focus:border-gray-400 outline-none resize-none" maxLength={5000} />

            <div className="flex items-center justify-between gap-3 mt-3">
                <p className="text-[11px] text-gray-400">
                    {channel === "sms" ? "Sent by SMS to their mobile." : "Sent by email from the EP client address."}
                </p>
                <button type="button" onClick={send} disabled={sending || ! body.trim()}
                    className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-teal-600 text-white text-[13px] font-bold hover:bg-teal-700 disabled:opacity-40">
                    {sending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
                    Send {channel === "sms" ? "SMS" : "email"}
                </button>
            </div>
        </div>
    );
}
