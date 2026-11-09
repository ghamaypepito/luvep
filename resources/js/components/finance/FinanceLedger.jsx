import { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Plus, Search, X, Save, Trash2, CreditCard, Pencil, FileText, AlertTriangle } from "lucide-react";

// Per-kind copy + field/endpoint config. One component serves both Accounts
// Receivable (client invoices) and Accounts Payable (vendor bills).
const KIND = {
    receivable: {
        title: "Accounts Receivable", subtitle: "Invoices your clients owe you.",
        numberField: "invoice_no", numberLabel: "Invoice #",
        partyField: "client_name", partyLabel: "Client", partyPlaceholder: "Client name",
        base: "/portal/finance/receivables", entity: "Invoice", newLabel: "New invoice",
        statuses: ["draft", "sent", "paid", "void"], createStatuses: ["draft", "sent"],
        hasCategory: false, collectedLabel: "Collected", outLabel: "Outstanding",
    },
    payable: {
        title: "Accounts Payable", subtitle: "Bills you owe vendors.",
        numberField: "bill_no", numberLabel: "Bill #",
        partyField: "vendor_name", partyLabel: "Vendor", partyPlaceholder: "Vendor name",
        base: "/portal/finance/payables", entity: "Bill", newLabel: "New bill",
        statuses: ["draft", "approved", "paid", "void"], createStatuses: ["draft", "approved"],
        hasCategory: true, collectedLabel: "Paid out", outLabel: "Owed",
    },
};

const money = (v, cur) => `${cur || ""} ${Number(v || 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();
const today = () => new Date().toISOString().slice(0, 10);

const STATUS_BADGE = {
    paid: "bg-emerald-100 text-emerald-700",
    partial: "bg-blue-100 text-blue-700",
    overdue: "bg-rose-100 text-rose-700",
    open: "bg-amber-100 text-amber-700",
    void: "bg-gray-100 text-gray-500",
    draft: "bg-gray-100 text-gray-700",
    review: "bg-orange-100 text-orange-800",
};
const STATUS_LABEL = { review: "Needs review" };
const FILTERS = [
    { key: "all", label: "All" }, { key: "open", label: "Open" }, { key: "partial", label: "Partial" },
    { key: "overdue", label: "Overdue" }, { key: "paid", label: "Paid" }, { key: "void", label: "Void" },
];
const BUCKET_LABEL = { current: "Current", "1-30": "1–30d", "31-60": "31–60d", "61-90": "61–90d", "90+": "90+d" };

const inputCls = "w-full px-3 py-2 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500";

// Create / edit an entry.
function EntryModal({ kind, cfg, row, currencyDefault, onClose }) {
    const editing = !!row;
    const [f, setF] = useState({
        party: row?.[cfg.partyField] || "",
        category: row?.category || "",
        invoice_no: "",
        amount: row?.amount ?? "",
        currency: row?.currency || currencyDefault || "NZD",
        issue_date: row?.issue_date || today(),
        due_date: row?.due_date || today(),
        description: row?.description || "",
        notes: row?.notes || "",
        status: row?.status || cfg.createStatuses[0],
    });
    const [saving, setSaving] = useState(false);
    const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target.value }));

    const submit = () => {
        setSaving(true);
        const payload = {
            [cfg.partyField]: f.party,
            amount: f.amount,
            currency: f.currency,
            issue_date: f.issue_date,
            due_date: f.due_date,
            description: f.description || null,
            notes: f.notes || null,
            status: f.status,
        };
        if (cfg.hasCategory) payload.category = f.category || null;
        if (!editing && kind === "receivable" && f.invoice_no.trim()) payload.invoice_no = f.invoice_no.trim();

        const opts = { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setSaving(false) };
        if (editing) router.put(`${cfg.base}/${row.id}`, payload, opts);
        else router.post(cfg.base, payload, opts);
    };

    const statuses = editing ? cfg.statuses : cfg.createStatuses;
    const canSave = f.party.trim() && f.amount !== "" && Number(f.amount) >= 0 && !saving;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 overflow-y-auto bg-black/40 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-xl bg-white rounded-2xl shadow-2xl overflow-hidden my-8" onClick={(e) => e.stopPropagation()}>
                <div className="px-6 py-4 border-b border-gray-100 flex items-start justify-between bg-gradient-to-br from-gray-50 to-white">
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.24em] text-gray-400 mb-1">{cfg.title}</p>
                        <h2 className="text-lg font-bold text-gray-900">{editing ? `Edit ${cfg.entity.toLowerCase()} ${row[cfg.numberField]}` : `New ${cfg.entity.toLowerCase()}`}</h2>
                    </div>
                    <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                </div>

                <div className="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label className="block sm:col-span-2">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">{cfg.partyLabel}</span>
                        <input className={inputCls} value={f.party} onChange={set("party")} placeholder={cfg.partyPlaceholder} />
                    </label>
                    {cfg.hasCategory && (
                        <label className="block sm:col-span-2">
                            <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Category</span>
                            <input className={inputCls} value={f.category} onChange={set("category")} placeholder="e.g. Software, Rent, Marketing" />
                        </label>
                    )}
                    {!editing && kind === "receivable" && (
                        <label className="block sm:col-span-2">
                            <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Invoice # <span className="text-gray-300">(auto if blank)</span></span>
                            <input className={inputCls} value={f.invoice_no} onChange={set("invoice_no")} placeholder="AR-00001" />
                        </label>
                    )}
                    <label className="block">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Amount</span>
                        <input type="number" step="0.01" min="0" disabled={row?.portal_issued} className={inputCls} value={f.amount} onChange={set("amount")} placeholder="0.00" />
                    </label>
                    <label className="block">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Currency</span>
                        <input maxLength={3} disabled={row?.portal_issued} className={`${inputCls} uppercase`} value={f.currency} onChange={(e) => setF((p) => ({ ...p, currency: e.target.value.toUpperCase() }))} />
                    </label>
                    <label className="block">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Issue date</span>
                        <input type="date" className={inputCls} value={f.issue_date} onChange={set("issue_date")} />
                    </label>
                    <label className="block">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Due date</span>
                        <input type="date" className={inputCls} value={f.due_date} onChange={set("due_date")} />
                    </label>
                    <label className="block sm:col-span-2">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Description</span>
                        <input className={inputCls} value={f.description} onChange={set("description")} placeholder="What is this for?" />
                    </label>
                    <label className="block">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Status</span>
                        <select className={inputCls} value={f.status} onChange={set("status")}>
                            {statuses.map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
                        </select>
                    </label>
                    <label className="block sm:col-span-2">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Notes</span>
                        <textarea rows={2} className={`${inputCls} resize-y`} value={f.notes} onChange={set("notes")} />
                    </label>
                </div>

                <div className="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50/50">
                    <button onClick={onClose} className="px-4 py-2.5 text-sm font-semibold text-gray-600 rounded-xl hover:bg-gray-100">Cancel</button>
                    <button onClick={submit} disabled={!canSave} className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-bold rounded-xl hover:bg-indigo-700 disabled:opacity-60">
                        <Save size={15} /> {saving ? "Saving…" : editing ? "Save changes" : `Create ${cfg.entity.toLowerCase()}`}
                    </button>
                </div>
            </div>
        </div>
    );
}

// Detail + payment ledger for one entry.
function DetailModal({ cfg, row, onEdit, onClose }) {
    const [pay, setPay] = useState({ amount: "", paid_on: today(), method: "", reference: "", notes: "" });
    const [saving, setSaving] = useState(false);
    const set = (k) => (e) => setPay((p) => ({ ...p, [k]: e.target.value }));

    const record = () => {
        setSaving(true);
        router.post(`${cfg.base}/${row.id}/payments`, pay, {
            preserveScroll: true,
            onSuccess: () => setPay({ amount: "", paid_on: today(), method: "", reference: "", notes: "" }),
            onFinish: () => setSaving(false),
        });
    };
    const removePayment = (pid) => {
        if (!window.confirm("Remove this payment?")) return;
        router.delete(`${cfg.base}/${row.id}/payments/${pid}`, { preserveScroll: true });
    };
    const removeEntry = () => {
        if (!window.confirm(`Delete ${row[cfg.numberField]}? This cannot be undone.`)) return;
        router.delete(`${cfg.base}/${row.id}`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    const badge = STATUS_BADGE[row.payment_status] || "bg-gray-100 text-gray-600";

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 overflow-y-auto bg-black/40 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden my-8" onClick={(e) => e.stopPropagation()}>
                <div className="px-6 py-4 border-b border-gray-100 flex items-start justify-between bg-gradient-to-br from-gray-50 to-white">
                    <div>
                        <div className="flex items-center gap-2 mb-1">
                            <span className="font-mono text-xs font-bold text-gray-500">{row[cfg.numberField]}</span>
                            <span className={`px-2 py-0.5 rounded-md text-[10px] font-bold uppercase ${badge}`}>{STATUS_LABEL[row.payment_status] || row.payment_status}</span>
                            {row.days_past_due > 0 && row.payment_status !== "paid" && row.payment_status !== "void" && (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-rose-600"><AlertTriangle size={11} /> {row.days_past_due}d overdue</span>
                            )}
                        </div>
                        <h2 className="text-lg font-bold text-gray-900">{row[cfg.partyField]}</h2>
                        {row.description && <p className="text-sm text-gray-500 mt-0.5">{row.description}</p>}
                    </div>
                    <div className="flex items-center gap-1">
                        {!row.document_url && <button onClick={() => onEdit(row)} title="Edit" className="p-2 rounded-lg text-gray-500 hover:bg-gray-100"><Pencil size={16} /></button>}
                        {!row.document_url && !row.portal_issued && <button onClick={removeEntry} title="Delete" className="p-2 rounded-lg text-gray-400 hover:bg-rose-50 hover:text-rose-600"><Trash2 size={16} /></button>}
                        <button onClick={onClose} className="p-2 rounded-lg text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                    </div>
                </div>

                {row.document_url ? (
                    <div className="p-6 text-sm text-gray-600">
                        <p>{row.payment_status === "draft" ? "This invoice is saved as a draft. Its amount will be due when the invoice is issued." : "Finance needs to verify this invoice before it can enter the ledger."}</p>
                        <a href={row.document_url} className="mt-3 inline-block font-semibold text-indigo-700 underline">Open Invoice workspace</a>
                    </div>
                ) : <div className="p-6 space-y-5">
                    <div className="grid grid-cols-3 gap-3">
                        <div className="rounded-xl border border-gray-200 p-3">
                            <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Amount</p>
                            <p className="text-base font-bold text-gray-900 tabular-nums">{money(row.amount, row.currency)}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 p-3">
                            <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Paid</p>
                            <p className="text-base font-bold text-emerald-600 tabular-nums">{money(row.amount_paid, row.currency)}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 p-3">
                            <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">Balance</p>
                            <p className={`text-base font-bold tabular-nums ${row.balance > 0.005 ? "text-gray-900" : "text-gray-400"}`}>{money(row.balance, row.currency)}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-6 text-xs text-gray-500">
                        <span>Issued <b className="text-gray-700">{row.issue_date || "—"}</b></span>
                        <span>Due <b className="text-gray-700">{row.due_date || "—"}</b></span>
                    </div>

                    {/* Payment ledger */}
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-2">Payments</p>
                        <div className="rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-100">
                            {(row.payments || []).length === 0 ? (
                                <p className="px-3 py-3 text-xs text-gray-400">No payments recorded yet.</p>
                            ) : row.payments.map((p) => (
                                <div key={p.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                                    <span className="tabular-nums font-semibold text-gray-800 w-28">{money(p.amount, row.currency)}</span>
                                    <span className="text-gray-500 w-24">{p.paid_on}</span>
                                    <span className="text-gray-500 flex-1 truncate">{[p.method, p.reference].filter(Boolean).join(" · ") || "—"}</span>
                                    {!p.case_receipt && <button onClick={() => removePayment(p.id)} className="p-1 rounded text-gray-300 hover:text-rose-500"><Trash2 size={13} /></button>}
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Record payment */}
                    {row.payment_status !== "void" && (
                        <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-indigo-700 mb-2 flex items-center gap-1.5"><CreditCard size={13} /> Record a payment</p>
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                <input type="number" step="0.01" min="0.01" className={inputCls} value={pay.amount} onChange={set("amount")} placeholder="Amount" />
                                <input type="date" className={inputCls} value={pay.paid_on} onChange={set("paid_on")} />
                                <input className={inputCls} value={pay.method} onChange={set("method")} placeholder="Method" />
                                <input className={inputCls} value={pay.reference} onChange={set("reference")} placeholder="Reference" />
                            </div>
                            <div className="flex justify-end mt-2">
                                <button onClick={record} disabled={!pay.amount || Number(pay.amount) <= 0 || saving} className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg hover:bg-indigo-700 disabled:opacity-60">
                                    <Plus size={14} /> {saving ? "Recording…" : "Add payment"}
                                </button>
                            </div>
                        </div>
                    )}

                    {row.notes && (
                        <div>
                            <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">Notes</p>
                            <p className="text-sm text-gray-700 whitespace-pre-wrap">{row.notes}</p>
                        </div>
                    )}
                </div>}
            </div>
        </div>
    );
}

export default function FinanceLedger({ kind, rows = [], summary = {}, filters = {}, currencyDefault = "NZD" }) {
    const cfg = KIND[kind];
    const availableFilters = kind === "receivable"
        ? [...FILTERS, { key: "draft", label: "Draft" }, { key: "review", label: "Needs review" }]
        : FILTERS;
    const [q, setQ] = useState(filters.q || "");
    const [status, setStatus] = useState(filters.status || "all");
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);
    const [detail, setDetail] = useState(null);

    const applyFilters = (next = {}) => {
        const params = { status, q, ...next };
        router.get(cfg.base, params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const collected = summary.collected ?? summary.paid ?? 0;
    const buckets = summary.buckets || {};
    const bucketMax = useMemo(() => Math.max(1, ...Object.values(buckets)), [buckets]);

    // Keep the detail modal in sync with fresh row data after a mutation.
    const detailRow = detail ? rows.find((r) => r.id === detail.id) || detail : null;

    return (
        <div className="space-y-6 max-w-[1500px] mx-auto pb-12">
            <Head title={cfg.title} />

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{cfg.title}</h1>
                    <p className="text-sm text-gray-500 mt-1">{cfg.subtitle} Summary amounts are in {currencyDefault}.</p>
                </div>
                <button onClick={() => setCreating(true)} className="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-600 text-white text-sm font-bold rounded-xl hover:bg-indigo-700 transition-colors shadow-sm w-max">
                    <Plus size={16} /> {cfg.newLabel}
                </button>
            </div>

            {/* Summary tiles */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">{cfg.outLabel}</p>
                    <p className="text-2xl font-bold text-gray-900 tabular-nums">{money(summary.outstanding, currencyDefault)}</p>
                </div>
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Overdue</p>
                    <p className="text-2xl font-bold text-rose-600 tabular-nums">{money(summary.overdue, currencyDefault)}</p>
                </div>
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">{cfg.collectedLabel}</p>
                    <p className="text-2xl font-bold text-emerald-600 tabular-nums">{money(collected, currencyDefault)}</p>
                </div>
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">{cfg.entity}s</p>
                    <p className="text-2xl font-bold text-gray-900 tabular-nums">{summary.count ?? rows.length}</p>
                </div>
            </div>

            {/* Aging */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <p className="text-sm font-bold text-gray-900 mb-3">Aging — outstanding by age</p>
                <div className="grid grid-cols-5 gap-3">
                    {["current", "1-30", "31-60", "61-90", "90+"].map((b) => (
                        <div key={b}>
                            <div className="h-24 flex items-end">
                                <div className={`w-full rounded-t-lg ${b === "current" ? "bg-indigo-500" : b === "90+" ? "bg-rose-500" : "bg-indigo-300"}`}
                                    style={{ height: `${Math.max(4, ((buckets[b] || 0) / bucketMax) * 100)}%` }} />
                            </div>
                            <p className="text-[10px] font-bold uppercase tracking-wide text-gray-400 mt-1.5">{BUCKET_LABEL[b]}</p>
                            <p className="text-xs font-bold text-gray-800 tabular-nums">{money(buckets[b], currencyDefault)}</p>
                        </div>
                    ))}
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-1.5 flex-wrap">
                    {availableFilters.map((ff) => (
                        <button key={ff.key} onClick={() => { setStatus(ff.key); applyFilters({ status: ff.key }); }}
                            className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ${status === ff.key ? "bg-indigo-600 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`}>
                            {ff.label}
                        </button>
                    ))}
                </div>
                <div className="relative">
                    <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && applyFilters()}
                        placeholder={`Search ${cfg.entity.toLowerCase()}s…`} className="pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 w-64" />
                </div>
            </div>

            {/* Table */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider">
                                <th className="px-4 py-3">{cfg.numberLabel}</th>
                                <th className="px-3 py-3">{cfg.partyLabel}</th>
                                <th className="px-3 py-3 text-right">Amount</th>
                                <th className="px-3 py-3 text-right">Balance</th>
                                <th className="px-3 py-3">Due</th>
                                <th className="px-3 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-gray-400">
                                    <FileText size={28} className="mx-auto mb-2 text-gray-300" />
                                    No {cfg.entity.toLowerCase()}s yet. Click “{cfg.newLabel}” to add one.
                                </td></tr>
                            ) : rows.map((r) => (
                                <tr key={r.id} onClick={() => setDetail(r)} className="hover:bg-indigo-50/40 cursor-pointer">
                                    <td className="px-4 py-3 font-mono text-xs font-semibold text-gray-600">{r[cfg.numberField]}</td>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold text-gray-800">{r[cfg.partyField]}</p>
                                        {(r.description || r.category) && <p className="text-[11px] text-gray-400 truncate max-w-[280px]">{r.category || r.description}</p>}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-gray-700">{r.amount == null || !r.currency ? "—" : money(r.amount, r.currency)}</td>
                                    <td className="px-3 py-3 text-right tabular-nums font-semibold text-gray-900">{r.balance == null ? "—" : money(r.balance, r.currency)}</td>
                                    <td className="px-3 py-3 whitespace-nowrap text-gray-600">
                                        {r.due_date || "—"}
                                        {r.days_past_due > 0 && r.payment_status !== "paid" && r.payment_status !== "void" && (
                                            <span className="ml-1.5 text-[10px] font-bold text-rose-600">+{r.days_past_due}d</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-3">
                                        <span className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold uppercase ${STATUS_BADGE[r.payment_status] || "bg-gray-100 text-gray-600"}`}>{STATUS_LABEL[r.payment_status] || r.payment_status}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {creating && <EntryModal kind={kind} cfg={cfg} row={null} currencyDefault={currencyDefault} onClose={() => setCreating(false)} />}
            {editing && <EntryModal kind={kind} cfg={cfg} row={editing} currencyDefault={currencyDefault} onClose={() => setEditing(null)} />}
            {detailRow && <DetailModal cfg={cfg} row={detailRow} onEdit={(r) => { setDetail(null); setEditing(r); }} onClose={() => setDetail(null)} />}
        </div>
    );
}
