import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import {
    X as XIcon, FileText, Download, Eye, MessageSquare, MoreVertical,
    Send, Upload, Check, Loader2, Trash2,
} from "lucide-react";
import { ThreadItem, ThreadComposer } from "@/components/immigration/case-profile/threads";

// Shared document viewer + per-file action menu — the immigration Case
// Documents experience, reused on the general lead Documents tab. Parametrized
// on `basePath` + `anchor` so comments post to the right endpoint (general
// leads anchor by checklist key; immigration by document id).

// Destructive actions are opt-in for the lead-profile document workflow.
export function LeadDocFileMenu({ doc, leadId, checklistKey = null, only = ["documents"], basePath = "/admin/leads", canDelete = false, canReject = false }) {
    const [open, setOpen] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [sharing, setSharing] = useState(false);
    const [acting, setActing] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0 });
    const btnRef = useRef(null);
    const menuRef = useRef(null);
    const fileRef = useRef(null);
    const MENU_W = 168;

    useLayoutEffect(() => {
        if (! open) return;
        const place = () => {
            const r = btnRef.current?.getBoundingClientRect();
            if (!r) return;
            const height = menuRef.current?.getBoundingClientRect().height || 0;
            const below = r.bottom + 4;
            const top = below + height <= window.innerHeight - 8 ? below : r.top - height - 4;
            setCoords({
                top: Math.max(8, Math.min(top, window.innerHeight - height - 8)),
                left: Math.max(8, Math.min(r.right - MENU_W, window.innerWidth - MENU_W - 8)),
            });
        };
        place();
        window.addEventListener("scroll", place, true);
        window.addEventListener("resize", place);
        return () => { window.removeEventListener("scroll", place, true); window.removeEventListener("resize", place); };
    }, [open]);

    const close = () => setOpen(false);

    const removeFile = () => {
        if (!window.confirm(`Delete ${doc.original_name}? This removes the uploaded file.`)) return;
        setActing(true);
        router.delete(`${basePath}/${leadId}/documents/${doc.id}`, {
            preserveScroll: true, preserveState: true, only,
            onSuccess: () => toast.success("File removed"),
            onError: (errors) => toast.error(Object.values(errors)[0] || "Could not remove file"),
            onFinish: () => { setActing(false); close(); },
        });
    };

    const rejectFile = () => {
        const note = window.prompt("Why does this document need to be replaced?");
        if (note === null) return;
        setActing(true);
        router.post(`${basePath}/${leadId}/documents/${doc.id}/status`, { status: "Rejected", note }, {
            preserveScroll: true, preserveState: true, only,
            onSuccess: () => toast.success("Document rejected"),
            onError: (errors) => toast.error(Object.values(errors)[0] || "Could not reject document"),
            onFinish: () => { setActing(false); close(); },
        });
    };

    const sendToClient = () => {
        setSharing(true);
        router.post(`/admin/leads/${leadId}/documents/${doc.id}/send-to-client`, {}, {
            preserveScroll: true,
            preserveState: true,
            only,
            onSuccess: () => toast.success("Sent to client"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not send"),
            onFinish: () => { setSharing(false); close(); },
        });
    };

    const onReplace = (e) => {
        const files = Array.from(e.target.files || []);
        if (files.length === 0) return;
        setUploading(true);
        router.post(
            `/admin/leads/${leadId}/documents/checklist/${encodeURIComponent(checklistKey)}/upload`,
            { files },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                only,
                onSuccess: () => toast.success("File uploaded"),
                onError: (errs) => toast.error(Object.values(errs)[0] || "Upload failed"),
                onFinish: () => {
                    setUploading(false);
                    if (fileRef.current) fileRef.current.value = "";
                    close();
                },
            },
        );
    };

    return (
        <>
            <button
                ref={btnRef}
                type="button"
                onClick={() => setOpen((o) => !o)}
                title="More"
                className="inline-flex items-center justify-center w-6 h-6 rounded-md text-gray-400 hover:text-gray-900 hover:bg-gray-100"
            >
                <MoreVertical size={12} />
            </button>
            {checklistKey && (
                <input
                    ref={fileRef}
                    type="file"
                    multiple
                    onChange={onReplace}
                    className="hidden"
                    accept="application/pdf,image/*,.doc,.docx,.xls,.xlsx"
                />
            )}
            {open && createPortal(
                <>
                    <div className="fixed inset-0 z-[59]" onClick={close} />
                    <div
                        ref={menuRef}
                        style={{ position: "fixed", top: coords.top, left: coords.left, width: MENU_W, maxHeight: "calc(100vh - 16px)", overflowY: "auto" }}
                        className="z-[60] bg-white rounded-lg shadow-xl border border-gray-100 py-1"
                    >
                        <a
                            href={`/admin/documents/${doc.id}/download`}
                            onClick={close}
                            className="flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50"
                        >
                            <Download size={13} className="text-gray-400" /> Download
                        </a>
                        {doc?.status === "StaffShared" ? (
                            <div className="flex items-center gap-2 px-3 py-1.5 text-[12px] text-teal-700"><Check size={13} /> Shared with client</div>
                        ) : (
                            <button
                                type="button"
                                onClick={sendToClient}
                                disabled={sharing}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            >
                                {sharing ? <Loader2 size={13} className="animate-spin text-gray-400" /> : <Send size={13} className="text-gray-400" />}
                                {sharing ? "Sending…" : "Send to client"}
                            </button>
                        )}
                        {checklistKey && (
                            <button
                                type="button"
                                onClick={() => fileRef.current?.click()}
                                disabled={uploading}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            >
                                {uploading ? <Loader2 size={13} className="animate-spin text-gray-400" /> : <Upload size={13} className="text-gray-400" />}
                                {uploading ? "Uploading…" : "Replace"}
                            </button>
                        )}
                        {canReject && doc?.status !== "StaffShared" && (
                            <button type="button" onClick={rejectFile} disabled={acting}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-red-700 hover:bg-red-50 disabled:opacity-50">
                                <XIcon size={13} /> Reject file
                            </button>
                        )}
                        {canDelete && (
                            <button type="button" onClick={removeFile} disabled={acting}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-red-700 hover:bg-red-50 disabled:opacity-50">
                                <Trash2 size={13} /> Delete file
                            </button>
                        )}
                    </div>
                </>,
                document.body,
            )}
        </>
    );
}

// ── The preview + comments modal ────────────────────────────────────────────
export function LeadDocViewerModal({ doc, label = "Document", leadId, staffOptions = [], threads = [], childrenOf = null, anchor, basePath = "/admin/leads", onClose }) {
    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        const prev = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        window.addEventListener("keydown", onKey);
        return () => { document.body.style.overflow = prev; window.removeEventListener("keydown", onKey); };
    }, [onClose]);

    if (! doc) return null;

    const inlineUrl = `/admin/documents/${doc.id}/download?inline=1`;
    const isPdf = (doc.mime || "").includes("pdf");
    const isImage = (doc.mime || "").startsWith("image/");
    // Comments anchor: general leads key by checklist item, immigration by doc id.
    const fixedAnchor = anchor || { anchor_type: "document", anchor_id: doc.id };

    return createPortal(
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/40" onClick={onClose}>
            <div className="w-[94vw] max-w-[1150px] h-[88vh] rounded-2xl bg-white shadow-xl flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400 mb-0.5">{label}</p>
                        <h3 className="text-sm font-semibold text-gray-900 truncate">{doc.original_name}</h3>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700 flex-shrink-0"><XIcon size={18} /></button>
                </div>

                <div className="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">
                    {/* Preview */}
                    <div className="lg:flex-1 min-h-0 bg-gray-100 border-b lg:border-b-0 lg:border-r border-gray-100 flex flex-col">
                        {isImage ? (
                            <div className="flex-1 overflow-auto p-4 flex items-start justify-center">
                                <img src={inlineUrl} alt={doc.original_name} className="max-w-full h-auto rounded-lg shadow-sm" />
                            </div>
                        ) : isPdf ? (
                            <iframe src={inlineUrl} title={doc.original_name} className="flex-1 w-full border-0" />
                        ) : (
                            <div className="flex-1 flex flex-col items-center justify-center text-center p-8">
                                <FileText size={40} className="text-gray-300" />
                                <p className="mt-3 text-sm text-gray-600">No inline preview for this file type.</p>
                                <a href={`/admin/documents/${doc.id}/download`} className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-[12px] font-semibold hover:bg-white"><Download size={13} /> Download to view</a>
                            </div>
                        )}
                        <div className="flex items-center gap-1.5 px-4 py-2 border-t border-gray-200 bg-white">
                            <a href={inlineUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-200 text-gray-600 text-[11px] font-semibold hover:bg-gray-50"><Eye size={12} /> Open in tab</a>
                            <a href={`/admin/documents/${doc.id}/download`} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-200 text-gray-600 text-[11px] font-semibold hover:bg-gray-50"><Download size={12} /> Download</a>
                        </div>
                    </div>

                    {/* Comments */}
                    <div className="lg:w-[360px] flex-shrink-0 overflow-y-auto overscroll-contain p-4 bg-gray-50">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-2 inline-flex items-center gap-1.5"><MessageSquare size={12} /> Comments</p>
                        <div className="space-y-1.5 mb-3">
                            {threads.length === 0 && <p className="text-[12px] text-gray-400">No comments yet. Add the first below.</p>}
                            {threads.map((t) => (
                                <ThreadItem
                                    key={t.id}
                                    thread={t}
                                    leadId={leadId}
                                    anchor={fixedAnchor}
                                    childrenOf={childrenOf}
                                    caseStaff={staffOptions}
                                    basePath={basePath}
                                />
                            ))}
                        </div>
                        <ThreadComposer
                            leadId={leadId}
                            caseStaff={staffOptions}
                            fixedAnchor={fixedAnchor}
                            basePath={basePath}
                            compact
                            plain
                            placeholder="Add a comment…"
                        />
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
