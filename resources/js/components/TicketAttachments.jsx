import { attachmentKind, attachmentSize } from "./TicketAttachmentPicker";

export default function TicketAttachments({ attachments = [] }) {
    if (!attachments?.length) return null;
    return <ul aria-label="Attachments" className="mt-2 space-y-2">
        {attachments.map((file, index) => <li key={`${file.download_url}-${index}`} className="min-w-0 rounded-lg border border-gray-200 bg-white p-2">
            {attachmentKind(file) === "image" && <a href={file.url} target="_blank" rel="noopener noreferrer"><img src={file.url} alt={file.name} loading="lazy" className="mb-2 max-h-48 max-w-full rounded object-contain" /></a>}
            {attachmentKind(file) === "video" && <><video src={file.url} controls preload="metadata" aria-label={file.name} className="mb-2 w-full max-h-64 max-w-full rounded" /><p className="mb-1 text-xs text-gray-500">If this video does not play, download it to watch.</p></>}
            <a href={file.download_url} download={file.name} className="break-words text-xs font-medium text-blue-700 underline">Download {file.name}</a>
            <span className="ml-2 text-[11px] text-gray-500">{attachmentSize(file.size)}</span>
        </li>)}
    </ul>;
}
