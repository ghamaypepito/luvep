import { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import { CalendarDays, CheckCircle, MapPin } from "lucide-react";
import { viewingDate, viewingTime } from "@/lib/viewingSchedule";

const input = "w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900";

export default function BookViewing({ address, slots, bookingUrl, confirmation = null }) {
    const dates = [...new Set(slots.map((slot) => slot.date))];
    const [date, setDate] = useState(dates[0] ?? "");
    const { data, setData, post, processing, errors } = useForm({ slot_id: "", name: "", email: "", mobile: "" });
    const activeDate = dates.includes(date) ? date : (dates[0] ?? "");
    const times = slots.filter((slot) => slot.date === activeDate);
    const selectedSlot = times.find((slot) => slot.id === data.slot_id);

    const submit = (event) => {
        event.preventDefault();
        if (selectedSlot) post(bookingUrl, { preserveScroll: true });
    };

    return (
        <main className="min-h-screen bg-[#eaf2ef] px-4 py-8 text-slate-900 sm:py-16">
            <Head title="Book an Exalt property viewing" />
            <div className="mx-auto grid max-w-4xl overflow-hidden rounded-2xl bg-white shadow-md sm:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
                <div className="flex flex-col justify-between gap-10 bg-[#153c35] p-7 text-white sm:p-9">
                    <div>
                        <p className="text-sm font-semibold tracking-wide text-emerald-100">Exalt Property Management</p>
                        <h1 className="mt-4 text-3xl font-bold">Book a property viewing</h1>
                    </div>
                    <p className="flex gap-2 text-sm text-emerald-100"><MapPin className="shrink-0" size={20} /> {address}</p>
                </div>
                {confirmation ? (
                    <section className="p-7 sm:p-9" aria-live="polite">
                        <CheckCircle className="text-emerald-700" size={30} />
                        <h2 className="mt-3 text-2xl font-bold">Your viewing is confirmed</h2>
                        <p className="mt-2 text-sm text-slate-600">{confirmation.address}</p>
                        <p className="mt-1 text-sm text-slate-600">{viewingDate(confirmation.date)} · {viewingTime(confirmation.time)} NZ time</p>
                    </section>
                ) : dates.length ? (
                    <form onSubmit={submit} className="space-y-5 p-7 sm:p-9">
                        <p className="flex items-center gap-2 text-sm text-slate-600"><CalendarDays size={17} /> Monday to Saturday · New Zealand time</p>
                        <label className="block space-y-1 text-sm font-semibold">
                            <span>Viewing date</span>
                            <select className={input} value={activeDate} onChange={(event) => {
                                setDate(event.target.value);
                                setData("slot_id", "");
                            }}>
                                {dates.map((value) => <option key={value} value={value}>{viewingDate(value)}</option>)}
                            </select>
                        </label>
                        <fieldset>
                            <legend className="mb-2 text-sm font-semibold">Start time</legend>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {times.map((slot) => (
                                    <label key={slot.id} className="cursor-pointer">
                                        <input className="peer sr-only" type="radio" name="start_time" checked={data.slot_id === slot.id}
                                            onChange={() => setData("slot_id", slot.id)} />
                                        <span className="block rounded-lg border border-emerald-900/25 p-2.5 text-center text-sm font-semibold peer-checked:bg-[#153c35] peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-700">
                                            {viewingTime(slot.time)}
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <p className="mt-2 text-xs text-slate-600">Other prospective clients may attend this viewing.</p>
                        </fieldset>
                        {selectedSlot && selectedSlot.address !== address && (
                            <p className="text-sm text-slate-600">Viewing address · {selectedSlot.address}</p>
                        )}
                        {[
                            ["name", "Full name", "text", "name", 191],
                            ["email", "Email address", "email", "email", 191],
                            ["mobile", "Mobile number", "tel", "tel", 40],
                        ].map(([key, label, type, autoComplete, maxLength]) => (
                            <label key={key} className="block space-y-1 text-sm font-semibold">
                                <span>{label}</span>
                                <input className={input} required type={type} autoComplete={autoComplete} maxLength={maxLength}
                                    value={data[key]} onChange={(event) => setData(key, event.target.value)} />
                                {errors[key] && <span className="block text-rose-700" role="alert">{errors[key]}</span>}
                            </label>
                        ))}
                        {errors.slot_id && <p className="text-sm text-rose-700" role="alert">{errors.slot_id}</p>}
                        <button type="submit" disabled={processing || !selectedSlot}
                            className="w-full rounded-lg bg-[#153c35] px-4 py-3 font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">
                            {processing ? "Confirming…" : "Confirm viewing"}
                        </button>
                    </form>
                ) : (
                    <section className="p-7 sm:p-9">
                        <h2 className="text-xl font-bold">No viewing times are available</h2>
                        <p className="mt-2 text-sm text-slate-600">{errors.slot_id || "Check this link again for new viewing times."}</p>
                    </section>
                )}
            </div>
        </main>
    );
}
