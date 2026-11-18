import { useRef, useState } from "react";
import { useForm } from "@inertiajs/react";
import { LifeBuoy, X, Send } from "lucide-react";
import TicketAttachmentPicker from "./TicketAttachmentPicker";

const CATEGORIES = [
    { key: "change", label: "Change to something" },
    { key: "feature", label: "New feature" },
    { key: "bug", label: "Something's broken" },
    { key: "other", label: "Other" },
];
const PRIORITIES = ["low", "normal", "high", "urgent"];
const inp = "w-full px-3 py-2 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300";

/**
 * Lets any staff member raise a ticket (change / feature / bug) from the
 * topbar. Posts to /tickets; admins/super-admins triage them on the
 * System Tickets board.
 */
export default function RequestTicketButton() {
    const [open, setOpen] = useState(false);
    const form = useForm({ title: "", description: "", category: "change", priority: "normal", attachments: [] });
    const { data, setData, post, processing, progress, errors, reset, transform } = form;
    const submitting = useRef(false);

    const submit = (e) => {
        e.preventDefault();
        if (submitting.current || processing) return;
        submitting.current = true;
        transform((values) => {
            const payload = new FormData();
            ["title", "description", "category", "priority"].forEach((key) => payload.append(key, values[key]));
            values.attachments.forEach((file) => payload.append("attachments[]", file));
            return payload;
        });
        post("/tickets", {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => { submitting.current = false; },
            onSuccess: () => { reset(); setOpen(false); },
        });
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                title="Submit a ticket — request a change or feature"
                className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white border border-gray-100 shadow-sm text-gray-600 text-xs font-semibold hover:shadow transition-shadow"
            >
                <LifeBuoy size={14} /> Ticket
            </button>
            <button type="button" onClick={() => setOpen(true)} className="sm:hidden p-1.5 text-gray-600 hover:bg-white hover:shadow-sm rounded-full" aria-label="Submit a ticket">
                <LifeBuoy size={18} />
            </button>

            {open && (
                <div className="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center p-4" onMouseDown={() => setOpen(false)}>
                    <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto" onMouseDown={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                            <div>
                                <h2 className="text-lg font-bold text-gray-900">Submit a ticket</h2>
                                <p className="text-xs text-gray-500">Tell the admin team what you'd like changed or added.</p>
                            </div>
                            <button onClick={() => setOpen(false)} className="text-gray-400 hover:text-gray-600"><X size={20} /></button>
                        </div>
                        <form onSubmit={submit} className="p-6 space-y-4">
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Title</span>
                                <input value={data.title} onChange={(e) => setData("title", e.target.value)} placeholder="e.g. Add a bulk export button to Leads" className={inp} />
                                {errors.title && <span className="text-xs text-rose-600">{errors.title}</span>}
                            </label>
                            <div className="grid grid-cols-2 gap-4">
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Type</span>
                                    <select value={data.category} onChange={(e) => setData("category", e.target.value)} className={inp}>
                                        {CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                                    </select>
                                </label>
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Priority</span>
                                    <select value={data.priority} onChange={(e) => setData("priority", e.target.value)} className={inp}>
                                        {PRIORITIES.map((p) => <option key={p} value={p}>{p[0].toUpperCase() + p.slice(1)}</option>)}
                                    </select>
                                </label>
                            </div>
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Details</span>
                                <textarea value={data.description} onChange={(e) => setData("description", e.target.value)} rows={5} placeholder="Describe what you want and why it would help…" className={inp} />
                                {errors.description && <span className="text-xs text-rose-600">{errors.description}</span>}
                            </label>

                            <TicketAttachmentPicker files={data.attachments} onChange={(files) => setData("attachments", files)} disabled={processing} errors={errors} />
                            {processing && <p role="status" className="text-xs text-gray-600">{progress ? `Uploading… ${Math.round(progress.percentage ?? 0)}%` : "Submitting ticket…"}</p>}

                            <div className="flex justify-end gap-2 pt-1">
                                <button type="button" onClick={() => setOpen(false)} className="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-xl">Cancel</button>
                                <button type="submit" disabled={processing} className="px-4 py-2 text-sm font-semibold bg-gray-900 text-white rounded-xl hover:bg-black disabled:opacity-50 inline-flex items-center gap-2">
                                    <Send size={14} /> {processing ? "Submitting…" : "Submit ticket"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </>
    );
}
