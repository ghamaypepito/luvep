import { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Calendar, Clock, Home, MapPin, X, Loader2, Send } from "lucide-react";
import PortalPageHeader from "@/components/portal/PortalPageHeader";
import ViewingsCalendar from "@/components/accommodation/ViewingsCalendar";
import ViewingBookingSchedule from "@/components/accommodation/ViewingBookingSchedule";

const STATUS_CHIP = {
    Pending: "bg-amber-50 text-amber-700 border-amber-200",
    Confirmed: "bg-emerald-50 text-emerald-700 border-emerald-200",
    Completed: "bg-gray-100 text-gray-600 border-gray-200",
    Cancelled: "bg-rose-50 text-rose-700 border-rose-200",
};
const fmt = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—");

export default function AccommodationViewings({ viewings = [], statuses = [], sharedSchedule = null }) {
    const [selectedKey, setSelectedKey] = useState(null);
    const [savingId, setSavingId] = useState(null);
    const [resendingId, setResendingId] = useState(null);

    const rows = useMemo(
        () => (selectedKey ? viewings.filter((v) => v.appointment_date === selectedKey) : viewings),
        [viewings, selectedKey],
    );

    const todayKey = new Date().toISOString().slice(0, 10);
    const upcoming = viewings.filter((v) => v.appointment_date && v.appointment_date >= todayKey && v.status !== "Cancelled").length;
    const pending = viewings.filter((v) => v.status === "Pending").length;
    const confirmed = viewings.filter((v) => v.status === "Confirmed").length;

    const changeStatus = (id, status) => {
        setSavingId(id);
        router.post(`/portal/accommodation/viewings/${id}/status`, { status }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSavingId(null),
        });
    };

    const resend = (id) => {
        setResendingId(id);
        router.post(`/portal/accommodation/viewings/${id}/resend`, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setResendingId(null),
        });
    };

    return (
        <div className="space-y-5 max-w-[1400px] mx-auto pb-12">
            <Head title="Viewings — Accommodation" />
            <PortalPageHeader
                eyebrow="Bookings"
                title="Property Viewings"
                description="Share booking links and manage property viewings."
            />

            {sharedSchedule && <ViewingBookingSchedule schedule={sharedSchedule} />}

            {/* Summary */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                {[
                    { label: "Viewing requests", value: viewings.length, cls: "text-gray-900" },
                    { label: "Upcoming", value: upcoming, cls: "text-[#1F5A8B]" },
                    { label: "Pending", value: pending, cls: "text-amber-600" },
                    { label: "Confirmed", value: confirmed, cls: "text-emerald-600" },
                ].map((s) => (
                    <div key={s.label} className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">{s.label}</p>
                        <p className={`text-2xl font-bold mt-1 ${s.cls}`}>{s.value}</p>
                    </div>
                ))}
            </div>

            {/* Calendar */}
            <ViewingsCalendar viewings={viewings} selectedKey={selectedKey} onSelectDay={setSelectedKey} />

            {/* Table */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3">
                    <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                        {selectedKey ? `Viewings on ${fmt(selectedKey)}` : "All viewings"}
                        {selectedKey && (
                            <button onClick={() => setSelectedKey(null)} className="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800">
                                <X size={13} /> Show all
                            </button>
                        )}
                    </h3>
                    <span className="text-xs text-gray-400">{rows.length} shown</span>
                </div>
                {rows.length === 0 ? (
                    <div className="p-14 text-center text-gray-400">
                        <Calendar size={28} className="mx-auto mb-3 text-gray-300" />
                        <p className="text-sm font-medium">No viewings{selectedKey ? " on this date" : " yet"}.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="bg-gray-50/50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    <th className="px-5 py-2.5">Client</th>
                                    <th className="px-5 py-2.5">Property</th>
                                    <th className="px-5 py-2.5">Schedule</th>
                                    <th className="px-5 py-2.5">Status</th>
                                    <th className="px-5 py-2.5 text-right">Confirmation</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {rows.map((v) => (
                                    <tr key={v.id} className="hover:bg-gray-50/40 align-top">
                                        <td className="px-5 py-3">
                                            <p className="text-sm font-semibold text-gray-900">{v.name}</p>
                                            <p className="text-[11px] text-gray-500">{v.email}{v.phone ? ` · ${v.phone}` : ""}</p>
                                            {v.message && <p className="text-[11px] text-gray-400 mt-0.5 max-w-xs truncate" title={v.message}>“{v.message}”</p>}
                                        </td>
                                        <td className="px-5 py-3 text-sm text-gray-700">
                                            <div className="flex items-center gap-1.5"><Home size={13} className="text-gray-400" /> {v.property || "—"}</div>
                                            {v.property_location && <div className="flex items-center gap-1.5 text-[11px] text-gray-500 mt-0.5"><MapPin size={11} className="text-gray-400" /> {v.property_location}</div>}
                                        </td>
                                        <td className="px-5 py-3 text-sm text-gray-700">
                                            <div className="flex items-center gap-1.5"><Calendar size={13} className="text-gray-400" /> {fmt(v.appointment_date)}</div>
                                            {v.appointment_time && <div className="flex items-center gap-1.5 text-[11px] text-gray-500 mt-0.5"><Clock size={11} className="text-gray-400" /> {v.appointment_time}</div>}
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex items-center gap-2">
                                                <select
                                                    value={v.status}
                                                    disabled={savingId === v.id}
                                                    onChange={(e) => changeStatus(v.id, e.target.value)}
                                                    className={`text-[11px] font-bold uppercase tracking-wider border rounded-lg px-2 py-1 outline-none cursor-pointer ${STATUS_CHIP[v.status] || STATUS_CHIP.Pending}`}
                                                >
                                                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                                                </select>
                                                {savingId === v.id && <Loader2 size={13} className="animate-spin text-gray-400" />}
                                            </div>
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <button
                                                onClick={() => resend(v.id)}
                                                disabled={resendingId === v.id}
                                                className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 border border-gray-200 hover:bg-gray-50 disabled:opacity-50"
                                                title="Re-send the viewing confirmation email"
                                            >
                                                {resendingId === v.id ? <Loader2 size={13} className="animate-spin" /> : <Send size={13} />}
                                                {resendingId === v.id ? "Sending…" : "Resend"}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
