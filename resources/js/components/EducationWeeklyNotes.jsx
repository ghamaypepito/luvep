import { useState } from "react";
import { useForm } from "@inertiajs/react";

const timestamp = value => new Date(value).toLocaleString("en-NZ", { timeZone: "Pacific/Auckland", dateStyle: "medium", timeStyle: "short" });

export default function EducationWeeklyNotes({ report, saveUrl }) {
    const [editor, setEditor] = useState(null);
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        week_start: report.from, entry_action: "add", entry_id: "", note: "", expected_note: "",
    });
    const open = entry => {
        clearErrors();
        setData({ week_start: report.from, entry_action: entry ? "edit" : "add",
            entry_id: entry?.id || crypto.randomUUID(), note: entry?.note || "", expected_note: entry?.note || "" });
        setEditor(entry?.id || "new");
    };
    return <div className="mt-3 space-y-3">
        <div className="flex justify-end">
            <button type="button" disabled={!!editor} onClick={() => open(null)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold disabled:opacity-50">Add note</button>
        </div>
        {report.entries.length === 0 && !report.note && !editor && <p className="text-sm text-gray-500">No notes for this week yet.</p>}
        <ul className="space-y-3" aria-label="Weekly note entries">
            {report.entries.map(entry => <li key={entry.id} className="rounded-xl border border-gray-100 p-3">
                <div className="flex items-start justify-between gap-2">
                    <p className="text-xs text-gray-500">{entry.author} · <time dateTime={entry.created_at}>{timestamp(entry.created_at)} NZ</time></p>
                    {entry.can_edit && <button type="button" disabled={!!editor} onClick={() => open(entry)} className="text-xs font-semibold text-gray-600 disabled:opacity-50" aria-label={`Edit note by ${entry.author}`}>Edit</button>}
                </div>
                <p className="mt-2 whitespace-pre-wrap break-words text-sm text-gray-700">{entry.note}</p>
            </li>)}
        </ul>
        {editor && <form className="space-y-2" onSubmit={event => {
            event.preventDefault();
            post(saveUrl, { preserveScroll: true, onSuccess: () => setEditor(null) });
        }}>
            <label className="block text-xs font-semibold">{editor === "new" ? "New weekly note" : "Edit weekly note"}
                <textarea autoFocus rows={3} maxLength={4000} required value={data.note} onChange={event => setData("note", event.target.value)} className="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
            </label>
            {Object.keys(errors).length > 0 && <p role="alert" className="text-xs text-red-700">{Object.values(errors).join(" ")}</p>}
            <div className="flex justify-end gap-2">
                <button type="button" disabled={processing} onClick={() => setEditor(null)} className="px-3 py-1.5 text-xs">Cancel</button>
                <button type="submit" disabled={processing || !data.note.trim()} className="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">{processing ? "Saving…" : "Save note"}</button>
            </div>
        </form>}
    </div>;
}
