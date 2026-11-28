import { useState, useEffect, useMemo, useCallback } from "react";
import { Head, usePage, router } from "@inertiajs/react";
import { toast } from "sonner";
import { viewingDate, viewingTime } from "@/lib/viewingSchedule";
import {
    ChevronLeft, ChevronRight, Plus, SlidersHorizontal, X, Home, FileText,
    CalendarDays, Loader2, Pencil, Trash2, ExternalLink, Eye, CalendarClock,
} from "lucide-react";

const ICONS = { Home, FileText, CalendarDays };

// ---- date helpers (no external lib) ----------------------------------------
const startOfDay = (d) => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; };
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const addMonths = (d, n) => { const x = new Date(d); x.setMonth(x.getMonth() + n); return x; };
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const sameDay = (a, b) => ymd(a) === ymd(b);
const mondayOf = (d) => { const x = startOfDay(d); const day = (x.getDay() + 6) % 7; return addDays(x, -day); };
const fmtTime = (d) => d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
const fmtDay = (d) => d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" });
const eventDay = (event) => event.meta?.viewing_date ?? ymd(new Date(event.starts_at));
const eventTime = (event) => event.meta?.start_time ? viewingTime(event.meta.start_time) : fmtTime(new Date(event.starts_at));
const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const VIEWS = ["month", "week", "day", "agenda"];

function rangeFor(view, anchor) {
    if (view === "month") {
        const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
        const start = mondayOf(first);
        return { start, end: addDays(start, 41) };
    }
    if (view === "week") { const start = mondayOf(anchor); return { start, end: addDays(start, 6) }; }
    if (view === "day") { const start = startOfDay(anchor); return { start, end: start }; }
    const start = startOfDay(anchor); // agenda: 30-day window
    return { start, end: addDays(start, 29) };
}

function periodLabel(view, anchor) {
    if (view === "month") return `${MONTHS[anchor.getMonth()]} ${anchor.getFullYear()}`;
    if (view === "week") { const s = mondayOf(anchor); const e = addDays(s, 6); return `${s.getDate()} ${MONTHS[s.getMonth()].slice(0, 3)} – ${e.getDate()} ${MONTHS[e.getMonth()].slice(0, 3)} ${e.getFullYear()}`; }
    if (view === "day") return anchor.toLocaleDateString([], { weekday: "long", day: "numeric", month: "long", year: "numeric" });
    return "Next 30 days";
}

function KpiCard({ icon, label, value, hint }) {
    return (
        <div className="flex items-center gap-3 rounded-3xl border border-gray-50 bg-white p-4 shadow-sm">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#1F5A8B]/10 text-[#1F5A8B]">{icon}</span>
            <div className="min-w-0">
                <p className="text-2xl font-bold leading-tight text-gray-900">{value}</p>
                <p className="truncate text-sm font-medium text-gray-600">{label}</p>
                {hint && <p className="truncate text-xs text-gray-400">{hint}</p>}
            </div>
        </div>
    );
}

export default function Calendar({ eventTypes = [], properties = [], viewingStatuses = [], kpis = {} }) {
    const { props } = usePage();
    const currentUser = props.auth?.user;

    const isMobile = typeof window !== "undefined" && window.innerWidth < 768;
    const [view, setView] = useState(isMobile ? "agenda" : "month");
    const [anchor, setAnchor] = useState(() => startOfDay(new Date()));
    const [events, setEvents] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState(null);
    const [showFilters, setShowFilters] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [editEvent, setEditEvent] = useState(null);

    const [enabledTypes, setEnabledTypes] = useState(() => eventTypes.filter((t) => t.enabled).map((t) => t.key));
    const [propertyIds, setPropertyIds] = useState([]);

    const { start, end } = useMemo(() => rangeFor(view, anchor), [view, anchor]);

    const fetchEvents = useCallback(() => {
        setLoading(true);
        window.axios.get("/portal/accommodation/calendar/events", {
            params: { start: ymd(start), end: ymd(end), event_types: enabledTypes, property_ids: propertyIds },
        }).then((r) => setEvents(r.data)).catch(() => toast.error("Could not load events")).finally(() => setLoading(false));
    }, [start, end, enabledTypes, propertyIds]);

    useEffect(() => { fetchEvents(); }, [fetchEvents]);

    const move = (dir) => {
        if (view === "month") setAnchor((a) => addMonths(a, dir));
        else if (view === "week") setAnchor((a) => addDays(a, dir * 7));
        else if (view === "day") setAnchor((a) => addDays(a, dir));
        else setAnchor((a) => addDays(a, dir * 30));
    };

    const toggleType = (key) => setEnabledTypes((t) => (t.includes(key) ? t.filter((k) => k !== key) : [...t, key]));
    const toggleProperty = (id) => setPropertyIds((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

    return (
        <div className="max-w-7xl mx-auto space-y-4">
            <Head title="Calendar" />

            {/* Top bar */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-bold text-gray-900">Calendar</h1>
                    {loading && <Loader2 size={16} className="animate-spin text-gray-400" />}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex items-center rounded-xl bg-gray-100 p-1">
                        {VIEWS.map((v) => (
                            <button key={v} onClick={() => setView(v)} className={`rounded-lg px-3 py-1.5 text-sm font-semibold capitalize ${view === v ? "bg-white text-[#1F5A8B] shadow-sm" : "text-gray-500"}`}>{v}</button>
                        ))}
                    </div>
                    <button onClick={() => setShowFilters((s) => !s)} className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50"><SlidersHorizontal size={15} /> Filters</button>
                    <button onClick={() => { setEditEvent(null); setAddOpen(true); }} className="inline-flex items-center gap-1.5 rounded-full bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white hover:bg-[#184A73]"><Plus size={16} /> Add event</button>
                </div>
            </div>

            {/* KPI cards */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <KpiCard
                    icon={<Eye size={20} />}
                    label="Upcoming viewings"
                    value={kpis.upcoming_viewings ?? 0}
                    hint="clients booked to view"
                />
                <KpiCard
                    icon={<CalendarClock size={20} />}
                    label="Contracts ending soon"
                    value={kpis.ending_soon ?? 0}
                    hint="active tenants ending within 25 days"
                />
            </div>

            {/* Date nav */}
            <div className="flex items-center gap-3">
                <button onClick={() => move(-1)} className="rounded-lg border border-gray-200 bg-white p-2 text-gray-600 hover:bg-gray-50"><ChevronLeft size={16} /></button>
                <button onClick={() => setAnchor(startOfDay(new Date()))} className="rounded-full border border-gray-200 bg-white px-4 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Today</button>
                <button onClick={() => move(1)} className="rounded-lg border border-gray-200 bg-white p-2 text-gray-600 hover:bg-gray-50"><ChevronRight size={16} /></button>
                <span className="text-lg font-semibold text-gray-900">{periodLabel(view, anchor)}</span>
                {(view === "month" || view === "week") && isMobile && (
                    <span className="text-xs text-amber-600">Tip: Agenda view works best on mobile</span>
                )}
            </div>

            <div className="flex gap-4">
                {/* Filter panel */}
                {showFilters && (
                    <div className="w-56 shrink-0 space-y-4 rounded-3xl border border-gray-50 bg-white p-4 shadow-sm">
                        <div>
                            <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Event types</p>
                            <div className="space-y-1.5">
                                {eventTypes.map((t) => (
                                    <label key={t.key} className={`flex items-center gap-2 text-sm ${t.enabled ? "text-gray-700" : "text-gray-300"}`}>
                                        <input type="checkbox" disabled={!t.enabled} checked={t.enabled && enabledTypes.includes(t.key)} onChange={() => toggleType(t.key)} className="h-4 w-4 rounded border-gray-300" />
                                        <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: t.color }} />
                                        {t.label}{!t.enabled && <span className="text-[10px]">(soon)</span>}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div>
                            <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Properties</p>
                            <div className="max-h-48 space-y-1.5 overflow-y-auto">
                                {properties.map((p) => (
                                    <label key={p.id} className="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" checked={propertyIds.includes(p.id)} onChange={() => toggleProperty(p.id)} className="h-4 w-4 rounded border-gray-300" />
                                        {p.code ? `#${p.code} ` : ""}{p.address}
                                    </label>
                                ))}
                                {properties.length === 0 && <p className="text-xs text-gray-400">No properties</p>}
                            </div>
                        </div>
                    </div>
                )}

                {/* Calendar body */}
                <div className="min-w-0 flex-1">
                    {view === "month" && <MonthView start={start} anchor={anchor} events={events} onSelect={setSelected} />}
                    {view === "week" && <ListView start={start} days={7} events={events} onSelect={setSelected} />}
                    {view === "day" && <ListView start={start} days={1} events={events} onSelect={setSelected} />}
                    {view === "agenda" && <AgendaView events={events} onSelect={setSelected} />}
                </div>
            </div>

            {selected && (
                <EventDrawer
                    event={selected}
                    currentUser={currentUser}
                    onClose={() => setSelected(null)}
                    onChanged={() => { setSelected(null); fetchEvents(); }}
                    onEdit={(e) => { setSelected(null); setEditEvent(e); setAddOpen(true); }}
                />
            )}

            {addOpen && (
                <EventFormModal
                    event={editEvent}
                    properties={properties}
                    onClose={() => { setAddOpen(false); setEditEvent(null); }}
                    onSaved={() => { setAddOpen(false); setEditEvent(null); fetchEvents(); }}
                />
            )}
        </div>
    );
}

// ---- Views -----------------------------------------------------------------

function EventChip({ event, onSelect, compact = false }) {
    const Icon = ICONS[event.icon] ?? CalendarDays;
    return (
        <button
            onClick={() => onSelect(event)}
            className="flex w-full items-center gap-1 truncate rounded px-1.5 py-0.5 text-left text-[11px] font-medium text-white"
            style={{ backgroundColor: event.color }}
            title={event.title}
        >
            {!compact && <Icon size={11} className="shrink-0" />}
            {!event.is_all_day && <span className="shrink-0 opacity-90">{eventTime(event)}</span>}
            <span className="truncate">{event.title}</span>
        </button>
    );
}

function MonthView({ start, anchor, events, onSelect }) {
    const cells = Array.from({ length: 42 }, (_, i) => addDays(start, i));
    const byDay = useMemo(() => {
        const m = {};
        for (const e of events) { const k = eventDay(e); (m[k] ??= []).push(e); }
        return m;
    }, [events]);

    return (
        <div className="overflow-hidden rounded-3xl border border-gray-50 bg-white shadow-sm">
            <div className="grid grid-cols-7 border-b border-gray-100 bg-gray-50 text-center text-[11px] font-bold uppercase tracking-wider text-gray-400">
                {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => <div key={d} className="py-2">{d}</div>)}
            </div>
            <div className="grid grid-cols-7">
                {cells.map((day, i) => {
                    const inMonth = day.getMonth() === anchor.getMonth();
                    const isToday = sameDay(day, new Date());
                    const dayEvents = byDay[ymd(day)] ?? [];
                    return (
                        <div key={i} className={`min-h-[96px] border-b border-r border-gray-50 p-1.5 ${inMonth ? "bg-white" : "bg-gray-50/40"}`}>
                            <div className={`mb-1 text-xs font-semibold ${isToday ? "inline-flex h-5 w-5 items-center justify-center rounded-full bg-[#1F5A8B] text-white" : inMonth ? "text-gray-700" : "text-gray-300"}`}>{day.getDate()}</div>
                            <div className="space-y-1">
                                {dayEvents.slice(0, 3).map((e) => <EventChip key={e.id} event={e} onSelect={onSelect} compact />)}
                                {dayEvents.length > 3 && <p className="px-1 text-[10px] font-medium text-gray-400">+{dayEvents.length - 3} more</p>}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function ListView({ start, days, events, onSelect }) {
    const cols = Array.from({ length: days }, (_, i) => addDays(start, i));
    const byDay = useMemo(() => {
        const m = {};
        for (const e of events) { const k = eventDay(e); (m[k] ??= []).push(e); }
        for (const k in m) m[k].sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at));
        return m;
    }, [events]);

    return (
        <div className={`grid gap-3 ${days === 1 ? "grid-cols-1" : "grid-cols-1 sm:grid-cols-7"}`}>
            {cols.map((day, i) => {
                const dayEvents = byDay[ymd(day)] ?? [];
                const isToday = sameDay(day, new Date());
                return (
                    <div key={i} className="rounded-2xl border border-gray-50 bg-white p-2 shadow-sm">
                        <p className={`mb-2 px-1 text-xs font-bold ${isToday ? "text-[#1F5A8B]" : "text-gray-500"}`}>{fmtDay(day)}</p>
                        <div className="space-y-1.5 min-h-[40px]">
                            {dayEvents.length === 0 ? <p className="px-1 text-[11px] text-gray-300">—</p> : dayEvents.map((e) => <EventChip key={e.id} event={e} onSelect={onSelect} />)}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function AgendaView({ events, onSelect }) {
    const groups = useMemo(() => {
        const m = {};
        for (const e of [...events].sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))) {
            const k = eventDay(e); (m[k] ??= []).push(e);
        }
        return m;
    }, [events]);
    const keys = Object.keys(groups);

    if (keys.length === 0) return <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-16 text-center text-sm text-gray-500">No events in this range.</div>;

    return (
        <div className="space-y-4">
            {keys.map((k) => (
                <div key={k} className="rounded-3xl border border-gray-50 bg-white p-4 shadow-sm">
                    <p className="mb-2 text-sm font-bold text-gray-900">{fmtDay(new Date(k + "T00:00:00"))}</p>
                    <div className="divide-y divide-gray-50">
                        {groups[k].map((e) => {
                            const Icon = ICONS[e.icon] ?? CalendarDays;
                            return (
                                <button key={e.id} onClick={() => onSelect(e)} className="flex w-full items-center gap-3 py-2.5 text-left hover:bg-gray-50/50">
                                    <span className="h-8 w-1.5 rounded-full" style={{ backgroundColor: e.color }} />
                                    <Icon size={16} style={{ color: e.color }} />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-gray-900">{e.title}</p>
                                        {e.subtitle && <p className="truncate text-xs text-gray-500">{e.subtitle}</p>}
                                    </div>
                                    <span className="shrink-0 text-xs text-gray-500">{e.is_all_day ? "All day" : eventTime(e)}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

// ---- Drawer ----------------------------------------------------------------

function EventDrawer({ event, currentUser, onClose, onChanged, onEdit }) {
    const m = event.meta ?? {};
    const isCustom = event.source_type === "custom";
    const canEdit = isCustom && (m.created_by_user_id === currentUser?.id || currentUser?.role === "admin");

    const markViewingCompleted = () => {
        router.patch(`/portal/accommodation/applications/${event.source_id}/status`, { status: "viewing_completed" }, {
            preserveScroll: true, preserveState: true,
            onSuccess: () => { toast.success("Viewing marked completed"); onChanged(); },
            onError: () => toast.error("Could not update — check the onboarding stage"),
        });
    };
    const deleteCustom = () => {
        if (!confirm("Delete this event?")) return;
        window.axios.delete(`/portal/accommodation/calendar/events/${event.source_id}`)
            .then(() => { toast.success("Event deleted"); onChanged(); })
            .catch(() => toast.error("Could not delete"));
    };

    const Icon = ICONS[event.icon] ?? CalendarDays;

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={onClose}>
            <div className="h-full w-full max-w-md overflow-y-auto bg-white shadow-2xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <span className="inline-flex items-center gap-2 text-sm font-bold uppercase tracking-wider" style={{ color: event.color }}><Icon size={16} /> {event.source_type.replace("_", " ")}</span>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                </div>
                <div className="space-y-4 px-6 py-5">
                    <h2 className="text-xl font-bold text-gray-900">{event.title}</h2>
                    {event.subtitle && <p className="text-sm text-gray-500">{event.subtitle}</p>}
                    <p className="text-sm text-gray-700">
                        {event.is_all_day ? "All day" : m.viewing_date
                            ? `${viewingDate(m.viewing_date)} · ${eventTime(event)} NZ time`
                            : `${fmtDay(new Date(event.starts_at))} · ${eventTime(event)}`}
                    </p>

                    {event.source_type === "group_viewing" && (
                        <ul className="divide-y divide-gray-100 rounded-xl border border-gray-100 px-3">
                            {m.attendees.map((attendee) => (
                                <li key={attendee.id} className="space-y-1 py-3 text-sm text-gray-700">
                                    <p className="font-semibold text-gray-900">{attendee.name}</p>
                                    <Detail label="Email" value={attendee.email} />
                                    <Detail label="Mobile" value={attendee.mobile} />
                                    <Detail label="Status" value={attendee.status} />
                                </li>
                            ))}
                        </ul>
                    )}

                    {event.source_type === "viewing" && (
                        <div className="space-y-1 text-sm text-gray-700">
                            <Detail label="Status" value={m.status?.replace(/_/g, " ")} />
                            <Detail label="Temperature" value={m.lead_temperature} />
                            <Detail label="Email" value={m.applicant_email} />
                            <Detail label="Phone" value={m.applicant_phone} />
                            <div className="flex flex-wrap gap-2 pt-3">
                                <DrawerLink href={`/portal/accommodation/applications/${event.source_id}`} label="Open onboarding record" />
                                {m.status === "viewing_booked" && <DrawerBtn onClick={markViewingCompleted} label="Mark viewing completed" />}
                            </div>
                        </div>
                    )}

                    {event.source_type === "contract_end" && (
                        <div className="space-y-1 text-sm text-gray-700">
                            <Detail label="Days to end" value={m.days_to_end} />
                            <Detail label="Status" value={m.current_status?.replace(/_/g, " ")} />
                            <Detail label="Weekly rent" value={m.weekly_rent_nzd ? `$${m.weekly_rent_nzd}` : null} />
                            <div className="flex flex-wrap gap-2 pt-3">
                                <DrawerLink href={`/portal/accommodation/tenants/${event.source_id}`} label="Open tenant record" />
                            </div>
                            <p className="pt-1 text-xs text-gray-400">Renewal / notice actions live on the tenant record.</p>
                        </div>
                    )}

                    {event.source_type === "custom" && (
                        <div className="space-y-1 text-sm text-gray-700">
                            {m.description && <p className="text-gray-700">{m.description}</p>}
                            <Detail label="Location" value={m.location} />
                            <Detail label="Property" value={event.property_address} />
                            <Detail label="Created by" value={m.created_by} />
                            {canEdit && (
                                <div className="flex gap-2 pt-3">
                                    <DrawerBtn onClick={() => onEdit(event)} label="Edit" icon={<Pencil size={14} />} />
                                    <button onClick={deleteCustom} className="inline-flex items-center gap-1.5 rounded-full border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50"><Trash2 size={14} /> Delete</button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

const Detail = ({ label, value }) => value ? <div className="flex justify-between gap-4"><span className="text-gray-400">{label}</span><span className="font-medium text-gray-800">{value}</span></div> : null;
const DrawerLink = ({ href, label }) => <a href={href} className="inline-flex items-center gap-1.5 rounded-full bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white hover:bg-[#184A73]"><ExternalLink size={14} /> {label}</a>;
const DrawerBtn = ({ onClick, label, icon }) => <button onClick={onClick} className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{icon} {label}</button>;

// ---- Add / Edit modal ------------------------------------------------------

const FIELD = "w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-[#1F5A8B] focus:ring-[#1F5A8B]";
const LABEL = "block text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1";

function EventFormModal({ event, properties, onClose, onSaved }) {
    const editing = Boolean(event);
    const localDT = (iso) => iso ? new Date(iso).toISOString().slice(0, 16) : "";
    const [form, setForm] = useState({
        title: event?.title ?? "",
        description: event?.meta?.description ?? "",
        is_all_day: event?.is_all_day ?? false,
        starts_at: localDT(event?.starts_at) || new Date().toISOString().slice(0, 16),
        ends_at: localDT(event?.ends_at),
        property_id: event?.property_id ?? "",
        location: event?.meta?.location ?? "",
        color_hex: event?.color ?? "#6B7280",
    });
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const submit = (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const payload = { ...form, property_id: form.property_id || null, ends_at: form.is_all_day ? null : (form.ends_at || null) };
        const req = editing
            ? window.axios.patch(`/portal/accommodation/calendar/events/${event.source_id}`, payload)
            : window.axios.post("/portal/accommodation/calendar/events", payload);
        req.then(() => { toast.success(editing ? "Event updated" : "Event created"); onSaved(); })
            .catch((err) => { setErrors(err.response?.data?.errors ?? {}); toast.error("Please check the form"); })
            .finally(() => setSaving(false));
    };

    const err = (k) => errors[k] && <p className="mt-1 text-xs text-rose-600">{errors[k][0]}</p>;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <form onSubmit={submit} onClick={(e) => e.stopPropagation()} className="flex max-h-[88vh] w-full max-w-md flex-col overflow-hidden rounded-3xl bg-white shadow-xl">
                <div className="flex shrink-0 items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 className="text-lg font-bold text-gray-900">{editing ? "Edit event" : "Add event"}</h3>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                </div>
                <div className="flex-1 space-y-3 overflow-y-auto px-6 py-5">
                    <div>
                        <label className={LABEL}>Title</label>
                        <input className={FIELD} value={form.title} onChange={(e) => set("title", e.target.value)} />
                        {err("title")}
                    </div>
                    <div>
                        <label className={LABEL}>Description</label>
                        <textarea rows={2} className={FIELD} value={form.description} onChange={(e) => set("description", e.target.value)} />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={form.is_all_day} onChange={(e) => set("is_all_day", e.target.checked)} className="h-4 w-4 rounded border-gray-300" /> All day
                    </label>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className={LABEL}>Starts</label>
                            <input type={form.is_all_day ? "date" : "datetime-local"} className={FIELD} value={form.is_all_day ? form.starts_at.slice(0, 10) : form.starts_at} onChange={(e) => set("starts_at", e.target.value)} />
                            {err("starts_at")}
                        </div>
                        {!form.is_all_day && (
                            <div>
                                <label className={LABEL}>Ends</label>
                                <input type="datetime-local" className={FIELD} value={form.ends_at} onChange={(e) => set("ends_at", e.target.value)} />
                                {err("ends_at")}
                            </div>
                        )}
                    </div>
                    <div>
                        <label className={LABEL}>Property (optional)</label>
                        <select className={FIELD} value={form.property_id} onChange={(e) => set("property_id", e.target.value)}>
                            <option value="">None</option>
                            {properties.map((p) => <option key={p.id} value={p.id}>{p.code ? `#${p.code} · ` : ""}{p.address}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={LABEL}>Location (optional)</label>
                        <input className={FIELD} value={form.location} onChange={(e) => set("location", e.target.value)} />
                    </div>
                    <div>
                        <label className={LABEL}>Colour</label>
                        <input type="color" className="h-9 w-16 rounded border border-gray-200" value={form.color_hex} onChange={(e) => set("color_hex", e.target.value)} />
                    </div>
                </div>
                <div className="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 px-6 py-4">
                    <button type="button" onClick={onClose} className="rounded-full px-5 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button type="submit" disabled={saving} className="rounded-full bg-[#1F5A8B] px-6 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">{saving ? "Saving…" : editing ? "Save" : "Create event"}</button>
                </div>
            </form>
        </div>
    );
}
