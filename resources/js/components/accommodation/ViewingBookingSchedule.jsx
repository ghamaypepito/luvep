import { useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import { viewingDate, viewingTime } from "@/lib/viewingSchedule";

const viewingsPath = "/portal/accommodation/viewings";
const control = "rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm text-slate-900 disabled:bg-slate-50";
const action = "rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-[#153c35] hover:bg-emerald-50 disabled:opacity-50";

function SlotEditor({ date, time, slot, properties, past }) {
    const { data, setData, post, processing, errors } = useForm({
        slot_id: slot?.id ?? null,
        date,
        time,
        property_id: slot?.property_id ?? "",
        capacity: slot?.capacity ?? 1,
        blocked: slot?.blocked ?? false,
    });
    const attendees = slot?.attendees ?? [];
    const booked = attendees.filter((attendee) => attendee.status !== "Cancelled").length;
    const disabled = past || slot?.past || processing;
    const oldProperty = slot?.property_id && !properties.some((property) => property.id === slot.property_id);

    return (
        <form onSubmit={(event) => {
            event.preventDefault();
            post(`${viewingsPath}/slots`, { preserveScroll: true });
        }} className="rounded-xl border border-slate-200 p-3">
            <p className="text-sm font-semibold">{viewingTime(time)}</p>
            <p className="mb-3 text-xs text-slate-500">
                {slot ? `${booked}/${slot.capacity} booked · ${slot.blocked ? "Blocked" : "Open"}` : "Unscheduled"}
            </p>
            <div className="flex flex-wrap items-end gap-2">
                <label className="min-w-0 flex-1 space-y-1 text-xs font-medium">Property
                    {attendees.length ? (
                        <span className="block py-2 font-semibold">{slot.property_address ?? "Archived property"}</span>
                    ) : (
                        <select className={`${control} block w-full`} value={data.property_id} disabled={disabled}
                            required={!data.blocked} onChange={(event) => setData("property_id", event.target.value)}>
                            <option value="">Select property</option>
                            {oldProperty && <option value={slot.property_id}>{slot.property_address} (inactive)</option>}
                            {properties.map((property) => <option key={property.id} value={property.id}>{property.address}</option>)}
                        </select>
                    )}
                </label>
                <label className="space-y-1 text-xs font-medium">Limit
                    <input className={`${control} block w-16`} type="number" min="1" max="100" required disabled={disabled}
                        value={data.capacity} onChange={(event) => setData("capacity", event.target.value)} />
                </label>
                <label className="inline-flex items-center gap-1.5 py-2.5 text-sm">
                    <input type="checkbox" checked={data.blocked} disabled={disabled}
                        onChange={(event) => setData("blocked", event.target.checked)} /> Block
                </label>
                <button className={action} disabled={disabled} type="submit">Save</button>
            </div>
            {Object.values(errors).map((error) => <p className="mt-2 text-xs text-rose-700" role="alert" key={error}>{error}</p>)}
        </form>
    );
}

function AttendeeList({ slot }) {
    const [pending, setPending] = useState(null);
    const [error, setError] = useState("");
    const handle = (id, status) => {
        setError("");
        setPending(id);
        const options = {
            preserveScroll: true,
            onFinish: () => setPending(null),
            onError: (errors) => setError(Object.values(errors)[0] || "The booking could not be updated."),
        };
        const url = `${viewingsPath}/attendees/${id}`;
        if (status) router.patch(`${url}/status`, { status }, options);
        else router.post(`${url}/resend`, {}, options);
    };

    return (
        <details open className="rounded-xl border border-slate-200">
            <summary className="cursor-pointer rounded-xl bg-emerald-50/70 px-4 py-3 text-sm font-semibold">
                {viewingTime(slot.start_time)} · {slot.attendees.length} {slot.attendees.length === 1 ? "booking" : "bookings"}
            </summary>
            <ul className="divide-y divide-slate-100 px-4">
                {slot.attendees.map((person) => (
                    <li key={person.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                        <div className="min-w-0">
                            <p className="font-semibold">{person.name}</p>
                            <p className="break-all text-slate-600">{person.email} · {person.mobile}</p>
                            {person.attendance_response && <p className="text-slate-700">
                                Attendance {person.attendance_response}
                            </p>}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {person.status === "Cancelled" ? <span className="text-slate-600">Cancelled</span> : (
                                <select aria-label={`Status for ${person.name}`} className={control} disabled={pending === person.id}
                                    value={person.status} onChange={(event) => handle(person.id, event.target.value)}>
                                    <option>Confirmed</option><option>Completed</option><option>Cancelled</option>
                                </select>
                            )}
                            {person.status === "Confirmed" && <button className={action} disabled={pending === person.id}
                                type="button" onClick={() => handle(person.id)}>Resend confirmation</button>}
                        </div>
                    </li>
                ))}
            </ul>
            {error && <p className="px-4 pb-3 text-xs text-rose-700" role="alert">{error}</p>}
        </details>
    );
}

export default function ViewingBookingSchedule({ schedule }) {
    const [propertyId, setPropertyId] = useState("");
    const [copyStatus, setCopyStatus] = useState("");
    const flash = usePage().props.flash;
    const property = schedule.properties.find((entry) => String(entry.id) === propertyId);
    const sunday = new Date(`${schedule.date}T00:00:00`).getDay() === 0;
    const slotsByTime = new Map(schedule.slots.map((slot) => [slot.start_time, slot]));
    const bookedProperties = new Map();
    for (const slot of schedule.slots.filter((entry) => entry.attendees.length)) {
        const key = slot.property_id ?? slot.property_address;
        if (!bookedProperties.has(key)) bookedProperties.set(key, []);
        bookedProperties.get(key).push(slot);
    }

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(property.url);
            setCopyStatus("Booking link copied.");
        } catch {
            setCopyStatus("Select and copy the booking link below.");
        }
    };

    return (
        <section className="space-y-5 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
            <h2 className="text-lg font-bold">Viewing booking links</h2>
            <div className="flex flex-wrap items-end gap-2">
                <label className="min-w-0 flex-1 space-y-1 text-sm font-medium">Property to share
                    <select className={`${control} block w-full`} value={propertyId} onChange={(event) => {
                        setPropertyId(event.target.value);
                        setCopyStatus("");
                    }}>
                        <option value="">Select property</option>
                        {schedule.properties.map((entry) => <option key={entry.id} value={entry.id}>{entry.address}</option>)}
                    </select>
                </label>
                {property && <>
                    <button className={action} type="button" onClick={copy}>Copy booking link</button>
                    <a className={action} href={`mailto:?subject=${encodeURIComponent("Book an Exalt property viewing")}&body=${encodeURIComponent(`Choose a viewing time for ${property.address}:\n\n${property.url}`)}`}>Email booking link</a>
                    <a className={action} href={property.url}>Open booking page</a>
                </>}
            </div>
            {property && <input className={`${control} w-full`} aria-label="Property booking link" value={property.url} readOnly
                onFocus={(event) => event.target.select()} />}
            {copyStatus && <p className="text-sm text-slate-600" role="status">{copyStatus}</p>}

            <div className="flex flex-wrap items-end justify-between gap-2 border-t border-slate-100 pt-4">
                <div>
                    <h3 className="font-semibold">Schedule and attendees</h3>
                    <p className="text-sm text-slate-600">One property per start time · New Zealand time</p>
                </div>
                <label className="space-y-1 text-sm font-medium">Schedule date
                    <input type="date" className={`${control} block`} value={schedule.date} onChange={(event) => {
                        if (event.target.value) router.get(viewingsPath, { schedule_date: event.target.value }, {
                            preserveScroll: true, preserveState: true, replace: true,
                        });
                    }} />
                </label>
            </div>
            {flash?.success && <p role="status" className="text-sm text-emerald-800">{flash.success}</p>}
            {flash?.error && <p role="alert" className="text-sm text-rose-700">{flash.error}</p>}
            {sunday ? <p className="text-sm text-slate-600">Viewings are available Monday to Saturday.</p> : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {schedule.times.map((time) => {
                        const slot = slotsByTime.get(time);
                        return <SlotEditor key={JSON.stringify([schedule.date, time, slot?.property_id, slot?.capacity, slot?.blocked, slot?.id])}
                            date={schedule.date} time={time} slot={slot} properties={schedule.properties} past={schedule.date < schedule.today} />;
                    })}
                </div>
            )}
            {[...bookedProperties].map(([key, slots]) => (
                <div className="space-y-2" key={key}>
                    <h4 className="font-semibold">{slots[0].property_address}</h4>
                    <p className="text-sm text-slate-600">{viewingDate(schedule.date)}</p>
                    {slots.map((slot) => <AttendeeList key={slot.id} slot={slot} />)}
                </div>
            ))}
        </section>
    );
}
