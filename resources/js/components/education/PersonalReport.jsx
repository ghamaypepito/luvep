import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";

const errorText = errors => Object.values(errors || {}).flat().join(" ") || "Could not save. Please try again.";
const newKey = () => globalThis.crypto.randomUUID();
const fieldClass = "mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm";

export default function EducationPersonalReport({ data }) {
    return data ? <PersonalReportContent data={data} /> : null;
}

function PersonalReportContent({ data }) {
    const [query, setQuery] = useState("");
    const [clients, setClients] = useState([]);
    const [client, setClient] = useState(null);
    const [searchError, setSearchError] = useState("");
    const [kind, setKind] = useState("called");
    const [date, setDate] = useState(data.today);
    const [details, setDetails] = useState("");
    const [submissionKey, setSubmissionKey] = useState(newKey);
    const [busy, setBusy] = useState(false);
    const [activityError, setActivityError] = useState("");
    const [notice, setNotice] = useState("");
    const [notes, setNotes] = useState(data.notes);
    const [draftWeek, setDraftWeek] = useState(data.notes_week_start);
    const [dirty, setDirty] = useState(false);
    const [notesBusy, setNotesBusy] = useState(false);
    const [notesError, setNotesError] = useState("");
    const [removing, setRemoving] = useState(null);

    useEffect(() => {
        if (!dirty) { setNotes(data.notes); setDraftWeek(data.notes_week_start); }
    }, [data.notes, data.notes_week_start, dirty]);

    useEffect(() => {
        const abort = new AbortController();
        if (query.trim().length < 2) { setClients([]); setSearchError(""); return () => abort.abort(); }
        const timer = setTimeout(async () => {
            try {
                const response = await fetch("/portal/education/reports/clients?q=" + encodeURIComponent(query.trim()), {
                    credentials: "same-origin", headers: { Accept: "application/json" }, signal: abort.signal,
                });
                if (!response.ok) throw new Error("Client search failed. Please retry.");
                const payload = await response.json();
                if (!abort.signal.aborted) { setClients(payload.clients || []); setSearchError(""); }
            } catch (error) {
                if (!abort.signal.aborted) { setClients([]); setSearchError(error.message); }
            }
        }, 250);
        return () => { clearTimeout(timer); abort.abort(); };
    }, [query]);

    const record = event => {
        event.preventDefault();
        if (busy) return;
        if (!client) { setActivityError("Search for and choose a client first."); return; }
        setBusy(true); setActivityError(""); setNotice("");
        router.post("/portal/education/reports/activities", {
            lead_id: client.id, kind, occurred_on: date, details, submission_key: submissionKey,
        }, {
            preserveState: true, preserveScroll: true,
            onSuccess: () => { setDetails(""); setSubmissionKey(newKey()); setNotice("Activity saved. It appears when its date is included in the report range."); },
            onError: errors => setActivityError(errorText(errors)),
            onFinish: () => setBusy(false),
        });
    };
    const saveNotes = event => {
        event.preventDefault();
        if (notesBusy) return;
        setNotesBusy(true); setNotesError(""); setNotice("");
        router.post("/portal/education/reports/personal-notes", { week_start: draftWeek, ...notes }, {
            preserveState: true, preserveScroll: true,
            onSuccess: () => { setDirty(false); setNotice("Your weekly notes were saved."); },
            onError: errors => setNotesError(errorText(errors)),
            onFinish: () => setNotesBusy(false),
        });
    };
    const remove = id => {
        if (removing) return;
        setRemoving(id); setActivityError("");
        router.delete("/portal/education/reports/activities/" + id, {
            preserveState: true, preserveScroll: true,
            onError: errors => setActivityError(errorText(errors)),
            onFinish: () => setRemoving(null),
        });
    };
    const entries = data.entries || { data: [], current_page: 1, last_page: 1 };
    const needsContext = ["enrolment", "offer", "proposal"].includes(kind);

    return (
        <section aria-label="My Education accomplishments" className="space-y-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
            <div>
                <h2 className="text-lg font-bold text-gray-900">My Education accomplishments</h2>
                <p className="mt-1 text-xs text-gray-500">Your recorded activities follow the selected report dates. Multiple activities for the same client are counted separately and do not change their stage.</p>
            </div>
            {notice && <p role="status" className="text-sm text-teal-700">{notice}</p>}

            <section aria-label="My lead contact activity">
                <h3 className="text-sm font-semibold">My lead contact activity</h3>
                <p className="mt-1 text-xs text-gray-500">Only your recorded contact-stage changes for clients currently in List of Leads. Students are excluded.</p>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    {Object.entries(data.contacts || {}).map(([label, rows]) => (
                        <details key={label} className="rounded-xl border border-gray-200 p-3">
                            <summary className="cursor-pointer text-sm font-semibold">{label}: {rows.length} leads</summary>
                            {rows.length ? <ul className="mt-2 space-y-1 text-sm">{rows.map(row => <li key={row.id}>{row.name} · {new Date(row.date).toLocaleDateString("en-NZ", { timeZone: "Pacific/Auckland" })}</li>)}</ul> : <p className="mt-2 text-xs text-gray-500">No recorded contact changes for you in this range.</p>}
                        </details>
                    ))}
                </div>
            </section>

            <div aria-label="My activity totals" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                {Object.entries(data.counts || {}).map(([key, count]) => <div key={key} className="rounded-xl bg-gray-50 p-3">
                    <p className="text-xs font-semibold">{count.label}</p>
                    <p className="mt-1 text-sm">{count.events} {count.events === 1 ? "activity" : "activities"} · {count.clients} {count.clients === 1 ? "client" : "clients"}</p>
                </div>)}
            </div>

            <form aria-label="Record an accomplishment" onSubmit={record} className="space-y-3 rounded-xl border border-gray-200 p-3">
                <h3 className="text-sm font-semibold">Record an accomplishment</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-xs font-semibold">Activity
                        <select aria-label="Accomplishment activity" disabled={busy} className={fieldClass} value={kind} onChange={event => setKind(event.target.value)}>
                            {Object.entries(data.kinds || {}).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                    <label className="text-xs font-semibold">Activity date
                        <input aria-label="Accomplishment date" disabled={busy} type="date" required max={data.today} className={fieldClass} value={date} onChange={event => setDate(event.target.value)} />
                    </label>
                    <label className="text-xs font-semibold">Search client
                        <input aria-label="Search accomplishment client" disabled={busy} className={fieldClass} value={query} onChange={event => { setQuery(event.target.value); setClient(null); setClients([]); }} placeholder="Enter a name or client reference" />
                    </label>
                    <label className="text-xs font-semibold">Choose client
                        <select aria-label="Choose accomplishment client" disabled={busy} className={fieldClass} value={client?.id || ""} onChange={event => setClient(clients.find(item => String(item.id) === event.target.value) || null)}>
                            <option value="">Choose a search result</option>
                            {clients.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    </label>
                </div>
                {searchError && <p role="alert" className="text-sm text-red-700">{searchError}</p>}
                <label className="block text-xs font-semibold">Details {needsContext ? "(school, programme, offer or proposal reference required)" : "(optional)"}
                    <textarea aria-label="Accomplishment details" disabled={busy} required={needsContext} maxLength={1000} rows={2} className={fieldClass} value={details} onChange={event => setDetails(event.target.value)} />
                </label>
                {activityError && <p role="alert" className="text-sm text-red-700">{activityError}</p>}
                <button disabled={busy} type="submit" className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? "Saving activity…" : "Save activity"}</button>
            </form>

            <div className="overflow-x-auto">
                <h3 className="mb-2 text-sm font-semibold">My dated activity list</h3>
                {entries.data.length ? <table className="w-full text-left text-xs">
                    <thead><tr><th className="p-2">Date</th><th className="p-2">Client</th><th className="p-2">Activity</th><th className="p-2">Details</th><th className="p-2">Action</th></tr></thead>
                    <tbody>{entries.data.map(entry => <tr key={entry.id} className="border-t border-gray-100">
                        <td className="p-2">{entry.date}</td><td className="p-2">{entry.client}</td><td className="p-2">{data.kinds[entry.kind] || entry.kind}</td>
                        <td className="whitespace-pre-wrap p-2">{entry.details}</td>
                        <td className="p-2"><button type="button" disabled={Boolean(removing)} aria-label={"Remove activity " + entry.id} onClick={() => remove(entry.id)} className="text-red-700 disabled:opacity-50">Remove entry</button></td>
                    </tr>)}</tbody>
                </table> : <p className="text-sm text-gray-500">No accomplishments recorded for you in this range.</p>}
                {entries.last_page > 1 && <div className="mt-3 flex items-center gap-3 text-sm">
                    <button type="button" disabled={!entries.prev_page_url} onClick={() => router.get(entries.prev_page_url, {}, { preserveState: true, preserveScroll: true })}>Previous activities</button>
                    <span>Page {entries.current_page} of {entries.last_page}</span>
                    <button type="button" disabled={!entries.next_page_url} onClick={() => router.get(entries.next_page_url, {}, { preserveState: true, preserveScroll: true })}>Next activities</button>
                </div>}
            </div>

            <form aria-label="My weekly Education notes" onSubmit={saveNotes} className="space-y-3 border-t border-gray-200 pt-4">
                <h3 className="text-sm font-semibold">My weekly notes · week starting {data.notes_week_start}</h3>
                <p className="text-xs text-gray-500">Saved for your account and this week. Choose This week or Last week to view that week's notes; other date filters show the current week's notes.</p>
                {dirty && draftWeek !== data.notes_week_start && <p role="alert" className="text-sm text-amber-800">Your unsaved notes belong to the week starting {draftWeek}. Saving keeps them with that week.</p>}
                {[["accomplishments", "Weekly accomplishments"], ["other_activities", "Other activities"], ["next_week_tasks", "Next week's tasks"]].map(([key, label]) => (
                    <label key={key} className="block text-xs font-semibold">{label}
                        <textarea aria-label={label} disabled={notesBusy} maxLength={4000} rows={3} className={fieldClass} value={notes[key] || ""} onChange={event => { setDirty(true); setNotes(current => ({ ...current, [key]: event.target.value })); }} />
                    </label>
                ))}
                {notesError && <p role="alert" className="text-sm text-red-700">{notesError}</p>}
                <div className="flex gap-2">
                    <button disabled={notesBusy} type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{notesBusy ? "Saving notes…" : "Save my weekly notes"}</button>
                    {dirty && <button disabled={notesBusy} type="button" onClick={() => { setDirty(false); setNotes(data.notes); setDraftWeek(data.notes_week_start); setNotesError(""); }} className="rounded-lg border px-3 py-2 text-sm">Discard unsaved notes</button>}
                </div>
            </form>
        </section>
    );
}
