import { useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Plus, Pencil, Eye, Users, Search, Download, Archive, ArchiveRestore } from "lucide-react";
import Pagination from "@/components/ui/Pagination";

const STATUS_STYLES = {
    pending_payment: "bg-amber-50 text-amber-800",
    active: "bg-emerald-50 text-emerald-700",
    notice_given: "bg-amber-50 text-amber-700",
    vacating: "bg-orange-50 text-orange-700",
    vacated: "bg-gray-100 text-gray-500",
    breached: "bg-rose-50 text-rose-600",
};
const STATUS_LABEL = {
    pending_payment: "Pending payment",
    active: "Active", notice_given: "Notice given", vacating: "Vacating",
    vacated: "Vacated", breached: "Breached",
};
const fmtDate = (v) => (v ? String(v).slice(0, 10) : "—");
const money = (v) => (v == null || v === "" ? "—" : `$${Number(v).toFixed(0)}`);

// Row tint mirrors the source spreadsheet: amber when ending soon, red when
// the contract has already ended and the tenant hasn't vacated.
function rowTint(t) {
    if (t.current_status === "vacated") return "";
    if (t.days_to_end != null && t.days_to_end <= 0) return "bg-rose-50/70";
    if (t.days_to_end != null && t.days_to_end <= 25) return "bg-amber-50/60";
    return "";
}
function daysStyle(d) {
    if (d == null) return "text-gray-400";
    if (d <= 0) return "text-rose-600 font-semibold";
    if (d <= 25) return "text-amber-600 font-semibold";
    return "text-gray-600";
}

export default function Tenants({ tenants = {}, filters = {}, options = {} }) {
    const rows = tenants.data ?? [];
    const [search, setSearch] = useState(filters.search ?? "");
    const propertyId = filters.property_id ?? "all";
    const status = filters.status ?? "all";
    const sort = filters.sort ?? "days_to_end";
    const missingDocs = Boolean(filters.missing_docs);
    const hasEmail = Boolean(filters.has_email);
    const archived = Boolean(filters.archived);
    const hasFilters = Boolean(search) || propertyId !== "all" || status !== "all" || missingDocs || hasEmail;

    const applyFilters = (next = {}) => {
        const merged = {
            search: next.search !== undefined ? next.search : search,
            property_id: next.property_id !== undefined ? next.property_id : propertyId,
            status: next.status !== undefined ? next.status : status,
            sort: next.sort !== undefined ? next.sort : sort,
            missing_docs: next.missing_docs !== undefined ? next.missing_docs : missingDocs,
            has_email: next.has_email !== undefined ? next.has_email : hasEmail,
            archived: next.archived !== undefined ? next.archived : archived,
        };
        const query = {};
        if (merged.search) query.search = merged.search;
        if (merged.property_id !== "all") query.property_id = merged.property_id;
        if (merged.status !== "all") query.status = merged.status;
        if (merged.sort !== "days_to_end") query.sort = merged.sort;
        if (merged.missing_docs) query.missing_docs = 1;
        if (merged.has_email) query.has_email = 1;
        if (merged.archived) query.archived = 1;
        router.get("/portal/accommodation/tenants", query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const archiveTenant = (t) => {
        if (confirm(`Archive ${t.display_name}? They'll be hidden from the list but kept on record (you can restore them anytime).`)) {
            router.patch(`/portal/accommodation/tenants/${t.id}/archive`, {}, { preserveScroll: true });
        }
    };
    const restoreTenant = (t) => {
        router.patch(`/portal/accommodation/tenants/${t.id}/restore`, {}, { preserveScroll: true });
    };

    useEffect(() => {
        const tmr = setTimeout(() => {
            if ((filters.search ?? "") !== search) applyFilters({ search });
        }, 400);
        return () => clearTimeout(tmr);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const select = "rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]";
    const toggle = (on) => `rounded-xl border px-4 py-2 text-sm font-medium shadow-sm ${on ? "border-[#1F5A8B] bg-[#1F5A8B]/10 text-[#1F5A8B]" : "border-gray-200 bg-white text-gray-600"}`;

    const Dot = ({ on }) => <span className={`inline-block h-2.5 w-2.5 rounded-full ${on ? "bg-emerald-500" : "bg-rose-400"}`} />;

    return (
        <div className="space-y-6 max-w-7xl mx-auto">
            <Head title="Tenants" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Tenants</h1>
                    <p className="text-sm text-gray-500">Current and past tenants across all managed properties.</p>
                </div>
                <div className="flex items-center gap-2">
                    <a href="/portal/accommodation/tenants/export" className="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50">
                        <Download size={16} /> Export CSV
                    </a>
                    <Link href="/portal/accommodation/tenants/create" className="inline-flex items-center gap-2 rounded-full bg-[#1F5A8B] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#184A73]">
                        <Plus size={18} /> Add tenant
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-col lg:flex-row flex-wrap gap-3">
                <div className="relative flex-1 min-w-[220px]">
                    <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, email or phone…" className="w-full rounded-xl border border-gray-200 bg-white pl-9 pr-4 py-2 text-sm text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]" />
                </div>
                <select value={propertyId} onChange={(e) => applyFilters({ property_id: e.target.value })} className={select}>
                    <option value="all">All properties</option>
                    {(options.properties ?? []).map((p) => (
                        <option key={p.id} value={p.id}>{p.code ? `#${p.code} · ` : ""}{p.address}</option>
                    ))}
                </select>
                <select value={status} onChange={(e) => applyFilters({ status: e.target.value })} className={select}>
                    <option value="all">All statuses</option>
                    {(options.statuses ?? []).map((s) => <option key={s} value={s}>{STATUS_LABEL[s] ?? s}</option>)}
                    <option value="ending_soon">Ending soon</option>
                    <option value="overdue">Overdue</option>
                </select>
                <select value={sort} onChange={(e) => applyFilters({ sort: e.target.value })} className={select}>
                    <option value="days_to_end">Sort: Days to end</option>
                    <option value="property">Sort: Property</option>
                    <option value="name">Sort: Name</option>
                    <option value="contract_end">Sort: Contract end</option>
                </select>
                <button onClick={() => applyFilters({ missing_docs: !missingDocs })} className={toggle(missingDocs)}>Missing docs</button>
                <button onClick={() => applyFilters({ has_email: !hasEmail })} className={toggle(hasEmail)}>Has email</button>
                <button onClick={() => applyFilters({ archived: !archived })} className={toggle(archived)}>Archived</button>
            </div>

            {rows.length === 0 ? (
                <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-16 text-center">
                    <Users className="mx-auto mb-3 text-gray-300" size={40} />
                    <p className="font-semibold text-gray-900">{archived ? "No archived tenants" : hasFilters ? "No matching tenants" : "No tenants yet"}</p>
                    <p className="text-sm text-gray-500">{archived ? "Tenants you archive will appear here." : hasFilters ? "Try adjusting your search or filters." : "Add your first tenant to a property."}</p>
                </div>
            ) : (
                <div className="overflow-x-auto rounded-3xl border border-gray-50 bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th className="px-4 py-4 font-semibold">Property</th>
                                <th className="px-4 py-4 font-semibold">Tenant</th>
                                <th className="px-4 py-4 font-semibold">Contact</th>
                                <th className="px-4 py-4 font-semibold">Contract</th>
                                <th className="px-4 py-4 font-semibold">Days</th>
                                <th className="px-4 py-4 font-semibold">Docs</th>
                                <th className="px-4 py-4 font-semibold">Bond</th>
                                <th className="px-4 py-4 font-semibold">Status</th>
                                <th className="px-4 py-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {rows.map((t) => (
                                <tr key={t.id} className={`hover:bg-gray-50/50 ${rowTint(t)}`}>
                                    <td className="px-4 py-3">
                                        <div className="text-gray-900">{t.property?.address || t.property?.name || "—"}</div>
                                        <div className="text-xs text-gray-500">{t.property?.code ? `#${t.property.code}` : ""}{t.unit ? ` · Unit ${t.unit}` : ""}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={`/portal/accommodation/tenants/${t.id}`} className="font-semibold text-gray-900 hover:text-[#1F5A8B]">{t.display_name}</Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">
                                        <div>{t.email || "—"}</div>
                                        <div className="text-xs text-gray-500">{t.phone || t.whatsapp || ""}</div>
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">
                                        {t.contract_start || t.contract_end ? (
                                            <span>{fmtDate(t.contract_start)} → {fmtDate(t.contract_end)}</span>
                                        ) : (
                                            <span className="text-xs text-gray-400">No dates</span>
                                        )}
                                    </td>
                                    <td className={`px-4 py-3 ${daysStyle(t.days_to_end)}`}>{t.days_to_end ?? "—"}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1" title="Passport · Agreement · Inspection">
                                            <Dot on={t.has_passport_in_drive} />
                                            <Dot on={t.has_tenancy_agreement_in_drive} />
                                            <Dot on={t.has_inspection_report_in_drive} />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{money(t.bond_paid_nzd)}</td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold ${STATUS_STYLES[t.current_status] ?? "bg-gray-100 text-gray-500"}`}>
                                            {STATUS_LABEL[t.current_status] ?? t.current_status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-1">
                                            {archived ? (
                                                <button onClick={() => restoreTenant(t)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-[#1F5A8B] hover:bg-[#1F5A8B]/5" title="Restore"><ArchiveRestore size={14} /> Restore</button>
                                            ) : (
                                                <>
                                                    <Link href={`/portal/accommodation/tenants/${t.id}`} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="View"><Eye size={16} /></Link>
                                                    <Link href={`/portal/accommodation/tenants/${t.id}/edit`} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="Edit"><Pencil size={16} /></Link>
                                                    <button onClick={() => archiveTenant(t)} className="rounded-lg p-2 text-gray-500 hover:bg-amber-50 hover:text-amber-600" title="Archive"><Archive size={16} /></button>
                                                </>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Pagination links={tenants.links} />
        </div>
    );
}
