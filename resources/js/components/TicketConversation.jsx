import { useEffect, useRef, useState } from "react";
import axios from "axios";
import TicketAttachmentPicker from "./TicketAttachmentPicker";
import TicketAttachments from "./TicketAttachments";

export default function TicketConversation({ ticket }) {
    const [messages, setMessages] = useState([]);
    const [next, setNext] = useState(null);
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [body, setBody] = useState("");
    const [error, setError] = useState("");
    const [files, setFiles] = useState([]);
    const [uploadProgress, setUploadProgress] = useState(null);
    const sendingRef = useRef(false);
    async function load(url = `/system-tickets/${ticket.id}/conversation`, append = false) {
        setLoading(true); setError("");
        try {
            const { data } = await axios.get(url);
            const page = data.messages ?? data;
            setMessages((old) => append ? [...(page.data ?? []), ...old] : (page.data ?? []));
            setNext(page.next_page_url ?? null);
        } catch { setError("Conversation could not be loaded. Please try again."); }
        finally { setLoading(false); }
    }
    useEffect(() => { load(); }, [ticket.id]);
    async function send(e) {
        e.preventDefault();
        if (sendingRef.current || ticket.archived_at || (!body.trim() && !files.length)) return;
        sendingRef.current = true;
        setSending(true); setError(""); setUploadProgress(null);
        const payload = new FormData();
        payload.append("body", body);
        files.forEach((file) => payload.append("attachments[]", file));
        try {
            await axios.post(`/system-tickets/${ticket.id}/messages`, payload, {
                onUploadProgress: ({ loaded, total }) => { if (total) setUploadProgress(Math.round(loaded / total * 100)); },
            });
            setBody(""); setFiles([]); await load();
        } catch (err) {
            const validation = Object.values(err.response?.data?.errors ?? {}).flat();
            setError(validation.join(" ") || err.response?.data?.message || "Reply could not be sent.");
        } finally { sendingRef.current = false; setSending(false); setUploadProgress(null); }
    }
    return <section className="space-y-3" aria-label="Ticket conversation">
        <h3 className="font-semibold text-gray-900">Conversation</h3>
        {error && <div role="alert" className="text-sm text-rose-700">{error} <button type="button" className="underline" onClick={() => load()}>Reload</button></div>}
        <div className="space-y-3" aria-live="polite">
            {messages.map((message) => <article key={message.id} className="rounded-xl border border-gray-200 bg-gray-50 p-3">
                <div className="flex flex-wrap justify-between gap-2 text-xs text-gray-500"><strong>{message.user?.name ?? "Former user"}</strong><time>{message.created_at ? new Date(message.created_at).toLocaleString() : ""}</time></div>
                {message.body && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-gray-800">{message.body}</p>}
                <TicketAttachments attachments={message.attachments} />
            </article>)}
            {!loading && !messages.length && <p className="text-sm text-gray-500">No messages yet.</p>}
            {loading && <p className="text-sm text-gray-500">Loading conversation…</p>}
        </div>
        {next && <button disabled={loading} onClick={() => load(next, true)} className="text-sm underline">Load earlier messages</button>}
        {ticket.archived_at ? <p className="text-sm text-gray-500">This archived ticket is read-only.</p> : <form onSubmit={send} className="space-y-2">
            <label className="block text-sm font-medium">Reply<textarea value={body} onChange={(e) => setBody(e.target.value)} disabled={sending} maxLength={10000} rows={3} className="mt-1 w-full rounded-lg border border-gray-300 p-3 font-normal" /></label>
            <TicketAttachmentPicker files={files} onChange={setFiles} disabled={sending} />
            {sending && <p role="status" className="text-xs text-gray-600">{uploadProgress === null ? "Uploading reply…" : `Uploading… ${uploadProgress}%`}</p>}
            <button disabled={sending || (!body.trim() && !files.length)} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{sending ? "Sending…" : "Send reply"}</button>
        </form>}
    </section>;
}
