import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

const SIGNABLE = ['written_agreement', 'initial_consultation', 'oia'];
export default function StaffSignatureUpload({ record, internalReady = false, onClose, onSaved }) {
    const agreements = record.documents.filter((doc) => SIGNABLE.includes(doc.type_key) && !doc.signed);
    const [documentId, setDocumentId] = useState(String(agreements[0]?.id || ''));
    const [name, setName] = useState(record.case_name || '');
    const [file, setFile] = useState(null);
    const [imageUrl, setImageUrl] = useState('');
    const [authorized, setAuthorized] = useState(false);
    const [notifyClient, setNotifyClient] = useState(false);
    const [notifyInternal, setNotifyInternal] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState(null);
    const dialog = useRef(null);
    const document = agreements.find((doc) => String(doc.id) === documentId);
    useEffect(() => { dialog.current?.showModal(); }, []);
    useEffect(() => {
        if (!file) { setImageUrl(''); return; }
        const url = URL.createObjectURL(file); setImageUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);
    const pick = (event) => {
        const selected = event.target.files?.[0]; setError('');
        if (!selected) return;
        if (!['image/png', 'image/jpeg'].includes(selected.type) || selected.size > 2 * 1024 * 1024) {
            setFile(null); event.target.value = ''; setError('Choose a PNG or JPG signature up to 2 MB.'); return;
        }
        setFile(selected);
    };
    const submit = async () => {
        if (busy || result || !document || !file || !authorized || !name.trim()) return;
        setBusy(true); setError('');
        const data = new FormData();
        data.append('signature_image', file); data.append('signer_name', name.trim()); data.append('authorized', '1');
        data.append('notify_client', notifyClient ? '1' : '0'); data.append('notify_internal', notifyInternal ? '1' : '0');
        try {
            const response = await axios.post(`/portal/immigration/engagement/documents/${document.id}/signature`, data);
            setResult(response.data); onSaved?.();
        } catch (e) {
            setError(Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'Signature upload failed. Please retry.');
        } finally { setBusy(false); }
    };
    return <dialog ref={dialog} aria-labelledby="staff-signature-title" onCancel={(e) => { e.preventDefault(); if (!busy) onClose(); }} className="w-[min(1100px,95vw)] rounded-2xl p-0 backdrop:bg-black/40">
        <div className="space-y-4 p-5">
            <h2 id="staff-signature-title" className="text-lg font-bold">Upload client-provided signature</h2>
            <p className="text-sm text-gray-600">{record.case_name}. This records a signature uploaded by staff, with your name and timestamp in the audit history. It cannot replace an already-signed agreement.</p>
            {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
            {result ? <div role="status" className="space-y-2 rounded-lg bg-green-50 p-4 text-sm">
                <p className="font-semibold">Signature saved. Do not upload it again to retry a notification.</p>
                <p>Client email: {result.notifications.client === 'not_requested' ? 'not requested' : result.notifications.client}.</p>
                <p>Internal portal notification: {result.notifications.internal === 'not_requested' ? 'not requested' : result.notifications.internal}.</p>
                {Object.values(result.notifications).includes('failed') && <p role="alert">A notification failed. The signature is already saved; ask support to review notification delivery.</p>}
            </div> : <div className="grid gap-5 lg:grid-cols-2">
                <div>{document && <iframe title="Issued agreement preview" src={document.view_url || document.download_url + '?inline=1'} className="h-[540px] w-full rounded-lg border" />}</div>
                <fieldset disabled={busy} className="space-y-4 text-sm">
                    <label className="block font-semibold">Agreement<select aria-label="Agreement to sign" value={documentId} onChange={(e) => { setDocumentId(e.target.value); setAuthorized(false); }} className="mt-1 block w-full rounded-lg border p-2">{agreements.map((doc) => <option key={doc.id} value={doc.id}>{doc.type_label || doc.type_key}</option>)}</select></label>
                    <label className="block font-semibold">Client signer full name<input value={name} maxLength={120} onChange={(e) => setName(e.target.value)} className="mt-1 block w-full rounded-lg border p-2" /></label>
                    <label className="block font-semibold">Signature image (PNG/JPG, up to 2 MB)<input aria-label="Client signature image" type="file" accept="image/png,image/jpeg" onChange={pick} className="mt-2 block w-full text-xs" /></label>
                    {imageUrl && <img src={imageUrl} alt="Selected client signature" className="max-h-32 max-w-full rounded-lg border bg-white p-3" />}
                    <label className="flex items-start gap-2"><input type="checkbox" checked={authorized} onChange={(e) => setAuthorized(e.target.checked)} /><span>I confirm that the client provided this signature and authorized its application to this specific issued agreement.</span></label>
                    <fieldset className="space-y-2 rounded-lg border p-3"><legend className="px-1 font-semibold">Client email</legend>
                        <label className="flex gap-2"><input type="checkbox" checked={!notifyClient} onChange={() => setNotifyClient(false)} />Do not send an email notification to the client.</label>
                        <label className="flex gap-2"><input type="checkbox" checked={notifyClient} onChange={() => setNotifyClient(true)} />Notify the client via email once the signature has been uploaded.</label>
                    </fieldset>
                    <fieldset className="space-y-2 rounded-lg border p-3"><legend className="px-1 font-semibold">Internal portal notification</legend>
                        <label className="flex gap-2"><input type="checkbox" checked={!notifyInternal} onChange={() => setNotifyInternal(false)} />Do not send an internal notification to our team.</label>
                        <label className="flex gap-2"><input type="checkbox" disabled={!internalReady} checked={notifyInternal} onChange={() => setNotifyInternal(true)} />Send an internal notification to our team once the signature has been uploaded.</label>
                        {!internalReady && <p className="text-xs text-amber-800">Internal recipients still need confirmation. This option becomes available after the approved team is configured.</p>}
                    </fieldset>
                    <p className="text-xs text-gray-500">Notification choices are independent. The original agreement is retained. Only the selected agreement receives this signature.</p>
                </fieldset>
            </div>}
            <footer className="flex justify-end gap-2 border-t pt-4">
                <button type="button" disabled={busy} onClick={onClose} className="rounded-lg border px-4 py-2 text-sm">{result ? 'Close' : 'Cancel'}</button>
                {!result && <button type="button" disabled={busy || !file || !authorized || !name.trim() || !document} onClick={submit} className="rounded-lg bg-teal-700 px-4 py-2 text-sm text-white disabled:opacity-40">{busy ? 'Saving signature...' : 'Upload signature'}</button>}
            </footer>
        </div>
    </dialog>;
}
