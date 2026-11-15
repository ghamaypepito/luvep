import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FileText } from 'lucide-react';

const base = '/portal/accommodation/purchase-invoices';
const fieldStyle = 'w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-[#1F5A8B]';
const labelStyle = 'space-y-1 text-sm font-semibold text-gray-700';
const primary = 'inline-flex items-center justify-center rounded-lg bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50';
const textLink = 'text-sm font-semibold text-[#1F5A8B] hover:underline';
const money = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', currencyDisplay: 'code' });
const emptyLine = () => ({ description: '', amount: '' });
const emptyForm = (tenant) => ({
    source: 'generate', submission_key: crypto.randomUUID(), reference: '', issue_date: '', due_date: '',
    total: '', invoice: null, receipts: [], lines: [emptyLine()], gst: '', gst_number: '', bill_to: tenant.name, payment_instructions: '',
});

function InvoiceForm({ tenant, maxUploadMb, onClose }) {
    const [initial] = useState(() => emptyForm(tenant));
    const { data, setData, transform, post, errors, processing, progress } = useForm(initial);
    const generate = data.source === 'generate';
    const uploadBytes = (generate ? 0 : (data.invoice?.size ?? 0)) + data.receipts.reduce((sum, file) => sum + file.size, 0);
    const invalidUploads = uploadBytes > maxUploadMb * 1024 ** 2 || data.receipts.length > 10;
    const totalCents = data.lines.reduce((sum, line) => sum + Math.round(Number(line.amount) * 100), 0);
    const updateLine = (index, key, value) => setData('lines', data.lines.map((line, position) => position === index ? { ...line, [key]: value } : line));
    const submit = (event) => {
        event.preventDefault();
        if (invalidUploads) return;
        transform((values) => generate ? { ...values, invoice: null } : values);
        post(`/portal/accommodation/tenants/${tenant.id}/purchase-invoices`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return <form onSubmit={submit} className="space-y-4 rounded-2xl border border-gray-200 bg-white p-5">
        <h2 className="text-lg font-bold text-gray-900">Add purchase invoice</h2>
        <fieldset className="flex flex-wrap gap-4 text-sm font-semibold text-gray-700">
            <legend className="sr-only">Invoice source</legend>
            <label className="flex items-center gap-2"><input type="radio" name="source" checked={generate} onChange={() => setData('source', 'generate')} /> Generate a PDF</label>
            <label className="flex items-center gap-2"><input type="radio" name="source" checked={!generate} onChange={() => setData('source', 'upload')} /> Upload an existing invoice</label>
        </fieldset>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label className={labelStyle}>Invoice number<input className={fieldStyle} required maxLength={64} value={data.reference} onChange={(e) => setData('reference', e.target.value)} /></label>
            <label className={labelStyle}>Issue date<input className={fieldStyle} required type="date" value={data.issue_date} onChange={(e) => setData('issue_date', e.target.value)} /></label>
            <label className={labelStyle}>Due date<input className={fieldStyle} type="date" min={data.issue_date || undefined} value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} /></label>
            {generate
                ? <label className={labelStyle}>Bill to<input className={fieldStyle} required maxLength={200} value={data.bill_to} onChange={(e) => setData('bill_to', e.target.value)} /></label>
                : <label className={labelStyle}>Total (NZD)<input className={fieldStyle} required type="number" min="0.01" max="9999999.99" step="0.01" value={data.total} onChange={(e) => setData('total', e.target.value)} /></label>}
        </div>
        {generate && <fieldset className="space-y-3">
            <legend className="font-semibold text-gray-900">Purchases and fees</legend>
            <p className="text-sm text-gray-600">Enter the amount the client owes for each line, including any applicable GST. Add the service fee as its own line.</p>
            {data.lines.map((line, index) => <div key={index} className="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto]">
                <label className={labelStyle}>Description {index + 1}<input className={fieldStyle} required maxLength={255} value={line.description} onChange={(e) => updateLine(index, 'description', e.target.value)} /></label>
                <label className={labelStyle}>Amount (NZD)<input className={fieldStyle} required type="number" min="0.01" max="9999999.99" step="0.01" value={line.amount} onChange={(e) => updateLine(index, 'amount', e.target.value)} /></label>
                <button type="button" className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 disabled:opacity-50" aria-label={`Remove line ${index + 1}`} disabled={data.lines.length === 1} onClick={() => setData('lines', data.lines.filter((_, position) => position !== index))}>Remove</button>
            </div>)}
            <div className="flex flex-wrap justify-between gap-3">
                <button type="button" className={textLink} disabled={data.lines.length >= 30} onClick={() => setData('lines', [...data.lines, emptyLine()])}>Add line</button>
                <p className="text-sm font-bold text-gray-900">Total due {money.format(totalCents / 100)}</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <label className={labelStyle}>GST charged by Exalt within the total (NZD)<input className={fieldStyle} required type="number" min="0" max={totalCents / 100} step="0.01" value={data.gst} onChange={(e) => setData('gst', e.target.value)} /></label>
                <label className={labelStyle}>Exalt GST number<input className={fieldStyle} required={Number(data.gst) > 0} maxLength={32} value={data.gst_number} onChange={(e) => setData('gst_number', e.target.value)} /></label>
            </div>
            <label className={`block ${labelStyle}`}>Payment instructions<textarea className={fieldStyle} rows={3} required maxLength={1000} value={data.payment_instructions} onChange={(e) => setData('payment_instructions', e.target.value)} /></label>
        </fieldset>}
        <div className="grid gap-4 sm:grid-cols-2">
            {!generate && <label className={labelStyle}>Invoice PDF<input className={fieldStyle} required type="file" accept=".pdf" onChange={(e) => setData('invoice', e.target.files?.[0] ?? null)} /></label>}
            <label className={labelStyle}>Supporting receipts<input className={fieldStyle} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={(e) => setData('receipts', Array.from(e.target.files ?? []))} /></label>
        </div>
        <p className="text-sm text-gray-500">Up to 10 receipts. PDF, JPG, PNG or WebP. The invoice and receipts can be up to {maxUploadMb} MB combined.</p>
        {invalidUploads && <p role="alert" className="text-sm text-rose-700">Choose up to 10 receipts within the combined upload limit. You can add more receipts in Media Drive after saving.</p>}
        {Object.keys(errors).length > 0 && <ul role="alert" className="space-y-1 text-sm text-rose-700">{Object.entries(errors).map(([field, error]) => <li key={field}>{error}</li>)}</ul>}
        {progress && <p role="status" className="text-sm text-gray-600">Uploading {progress.percentage}%</p>}
        <div className="flex gap-3">
            <button type="submit" disabled={processing || invalidUploads} className={primary}>{processing ? 'Saving…' : generate ? 'Generate and save PDF' : 'Save invoice'}</button>
            <button type="button" onClick={onClose} disabled={processing} className="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100">Cancel</button>
        </div>
    </form>;
}

export default function PurchaseInvoices({ tenant, property, invoices, max_upload_mb }) {
    const [open, setOpen] = useState(false);
    return <div className="mx-auto max-w-6xl space-y-5">
        <Head title="Invoices & Receipts" />
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 className="text-2xl font-bold text-gray-900">Invoices &amp; Receipts</h1>
                <p className="mt-1 text-sm text-gray-600">{[tenant?.name, property?.name].filter(Boolean).join(' · ') || 'Accommodation purchase invoice history'}</p>
                {(tenant || property) && <Link href={base} className={textLink}>View all invoices</Link>}
            </div>
            {tenant?.can_create && !open && <button type="button" className={primary} onClick={() => setOpen(true)}>Add invoice</button>}
            {!tenant && <Link href="/portal/accommodation/tenants" className={primary}>Choose a tenant to add an invoice</Link>}
        </header>
        {open && tenant?.can_create && <InvoiceForm key={tenant.id} tenant={tenant} maxUploadMb={max_upload_mb} onClose={() => setOpen(false)} />}
        <section aria-label="Invoice history" className="overflow-hidden rounded-2xl border border-gray-200 bg-white">
            {invoices.data.length === 0 && <p className="p-6 text-sm text-gray-500">No purchase invoices recorded.</p>}
            {invoices.data.map((invoice) => <article key={invoice.id} className="space-y-3 border-b border-gray-100 p-5 last:border-b-0">
                <div className="flex flex-wrap justify-between gap-3">
                    <div>
                        <h2 className="flex items-center gap-2 font-bold text-gray-900"><FileText size={17} /> {invoice.reference}</h2>
                        <p className="mt-1 text-sm text-gray-600">{invoice.tenant_name} · {invoice.property_address}</p>
                    </div>
                    <div>
                        <p className="font-semibold tabular-nums text-gray-900">{money.format(invoice.total)}</p>
                        <p className="text-sm text-gray-600">Issued {invoice.issue_date}{invoice.due_date && ` · Due ${invoice.due_date}`}</p>
                    </div>
                </div>
                <ul className="divide-y divide-gray-100 rounded-lg bg-gray-50 px-3">
                    {invoice.files.map((file) => {
                        const url = `${base}/${invoice.id}/files/${file.id}`;
                        return <li key={file.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                            <span className="break-all text-gray-700">{file.is_invoice ? 'Invoice' : 'Receipt'} · {file.name}</span>
                            <div className="flex gap-3"><a href={`${url}?inline=1`} target="_blank" rel="noreferrer" className={textLink}>View</a><a href={url} className={textLink}>Download</a></div>
                        </li>;
                    })}
                </ul>
                <Link href={`/portal/accommodation/media-drive?folder=${invoice.folder_id}`} className={textLink}>Manage receipts and client sharing</Link>
            </article>)}
        </section>
        {invoices.last_page > 1 && <nav aria-label="Invoice history pages" className="flex flex-wrap gap-4 text-sm">
            {invoices.prev_page_url && <Link href={invoices.prev_page_url} className={textLink}>Previous</Link>}
            <span>Page {invoices.current_page} of {invoices.last_page}</span>
            {invoices.next_page_url && <Link href={invoices.next_page_url} className={textLink}>Next</Link>}
        </nav>}
    </div>;
}
