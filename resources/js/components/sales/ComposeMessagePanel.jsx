import { useState, useEffect } from "react";
import { X, Send, Loader2, Mail, MessageSquare } from "lucide-react";
import { toast } from "sonner";
import { getJson, postJson } from "@/lib/http";

const inp = "w-full px-3 py-2 rounded-lg border border-gray-200 text-sm outline-none focus:border-gray-400";
const CHANNELS = [
    { key: "email", label: "Email", icon: <Mail size={14} /> },
    { key: "sms", label: "SMS", icon: <MessageSquare size={14} /> },
    { key: "both", label: "Both", icon: null },
];

/**
 * Right slide-over to send a one-off message to a single lead. Email / SMS /
 * both, template-or-custom. Matches the AI chat panel's slim gray styling.
 * Posts to /admin/leads/{id}/compose.
 */
export default function ComposeMessagePanel({ open, onClose, lead }) {
    const [channel, setChannel] = useState("email");
    const [templates, setTemplates] = useState([]);
    const [templatesLoading, setTemplatesLoading] = useState(false);
    const [templateError, setTemplateError] = useState("");
    const [templateId, setTemplateId] = useState("");
    const [subject, setSubject] = useState("");
    const [body, setBody] = useState("");
    const [busy, setBusy] = useState(false);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!open || !lead?.id) return;
        let active = true;
        setVisible(false);
        setTemplates([]);
        setTemplateId("");
        setTemplateError("");
        setTemplatesLoading(true);
        const id = requestAnimationFrame(() => setVisible(true));
        const loadTemplates = async () => {
            try {
                const { ok, data } = await getJson(`/admin/leads/${lead.id}/compose/templates`);
                if (!ok || !Array.isArray(data)) throw new Error(data?.message || "Could not load templates.");
                if (active) setTemplates(data);
            } catch (error) {
                if (active) setTemplateError(error.message || "Could not load templates.");
            } finally {
                if (active) setTemplatesLoading(false);
            }
        };
        loadTemplates();
        return () => { active = false; cancelAnimationFrame(id); };
    }, [open, lead?.id]);

    if (!open) return null;

    const close = () => { setVisible(false); setTimeout(onClose, 200); };
    const needsSubject = channel === "email" || channel === "both";
    const supportsChannel = (template, nextChannel) => (nextChannel === "both" ? ["email", "sms"] : [nextChannel])
        .every((selectedChannel) => template.channels?.includes(selectedChannel));
    const eligibleTemplates = templates.filter((template) => supportsChannel(template, channel));
    const valid = templateId
        ? eligibleTemplates.some((template) => String(template.id) === templateId)
        : body.trim() && (!needsSubject || subject.trim());
    const changeChannel = (nextChannel) => {
        setChannel(nextChannel);
        if (templateId && !templates.some((template) => String(template.id) === templateId && supportsChannel(template, nextChannel))) {
            setTemplateId("");
        }
    };

    const send = async () => {
        if (busy || !valid) return;
        setBusy(true);
        try {
            const payload = { channel, ...(templateId ? { template_id: Number(templateId) } : { subject, body }) };
            const { ok, data } = await postJson(`/admin/leads/${lead.id}/compose`, payload);
            if (!ok) { toast.error(data?.message || "Could not send the message."); return; }
            toast.success(`Message sent to ${lead.name || "lead"}.`);
            close();
        } catch {
            toast.error("Could not send the message. Please try again.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[70]">
            <div className={`absolute inset-0 bg-black/30 transition-opacity duration-200 ${visible ? "opacity-100" : "opacity-0"}`} onClick={close} />
            <div className={`absolute right-0 top-0 bottom-0 flex flex-col w-full max-w-[440px] bg-white shadow-[-8px_0_24px_rgba(0,0,0,0.12)] transition-transform duration-200 ease-out ${visible ? "translate-x-0" : "translate-x-full"}`}>
                <div className="flex items-center gap-2 px-4 py-3 border-b border-gray-100">
                    <span className="w-8 h-8 rounded-lg bg-gray-100 text-gray-700 flex items-center justify-center"><Send size={15} /></span>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-sm font-bold text-gray-900 leading-tight truncate">Compose message</h2>
                        <p className="text-[11px] text-gray-400 leading-tight truncate">to {lead?.name || "this lead"}{lead?.email ? ` · ${lead.email}` : ""}</p>
                    </div>
                    <button onClick={close} className="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100"><X size={17} /></button>
                </div>

                <div className="flex-1 overflow-y-auto p-4 space-y-4">
                    <div>
                        <span className="block text-xs font-semibold text-gray-600 mb-1.5">Send as</span>
                        <div className="flex gap-2">
                            {CHANNELS.map((c) => (
                                <button key={c.key} onClick={() => changeChannel(c.key)}
                                    className={`flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border text-sm font-medium ${channel === c.key ? "bg-gray-900 text-white border-gray-900" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50"}`}>
                                    {c.icon} {c.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <label className="block">
                        <span className="block text-xs font-semibold text-gray-600 mb-1">Template</span>
                        <select value={templateId} onChange={(e) => setTemplateId(e.target.value)} disabled={templatesLoading || !!templateError} className={inp}>
                            <option value="">{templatesLoading ? "Loading templates…" : "Custom message…"}</option>
                            {eligibleTemplates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                        {templateError && <span role="alert" className="block text-xs text-red-600 mt-1">{templateError} You can still write a custom message.</span>}
                    </label>

                    {!templateId && (
                        <>
                            {needsSubject && (
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Subject</span>
                                    <input value={subject} onChange={(e) => setSubject(e.target.value)} className={inp} placeholder="Subject" />
                                </label>
                            )}
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Message</span>
                                <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={8} className={inp} placeholder="Hi {{first_name}}, …" />
                                <span className="text-[11px] text-gray-400 mt-1 block">Personalise with {`{{first_name}}`}, {`{{client_portal_url}}`}, {`{{tracker_url}}`}.</span>
                            </label>
                        </>
                    )}
                </div>

                <div className="border-t border-gray-100 p-3 flex justify-end">
                    <button onClick={send} disabled={!valid || busy} className="px-5 py-2 text-sm font-semibold bg-gray-900 text-white rounded-xl hover:bg-black disabled:opacity-40 inline-flex items-center gap-2">
                        {busy ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />} Send
                    </button>
                </div>
            </div>
        </div>
    );
}
