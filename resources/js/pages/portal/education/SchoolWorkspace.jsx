import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';

const tabs = { enrolment: 'Student Enrolments', communication: 'Communications', course: 'Courses & Fees', intake: 'Intakes' };
const labelClass = 'block text-xs font-semibold text-gray-600';
const inputClass = 'mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm';
const name = student => [student?.first_name, student?.middle_name, student?.last_name, student?.suffix].filter(Boolean).join(' ') || 'Unavailable student';
export default function SchoolWorkspace({ school, records = [], programs = [], students = [], staff = [], statuses = {}, today, initialSection = 'enrolment' }) {
    const [tab, setTab] = useState(initialSection);
    const [editing, setEditing] = useState(null);
    const [programFilter, setProgramFilter] = useState('');
    const [periodFilter, setPeriodFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState('');
    const base = `/portal/education/schools/${school.id}`;
    const programme = id => programs.find(p => p.id === Number(id));
    const enrolments = records.filter(r => r.kind === 'enrolment');
    const intakes = records.filter(r => r.kind === 'intake');
    const communications = records.filter(r => r.kind === 'communication');
    const offered = programs.filter(p => p.status === 'published' && !records.some(r => r.kind === 'course' && r.program_id === p.id && r.data.status !== 'Active'));
    const activeIntakes = intakes.filter(r => offered.some(p => p.id === r.program_id) && ['Open', 'Closing Soon'].includes(r.data.status) && r.data.starts_on >= today);
    const pending = communications.filter(r => r.data.status !== 'Completed');
    const uniqueStudents = values => new Set(values.map(r => r.lead_id)).size;
    const stats = [
        ['Enrolled students', uniqueStudents(enrolments.filter(r => ['Enrolled', 'Currently Studying'].includes(r.data.status)))],
        ['Currently studying', uniqueStudents(enrolments.filter(r => r.data.status === 'Currently Studying'))],
        ['Completed students', uniqueStudents(enrolments.filter(r => r.data.status === 'Completed'))],
        ['Upcoming intakes', activeIntakes.length],
        ['Courses offered', offered.length],
        ['Pending communications', pending.length],
    ];
    const upcomingCompletions = enrolments.filter(r => ['Enrolled', 'Currently Studying'].includes(r.data.status) && r.data.ends_on >= today)
        .sort((a, b) => a.data.ends_on.localeCompare(b.data.ends_on));
    const changeTab = key => { setTab(key); setEditing(null); setErrors({}); setFailure(''); setProgramFilter(''); setPeriodFilter(''); setStatusFilter(''); };
    const begin = (record = null) => {
        setErrors({}); setFailure('');
        setEditing(record ? { ...record.data, id: record.id, version: record.version, program_id: record.program_id || '', lead_id: record.lead_id || '' }
            : { program_id: '', lead_id: '', status: statuses[tab]?.[0] || 'Active', type: 'Email', communicated_on: today, staff_id: '', currency: '', starts_on: '', ends_on: '', opens_on: '', closes_on: '' });
    };
    const set = (field, value) => setEditing(previous => ({ ...previous, [field]: value }));
    const save = event => {
        event.preventDefault();
        const payload = { ...editing };
        delete payload.id;
        if (tab === 'communication') delete payload.program_id;
        if (tab !== 'enrolment') delete payload.lead_id;
        setBusy(true); setErrors({}); setFailure('');
        router.post(`${base}/management/${tab}${editing.id ? `/${editing.id}` : ''}`, payload, {
            forceFormData: true, preserveScroll: true,
            onSuccess: page => { if (page.props?.flash?.error) setFailure(page.props.flash.error); else setEditing(null); },
            onError: values => setErrors(values),
            onFinish: () => setBusy(false),
        });
    };
    const input = (key, label, type = 'text', required = false) => <label className={labelClass} key={key}>{label}
        <input aria-label={label} className={inputClass} type={type} value={editing[key] ?? ''} required={required} onChange={e => set(key, e.target.value)}
            {...(type === 'number' ? { min: 0, step: '0.01' } : {})} />
    </label>;
    const select = (key, label, options, required = true) => <label className={labelClass} key={key}>{label}
        <select aria-label={label} className={inputClass} value={editing[key] ?? ''} onChange={e => set(key, e.target.value)} required={required}>
            <option value="">Select…</option>{options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
        </select>
    </label>;
    const area = (key, label, required = false) => <label key={key} className={`${labelClass} md:col-span-2`}>{label}
        <textarea aria-label={label} className={inputClass} value={editing[key] ?? ''} onChange={e => set(key, e.target.value)} required={required} />
    </label>;
    const files = record => record?.files?.map(file => <a className="block text-sm text-blue-700 underline" key={file.id}
        href={`${base}/management-records/${record.id}/files/${file.id}`}>{file.name}</a>);
    const matches = record => (!programFilter || String(record.program_id) === programFilter)
        && (!statusFilter || record.data.status === statusFilter)
        && (!periodFilter || (record.data.intake || record.data.name || '').toLowerCase().includes(periodFilter.toLowerCase()));
    const filtered = records.filter(r => r.kind === tab && matches(r)).sort((a, b) => tab === 'communication'
        ? (b.data.communicated_on || '').localeCompare(a.data.communicated_on || '') || b.id - a.id
        : tab === 'intake' ? a.data.starts_on.localeCompare(b.data.starts_on) : a.id - b.id);
    const errorList = [...Object.entries(errors).map(([key, value]) => `${key}: ${value}`), ...(failure ? [failure] : [])];
    return <main className="mx-auto max-w-6xl space-y-5 p-5">
        <Head title={`${school.name} — School dashboard`} />
        <Link className="text-blue-700 underline" href={`${base}/profile#school-information`}>School information</Link>{' · '}
        <Link className="text-blue-700 underline" href="/portal/education/schools">All schools</Link>
        <h1 className="text-2xl font-bold">{school.name}</h1>
        <p className="text-sm text-gray-600">Confirmed school records. Planned programmes and month-only catalogue intakes are not automatically treated as enrolments or exact dates.</p>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3">{stats.map(([label, count]) => <div key={label} className="rounded-xl border bg-white p-4"><div className="text-2xl font-bold">{count}</div><div>{label}</div></div>)}</div>
        <div className="grid gap-4 md:grid-cols-3">
            <Summary title="Upcoming course completions" rows={upcomingCompletions.map(r => `${name(students.find(s => s.id === r.lead_id))} · ${r.data.ends_on}`)} />
            <Summary title="Upcoming intakes / deadlines" rows={activeIntakes.sort((a, b) => a.data.starts_on.localeCompare(b.data.starts_on)).map(r => `${programme(r.program_id)?.title || 'Unavailable programme'} · ${r.data.name} · Starts ${r.data.starts_on}${r.data.closes_on ? ` · Apply by ${r.data.closes_on}${r.data.closes_on < today ? ' (deadline passed)' : ''}` : ''}`)} />
            <Summary title="Pending follow-ups" rows={pending.filter(r => r.data.follow_up || r.data.follow_up_on).sort((a, b) => (a.data.follow_up_on || '9999').localeCompare(b.data.follow_up_on || '9999'))
                .map(r => `${r.data.subject} · ${r.data.follow_up_on || 'Date not specified'}${r.data.follow_up_on && r.data.follow_up_on < today ? ' (overdue)' : ''} · ${r.data.follow_up || ''}`)} />
        </div>
        <nav className="flex flex-wrap gap-2" aria-label="School sections">{Object.entries(tabs).map(([key, label]) => <button key={key} aria-pressed={key === tab} onClick={() => changeTab(key)} className={`rounded-lg border p-3 ${tab === key ? 'bg-emerald-800 text-white' : 'bg-white'}`}>{label}</button>)}</nav>
        {tab === 'course' && <p className="text-sm">Course names, levels, durations, tuition, entry requirements and catalogue status use existing <Link className="text-blue-700 underline" href="/portal/education/programs">Programs management</Link>. Add/edit courses there; use Archive rather than Delete to retain history. School-specific application fees, status and private brochures are below.</p>}
        {tab === 'enrolment' && <><p className="text-sm">Add verified enrolments using existing student profiles. These statuses are school enrolment statuses; saving does not change a client’s Education stage or visa outcome. Multiple programmes count once per student in each summary.</p>
            <details className="rounded-lg border p-3"><summary>Existing school associations to review (not confirmed enrolments)</summary>
                {students.filter(s => s.school_id === school.id && !enrolments.some(r => r.lead_id === s.id)).map(s => <Link key={s.id} className="mt-2 block text-blue-700 underline" href={`/portal/education/leads/${s.id}`}>{name(s)}</Link>)}
                <p className="text-sm text-gray-600">Confirm programme, dates and enrolment status before adding these students to the totals. Other programme-school associations can be reviewed in their student profiles.</p>
            </details></>}
        <div className="flex flex-wrap gap-3">
            {tab !== 'communication' && <select aria-label="Filter programme" className={inputClass} value={programFilter} onChange={e => setProgramFilter(e.target.value)}><option value="">All programmes</option>{programs.map(p => <option key={p.id} value={p.id}>{p.title}</option>)}</select>}
            {['intake', 'enrolment'].includes(tab) && <input aria-label="Filter intake" placeholder="Filter intake period" className={inputClass} value={periodFilter} onChange={e => setPeriodFilter(e.target.value)} />}
            {statuses[tab] && <select aria-label="Filter status" className={inputClass} value={statusFilter} onChange={e => setStatusFilter(e.target.value)}><option value="">All statuses</option>{statuses[tab].map(status => <option key={status}>{status}</option>)}</select>}
            <button className="rounded-lg bg-gray-900 p-3 text-white" onClick={() => begin()}>Add {tab === 'course' ? 'course details' : tab}</button>
        </div>
        {editing && <form onSubmit={save} className="space-y-4 rounded-xl border bg-white p-5">
            <h2 className="font-bold">{editing.id ? 'Edit' : 'Add'} {tabs[tab]}</h2>
            <div className="grid gap-4 md:grid-cols-2">
                {tab !== 'communication' && select('program_id', 'Programme', programs.map(p => [p.id, p.title]))}
                {tab === 'enrolment' && <>{select('lead_id', 'Student', students.map(s => [s.id, `${name(s)} · ${s.lead_id || s.id}`]))}{input('intake', 'Intake / period', 'text', true)}{input('starts_on', 'Course start date', 'date')}{input('ends_on', 'Expected completion date', 'date')}</>}
                {tab === 'communication' && <>{input('communicated_on', 'Communication date', 'date', true)}{input('subject', 'Subject', 'text', true)}{select('type', 'Communication type', ['Email', 'Meeting', 'Phone Call', 'Other'].map(v => [v, v]))}{input('contact', 'School contact')}{select('staff_id', 'Responsible staff', staff.map(s => [s.id, s.name]))}{area('summary', 'Summary / details', true)}{area('follow_up', 'Follow-up action')}{input('follow_up_on', 'Follow-up date', 'date')}</>}
                {tab === 'intake' && <>{input('name', 'Intake name / period', 'text', true)}{input('opens_on', 'Applications open', 'date')}{input('closes_on', 'Applications close', 'date')}{input('starts_on', 'Course commencement', 'date', true)}{input('ends_on', 'Course end', 'date')}{input('campus', 'Campus / location')}</>}
                {tab === 'course' && <>{input('application_fee', 'Application fee', 'number')}{input('currency', 'Fee currency (three-letter code)', 'text', true)}{area('other_fees', 'Other applicable fees / notes')}{select('status', 'School course status', ['Active', 'Inactive', 'Archived'].map(v => [v, v]))}</>}
                {statuses[tab] && select('status', 'Status', statuses[tab].map(v => [v, v]))}
                {['communication', 'course'].includes(tab) && <label className={labelClass}>Attachments (up to 5 per save, 10 MB each; previous attachments retained)
                    <input aria-label="Attachments" type="file" multiple className={inputClass} onChange={e => set('attachments', Array.from(e.target.files))} />
                </label>}
            </div>
            {errorList.length > 0 && <div role="alert" className="text-red-700">{errorList.map((error, i) => <p key={i}>{error}</p>)}</div>}
            <button className="rounded-lg bg-emerald-800 p-3 text-white disabled:opacity-50" disabled={busy}>{busy ? 'Saving…' : 'Save record'}</button>{' '}
            <button type="button" disabled={busy} onClick={() => setEditing(null)}>Cancel</button>
        </form>}
        <section aria-label={tabs[tab]} className="space-y-3">
            {tab === 'course' ? programs.filter(p => !programFilter || String(p.id) === programFilter).map(p => {
                const record = records.find(r => r.kind === 'course' && r.program_id === p.id);
                return <article className="space-y-2 rounded-xl border bg-white p-4" key={p.id}>
                    <h2 className="font-bold">{p.title} · Level {p.level} · {record?.data.status || (p.status === 'archived' ? 'Archived' : 'No school status recorded')}</h2>
                    <p>Duration: {p.duration_months == null ? 'Not specified' : `${p.duration_months} months`} · Campus: {p.location || 'Not specified'} · Catalogue: {p.status}</p>
                    <p>Tuition: {p.tuition_fee ?? p.price_text ?? 'Not specified'} {p.tuition_fee_notes || ''} · Application fee: {record?.data.application_fee ?? 'Not specified'} {record?.data.currency || ''}</p>
                    <p>Other fees: {record?.data.other_fees || 'Not specified'}</p>
                    {Array.isArray(p.tuition_fees) && p.tuition_fees.map((fee, i) => <p key={i}>{fee.label}: {fee.amount ?? 'Not specified'} {fee.notes || ''}</p>)}
                    <p>Catalogue insurance: {p.insurance_fee ?? 'Not specified'} · Visa processing: {p.visa_processing_fee ?? 'Not specified'} (amounts as recorded; currency is not inferred)</p>
                    <p>English: {p.english_requirements || 'Not specified'}</p>
                    <div>Entry requirements: {Array.isArray(p.entry_requirements) ? p.entry_requirements.map((s, i) => <div key={i}>{s.intro}<ul>{s.bullets?.map((b, j) => <li key={j}>{b}</li>)}</ul></div>) : 'Not specified'}</div>
                    <p>Catalogue intakes: {p.intake_months || 'Not specified'} (exact dates are in Intakes)</p>
                    {files(record)}<button className="text-blue-700 underline" onClick={() => { begin(record); if (!record) setEditing(previous => ({ ...previous, program_id: p.id })); }}>Edit school course details</button>
                </article>;
            }) : filtered.map(record => <article key={record.id} className="space-y-2 rounded-xl border bg-white p-4">
                {tab === 'enrolment' && <><Link className="font-bold text-blue-700 underline" href={`/portal/education/leads/${record.lead_id}`}>{name(students.find(s => s.id === record.lead_id))}</Link><p>{programme(record.program_id)?.title || 'Unavailable programme'} · {record.data.intake}</p><p>Start: {record.data.starts_on || 'Not specified'} · Completion: {record.data.ends_on || 'Not specified'}</p></>}
                {tab === 'communication' && <><h2 className="font-bold">{record.data.communicated_on} · {record.data.subject}</h2><p>{record.data.type} · {record.data.contact || 'Contact not specified'} · {staff.find(s => s.id === Number(record.data.staff_id))?.name || 'Former/unavailable staff'}</p><p className="whitespace-pre-wrap">{record.data.summary}</p><p>Follow-up: {record.data.follow_up || 'Not specified'} · {record.data.follow_up_on || 'Date not specified'}</p></>}
                {tab === 'intake' && <><h2 className="font-bold">{programme(record.program_id)?.title || 'Unavailable programme'} · {record.data.name}</h2><p>Applications: {record.data.opens_on || 'Not specified'} → {record.data.closes_on || 'Not specified'}</p><p>Course: {record.data.starts_on} → {record.data.ends_on || 'Not specified'} · {record.data.campus || 'Campus not specified'}</p></>}
                <p>{record.data.status}</p>{files(record)}<button className="text-blue-700 underline" onClick={() => begin(record)}>Edit record</button>
            </article>)}
            {tab !== 'course' && !filtered.length && <p>No matching records. Existing study plans have not been converted into confirmed enrolments.</p>}
        </section>
    </main>;
}
function Summary({ title, rows }) {
    return <section className="rounded-xl border bg-white p-4"><h2 className="font-bold">{title}</h2>{rows.length ? <ul>{rows.map((row, i) => <li className="mt-2 text-sm" key={i}>{row}</li>)}</ul> : <p className="mt-2 text-sm text-gray-500">No recorded items.</p>}</section>;
}
