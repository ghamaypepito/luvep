import { Head, Link, useForm } from '@inertiajs/react';
import AccommodationLayout from '../../../components/layout/AccommodationLayout';

const root = '/portal/accommodation/move-out';

export default function MoveOutStart({ tenants }) {
    const form = useForm({ tenant_id: '' });
    return <AccommodationLayout>
        <Head title="Start move-out record" />
        <main className="mx-auto max-w-2xl space-y-4 p-6">
            <Link className="text-[#1C5685]" href={root}>Back to Move-Out Pipeline</Link>
            <form className="space-y-4 rounded-xl border bg-white p-5" onSubmit={(event) => { event.preventDefault(); form.post(root); }}>
                <h1 className="text-xl font-bold">Start a renewal or move-out record</h1>
                {tenants.length ? <>
                    <label className="block space-y-1">
                        <span className="block font-medium">Current tenant and agreement term</span>
                        <select required value={form.data.tenant_id} onChange={(event) => form.setData('tenant_id', event.target.value)} className="w-full rounded-lg border-gray-300">
                            <option value="">Select a tenant</option>
                            {tenants.map((tenant) => <option key={tenant.id} value={tenant.id}>{tenant.name} · {tenant.property} · ends {tenant.ends}</option>)}
                        </select>
                    </label>
                    {form.errors.tenant_id && <p role="alert" className="text-sm text-red-700">{form.errors.tenant_id}</p>}
                    <button disabled={form.processing} className="rounded-lg bg-[#1C5685] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Start record</button>
                </> : <p className="text-gray-600">There are no current tenancies with an end date available to add.</p>}
            </form>
        </main>
    </AccommodationLayout>;
}
