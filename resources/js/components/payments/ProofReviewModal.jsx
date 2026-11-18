import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, Download, Loader2, X } from 'lucide-react';

const inputClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 disabled:bg-gray-50 disabled:text-gray-600';
const statusLabel = status => status === 'Approved' ? 'Confirmed' : status === 'Rejected' ? 'Needs attention' : 'Under review';
const money = (value, currency) => value == null ? 'Not recorded' : new Intl.NumberFormat('en-NZ', { style: 'currency', currency: currency || 'NZD' }).format(Number(value));

/** Existing proof preview plus invoice/receipt verification; shared by both staff entry points. */
export default function ProofReviewModal({ proof, leadId, invoiceId = null, onClose, proofChoices = [], onSelect }) {
    const dialog = useRef(null);
    const opener = useRef(null);
    const id = useId();
    const [options, setOptions] = useState(null);
    const [loadError, setLoadError] = useState('');
    const [retry, setRetry] = useState(0);
    const [rejection, setRejection] = useState(false);
    const form = useForm({ status: 'Approved', invoice_id: '', invoice_currency: '', payment_mode: 'new', amount: '', paid_at: '', reference: '', finance_payment_id: '', note: '' });
    const invoice = options?.invoices.find(item => String(item.id) === String(form.data.invoice_id));
    const currency = (invoice?.currency_recorded === false ? form.data.invoice_currency : invoice?.summary.currency) || 'NZD';
    const receipts = options?.payments.filter(item => (!item.invoice_id || String(item.invoice_id) === String(form.data.invoice_id)) && item.currency === currency) || [];
    const approved = (options?.status || proof.status) === 'Approved';
    const editable = !!options?.can_review && !approved;
    const viewUrl = proof.view_url || `/admin/documents/${proof.id}/download?inline=1`;
    const downloadUrl = proof.download_url || `/admin/documents/${proof.id}/download`;
    const isImage = (proof.mime || '').startsWith('image/') || /\.(png|jpe?g|gif|webp)$/i.test(proof.original_name || '');

    useEffect(() => {
        opener.current = document.activeElement;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.current?.showModal();
        return () => {
            document.body.style.overflow = overflow;
            opener.current?.focus?.();
        };
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        setLoadError('');
        setOptions(null);
        axios.get(`/clients/${leadId}/payment-proofs/${proof.id}/review-options`, { signal: controller.signal })
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                setOptions(data);
                const assigned = data.review?.invoice_id;
                const selected = assigned || (data.invoices.some(item => item.id === invoiceId) ? invoiceId : null) || (data.invoices.length === 1 ? data.invoices[0].id : '');
                form.setData({ status: 'Approved', invoice_id: String(selected || ''), invoice_currency: '', payment_mode: 'new', amount: '',
                    paid_at: data.today, reference: '', finance_payment_id: '', note: data.note || '' });
            }).catch(error => {
                if (!controller.signal.aborted) setLoadError(error.response?.status === 403 ? 'You do not have access to this payment review.' : 'Could not load payment details. Try again.');
            });
        return () => controller.abort();
    }, [leadId, proof.id, invoiceId, retry]);

    const finishClose = () => { dialog.current?.close(); onClose(); };
    const close = () => { if (!form.processing) finishClose(); };
    const submit = event => {
        event.preventDefault();
        if (!editable || form.processing) return;
        form.transform(data => ({ ...data, status: rejection ? 'Rejected' : 'Approved', note: rejection ? data.note : null,
            ...(rejection || data.payment_mode === 'existing' ? { amount: null, reference: null } : { finance_payment_id: null }),
        }));
        form.post(`/admin/leads/${leadId}/documents/${proof.id}/status`, { preserveScroll: true, onSuccess: finishClose });
    };
    const keepFocus = event => {
        if (event.key !== 'Tab') return;
        const controls = Array.from(dialog.current.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled)')).filter(element => element.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };

    return createPortal(<dialog ref={dialog} aria-labelledby={`${id}-title`} onKeyDown={keepFocus}
        onCancel={event => { event.preventDefault(); close(); }}
        className="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[95vw] max-w-[1000px] overflow-y-auto rounded-2xl bg-white p-0 text-gray-900 shadow-xl backdrop:bg-black/50">
        <header className="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-3.5">
            <div className="min-w-0"><h2 id={`${id}-title`} className="truncate text-sm font-bold">{proof.case_name ? `${proof.case_name} — ` : ''}Proof of payment</h2>
                <p className="mt-1 truncate text-xs text-gray-500">{proof.original_name}</p></div>
            <div className="flex shrink-0 items-center gap-2"><a href={downloadUrl} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700"><Download size={14} /> Download</a>
                <button type="button" onClick={close} disabled={form.processing} aria-label="Close payment proof" className="flex h-10 w-10 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 disabled:opacity-50"><X size={18} /></button></div>
        </header>
        {proofChoices.length > 1 && <nav aria-label="Payment proof files" className="flex flex-wrap gap-1.5 border-b border-gray-100 bg-gray-50 px-4 py-2">
            {proofChoices.map((item, index) => <button type="button" disabled={form.processing} key={item.id} onClick={() => onSelect?.(item.id)} aria-current={item.id === proof.id ? 'true' : undefined}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium ${item.id === proof.id ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:bg-white'}`}>Proof {index + 1} · {statusLabel(item.status)}</button>)}
        </nav>}
        <div className="grid grid-cols-1 md:grid-cols-2">
            <div className="flex min-h-[300px] items-center justify-center overflow-auto bg-gray-100 p-4 md:min-h-[500px]">
                {isImage ? <img src={viewUrl} alt={proof.original_name} className="max-h-[65vh] max-w-full object-contain" /> : <iframe src={viewUrl} title={proof.original_name || 'Payment proof'} className="h-[500px] w-full border-0" />}
            </div>
            <div className="space-y-4 p-5">
                {!options && !loadError && <p role="status" className="flex items-center gap-2 text-sm text-gray-600"><Loader2 size={15} className="animate-spin motion-reduce:animate-none" /> Loading payment details…</p>}
                {loadError && <div className="space-y-3"><p role="alert" className="text-sm text-rose-700">{loadError}</p><button type="button" onClick={() => setRetry(value => value + 1)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm">Try again</button></div>}
                {options && <>
                    <div><p className="text-sm font-semibold">{editable ? 'Verify this proof' : 'Review status'}</p><p className={`mt-1 text-sm font-semibold ${approved ? 'text-emerald-700' : options.status === 'Rejected' ? 'text-rose-700' : 'text-amber-700'}`}>{statusLabel(options.status)}</p>
                        {options.reviewed_by && <p className="mt-1 text-xs text-gray-500">Reviewed by {options.reviewed_by}{options.reviewed_at ? ` · ${new Date(options.reviewed_at).toLocaleDateString('en-NZ')}` : ''}</p>}</div>
                    {Object.keys(form.errors).length > 0 && <div role="alert" className="space-y-1 text-sm text-rose-700">{Object.entries(form.errors).map(([key, error]) => <p key={key}>{error}</p>)}</div>}
                    {editable ? <form id={`${id}-form`} onSubmit={submit} className="space-y-4" aria-busy={form.processing}>
                        <label className="block text-sm font-medium" htmlFor={`${id}-invoice`}>Invoice</label>
                        <select id={`${id}-invoice`} className={inputClass} disabled={form.processing || !!options.review?.invoice_id} value={form.data.invoice_id}
                            onChange={event => { form.clearErrors(); form.setData({ ...form.data, invoice_id: event.target.value, invoice_currency: '', finance_payment_id: '' }); }}>
                            <option value="">Select the matching invoice</option>{options.invoices.map(item => <option key={item.id} value={item.id}>{item.number} · {money(item.summary.total, item.summary.currency)}</option>)}
                        </select>
                        {invoice && <p className="text-xs text-gray-600">Remaining {money(invoice.summary.remaining, currency)}</p>}
                        {!rejection && <>
                            {invoice?.currency_recorded === false && <div className="space-y-1.5"><label htmlFor={`${id}-currency`} className="block text-sm font-medium">Invoice currency</label>
                                <select id={`${id}-currency`} className={inputClass} required disabled={form.processing} value={form.data.invoice_currency} onChange={event => form.setData({ ...form.data, invoice_currency: event.target.value, finance_payment_id: '' })}>
                                    <option value="">Confirm currency from the invoice</option><option value="NZD">NZD</option><option value="USD">USD</option>
                                </select><p className="text-xs text-gray-600">This older invoice has no saved currency. Check the invoice before recording money.</p></div>}

                            <div className="space-y-1.5"><label htmlFor={`${id}-mode`} className="block text-sm font-medium">Payment record</label>
                                <select id={`${id}-mode`} className={inputClass} disabled={form.processing} value={form.data.payment_mode} onChange={event => { form.clearErrors(); form.setData('payment_mode', event.target.value); }}>
                                    <option value="new">Record a separate payment</option><option value="existing">Supporting file for an existing payment</option>
                                </select></div>
                            {form.data.payment_mode === 'existing' ? <div className="space-y-1.5"><label htmlFor={`${id}-receipt`} className="block text-sm font-medium">Existing confirmed payment</label>
                                <select id={`${id}-receipt`} className={inputClass} value={form.data.finance_payment_id} disabled={form.processing} onChange={event => form.setData('finance_payment_id', event.target.value)} required>
                                    <option value="">Select the matching payment</option>{receipts.map(item => <option key={item.id} value={item.id}>{item.reference || 'Payment'} · {money(item.amount, item.currency)} · {item.paid_at}</option>)}
                                </select><p className="text-xs text-gray-600">Links this file without recording the amount again.</p></div> : <>
                                <div className="space-y-1.5"><label htmlFor={`${id}-amount`} className="block text-sm font-medium">Verified payment amount ({currency})</label><input id={`${id}-amount`} className={inputClass} type="number" min="0.01" max="999999999.99" step="0.01" required disabled={form.processing} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} /></div>
                                <div className="space-y-1.5"><label htmlFor={`${id}-date`} className="block text-sm font-medium">Payment date</label><input id={`${id}-date`} className={inputClass} type="date" max={options.today} required disabled={form.processing} value={form.data.paid_at} onChange={event => form.setData('paid_at', event.target.value)} /></div>
                                <div className="space-y-1.5"><label htmlFor={`${id}-reference`} className="block text-sm font-medium">Transfer reference</label><input id={`${id}-reference`} className={inputClass} maxLength={120} required disabled={form.processing} value={form.data.reference} onChange={event => form.setData('reference', event.target.value)} placeholder="Unique bank transaction ID" /><p className="text-xs text-gray-600">Use this transfer’s bank transaction ID, rather than a repeated invoice reference.</p></div>
                            </>}
                            <p className="text-xs leading-relaxed text-gray-600">Confirm only the amount received for this transfer. The invoice stays partially paid until confirmed payments cover its total.</p>
                        </>}
                        {rejection && <div className="space-y-1.5"><label htmlFor={`${id}-note`} className="block text-sm font-medium">Reason this proof needs attention</label><textarea id={`${id}-note`} className={inputClass} rows={3} maxLength={500} required disabled={form.processing} value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></div>}
                    </form> : <div className="space-y-3 text-sm text-gray-600">
                        {options.review && <><p>Invoice: {options.review.invoice_number}</p><p>{options.review.supporting ? 'Supporting document for' : 'Confirmed payment:'} {money(options.review.amount, options.review.currency)}</p><p>Reference: {options.review.reference || 'Not recorded'}</p></>}
                        {options.note && <p>{options.note}</p>}
                        <p>{approved ? 'Confirmed payment evidence is retained for reconciliation. Ask Finance to review any correction.' : 'Only authorized Admin and Finance staff can confirm payments or mark a proof as needing attention.'}</p>
                    </div>}
                </>}
            </div>
        </div>
        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-5 py-3.5">
            <p className="text-xs text-gray-600">This review applies to the selected proof only.</p><div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={close} disabled={form.processing} className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 disabled:opacity-50">Close</button>
                {editable && <><button type="button" disabled={form.processing} onClick={() => { form.clearErrors(); setRejection(value => !value); }} className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 disabled:opacity-50">{rejection ? 'Back to confirmation' : 'Needs attention'}</button>
                    <button type="submit" form={`${id}-form`} disabled={form.processing} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-50">{form.processing ? <Loader2 size={14} className="animate-spin motion-reduce:animate-none" /> : <CheckCircle2 size={14} />}{rejection ? 'Save needs attention' : 'Confirm payment'}</button></>}
            </div>
        </footer>
    </dialog>, document.body);
}
