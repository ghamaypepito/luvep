export function educationNoteSubmission(enabled, writeBase, activity) {
    return enabled
        ? { base: "/portal/education", fields: { education_report_activity: activity } }
        : { base: writeBase, fields: {} };
}

export default function EducationNoteReportControl({ value, onChange, disabled = false }) {
    return <label className="block text-xs font-semibold text-gray-700">
        Record in my Education report as
        <select aria-label="Education note report activity" value={value} disabled={disabled}
            onChange={event => onChange(event.target.value)}
            className="ml-2 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs">
            <option value="update">Client update</option>
            <option value="follow_up">Follow-up</option>
        </select>
        <span className="mt-1 block font-normal text-gray-500">Recorded automatically when the note is saved. Choose Follow-up explicitly; note wording is not used to guess activity.</span>
    </label>;
}
