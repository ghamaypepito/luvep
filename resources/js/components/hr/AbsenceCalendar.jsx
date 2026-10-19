export default function AbsenceCalendar({ absences }) {
    return (
        <section className="hr-panel">
            <h2>Team availability</h2>
            <p>
                Approved time away in the next 30 days, limited to employees you
                oversee.
            </p>
            {absences.length ? (
                <ul className="hr-employee-list">
                    {absences.map((a) => (
                        <li key={a.id}>
                            <div>
                                <strong>{a.name}</strong>
                                <p>
                                    {a.start_date} – {a.end_date}
                                    {a.half_day && a.half_day !== "N/A"
                                        ? ` · ${a.half_day}`
                                        : ""}
                                </p>
                            </div>
                            <span className="hr-status">Away</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="hr-empty">No approved absences in this period.</p>
            )}
        </section>
    );
}
