import { useState, useEffect, useCallback, useRef } from "react";
import { Mail, MessageSquare, Loader2, ChevronDown, AlertTriangle, Send, Paperclip, X, Reply, Inbox, RefreshCw } from "lucide-react";
import RichTextEditor from "@/components/templates/RichTextEditor";

const STATUS = {
    queued:   "bg-blue-50 text-blue-700 border-blue-100",
    sent:     "bg-emerald-50 text-emerald-700 border-emerald-100",
    failed:   "bg-rose-50 text-rose-700 border-rose-100",
    bounced:  "bg-amber-50 text-amber-700 border-amber-100",
    received: "bg-indigo-50 text-indigo-700 border-indigo-100",
};
const fmt = (iso) =>
    iso ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

// Inbound email replies quote the original message with leading "> " markers on
// every line. Strip them (including nested ">>") so the conversation reads
// cleanly without the ">" clutter.
const stripQuoteMarkers = (t) => (t || "").replace(/^[ \t]*>+[ \t]?/gm, "");

// Laravel expects the (decoded) XSRF-TOKEN cookie echoed back as a header.
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || "");
const MAX_ATTACHMENTS = 5;
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

/**
 * Staff-side conversation for one lead — every email/SMS we sent them merged
 * with the replies they sent back, newest first, plus an inline composer to
 * email them. Mirrors the Compose editor. A lead with no email can be viewed
 * but not messaged (the composer is replaced with a prompt).
 */
export default function CommunicationsPanel({ leadId, leadEmail = "", allowCc = false }) {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(null);
    const [checkError, setCheckError] = useState(null);
    const [openId, setOpenId] = useState(null);
    const [canEmail, setCanEmail] = useState(!!leadEmail);
    const [email, setEmail] = useState(leadEmail);

    // Composer state. It stays closed until the user picks a message to reply
    // to (or starts a fresh email), then opens pre-filled.
    const [composerOpen, setComposerOpen] = useState(false);
    const [replyContext, setReplyContext] = useState(null); // { subject } of the message being answered
    const [subject, setSubject] = useState("");
    const [cc, setCc] = useState("");
    const [body, setBody] = useState("");
    const [files, setFiles] = useState([]);
    const [canAttachDocuments, setCanAttachDocuments] = useState(false);
    const [documents, setDocuments] = useState([]);
    const [selectedDocuments, setSelectedDocuments] = useState([]);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState(null);
    const [sent, setSent] = useState(false);
    const [checking, setChecking] = useState(false);
    const [checkNote, setCheckNote] = useState(null);
    const fileRef = useRef(null);

    const indexUrl = `/admin/leads/${leadId}/communications`;
    const composerRef = useRef(null);

    const scrollToComposer = () => setTimeout(() => composerRef.current?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);

    // Open the composer to answer a specific message — pre-fills "Re: <subject>".
    const openReply = (m) => {
        const base = (m.subject || "").replace(/^\s*re:\s*/i, "").trim();
        setSubject(base ? `Re: ${base}` : "");
        setReplyContext({ subject: m.subject, from: m.direction === "in" ? (m.from || "the lead") : "your team" });
        setBody(""); setCc(""); setError(null); setSent(false);
        setComposerOpen(true);
        scrollToComposer();
    };

    // Start a fresh email not tied to any message.
    const openBlank = () => {
        setSubject(""); setReplyContext(null); setBody(""); setCc(""); setError(null); setSent(false);
        setComposerOpen(true);
        scrollToComposer();
    };

    const closeComposer = () => { setComposerOpen(false); setReplyContext(null); setSubject(""); setBody(""); setFiles([]); setSelectedDocuments([]); setError(null); };

    const load = useCallback(async () => {
        setLoading(true);
        setLoadError(null);
        try {
            const res = await fetch(indexUrl, {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(res.status === 419
                ? "Your session expired. Refresh the page and try again."
                : data.message || "Could not load the conversation. Please try again.");
            setLogs(data.data ?? []);
            if (typeof data.can_email === "boolean") setCanEmail(data.can_email);
            if (data.lead_email !== undefined) setEmail(data.lead_email || "");
            setCanAttachDocuments(data.can_attach_documents === true);
            setDocuments(data.attachment_documents ?? []);
        } catch (error) {
            setLoadError(error instanceof TypeError ? "Could not load the conversation. Please try again." : error.message);
        } finally {
            setLoading(false);
        }
    }, [indexUrl]);

    useEffect(() => { load(); }, [load]);

    // Trigger the IMAP mailbox fetch, then refresh the feed so a just-arrived
    // reply shows up here without visiting the Email → Replies inbox.
    const checkReplies = async () => {
        setChecking(true); setCheckNote(null); setCheckError(null);
        try {
            const res = await fetch(`${indexUrl}/sync`, {
                method: "POST",
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", "X-XSRF-TOKEN": xsrf() },
                credentials: "same-origin",
            });
            if (!res.ok) throw new Error(res.status === 419
                ? "Your session expired. Refresh the page and try again."
                : "Could not check for replies. Please try again.");
            // The fetch is queued; give the worker a moment, then reload.
            await new Promise((r) => setTimeout(r, 2500));
            await load();
            setCheckNote("Mailbox check requested. Refresh shortly to see any new replies.");
            setTimeout(() => setCheckNote(null), 6000);
        } catch (error) {
            setCheckError(error instanceof TypeError ? "Could not check for replies. Please try again." : error.message);
        } finally {
            setChecking(false);
        }
    };

    const attachmentCount = files.length + selectedDocuments.length;
    const addFiles = (list) => {
        const incoming = Array.from(list || []);
        if (attachmentCount + incoming.length > MAX_ATTACHMENTS) {
            setError("You can attach up to five files per email.");
            return;
        }
        const oversized = incoming.find((file) => file.size > MAX_ATTACHMENT_BYTES);
        if (oversized) {
            setError(`${oversized.name} exceeds the 10 MB limit per file.`);
            return;
        }
        setFiles((prev) => [...prev, ...incoming]);
        setError(null);
    };
    const removeFile = (i) => setFiles((prev) => prev.filter((_, idx) => idx !== i));
    const addDocument = (id) => {
        const document = documents.find((item) => String(item.id) === id);
        if (!document || attachmentCount >= MAX_ATTACHMENTS) return;
        setSelectedDocuments((prev) => [...prev, document]);
        setError(null);
    };
    const removeDocument = (id) => setSelectedDocuments((prev) => prev.filter((item) => item.id !== id));

    const send = async () => {
        const plain = body.replace(/<[^>]*>/g, "").trim();
        if (!plain && attachmentCount === 0) { setError("Write a message or attach a file first."); return; }
        setSending(true); setError(null); setSent(false);
        try {
            const fd = new FormData();
            if (subject.trim()) fd.append("subject", subject.trim());
            if (allowCc) {
                cc.split(/[,;]/).map((address) => address.trim()).filter(Boolean)
                    .forEach((address) => fd.append("cc[]", address));
            }
            fd.append("body", body);
            files.forEach((f) => fd.append("attachments[]", f));
            selectedDocuments.forEach((document) => fd.append("document_ids[]", document.id));

            const res = await fetch(indexUrl, {
                method: "POST",
                body: fd,
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", "X-XSRF-TOKEN": xsrf() },
                credentials: "same-origin",
            });
            if (res.ok) {
                setSubject(""); setBody(""); setFiles([]); setSelectedDocuments([]); setSent(true);
                setComposerOpen(false); setReplyContext(null);
                await load();
                setTimeout(() => setSent(false), 3000);
            } else {
                const j = await res.json().catch(() => ({}));
                setError(res.status === 413
                    ? "These attachments are too large to send together. Try fewer or smaller files."
                    : j.message || "Could not send the message. Please try again.");
            }
        } catch {
            setError("Could not send the message. Please try again.");
        } finally {
            setSending(false);
        }
    };

    return (
        <div className="space-y-4">
            {/* ── No-email guard (always shown when the lead has no address) ── */}
            {!canEmail && (
                <div className="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-3">
                    <AlertTriangle size={16} className="text-amber-600 shrink-0 mt-0.5" />
                    <div>
                        <p className="text-sm font-semibold text-amber-800">No email address on file</p>
                        <p className="text-xs text-amber-700 mt-0.5">Add an email in the <strong>Personal Info</strong> tab to email this lead. You can still view any past messages below.</p>
                    </div>
                </div>
            )}
            {sent && <p className="text-xs text-emerald-600 px-1">Reply sent to {email}.</p>}

            {/* ── Composer — opens when a message's Reply button (or New email) is used ── */}
            {canEmail && composerOpen && (
                <div ref={composerRef} className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
                    <div className="flex items-start justify-between gap-2 mb-3">
                        <div className="flex items-center gap-2 min-w-0">
                            <span className="w-8 h-8 rounded-lg bg-gray-900 text-white flex items-center justify-center shrink-0">
                                <Reply size={15} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-gray-900">
                                    {replyContext ? "Reply" : "New email"}
                                </p>
                                <p className="text-[11px] text-gray-400 truncate">To {email}</p>
                            </div>
                        </div>
                        <button type="button" onClick={closeComposer} className="text-gray-400 hover:text-gray-700 p-1" title="Close">
                            <X size={16} />
                        </button>
                    </div>

                    {replyContext && (
                        <div className="mb-2 text-[11px] text-gray-500 bg-gray-50 border border-gray-100 rounded-lg px-3 py-1.5 truncate">
                            Replying to <span className="font-medium text-gray-700">{replyContext.subject || "(no subject)"}</span>
                        </div>
                    )}

                    {allowCc && (
                        <label className="block text-xs font-medium text-gray-600">
                            CC (optional)
                            <input
                                value={cc}
                                onChange={(e) => setCc(e.target.value)}
                                placeholder="Separate email addresses with commas"
                                className="mt-1 w-full px-3 py-2 mb-2 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300"
                            />
                        </label>
                    )}
                    <input
                        value={subject}
                        onChange={(e) => setSubject(e.target.value)}
                        placeholder="Subject (optional)"
                        className="w-full px-3 py-2 mb-2 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300"
                    />

                    <RichTextEditor value={body} onChange={setBody} />

                    {attachmentCount > 0 && (
                        <div className="flex flex-wrap gap-2 mt-2">
                            {files.map((f, i) => (
                                <span key={`upload-${i}`} className="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-full bg-gray-100 text-gray-600 text-[11px]">
                                    <Paperclip size={11} /> <span className="max-w-[140px] truncate">{f.name}</span>
                                    <button type="button" aria-label={`Remove ${f.name}`} onClick={() => removeFile(i)} className="text-gray-400 hover:text-gray-700"><X size={12} /></button>
                                </span>
                            ))}
                            {selectedDocuments.map((document) => (
                                <span key={`document-${document.id}`} className="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-full bg-gray-100 text-gray-600 text-[11px]">
                                    <Paperclip size={11} /> <span className="max-w-[140px] truncate">{document.name}</span><span>#{document.id}</span>
                                    <button type="button" aria-label={`Remove ${document.name}`} onClick={() => removeDocument(document.id)} className="text-gray-400 hover:text-gray-700"><X size={12} /></button>
                                </span>
                            ))}
                        </div>
                    )}

                    {error && <p role="alert" className="mt-2 text-xs text-rose-600 flex items-center gap-1.5"><AlertTriangle size={12} /> {error}</p>}

                    <div className="flex flex-wrap items-center justify-between gap-2 mt-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <button type="button" onClick={() => fileRef.current?.click()} disabled={attachmentCount >= MAX_ATTACHMENTS}
                                className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 disabled:opacity-50">
                                <Paperclip size={14} /> Upload files
                            </button>
                            <input ref={fileRef} type="file" multiple className="hidden"
                                onChange={(e) => { addFiles(e.target.files); e.target.value = ""; }} />
                            {canAttachDocuments && (
                                <select aria-label="Attach a student document" value="" onChange={(e) => addDocument(e.target.value)}
                                    disabled={attachmentCount >= MAX_ATTACHMENTS || documents.length === 0}
                                    className="max-w-64 rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 disabled:opacity-50">
                                    <option value="">{documents.length > 0 ? "Attach a student document" : "No approved or shared documents"}</option>
                                    {documents.filter((document) => !selectedDocuments.some((item) => item.id === document.id))
                                        .map((document) => <option key={document.id} value={document.id}>
                                            {document.name} · {document.status === "StaffShared" ? "Staff shared" : "Approved"}
                                            {document.added_at ? ` · ${new Date(document.added_at).toLocaleString("en-NZ", { timeZone: "Pacific/Auckland", dateStyle: "medium", timeStyle: "short" })}` : ""}
                                            {` · #${document.id}`}
                                        </option>)}
                                </select>
                            )}
                            {attachmentCount > 0 && <span className="text-xs text-gray-500">{attachmentCount}/{MAX_ATTACHMENTS}</span>}
                        </div>
                        <button
                            onClick={send}
                            disabled={sending}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black disabled:opacity-50"
                        >
                            {sending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
                            {sending ? "Sending…" : "Send email"}
                        </button>
                    </div>
                </div>
            )}

            {/* ── Conversation feed ────────────────────────────────────── */}
            <div className="flex items-center justify-between px-1">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider">Conversation</p>
                <div className="flex items-center gap-3">
                    {canEmail && !composerOpen && (
                        <button
                            onClick={openBlank}
                            className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-700 hover:text-black"
                        >
                            <Mail size={13} /> New email
                        </button>
                    )}
                    <button
                        onClick={checkReplies}
                        disabled={checking}
                        title="Pull the mailbox for new replies from this lead"
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 disabled:opacity-50"
                    >
                        <RefreshCw size={13} className={checking ? "animate-spin" : ""} />
                        {checking ? "Checking…" : "Check for replies"}
                    </button>
                </div>
            </div>
            {checkNote && <p className="text-[11px] text-gray-400 px-1 -mt-2">{checkNote}</p>}
            {checkError && <p role="alert" className="text-xs text-rose-600 px-1">{checkError}</p>}
            {loadError && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <p>{loadError}</p>
                <button type="button" onClick={load} className="mt-2 font-semibold underline">Retry</button>
            </div>}

            {loadError ? null : loading && logs.length === 0 ? (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-10 flex items-center justify-center text-gray-400 text-sm gap-2">
                    <Loader2 size={16} className="animate-spin" /> Loading conversation…
                </div>
            ) : logs.length === 0 ? (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-12 text-center">
                    <Mail className="w-8 h-8 text-gray-200 mx-auto mb-3" />
                    <p className="text-sm font-medium text-gray-600">No messages yet</p>
                    <p className="text-xs text-gray-400 mt-1">Emails you send and replies from this lead will appear here.</p>
                </div>
            ) : (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="divide-y divide-gray-50">
                        {logs.map((m) => {
                            const inbound = m.direction === "in";
                            const isEmail = m.channel === "email";
                            const open = openId === m.key;
                            return (
                                <div key={m.key} className={`px-5 py-3.5 ${inbound ? "bg-indigo-50/30" : ""}`}>
                                    <button onClick={() => setOpenId(open ? null : m.key)} className="w-full flex items-start gap-3 text-left">
                                        <span className={`mt-0.5 w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${inbound ? "bg-indigo-100 text-indigo-600" : "bg-gray-100 text-gray-600"}`}>
                                            {inbound ? <Inbox size={15} /> : isEmail ? <Mail size={15} /> : <MessageSquare size={15} />}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="text-sm font-semibold text-gray-900 truncate">
                                                    {isEmail ? (m.subject || "(no subject)") : "SMS"}
                                                </span>
                                                <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border capitalize ${STATUS[m.status] || "bg-gray-100 text-gray-500 border-gray-200"}`}>
                                                    {inbound ? "Reply" : m.status}
                                                </span>
                                            </div>
                                            {!open && <p className="text-xs text-gray-500 mt-0.5 truncate">{stripQuoteMarkers((m.body || "").replace(/<[^>]*>/g, " ")).slice(0, 100)}</p>}
                                            <p className="text-[11px] text-gray-400 mt-1">
                                                {fmt(m.created_at)}
                                                {inbound ? (m.from ? ` · from ${m.from}` : " · from the lead") : (m.sender ? ` · by ${m.sender}` : "")}
                                                {m.template_key ? ` · ${m.template_key}` : ""}
                                            </p>
                                        </div>
                                        <ChevronDown size={14} className={`text-gray-300 shrink-0 mt-1 transition-transform ${open ? "rotate-180" : ""}`} />
                                    </button>
                                    {open && (
                                        <div className="mt-3 ml-11 rounded-xl bg-gray-50 border border-gray-100 p-3.5">
                                            {!inbound && /^\s*<(!doctype|html|p|div|table|h[1-6]|span|br)\b/i.test(m.body || "")
                                                ? <iframe title={m.subject || "Email message"} sandbox="" referrerPolicy="no-referrer"
                                                    srcDoc={m.body} className="w-full min-h-[280px] rounded-lg border-0 bg-white" />
                                                : <p className="text-sm text-gray-700 whitespace-pre-wrap break-words">{stripQuoteMarkers(m.body)}</p>}
                                            {m.error && (
                                                <p className="mt-2 text-xs text-rose-600 flex items-center gap-1.5">
                                                    <AlertTriangle size={12} /> {m.error}
                                                </p>
                                            )}
                                            {canEmail && isEmail && (
                                                <button
                                                    onClick={(e) => { e.stopPropagation(); openReply(m); }}
                                                    className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs font-semibold hover:bg-black"
                                                >
                                                    <Reply size={13} /> Reply to this email
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
