import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const base = '/portal/accommodation/rental-invoices';
const input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm';
const button = 'rounded-lg bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white disabled:opacity-40';
const textLink = 'text-sm font-semibold text-[#1F5A8B] hover:underline';
const formatMoney = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', currencyDisplay: 'code' });
const money = cents => formatMoney.format(cents / 100);
const toCents = value => Math.round(Number(value || 0) * 100);
const addDays = (iso, days) => {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
};

function InvoiceEditor({ kind, tenant, agreements, weeks, bank, today, dueDate }) {
    const moveIn = kind === 'move-in';
    const initial = agreements[0];
    const [customDue, setCustomDue] = useState(false);
    const [preview, setPreview] = useState('');
    const [previewBusy, setPreviewBusy] = useState(false);
    const [previewError, setPreviewError] = useState('');
    const { data, setData, transform, post, errors, processing } = useForm(() => ({
        submission_key: crypto.randomUUID(), issue_date: today, due_date: dueDate,
        agreement_id: initial?.id ?? '', lines: initial?.lines ?? [], weeks: [], payment_check_confirmed: false,
    }));
    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);
    const selectedAgreement = agreements.find(agreement => agreement.id === data.agreement_id);
    const endpoint = `${base}/${kind}/tenants/${tenant.id}`;
    const total = (moveIn ? data.lines : data.weeks).reduce((sum, line) => sum + line.amount_cents, 0);
    const packageSubtotal = data.lines.filter(line => line.group === 'package').reduce((sum, line) => sum + line.amount_cents, 0);
    const canIssue = total > 0 && !!data.issue_date && !!data.due_date && (moveIn ? !!selectedAgreement : data.payment_check_confirmed);
    const payload = values => {
        const { issue_date, due_date, submission_key, agreement_id, lines, weeks, payment_check_confirmed } = values;
        return { issue_date, due_date, submission_key, ...(moveIn ? { agreement_id, lines } : { weeks, payment_check_confirmed }) };
    };

    function updateWeek(row, selected) {
        setData('weeks', selected
            ? [...data.weeks, { start: row.start, version: row.version, amount_cents: row.outstanding_cents }]
            : data.weeks.filter(week => week.start !== row.start));
    }

    async function showPreview(event) {
        if (!event.currentTarget.form.reportValidity()) return;
        setPreviewBusy(true);
        setPreviewError('');
        try {
            const response = await window.axios.post(`${endpoint}/preview`, payload(data), { responseType: 'blob' });
            setPreview(URL.createObjectURL(response.data));
        } catch (error) {
            const body = await error.response?.data?.text?.();
            let message = 'Could not preview the invoice. Check the details and try again.';
            try { message = JSON.parse(body).message || message; } catch { /* use default */ }
            setPreviewError(message);
        } finally {
            setPreviewBusy(false);
        }
    }

    function issue(event) {
        event.preventDefault();
        if (!canIssue || processing || !window.confirm('Issue this invoice and save its PDF to the tenant history?')) return;
        transform(payload);
        post(endpoint);
    }

    return <form onSubmit={issue} className="space-y-5 rounded-xl border bg-white p-5">
        {moveIn && <label className="block text-sm font-semibold">Agreed costs from agreement
            <select className={input} value={data.agreement_id} onChange={event => {
                const agreement = agreements.find(item => item.id === Number(event.target.value));
                setData(previous => ({ ...previous, agreement_id: agreement.id, lines: agreement.lines }));
            }}>
                {agreements.map(agreement => <option key={agreement.id} value={agreement.id}>#{agreement.id} · {agreement.label}{agreement.signed_on && ` · ${agreement.signed_on}`}</option>)}
            </select>
        </label>}
        <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><strong>Bill to</strong><p>{selectedAgreement?.bill_to || tenant.name}</p></div>
            <div><strong>Property</strong><p>{selectedAgreement?.property || tenant.property}</p></div>
            <label className="font-semibold">Invoice date<input className={input} required type="date" value={data.issue_date} onChange={event => {
                const issueDate = event.target.value;
                setData(previous => ({ ...previous, issue_date: issueDate, due_date: customDue || !issueDate ? previous.due_date : addDays(issueDate, 2) }));
            }} /></label>
            <label className="font-semibold">Payment due date<input className={input} required type="date" min={data.issue_date} value={data.due_date} onChange={event => {
                setCustomDue(Boolean(event.target.value));
                setData('due_date', event.target.value || (data.issue_date ? addDays(data.issue_date, 2) : ''));
            }} /></label>
        </div>
        {moveIn ? <fieldset className="space-y-2">
            <legend className="mb-2 font-semibold">Invoice items</legend>
            {data.lines.map((line, pos) => <div key={pos} className="grid items-end gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_auto]">
                <label className="text-sm">{line.group === 'package' ? 'Welcome Package' : 'Description'} <input required maxLength={255} className={input}
                    value={line.description} onChange={event => setData('lines', data.lines.map((item, index) => index === pos ? { ...item, description: event.target.value } : item))} /></label>
                <label className="text-sm">Amount (NZD) <input required className={input} type="number" min="0.01" max="9999999.99" step="0.01"
                    value={line.amount_cents ? line.amount_cents / 100 : ''} onChange={event => setData('lines', data.lines.map((item, index) => index === pos ? { ...item, amount_cents: toCents(event.target.value) } : item))} /></label>
                <button type="button" className={textLink} onClick={() => setData('lines', data.lines.filter((_, index) => index !== pos))}>Remove</button>
            </div>)}
            <button type="button" className={textLink} disabled={data.lines.length >= 30} onClick={() => setData('lines', [...data.lines,
                { description: '', amount_cents: 0, group: 'move-in' },
            ])}>Add item</button>
            <div className="text-sm">Move-in subtotal {money(total - packageSubtotal)}{packageSubtotal > 0 && <p>Welcome Packages subtotal {money(packageSubtotal)}</p>}</div>
        </fieldset> : <fieldset className="space-y-3">
            <legend className="mb-2 font-semibold">Unpaid or partially paid rental weeks</legend>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[680px] text-left text-sm">
                    <thead className="bg-[#eff5f5]"><tr>{['Select', 'Rental period', 'Original Friday due', 'Amount due', 'Received', 'Outstanding', 'Invoice amount (NZD)'].map(label =>
                        <th key={label} className="p-2">{label}</th>)}</tr></thead>
                    <tbody>{weeks.map(row => {
                        const selected = data.weeks.find(week => week.start === row.start);
                        return <tr key={row.start} className="border-b">
                            <td className="p-2"><input type="checkbox" aria-label={`Select week of ${row.start}`} checked={!!selected}
                                disabled={!selected && data.weeks.length >= 30} onChange={event => updateWeek(row, event.target.checked)} /></td>
                            <td className="p-2">{row.start} to {row.end}</td><td className="p-2">{row.original_due}</td>
                            <td className="p-2">{money(row.due_cents)}</td>
                            <td className="p-2">{money(row.received_cents)}{row.received_on && <div className="text-xs">{row.received_on}</div>}</td>
                            <td className="p-2">{money(row.outstanding_cents)}</td>
                            <td className="p-2">{selected && <input className={input} type="number" aria-label={`Invoice amount for ${row.start}`}
                                required min="0.01" max={row.outstanding_cents / 100} step="0.01" value={selected.amount_cents ? selected.amount_cents / 100 : ''}
                                onChange={event => setData('weeks', data.weeks.map(week => week.start === row.start
                                    ? { ...week, amount_cents: toCents(event.target.value) } : week))} />}</td>
                        </tr>;
                    })}</tbody>
                </table>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={data.payment_check_confirmed}
                onChange={event => setData('payment_check_confirmed', event.target.checked)} /> I completed the Monday payment check for the selected weeks.</label>
        </fieldset>}
        <div className="rounded-lg bg-[#e6f2ee] p-4 font-bold">Total amount due {money(total)}</div>
        <dl className="text-sm">
            <dt className="font-semibold">Bank transfer details</dt><dd>{bank.name}</dd><dd>{bank.account}</dd>
        </dl>
        {(previewError || Object.keys(errors).length > 0) && <div role="alert" className="text-sm text-rose-700">{previewError || Object.values(errors).join(' ')}</div>}
        <div className="flex flex-wrap gap-3">
            <button type="button" disabled={!canIssue || previewBusy || processing} className={textLink}
                onClick={showPreview}>{previewBusy ? 'Preparing preview…' : 'Preview PDF'}</button>
            <button type="submit" className={button} disabled={!canIssue || previewBusy || processing}>{processing ? 'Issuing…' : 'Issue and save invoice'}</button>
        </div>
        {preview && <dialog open aria-label="Invoice preview" className="fixed inset-4 z-50 m-auto h-[85vh] w-[min(95vw,52rem)] rounded-xl border bg-white p-3 shadow-xl">
            <div className="flex h-full flex-col gap-2">
                <button className={textLink} type="button" onClick={() => setPreview('')}>Close preview</button>
                <iframe className="min-h-0 flex-1" src={preview} title="Invoice PDF preview" />
            </div>
        </dialog>}
    </form>;
}

export default function RentalInvoices({ kind, title, tenant, tenants, agreements, weeks, history, bank, today, due_date, can_purchase, can_overdue }) {
    const query = tenant ? `?tenant=${tenant.id}` : '';
    const canBuild = tenant && (kind === 'move-in' ? agreements.length > 0 : weeks.length > 0);
    return <div className="mx-auto max-w-6xl space-y-5">
        <Head title={title} />
        <header><h1 className="text-2xl font-bold">{title}</h1>
            <nav aria-label="Invoice types" className="mt-3 flex flex-wrap gap-4">
                {can_purchase && <Link className={textLink} href={`/portal/accommodation/purchase-invoices${query}`}>Invoices &amp; Receipts</Link>}
                {can_purchase && kind !== 'move-in' && <Link className={textLink} href={`${base}/move-in${query}`}>Move-In Invoice</Link>}
                {can_overdue && kind !== 'overdue' && <Link className={textLink} href={`${base}/overdue${query}`}>Overdue Payment Invoice</Link>}
                {kind === 'overdue' && <Link className={textLink} href="/portal/accommodation/rent-utilities">Rent &amp; Utilities</Link>}
            </nav>
        </header>
        <label className="block max-w-md text-sm font-semibold">Tenant
            <select className={input} value={tenant?.id || ''} onChange={event => router.get(`${base}/${kind}`, event.target.value ? { tenant: event.target.value } : {})}>
                <option value="">Choose a tenant</option>{tenants.map(row => <option key={row.id} value={row.id}>{row.name} · #{row.id}</option>)}
            </select>
        </label>
        {canBuild ? <InvoiceEditor key={`${kind}:${tenant.id}:${history.data[0]?.id ?? ''}`} {...{ kind, tenant, agreements, weeks, bank, today }} dueDate={due_date} />
            : tenant && <p className="rounded-lg border bg-white p-4 text-sm">{kind === 'move-in'
                ? 'This tenant has no linked signed agreement with saved costs available to invoice.'
                : 'This tenant has no eligible rental weeks with a saved amount due and an uninvoiced balance.'}</p>}
        <section aria-label="Invoice history" className="space-y-3 rounded-xl border bg-white p-5">
            <h2 className="font-semibold">Invoice history</h2>
            {history.data.length === 0 && <p className="text-sm text-gray-600">No invoices issued yet.</p>}
            {history.data.map(invoice => <article key={invoice.id} className="flex flex-wrap justify-between gap-3 border-t py-3 text-sm">
                <div><p className="break-all font-semibold">{invoice.reference}</p><p>{invoice.bill_to} · {invoice.property}</p>
                    <p>Issued {invoice.issue_date} · Due {invoice.due_date}</p></div>
                <div><p className="font-semibold">{money(invoice.total_cents)}</p><a href={invoice.download} className={textLink}>Download PDF</a></div>
            </article>)}
            {history.last_page > 1 && <nav aria-label="Invoice history pages" className="flex gap-4">
                {history.prev_page_url && <Link className={textLink} href={history.prev_page_url}>Previous</Link>}
                {history.next_page_url && <Link className={textLink} href={history.next_page_url}>Next</Link>}
            </nav>}
        </section>
    </div>;
}
