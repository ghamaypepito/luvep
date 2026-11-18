import { useMemo, useState, useEffect, useRef } from "react";
import { Head, router } from "@inertiajs/react";
import { toast } from "sonner";
import RichTextEditor from "@/components/templates/RichTextEditor";
import { FileText, Trash2, Search, Layers, ArrowRight, X, Eye, Pencil, Save, Send, FilePlus, Wand2, Download, ChevronDown, FileType2 } from "lucide-react";

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—");
const relTime = (iso) => {
    if (!iso) return "—";
    const s = (Date.now() - new Date(iso).getTime()) / 1000;
    if (s < 3600) return `${Math.max(1, Math.round(s / 60))} min ago`;
    if (s < 86400) { const h = Math.round(s / 3600); return `${h} hour${h === 1 ? "" : "s"} ago`; }
    const d = Math.floor(s / 86400);
    if (d === 1) return "Yesterday";
    if (d < 7) return `${d} days ago`;
    return fmtDate(iso);
};
const STATE = {
    draft: ["Draft", "bg-gray-100 text-gray-500"],
    edited: ["Edited on case", "bg-violet-50 text-violet-700"],
    awaiting_signature: ["Awaiting signature", "bg-amber-50 text-amber-700"],
    signed: ["Signed", "bg-emerald-50 text-emerald-700"],
    needs_manager: ["Needs manager", "bg-rose-50 text-rose-700"],
    sent: ["Sent to client", "bg-emerald-50 text-emerald-700"],
};

// Merge variables staff can drop into a document; filled per case on send.
const VAR_GROUPS = [
    ["Client", ["client.full_name", "client.first_name", "client.email", "client.nationality", "client.passport_no"]],
    ["Case", ["case.reference", "case.visa_type", "case.fee_total", "case.inz_fee", "case.fee_instalments", "case.lodged_on"]],
    ["Adviser & firm", ["adviser.full_name", "adviser.licence", "firm.name", "firm.address", "today"]],
];
const SAMPLE = {
    "client.full_name": "Aroha Ngata", "client.first_name": "Aroha", "client.email": "aroha@example.com", "client.nationality": "New Zealand", "client.passport_no": "PA1234567",
    "case.reference": "IMM-0001", "case.visa_type": "Accredited Employer Work Visa", "case.fee_total": "$3,450.00", "case.inz_fee": "$750.00", "case.fee_instalments": "3", "case.lodged_on": "12 Aug 2026",
    "adviser.full_name": "Hendry Dai", "adviser.licence": "201100888", "firm.name": "ePathways", "firm.address": "Auckland, NZ", "today": fmtDate(new Date().toISOString()),
};
const fillPreview = (html) => String(html || "").replace(/\{([a-z0-9_.]+)\}/gi, (m, k) => (SAMPLE[k] !== undefined ? SAMPLE[k] : m));

export default function ClientDocuments({ cases = [], formats = [], usages = [], visaOptions = [] }) {
    const [tab, setTab] = useState("compose");

    // ── Composer: a blank document you write, then Save (→ file formats) or
    // Send (→ pick a case, fill variables, deliver to the client). Loading a
    // saved format via "Use this format" seeds these fields.
    const [docId, setDocId] = useState(null); // set once a compose is saved / loaded from a format
    const [name, setName] = useState("Untitled document");
    const [content, setContent] = useState("");
    const [dirty, setDirty] = useState(false);
    const [preview, setPreview] = useState(false);
    const [saving, setSaving] = useState(false);
    const [sendOpen, setSendOpen] = useState(false);
    const [dlOpen, setDlOpen] = useState(false);
    const editorRef = useRef(null);
    const pickNewest = useRef(false);

    // After a brand-new save, the store redirects with fresh `formats` (newest
    // first) — adopt that id so subsequent saves update the same format.
    useEffect(() => {
        if (pickNewest.current && formats[0]) { setDocId(formats[0].id); pickNewest.current = false; }
        // eslint-disable-next-line
    }, [formats]);

    const resetComposer = () => { setDocId(null); setName("Untitled document"); setContent(""); setDirty(false); setPreview(false); };

    const save = () => {
        const cleanName = name.trim();
        if (!cleanName) return toast.error("Give the document a name first");
        setSaving(true);
        const payload = { name: cleanName, content, category: "client_facing", status: "live" };
        const done = { preserveScroll: true, only: ["formats"], onFinish: () => setSaving(false), onError: () => toast.error("Could not save") };
        if (docId) {
            router.post(`/admin/document-formats/${docId}`, payload, { ...done, onSuccess: () => { setDirty(false); toast.success("Saved to file formats"); } });
        } else {
            pickNewest.current = true;
            router.post("/admin/document-formats", payload, { ...done, onSuccess: () => { setDirty(false); toast.success("Saved to file formats"); } });
        }
    };

    const useFormat = (f) => { setDocId(f.id); setName(f.name || "Untitled document"); setContent(f.content || ""); setDirty(false); setPreview(false); setTab("compose"); };

    const insertVar = (token) => {
        const ed = editorRef.current;
        if (ed) ed.chain().focus().insertContent(`{${token}}`).run();
        else { setContent((c) => `${c} {${token}}`); }
        setDirty(true);
    };

    const canSend = content.trim().length > 0 && name.trim().length > 0;

    // Download the current document as PDF or Word. A real form POST (not Inertia)
    // so the browser handles the file download; carries the CSRF token.
    const download = (format) => {
        if (!content.trim()) { toast.error("Write the document first"); return; }
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "/admin/document-formats/download";
        [["_token", token], ["name", name.trim() || "Untitled document"], ["content", content], ["format", format]].forEach(([k, v]) => {
            const i = document.createElement("input"); i.type = "hidden"; i.name = k; i.value = v; form.appendChild(i);
        });
        document.body.appendChild(form);
        form.requestSubmit();
        form.remove();
        setDlOpen(false);
    };

    return (
        <>
            <Head title="Initial Agreement" />
            <div className="max-w-[1400px] mx-auto pb-12">
                {/* Header */}
                <div className="flex items-start justify-between gap-4 flex-wrap mb-4">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-violet-600">Case · Initial Agreement</p>
                        <h1 className="text-[26px] font-bold text-gray-900 tracking-tight">Initial agreement</h1>
                        <p className="text-[13.5px] text-gray-500 mt-1 max-w-[62ch]">Write a document with variables in it. <b>Save</b> it to reuse as a file format, or <b>Send</b> it to a client — the variables fill themselves in from that case.</p>
                    </div>
                    {tab === "compose" && (
                        <div className="flex items-center gap-2.5">
                            <span className={`text-[12.5px] font-medium ${dirty ? "text-amber-600" : "text-gray-400"}`}>{dirty ? "Unsaved" : docId ? "Saved" : "New document"}</span>
                            <button type="button" onClick={save} disabled={saving}
                                className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl border border-gray-200 text-gray-800 text-[13.5px] font-semibold hover:border-gray-400 disabled:opacity-50"><Save size={15} /> Save</button>
                            <div className="relative">
                                <button type="button" onClick={() => setDlOpen((o) => !o)}
                                    className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl border border-gray-200 text-gray-800 text-[13.5px] font-semibold hover:border-gray-400"><Download size={15} /> Download <ChevronDown size={13} className="text-gray-400" /></button>
                                {dlOpen && (
                                    <>
                                        <div className="fixed inset-0 z-30" onClick={() => setDlOpen(false)} />
                                        <div className="absolute right-0 mt-1 w-48 bg-white border border-gray-200 rounded-xl shadow-xl py-1 z-40">
                                            <button type="button" onClick={() => download("pdf")} className="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] text-gray-700 hover:bg-gray-50"><FileText size={15} className="text-rose-500" /> Download as PDF</button>
                                            <button type="button" onClick={() => download("word")} className="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] text-gray-700 hover:bg-gray-50"><FileType2 size={15} className="text-blue-600" /> Download as Word</button>
                                        </div>
                                    </>
                                )}
                            </div>
                            <button type="button" onClick={() => canSend ? setSendOpen(true) : toast.error("Write the document first")} disabled={!canSend}
                                className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-violet-600 text-white text-[13.5px] font-semibold hover:bg-violet-700 disabled:opacity-50"><Send size={15} /> Send to client</button>
                        </div>
                    )}
                </div>

                {/* Tabs */}
                <div className="border-b border-gray-200 flex gap-1 mb-5">
                    {[["compose", "New document", null], ["formats", "File formats", formats.length], ["cases", "Sent to cases", usages.length]].map(([v, t, n]) => (
                        <button key={v} type="button" onClick={() => setTab(v)}
                            className={`flex items-center gap-2 px-4 py-2.5 text-[14px] font-semibold border-b-2 -mb-px ${tab === v ? "border-violet-600 text-violet-700" : "border-transparent text-gray-500 hover:text-gray-800"}`}>
                            {t}{n !== null && <span className={`text-[11px] font-mono px-1.5 py-0.5 rounded ${tab === v ? "bg-violet-100 text-violet-700" : "bg-gray-100 text-gray-400"}`}>{n}</span>}
                        </button>
                    ))}
                </div>

                {tab === "cases" && <CaseDocs formats={formats} usages={usages} cases={cases} />}

                {tab === "formats" && <FormatList formats={formats} cases={cases} onUse={useFormat} />}

                {tab === "compose" && (
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-5 items-start">
                        {/* ── Center: blank word editor ── */}
                        <div className="lg:col-span-3 bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden min-h-[70vh]">
                            <div className="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                                <input value={name} onChange={(e) => { setName(e.target.value); setDirty(true); }} className="flex-1 text-[15px] font-semibold text-gray-900 outline-none" placeholder="Document name" />
                                {docId && <button type="button" onClick={resetComposer} className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-500 border border-gray-200 rounded-lg px-2.5 py-1.5 hover:border-gray-400"><FilePlus size={13} /> New blank</button>}
                                <button type="button" onClick={() => setPreview((p) => !p)} className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-violet-700 border border-gray-200 rounded-lg px-3 py-1.5 hover:border-violet-400">
                                    {preview ? <><Pencil size={13} /> Edit</> : <><Eye size={13} /> Preview with sample values</>}
                                </button>
                            </div>
                            <div className="min-h-[60vh]">
                                {preview ? (
                                    <div className="bg-gray-100 p-6"><div className="tiptap-body max-w-[820px] min-h-[620px] mx-auto bg-white rounded shadow-sm px-12 py-10 text-[15px] leading-relaxed text-gray-800" dangerouslySetInnerHTML={{ __html: fillPreview(content) }} /></div>
                                ) : (
                                    <RichTextEditor variant="document" value={content} onChange={(html) => { setContent(html); setDirty(true); }} onReady={(ed) => { editorRef.current = ed; }} />
                                )}
                            </div>
                        </div>

                        {/* ── Right: variables ── */}
                        <div className="lg:col-span-1 space-y-4">
                            <div className="bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
                                <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500">Insert a variable</p>
                                <p className="text-[11px] text-gray-400 mt-0.5 mb-3">Click one to drop it in — it fills from the case when you send.</p>
                                {VAR_GROUPS.map(([label, tokens]) => (
                                    <div key={label} className="mb-3 last:mb-0">
                                        <p className="text-[9.5px] font-bold uppercase tracking-wider text-gray-400 mb-1.5">{label}</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {tokens.map((t) => (
                                                <button key={t} type="button" onClick={() => insertVar(t)} disabled={preview}
                                                    className="font-mono text-[10.5px] px-1.5 py-1 rounded border border-violet-100 bg-violet-50 text-violet-700 hover:bg-violet-100 disabled:opacity-40">{`{${t}}`}</button>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="bg-violet-50 border border-violet-100 rounded-2xl p-4">
                                <p className="text-[12px] font-semibold text-violet-900 flex items-center gap-1.5"><Wand2 size={14} /> How it works</p>
                                <ul className="text-[11.5px] text-violet-800/80 mt-2 space-y-1.5 list-disc pl-4">
                                    <li><b>Save</b> stores this as a reusable file format.</li>
                                    <li><b>Send to client</b> picks a case, fills the variables with that case's real values, and delivers the PDF to the client.</li>
                                    <li>Reopen any saved format from the <b>File formats</b> tab.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {sendOpen && <SendModal name={name} content={content} cases={cases} onClose={() => setSendOpen(false)} />}
        </>
    );
}

// ── File formats: saved documents, each reusable via "Use this format" ───────
function FormatList({ formats = [], cases = [], onUse }) {
    const [assignFor, setAssignFor] = useState(null);
    const del = (f) => { if (!window.confirm(`Delete “${f.name}”? This removes it from every case using it.`)) return; router.delete(`/admin/document-formats/${f.id}`, { preserveScroll: true, only: ["formats"] }); };

    if (formats.length === 0) {
        return (
            <div className="bg-white border border-gray-100 rounded-2xl shadow-sm px-6 py-16 text-center">
                <Layers size={34} className="text-gray-300 mx-auto" />
                <p className="text-[14px] font-semibold text-gray-700 mt-3">No saved formats yet</p>
                <p className="text-[12.5px] text-gray-400 mt-1">Write one on the <b>New document</b> tab and press <b>Save</b>.</p>
            </div>
        );
    }

    return (
        <>
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                {formats.map((f) => (
                    <div key={f.id} className="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 flex flex-col">
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-[14px] font-semibold text-gray-900 truncate">{f.name}</p>
                                <p className="text-[11px] text-gray-400 mt-0.5">{f.uses_count ? `${f.uses_count} case${f.uses_count === 1 ? "" : "s"}` : "not applied"} · {fmtDate(f.updated_at)}</p>
                            </div>
                            <span className={`text-[9px] font-bold uppercase tracking-wider rounded px-1.5 py-0.5 ${f.status === "live" ? "text-teal-700 bg-teal-50 border border-teal-200" : "text-amber-700 bg-amber-50 border border-amber-200"}`}>{f.status === "live" ? "Live" : "Draft"}</span>
                        </div>
                        <div className="mt-3 text-[12px] text-gray-500 line-clamp-3 flex-1" dangerouslySetInnerHTML={{ __html: fillPreview(f.content) || "<span class='text-gray-300'>Empty</span>" }} />
                        <div className="flex items-center gap-2 mt-3 pt-3 border-t border-gray-100">
                            <button type="button" onClick={() => onUse(f)} className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-gray-900 text-white text-[12.5px] font-semibold hover:bg-gray-800"><Pencil size={13} /> Use this format</button>
                            <button type="button" onClick={() => setAssignFor(f)} title="Apply to cases" className="text-gray-500 hover:text-violet-700 hover:bg-violet-50 rounded-lg p-2"><Layers size={15} /></button>
                            <button type="button" onClick={() => del(f)} title="Delete" className="text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg p-2"><Trash2 size={15} /></button>
                        </div>
                    </div>
                ))}
            </div>
            {assignFor && <AssignModal formats={[assignFor]} initialFormatId={assignFor.id} cases={cases} onClose={() => setAssignFor(null)} />}
        </>
    );
}

// ── Send to client: pick one case, deliver the filled PDF ────────────────────
function SendModal({ name, content, cases = [], onClose }) {
    const [picked, setPicked] = useState(null);
    const [query, setQuery] = useState("");
    const [sending, setSending] = useState(false);
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return (q ? cases.filter((c) => (c.name + " " + (c.lead_id || "")).toLowerCase().includes(q)) : cases).slice(0, 100);
    }, [cases, query]);

    const send = () => {
        if (!picked) return toast.error("Pick a case");
        setSending(true);
        router.post("/admin/document-formats/send-to-case", { lead_id: picked, name: name.trim(), content }, {
            preserveScroll: true, only: ["usages", "formats"],
            onSuccess: () => onClose(),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not send"),
            onFinish: () => setSending(false),
        });
    };

    return (
        <div className="fixed inset-0 z-[80] bg-black/40 flex items-center justify-center p-4" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-[96vw] max-w-[520px] max-h-[86vh] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-3.5 border-b border-gray-100">
                    <div>
                        <h2 className="text-[15px] font-bold text-gray-900">Send to a client</h2>
                        <p className="text-[12px] text-gray-400">Variables fill from the case you pick. It's delivered as a PDF.</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                </div>
                <div className="px-4 pt-3">
                    <div className="relative">
                        <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search cases…" className="w-full text-[12.5px] pl-8 pr-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-gray-400" />
                    </div>
                </div>
                <div className="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-0.5">
                    {filtered.length === 0 && <p className="text-center text-[12.5px] text-gray-400 py-8">No cases found.</p>}
                    {filtered.map((c) => (
                        <label key={c.id} className="flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="radio" name="send-case" checked={picked === c.id} onChange={() => setPicked(c.id)} className="text-violet-600" />
                            <div className="min-w-0"><p className="text-[13px] font-medium text-gray-800 truncate">{c.name}</p><p className="text-[10.5px] text-gray-400 font-mono">{c.lead_id}{c.inz_visa_type ? ` · ${c.inz_visa_type}` : ""}</p></div>
                        </label>
                    ))}
                </div>
                <div className="flex items-center justify-end gap-2 px-5 py-3 border-t border-gray-100">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-500 hover:text-gray-900">Cancel</button>
                    <button type="button" onClick={send} disabled={sending || !picked} className="inline-flex items-center gap-1.5 px-5 py-2 rounded-xl bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700 disabled:opacity-50"><Send size={14} /> {sending ? "Sending…" : "Send"}</button>
                </div>
            </div>
        </div>
    );
}

// ── Sent to cases (usage) ────────────────────────────────────────────────────
function CaseDocs({ formats, usages, cases }) {
    const [assignOpen, setAssignOpen] = useState(false);
    const [query, setQuery] = useState("");
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return q ? usages.filter((u) => (u.format_name + " " + u.case_name).toLowerCase().includes(q)) : usages;
    }, [usages, query]);
    const remove = (u) => {
        const url = u.kind === "sent" ? `/admin/leads/${u.case_id}/documents/${u.id}` : `/admin/document-format-uses/${u.id}`;
        router.delete(url, { preserveScroll: true, only: ["usages", "formats"] });
    };

    return (
        <div>
            <div className="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 className="text-[15px] font-bold text-gray-900">Documents on cases <span className="text-[12.5px] font-normal text-gray-400 ml-1">· applied from a format, then edited per case</span></h2>
                </div>
                <div className="flex items-center gap-2">
                    <div className="relative">
                        <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search…" className="text-[13px] pl-8 pr-3 py-2 border border-gray-200 rounded-lg w-[180px] focus:outline-none focus:border-gray-400" />
                    </div>
                    <button type="button" onClick={() => setAssignOpen(true)} disabled={formats.length === 0} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-[13px] font-semibold hover:bg-gray-800 disabled:opacity-50"><Layers size={15} /> Apply a format</button>
                </div>
            </div>

            <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                {filtered.length === 0 ? (
                    <div className="px-6 py-16 text-center"><FileText size={34} className="text-gray-300 mx-auto" /><p className="text-[14px] font-semibold text-gray-700 mt-3">No documents applied yet</p><p className="text-[12.5px] text-gray-400 mt-1">Use <b>Apply a format</b> to add a document to one or more cases.</p></div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left min-w-[720px]">
                            <thead>
                                <tr className="bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-400 uppercase tracking-[0.1em]">
                                    <th className="px-5 py-3">Case</th>
                                    <th className="px-3 py-3">Document</th>
                                    <th className="px-3 py-3">State</th>
                                    <th className="px-3 py-3">Updated</th>
                                    <th className="px-3 py-3 w-10"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {filtered.map((u) => {
                                    const [label, tone] = STATE[u.state] || STATE.edited;
                                    return (
                                        <tr key={`${u.kind}-${u.id}`} className="group hover:bg-gray-50/60">
                                            <td className="px-5 py-3.5"><p className="text-[13.5px] font-semibold text-gray-900">{u.case_name}</p>{u.case_ref && <p className="text-[10.5px] text-gray-400 font-mono">{u.case_ref}</p>}</td>
                                            <td className="px-3 py-3.5 text-[13px] text-gray-700">{u.format_name}</td>
                                            <td className="px-3 py-3.5"><span className={`text-[11px] font-semibold px-2.5 py-1 rounded-full ${tone}`}>{label}</span></td>
                                            <td className="px-3 py-3.5 text-[12.5px] text-gray-400">{relTime(u.updated_at)}</td>
                                            <td className="px-3 py-3.5 text-right"><button type="button" onClick={() => remove(u)} title="Remove" className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-md p-1.5 transition-opacity"><Trash2 size={14} /></button></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {assignOpen && <AssignModal formats={formats} cases={cases} onClose={() => setAssignOpen(false)} />}
        </div>
    );
}

function AssignModal({ formats = [], cases = [], initialFormatId = null, onClose }) {
    const [formatId, setFormatId] = useState(initialFormatId ?? formats[0]?.id ?? "");
    const fmt = formats.find((f) => String(f.id) === String(formatId));
    const [content, setContent] = useState(fmt?.content || "");
    const [picked, setPicked] = useState([]);
    const [caseSearch, setCaseSearch] = useState("");
    const [saving, setSaving] = useState(false);
    useEffect(() => { setContent(fmt?.content || ""); /* eslint-disable-next-line */ }, [formatId]);

    const filteredCases = useMemo(() => {
        const q = caseSearch.trim().toLowerCase();
        return (q ? cases.filter((c) => (c.name + " " + (c.lead_id || "")).toLowerCase().includes(q)) : cases).slice(0, 100);
    }, [cases, caseSearch]);
    const toggle = (id) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const apply = () => {
        if (!formatId) return toast.error("Pick a format");
        if (!picked.length) return toast.error("Select at least one case");
        setSaving(true);
        router.post(`/admin/document-formats/${formatId}/apply`, { lead_ids: picked, content }, { preserveScroll: true, only: ["usages", "formats"], onSuccess: () => onClose(), onError: (e) => toast.error(Object.values(e)[0] || "Could not apply"), onFinish: () => setSaving(false) });
    };

    return (
        <div className="fixed inset-0 z-[80] bg-black/40 flex items-center justify-center p-4" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-[96vw] max-w-[1050px] h-[86vh] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-3.5 border-b border-gray-100"><h2 className="text-[15px] font-bold text-gray-900">Apply a document to cases</h2><button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button></div>
                <div className="flex-1 min-h-0 flex flex-col lg:flex-row">
                    <div className="lg:w-[340px] flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-100 flex flex-col min-h-0">
                        <div className="p-3 border-b border-gray-100"><label className="block text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Document format</label><select value={formatId} onChange={(e) => setFormatId(e.target.value)} className="w-full text-[13px] px-2.5 py-2 border border-gray-200 rounded-lg bg-white">{formats.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}</select></div>
                        <div className="p-3 flex items-center justify-between"><label className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Apply to cases</label><span className="text-[11px] text-violet-700 font-semibold">{picked.length} selected</span></div>
                        <div className="px-3"><div className="relative mb-2"><Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" /><input value={caseSearch} onChange={(e) => setCaseSearch(e.target.value)} placeholder="Search cases…" className="w-full text-[12.5px] pl-8 pr-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-gray-400" /></div></div>
                        <div className="flex-1 min-h-0 overflow-y-auto px-3 pb-3 space-y-0.5">
                            {filteredCases.map((c) => (
                                <label key={c.id} className="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer"><input type="checkbox" checked={picked.includes(c.id)} onChange={() => toggle(c.id)} className="rounded border-gray-300 text-violet-600" /><div className="min-w-0"><p className="text-[12.5px] font-medium text-gray-800 truncate">{c.name}</p><p className="text-[10px] text-gray-400 font-mono">{c.lead_id}{c.inz_visa_type ? ` · ${c.inz_visa_type}` : ""}</p></div></label>
                            ))}
                        </div>
                    </div>
                    <div className="flex-1 min-h-0 flex flex-col">
                        <div className="px-4 py-2 border-b border-gray-100 flex items-center gap-2 text-[11px] text-gray-400"><ArrowRight size={13} /> Edit the copy that will be applied to the selected case(s)</div>
                        <div className="flex-1 min-h-0 overflow-y-auto p-4"><RichTextEditor value={content} onChange={setContent} /></div>
                    </div>
                </div>
                <div className="flex items-center justify-end gap-2 px-5 py-3 border-t border-gray-100"><button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-500 hover:text-gray-900">Cancel</button><button type="button" onClick={apply} disabled={saving} className="px-5 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 disabled:opacity-50">{saving ? "Applying…" : `Apply to ${picked.length || ""} case${picked.length === 1 ? "" : "s"}`}</button></div>
            </div>
        </div>
    );
}
