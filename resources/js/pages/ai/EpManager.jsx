import { useCallback, useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowUpRight, Bell, CalendarDays, MessageSquare, RefreshCw, Users } from 'lucide-react';
import { epAgentClient as api } from '../../lib/epAgentClient';
import QuickDetails from '../../components/ep-agent/QuickDetails';
import '../../../css/ep-agent.css';
const tabs = [{ id: 'today', label: 'Today', icon: CalendarDays }, { id: 'attention', label: 'Attention', icon: Bell }, { id: 'team', label: 'Team', icon: Users }, { id: 'ask', label: 'Ask Ep', icon: MessageSquare }];
const date = value => value ? new Date(value).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Update not recorded';
export function ManagerView({ ep }) {
    const [portal, setPortal] = useState(ep.portals[0]), [tab, setTab] = useState('today'), [data, setData] = useState(null);
    const [error, setError] = useState(''), [loading, setLoading] = useState(false), [detail, setDetail] = useState(null), [locked, setLocked] = useState(false), [cursor, setCursor] = useState(null);
    const alive = useRef(true), version = useRef(0), controllers = useRef(new Set());
    const accessLost = useCallback(() => { ++version.current; setData(null); setDetail(null); setLoading(false); setLocked(true); setError('Your access changed or your session ended. Reload the page to continue.'); }, []);
    const closeDetails = useCallback(() => setDetail(null), []);
    const load = useCallback(async (before) => {
        const current = ++version.current, controller = new AbortController(); controllers.current.add(controller);
        setLoading(true); setError(''); setDetail(null); setData(null); setCursor(before || null);
        try {
            const result = await api.manager(portal, { before, attention: tab === 'attention', signal: controller.signal });
            if (!alive.current || current !== version.current) return;
            if ([401, 403, 419].includes(result.status)) { accessLost(); return; }
            if (!result.ok) throw Error();
            setData(result.data);
        } catch (e) { if (alive.current && current === version.current && e.name !== 'AbortError') setError('Manager could not load. Refresh to try again.'); }
        finally { controllers.current.delete(controller); if (alive.current && current === version.current) setLoading(false); }
    }, [portal, tab, accessLost]);
    useEffect(() => { alive.current = true; setDetail(null); load(); return () => { alive.current = false; ++version.current; controllers.current.forEach(controller => controller.abort()); controllers.current.clear(); }; }, [load]);
    useEffect(() => { const refresh = () => { if (!document.hidden && !locked) load(); }; document.addEventListener('visibilitychange', refresh); return () => document.removeEventListener('visibilitychange', refresh); }, [load, locked]);
    const open = (kind, id) => setDetail({ kind, id });
    return <main className="ep-shell ep-manager-shell">
        <header className="ep-page-heading"><div><h1>Ep Manager</h1><p>A clearer view of the work you can access.</p></div><button type="button" className="ep-icon" aria-label="Refresh Manager" disabled={loading || locked} onClick={() => load()}><RefreshCw size={19} /></button></header>
        <div className="ep-manager-toolbar"><label>Portal <select value={portal} disabled={locked} onChange={event => { setDetail(null); setPortal(event.target.value); }}>{ep.portals.map(value => <option key={value} value={value}>{value === 'education' ? 'Education' : 'Immigration'}</option>)}</select></label><span>Read-only · AI generation off</span></div>
        <nav className="ep-manager-nav" aria-label="Manager navigation">{tabs.map(({ id, label, icon: Icon }) => <button type="button" key={id} aria-current={tab === id ? 'page' : undefined} disabled={locked} onClick={() => setTab(id)}><Icon size={18} /><span>{label}</span></button>)}</nav>
        {error && <p role="alert" className="ep-error">{error}</p>}
        {loading && !data && <p role="status" className="ep-manager-loading">Loading authorized records…</p>}
        {data && <>
            {tab !== 'ask' && <div className="ep-summary-strip"><div><span>Accessible records</span><strong>{data.counts.total}</strong></div><div><span>No update in 7 days</span><strong>{data.counts.inactive}</strong></div><div><span>Unassigned</span><strong>{data.counts.unassigned}</strong></div></div>}
            {tab === 'today' || tab === 'attention' ? <section className="ep-manager-records" aria-labelledby="ep-records-heading">
                <div className="ep-section-heading"><h2 id="ep-records-heading">{tab === 'attention' ? 'No recent record update' : 'CRM records'}</h2><span>{data.records.length} shown</span></div>
                {tab === 'attention' && <p className="ep-muted ep-section-note">These records have no update in the last seven days. This does not determine a case deadline or outcome.</p>}
                {!data.records.length && <div className="ep-manager-empty"><CalendarDays size={28} /><h3>{tab === 'attention' ? 'No inactive records in this view' : 'No accessible records yet'}</h3><p>Only records within your current portal and assignment permissions appear here.</p></div>}
                {data.records.map(record => <article className="ep-record-row" key={record.id}>
                    <div className="ep-record-main"><small>Record #{record.id}</small><button type="button" className="ep-name-button" onClick={() => open('clients', record.client.id)}>{record.client.name}</button><span className="ep-stage">{record.stage || 'Stage not recorded'}</span></div>
                    <div className="ep-record-meta"><span>Assigned personnel</span>{record.personnel ? <button type="button" className="ep-person-button" onClick={() => open('personnel', record.personnel.id)}><Users size={13} />{record.personnel.name}</button> : <p>{record.assignment_status === 'unavailable' ? 'Personnel unavailable' : 'Unassigned'}</p>}<small>{date(record.updated_at)}</small></div>
                    <a className="ep-record-chat" aria-label={`Open private chat for ${record.client.name}`} href={`/ep-agent?portal=${encodeURIComponent(portal)}&lead_id=${record.id}`}><ArrowUpRight size={18} /><span>Private chat</span></a>
                </article>)}
                {cursor && <button type="button" className="ep-text-button" disabled={loading} onClick={() => load()}>First records</button>}
                {data.next_before && <button type="button" className="ep-text-button" disabled={loading} onClick={() => load(data.next_before)}>{loading ? 'Loading…' : 'Next records'}</button>}
            </section> : tab === 'team' ? <section className="ep-team" aria-labelledby="ep-team-heading"><div className="ep-section-heading"><h2 id="ep-team-heading">Assigned personnel</h2></div><p className="ep-muted ep-section-note">Workloads count only the records you are authorized to access.</p>{data.team.map(person => <button key={person.id} type="button" className="ep-team-person" onClick={() => open('personnel', person.id)}><span className="ep-person-initial" aria-hidden="true">{person.name.charAt(0)}</span><span><strong>{person.name}</strong><small>{person.role.replaceAll('_', ' ')}</small></span><span className="ep-team-count">{person.workload}<small>records</small></span><ArrowUpRight size={18} /></button>)}{!data.team.length && <p className="ep-manager-empty">No assigned personnel in this scope.</p>}{data.team_limited && <p className="ep-muted">Showing the first 25 personnel. Open individual record assignments for more.</p>}</section> : <section className="ep-ask"><MessageSquare size={31} /><h2>Your private workspace</h2><p>Keep requests and working notes together, with the right portal context.</p><p className="ep-muted">AI generation is off. Ep cannot generate a briefing, follow-up or document yet. Your saved requests remain private.</p><a href={`/ep-agent?portal=${encodeURIComponent(portal)}`} className="ep-primary">Open private chat</a></section>}
            <footer className="ep-manager-foot"><span>Retrieved {date(data.as_of)}</span><span>Current permissions · No AI-generated totals</span></footer>
        </>}
        {detail && <QuickDetails key={`${portal}-${detail.kind}-${detail.id}`} detail={detail} portal={portal} onClose={closeDetails} onAccessLost={accessLost} />}
    </main>;
}
export default function EpManager(props) { return <><Head title="Ep Manager" /><ManagerView {...props} /></>; }
