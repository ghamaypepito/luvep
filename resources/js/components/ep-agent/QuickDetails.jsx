import { useEffect, useId, useRef, useState } from 'react';
import { X } from 'lucide-react';
import { epAgentClient as api } from '../../lib/epAgentClient';
export default function QuickDetails({ detail, portal, onClose, onAccessLost }) {
    const dialog = useRef(null), heading = useId();
    const [data, setData] = useState(null), [error, setError] = useState(''), [retry, setRetry] = useState(0);
    useEffect(() => {
        const previous = document.activeElement; const node = dialog.current;
        node.showModal();
        const cancel = event => { event.preventDefault(); onClose(); }; node.addEventListener('cancel', cancel);
        return () => { node.removeEventListener('cancel', cancel); node.close(); if (previous?.isConnected) previous.focus(); };
    }, [onClose]);
    useEffect(() => {
        const controller = new AbortController(); let alive = true; setData(null); setError('');
        api.detail(detail.kind, detail.id, portal, { signal: controller.signal }).then(result => {
            if (!alive) return;
            if ([401, 403, 419].includes(result.status)) { onAccessLost(); return; }
            if (!result.ok) throw Error();
            setData(result.data[detail.kind === 'clients' ? 'client' : 'personnel']);
        }).catch(e => { if (alive && e.name !== 'AbortError') setError('These details are unavailable. Refresh or close this window.'); });
        return () => { alive = false; controller.abort(); };
    }, [detail.kind, detail.id, portal, retry, onAccessLost]);
    return <dialog ref={dialog} className="ep-quick-details" aria-labelledby={heading}>
        <header><h2 id={heading}>{detail.kind === 'clients' ? 'Client details' : 'Personnel details'}</h2><button type="button" className="ep-icon" aria-label="Close quick details" onClick={onClose}><X size={19} /></button></header>
        {!data && !error && <p role="status" className="ep-muted">Loading details…</p>}
        {error && <div><p role="alert" className="ep-error">{error}</p><button type="button" className="ep-text-button" onClick={() => setRetry(value => value + 1)}>Try again</button></div>}
        {data && <><h3>{data.name}</h3><dl>{detail.kind === 'clients' ? <>
            <dt>Email</dt><dd>{data.email || 'Not recorded'}</dd><dt>Phone</dt><dd>{data.phone || 'Not recorded'}</dd><dt>Stage</dt><dd>{data.stage || 'Not recorded'}</dd><dt>Assigned personnel</dt><dd>{data.personnel?.name || (data.assignment_status === 'unavailable' ? 'Personnel unavailable' : 'Unassigned')}</dd>
        </> : <><dt>Role</dt><dd>{data.role?.replaceAll('_', ' ')}</dd><dt>Work email</dt><dd>{data.work_email || 'Not recorded'}</dd><dt>Accessible records assigned</dt><dd>{data.workload}</dd></>}</dl><p className="ep-muted">{portal === 'education' ? 'Education' : 'Immigration'} · Current authorized record data</p></>}
    </dialog>;
}
