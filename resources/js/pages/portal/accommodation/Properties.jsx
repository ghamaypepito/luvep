import { useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Plus, Pencil, Archive, Eye, Home, Search, Download } from "lucide-react";
import Pagination from "@/components/ui/Pagination";

const OCCUPANCY_STYLES = {
    full: "bg-emerald-50 text-emerald-700",
    partial: "bg-amber-50 text-amber-700",
    vacant: "bg-rose-50 text-rose-600",
};
const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

export default function Properties({ properties = {}, filters = {}, options = {} }) {
    const rows = properties.data ?? [];
    const [search, setSearch] = useState(filters.search ?? "");
    const active = filters.active ?? "active";
    const propertyType = filters.property_type ?? "all";
    const city = filters.city ?? "all";
    const sort = filters.sort ?? "code";
    const hasTenants = Boolean(filters.has_tenants);
    const hasFilters =
        Boolean(search) || active !== "active" || propertyType !== "all" || city !== "all" || hasTenants;

    const applyFilters = (next = {}) => {
        const merged = {
            search: next.search !== undefined ? next.search : search,
            active: next.active !== undefined ? next.active : active,
            property_type: next.property_type !== undefined ? next.property_type : propertyType,
            city: next.city !== undefined ? next.city : city,
            sort: next.sort !== undefined ? next.sort : sort,
            has_tenants: next.has_tenants !== undefined ? next.has_tenants : hasTenants,
        };
        const query = {};
        if (merged.search) query.search = merged.search;
        if (merged.active !== "active") query.active = merged.active;
        if (merged.property_type !== "all") query.property_type = merged.property_type;
        if (merged.city !== "all") query.city = merged.city;
        if (merged.sort !== "code") query.sort = merged.sort;
        if (merged.has_tenants) query.has_tenants = 1;
        router.get("/portal/accommodation/properties", query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Debounce the search box so we don't hit the server on every keystroke.
    useEffect(() => {
        const t = setTimeout(() => {
            if ((filters.search ?? "") !== search) applyFilters({ search });
        }, 400);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const archive = (property) => {
        if (confirm(`Archive "${property.address || property.name}"? It will be hidden from the active list.`)) {
            router.patch(`/portal/accommodation/properties/${property.id}/archive`, {}, {
                preserveScroll: true,
            });
        }
    };

    const select = "rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]";

    return (
        <div className="space-y-6 max-w-7xl mx-auto">
            <Head title="Properties" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Properties</h1>
                    <p className="text-sm text-gray-500">Your managed property portfolio. Public listing details and internal records in one place.</p>
                </div>
                <div className="flex items-center gap-2">
                    <a
                        href="/portal/accommodation/properties/export"
                        className="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                    >
                        <Download size={16} /> Export CSV
                    </a>
                    <Link
                        href="/portal/accommodation/properties/create"
                        className="inline-flex items-center gap-2 rounded-full bg-[#1F5A8B] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#184A73] transition-colors"
                    >
                        <Plus size={18} /> Add property
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-col lg:flex-row gap-3">
                <div className="relative flex-1">
                    <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search code, address, city or property manager…"
                        className="w-full rounded-xl border border-gray-200 bg-white pl-9 pr-4 py-2 text-sm text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]"
                    />
                </div>
                <select value={active} onChange={(e) => applyFilters({ active: e.target.value })} className={select}>
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                    <option value="all">All</option>
                </select>
                <select value={propertyType} onChange={(e) => applyFilters({ property_type: e.target.value })} className={select}>
                    <option value="all">All types</option>
                    {(options.property_types ?? []).map((t) => (
                        <option key={t} value={t}>{t}</option>
                    ))}
                </select>
                {(options.cities ?? []).length > 0 && (
                    <select value={city} onChange={(e) => applyFilters({ city: e.target.value })} className={select}>
                        <option value="all">All cities</option>
                        {options.cities.map((c) => (
                            <option key={c} value={c}>{c}</option>
                        ))}
                    </select>
                )}
                <select value={sort} onChange={(e) => applyFilters({ sort: e.target.value })} className={select}>
                    <option value="code">Sort: Code</option>
                    <option value="address">Sort: Address</option>
                    <option value="latest">Sort: Newest</option>
                </select>
                <button
                    onClick={() => applyFilters({ has_tenants: !hasTenants })}
                    className={`rounded-xl border px-4 py-2 text-sm font-medium shadow-sm ${hasTenants ? "border-[#1F5A8B] bg-[#1F5A8B]/10 text-[#1F5A8B]" : "border-gray-200 bg-white text-gray-600"}`}
                >
                    Has active tenants
                </button>
            </div>

            {rows.length === 0 ? (
                <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-16 text-center">
                    <Home className="mx-auto mb-3 text-gray-300" size={40} />
                    <p className="font-semibold text-gray-900">{hasFilters ? "No matching properties" : "No properties yet"}</p>
                    <p className="text-sm text-gray-500">{hasFilters ? "Try adjusting your search or filters." : "Add your first property to start building the portfolio."}</p>
                </div>
            ) : (
                <div className="overflow-x-auto rounded-3xl border border-gray-50 bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th className="px-5 py-4 font-semibold">#</th>
                                <th className="px-5 py-4 font-semibold">Property</th>
                                <th className="px-5 py-4 font-semibold">Rooms</th>
                                <th className="px-5 py-4 font-semibold">Occupancy</th>
                                <th className="px-5 py-4 font-semibold">Property manager</th>
                                <th className="px-5 py-4 font-semibold">Status</th>
                                <th className="px-5 py-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {rows.map((p) => (
                                <tr key={p.id} className="hover:bg-gray-50/50">
                                    <td className="px-5 py-4 font-semibold text-gray-900">{p.code || "—"}</td>
                                    <td className="px-5 py-4">
                                        <Link href={`/portal/accommodation/properties/${p.id}`} className="flex items-center gap-3 group">
                                            <div className="h-11 w-11 shrink-0 overflow-hidden rounded-xl bg-gray-100">
                                                {p.cover_image ? (
                                                    <img src={p.cover_image} alt="" className="h-full w-full object-cover" />
                                                ) : (
                                                    <div className="flex h-full w-full items-center justify-center text-gray-300">
                                                        <Home size={18} />
                                                    </div>
                                                )}
                                            </div>
                                            <div>
                                                <p className="font-semibold text-gray-900 group-hover:text-[#1F5A8B]">{p.address || p.name}</p>
                                                <p className="text-xs text-gray-500">
                                                    {[p.city || p.suburb, p.property_type].filter(Boolean).join(" · ") || "—"}
                                                </p>
                                            </div>
                                        </Link>
                                    </td>
                                    <td className="px-5 py-4 text-gray-700">
                                        {p.room_summary?.total ? `${p.room_summary.occupied ?? '?'}/${p.room_summary.total}` : "Not set"}
                                    </td>
                                    <td className="px-5 py-4">
                                        {p.room_summary ? (
                                            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">
                                                {p.room_summary.state === 'verified' ? 'Verified' : 'Needs room check'}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className="px-5 py-4 text-gray-700">
                                        {p.property_manager_name ? (
                                            <div>
                                                <p className="text-gray-900">{p.property_manager_name}</p>
                                                {p.pm_payment_schedule && <p className="text-xs text-gray-500">{p.pm_payment_schedule}</p>}
                                            </div>
                                        ) : (
                                            <span className="text-xs text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className="px-5 py-4">
                                        {p.is_active ? (
                                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Active</span>
                                        ) : (
                                            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-500">Archived</span>
                                        )}
                                    </td>
                                    <td className="px-5 py-4">
                                        <div className="flex items-center justify-end gap-1">
                                            <Link href={`/portal/accommodation/properties/${p.id}`} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="View">
                                                <Eye size={16} />
                                            </Link>
                                            <Link href={`/portal/accommodation/properties/${p.id}/edit`} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="Edit">
                                                <Pencil size={16} />
                                            </Link>
                                            {p.is_active && (
                                                <button onClick={() => archive(p)} className="rounded-lg p-2 text-gray-500 hover:bg-amber-50 hover:text-amber-600" title="Archive">
                                                    <Archive size={16} />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Pagination links={properties.links} />
        </div>
    );
}
