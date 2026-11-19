import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';

export default function CaseHelper({ case: record, helper, eligibleHelpers = [] }) {
    const [selected, setSelected] = useState(String(helper?.id || ''));
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const saving = useRef(false);
    useEffect(() => { setSelected(String(helper?.id || '')); setConfirmed(false); setError(''); }, [record.id, helper?.id]);
    const changed = selected !== String(helper?.id || '');
    const save = event => {
        event.preventDefault();
        if (!confirmed || !changed || saving.current) return;
        saving.current = true; setBusy(true); setError('');
        router.post(`/portal/immigration/cases/${record.id}/helper`, { helper_id: selected ? Number(selected) : null,
            expected_helper_id: helper?.id || null, confirmed: true }, {
            preserveScroll: true,
            onSuccess: () => setConfirmed(false),
            onError: errors => setError(Object.values(errors).flat().join(' ') || 'Could not update helper access.'),
            onFinish: () => { saving.current = false; setBusy(false); },
        });
    };
    return <main className="mx-auto max-w-3xl space-y-5 p-6">
        <Head title="Case helper access" />
        <Link href={`/portal/immigration/cases/${record.id}/profile`} className="text-sm text-teal-800 underline">Back to case</Link>
        <header><h1 className="text-2xl font-bold">Case helper access</h1><p className="mt-1 text-sm text-gray-600">{record.name} · {record.reference}</p></header>
        <section className="rounded-xl border bg-white p-5"><h2 className="font-semibold">Case-specific helper</h2><p className="mt-2 text-sm text-gray-600">The current owner and adviser remain responsible. An assigned helper can:</p>
            <ul className="my-3 list-disc space-y-1 pl-5 text-sm"><li>View this assigned case summary.</li><li>View and download visible, non-financial primary-applicant checklist and request uploads. Unmatched files stay with staff.</li><li>Upload evidence to visible required checklist slots or active document requests.</li></ul>
            <p className="text-sm text-gray-600">This access excludes invoices, payments, internal notes, stage changes, signatures and approvals. Existing referral access is separate.</p>
            <form onSubmit={save} className="mt-5 space-y-4">
                <label className="block text-sm font-medium">Case helper<select aria-label="Case helper" value={selected} disabled={busy} onChange={event => { setSelected(event.target.value); setConfirmed(false); }} className="mt-1 block w-full rounded border p-2"><option value="">No helper</option>{helper && !eligibleHelpers.some(user => user.id === helper.id) && <option value={helper.id} disabled>{helper.name} (no longer eligible)</option>}{eligibleHelpers.map(user => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label>
                {!eligibleHelpers.length && <p className="text-sm text-gray-600">No eligible Agents. The assigned-case module must be explicitly enabled before a helper can be selected.</p>}
                <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={confirmed} disabled={busy} onChange={event => setConfirmed(event.target.checked)} />{selected ? 'I confirm the case-specific helper access described above.' : 'I confirm removal of helper access for this case.'}</label>
                <button type="submit" disabled={!confirmed || !changed || busy} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Saving…' : 'Update helper access'}</button>
                {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
            </form>
        </section>
    </main>;
}
