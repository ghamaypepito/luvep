import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { hrPath } from '@/lib/hrPath';
import { duration } from '@/pages/hr/Dtr';
import CalendarGrid, { calendarDate, calendarRange } from './CalendarGrid';
import DtrRecordDialog from './DtrRecordDialog';

export function weeklyTotals(records) {
    const groups = new Map();
    for (const record of records) {
        const date = new Date(`${record.work_date}T12:00:00`);
        date.setDate(date.getDate() - (date.getDay() + 6) % 7);
        const start = calendarDate(date), key = `${record.employee_id}:${start}`;
        if (!groups.has(key)) groups.set(key, { employee_id: record.employee_id, name: record.employee_name, department: record.department, week: start, days: 0, working: 0, breaks: 0, approved: 0 });
        const total = groups.get(key);
        total.days++; total.working += record.working_seconds; total.breaks += record.break_seconds;
        if (record.hr_status === 'reviewed') total.approved += record.working_seconds;
    }
    return [...groups.values()].sort((a, b) => b.week.localeCompare(a.week) || a.name.localeCompare(b.name));
}

export default function DtrCalendar({ records, weeks = [], filters, path, title, summary = false, query = {}, oldestPending }) {
    const [selected, select] = useState(null), [search, setSearch] = useState('');
    const date = filters.from;
    const view = (new Date(`${filters.to}T12:00:00`) - new Date(`${date}T12:00:00`)) / 86400000 < 7 ? 'week' : 'month';
    const range = calendarRange(date, view);
    const matches = record => `${record.employee_name} ${record.department || ''} ${record.employee_number || ''}`.toLowerCase().includes(search.toLowerCase());
    const visible = records.filter(matches), visibleWeeks = weeks.filter(matches);
    const events = visible.map(record => ({ id: `dtr:${record.id}`, kind: record.pending_acknowledgements ? 'pending' : record.hr_status, start: record.work_date, end: record.work_date, title: record.employee_name,
        detail: `${duration(record.working_seconds)} · ${record.hr_status}${record.pending_acknowledgements ? ' · acknowledgement pending' : ''}`, record }));
    for (const week of visibleWeeks) events.push({ id: `week:${week.id}`, kind: 'submitted', start: week.week_start.slice(0, 10) < filters.from ? filters.from : week.week_start.slice(0, 10), end: week.week_start.slice(0, 10) < filters.from ? filters.from : week.week_start.slice(0, 10), title: week.employee_name, detail: 'Weekly declaration · awaiting review', record: week, weekly: true });
    const go = (nextDate, nextView = view) => {
        const day = new Date(`${nextDate}T12:00:00`);
        let from, to;
        if (nextView === 'month') { from = calendarDate(new Date(day.getFullYear(), day.getMonth(), 1, 12)); to = calendarDate(new Date(day.getFullYear(), day.getMonth() + 1, 0, 12)); }
        else ({ start: from, end: to } = calendarRange(nextDate, 'week'));
        router.get(hrPath(path), { ...filters, ...query, from, to }, { preserveScroll: true, preserveState: false });
    };
    const move = step => { const day = new Date(`${date}T12:00:00`); if (view === 'month') { day.setDate(1); day.setMonth(day.getMonth() + step); } else day.setDate(day.getDate() + step * 7); go(calendarDate(day)); };
    const current = selected && (selected.weekly ? visibleWeeks.find(week => week.id === selected.record.id) : visible.find(record => record.id === selected.record.id));
    return <>
        <section className="hr-panel" aria-label={title}>
            <div className="hr-calendar-toolbar"><div><p className="hr-eyebrow">Attendance · work reports · changes</p><h2>{title}</h2></div><div className="hr-actions">
                <button type="button" className="hr-button-secondary" aria-label="Previous period" onClick={() => move(-1)}><ChevronLeft size={18} /></button>
                <button type="button" className="hr-button-secondary" onClick={() => go(new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()))}>Today</button>
                <button type="button" className="hr-button-secondary" aria-label="Next period" onClick={() => move(1)}><ChevronRight size={18} /></button>
                <form className="hr-actions" onSubmit={event => { event.preventDefault(); go(new FormData(event.currentTarget).get("date")); }}><input type="date" name="date" aria-label="Go to work date" required defaultValue={date} /><button type="submit" className="hr-button-secondary">Go</button></form>
                <select aria-label="Calendar view" value={view} onChange={event => go(date, event.target.value)}><option value="month">Month</option><option value="week">Week</option></select>
            </div></div>
            <div className="hr-calendar-toolbar"><strong>{new Date(`${date}T12:00:00`).toLocaleDateString('en', { month: 'long', year: 'numeric' })}</strong><input type="search" aria-label="Find employee in calendar" placeholder="Find employee or department" value={search} onChange={event => setSearch(event.target.value)} /></div>
            <p className="hr-muted">Showing {filters.from} – {filters.to} · {visible.length} daily records{weeks.length ? ` · ${visibleWeeks.length} weekly declarations` : ''}. Dates follow each recorded workday. Select a name for details, review and change history.</p>
            {oldestPending && oldestPending < filters.from && <p className="hr-notice">Older records need attention. <button type="button" className="hr-text-link" onClick={() => go(oldestPending)}>Open earliest pending period ({oldestPending.slice(0, 10)})</button></p>}
            <div className="hr-dtr-legend" aria-label="Calendar status legend"><span>Draft / legacy</span><span>Submitted</span><span>Reviewed</span><span>Returned</span><span>Acknowledgement pending</span></div>
            {!events.length && <p>No records match this period and employee search.</p>}
            <CalendarGrid events={events} date={date} view={view} {...range} onDate={day => go(day, 'week')} onEvent={select} />
        </section>
        {summary && <section aria-label="Weekly employee totals"><h2 className="hr-subheading">Weekly totals per employee</h2><p className="hr-muted">Monday–Sunday, using all matching records in the selected date range. Partial weeks include only the selected dates. Recorded hours include drafts; approved hours include reviewed records only. Open shifts are a snapshot at page load.</p>
            <div className="hr-weekly-cards">{weeklyTotals(visible).map(total => <article className="hr-panel" key={`${total.employee_id}:${total.week}`}><p className="hr-eyebrow">Week of {total.week}</p><h3>{total.name}</h3><p className="hr-muted">{total.department || 'Department not set'} · {total.days} recorded day{total.days === 1 ? '' : 's'}</p><dl className="hr-weekly-totals"><div><dt>Recorded work</dt><dd>{duration(total.working)}</dd></div><div><dt>Breaks</dt><dd>{duration(total.breaks)}</dd></div><div><dt>Approved work</dt><dd>{duration(total.approved)}</dd></div></dl></article>)}</div>
            {!visible.length && <p>No weekly totals for this selection.</p>}
        </section>}
        {current && <DtrRecordDialog key={selected.id} record={current} weekly={selected.weekly} onClose={() => select(null)} />}
    </>;
}
