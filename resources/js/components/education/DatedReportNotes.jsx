import { useRef, useState } from "react";
import { router } from "@inertiajs/react";

const endpoint = "/portal/education/reports/dated-notes";
const field = "mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm";
const errorsText = errors => Object.values(errors || {}).flat().join(" ") || "Could not save. Please try again.";
const dateLabel = date => new Date(date + "T12:00:00Z").toLocaleDateString("en-NZ", { timeZone: "UTC", weekday: "long", day: "numeric", month: "long", year: "numeric" });

export default function DatedReportNotes({ data, today }) {
    const [editing, setEditing] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [busy, setBusy] = useState(false);
    const [drafting, setDrafting] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const requestBusy = useRef(false);
    const heading = useRef(null);
    if (!data) return null;
    const page = data.entries;
    const groups = Object.groupBy ? Object.groupBy(page.data, entry => entry.date) : page.data.reduce((days, entry) => {
        (days[entry.date] ||= []).push(entry); return days;
    }, {});
    const closeEdit = () => { setEditing(null); setDrafting(false); heading.current?.focus(); };
    const remove = () => {
        if (requestBusy.current || busy || !removing) return;
        requestBusy.current = true; setBusy(true); setError(""); setNotice("");
        router.delete(endpoint + "/" + removing.id, {
            data: { revision: removing.revision }, preserveState: true, preserveScroll: true,
            onSuccess: () => { setRemoving(null); setNotice("Note removed."); heading.current?.focus(); },
            onError: errors => setError(errorsText(errors)),
            onFinish: () => { requestBusy.current = false; setBusy(false); },
        });
    };
    return <section aria-label="Dated weekly notes" className="space-y-3 border-t border-gray-200 pt-4">
        <h3 ref={heading} tabIndex={-1} className="text-sm font-semibold">My weekly notes / Other activities by day</h3>
        <p className="text-xs text-gray-500">Add a date for each note. Entries below follow the selected report dates (New Zealand time) and are private to your account. Note counts are separate from client activities and completed tasks.</p>
        <p className="text-xs text-gray-500">Your existing weekly summaries remain unchanged below. They have no individual activity dates; no dates have been guessed or assigned to them.</p>
        {notice && <p role="status" className="text-sm text-teal-700">{notice}</p>}
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <NoteForm key={editing?.id || "new"} entry={editing} today={today} categories={data.categories}
            disabled={Boolean(removing)} setBusy={setBusy} onClose={closeEdit} setNotice={setNotice} setDrafting={setDrafting} />
        {drafting && !editing && <p className="text-xs text-gray-500">Save or discard your draft before editing or removing another note.</p>}
        {Object.entries(groups).map(([date, rows]) => <details key={date} open className="rounded-xl border border-gray-200 p-3">
            <summary className="cursor-pointer text-sm font-semibold">{dateLabel(date)} · {data.day_counts[date]} {data.day_counts[date] === 1 ? "entry" : "entries"}</summary>
            {rows.length < data.day_counts[date] && <p className="mt-2 text-xs text-gray-500">Showing {rows.length} of {data.day_counts[date]} entries for this day on this page.</p>}
            <ul className="mt-2 space-y-3">{rows.map(note => <li key={note.id} className="border-t border-gray-100 pt-2">
                <p className="text-xs font-semibold">{data.categories[note.category]}</p>
                <p className="whitespace-pre-wrap break-words text-sm">{note.body}</p>
                <div className="mt-2 flex gap-3 text-xs">
                    <button type="button" disabled={busy || drafting || Boolean(editing || removing)} aria-label={"Edit dated note " + note.id} className="text-blue-700 disabled:opacity-50" onClick={() => { setEditing(note); setError(""); setNotice(""); }}>Edit note</button>
                    <button type="button" disabled={busy || drafting || Boolean(editing || removing)} aria-label={"Remove dated note " + note.id} className="text-red-700 disabled:opacity-50" onClick={() => { setRemoving(note); setError(""); setNotice(""); }}>Remove note</button>
                </div>
            </li>)}</ul>
        </details>)}
        {!page.data.length && <p className="text-sm text-gray-500">No dated notes in this reporting period. Existing weekly summaries are retained below.</p>}
        {removing && <div role="group" aria-label="Confirm dated note removal" className="rounded-lg border border-amber-200 p-3 text-sm">
            <p>Remove this {data.categories[removing.category].toLowerCase()} dated {removing.date} from your report?</p>
            <button type="button" disabled={busy} onClick={remove} className="mr-3 text-red-700">Confirm remove note</button>
            <button type="button" disabled={busy} onClick={() => { setRemoving(null); setError(""); }}>Keep note</button>
        </div>}
        {page.last_page > 1 && <nav aria-label="Dated note pages" className="flex gap-3 text-sm">
            <button type="button" disabled={busy || !page.prev_page_url} onClick={() => router.get(page.prev_page_url, {}, { preserveState: true, preserveScroll: true })}>Previous notes</button>
            <span>Page {page.current_page} of {page.last_page}</span>
            <button type="button" disabled={busy || !page.next_page_url} onClick={() => router.get(page.next_page_url, {}, { preserveState: true, preserveScroll: true })}>Next notes</button>
        </nav>}
    </section>;
}

function NoteForm({ entry, today, categories, disabled, setBusy, onClose, setNotice, setDrafting }) {
    const [date, setDate] = useState(entry?.date || today);
    const [category, setCategory] = useState(entry?.category || "other_activities");
    const [body, setBody] = useState(entry?.body || "");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const submissionKey = useRef(null);
    const submitting = useRef(false);
    const submit = event => {
        event.preventDefault();
        if (submitting.current || disabled) return;
        if (!date || date > today || !body.trim()) { setError("Choose an activity date up to today and enter a note."); return; }
        submissionKey.current ||= globalThis.crypto.randomUUID();
        submitting.current = true; setSaving(true); setBusy(true); setError(""); setNotice("");
        const payload = { occurred_on: date, category, body, ...(entry ? { revision: entry.revision } : { submission_key: submissionKey.current }) };
        router[entry ? "patch" : "post"](endpoint + (entry ? "/" + entry.id : ""), payload, {
            preserveState: true, preserveScroll: true,
            onSuccess: () => {
                setDrafting(false);
                setNotice("Note saved. It appears when its activity date is included in the report range.");
                if (entry) onClose(); else { setBody(""); submissionKey.current = null; }
            },
            onError: errors => setError(errorsText(errors)),
            onFinish: () => { submitting.current = false; setSaving(false); setBusy(false); },
        });
    };
    return <form aria-label={entry ? "Edit dated weekly note" : "Add dated weekly note"} onSubmit={submit} className="space-y-2 rounded-xl bg-gray-50 p-3">
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-xs font-semibold">Activity date<input aria-label="Dated note date" type="date" required max={today} className={field} disabled={saving || disabled} value={date} onChange={event => { setDate(event.target.value); setDrafting(true); }} /></label>
            <label className="text-xs font-semibold">Note category<select aria-label="Dated note category" className={field} disabled={saving || disabled} value={category} onChange={event => { setCategory(event.target.value); setDrafting(true); }}>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
        </div>
        <label className="block text-xs font-semibold">Note<textarea aria-label="Dated note text" rows={3} required maxLength={4000} className={field} disabled={saving || disabled} value={body} onChange={event => { setBody(event.target.value); setDrafting(true); }} /></label>
        <p className="text-xs text-gray-500">The selected activity date determines which day and reporting period contains this note, even if you change the report filter while drafting.</p>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <button type="submit" disabled={saving || disabled} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{saving ? "Saving note…" : entry ? "Save dated note changes" : "Add dated note"}</button>
        {entry && <button type="button" disabled={saving} className="ml-3 text-sm" onClick={onClose}>Cancel note edit</button>}
        {!entry && <button type="button" disabled={saving || disabled} className="ml-3 text-sm" onClick={() => { setBody(""); setDate(today); setCategory("other_activities"); setError(""); submissionKey.current = null; setDrafting(false); }}>Discard dated note draft</button>}
    </form>;
}
