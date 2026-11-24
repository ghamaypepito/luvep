import { useState } from 'react';
import { Head, Link } from "@inertiajs/react";
import { Home, CheckCircle, EyeOff, ClipboardList, Inbox, Plus } from "lucide-react";

function formatDate(value) {
    if (!value) return "—";
    const d = new Date(value);
    return isNaN(d.getTime()) ? "—" : d.toLocaleDateString();
}

function statusBadgeClass(status) {
    switch (status) {
        case "new": return "bg-blue-50 text-blue-700";
        case "reviewed": return "bg-amber-50 text-amber-700";
        case "shortlisted": return "bg-emerald-50 text-emerald-700";
        default: return "bg-gray-100 text-gray-500";
    }
}

export default function AccommodationDashboard({
    propertyStats = {},
    applicationStats = {},
    recentProperties = [],
    recentApplications = [],
    inventoryStats = null,
    inventoryError = null,
}) {
    const [roomFilter, setRoomFilter] = useState('all');
    const money = (v) => (v == null ? "—" : `$${Number(v).toFixed(0)}`);
    const complete = inventoryStats && !inventoryStats.unverified_properties && !inventoryStats.missing_capacity;
    const inventoryRows = (inventoryStats?.rows || []).filter(row => roomFilter === 'all'
        || (roomFilter === 'vacant' ? row.vacant > 0 : roomFilter === 'available' ? row.available_to_let > 0 : row.state !== 'verified'));
    const inventoryCards = [
        ['Active properties', inventoryStats?.properties, 'Active managed property records', '/portal/accommodation/properties'],
        ['Total rooms', inventoryStats && !inventoryStats.missing_capacity ? inventoryStats.rooms : null, 'Recorded physical room capacity', '#room-inventory'],
        ['Current household tenancies', inventoryStats?.households, 'Couples/families count once; not individual people', '/portal/accommodation/tenants'],
        ['Occupied rooms', complete ? inventoryStats.occupied : null, `${inventoryStats?.occupied ?? 0} verified occupied; household totals are separate`, '#room-inventory'],
        ['Vacant rooms', complete ? inventoryStats.vacant : null, `${inventoryStats?.vacant ?? 0} verified vacant; click to view properties`, '#room-inventory', 'vacant'],
        ['Available rooms to let', complete ? inventoryStats.available_to_let : null, 'Excludes bedrooms offered only as a whole-property let', '#room-inventory', 'available'],
        ['Unavailable rooms', complete ? inventoryStats.unavailable : null, 'Verified rooms not available to rent', '#room-inventory'],
    ];

    const cards = [
        { label: "Leads", value: applicationStats.total ?? 0, icon: <ClipboardList className="w-5 h-5" />, foot: <span className="text-xs text-gray-400">{applicationStats.hot ?? 0} hot · {applicationStats.cold ?? 0} cold</span> },
        { label: "New leads", value: applicationStats.new ?? 0, icon: <Inbox className="w-5 h-5" />, foot: <span className="text-xs text-gray-400">awaiting review</span> },
    ];

    return (
        <div className="space-y-6 max-w-7xl mx-auto">
            <Head title="Accommodation Dashboard" />

            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Accommodation</h1>
                    <p className="text-sm text-gray-500">Active properties, household tenancies and verified room inventory.</p>
                </div>
                <Link
                    href="/portal/accommodation/properties/create"
                    className="inline-flex items-center gap-2 rounded-full bg-[#1F5A8B] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#184A73] transition-colors"
                >
                    <Plus size={18} /> New property
                </Link>
            </div>

            {inventoryError && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{inventoryError}</p>}
            {inventoryStats && !complete && <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                {inventoryStats.unverified_properties} properties need a room check; {inventoryStats.unverified_rooms} known-capacity rooms are not verified.
                {' '}{inventoryStats.missing_capacity > 0 && `${inventoryStats.missing_capacity} properties also need a room capacity.`}
                {' '}Unassigned rooms are not assumed vacant. <a href="#room-inventory" onClick={() => setRoomFilter('unverified')} className="underline">Review properties</a>
            </div>}
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-3" aria-label="Property and room totals">
                {inventoryCards.map(([label, value, note, href, filter]) => {
                    const content = <><p className="text-sm text-gray-600">{label}</p><p className="mt-2 text-3xl font-bold">{value ?? '—'}</p><p className="mt-2 text-xs text-gray-500">{note}</p></>;
                    return href.startsWith('#') ? <a key={label} href={href} onClick={() => setRoomFilter(filter || 'all')} className="rounded-3xl border bg-white p-5 shadow-sm hover:border-blue-300">{content}</a>
                        : <Link key={label} href={href} className="rounded-3xl border bg-white p-5 shadow-sm hover:border-blue-300">{content}</Link>;
                })}
            </div>
            <section id="room-inventory" className="rounded-3xl border bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-bold">Room inventory by property</h2>
                    <select aria-label="Room inventory filter" className="rounded-lg border p-2 text-sm" value={roomFilter} onChange={event => setRoomFilter(event.target.value)}>
                        <option value="all">All active properties</option><option value="vacant">Verified vacancies</option><option value="available">Available to let per room</option><option value="unverified">Needs verification</option>
                    </select>
                </div>
                <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr>
                    {['Property', 'Rooms', 'Households', 'Occupied', 'Vacant', 'Unavailable', 'Verification'].map(label => <th className="p-2" key={label}>{label}</th>)}
                </tr></thead><tbody>{inventoryRows.map(row => <tr key={row.id} className="border-t">
                    <td className="p-2"><Link className="font-semibold text-blue-700 underline" href={`/portal/accommodation/properties/${row.id}`}>{row.code ? `#${row.code} · ` : ''}{row.name}</Link>{row.vacancy_notes && <p className="mt-1 whitespace-pre-wrap text-xs text-gray-600">{row.vacancy_notes}</p>}</td>
                    <td className="p-2">{row.total ?? 'Not set'}</td><td className="p-2">{row.households}</td>
                    <td className="p-2">{row.occupied ?? '—'}</td><td className="p-2">{row.vacant ?? '—'}</td><td className="p-2">{row.unavailable ?? '—'}</td>
                    <td className="p-2">{row.state === 'verified' ? `Checked ${row.observed_on}` : 'Needs review'}{row.unlabelled_households > 0 && <p className="text-xs text-amber-700">{row.unlabelled_households} households need room labels</p>}</td>
                </tr>)}</tbody></table></div>
                {!inventoryRows.length && <p className="py-4 text-sm text-gray-500">No matching verified records. This does not establish that unverified properties have no vacancies.</p>}
                <p className="mt-3 text-xs text-gray-500">Room checks become stale when recorded tenancy membership, unit labels or capacity changes. Staff can assign room labels through each tenant record; verified totals do not invent those assignments.</p>
            </section>
            <p className="text-xs text-gray-500">Public listing availability: {propertyStats.available ?? '—'} available / {propertyStats.unavailable ?? '—'} hidden. Listing publication is separate from room vacancy.</p>

            {/* Stat cards */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                {cards.map((c, i) => (
                    <div key={i} className={`p-6 rounded-3xl ${c.dark ? "bg-gray-900 text-white shadow-lg" : "bg-white text-gray-900 border border-gray-50 shadow-sm"}`}>
                        <div className="flex items-center justify-between mb-3">
                            <span className={`text-sm font-medium ${c.dark ? "text-gray-300" : "text-gray-500"}`}>{c.label}</span>
                            <span className={`p-1.5 rounded-lg ${c.dark ? "bg-white/10 text-white" : "bg-gray-100 text-gray-500"}`}>{c.icon}</span>
                        </div>
                        <p className="text-3xl font-bold tracking-tight">{c.value}</p>
                        <div className="mt-2">{c.foot}</div>
                    </div>
                ))}
            </div>

            {/* Recent leads */}
            <div className="bg-white rounded-3xl border border-gray-50 shadow-sm overflow-hidden">
                <div className="flex items-center justify-between px-6 py-5">
                    <h2 className="text-lg font-bold text-gray-900">Recent leads</h2>
                    <Link href="/portal/accommodation/applications" className="text-sm font-semibold text-[#1F5A8B] hover:text-[#184A73]">View all</Link>
                </div>
                {recentApplications.length === 0 ? (
                    <div className="border-t border-gray-50 px-6 py-12 text-center">
                        <ClipboardList className="mx-auto mb-3 text-gray-300" size={36} />
                        <p className="font-semibold text-gray-900">No leads yet</p>
                        <p className="text-sm text-gray-500">Expression of Interest submissions will appear here.</p>
                    </div>
                ) : (
                    <table className="w-full text-sm border-t border-gray-50">
                        <tbody className="divide-y divide-gray-50">
                            {recentApplications.map((a) => (
                                <tr key={a.id} className="hover:bg-gray-50/50">
                                    <td className="px-6 py-4">
                                        <p className="font-semibold text-gray-900">{a.full_legal_name}</p>
                                        <p className="text-xs text-gray-500">{a.room_type_interest || "—"}</p>
                                    </td>
                                    <td className="px-6 py-4">
                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold capitalize ${a.form_type === "hot" ? "bg-rose-50 text-rose-700" : "bg-sky-50 text-sky-700"}`}>
                                            {a.form_type || "cold"}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4">
                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold capitalize ${statusBadgeClass(a.status)}`}>{a.status}</span>
                                    </td>
                                    <td className="px-6 py-4 text-gray-500">{formatDate(a.created_at)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/portal/accommodation/applications/${a.id}`} className="text-sm font-semibold text-gray-500 hover:text-gray-900">View</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Recent properties */}
            <div className="bg-white rounded-3xl border border-gray-50 shadow-sm overflow-hidden">
                <div className="flex items-center justify-between px-6 py-5">
                    <h2 className="text-lg font-bold text-gray-900">Recent properties</h2>
                    <Link href="/portal/accommodation/properties" className="text-sm font-semibold text-[#1F5A8B] hover:text-[#184A73]">View all</Link>
                </div>
                {recentProperties.length === 0 ? (
                    <div className="border-t border-gray-50 px-6 py-12 text-center">
                        <Home className="mx-auto mb-3 text-gray-300" size={36} />
                        <p className="font-semibold text-gray-900">No properties yet</p>
                        <p className="text-sm text-gray-500">Add your first listing to populate the accommodation page.</p>
                    </div>
                ) : (
                    <table className="w-full text-sm border-t border-gray-50">
                        <tbody className="divide-y divide-gray-50">
                            {recentProperties.map((p) => (
                                <tr key={p.id} className="hover:bg-gray-50/50">
                                    <td className="px-6 py-4">
                                        <div className="flex items-center gap-3">
                                            <div className="h-11 w-11 shrink-0 overflow-hidden rounded-xl bg-gray-100">
                                                {p.cover_image ? (
                                                    <img src={p.cover_image} alt="" className="h-full w-full object-cover" />
                                                ) : (
                                                    <div className="flex h-full w-full items-center justify-center text-gray-300"><Home size={16} /></div>
                                                )}
                                            </div>
                                            <div>
                                                <p className="font-semibold text-gray-900">{p.name}</p>
                                                <p className="text-xs text-gray-500 capitalize">{p.room_type} · {p.suburb || p.location || "—"}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 text-gray-700">{money(p.rent_single)}/wk</td>
                                    <td className="px-6 py-4">
                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold ${p.status === "available" ? "bg-emerald-50 text-emerald-700" : "bg-gray-100 text-gray-500"}`}>{p.status}</span>
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/portal/accommodation/properties/${p.id}/edit`} className="text-sm font-semibold text-gray-500 hover:text-gray-900">Edit</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}
