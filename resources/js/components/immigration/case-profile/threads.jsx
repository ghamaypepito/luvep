import { useState } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import { MessageSquare, CheckCircle2, CornerDownRight } from "lucide-react";
import { immBase } from "@/lib/caseRoutes";

// Build 12 phase 6 — shared UI for anchored threads (§7). Reused by the Notes,
// Documents and Process tabs so a thread looks and behaves the same wherever its
// anchor puts it. Placement is the caller's job (it filters by anchor); this
// file only renders and writes.

const fmt = (iso) =>
    iso ? new Date(iso).toLocaleString("en-NZ", { day: "numeric", month: "short", hour: "numeric", minute: "2-digit", hour12: true }) : "";

// ── Avatar helpers ──────────────────────────────────────────────────────
// A deterministic colour per author so the same person keeps the same tile.
const AVATAR_COLORS = [
    "bg-violet-500", "bg-emerald-500", "bg-blue-500", "bg-amber-500",
    "bg-rose-500", "bg-teal-500", "bg-indigo-500", "bg-fuchsia-500",
];
const avatarColor = (name = "") => {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return AVATAR_COLORS[h % AVATAR_COLORS.length];
};
const initials = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase() || "?";

function Avatar({ name, size = 28 }) {
    return (
        <span
            className={`inline-flex items-center justify-center rounded-full text-white font-bold flex-shrink-0 ${avatarColor(name)}`}
            style={{ width: size, height: size, fontSize: size * 0.36 }}
        >
            {initials(name)}
        </span>
    );
}

// `basePath` lets non-immigration surfaces reuse these threads: the immigration
// Case Profile posts to /portal/immigration/cases/{lead}/threads (the default),
// while the general lead-profile Documents tab passes /admin/leads.
export function resolveThread(leadId, threadId, basePath = `${immBase()}/cases`) {
    router.post(`${basePath}/${leadId}/threads/${threadId}/resolve`, {}, {
        preserveScroll: true,
        onSuccess: () => toast.success("Thread resolved"),
        onError: (e) => toast.error(Object.values(e)[0] || "Could not resolve"),
    });
}

// Stable textarea identity for document composers.
const composerIdFor = (anchor) =>
    anchor && anchor.anchor_type === "document" && (anchor.anchor_id || anchor.anchor_key)
        ? `thread-composer-document-${anchor.anchor_id || anchor.anchor_key}`
        : null;

// The card + badge tone for a thread: amber when it's an open question awaiting
// an answer, green when it's shared with (or came from) the client, lavender for
// an internal note. Replies always render as plain white sub-cards.
function toneFor(thread) {
    const fromClient = thread.author_role === "lead";
    const awaiting = ! thread.resolved_at && thread.requires_answer;
    if (awaiting) {
        return { card: "bg-amber-50/70 border-amber-200", badge: "bg-amber-100 text-amber-700", label: "Awaiting answer" };
    }
    if (fromClient) {
        return { card: "bg-emerald-50/70 border-emerald-200", badge: "bg-emerald-100 text-emerald-700", label: "From client" };
    }
    if (thread.client_visible) {
        return { card: "bg-emerald-50/70 border-emerald-200", badge: "bg-emerald-100 text-emerald-700", label: "Client sees this" };
    }
    return { card: "bg-violet-50/60 border-violet-100", badge: "bg-violet-100 text-violet-700", label: "Internal" };
}

/** One thread: body, who wrote it, its audience, and an explicit resolve.
 * Replies use the thread's persisted anchor unless its caller supplies one.
 * `childrenOf` renders nested replies; `isReply` uses a plain white sub-card. */
export function ThreadItem({ thread, leadId, anchorLabel = null, childrenOf = null, anchor = null, caseStaff = [], basePath = `${immBase()}/cases`, isReply = false }) {
    const open = ! thread.resolved_at;
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(thread.body || "");
    const [saving, setSaving] = useState(false);
    const [replyOpen, setReplyOpen] = useState(false);
    const replies = childrenOf ? childrenOf(thread.id) : [];
    const replyAnchor = anchor || {
        anchor_type: thread.anchor_type,
        anchor_id: thread.anchor_id ?? null,
        anchor_key: thread.anchor_key ?? null,
        anchor_attempt: thread.anchor_attempt ?? null,
    };
    const tone = toneFor(thread);

    const saveEdit = () => {
        if (! draft.trim()) return toast.error("Comment can't be empty");
        setSaving(true);
        router.patch(`${basePath}/${leadId}/threads/${thread.id}`, { body: draft }, {
            preserveScroll: true,
            onSuccess: () => { toast.success("Comment updated"); setEditing(false); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not update"),
            onFinish: () => setSaving(false),
        });
    };

    const cardClass = isReply
        ? "rounded-xl border border-gray-200 bg-white px-3.5 py-3"
        : `rounded-xl border px-3.5 py-3 ${tone.card}`;

    return (
        <div>
            <div className={cardClass}>
                {/* Header — avatar, name, time, and the audience badge */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2 min-w-0">
                        <Avatar name={thread.author || "?"} size={isReply ? 24 : 28} />
                        <span className="text-[13px] font-bold text-gray-900 truncate">{thread.author || "Unknown"}</span>
                        <span className="text-[11.5px] text-gray-400 flex-shrink-0">{fmt(thread.created_at)}</span>
                    </div>
                    {! isReply && (
                        open ? (
                            <span className={`text-[9.5px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full flex-shrink-0 ${tone.badge}`}>
                                {tone.label}
                            </span>
                        ) : (
                            <span className="text-[9.5px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 flex-shrink-0">
                                Answered
                            </span>
                        )
                    )}
                </div>

                {/* Body / edit */}
                {editing ? (
                    <div className="mt-2 space-y-2">
                        <textarea
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            rows={3}
                            maxLength={2000}
                            autoFocus
                            className="w-full text-[13px] px-2.5 py-1.5 bg-white border border-gray-300 rounded-lg focus:outline-none focus:border-gray-500 resize-none"
                        />
                        <div className="flex items-center gap-1.5">
                            <button type="button" onClick={saveEdit} disabled={saving}
                                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-gray-900 text-white text-[10.5px] font-semibold hover:bg-black disabled:opacity-50">
                                <CheckCircle2 size={11} /> Save
                            </button>
                            <button type="button" onClick={() => { setEditing(false); setDraft(thread.body || ""); }}
                                className="text-[10.5px] font-semibold text-gray-400 hover:text-gray-700">Cancel</button>
                        </div>
                    </div>
                ) : (
                    <p className="mt-1.5 text-[13px] leading-relaxed whitespace-pre-wrap text-slate-700">{thread.body}</p>
                )}

                {/* Footer — reply/edit, mark-answered, reply count */}
                {! editing && (
                    <div className="mt-2.5 flex items-center justify-between gap-3">
                        <div className="flex items-center gap-3 text-[12px] font-semibold text-gray-500">
                            <button
                                type="button"
                                onClick={() => setReplyOpen((v) => ! v)}
                                className={replyOpen ? "text-gray-900" : "hover:text-gray-900"}
                            >
                                Reply
                            </button>
                            {thread.can_edit && (
                                <button type="button" onClick={() => { setDraft(thread.body || ""); setEditing(true); }} className="text-gray-400 hover:text-gray-900">Edit</button>
                            )}
                            {open && thread.requires_answer && (
                                <button type="button" onClick={() => resolveThread(leadId, thread.id, basePath)} className="text-gray-400 hover:text-emerald-600">
                                    Mark answered
                                </button>
                            )}
                        </div>
                        <span className="text-[11px] text-gray-400 flex-shrink-0">
                            {! open
                                ? `Answered${thread.resolved_by ? ` by ${thread.resolved_by}` : ""} · ${fmt(thread.resolved_at)}`
                                : (replies.length > 0
                                    ? `${replies.length} repl${replies.length === 1 ? "y" : "ies"}`
                                    : (thread.addressed_to ? `For ${thread.addressed_to.name}` : ""))}
                        </span>
                    </div>
                )}
            </div>

            {replyOpen && (
                <div className="mt-2 ml-6">
                    <ReplyComposer
                        leadId={leadId}
                        anchor={replyAnchor}
                        parentId={thread.id}
                        clientVisible={thread.client_visible}
                        basePath={basePath}
                        onDone={() => setReplyOpen(false)}
                    />
                </div>
            )}

            {replies.length > 0 && (
                <div className="mt-2 ml-6 space-y-2">
                    {replies.map((c) => (
                        <ThreadItem
                            key={c.id}
                            thread={c}
                            leadId={leadId}
                            anchor={replyAnchor}
                            caseStaff={caseStaff}
                            childrenOf={childrenOf}
                            basePath={basePath}
                            isReply
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

/** A compact inline reply box, posting a child comment on the same anchor. */
function ReplyComposer({ leadId, anchor, parentId, clientVisible, onDone, basePath = `${immBase()}/cases` }) {
    const [body, setBody] = useState("");
    const [posting, setPosting] = useState(false);

    const submit = () => {
        if (! body.trim()) return toast.error("Write a reply first");
        setPosting(true);
        router.post(`${basePath}/${leadId}/threads`, {
            ...anchor,
            parent_id: parentId,
            body,
            client_visible: !! clientVisible,
        }, {
            preserveScroll: true,
            onSuccess: () => { toast.success("Reply added"); setBody(""); onDone?.(); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not reply"),
            onFinish: () => setPosting(false),
        });
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-2.5 space-y-2">
            <div className="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400">
                <CornerDownRight size={12} /> Reply
            </div>
            <textarea
                autoFocus
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={2}
                maxLength={2000}
                placeholder="Write a reply…"
                className="w-full text-[13px] px-2.5 py-1.5 bg-white border border-gray-200 rounded-lg focus:outline-none focus:border-gray-400 resize-none"
            />
            <div className="flex items-center justify-end gap-2">
                <button type="button" onClick={onDone}
                    className="text-[12px] font-semibold text-gray-400 hover:text-gray-700">Cancel</button>
                <button type="button" onClick={submit} disabled={posting}
                    className="inline-flex items-center px-3 py-1.5 rounded-lg bg-violet-600 text-white text-[12px] font-semibold hover:bg-violet-700 disabled:opacity-50">Post reply</button>
            </div>
        </div>
    );
}

/**
 * Composer. `fixedAnchor` = {anchor_type, anchor_id?, anchor_key?, anchor_attempt?}
 * pins the anchor (document / step rows). Omit it and the composer offers a
 * general anchor picker (Case / Gate / Stage) for the Notes tab. `stages` is an
 * optional list for the Stage picker.
 */
export function ThreadComposer({ leadId, caseStaff = [], fixedAnchor = null, stages = [], compact = false, plain = false, placeholder = "Write a note for the team…", basePath = `${immBase()}/cases` }) {
    const [open, setOpen] = useState(! compact);
    const [body, setBody] = useState("");
    const [addressedTo, setAddressedTo] = useState("");
    const [requires, setRequires] = useState(false);
    // Audience: false = internal only (default), true = shared with the client.
    const [clientVisible, setClientVisible] = useState(false);
    const [anchorType, setAnchorType] = useState("case");
    const [anchorKey, setAnchorKey] = useState("");
    const [posting, setPosting] = useState(false);

    const composerId = composerIdFor(fixedAnchor);

    const submit = () => {
        if (! body.trim()) return toast.error(plain ? "Write a note first" : "Write the question first");
        const anchor = fixedAnchor || { anchor_type: anchorType, anchor_key: anchorType === "case" ? null : anchorKey };
        if (! fixedAnchor && anchorType !== "case" && ! anchorKey.trim()) {
            return toast.error(anchorType === "gate" ? "Name the gate (e.g. 06)" : "Name the stage");
        }
        setPosting(true);
        router.post(`${basePath}/${leadId}/threads`, {
            ...anchor,
            body,
            addressed_to_id: addressedTo || null,
            requires_answer: requires,
            client_visible: clientVisible,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(clientVisible ? "Shared with the client" : (plain ? "Note added" : "Thread posted"));
                setBody(""); setRequires(false); setAddressedTo(""); setAnchorKey(""); setClientVisible(false);
                if (compact) setOpen(false);
            },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not post"),
            onFinish: () => setPosting(false),
        });
    };

    if (compact && ! open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-violet-600 hover:text-violet-800"
            >
                <MessageSquare size={13} /> {plain ? "Add a note" : "Ask a question"}
            </button>
        );
    }

    // Audience toggle — two pills. Active pill is a white chip with a coloured
    // dot; the subtitle spells out who can see it.
    const Pill = ({ active, dot, onClick, children }) => (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11.5px] font-semibold transition-colors ${
                active ? "bg-white border border-violet-200 text-violet-800 shadow-sm" : "text-gray-400 hover:text-gray-600"
            }`}
        >
            <span className={`w-1.5 h-1.5 rounded-full ${active ? dot : "bg-gray-300"}`} />
            {children}
        </button>
    );

    const audienceRow = (
        <div className="flex items-center gap-1.5 flex-wrap">
            <Pill active={! clientVisible} dot="bg-violet-500" onClick={() => setClientVisible(false)}>Internal only</Pill>
            <Pill active={clientVisible} dot="bg-emerald-500" onClick={() => setClientVisible(true)}>Client sees this</Pill>
            <span className="text-[11px] text-gray-400 ml-0.5">
                {clientVisible ? "Appears in the client portal and the reminder email." : "Staff only — never shown to the client."}
            </span>
        </div>
    );

    return (
        <div className="rounded-2xl border border-violet-100 bg-violet-50/50 p-3 space-y-2.5">
            {! fixedAnchor && (
                <div className="flex items-center gap-2">
                    <select
                        value={anchorType}
                        onChange={(e) => setAnchorType(e.target.value)}
                        className="text-[11px] font-semibold px-2 py-1.5 bg-white border border-gray-200 rounded-lg focus:outline-none"
                    >
                        <option value="case">On the case</option>
                        <option value="gate">On a gate</option>
                        <option value="stage">On a stage</option>
                    </select>
                    {anchorType === "gate" && (
                        <input
                            value={anchorKey}
                            onChange={(e) => setAnchorKey(e.target.value)}
                            placeholder="Gate (e.g. 06)"
                            className="text-[11px] px-2 py-1.5 bg-white border border-gray-200 rounded-lg focus:outline-none w-28"
                        />
                    )}
                    {anchorType === "stage" && (
                        stages.length > 0 ? (
                            <select
                                value={anchorKey}
                                onChange={(e) => setAnchorKey(e.target.value)}
                                className="text-[11px] px-2 py-1.5 bg-white border border-gray-200 rounded-lg focus:outline-none"
                            >
                                <option value="">Choose a stage…</option>
                                {stages.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        ) : (
                            <input
                                value={anchorKey}
                                onChange={(e) => setAnchorKey(e.target.value)}
                                placeholder="Stage"
                                className="text-[11px] px-2 py-1.5 bg-white border border-gray-200 rounded-lg focus:outline-none"
                            />
                        )
                    )}
                </div>
            )}

            {audienceRow}

            <textarea
                id={composerId || undefined}
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={compact ? 2 : 3}
                maxLength={2000}
                placeholder={placeholder}
                className="w-full text-[13px] px-3 py-2 bg-white border border-gray-200 rounded-xl focus:outline-none focus:border-violet-300 resize-none placeholder-gray-400"
            />

            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-3">
                    <label className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-500 cursor-pointer">
                        <input type="checkbox" checked={requires} onChange={(e) => setRequires(e.target.checked)}
                            className="rounded border-gray-300 text-amber-600 focus:ring-0 w-3.5 h-3.5" />
                        Needs an answer
                    </label>
                    {! plain && (
                        <select
                            value={addressedTo}
                            onChange={(e) => setAddressedTo(e.target.value)}
                            className="text-[11px] px-2 py-1.5 bg-white border border-gray-200 rounded-lg focus:outline-none max-w-[160px]"
                        >
                            <option value="">To (anyone)</option>
                            {caseStaff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                    )}
                </div>
                <div className="flex items-center gap-3">
                    {compact && (
                        <button type="button" onClick={() => setOpen(false)} className="text-[12px] font-semibold text-gray-400 hover:text-gray-700">Cancel</button>
                    )}
                    <button type="button" onClick={submit} disabled={posting}
                        className="inline-flex items-center px-4 py-1.5 rounded-lg bg-violet-600 text-white text-[12px] font-semibold hover:bg-violet-700 disabled:opacity-50">
                        {plain ? "Post note" : "Post"}
                    </button>
                </div>
            </div>
        </div>
    );
}
