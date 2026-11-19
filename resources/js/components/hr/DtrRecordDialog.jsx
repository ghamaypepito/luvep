import { useEffect, useId, useRef, useState } from 'react';
import { hrPath } from '@/lib/hrPath';
import { RecordDetails, duration } from '@/pages/hr/Dtr';
import DtrHistory from './DtrHistory';
import DtrReviewForm from './DtrReviewForm';
import DtrAcknowledgement from './DtrAcknowledgement';

export default function DtrRecordDialog({ record, weekly = false, onClose }) {
    const dialog = useRef(null), title = useId();
    const [details, setDetails] = useState(null), [error, setError] = useState('');
    const [retry, setRetry] = useState(0), [ackPage, setAckPage] = useState(1);
    useEffect(() => {
        const element = dialog.current, trigger = document.activeElement;
        const previous = document.body.style.overflow;
        element.showModal();
        document.body.style.overflow = 'hidden';
        return () => { element.close(); document.body.style.overflow = previous; if (trigger?.isConnected) trigger.focus(); };
    }, []);
    useEffect(() => {
        if (weekly || !record.can_view_details) return;
        const controller = new AbortController();
        setDetails(null); setError('');
        fetch(hrPath(`/dtr/${record.id}/history?details=1&ack_page=${ackPage}`), {
            credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal,
        }).then(async response => {
            if (!response.ok) throw new Error('Unable to open this DTR. Your access or the record may have changed.');
            const result = await response.json();
            if (!result.entry || !result.acknowledgements) throw new Error('Unable to load DTR details. Please try again.');
            if (!controller.signal.aborted) setDetails(result);
        }).catch(error => { if (!controller.signal.aborted) setError(error.message); });
        return () => controller.abort();
    }, [record.id, record.hr_version, record.can_view_details, weekly, ackPage, retry]);
    const refreshed = () => setRetry(value => value + 1);
    return <dialog ref={dialog} className="hr-record-dialog" aria-labelledby={title} onCancel={event => { event.preventDefault(); onClose(); }} onClose={onClose}>
        <header className="hr-section-bar"><div><p className="hr-eyebrow">{weekly ? 'Weekly declaration' : 'Daily time record'}</p><h2 id={title}>{record.employee_name} · {weekly ? record.week_start.slice(0, 10) : record.work_date}</h2></div><button type="button" className="hr-button-secondary" onClick={onClose}>Close details</button></header>
        {weekly ? <DtrReviewForm record={record} weekly /> : !record.can_view_details ? <section>
            <p>{record.hr_status} · {record.time_source}</p><p>Working time: {duration(record.working_seconds)} · Breaks: {duration(record.break_seconds)}</p>
            <p>Your report access includes attendance totals. Work-report notes and change history require separate DTR access.</p>
        </section> : error ? <div role="alert"><p>{error}</p><button type="button" className="hr-button-secondary" onClick={refreshed}>Retry details</button></div> : !details ? <p role="status">Loading DTR details…</p> : <>
            <p className="hr-status">{details.entry.hr_status} · {details.entry.time_source} · version {details.entry.hr_version}</p>
            <RecordDetails entry={details.entry} />
            {details.entry.review_comment && <p><strong>Review comment:</strong> {details.entry.review_comment}</p>}
            {details.entry.can_print && <a className="hr-button-secondary" href={hrPath(`/dtr/report?employee=${details.entry.employee_id}&date=${details.entry.work_date}`)} target="_blank" rel="noreferrer">Open printable record</a>}
            <DtrHistory entry={details.entry} />
            {details.entry.can_review && <DtrReviewForm key={`${details.entry.id}:${details.entry.hr_version}`} record={details.entry} onSaved={refreshed} />}
            <section aria-label="Acknowledgement history"><h3>Department Head acknowledgements</h3>
                {details.acknowledgements.data.length ? details.acknowledgements.data.map(item => <DtrAcknowledgement key={`${item.id}:${item.version}:${item.status}`} item={item} onSaved={refreshed} />) : <p>No report changes submitted for acknowledgement.</p>}
                {details.acknowledgements.last_page > 1 && <nav className="hr-actions" aria-label="Acknowledgement pages">
                    <button type="button" className="hr-button-secondary" disabled={ackPage <= 1} onClick={() => setAckPage(page => page - 1)}>Newer acknowledgements</button>
                    <span>Page {details.acknowledgements.current_page} of {details.acknowledgements.last_page}</span>
                    <button type="button" className="hr-button-secondary" disabled={ackPage >= details.acknowledgements.last_page} onClick={() => setAckPage(page => page + 1)}>Older acknowledgements</button>
                </nav>}
            </section>
        </>}
    </dialog>;
}
