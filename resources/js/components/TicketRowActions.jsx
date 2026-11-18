import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { router } from "@inertiajs/react";
import { Archive, Check, MoreHorizontal, X } from "lucide-react";

const actions = {
    declined: { label: "Decline", title: "Decline this ticket?", note: "The ticket will move to Declined and the sender will be notified in the portal.", icon: X },
    done: { label: "Done", title: "Mark this ticket Done?", note: "The ticket will appear only in Done. It will automatically move to Archive two months after completion. The sender will be notified in the portal.", icon: Check },
    archive: { label: "Archive", title: "Archive this ticket?", note: "The ticket will appear only in Archive and become read-only. Its files, conversation and resolution report will be kept.", icon: Archive },
};
const requiredReport = ["issue_summary", "root_cause", "resolution", "verification", "documentation_summary"];

export default function TicketRowActions({ ticket, onNeedsReport, onSaved }) {
    const trigger = useRef(null);
    const [position, setPosition] = useState(null);
    const [confirmation, setConfirmation] = useState(null);
    const choose = (action) => {
        setPosition(null);
        if (action === "done" && requiredReport.some((key) => !String(ticket.resolution_report?.[key] ?? "").trim())) {
            onNeedsReport(ticket);
        } else {
            setConfirmation(action);
        }
    };
    return <>
        <button ref={trigger} type="button" aria-label={`Actions for ${ticket.ticket_ref}`} aria-haspopup="menu" aria-expanded={!!position} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600" onClick={() => {
            if (position) { setPosition(null); return; }
            const rect = trigger.current.getBoundingClientRect();
            setPosition({ top: rect.bottom + 4, left: rect.right - 176 });
        }}><MoreHorizontal size={18} /></button>
        {position && <ActionMenu ticket={ticket} position={position} trigger={trigger} onClose={() => setPosition(null)} onChoose={choose} />}
        {confirmation && <ActionConfirmation ticket={ticket} action={confirmation} onClose={() => { setConfirmation(null); trigger.current?.focus(); }} onSaved={() => { setConfirmation(null); onSaved(); }} />}
    </>;
}

function ActionMenu({ ticket, position, trigger, onClose, onChoose }) {
    const menu = useRef(null);
    useEffect(() => {
        menu.current?.querySelector('button:not([disabled])')?.focus();
        const dismiss = (event) => {
            if (!menu.current?.contains(event.target) && !trigger.current?.contains(event.target)) onClose();
        };
        const close = () => onClose();
        document.addEventListener("mousedown", dismiss);
        window.addEventListener("resize", close);
        window.addEventListener("scroll", close, true);
        return () => {
            document.removeEventListener("mousedown", dismiss);
            window.removeEventListener("resize", close);
            window.removeEventListener("scroll", close, true);
            trigger.current?.focus();
        };
    }, []);
    const keyboard = (event) => {
        if (event.key === "Escape") { event.preventDefault(); onClose(); }
        if (event.key === "Tab") onClose();
        if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const buttons = [...menu.current.querySelectorAll('button:not([disabled])')];
        const current = buttons.indexOf(document.activeElement);
        const index = event.key === "Home" ? 0 : event.key === "End" ? buttons.length - 1 : (current + (event.key === "ArrowDown" ? 1 : -1) + buttons.length) % buttons.length;
        buttons[index]?.focus();
    };
    return createPortal(<div ref={menu} role="menu" aria-label={`Ticket actions for ${ticket.ticket_ref}`} onKeyDown={keyboard} className="fixed z-[60] w-44 rounded-xl border border-gray-200 bg-white p-1 shadow-lg" style={{ top: Math.max(8, Math.min(position.top, window.innerHeight - 148)), left: Math.max(8, Math.min(position.left, window.innerWidth - 184)) }}>
        {Object.entries(actions).map(([key, action]) => <button type="button" key={key} role="menuitem" disabled={ticket.status === key} onClick={() => onChoose(key)} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 focus:bg-gray-100 focus:outline-none disabled:opacity-40"><action.icon size={16} />{action.label}</button>)}
    </div>, document.body);
}

function ActionConfirmation({ ticket, action, onClose, onSaved }) {
    const dialog = useRef(null);
    const pending = useRef(false);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    useEffect(() => { dialog.current.showModal(); }, []);
    const submit = () => {
        if (pending.current) return;
        pending.current = true;
        setSaving(true); setErrors({});
        const archive = action === "archive";
        router.post(`/admin/system-tickets/${ticket.id}${archive ? "/archive" : ""}`, { ...(archive ? {} : { status: action }), expected_status: ticket.status }, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: onSaved,
            onFinish: () => { pending.current = false; setSaving(false); },
        });
    };
    return createPortal(<dialog ref={dialog} aria-labelledby={`ticket-action-${ticket.id}`} onCancel={(event) => { event.preventDefault(); if (!saving) onClose(); }} className="w-[calc(100%_-_2rem)] max-w-md rounded-2xl border-0 p-6 text-gray-900 shadow-xl backdrop:bg-black/40">
        <h2 id={`ticket-action-${ticket.id}`} className="text-lg font-bold">{actions[action].title}</h2>
        <p className="mt-2 text-sm font-semibold">{ticket.ticket_ref} · {ticket.title}</p>
        <p className="mt-3 text-sm text-gray-600">{actions[action].note}</p>
        {Object.keys(errors).length > 0 && <div role="alert" className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>}
        <div className="mt-6 flex justify-end gap-3">
            <button type="button" autoFocus disabled={saving} onClick={onClose} className="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Cancel</button>
            <button type="button" disabled={saving} onClick={submit} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{saving ? "Saving…" : actions[action].label === "Done" ? "Mark Done" : actions[action].label}</button>
        </div>
    </dialog>, document.body);
}
