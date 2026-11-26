import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';

export default function AgreementTenantLink({ agreement, source, version, properties = [], tenants = [] }) {
    const [search, setSearch] = useState('');
    const form = useForm({ version, property_id: String(agreement.property_id || ''), mode: 'existing', tenant_version: tenants.find(t => t.id === agreement.tenant_id)?.version || '', tenant_id: String(agreement.tenant_id || ''), confirmed: false, reason: '' });
    const options = tenants.filter(t => String(t.property_id) === form.data.property_id && `${t.name} #${t.id} ${t.unit || ''}`.toLowerCase().includes(search.toLowerCase()));
    return <div className="mx-auto max-w-3xl space-y-5 p-4">
        <a href="/portal/accommodation/forms/agreements" className="text-sm text-blue-700 underline">Back to agreements</a>
        <h1 className="text-xl font-bold">Link signed agreement to tenant</h1>
        <div className="rounded-xl border bg-white p-4 space-y-2">
            <p className="font-semibold">Agreement #{agreement.id} · {agreement.name}</p>
            <p>Signed: {agreement.signed_at?.slice(0, 10)} · {agreement.property_address}</p>
            <a className="block text-blue-700 underline" href={`/portal/accommodation/forms/agreements/${agreement.id}/download`} target="_blank" rel="noreferrer">Review original signed PDF</a>
            <a className="block text-blue-700 underline" href={source.url} target="_blank" rel="noreferrer">Review pre-tenancy source #{source.id} · {source.name}</a>
            <p className="text-sm text-gray-600">Existing tenants keep their details and status. New records stay pending payment. Linking does not change signatures, send email, confirm payment or approve move-in.</p>
        </div>
        <form className="space-y-4 rounded-xl border bg-white p-4" onSubmit={e => { e.preventDefault(); form.post(`/portal/accommodation/forms/agreements/${agreement.id}/tenant-link`); }}>
            <label className="block">Property
                <select className="mt-1 w-full rounded border p-2" value={form.data.property_id} disabled={!!agreement.property_id} onChange={e => { form.setData('property_id', e.target.value); form.setData('tenant_id', ''); form.setData('confirmed', false); }} required>
                    <option value="">Select verified property</option>
                    {properties.map(p => <option key={p.id} value={p.id}>#{p.id} · {p.label}</option>)}
                </select>
            </label>
            <label className="block">Action
                <select className="mt-1 w-full rounded border p-2" value={form.data.mode} onChange={e => { form.setData('mode', e.target.value); form.setData('confirmed', false); }}>
                    <option value="existing">Link an existing tenant</option>
                    <option value="create">Create missing tenant — pending payment</option>
                </select>
            </label>
            {form.data.mode === 'existing' ? <>
                <label className="block">Find existing tenant<input className="mt-1 w-full rounded border p-2" value={search} onChange={e => setSearch(e.target.value)} /></label>
                <label className="block">Confirmed tenant record<select className="mt-1 w-full rounded border p-2" value={form.data.tenant_id} onChange={e => { form.setData('tenant_id', e.target.value); form.setData('tenant_version', tenants.find(t => String(t.id) === e.target.value)?.version || ''); form.setData('confirmed', false); }} required>
                    <option value="">Select exact record after review</option>
                    {options.map(t => <option key={t.id} value={t.id}>#{t.id} · {t.name} · {t.unit || 'No room label'} · {t.status}</option>)}
                </select></label>
            </> : <p className="rounded bg-amber-50 p-3 text-sm">Check Tenants for existing records first. Creation uses the original signed terms ({agreement.terms?.commence_date || 'missing start'} to {agreement.terms?.end_date || 'missing end'}; rent {agreement.terms?.weekly_rent ?? 'missing'}). Missing or invalid terms require staff review; they are not guessed.</p>}
            <label className="block">Audit reason<textarea className="mt-1 w-full rounded border p-2" value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} maxLength={1000} required /></label>
            <label className="flex gap-2 text-sm"><input type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} />I reviewed the original PDF, all required signatures, pre-tenancy source, property and existing tenant records. This is the correct tenancy; creating a record will not duplicate one.</label>
            {Object.keys(form.errors).length > 0 && <div role="alert" className="text-red-700">{Object.values(form.errors).map((error, i) => <p key={i}>{error}</p>)}</div>}
            <button type="submit" disabled={form.processing || !form.data.confirmed || !form.data.property_id || (form.data.mode === 'existing' && !form.data.tenant_id)} className="rounded bg-gray-900 px-4 py-2 font-semibold text-white disabled:opacity-50">{form.processing ? 'Linking…' : 'Confirm tenant link'}</button>
        </form>
    </div>;
}
