import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, CalendarDays } from 'lucide-react';
import { hrPath } from '@/lib/hrPath';
const parse = (s) => new Date(`${s}T12:00:00`);
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
export default function Calendar({events,date,view,start,end,timezone}) {
 const selected=parse(date), days=[];
 for(let d=parse(start);d<=parse(end);d.setDate(d.getDate()+1))days.push(iso(d));
 const go=(nextDate,nextView=view)=>router.get(hrPath('/calendar'),{date:nextDate,view:nextView},{preserveScroll:true});
 const move=(step)=>{const d=parse(date);if(view==='month'){d.setDate(1);d.setMonth(d.getMonth()+step);}else d.setDate(d.getDate()+step*(view==='week'?7:1));go(iso(d));};
 return <><Head title="HR calendar"/><header className="hr-page-heading"><p className="hr-eyebrow">People & culture</p><h1>Calendar</h1><p>Plan your work, leave and upcoming deadlines. Details follow your access permissions.</p></header>
 <section className="hr-panel"><div className="hr-calendar-toolbar"><div className="hr-actions"><CalendarDays size={22}/><h2>{selected.toLocaleDateString('en',{month:'long',year:'numeric',...(view==='day'?{day:'numeric'}:{})})}</h2></div><div className="hr-actions"><button className="hr-button-secondary" onClick={()=>move(-1)} aria-label="Previous period"><ChevronLeft size={18}/></button><button className="hr-button-secondary" onClick={()=>go(new Intl.DateTimeFormat('en-CA',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()))}>Today</button><button className="hr-button-secondary" onClick={()=>move(1)} aria-label="Next period"><ChevronRight size={18}/></button><select aria-label="Calendar view" value={view} onChange={e=>go(date,e.target.value)}>{['month','week','day'].map(v=><option key={v} value={v}>{v[0].toUpperCase()+v.slice(1)}</option>)}</select><input aria-label="Go to date" type="date" value={date} onChange={e=>e.target.value&&go(e.target.value)}/></div></div>
 <p className="hr-muted">Schedules use {timezone}. Leave reasons and private HR notes are excluded.</p>
 <div className={`hr-calendar-grid hr-calendar-${view}`}>{days.map(day=><section key={day} className={`hr-calendar-day ${day.slice(0,7)!==date.slice(0,7)?'hr-calendar-outside':''}`} aria-label={day}><button className="hr-calendar-date" onClick={()=>go(day,'day')}>{parse(day).toLocaleDateString('en',{weekday:'short',day:'numeric',month:view==='month'?undefined:'short'})}</button>{events.filter(e=>e.start<=day&&e.end>=day).map(e=><div key={e.id} className={`hr-calendar-event hr-calendar-${e.kind}`}><strong>{e.title}</strong><span>{e.detail}</span></div>)}{view==='day'&&!events.some(e=>e.start<=day&&e.end>=day)&&<p>No events for this day.</p>}</section>)}</div></section></>;
}
