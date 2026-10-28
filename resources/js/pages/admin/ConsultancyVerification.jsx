import { useMemo, useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { toast } from "sonner";
import {
    BadgeCheck, Check, AlertCircle, Loader2, ChevronRight, ExternalLink,
    X, Flag, Send, MessageSquarePlus, FileText, Eye, Download,
} from "lucide-react";

// ── Consultancy Agreement Verification ───────────────────────────────────────
// The consultancy counterpart of Program Verification. A generated consultancy
// agreement enters as PENDING; a reviewer confirms/edits each fee line-item,
// leaves internal notes, then Verify → Approve (which emails the client).
export default function ConsultancyVerification({ selectedLeadId = null, proposals = [], counts = {}, leadBase = "/admin" }) {
    const [activeId, setActiveId] = useState(selectedLeadId ?? proposals[0]?.id ?? null);
    const active = useMemo(() => proposals.find((p) => p.id === activeId) || proposals[0] || null, [proposals, activeId]);

    return (
        <>
            <Head title="Consultancy Agreement Verification" />
            {selectedLeadId && <Link href="/consultancy-verification" className="inline-block mb-3 text-sm font-semibold text-emerald-800 hover:underline">← All reviews</Link>}
            <div className="p-6 max-w-[1400px] mx-auto">
                <div className="flex items-center gap-2 mb-1">
                    <BadgeCheck size={22} className="text-gray-900" />
                    <h1 className="text-[22px] font-bold text-gray-900">Consultancy Agreement Verification</h1>
                </div>
                <p className="text-[13px] text-gray-500 mb-5">
                    {counts.pending ?? 0} draft · {counts.verified ?? 0} verified · {counts.approved_today ?? 0} approved today
                </p>

                {proposals.length === 0 ? (
                    <div className="rounded-2xl border border-gray-100 bg-white p-12 text-center text-gray-500">
                        <BadgeCheck size={30} className="mx-auto text-gray-300 mb-2" />
                        <p className="text-[14px] font-semibold text-gray-700">Nothing to verify</p>
                        <p className="text-[12.5px]">Consultancy agreements appear here the moment staff generate one.</p>
                    </div>
                ) : (
                    <div className="flex gap-5 items-start">
                        {/* Queue */}
                        <div className="w-[300px] flex-shrink-0 space-y-2">
                            <div className="flex items-center gap-2">
                                <StatCard label="Draft" value={counts.pending ?? 0} tone="amber" active />
                                <StatCard label="Verified" value={counts.verified ?? 0} tone="emerald" />
                                <StatCard label="Approved" value={counts.approved_today ?? 0} tone="gray" />
                            </div>
                            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 pt-2 pl-1">Newest first</div>
                            {proposals.map((p) => (
                                <button
                                    key={p.id} type="button" onClick={() => setActiveId(p.id)}
                                    className={`w-full text-left rounded-xl border p-3 transition-colors ${
                                        active?.id === p.id ? "border-gray-900 bg-white shadow-sm" : "border-gray-100 bg-white hover:border-gray-300"
                                    }`}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-[13px] font-semibold text-gray-900 truncate">{p.name}</span>
                                        <StatusPill status={p.status} />
                                    </div>
                                    <div className="text-[11px] text-gray-500 mt-0.5 font-mono">{p.lead_id}</div>
                                    <div className="text-[11px] text-gray-500 mt-0.5">{p.scenario_label}</div>
                                    {p.changes_requested && (
                                        <div className="text-[11px] text-rose-600 font-semibold mt-0.5">Changes requested</div>
                                    )}
                                </button>
                            ))}
                        </div>

                        {/* Panel */}
                        {active && <Panel key={active.id} p={active} leadBase={leadBase} />}
                    </div>
                )}
            </div>
        </>
    );
}

function Panel({ p, leadBase }) {
    const [busy, setBusy] = useState(null);
    const [requesting, setRequesting] = useState(false);
    const [viewing, setViewing] = useState(false);

    const post = (url, data, key) => {
        setBusy(key);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {},
            onError: (e) => toast.error(Object.values(e || {})[0] || "Something went wrong."),
            onFinish: () => setBusy(null),
        });
    };
    const base = `/consultancy-verification/${p.id}`;
    const metaUpdate = (patch, key) => post(`${base}/meta`, { meta: patch }, key);
    const approve = (opts) => post(`${base}/approve`, opts, opts.verify_all ? "verify-all" : opts.send_email === false ? "approve-noemail" : "approve");
    const draft = () => post(`${base}/draft`, {}, "draft");

    return (
        <div className="flex-1 min-w-0 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            {/* Header */}
            <div className="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4 flex-wrap">
                <div className="flex items-start gap-3 min-w-0">
                    <div className="w-10 h-10 rounded-full bg-gray-900 text-white flex items-center justify-center text-[12px] font-bold shrink-0">{p.initials}</div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h2 className="text-[16px] font-bold text-gray-900 truncate">{p.name}</h2>
                            <StatusPill status={p.status} />
                        </div>
                        <div className="text-[11px] text-gray-500 mt-0.5 flex items-center gap-x-3 gap-y-0.5 flex-wrap">
                            <span className="font-mono">{p.lead_id}</span>
                            {p.email && <span>{p.email}</span>}
                            <span className="font-semibold text-gray-700">{p.scenario_label}</span>
                            <span className="uppercase text-[10px] tracking-wider">{p.applicant_mode}</span>
                        </div>
                        <Link href={`${leadBase}/leads/${p.id}`} className="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 mt-1">
                            View lead profile <ExternalLink size={11} />
                        </Link>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <button type="button" onClick={draft}
                        disabled={busy === "draft" || p.status === "approved"}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50 disabled:opacity-40">
                        {busy === "draft" ? <Loader2 size={14} className="animate-spin" /> : <FileText size={14} />} Draft
                    </button>
                    <button type="button" onClick={() => setRequesting(true)}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                        Request changes
                    </button>
                    <button type="button" onClick={() => approve({ verify_all: true, send_email: true })}
                        disabled={busy === "verify-all" || p.status === "approved"}
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black disabled:opacity-40">
                        {busy === "verify-all" ? <Loader2 size={14} className="animate-spin" /> : <BadgeCheck size={14} />} Approve
                    </button>
                </div>
            </div>

            {/* Changes-requested banner */}
            {p.changes_requested?.message && (
                <div className="mx-6 mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                    <div className="flex items-start gap-2">
                        <AlertCircle size={15} className="mt-0.5 shrink-0 text-rose-500" />
                        <div className="min-w-0">
                            <div className="text-[10px] font-bold uppercase tracking-wider text-rose-600">Changes requested</div>
                            <p className="text-[12.5px] text-gray-800 leading-snug mt-0.5 whitespace-pre-wrap">{p.changes_requested.message}</p>
                        </div>
                    </div>
                </div>
            )}

            {/* Document summary — the agreement is reviewed as ONE document:
                its total amount, a note, and the overall status (no per fee
                line-item breakdown). */}
            <div className="p-6 pt-4">
                <div className="overflow-x-auto -mx-2">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="text-[10px] font-bold uppercase tracking-wider text-gray-400 border-b border-gray-100">
                                <th className="py-2 pl-2 pr-3">Document</th>
                                <th className="py-2 px-3 w-[150px]">Total amount ({p.currency_symbol})</th>
                                <th className="py-2 px-3">Note</th>
                                <th className="py-2 px-3 text-right pr-2 w-[120px]">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="align-top">
                                <td className="py-3 pl-2 pr-3">
                                    <button type="button" onClick={() => setViewing(true)} className="text-left group" title="Open preview">
                                        <div className="text-[13px] font-semibold text-indigo-600 group-hover:text-indigo-800 group-hover:underline underline-offset-2 inline-flex items-center gap-1.5">
                                            <FileText size={14} className="text-rose-400 shrink-0" />
                                            {p.document ? p.document.name : p.scenario_label}
                                        </div>
                                        {p.document && <div className="text-[11px] text-gray-400">{p.scenario_label}</div>}
                                    </button>
                                    {p.document && (
                                        <a href={p.document.download_url} className="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-500 hover:text-gray-800 mt-1">
                                            <Download size={11} /> Download
                                        </a>
                                    )}
                                </td>
                                <td className="py-3 px-3 text-[14px] font-bold text-gray-900 tabular-nums whitespace-nowrap">
                                    {p.currency_symbol} {Number(p.total_amount).toLocaleString()}
                                </td>
                                <td className="py-3 px-3">
                                    <DocNote
                                        value={p.note}
                                        disabled={p.status === "approved"}
                                        busy={busy === "doc-note"}
                                        onSave={(note) => post(`${base}/meta`, { review_note: note }, "doc-note")}
                                    />
                                </td>
                                <td className="py-3 px-3 text-right">
                                    <StatusPill status={p.status} />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {requesting && (
                <RequestChangesModal
                    items={p.items}
                    onClose={() => setRequesting(false)}
                    onSend={(message, itemKeys) => post(`${base}/request-changes`, { message, item_keys: itemKeys }, "req")}
                    busy={busy === "req"}
                />
            )}

            {viewing && (
                <AgreementReviewModal
                    p={p}
                    onClose={() => setViewing(false)}
                    metaUpdate={metaUpdate}
                    approve={approve}
                    onDraft={draft}
                    busy={busy}
                    onRequestChanges={() => { setViewing(false); setRequesting(true); }}
                    onDocNote={(note) => post(`${base}/meta`, { review_note: note }, "doc-note")}
                />
            )}
        </div>
    );
}

// Full-screen review modal: the generated PDF alongside the fee-item settings
// (amount / status / note) and the Verify & approve / Request changes actions.
function AgreementReviewModal({ p, onClose, metaUpdate, approve, busy, onRequestChanges, onDraft, onDocNote }) {
    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6" onClick={onClose}>
                <div className="w-full max-w-6xl h-[92vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                    {/* Header */}
                    <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3 shrink-0">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <h3 className="text-[15px] font-bold text-gray-900 truncate">{p.name}</h3>
                                <StatusPill status={p.status} />
                            </div>
                            <div className="text-[11px] text-gray-500 truncate">
                                {p.scenario_label}{p.document ? '' : ' · preview (PDF on approval)'}
                            </div>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                            {p.document && (
                                <a href={p.document.download_url}
                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                                    <Download size={13} /> Download
                                </a>
                            )}
                            <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700 p-1"><X size={18} /></button>
                        </div>
                    </div>

                    {/* Body — live agreement preview left, settings right */}
                    <div className="flex-1 flex min-h-0 flex-col lg:flex-row">
                        <div className="flex-1 lg:min-w-0 min-h-[45vh] lg:min-h-0 bg-gray-100 border-b lg:border-b-0 lg:border-r border-gray-100 relative">
                            <iframe src={p.preview_url} title="Agreement preview" className="absolute inset-0 w-full h-full" />
                            <a href={p.preview_url} target="_blank" rel="noopener noreferrer"
                                className="absolute bottom-2 right-2 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-white/90 border border-gray-200 text-[11px] font-semibold text-gray-700 shadow-sm hover:bg-white">
                                <ExternalLink size={11} /> Open in new tab
                            </a>
                        </div>

                        <div className="w-full lg:w-96 lg:shrink-0 overflow-y-auto p-4 space-y-3">
                            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Fee items</div>

                            {p.changes_requested?.message && (
                                <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                                    <div className="text-[10px] font-bold uppercase tracking-wider text-rose-600">Changes requested</div>
                                    <p className="text-[12px] text-gray-800 mt-0.5 whitespace-pre-wrap">{p.changes_requested.message}</p>
                                </div>
                            )}

                            {p.items.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-gray-200 p-4 text-center text-[12px] text-gray-500">
                                    Free engagement — no fees. Approve to send.
                                </div>
                            ) : p.items.map((it) => (
                                <ItemEditor
                                    key={it.key} item={it} currency={p.currency_symbol}
                                    onAmount={(amount) => metaUpdate({ [it.key]: { amount } }, `row-${it.key}`)}
                                />
                            ))}

                            <div className="flex items-center justify-between pt-1 text-[12px]">
                                <span className="font-bold text-gray-700">Total</span>
                                <span className="font-bold text-gray-900">{p.currency_symbol} {Number(p.total_amount).toLocaleString()}</span>
                            </div>

                            {/* One note for the whole agreement — the SAME note shown
                                in the Note column of the list. */}
                            <div>
                                <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Note</div>
                                <DocNote
                                    value={p.note}
                                    disabled={p.status === "approved"}
                                    busy={busy === "doc-note"}
                                    onSave={onDocNote}
                                />
                            </div>
                        </div>
                    </div>

                    {/* Footer actions */}
                    <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2 shrink-0">
                        <button type="button" onClick={onDraft}
                            disabled={busy === "draft" || p.status === "approved"}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50 disabled:opacity-40">
                            {busy === "draft" ? <Loader2 size={14} className="animate-spin" /> : <FileText size={14} />} Draft
                        </button>
                        <button type="button" onClick={onRequestChanges}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                            Request changes
                        </button>
                        <button type="button" onClick={() => approve({ verify_all: true, send_email: true })}
                            disabled={busy === "verify-all" || p.status === "approved"}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black disabled:opacity-40">
                            {busy === "verify-all" ? <Loader2 size={14} className="animate-spin" /> : <BadgeCheck size={14} />} Approve
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}

// One fee item's amount inside the review modal — no status, no per-item note
// (the note lives at the document level, shared with the Note column).
function ItemEditor({ item, currency, onAmount }) {
    const [amount, setAmount] = useState(item.amount ?? "");
    const saveAmount = () => { const n = amount === "" ? null : Number(amount); if (n !== item.amount) onAmount(n); };

    return (
        <div className="rounded-xl border border-gray-100 p-3">
            <label className="block text-[12.5px] font-semibold text-gray-900 mb-1">{item.label}</label>
            <div className="relative">
                <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] text-gray-400">{currency}</span>
                <input type="number" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} onBlur={saveAmount}
                    onKeyDown={(e) => { if (e.key === "Enter") e.currentTarget.blur(); }}
                    placeholder="Set amount"
                    className="w-full pl-10 pr-2.5 py-1.5 border border-gray-200 rounded-lg text-[12.5px] focus:outline-none focus:border-gray-900" />
            </div>
        </div>
    );
}

// Document-level note — one note for the whole agreement. Saves on blur.
function DocNote({ value, onSave, busy, disabled }) {
    const [note, setNote] = useState(value ?? "");
    useEffect(() => { setNote(value ?? ""); }, [value]);
    const save = () => { if ((note || "") !== (value || "")) onSave(note); };

    return (
        <div>
            <textarea
                rows={2}
                value={note}
                disabled={disabled}
                onChange={(e) => setNote(e.target.value)}
                onBlur={save}
                placeholder="Add a note about this agreement…"
                className="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-[12.5px] focus:outline-none focus:border-gray-900 resize-y disabled:bg-gray-50 disabled:text-gray-400"
            />
            {busy && <span className="text-[10px] text-gray-400">Saving…</span>}
        </div>
    );
}

function FeeRow({ item, leadId, currency, flagged, onAmount, onStatus, busy }) {
    const [editing, setEditing] = useState(false);
    const [val, setVal] = useState(item.amount ?? "");
    const verified = item.status === "verified";
    const save = () => { setEditing(false); const n = val === "" ? null : Number(val); if (n !== item.amount) onAmount(n); };

    return (
        <tr className="align-top">
            <td className="py-3 pl-2 pr-3">
                <div className="flex items-center gap-2">
                    <span className="text-[13px] font-semibold text-gray-900">{item.label}</span>
                    {flagged && <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-rose-50 text-rose-600 border border-rose-200">Revise</span>}
                    {item.edited && <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-indigo-50 text-indigo-600 border border-indigo-200">Edited</span>}
                </div>
            </td>
            <td className="py-3 px-3">
                {editing ? (
                    <div className="flex items-center gap-1">
                        <input autoFocus type="number" min="0" value={val} onChange={(e) => setVal(e.target.value)}
                            onKeyDown={(e) => { if (e.key === "Enter") save(); if (e.key === "Escape") setEditing(false); }}
                            className="w-28 px-2 py-1 border border-gray-200 rounded-md text-[12px] focus:outline-none focus:border-gray-900" />
                        <button type="button" onClick={save} className="text-emerald-600 shrink-0"><Check size={14} /></button>
                    </div>
                ) : (
                    <button type="button" onClick={() => { setVal(item.amount ?? ""); setEditing(true); }} className="text-[13px] text-gray-800 hover:text-gray-900 hover:underline">
                        {item.amount == null ? <span className="text-gray-300">Set amount</span> : `${currency} ${Number(item.amount).toLocaleString()}`}
                    </button>
                )}
            </td>
            <td className="py-3 px-3">
                <ItemNotes leadId={leadId} item={item} />
            </td>
            <td className="py-3 px-3 text-right">
                <button type="button" onClick={onStatus} disabled={busy}
                    className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border transition-colors disabled:opacity-50 ${
                        verified ? "bg-emerald-50 text-emerald-700 border-emerald-200" : "bg-rose-50 text-rose-600 border-rose-200"
                    }`}>
                    {busy ? <Loader2 size={11} className="animate-spin" /> : verified ? <Check size={11} /> : <AlertCircle size={11} />}
                    {verified ? "Verified" : "Needs check"}
                </button>
            </td>
        </tr>
    );
}

// Per fee-item note thread — latest note shown, rest expand. Add / reply / actioned.
function ItemNotes({ leadId, item }) {
    const notes = item.notes || [];
    const ordered = [...notes].reverse();
    const [expanded, setExpanded] = useState(false);
    const [adding, setAdding] = useState(false);
    const [body, setBody] = useState("");
    const base = `/consultancy-verification/${leadId}/notes/${item.key}`;
    const send = (url, data, done) => router.post(url, data, { preserveScroll: true, onSuccess: done });
    const addNote = () => { if (!body.trim()) return; send(base, { body }, () => { setBody(""); setAdding(false); }); };
    const toggle = (id) => send(`${base}/${id}/actioned`, {});

    const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "2-digit", month: "short" }) : "");

    return (
        <div className="space-y-1.5 min-w-[220px]">
            {ordered.length > 0 && (() => {
                const shown = expanded ? ordered : ordered.slice(0, 1);
                return shown.map((n) => {
                    const done = !!n.actioned_at;
                    const cr = n.tag === "change_requested";
                    return (
                        <div key={n.id} className={!expanded ? `rounded-lg border px-2.5 py-1.5 ${cr ? "border-rose-200 bg-rose-50" : "border-amber-200 bg-amber-50"}` : ""}>
                            <div className="flex items-start gap-1.5">
                                {!expanded && <Flag size={11} className={`mt-0.5 shrink-0 ${cr ? "text-rose-500" : "text-amber-500"}`} />}
                                <span className={`text-[12px] leading-snug [overflow-wrap:anywhere] ${done ? "line-through text-gray-400" : "text-gray-800"}`}>{n.body}</span>
                            </div>
                            <div className="text-[10px] text-gray-500 mt-0.5 ml-[16px]">{n.author} · {fmt(n.created_at)}</div>
                            {expanded && (
                                <button type="button" onClick={() => toggle(n.id)} className={`mt-1 ml-[16px] text-[10px] font-semibold ${done ? "text-gray-400" : "text-emerald-700 hover:text-emerald-900"}`}>
                                    {done ? "Actioned ✓" : (cr ? "Mark as actioned" : "Acknowledge")}
                                </button>
                            )}
                        </div>
                    );
                });
            })()}

            <div className="flex items-center gap-3">
                {notes.length > 0 && (
                    <button type="button" onClick={() => setExpanded((v) => !v)} className="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-500 hover:text-gray-800">
                        {expanded ? "Show less" : `${notes.length} note${notes.length === 1 ? "" : "s"}`}
                        <ChevronRight size={11} className={`transition-transform ${expanded ? "rotate-90" : ""}`} />
                    </button>
                )}
                {!adding && (
                    <button type="button" onClick={() => setAdding(true)} className="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 hover:text-emerald-900">
                        <MessageSquarePlus size={12} /> Add note
                    </button>
                )}
            </div>

            {adding && (
                <div className="flex items-start gap-1">
                    <textarea autoFocus rows={2} value={body} onChange={(e) => setBody(e.target.value)}
                        onKeyDown={(e) => { if (e.key === "Escape") { setBody(""); setAdding(false); } }}
                        placeholder="Internal note…" className="w-full px-2 py-1 border border-gray-200 rounded-md text-[12px] focus:outline-none focus:border-gray-900 resize-y" />
                    <button type="button" onClick={addNote} className="text-emerald-600 mt-1 shrink-0"><Check size={14} /></button>
                </div>
            )}
        </div>
    );
}

function RequestChangesModal({ items = [], onClose, onSend, busy }) {
    const [message, setMessage] = useState("");
    const [picked, setPicked] = useState([]);
    const toggle = (k) => setPicked((p) => (p.includes(k) ? p.filter((x) => x !== k) : [...p, k]));

    return (
        <>
            <div className="fixed inset-0 bg-black/30 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
                <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl" onClick={(e) => e.stopPropagation()}>
                    <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 className="text-[15px] font-bold text-gray-900">Request changes</h3>
                        <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                    </div>
                    <div className="p-5 space-y-4">
                        {items.length > 0 && (
                            <div>
                                <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Fee items to revise</div>
                                <div className="space-y-1.5 max-h-[200px] overflow-y-auto">
                                    {items.map((it) => (
                                        <label key={it.key} className="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-100 hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" checked={picked.includes(it.key)} onChange={() => toggle(it.key)} className="rounded" />
                                            <span className="text-[12.5px] text-gray-800">{it.label}</span>
                                        </label>
                                    ))}
                                </div>
                                <p className="text-[11px] text-gray-400 mt-1">Optional — tick the items that need changing.</p>
                            </div>
                        )}
                        <div>
                            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Message</div>
                            <textarea rows={3} value={message} onChange={(e) => setMessage(e.target.value)}
                                placeholder="What needs revising? (the submitter sees this)"
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-[13px] focus:outline-none focus:border-gray-900 resize-y" />
                        </div>
                    </div>
                    <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-end gap-2">
                        <button type="button" onClick={onClose} className="text-[13px] font-semibold text-gray-500 hover:text-gray-800">Cancel</button>
                        <button type="button" onClick={() => onSend(message, picked)} disabled={busy}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[13px] font-bold hover:bg-black disabled:opacity-40">
                            {busy ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />} Send
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}

function StatCard({ label, value, tone, active }) {
    const tones = { amber: "text-amber-700", emerald: "text-emerald-700", gray: "text-gray-700" };
    return (
        <div className={`flex-1 rounded-xl border px-3 py-2 ${active ? "border-gray-900 bg-white" : "border-gray-100 bg-white"}`}>
            <div className={`text-[18px] font-bold ${tones[tone] || "text-gray-700"}`}>{value}</div>
            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400">{label}</div>
        </div>
    );
}

function StatusPill({ status }) {
    const map = {
        pending: ["bg-amber-50 text-amber-700 border-amber-200", "Draft"],
        verified: ["bg-blue-50 text-blue-700 border-blue-200", "Verified"],
        approved: ["bg-emerald-50 text-emerald-700 border-emerald-200", "Approved"],
    };
    const [cls, label] = map[status] || ["bg-gray-50 text-gray-600 border-gray-200", status || "—"];
    return <span className={`inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${cls}`}>{label}</span>;
}
