import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';

const initialForm = (connector) => ({
    api_key: '', remove_saved_key: false,
    pathy_enabled: connector.pathy_enabled, staff_enabled: connector.staff_enabled,
    pathy_model: connector.pathy_model, staff_model: connector.staff_model,
    pathy_instructions: connector.pathy_instructions, staff_instructions: connector.staff_instructions,
});
const inputStyle = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#436235] disabled:bg-gray-100';

export default function ClaudeConnectors({ connector }) {
    const [connection, setConnection] = useState(connector);
    const [form, setForm] = useState(() => initialForm(connector));
    const [saved, setSaved] = useState(() => initialForm(connector));
    const [busy, setBusy] = useState('');
    const [errors, setErrors] = useState({});
    const [notice, setNotice] = useState(null);
    const dirty = JSON.stringify(form) !== JSON.stringify(saved);
    const set = (key, value) => setForm(current => ({ ...current, [key]: value }));
    const errorFor = (key) => errors[key] && <p id={`${key}-error`} className="mt-1 text-sm text-red-700">{errors[key][0]}</p>;

    async function save(event) {
        event.preventDefault();
        setBusy('save'); setErrors({}); setNotice(null);
        try {
            const { data } = await axios.post('/admin/ai-connectors', form);
            setConnection(data.connector);
            const next = initialForm(data.connector);
            setForm(next); setSaved(next);
            setNotice({ ok: true, text: 'Connector saved. Use Test connection to verify your API key and model.' });
        } catch (error) {
            setErrors(error.response?.data?.errors || {});
            setNotice({ ok: false, text: error.response?.status === 422 ? 'Check the highlighted fields and save again.' : 'Could not save. Refresh the page and try again.' });
        } finally { setBusy(''); }
    }

    async function test(surface) {
        setBusy(surface); setNotice(null);
        try {
            const { data } = await axios.post('/admin/ai-connectors/test', { surface });
            setNotice({ ok: true, text: `${surface === 'pathy' ? 'Pathy' : 'EP Assistant'}: ${data.message}` });
        } catch (error) {
            setNotice({ ok: false, text: error.response?.data?.message || 'Connection failed. Check the API key, model access and Anthropic billing.' });
        } finally { setBusy(''); }
    }

    return <>
        <Head title="AI Connectors" />
        <div className="mx-auto max-w-4xl p-4 sm:p-8">
            <header className="mb-8">
                <h1 className="text-2xl font-bold text-gray-900">AI Connectors</h1><Link href="/admin/marketing-connectors" className="mt-3 inline-block text-sm font-semibold text-[#436235]">GHL marketing connection</Link>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600">Connect Pathy and EP Assistant directly to Claude. Manage the API key and give each assistant its own instructions.</p>
            </header>
            {!connection.global_enabled && <p role="status" className="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">The global AI switch is off. You can save and test this connector, but both assistants will stay off until global AI is enabled.</p>}
            {notice && <div role={notice.ok ? 'status' : 'alert'} className={`mb-6 rounded-lg px-4 py-3 text-sm ${notice.ok ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-900'}`}>{notice.text}</div>}
            <form onSubmit={save} className="space-y-8">
                <section aria-labelledby="connection-title" className="border-b border-gray-200 pb-8">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="connection-title" className="text-lg font-semibold text-gray-900">Claude API connection</h2>
                        <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">{connection.configured ? 'API key configured' : 'API key needed'}</span>
                    </div>
                    <p className="mt-2 text-sm leading-6 text-gray-600">The API key is stored encrypted on Luvep’s server and is never displayed here. Saving makes no API request. Testing checks the saved key and selected model with Anthropic without generating a chat response.</p>
                    <div className="mt-5">
                        <label htmlFor="claude-api-key" className="mb-2 block text-sm font-semibold text-gray-900">{connection.configured ? 'Replace API key' : 'Anthropic API key'}</label>
                        <input id="claude-api-key" type="password" autoComplete="new-password" spellCheck={false} value={form.api_key} onChange={e => set('api_key', e.target.value)} placeholder={connection.configured ? 'Leave blank to keep your saved key' : 'Paste your Anthropic API key'} className={inputStyle} disabled={!!busy} aria-invalid={!!errors.api_key} aria-describedby={errors.api_key ? 'api_key-error' : undefined} />
                        {errorFor('api_key')}
                        {connection.key_source === 'environment' && <p className="mt-2 text-sm text-gray-600">Using the API key configured on the server. Saving a new key here replaces it for these assistants.</p>}
                        {connection.key_source === 'saved' && <label className="mt-3 flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" checked={form.remove_saved_key} onChange={e => set('remove_saved_key', e.target.checked)} disabled={!!busy} className="mt-0.5" />Remove the saved key (a server environment key may still apply)</label>}
                    </div>
                </section>
                {[
                    ['pathy', 'Pathy', 'Public website assistant. Sends visitor messages, recent conversation history and relevant published program facts to Anthropic. It has no access to private client records.'],
                    ['staff', 'EP Assistant', 'Staff assistant. Sends staff messages and the record context they are authorized to view to Anthropic. Existing permissions and read-only restrictions stay in place.'],
                ].map(([surface, title, description]) => <section key={surface} aria-labelledby={`${surface}-title`} className="border-b border-gray-200 pb-8">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <h2 id={`${surface}-title`} className="text-lg font-semibold text-gray-900">{title}</h2>
                        <label className="flex items-center gap-2 text-sm font-medium text-gray-900"><input type="checkbox" checked={form[`${surface}_enabled`]} onChange={e => set(`${surface}_enabled`, e.target.checked)} disabled={!!busy} />Enable {title}</label>
                    </div>
                    <p className="mt-2 text-sm leading-6 text-gray-600">{description}</p>
                    <div className="mt-5 grid gap-5">
                        <div><label htmlFor={`${surface}-model`} className="mb-2 block text-sm font-semibold text-gray-900">Claude model ID</label>
                            <input id={`${surface}-model`} value={form[`${surface}_model`]} onChange={e => set(`${surface}_model`, e.target.value)} className={inputStyle} disabled={!!busy} required aria-invalid={!!errors[`${surface}_model`]} />
                            {errorFor(`${surface}_model`)}<p className="mt-1 text-sm text-gray-600">Use a model available to your Anthropic API account.</p></div>
                        <div><label htmlFor={`${surface}-instructions`} className="mb-2 block text-sm font-semibold text-gray-900">{title} instructions</label>
                            <textarea id={`${surface}-instructions`} rows={5} maxLength={12000} required value={form[`${surface}_instructions`]} onChange={e => set(`${surface}_instructions`, e.target.value)} className={inputStyle} disabled={!!busy} aria-invalid={!!errors[`${surface}_instructions`]} />
                            {errorFor(`${surface}_instructions`)}<p className="mt-1 text-sm text-gray-600">Set tone and guidance here. Record permissions and mandatory safety rules always apply.</p></div>
                    </div>
                    <button type="button" onClick={() => test(surface)} disabled={!!busy || dirty || !connection.configured} className="mt-5 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-900 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#436235] disabled:cursor-not-allowed disabled:opacity-50">{busy === surface ? 'Testing…' : `Test ${title} connection`}</button>
                </section>)}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <p className="text-sm text-gray-600">{dirty ? 'You have unsaved changes. Save before testing.' : 'Both chats use Claude directly. Social/Marketing integrations are unchanged.'}</p>
                    <button disabled={!!busy || !dirty} className="shrink-0 rounded-lg bg-[#436235] px-5 py-3 text-sm font-semibold text-white hover:bg-[#344c29] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#436235] disabled:cursor-not-allowed disabled:opacity-50">{busy === 'save' ? 'Saving…' : 'Save connector'}</button>
                </div>
            </form>
        </div>
    </>;
}
