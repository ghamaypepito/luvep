import { useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import {
    FileText, Upload, Download, Loader, Eye, Inbox, Calendar, AlertTriangle,
} from "lucide-react";

// Client-facing review status → label + dot colour (read-only; the adviser sets it).
const STATUS = {
    Submitted:   { label: "Awaiting review", text: "text-blue-600",    dot: "bg-blue-500" },
    UnderReview: { label: "Under review",    text: "text-amber-600",   dot: "bg-amber-500" },
    Approved:    { label: "Approved",        text: "text-emerald-600", dot: "bg-emerald-500" },
    Rejected:    { label: "Needs new file",  text: "text-rose-600",    dot: "bg-rose-500" },
    StaffShared: { label: "Shared with you", text: "text-gray-500",    dot: "bg-gray-400" },
};

const fmtSize = (b) => {
    if (!b) return "";
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(2)} MB`;
};
const fmtDate = (iso) => iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "";
const latestOf = (files) => (files && files.length ? files[files.length - 1] : null);
const requestNeedsAction = (r) => !r.latest_document || r.latest_document.status === "Rejected";
const rowNeedsAction = (row) => {
    if (row.kind === "shared" || row.kind === "vif") return false;
    const doc = row.kind === "request" ? row.req.latest_document : latestOf(row.files);
    return !doc || doc.status === "Rejected";
};

export default function LeadDocumentsPage({
    lead,
    requests = [],
    shared_by_staff = [],
    vifItem = null,
    checklist = {},
    checklistFiles = {},
}) {
    const rfiDeadline = lead?.rfi_deadline || null;

    const rfiRequests = requests.filter((r) => r.origin === "rfi");
    const otherRequests = requests.filter((r) => r.origin !== "rfi");
    const rfiLetters = shared_by_staff.filter((d) => d.source_variant === "rfi");
    const medicalLetters = shared_by_staff.filter((d) => d.source_variant === "medical");
    const immTeamDocs = shared_by_staff.filter((d) => !["rfi", "vif", "medical"].includes(d.source_variant));

    // Table sections (Immigration Team → Request Information → checklist categories).
    // Requested documents are handled separately as a highlighted card above.
    const sections = [];
    const immRows = [];
    // The Visa Information Form leads the Immigration Team section — the real
    // checklist item (upload + status), not a duplicate.
    if (vifItem) immRows.push({ kind: "checklist", item: vifItem, files: checklistFiles[vifItem.key] || [] });
    immTeamDocs.forEach((d) => immRows.push({ kind: "shared", d }));
    if (immRows.length) sections.push({ key: "imm", title: "Immigration Team", rows: immRows });
    if (medicalLetters.length) {
        sections.push({ key: "medical", title: "Request for Medical", deadline: lead?.medical_deadline,
            rows: medicalLetters.map((d) => ({ kind: "shared", d, label: "INZ medical notice" })) });
    }
    if (rfiRequests.length || rfiLetters.length) {
        sections.push({
            key: "rfi", title: "Request Information", deadline: rfiDeadline,
            rows: [
                // The INZ letter reads as "Request Information Form" here, not its raw filename.
                ...rfiLetters.map((d) => ({ kind: "shared", d, label: "Request Information Form" })),
                ...rfiRequests.map((r) => ({ kind: "request", req: r })),
            ],
        });
    }
    Object.entries(checklist).forEach(([category, items]) => {
        sections.push({ key: `cl-${category}`, title: category, rows: items.map((it) => ({ kind: "checklist", item: it, files: checklistFiles[it.key] || [] })) });
    });

    const outstanding = otherRequests.filter(requestNeedsAction).length
        + sections.reduce((n, s) => n + s.rows.filter(rowNeedsAction).length, 0);
    const isEmpty = otherRequests.length === 0 && sections.length === 0;

    return (
        <div className="space-y-8 max-w-6xl mx-auto pb-16">
            <Head title="My documents" />

            {/* Header — tells the client exactly what's left to do. */}
            <div>
                <p className="text-[10px] font-bold text-[#009688] uppercase tracking-[0.32em] mb-1.5">Documents</p>
                <h1 className="text-2xl sm:text-3xl font-medium text-[#282728] tracking-tight">My documents</h1>
                <p className="text-sm text-gray-500 font-light mt-1.5 max-w-2xl">
                    {isEmpty
                        ? "This is where documents you need to upload — and files your team shares with you — will appear."
                        : outstanding > 0
                            ? `You have ${outstanding} document${outstanding === 1 ? "" : "s"} to upload. Add ${outstanding === 1 ? "it" : "them"} below and your team is notified automatically.`
                            : "Everything's up to date. There's nothing left for you to upload right now."}
                </p>
            </div>

            {/* Requested documents — a highlighted card (not part of the table). */}
            {otherRequests.length > 0 && <RequestedDocsCard items={otherRequests} />}

            {/* The document table — Immigration Team, Request Information, checklist.
                All sections stay expanded. */}
            {sections.length > 0 && (
                <section className="bg-white rounded-2xl border border-[#282728]/15 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] border-collapse text-left">
                            <thead>
                                <tr className="bg-gray-50 text-[10.5px] font-bold uppercase tracking-wider text-gray-400">
                                    <th className="px-4 py-3 w-[42%]">Document</th>
                                    <th className="px-4 py-3 w-[36%]">Attachment</th>
                                    <th className="px-4 py-3 w-[22%]">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sections.map((s) => {
                                    const uploadable = s.rows.filter((r) => r.kind === "request" || r.kind === "checklist");
                                    const done = uploadable.filter((r) => !rowNeedsAction(r)).length;
                                    const need = uploadable.length - done;
                                    const total = uploadable.length || s.rows.length;
                                    return (
                                        <SectionBlock
                                            key={s.key}
                                            title={s.title}
                                            done={uploadable.length ? done : s.rows.length}
                                            total={total}
                                            need={need}
                                            deadline={s.deadline}
                                            rows={s.rows}
                                        />
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {isEmpty && (
                <section className="bg-white rounded-2xl border border-[#282728]/15 p-10 text-center">
                    <Inbox size={30} className="mx-auto text-gray-300" />
                    <p className="mt-3 text-sm font-semibold text-[#282728]">Nothing to do yet</p>
                    <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                        When your team requests a document or shares a file with you, it&apos;ll show up here.
                    </p>
                </section>
            )}
        </div>
    );
}

// ── Requested documents (highlighted yellow card) ──────────────────────────

function RequestedDocsCard({ items }) {
    const sorted = [...items].sort((a, b) =>
        (requestNeedsAction(b) - requestNeedsAction(a)) || ((b.required === true) - (a.required === true)));
    const done = items.filter((r) => !requestNeedsAction(r)).length;
    return (
        <section className="bg-amber-50/60 rounded-2xl border-2 border-amber-300 overflow-hidden">
            <div className="px-5 sm:px-6 py-4 border-b border-amber-200/70 flex items-center gap-2.5">
                <AlertTriangle size={16} className="text-amber-600" />
                <h2 className="text-sm font-bold uppercase tracking-[0.16em] text-amber-900">Requested documents</h2>
                <span className="ml-auto text-[11px] font-bold text-amber-700 tabular-nums">{done}/{items.length} uploaded</span>
            </div>
            <p className="px-5 sm:px-6 pt-4 text-xs text-amber-800/90 font-light">
                Your adviser has asked you for these. Please upload each one — they&apos;re notified as soon as you do.
            </p>
            <ul className="p-4 sm:p-5 space-y-2.5">
                {sorted.map((r) => <RequestCard key={r.id} req={r} />)}
            </ul>
        </section>
    );
}

function RequestCard({ req }) {
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef(null);
    const doc = req.latest_document;
    const approved = doc?.status === "Approved";
    const needsFile = !doc || doc.status === "Rejected";

    const onFile = (e) => {
        const file = (e.target.files || [])[0];
        if (!file) return;
        const fd = new FormData();
        fd.append("file", file);
        fd.append("request_id", req.id);
        setUploading(true);
        router.post("/portal/lead/documents/upload", fd, {
            preserveScroll: true, preserveState: true, forceFormData: true,
            onFinish: () => { setUploading(false); if (fileRef.current) fileRef.current.value = ""; },
        });
    };

    return (
        <li className={`rounded-xl border p-4 ${doc && !needsFile ? "border-emerald-200 bg-emerald-50/40" : "border-amber-200 bg-white"}`}>
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <p className="text-sm font-semibold text-[#282728]">{req.label}</p>
                        {req.required && (
                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-rose-50 text-rose-600 border border-rose-200">Required</span>
                        )}
                        {doc && <StatusPill status={doc.status} />}
                    </div>
                    {req.description && <p className="text-[11px] text-gray-500 mt-1 leading-relaxed">{req.description}</p>}
                </div>
                {!approved && (
                    <>
                        <input ref={fileRef} type="file" onChange={onFile} className="hidden" />
                        <button type="button" onClick={() => fileRef.current?.click()} disabled={uploading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#282728] text-white rounded-lg text-[11px] font-bold uppercase tracking-wider hover:bg-black transition-colors disabled:opacity-50">
                            {uploading ? <Loader size={11} className="animate-spin" /> : <Upload size={11} />}
                            {uploading ? "Uploading…" : needsFile ? "Upload" : "Replace"}
                        </button>
                    </>
                )}
            </div>
            {doc && <div className="mt-3"><FileChip f={doc} /></div>}
        </li>
    );
}

function StatusPill({ status }) {
    const meta = STATUS[status];
    if (!meta) return null;
    return (
        <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border ${meta.text} bg-white`}>
            <span className={`w-1.5 h-1.5 rounded-full ${meta.dot}`} /> {meta.label}
        </span>
    );
}

// ── Table section + rows ───────────────────────────────────────────────────

function SectionBlock({ title, done, total, need, deadline, rows }) {
    const deadlineText = deadline
        ? new Date(deadline).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" })
        : null;
    return (
        <>
            <tr className="bg-gray-800">
                <td colSpan={3} className="px-4 py-2.5">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-gray-100">{title}</span>
                        <span className="text-[11px] font-semibold text-gray-400 tabular-nums">{done}/{total}</span>
                        {deadlineText && (
                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-300">
                                <Calendar size={11} /> Due {deadlineText}
                            </span>
                        )}
                        {need > 0 && <span className="ml-auto text-[11px] text-gray-300">{need} to upload</span>}
                    </div>
                </td>
            </tr>
            {rows.map((row) => {
                if (row.kind === "shared") return <SharedRow key={`s-${row.d.id}`} d={row.d} label={row.label} />;
                if (row.kind === "request") return <RequestRow key={`r-${row.req.id}`} req={row.req} />;
                return <ChecklistRow key={`c-${row.item.key}`} item={row.item} files={row.files} />;
            })}
        </>
    );
}

function FileChip({ f, downloadUrl }) {
    const dl = downloadUrl || `/portal/lead/documents/${f.id}/download`;
    return (
        <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 max-w-[300px]">
            <FileText size={15} className="text-gray-300 flex-shrink-0" />
            <span className="flex flex-col min-w-0 flex-1">
                <span className="text-[12px] text-gray-800 truncate" title={f.original_name}>{f.original_name}</span>
                {f.size ? <span className="text-[10px] text-gray-400 tabular-nums">{fmtSize(f.size)}</span> : null}
            </span>
            <a href={`/portal/lead/documents/${f.id}/download?inline=1`} target="_blank" rel="noreferrer"
                className="inline-flex items-center justify-center w-6 h-6 rounded text-gray-500 hover:text-[#009688] hover:bg-[#009688]/10" title="View"><Eye size={12} /></a>
            <a href={dl}
                className="inline-flex items-center justify-center w-6 h-6 rounded text-gray-500 hover:text-[#009688] hover:bg-[#009688]/10" title="Download"><Download size={12} /></a>
        </div>
    );
}

function StatusCell({ status, label, dot }) {
    if (label) {
        return (
            <span className={`inline-flex items-center gap-2 text-[12px] font-medium ${dot ? "" : "text-gray-500"}`}>
                <span className={`w-2 h-2 rounded-full ${dot || "bg-gray-400"}`} /> {label}
            </span>
        );
    }
    const meta = status ? STATUS[status] : null;
    if (!meta) {
        return (
            <span className="inline-flex items-center gap-2 text-[11px] font-semibold text-gray-500">
                <span className="w-2 h-2 rounded-full bg-gray-300" /> Not uploaded
            </span>
        );
    }
    return (
        <span className={`inline-flex items-center gap-2 text-[12px] font-semibold ${meta.text}`}>
            <span className={`w-2 h-2 rounded-full ${meta.dot}`} /> {meta.label}
        </span>
    );
}

function UploadButton({ onPick, uploading, hasFiles }) {
    return (
        <button type="button" onClick={onPick} disabled={uploading}
            className={hasFiles
                ? "inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md border border-gray-200 text-gray-600 text-[11px] font-bold hover:border-gray-400 hover:text-gray-900 disabled:opacity-50"
                : "inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#282728] text-white rounded-lg text-[11px] font-bold uppercase tracking-wider hover:bg-black disabled:opacity-50"}>
            {uploading ? <Loader size={11} className="animate-spin" /> : <Upload size={11} />}
            {uploading ? "Uploading…" : hasFiles ? "Add more" : "Upload"}
        </button>
    );
}

// A document the immigration team shared — download only. `label` overrides the
// displayed name (e.g. the RFI letter reads "Request Information Form").
function SharedRow({ d, label }) {
    return (
        <tr className="border-b border-gray-50 last:border-b-0 align-top">
            <td className="px-4 py-3">
                <div className="flex items-start gap-2">
                    <FileText size={14} className="text-gray-300 flex-shrink-0 mt-0.5" />
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-[#282728] leading-tight truncate">{label || d.original_name}</p>
                        <p className="text-[10.5px] text-gray-400 mt-0.5">Shared {fmtDate(d.created_at)}</p>
                    </div>
                </div>
            </td>
            <td className="px-4 py-3"><FileChip f={d} /></td>
            <td className="px-4 py-3"><StatusCell label="Shared with you" /></td>
        </tr>
    );
}

// A document requested from the client (RFI) — upload against request_id.
function RequestRow({ req }) {
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef(null);
    const doc = req.latest_document;
    const approved = doc?.status === "Approved";

    const onFile = (e) => {
        const file = (e.target.files || [])[0];
        if (!file) return;
        const fd = new FormData();
        fd.append("file", file);
        fd.append("request_id", req.id);
        setUploading(true);
        router.post("/portal/lead/documents/upload", fd, {
            preserveScroll: true, preserveState: true, forceFormData: true,
            onFinish: () => { setUploading(false); if (fileRef.current) fileRef.current.value = ""; },
        });
    };

    return (
        <tr className="border-b border-gray-50 last:border-b-0 align-top">
            <td className="px-4 py-3">
                <div className="flex items-start gap-2">
                    <FileText size={14} className="text-gray-300 flex-shrink-0 mt-0.5" />
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-[#282728] leading-tight">
                            {req.label}{req.required && <span className="ml-1 text-rose-500">*</span>}
                        </p>
                        {req.description && <p className="text-[10.5px] text-gray-500 mt-0.5 max-w-[320px]">{req.description}</p>}
                    </div>
                </div>
            </td>
            <td className="px-4 py-3">
                <input ref={fileRef} type="file" onChange={onFile} className="hidden" />
                {doc ? (
                    <div className="flex items-center gap-2 flex-wrap">
                        <FileChip f={doc} />
                        {!approved && <UploadButton onPick={() => fileRef.current?.click()} uploading={uploading} hasFiles />}
                    </div>
                ) : (
                    <UploadButton onPick={() => fileRef.current?.click()} uploading={uploading} hasFiles={false} />
                )}
            </td>
            <td className="px-4 py-3"><StatusCell status={doc?.status} /></td>
        </tr>
    );
}

// A checklist item — upload against the checklist key (may hold several files).
function ChecklistRow({ item, files }) {
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef(null);
    const latest = latestOf(files);

    const onFile = (e) => {
        const picked = Array.from(e.target.files || []);
        if (!picked.length) return;
        const fd = new FormData();
        picked.forEach((f) => fd.append("files[]", f));
        setUploading(true);
        router.post(`/portal/lead/documents/checklist/${encodeURIComponent(item.key)}/upload`, fd, {
            preserveScroll: true, preserveState: true, forceFormData: true,
            onFinish: () => { setUploading(false); if (fileRef.current) fileRef.current.value = ""; },
        });
    };

    return (
        <tr className="border-b border-gray-50 last:border-b-0 align-top">
            <td className="px-4 py-3">
                <div className="flex items-start gap-2">
                    <FileText size={14} className="text-gray-300 flex-shrink-0 mt-0.5" />
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-[#282728] leading-tight">
                            {item.label}{item.required && <span className="ml-1 text-rose-500">*</span>}
                        </p>
                        {item.hint && <p className="text-[10.5px] text-gray-500 mt-0.5 max-w-[320px]">{item.hint}</p>}
                    </div>
                </div>
            </td>
            <td className="px-4 py-3">
                <input ref={fileRef} type="file" multiple onChange={onFile} className="hidden" />
                {files.length ? (
                    <div className="flex flex-col gap-1.5">
                        {files.map((f) => <FileChip key={f.id} f={f} />)}
                        <UploadButton onPick={() => fileRef.current?.click()} uploading={uploading} hasFiles />
                    </div>
                ) : (
                    <UploadButton onPick={() => fileRef.current?.click()} uploading={uploading} hasFiles={false} />
                )}
            </td>
            <td className="px-4 py-3"><StatusCell status={latest?.status} /></td>
        </tr>
    );
}
