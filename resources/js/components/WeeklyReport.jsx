import { useId, useState } from "react";
import { useForm } from "@inertiajs/react";
import { ClipboardList, PencilLine } from "lucide-react";
import EducationWeeklyNotes from "./EducationWeeklyNotes";

export const WEEK_PRESETS = [
    { key: "this_week", label: "This week" },
    { key: "last_week", label: "Last week" },
];

export function WeekButtons({ preset, onChange }) {
    return (
        <div role="group" aria-label="Report week" className="flex flex-wrap gap-1.5">
            {WEEK_PRESETS.map(({ key, label }) => (
                <button key={key} type="button" aria-pressed={preset === key} onClick={() => onChange(key)}
                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${preset === key ? "border-gray-900 bg-gray-900 text-white" : "border-gray-200 bg-white text-gray-600 hover:bg-gray-100"}`}>
                    {label}
                </button>
            ))}
        </div>
    );
}

const dateLabel = (date) => new Date(`${date}T12:00:00Z`).toLocaleDateString("en-NZ", {
    day: "numeric", month: "short", timeZone: "UTC",
});

export default function WeeklyReport({ report, saveUrl }) {
    if (!report) return null;

    return <WeekCard key={report.from} report={report} saveUrl={saveUrl} />;
}

function WeekCard({ report, saveUrl }) {
    const id = useId();
    const [editing, setEditing] = useState(false);
    const { data, setData, post, processing, errors, clearErrors, recentlySuccessful } = useForm({
        week_start: report.from, note: report.note,
    });
    const startEditing = () => {
        clearErrors();
        setData("note", report.note);
        setEditing(true);
    };

    return (
        <div className={`grid gap-3 ${report.tasks ? "lg:grid-cols-2" : ""}`}>
            <section className="min-w-0 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" aria-labelledby={`${id}-title`}>
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 id={`${id}-title`} className="text-sm font-bold text-gray-900">Weekly notes</h2>
                        <p className="mt-1 text-xs text-gray-500">
                            {dateLabel(report.from)} – {dateLabel(report.to)} · {report.personal ? "Personal note" : "Shared in this portal"}
                        </p>
                    </div>
                    {!editing && (!report.entries || report.note) && (
                        <button type="button" onClick={startEditing} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                            <PencilLine size={13} /> {report.entries ? "Edit previous note" : report.note ? "Edit note" : "Add note"}
                        </button>
                    )}
                </div>
                {report.entries && <EducationWeeklyNotes report={report} saveUrl={saveUrl} />}
                {editing ? (
                    <form className="mt-3 space-y-2" onSubmit={(event) => {
                        event.preventDefault();
                        post(saveUrl, { preserveScroll: true, onSuccess: () => setEditing(false) });
                    }}>
                        <label htmlFor={id} className="sr-only">Weekly notes</label>
                        <textarea id={id} autoFocus rows={3} maxLength={4000} value={data.note}
                            onChange={(event) => setData("note", event.target.value)}
                            className="block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:border-gray-500 focus:outline-none"
                            placeholder="Record updates, blockers or follow-ups for this week." />
                        {(errors.note || errors.week_start) && <p role="alert" className="text-xs text-red-700">{errors.note || errors.week_start}</p>}
                        <div className="flex justify-end gap-2">
                            <button type="button" disabled={processing} onClick={() => setEditing(false)} className="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-100">Cancel</button>
                            <button type="submit" disabled={processing} className="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">{processing ? "Saving…" : "Save note"}</button>
                        </div>
                    </form>
                ) : (
                    (!report.entries || report.note) && <div className="mt-3">
                        {report.entries && <p className="text-xs text-gray-500">Previous weekly note</p>}
                        <p className="whitespace-pre-wrap break-words text-sm text-gray-700">{report.note || "No notes for this week yet."}</p>
                    </div>
                )}
                {recentlySuccessful && <p role="status" className="mt-2 text-xs text-emerald-700">Weekly note saved.</p>}
            </section>
            {report.tasks && (
                <section className="min-w-0 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" aria-labelledby={`${id}-tasks`}>
                    <h2 id={`${id}-tasks`} className="flex items-center gap-2 text-sm font-bold text-gray-900"><ClipboardList size={15} /> Task Board activity</h2>
                    <dl className="mt-2 flex gap-4 text-sm text-gray-600">
                        <div><dt className="inline">Created </dt><dd className="inline font-bold text-gray-900">{report.tasks.created}</dd></div>
                        <div><dt className="inline">Completed </dt><dd className="inline font-bold text-gray-900">{report.tasks.completed}</dd></div>
                    </dl>
                    {report.tasks.recent.length > 0 && (
                        <details className="mt-2">
                            <summary className="cursor-pointer text-xs font-semibold text-gray-600">Recent completions</summary>
                            <ul className="mt-2 space-y-1">
                                {report.tasks.recent.map(({ id, title, date }) => (
                                    <li key={id} className="flex justify-between gap-2 text-xs text-gray-700"><span className="break-words">{title}</span><time className="shrink-0 text-gray-500" dateTime={date}>{dateLabel(date)}</time></li>
                                ))}
                            </ul>
                        </details>
                    )}
                </section>
            )}
        </div>
    );
}
