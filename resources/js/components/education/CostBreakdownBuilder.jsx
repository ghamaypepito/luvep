import React, { useEffect, useState } from 'react';
import axios from 'axios';

const API = '/portal/education/cost-breakdowns';
const emptyRow = () => ({ description: '', amount: '' });
const errorText = (error) => Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || 'Could not complete this request. Please retry.';
const money = (value) => Number(value).toLocaleString('en-NZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CostBreakdownBuilder() {
    const [search, setSearch] = useState('');
    const [clients, setClients] = useState([]);
    const [documents, setDocuments] = useState([]);
    const [client, setClient] = useState(null);
    const [country, setCountry] = useState('');
    const [category, setCategory] = useState('');
    const [family, setFamily] = useState('single');
    const [source, setSource] = useState('NZD');
    const [target, setTarget] = useState('');
    const [rows, setRows] = useState([emptyRow()]);
    const [quote, setQuote] = useState(null);
    const [rateError, setRateError] = useState('');
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [preview, setPreview] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [rateLoading, setRateLoading] = useState(false);
    const [refresh, setRefresh] = useState(0);
    const [rateRefresh, setRateRefresh] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        const timer = setTimeout(() => {
            axios.get(API, { params: { q: search }, signal: controller.signal }).then(({ data }) => {
                if (!controller.signal.aborted) { setClients(data.clients); setDocuments(data.documents); setError(''); }
            }).catch((e) => { if (!controller.signal.aborted) setError(errorText(e)); })
                .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 250);
        return () => { controller.abort(); clearTimeout(timer); };
    }, [search, refresh]);

    useEffect(() => {
        const controller = new AbortController();
        setQuote(null); setRateError(''); setRateLoading(false);
        if (!client || !/^[A-Z]{3}$/.test(source) || !/^[A-Z]{3}$/.test(target)) return () => controller.abort();
        setRateLoading(true);
        const timer = setTimeout(() => {
            axios.get(`${API}/rates`, { params: { source, target }, signal: controller.signal }).then(({ data }) => {
                if (!controller.signal.aborted) setQuote(data);
            }).catch((e) => { if (!controller.signal.aborted) setRateError(errorText(e)); })
                .finally(() => { if (!controller.signal.aborted) setRateLoading(false); });
        }, 250);
        return () => { controller.abort(); clearTimeout(timer); };
    }, [client, source, target, rateRefresh]);

    useEffect(() => { setPreview(''); setMessage(''); }, [client, country, category, family, source, target, rows, quote]);
    const selectClient = (id) => {
        const selected = clients.find((row) => String(row.id) === id);
        setClient(selected || null); setCountry(selected?.country || ''); setCategory(selected?.category || '');
        setTarget(selected?.currency || ''); setSource(selected?.category === 'philippines' ? 'PHP' : 'NZD');
        setRows([emptyRow()]); setFamily('single'); setQuote(null); setPreview(''); setError('');
    };
    const updateRow = (index, field, value) => setRows((old) => old.map((row, i) => i === index ? { ...row, [field]: value } : row));
    // A quote is usable only for the currencies currently displayed, including while a new request is pending.
    const currentQuote = quote?.source_currency === source && quote?.target_currency === target ? quote : null;
    const converted = (row) => {
        if (!currentQuote || row.amount === '' || !Number.isFinite(Number(row.amount))) return null;
        const nzd = Number(row.amount) / currentQuote.rates[source].rate;
        return { nzd: Math.round((nzd + Number.EPSILON) * 100) / 100, local: Math.round((nzd * currentQuote.rates[target].rate + Number.EPSILON) * 100) / 100 };
    };
    const total = rows.reduce((sum, row) => { const value = converted(row); return { amount: sum.amount + (Number(row.amount) || 0), nzd: sum.nzd + (value?.nzd || 0), local: sum.local + (value?.local || 0) }; }, { amount: 0, nzd: 0, local: 0 });
    const validRows = rows.every((row) => row.description.trim() && /^\d+(\.\d{1,2})?$/.test(String(row.amount)) && Number(row.amount) <= 99999999.99);
    const canGenerate = client && country.trim() && category && currentQuote && validRows && !busy && !rateLoading;
    const generate = async (action) => {
        if (!canGenerate) return;
        setBusy(true); setError(''); setMessage('');
        try {
            const response = await axios.post(API, { lead_id: client.id, country, category, family, source_currency: source, target_currency: target, rate_token: currentQuote.token, rows, action }, { responseType: action === 'download' ? 'blob' : 'json' });
            if (action === 'preview') setPreview(response.data.html);
            if (action === 'save') { setMessage('New cost breakdown saved. Existing agreements and fees were not changed.'); setRefresh((old) => old + 1); }
            if (action === 'download') {
                const url = URL.createObjectURL(response.data); const link = document.createElement('a');
                link.href = url; link.download = 'Education-Cost-Breakdown.pdf'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
            }
        } catch (e) {
            if (e.response?.data instanceof Blob) { try { e.response.data = JSON.parse(await e.response.data.text()); } catch { /* use default error */ } }
            setError(errorText(e));
        } finally { setBusy(false); }
    };
    const inputClass = 'mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm';
    return <section aria-label="Education cost breakdown" className="space-y-5 p-5">
        <div><h2 className="font-bold text-gray-900">Breakdown Cost</h2><p className="mt-1 text-sm text-gray-600">Editable estimates. Original fees remain in their entered currency; this does not update issued agreements or send an email.</p></div>
        {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
        {message && <p role="status" className="rounded-lg bg-green-50 p-3 text-sm text-green-800">{message}</p>}
        <fieldset disabled={busy} className="space-y-4 disabled:opacity-60">
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-xs font-semibold">Find client<input aria-label="Find cost breakdown client" className={inputClass} value={search} maxLength={150} onChange={(e) => setSearch(e.target.value)} placeholder="Name, email or reference" /></label>
                <label className="text-xs font-semibold">Client<select className={inputClass} value={client?.id || ''} onChange={(e) => selectClient(e.target.value)}><option value="">{loading ? 'Loading clients...' : 'Choose a client'}</option>{client && !clients.some((row) => row.id === client.id) && <option value={client.id}>{client.name}</option>}{clients.map((row) => <option key={row.id} value={row.id}>{row.name}{row.reference ? ` - ${row.reference}` : ''}</option>)}</select></label>
            </div>
            <p className="text-xs text-gray-500">Changing client clears the unsaved cost rows. Search to find clients outside the first 50 results.</p>
            {client && <>
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="text-xs font-semibold">Country of residence<input className={inputClass} value={country} maxLength={100} onChange={(e) => setCountry(e.target.value)} /></label>
                    <label className="text-xs font-semibold">Category<select className={inputClass} value={category} onChange={(e) => setCategory(e.target.value)}><option value="">Choose category</option><option value="philippines">Philippines</option><option value="onshore">Onshore</option><option value="offshore">Offshore</option></select></label>
                    <label className="text-xs font-semibold">Applicants<select className={inputClass} value={family} onChange={(e) => setFamily(e.target.value)}><option value="single">Single</option><option value="couple">Couple</option><option value="couple_child">Couple with child</option></select></label>
                    <label className="text-xs font-semibold">Original fee currency<input className={inputClass} value={source} maxLength={3} onChange={(e) => setSource(e.target.value.toUpperCase())} /></label>
                    <label className="text-xs font-semibold">Local estimate currency<input className={inputClass} value={target} maxLength={3} onChange={(e) => setTarget(e.target.value.toUpperCase())} placeholder="e.g. PHP" /></label>
                </div>
                <p className="text-xs text-gray-500">Defaults use current residence. Staff may override country, category and currency. Changing category or applicant type does not invent new prices; review every amount. Use three-letter currency codes.</p>
                <div className="rounded-lg bg-teal-50 p-3 text-xs text-teal-900">
                    {rateLoading ? <p role="status">Loading automatic reference rates...</p> : rateError ? <p role="alert">{rateError}</p> : currentQuote ? <><p>Indicative conversion - {currentQuote.provider}</p>{Object.entries(currentQuote.rates).map(([code, value]) => <p key={code}>1 NZD = {value.rate} {code} - rate date {value.date}</p>)}</> : <p>Select valid currencies to fetch automatic reference rates.</p>}
                    <button type="button" className="mt-2 underline" onClick={() => setRateRefresh((old) => old + 1)} disabled={rateLoading}>Refresh rates</button>
                </div>
                <div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead><tr><th>Description</th><th>Original {source}</th><th>Estimate NZD</th><th>Estimate {target || 'local'}</th><th>Action</th></tr></thead><tbody>{rows.map((row, index) => { const estimate = converted(row); return <tr key={index}>
                    <td className="py-2 pr-2"><textarea aria-label={`Description ${index + 1}`} className={inputClass} maxLength={500} value={row.description} onChange={(e) => updateRow(index, 'description', e.target.value)} /></td>
                    <td className="pr-2"><input aria-label={`Amount ${index + 1}`} type="number" min="0" max="99999999.99" step="0.01" className={inputClass} value={row.amount} onChange={(e) => updateRow(index, 'amount', e.target.value)} /></td>
                    <td>{estimate ? money(estimate.nzd) : '-'}</td><td>{estimate ? money(estimate.local) : '-'}</td>
                    <td><button type="button" aria-label={`Remove row ${index + 1}`} disabled={rows.length === 1} onClick={() => setRows((old) => old.filter((_, i) => i !== index))}>Remove</button></td>
                </tr>; })}</tbody><tfoot><tr><th>Listed cost total</th><td>{money(total.amount)}</td><td>{currentQuote ? money(total.nzd) : '-'}</td><td>{currentQuote ? money(total.local) : '-'}</td><td /></tr></tfoot></table></div>
                <button type="button" className="rounded-lg border px-3 py-2 text-sm" disabled={rows.length >= 30} onClick={() => setRows((old) => [...old, emptyRow()])}>Add cost item</button>
                <p className="text-xs text-gray-500">Totals sum rounded line amounts, using two decimal places. Only listed costs are included. Converted amounts are estimates, not payment amounts.</p>
                <div className="flex flex-wrap gap-2">{[['preview', 'Preview breakdown'], ['download', 'Download PDF'], ['save', 'Save new breakdown']].map(([action, label]) => <button key={action} type="button" disabled={!canGenerate} onClick={() => generate(action)} className="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-40">{busy ? 'Working...' : label}</button>)}</div>
            </>}
        </fieldset>
        {preview && <iframe title="Cost breakdown preview" sandbox="" srcDoc={preview} className="h-[650px] w-full rounded-lg border" />}
        <div><h3 className="font-semibold">Saved breakdowns</h3><p className="mt-1 text-xs text-gray-500">Latest 50 saved documents. Each save creates a new version with its own rate and amount snapshot.</p>{documents.length ? <ul className="mt-2 divide-y">{documents.map((doc) => <li key={doc.id} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span>{doc.client} - {doc.name}</span><a className="underline" href={doc.download_url}>Download saved PDF</a></li>)}</ul> : <p className="mt-2 text-sm text-gray-500">No saved cost breakdowns yet.</p>}</div>
    </section>;
}
