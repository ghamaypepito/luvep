import { Link, router } from "@inertiajs/react";

const date = value => new Date(value).toLocaleString("en-NZ", { timeZone: "Pacific/Auckland", dateStyle: "medium", timeStyle: "short" });

export default function EducationNoteActivityReport({ data }) {
    if (!data) return null;
    const entries = data.entries;
    return <section aria-label="My automatic Education note activity" className="mt-6 space-y-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
        <div>
            <h2 className="text-lg font-bold text-gray-900">My automatic note activity</h2>
            <p className="mt-1 text-sm text-gray-600">{data.total} posted notes · {data.clients} distinct clients in the selected report dates.</p>
            <p className="mt-1 text-xs text-gray-500">Your Education note posts only, for records you can currently access. Every post is counted separately; edits and pin changes do not add activities. These counts are separate from stages and manually recorded accomplishments.</p>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
            {Object.entries(data.counts).map(([kind, count]) => <div key={kind} className="rounded-xl bg-gray-50 p-3">
                <h3 className="text-sm font-semibold">{count.label}</h3>
                <p className="text-sm">{count.events} activities · {count.clients} distinct clients</p>
            </div>)}
        </div>
        {entries.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm">
            <thead><tr><th className="p-2">Posted (NZ)</th><th className="p-2">Client</th><th className="p-2">Activity</th><th className="p-2">Author</th></tr></thead>
            <tbody>{entries.data.map(entry => <tr key={entry.id} className="border-t border-gray-100">
                <td className="p-2">{date(entry.posted_at)}</td>
                <td className="p-2"><Link className="text-blue-700 underline" href={entry.url}>{entry.client}</Link></td>
                <td className="p-2">{entry.activity === "follow_up" ? "Follow-up" : "Client update"}{entry.is_reply ? " · Reply" : ""}
                    {!entry.note_available && <span className="block text-xs text-gray-500">Original note removed; posting remains recorded.</span>}</td>
                <td className="p-2">{entry.author}</td>
            </tr>)}</tbody>
        </table></div> : <p className="text-sm text-gray-500">No tracked note posts in this period.</p>}
        {entries.last_page > 1 && <nav aria-label="Note activity pages" className="flex gap-3 text-sm">
            <button type="button" disabled={!entries.prev_page_url} onClick={() => router.get(entries.prev_page_url, {}, { preserveState: true, preserveScroll: true })}>Previous note activities</button>
            <span>Page {entries.current_page} of {entries.last_page}</span>
            <button type="button" disabled={!entries.next_page_url} onClick={() => router.get(entries.next_page_url, {}, { preserveState: true, preserveScroll: true })}>Next note activities</button>
        </nav>}
        <p className="text-xs text-gray-500">Tracking starts when this update is deployed. Earlier notes are not retrospectively assigned a department or a follow-up label. Note contents and attachments remain in the private client profile.</p>
    </section>;
}
