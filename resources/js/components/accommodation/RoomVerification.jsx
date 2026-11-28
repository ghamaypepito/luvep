import { useState } from 'react';
import { router } from '@inertiajs/react';

export default function RoomVerification({ propertyId, summary, canVerify = false }) {
    const [form, setForm] = useState(() => ({ total_rooms: summary?.total ?? '', occupied: summary?.occupied ?? '', vacant: summary?.vacant ?? '',
        observed_on: summary?.today || '', source_ref: summary?.source_ref || '', vacancy_notes: summary?.vacancy_notes || '', confirm: false }));
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const set = (key, value) => setForm(previous => ({ ...previous, [key]: value }));
    const submit = event => {
        event.preventDefault(); setBusy(true); setErrors({});
        router.post(`/portal/accommodation/properties/${propertyId}/room-occupancy`, { ...form, version: summary.version }, {
            preserveScroll: true, onError: setErrors, onSuccess: () => setForm(previous => ({ ...previous, confirm: false })), onFinish: () => setBusy(false),
        });
    };
    if (!summary) return null;
    const remainder = [form.total_rooms, form.occupied, form.vacant].every(value => value !== '')
        ? Number(form.total_rooms) - Number(form.occupied) - Number(form.vacant) : null;
    return <section className="rounded-3xl border bg-white p-6 shadow-sm" aria-label="Room verification">
        <h2 className="text-lg font-semibold">Room occupancy verification</h2>
        <p className="mt-2 text-sm text-gray-600">{summary.households} current household tenancies · {summary.total ?? 'Unknown'} rooms. Household counts are not room counts.</p>
        <p className="mt-2 text-sm">{summary.state === 'verified' ? `Verified ${summary.observed_on}: ${summary.occupied} occupied, ${summary.vacant} vacant.` : 'Room totals need verification. Unassigned rooms are not assumed vacant.'}</p>
        <p className="mt-2 text-xs text-gray-500">{summary.unlabelled_households} household records have no room label. Add labels in the existing tenant Edit screen when confirmed; this check does not assign a room or alter rent, payments or tenancy status.</p>
        {!summary.installed ? <p className="mt-3 text-sm text-amber-700">Room verification is being installed. Existing property and tenant actions remain available.</p>
            : canVerify && <form onSubmit={submit} className="mt-4 space-y-4">
                    <div className="grid gap-3 sm:grid-cols-3">{[['total_rooms', 'Total Rooms', 1], ['occupied', 'Total Occupied Rooms', 0], ['vacant', 'Total Vacant Rooms', 0]].map(([key, label, min]) => <label className="text-sm" key={key}>{label}
                        <input aria-label={label} type="number" min={min} max="50" step="1" required value={form[key]} onChange={event => set(key, event.target.value)} className="mt-1 w-full rounded-lg border p-2" />
                    </label>)}</div>
                    {remainder > 0 && <p className="text-sm text-gray-600">{remainder} {remainder === 1 ? 'room stays' : 'rooms stay'} outside the occupied and vacant totals.</p>}
                    <label className="block text-sm">Checked on<input aria-label="Checked on" type="date" required max={summary.today} value={form.observed_on} onChange={event => set('observed_on', event.target.value)} className="mt-1 block rounded-lg border p-2" /></label>
                    <label className="block text-sm">Source / reason<input aria-label="Source / reason" required maxLength={500} value={form.source_ref} onChange={event => set('source_ref', event.target.value)} placeholder="Current register or property inspection reference" className="mt-1 w-full rounded-lg border p-2" /></label>
                    <label className="block text-sm">Vacancy details (optional)<textarea aria-label="Vacancy details" maxLength={1000} value={form.vacancy_notes} onChange={event => set('vacancy_notes', event.target.value)} placeholder="Verified room types or labels; leave unknown labels blank" className="mt-1 w-full rounded-lg border p-2" /></label>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.confirm} onChange={event => set('confirm', event.target.checked)} required />I have checked these figures against the current register or property records.</label>
                    {Object.keys(errors).length > 0 && <div role="alert" className="text-sm text-red-700">{Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>}
                    <button disabled={busy || !form.confirm} className="rounded-full bg-[#1F5A8B] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Saving…' : 'Confirm room counts'}</button>
                </form>}
    </section>;
}
