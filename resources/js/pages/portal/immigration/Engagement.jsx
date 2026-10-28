import React, { useState, useMemo, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import { Head, router, Link } from "@inertiajs/react";
import { toast } from "sonner";
import {
    FileSignature, Search, Plus, X, Download, Check,
    FileText, Loader2, Mail, Eye, Trash2, ExternalLink, Copy, Link2, ScrollText, Send as SendIcon, CheckCircle2,
} from "lucide-react";
import { AvatarPhoto } from "@/components/ui/Avatar";
import { confirmDialog } from "@/components/ui/ConfirmDialog";
import GenerationProgress from "@/components/ui/GenerationProgress";
import EngagementEmailReview from "@/components/immigration/EngagementEmailReview";
import useEngagementWorkflow from "@/components/immigration/useEngagementWorkflow";
import PdfDocumentPreview from "@/components/immigration/PdfDocumentPreview";

// Initials fallback for the profile avatar when there's no face image.
const rowInitials = (name = "") =>
    (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join("") || "?";

const fmtFee = (n) =>
    Number(n).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Pricing context chips for a generated engagement / invoice row: the
// applicant's location (onshore/offshore), their country, and whether the fee
// is quoted GST-inclusive or exclusive.
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

// NZ GST. Mirrors VisaType::GST_RATE — fees are stored exclusive of GST.
const GST_RATE = 0.15;
const GST_PCT = Math.round(GST_RATE * 100);

// A per-applicant fee input for the engagement modal. Holds a local draft and
// commits on blur / Enter; blank clears the override (back to the visa fee).
function ApplicantFeeInput({ value, placeholder, disabled = false, onCommit }) {
    const [local, setLocal] = useState(value != null ? String(value) : "");
    useEffect(() => { setLocal(value != null ? String(value) : ""); }, [value]);
    const commit = () => {
        const orig = value != null ? String(value) : "";
        if (local === orig) return;
        onCommit(local);
    };
    return (
        <div className="flex items-center gap-1 flex-shrink-0">
            <span className="text-[11px] text-gray-400">$</span>
            <input
                type="number" min="0" step="0.01"
                value={local}
                disabled={disabled}
                onChange={(e) => setLocal(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); e.currentTarget.blur(); } }}
                placeholder={placeholder != null ? Number(placeholder).toFixed(2) : "0.00"}
                title="Blank uses the visa fee"
                className="w-20 text-[12px] text-right tabular-nums border border-gray-200 rounded px-2 py-0.5 bg-white focus:outline-none focus:border-gray-400 disabled:opacity-40 disabled:bg-gray-100"
            />
        </div>
    );
}

// One control style for every input/select in the settings panel, so the
// column reads as a single stack rather than a pile of one-off styles.
const ctrlCls = "w-full px-2.5 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-800 focus:outline-none focus:bg-white focus:border-gray-400";

const FieldLabel = ({ children }) => (
    <label className="text-[11px] font-semibold text-gray-500">{children}</label>
);

// The engagement documents' brand teal — see the .cover / table.head rules in
// agreements/engagement/layout.blade.php. Used here so the fee shown in the
// settings matches the fee tables in the document being previewed.
const BRAND_TEAL = "#2f7d84";

/** Inline hint / warning under a setting. */
const Note = ({ tone = "gray", children }) => (
    <p className={`text-[10.5px] leading-snug rounded-lg px-2.5 py-1.5 ${
        tone === "amber"
            ? "text-amber-700 bg-amber-50 border border-amber-200"
            : "text-gray-500 bg-gray-50 border border-gray-100"
    }`}>
        {children}
    </p>
);

const fmtSize = (bytes) => {
    if (! bytes) return "—";
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

const fmtDate = (iso) => {
    if (! iso) return "—";
    const d = new Date(iso);
    return d.toLocaleString(undefined, {
        day: "2-digit", month: "short", year: "numeric",
        hour: "2-digit", minute: "2-digit",
    });
};

// The client's scoped engagement link — open in a new tab or copy to share.
function ClientLink({ url }) {
    const abs = typeof window !== "undefined" ? `${window.location.origin}${url}` : url;
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(abs);
            toast.success("Client link copied");
        } catch {
            toast.error("Could not copy the link");
        }
    };
    return (
        <div className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 pl-2 pr-1 py-0.5 max-w-full">
            <Link2 size={11} className="text-gray-400 shrink-0" />
            <span className="text-[11px] text-gray-500 truncate max-w-[140px]" title={abs}>{url}</span>
            <a href={url} target="_blank" rel="noopener noreferrer" title="Open client link"
                className="w-5 h-5 rounded flex items-center justify-center text-gray-500 hover:text-gray-900 hover:bg-gray-200 transition-colors">
                <ExternalLink size={11} />
            </a>
            <button type="button" onClick={copy} title="Copy client link"
                className="w-5 h-5 rounded flex items-center justify-center text-gray-500 hover:text-gray-900 hover:bg-gray-200 transition-colors">
                <Copy size={11} />
            </button>
        </div>
    );
}

/**
 * Delete a case's whole generated engagement pack in one call. Confirms
 * first (naming the case + document count), then removes the stored files
 * and their rows server-side.
 */
function DeleteCaseDocsButton({ caseId, caseName, count, packId }) {
    const [busy, setBusy] = useState(false);

    const remove = async () => {
        const ok = await confirmDialog({
            title: "Delete engagement documents?",
            message: `Delete all ${count} engagement document${count === 1 ? '' : 's'} for ${caseName}? This removes the files permanently.`,
            confirmText: "Delete", tone: "danger",
        });
        if (! ok) return;
        setBusy(true);
        router.delete(`/admin/leads/${caseId}/engagement/documents?pack_id=${encodeURIComponent(packId || "legacy")}`, {
            preserveScroll: true,
            onError: () => toast.error("Could not delete the documents."),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <button
            type="button"
            onClick={remove}
            disabled={busy}
            title={`Delete all ${count} engagement document(s) for ${caseName}`}
            className="w-7 h-7 rounded-lg border border-gray-200 flex items-center justify-center text-rose-500 hover:border-rose-300 hover:bg-rose-50 transition-colors disabled:opacity-40"
        >
            {busy ? <Loader2 size={12} className="animate-spin" /> : <Trash2 size={13} />}
        </button>
    );
}

/**
 * Engagement generation workspace. Staff click "New", pick a case, choose
 * which engagement documents to generate (Written Agreement + the three
 * IAA standard docs), preview each live, then generate. The Written
 * Agreement's fees are pulled from the case's visa on the Visas page.
 */
export default function Engagement({ cases = [], documents = [], generated = [], signers = [], default_signer_id = null, me_id = null, activityLog = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [auditFor, setAuditFor] = useState(null);   // { case_name, audit } for the audit-trail modal
    const [manageFor, setManageFor] = useState(null); // the draft row being managed
    const [preselectedCase, setPreselectedCase] = useState(null);
    const [view, setView] = useState("documents");    // "documents" | "log"

    // Deep-link from a case profile's "Generate Engagement" button:
    // /portal/immigration/cases/engagement?case={id} opens the modal preselected.
    // If that case already has a draft, open it in manage mode instead.
    useEffect(() => {
        if (typeof window === "undefined") return;
        const caseId = new URLSearchParams(window.location.search).get("case");
        if (!caseId) return;
        const existing = generated.find((g) => String(g.case_id) === String(caseId));
        if (existing) { setManageFor(existing); return; }
        const c = cases.find((x) => String(x.id) === String(caseId));
        if (c) { setPreselectedCase(c); setModalOpen(true); }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="space-y-5 max-w-[1400px] mx-auto pb-12">
            <Head title="Engagement — Immigration" />

            <div className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gray-900 text-white flex items-center justify-center">
                        <FileSignature size={18} />
                    </div>
                    <div>
                        <h1 className="text-lg font-bold text-gray-900">Engagement</h1>
                        <p className="text-sm text-gray-500">Generate engagement documents for a case.</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => setModalOpen(true)}
                    className="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black transition-colors flex items-center gap-2 flex-shrink-0"
                >
                    <Plus size={14} strokeWidth={2.5} /> New
                </button>
            </div>

            {/* Tabs — the generated pack table, or the fee-change activity log. */}
            <div className="flex items-center gap-1 border-b border-gray-200">
                {[
                    { key: "documents", label: "Generated documents" },
                    { key: "log", label: `Activity log${activityLog.length ? ` (${activityLog.length})` : ""}` },
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

            {/* Recently generated — same column layout as Proposals & Agreements */}
            {view === "documents" && (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-5 py-3 border-b border-gray-100">
                    <h2 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500">Generated documents</h2>
                </div>

                {generated.length === 0 ? (
                    <div className="px-5 py-12 text-center text-sm text-gray-400">
                        No engagement documents generated yet. Click <span className="font-semibold text-gray-600">New</span> to start.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs min-w-[900px]">
                            {/* Fixed widths so the documents column takes the
                                slack instead of leaving a gap before Created. */}
                            <colgroup>
                                <col className="w-[56px]" />
                                <col className="w-[230px]" />
                                <col />
                                <col className="w-[120px]" />
                                <col className="w-[120px]" />
                                <col className="w-[160px]" />
                                <col className="w-[130px]" />
                                <col className="w-[150px]" />
                                <col className="w-[90px]" />
                            </colgroup>
                            <thead>
                                <tr className="bg-slate-800 text-[10px] font-bold text-white uppercase tracking-wider">
                                    <th className="px-4 py-2.5">Profile</th>
                                    <th className="px-3 py-2.5">Name &amp; contacts</th>
                                    <th className="px-3 py-2.5">Documents &amp; link</th>
                                    <th className="px-3 py-2.5">Consulting fee</th>
                                    <th className="px-3 py-2.5">Total amount</th>
                                    <th className="px-3 py-2.5">Details</th>
                                    <th className="px-3 py-2.5">Status</th>
                                    <th className="px-3 py-2.5">Created</th>
                                    <th className="px-3 py-2.5 text-right pr-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {/* One row per CASE — its generated documents stack inside. */}
                                {generated.map((c) => (
                                    <tr key={`${c.case_id}:${c.pack_id || "legacy"}`} className="hover:bg-gray-50/60 transition-colors align-top">
                                        {/* Profile */}
                                        <td className="px-4 py-3">
                                            <Link href={`/portal/immigration/cases/${c.case_id}/profile`} className="inline-block">
                                                <div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-gray-100 text-gray-500 text-[11px] font-bold ring-1 ring-gray-200">
                                                    <AvatarPhoto src={c.avatar_url} title={c.case_name}>
                                                        {rowInitials(c.case_name)}
                                                    </AvatarPhoto>
                                                </div>
                                            </Link>
                                        </td>
                                        {/* Name & contacts */}
                                        <td className="px-3 py-3">
                                            <Link
                                                href={`/portal/immigration/cases/${c.case_id}/profile`}
                                                className="font-semibold text-gray-900 hover:text-gray-700 hover:underline underline-offset-2"
                                            >
                                                {c.case_name}
                                            </Link>
                                            {c.engagement_type_label && <p className="mt-0.5 text-[11px] text-gray-600">{c.engagement_type_label}</p>}
                                            <div className="mt-0.5">
                                                {c.email && <div className="text-[10.5px] text-gray-500 truncate max-w-[210px]">{c.email}</div>}
                                                {c.phone && <div className="text-[10.5px] text-gray-400 truncate max-w-[210px]">{c.phone}</div>}
                                                {! c.email && ! c.phone && <div className="text-[10px] text-gray-300">No contact</div>}
                                            </div>
                                        </td>
                                        {/* Documents & link — a count of what was
                                            generated plus the client's scoped
                                            engagement link (open + copy), instead
                                            of listing every file. */}
                                        <td className="px-3 py-3">
                                            <div className="flex flex-col items-start gap-1.5">
                                                <span className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-800">
                                                    <FileText size={12} className="text-gray-400" />
                                                    {c.doc_count} document{c.doc_count === 1 ? "" : "s"}
                                                </span>
                                                {c.is_draft ? (
                                                    <button type="button" onClick={() => setManageFor(c)}
                                                        className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-600 hover:text-gray-900 border border-gray-200 rounded-md px-2 py-1 hover:bg-gray-50">
                                                        <Eye size={12} /> View / manage draft
                                                    </button>
                                                ) : c.signing_url ? (
                                                    <ClientLink url={c.signing_url} />
                                                ) : (
                                                    <div className="text-[11px] text-gray-300">No client link</div>
                                                )}
                                            </div>
                                        </td>
                                        {/* Consulting fee — our professional fee (ex GST). */}
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            {c.fee_total != null ? (
                                                <>
                                                    <div className="text-[13px] font-bold text-gray-900 tabular-nums">
                                                        {c.currency || "NZD"} ${Number(c.fee_total).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                                    </div>
                                                    <div className="text-[10px] text-gray-400">ex GST</div>
                                                </>
                                            ) : (
                                                <span className="text-[11px] text-gray-300">—</span>
                                            )}
                                        </td>
                                        {/* Total amount — the grand total (our fees
                                            incl GST + INZ). Blank on older rows that
                                            predate the stored total (regenerate to fill). */}
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            {c.total_amount != null ? (
                                                <>
                                                    <div className="text-[13px] font-bold text-gray-900 tabular-nums">
                                                        {c.currency || "NZD"} ${Number(c.total_amount).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                                    </div>
                                                    <div className="text-[10px] text-gray-400">{c.engagement_type === "immigration_advice" || !c.engagement_type ? "incl. fees & INZ" : c.engagement_type === "oia" ? "incl. GST" : "booking fee"}</div>
                                                </>
                                            ) : (
                                                <span className="text-[11px] text-gray-300">—</span>
                                            )}
                                        </td>
                                        {/* Details — pricing context: onshore/offshore,
                                            country, and whether GST is included. */}
                                        <td className="px-3 py-3">
                                            <CaseDetails row={c} />
                                        </td>
                                        {/* Signed / draft status */}
                                        <td className="px-3 py-3">
                                            {c.signed ? (
                                                <div>
                                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        <Check size={11} /> Signed
                                                    </span>
                                                    {(c.signer_name || c.signed_at) && (
                                                        <div className="text-[10px] text-gray-400 mt-1">
                                                            {c.signer_name}{c.signer_name && c.signed_at ? " · " : ""}{c.signed_at ? fmtDate(c.signed_at) : ""}
                                                        </div>
                                                    )}
                                                </div>
                                            ) : c.is_draft ? (
                                                <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                                    Draft
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
                                                    Sent
                                                </span>
                                            )}
                                        </td>
                                        {/* Created — latest generation for this case */}
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <div className="text-[12px] text-gray-700 font-medium">{fmtDate(c.latest_created_at)}</div>
                                            {c.latest_by && (
                                                <div className="text-[11px] text-gray-500 mt-0.5">
                                                    by <span className="font-medium text-gray-600">{c.latest_by}</span>
                                                </div>
                                            )}
                                        </td>
                                        {/* Actions — open the client's tracker, or
                                            delete the case's whole pack. */}
                                        <td className="px-3 py-3 pr-4 whitespace-nowrap">
                                            <div className="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setAuditFor({ caseName: c.case_name, audit: c.audit })}
                                                    title="Audit trail"
                                                    className="w-7 h-7 rounded-lg border border-gray-200 flex items-center justify-center text-gray-500 hover:border-gray-900 hover:text-gray-900 transition-colors"
                                                >
                                                    <ScrollText size={13} />
                                                </button>
                                                <DeleteCaseDocsButton
                                                    caseId={c.case_id}
                                                    packId={c.pack_id}
                                                    caseName={c.case_name}
                                                    count={c.documents.length}
                                                />
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

            {view === "log" && (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-3 border-b border-gray-100">
                        <h2 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500">Fee change log</h2>
                    </div>
                    {activityLog.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-gray-400">No fee changes logged yet.</div>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {activityLog.map((a) => (
                                <li key={a.id} className="px-5 py-3 flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <p className="text-[13px] text-gray-800">{a.description}</p>
                                        <p className="text-[11px] text-gray-400 mt-0.5">
                                            {a.actor_name}
                                            <span className="ml-1.5 inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-500">{a.action}</span>
                                        </p>
                                    </div>
                                    <span className="text-[11px] text-gray-400 whitespace-nowrap flex-shrink-0">{fmtDate(a.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {modalOpen && (
                <NewEngagementModal
                    cases={cases}
                    documents={documents}
                    signers={signers}
                    defaultSignerId={default_signer_id}
                    meId={me_id}
                    initialCase={preselectedCase}
                    onClose={() => { setModalOpen(false); setPreselectedCase(null); }}
                />
            )}

            {auditFor && (
                <AuditTrailModal caseName={auditFor.caseName} audit={auditFor.audit} onClose={() => setAuditFor(null)} />
            )}

            {manageFor && (
                <NewEngagementModal
                    cases={cases}
                    documents={documents}
                    signers={signers}
                    defaultSignerId={default_signer_id}
                    meId={me_id}
                    initialCase={cases.find((c) => String(c.id) === String(manageFor.case_id)) || { id: manageFor.case_id, name: manageFor.case_name, email: manageFor.email }}
                    draft={manageFor}
                    onClose={() => setManageFor(null)}
                />
            )}
        </div>
    );
}

// Signing audit trail for a case's engagement — who sent it, who signed, when.
function AuditTrailModal({ caseName, audit = {}, onClose }) {
    const signed = audit.status === "Signed";
    const fmt = (iso) => {
        if (!iso) return "—";
        const d = new Date(iso);
        return `${d.toLocaleDateString("en-NZ", { year: "numeric", month: "2-digit", day: "2-digit" })}\n${d.toLocaleTimeString("en-NZ", { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}`;
    };

    return createPortal(
        <div className="fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4 overflow-y-auto" role="dialog" aria-modal="true" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg my-8" onClick={(e) => e.stopPropagation()}>
                <header className="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold text-gray-900">Audit trail</h2>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                </header>

                <div className="p-6 space-y-5">
                    {/* Details */}
                    <section className="rounded-xl border border-gray-200">
                        <div className="px-4 py-2.5 border-b border-gray-100 text-[13px] font-semibold text-gray-800">Details</div>
                        <dl className="divide-y divide-gray-50">
                            <Row label="File name" value={audit.file_name || `${caseName} — Engagement`} />
                            <Row label="Status" value={
                                <span className="inline-flex items-center gap-1.5">
                                    <span className={`w-2 h-2 rounded-full ${signed ? "bg-emerald-500" : "bg-amber-500"}`} />
                                    {signed ? "Signed" : "Awaiting signature"}
                                </span>
                            } />
                            <Row label="Status timestamp" value={<span className="whitespace-pre-line tabular-nums">{fmt(audit.status_at)} UTC</span>} />
                        </dl>
                    </section>

                    {/* Activity */}
                    <section className="rounded-xl border border-gray-200">
                        <div className="px-4 py-2.5 border-b border-gray-100 text-[13px] font-semibold text-gray-800">Activity</div>
                        <ul className="divide-y divide-gray-50">
                            <Activity
                                icon={<SendIcon size={16} />} tag="Sent"
                                body={<><span className="font-medium text-gray-800">{audit.sent_by_email || audit.sent_by || "Staff"}</span> sent a signature request to:<br /><span className="text-gray-700">• {audit.client_name}{audit.client_email ? ` (${audit.client_email})` : ""}</span></>}
                                at={fmt(audit.sent_at)}
                            />
                            {signed && (
                                <Activity
                                    icon={<FileSignature size={16} />} tag="Signed"
                                    body={<><span className="font-semibold text-gray-900">Signed</span> by {audit.signer_name || audit.client_name}{audit.client_email ? ` (${audit.client_email})` : ""}</>}
                                    at={fmt(audit.signed_at)}
                                />
                            )}
                            {signed && (
                                <Activity
                                    icon={<CheckCircle2 size={16} />} tag="Completed"
                                    body={<>This document has been signed and is <span className="font-semibold">complete</span>.</>}
                                    at={fmt(audit.signed_at)}
                                />
                            )}
                        </ul>
                    </section>

                    <p className="text-[11px] text-gray-400 leading-relaxed">
                        The email address indicated for the signer may be associated with the account used to access the signing link. Timestamps are shown in UTC.
                    </p>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function Row({ label, value }) {
    return (
        <div className="px-4 py-3 flex items-start gap-4">
            <dt className="w-32 flex-shrink-0 text-[10px] font-bold uppercase tracking-wider text-gray-400 pt-0.5">{label}</dt>
            <dd className="text-[13px] text-gray-800 min-w-0">{value}</dd>
        </div>
    );
}

function Activity({ icon, tag, body, at }) {
    return (
        <li className="px-4 py-3.5 flex items-start gap-3">
            <div className="w-14 flex-shrink-0 flex flex-col items-center text-gray-400">
                <span>{icon}</span>
                <span className="text-[9px] font-bold uppercase tracking-wider mt-1">{tag}</span>
            </div>
            <p className="flex-1 text-[12.5px] text-gray-700 leading-snug min-w-0">{body}</p>
            <span className="text-[11px] text-gray-500 tabular-nums whitespace-pre-line text-right flex-shrink-0">{at} UTC</span>
        </li>
    );
}

export function NewEngagementModal({ cases, documents: baseDocuments, signers = [], defaultSignerId = null, meId, onClose, initialCase = null, draft = null }) {
    const [engagementType, setEngagementType] = useState(draft?.engagement_type || "immigration_advice");
    const specialService = engagementType !== "immigration_advice";
    const consultation = engagementType === "initial_consultation";
    const [consultationDate, setConsultationDate] = useState(draft?.engagement_settings?.consultation_date || "");
    const [consultationTime, setConsultationTime] = useState(draft?.engagement_settings?.consultation_time || "");
    const [consultationTimezone, setConsultationTimezone] = useState(draft?.engagement_settings?.consultation_timezone || "Pacific/Auckland");
    const [meetingLink, setMeetingLink] = useState(draft?.engagement_settings?.meeting_link || "");
    const documents = useMemo(() => specialService ? [
        { key: engagementType, label: consultation ? "Initial Consultation Agreement" : "OIA Engagement Agreement", dynamic: true },
        { key: "invoice", label: "Invoice", dynamic: true },
    ] : baseDocuments, [engagementType, baseDocuments]);
    // When managing an existing draft, seed every setting from it so the modal
    // reopens exactly as the pack was generated. The invoice isn't an
    // "engagement:" document, so add it back from the draft's has_invoice flag.
    const draftTypes = draft
        ? [
            ...(draft.documents || []).map((d) => d.type_key).filter(Boolean),
            ...(draft.has_invoice && !(draft.documents || []).some((doc) => doc.type_key === "invoice") ? ["invoice"] : []),
        ]
        : [];

    const [caseSearch, setCaseSearch] = useState("");
    const [selectedCase, setSelectedCase] = useState(initialCase);
    const [selectedTypes, setSelectedTypes] = useState(draftTypes.length ? draftTypes : documents.map((d) => d.key));
    const [previewType, setPreviewType] = useState(documents[0]?.key ?? null);
    const [previewLoading, setPreviewLoading] = useState(false);
    // Keep the preview scrolled where the user left it across the silent reloads
    // that follow a per-applicant amount/disbursement edit (which only bumps
    // familyRefresh). Reset to top only when the document itself changes.
    const previewIframeRef = useRef(null);
    const previewScrollRef = useRef(0);
    const handlePreviewLoad = () => {
        setPreviewLoading(false);
        const win = previewIframeRef.current?.contentWindow;
        if (!win) return;
        try {
            win.scrollTo(0, previewScrollRef.current || 0);
            win.onscroll = () => { previewScrollRef.current = win.scrollY; };
        } catch (e) { /* same-origin only; ignore if unavailable */ }
    };
    // Which price the client is engaged at — "normal" (payment plan) or
    // "discounted" (pay now). Drives the professional fee on the agreement.
    const [feeTier, setFeeTier] = useState(draft?.fee_tier ?? "normal");
    // Applicant location — "onshore" (in NZ) or "offshore" (abroad). Picks
    // which of the visa's two fee schedules the agreement quotes.
    const [feeLocation, setFeeLocation] = useState(draft?.fee_location ?? "onshore");
    // Fees are stored excluding GST; this decides whether the agreement
    // quotes that figure or the GST-inclusive RRP.
    const [includeGst, setIncludeGst] = useState(!! draft?.include_gst);
    // Manual override for the (ex-GST) professional fee. "" = use the visa's fee.
    const [feeOverride, setFeeOverride] = useState(draft?.engagement_settings?.professional_fee ?? "");
    // Default the signing adviser to the draft's, else the practice's designated
    // LIA (Hendry), else the current user if eligible, else the first.
    const [signerId, setSignerId] = useState(() => {
        if (draft?.signer_id && signers.some((s) => s.id === draft.signer_id)) return draft.signer_id;
        if (defaultSignerId && signers.some((s) => s.id === defaultSignerId)) return defaultSignerId;
        if (meId && signers.some((s) => s.id === meId)) return meId;
        return signers[0]?.id ?? null;
    });
    // Adviser to assist (clause 2.1, row 2). Draft's value, else the practice's
    // designated adviser when someone else is signing; blank otherwise.
    const [assistSignerId, setAssistSignerId] = useState(() => {
        if (draft) return draft.assist_signer_id ?? null;
        return (defaultSignerId && signers.some((s) => s.id === defaultSignerId) && defaultSignerId !== signerId) ? defaultSignerId : null;
    });

    useEffect(() => {
        if (!consultation || !selectedCase?.id || draft) return;
        let cancelled = false;
        fetch(`/admin/leads/${selectedCase.id}/engagement/defaults`, { headers: { Accept: "application/json" } })
            .then((response) => response.ok ? response.json() : {})
            .then((data) => {
                if (cancelled) return;
                setConsultationDate(data.consultation_date || ""); setConsultationTime(data.consultation_time || "");
                setConsultationTimezone(data.consultation_timezone || "Pacific/Auckland"); setMeetingLink(data.meeting_link || "");
            }).catch(() => { if (!cancelled) toast.error("Could not load saved consultation details. Enter them below."); });
        return () => { cancelled = true; };
    }, [consultation, selectedCase?.id, draft]);

    const selectedSigner = signers.find((s) => s.id === signerId) || null;

    const filteredCases = useMemo(() => {
        const q = caseSearch.trim().toLowerCase();
        if (!q) return cases.slice(0, 50);
        return cases.filter((c) =>
            [c.name, c.lead_id, c.email, c.inz_visa_type]
                .some((v) => (v || "").toString().toLowerCase().includes(q))
        ).slice(0, 50);
    }, [cases, caseSearch]);

    const toggleType = (key) => {
        setSelectedTypes((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
        );
    };

    // Keep the preview tab valid: if it's not in the selected set, snap to
    // the first selected doc.
    useEffect(() => {
        if (!selectedTypes.includes(previewType)) {
            setPreviewType(selectedTypes[0] ?? null);
        }
    }, [selectedTypes, previewType]);

    // Reset the loading spinner whenever the previewed doc / case / signer /
    // pricing tier changes — each re-renders the document.
    useEffect(() => {
        if (selectedCase && previewType) setPreviewLoading(true);
        // A genuine document change starts at the top; amount edits (familyRefresh)
        // deliberately keep the saved position.
        previewScrollRef.current = 0;
    }, [selectedCase, previewType, signerId, feeTier, feeLocation, includeGst]);

    // Which fee columns the chosen location reads from. Mirrors
    // VisaType::FEE_FIELDS on the server.
    const feeFields = feeLocation === "offshore"
        ? { normal: "professional_fees_offshore", discounted: "professional_fees_discounted_offshore", inz: "inz_application_fee_offshore" }
        : { normal: "professional_fees", discounted: "professional_fees_discounted", inz: "inz_application_fee" };

    // Offered whenever the visa has a discounted fee explicitly set for this
    // location — even if it equals the normal price. Snaps back otherwise.
    const hasDiscounted = selectedCase?.[feeFields.discounted] != null;

    // The ex-GST fee for the selected tier + location — what GST uplifts.
    const quotedFee = !selectedCase
        ? null
        : (feeTier === "discounted" && selectedCase[feeFields.discounted] != null
            ? selectedCase[feeFields.discounted]
            : selectedCase[feeFields.normal]);
    useEffect(() => {
        if (!hasDiscounted && feeTier === "discounted") setFeeTier("normal");
    }, [hasDiscounted, feeTier]);

    // Family-aware fee totals from the server (professional sum + INZ sum across
    // all applicants), fetched live so the receipt matches the generated pack
    // for cases with dependants. Falls back to the principal's visa while loading.
    // The case's family + their in-agreement flags, for the include checkboxes.
    // Bumping familyRefresh re-fetches the family AND the fee totals, and forces
    // the preview iframe to re-render, so toggling a member updates everything.
    const [family, setFamily] = useState([]);
    const [principal, setPrincipal] = useState(null);
    const [familyRefresh, setFamilyRefresh] = useState(0);
    useEffect(() => {
        if (!selectedCase) { setFamily([]); setPrincipal(null); return; }
        let cancelled = false;
        const url = `/admin/leads/${selectedCase.id}/engagement/family?fee_tier=${feeTier}&fee_location=${feeLocation}`;
        fetch(url, { headers: { Accept: "application/json" } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => { if (!cancelled) { setFamily(d?.family || []); setPrincipal(d?.principal || null); } })
            .catch(() => { if (!cancelled) { setFamily([]); setPrincipal(null); } });
        return () => { cancelled = true; };
    }, [selectedCase, feeTier, feeLocation, familyRefresh]);

    const xsrfToken = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || "");

    const toggleFamilyMember = (dep, val) => {
        if (!selectedCase) return;
        setFamily((prev) => prev.map((m) => (m.id === dep.id ? { ...m, in_agreement: val } : m)));
        fetch(`/admin/leads/${selectedCase.id}/engagement/family/${dep.id}`, {
            method: "PATCH",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
            body: JSON.stringify({ in_agreement: val }),
        })
            .then((r) => { if (!r.ok) throw new Error(); })
            .then(() => setFamilyRefresh((v) => v + 1))
            .catch(() => { toast.error("Could not update"); setFamilyRefresh((v) => v + 1); });
    };

    // Persist one applicant's fee override (principal or dependant). value "" clears it.
    const setApplicantFee = (type, dependentId, value, column = "fee") => {
        if (!selectedCase) return;
        fetch(`/admin/leads/${selectedCase.id}/engagement/applicant-fee`, {
            method: "PATCH",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
            body: JSON.stringify({ type, dependent_id: dependentId ?? null, column, fee: value === "" ? null : Number(value) }),
        })
            .then((r) => { if (!r.ok) throw new Error(); })
            .then(() => { toast.success(column === "disbursement" ? "Disbursement updated" : "Amount updated"); setFamilyRefresh((v) => v + 1); })
            .catch(() => { toast.error("Could not update the amount"); setFamilyRefresh((v) => v + 1); });
    };

    const [familyTotals, setFamilyTotals] = useState(null);
    useEffect(() => {
        if (!selectedCase) { setFamilyTotals(null); return; }
        let cancelled = false;
        const url = `/admin/leads/${selectedCase.id}/engagement/fee-totals?fee_tier=${feeTier}&fee_location=${feeLocation}&include_gst=${includeGst ? 1 : 0}`;
        fetch(url, { headers: { Accept: "application/json" } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => { if (!cancelled) setFamilyTotals(d); })
            .catch(() => { if (!cancelled) setFamilyTotals(null); });
        return () => { cancelled = true; };
    }, [selectedCase, feeTier, feeLocation, includeGst, familyRefresh]);

    // The ex-GST professional fee: the manual override if set, else the family
    // sum (or the principal's visa fee before the totals arrive).
    const familyProfExcl = familyTotals?.professional_excl ?? quotedFee;
    const effectiveFee = feeOverride !== "" ? Number(feeOverride) : familyProfExcl;
    // INZ disbursement total across all applicants (a government charge, no GST).
    const inzFee = familyTotals?.inz_total ?? (selectedCase ? selectedCase[feeFields.inz] : null);

    // The fee a location would quote at the current tier + GST setting — shown
    // in the location dropdown so onshore vs offshore prices are both visible
    // before switching.
    const feeForLocation = (loc) => {
        if (!selectedCase) return null;
        const f = loc === "offshore"
            ? { normal: "professional_fees_offshore", discounted: "professional_fees_discounted_offshore" }
            : { normal: "professional_fees", discounted: "professional_fees_discounted" };
        const base = feeTier === "discounted" && selectedCase[f.discounted] != null
            ? selectedCase[f.discounted]
            : selectedCase[f.normal];
        if (base == null) return null;
        return includeGst ? base * (1 + GST_RATE) : base;
    };

    const writtenSelected = selectedTypes.includes("written_agreement");
    const missingFees = selectedCase && writtenSelected &&
        (!selectedCase[feeFields.normal] || !selectedCase[feeFields.inz]);
    const missingSignature = selectedTypes.some((key) => ["written_agreement", "initial_consultation", "oia"].includes(key)) && selectedSigner && !selectedSigner.has_signature;

    const settings = {
        engagement_type: engagementType, types: selectedTypes, signer_id: signerId,
        assist_signer_id: assistSignerId, fee_tier: feeTier, fee_location: feeLocation,
        include_gst: includeGst, professional_fee: feeOverride !== "" ? Number(feeOverride) : null,
        ...(consultation ? { consultation_date: consultationDate, consultation_time: consultationTime,
            consultation_timezone: consultationTimezone, meeting_link: meetingLink } : {}),
        ...(draft?.pack_id && draft.pack_id !== "legacy" ? { pack_id: draft.pack_id } : {}),
        revision: familyRefresh,
    };
    const flow = useEngagementWorkflow(selectedCase?.id, settings, draft, onClose);
    const submitting = !!flow.busy;
    const previewParameters = new URLSearchParams({
        engagement_type: engagementType, fee_tier: feeTier, fee_location: feeLocation,
        include_gst: includeGst ? "1" : "0", signer: signerId || "", assist_signer: assistSignerId || "",
        ...(feeOverride !== "" ? { professional_fee: feeOverride } : {}),
        ...(consultation ? { consultation_date: consultationDate, consultation_time: consultationTime,
            consultation_timezone: consultationTimezone, meeting_link: meetingLink } : {}), _r: familyRefresh,
    });
    const preparedPreview = flow.prepared?.documents.find((doc) => doc.type === previewType)?.preview_url;
    const savedPreview = !flow.prepared && flow.savedPackId ? draft?.documents?.find((doc) => doc.type_key === previewType)?.view_url : null;
    const previewUrl = preparedPreview || savedPreview || (selectedCase && previewType
        ? `/admin/leads/${selectedCase.id}/generate/engage_${previewType}/preview?${previewParameters}` : null);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" onClick={onClose}>
            <div
                className="engagement-builder bg-white rounded-2xl shadow-xl w-full max-w-[1500px] h-[94vh] flex flex-col overflow-hidden"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                    <div className="min-w-0">
                        <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <FileSignature size={16} className="text-gray-700" /> {draft ? `Manage draft — ${draft.case_name || selectedCase?.name || "case"}` : "New engagement documents"}
                        </h3>
                        {draft && <p className="text-[11px] text-gray-400 mt-0.5">{draft.sent_at ? "Already emailed — regenerating replaces the current draft." : "Not yet sent to the client."}</p>}
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X size={18} /></button>
                </div>

                <div className="engagement-builder-body flex-1 flex min-h-0">
                    {/* Left: controls. Case + settings are fixed at the top;
                        only the document list scrolls, so picking documents
                        never squeezes the settings and vice versa. */}
                    <div className="engagement-settings min-w-0 border-r border-gray-100 flex flex-col min-h-0 overflow-y-auto">
                        <fieldset disabled={submitting} className="min-w-0 w-full border-0 p-0 m-0">
                        {/* Case picker */}
                        <div className="px-4 pt-3 pb-3 border-b border-gray-100">
                            <FieldLabel>Case</FieldLabel>
                            {selectedCase ? (
                                <div className="mt-1.5 flex items-center gap-2 bg-gray-900 rounded-lg px-3 py-2">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs font-semibold text-white truncate">{selectedCase.name}</p>
                                        <p className="text-[10.5px] text-gray-400 truncate">{selectedCase.inz_visa_type || "No visa set"}</p>
                                    </div>
                                    <button onClick={() => setSelectedCase(null)} className="text-gray-400 hover:text-white"><X size={14} /></button>
                                </div>
                            ) : (
                                <>
                                    <div className="relative mt-1.5">
                                        <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                                        <input
                                            autoFocus
                                            value={caseSearch}
                                            onChange={(e) => setCaseSearch(e.target.value)}
                                            placeholder="Search a case…"
                                            className={`${ctrlCls} !pl-8`}
                                        />
                                    </div>
                                    <div className="mt-1.5 max-h-44 overflow-y-auto rounded-lg border border-gray-100 divide-y divide-gray-50">
                                        {filteredCases.length === 0 && (
                                            <div className="px-3 py-4 text-center text-xs text-gray-400">No cases found.</div>
                                        )}
                                        {filteredCases.map((c) => (
                                            <button
                                                key={c.id}
                                                onClick={() => setSelectedCase(c)}
                                                className="w-full text-left px-3 py-1.5 hover:bg-gray-50 transition-colors"
                                            >
                                                <p className="text-xs font-semibold text-gray-900 truncate">{c.name}</p>
                                                <p className="text-[10.5px] text-gray-400 truncate">{c.lead_id} · {c.inz_visa_type || "No visa"}</p>
                                            </button>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Settings — adviser, then the two fee controls side
                            by side. Warnings only render when they apply, so
                            the block stays short in the normal case. */}
                        <div className="px-4 pt-3 pb-3 border-b border-gray-100 space-y-2.5">
                            <div>
                                <label htmlFor="engagement-type" className="text-[11px] font-semibold text-gray-600">Engagement Type</label>
                                <select id="engagement-type" value={engagementType} disabled={!!draft}
                                    onChange={(event) => {
                                        const type = event.target.value;
                                        setEngagementType(type);
                                        const keys = type === "immigration_advice" ? baseDocuments.map((doc) => doc.key) : [type, "invoice"];
                                        setSelectedTypes(keys); setPreviewType(keys[0]);
                                    }} className={`${ctrlCls} mt-1.5`}>
                                    <option value="immigration_advice">Immigration Advice Services – Written Agreement &amp; Invoice</option>
                                    <option value="initial_consultation">Initial Consultation Agreement &amp; Invoice</option>
                                    <option value="oia">Official Information Act (OIA) Engagement &amp; Invoice</option>
                                </select>
                            </div>
                            {consultation && <div className="space-y-2.5">
                                <div className="grid grid-cols-2 gap-2">
                                    <label className="text-xs font-medium text-gray-700">Consultation date
                                        <input type="date" value={consultationDate} onChange={(e) => setConsultationDate(e.target.value)} className={`${ctrlCls} mt-1.5`} />
                                    </label>
                                    <label className="text-xs font-medium text-gray-700">Consultation time
                                        <input type="time" value={consultationTime} onChange={(e) => setConsultationTime(e.target.value)} className={`${ctrlCls} mt-1.5`} />
                                    </label>
                                </div>
                                <label className="block text-xs font-medium text-gray-700">Time zone
                                    <input value={consultationTimezone} onChange={(e) => setConsultationTimezone(e.target.value)} placeholder="Pacific/Auckland" className={`${ctrlCls} mt-1.5`} />
                                </label>
                                <label className="block text-xs font-medium text-gray-700">Google Meet link
                                    <input type="url" value={meetingLink} onChange={(e) => setMeetingLink(e.target.value)} placeholder="https://meet.google.com/…" className={`${ctrlCls} mt-1.5`} />
                                </label>
                            </div>}

                            <div className="grid grid-cols-2 gap-2 items-start">
                                <div>
                                    <FieldLabel>Signing adviser</FieldLabel>
                                    <select
                                        value={signerId ?? ""}
                                        onChange={(e) => setSignerId(e.target.value ? Number(e.target.value) : null)}
                                        className={`${ctrlCls} mt-1.5`}
                                    >
                                        {signers.length === 0 && <option value="">No licensed advisers</option>}
                                        {signers.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}{s.licence ? ` · ${s.licence}` : ""}{s.licence_current === false ? " — licence expired" : ""}{s.has_signature ? "" : " (no signature)"}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <FieldLabel>Adviser to assist <span className="font-normal">(optional)</span></FieldLabel>
                                    <select
                                        value={assistSignerId ?? ""}
                                        onChange={(e) => setAssistSignerId(e.target.value ? Number(e.target.value) : null)}
                                        className={`${ctrlCls} mt-1.5`}
                                    >
                                        <option value="">— None —</option>
                                        {signers.filter((s) => s.id !== signerId).map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}{s.licence ? ` · ${s.licence}` : ""}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            <p className="text-[10.5px] text-gray-400">The signing adviser is the Main adviser; the other is listed as Adviser to assist (clause 2.1).</p>

                            {selectedSigner && selectedSigner.licence_current === false && (
                                <Note tone="amber">
                                    <span className="font-semibold">{selectedSigner.name}'s IAA licence has expired</span>
                                    {selectedSigner.licence_expiry ? ` (${selectedSigner.licence_expiry})` : ""}. A pack can't be
                                    generated under a lapsed licence — choose a current adviser, or ask an admin to update the licence.
                                </Note>
                            )}

                            {!specialService && <>
                            <div className="min-w-0">
                                <FieldLabel>Applicant location</FieldLabel>
                                <select
                                    value={feeLocation}
                                    onChange={(e) => setFeeLocation(e.target.value)}
                                    className={`${ctrlCls} mt-1.5`}
                                >
                                    <option value="onshore">
                                        Onshore{feeForLocation("onshore") != null ? ` · $${fmtFee(feeForLocation("onshore"))}` : ""}
                                    </option>
                                    <option value="offshore">
                                        Offshore{feeForLocation("offshore") != null ? ` · $${fmtFee(feeForLocation("offshore"))}` : ""}
                                    </option>
                                </select>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <div className="min-w-0">
                                    <FieldLabel>Payment basis</FieldLabel>
                                    <select
                                        value={feeTier}
                                        onChange={(e) => setFeeTier(e.target.value)}
                                        className={`${ctrlCls} mt-1.5`}
                                    >
                                        <option value="normal">Normal (payment plan)</option>
                                        <option value="discounted" disabled={!hasDiscounted}>
                                            Discounted (pay now){hasDiscounted ? "" : " — none set"}
                                        </option>
                                    </select>
                                </div>
                                <div className="min-w-0">
                                    <FieldLabel>GST</FieldLabel>
                                    <select
                                        value={includeGst ? "incl" : "excl"}
                                        onChange={(e) => setIncludeGst(e.target.value === "incl")}
                                        className={`${ctrlCls} mt-1.5`}
                                    >
                                        <option value="excl">Excluding GST</option>
                                        <option value="incl">Including GST ({GST_PCT}%)</option>
                                    </select>
                                </div>
                            </div>

                            {/* Family — who is billed on this agreement. Only
                                shown when the case has costed dependants; untick a
                                member to leave them off the agreement + invoice. */}
                            {selectedCase && (
                                <div className="rounded-lg border border-gray-200 bg-gray-50/60 p-2.5">
                                    <div className="flex items-center gap-2 mb-1.5 -mx-2.5 -mt-2.5 px-2.5 py-2 rounded-t-lg bg-gray-700">
                                        <p className="text-[10px] font-bold uppercase tracking-wider text-white flex-1">Applicant/s</p>
                                        <p className="text-[10px] text-gray-300 w-[92px] text-right">Amount (ex GST)</p>
                                        <p className="text-[10px] text-gray-300 w-[92px] text-right">Disbursement</p>
                                    </div>
                                    {/* Principal — always included; amount + disbursement editable. */}
                                    <div className="flex items-center gap-2 py-1">
                                        <input type="checkbox" checked disabled className="rounded border-gray-300 w-3.5 h-3.5 flex-shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <span className="text-[12px] font-semibold text-gray-700">{principal?.name || selectedCase.name}</span>
                                            <span className="text-[12px] text-gray-400"> · main applicant</span>
                                        </div>
                                        <ApplicantFeeInput
                                            value={principal?.fee_override}
                                            placeholder={principal?.default_fee}
                                            onCommit={(v) => setApplicantFee("principal", null, v, "fee")}
                                        />
                                        <ApplicantFeeInput
                                            value={principal?.disbursement_override}
                                            placeholder={principal?.default_disbursement}
                                            onCommit={(v) => setApplicantFee("principal", null, v, "disbursement")}
                                        />
                                    </div>
                                    {family.map((m) => (
                                        <div key={m.id} className="flex items-center gap-2 py-1">
                                            <input
                                                type="checkbox"
                                                checked={m.in_agreement !== false}
                                                onChange={(e) => toggleFamilyMember(m, e.target.checked)}
                                                className="rounded border-gray-300 text-[#009688] focus:ring-0 w-3.5 h-3.5 flex-shrink-0"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <span className="text-[12px] font-semibold text-gray-800">{m.name}</span>
                                                <span className="text-[12px] text-gray-400 capitalize"> · {m.relationship}{m.visa ? ` · ${m.visa}` : ""}</span>
                                            </div>
                                            <ApplicantFeeInput
                                                value={m.fee_override}
                                                placeholder={m.default_fee}
                                                disabled={m.in_agreement === false}
                                                onCommit={(v) => setApplicantFee("dependent", m.id, v, "fee")}
                                            />
                                            <ApplicantFeeInput
                                                value={m.disbursement_override}
                                                placeholder={m.default_disbursement}
                                                disabled={m.in_agreement === false}
                                                onCommit={(v) => setApplicantFee("dependent", m.id, v, "disbursement")}
                                            />
                                        </div>
                                    ))}
                                    <p className="text-[10px] text-gray-400 mt-1.5">Edit an amount to set that applicant's fee or INZ disbursement. Leave blank to use the visa fee.</p>
                                </div>
                            )}

                            {/* Receipt-style fee summary — our professional fee
                                (editable, summed across the family), GST, the INZ
                                disbursements, and the grand total. Matches the
                                generated agreement's fee tables and the stored
                                "Total amount". */}
                            {writtenSelected && (() => {
                                const prof = effectiveFee != null && effectiveFee !== "" ? Number(effectiveFee) : 0;
                                const inz = inzFee != null ? Number(inzFee) : 0;
                                const gstAmt = prof * GST_RATE;
                                const total = (includeGst ? prof + gstAmt : prof) + inz;
                                return (
                                    <div className="rounded-lg overflow-hidden text-white" style={{ backgroundColor: BRAND_TEAL }}>
                                        <div className="px-3 py-1.5 bg-black/10 text-[10px] font-bold uppercase tracking-wider text-white/80">Fee summary</div>
                                        <div className="px-3 py-2 space-y-1.5">
                                            {/* Our professional fee — the sum of each applicant's
                                                amount (edit per applicant above). */}
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-[11.5px] text-white/85">Our fees <span className="text-white/50">(ex GST)</span></span>
                                                <span className="text-[12.5px] font-bold tabular-nums">${fmtFee(prof)}</span>
                                            </div>
                                            {includeGst && (
                                                <div className="flex items-center justify-between gap-2 text-white/75">
                                                    <span className="text-[11.5px]">GST ({GST_PCT}%)</span>
                                                    <span className="text-[12.5px] font-semibold tabular-nums">${fmtFee(gstAmt)}</span>
                                                </div>
                                            )}
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-[11.5px] text-white/85">Disbursements <span className="text-white/50">(INZ)</span></span>
                                                <span className="text-[12.5px] font-semibold tabular-nums">{inzFee != null ? `$${fmtFee(inz)}` : "—"}</span>
                                            </div>
                                            <div className="flex items-center justify-between gap-2 border-t border-white/25 pt-1.5 mt-0.5">
                                                <span className="text-[11px] font-bold uppercase tracking-wide">Total amount</span>
                                                <span className="text-[15px] font-bold tabular-nums">${fmtFee(total)}</span>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })()}

                            </>}
                            {specialService && <div className="rounded-lg bg-teal-50 p-3 text-sm text-teal-900">
                                <p className="font-semibold">{consultation ? "USD 250.00 booking fee" : "NZD 500.00 + 75.00 GST = NZD 575.00"}</p>
                                <p className="mt-1 text-xs">{consultation ? "Non-refundable; credited toward a subsequent visa processing engagement." : "Payable in advance. No visa application disbursement is included."}</p>
                                <p className="mt-1 text-xs">Client and adviser details are filled from their portal records.</p>
                            </div>}
                            {signers.length === 0 && (
                                <Note>
                                    Only licensed advisers can sign. Add an <span className="font-semibold">IAA licence number</span> on My Profile to appear here.
                                </Note>
                            )}
                            {missingSignature && (
                                <Note tone="amber">
                                    This adviser has no signature yet — the agreement will show a blank signature line.
                                </Note>
                            )}
                            {missingFees && (
                                <Note tone="amber">
                                    This visa has no fees set on the <span className="font-semibold">Visas</span> page — the Written Agreement will show placeholders.
                                </Note>
                            )}
                        </div>

                        </fieldset>
                    </div>

                    {/* Right: live preview */}
                    <div className="flex-1 flex flex-col min-w-0 min-h-[240px] lg:min-h-0 bg-gray-50">
                        {/* Document tabs double as the include checkboxes: tick to
                            add to the pack, click the name to preview it. */}
                        <div className="px-4 py-2.5 border-b border-gray-100 flex items-center gap-1.5 overflow-x-auto flex-shrink-0 bg-white">
                            <button
                                type="button"
                                disabled={specialService || submitting} onClick={() => setSelectedTypes(selectedTypes.length === documents.length ? [] : documents.map((d) => d.key))}
                                className="text-[10.5px] font-semibold text-gray-400 hover:text-gray-900 flex-shrink-0 mr-0.5"
                            >
                                {selectedTypes.length === documents.length ? "Clear all" : "Select all"}
                            </button>
                            {documents.map((d) => {
                                const checked = selectedTypes.includes(d.key);
                                const isPreview = previewType === d.key;
                                return (
                                    <div
                                        key={d.key}
                                        className={`flex items-center gap-1.5 pl-1.5 pr-2.5 py-1 rounded-full whitespace-nowrap border flex-shrink-0 transition-colors ${isPreview ? "bg-gray-900 border-gray-900 text-white" : checked ? "bg-gray-100 border-gray-200 text-gray-700" : "bg-white border-gray-200 text-gray-400"}`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => { if (!specialService) toggleType(d.key); }} disabled={specialService || submitting}
                                            className={`w-3.5 h-3.5 rounded border flex items-center justify-center flex-shrink-0 ${checked ? (isPreview ? "bg-white border-white" : "bg-gray-900 border-gray-900") : "bg-white border-gray-300"}`}
                                        >
                                            {checked && <Check size={10} className={isPreview ? "text-gray-900" : "text-white"} strokeWidth={3} />}
                                        </button>
                                        <button type="button" onClick={() => setPreviewType(d.key)} className="text-[11px] font-semibold flex items-center gap-1">
                                            {d.label}
                                            {d.dynamic && <span className={`text-[8px] font-bold uppercase tracking-wide rounded px-1 ${isPreview ? "bg-white/20 text-white" : "bg-gray-200 text-gray-600"}`}>Auto</span>}
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                        <div className="flex-1 relative min-h-0">
                            {!selectedCase ? (
                                <div className="absolute inset-0 flex flex-col items-center justify-center text-center px-6">
                                    <FileText size={30} className="text-gray-300" />
                                    <p className="text-sm font-semibold text-gray-500 mt-2">Pick a case to preview</p>
                                    <p className="text-xs text-gray-400 mt-1">The live document preview will appear here.</p>
                                </div>
                            ) : previewUrl ? (
                                <>
                                    {previewLoading && !preparedPreview && !savedPreview && (
                                        <div className="absolute inset-0 flex items-center justify-center bg-gray-50/80 z-10">
                                            <Loader2 size={22} className="text-gray-700 animate-spin" />
                                        </div>
                                    )}
                                    {(preparedPreview || savedPreview) ? <PdfDocumentPreview url={previewUrl} className="absolute inset-0 h-full w-full" /> : <iframe
                                        ref={previewIframeRef}
                                        src={previewUrl}
                                        title="Document preview"
                                        className="absolute inset-0 w-full h-full bg-white"
                                        onLoad={handlePreviewLoad}
                                    />}
                                </>
                            ) : (
                                <div className="absolute inset-0 flex items-center justify-center text-xs text-gray-400">
                                    Select a document to preview.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Generate, save and reviewed sending are distinct operations. */}
                <div className="border-t border-gray-100 px-5 py-3 flex flex-wrap items-center justify-between gap-3 flex-shrink-0">
                    <div className="min-w-0 text-xs text-gray-600">
                        <p>{flow.savedPackId ? "Draft saved. Ready to review the email." : flow.prepared ? "Generated for preview. Save as Draft to keep these documents." : "Generate the documents, then save a draft before reviewing the email."}</p>
                        <p className="mt-1">Generate and Save as Draft do not send email.</p>
                        {flow.error && !flow.review && <p role="alert" className="mt-2 max-w-xl text-red-700">{flow.error}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => { if (!submitting) onClose(); }} disabled={submitting} className="px-3 py-2 text-sm font-semibold text-gray-600 disabled:opacity-50">Close</button>
                        <button type="button" onClick={flow.generate}
                            disabled={!selectedCase || !selectedTypes.length || !signerId || submitting || selectedSigner?.licence_current === false || !!draft?.signed}
                            className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                            {flow.busy === "prepare" ? "Generating…" : "Generate"}
                        </button>
                        <button type="button" onClick={flow.save} disabled={!flow.prepared || submitting || !!flow.savedPackId}
                            className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                            {flow.busy === "save" ? "Saving…" : "Save as Draft"}
                        </button>
                        <button type="button" onClick={flow.verify} disabled={!flow.savedPackId || submitting || !selectedCase?.email}
                            className="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black disabled:opacity-50">
                            {flow.busy === "review" ? "Preparing review…" : "Verify & Email"}
                        </button>
                    </div>
                </div>
            </div>
            <GenerationProgress active={submitting && !flow.review} title={flow.busy === "prepare" ? "Generating documents without emailing…" : flow.busy === "save" ? "Saving your draft…" : "Preparing email review…"} />
            {flow.review && <EngagementEmailReview review={flow.review} busy={flow.busy === "send-reviewed"} error={flow.error} onClose={flow.closeReview} onSend={flow.send} />}
        </div>
    );
}
