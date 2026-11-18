import { useState, useEffect, useRef, useCallback } from "react";
import { Head, Link } from "@inertiajs/react";
import { Sparkles, ArrowLeft, Plus, Send, Trash2, Loader2, ShieldCheck, MessageSquarePlus, History } from "lucide-react";
import { aiClient } from "@/lib/aiClient";

const fmtTime = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "2-digit", month: "short" }) : "");

/**
 * The single, dedicated AI Assistant page. General by default; when the server
 * passes a `subject` it scopes to that record (grounded answers + immigration
 * guardrail, enforced server-side). Reused by the full page and the global side panel.
 */
export default function Assistant({ subject = null, aiEnabled = true, backUrl = "/", embedded = false, onConversationChange, onBusyChange }) {
    const scoped = !!subject;
    const [historyOpen, setHistoryOpen] = useState(false);
    const [error, setError] = useState("");

    const [conversations, setConversations] = useState([]);
    const [activeId, setActiveId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState("");
    const [sending, setSending] = useState(false);
    const endRef = useRef(null);

    useEffect(() => { endRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages, sending]);

    const loadConversation = useCallback(async (id) => {
        try {
            const { ok, data } = await aiClient.getConversation(id);
            if (!ok) { setError("This conversation is unavailable."); return; }
            setActiveId(id);
            setMessages(data.messages ?? []);
            setHistoryOpen(false);
            setError("");
        } catch { setError("Could not load the conversation. Please try again."); }
    }, []);

    useEffect(() => { onBusyChange?.(sending || !!input.trim()); }, [sending, input, onBusyChange]);
    useEffect(() => { onConversationChange?.(activeId); }, [activeId, onConversationChange]);
    useEffect(() => {
        if (!embedded && aiEnabled) {
            const id = new URLSearchParams(window.location.search).get("conversation_id");
            if (id && /^\d+$/.test(id)) loadConversation(Number(id));
        }
    }, [embedded, aiEnabled, loadConversation]);

    // Load the thread list. In record mode we always start a fresh scoped
    // thread rather than resuming a general one.
    useEffect(() => {
        if (!aiEnabled) return;
        (async () => {
            try {
                const { ok, data } = await aiClient.listConversations();
                if (ok) setConversations(data.conversations ?? []);
                else setError("Conversation history is unavailable.");
            } catch { setError("Could not load conversation history."); }
        })();
    }, [aiEnabled]);

    const newChat = () => { if (sending) return; setActiveId(null); setMessages([]); setInput(""); setError(""); };

    const send = async (text) => {
        const q = (text ?? input).trim();
        if (!q || sending) return;
        setInput("");
        setMessages((m) => [...m, { id: `tmp-${Date.now()}`, role: "user", content: q }]);
        setSending(true);
        try {
            const { ok, data } = await aiClient.sendMessage(q, activeId, scoped ? { type: subject.type, id: subject.id } : null);
            if (!ok) {
                setMessages((m) => [...m, { id: `err-${Date.now()}`, role: "assistant", content: data.error || "The assistant is unavailable right now. Please try again." }]);
                return;
            }
            const convo = data.conversation;
            if (convo && convo.id !== activeId) setActiveId(convo.id);
            if (convo) {
                setConversations((list) => [
                    { id: convo.id, title: convo.title, last_message_at: convo.last_message_at },
                    ...list.filter((c) => c.id !== convo.id),
                ]);
            }
            if (data.message) setMessages((m) => [...m, data.message]);
        } catch {
            setInput(q);
            setError("Connection interrupted. Your message is restored below; check history before retrying.");
        } finally {
            setSending(false);
        }
    };

    const archive = async (id, e) => {
        e.stopPropagation();
        if (sending) return;
        try {
            const { ok } = await aiClient.archiveConversation(id);
            if (!ok) { setError("Could not archive the conversation."); return; }
        } catch { setError("Could not archive the conversation."); return; }
        setConversations((list) => list.filter((c) => c.id !== id));
        if (id === activeId) newChat();
    };

    const presets = scoped
        ? ["Summarise this record", "What should I do next?", "What's outstanding?"]
        : ["Draft a follow-up email", "How do I convert a lead?", "Summarise my day"];

    return (
        <div className="h-full min-h-0 bg-gray-50 flex flex-col font-urbanist rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            {!embedded && <Head title="AI Assistant — EP" />}

            {/* Top bar */}
            <header className="bg-white border-b border-gray-100 px-4 sm:px-6 py-3 flex items-center gap-3">
                {!embedded && <Link href={backUrl} className="p-2 -ml-2 rounded-lg text-gray-500 hover:bg-gray-100" title="Back">
                    <ArrowLeft size={18} />
                </Link>}
                <span className="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-900 text-white">
                    <Sparkles size={17} className="text-amber-300" />
                </span>
                <div className="min-w-0 flex-1">
                    {!embedded && <h1 className="text-sm font-bold text-gray-900 leading-tight">EP Assistant</h1>}
                    {scoped ? (
                        <p className="text-[11px] text-gray-500 leading-tight truncate">About: {subject.label}</p>
                    ) : (
                        <p className="text-[11px] text-gray-400 leading-tight">Your work assistant · always review before acting</p>
                    )}
                </div>
                <button type="button" aria-label="Conversation history" aria-expanded={historyOpen} onClick={() => setHistoryOpen(!historyOpen)} className="p-2 rounded-lg hover:bg-gray-100"><History size={17} /></button>
                <button disabled={sending} onClick={newChat} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-600 border border-gray-200 hover:bg-gray-50">
                    <Plus size={14} /> New chat
                </button>
            </header>

            {error && <p role="alert" className="px-4 py-2 text-xs text-rose-700 bg-rose-50">{error}</p>}
            {!aiEnabled ? (
                <div className="flex-1 flex items-center justify-center p-8 text-sm text-gray-500">
                    The AI assistant is currently turned off.
                </div>
            ) : (
                <div className="flex-1 flex min-h-0 max-w-6xl w-full mx-auto">
                    {/* History sidebar */}
                    <aside className={`${embedded ? (historyOpen ? "flex flex-col w-44" : "hidden") : (historyOpen ? "flex flex-col w-52" : "hidden md:flex md:flex-col w-64")} shrink-0 border-r border-gray-100 bg-white/60`}>
                        <div className="px-4 py-3 text-[11px] font-bold uppercase tracking-[0.18em] text-gray-400">Conversations</div>
                        <div className="flex-1 overflow-y-auto">
                            {conversations.length === 0 ? (
                                <p className="px-4 py-4 text-xs text-gray-400">No previous conversations.</p>
                            ) : conversations.map((c) => (
                                <button key={c.id} disabled={sending} onClick={() => loadConversation(c.id)}
                                    className={`group w-full flex items-center gap-2 px-4 py-2.5 text-left hover:bg-white ${c.id === activeId ? "bg-white" : ""}`}>
                                    <span className="flex-1 min-w-0 truncate text-sm text-gray-700">{c.title || "Untitled chat"}</span>
                                    <span className="text-[10px] text-gray-400 shrink-0">{fmtTime(c.last_message_at)}</span>
                                    <span onClick={(e) => archive(c.id, e)} className="opacity-0 group-hover:opacity-100 p-1 rounded text-gray-400 hover:text-rose-600" title="Archive">
                                        <Trash2 size={13} />
                                    </span>
                                </button>
                            ))}
                        </div>
                    </aside>

                    {/* Chat column */}
                    <main className="flex-1 flex flex-col min-w-0 bg-white">
                        {/* Compliance note when scoped to a record */}
                        {scoped && (
                            <div className="px-4 sm:px-6 py-2 bg-gray-50 border-b border-gray-100 flex items-start gap-2">
                                <ShieldCheck size={13} className="text-gray-400 mt-0.5 shrink-0" />
                                <p className="text-[11px] text-gray-500 leading-snug">
                                    Read-only — answers only from this record and can't change anything.
                                    {subject.immigration && " This is an immigration case: status and process only, never advice."}
                                </p>
                            </div>
                        )}

                        <div className="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-3">
                            {messages.length === 0 && !sending ? (
                                <div className="h-full flex flex-col items-center justify-center text-center px-6">
                                    <MessageSquarePlus className="w-8 h-8 text-gray-300 mb-3" />
                                    <p className="text-sm font-medium text-gray-900">{scoped ? `Ask about ${subject.label}` : "How can I help?"}</p>
                                    <p className="text-xs text-gray-500 mt-1 max-w-sm">
                                        {scoped
                                            ? "I can summarise this record, list what's outstanding, and answer questions — grounded in the file."
                                            : "Ask me to draft a message, summarise your work, or answer a question."}
                                    </p>
                                </div>
                            ) : messages.map((m) => (
                                <div key={m.id} className={`flex ${m.role === "user" ? "justify-end" : "justify-start"}`}>
                                    <div className={`max-w-[80%] px-4 py-2.5 rounded-2xl text-sm whitespace-pre-wrap break-words ${m.role === "user" ? "bg-gray-900 text-white rounded-br-sm" : "bg-gray-100 text-gray-800 rounded-bl-sm"}`}>
                                        {m.content}
                                    </div>
                                </div>
                            ))}
                            {sending && (
                                <div className="flex justify-start">
                                    <div className="px-4 py-3 rounded-2xl bg-gray-100 text-gray-400 rounded-bl-sm inline-flex items-center gap-2">
                                        <Loader2 size={15} className="animate-spin" /> Thinking…
                                    </div>
                                </div>
                            )}
                            <div ref={endRef} />
                        </div>

                        {messages.length === 0 && (
                            <div className="px-4 sm:px-6 pb-2 flex flex-wrap gap-1.5">
                                {presets.map((p) => (
                                    <button key={p} onClick={() => send(p)} disabled={sending}
                                        className="text-[11px] px-3 py-1.5 rounded-full border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50">
                                        {p}
                                    </button>
                                ))}
                            </div>
                        )}

                        <form onSubmit={(e) => { e.preventDefault(); send(); }} className="border-t border-gray-100 p-3 sm:p-4">
                            <div className="flex items-end gap-2 rounded-2xl bg-gray-100 px-3 py-2 max-w-3xl mx-auto">
                                <textarea
                                    aria-label="Message EP Assistant"
                                    value={input}
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); } }}
                                    rows={1}
                                    placeholder={scoped ? "Ask about this record…" : "Message the assistant…"}
                                    className="min-w-0 flex-1 resize-none max-h-40 text-sm bg-transparent outline-none focus:ring-0"
                                />
                                <button type="submit" disabled={!input.trim() || sending}
                                    className="shrink-0 p-2 rounded-xl bg-gray-900 text-white disabled:opacity-40 hover:bg-gray-800" title="Send">
                                    <Send size={16} />
                                </button>
                            </div>
                        </form>
                    </main>
                </div>
            )}
        </div>
    );
}
