import { useEffect, useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { toast } from "sonner";
import { ChevronLeft, ChevronRight } from "lucide-react";

const money = (v) => `$${Number(v || 0).toFixed(2)}`;

// Mirror of App\Support\RentRoll::status for live client-side recompute.
function paymentStatus(due, paid) {
    const d = Math.round(due * 100) / 100;
    if (d <= 0) return { text: "Set weekly rent & utilities", tone: "text-amber-600 font-semibold" };
    const diff = Math.round((paid - d) * 100) / 100;
    if (diff === 0) return { text: "Paid up", tone: "text-emerald-600 font-semibold" };
    if (diff < 0) return { text: `Underpaid — short ${money(-diff)}`, tone: "text-rose-600 font-semibold" };
    return { text: `Overpaid — credit ${money(diff)}`, tone: "text-amber-600 font-semibold" };
}

const shiftDate = (isoDate, days) => {
    const d = new Date(`${isoDate}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
};

export default function RentUtilities({ groups = [], weeks = [], window: win = { start: "", weeks: 18 } }) {
    // Local payment overrides: { [tenantId]: { [weekStart]: number } }
    const [paid, setPaid] = useState(() => {
        const map = {};
        for (const g of groups) for (const t of g.tenants) map[t.id] = { ...(t.payments || {}) };
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
        const weeklyDue = (Number(f.rent) || 0) + (Number(f.util) || 0);
        const cells = paid[t.id] || {};
        const totalPaid = weekStarts.reduce((sum, ws) => sum + (Number(cells[ws]) || 0), 0);
        const totalDue = weeklyDue * weeks.length;
        const balance = totalPaid - totalDue;
        return { weeklyDue, totalPaid, totalDue, balance, status: paymentStatus(totalDue, totalPaid) };
    };

    const hasRows = groups.some((g) => g.tenants.length > 0);
    const colSpan = weeks.length + 8;

    return (
        <div className="space-y-5">
            <Head title="Rent & Utilities" />

            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Rent & Utilities</h1>
                    <p className="text-sm text-gray-500">Weekly rent roll for active tenants. Set each tenant's rent &amp; utilities, then record weekly payments.</p>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={() => navigate({ start: shiftDate(win.start, -7) })} className="rounded-full border border-gray-200 p-2 text-gray-600 hover:bg-gray-50"><ChevronLeft size={16} /></button>
                    <input type="date" value={win.start} onChange={(e) => navigate({ start: e.target.value })} className="rounded-xl border border-gray-200 px-3 py-2 text-sm" />
                    <select value={win.weeks} onChange={(e) => navigate({ weeks: e.target.value })} className="rounded-xl border border-gray-200 px-3 py-2 text-sm">
                        <option value={12}>12 weeks</option>
                        <option value={18}>18 weeks</option>
                        <option value={26}>26 weeks</option>
                    </select>
                    <button onClick={() => navigate({ start: shiftDate(win.start, 7) })} className="rounded-full border border-gray-200 p-2 text-gray-600 hover:bg-gray-50"><ChevronRight size={16} /></button>
                </div>
            </header>

            {!hasRows ? (
                <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-10 text-center text-sm text-gray-400">No active tenants to show.</div>
            ) : (
                <div className="overflow-x-auto rounded-3xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-max border-collapse text-sm">
                        <thead>
                            <tr className="bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                                <th className="sticky left-0 z-10 bg-gray-50 px-3 py-2 min-w-[180px]">Tenant</th>
                                <th className="px-3 py-2 text-right">Rent</th>
                                <th className="px-3 py-2 text-right">Utilities</th>
                                <th className="px-3 py-2 text-right">Total Weekly due</th>
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
    const received = weeks.map(week => group.tenants.reduce((sum, tenant) => (
        sum + Math.round(Number(paid[tenant.id]?.[week.start] || 0) * 100)
    ), 0));

    return (
        <>
            <tr className="bg-[#1F5A8B]/5">
                <td colSpan={colSpan} className="sticky left-0 bg-[#eaf1f7] px-3 py-2 text-sm font-semibold text-[#1F5A8B]">
                    {group.property_code ? `#${group.property_code} · ` : ""}{group.property_address}
                </td>
            </tr>
            {group.tenants.map((t) => {
                const { weeklyDue, totalPaid, totalDue, balance, status } = rowTotals(t);
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
                        <td className="px-2 py-1"><Cell value={f.rent} onCommit={(raw) => setRentUtil(t.id, "rent", raw)} /></td>
                        <td className="px-2 py-1"><Cell value={f.util} onCommit={(raw) => setRentUtil(t.id, "util", raw)} /></td>
                        <td className="px-3 py-1.5 text-right font-semibold text-gray-800">{money(weeklyDue)}</td>
                        {weeks.map((w) => (
                            <td key={w.start} className="px-1 py-1">
                                <Cell value={cells[w.start] ?? null} due={weeklyDue} onCommit={(raw) => setCell(t.id, w.start, raw)} />
                            </td>
                        ))}
                        <td className="px-3 py-1.5 text-right text-gray-700">{money(totalDue)}</td>
                        <td className="px-3 py-1.5 text-right text-gray-700">{money(totalPaid)}</td>
                        <td className={`px-3 py-1.5 text-right font-semibold ${balance < 0 ? "text-rose-600" : balance > 0 ? "text-amber-600" : "text-gray-700"}`}>{money(balance)}</td>
                        <td className={`px-3 py-1.5 whitespace-nowrap text-xs ${status.tone}`}>{status.text}</td>
                    </tr>
                );
            })}
            <tr className="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                <th scope="row" colSpan={4} className="px-3 py-2 text-left">Property Total · received</th>
                {weeks.map((week, index) => <td key={week.start} className="px-2 py-2 text-right">{money(received[index] / 100)}</td>)}
                <td aria-label="Weekly amounts due not captured here" />
                <td className="px-3 py-2 text-right">{money(received.reduce((sum, amount) => sum + amount, 0) / 100)}</td>
                <td colSpan={2} />
            </tr>
        </>
    );
}
