import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

export default function SocialDrivePicker({ onSelect, onClose }) {
    const dialog = useRef(null), request = useRef(null);
    const [search, setSearch] = useState(''), [page, setPage] = useState(1), [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true), [picking, setPicking] = useState(false), [error, setError] = useState(''), [attempt, setAttempt] = useState(0);
    useEffect(() => { dialog.current.showModal(); return () => request.current?.abort(); }, []);
    useEffect(() => {
        const controller = new AbortController(); setLoading(true); setError('');
        axios.get('/social/drive/assets', { signal: controller.signal, headers: { Accept: 'application/json' }, params: { search, page } })
            .then(({ data }) => { if (!controller.signal.aborted) setResult(data.assets); })
            .catch(e => { if (!controller.signal.aborted) setError(e.response?.data?.message || 'Social Drive could not load. Try again.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [search, page, attempt]);
    function close() { request.current?.abort(); onClose(); }
    async function choose(asset) {
        if (picking) return;
        const controller = new AbortController(); request.current = controller; setPicking(true); setError('');
        try {
            let file = null;
            if (asset.download_url) {
                let { data } = await axios.get(asset.download_url, { signal: controller.signal, responseType: 'blob' });
                let mime = asset.mime;
                if (mime === 'image/webp') {
                    const bitmap = await createImageBitmap(data);
                    try { const canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height; canvas.getContext('2d').drawImage(bitmap, 0, 0); data = await new Promise(resolve => canvas.toBlob(resolve, 'image/png')); mime = 'image/png'; }
                    finally { bitmap.close(); }
                }
                if (!data || data.size >= (mime === 'video/mp4' ? 50 : 10) * 1024 * 1024) throw Error('This media is too large to attach. Choose another asset.');
                const ext = { 'video/mp4': 'mp4', 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' }[mime];
                if (!ext) throw Error('This asset cannot be attached to a social post.');
                file = new File([data], 'social-drive.' + ext, { type: mime });
            }
            if (!controller.signal.aborted) onSelect(asset, file);
        } catch (e) { if (!controller.signal.aborted) setError(e.response?.data?.message || e.message || 'The asset could not be attached.'); }
        finally { if (!controller.signal.aborted) setPicking(false); }
    }
    return <dialog ref={dialog} className="calendar-type-dialog social-drive-picker" aria-labelledby="social-drive-picker-title" onCancel={event => { event.preventDefault(); close(); }}>
        <header><div><h2 id="social-drive-picker-title">Upload from Social Drive</h2><p>Private files for admins and the Digital team. Reused content starts as a new draft requiring approval.</p></div><button type="button" className="calendar-icon" aria-label="Close Social Drive" onClick={close}>×</button></header>
        <label className="social-field">Search Social Drive<input value={search} disabled={picking} onChange={event => { setSearch(event.target.value); setPage(1); }} /></label>
        {error && <p role="alert">{error} <button type="button" onClick={() => setAttempt(value => value + 1)}>Retry</button></p>}
        {loading ? <p role="status">Loading private assets…</p> : !error && <><ul className="social-drive-picker-list">{result?.data.map(asset => <li key={asset.id}><div><strong>{asset.name}</strong><small>{asset.mime || 'Caption'} · {asset.source_version ? 'Approved snapshot' : 'Uploaded asset'}</small></div><button type="button" className="calendar-button secondary" disabled={picking} onClick={() => choose(asset)}>{picking ? 'Attaching…' : 'Use asset'}</button></li>)}</ul>
            {!result?.data.length && <p>No matching assets in Social Drive.</p>}
            <div className="calendar-header-actions"><button type="button" className="calendar-button secondary" disabled={picking || page <= 1} onClick={() => setPage(value => value - 1)}>Previous</button><span>Page {page} of {result?.last_page || 1}</span><button type="button" className="calendar-button secondary" disabled={picking || page >= result?.last_page} onClick={() => setPage(value => value + 1)}>Next</button></div></>}
    </dialog>;
}
