import { Head, Link } from '@inertiajs/react';

export default function AssignedCases({ cases = { data: [], links: [] } }) {
    return <main className="mx-auto max-w-5xl space-y-5 p-6">
        <Head title="Assigned cases" />
        <div><h1 className="text-2xl font-bold">Assigned cases</h1><p className="mt-1 text-sm text-gray-600">Case summaries and requested documents assigned to you as a helper.</p></div>
        {!cases.data.length ? <p className="rounded-xl border bg-white p-6 text-sm text-gray-600">No cases are currently assigned to you.</p>
            : <ul className="divide-y rounded-xl border bg-white">{cases.data.map(item => <li key={item.id} className="p-4"><Link href={`/portal/agent/assigned-cases/${item.id}`} className="font-semibold text-teal-800 underline">{item.name || item.reference}</Link><p className="mt-1 text-sm text-gray-600">{[item.reference, item.visa, item.stage].filter(Boolean).join(' · ')}</p></li>)}</ul>}
        <nav aria-label="Assigned case pages" className="flex flex-wrap gap-2">{cases.links.filter(link => link.url).map((link, index) => <Link key={index} href={link.url} aria-current={link.active ? 'page' : undefined} className="rounded border px-3 py-1 text-sm">{link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»')}</Link>)}</nav>
    </main>;
}
