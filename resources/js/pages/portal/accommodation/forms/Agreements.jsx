import React, { useEffect, useMemo, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Search, FileSignature, Plus, Trash2, Download, RefreshCw, CheckCircle2, Users, Loader2, ChevronDown, Check, Link as LinkIcon, Send, Eye } from "lucide-react";
import PdfPreviewModal from "@/components/ui/PdfPreviewModal";

// Forms → Agreements: pick a client from the top-bar dropdown (unified list of
// general + onboarding pre-tenancy submissions); the agreement-type picker,
// auto-filled editable fields and a live preview then use the full width.
// Generating renders the chosen Exalt agreement to a stored PDF (and links it to
// the onboarding record when the client has one).
export default function Agreements({ clients = [], agreements = [], types = {}, properties = [] }) {
    const typeKeys = Object.keys(types);
    const [search, setSearch] = useState("");
    const [pickerOpen, setPickerOpen] = useState(false);
    const [selected, setSelected] = useState(null);          // {source, id, name}
    const [agreementType, setAgreementType] = useState(typeKeys[0] || "whole_property");
    const [fields, setFields] = useState(null);              // editable agreement data
    const [loadingPrefill, setLoadingPrefill] = useState(false);
    const [previewHtml, setPreviewHtml] = useState("");
    const [previewLoading, setPreviewLoading] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [copiedId, setCopiedId] = useState(null);
    const [docPreview, setDocPreview] = useState(null); // { url, title, downloadUrl }
    const previewTimer = useRef(null);

    const copyLink = (a) => {
        if (!a.signing_url) return;
        navigator.clipboard?.writeText(a.signing_url).then(() => { setCopiedId(a.id); setTimeout(() => setCopiedId(null), 1500); });
    };
    const resend = (a) => router.post(`/portal/accommodation/forms/agreements/${a.id}/resend`, {}, { preserveScroll: true });

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return clients;
        return clients.filter((c) => `${c.name} ${c.email || ""} ${c.property_address || ""}`.toLowerCase().includes(q));
    }, [clients, search]);

    // Pick a client -> load auto-fill from their pre-tenancy submission.
    const pickClient = async (c) => {
        setPickerOpen(false);
        setSelected(c);
        setLoadingPrefill(true);
        setFields(null);
        setPreviewHtml("");
        try {
            const res = await fetch("/portal/accommodation/forms/agreements/prefill", {
                method: "POST",
                headers: { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest", ...csrfHeaders() },
                body: JSON.stringify({ source: c.source, id: c.id }),
            });
            const json = await res.json();
            setFields(json.data);
        } catch {
            setFields(emptyFields());
        } finally {
            setLoadingPrefill(false);
        }
    };

    // Debounced live preview whenever the type or fields change.
    useEffect(() => {
        if (!fields) return;
        clearTimeout(previewTimer.current);
        previewTimer.current = setTimeout(() => refreshPreview(), 500);
        return () => clearTimeout(previewTimer.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fields, agreementType]);

    const refreshPreview = async () => {
        if (!fields) return;
        setPreviewLoading(true);
        try {
            const res = await fetch("/portal/accommodation/forms/agreements/preview", {
                method: "POST",
                headers: { "Content-Type": "application/json", "Accept": "text/html", "X-Requested-With": "XMLHttpRequest", ...csrfHeaders() },
                body: JSON.stringify({ agreement_type: agreementType, data: fields }),
            });
            setPreviewHtml(await res.text());
        } finally {
            setPreviewLoading(false);
        }
    };

    const generate = () => {
        if (!selected || !fields) return;
        setGenerating(true);
        router.post("/portal/accommodation/forms/agreements/generate", {
            agreement_type: agreementType,
            source: selected.source,
            source_id: selected.id,
            data: fields,
        }, {
            preserveScroll: true,
            onFinish: () => setGenerating(false),
        });
    };

    // ---- field editing helpers ----
    const setF = (path, val) => setFields((f) => setDeep(f, path, val));
    const setSub = (i, key, val) => setFields((f) => ({ ...f, subtenants: f.subtenants.map((s, idx) => (idx === i ? { ...s, [key]: val } : s)) }));
    const addSub = () => setFields((f) => ({ ...f, subtenants: [...(f.subtenants || []), emptySub()] }));
    const removeSub = (i) => setFields((f) => ({ ...f, subtenants: f.subtenants.filter((_, idx) => idx !== i) }));

    return (
        <div className="space-y-5">
            <Head title="Agreements" />

            <div>
                <h1 className="text-2xl font-bold text-gray-900">Agreements</h1>
                <p className="mt-1 text-sm text-gray-500">
                    Pick a client, choose an agreement, review the auto-filled details, and generate the Exalt
                    flat/house-sharing agreement. Clients come from both the general and onboarding pre-tenancy forms.
                </p>
            </div>

            {/* Builder card */}
            <div className="rounded-2xl border border-gray-100 bg-white shadow-sm">
                {/* Top bar: client picker + type tabs + actions */}
                <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 p-3">
                    {/* Client dropdown */}
                    <div className="relative">
                        <button onClick={() => setPickerOpen((o) => !o)} className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <Users size={15} className="text-[#1F5A8B]" />
                            {selected ? (
                                <span className="max-w-[16rem] truncate">{selected.name}</span>
                            ) : (
                                <span className="text-gray-500">Select a client…</span>
                            )}
                            <ChevronDown size={15} className="text-gray-400" />
                        </button>
                        {pickerOpen && (
                            <>
                                <div className="fixed inset-0 z-10" onClick={() => setPickerOpen(false)} />
                                <div className="absolute left-0 z-20 mt-1 w-80 rounded-xl border border-gray-200 bg-white shadow-lg">
                                    <div className="border-b border-gray-100 p-2">
                                        <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5">
                                            <Search size={15} className="text-gray-400" />
                                            <input autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search clients…" className="min-w-0 flex-1 bg-transparent text-sm outline-none" />
                                        </div>
                                    </div>
                                    <div className="max-h-72 overflow-y-auto">
                                        {filtered.length === 0 ? (
                                            <p className="px-4 py-8 text-center text-xs text-gray-400">No pre-tenancy submissions found.</p>
                                        ) : filtered.map((c) => {
                                            const active = selected && selected.source === c.source && selected.id === c.id;
                                            return (
                                                <button key={`${c.source}-${c.id}`} onClick={() => pickClient(c)} className={`block w-full border-b border-gray-50 px-3 py-2 text-left hover:bg-gray-50 ${active ? "bg-[#1F5A8B]/5" : ""}`}>
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className={`truncate text-sm font-semibold ${active ? "text-[#1F5A8B]" : "text-gray-900"}`}>{c.name}</span>
                                                        <span className={`shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${c.source === "onboarding" ? "bg-amber-100 text-amber-700" : "bg-gray-100 text-gray-500"}`}>{c.source}</span>
                                                    </div>
                                                    <p className="truncate text-xs text-gray-400">{c.property_address || c.email || "—"}</p>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </>
                        )}
                    </div>

                    {selected && <div className="hidden h-7 w-px bg-gray-200 sm:block" />}

                    {selected && typeKeys.map((k) => (
                        <button key={k} onClick={() => setAgreementType(k)} className={`rounded-full px-3 py-1.5 text-xs font-semibold ${agreementType === k ? "bg-[#1F5A8B] text-white" : "border border-gray-200 text-gray-600 hover:bg-gray-50"}`}>
                            {types[k]}
                        </button>
                    ))}

                    {selected && (
                        <div className="ml-auto flex items-center gap-2">
                            <button onClick={refreshPreview} className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                <RefreshCw size={13} className={previewLoading ? "animate-spin" : ""} /> Preview
                            </button>
                            <button onClick={generate} disabled={generating} title="Generate the PDF and email the client a signing link" className="inline-flex items-center gap-1.5 rounded-full bg-[#1F5A8B] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[#184A73] disabled:opacity-60">
                                {generating ? <Loader2 size={13} className="animate-spin" /> : <CheckCircle2 size={13} />} Generate &amp; send
                            </button>
                        </div>
                    )}
                </div>

                {!selected ? (
                    <div className="flex flex-col items-center justify-center gap-2 px-6 py-24 text-center">
                        <FileSignature size={30} className="text-gray-300" />
                        <p className="text-sm font-medium text-gray-500">Pick a client to build an agreement</p>
                        <p className="text-xs text-gray-400">Their details auto-fill the agreement; you can edit anything before generating.</p>
                    </div>
                ) : loadingPrefill || !fields ? (
                    <div className="flex items-center justify-center gap-2 py-24 text-sm text-gray-400">
                        <Loader2 size={16} className="animate-spin" /> Loading client details…
                    </div>
                ) : (
                    <div className="grid gap-0 xl:grid-cols-[26rem_1fr]">
                        {/* Fields editor — fixed slim width so the preview gets the rest. */}
                        <div className="max-h-[44rem] space-y-5 overflow-y-auto border-r border-gray-100 p-4">
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-500">Property address</span>
                                <select value={fields.property_address || ""} onChange={(e) => setF(["property_address"], e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-[#1F5A8B] focus:outline-none focus:ring-1 focus:ring-[#1F5A8B]">
                                    <option value="">Select a property…</option>
                                    {properties.map((p) => <option key={p} value={p}>{p}</option>)}
                                    {fields.property_address && !properties.includes(fields.property_address) && (
                                        <option value={fields.property_address}>{fields.property_address}</option>
                                    )}
                                </select>
                            </label>

                            <div>
                                <div className="mb-2 flex items-center justify-between">
                                    <p className="text-xs font-bold uppercase tracking-wider text-gray-400">Sub-tenants</p>
                                    <button onClick={addSub} className="inline-flex items-center gap-1 text-xs font-semibold text-[#1F5A8B] hover:underline"><Plus size={12} /> Add</button>
                                </div>
                                {(fields.subtenants || []).map((s, i) => (
                                    <div key={i} className="mb-3 rounded-xl border border-gray-100 p-3">
                                        <div className="mb-2 flex items-center justify-between">
                                            <span className="text-xs font-semibold text-gray-500">Sub-Tenant {i + 1}</span>
                                            {fields.subtenants.length > 1 && (
                                                <button onClick={() => removeSub(i)} className="text-rose-500 hover:text-rose-600"><Trash2 size={13} /></button>
                                            )}
                                        </div>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Mini label="First name" value={s.first_name} onChange={(v) => setSub(i, "first_name", v)} />
                                            <Mini label="Last name" value={s.last_name} onChange={(v) => setSub(i, "last_name", v)} />
                                            <Mini label="Passport / ID" value={s.passport_id} onChange={(v) => setSub(i, "passport_id", v)} />
                                            <Mini label="Expiry date" type="date" value={s.expiry_date} onChange={(v) => setSub(i, "expiry_date", v)} />
                                            <Mini label="Date of birth" type="date" value={s.dob} onChange={(v) => setSub(i, "dob", v)} />
                                            <Mini label="Mobile" type="tel" value={s.mobile} onChange={(v) => setSub(i, "mobile", v)} />
                                            <Mini label="Email" type="email" value={s.email} onChange={(v) => setSub(i, "email", v)} full />
                                            <Mini label="Current address" value={s.current_address} onChange={(v) => setSub(i, "current_address", v)} full />
                                            {i > 0 && <Mini label="Relationship to Sub-Tenant 1" value={s.relationship} onChange={(v) => setSub(i, "relationship", v)} full />}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div>
                                <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Tenancy</p>
                                <div className="grid grid-cols-2 gap-2">
                                    <Mini label="Commence date" type="date" value={fields.tenancy?.commence_date} onChange={(v) => setF(["tenancy", "commence_date"], v)} />
                                    <Mini label="Term" value={fields.tenancy?.term} onChange={(v) => setF(["tenancy", "term"], v)} />
                                    <Mini label="End date" type="date" value={fields.tenancy?.end_date} onChange={(v) => setF(["tenancy", "end_date"], v)} />
                                    <Mini label="Weekly rent" type="number" step="0.01" prefix="NZ$" value={fields.tenancy?.weekly_rent} onChange={(v) => setF(["tenancy", "weekly_rent"], v)} />
                                    <Mini label="Move-in date" type="date" value={fields.tenancy?.move_in_date} onChange={(v) => setF(["tenancy", "move_in_date"], v)} />
                                    <Mini label="Move-in time" type="time" value={fields.tenancy?.move_in_time} onChange={(v) => setF(["tenancy", "move_in_time"], v)} />
                                    <Mini label="Move-out date" type="date" value={fields.tenancy?.move_out_date} onChange={(v) => setF(["tenancy", "move_out_date"], v)} />
                                    <Mini label="Move-out time" type="time" value={fields.tenancy?.move_out_time} onChange={(v) => setF(["tenancy", "move_out_time"], v)} />
                                    <Mini label="Rent day" options={WEEKDAYS} value={fields.tenancy?.rent_day} onChange={(v) => setF(["tenancy", "rent_day"], v)} />
                                    <Mini label="Utility option" options={UTILITY_OPTIONS} value={fields.tenancy?.utility_option} onChange={(v) => setF(["tenancy", "utility_option"], v)} />
                                    {fields.tenancy?.utility_option ? (
                                        <Mini label="Utility amount / week" type="number" step="0.01" prefix="NZ$" value={fields.tenancy?.utility_amount} onChange={(v) => setF(["tenancy", "utility_amount"], v)} />
                                    ) : null}
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Move-in costs (NZ$)</p>
                                <div className="grid grid-cols-2 gap-2">
                                    <Mini label="Bond" type="number" step="0.01" prefix="NZ$" value={fields.costs?.bond} onChange={(v) => setF(["costs", "bond"], v)} />
                                    <Mini label="Deposit" type="number" step="0.01" prefix="NZ$" value={fields.costs?.deposit} onChange={(v) => setF(["costs", "deposit"], v)} />
                                    <Mini label="Rent in advance" type="number" step="0.01" prefix="NZ$" value={fields.costs?.rent_advance} onChange={(v) => setF(["costs", "rent_advance"], v)} />
                                    <Mini label="Pro-rated days" type="number" step="1" value={fields.costs?.prorated_days} onChange={(v) => setF(["costs", "prorated_days"], v)} />
                                    <Mini label="Pro-rated rent" type="number" step="0.01" prefix="NZ$" value={fields.costs?.prorated_rent} onChange={(v) => setF(["costs", "prorated_rent"], v)} />
                                    <Mini label="Total move-in" type="number" step="0.01" prefix="NZ$" value={fields.costs?.total_move_in} onChange={(v) => setF(["costs", "total_move_in"], v)} />
                                    <Mini label="Advance from" type="date" value={fields.costs?.rent_advance_from} onChange={(v) => setF(["costs", "rent_advance_from"], v)} />
                                    <Mini label="Advance to" type="date" value={fields.costs?.rent_advance_to} onChange={(v) => setF(["costs", "rent_advance_to"], v)} />
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Bank reference</p>
                                <div className="grid grid-cols-2 gap-2">
                                    <Mini label="Particular (tenant name)" value={fields.bank?.particular} onChange={(v) => setF(["bank", "particular"], v)} />
                                    <Mini label="Reference (property name)" value={fields.bank?.reference} onChange={(v) => setF(["bank", "reference"], v)} />
                                </div>
                            </div>
                        </div>

                        {/* Live preview */}
                        <div className="relative min-h-[44rem] bg-gray-100">
                            {previewLoading && (
                                <div className="absolute right-3 top-3 z-10 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-2.5 py-1 text-xs font-semibold text-gray-500 shadow">
                                    <Loader2 size={12} className="animate-spin" /> Rendering
                                </div>
                            )}
                            <iframe title="Agreement preview" srcDoc={previewHtml} className="h-[44rem] w-full" />
                        </div>
                    </div>
                )}
            </div>

            {/* Generated agreements */}
            {agreements.length > 0 && (
                <div className="rounded-2xl border border-gray-100 bg-white shadow-sm">
                    <div className="border-b border-gray-100 px-4 py-2.5">
                        <h2 className="text-xs font-bold uppercase tracking-wider text-gray-400">Generated agreements</h2>
                    </div>
                    <div className="divide-y divide-gray-50">
                        {agreements.map((a) => (
                            <div key={a.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium text-gray-900">{a.client_name}</span>
                                    <span className="text-xs text-gray-400">{a.type_label}</span>
                                    <StatusBadge status={a.status} />
                                </div>
                                <div className="flex items-center gap-1.5">
                                    {a.signing_url && !a.signed && (
                                        <>
                                            <button onClick={() => copyLink(a)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                                {copiedId === a.id ? <Check size={13} /> : <LinkIcon size={13} />} {copiedId === a.id ? "Copied" : "Copy link"}
                                            </button>
                                            <button onClick={() => resend(a)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                                <Send size={13} /> Resend
                                            </button>
                                        </>
                                    )}
                                    <button onClick={() => setDocPreview({ url: `/portal/accommodation/forms/agreements/${a.id}/download?inline=1`, title: `${a.client_name} — ${a.type_label}`, downloadUrl: `/portal/accommodation/forms/agreements/${a.id}/download` })} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                        <Eye size={13} /> Preview
                                    </button>
                                    <a href={`/portal/accommodation/forms/agreements/${a.id}/download`} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                        <Download size={13} /> {a.signed ? "Signed PDF" : "PDF"}
                                    </a>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <PdfPreviewModal open={!!docPreview} onClose={() => setDocPreview(null)} url={docPreview?.url} title={docPreview?.title} downloadUrl={docPreview?.downloadUrl} />
        </div>
    );
}

function Field({ label, value, onChange }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-gray-500">{label}</span>
            <input value={value || ""} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-[#1F5A8B] focus:outline-none focus:ring-1 focus:ring-[#1F5A8B]" />
        </label>
    );
}

function Mini({ label, value, onChange, full = false, type = "text", options = null, step, prefix }) {
    const cls = "w-full rounded-md border border-gray-200 px-2 py-1.5 text-sm focus:border-[#1F5A8B] focus:outline-none";
    return (
        <label className={`block ${full ? "col-span-2" : ""}`}>
            <span className="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-gray-400">{label}</span>
            {options ? (
                <select value={value || ""} onChange={(e) => onChange(e.target.value)} className={cls}>
                    <option value="">—</option>
                    {options.map((o) => (typeof o === "string"
                        ? <option key={o} value={o}>{o}</option>
                        : <option key={o.value} value={o.value}>{o.label}</option>))}
                </select>
            ) : prefix ? (
                <div className="flex items-center rounded-md border border-gray-200 focus-within:border-[#1F5A8B]">
                    <span className="pl-2 text-sm text-gray-400">{prefix}</span>
                    <input type={type} step={step} min={type === "number" ? "0" : undefined} value={value || ""} onChange={(e) => onChange(e.target.value)} className="w-full rounded-md border-0 px-1.5 py-1.5 text-sm focus:outline-none focus:ring-0" />
                </div>
            ) : (
                <input type={type} step={step} min={type === "number" ? "0" : undefined} value={value || ""} onChange={(e) => onChange(e.target.value)} className={cls} />
            )}
        </label>
    );
}

const WEEKDAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const UTILITY_OPTIONS = [{ value: "1", label: "1 — Exalt manages utilities" }, { value: "2", label: "2 — Tenant arranges directly" }];

function StatusBadge({ status }) {
    const map = {
        sent: "bg-blue-100 text-blue-700",
        viewed: "bg-amber-100 text-amber-700",
        signed: "bg-emerald-100 text-emerald-700",
        draft: "bg-gray-100 text-gray-500",
        generated: "bg-gray-100 text-gray-500",
    };
    const label = { sent: "Sent · awaiting signature", viewed: "Viewed", signed: "Signed", draft: "Draft", generated: "Generated" };
    return <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${map[status] || "bg-gray-100 text-gray-500"}`}>{label[status] || status}</span>;
}

function emptySub() {
    return { first_name: "", last_name: "", passport_id: "", expiry_date: "", dob: "", current_address: "", email: "", mobile: "", relationship: "" };
}
function emptyFields() {
    return { property_address: "", subtenants: [emptySub()], tenancy: {}, costs: {}, bank: {} };
}
function setDeep(obj, path, val) {
    const next = { ...obj };
    let cur = next;
    for (let i = 0; i < path.length - 1; i++) {
        cur[path[i]] = { ...(cur[path[i]] || {}) };
        cur = cur[path[i]];
    }
    cur[path[path.length - 1]] = val;
    return next;
}
function csrfHeaders() {
    const cookie = document.cookie.split("; ").find((r) => r.startsWith("XSRF-TOKEN="));
    const xsrf = cookie ? decodeURIComponent(cookie.split("=")[1]) : null;
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    return xsrf ? { "X-XSRF-TOKEN": xsrf, "X-CSRF-TOKEN": meta } : { "X-CSRF-TOKEN": meta };
}
