import { useEffect, useId, useRef, useState } from 'react';
import { X } from 'lucide-react';
import { epAdminClient as api } from '../../lib/epAdminClient';
import { utcDate } from '../../lib/epAdminTime';
import { money } from '../../lib/epMoney';
export default function RunDetails({ id, onClose, onAccessLost }) {
    const dialog = useRef(null), heading = useId(); const [run, setRun] = useState(null), [error, setError] = useState('');
    useEffect(() => {
        const previous = document.activeElement, node = dialog.current; node.showModal();
        const cancel = event => { event.preventDefault(); onClose(); }; node.addEventListener('cancel', cancel);
        return () => { node.removeEventListener('cancel', cancel); node.close(); if (previous?.isConnected) previous.focus(); };
    }, [onClose]);
    useEffect(() => {
        const controller = new AbortController(); let alive = true;
        api.run(id, { signal: controller.signal }).then(result => {
            if (!alive) return;
            if ([401, 403, 419].includes(result.status)) { onAccessLost(); return; }
            if (!result.ok) throw Error();
            setRun(result.data.run);
        }).catch(e => { if (alive && e.name !== 'AbortError') setError('This run is unavailable. Close the window and refresh the dashboard.'); });
        return () => { alive = false; controller.abort(); };
    }, [id, onAccessLost]);
    const value = field => run?.[field] ?? 'Not recorded';
    return <dialog ref={dialog} className="ep-quick-details ep-run-details" aria-labelledby={heading}><header><h2 id={heading}>Run #{id}</h2><button type="button" className="ep-icon" aria-label="Close run details" onClick={onClose}><X size={18} /></button></header>
        {error ? <p role="alert" className="ep-error">{error}</p> : !run ? <p role="status" className="ep-muted">Loading run metadata…</p> : <><h3>{run.state.replaceAll('_', ' ')}</h3><dl>
            <dt>Owner</dt><dd>{run.actor.name}</dd><dt>Portal</dt><dd>{run.portal}</dd><dt>Status reason</dt><dd>{run.reason_label || 'Not recorded'}</dd>
            <dt>Recorded cost</dt><dd>{money(run.actual_micros)}</dd><dt>Reserved bound</dt><dd>{money(run.upper_bound_micros)}</dd>
            <dt>Input tokens</dt><dd>{value('input_tokens')}</dd><dt>Output tokens</dt><dd>{value('output_tokens')}</dd><dt>Cached read</dt><dd>{value('cached_read_tokens')}</dd><dt>Cached write</dt><dd>{value('cached_write_tokens')}</dd><dt>Tool units</dt><dd>{value('tool_units')}</dd>
            <dt>Provider</dt><dd>{value('provider')}</dd><dt>Model</dt><dd>{value('model')}</dd><dt>Price version</dt><dd>{value('pricing_version')}</dd><dt>Created</dt><dd>{utcDate(run.created_at)}</dd>
        </dl><p className="ep-muted">Operational metadata only. Private messages, titles and drafts are not available here. A reserved bound is not a final charge.</p></>}
    </dialog>;
}
