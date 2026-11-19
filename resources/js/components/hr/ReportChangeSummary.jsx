const fields = {
    work_location: 'Work location', tasks: 'Tasks', meetings: 'Meetings',
    people_assisted: 'People assisted', blockers: 'Blockers or support needed',
    tomorrow: 'Plans for tomorrow', remarks: 'Additional notes',
};

function normalized(field, value) {
    if (field === 'tasks') return (value || []).map(t => ({
        task: t.task || '', start: t.start || '', end: t.end || '',
        category: t.category || 'other', reference: t.reference || '', status: t.status || 'todo',
    }));
    if (['meetings', 'people_assisted'].includes(field)) return Number(value || 0);
    return value || '';
}

export function reportChanges(before, after) {
    return Object.fromEntries(Object.keys(fields).flatMap(field => {
        const oldValue = normalized(field, before?.[field]);
        const newValue = normalized(field, after?.[field]);
        return JSON.stringify(oldValue) === JSON.stringify(newValue) ? [] : [[field, { before: oldValue, after: newValue }]];
    }));
}

function Value({ value }) {
    if (Array.isArray(value)) return value.length ? <ul>{value.map((task, i) => <li key={i}>
        <strong>{task.task || task.description || 'Untitled task'}</strong>
        <span>{task.start || '—'} – {task.end || '—'} · {(task.status || '').replaceAll('_', ' ')}</span>
        {(task.category || task.reference) && <span>{task.category} {task.reference}</span>}
    </li>)}</ul> : <span>No tasks</span>;
    return <span>{value === null || value === undefined || value === '' ? 'Not recorded' : String(value)}</span>;
}

export default function ReportChangeSummary({ changes = {} }) {
    const visible = Object.entries(changes).filter(([field]) => fields[field]);
    return visible.length ? <div className="hr-report-diff">{visible.map(([field, change]) => <section key={field}>
        <h3>{fields[field]}</h3>
        <div><small>Before</small><Value value={change.before} /></div>
        <div><small>After</small><Value value={change.after} /></div>
    </section>)}</div> : <p className="hr-muted">No report-content changes to submit.</p>;
}
