import { useState } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import {
    DollarSign, Receipt, Plus, Trash2, CheckCircle2, AlertCircle, UserPlus, Save, Eye, FileDown, Calendar,
} from "lucide-react";
import { immBase } from "@/lib/caseRoutes";

// Case Financials — the money side of the immigration dashboard (fees, invoice,
// payment ledger). Staff enter the figures once; the system derives total
// payable, amount owed and "Account settled". No numbers are generated.

const PAYMENT_TYPES = [
    { value: "", label: "—" },
    { value: "pay_now", label: "Pay now" },
    { value: "pay_later", label: "Pay later" },
];

export default function FinancialsTab({ lead, financials = { record: null, payments: [], totals: {}, referred_by: null } }) {
    if (! lead?.id) return null;

    const r = financials.record || {};
    const t = financials.totals || {};
    const currency = r.currency || "NZD";
    const fmt = (n) => new Intl.NumberFormat("en-NZ", { style: "currency", currency }).format(Number(n || 0));

    const [form, setForm] = useState({
        service_fee_normal: r.service_fee_normal ?? "",
        service_fee_chargeable: r.service_fee_chargeable ?? "",
        inz_fee: r.inz_fee ?? "",
        other_fee: r.other_fee ?? "",
        disbursement: r.disbursement ?? "",
        payment_type: r.payment_type ?? "",
        inz_fee_paid_to: r.inz_fee_paid_to ?? "",
        issued_from: r.issued_from ?? "",
        invoice_no: r.invoice_no ?? "",
        invoice_sent_at: r.invoice_sent_at ?? "",
        currency,
        notes: r.notes ?? "",
        referred_by: financials.referred_by ?? "",
    });
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const [saving, setSaving] = useState(false);
    const saveFees = () => {
        setSaving(true);
        router.post(`${immBase()}/cases/${lead.id}/financials`, form, {
            preserveScroll: true,
            onSuccess: () => toast.success("Financials saved"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not save"),
            onFinish: () => setSaving(false),
        });
    };

    const [invoicing, setInvoicing] = useState(false);
    const generateInvoice = () => {
        setInvoicing(true);
        router.post(`${immBase()}/cases/${lead.id}/financials/invoice`, {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Invoice generated — see Documents"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not generate invoice"),
            onFinish: () => setInvoicing(false),
        });
    };

    return (
        <div className="space-y-5">
            {/* Summary */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <Stat label="Total payable" value={fmt(t.payable)} />
                <Stat label="Paid" value={fmt(t.paid)} tone="emerald" />
                <Stat label="Owed" value={fmt(t.owed)} tone={Number(t.owed) > 0 ? "rose" : "gray"} />
                <div className="rounded-xl border border-gray-100 bg-white p-3.5 flex flex-col justify-center">
                    <span className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-400">Status</span>
                    {t.settled ? (
                        <span className="mt-1 inline-flex items-center gap-1.5 text-sm font-bold text-emerald-600"><CheckCircle2 size={15} /> Settled</span>
                    ) : (
                        <span className="mt-1 inline-flex items-center gap-1.5 text-sm font-bold text-amber-600"><AlertCircle size={15} /> Outstanding</span>
                    )}
                    <span className="text-[10.5px] text-gray-400 mt-0.5">Net after disbursement {fmt(t.net_after_disbursement)}</span>
                </div>
            </div>

            {/* Fees + invoice */}
            <section className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
                <div className="flex items-center justify-between gap-3 flex-wrap mb-4">
                    <h3 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500 inline-flex items-center gap-2">
                        <DollarSign size={14} /> Fees &amp; invoice
                    </h3>
                    <div className="flex items-center gap-1.5">
                        <a href={`${immBase()}/cases/${lead.id}/financials/invoice/preview`} target="_blank" rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-[11px] font-semibold hover:bg-gray-50">
                            <Eye size={13} /> Preview invoice
                        </a>
                        <button type="button" onClick={generateInvoice} disabled={invoicing}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black disabled:opacity-50">
                            <FileDown size={13} /> {invoicing ? "Generating…" : "Generate invoice"}
                        </button>
                    </div>
                </div>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <Money label="Service fee (normal)" value={form.service_fee_normal} onChange={(v) => set("service_fee_normal", v)} />
                    <Money label="Service fee (chargeable)" value={form.service_fee_chargeable} onChange={(v) => set("service_fee_chargeable", v)} />
                    <Money label="INZ fee + other" value={form.inz_fee} onChange={(v) => set("inz_fee", v)} />
                    <Money label="Other fee" value={form.other_fee} onChange={(v) => set("other_fee", v)} />
                    <Money label="Disbursement" value={form.disbursement} onChange={(v) => set("disbursement", v)} placeholder="defaults to INZ fee" />
                    <Field label="Payment type">
                        <select value={form.payment_type} onChange={(e) => set("payment_type", e.target.value)} className={inputCls}>
                            {PAYMENT_TYPES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </Field>
                    <Text label="Invoice no." value={form.invoice_no} onChange={(v) => set("invoice_no", v)} placeholder="INV-0000" />
                    <Field label="Invoice sent">
                        <input type="date" value={form.invoice_sent_at || ""} onChange={(e) => set("invoice_sent_at", e.target.value)} className={inputCls} />
                    </Field>
                    <Text label="INZ fee paid to" value={form.inz_fee_paid_to} onChange={(v) => set("inz_fee_paid_to", v)} placeholder="Hendry / eP" />
                    <Text label="Issued from" value={form.issued_from} onChange={(v) => set("issued_from", v)} placeholder="TD / eP / ePM" />
                    <Text label="Currency" value={form.currency} onChange={(v) => set("currency", v)} />
                    <Text label="Referred by" value={form.referred_by} onChange={(v) => set("referred_by", v)} placeholder="e.g. Tarun" icon={<UserPlus size={12} />} />
                </div>
                <div className="mt-3">
                    <span className="block text-[11px] font-semibold text-gray-600 mb-1">Notes</span>
                    <textarea value={form.notes} onChange={(e) => set("notes", e.target.value)} rows={2}
                        className="w-full text-[13px] px-2.5 py-1.5 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:bg-white focus:border-gray-300 resize-y" />
                </div>
                <button type="button" onClick={saveFees} disabled={saving}
                    className="mt-3 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black disabled:opacity-50">
                    <Save size={13} /> Save financials
                </button>
            </section>

            {/* Payment ledger */}
            <PaymentLedger leadId={lead.id} payments={financials.payments || []} fmt={fmt} owed={t.owed} />

            {/* Installment schedule (pay later) — which scheduled payments are paid */}
            <PaymentSchedule
                leadId={lead.id}
                plan={financials.installment_plan}
                config={financials.installment_config}
                defaults={{ total: t.payable, inz: r.inz_fee }}
                fmt={fmt}
            />
        </div>
    );
}

// Pay-later installment plan: deposit + weekly schedule, each row paid or not,
// with a Mark-paid action. If no plan yet, staff set the terms to generate it.
function PaymentSchedule({ leadId, plan, config, defaults = {}, fmt }) {
    const presets = config?.presets || { 3: 13, 6: 26 };
    const dep = config?.deposit || { default: 25, min: 20, max: 30 };
    const monthsList = Object.keys(presets).map(Number).sort((a, b) => a - b);

    const [months, setMonths] = useState(monthsList[0] || 3);
    const [pct, setPct] = useState(dep.default);
    const [total, setTotal] = useState(defaults.total || "");
    const [inz, setInz] = useState(defaults.inz || "");
    const [saving, setSaving] = useState(false);
    const [payingId, setPayingId] = useState(null);

    const base = `${immBase()}/cases/${leadId}`;
    const active = plan && (plan.status === "active" || plan.status === "completed");

    const weeks = presets[months] || months * 4;
    const deposit = Math.min(Math.max((Number(total) * Number(pct)) / 100, Number(inz || 0)), Number(total || 0));
    const financed = Math.max(0, Number(total || 0) - deposit);
    const weekly = weeks > 0 ? financed / weeks : 0;

    const createPlan = () => {
        if (!Number(total)) { toast.error("Enter the total payable"); return; }
        setSaving(true);
        router.post(`${base}/installment-plan`,
            { total_amount: Number(total), inz_fee: Number(inz || 0), plan_months: Number(months), deposit_percent: Number(pct) },
            { preserveScroll: true, preserveState: true, onFinish: () => setSaving(false) });
    };
    const markPaid = (row) => {
        if (!window.confirm(`Record ${fmt(row.amount)} as paid?`)) return;
        setPayingId(row.id);
        router.post(`${base}/installments/${row.id}/pay`, {},
            { preserveScroll: true, preserveState: true, onFinish: () => setPayingId(null) });
    };

    return (
        <section className="rounded-2xl border border-gray-100 bg-white shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
                <Calendar size={14} className="text-gray-500" />
                <h3 className="text-[13px] font-bold text-gray-900">Payment plan</h3>
                <span className="text-[11px] text-gray-400">· pay-later schedule</span>
            </div>

            {!active ? (
                <div className="p-5">
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <label className="block"><span className="text-[11px] font-semibold text-gray-500">Total payable</span>
                            <input type="number" min="0" step="0.01" value={total} onChange={(e) => setTotal(e.target.value)} className={inputCls} /></label>
                        <label className="block"><span className="text-[11px] font-semibold text-gray-500">INZ fee</span>
                            <input type="number" min="0" step="0.01" value={inz} onChange={(e) => setInz(e.target.value)} className={inputCls} /></label>
                        <label className="block"><span className="text-[11px] font-semibold text-gray-500">Plan length</span>
                            <select value={months} onChange={(e) => setMonths(Number(e.target.value))} className={inputCls + " bg-white"}>
                                {monthsList.map((m) => <option key={m} value={m}>{m} month{m === 1 ? "" : "s"} · {presets[m]} wks</option>)}
                            </select></label>
                        <label className="block"><span className="text-[11px] font-semibold text-gray-500">Deposit % ({dep.min}–{dep.max})</span>
                            <input type="number" min={dep.min} max={dep.max} step="1" value={pct} onChange={(e) => setPct(e.target.value)} className={inputCls} /></label>
                    </div>
                    <p className="mt-2 text-[11px] text-gray-500">Deposit <span className="font-semibold text-gray-700">{fmt(deposit)}</span> (incl. INZ) · then <span className="font-semibold text-gray-700">{fmt(weekly)}</span> / week × {weeks} · financed {fmt(financed)}</p>
                    <button type="button" onClick={createPlan} disabled={saving}
                        className="mt-3 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black disabled:opacity-50">
                        <Plus size={13} /> Create payment plan
                    </button>
                </div>
            ) : (
                <>
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 p-4">
                        <Stat label="Deposit" value={fmt(plan.deposit_amount)} />
                        <Stat label={`Weekly × ${plan.weeks}`} value={fmt(plan.weekly_amount)} />
                        <Stat label="Paid" value={fmt(plan.total_paid)} tone="emerald" />
                        <Stat label="Outstanding" value={fmt(plan.amount_owed)} />
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-[12.5px]">
                            <thead className="bg-gray-50 text-gray-400 text-[10px] uppercase tracking-wider">
                                <tr><th className="text-left px-4 py-2 font-bold">Payment</th><th className="text-left px-4 py-2 font-bold">Due</th><th className="text-right px-4 py-2 font-bold">Amount</th><th className="text-right px-4 py-2 font-bold">Status</th><th className="px-4 py-2"></th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {(plan.schedule || []).map((row) => (
                                    <tr key={row.id} className={row.status === "overdue" ? "bg-rose-50/40" : ""}>
                                        <td className="px-4 py-2 text-gray-700">{row.kind === "deposit" ? "Deposit" : `Week ${row.sequence}`}</td>
                                        <td className="px-4 py-2 text-gray-500">{row.due_date}</td>
                                        <td className="px-4 py-2 text-right tabular-nums text-gray-900">{fmt(row.amount)}</td>
                                        <td className="px-4 py-2 text-right">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${
                                                row.status === "paid" ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                                    : row.status === "overdue" ? "bg-rose-50 text-rose-600 border-rose-200"
                                                    : "bg-gray-50 text-gray-500 border-gray-200"}`}>{row.status}</span>
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            {row.status !== "paid" && (
                                                <button type="button" onClick={() => markPaid(row)} disabled={payingId === row.id}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-bold border border-gray-900 text-gray-900 hover:bg-gray-900 hover:text-white disabled:opacity-50">
                                                    <CheckCircle2 size={11} /> Mark paid
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {plan.status === "completed" && (
                        <p className="px-5 py-3 text-[12px] font-semibold text-emerald-700 inline-flex items-center gap-1.5"><CheckCircle2 size={14} /> Fully paid.</p>
                    )}
                </>
            )}
        </section>
    );
}

const inputCls = "w-full px-2.5 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-[13px] focus:outline-none focus:bg-white focus:border-gray-300";

function Field({ label, children }) {
    return (
        <div>
            <span className="block text-[11px] font-semibold text-gray-600 mb-1">{label}</span>
            {children}
        </div>
    );
}
function Money({ label, value, onChange, placeholder }) {
    return (
        <Field label={label}>
            <input type="number" step="0.01" min="0" value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className={inputCls} />
        </Field>
    );
}
function Text({ label, value, onChange, placeholder, icon }) {
    return (
        <Field label={<span className="inline-flex items-center gap-1">{icon}{label}</span>}>
            <input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className={inputCls} />
        </Field>
    );
}
function Stat({ label, value, tone = "gray" }) {
    const c = tone === "emerald" ? "text-emerald-600" : tone === "rose" ? "text-rose-600" : "text-gray-900";
    return (
        <div className="rounded-xl border border-gray-100 bg-white p-3.5">
            <span className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-400">{label}</span>
            <p className={`text-xl font-bold tabular-nums mt-1 ${c}`}>{value}</p>
        </div>
    );
}

function PaymentLedger({ leadId, payments, fmt, owed }) {
    const [row, setRow] = useState({ paid_at: "", amount: "", method: "", reference: "" });
    const [adding, setAdding] = useState(false);

    const add = () => {
        if (! row.paid_at || ! row.amount) return toast.error("Date and amount are required");
        setAdding(true);
        router.post(`${immBase()}/cases/${leadId}/financials/payments`, row, {
            preserveScroll: true,
            onSuccess: () => { toast.success("Payment recorded"); setRow({ paid_at: "", amount: "", method: "", reference: "" }); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not record"),
            onFinish: () => setAdding(false),
        });
    };
    const remove = (id) => {
        if (! window.confirm("Remove this payment?")) return;
        router.delete(`${immBase()}/cases/${leadId}/financials/payments/${id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success("Payment removed"),
        });
    };

    return (
        <section className="rounded-2xl border border-gray-100 bg-white shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-2">
                <h3 className="text-[12px] font-bold uppercase tracking-[0.12em] text-gray-500 inline-flex items-center gap-2">
                    <Receipt size={14} /> Payment ledger
                </h3>
                <span className="text-[11px] text-gray-400">Owed <span className={`font-bold tabular-nums ${Number(owed) > 0 ? "text-rose-600" : "text-emerald-600"}`}>{fmt(owed)}</span></span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="bg-gray-50/60 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                            <th className="text-left px-4 py-2">Date</th>
                            <th className="text-left px-4 py-2">Amount</th>
                            <th className="text-left px-4 py-2">Method</th>
                            <th className="text-left px-4 py-2">Reference</th>
                            <th className="text-left px-4 py-2">By</th>
                            <th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {payments.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-4 text-[12px] text-gray-400">No payments recorded yet.</td></tr>
                        )}
                        {payments.map((p) => (
                            <tr key={p.id}>
                                <td className="px-4 py-2 text-[12px] text-gray-700 tabular-nums">{p.paid_at}</td>
                                <td className="px-4 py-2 text-[13px] font-semibold text-gray-900 tabular-nums">{fmt(p.amount)}</td>
                                <td className="px-4 py-2 text-[12px] text-gray-600">{p.method || "—"}</td>
                                <td className="px-4 py-2 text-[12px] text-gray-600">{p.reference || "—"}</td>
                                <td className="px-4 py-2 text-[11px] text-gray-400">{p.recorded_by || "—"}</td>
                                <td className="px-4 py-2 text-right">
                                    <button type="button" onClick={() => remove(p.id)} className="text-gray-300 hover:text-rose-600"><Trash2 size={14} /></button>
                                </td>
                            </tr>
                        ))}
                        {/* Add row */}
                        <tr className="bg-gray-50/40">
                            <td className="px-4 py-2"><input type="date" value={row.paid_at} onChange={(e) => setRow({ ...row, paid_at: e.target.value })} className={inputCls} /></td>
                            <td className="px-4 py-2"><input type="number" step="0.01" min="0" placeholder="0.00" value={row.amount} onChange={(e) => setRow({ ...row, amount: e.target.value })} className={inputCls} /></td>
                            <td className="px-4 py-2"><input placeholder="Bank / CC / cash" value={row.method} onChange={(e) => setRow({ ...row, method: e.target.value })} className={inputCls} /></td>
                            <td className="px-4 py-2"><input placeholder="e.g. Instalment 1" value={row.reference} onChange={(e) => setRow({ ...row, reference: e.target.value })} className={inputCls} /></td>
                            <td className="px-4 py-2"></td>
                            <td className="px-4 py-2 text-right">
                                <button type="button" onClick={add} disabled={adding}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-gray-900 text-white text-[11px] font-semibold hover:bg-black disabled:opacity-50">
                                    <Plus size={12} /> Add
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    );
}
