import { useRef, useState } from "react";
import { useForm } from "@inertiajs/react";
import { PencilLine, Save } from "lucide-react";
import TicketAttachmentPicker from "./TicketAttachmentPicker";

const categories = { change: "Change to something", feature: "New feature", bug: "Something's broken", other: "Other" };
const priorities = ["low", "normal", "high", "urgent"];
const fieldClass = "mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-gray-300 disabled:opacity-60";
const message = (value) => Array.isArray(value) ? value.join(" ") : value;

export default function TicketRequestEditor({ ticket }) {
    const [editing, setEditing] = useState(false);
    const [saved, setSaved] = useState(false);
    const canEdit = ticket.can_edit_request && !ticket.archived_at;
    if (!canEdit && !ticket.request_edited_at) return null;

    return <div className="mt-3 space-y-2">
        {ticket.request_edited_at && <p className="text-[11px] text-gray-500"><time dateTime={ticket.request_edited_at}>Edited {new Date(ticket.request_edited_at).toLocaleString()}</time></p>}
        {saved && <p role="status" className="text-xs text-emerald-700">Request updated.</p>}
        {canEdit && (editing ? <RequestForm key={ticket.id} ticket={ticket} onCancel={() => setEditing(false)} onSuccess={() => { setEditing(false); setSaved(true); }} /> : <button type="button" onClick={() => { setSaved(false); setEditing(true); }} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50"><PencilLine size={13} />Edit request</button>)}
    </div>;
}

function RequestForm({ ticket, onCancel, onSuccess }) {
    // Keep the version captured when editing began. New page props must not
    // overwrite a draft or silently bypass a concurrent request-content edit.
    const { data, setData, post, transform, processing, progress, errors } = useForm({ title: ticket.title || "", description: ticket.description || "", category: ticket.category || "change", priority: ticket.priority || "normal", request_version: ticket.request_version, attachments: [] });
    const submitting = useRef(false);
    const [selectionError, setSelectionError] = useState("");
    const existingCount = (ticket.images?.length || 0) + (ticket.attachments?.length || 0);
    const remaining = Math.max(0, 10 - existingCount);
    const completed = ticket.status === "done" || ticket.status === "declined";
    const otherErrors = Object.entries(errors).filter(([key]) => !["title", "description", "category", "priority", "request_version", "attachments"].includes(key) && !key.startsWith("attachments."));
    const chooseFiles = (files) => {
        if (files.length > remaining) { setSelectionError(`Up to 10 total files are allowed, including ${existingCount} existing files. You can add ${remaining} more. Remove a selected file or choose fewer files.`); return; }
        setSelectionError(""); setData("attachments", files);
    };
    const submit = (event) => {
        event.preventDefault();
        if (submitting.current || processing) return;
        if (data.attachments.length > remaining) { setSelectionError("This ticket now has more evidence. Remove selected files to stay within the 10-file total."); return; }
        submitting.current = true;
        transform((values) => {
            const payload = new FormData();
            ["title", "description", "category", "priority", "request_version"].forEach((key) => payload.append(key, values[key]));
            values.attachments.forEach((file) => payload.append("attachments[]", file));
            return payload;
        });
        post(`/system-tickets/${ticket.id}/request`, { forceFormData: true, preserveScroll: true, preserveState: true, onSuccess, onFinish: () => { submitting.current = false; } });
    };

    return <form aria-label="Edit ticket request" onSubmit={submit} className="space-y-4 rounded-xl border border-gray-200 bg-gray-50/50 p-4">
        <div><h3 className="text-sm font-semibold text-gray-900">Edit your request</h3><p className="mt-1 text-xs text-gray-500">Update the request or add evidence. Existing attachments stay on the ticket.</p></div>
        {completed && <p className="text-xs text-gray-600">Saving keeps the ticket status {ticket.status === "done" ? "Done" : "Declined"} and preserves the support team's response and resolution.</p>}
        {otherErrors.length > 0 && <div role="alert" className="text-xs text-rose-700">{otherErrors.map(([key, value]) => <p key={key}>{message(value)}</p>)}</div>}
        {errors.request_version && <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><p className="font-semibold">{message(errors.request_version)}</p><p className="mt-1">Your draft and selected files are still here. Copy your draft before reloading the page to see the latest request. Reloading clears unsaved changes and selected files.</p></div>}
        <label className="block text-xs font-semibold text-gray-600">Title<input name="title" value={data.title} onChange={(event) => setData("title", event.target.value)} maxLength={160} required disabled={processing} className={fieldClass} />{errors.title && <span className="mt-1 block text-xs font-normal text-rose-600">{message(errors.title)}</span>}</label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label className="block text-xs font-semibold text-gray-600">Type<select name="category" value={data.category} onChange={(event) => setData("category", event.target.value)} disabled={processing} className={fieldClass}>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select>{errors.category && <span className="mt-1 block text-xs font-normal text-rose-600">{message(errors.category)}</span>}</label>
            <label className="block text-xs font-semibold text-gray-600">Priority<select name="priority" value={data.priority} onChange={(event) => setData("priority", event.target.value)} disabled={processing} className={fieldClass}>{priorities.map((priority) => <option key={priority} value={priority}>{priority[0].toUpperCase() + priority.slice(1)}</option>)}</select>{errors.priority && <span className="mt-1 block text-xs font-normal text-rose-600">{message(errors.priority)}</span>}</label>
        </div>
        <label className="block text-xs font-semibold text-gray-600">Details<textarea name="description" value={data.description} onChange={(event) => setData("description", event.target.value)} maxLength={5000} rows={5} disabled={processing} className={fieldClass} />{errors.description && <span className="mt-1 block text-xs font-normal text-rose-600">{message(errors.description)}</span>}</label>
        <div className="space-y-2"><p className="text-xs text-gray-500">{existingCount} existing {existingCount === 1 ? "file" : "files"} · {remaining} additional {remaining === 1 ? "file" : "files"} allowed (10 total).</p><TicketAttachmentPicker files={data.attachments} onChange={chooseFiles} disabled={processing || remaining === 0} errors={errors} />{selectionError && <p role="alert" className="text-xs text-rose-700">{selectionError}</p>}</div>
        {processing && <p role="status" className="text-xs text-gray-600">{progress ? `Uploading… ${Math.round(progress.percentage ?? 0)}%` : "Saving request…"}</p>}
        <div className="flex flex-wrap justify-end gap-2"><button type="button" disabled={processing} onClick={onCancel} className="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-50">Cancel</button><button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black disabled:opacity-50"><Save size={14} />{processing ? "Saving…" : "Save request"}</button></div>
    </form>;
}
