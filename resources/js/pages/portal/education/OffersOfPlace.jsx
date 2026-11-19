import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Pagination from '@/components/ui/Pagination';

export default function OffersOfPlace({ documents = { data: [], links: [], total: 0 }, filters = {}, clients = 0, timezone = 'UTC', base }) {
    const [search, setSearch] = useState(filters.search || '');
    const [from, setFrom] = useState(filters.from || '');
    const [to, setTo] = useState(filters.to || '');
    const apply = (event) => {
        event.preventDefault();
        router.get(base, { search, from, to }, { preserveScroll: true });
    };
    const date = (value) => value ? new Intl.DateTimeFormat('en-NZ', { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Unknown';
    const inputClass = 'rounded-lg border border-gray-300 px-3 py-2 text-sm';

    return <div className="space-y-5 p-4">
        <Head title="Offers of Place" />
        <header>
            <h1 className="text-2xl font-semibold">Offers of Place</h1>
            <p className="mt-2 text-sm text-gray-600">Uploaded Offer of Place files for records you can access. All stored versions are shown, newest uploads first.</p>
            <p className="mt-1 text-sm text-gray-600">Dates show when files were uploaded ({timezone}), not when a school issued or sent an offer. Student stage changes alone do not count as an uploaded offer.</p>
        </header>
        <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
            <label className="grid gap-1 text-sm">Client name or code<input className={inputClass} value={search} onChange={e => setSearch(e.target.value)} maxLength={150} /></label>
            <label className="grid gap-1 text-sm">Uploaded from<input className={inputClass} type="date" value={from} onChange={e => setFrom(e.target.value)} /></label>
            <label className="grid gap-1 text-sm">Uploaded to<input className={inputClass} type="date" value={to} min={from || undefined} onChange={e => setTo(e.target.value)} /></label>
            <button className="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white" type="submit">Apply filters</button>
            <button className="rounded-lg border px-4 py-2 text-sm" type="button" onClick={() => { setSearch(''); setFrom(''); setTo(''); router.get(base); }}>Clear filters</button>
        </form>
        <p className="text-sm font-medium">{documents.total} files · {clients} clients in the selected results</p>
        <div className="overflow-x-auto rounded-xl border bg-white">
            <table className="w-full text-left text-sm">
                <thead className="bg-gray-50"><tr>{['Client', 'Offer file', 'Uploaded', 'Document status', 'Actions'].map(label => <th className="p-3" key={label}>{label}</th>)}</tr></thead>
                <tbody>{documents.data.length ? documents.data.map(doc => <tr className="border-t" key={doc.id}>
                    <td className="p-3"><div>{doc.client_name}</div><div className="text-xs text-gray-500">{doc.client_code}</div></td>
                    <td className="p-3">{doc.filename}</td>
                    <td className="p-3">{date(doc.uploaded_at)}</td>
                    <td className="p-3">{doc.status || 'Unknown'}</td>
                    <td className="p-3"><div className="flex gap-3"><a className="text-blue-700 underline" href={doc.view_url} target="_blank" rel="noopener noreferrer">View</a><a className="text-blue-700 underline" href={doc.download_url}>Download</a></div></td>
                </tr>) : <tr><td className="p-8 text-center text-gray-500" colSpan={5}>No Offer of Place files found for the selected filters and your access.</td></tr>}</tbody>
            </table>
        </div>
        <Pagination links={documents.links} />
    </div>;
}
