import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import {
    ArrowLeft, Pencil, Home, Users, Activity, MapPin, Wallet, Plug,
    UserCog, FileText, Lock, Flame, Plus,
} from "lucide-react";

const OCCUPANCY_STYLES = {
    full: "bg-emerald-50 text-emerald-700",
    partial: "bg-amber-50 text-amber-700",
    vacant: "bg-rose-50 text-rose-600",
};
const TENANT_STATUS_STYLES = {
    active: "bg-emerald-50 text-emerald-700", notice_given: "bg-amber-50 text-amber-700",
    vacating: "bg-orange-50 text-orange-700", vacated: "bg-gray-100 text-gray-500",
    breached: "bg-rose-50 text-rose-600",
};

const money = (v) => (v == null || v === "" ? "—" : `$${Number(v).toLocaleString("en-NZ", { minimumFractionDigits: 2 })}`);
const fmtDate = (v) => (v ? String(v).slice(0, 10) : "—");
const show = (v) => (v == null || v === "" ? "—" : v);
const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, " ") : s);

function Row({ label, value, mono = false }) {
    return (
        <div className="flex justify-between gap-4 py-2 border-b border-gray-50 last:border-0">
            <span className="text-sm text-gray-500">{label}</span>
            <span className={`text-sm font-medium text-gray-900 text-right ${mono ? "font-mono" : ""}`}>{value}</span>
        </div>
    );
}

function Card({ title, icon, internal = false, children }) {
    return (
        <div className="rounded-3xl border border-gray-50 bg-white p-6 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                {icon}
                <h2 className="font-semibold text-gray-900">{title}</h2>
                {internal && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">
                        <Lock size={10} /> Internal
                    </span>
                )}
            </div>
            <div>{children}</div>
        </div>
    );
}

export default function PropertyDetail({ property, tenants = [], historicalTenants = [] }) {
    const [tab, setTab] = useState("overview");
    const tabs = [
        { key: "overview", label: "Overview", icon: <Home size={16} /> },
        { key: "tenants", label: "Tenants", icon: <Users size={16} /> },
        { key: "activity", label: "Activity", icon: <Activity size={16} /> },
    ];

    return (
        <div className="max-w-5xl mx-auto space-y-6">
            <Head title={property.address || property.name} />

            <Link href="/portal/accommodation/properties" className="inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-900">
                <ArrowLeft size={16} /> Back to properties
            </Link>

            {/* Header */}
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-4">
                    <div className="h-16 w-16 shrink-0 overflow-hidden rounded-2xl bg-gray-100">
                        {property.cover_image ? (
                            <img src={property.cover_image} alt="" className="h-full w-full object-cover" />
                        ) : (
                            <div className="flex h-full w-full items-center justify-center text-gray-300"><Home size={24} /></div>
                        )}
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            {property.code && <span className="rounded-lg bg-gray-100 px-2 py-0.5 text-sm font-bold text-gray-700">#{property.code}</span>}
                            <h1 className="text-2xl font-bold text-gray-900">{property.address || property.name}</h1>
                        </div>
                        <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                            {[property.city || property.suburb, property.region, property.property_type].filter(Boolean).join(" · ") || "—"}
                            {property.occupancy_status && (
                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${OCCUPANCY_STYLES[property.occupancy_status] ?? "bg-gray-100 text-gray-500"}`}>
                                    {cap(property.occupancy_status)}
                                </span>
                            )}
                            {!property.is_active && (
                                <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-500">Archived</span>
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={`/portal/accommodation/purchase-invoices?property=${property.id}`} className="rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Invoices &amp; Receipts</Link>
                    <Link href={`/portal/accommodation/properties/${property.id}/edit`} className="inline-flex items-center gap-2 rounded-full bg-[#1F5A8B] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#184A73]">
                        <Pencil size={16} /> Edit
                    </Link>
                </div>
            </div>

            {/* Tabs */}
            <div className="flex gap-1 border-b border-gray-100">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setTab(t.key)}
                        className={`flex items-center gap-2 px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition-colors ${
                            tab === t.key
                                ? "border-[#1F5A8B] text-[#1F5A8B]"
                                : "border-transparent text-gray-500 hover:text-gray-900"
                        }`}
                    >
                        {t.icon} {t.label}
                        {t.key === "tenants" && property.total_rooms ? (
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{property.rooms_occupied ?? 0}/{property.total_rooms}</span>
                        ) : null}
                    </button>
                ))}
            </div>

            {/* Overview */}
            {tab === "overview" && (
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Card title="Property details" icon={<MapPin size={18} className="text-[#1F5A8B]" />}>
                        <Row label="Address" value={show(property.address)} />
                        <Row label="City" value={show(property.city)} />
                        <Row label="Region" value={show(property.region)} />
                        <Row label="Type" value={show(property.property_type)} />
                        <Row label="Total rooms" value={show(property.total_rooms)} />
                        <Row label="Occupied" value={property.total_rooms ? `${property.rooms_occupied ?? 0}/${property.total_rooms}` : (property.rooms_occupied ?? 0)} />
                        {!property.total_rooms && property.rooms_occupied > 0 && (
                            <p className="mt-2 text-xs text-amber-600">Set total rooms to see occupancy.</p>
                        )}
                    </Card>

                    <Card title="Public listing" icon={<Home size={18} className="text-[#1F5A8B]" />}>
                        <Row label="Listing name" value={show(property.name)} />
                        <Row label="Rental mode" value={property.rental_mode === "whole_property" ? "Whole property" : "Per room"} />
                        {property.rental_mode === "whole_property" ? (
                            <>
                                <Row label="Rent" value={property.whole_property_rent_weekly != null ? `${money(property.whole_property_rent_weekly)}/wk` : "—"} />
                                <Row label="Bedrooms" value={show(property.bedrooms)} />
                                <Row label="Bathrooms" value={show(property.bathrooms)} />
                            </>
                        ) : (
                            <>
                                <Row label="Room type" value={show(property.room_type)} />
                                <Row label="Rent (single)" value={property.rent_single != null ? `${money(property.rent_single)}/wk` : "—"} />
                                <Row label="Rent (couple)" value={property.rent_couple != null ? `${money(property.rent_couple)}/wk` : "—"} />
                            </>
                        )}
                        <Row label="Listing status" value={show(property.status)} />
                        {property.rental_mode === "whole_property" && Array.isArray(property.rooms_layout) && property.rooms_layout.length > 0 && (
                            <div className="mt-4">
                                <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Rooms ({property.rooms_layout.length})</p>
                                <div className="overflow-x-auto rounded-xl border border-gray-100">
                                    <table className="w-full text-xs">
                                        <thead className="bg-gray-50 text-left text-[10px] uppercase tracking-wider text-gray-500">
                                            <tr>
                                                <th className="px-2 py-1.5 font-semibold">Room</th>
                                                <th className="px-2 py-1.5 font-semibold">Type</th>
                                                <th className="px-2 py-1.5 font-semibold">Bed</th>
                                                <th className="px-2 py-1.5 font-semibold">Ensuite</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {property.rooms_layout.map((row, idx) => (
                                                <tr key={idx} className="border-t border-gray-100">
                                                    <td className="px-2 py-1.5 font-semibold text-gray-900">{row.name || "—"}</td>
                                                    <td className="px-2 py-1.5 text-gray-700">{row.type || "—"}</td>
                                                    <td className="px-2 py-1.5 text-gray-700">{row.bed || "—"}</td>
                                                    <td className="px-2 py-1.5 text-gray-700">{row.ensuite || "—"}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </Card>

                    <Card title="Property manager" icon={<UserCog size={18} className="text-[#1F5A8B]" />} internal>
                        <Row label="Name" value={show(property.property_manager_name)} />
                        <Row label="Phone" value={show(property.property_manager_phone)} />
                        <Row label="Email" value={show(property.property_manager_email)} />
                        <Row label="Payment schedule" value={show(property.pm_payment_schedule)} />
                    </Card>

                    <Card title="Bond & advance" icon={<Wallet size={18} className="text-[#1F5A8B]" />} internal>
                        <Row label="Bond total" value={money(property.bond_total_nzd)} />
                        <Row label="Advance total" value={money(property.advance_total_nzd)} />
                    </Card>

                    <Card title="Utilities & access codes" icon={<Plug size={18} className="text-[#1F5A8B]" />} internal>
                        <Row label="Mercury account #" value={show(property.mercury_account_number)} mono />
                        <Row label="Account holder" value={show(property.mercury_account_holder)} />
                        <Row label="Property ICP" value={show(property.property_icp)} mono />
                        <Row label="House / door code" value={show(property.house_code)} mono />
                        <Row label="Wifi password" value={show(property.internet_passcode)} mono />
                        <Row label="Power due" value={fmtDate(property.power_due_date)} />
                        <Row label="Water due" value={fmtDate(property.water_due_date)} />
                        <Row label="Internet due" value={fmtDate(property.internet_due_date)} />
                    </Card>

                    <Card title="Gas" icon={<Flame size={18} className="text-[#1F5A8B]" />} internal>
                        <Row label="Bottled (LPG) gas" value={property.uses_bottled_gas ? "Yes" : "No"} />
                        {property.uses_bottled_gas && <Row label="Last gas purchase" value={fmtDate(property.last_gas_purchase)} />}
                    </Card>

                    {property.notes && (
                        <div className="md:col-span-2">
                            <Card title="Notes" icon={<FileText size={18} className="text-[#1F5A8B]" />} internal>
                                <p className="whitespace-pre-wrap text-sm text-gray-700">{property.notes}</p>
                            </Card>
                        </div>
                    )}

                    {property.images?.length > 0 && (
                        <div className="md:col-span-2">
                            <Card title="Photos" icon={<Home size={18} className="text-[#1F5A8B]" />}>
                                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
                                    {property.images.map((img) => (
                                        <div key={img.id} className="overflow-hidden rounded-xl border border-gray-100">
                                            <img src={img.url} alt="" className="h-28 w-full object-cover" />
                                        </div>
                                    ))}
                                </div>
                            </Card>
                        </div>
                    )}
                </div>
            )}

            {/* Tenants */}
            {tab === "tenants" && (
                <div className="space-y-5">
                    <div className="flex items-center justify-between">
                        <h2 className="font-semibold text-gray-900">Active tenants ({tenants.length})</h2>
                        <Link href={`/portal/accommodation/tenants/create?property_id=${property.id}`} className="inline-flex items-center gap-2 rounded-full bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white hover:bg-[#184A73]">
                            <Plus size={16} /> Add tenant
                        </Link>
                    </div>

                    {tenants.length === 0 ? (
                        <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-12 text-center text-sm text-gray-500">
                            No active tenants at this property.
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-3xl border border-gray-50 bg-white shadow-sm">
                            <table className="w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                    <tr>
                                        <th className="px-5 py-3 font-semibold">Tenant</th>
                                        <th className="px-5 py-3 font-semibold">Unit</th>
                                        <th className="px-5 py-3 font-semibold">Contract</th>
                                        <th className="px-5 py-3 font-semibold">Days to end</th>
                                        <th className="px-5 py-3 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {tenants.map((t) => (
                                        <tr key={t.id} className="cursor-pointer hover:bg-gray-50/50" onClick={() => (window.location.href = `/portal/accommodation/tenants/${t.id}`)}>
                                            <td className="px-5 py-3 font-semibold text-gray-900">{t.display_name}</td>
                                            <td className="px-5 py-3 text-gray-600">{t.unit || "—"}</td>
                                            <td className="px-5 py-3 text-gray-600">{fmtDate(t.contract_start)} → {fmtDate(t.contract_end)}</td>
                                            <td className={`px-5 py-3 ${t.days_to_end != null && t.days_to_end <= 25 ? "font-semibold text-amber-600" : "text-gray-600"}`}>{t.days_to_end ?? "—"}</td>
                                            <td className="px-5 py-3">
                                                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${TENANT_STATUS_STYLES[t.current_status] ?? "bg-gray-100 text-gray-500"}`}>{cap(t.current_status)}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {historicalTenants.length > 0 && (
                        <details className="rounded-3xl border border-gray-50 bg-white p-5 shadow-sm">
                            <summary className="cursor-pointer text-sm font-semibold text-gray-700">Historical tenants ({historicalTenants.length})</summary>
                            <div className="mt-3 divide-y divide-gray-50">
                                {historicalTenants.map((t) => (
                                    <Link key={t.id} href={`/portal/accommodation/tenants/${t.id}`} className="flex items-center justify-between py-2 text-sm hover:text-[#1F5A8B]">
                                        <span className="font-medium text-gray-800">{t.display_name}</span>
                                        <span className="text-xs text-gray-500">ended {fmtDate(t.ended_at)}</span>
                                    </Link>
                                ))}
                            </div>
                        </details>
                    )}
                </div>
            )}

            {/* Activity (placeholder) */}
            {tab === "activity" && (
                <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-16 text-center">
                    <Activity className="mx-auto mb-3 text-gray-300" size={40} />
                    <p className="font-semibold text-gray-900">Activity log coming soon</p>
                    <p className="mx-auto mt-1 max-w-md text-sm text-gray-500">
                        Changes to this property, gas deliveries, and utility due-date history will be tracked here.
                    </p>
                </div>
            )}
        </div>
    );
}
