import React, { useState, useMemo, useEffect } from "react";
import { Head, router, Link } from "@inertiajs/react";
import { toast } from "sonner";
import {
    ReceiptText, Search, Plus, X, Download, Eye, Trash2,
    FileText, Loader2, AlertTriangle, ExternalLink,
    FileSignature, ArrowRight, CheckCircle2,
} from "lucide-react";

// Shared shape for the icon-only row actions — no text labels, the
// intent lives in the tooltip.
const iconBtnCls =
    "w-7 h-7 rounded-lg border border-gray-200 flex items-center justify-center text-gray-500 hover:border-gray-900 hover:text-gray-900 transition-colors";
import { AvatarPhoto } from "@/components/ui/Avatar";

const rowInitials = (name = "") =>
    (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join("") || "?";

const fmtSize = (bytes) => {
    if (! bytes) return "—";
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

const fmtFee = (n) =>
    Number(n).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// NZ GST. Mirrors VisaType::GST_RATE — fees are stored exclusive of GST.
const GST_RATE = 0.15;
const GST_PCT = Math.round(GST_RATE * 100);

const fmtDate = (iso) => {
    if (! iso) return "—";
    return new Date(iso).toLocaleString(undefined, {
        day: "2-digit", month: "short", year: "numeric",
        hour: "2-digit", minute: "2-digit",
    });
};

const money = (n) => (n === null || n === undefined || n === "" ? "—" : `$${Number(n).toFixed(2)}`);

// Pricing context chips for a generated invoice row: the applicant's location
// (onshore/offshore), their country, and whether the fee is GST-inclusive.
function CaseDetails({ row = {} }) {
    const loc = (row.fee_location || "").toLowerCase();
    const locLabel = loc === "offshore" ? "Offshore" : loc === "onshore" ? "Onshore" : null;
    const locTone = loc === "offshore"
        ? "bg-blue-50 text-blue-700 border-blue-200"
        : "bg-teal-50 text-teal-700 border-teal-200";
    return (
        <div className="flex flex-col items-start gap-1">
            {locLabel
                ? <span className={`inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded border ${locTone}`}>{locLabel}</span>
                : <span className="text-[10px] text-gray-300">—</span>}
            {row.country && <span className="text-[10.5px] text-gray-500 truncate max-w-[150px]">{row.country}</span>}
            <span className={`inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded border ${row.include_gst ? "bg-purple-50 text-purple-700 border-purple-200" : "bg-gray-50 text-gray-500 border-gray-200"}`}>
                {row.include_gst ? "Incl. GST" : "Excl. GST"}
            </span>
        </div>
    );
}

/**
 * Invoice workspace. "New" opens a modal with the invoice settings on the
 * left (case, number, dates, fee lines) and a live preview of the tax
 * invoice on the right. Amounts default from the case's visa fees.
 */
export default function Invoice({ cases = [], generated = [], suggestions = [], proofs = [], nextNumber = null }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [view, setView] = useState("invoices"); // "invoices" | "proofs"
    const pendingProofs = proofs.filter((p) => p.status !== "Approved").length;
    // When set, the New-invoice modal opens with this case pre-selected —
    // used by the "generate invoice" suggestion cards.
    const [preselectId, setPreselectId] = useState(null);

    const openNew = (caseId = null) => {
        setPreselectId(caseId);
        setModalOpen(true);
    };
    const closeModal = () => {
        setModalOpen(false);
        setPreselectId(null);
    };

    return (
        <div className="space-y-5 max-w-[1400px] mx-auto pb-12">
            <Head title="Invoice — Immigration" />

            <div className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gray-900 text-white flex items-center justify-center">
                        <ReceiptText size={18} />
                    </div>
                    <div>
                        <h1 className="text-lg font-bold text-gray-900">Invoice</h1>
                        <p className="text-sm text-gray-500">Generate a tax invoice for a case.</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => openNew()}
                    className="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black transition-colors flex items-center gap-2 flex-shrink-0"
                >
                    <Plus size={14} strokeWidth={2.5} /> New
                </button>
            </div>

            {/* Tabs — generated invoices, or client proof-of-payment uploads. */}
            <div className="flex items-center gap-1 border-b border-gray-200">
                {[
                    { key: "invoices", label: "Generated invoices" },
                    { key: "proofs", label: `Proof of payment${pendingProofs ? ` (${pendingProofs})` : ""}` },
                ].map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => setView(t.key)}
                        className={`px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors ${
                            view === t.key ? "border-gray-900 text-gray-900" : "border-transparent text-gray-500 hover:text-gray-900"
                        }`}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {view === "proofs" && <ProofReviewTable proofs={proofs} />}

            {view === "invoices" && suggestions.length > 0 && (
                <SuggestionStrip suggestions={suggestions} onGenerate={openNew} />
            )}

            {/* Generated invoices — one row per case */}
            {view === "invoices" && (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-5 py-3 border-b border-gray-100">
                    <h2 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500">Generated invoices</h2>
                </div>

                {generated.length === 0 ? (
                    <div className="px-5 py-12 text-center text-sm text-gray-400">
                        No invoices generated yet. Click <span className="font-semibold text-gray-600">New</span> to start.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="bg-slate-800 text-[10px] font-bold text-white uppercase tracking-wider">
                                    <th className="px-4 py-3">Profile</th>
                                    <th className="px-3 py-3">Name</th>
                                    <th className="px-3 py-3">Contacts</th>
                                    <th className="px-3 py-3">Invoices</th>
                                    <th className="px-3 py-3">Total amount</th>
                                    <th className="px-3 py-3">Details</th>
                                    <th className="px-3 py-3">Created</th>
                                    <th className="px-3 py-3 text-right pr-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {generated.map((c) => (
                                    <tr key={`${c.case_id}:${c.currency || "NZD"}`} className="hover:bg-gray-50/60 transition-colors align-top">
                                        <td className="px-4 py-3">
                                            <Link href={`/portal/immigration/cases/${c.case_id}/profile`} className="inline-block">
                                                <div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-gray-100 text-gray-500 text-[11px] font-bold ring-1 ring-gray-200">
                                                    <AvatarPhoto src={c.avatar_url} title={c.case_name}>
                                                        {rowInitials(c.case_name)}
                                                    </AvatarPhoto>
                                                </div>
                                            </Link>
                                        </td>
                                        <td className="px-3 py-3">
                                            <Link
                                                href={`/portal/immigration/cases/${c.case_id}/profile`}
                                                className="font-semibold text-gray-900 hover:text-gray-700 hover:underline underline-offset-2"
                                            >
                                                {c.case_name}
                                            </Link>
                                            {c.case_ref && <div className="text-[10px] text-gray-400 font-mono mt-0.5">{c.case_ref}</div>}
                                            <div className="text-[10px] text-gray-400 mt-1">
                                                {c.invoices.length} invoice{c.invoices.length === 1 ? "" : "s"}
                                            </div>
                                        </td>
                                        <td className="px-3 py-3">
                                            {c.email && <div className="text-[11px] text-gray-600 truncate max-w-[220px]">{c.email}</div>}
                                            {c.phone && <div className="text-[11px] text-gray-500 truncate max-w-[220px] mt-0.5">{c.phone}</div>}
                                            {! c.email && ! c.phone && <span className="text-[11px] text-gray-300">—</span>}
                                        </td>
                                        <td className="px-3 py-3">
                                            <div className="flex flex-col gap-2">
                                                {c.invoices.map((iv) => (
                                                    <div key={iv.id} className="flex items-center gap-2 h-5">
                                                        <FileText size={13} className="text-gray-400 shrink-0" />
                                                        <span className="text-[12px] font-semibold text-gray-800 font-mono">{iv.number || "Invoice"}</span>
                                                        <span className="text-[10px] text-gray-400">{fmtSize(iv.size)}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </td>
                                        {/* Total amount — sum of this case's invoice totals. */}
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            {c.total_amount != null ? (
                                                <span className="text-[13px] font-bold text-gray-900 tabular-nums">
                                                    {c.currency || "NZD"} ${Number(c.total_amount).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                                </span>
                                            ) : (
                                                <span className="text-[11px] text-gray-300">—</span>
                                            )}
                                        </td>
                                        {/* Details — onshore/offshore, country, GST. */}
                                        <td className="px-3 py-3">
                                            <CaseDetails row={c} />
                                        </td>
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <div className="text-[12px] text-gray-700 font-medium">{fmtDate(c.latest_created_at)}</div>
                                            {c.latest_by && (
                                                <div className="text-[11px] text-gray-500 mt-0.5">
                                                    by <span className="font-medium text-gray-600">{c.latest_by}</span>
                                                </div>
                                            )}
                                        </td>
                                        {/* Actions — icon-only View/Download per invoice (aligned
                                            to the Invoices column), then the row-level tracker
                                            link and delete. */}
                                        <td className="px-3 py-3 pr-4 whitespace-nowrap">
                                            <div className="flex flex-col gap-2 items-end">
                                                {c.invoices.map((iv) => (
                                                    <div key={iv.id} className="flex items-center justify-end gap-1 h-5">
                                                        <a href={iv.view_url} target="_blank" rel="noopener noreferrer"
                                                           title={`View ${iv.number || "invoice"}`}
                                                           className="w-6 h-6 rounded-md flex items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-900 transition-colors">
                                                            <Eye size={13} />
                                                        </a>
                                                        <a href={iv.download_url}
                                                           title={`Download ${iv.number || "invoice"}`}
                                                           className="w-6 h-6 rounded-md flex items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-900 transition-colors">
                                                            <Download size={13} />
                                                        </a>
                                                    </div>
                                                ))}
                                                <div className="pt-2 mt-0.5 border-t border-gray-100 w-full flex items-center justify-end gap-1">
                                                    <DeleteInvoicesButton caseId={c.case_id} caseName={c.case_name} count={c.invoices.length} />
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            )}

            {modalOpen && (
                <NewInvoiceModal cases={cases} nextNumber={nextNumber} preselectId={preselectId} onClose={closeModal} />
            )}
        </div>
    );
}

// Client proof-of-payment uploads, with verify (approve) / reject actions.
function ProofReviewTable({ proofs = [] }) {
    const [busyId, setBusyId] = useState(null);
    const [previewing, setPreviewing] = useState(null);

    const setStatus = (p, status, onDone) => {
        setBusyId(p.id);
        router.post(p.status_url, { status }, {
            preserveScroll: true,
            preserveState: true,
            only: ["proofs"],
            onSuccess: () => { toast.success(status === "Approved" ? "Payment confirmed" : "Marked as needs attention"); onDone?.(); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not update"),
            onFinish: () => setBusyId(null),
        });
    };

    const badge = (s) => s === "Approved"
        ? "bg-emerald-50 text-emerald-700 border-emerald-200"
        : s === "Rejected"
            ? "bg-rose-50 text-rose-700 border-rose-200"
            : "bg-amber-50 text-amber-700 border-amber-200";
    const badgeLabel = (s) => s === "Approved" ? "Confirmed" : s === "Rejected" ? "Needs attention" : "Under review";

    return (
        <>
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100">
                <h2 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500">Proof of payment</h2>
            </div>
            {proofs.length === 0 ? (
                <div className="px-5 py-12 text-center text-sm text-gray-400">No proof-of-payment uploads yet.</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs min-w-[820px]">
                        <thead>
                            <tr className="bg-slate-800 text-[10px] font-bold text-white uppercase tracking-wider">
                                <th className="px-4 py-3">Case</th>
                                <th className="px-4 py-3">File</th>
                                <th className="px-4 py-3">Uploaded</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {proofs.map((p) => (
                                <tr key={p.id} className="hover:bg-gray-50/60">
                                    <td className="px-4 py-3">
                                        <a href={`/portal/immigration/cases/${p.case_id}/profile`} className="font-bold text-gray-900 hover:text-[#009688]">{p.case_name}</a>
                                        {p.case_ref && <div className="text-[11px] text-gray-400 font-mono">{p.case_ref}</div>}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1.5">
                                            <FileText size={13} className="text-gray-300" />
                                            <span className="text-gray-700 truncate max-w-[200px]" title={p.original_name}>{p.original_name}</span>
                                            <span className="text-gray-400">{fmtSize(p.size)}</span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-500">{fmtDate(p.uploaded_at)}</td>
                                    <td className="px-4 py-3">
                                        <span className={`inline-flex items-center text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full border ${badge(p.status)}`}>{badgeLabel(p.status)}</span>
                                    </td>
                                    <td className="px-4 py-3 pr-4">
                                        <div className="flex items-center justify-end gap-1.5">
                                            <button type="button" onClick={() => setPreviewing(p)} title="View & verify" className={iconBtnCls}><Eye size={13} /></button>
                                            <a href={p.download_url} title="Download" className={iconBtnCls}><Download size={13} /></a>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
        {previewing && (
            <ProofPreviewModal
                proof={previewing}
                busy={busyId === previewing.id}
                onConfirm={() => setStatus(previewing, "Approved", () => setPreviewing(null))}
                onReject={() => setStatus(previewing, "Rejected", () => setPreviewing(null))}
                onClose={() => setPreviewing(null)}
            />
        )}
        </>
    );
}

// Preview a proof-of-payment file in a modal, with Confirm / Reject inline.
function ProofPreviewModal({ proof, busy, onConfirm, onReject, onClose }) {
    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        const prev = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        window.addEventListener("keydown", onKey);
        return () => { document.body.style.overflow = prev; window.removeEventListener("keydown", onKey); };
    }, [onClose]);

    const isPdf = /\.pdf$/i.test(proof.original_name || "");
    const done = proof.status === "Approved" || proof.status === "Rejected";

    return (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-3" onClick={onClose}>
            <div className="w-[95vw] max-w-[1000px] h-[90vh] max-h-[calc(100vh-1.5rem)] bg-white rounded-2xl shadow-xl flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-3 flex-shrink-0">
                    <div className="min-w-0">
                        <h2 className="text-sm font-bold text-gray-900 truncate">{proof.case_name} — proof of payment</h2>
                        <p className="text-[11px] text-gray-400 truncate">{proof.original_name} · {fmtSize(proof.size)} · {fmtDate(proof.uploaded_at)}</p>
                    </div>
                    <div className="flex items-center gap-2 flex-shrink-0">
                        <a href={proof.download_url} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-[12px] font-semibold hover:bg-gray-50"><Download size={13} /> Download</a>
                        <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                    </div>
                </div>
                <div className="flex-1 min-h-0 bg-gray-100 flex items-center justify-center overflow-auto">
                    {isPdf
                        ? <iframe src={proof.view_url} title={proof.original_name} className="w-full h-full border-0" />
                        : <img src={proof.view_url} alt={proof.original_name} className="max-w-full max-h-full object-contain" />}
                </div>
                <div className="px-5 py-3.5 border-t border-gray-100 flex items-center justify-between gap-3 flex-shrink-0 flex-wrap">
                    <p className="text-[12px] text-gray-500">Does this receipt match the invoice amount?</p>
                    <div className="flex items-center gap-2">
                        {proof.status !== "Rejected" && (
                            <button type="button" onClick={onReject} disabled={busy}
                                className="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-gray-600 text-[13px] font-semibold hover:border-rose-400 hover:text-rose-600 disabled:opacity-50">Reject</button>
                        )}
                        {proof.status !== "Approved" && (
                            <button type="button" onClick={onConfirm} disabled={busy}
                                className="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg bg-emerald-600 text-white text-[13px] font-bold hover:bg-emerald-700 disabled:opacity-50">
                                {busy ? <Loader2 size={13} className="animate-spin" /> : <CheckCircle2 size={14} />} Confirm payment
                            </button>
                        )}
                        {done && proof.status === "Approved" && <span className="text-[13px] font-semibold text-emerald-600">Confirmed</span>}
                    </div>
                </div>
            </div>
        </div>
    );
}

// Prompts staff to bill cases that have a generated engagement pack but no
// invoice yet. Reads as a normal section card (matching "Generated invoices")
// rather than a warning banner; each row opens the New-invoice modal
// pre-selected to that case, so it's one click from engagement → invoice.
function SuggestionStrip({ suggestions, onGenerate }) {
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="relative flex h-2 w-2">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-teal-400 opacity-60" />
                        <span className="relative inline-flex rounded-full h-2 w-2 bg-teal-500" />
                    </span>
                    <h2 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500">
                        Ready to invoice
                    </h2>
                    <span className="text-[10px] font-bold text-teal-700 bg-teal-50 border border-teal-100 rounded-full px-2 py-0.5">
                        {suggestions.length}
                    </span>
                </div>
                <p className="text-[11px] text-gray-400 hidden sm:block">
                    Engagement generated · not yet invoiced
                </p>
            </div>

            {/* Capped, scrollable list — stays a tidy fixed height whether one
                case is waiting or fifty. ~4.5 rows peek so it's obviously
                scrollable when full. */}
            <div className="divide-y divide-gray-50 max-h-[248px] overflow-y-auto">
                {suggestions.map((s) => (
                    <div
                        key={s.case_id}
                        className="group flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50/70 transition-colors"
                    >
                        <div className="w-8 h-8 rounded-lg overflow-hidden flex items-center justify-center bg-gray-100 text-gray-500 text-[10px] font-bold ring-1 ring-gray-200 shrink-0">
                            <AvatarPhoto src={s.avatar_url} title={s.case_name}>
                                {rowInitials(s.case_name)}
                            </AvatarPhoto>
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="text-[13px] font-semibold text-gray-900 truncate leading-tight">
                                {s.case_name}
                            </div>
                            <div className="text-[10.5px] text-gray-400 truncate mt-0.5 flex items-center gap-1.5">
                                <FileSignature size={11} className="text-gray-300 shrink-0" />
                                {s.inz_visa_type || "Visa not set"}
                                {s.engagement_at && <span className="text-gray-300">·&nbsp;{fmtDate(s.engagement_at)}</span>}
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => onGenerate(s.case_id)}
                            title={`Generate an invoice for ${s.case_name}`}
                            className="shrink-0 inline-flex items-center gap-1.5 pl-3 pr-2.5 py-1.5 rounded-lg border border-gray-200 text-gray-600 text-[11px] font-semibold hover:bg-gray-900 hover:text-white hover:border-gray-900 transition-colors"
                        >
                            Create invoice
                            <ArrowRight size={12} className="transition-transform group-hover:translate-x-0.5" />
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DeleteInvoicesButton({ caseId, caseName, count }) {
    const [busy, setBusy] = useState(false);
    const remove = () => {
        if (! confirm(`Delete all ${count} invoice${count === 1 ? "" : "s"} for ${caseName}? This removes the files permanently.`)) return;
        setBusy(true);
        router.delete(`/admin/leads/${caseId}/invoice/documents`, {
            preserveScroll: true,
            onError: () => toast.error("Could not delete the invoices."),
            onFinish: () => setBusy(false),
        });
    };
    return (
        <button
            type="button" onClick={remove} disabled={busy}
            title={`Delete all ${count} invoice${count === 1 ? "" : "s"} for ${caseName}`}
            className="w-7 h-7 rounded-lg border border-gray-200 flex items-center justify-center text-rose-500 hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 transition-colors disabled:opacity-40"
        >
            {busy ? <Loader2 size={13} className="animate-spin" /> : <Trash2 size={13} />}
        </button>
    );
}

function NewInvoiceModal({ cases, nextNumber, preselectId = null, onClose }) {
    const [caseSearch, setCaseSearch] = useState("");
    // Pre-select the case when the modal was opened from a suggestion card.
    const [selectedCase, setSelectedCase] = useState(
        () => (preselectId != null ? cases.find((c) => c.id === preselectId) ?? null : null)
    );
    const [submitting, setSubmitting] = useState(false);
    const [previewLoading, setPreviewLoading] = useState(false);

    const today = new Date().toISOString().slice(0, 10);
    const plus7 = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10);

    const [form, setForm] = useState({
        invoice_number: nextNumber || "",
        invoice_date: today,
        due_date: plus7,
    });
    // Which price the invoice quotes for the service fee — "normal" (payment
    // plan) or "discounted" (pay now).
    const [feeTier, setFeeTier] = useState("normal");
    // Applicant location — "onshore" (in NZ) or "offshore" (abroad). Picks
    // which of the visa's two fee schedules the service fee is seeded from.
    const [feeLocation, setFeeLocation] = useState("onshore");
    // Fees are stored excluding GST; this decides whether the service-fee
    // line is seeded ex-GST or as the GST-inclusive RRP.
    const [includeGst, setIncludeGst] = useState(false);
    // Fully editable line items — starts from the visa's fees, staff can
    // edit any row or add their own.
    const [items, setItems] = useState([]);
    // Family invoice: a section per applicant (principal + dependants), built
    // server-side. On by default when the case has dependants. Turning it off
    // reveals the manual line-item editor.
    const [familyMode, setFamilyMode] = useState(false);
    useEffect(() => {
        setFamilyMode(!!selectedCase?.has_family);
    }, [selectedCase]);

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const setItem = (i, k, v) => setItems((list) => list.map((it, idx) => (idx === i ? { ...it, [k]: v } : it)));
    const addItem = () => setItems((list) => [...list, { description: "", quantity: 1, unit_price: "" }]);
    const removeItem = (i) => setItems((list) => list.filter((_, idx) => idx !== i));

    // Which fee columns the chosen location reads from. Mirrors
    // VisaType::FEE_FIELDS on the server.
    const feeFields = feeLocation === "offshore"
        ? { normal: "professional_fees_offshore", discounted: "professional_fees_discounted_offshore", inz: "inz_application_fee_offshore" }
        : { normal: "professional_fees", discounted: "professional_fees_discounted", inz: "inz_application_fee" };
    const normalFee = selectedCase?.[feeFields.normal] ?? null;
    const discountedFee = selectedCase?.[feeFields.discounted] ?? null;
    const inzFee = selectedCase?.[feeFields.inz] ?? null;

    // Offered whenever the visa has a discounted fee explicitly set for this
    // location — even if it happens to equal the normal price.
    const hasDiscounted = discountedFee != null;

    // The ex-GST fee for the selected tier + location — what GST uplifts.
    const quotedFee = !selectedCase
        ? null
        : (feeTier === "discounted" && discountedFee != null ? discountedFee : normalFee);
    useEffect(() => {
        if (!hasDiscounted && feeTier === "discounted") setFeeTier("normal");
    }, [hasDiscounted, feeTier]);

    // Pre-fill the standard lines from the picked case's visa fees, at the
    // chosen tier. Re-seeds when the tier changes so the service-fee line
    // follows pay-now vs payment-plan.
    useEffect(() => {
        if (! selectedCase) return;
        const visa = selectedCase.inz_visa_type || "Visa";
        const baseFee = feeTier === "discounted" && discountedFee != null
            ? discountedFee
            : normalFee;
        // GST applies to our service fee only — the INZ disbursement below is
        // a government charge and is never uplifted.
        const serviceFee = baseFee && includeGst
            ? Math.round(baseFee * (1 + GST_RATE) * 100) / 100
            : baseFee;
        // Always seed the two standard immigration lines so their DESCRIPTIONS
        // stay put when the applicant location changes — even if the chosen
        // location has no fees configured on the Visas page (offshore is often
        // blank, e.g. the Religious Worker Work Visa). The amount is pre-filled
        // when known, otherwise left blank for the staff member to enter
        // manually (see the "missingFees" hint below).
        const seed = [
            {
                description: `Consulting and Service Fee - [${visa} Application] (assessing client's eligibility, documents review, providing advice and lodging the above visa application on behalf of client) - pay in advance`,
                quantity: 1,
                unit_price: serviceFee || "",
            },
            {
                description: `Disbursement - INZ [${visa}] application fee - pay in advance`,
                quantity: 1,
                unit_price: inzFee || "",
            },
        ];
        setItems(seed);
    }, [selectedCase, feeTier, feeLocation, includeGst]);

    const filteredCases = useMemo(() => {
        const q = caseSearch.trim().toLowerCase();
        const list = q
            ? cases.filter((c) => [c.name, c.lead_id, c.email, c.inz_visa_type]
                .some((v) => (v || "").toString().toLowerCase().includes(q)))
            : cases;
        return list.slice(0, 50);
    }, [cases, caseSearch]);

    const lineAmount = (it) => (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
    const total = items.reduce((sum, it) => sum + lineAmount(it), 0);
    const missingFees = selectedCase && ! normalFee && ! inzFee;

    const previewUrl = useMemo(() => {
        if (! selectedCase) return null;
        const p = new URLSearchParams({
            invoice_number: form.invoice_number || "",
            invoice_date: form.invoice_date || "",
            due_date: form.due_date || "",
            fee_tier: feeTier,
            fee_location: feeLocation,
            include_gst: includeGst ? 1 : 0,
        });
        // Family mode omits items so the server itemises per applicant.
        if (!familyMode) p.set("items", JSON.stringify(items));
        return `/admin/leads/${selectedCase.id}/invoice/preview?${p.toString()}`;
    }, [selectedCase, form, items, feeTier, feeLocation, includeGst, familyMode]);

    // Debounce preview reloads while typing amounts.
    const [debouncedUrl, setDebouncedUrl] = useState(null);
    useEffect(() => {
        if (! previewUrl) { setDebouncedUrl(null); return; }
        setPreviewLoading(true);
        const t = setTimeout(() => setDebouncedUrl(previewUrl), 450);
        return () => clearTimeout(t);
    }, [previewUrl]);

    const generate = () => {
        if (! selectedCase) return;
        setSubmitting(true);
        router.post(`/admin/leads/${selectedCase.id}/invoice/generate`, { ...form, fee_tier: feeTier, fee_location: feeLocation, include_gst: includeGst, ...(familyMode ? {} : { items }) }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => toast.error("Could not generate the invoice."),
            onFinish: () => setSubmitting(false),
        });
    };

    const inputCls = "w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-800 focus:outline-none focus:bg-white focus:border-gray-300";

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/40" onClick={onClose}>
            <div className="bg-white w-full max-w-[1400px] h-[94vh] shadow-xl flex flex-col overflow-hidden rounded-lg"
                 onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                    <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                        <ReceiptText size={16} className="text-gray-700" /> New invoice
                    </h3>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X size={18} /></button>
                </div>

                <div className="flex-1 flex flex-col sm:flex-row min-h-0">
                    {/* ── Settings (left) ── */}
                    <div className="sm:w-[420px] sm:flex-none border-r border-gray-100 overflow-y-auto">
                        {/* Case */}
                        <div className="p-4 border-b border-gray-100">
                            <label className="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400">Case</label>
                            {selectedCase ? (
                                <div className="mt-2 flex items-center gap-2 bg-gray-900 border border-gray-900 rounded-lg px-3 py-2.5">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-white truncate">{selectedCase.name}</p>
                                        <p className="text-[11px] text-gray-300 truncate">{selectedCase.inz_visa_type || "No visa set"}</p>
                                    </div>
                                    <button onClick={() => setSelectedCase(null)} className="text-gray-400 hover:text-white"><X size={15} /></button>
                                </div>
                            ) : (
                                <>
                                    <div className="relative mt-2">
                                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                                        <input autoFocus value={caseSearch} onChange={(e) => setCaseSearch(e.target.value)}
                                               placeholder="Search a case…" className={`${inputCls} pl-9`} />
                                    </div>
                                    <div className="mt-2 max-h-52 overflow-y-auto rounded-lg border border-gray-100 divide-y divide-gray-50">
                                        {filteredCases.length === 0 && (
                                            <div className="px-3 py-4 text-center text-xs text-gray-400">No cases found.</div>
                                        )}
                                        {filteredCases.map((c) => (
                                            <button key={c.id} onClick={() => setSelectedCase(c)}
                                                    className="w-full text-left px-3 py-2 hover:bg-gray-50 transition-colors">
                                                <p className="text-xs font-semibold text-gray-900 truncate">{c.name}</p>
                                                <p className="text-[10.5px] text-gray-400 truncate">{c.lead_id} · {c.inz_visa_type || "No visa"}</p>
                                            </button>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Invoice details */}
                        <div className="p-4 border-b border-gray-100 space-y-3">
                            <label className="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400">Invoice details</label>
                            <div>
                                <span className="block text-[11px] font-semibold text-gray-600 mb-1">Invoice number</span>
                                <input value={form.invoice_number} onChange={(e) => set("invoice_number", e.target.value)}
                                       className={`${inputCls} font-mono`} placeholder="INV-0117" />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-600 mb-1">Invoice date</span>
                                    <input type="date" value={form.invoice_date} onChange={(e) => set("invoice_date", e.target.value)} className={inputCls} />
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-600 mb-1">Due date</span>
                                    <input type="date" value={form.due_date} onChange={(e) => set("due_date", e.target.value)} className={inputCls} />
                                </div>
                            </div>
                            {/* Applicant location — onshore vs offshore fee
                                schedule. Changing it re-seeds the service-fee
                                line; edits made afterwards stay. */}
                            <div>
                                <span className="block text-[11px] font-semibold text-gray-600 mb-1">Applicant location</span>
                                <select value={feeLocation} onChange={(e) => setFeeLocation(e.target.value)} className={inputCls}>
                                    <option value="onshore">Onshore (in New Zealand)</option>
                                    <option value="offshore">Offshore (applying from abroad)</option>
                                </select>
                            </div>
                            {/* Which price the service-fee line quotes. Changing
                                either re-seeds that line; edits made after stay. */}
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-600 mb-1">Payment basis</span>
                                    <select value={feeTier} onChange={(e) => setFeeTier(e.target.value)} className={inputCls}>
                                        <option value="normal">
                                            Normal price (payment plan){normalFee != null ? ` · $${fmtFee(normalFee)}` : ""}
                                        </option>
                                        <option value="discounted" disabled={!hasDiscounted}>
                                            Discounted price (pay now){hasDiscounted ? ` · $${fmtFee(discountedFee)}` : " — none set"}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-600 mb-1">GST</span>
                                    <select
                                        value={includeGst ? "incl" : "excl"}
                                        onChange={(e) => setIncludeGst(e.target.value === "incl")}
                                        className={inputCls}
                                    >
                                        <option value="excl">
                                            Excluding GST{quotedFee != null ? ` · $${fmtFee(quotedFee)}` : ""}
                                        </option>
                                        <option value="incl">
                                            Including GST ({GST_PCT}%){quotedFee != null ? ` · $${fmtFee(quotedFee * (1 + GST_RATE))}` : ""}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Line items — fully editable, add as many as needed */}
                        <div className="p-4 space-y-3">
                            <div className="flex items-center justify-between">
                                <label className="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400">Line items</label>
                                {!familyMode && (
                                    <button
                                        type="button"
                                        onClick={addItem}
                                        className="text-[11px] font-semibold text-gray-800 hover:text-black inline-flex items-center gap-1"
                                    >
                                        <Plus size={12} /> Add item
                                    </button>
                                )}
                            </div>

                            {selectedCase?.has_family && (
                                <label className="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 cursor-pointer">
                                    <input type="checkbox" checked={familyMode} onChange={(e) => setFamilyMode(e.target.checked)} className="mt-0.5 rounded border-gray-300" />
                                    <span className="text-[11.5px] text-gray-700 leading-snug">
                                        <span className="font-semibold">Itemise per family member</span> — a section per applicant (principal, partner, then children), each with its consulting fee &amp; disbursement.
                                    </span>
                                </label>
                            )}

                            {familyMode ? (
                                <p className="text-[11.5px] text-gray-500 leading-snug rounded-lg border border-dashed border-gray-200 px-3 py-3">
                                    Each applicant with a visa gets its own consulting fee and INZ disbursement section, totalled at the bottom — see the preview. Turn off <span className="font-semibold">Itemise per family member</span> to edit line items by hand.
                                </p>
                            ) : (
                            <>
                            {items.length === 0 && (
                                <p className="text-[11px] text-gray-400">No items yet — click <span className="font-semibold">Add item</span>.</p>
                            )}

                            {items.map((it, i) => (
                                <div key={i} className="rounded-lg border border-gray-200 overflow-hidden bg-white">
                                    {/* Item header strip — the only tinted band; the fields
                                        below stay on white so they read cleanly. */}
                                    <div className="flex items-center justify-between gap-2 px-3 py-1.5 bg-slate-800">
                                        <span className="text-[10px] font-bold uppercase tracking-wide text-white">Item {i + 1}</span>
                                        <button
                                            type="button"
                                            onClick={() => removeItem(i)}
                                            title="Remove item"
                                            className="text-rose-300 hover:text-rose-100 transition-colors"
                                        >
                                            <Trash2 size={13} />
                                        </button>
                                    </div>

                                    <div className="p-3 space-y-2">
                                        <textarea
                                            rows={3}
                                            value={it.description}
                                            onChange={(e) => setItem(i, "description", e.target.value)}
                                            placeholder="Description"
                                            className={`${inputCls} resize-y leading-snug`}
                                        />

                                        <div className="grid grid-cols-3 gap-2 items-end">
                                            <div>
                                                <span className="block text-[10px] font-semibold text-gray-500 mb-1">Qty</span>
                                                <input type="number" step="0.01" min="0" value={it.quantity}
                                                       onChange={(e) => setItem(i, "quantity", e.target.value)} className={inputCls} />
                                            </div>
                                            <div>
                                                <span className="block text-[10px] font-semibold text-gray-500 mb-1">Unit price</span>
                                                <input type="number" step="0.01" min="0" value={it.unit_price}
                                                       onChange={(e) => setItem(i, "unit_price", e.target.value)} className={inputCls} placeholder="0.00" />
                                            </div>
                                            <div className="text-right">
                                                <span className="block text-[10px] font-semibold text-gray-500 mb-1">Amount</span>
                                                <span className="block text-[13px] font-bold text-gray-900 tabular-nums py-2">
                                                    {money(lineAmount(it))}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}

                            <div className="flex items-center justify-between pt-2 border-t border-gray-100">
                                <span className="text-[11px] font-bold uppercase tracking-wide text-gray-500">Total NZD</span>
                                <span className="text-base font-bold text-gray-900 tabular-nums">{money(total)}</span>
                            </div>

                            {missingFees && (
                                <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5">
                                    <AlertTriangle size={13} className="text-amber-500 mt-0.5 flex-shrink-0" />
                                    <p className="text-[11px] text-amber-700 leading-snug">
                                        This visa has no fees on the <span className="font-semibold">Visas</span> page — enter the amounts manually.
                                    </p>
                                </div>
                            )}
                            </>
                            )}
                        </div>
                    </div>

                    {/* ── Live preview (right) ── */}
                    <div className="flex-1 min-w-0 min-h-[240px] bg-gray-100 relative">
                        {! selectedCase ? (
                            <div className="absolute inset-0 flex flex-col items-center justify-center text-center px-6">
                                <ReceiptText size={30} className="text-gray-300" />
                                <p className="text-sm font-semibold text-gray-500 mt-2">Pick a case to preview</p>
                                <p className="text-xs text-gray-400 mt-1">The live invoice preview will appear here.</p>
                            </div>
                        ) : (
                            <>
                                {previewLoading && (
                                    <div className="absolute inset-0 flex items-center justify-center bg-gray-100/70 z-10">
                                        <Loader2 size={22} className="text-gray-700 animate-spin" />
                                    </div>
                                )}
                                {debouncedUrl && (
                                    <iframe
                                        key={debouncedUrl}
                                        src={debouncedUrl}
                                        title="Invoice preview"
                                        className="w-full h-full bg-white"
                                        onLoad={() => setPreviewLoading(false)}
                                    />
                                )}
                            </>
                        )}
                    </div>
                </div>

                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-4 flex-shrink-0">
                    <p className="text-xs text-gray-400">
                        {selectedCase ? <>Total <span className="font-semibold text-gray-700">{money(total)}</span> · {form.invoice_number}</> : "No case selected"}
                    </p>
                    <div className="flex items-center gap-2">
                        <button onClick={onClose} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800">Cancel</button>
                        <button
                            onClick={generate}
                            disabled={! selectedCase || total <= 0 || submitting}
                            className="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {submitting ? <Loader2 size={14} className="animate-spin" /> : <ReceiptText size={14} />} Generate invoice
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
