import { useEffect, useId, useMemo, useState } from "react";
import { Paperclip, X } from "lucide-react";

export const TICKET_ATTACHMENT_ACCEPT = ".png,.jpg,.jpeg,.webp,.gif,.mp4,.webm,.mov,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip";
const extensions = new Set(TICKET_ATTACHMENT_ACCEPT.split(",").map((extension) => extension.slice(1)));
const MAX_FILES = 10;
const MAX_SIZE = 50 * 1024 * 1024;

export function attachmentSize(size) {
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    return `${Math.max(1, Math.ceil((size || 0) / 1024))} KB`;
}

export function attachmentKind(file) {
    const mime = file.mime || file.type || "";
    if (/^image\//.test(mime)) return "image";
    if (/^video\//.test(mime)) return "video";
    if (mime) return "file";
    const extension = file.name?.split(".").pop()?.toLowerCase();
    if (["png", "jpg", "jpeg", "webp", "gif"].includes(extension)) return "image";
    if (["mp4", "webm", "mov"].includes(extension)) return "video";
    return "file";
}

export default function TicketAttachmentPicker({ files, onChange, disabled = false, errors = {} }) {
    const id = useId();
    const [error, setError] = useState("");
    const previews = useMemo(() => files.map((file) => attachmentKind(file) === "file" ? null : URL.createObjectURL(file)), [files]);
    useEffect(() => () => previews.forEach((url) => url && URL.revokeObjectURL(url)), [previews]);
    const serverErrors = Object.entries(errors).filter(([key]) => key === "attachments" || key.startsWith("attachments.")).flatMap(([, value]) => Array.isArray(value) ? value : [value]);
    const select = (event) => {
        const incoming = Array.from(event.target.files || []);
        event.target.value = "";
        if (!incoming.length) return;
        const invalid = incoming.find((file) => !extensions.has(file.name.split(".").pop().toLowerCase()) || /(?:svg|html|javascript|x-executable|x-msdownload)/i.test(file.type));
        if (invalid) { setError(`${invalid.name}: this file type is not supported. Choose an image, video, document or ZIP file.`); return; }
        const large = incoming.find((file) => file.size > MAX_SIZE);
        if (large) { setError(`${large.name} exceeds the 50 MB per-file limit.`); return; }
        if (files.length + incoming.length > MAX_FILES) { setError(`You can attach up to ${MAX_FILES} files. Remove a file or choose fewer files.`); return; }
        setError(""); onChange([...files, ...incoming]);
    };
    return <div className="space-y-2">
        <label htmlFor={id} className="block text-xs font-semibold text-gray-600">Attachments <span className="font-normal text-gray-400">— optional</span></label>
        <p id={`${id}-help`} className="text-xs text-gray-500">Screenshots, videos, documents or ZIP files. Up to 10 files, 50 MB each.</p>
        <label className={`inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 ${disabled ? "opacity-50" : "cursor-pointer hover:bg-gray-50"}`}>
            <Paperclip size={14} /> Add files
            <input id={id} type="file" accept={TICKET_ATTACHMENT_ACCEPT} multiple disabled={disabled} aria-describedby={`${id}-help`} className="sr-only" onChange={select} />
        </label>
        {files.length > 0 && <ul className="space-y-2" aria-label="Selected attachments">
            {files.map((file, index) => <li key={`${file.name}-${index}`} className="rounded-lg border border-gray-200 p-2">
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1"><p className="break-words text-xs font-medium text-gray-700">{file.name}</p><p className="text-[11px] text-gray-500">{attachmentSize(file.size)}</p></div>
                    <button type="button" disabled={disabled} aria-label={`Remove ${file.name}`} onClick={() => { setError(""); onChange(files.filter((_, i) => i !== index)); }} className="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-50"><X size={14} /></button>
                </div>
                {attachmentKind(file) === "image" && <img src={previews[index]} alt={file.name} className="mt-2 h-20 max-w-full rounded object-contain" />}
                {attachmentKind(file) === "video" && <video src={previews[index]} controls preload="metadata" aria-label={file.name} className="mt-2 w-full max-h-40 max-w-full rounded" />}
            </li>)}
        </ul>}
        {(error || serverErrors.length > 0) && <div role="alert" className="text-xs text-rose-600">{error && <p>{error}</p>}{serverErrors.map((message, index) => <p key={index}>{message}</p>)}</div>}
    </div>;
}
