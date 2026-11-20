import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { hrPath } from '@/lib/hrPath';
import DtrHistory from '@/components/hr/DtrHistory';
import ReportChangeSummary, { reportChanges } from '@/components/hr/ReportChangeSummary';
import { Editor, Errors } from './Dtr';
import DtrCalendar from '@/components/hr/DtrCalendar';
import DtrAcknowledgement from '@/components/hr/DtrAcknowledgement';


function SubmitChanges({ entry, departmentHead, draft, dirty, saving, acknowledgements }) {
    const form = useForm({ version: entry.hr_version, reason: '' });
    const changes = reportChanges(entry.report_baseline || entry, draft);
    const pending = acknowledgements.some(a => a.dtr_entry_id === entry.id && a.status === 'pending');
    return <aside className="hr-change-sidebar hr-panel" aria-label="Report change summary">
        <h2>Summary of changes</h2>
        <p>{dirty ? 'Unsaved edits are shown below. Save them to update your report immediately.' : 'Saved report changes take effect immediately. Your Department Head acknowledges them separately.'}</p>
        <ReportChangeSummary changes={changes} />
        <form className="hr-form" onSubmit={e => {
            e.preventDefault();
            form.post(hrPath(`/dtr/${entry.id}/acknowledgements`), { preserveScroll: true });
        }}>
            <p><strong>Department Head</strong><br />{departmentHead?.name || 'Not assigned — ask HR to assign an active Department Head.'}</p>
            <label>Reason for changes<textarea disabled={dirty || saving || form.processing} value={form.data.reason} required maxLength={5000} onChange={e => form.setData('reason', e.target.value)} /></label>
            <Errors errors={form.errors} />
            {dirty && <p className="hr-notice">Save changes before submitting for acknowledgement.</p>}
            {pending && <p role="status">This saved version is awaiting acknowledgement.</p>}
            <button className="hr-button" disabled={dirty || saving || form.processing || !departmentHead || !Object.keys(changes).length || pending}>
                {form.processing ? 'Submitting…' : 'Submit to Department Head'}
            </button>
            <small>Clock-in/out and break corrections keep their separate reviewed process.</small>
        </form>
    </aside>;
}

export default function DtrChanges({ mode, records = { data: [] }, entries = [], departmentHead, acknowledgements = [], filters, oldestPending }) {
    const { hr } = usePage().props;
    const mine = mode === 'mine';
    const [selected, select] = useState(entries[0]?.id);
    const entry = entries.find(e => e.id === selected) || entries[0];
    const history = acknowledgements.filter(a => a.dtr_entry_id === entry?.id);
    return <>
        <Head title="DTR changes · Luvep" />
        {mine ? <>
            <section className="hr-dtr-report">
                {entry ? <>
                    <Editor heading={<div className="hr-section-bar"><h2>Work report</h2><label className="hr-select-label">Work date<select value={entry?.id || ''} onChange={e => select(Number(e.target.value))}>{entries.map(e => <option key={e.id} value={e.id}>{e.work_date}</option>)}</select></label></div>} key={`${entry.id}:${entry.hr_version}`} entry={entry} locked={!!hr.preview} reportChanges sidebar={(draft, dirty, saving) => <SubmitChanges key={entry.hr_version} entry={entry} departmentHead={departmentHead} draft={draft} dirty={dirty} saving={saving} acknowledgements={acknowledgements} />} />
                    {!hr.preview && <DtrHistory key={`${entry.id}:${entry.hr_version}:history`} entry={entry} />}
                </> : <p>No time records yet. <Link className="hr-text-link" href={hrPath('/dtr')}>Open my time records</Link> to begin.</p>}
            </section>
        </> : null}
        {mine && <section aria-label="Acknowledgement history"><h2 className="hr-subheading">Your submission history</h2>
            {history.length ? history.map(item => <DtrAcknowledgement key={`${item.id}:${item.version}:${item.status}`} item={item} />) : <div className="hr-panel"><p>No report changes have been submitted for acknowledgement.</p></div>}
        </section>}
        {!mine && <DtrCalendar records={records.data} filters={filters} path="/dtr/changes" title="DTR reports & acknowledgements" oldestPending={oldestPending} />}
    </>;
}
