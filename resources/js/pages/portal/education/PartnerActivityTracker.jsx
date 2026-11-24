import React, { useState, useEffect, useRef } from 'react';
import { Head, router, useForm } from '@inertiajs/react';

const BASE = '/portal/education/partner-tracker';
const label = value => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
const cls = 'mt-1 w-full rounded-lg border border-gray-300 bg-white p-2 text-sm disabled:bg-gray-100';
const F = ({ title, children }) => <label className="block text-sm font-medium">{title}{children}</label>;
const query = values => new URLSearchParams(Object.entries(values).filter(([, v]) => v !== '' && v != null)).toString();

function AttachmentUpload({ recordId }) {
    const form = useForm({ file: null });
    return <form className="mt-3 flex flex-wrap items-end gap-3" onSubmit={e => { e.preventDefault(); form.post(`${BASE}/activities/${recordId}/files`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() }); }}>
        <F title="Private attachment (up to 10 MB)"><input className={cls} type="file" accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.txt" onChange={e => form.setData('file', e.target.files[0] || null)} /></F>
        <button className="rounded-lg border px-3 py-2 text-sm" disabled={!form.data.file || form.processing}>Upload attachment</button>
        {form.errors.file && <p role="alert" className="text-sm text-red-700">{form.errors.file}</p>}
    </form>;
}

function ActivityEditor({ row, partners, staff, statuses, today, onClose }) {
    const initial = row || { source_key: `manual:${crypto.randomUUID()}`, agent_id: '', category: 'email', activity_on: today, title: '', status: 'awaiting_response', assignee_id: '', follow_up_on: '', lead_id: '', details: {}, version: '' };
    const form = useForm({ ...initial, assignee_id: initial.assignee_id || '', follow_up_on: initial.follow_up_on || '', lead_id: initial.lead_id || '', details: initial.details || {} });
    const auto = !!row?.automatic;
    const category = form.data.category;
    const editorRef = useRef(null);
    const [clientQuery, setClientQuery] = useState('');
    const [clients, setClients] = useState([]);
    useEffect(() => { editorRef.current?.scrollIntoView?.({ block: 'start' }); }, []);
    useEffect(() => {
        if (auto || category !== 'follow_up' || !form.data.agent_id) { setClients([]); return; }
        let active = true;
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`${BASE}/clients?${query({agent_id:form.data.agent_id,q:clientQuery})}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const result = await response.json();
                if (active) setClients(response.ok ? result.clients || [] : []);
            } catch { if (active) setClients([]); }
        }, 250);
        return () => { active = false; clearTimeout(timer); };
    }, [auto, category, form.data.agent_id, clientQuery]);
    const detail = (key, value) => form.setData('details', { ...form.data.details, [key]: value });
    const field = (key, title, type = 'text', disabled = false) => <F title={title}><input className={cls} type={type} disabled={disabled} value={form.data[key] ?? ''} onChange={e => form.setData(key, e.target.value)} /></F>;
    const extra = (key, title, type = 'text', disabled = false) => <F title={title}><input className={cls} type={type} disabled={disabled} value={form.data.details[key] ?? ''} onChange={e => detail(key, e.target.value)} /></F>;
    return <section ref={editorRef} className="rounded-xl border border-emerald-200 bg-white p-5" aria-label="Activity editor">
        <div className="mb-4 flex justify-between gap-3"><h2 className="text-lg font-semibold">{row ? 'Update activity' : 'Record activity'}</h2><button type="button" onClick={onClose} className="underline">Close editor</button></div>
        {auto && <p className="mb-3 rounded bg-blue-50 p-3 text-sm">This activity is linked to an existing portal record. Original dates, documents, agreement status and task completion stay controlled by their existing workflows. Here you can add tracking notes{category === 'follow_up' ? '.' : ', a responsible staff member and a follow-up date.'}</p>}
        <form className="space-y-4" onSubmit={e => { e.preventDefault(); form.post(`${BASE}/activities`, { preserveScroll: true, onSuccess: onClose }); }}>
            <div className="grid gap-4 md:grid-cols-2">
                <F title="Agent / affiliate partner"><select className={cls} required disabled={!!row} value={form.data.agent_id} onChange={e => form.setData('agent_id', e.target.value)}><option value="">Choose partner</option>{partners.map(p => <option key={p.id} value={p.id}>{p.name}{p.archived ? ' (archived)' : ''}</option>)}</select></F>
                <F title="Activity category"><select className={cls} disabled={!!row} value={category} onChange={e => { form.setData('category', e.target.value); form.setData('status', statuses[e.target.value][0]); form.setData('details', e.target.value === 'training' ? { attendance: 'not_recorded' } : {}); }}>{Object.keys(statuses).map(c => <option key={c} value={c}>{label(c)}</option>)}</select></F>
                {field('title', category === 'training' ? 'Training topic' : category === 'email' ? 'Email subject' : 'Subject / title', 'text', auto)}
                {field('activity_on', category === 'training' ? 'Training date' : category === 'email' ? 'Date sent' : 'Activity / follow-up date', 'date', auto)}
                <F title={category === 'email' ? 'Response status' : 'Activity status'}><select className={cls} disabled={auto && category !== 'email'} value={form.data.status} onChange={e => form.setData('status', e.target.value)}>{![...(statuses[category] || [])].includes(form.data.status) && <option value={form.data.status}>{label(form.data.status)}</option>}{statuses[category]?.map(s => <option key={s} value={s}>{label(s)}</option>)}</select></F>
                <F title="Assigned staff"><select className={cls} disabled={auto && category === 'follow_up'} value={form.data.assignee_id} onChange={e => form.setData('assignee_id', e.target.value)}><option value="">Unassigned</option>{form.data.assignee_id && !staff.some(u => String(u.id) === String(form.data.assignee_id)) && <option value={form.data.assignee_id}>Former / unavailable staff #{form.data.assignee_id}</option>}{staff.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select></F>
                {field('follow_up_on', 'Deadline / next follow-up date', 'date', auto && category === 'follow_up')}
                {auto && category === 'agreement' && <F title="Tracking review state"><select className={cls} value={form.data.details.review_state || ''} onChange={e => detail('review_state', e.target.value)}><option value="">No additional review state</option><option value="under_review">Under Review</option><option value="updated">Updated</option></select></F>}
                {auto && category === 'follow_up' && <>{extra('next_action', 'Next action (tracking note)')}{extra('next_follow_up_on', 'Additional next follow-up date', 'date')}</>}
                {!auto && category === 'agreement' && <>{extra('sent_on', 'Date agreement sent', 'date')}{extra('received_on', 'Date received', 'date')}{extra('expiry_on', 'Expiry date (if applicable)', 'date')}<p className="text-sm text-gray-600">Record external agreements here. Existing portal agreements are tracked automatically below; keep their signing and version history in the original workflow.</p></>}
                {!auto && category === 'training' && <>{extra('facilitator', 'Facilitator')}<F title="Attendance"><select className={cls} value={form.data.details.attendance || 'not_recorded'} onChange={e => detail('attendance', e.target.value)}><option value="not_recorded">Not recorded</option><option value="attended">Attended</option><option value="absent">Absent</option></select></F></>}
                {!auto && category === 'follow_up' && <><p className="text-sm text-gray-600">Existing Task Board follow-ups are imported automatically. Use this for additional work, without re-entering the same task.</p>{extra('client_name', 'Client name', 'text', !!form.data.lead_id)}
                    <F title="Find an existing referred client (optional)"><input className={cls} value={clientQuery} onChange={e => setClientQuery(e.target.value)} placeholder="Search client name" /></F>
                    <F title="Link existing client"><select disabled={!!row?.lead_id} className={cls} value={form.data.lead_id} onChange={e => { form.setData('lead_id', e.target.value); const client = clients.find(c => String(c.id) === e.target.value); if (client) form.setData('details', { ...form.data.details, client_name: client.name, application_status: client.status || '' }); }}><option value="">No link / prospective client</option>{form.data.lead_id && !clients.some(c => String(c.id) === String(form.data.lead_id)) && <option value={form.data.lead_id}>{form.data.details.client_name || 'Existing linked client'}</option>}{clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></F>{extra('method', 'Communication method')}{extra('application_status', 'Client application status (if known)')}{extra('outcome', 'Outcome / response')}{extra('next_action', 'Next action')}</>}
            </div>
            {!auto && category === 'email' && <p className="text-sm text-gray-600">For automatic logging, use the partner Compose entry point above. Record earlier or external emails here without duplicating an existing tracked message.</p>}
            {(!auto || category === 'email') && <F title="Purpose of communication"><textarea className={cls} maxLength={3000} value={form.data.details.purpose || ''} onChange={e => detail('purpose', e.target.value)} /></F>}
            <F title={category === 'training' ? 'Feedback / remarks' : 'Remarks'}><textarea className={cls} maxLength={5000} value={form.data.details.remarks || ''} onChange={e => detail('remarks', e.target.value)} /></F>
            {Object.keys(form.errors).length > 0 && <div role="alert" className="text-sm text-red-700">{Object.values(form.errors).map((e, i) => <p key={i}>{e}</p>)}</div>}
            <button disabled={form.processing} className="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Save activity'}</button>
        </form>
    </section>;
}

function ActivityList({ title, rows, installed, onEdit }) {
    const [page, setPage] = useState(0);
    const currentPage = Math.min(page, Math.max(0, Math.ceil(rows.length / 30) - 1));
    const shown = rows.slice(currentPage * 30, (currentPage + 1) * 30);
    return <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 text-lg font-semibold">{title} ({rows.length})</h2>
        <div className="space-y-3">{shown.map(row => <article key={row.source_key} className="rounded-lg border p-3">
            <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><h3 className="break-words font-semibold">{row.partner} · {row.title}</h3><p className="text-sm">{row.activity_on} · {label(row.category)} · {label(row.status)} {row.details?.current === false && <span> · Historical version</span>}{row.details?.review_state && <span> · Tracking: {label(row.details.review_state)}</span>}{row.details?.delivery === 'bounced' && <strong className="text-red-700"> · Email bounced</strong>}{row.overdue && <strong className="text-red-700"> · Overdue</strong>}</p><p className="text-xs text-gray-600">Staff: {row.staff || 'Unassigned'} · Follow-up: {row.follow_up_on || 'Not set'} · {row.automatic ? 'Automatic source' : 'Staff record'} · Updated: {row.updated_at?.slice(0, 10)}</p></div>
                {installed && !row.event_only && <button className="rounded border px-3 py-1 text-sm" onClick={() => onEdit(row)}>Update tracking</button>}
            </div>
            <details className="mt-2 text-sm"><summary className="cursor-pointer text-blue-800">Details and documents</summary>
                {Object.entries(row.details || {}).filter(([k, v]) => v != null && !k.endsWith('_url') && typeof v !== 'object').map(([k, v]) => <p key={k} className="mt-1 whitespace-pre-wrap break-words"><strong>{label(k)}:</strong> {String(v)}</p>)}
                {row.details?.document_url && <a className="mt-2 block text-blue-700 underline" href={row.details.document_url}>View / download original agreement</a>}
                {row.details?.task_url && <a className="mt-2 block text-blue-700 underline" href={row.details.task_url}>Open existing Task Board</a>}
                {row.files?.map(f => <a key={f.id} href={`${f.url}?inline=1`} target="_blank" rel="noreferrer" className="mt-2 block break-all text-blue-700 underline">{f.name}</a>)}
                {installed && row.record_id ? <AttachmentUpload recordId={row.record_id} /> : !row.event_only && <p className="mt-2 text-gray-600">Save tracking details before attaching files.</p>}
            </details>
        </article>)}{!rows.length && <p className="text-sm text-gray-500">No matching recorded activity.</p>}</div>
        {rows.length > 30 && <div className="mt-3 flex items-center gap-3 text-sm"><button disabled={currentPage === 0} onClick={() => setPage(currentPage - 1)}>Previous</button><span>Page {currentPage + 1} of {Math.ceil(rows.length / 30)}</span><button disabled={(currentPage + 1) * 30 >= rows.length} onClick={() => setPage(currentPage + 1)}>Next</button></div>}
    </section>;
}

export default function PartnerActivityTracker({ rows = [], agreements = [], workload = [], summary = {}, filters, partners = [], staff = [], statuses = {}, installed = false, today, timezone, errors = {} }) {
    const [draft, setDraft] = useState(filters);
    const [editing, setEditing] = useState(undefined);
    const set = (key, value) => setDraft(old => ({ ...old, [key]: value }));
    return <div className="space-y-5 p-4 md:p-6">
        <Head title="Partner Activity Tracker" />
        <div className="flex flex-wrap justify-between gap-3"><div><h1 className="text-2xl font-bold">Agent & Affiliate Partner Activity Tracker</h1><p className="mt-1 text-sm text-gray-600">Communications, agreements, training and client follow-ups · {timezone}</p></div><button disabled={!installed} onClick={() => setEditing(null)} className="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Record activity</button></div>
        {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{Object.values(errors).map((error, i) => <p key={i}>{error}</p>)}</div>}
        {!installed && <p role="alert" className="rounded-lg bg-amber-50 p-3">Tracker storage is not installed yet. Existing source activity is visible; adding records and attachments is unavailable.</p>}
        <form className="grid gap-3 rounded-xl border bg-white p-4 md:grid-cols-3" onSubmit={e => { e.preventDefault(); router.get(BASE, draft, { preserveState: false }); }}>
            <F title="Partner"><select className={cls} value={draft.agent_id} onChange={e => set('agent_id', e.target.value)}><option value="">All partners</option>{partners.map(p => <option key={p.id} value={p.id}>{p.name}{p.archived ? ' (archived)' : ''}</option>)}</select></F>
            <F title="Category"><select className={cls} value={draft.category} onChange={e => set('category', e.target.value)}><option value="">All categories</option>{Object.keys(statuses).map(c => <option key={c} value={c}>{label(c)}</option>)}</select></F>
            <F title="Staff"><select className={cls} value={draft.assignee_id} onChange={e => set('assignee_id', e.target.value)}><option value="">All staff</option>{staff.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select></F>
            <F title="Activity date from"><input className={cls} type="date" value={draft.from} onChange={e => { set('from', e.target.value); set('period', 'custom'); }} /></F>
            <F title="Activity date through"><input className={cls} type="date" value={draft.to} onChange={e => { set('to', e.target.value); set('period', 'custom'); }} /></F>
            <F title="Renewals due within (days)"><input className={cls} type="number" min="1" max="365" value={draft.renewal_days} onChange={e => set('renewal_days', e.target.value)} /></F>
            <div className="flex flex-wrap gap-3 md:col-span-3"><button className="rounded-lg bg-gray-900 px-3 py-2 text-sm text-white">Apply filters</button>{['week', 'month'].map(period => <button key={period} type="button" className="rounded-lg border px-3 py-2 text-sm" onClick={() => router.get(BASE, { ...draft, period, from: '', to: '' }, { preserveState: false })}>This {period}</button>)}<a className="rounded-lg border px-3 py-2 text-sm" href={`${BASE}/export?${query({ ...filters, format: 'xlsx' })}`}>Export Excel</a><a className="rounded-lg border px-3 py-2 text-sm" href={`${BASE}/export?${query({ ...filters, format: 'pdf' })}`}>Export PDF</a></div>
        </form>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Object.entries(summary).map(([name, value]) => <div key={name} className="rounded-xl border bg-white p-4"><p className="text-sm text-gray-600">{label(name)}</p><p className="text-2xl font-bold">{value}</p></div>)}</div>
        <p className="text-sm text-gray-600">Completed follow-ups and recorded trained agents use {filters.from}–{filters.to}. Pending/overdue workload and agreement cards use all dates for the selected partner/category/staff. Training requires Completed + Attended. Renewals: next {filters.renewal_days} days. Current agreement cards use the latest issued portal version, retaining drafts and history below.</p>
        <div className="rounded-xl border bg-white p-4"><F title="Email a partner with automatic activity logging"><select className={cls} defaultValue="" onChange={e => { if (e.target.value) router.get(`/portal/education/compose?partner_id=${e.target.value}`); }}><option value="">Choose a partner to open Compose</option>{partners.filter(p => !p.archived).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></F><p className="mt-2 text-xs text-gray-500">Only confirmed Sent messages with an explicit partner ID are listed automatically. Earlier unlinked typed-address emails and uncertain replies are not guessed.</p></div>
        {editing !== undefined && <ActivityEditor key={editing?.source_key || 'new'} row={editing} partners={partners} staff={staff} statuses={statuses} today={today} onClose={() => setEditing(undefined)} />}
        <ActivityList key={`period-${query(filters)}`} title="Dated activity history" rows={rows} installed={installed} onEdit={setEditing} />
        <ActivityList key={`agreements-${query(filters)}`} title="Agreement tracking — current state and versions" rows={agreements} installed={installed} onEdit={setEditing} />
        <ActivityList key={`workload-${query(filters)}`} title="Overdue workload — all dates" rows={workload} installed={installed} onEdit={setEditing} />
    </div>;
}
