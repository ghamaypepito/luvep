import { useEffect, useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { toast } from "sonner";
import { ChevronLeft, ChevronRight } from "lucide-react";

const nzd = new Intl.NumberFormat("en-NZ", { style: "currency", currency: "NZD" });
const money = (v) => nzd.format(Number(v || 0));
const cents = value => Math.round(Number(value || 0) * 100);

function weeklyDue(tenant, week, finances) {
    const record = tenant.weekly?.[week];
    if (record?.due == null) return null;
    if (record.captured) return record.due;
    return (cents(finances?.rent) + cents(finances?.util)) / 100;
}

// Mirror of App\Support\RentRoll::status for live client-side recompute.
function paymentStatus(due, paid) {
    if (due == null) return { text: "Due unavailable", tone: "text-gray-500" };
    if (paid == null && due > 0) return { text: "Payment not recorded", tone: "text-gray-500" };
    const d = Math.round(due * 100) / 100;
    if (d <= 0) return { text: "Set weekly rent & utilities", tone: "text-amber-600 font-semibold" };
    const diff = Math.round((paid - d) * 100) / 100;
    if (diff === 0) return { text: "Paid in full", tone: "text-emerald-600 font-semibold" };
    if (diff < 0) return { text: `Underpaid — short ${money(-diff)}`, tone: "text-rose-600 font-semibold" };
    return { text: `Overpaid — credit ${money(diff)}`, tone: "text-amber-600 font-semibold" };
}

const shiftDate = (isoDate, days) => {
    const d = new Date(`${isoDate}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
};

export default function RentUtilities({ groups = [], weeks = [], window: win = { start: "", weeks: 3 } }) {
    // Local payment overrides: { [tenantId]: { [weekStart]: number } }
    const [paid, setPaid] = useState(() => {
        const map = {};
        for (const g of groups) for (const t of g.tenants) {
            map[t.id] = {
                ...map[t.id],
                ...Object.fromEntries(Object.entries(t.weekly || {}).map(([week, record]) => [week, record.received])),
            };
        }
        return map;
    });

    // Local weekly rent / utilities per tenant (editable; saved to the tenant).
    const [fin, setFin] = useState(() => {
        const map = {};
        for (const g of groups) for (const t of g.tenants) {
            map[t.id] = { rent: Number(t.weekly_rent_nzd || 0), util: Number(t.weekly_utilities_nzd || 0) };
        }
        return map;
    });

    const weekStarts = useMemo(() => weeks.map((w) => w.start), [weeks]);

    // Window navigation reloads fresh props (no preserveState) so the grid
    // re-seeds from the server for the new window.
    const navigate = (params) => {
        router.get("/portal/accommodation/rent-utilities", { start: win.start, weeks: win.weeks, ...params }, { preserveScroll: true });
    };

    const setCell = (tenantId, weekStart, raw) => {
        const parsed = raw === "" ? null : Number(raw);
        const value = parsed == null || Number.isNaN(parsed) || parsed === 0 ? null : parsed;
        const prev = (paid[tenantId] || {})[weekStart] ?? null;

        setPaid((p) => {
            const next = { ...p, [tenantId]: { ...p[tenantId] } };
            if (value == null) delete next[tenantId][weekStart];
            else next[tenantId][weekStart] = value;
            return next;
        });

        router.patch(
            `/portal/accommodation/rent-utilities/tenants/${tenantId}/payment`,
            { week_start: weekStart, amount: value },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    setPaid((p) => {
                        const next = { ...p, [tenantId]: { ...p[tenantId] } };
                        if (prev == null) delete next[tenantId][weekStart];
                        else next[tenantId][weekStart] = prev;
                        return next;
                    });
                    toast.error("Could not save payment");
                },
            },
        );
    };

    const setRentUtil = (tenantId, field, raw) => {
        const parsed = raw === "" ? 0 : Number(raw);
        const value = Number.isNaN(parsed) || parsed < 0 ? 0 : parsed;
        const prev = fin[tenantId] || { rent: 0, util: 0 };
        const updated = { ...prev, [field]: value };

        setFin((f) => ({ ...f, [tenantId]: updated }));

        router.patch(
            `/portal/accommodation/rent-utilities/tenants/${tenantId}/rent`,
            { weekly_rent_nzd: updated.rent, weekly_utilities_nzd: updated.util },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    setFin((f) => ({ ...f, [tenantId]: prev }));
                    toast.error("Could not save rent / utilities");
                },
            },
        );
    };

    const rowTotals = (t) => {
        const f = fin[t.id] || { rent: 0, util: 0 };
        const rate = (cents(f.rent) + cents(f.util)) / 100;
        const cells = paid[t.id] || {};
        const dates = weekStarts.filter(ws => t.weekly?.[ws]);
        const dues = dates.map(ws => weeklyDue(t, ws, f));
        const totalPaid = dates.reduce((sum, ws) => sum + cents(cells[ws]), 0) / 100;
        const totalDue = dues.includes(null) ? null : dues.reduce((sum, due) => sum + cents(due), 0) / 100;
        const balance = totalDue == null ? null : (cents(totalPaid) - cents(totalDue)) / 100;
        return { rate, totalPaid, totalDue, balance, status: paymentStatus(totalDue, totalPaid) };
    };

    const hasRows = groups.some((g) => g.tenants.length > 0);
    const colSpan = weeks.length + 8;

    return (
        <div className="space-y-5">
            <Head title="Rent & Utilities" />

            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Rent & Utilities</h1>
                    <p className="text-sm text-gray-500">Amounts in NZD. Payments without a weekly charge record use the tenant’s current property and show Due unavailable for past weeks.</p>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={() => navigate({ start: shiftDate(win.start, -7) })} className="rounded-full border border-gray-200 p-2 text-gray-600 hover:bg-gray-50"><ChevronLeft size={16} /></button>
                    <input aria-label="First week to display" type="date" value={win.start} onChange={(e) => e.target.value && navigate({ start: e.target.value })} className="rounded-xl border border-gray-200 px-3 py-2 text-sm" />
                    <select value={win.weeks} onChange={(e) => navigate({ weeks: e.target.value })} className="rounded-xl border border-gray-200 px-3 py-2 text-sm">
                        <option value={3}>3 weeks</option>
                        <option value={12}>12 weeks</option>
                        <option value={18}>18 weeks</option>
                        <option value={26}>26 weeks</option>
                    </select>
                    <button onClick={() => navigate({ start: shiftDate(win.start, 7) })} className="rounded-full border border-gray-200 p-2 text-gray-600 hover:bg-gray-50"><ChevronRight size={16} /></button>
                </div>
            </header>

            {!hasRows ? (
                <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-10 text-center text-sm text-gray-400">No tenant records for these weeks.</div>
            ) : (
                <div className="overflow-x-auto rounded-3xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-max border-collapse text-sm">
                        <thead>
                            <tr className="bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                                <th className="sticky left-0 z-10 bg-gray-50 px-3 py-2 min-w-[180px]">Tenant</th>
                                <th className="px-3 py-2 text-right">Rent</th>
                                <th className="px-3 py-2 text-right">Utilities</th>
                                <th className="px-3 py-2 text-right">Current weekly rate</th>
                                {weeks.map((w) => <th key={w.start} className="px-2 py-2 text-right whitespace-nowrap">{w.label}</th>)}
                                <th className="px-3 py-2 text-right">Total due</th>
                                <th className="px-3 py-2 text-right">Total paid</th>
                                <th className="px-3 py-2 text-right">Balance</th>
                                <th className="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((g) => (
                                <GroupRows key={g.property_id} group={g} weeks={weeks} paid={paid} fin={fin} setCell={setCell} setRentUtil={setRentUtil} rowTotals={rowTotals} colSpan={colSpan} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function Cell({ value, due, onCommit }) {
    const [draft, setDraft] = useState(value ?? "");
    useEffect(() => { setDraft(value ?? ""); }, [value]);

    const commit = () => {
        const a = draft === "" ? null : Number(draft);
        const b = value ?? null;
        if (a !== b) onCommit(draft);
    };

    // Colour payment cells against the weekly due: green = exact match, orange =
    // paid more than due (goes up), red = paid less than due (goes down). Only
    // when a `due` is supplied — the rent/utilities cells pass none and stay plain.
    const num = draft === "" ? null : Number(draft);
    const cents = (x) => Math.round(x * 100);
    let tone = "border-gray-200";
    if (due != null && num != null && !Number.isNaN(num)) {
        if (cents(num) === cents(due)) tone = "border-emerald-400 bg-emerald-50 text-emerald-700";
        else if (cents(num) > cents(due)) tone = "border-amber-400 bg-amber-50 text-amber-700";
        else tone = "border-rose-400 bg-rose-50 text-rose-700";
    }

    return (
        <input
            type="number" step="0.01" min="0"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => { if (e.key === "Enter") e.currentTarget.blur(); }}
            className={`w-20 rounded-lg border px-2 py-1 text-right text-sm font-medium focus:ring-1 focus:ring-[#1F5A8B] ${tone}`}
        />
    );
}

function GroupRows({ group, weeks, paid, fin, setCell, setRentUtil, rowTotals, colSpan }) {
    const totals = weeks.map(({ start }) => {
        const tenants = group.tenants.filter(tenant => tenant.weekly?.[start]);
        const dues = tenants.map(tenant => weeklyDue(tenant, start, fin[tenant.id]));
        return {
            due: !dues.length || dues.includes(null) ? null : dues.reduce((sum, amount) => sum + cents(amount), 0) / 100,
            received: tenants.reduce((sum, tenant) => sum + cents(paid[tenant.id]?.[start]), 0) / 100,
        };
    });

    return (
        <>
            <tr className="bg-[#1F5A8B]/5">
                <td colSpan={colSpan} className="sticky left-0 bg-[#eaf1f7] px-3 py-2 text-sm font-semibold text-[#1F5A8B]">
                    {group.property_code ? `#${group.property_code} · ` : ""}{group.property_address}
                </td>
            </tr>
            {group.tenants.map((t) => {
                const { rate, totalPaid, totalDue, balance, status } = rowTotals(t);
                const cells = paid[t.id] || {};
                const f = fin[t.id] || { rent: 0, util: 0 };
                return (
                    <tr key={t.id} className="border-t border-gray-50 hover:bg-gray-50/50">
                        <td className="sticky left-0 z-10 bg-white px-3 py-1.5 whitespace-nowrap">
                            <div className="font-medium text-gray-800">{t.display_name}</div>
                            {(group.property_code || group.property_address) && (
                                <div className="text-[11px] text-gray-400">{group.property_code ? `#${group.property_code} · ` : ""}{group.property_address}</div>
                            )}
                        </td>
                        <td className="px-2 py-1">{t.can_edit_rates ? <Cell value={f.rent} onCommit={(raw) => setRentUtil(t.id, "rent", raw)} /> : "—"}</td>
                        <td className="px-2 py-1">{t.can_edit_rates ? <Cell value={f.util} onCommit={(raw) => setRentUtil(t.id, "util", raw)} /> : "—"}</td>
                        <td className="px-3 py-1.5 text-right font-semibold text-gray-800">{t.can_edit_rates ? money(rate) : "—"}</td>
                        {weeks.map(({ start }) => {
                            const record = t.weekly?.[start];
                            const due = weeklyDue(t, start, f);
                            const received = cells[start];
                            const comparison = paymentStatus(due, received);
                            const canEnterPastPayment = !record && t.can_edit_rates && !received
                                && (!t.first_tenancy_week || start >= t.first_tenancy_week);
                            return <td key={start} className="min-w-[140px] px-2 py-1">
                                {record ? <>
                                    <div className="text-xs">Due {due == null ? "—" : money(due)}</div>
                                    <div className="flex items-center gap-1 text-xs">Received {t.can_edit_payments
                                        ? <Cell value={received ?? null} due={due} onCommit={raw => setCell(t.id, start, raw)} />
                                        : (received == null ? "—" : money(received))}</div>
                                    <div className={`text-xs ${comparison.tone}`}>{comparison.text}</div>
                                </> : canEnterPastPayment ? (
                                    <div className="flex items-center gap-1 text-xs">Received <Cell onCommit={raw => setCell(t.id, start, raw)} /></div>
                                ) : "—"}
                            </td>;
                        })}
                        <td className="px-3 py-1.5 text-right text-gray-700">{totalDue == null ? "—" : money(totalDue)}</td>
                        <td className="px-3 py-1.5 text-right text-gray-700">{money(totalPaid)}</td>
                        <td className={`px-3 py-1.5 text-right font-semibold ${balance < 0 ? "text-rose-600" : balance > 0 ? "text-amber-600" : "text-gray-700"}`}>{balance == null ? "—" : money(balance)}</td>
                        <td className={`px-3 py-1.5 whitespace-nowrap text-xs ${status.tone}`}>{status.text}</td>
                    </tr>
                );
            })}
            <tr className="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                <th scope="row" colSpan={4} className="px-3 py-2 text-left">Property Total</th>
                {weeks.map((week, index) => <td key={week.start} className="px-2 py-2 text-xs">
                    <div>Due {totals[index].due == null ? "—" : money(totals[index].due)}</div>
                    <div>Received {money(totals[index].received)}</div>
                </td>)}
                <td />
                <td className="px-3 py-2 text-right">{money(totals.reduce((sum, week) => sum + cents(week.received), 0) / 100)}</td>
                <td colSpan={2} />
            </tr>
        </>
    );
}
