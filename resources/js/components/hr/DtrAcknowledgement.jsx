import { useForm } from '@inertiajs/react';
import { hrPath } from '@/lib/hrPath';
import ReportChangeSummary from './ReportChangeSummary';
import { Errors } from '@/pages/hr/Dtr';
const labels = { pending: 'Pending acknowledgement', acknowledged: 'Acknowledged', clarification: 'Clarification requested', superseded: 'Superseded by a newer version' };
const dateTime = value => value ? new Date(value).toLocaleString() : '';
export default function DtrAcknowledgement({ item, onSaved }) {
    const form = useForm({ version: item.version, decision: 'acknowledged', comment: '' });
    return <article className="hr-panel hr-acknowledgement">
        <div className="hr-section-bar"><h3>{item.employee_name ? `${item.employee_name} · ` : ''}{item.work_date || `Report #${item.dtr_entry_id}`}</h3><span className="hr-status">{labels[item.status] || item.status}</span></div>
        <p>Version {item.report_version} · Submitted {dateTime(item.submitted_at)} to {item.head_name || 'Department Head'}</p>
        <p><strong>Reason:</strong> {item.reason}</p>
        <details><summary>View submitted changes</summary><ReportChangeSummary changes={item.changes} /></details>
        {item.comment && <p><strong>Department Head response:</strong> {item.comment}</p>}
        {item.decided_at && <p>{dateTime(item.decided_at)}</p>}
        {item.status === 'pending' && item.can_decide && <form className="hr-form" onSubmit={e => {
            e.preventDefault();
            form.post(hrPath(`/dtr/acknowledgements/${item.id}/decision`), { preserveScroll: true, onSuccess: onSaved });
        }}>
            <label>Response<select value={form.data.decision} onChange={e => form.setData('decision', e.target.value)}><option value="acknowledged">Acknowledge changes</option><option value="clarification">Request clarification</option></select></label>
            <label>Comment<textarea value={form.data.comment} required={form.data.decision === 'clarification'} maxLength={5000} onChange={e => form.setData('comment', e.target.value)} /></label>
            <Errors errors={form.errors} />
            <button className="hr-button" disabled={form.processing}>{form.processing ? 'Recording…' : 'Record response'}</button>
            <small>This records your response. It does not revert saved report edits.</small>
        </form>}
    </article>;
}

