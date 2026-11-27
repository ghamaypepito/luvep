import { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";

const path = "/portal/accommodation/finance/weekly-property-summary";
const currency = new Intl.NumberFormat("en-NZ", { style: "currency", currency: "NZD" });
const cents = amount => Math.round(Number(amount || 0) * 100);
const money = amount => currency.format(amount / 100);
const dateLabel = date => new Date(`${date}T12:00:00Z`).toLocaleDateString("en-NZ", { dateStyle: "medium", timeZone: "UTC" });

function SummaryTable({ rows, week, category }) {
    const [changes, setChanges] = useState({});
    const [saving, setSaving] = useState(null);
    const [errors, setErrors] = useState({});
    const savedValues = row => ({
        version: row.version,
        revenue_nzd: row.revenue_nzd ?? "",
        cost_nzd: row.cost_nzd ?? "",
    });
    const valuesFor = row => changes[row.id] || savedValues(row);
    const total = rows.reduce((sum, row) => {
        const values = valuesFor(row);
        return { revenue: sum.revenue + cents(values.revenue_nzd), cost: sum.cost + cents(values.cost_nzd) };
    }, { revenue: 0, cost: 0 });

    function change(row, key, value) {
        setChanges(current => ({ ...current, [row.id]: { ...(current[row.id] || savedValues(row)), [key]: value } }));
    }

    function save(event, row) {
        event.preventDefault();
        setSaving(row.id);
        setErrors({});
        const values = valuesFor(row);
        router.patch(`${path}/${row.id}`, {
            week: week.start,
            category,
            version: values.version,
            revenue_nzd: values.revenue_nzd === "" ? null : values.revenue_nzd,
            cost_nzd: values.cost_nzd === "" ? null : values.cost_nzd,
        }, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setChanges(current => {
                const next = { ...current };
                delete next[row.id];
                return next;
            }),
            onFinish: () => setSaving(null),
        });
    }

    if (!rows.length) return <p className="rounded-xl bg-white p-4 text-sm text-gray-600">No properties for this week.</p>;

    return <>
        {rows.map(row => <form key={row.id} id={`weekly-property-${row.id}`} onSubmit={event => save(event, row)} />)}
        {Object.keys(errors).length > 0 && <p role="alert" className="text-sm text-red-700">{Object.values(errors).join(" ")}</p>}
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table className="w-full min-w-[560px] text-left text-sm">
                <thead className="bg-gray-50 text-gray-700"><tr>
                    {["Property", "Revenue", "Cost", "Profit"].map(label => <th scope="col" key={label} className="p-3">{label}</th>)}
                </tr></thead>
                <tbody className="divide-y divide-gray-100">{rows.map(row => {
                    const values = valuesFor(row);
                    const form = `weekly-property-${row.id}`;
                    return <tr key={row.id}>
                        <th scope="row" className="p-3 font-medium">{row.name}</th>
                        {[["revenue_nzd", "Revenue"], ["cost_nzd", "Cost"]].map(([key, label]) => <td key={key} className="p-3">
                            <input type="number" inputMode="decimal" min="0" max="99999999.99" step="0.01" form={form}
                                aria-label={`${label} for ${row.name}`} value={values[key]} disabled={saving !== null}
                                onChange={event => change(row, key, event.target.value)} className="w-28 rounded-lg border border-gray-200 p-2" />
                        </td>)}
                        <td className="p-3"><div className="flex flex-wrap items-center justify-between gap-2">
                            <span>{values.revenue_nzd === "" && values.cost_nzd === "" ? "—" : money(cents(values.revenue_nzd) - cents(values.cost_nzd))}</span>
                            <button type="submit" form={form} disabled={!changes[row.id] || saving !== null}
                                aria-label={`Save ${row.name}`} className="rounded-md border border-emerald-900 px-2 py-1 text-emerald-900 disabled:opacity-40">
                                {saving === row.id ? "Saving…" : "Save"}
                            </button>
                        </div></td>
                    </tr>;
                })}</tbody>
                <tfoot className="bg-emerald-950 text-white"><tr>
                    <th scope="row" className="p-3">Weekly Total</th>
                    <td className="p-3">{money(total.revenue)}</td>
                    <td className="p-3">{money(total.cost)}</td>
                    <td className="p-3">{money(total.revenue - total.cost)}</td>
                </tr></tfoot>
            </table>
        </div>
    </>;
}

export default function WeeklyPropertySummary({ rows, week, category }) {
    const weekLink = (date, tab = category) => `${path}?week=${date}&category=${tab}`;
    return <div className="space-y-4">
        <Head title="Weekly Property Summary" />
        <h1 className="text-2xl font-bold text-gray-900">Weekly Property Summary</h1>
        <div className="flex flex-wrap items-center justify-between gap-3">
            <nav aria-label="Revenue and cost categories" className="flex gap-2">
                {[["rent", "Rent"], ["utilities", "Utilities"]].map(([key, label]) => <Link key={key} href={weekLink(week.start, key)}
                    aria-current={category === key ? "page" : undefined}
                    className={`rounded-lg px-3 py-2 text-sm font-medium ${category === key ? "bg-emerald-950 text-white" : "bg-white text-gray-800"}`}>
                    {label}
                </Link>)}
            </nav>
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Link href={weekLink(week.previous)}>Previous week</Link>
                <label>Week <input type="date" value={week.start} onChange={event => {
                    if (event.target.value) router.get(weekLink(event.target.value));
                }} className="rounded-lg border border-gray-200 p-2" /></label>
                <Link href={weekLink(week.next)}>Next week</Link>
            </div>
        </div>
        <p className="text-sm text-gray-600">{dateLabel(week.start)} – {dateLabel(week.end)} · NZD</p>
        <SummaryTable key={`${category}:${week.start}`} rows={rows} week={week} category={category} />
    </div>;
}
