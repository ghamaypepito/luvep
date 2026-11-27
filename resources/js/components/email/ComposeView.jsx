import { useState, useEffect, useRef } from "react";
import { Head, router } from "@inertiajs/react";
import {
    Mail, Send, X, Plus, Paperclip, Eye, Wand2, User, Loader2, PenSquare, Search,
} from "lucide-react";
import RichTextEditor from "@/components/templates/RichTextEditor";
import EmailBuilder from "@/components/templates/EmailBuilder";
import ExaltTemplateDraft from "@/components/email/ExaltTemplateDraft";

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString("en-US", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "");
const isEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s || "").trim());
const statusTone = (s) => ({ sent: "bg-emerald-50 text-emerald-700", queued: "bg-amber-50 text-amber-700", failed: "bg-rose-50 text-rose-700", bounced: "bg-rose-50 text-rose-700" }[s] || "bg-gray-100 text-gray-500");
const isFullHtml = (html) => /^\s*<(!doctype|html)\b/i.test(html || "");

/**
 * Compose module — reused by the admin area and every department portal. Left:
 * a history of Compose-sent emails to preview. Right: a composer (recipients =
 * picked leads and/or typed addresses, subject, the shared body editor +
 * drag-and-drop builder, attachments).
 */
export default function ComposeView({ basePath = "/admin/email/compose", sent = [], templates = [], partnerRecipient = null }) {
    const [leads, setLeads] = useState([]);      // [{id,name,email}]
    const [emails, setEmails] = useState(partnerRecipient ? [partnerRecipient.email.toLowerCase()] : []);     // [string]
    const [subject, setSubject] = useState("");
    const [exaltTemplate, setExaltTemplate] = useState(null);
    const [body, setBody] = useState("");
    const [designJson, setDesignJson] = useState(null);
    const [attachments, setAttachments] = useState([]); // File[]
    const [query, setQuery] = useState("");
    const [suggestions, setSuggestions] = useState([]);
    const [builderOpen, setBuilderOpen] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [selected, setSelected] = useState(null); // a sent item being previewed
    const [sending, setSending] = useState(false);
    const [sendErrors, setSendErrors] = useState({});
    const fileRef = useRef(null);

    // Debounced lead typeahead.
    useEffect(() => {
        const q = query.trim();
        if (q.length < 2 || isEmail(q)) { setSuggestions([]); return undefined; }
        const t = setTimeout(async () => {
            try {
                const res = await fetch(`${basePath}/leads?q=${encodeURIComponent(q)}`, { headers: { Accept: "application/json" }, credentials: "same-origin" });
                const json = await res.json();
                setSuggestions(json.leads || []);
            } catch { setSuggestions([]); }
        }, 250);
        return () => clearTimeout(t);
    }, [query, basePath]);

    const addLead = (l) => {
        if (!leads.some((x) => x.id === l.id)) setLeads((p) => [...p, l]);
        setQuery(""); setSuggestions([]);
    };
    const addEmail = (e) => {
        const v = e.trim().toLowerCase();
        if (isEmail(v) && !emails.includes(v)) setEmails((p) => [...p, v]);
        setQuery(""); setSuggestions([]);
    };
    const onRecipientKey = (e) => {
        if ((e.key === "Enter" || e.key === ",") && isEmail(query)) { e.preventDefault(); addEmail(query); }
    };
    const removeLead = (id) => setLeads((p) => p.filter((x) => x.id !== id));
    const removeEmail = (v) => setEmails((p) => p.filter((x) => x !== v));
    const pickFiles = (e) => {
        const files = Array.from(e.target.files || []);
        setAttachments((previous) => [...previous, ...files]);
        e.target.value = "";
    };
    const removeFile = (i) => setAttachments((p) => p.filter((_, ix) => ix !== i));
    const applyTemplate = (id) => {
        const template = templates.find((item) => String(item.id) === id);
        if (!template) return;
        if ((exaltTemplate || subject.trim() || body.trim()) && !window.confirm("Replace the current draft with this template?")) return;
        setExaltTemplate(template.exalt ? template : null);
        setSubject(template.email_subject || "");
        setBody(template.email_body);
        setDesignJson(template.design_json);
    };

    const resetForm = () => {
        setLeads([]); setEmails(partnerRecipient ? [partnerRecipient.email.toLowerCase()] : []); setSubject(""); setBody(""); setDesignJson(null);
        setAttachments([]); setQuery(""); setSuggestions([]);
        setExaltTemplate(null);
        setSendErrors({});
    };

    const canSend = !exaltTemplate && (leads.length > 0 || emails.length > 0) && (subject.trim() || body.trim()) && !sending;
    const clearExaltTemplate = () => {
        setExaltTemplate(null);
        setSubject("");
        setBody("");
        setDesignJson(null);
    };
    const exaltRecipients = [...new Set([...emails, ...leads.map((lead) => lead.email)].filter(Boolean).map((email) => email.toLowerCase()))];
    const exaltRecipient = exaltRecipients.length === 1 ? exaltRecipients[0] : null;

    const send = () => {
        if (!canSend) return;
        setSendErrors({});
        setSending(true);
        const fd = new FormData();
        if (partnerRecipient) fd.append("partner_id", partnerRecipient.id);
        leads.forEach((l) => fd.append("lead_ids[]", l.id));
        emails.forEach((e) => fd.append("emails[]", e));
        fd.append("subject", subject);
        fd.append("body", body);
        attachments.forEach((f) => fd.append("attachments[]", f));
        router.post(`${basePath}/send`, fd, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => resetForm(),
            onError: setSendErrors,
            onFinish: () => setSending(false),
        });
    };

    const inp = "w-full px-3 py-2 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300";
    const bodyDoc = (html) => isFullHtml(html) ? html : `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;background:#f3f4f6;font-family:'Segoe UI',Arial,sans-serif;color:#333;} .em{max-width:600px;margin:16px auto;background:#fff;padding:24px 28px;font-size:14px;line-height:1.6;} img{max-width:100%;}</style></head><body><div class="em">${html || ""}</div></body></html>`;

    return (
        <div className="flex h-[calc(100vh-120px)] gap-4 max-w-[1400px] mx-auto">
            <Head title="Compose email" />

            {/* Left — sent history */}
            <aside className="w-72 shrink-0 flex flex-col bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                    <span className="text-sm font-bold text-gray-900 flex items-center gap-2"><Mail size={16} /> Sent</span>
                    <button type="button" onClick={() => { setSelected(null); resetForm(); }} className="text-[11px] font-semibold text-[#436235] hover:text-[#375029] inline-flex items-center gap-1"><PenSquare size={12} /> New</button>
                </div>
                <div className="flex-1 overflow-y-auto divide-y divide-gray-50">
                    {sent.length === 0 && <p className="px-4 py-6 text-xs text-gray-400 text-center">No emails sent yet.</p>}
                    {sent.map((m) => (
                        <button key={m.id} type="button" onClick={() => setSelected(m)} className={`w-full text-left px-4 py-3 hover:bg-gray-50 ${selected?.id === m.id ? "bg-indigo-50/50" : ""}`}>
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-semibold text-gray-800 truncate">{m.to_name || m.to}</span>
                                <span className={`text-[9px] font-bold px-1.5 py-0.5 rounded uppercase ${statusTone(m.status)}`}>{m.status}</span>
                            </div>
                            <p className="text-[12px] text-gray-600 truncate mt-0.5">{m.subject || "(no subject)"}</p>
                            <p className="text-[10px] text-gray-400 mt-0.5">{fmtDate(m.created_at)}</p>
                        </button>
                    ))}
                </div>
            </aside>

            {/* Right — compose form OR sent preview */}
            <main className="flex-1 min-w-0 bg-white rounded-2xl border border-gray-100 shadow-sm flex flex-col overflow-hidden">
                {selected ? (
                    <div className="flex flex-col h-full">
                        <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                            <div className="min-w-0">
                                <p className="text-sm font-bold text-gray-900 truncate">{selected.subject || "(no subject)"}</p>
                                <p className="text-xs text-gray-500 truncate">To: {selected.to_name ? `${selected.to_name} · ` : ""}{selected.to} · {fmtDate(selected.created_at)}{selected.sender ? ` · by ${selected.sender}` : ""}</p>
                            </div>
                            <button type="button" onClick={() => setSelected(null)} className="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                        </div>
                        <iframe title="Sent email" srcDoc={bodyDoc(selected.body)} sandbox="" className="flex-1 w-full bg-[#f3f4f6]" />
                    </div>
                ) : (
                    <div className="flex flex-col h-full">
                        <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                            <h1 className="text-sm font-bold text-gray-900 flex items-center gap-2"><PenSquare size={16} /> Compose email</h1>
                            {!exaltTemplate && <button type="button" onClick={send} disabled={!canSend} className="inline-flex items-center gap-2 px-4 py-2 bg-[#436235] text-white text-sm font-bold rounded-xl hover:bg-[#375029] disabled:opacity-40">
                                {sending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} Send
                            </button>}
                        </div>

                        <div className="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">
                            {partnerRecipient && <p className="rounded-lg bg-emerald-50 p-3 text-sm">Partner: {partnerRecipient.name}. This email will appear in the Partner Activity Tracker after it is sent. Reply status can be updated there.</p>}
                            {/* Recipients */}
                            <div className="relative">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">To</span>
                                <div className="flex flex-wrap items-center gap-1.5 px-2 py-1.5 rounded-lg border border-gray-200 min-h-[42px] focus-within:ring-2 focus-within:ring-gray-300">
                                    {leads.map((l) => (
                                        <span key={`l${l.id}`} className="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 text-xs font-semibold rounded-full pl-2 pr-1 py-0.5"><User size={11} /> {l.name} <button type="button" onClick={() => removeLead(l.id)} className="hover:text-indigo-900"><X size={12} /></button></span>
                                    ))}
                                    {emails.map((e) => (
                                        <span key={`e${e}`} className="inline-flex items-center gap-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-full pl-2 pr-1 py-0.5">{e} <button type="button" disabled={!!partnerRecipient} onClick={() => removeEmail(e)} className="hover:text-gray-900"><X size={12} /></button></span>
                                    ))}
                                    <input disabled={!!partnerRecipient} value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={onRecipientKey} placeholder={leads.length || emails.length ? "" : "Type a name to find a lead, or an email address…"} className="flex-1 min-w-[160px] outline-none text-sm py-1" />
                                </div>
                                {(suggestions.length > 0 || (isEmail(query))) && (
                                    <div className="absolute z-20 left-0 right-0 mt-1 bg-white rounded-xl shadow-lg border border-gray-100 py-1 max-h-64 overflow-y-auto">
                                        {isEmail(query) && (
                                            <button type="button" onClick={() => addEmail(query)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2"><Plus size={14} className="text-gray-400" /> Add <strong>{query.trim()}</strong></button>
                                        )}
                                        {suggestions.map((l) => (
                                            <button key={l.id} type="button" onClick={() => addLead(l)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                                                <User size={14} className="text-indigo-400 shrink-0" />
                                                <span className="truncate"><span className="font-semibold text-gray-800">{l.name}</span> <span className="text-gray-400">· {l.email}</span></span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {templates.length > 0 && (
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Email template</span>
                                    <select value="" onChange={(e) => applyTemplate(e.target.value)} className={inp}>
                                        <option value="">Choose an email template</option>
                                        {templates.map((template) => (
                                            <option key={template.id} value={template.id}>
                                                {template.name}{template.department ? ` · ${template.department}` : ""}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}

                            {exaltTemplate ? <ExaltTemplateDraft key={`${exaltTemplate.id}:${exaltRecipient}`}
                                template={exaltTemplate} email={exaltRecipient} attachments={attachments}
                                basePath={basePath} onSent={resetForm} onClear={clearExaltTemplate} /> : <>
                            {/* Subject */}
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Subject</span>
                                <input value={subject} onChange={(e) => setSubject(e.target.value)} className={inp} placeholder="Subject" />
                            </label>

                            {/* Body */}
                            <div className="block">
                                <div className="flex items-center justify-between mb-1">
                                    <span className="block text-xs font-semibold text-gray-600">Body</span>
                                    <div className="flex items-center gap-3">
                                        <button type="button" onClick={() => setBuilderOpen(true)} className="text-[11px] font-bold text-white bg-[#436235] hover:bg-[#375029] inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg"><Wand2 size={12} /> Customize email body</button>
                                        <button type="button" onClick={() => setPreviewOpen(true)} className="text-[11px] font-semibold text-[#436235] hover:text-[#375029] inline-flex items-center gap-1"><Eye size={12} /> Preview</button>
                                    </div>
                                </div>
                                {designJson || isFullHtml(body) ? (
                                    <div className="rounded-lg border border-gray-200 overflow-hidden bg-gray-100">
                                        <div className="flex items-center gap-2 px-3 py-2 border-b border-gray-200 bg-white text-[11px] text-gray-600"><Wand2 size={13} className="text-[#436235]" /> Built with the visual editor — click <strong>Customize email body</strong> to edit.</div>
                                        <iframe title="Body" srcDoc={body} sandbox="" className="w-full bg-gray-100" style={{ height: 360 }} />
                                    </div>
                                ) : (
                                    <RichTextEditor value={body} onChange={setBody} />
                                )}
                                <span className="block text-[11px] text-gray-400 mt-1">Use <code>{"{{first_name}}"}</code>-style variables — they fill in for lead recipients (blank for typed addresses).</span>
                            </div></>}

                        </div>

                        <section aria-label="Attachments" className="shrink-0 border-t border-gray-100 px-5 py-3 max-h-40 overflow-y-auto">
                            <div className="flex items-center gap-2 mb-1">
                                <span className="block text-xs font-semibold text-gray-600">Attachments ({attachments.length})</span>
                                <button type="button" disabled={sending} onClick={() => fileRef.current?.click()} className="text-[11px] font-semibold text-[#436235] hover:text-[#375029] inline-flex items-center gap-1 disabled:opacity-40"><Paperclip size={12} /> Add files</button>
                                <input ref={fileRef} type="file" multiple disabled={sending} onChange={pickFiles} className="hidden" />
                            </div>
                            <p className="text-[11px] text-gray-500 mb-2">Up to 10 files, 10 MB each. Files upload when you send.</p>
                            <div aria-label="Selected attachments" aria-live="polite" className="flex flex-wrap gap-1.5">
                                {attachments.map((f, i) => (
                                    <span key={i} className="inline-flex max-w-full items-center gap-1 bg-gray-100 text-gray-700 text-xs rounded-lg px-2 py-1"><Paperclip size={11} className="shrink-0" /><span className="break-all">{f.name}</span><button type="button" aria-label={`Remove ${f.name}`} disabled={sending} onClick={() => removeFile(i)} className="shrink-0 hover:text-rose-600 disabled:opacity-40"><X size={12} /></button></span>
                                ))}
                            </div>
                            {Object.keys(sendErrors).length > 0 && (
                                <div role="alert" className="mt-2 text-xs text-rose-700">{Object.values(sendErrors).join(" ")}</div>
                            )}
                        </section>
                    </div>
                )}
            </main>

            {/* Visual builder */}
            {builderOpen && (
                <EmailBuilder
                    initialDesign={designJson}
                    initialHtml={body}
                    uploadUrl={`${basePath}/upload-image`}
                    onSave={(html, design) => { setBody(html); setDesignJson(design); setBuilderOpen(false); }}
                    onClose={() => setBuilderOpen(false)}
                />
            )}

            {/* Preview modal (composing email) */}
            {previewOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={() => setPreviewOpen(false)}>
                    <div className="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" />
                    <div className="relative bg-white rounded-2xl shadow-xl border border-gray-100 w-full max-w-2xl h-[82vh] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                            <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2"><Eye size={16} /> Preview</h3>
                            <button type="button" onClick={() => setPreviewOpen(false)} className="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                        </div>
                        {subject && <div className="px-5 py-2 border-b border-gray-100 text-sm"><span className="text-gray-400">Subject:</span> <span className="font-semibold text-gray-800">{subject}</span></div>}
                        <iframe title="Preview" srcDoc={bodyDoc(body)} sandbox="" className="flex-1 w-full bg-[#f3f4f6]" />
                    </div>
                </div>
            )}
        </div>
    );
}
