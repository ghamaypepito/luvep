import { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { toast } from "sonner";
import { X, Search, Save, ArrowRight, Send, AlarmClock } from "lucide-react";

// Admin + super-admin only. Choose which events send a message, to whom, and
// with which template. The catalogue comes from EmailEventRegistry; each event
// can fan out to several messages (client receipt + adviser notice, etc.).
export default function EmailAutomation({
    catalogue = {}, departments = {}, recipients = {}, recipientLabels = {},
    templates = [], messages = {},
}) {
    const deptKeys = Object.keys(departments);
    const [dept, setDept] = useState(deptKeys.includes("immigration") ? "immigration" : deptKeys[0]);
    const [query, setQuery] = useState("");
    const [filter, setFilter] = useState("all");
    const [open, setOpen] = useState(() => new Set());
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);

    const [config, setConfig] = useState(() => {
        const c = {};
        Object.entries(messages || {}).forEach(([k, arr]) => { c[k] = (arr || []).map((m) => ({ ...m })); });
        return c;
    });

    const recipientsFor = (d) => recipients[d] || recipients.default || ["client", "team"];
    // "team" reads as the department's own team everywhere except immigration,
    // which keeps its adviser/manager/"Case team" vocabulary.
    const recipientLabelsFor = (d) => (d === "immigration"
        ? recipientLabels
        : { ...recipientLabels, team: `${departments[d] || "Department"} team` });
    const msgsOf = (key) => config[key] || [];
    const setMsgs = (key, arr) => { setConfig((p) => ({ ...p, [key]: arr })); setDirty(true); };
    const eventOn = (key) => msgsOf(key).some((m) => m.enabled);
    const missingOf = (key) => msgsOf(key).filter((m) => m.enabled && !m.template_key).length;
    const status = (key) => {
        const miss = missingOf(key);
        if (miss > 0) return { text: `${miss} missing template`, tone: "text-rose-600" };
        const n = msgsOf(key).length;
        if (n > 0) return { text: `${n} message${n === 1 ? "" : "s"}`, tone: "text-gray-400" };
        return { text: "No recipients", tone: "text-gray-300" };
    };
    const toggleOpen = (key) => setOpen((s) => { const n = new Set(s); n.has(key) ? n.delete(key) : n.add(key); return n; });

    const deptCount = (d) => {
        let on = 0, total = 0;
        (catalogue[d] || []).forEach((g) => g.events.forEach((e) => { total++; if (eventOn(e.key)) on++; }));
        return { on, total };
    };

    const passesFilter = (key) => {
        if (filter === "on") return eventOn(key);
        if (filter === "off") return !eventOn(key);
        if (filter === "needs") return missingOf(key) > 0 || (eventOn(key) && msgsOf(key).length === 0);
        return true;
    };

    const groups = useMemo(() => {
        const q = query.trim().toLowerCase();
        return (catalogue[dept] || []).map((g) => ({
            ...g,
            events: g.events.filter((e) =>
                passesFilter(e.key) && (!q || e.label.toLowerCase().includes(q) || (e.when || "").toLowerCase().includes(q))),
        })).filter((g) => g.events.length);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [catalogue, dept, query, filter, config]);

    const save = () => {
        setSaving(true);
        const flat = [];
        Object.entries(config).forEach(([event_key, arr]) => (arr || []).forEach((m) => flat.push({
            event_key, recipient: m.recipient, template_key: m.template_key || null,
            channel: m.channel || "email", delay_minutes: m.delay_minutes || 0, enabled: !!m.enabled,
            cc_agent: !!m.cc_agent,
        })));
        router.post("/admin/email-automation", { messages: flat }, {
            preserveScroll: true,
            onSuccess: () => setDirty(false),   // success toast comes from the flash message
            onError: () => toast.error("Could not save"),
            onFinish: () => setSaving(false),
        });
    };

    const sendTest = (templateKey) => {
        if (!templateKey) return toast.error("Pick a template first");
        router.post("/admin/email-automation/test", { template_key: templateKey }, {
            preserveScroll: true,
            // success + error messages come from the server flash (avoids a
            // duplicate toast on top of the flash toaster).
            onError: (e) => toast.error(Object.values(e)[0] || "Could not send test"),
        });
    };

    const total = deptCount(dept);

    return (
        <>
            <Head title="Email Automation" />
            <div className="max-w-[1400px] mx-auto pb-12">
                {/* Header */}
                <div className="flex items-end justify-between gap-4 flex-wrap mb-6">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-violet-600">Settings · Automation</p>
                        <h1 className="text-[26px] font-bold text-gray-900 tracking-tight">Email automation</h1>
                        <p className="text-[13.5px] text-gray-500 mt-1 max-w-[58ch]">Every event can notify several people, each with their own template and channel. Open an event to set up who gets what.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className={`text-[12.5px] font-medium ${dirty ? "text-amber-600" : "text-gray-400"}`}>{dirty ? "Unsaved changes" : "All changes saved"}</span>
                        <button type="button" onClick={save} disabled={saving || !dirty}
                            className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-violet-600 text-white text-[13.5px] font-semibold hover:bg-violet-700 disabled:opacity-50">
                            <Save size={15} /> {saving ? "Saving…" : "Save changes"}
                        </button>
                    </div>
                </div>

                {/* Department tabs */}
                <div className="border-b border-gray-200 flex gap-1 overflow-x-auto mb-3">
                    {deptKeys.map((d) => {
                        const { on, total } = deptCount(d);
                        const active = d === dept;
                        return (
                            <button key={d} type="button" onClick={() => setDept(d)}
                                className={`flex items-center gap-2 px-4 py-2.5 text-[14px] font-semibold border-b-2 -mb-px whitespace-nowrap transition-colors ${active ? "border-violet-600 text-violet-700" : "border-transparent text-gray-500 hover:text-gray-800"}`}>
                                {departments[d]}
                                <span className={`text-[11px] font-mono tabular-nums px-1.5 py-0.5 rounded ${active ? "bg-violet-100 text-violet-700" : "bg-gray-100 text-gray-400"}`}>{on}/{total}</span>
                            </button>
                        );
                    })}
                </div>

                <p className="text-[12px] text-gray-400 mb-4">Turning an event on here only affects <b className="text-gray-600">{departments[dept]}</b>. All automations start off — nothing sends until you enable it.</p>

                {/* Panel */}
                <section className="min-w-0">
                        <div className="flex items-center justify-between gap-3 flex-wrap mb-4 px-0.5">
                            <div className="text-[18px] font-bold text-gray-900">{departments[dept]}
                                <span className="text-[13px] font-medium text-gray-400 ml-2">· {total.on} of {total.total} events on</span>
                            </div>
                            <div className="flex items-center gap-2 flex-wrap">
                                <div className="inline-flex bg-gray-100 rounded-lg p-0.5">
                                    {[["all", "All"], ["on", "On"], ["off", "Off"], ["needs", "Needs setup"]].map(([v, t]) => (
                                        <button key={v} type="button" onClick={() => setFilter(v)}
                                            className={`text-[12px] font-semibold px-2.5 py-1.5 rounded-md ${filter === v ? "bg-gray-900 text-white" : "text-gray-500 hover:text-gray-800"}`}>{t}</button>
                                    ))}
                                </div>
                                <div className="relative">
                                    <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                                    <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search events…"
                                        className="text-[13px] pl-8 pr-3 py-2 border border-gray-200 rounded-lg w-[180px] focus:outline-none focus:border-gray-400" />
                                </div>
                            </div>
                        </div>

                        {groups.map((g) => {
                            const onCount = g.events.filter((e) => eventOn(e.key)).length;
                            return (
                                <div key={g.group} className="mb-5">
                                    <div className="flex items-center gap-2 mb-2 px-1">
                                        <span className="text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">{g.group}</span>
                                        <span className="text-[11px] text-gray-400">{onCount} of {g.events.length} on</span>
                                    </div>
                                    <div className="flex flex-col gap-2.5">
                                        {g.events.map((ev) => (
                                            <EventCard key={ev.key} ev={ev} msgs={msgsOf(ev.key)} setMsgs={(a) => setMsgs(ev.key, a)}
                                                on={eventOn(ev.key)} status={status(ev.key)} isOpen={open.has(ev.key)} onOpen={() => toggleOpen(ev.key)}
                                                recipients={recipientsFor(dept)} recipientLabels={recipientLabelsFor(dept)} templates={templates}
                                                onTest={sendTest} />
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                        {groups.length === 0 && <p className="text-[13px] text-gray-400 px-1">No events match this filter.</p>}

                        <p className="text-[12px] text-gray-400 mt-3 px-1 max-w-[74ch]">
                            Every message uses your existing templates via <code className="font-mono text-[11px] bg-gray-100 px-1 rounded">CommunicationService</code> — no AI-written content. Events marked <b>scheduled</b> fire from a nightly sweep once wired; the rest fire the moment the action happens.
                        </p>
                </section>
            </div>
        </>
    );
}

function Switch({ on, onChange, label, size = "md" }) {
    const w = size === "sm" ? 34 : 42, k = size === "sm" ? 14 : 18;
    return (
        <button type="button" role="switch" aria-checked={on} aria-label={label} onClick={(e) => { e.stopPropagation(); onChange(!on); }}
            style={{ width: w, height: k + 6 }}
            className={`relative rounded-full transition-colors flex-shrink-0 ${on ? "bg-violet-500" : "bg-gray-300"}`}>
            <span style={{ width: k, height: k, transform: on ? `translateX(${w - k - 6}px)` : "none" }}
                className="absolute top-[3px] left-[3px] rounded-full bg-white shadow transition-transform" />
        </button>
    );
}

function EventCard({ ev, msgs, setMsgs, on, status, isOpen, onOpen, recipients, recipientLabels, templates, onTest }) {
    const defaultRecipient = recipients[0] || "client";

    const toggleEvent = (v) => {
        if (v) {
            if (msgs.length === 0) { setMsgs([{ recipient: defaultRecipient, template_key: "", channel: "email", delay_minutes: 0, enabled: true }]); if (!isOpen) onOpen(); }
            else setMsgs(msgs.map((m) => ({ ...m, enabled: true })));
        } else setMsgs(msgs.map((m) => ({ ...m, enabled: false })));
    };
    const addMsg = () => {
        const next = recipients.find((r) => !msgs.some((m) => m.recipient === r)) || defaultRecipient;
        setMsgs([...msgs, { recipient: next, template_key: "", channel: "email", delay_minutes: 0, enabled: true }]);
        if (!isOpen) onOpen();
    };
    const patch = (i, p) => setMsgs(msgs.map((m, x) => (x === i ? { ...m, ...p } : m)));
    const remove = (i) => setMsgs(msgs.filter((_, x) => x !== i));

    return (
        <div className={`bg-white border rounded-2xl shadow-sm overflow-hidden ${isOpen ? "border-violet-200" : "border-gray-100"} ${on ? "" : "opacity-75"}`}>
            <div className="flex items-center gap-3.5 px-4 py-3.5 cursor-pointer" onClick={onOpen}>
                <Switch on={on} onChange={toggleEvent} label={`Enable ${ev.label}`} />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <p className="text-[14px] font-semibold text-gray-900">{ev.label}</p>
                        {ev.scheduled && <span className="inline-flex items-center gap-1 text-[9.5px] font-bold uppercase tracking-wider text-gray-400 border border-gray-200 rounded px-1.5 py-0.5"><AlarmClock size={10} /> scheduled</span>}
                    </div>
                    <p className="text-[12.5px] text-gray-500">{ev.when}</p>
                </div>
                <span className={`text-[12px] font-medium ${status.tone}`}>{status.text}</span>
                <button type="button" onClick={(e) => { e.stopPropagation(); addMsg(); }}
                    className="text-[12px] font-semibold text-violet-700 border border-gray-200 rounded-lg px-2.5 py-1.5 hover:border-violet-400 whitespace-nowrap">+ Message</button>
            </div>

            {isOpen && (
                <div className="border-t border-gray-100 bg-gray-50/70 px-4 py-3.5 flex flex-col gap-2.5">
                    {msgs.length === 0 && <p className="text-[12.5px] text-gray-400">No messages yet — add a recipient to send something when this happens.</p>}
                    {msgs.map((m, i) => {
                        const staff = m.recipient !== "client";
                        return (
                            <div key={i} className={`flex flex-wrap items-center gap-3 pl-3 pr-2.5 py-2.5 rounded-xl border bg-white border-l-[3px] ${staff ? "border-l-amber-500" : "border-l-violet-500"} border-gray-100`}>
                                <Switch size="sm" on={!!m.enabled} onChange={(v) => patch(i, { enabled: v })} label="Enable message" />
                                <Field label="Send to">
                                    <select value={m.recipient} onChange={(e) => patch(i, { recipient: e.target.value })} className={selCls}>
                                        {recipients.map((r) => <option key={r} value={r}>{recipientLabels[r] || r}</option>)}
                                    </select>
                                </Field>
                                <ArrowRight size={13} className="text-gray-300 mt-4" />
                                <Field label="Template">
                                    <input list="ea-templates" value={m.template_key || ""} onChange={(e) => patch(i, { template_key: e.target.value })}
                                        placeholder="— none —" className={`${selCls} font-mono w-[190px] ${m.enabled && !m.template_key ? "border-rose-300" : ""}`} />
                                </Field>
                                <Field label="Channel">
                                    <div className="inline-flex border border-gray-200 rounded-lg overflow-hidden">
                                        {[["email", "Email"], ["sms", "SMS"], ["both", "Both"]].map(([v, t], x) => (
                                            <button key={v} type="button" onClick={() => patch(i, { channel: v })}
                                                className={`text-[11.5px] font-semibold px-2.5 py-1.5 ${x ? "border-l border-gray-200" : ""} ${(m.channel || "email") === v ? "bg-violet-50 text-violet-800" : "text-gray-500 hover:text-gray-800"}`}>{t}</button>
                                        ))}
                                    </div>
                                </Field>
                                <Field label="CC agent">
                                    <div className="inline-flex items-center gap-1.5 h-[34px]" title="Also CC this client's own recruiting agent (leads.agent_id)">
                                        <Switch size="sm" on={!!m.cc_agent} onChange={(v) => patch(i, { cc_agent: v })} label="CC the client's Affiliate Partner" />
                                        <span className="text-[11.5px] text-gray-500">Client's agent</span>
                                    </div>
                                </Field>
                                <button type="button" onClick={() => onTest(m.template_key)} disabled={!m.template_key} title="Send a test to yourself"
                                    className="inline-flex items-center gap-1 text-[11.5px] font-semibold text-gray-500 hover:text-violet-700 disabled:opacity-40 mt-4"><Send size={12} /> Test</button>
                                <button type="button" onClick={() => remove(i)} title="Remove message"
                                    className="ml-auto text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-md p-1 mt-4"><X size={15} /></button>
                            </div>
                        );
                    })}
                    <div className="flex items-center justify-between">
                        <button type="button" onClick={addMsg} className="text-[12.5px] font-semibold text-violet-700 hover:text-violet-900">+ Add another recipient</button>
                    </div>
                    <datalist id="ea-templates">{templates.map((t) => <option key={t} value={t} />)}</datalist>
                </div>
            )}
        </div>
    );
}

const selCls = "font-mono text-[12px] px-2.5 py-1.5 border border-gray-200 rounded-lg bg-white text-gray-700 cursor-pointer focus:outline-none focus:border-gray-400";
function Field({ label, children }) {
    return (
        <label className="flex flex-col gap-1">
            <span className="text-[9.5px] font-bold uppercase tracking-[0.1em] text-gray-400 pl-0.5">{label}</span>
            {children}
        </label>
    );
}
