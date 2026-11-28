import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

const portal = '/portal/accommodation';
const root = portal + '/move-out';
const field = 'w-full rounded-lg border-gray-300 text-sm';
const primary = 'rounded-lg bg-[#1C5685] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50';
const actionLabels = {
    check_in: 'Send renewal or move-out check-in',
    renew: 'Record renewal request and email tenancy form',
    confirm_move: 'Confirm move-out and send handover checklist',
    complete_renewal: 'Close renewed tenancy record',
    remind: 'Send one-week reminder',
    inspect: 'Record final room inspection',
    feedback: 'Send inspection feedback',
    resolve: 'Record tenant response and resolution',
    statement: 'Send itemised bond statement',
    accept: 'Record tenant confirmation',
    refund: 'Record bond refund',
    close_move: 'Send final email and close record',
};
const eventLabels = {
    started: 'Record started', assignment: 'Assignment changed', agreement_linked: 'Agreement linked', automation: 'Scheduled email setting changed',
    check_in: 'Check-in email sent', renew: 'Renewal requested and form sent', confirm_move: 'Move-out confirmed and checklist sent',
    complete_renewal: 'Renewal completed', remind: 'Move-out reminder sent', inspect: 'Final inspection recorded',
    feedback: 'Inspection feedback sent', resolve: 'Inspection items resolved', statement: 'Bond refund statement sent',
    accept: 'Tenant confirmed statement', refund: 'Bond refund recorded', close_move: 'Final email sent and record closed',
};
const sendsEmail = ['check_in', 'renew', 'confirm_move', 'remind', 'feedback', 'statement', 'close_move'];
const nzd = (cents) => new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', currencyDisplay: 'code' }).format(cents / 100);

function Field({ label, children }) {
    return <label className="block space-y-1"><span className="block text-sm font-medium">{label}</span>{children}</label>;
}

function ActionForm({ action, record, events, onDone }) {
    const form = useForm({ action, notes: '', move_out_on: '', reference: '', files: [], work_needed: false, return_at: '', proposed_charge: '', response: '', resolution: '', bond: record.bond ?? '', deductions: [] });
    const { data, setData } = form;
    const refund = events.find((event) => event.action === 'statement')?.details.refund_cents;
    const needsDecisionNote = record.stage !== 'check_in' || !events.some((event) => event.action === 'check_in');
    const text = (name, label, required = true, type = 'text') => <Field label={label}>
        <input className={field} type={type} required={required} value={data[name]} onChange={(event) => setData(name, event.target.value)} />
    </Field>;
    const notes = (name, label, required = true) => <Field label={label}>
        <textarea className={field} rows={3} maxLength={4000} required={required} value={data[name]} onChange={(event) => setData(name, event.target.value)} />
    </Field>;
    const files = (label, accept, required, multiple = false) => <Field label={label}>
        <input className="w-full text-sm" type="file" required={required} multiple={multiple} accept={accept} onChange={(event) => setData('files', Array.from(event.target.files))} />
    </Field>;
    const setDeduction = (index, key, value) => setData('deductions', data.deductions.map((line, position) => position === index ? { ...line, [key]: value } : line));
    let fields;
    switch (action) {
        case 'renew':
            fields = notes('notes', 'How the renewal request was received', needsDecisionNote);
            break;
        case 'confirm_move':
            fields = <>{text('move_out_on', 'Confirmed move-out date', true, 'date')}{notes('notes', 'How the decision was received', needsDecisionNote)}</>;
            break;
        case 'complete_renewal':
            fields = <><p>Save the new term using <Link className="text-[#1C5685] underline" href={portal + '/tenants/' + record.tenantId}>Renew on the tenant record</Link>, then attach the fully signed renewal.</p>
                {text('reference', 'Renewal agreement reference')}{files('Fully signed renewal agreement (PDF)', '.pdf', true)}</>;
            break;
        case 'inspect':
            fields = <><p>Mark the tenant as vacated after they have fully moved out.</p>
                {notes('notes', 'Condition and items needing attention')}
                {record.stage === 'decision' && text('reference', 'Reason for skipping the reminder')}
                {files('Inspection photos (up to five)', '.jpg,.jpeg,.png,.webp', false, true)}</>;
            break;
        case 'feedback':
            fields = <>{notes('notes', 'Feedback to email to the tenant')}
                <label className="flex gap-2"><input type="checkbox" checked={data.work_needed} onChange={(event) => setData('work_needed', event.target.checked)} /> Work is needed</label>
                {data.work_needed && <>{text('return_at', 'Proposed return time (New Zealand time)', false, 'datetime-local')}
                    {!data.return_at && text('proposed_charge', 'Proposed Exalt charge in NZD', false)}</>}</>;
            break;
        case 'resolve':
            fields = <>{notes('response', 'Tenant response')}{notes('resolution', 'How the items were resolved')}</>;
            break;
        case 'statement': {
            const total = data.deductions.reduce((sum, line) => sum + Math.round(Number(line.amount || 0) * 100), 0);
            const balance = Math.round(Number(data.bond) * 100) - total;
            fields = <>{text('bond', 'Original bond in NZD')}
                {data.deductions.map((line, index) => <div key={index} className="flex flex-wrap items-end gap-2">
                    <Field label="Deduction item"><input className={field} required maxLength={200} value={line.item} onChange={(event) => setDeduction(index, 'item', event.target.value)} /></Field>
                    <Field label="Amount in NZD"><input className={field} required inputMode="decimal" value={line.amount} onChange={(event) => setDeduction(index, 'amount', event.target.value)} /></Field>
                    <button type="button" className="rounded border px-3 py-2 text-sm" onClick={() => setData('deductions', data.deductions.filter((_, position) => position !== index))}>Remove item</button>
                </div>)}
                {data.deductions.length < 20 && <button type="button" className="rounded border px-3 py-2 text-sm" onClick={() => setData('deductions', [...data.deductions, { item: '', amount: '' }])}>Add deduction</button>}
                {data.bond !== '' && Number.isFinite(balance) && <p className="font-semibold">Refund amount {nzd(balance)}</p>}</>;
            break;
        }
        case 'accept':
            fields = notes('response', 'Tenant confirmation and where it was received');
            break;
        case 'refund':
            fields = <><p>Confirmed refund amount {nzd(refund)}</p>
                <p>Record the bank transfer after it is complete. For a zero balance, record the settlement reference.</p>
                {text('reference', refund > 0 ? 'Bank transfer reference' : 'Settlement reference')}
                {files('Proof of refund (PDF or image)', '.pdf,.jpg,.jpeg,.png', refund > 0)}</>;
            break;
    }

    return <form className="space-y-4 rounded-lg bg-gray-50 p-4" onSubmit={(event) => {
        event.preventDefault();
        form.post(root + '/' + record.id + '/advance', { preserveScroll: true, onSuccess: onDone });
    }}>
        <h3 className="font-semibold">{actionLabels[action]}</h3>
        {sendsEmail.includes(action) && <p className="text-sm">{record.tenantEmail ? 'Email recipient ' + record.tenantEmail : 'Add a valid email to the tenant record before sending.'}</p>}
        {fields}
        {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-700">{message}</p>)}
        <div className="flex gap-3">
            <button className={primary} disabled={form.processing || (sendsEmail.includes(action) && !record.tenantEmail)}>{sendsEmail.includes(action) ? 'Send email and save' : 'Save'}</button>
            <button type="button" className="rounded border bg-white px-3 py-2 text-sm" onClick={onDone}>Cancel</button>
        </div>
    </form>;
}

function History({ events }) {
    return <ol className="divide-y">
        {[...events].reverse().map(({ id, action, actor, at, details }) => <li key={id} className="space-y-2 py-3 text-sm">
            <p className="font-semibold">{eventLabels[action]} <span className="font-normal text-gray-600">· {actor} · {at} NZ time</span></p>
            {action === 'assignment' && <p>{details.from_owner || 'Unassigned'} → {details.to_owner || 'Unassigned'}</p>}
            {details.agreement_id && <p>Agreement #{details.agreement_id}</p>}
            {action === 'automation' && <p>Scheduled emails {details.enabled ? 'enabled' : 'paused'} for this record.</p>}
            {details.previous_move_out_on && <p>Previous move-out date {details.previous_move_out_on}</p>}
            {details.move_out_on && <p>Confirmed move-out on {details.move_out_on}</p>}
            {details.inspection_date && <p>Inspected on {details.inspection_date}</p>}
            {details.renewed_through && <p>Renewed through {details.renewed_through}</p>}
            {details.notes && !details.email && <p className="whitespace-pre-wrap">{details.notes}</p>}
            {details.notes && ['renew', 'confirm_move'].includes(action) && <p className="whitespace-pre-wrap">{details.notes}</p>}
            {details.reference && <p>Reference or reason · {details.reference}</p>}
            {details.response && <p className="whitespace-pre-wrap">Tenant response · {details.response}</p>}
            {details.resolution && <p className="whitespace-pre-wrap">Resolution · {details.resolution}</p>}
            {details.refund_cents != null && <p>Refund {nzd(details.refund_cents)}</p>}
            {details.email && <details>
                <summary className="cursor-pointer font-medium text-[#1C5685]">{details.email.subject} · {details.email.to}</summary>
                <p className="mt-2 whitespace-pre-wrap">{details.email.body}</p>
            </details>}
            {details.files.map((file, index) => <a key={index} className="mr-3 text-[#1C5685] underline" href={file.url}>{file.name}</a>)}
        </li>)}
    </ol>;
}

export default function MoveOutShow({ record, stages, events, owners, agreements, actions, today, pipelineAutomation }) {
    const automation = useForm({});
    const [selected, select] = useState(null);
    const owner = useForm({ assignee_id: record.assigneeId ?? '' });
    const agreement = useForm({ agreement_id: record.agreementId ?? '' });
    const base = root + '/' + record.id;
    const checkInDue = today >= record.checkInOn && today <= record.ends;
    const reminderDue = today >= record.reminderOn && today <= record.moveOut;

    return <>
        <Head title={'Move-Out · ' + record.tenant} />
        <main className="mx-auto max-w-4xl space-y-5 p-4 sm:p-6">
            <Link className="text-[#1C5685]" href={root}>Back to Move-Out Pipeline</Link>
            <section className="space-y-3 rounded-xl border bg-white p-5">
                <h1 className="text-2xl font-bold">{record.tenant}</h1>
                <p>{stages[record.stage]}{record.closed ? ' · Closed' : ''}</p>
                <div className="flex flex-wrap gap-4 text-sm text-[#1C5685]">
                    {record.tenantId && !record.tenantArchived && <Link href={portal + '/tenants/' + record.tenantId}>Tenant record</Link>}
                    {record.propertyId ? <Link href={portal + '/properties/' + record.propertyId}>{record.property}</Link> : <span>{record.property}</span>}
                    {record.agreementId && <a href={portal + '/forms/agreements/' + record.agreementId + '/download?inline=1'} target="_blank" rel="noreferrer">Agreement #{record.agreementId}</a>}
                </div>
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="font-semibold">Agreement ends</dt><dd>{record.ends}</dd></div>
                    <div><dt className="font-semibold">Confirmed move-out</dt><dd>{record.moveOut || 'Awaiting confirmation'}</dd></div>
                    {actions.includes('check_in') && <div><dt className="font-semibold">Check-in due</dt><dd>{record.checkInOn} (NZ time)</dd></div>}
                    {actions.includes('remind') && <div><dt className="font-semibold">Reminder due</dt><dd>{record.reminderOn} (NZ time)</dd></div>}
                </dl>
                {!record.closed && <div className="flex flex-wrap items-center gap-3 text-sm">
                    <span>Scheduled emails {record.automaticEmail && pipelineAutomation ? 'on' : 'paused'} for this record
                        {!pipelineAutomation && ' · Pipeline scheduling is off'}</span>
                    <button type="button" className="rounded-lg border px-3 py-2" disabled={automation.processing}
                        onClick={() => automation.transform(() => ({ enabled: !record.automaticEmail })).patch(base + '/automation', { preserveScroll: true })}>
                        {record.automaticEmail ? 'Pause for this record' : 'Enable for this record'}
                    </button>
                </div>}
                {!record.closed && <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
                    event.preventDefault();
                    owner.transform((data) => ({ assignee_id: data.assignee_id || null })).patch(base + '/assignee', { preserveScroll: true });
                }}>
                    <Field label="Assigned to"><select className={field} value={owner.data.assignee_id} onChange={(event) => owner.setData('assignee_id', event.target.value)}>
                        <option value="">Unassigned</option>
                        {record.assigneeId && !owners.some(({ id }) => id === record.assigneeId) && <option value={record.assigneeId} disabled>Former assignee</option>}
                        {owners.map(({ id, name }) => <option key={name} value={id ?? name} disabled={!id}>{name}{!id && ' (account unavailable)'}</option>)}
                    </select></Field>
                    <button className={primary} disabled={owner.processing || String(owner.data.assignee_id) === String(record.assigneeId ?? '')}>Save assignment</button>
                    {owner.errors.assignee_id && <p role="alert" className="text-sm text-red-700">{owner.errors.assignee_id}</p>}
                </form>}
                {!record.tenantId && <p className="text-sm text-gray-600">The original tenant record was removed. Its move-out history is still available.</p>}
                {!record.agreementId && agreements.length === 0 && <p className="text-sm text-gray-600">No signed agreement is linked to this tenant, property and term. Review the original record in <Link className="underline" href={portal + '/forms/agreements'}>Tenant agreements</Link>.</p>}
                {!record.closed && agreements.length > 0 && <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
                    event.preventDefault();
                    agreement.patch(base + '/agreement', { preserveScroll: true });
                }}>
                    <Field label="Agreement"><select className={field} required value={agreement.data.agreement_id} onChange={(event) => agreement.setData('agreement_id', event.target.value)}>
                        <option value="">Select a signed agreement</option>
                        {agreements.map(({ id, name }) => <option key={id} value={id}>#{id} · {name}</option>)}
                    </select></Field>
                    <button className={primary} disabled={agreement.processing || String(agreement.data.agreement_id) === String(record.agreementId ?? '')}>Link agreement</button>
                    {agreement.errors.agreement_id && <p role="alert" className="text-sm text-red-700">{agreement.errors.agreement_id}</p>}
                </form>}
            </section>
            {actions.length > 0 && <section className="space-y-3 rounded-xl border bg-white p-5">
                <h2 className="text-lg font-semibold">Available actions</h2>
                <div className="flex flex-wrap gap-2">{actions.map((action) => <button className="rounded-lg border px-3 py-2 text-sm text-[#1C5685] disabled:text-gray-500" key={action} type="button"
                    disabled={(action === 'check_in' && !checkInDue) || (action === 'remind' && !reminderDue)}
                    onClick={() => select(action)}>{actionLabels[action]}</button>)}</div>
                {actions.includes('feedback') && <p className="text-sm text-gray-600">Send feedback on the inspection day.</p>}
                {selected && actions.includes(selected) && <ActionForm key={selected} action={selected} record={record} events={events} onDone={() => select(null)} />}
            </section>}
            <section className="rounded-xl border bg-white p-5">
                <h2 className="text-lg font-semibold">Assignment and stage history</h2>
                <History events={events} />
            </section>
        </main>
    </>;
}
