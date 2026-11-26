import { useId, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ChevronLeft, ChevronRight, CalendarDays, Plus } from 'lucide-react';

export const dateLabel = (date, options = {}) => date ? new Date(`${date.slice(0, 10)}T12:00:00Z`).toLocaleDateString('en-NZ', {day:'numeric',month:'short',timeZone:'UTC',...options}) : '—';
export const inventoryComplete = stats => !!stats && !stats.unverified_properties && !stats.missing_capacity;

export function InsightsHeader({ reports = false, asOf, children }) {
    return <header className="ai-header">
        <div><nav className="ai-tabs" aria-label="Accommodation insights">
            <Link href="/portal/accommodation/dashboard" aria-current={!reports ? 'page' : undefined}>Dashboard</Link>
            <Link href="/portal/accommodation/reports" aria-current={reports ? 'page' : undefined}>Reports</Link>
        </nav><h1>{reports ? 'Accommodation reports' : 'A clear view of your portfolio.'}</h1>
            <p>{reports ? 'Your team’s activity, room coverage and weekly updates.' : 'Properties, people and the rooms they call home.'}</p>
        </div>
        <div className="ai-header-actions">{asOf && <span className="ai-snapshot">Portfolio as of <time dateTime={asOf}>{dateLabel(asOf, {year:'numeric'})}</time> · NZ</span>}
            {children || <Link className="ai-primary" href="/portal/accommodation/properties/create"><Plus size={16}/> New property</Link>}
        </div>
    </header>;
}

export function PortfolioSummary({ stats, onFilter = () => {} }) {
    const complete = inventoryComplete(stats);
    const cards = [
        ['Active properties', stats?.properties, 'Managed portfolio', '/portal/accommodation/properties'],
        ['Total rooms', stats && !stats.missing_capacity ? stats.rooms : null, 'Recorded room capacity', '#room-inventory'],
        ['Occupied rooms', complete ? stats.occupied : null, complete ? 'Distinct rooms in use' : 'Room setup needs review', '#room-inventory'],
        ['Active tenant agreements', stats?.active_agreements, 'Signed and linked to current tenancies', '/portal/accommodation/forms/agreements'],
        ['Current household tenancies', stats?.households, 'People sharing a tenancy count once', '/portal/accommodation/tenants'],
        ['Vacant rooms', complete ? stats.vacant : null, complete ? 'Verified empty rooms' : 'Room setup needs review', '#room-inventory', 'vacant'],
        ['Available rooms to let', complete ? stats.available_to_let : null, 'Excludes whole-property bedrooms', '#room-inventory', 'available'],
        ['Unavailable rooms', complete ? stats.unavailable : null, 'Verified rooms held from letting', '#room-inventory'],
    ];
    return <div className="ai-summary" aria-label="Property and room totals">{cards.map(([label, value, note, href, filter]) => {
        const content = <><p className="ai-metric-label">{label}</p><p className="ai-metric-value">{value ?? '—'}</p><p className="ai-metric-note">{note}</p></>;
        return href.startsWith('#') ? <a key={label} href={href} onClick={() => onFilter(filter || 'all')}>{content}</a>
            : <Link key={label} href={href}>{content}</Link>;
    })}</div>;
}

export function OccupancyPanel({ stats }) {
    const complete = inventoryComplete(stats);
    const rate = complete && stats.rooms > 0 ? Math.round(stats.occupied / stats.rooms * 100) : null;
    const rows = stats?.rows || [];
    return <section className="ai-panel ai-occupancy" aria-label="Current room occupancy">
        <div className="ai-panel-heading"><div><h2>Rooms at a glance</h2><p>Current occupancy by property</p></div><Link className="ai-icon-link" href="/portal/accommodation/properties" aria-label="Manage property room records"><ArrowUpRight size={20}/></Link></div>
        <div className="ai-occupancy-head"><div><strong>{rate == null ? '—' : `${rate}%`}</strong><span>{rate == null ? 'Needs review' : 'of recorded rooms occupied'}</span></div>
            <div className="ai-legend"><span><i className="ai-dot occupied"/>Occupied</span><span><i className="ai-dot vacant"/>Vacant</span><span><i className="ai-dot unavailable"/>Unavailable</span></div>
        </div>
        {!complete && stats && <p role="status" className="ai-coverage">{stats.unverified_properties} properties need a room check; {stats.unverified_rooms} known-capacity rooms are not verified. Unassigned rooms are not assumed vacant.</p>}
        <ul className="ai-property-bars">{rows.map(row => {
            const known = row.state === 'verified' && row.total > 0;
            return <li key={row.id}><div className="ai-bar-caption"><Link href={`/portal/accommodation/properties/${row.id}`}>{row.code ? `${row.code.replace(/^#/, '')}. ` : ''}{row.name}</Link><span>{known ? `${row.occupied} / ${row.total}` : 'Needs review'}</span></div>
                <div className={`ai-room-bar ${known ? '' : 'is-unknown'}`} aria-label={known ? `${row.occupied} occupied, ${row.vacant} vacant, ${row.unavailable} unavailable out of ${row.total} rooms` : 'Room assignments need review'}>
                    {known && <><span className="occupied" style={{width:`${row.occupied / row.total * 100}%`}}/><span className="vacant" style={{width:`${row.vacant / row.total * 100}%`}}/><span className="unavailable" style={{width:`${row.unavailable / row.total * 100}%`}}/></>}
                </div></li>;
        })}</ul>
        {!rows.length && <p className="ai-empty">{stats ? 'No active properties yet. Add a property to begin tracking rooms.' : 'Room figures are unavailable. Refresh to try again.'}</p>}
        <p className="ai-footnote">Each physical room counts once, including rooms shared by multiple households.</p>
    </section>;
}

export function ViewingCalendar({ insights, onMonthChange }) {
    const [selected, setSelected] = useState(null);
    const calendar = insights?.calendar;
    if (!calendar) return <section className="ai-panel"><h2>Viewing schedule</h2><p className="ai-empty">Viewing schedule unavailable. Refresh to try again.</p></section>;
    const month = new Date(`${calendar.month}-01T12:00:00Z`);
    const firstDay = (month.getUTCDay() + 6) % 7;
    const totalDays = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + 1, 0)).getUTCDate();
    const bookings = calendar.bookings || [];
    const currentMonth = calendar.month === insights.as_of?.slice(0, 7);
    const visible = selected ? bookings.filter(item => item.date === selected) : currentMonth
        ? bookings.filter(item => item.date >= insights.as_of && item.status?.toLowerCase() !== 'completed') : bookings;
    const changeMonth = delta => {
        const next = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + delta, 1, 12));
        onMonthChange?.(next.toISOString().slice(0, 7));
    };
    return <section className="ai-panel ai-calendar" aria-label="Accommodation viewing schedule">
        <div className="ai-panel-heading"><h2>Viewing schedule</h2><CalendarDays size={19}/></div>
        <div className="ai-month-nav"><h3>{month.toLocaleDateString('en-NZ', {month:'long',year:'numeric',timeZone:'UTC'})}</h3><div>
            <button type="button" aria-label="Previous month" onClick={() => changeMonth(-1)}><ChevronLeft size={16}/></button>
            <button type="button" aria-label="Next month" onClick={() => changeMonth(1)}><ChevronRight size={16}/></button>
        </div></div>
        <div className="ai-calendar-grid" aria-label="Viewing dates">
            {['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(day => <span className="ai-weekday" key={day}>{day}</span>)}
            {Array.from({length:firstDay}, (_, i) => <span key={`empty-${i}`}/>)}
            {Array.from({length:totalDays}, (_, i) => {
                const day = `${calendar.month}-${String(i + 1).padStart(2, '0')}`;
                const count = bookings.filter(item => item.date === day).length;
                return <button type="button" key={day} aria-label={`${dateLabel(day, {month:'long',year:'numeric'})}, ${count} viewing${count === 1 ? '' : 's'}`}
                    aria-pressed={selected === day} aria-current={insights.as_of === day ? 'date' : undefined}
                    className={`${selected === day ? 'is-selected' : ''} ${count ? 'has-viewing' : ''}`} onClick={() => setSelected(day)}>{i + 1}{count > 0 && <i/>}</button>;
            })}
        </div>
        <div className="ai-schedule-label"><h3>{selected ? dateLabel(selected, {month:'long'}) : currentMonth ? 'Today & upcoming' : 'This month'}</h3>{selected && <button type="button" onClick={() => setSelected(null)}>Reset dates</button>}<span>{visible.length} booked</span></div>
        <ul className="ai-viewings">{visible.slice(0, 4).map(item => <li key={item.id}>
            <div className="ai-viewing-date"><strong>{item.date.slice(8)}</strong><span>{dateLabel(item.date, {day:undefined,month:'short'})}</span></div>
            <div><p>{item.name || 'Property viewing'}</p><span>{item.property || 'Property to be confirmed'}</span><small>{item.time || 'Time to be confirmed'} · {item.status}</small></div>
        </li>)}</ul>
        {!visible.length && <p className="ai-empty">{selected ? 'No viewings booked for this day.' : currentMonth ? 'No upcoming viewings this month.' : 'No viewings booked for this month.'}</p>}
        <Link className="ai-text-link" href="/portal/accommodation/viewings">View all viewings <ArrowUpRight size={15}/></Link>
        <p className="ai-footnote">New Zealand dates · Cancelled bookings excluded.</p>
    </section>;
}

export function TaskActivity({ insights }) {
    const id = useId();
    const days = insights?.daily_tasks;
    const created = days?.reduce((sum, day) => sum + day.created, 0);
    const completed = days?.reduce((sum, day) => sum + day.completed, 0);
    const max = Math.max(1, ...(days || []).flatMap(day => [day.created, day.completed]));
    return <section className="ai-panel ai-task-activity" aria-labelledby={id}>
        <div className="ai-panel-heading"><div><h2 id={id}>Team activity</h2><p>{insights ? `${dateLabel(insights.period.from)} – ${dateLabel(insights.period.to)} · New Zealand time` : 'Task activity unavailable'}</p></div><Link className="ai-icon-link" aria-label="Open Accommodation task tracker" href="/portal/accommodation/tasks"><ArrowUpRight size={20}/></Link></div>
        <div className="ai-task-totals"><div><span>Tasks created</span><strong data-total="created">{created ?? '—'}</strong></div><div><span>Tasks completed</span><strong data-total="completed">{completed ?? '—'}</strong></div></div>
        {days && <><div className="ai-legend"><span><i className="ai-dot created"/>Created</span><span><i className="ai-dot occupied"/>Completed</span></div>
            <div className="ai-task-chart" aria-label="Daily task counts">{days.map(day => <div className="ai-task-day" key={day.date}>
                <div className="ai-task-bars"><div style={{height:`${day.created / max * 100}%`}} className="created" title={`${day.created} created`}><span>{day.created}</span></div><div style={{height:`${day.completed / max * 100}%`}} className="occupied" title={`${day.completed} completed`}><span>{day.completed}</span></div></div>
                <time dateTime={day.date}>{dateLabel(day.date, {day:undefined,month:undefined,weekday:'short'})}</time>
            </div>)}</div>
            <table className="sr-only"><caption>Daily task activity</caption><thead><tr><th>Date</th><th>Created</th><th>Completed</th></tr></thead><tbody>{days.map(day => <tr key={day.date}><th>{day.date}</th><td>{day.created}</td><td>{day.completed}</td></tr>)}</tbody></table>
        </>}
        <p className="ai-footnote">Completions can include tasks created before this week. These counts do not represent a completion percentage.</p>
    </section>;
}

export function InventoryTable({ stats, filter = 'all', onFilter = () => {} }) {
    const rows = (stats?.rows || []).filter(row => filter === 'all' || (filter === 'vacant' ? row.vacant > 0 : filter === 'available' ? row.available_to_let > 0 : row.state !== 'verified'));
    return <section id="room-inventory" className="ai-panel ai-inventory">
        <div className="ai-panel-heading"><div><h2>Room inventory by property</h2><p>Room records and current assignments</p></div><select aria-label="Room inventory filter" value={filter} onChange={event => onFilter(event.target.value)}>
            <option value="all">All active properties</option><option value="vacant">Verified vacancies</option><option value="available">Available to let per room</option><option value="unverified">Needs verification</option>
        </select></div>
        <div className="ai-table-scroll" role="region" aria-label="Property room details" tabIndex={0}><table><thead><tr>{['Property','Rooms','Households','Occupied','Vacant','Unavailable','Verification'].map(label => <th key={label}>{label}</th>)}</tr></thead>
            <tbody>{rows.map(row => <tr key={row.id}><td><Link href={`/portal/accommodation/properties/${row.id}`}>{row.code ? `${row.code} · ` : ''}{row.name}</Link>{row.vacancy_notes && <p>{row.vacancy_notes}</p>}</td>
                <td>{row.total ?? 'Not set'}</td><td>{row.households}</td><td>{row.occupied ?? '—'}</td><td>{row.vacant ?? '—'}</td><td>{row.unavailable ?? '—'}</td><td><span className={`ai-status ${row.state === 'verified' ? 'is-verified' : ''}`}>{row.state === 'verified' ? row.tracked ? 'Room assignments' : `Checked ${row.observed_on}` : 'Needs review'}</span>{row.unlabelled_households > 0 && <p>{row.unlabelled_households} households need {row.tracked ? 'room assignments' : 'room labels'}</p>}</td></tr>)}</tbody></table></div>
        {!rows.length && <p className="ai-empty">No matching verified records. This does not establish that unverified properties have no vacancies.</p>}
        <p className="ai-footnote">Totals update from current tenant assignments once the room register is complete. Properties awaiting setup use their latest valid room check.</p>
    </section>;
}
