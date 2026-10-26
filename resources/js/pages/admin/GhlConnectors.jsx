import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';

const field = 'w-full rounded-lg border border-gray-300 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#436235]';
export default function GhlConnectors({ connection: initial }) {
    const [connection, setConnection] = useState(initial);
    const [token, setToken] = useState('');
    const [remove, setRemove] = useState(false);
    const [busy, setBusy] = useState('');
    const [message, setMessage] = useState(null);
    const [accounts, setAccounts] = useState([]);
    const [errors, setErrors] = useState({});
    const dirty = token !== '' || remove;
    async function save(event) {
        event.preventDefault(); setBusy('save'); setErrors({}); setMessage(null);
        try {
            const { data } = await axios.post('/admin/marketing-connectors', { ghl_api_token: token, remove_saved_token: remove });
            setConnection(data.connection); setToken(''); setRemove(false); setAccounts([]);
            setMessage({ ok: true, text: data.message });
        } catch (error) {
            setErrors(error.response?.data?.errors || {});
            setMessage({ ok: false, text: 'Could not save. Check the credential field and try again.' });
        } finally { setBusy(''); }
    }
    async function test() {
        setBusy('test'); setMessage(null);
        try {
            const { data } = await axios.post('/admin/marketing-connectors/test');
            setConnection(data.connection); setAccounts(data.accounts || []);
            setMessage({ ok: true, text: data.message });
        } catch (error) {
            if (error.response?.data?.connection) setConnection(error.response.data.connection);
            setAccounts([]);
            setMessage({ ok: false, text: error.response?.data?.message || 'Could not reach GHL. Try again.' });
        } finally { setBusy(''); }
    }
    return <>
        <Head title="GHL Marketing Connection" />
        <div className="mx-auto max-w-3xl p-4 sm:p-8">
            <Link href="/admin/ai-connectors" className="text-sm font-semibold text-[#436235]">AI Connectors</Link>
            <h1 className="mt-5 text-2xl font-bold text-gray-900">GHL marketing connection</h1>
            <p className="mt-3 text-sm leading-6 text-gray-600">Connect Luvep to the epathways Social Planner. This setup saves your credential securely and verifies account access.</p>
            <div className="mt-6 rounded-lg bg-[#edf2e9] p-5 text-sm text-[#344c29]">
                <p className="font-bold">epathways</p><p className="mt-2 break-all">Location: {connection.location_id}</p>
                <p className="mt-2">{connection.verified ? `Account access verified · ${connection.account_count} social accounts returned` : connection.configured ? 'Credential saved · account access has not been verified' : 'EP-authorized credential needed'}</p>
                {connection.verified_at && <p className="mt-2">Last verified: {new Date(connection.verified_at).toLocaleString()}</p>}
            </div>
            <p className="mt-5 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">Publishing, scheduled-post sync and campaign analytics are not enabled by this connection check. Existing Social services stay in place. The new publishing workflow must enforce approved versions before it can send posts to GHL.</p>
            {message && <div role={message.ok ? 'status' : 'alert'} className={`mt-5 rounded-lg p-4 text-sm leading-6 ${message.ok ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-900'}`}>{message.text}</div>}
            <form onSubmit={save} className="mt-7">
                <label htmlFor="ghl-token" className="mb-2 block text-sm font-bold text-gray-900">{connection.configured ? 'Replace EP GHL access token' : 'EP GHL access token'}</label>
                <input id="ghl-token" type="password" autoComplete="new-password" spellCheck={false} value={token} onChange={event => setToken(event.target.value)} disabled={!!busy} className={field} placeholder={connection.configured ? 'Leave blank to keep the saved token' : 'Paste an epathways-authorized token'} aria-invalid={!!errors.ghl_api_token} aria-describedby="ghl-token-help" />
                {errors.ghl_api_token && <p role="alert" className="mt-2 text-sm text-red-700">{errors.ghl_api_token[0]}</p>}
                <p id="ghl-token-help" className="mt-3 text-sm leading-6 text-gray-600">The token is encrypted on Luvep’s server and never displayed here. Saving does not contact GHL. Verify account access makes a read-only request to EP’s location.</p>
                <p className="mt-3 text-sm leading-6 text-gray-600">Use a sub-account credential with Social Planner account-read access. If that scope is unavailable in Private Integrations, use an authorized OAuth installation. Do not use an ARCHUB credential.</p>
                {connection.configured && <label className="mt-4 flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" checked={remove} onChange={event => setRemove(event.target.checked)} disabled={!!busy} className="mt-1" />Remove the saved credential and clear verification</label>}
                <div className="mt-6 flex flex-wrap gap-3">
                    <button disabled={!!busy || !dirty} className="rounded-lg bg-[#436235] px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{busy === 'save' ? 'Saving…' : 'Save credential'}</button>
                    <button type="button" onClick={test} disabled={!!busy || dirty || !connection.configured} className="rounded-lg border border-gray-300 px-5 py-3 text-sm font-bold text-gray-900 disabled:opacity-50">{busy === 'test' ? 'Verifying…' : 'Verify EP account access'}</button>
                </div>
            </form>
            {accounts.length > 0 && <section className="mt-8"><h2 className="text-lg font-bold text-gray-900">Verified social accounts</h2><ul className="mt-4 space-y-3">{accounts.map(account => <li key={account.id} className="flex flex-wrap justify-between gap-3 rounded-lg bg-white p-4 text-sm"><span className="font-semibold">{account.name}</span><span className="text-gray-600">{account.platform} · {account.active ? 'Active' : 'Inactive'}</span></li>)}</ul></section>}
        </div>
    </>;
}
