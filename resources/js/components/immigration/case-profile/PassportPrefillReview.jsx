import { useEffect, useRef, useState } from 'react';
import { immBase } from '@/lib/caseRoutes';

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
async function request(url, body, signal) {
    const response = await fetch(url, { method: 'POST', signal, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'The request could not be completed.');
    return data;
}

export default function PassportPrefillReview({ leadId, blocked = false, onApplied, onBusyChange }) {
    const [draft, setDraft] = useState(null);
    const [selected, setSelected] = useState([]);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [autoStatus, setAutoStatus] = useState('');
    const generation = useRef(0);
    const pending = useRef(null);
    useEffect(() => {
        const current = ++generation.current;
        const reader = new AbortController();
        let timer;
        setDraft(null); setSelected([]); setConfirmed(false); setError(''); setSaved(false); setBusy(false); setAutoStatus(''); onBusyChange?.(false);
        const load = async (attempt = 0) => {
            try {
                const response = await fetch(`${immBase()}/cases/${leadId}/passport-prefill`, { credentials: 'same-origin', signal: reader.signal, headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (current !== generation.current || reader.signal.aborted) return;
                if (!response.ok) return;
                if (data.draft) { setDraft(data.draft); setAutoStatus(''); }
                else if (data.status === 'queued') {
                    setAutoStatus('Passport suggestions are being prepared. You can wait or extract them now.');
                    if (attempt < 60) timer = setTimeout(() => load(attempt + 1), 2000);
                    else setAutoStatus('Suggestions are not ready yet. Try extracting now or return later.');
                } else if (data.status === 'failed' || data.status === 'unavailable') {
                    setAutoStatus('Automatic suggestions are unavailable. Try extracting now or enter the details manually.');
                }
            } catch (e) { /* Manual extraction remains available. */ }
        };
        load();
        return () => { generation.current++; reader.abort(); pending.current?.abort(); clearTimeout(timer); };
    }, [leadId]);
    const run = async (callback) => {
        const current = ++generation.current;
        const controller = new AbortController(); pending.current = controller;
        const isCurrent = () => current === generation.current && !controller.signal.aborted;
        setBusy(true); onBusyChange?.(true); setError(''); setSaved(false);
        try { await callback(controller.signal, isCurrent); } catch (e) { if (isCurrent()) setError(e.message || 'Please try again.'); }
        finally { if (isCurrent()) { setBusy(false); onBusyChange?.(false); } }
    };
    const extract = () => run(async (signal, isCurrent) => {
        const result = await request(`${immBase()}/cases/${leadId}/passport-prefill`, {}, signal);
        if (!isCurrent()) return;
        setDraft(result); setSelected([]); setConfirmed(false);
    });
    const apply = () => run(async (signal, isCurrent) => {
        const result = await request(`${immBase()}/cases/${leadId}/passport-prefill/${draft.token}`, { fields: selected, confirmed }, signal);
        if (!isCurrent()) return;
        await onApplied?.(result.saved, result.personal);
        if (!isCurrent()) return;
        setDraft(null); setSelected([]); setConfirmed(false); setSaved(true);
    });

    return <section className="rounded-2xl border border-gray-200 bg-white p-4 space-y-3" aria-label="Passport prefill review">
        <h4 className="font-semibold text-gray-900">Prefill from passport</h4>
        <p className="text-xs text-gray-600">Extract suggestions from the latest passport on this case. Nothing is saved until you select fields, check the source and confirm. Existing values are not selected for replacement automatically.</p>
        {autoStatus && !draft && <p role="status" className="text-xs text-gray-600">{autoStatus}</p>}
        {blocked && <p className="text-xs text-amber-800">Wait for your current edits to save before reviewing passport suggestions.</p>}
        {error && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{error}</p>}
        {saved && <p role="status" className="text-sm text-emerald-800">Selected passport fields saved.</p>}
        {busy && <p role="status" className="text-sm text-gray-600">Please wait…</p>}
        {!draft ? <button type="button" disabled={busy || blocked} onClick={extract} className="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white disabled:opacity-50">Extract passport suggestions</button> : <>
            <a href={draft.document.url} target="_blank" rel="noopener noreferrer" className="text-sm text-blue-700 underline">Open source passport: {draft.document.name}</a>
            {draft.full_name && <p className="text-xs text-gray-600">Name printed: {draft.full_name}. Combined or ambiguous given names must be entered manually; they are not split by the system.</p>}
            <p className="text-xs text-gray-600">Review expires in {draft.expires_in_minutes} minutes. Confidence is a model estimate, not verification.</p>
            <ul className="space-y-3">{draft.fields.map(field => <li className="rounded-lg border p-3 text-xs space-y-1" key={field.key}>
                <label className="flex items-start gap-2"><input type="checkbox" aria-label={`Accept ${field.label}`} disabled={busy || blocked || !field.can_apply} checked={selected.includes(field.key)} onChange={e => { setConfirmed(false); setSelected(current => e.target.checked ? [...current, field.key] : current.filter(key => key !== field.key)); }} /><strong>{field.label}</strong></label>
                <p>Current: {field.current || 'Not supplied'}</p><p>Passport suggestion: {field.value}</p>
                <p>Reported page: {field.page || 'Unknown'} · Confidence: {field.confidence == null ? 'Unknown' : `${Math.round(field.confidence * 100)}%`}</p>
                {field.evidence && <p>Source text: {field.evidence}</p>}
                {!field.can_apply && <p className="text-amber-800">Needs manual entry: confidence or source evidence is insufficient.</p>}
            </li>)}</ul>
            <label className="flex gap-2 text-xs"><input type="checkbox" checked={confirmed} disabled={busy || blocked || !selected.length} onChange={e => setConfirmed(e.target.checked)} />I checked the selected values against the passport and approve saving them.</label>
            <div className="flex flex-wrap gap-2">
                <button type="button" disabled={busy || blocked || !confirmed || !selected.length} onClick={apply} className="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white disabled:opacity-50">Confirm and save selected fields</button>
                <button type="button" disabled={busy} onClick={() => { setDraft(null); setSelected([]); setConfirmed(false); setError(''); }} className="rounded-lg border px-3 py-2 text-sm">Discard suggestions</button>
            </div>
        </>}
    </section>;
}