import { Head, router, useForm } from "@inertiajs/react";
import { useRef, useState } from "react";

const tracker = "/portal/accommodation/gas-delivery";
const money = new Intl.NumberFormat("en-NZ", { style: "currency", currency: "NZD" });
const formatPaid = (cents) => money.format(cents / 100);
const field = "mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm";
const button = "rounded-lg bg-blue-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50";

function NewOrder({ today }) {
    const key = useRef(null);
    const form = useForm({ ordered_on: today, bottles: "", amount_paid: "" });

    const save = (event) => {
        event.preventDefault();
        key.current ??= globalThis.crypto.randomUUID();
        form.transform((data) => ({ ...data, submission_key: key.current }));
        form.post(tracker, { preserveScroll: true, onSuccess: () => { key.current = null; form.reset(); } });
    };

    return (
        <form aria-label="Record gas order" onSubmit={save} className="space-y-3 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-bold">Record gas order</h2>
            <div className="grid gap-3 sm:grid-cols-3">
                <label className="text-sm font-medium">Order date
                    <input className={field} type="date" required max={today} value={form.data.ordered_on} onChange={(e) => form.setData("ordered_on", e.target.value)} />
                </label>
                <label className="text-sm font-medium">Gas bottles ordered
                    <input className={field} type="number" required min="1" max="10000" step="1" value={form.data.bottles} onChange={(e) => form.setData("bottles", e.target.value)} />
                </label>
                <label className="text-sm font-medium">Total amount paid (NZD)
                    <input className={field} type="number" required min="0" max="999999" step="0.01" value={form.data.amount_paid} onChange={(e) => form.setData("amount_paid", e.target.value)} />
                </label>
            </div>
            {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-700">{error}</p>)}
            <button className={button} disabled={form.processing}>Save order</button>
        </form>
    );
}

export default function GasDelivery({ property, orders = [], month, today, total_paid_cents }) {
    const [selected, setSelected] = useState(month);

    return (
        <div className="mx-auto max-w-6xl space-y-5">
            <Head title="Gas Delivery Tracker" />
            <h1 className="text-2xl font-bold text-gray-900">Gas Delivery Tracker</h1>
            {!property ? <p role="status" className="rounded-xl bg-white p-5">31 Vazey Way, Hobsonville could not be matched to an active property. Check the Properties record before logging an order.</p> : <>
                <div>
                    <h2 className="text-lg font-semibold">{property.address}</h2>
                    {property.legacy_purchase_date && <p className="text-sm text-gray-600">Previous purchase date on file: {property.legacy_purchase_date}. Bottle count and amount are unavailable for this record.</p>}
                </div>
                <NewOrder today={today} />
                <section aria-label="Gas order history" className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <div className="mb-4 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-bold">Order history</h2>
                            <p className="text-sm text-gray-600">Total paid for {month}: <strong>{formatPaid(total_paid_cents)}</strong></p>
                        </div>
                        <form className="flex items-end gap-2" onSubmit={(event) => {
                            event.preventDefault();
                            router.get(tracker, { month: selected }, { preserveScroll: true });
                        }}>
                            <label className="text-sm font-medium">Month
                                <input className={field} type="month" required value={selected} onChange={(e) => setSelected(e.target.value)} />
                            </label>
                            <button className={button}>Apply</button>
                        </form>
                    </div>
                    {orders.length ? <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-left">
                                <tr><th className="p-3">Order date</th><th className="p-3">Bottles</th><th className="p-3 text-right">Amount paid</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {orders.map((order) => <tr key={order.id}>
                                    <td className="p-3">{order.ordered_on}</td>
                                    <td className="p-3">{order.bottles}</td>
                                    <td className="p-3 text-right tabular-nums">{formatPaid(order.paid_cents)}</td>
                                </tr>)}
                            </tbody>
                        </table>
                    </div> : <p className="text-sm text-gray-600">No gas orders recorded for this month.</p>}
                </section>
            </>}
        </div>
    );
}
