import { postTaskComment } from "@/lib/taskComments";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { MessageCircle, Send, X } from "lucide-react";
import Avatar from "@/components/ui/Avatar";

// Centered modal that lists a task's comments oldest-first and lets the
// viewer add a new one. Backed by:
//   GET  /api/tasks/{id}/comments
//   POST /api/tasks/{id}/comments  body=string
//
// Opened from the comments badge on a kanban card.

const fmtWhen = (iso) =>
    iso
        ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })
        : "";

const initialsOf = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase();

export default function CommentsPopover({ open, onClose, taskId, taskTitle, onCountChange }) {

    const [loading, setLoading]   = useState(false);
    const [comments, setComments] = useState([]);
    const [body, setBody]         = useState("");
    const [sending, setSending]   = useState(false);
    const [error, setError]       = useState(null);
    const listRef = useRef(null);

    useEffect(() => {
        if (! open || ! taskId) return;
        setLoading(true);
        setError(null);
        fetch(`/api/tasks/${taskId}/comments`, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        })
            .then((r) => r.ok ? r.json() : Promise.reject(r))
            .then((d) => setComments(d.comments || []))
            .catch(() => setError("Could not load comments."))
            .finally(() => setLoading(false));
    }, [open, taskId]);

    // Scroll to bottom whenever the comment list grows.
    useEffect(() => {
        if (listRef.current) {
            listRef.current.scrollTop = listRef.current.scrollHeight;
        }
    }, [comments]);

    if (! open) return null;

    const submit = async (e) => {
        e?.preventDefault();
        const text = body.trim();
        if (! text || sending) return;
        setSending(true);
        try {
            const data = await postTaskComment(taskId, text);
            setComments((prev) => {
                const next = [...prev, data.comment];
                onCountChange?.(next.length);
                return next;
            });
            setBody("");
        } catch (err) {
            toast.error("Could not add comment");
        } finally {
            setSending(false);
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6"
            onClick={(e) => {
                // Stop propagation so backdrop clicks never bubble up to
                // the underlying card / drag layer.
                e.stopPropagation();
                if (e.target === e.currentTarget) onClose?.();
            }}
        >
            <div
                className="bg-white w-full max-w-lg rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[80vh] overflow-hidden flex flex-col"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <div className="min-w-0">
                        <div className="inline-flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-500">
                            <MessageCircle size={12} />
                            Comments
                        </div>
                        <h3 className="text-sm font-semibold text-gray-900 truncate mt-0.5">{taskTitle}</h3>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-md hover:bg-gray-100 text-gray-500"
                        aria-label="Close comments"
                    >
                        <X size={16} />
                    </button>
                </div>

                {/* Comment list */}
                <div ref={listRef} className="flex-1 overflow-y-auto px-5 py-4 space-y-3">
                    {loading && <p className="text-[12px] text-gray-500 text-center py-6">Loading comments…</p>}
                    {error && <p className="text-[12px] text-red-600 text-center py-6">{error}</p>}
                    {! loading && ! error && comments.length === 0 && (
                        <p className="text-[12px] text-gray-500 text-center py-6">No comments yet. Be the first.</p>
                    )}
                    {comments.map((c) => (
                        <div key={c.id} className="flex items-start gap-2">
                            <Avatar
                                name={c.author?.name || ""}
                                src={c.author?.avatar_url}
                                colorKey={c.author?.id || c.author?.name}
                                size={28}
                                className="flex-shrink-0"
                            />
                            <div className="flex-1 min-w-0">
                                <div className="flex items-baseline gap-2">
                                    <span className="text-[12px] font-semibold text-gray-900">{c.author?.name || "Unknown"}</span>
                                    <span className="text-[10px] text-gray-400 tabular-nums">{fmtWhen(c.created_at)}</span>
                                </div>
                                <p className="text-[12.5px] text-gray-700 whitespace-pre-wrap mt-0.5">{c.body}</p>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Composer */}
                <form onSubmit={submit} className="px-5 py-3 border-t border-gray-100 bg-gray-50 flex items-end gap-2">
                    <textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) submit(e);
                        }}
                        rows={2}
                        placeholder="Add a comment… (⌘/Ctrl + Enter to send)"
                        className="flex-1 px-3 py-2 rounded-lg border border-gray-200 text-sm resize-none"
                    />
                    <button
                        type="submit"
                        disabled={! body.trim() || sending}
                        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-gray-900 text-white text-[11px] font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors disabled:opacity-40"
                    >
                        <Send size={11} />
                        {sending ? "…" : "Send"}
                    </button>
                </form>
            </div>
        </div>
    );
}
