import { useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import { caseNav } from "@/lib/caseNav";
import {
    Users, Plus, X, Pencil, Trash2, Upload, FileText, Eye, Download, IdCard, FolderOpen, Link as LinkIcon,
    CheckCircle2, Clock, AlertTriangle, XCircle, Circle,
} from "lucide-react";
import { immBase, docDownloadUrl } from "@/lib/caseRoutes";

// Case → Family / Dependants. Each dependant has a document CHECKLIST (dependent
// child visa etc.), with per-item status + overall progress. Clicking a
// dependant's "Documents" opens the full checklist modal.

const RELATIONSHIPS = ["child", "partner", "parent", "sibling", "other"];
const REL_LABEL = { child: "Child", partner: "Partner", parent: "Parent", sibling: "Sibling", other: "Other" };

const STATUS = {
    Missing:     { label: "Not submitted", tone: "bg-gray-50 text-gray-500 border-gray-200",         icon: Circle },
    Submitted:   { label: "Submitted",     tone: "bg-yellow-50 text-yellow-700 border-yellow-200",   icon: Clock },
    UnderReview: { label: "Under review",  tone: "bg-blue-50 text-blue-700 border-blue-200",         icon: AlertTriangle },
    Approved:    { label: "Approved",      tone: "bg-emerald-50 text-emerald-700 border-emerald-200", icon: CheckCircle2 },
    Rejected:    { label: "Rejected",      tone: "bg-red-50 text-red-700 border-red-200",            icon: XCircle },
};

export default function DependantsTab({ lead, dependents = [], caseOptions = [], visaTypes = [], canCorrectCaseNames = false }) {
    const [editing, setEditing] = useState(null);
    const [docsFor, setDocsFor] = useState(null);
    if (!lead?.id) return null;

    const base = `${immBase()}/cases/${lead.id}/dependents`;

    const remove = (d) => {
        if (!window.confirm(`Remove ${d.full_name} and their documents from this case?`)) return;
        router.delete(`${base}/${d.id}`, { preserveScroll: true, onSuccess: () => toast.success("Dependant removed") });
    };

    // Open a family member's own case. If they aren't tied to one yet, the
    // server creates it (every family member is a case) and redirects there.
    const openCase = (d) => {
        if (d.linked_lead_id) {
            router.visit(caseNav().profile(d.linked_lead_id));
            return;
        }
        router.post(`${base}/${d.id}/open-case`, { portal: caseNav().adviser ? "immigration-adviser" : "immigration" }, {
            onError: () => toast.error("Could not open the case"),
        });
    };

    // Include/exclude a dependant from the written agreement + invoice. The
    // engagement fees, preview and invoice all read this flag.
    const toggleAgreement = (d, val) => {
        router.patch(`${base}/${d.id}/in-agreement`, { in_agreement: val }, {
            preserveScroll: true,
            onSuccess: () => toast.success(val ? "Added to the agreement" : "Excluded from the agreement"),
            onError: () => toast.error("Could not update"),
        });
    };

    // Keep the open modal's data fresh after an Inertia reload.
    const openDoc = docsFor ? dependents.find((x) => x.id === docsFor.id) || docsFor : null;

    return (
        <div className="space-y-4">
            <div className="rounded-2xl border border-[#009688]/20 bg-white shadow-sm p-4 flex items-start justify-between gap-3 flex-wrap">
                <div className="flex items-start gap-2">
                    <Users size={15} className="text-[#009688] mt-0.5 flex-shrink-0" />
                    <p className="text-[12px] text-gray-600 leading-snug max-w-2xl">
                        Dependants included in this application, each with their own document checklist. The principal applicant
                        can also add them and upload documents from their portal.
                    </p>
                </div>
                <button type="button" onClick={() => setEditing({})}
                    className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-[#009688] text-white text-[12px] font-semibold hover:bg-[#00796b] flex-shrink-0">
                    <Plus size={14} /> Add dependant
                </button>
            </div>

            {dependents.length === 0 ? (
                <div className="text-center py-12 border border-dashed border-gray-200 rounded-2xl bg-gray-50/50">
                    <Users size={26} className="mx-auto text-gray-300" />
                    <p className="mt-3 text-sm text-gray-700 font-semibold">No dependants on this case</p>
                    <p className="text-xs text-gray-500 mt-1">Add children or a partner included in this application.</p>
                </div>
            ) : (
                <div className="border border-gray-100 rounded-2xl overflow-hidden bg-white shadow-sm overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr className="bg-gray-700 text-[10.5px] font-bold uppercase tracking-wider text-gray-100">
                                <th className="text-left px-4 py-3">Dependant</th>
                                <th className="text-left px-4 py-3">Relationship</th>
                                <th className="text-left px-4 py-3">Documents</th>
                                <th className="text-right px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {dependents.map((d) => (
                                <DependantRow key={d.id} d={d} onEdit={() => setEditing(d)} onRemove={() => remove(d)} onDocs={() => setDocsFor(d)} onToggleAgreement={(val) => toggleAgreement(d, val)} onOpenCase={() => openCase(d)} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {editing && <DependantModal dependant={editing} base={base} caseOptions={caseOptions} visaTypes={visaTypes} currentLeadId={lead.id} canCorrectCaseNames={canCorrectCaseNames} onClose={() => setEditing(null)} />}
            {openDoc && <DocumentsModal d={openDoc} base={base} onClose={() => setDocsFor(null)} />}
        </div>
    );
}

function ProgressBar({ done, total }) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const complete = total > 0 && done >= total;
    return (
        <div className="flex items-center gap-2 min-w-[160px]">
            <div className="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                <div className={`h-full ${complete ? "bg-emerald-500" : "bg-[#009688]"}`} style={{ width: `${pct}%` }} />
            </div>
            <span className={`text-[11px] font-bold ${complete ? "text-emerald-600" : "text-gray-500"}`}>{done}/{total}</span>
        </div>
    );
}

function DependantRow({ d, onEdit, onRemove, onDocs, onToggleAgreement, onOpenCase }) {
    const p = d.progress || { required_done: 0, required_total: 0 };
    // The child's uploaded Face image, if any — shown as their profile photo.
    const photo = (d.checklist || []).find((i) => i.key?.endsWith("face_image") && i.document)?.document;
    const photoUrl = photo ? docDownloadUrl(photo.id, { inline: true }) : null;
    return (
        <tr className="hover:bg-gray-50/60">
            <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                    {photoUrl ? (
                        <img src={photoUrl} alt={d.full_name} className="w-9 h-9 rounded-xl object-cover flex-shrink-0 border border-gray-100" />
                    ) : (
                        <div className="w-9 h-9 rounded-xl bg-[#009688]/10 flex items-center justify-center flex-shrink-0">
                            <IdCard size={16} className="text-[#009688]" />
                        </div>
                    )}
                    <div className="min-w-0">
                        <button
                            type="button"
                            onClick={onOpenCase}
                            className="text-sm font-bold text-[#009688] hover:text-[#00695f] hover:underline truncate inline-block max-w-full text-left"
                            title={d.linked_lead_id ? "Open this family member's case" : "Open this family member as a case"}
                        >
                            {d.full_name}
                        </button>
                        <div className="text-[12px] text-gray-500 truncate">
                            {[d.dob && `DOB ${d.dob}`, d.nationality, d.passport_number && `Passport ${d.passport_number}`].filter(Boolean).join(" · ") || "No details yet"}
                        </div>
                        <div className="text-[11px] mt-0.5 truncate">
                            {d.linked_lead_id
                                ? <span className="text-sky-600 font-medium">Visa from linked case</span>
                                : d.visa_name
                                    ? <span className="text-[#009688] font-medium">{d.visa_name}</span>
                                    : <span className="text-amber-600 font-medium">No visa set</span>}
                        </div>
                    </div>
                </div>
            </td>
            <td className="px-4 py-3">
                <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded border bg-[#009688]/10 text-[#009688] border-[#009688]/30">{REL_LABEL[d.relationship] || d.relationship}</span>
                {d.source === "portal" && <span className="text-[10px] font-semibold text-gray-400 border border-gray-200 rounded px-1.5 py-0.5 ml-1">Applicant</span>}
                {d.linked_lead_id && (
                    <div className="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold text-sky-700 bg-sky-50 border border-sky-100 rounded px-1.5 py-0.5" title="This dependant is tied to their own case">
                        <LinkIcon size={10} /> Tied to case
                    </div>
                )}
                {/* Whether this dependant is billed on the written agreement /
                    invoice. Default on; untick to leave them off the agreement. */}
                <label className="mt-1.5 flex items-center gap-1.5 text-[11px] font-semibold text-gray-600 cursor-pointer" title="Include this dependant on the written agreement and invoice">
                    <input
                        type="checkbox"
                        checked={d.in_agreement !== false}
                        onChange={(e) => onToggleAgreement(e.target.checked)}
                        className="rounded border-gray-300 text-[#009688] focus:ring-0 w-3.5 h-3.5"
                    />
                    In agreement
                </label>
            </td>
            <td className="px-4 py-3"><ProgressBar done={p.required_done} total={p.required_total} /></td>
            <td className="px-4 py-3">
                <div className="flex items-center justify-end gap-1.5">
                    <button type="button" onClick={onDocs} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-[#009688]/30 text-[#009688] text-[11px] font-semibold hover:bg-[#009688]/5">
                        <FolderOpen size={13} /> Documents
                    </button>
                    <button type="button" onClick={onEdit} title="Edit" className="p-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50"><Pencil size={13} /></button>
                    <button type="button" onClick={onRemove} title="Remove" className="p-1.5 rounded-lg border border-gray-200 text-red-500 hover:bg-red-50"><Trash2 size={13} /></button>
                </div>
            </td>
        </tr>
    );
}

function DocumentsModal({ d, base, onClose }) {
    const fileRefs = useRef({});
    const [busyKey, setBusyKey] = useState(null);
    const checklist = d.checklist || [];
    const other = d.other_documents || [];
    // Tied to the child's own case: documents live there. View/download only —
    // uploads, approvals and removals happen on the child's own case.
    const linked = !!d.linked;

    const upload = (key, e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setBusyKey(key || "__other__");
        router.post(`${base}/${d.id}/documents`, { file, checklist_key: key || "" }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => toast.success("Uploaded"),
            onError: (err) => toast.error(Object.values(err)[0] || "Upload failed"),
            onFinish: () => { setBusyKey(null); if (fileRefs.current[key || "__other__"]) fileRefs.current[key || "__other__"].value = ""; },
        });
    };
    const setStatus = (doc, status) => router.post(`${base}/${d.id}/documents/${doc.id}/status`, { status }, {
        preserveScroll: true, onSuccess: () => toast.success(`Marked ${status}`),
    });
    const saveNote = (doc, note) => router.post(`${base}/${d.id}/documents/${doc.id}/note`, { note }, {
        preserveScroll: true, onSuccess: () => toast.success("Note saved"),
    });
    const removeDoc = (doc) => {
        if (!window.confirm(`Remove ${doc.original_name}?`)) return;
        router.delete(`${base}/${d.id}/documents/${doc.id}`, { preserveScroll: true, onSuccess: () => toast.success("Removed") });
    };

    const satisfied = checklist.filter((i) => i.document && i.status !== "Rejected").length;

    const AttachmentCell = ({ item, doc, busy }) => (
        <div className="flex items-center gap-1.5">
            {!linked && (
                <>
                    <button type="button" onClick={() => fileRefs.current[item.key]?.click()} disabled={busy}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black disabled:opacity-50">
                        <Upload size={12} /> {busy ? "Uploading…" : doc ? "Replace" : "Upload"}
                    </button>
                    <input ref={(el) => (fileRefs.current[item.key] = el)} type="file" className="hidden" onChange={(e) => upload(item.key, e)} />
                </>
            )}
            {doc && <a href={docDownloadUrl(doc.id, { inline: true })} target="_blank" rel="noopener noreferrer" title="View" className="p-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50"><Eye size={12} /></a>}
            {doc && <a href={docDownloadUrl(doc.id)} title="Download" className="p-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50"><Download size={12} /></a>}
            {linked && !doc && <span className="text-[11px] text-gray-400">Not yet submitted</span>}
        </div>
    );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" onClick={onClose}>
            <div className="w-full max-w-5xl rounded-2xl bg-white shadow-xl max-h-[92vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">{d.full_name} — Documents</h2>
                        <p className="text-[12px] text-gray-500">{REL_LABEL[d.relationship] || d.relationship} · {d.progress?.required_done ?? 0}/{d.progress?.required_total ?? 0} required complete</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto">
                    {linked && (
                        <div className="mb-4 flex items-start gap-2 rounded-xl border border-sky-100 bg-sky-50 px-4 py-3">
                            <FolderOpen size={16} className="text-sky-500 mt-0.5 flex-shrink-0" />
                            <p className="text-[12px] text-sky-800">These documents were submitted by <span className="font-semibold">{d.linked_case_name || d.full_name}</span> on their own case — shown here read-only. Approve, replace or remove them from the child's own case.</p>
                        </div>
                    )}
                    {d.needs_visa ? (
                        <div className="text-center py-12">
                            <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-50 text-amber-600 mb-3">
                                <IdCard size={22} />
                            </div>
                            <p className="text-[15px] font-bold text-gray-800">No visa assigned yet</p>
                            <p className="text-[12.5px] text-gray-500 mt-1 max-w-sm mx-auto">Edit this family member and set their <span className="font-semibold">Visa type</span> — the document checklist is generated from the visa.</p>
                        </div>
                    ) : (
                    <div className="border border-gray-100 rounded-xl overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50 border-b border-gray-100 text-[10.5px] font-bold uppercase tracking-wider text-gray-500">
                                    <th className="text-left px-4 py-2.5 w-[40%]">Document</th>
                                    <th className="text-left px-4 py-2.5 w-[26%]">Attachment</th>
                                    <th className="text-left px-4 py-2.5 w-[18%]">Status</th>
                                    <th className="text-left px-4 py-2.5 w-[16%]">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="bg-gray-200 border-y border-gray-300">
                                    <td colSpan={4} className="px-4 py-2">
                                        <span className="text-[11px] font-bold uppercase tracking-wider text-gray-700">{REL_LABEL[d.relationship] || d.relationship} documents</span>
                                        <span className="text-[10.5px] font-semibold text-gray-500 ml-2">{satisfied}/{checklist.length}</span>
                                    </td>
                                </tr>
                                {checklist.map((item) => {
                                    const st = STATUS[item.status] || STATUS.Missing;
                                    const doc = item.document;
                                    const busy = busyKey === item.key;
                                    return (
                                        <tr key={item.key} className="border-b border-gray-50 hover:bg-gray-50/40 align-top">
                                            <td className="px-4 py-3">
                                                <span className="text-[13px] font-medium text-gray-800">{item.label}</span>
                                                {item.required && <span className="text-red-500 ml-1">*</span>}
                                                {item.hint && <div className="text-[11px] text-gray-400 mt-0.5 max-w-[260px]">{item.hint}</div>}
                                                {doc && <div className="text-[11px] text-gray-400 truncate mt-0.5 max-w-[240px]">{doc.original_name}</div>}
                                            </td>
                                            <td className="px-4 py-3"><AttachmentCell item={item} doc={doc} busy={busy} /></td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold border ${st.tone}`}>{st.label}</span>
                                                {doc && !linked && (
                                                    <div className="flex items-center gap-1 mt-1.5">
                                                        <button type="button" title="Approve" onClick={() => setStatus(doc, "Approved")} className="px-1.5 py-1 rounded text-[10px] font-semibold text-emerald-700 hover:bg-emerald-50">Approve</button>
                                                        <button type="button" title="Reject" onClick={() => setStatus(doc, "Rejected")} className="px-1.5 py-1 rounded text-[10px] font-semibold text-red-600 hover:bg-red-50">Reject</button>
                                                        <button type="button" title="Remove" onClick={() => removeDoc(doc)} className="p-1 rounded text-red-500 hover:bg-red-50"><Trash2 size={12} /></button>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {doc
                                                    ? <NoteCell doc={doc} onSave={(note) => saveNote(doc, note)} />
                                                    : <span className="text-gray-300">—</span>}
                                            </td>
                                        </tr>
                                    );
                                })}

                                {other.length > 0 && (
                                    <>
                                        <tr className="bg-gray-200 border-y border-gray-300">
                                            <td colSpan={4} className="px-4 py-2">
                                                <span className="text-[11px] font-bold uppercase tracking-wider text-gray-700">Other documents</span>
                                            </td>
                                        </tr>
                                        {other.map((doc) => (
                                            <tr key={doc.id} className="border-b border-gray-50 hover:bg-gray-50/40">
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex items-center gap-1.5 text-[13px] text-gray-800"><FileText size={13} className="text-gray-400" />{doc.original_name}</span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <a href={docDownloadUrl(doc.id, { inline: true })} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 text-gray-600 text-[11px] font-semibold hover:bg-gray-50"><Eye size={12} /> View</a>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold border ${(STATUS[doc.status] || STATUS.Submitted).tone}`}>{(STATUS[doc.status] || STATUS.Submitted).label}</span>
                                                    {!linked && <button type="button" onClick={() => removeDoc(doc)} className="p-1 ml-1 rounded text-red-500 hover:bg-red-50"><Trash2 size={12} /></button>}
                                                </td>
                                                <td className="px-4 py-3"><NoteCell doc={doc} onSave={(note) => saveNote(doc, note)} /></td>
                                            </tr>
                                        ))}
                                    </>
                                )}
                            </tbody>
                        </table>
                    </div>
                    )}
                </div>

                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
                    {linked ? <span /> : (
                        <>
                            <button type="button" onClick={() => fileRefs.current["__other__"]?.click()} disabled={busyKey === "__other__"}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold text-gray-600 hover:bg-gray-50">
                                <Plus size={12} /> Add other document
                            </button>
                            <input ref={(el) => (fileRefs.current["__other__"] = el)} type="file" className="hidden" onChange={(e) => upload("", e)} />
                        </>
                    )}
                    <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">Done</button>
                </div>
            </div>
        </div>
    );
}

// Inline staff note on a document. Saves on blur or Enter; a Save affordance
// appears while the text differs from what's stored. Works for the dependant's
// own documents and — via the note route — a linked case's read-through docs.
function NoteCell({ doc, onSave }) {
    const [val, setVal] = useState(doc.note || "");
    const dirty = val !== (doc.note || "");
    const commit = () => { if (dirty) onSave(val); };
    return (
        <div className="flex items-center gap-1">
            <input
                value={val}
                onChange={(e) => setVal(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); commit(); e.target.blur(); } }}
                placeholder="Add a note…"
                className="w-full min-w-[120px] rounded-md border border-gray-200 px-2 py-1 text-[11px] outline-none focus:border-[#009688] focus:ring-1 focus:ring-[#009688]"
            />
            {dirty && (
                <button type="button" onClick={commit} className="text-[10px] font-bold text-[#009688] hover:text-[#00796b] flex-shrink-0">Save</button>
            )}
        </div>
    );
}

function DependantModal({ dependant, base, caseOptions = [], visaTypes = [], currentLeadId, canCorrectCaseNames, onClose }) {
    const isNew = !dependant.id;
    const [form, setForm] = useState({
        linked_lead_id: dependant.linked_lead_id || "", // ties this dependant to the child's own case
        visa_type_id: dependant.visa_type_id || "",     // staff-set visa → drives the checklist
        relationship: dependant.relationship || "child",
        first_name: dependant.first_name || "",
        family_name: dependant.family_name || "",
        middle_name: dependant.middle_name || "",
        dob: dependant.dob || "",
        gender: dependant.gender || "",
        nationality: dependant.nationality || "",
        passport_number: dependant.passport_number || "",
        passport_expiry: dependant.passport_expiry || "",
        notes: dependant.notes || "",
    });
    const [saving, setSaving] = useState(false);
    // How this family member's documents are sourced: "case" = read from their
    // own case (tie), "visa" = set a visa here and the applicant uploads.
    const [docMode, setDocMode] = useState(dependant.linked_lead_id ? "case" : (dependant.visa_type_id ? "visa" : "case"));
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    // Selecting a case TIES this dependant to that child's own case (linked_lead_id)
    // and pre-fills the still-empty identity fields from that case's applicant
    // (fetched on demand so we don't ship every case's passport).
    const pickCase = (id) => {
        setForm((f) => ({ ...f, linked_lead_id: id }));
        if (!id) return;
        fetch(`${immBase()}/cases/${id}/dependent-source`, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => {
                if (!d) return;
                setForm((f) => ({
                    ...f,
                    ...(isNew || canCorrectCaseNames ? {
                        first_name: f.first_name || d.first_name || "",
                        family_name: f.family_name || d.family_name || "",
                        middle_name: f.middle_name || d.middle_name || "",
                    } : {}),
                    dob: f.dob || d.dob || "",
                    gender: f.gender || d.gender || "",
                    nationality: f.nationality || d.nationality || "",
                    passport_number: f.passport_number || d.passport_number || "",
                    passport_expiry: f.passport_expiry || d.passport_expiry || "",
                }));
            })
            .catch(() => {});
    };

    const submit = () => {
        setSaving(true);
        const opts = {
            preserveScroll: true,
            onSuccess: () => { toast.success(isNew ? "Dependant added" : "Dependant updated"); onClose(); },
            onError: (err) => toast.error(Object.values(err)[0] || "Could not save"),
            onFinish: () => setSaving(false),
        };
        // The dependant is always added to the CURRENT case (this Family tab).
        // "Related to case" ties it to the child's own case (linked_lead_id) so the
        // child's submitted documents show here — it is NOT the target case.
        if (isNew) router.post(base, form, opts);
        else router.put(`${base}/${dependant.id}`, form, opts);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" onClick={onClose}>
            <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white">
                    <h2 className="text-base font-semibold text-gray-900">{isNew ? "Add dependant" : "Edit dependant"}</h2>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><X size={18} /></button>
                </div>
                <div className="p-5 space-y-3.5">
                    <Field label="Relationship">
                        <select value={form.relationship} onChange={(e) => set("relationship", e.target.value)} className={INPUT}>
                            {RELATIONSHIPS.map((r) => <option key={r} value={r}>{REL_LABEL[r]}</option>)}
                        </select>
                    </Field>

                    {/* Where this member's documents come from — an explicit choice
                        so "tie to their own case" is never confused with "set a visa". */}
                    <div>
                        <span className="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Where do this family member&apos;s documents come from?</span>
                        {caseOptions.length > 0 && (
                            <div className="grid grid-cols-2 gap-2 mb-2">
                                <button type="button" onClick={() => setDocMode("case")}
                                    className={`text-left px-3 py-2 rounded-lg border transition-colors ${docMode === "case" ? "border-[#009688] bg-[#009688]/5" : "border-gray-200 hover:bg-gray-50"}`}>
                                    <span className={`block text-[12px] font-bold ${docMode === "case" ? "text-[#009688]" : "text-gray-700"}`}>They have their own case</span>
                                    <span className="block text-[10.5px] text-gray-400 mt-0.5">Show their case checklist &amp; documents here</span>
                                </button>
                                <button type="button" onClick={() => { setDocMode("visa"); set("linked_lead_id", ""); }}
                                    className={`text-left px-3 py-2 rounded-lg border transition-colors ${docMode === "visa" ? "border-[#009688] bg-[#009688]/5" : "border-gray-200 hover:bg-gray-50"}`}>
                                    <span className={`block text-[12px] font-bold ${docMode === "visa" ? "text-[#009688]" : "text-gray-700"}`}>Upload for them here</span>
                                    <span className="block text-[10.5px] text-gray-400 mt-0.5">Set a visa; the applicant uploads</span>
                                </button>
                            </div>
                        )}
                        {docMode === "case" && caseOptions.length > 0 ? (
                            <>
                                <select value={form.linked_lead_id} onChange={(e) => pickCase(e.target.value)} className={INPUT}>
                                    <option value="">— Select their case —</option>
                                    {caseOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <p className="text-[10.5px] text-gray-400 mt-1">Pulls this member&apos;s checklist and <span className="font-semibold">every document uploaded on their own case</span> into this Family view (read-only here).</p>
                            </>
                        ) : (
                            <p className="text-[10.5px] text-gray-400 mt-1">The applicant uploads each document from their portal — pick their visa below to build the checklist.</p>
                        )}
                    </div>

                    {/* Visa type — always available so staff can set the applicant's
                        visa for BOTH the checklist and the fee agreement, even when
                        the dependant has their own case. */}
                    <Field label="Visa type">
                        <select value={form.visa_type_id} onChange={(e) => set("visa_type_id", e.target.value)} className={INPUT}>
                            <option value="">— Select a visa —</option>
                            {visaTypes.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                        </select>
                        <p className="text-[10.5px] text-gray-400 mt-1">Drives this member&apos;s checklist and their line on the engagement agreement.</p>
                    </Field>
                    {/* Identity fields — only for the manual (no own case) path.
                        When tied to a case, name/DOB/passport come from that case,
                        so there's nothing to type here. */}
                    {form.linked_lead_id ? (
                        <div className="rounded-lg border border-sky-100 bg-sky-50 px-3 py-2.5 text-[12px] text-sky-800">
                            Details (name, date of birth, passport…) come from the linked case — no need to enter them here.
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Given name(s)"><input className={INPUT} readOnly={!isNew && !canCorrectCaseNames} value={form.first_name} onChange={(e) => set("first_name", e.target.value)} /></Field>
                                <Field label="Family name"><input className={INPUT} readOnly={!isNew && !canCorrectCaseNames} value={form.family_name} onChange={(e) => set("family_name", e.target.value)} /></Field>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Date of birth"><input type="date" className={INPUT} value={form.dob} onChange={(e) => set("dob", e.target.value)} /></Field>
                                <Field label="Gender"><input className={INPUT} value={form.gender} onChange={(e) => set("gender", e.target.value)} /></Field>
                            </div>
                            <Field label="Nationality"><input className={INPUT} value={form.nationality} onChange={(e) => set("nationality", e.target.value)} /></Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Passport number"><input className={INPUT} value={form.passport_number} onChange={(e) => set("passport_number", e.target.value)} /></Field>
                                <Field label="Passport expiry"><input type="date" className={INPUT} value={form.passport_expiry} onChange={(e) => set("passport_expiry", e.target.value)} /></Field>
                            </div>
                            <Field label="Notes"><textarea rows={2} className={INPUT} value={form.notes} onChange={(e) => set("notes", e.target.value)} /></Field>
                        </>
                    )}
                </div>
                <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-end gap-2 sticky bottom-0 bg-white">
                    <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="button" onClick={submit} disabled={saving} className="px-4 py-2 rounded-lg bg-[#009688] text-white text-sm font-semibold hover:bg-[#00796b] disabled:opacity-50">
                        {saving ? "Saving…" : isNew ? "Add dependant" : "Save"}
                    </button>
                </div>
            </div>
        </div>
    );
}

const INPUT = "w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[#009688] focus:ring-1 focus:ring-[#009688]";

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1">{label}</span>
            {children}
        </label>
    );
}
