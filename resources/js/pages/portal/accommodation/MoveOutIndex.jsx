import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const root = '/portal/accommodation/move-out';
const field = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm';
const button = 'inline-flex items-center rounded-lg bg-[#1C5685] px-4 py-2 text-sm font-semibold text-white';

function RecordState({ row, stages }) {
    return <><span>{stages[row.stage]}</span>{row.closed && <span className="ml-2 rounded-full bg-gray-100 px-2 py-1 text-xs">Closed</span>}</>;
}

export default function MoveOutIndex({ records, filters, stages, owners, automaticEmail, canManageAutomation }) {
    const [savingAutomation, setSavingAutomation] = useState(false);
    const [search, setSearch] = useState(filters.search || '');
    const [savingOwner, setSavingOwner] = useState(null);
    const [assignmentError, setAssignmentError] = useState('');
    const view = filters.view || 'table';
    const apply = (changes = {}) => router.get(root, { ...filters, search: search.trim() || undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const ownerName = (id) => owners.find((owner) => owner.id === id && id)?.name || (id ? 'Former assignee' : 'Unassigned');
    const assign = (row, value) => {
        setAssignmentError('');
        setSavingOwner(row.id);
        router.patch(root + '/' + row.id + '/assignee', { assignee_id: value || null }, {
            preserveScroll: true,
            onError: (errors) => setAssignmentError(errors.assignee_id || 'Assignment could not be saved.'),
            onFinish: () => setSavingOwner(null),
        });
    };
    const recordLink = (row) => <Link className={button} href={root + '/' + row.id} aria-label={'View move-out record for ' + row.tenant}>View</Link>;

    return <>
        <Head title="Move-Out Pipeline" />
        <div className="mx-auto max-w-screen-2xl space-y-5 p-4 sm:p-6">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-bold text-gray-900">Move-Out Pipeline</h1>
                <Link className={button} href={root + '/create'}>Start record</Link>
            </header>
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                <p><strong>Scheduled emails {automaticEmail ? 'on' : 'off'}</strong><br />
                    Eligible check-ins and reminders send at 9:00 a.m. New Zealand time on their due date. Staff can send manually.</p>
                {canManageAutomation && <button type="button" className={field} disabled={savingAutomation}
                    onClick={() => router.patch(root + '/automation', { enabled: !automaticEmail }, {
                        preserveScroll: true, onStart: () => setSavingAutomation(true), onFinish: () => setSavingAutomation(false),
                    })}>{automaticEmail ? 'Pause scheduled emails' : 'Enable scheduled emails'}</button>}
            </div>
            <form className="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-4" onSubmit={(event) => { event.preventDefault(); apply(); }}>
                <input type="search" aria-label="Search name, email or mobile" placeholder="Search name, email or mobile" maxLength={150}
                    value={search} onChange={(event) => setSearch(event.target.value)} className={field + ' min-w-56 flex-1'} />
                <button type="submit" className={field}>Search</button>
                <select className={field} aria-label="Stage" value={filters.stage || ''} onChange={(event) => apply({ stage: event.target.value || undefined })}>
                    <option value="">All stages</option>
                    {Object.entries(stages).map(([key, name]) => <option key={key} value={key}>{name}</option>)}
                </select>
                <select className={field} aria-label="Assignee" value={filters.assignee === 'mine' ? '' : filters.assignee || ''} onChange={(event) => apply({ assignee: event.target.value || undefined })}>
                    <option value="">All assignees</option>
                    {owners.map(({ name }) => <option key={name}>{name}</option>)}
                    <option value="unassigned">Unassigned</option>
                </select>
                <button type="button" className={field} aria-pressed={filters.assignee === 'mine'}
                    onClick={() => apply({ assignee: filters.assignee === 'mine' ? undefined : 'mine' })}>Assigned to me</button>
                <div className="flex gap-1" aria-label="View mode">
                    {['table', 'pipeline'].map((mode) => <button className={view === mode ? button : field} key={mode} type="button" aria-pressed={view === mode} onClick={() => apply({ view: mode })}>
                        {mode === 'table' ? 'Table' : 'Pipeline'}
                    </button>)}
                </div>
            </form>
            {assignmentError && <p role="alert" className="text-sm text-red-700">{assignmentError}</p>}
            {records.data.length === 0 ? <p className="rounded-xl border bg-white p-5 text-gray-600">No matching move-out records.</p>
                : view === 'table' ? <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-gray-50 text-gray-700"><tr>{['Tenant', 'Property', 'Agreement ends', 'Move-out date', 'Assigned to', 'Stage', 'Actions'].map((name) => <th scope="col" key={name} className="px-4 py-3">{name}</th>)}</tr></thead>
                        <tbody className="divide-y divide-gray-100">{records.data.map((row) => <tr key={row.id}>
                            <th scope="row" className="px-4 py-4 font-semibold">{row.tenant}</th>
                            <td className="px-4 py-4">{row.property}</td><td className="px-4 py-4 whitespace-nowrap">{row.ends}</td>
                            <td className="px-4 py-4 whitespace-nowrap">{row.moveOut || '—'}</td>
                            <td className="px-4 py-4">
                                {row.closed ? ownerName(row.assigneeId) : <select className={field} aria-label={'Assign ' + row.tenant}
                                    value={row.assigneeId ?? ''} disabled={savingOwner !== null} onChange={(event) => assign(row, event.target.value)}>
                                    <option value="">Unassigned</option>
                                    {row.assigneeId && !owners.some(({ id }) => id === row.assigneeId) && <option value={row.assigneeId} disabled>Former assignee</option>}
                                    {owners.map(({ name, id }) => <option key={name} value={id ?? name} disabled={!id}>{name}</option>)}
                                </select>}
                            </td>
                            <td className="px-4 py-4"><RecordState row={row} stages={stages} /></td>
                            <td className="px-4 py-4">{recordLink(row)}</td>
                        </tr>)}</tbody>
                    </table>
                </div> : <div className="flex gap-3 overflow-x-auto pb-3">
                    {Object.entries(stages).filter(([stage]) => !filters.stage || stage === filters.stage).map(([stage, name]) => {
                        const rows = records.data.filter((row) => row.stage === stage);
                        return <section key={stage} className="w-64 shrink-0 space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                            <h2 className="text-sm font-semibold text-gray-900">{name}</h2>
                            {rows.length === 0 && <p className="text-sm text-gray-600">No records on this page.</p>}
                            {rows.map((row) => <article key={row.id} className="space-y-2 rounded-lg border bg-white p-3 text-sm">
                                <h3 className="font-semibold">{row.tenant}{row.closed && ' · Closed'}</h3>
                                <p>{row.property}</p><p>Agreement ends {row.ends}</p>
                                {row.moveOut && <p>Moves out {row.moveOut}</p>}
                                <p>{ownerName(row.assigneeId)}</p>{recordLink(row)}
                            </article>)}
                        </section>;
                    })}
                </div>}
            {records.last_page > 1 && <nav aria-label="Move-out pages" className="flex items-center gap-4 text-sm">
                {records.prev_page_url && <Link href={records.prev_page_url}>Previous</Link>}
                <span>Page {records.current_page} of {records.last_page}</span>
                {records.next_page_url && <Link href={records.next_page_url}>Next</Link>}
            </nav>}
        </div>
    </>;
}
