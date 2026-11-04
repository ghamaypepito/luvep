import MediaAttachButton from '@/components/media/MediaAttachButton';
import { useState, useMemo } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    ArrowLeft, FileText, Plus, X, Check, Clock, AlertTriangle, Eye,
    Download, Upload, Trash2, Send, Loader,
} from "lucide-react";

const STATUS_CHIP = {
    Submitted:   "bg-blue-100 text-blue-700 border-blue-200",
    UnderReview: "bg-amber-100 text-amber-700 border-amber-200",
    Approved:    "bg-emerald-100 text-emerald-700 border-emerald-200",
    Rejected:    "bg-red-100 text-red-700 border-red-200",
    StaffShared: "bg-gray-100 text-gray-600 border-gray-200",
};

const STATUS_LABEL = {
    Submitted:   "Awaiting review",
    UnderReview: "Under review",
    Approved:    "Approved",
    Rejected:    "Rejected",
    StaffShared: "Shared with lead",
};

const fmtSize = (b) => {
    if (!b) return "—";
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(2)} MB`;
};
const fmtDate = (iso) => iso ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

export default function LeadDocuments({ lead, requests = [], orphans = [], templates = [] }) {
    const { flash } = usePage().props;
    const [showRequestModal, setShowRequestModal] = useState(false);
    const [showShareModal, setShowShareModal] = useState(false);
    const [busyId, setBusyId] = useState(null);

    const submittedCount = useMemo(() =>
        requests.filter(r => r.latest_document?.status === "Submitted").length,
        [requests]
    );

    const reviewDoc = (docId, status, note = null) => {
        setBusyId(docId);
        router.post(`/admin/leads/${lead.id}/documents/${docId}/status`, { status, note }, {
            preserveScroll: true,
            onFinish: () => setBusyId(null),
        });
    };

    const deleteRequest = (requestId) => {
        if (!confirm("Remove this document request? Files already uploaded against it stay attached to the lead.")) return;
        setBusyId(`req-${requestId}`);
        router.delete(`/admin/leads/${lead.id}/documents/requests/${requestId}`, {
            preserveScroll: true,
            onFinish: () => setBusyId(null),
        });
    };

    return (
        <div className="space-y-6 max-w-[1400px] mx-auto pb-12">
            <Head title={`Documents — ${lead.name}`} />
            <div className="flex justify-end"><MediaAttachButton clientId={lead.id} /></div>

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <Link href={`/admin/leads/${lead.id}`} className="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-700 mb-2">
                        <ArrowLeft size={13} /> Back to lead
                    </Link>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2.5">
                        <FileText size={22} className="text-gray-500" />
                        Documents — {lead.name}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1.5">
                        {lead.lead_id} · {lead.email || "no email"} · Portal status: <span className="font-medium text-gray-700">{lead.portal_invitation_status || "none"}</span>
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => setShowShareModal(true)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-200 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-50 transition-colors"
                    >
                        <Send size={13} /> Share with lead
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowRequestModal(true)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-gray-800 transition-colors"
                    >
                        <Plus size={13} /> Request documents
                    </button>
                </div>
            </div>

            {submittedCount > 0 && (
                <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
                    <AlertTriangle size={18} className="text-amber-500 flex-shrink-0 mt-0.5" />
                    <p className="text-sm text-amber-900">
                        <strong>{submittedCount}</strong> document{submittedCount > 1 ? "s" : ""} awaiting your review.
                    </p>
                </div>
            )}

            {/* Requested documents */}
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-bold uppercase tracking-wider text-gray-700">Requested from lead</h2>
                </div>
                {requests.length === 0 ? (
                    <div className="p-16 text-center text-gray-400">
                        <FileText size={28} className="mx-auto mb-3" />
                        <p className="text-sm">No documents requested yet. Click <strong>Request documents</strong> to ask the lead for specific files.</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {requests.map((r) => {
                            const doc = r.latest_document;
                            const status = doc?.status;
                            const isSubmitted = status === "Submitted";
                            return (
                                <li key={r.id} className="p-5 sm:p-6 flex flex-col lg:flex-row gap-5 lg:items-center lg:justify-between">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 mb-1">
                                            <p className="font-semibold text-gray-900 text-sm">{r.label}</p>
                                            {r.required && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-50 text-red-600">REQUIRED</span>}
                                        </div>
                                        {r.description && <p className="text-xs text-gray-500 mb-2">{r.description}</p>}
                                        <p className="text-[11px] text-gray-400">
                                            Requested by {r.requested_by || "—"} · {fmtDate(r.requested_at)}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {doc ? (
                                            <>
                                                <div className="text-right">
                                                    <p className="text-xs font-medium text-gray-700 truncate max-w-[200px]">{doc.original_name}</p>
                                                    <p className="text-[10px] text-gray-400">{fmtSize(doc.size)} · {fmtDate(doc.created_at)}</p>
                                                </div>
                                                <span className={`inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold border ${STATUS_CHIP[status]}`}>
                                                    {STATUS_LABEL[status]}
                                                </span>
                                                <a
                                                    href={`/admin/documents/${doc.id}/download`}
                                                    className="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors"
                                                    title="Download"
                                                >
                                                    <Download size={14} />
                                                </a>
                                                {isSubmitted && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            disabled={busyId === doc.id}
                                                            onClick={() => reviewDoc(doc.id, "Approved")}
                                                            className="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-500 text-white rounded-lg text-xs font-bold hover:bg-emerald-600 transition-colors disabled:opacity-50"
                                                        >
                                                            <Check size={12} /> Approve
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={busyId === doc.id}
                                                            onClick={() => {
                                                                const note = prompt("Reason for rejection (visible to the lead):");
                                                                if (note !== null) reviewDoc(doc.id, "Rejected", note);
                                                            }}
                                                            className="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-red-200 text-red-700 rounded-lg text-xs font-bold hover:bg-red-50 transition-colors disabled:opacity-50"
                                                        >
                                                            <X size={12} /> Reject
                                                        </button>
                                                    </>
                                                )}
                                            </>
                                        ) : (
                                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-bold border bg-gray-50 text-gray-500 border-gray-200">
                                                <Clock size={11} /> Awaiting upload
                                            </span>
                                        )}
                                        <button
                                            type="button"
                                            disabled={busyId === `req-${r.id}`}
                                            onClick={() => deleteRequest(r.id)}
                                            className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50"
                                            title="Remove request"
                                        >
                                            <Trash2 size={13} />
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {/* Other / unsolicited / shared by staff */}
            {orphans.length > 0 && (
                <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="px-6 py-4 border-b border-gray-100">
                        <h2 className="text-sm font-bold uppercase tracking-wider text-gray-700">Other documents</h2>
                        <p className="text-xs text-gray-500 mt-1">Files shared by staff or uploaded by the lead without a request.</p>
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {orphans.map((d) => (
                            <li key={d.id} className="p-5 sm:p-6 flex items-center justify-between gap-4">
                                <div className="flex items-center gap-3 min-w-0 flex-1">
                                    <div className="w-10 h-10 rounded-lg bg-gray-100 text-gray-500 flex items-center justify-center flex-shrink-0">
                                        <FileText size={16} />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-gray-900 truncate">{d.original_name}</p>
                                        <p className="text-[11px] text-gray-400">{fmtSize(d.size)} · {fmtDate(d.created_at)}</p>
                                    </div>
                                </div>
                                <span className={`inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold border ${STATUS_CHIP[d.status]}`}>
                                    {STATUS_LABEL[d.status]}
                                </span>
                                <a
                                    href={`/admin/documents/${d.id}/download`}
                                    className="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors"
                                    title="Download"
                                >
                                    <Download size={14} />
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/* Modals */}
            {showRequestModal && (
                <RequestDocumentsModal
                    leadId={lead.id}
                    templates={templates}
                    onClose={() => setShowRequestModal(false)}
                />
            )}
            {showShareModal && (
                <ShareDocumentModal
                    leadId={lead.id}
                    onClose={() => setShowShareModal(false)}
                />
            )}
        </div>
    );
}

// ── Request Documents Modal ────────────────────────────────────────────────

function RequestDocumentsModal({ leadId, templates, onClose }) {
    const [items, setItems] = useState([{ label: "", description: "", required: true }]);
    const [submitting, setSubmitting] = useState(false);

    const addItem = () => setItems([...items, { label: "", description: "", required: true }]);
    const removeItem = (i) => setItems(items.filter((_, idx) => idx !== i));
    const updateItem = (i, key, val) => setItems(items.map((it, idx) => idx === i ? { ...it, [key]: val } : it));
    const useTemplate = (i, t) => updateItem(i, "label", t.label) || updateItem(i, "description", t.description);

    const submit = (e) => {
        e.preventDefault();
        const validItems = items.filter(it => it.label.trim());
        if (validItems.length === 0) return;
        setSubmitting(true);
        router.post(`/admin/leads/${leadId}/documents/requests`,
            { items: validItems },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setSubmitting(false),
            }
        );
    };

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-0 sm:p-6">
            <div className="bg-white sm:rounded-2xl rounded-t-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <div className="p-6 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white">
                        <div>
                            <h3 className="text-lg font-bold text-gray-900">Request documents</h3>
                            <p className="text-xs text-gray-500 mt-1">Pick from templates or write your own. The lead sees these in their portal.</p>
                        </div>
                        <button type="button" onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center">
                            <X size={16} />
                        </button>
                    </div>

                    <div className="p-6 space-y-5">
                        {items.map((item, i) => (
                            <div key={i} className="bg-gray-50 rounded-xl p-4 space-y-3">
                                <div className="flex items-start gap-3">
                                    <span className="w-7 h-7 rounded-full bg-gray-900 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">{i + 1}</span>
                                    <input
                                        type="text"
                                        value={item.label}
                                        onChange={(e) => updateItem(i, "label", e.target.value)}
                                        placeholder="Document label (e.g. Passport bio page)"
                                        className="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-gray-900"
                                    />
                                    {items.length > 1 && (
                                        <button type="button" onClick={() => removeItem(i)} className="text-gray-400 hover:text-red-600 p-2">
                                            <X size={14} />
                                        </button>
                                    )}
                                </div>
                                <input
                                    type="text"
                                    value={item.description}
                                    onChange={(e) => updateItem(i, "description", e.target.value)}
                                    placeholder="Optional note (instructions for the lead)"
                                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none focus:border-gray-900"
                                />
                                <label className="inline-flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={item.required}
                                        onChange={(e) => updateItem(i, "required", e.target.checked)}
                                        className="rounded"
                                    />
                                    Required
                                </label>

                                {/* Templates */}
                                <div className="pt-2 border-t border-gray-200">
                                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Quick add</p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {templates.map((t, idx) => (
                                            <button
                                                key={idx}
                                                type="button"
                                                onClick={() => { updateItem(i, "label", t.label); updateItem(i, "description", t.description); }}
                                                className="text-[11px] px-2 py-1 rounded-md bg-white border border-gray-200 text-gray-600 hover:border-gray-900 hover:text-gray-900 transition-colors"
                                            >
                                                {t.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ))}

                        <button
                            type="button"
                            onClick={addItem}
                            className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-gray-200 text-gray-500 rounded-xl text-sm font-medium hover:border-gray-400 hover:text-gray-700 transition-colors"
                        >
                            <Plus size={14} /> Add another
                        </button>
                    </div>

                    <div className="p-6 border-t border-gray-100 flex items-center justify-end gap-2 sticky bottom-0 bg-white">
                        <button type="button" onClick={onClose} className="px-5 py-2.5 text-sm font-semibold text-gray-600 hover:text-gray-900">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white rounded-xl text-sm font-bold hover:bg-gray-800 transition-colors disabled:opacity-50"
                        >
                            {submitting ? <Loader size={14} className="animate-spin" /> : <Plus size={14} />}
                            Send requests
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Share Document Modal ───────────────────────────────────────────────────

function ShareDocumentModal({ leadId, onClose }) {
    const [file, setFile] = useState(null);
    const [note, setNote] = useState("");
    const [submitting, setSubmitting] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        if (!file) return;
        setSubmitting(true);
        const fd = new FormData();
        fd.append("file", file);
        if (note) fd.append("note", note);
        router.post(`/admin/leads/${leadId}/documents/share`, fd, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onClose(),
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-0 sm:p-6">
            <div className="bg-white sm:rounded-2xl rounded-t-2xl w-full max-w-lg">
                <form onSubmit={submit}>
                    <div className="p-6 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h3 className="text-lg font-bold text-gray-900">Share file with lead</h3>
                            <p className="text-xs text-gray-500 mt-1">e.g. offer letter, invoice, signed agreement.</p>
                        </div>
                        <button type="button" onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center">
                            <X size={16} />
                        </button>
                    </div>

                    <div className="p-6 space-y-4">
                        <label className="block">
                            <span className="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2 block">File (max 20MB)</span>
                            <input
                                type="file"
                                onChange={(e) => setFile(e.target.files[0] || null)}
                                className="block w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-gray-900 file:text-white hover:file:bg-gray-800"
                            />
                        </label>
                        <label className="block">
                            <span className="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2 block">Note (optional)</span>
                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                rows={3}
                                placeholder="Context for the lead — what this file is for…"
                                className="block w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-gray-900"
                            />
                        </label>
                    </div>

                    <div className="p-6 border-t border-gray-100 flex items-center justify-end gap-2">
                        <button type="button" onClick={onClose} className="px-5 py-2.5 text-sm font-semibold text-gray-600 hover:text-gray-900">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={!file || submitting}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white rounded-xl text-sm font-bold hover:bg-gray-800 transition-colors disabled:opacity-50"
                        >
                            {submitting ? <Loader size={14} className="animate-spin" /> : <Upload size={14} />}
                            Share
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
