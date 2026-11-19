import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';

export default function AssignedCase({ case: record, documents = [], slots = [], requests = [] }) {
    const [target, setTarget] = useState('');
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const input = useRef(null), saving = useRef(false);
    useEffect(() => { setTarget(''); setFile(null); setError(''); setMessage(''); if (input.current) input.current.value = ''; }, [record.id]);
    const upload = event => {
        event.preventDefault();
        if (!target || !file || saving.current) return;
        const separator = target.indexOf(':');
        const kind = target.slice(0, separator), value = target.slice(separator + 1);
        const data = { file, ...(kind === 'slot' ? { checklist_key: value } : { request_id: Number(value) }) };
        saving.current = true; setBusy(true); setError(''); setMessage('');
        router.post(`/portal/agent/assigned-cases/${record.id}/uploads`, data, {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => { setMessage('File uploaded for staff review.'); setFile(null); if (input.current) input.current.value = ''; },
            onError: errors => setError(Object.values(errors).flat().join(' ') || 'Could not upload this file. Refresh the case before retrying.'),
            onFinish: () => { saving.current = false; setBusy(false); },
        });
    };
    return <main className="mx-auto max-w-5xl space-y-5 p-6">
        <Head title={`${record.name} — Assigned case`} />
        <Link href="/portal/agent/assigned-cases" className="text-sm text-teal-800 underline">Assigned cases</Link>
        <header><h1 className="text-2xl font-bold">{record.name || record.reference}</h1><p className="mt-1 text-sm text-gray-600">{[record.reference, record.visa, record.stage].filter(Boolean).join(' · ')}</p></header>
        <section className="rounded-xl border bg-white p-5"><h2 className="font-semibold">Shared checklist and request uploads</h2><p className="mt-2 text-sm text-gray-600">Only visible, non-financial uploads matched to this case’s primary-applicant checklist or document requests are shared here.</p>
            <p className="mt-2 text-sm text-gray-600">Where listed, these can include applicant identity, CV, qualifications, police, medical and visa or travel evidence. Bank, funds and income records, signatures or signed forms, and sponsor, partner, child or mixed-person records stay with staff.</p>
            {!documents.length ? <p className="mt-3 text-sm text-gray-600">No visible uploaded documents.</p> : <ul className="mt-3 divide-y">{documents.map(doc => <li key={doc.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="break-all text-sm font-medium">{doc.name}</p><p className="text-xs text-gray-600">{doc.status}</p></div><div className="flex gap-3 text-sm text-teal-800"><a href={`${doc.url}?inline=1`} target="_blank" rel="noopener noreferrer" className="underline">View</a><a href={doc.url} className="underline">Download</a></div></li>)}</ul>}
        </section>
        <section className="rounded-xl border bg-white p-5"><h2 className="font-semibold">Upload requested evidence</h2>
            {!slots.length && !requests.length ? <p className="mt-3 text-sm text-gray-600">No upload requests are currently available. Ask the case team to request the required document.</p> : <form onSubmit={upload} className="mt-4 space-y-3">
                <label className="block text-sm">Requested document<select aria-label="Requested document" value={target} onChange={event => setTarget(event.target.value)} disabled={busy} className="mt-1 block w-full rounded border p-2"><option value="">Choose a requested document</option>{slots.map(slot => <option key={`slot:${slot.key}`} value={`slot:${slot.key}`}>{slot.label}</option>)}{requests.map(request => <option key={`request:${request.id}`} value={`request:${request.id}`}>{request.label}</option>)}</select></label>
                <label className="block text-sm">File<input ref={input} aria-label="Requested evidence file" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" disabled={busy} onChange={event => setFile(event.target.files?.[0] || null)} className="mt-1 block w-full text-sm" /></label>
                <p className="text-xs text-gray-600">PDF, JPG, PNG, Word or Excel. Maximum 50 MB. New uploads are submitted for staff review.</p>
                <button type="submit" disabled={busy || !target || !file} className="rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Uploading…' : 'Upload requested file'}</button>
            </form>}
            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}{message && <p role="status" className="mt-3 text-sm text-emerald-800">{message}</p>}
        </section>
    </main>;
}
