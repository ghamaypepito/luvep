const parse = value => new Date(`${value}T12:00:00`);
export const calendarDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
export function calendarRange(date, view) {
    const start = parse(date), end = parse(date);
    if (view === 'month') { start.setDate(1); end.setMonth(end.getMonth() + 1, 0); }
    if (view !== 'day') {
        start.setDate(start.getDate() - (start.getDay() + 6) % 7);
        end.setDate(end.getDate() + (7 - end.getDay()) % 7);
    }
    return { start: calendarDate(start), end: calendarDate(end) };
}
export default function CalendarGrid({ events, date, view, start, end, onDate, onEvent }) {
    const days = [];
    for (let day = parse(start); day <= parse(end); day.setDate(day.getDate() + 1)) days.push(calendarDate(day));
    return <div className={`hr-calendar-grid hr-calendar-${view}`}>
        {days.map(day => <section key={day} className={`hr-calendar-day ${view === 'month' && day.slice(0, 7) !== date.slice(0, 7) ? 'hr-calendar-outside' : ''}`} aria-label={day}>
            <button type="button" className="hr-calendar-date" onClick={() => onDate(day)}>{parse(day).toLocaleDateString('en', { weekday: 'short', day: 'numeric', month: view === 'month' ? undefined : 'short' })}</button>
            {events.filter(event => event.start <= day && event.end >= day).map(event => {
                const content = <><strong>{event.title}</strong><span>{event.detail}</span></>;
                return onEvent ? <button type="button" key={event.id} className={`hr-calendar-event hr-calendar-${event.kind}`} aria-label={`${event.title} · ${event.start} · ${event.detail}`} onClick={() => onEvent(event)}>{content}</button>
                    : <div key={event.id} className={`hr-calendar-event hr-calendar-${event.kind}`}>{content}</div>;
            })}
            {view === 'day' && !events.some(event => event.start <= day && event.end >= day) && <p>No events for this day.</p>}
        </section>)}
    </div>;
}
