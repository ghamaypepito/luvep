import { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Download, Eye } from "lucide-react";
import Pagination from "@/components/ui/Pagination";

export default function AffiliateAgreements({ agreements = { data: [], links: [] }, filters = {} }) {
    const [search, setSearch] = useState(filters.search || "");
    const [status, setStatus] = useState(filters.status || "");
    const date = (value) => value ? new Date(value).toLocaleDateString("en-NZ") : "—";
    const apply = (event) => {
        event.preventDefault();
        router.get("/admin/agents/agreements", { search, status });
    };

    return <div className="affiliate-page space-y-6">
        <Head title="Affiliate Agreements" />
        <header><h1 className="text-3xl font-semibold tracking-tight text-gray-900">Affiliate Agreements</h1><p className="mt-2 text-sm text-gray-600">Generated documents, signatures and earlier versions in one repository.</p></header>
        <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
            <label className="grid gap-1 text-sm">Affiliate partner<input aria-label="Affiliate partner" className="rounded-lg border border-gray-200 bg-white px-3 py-2" value={search} onChange={e => setSearch(e.target.value)} placeholder="Search by name" maxLength={150} /></label>
            <label className="grid gap-1 text-sm">Agreement status<select aria-label="Agreement status" className="rounded-lg border border-gray-200 bg-white px-3 py-2" value={status} onChange={e => setStatus(e.target.value)}><option value="">All statuses</option>{["Draft", "Generated", "Sent", "Pending Signature", "Signed", "Expired"].map(s => <option key={s}>{s}</option>)}</select></label>
            <button className="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Apply filters</button>
        </form>
        <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white"><table className="w-full text-left text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500"><tr>{["Affiliate partner", "Version", "Status", "Created", "Partner signed", "EP signed", "Expires", "Documents"].map(label => <th key={label} className="px-5 py-3">{label}</th>)}</tr></thead>
            <tbody>{agreements.data.length ? agreements.data.map(a => <tr className="border-t border-gray-100" key={a.id}>
                <td className="px-5 py-4"><Link className="font-semibold text-emerald-900 underline" href={a.profile_url}>{a.partner_name}</Link></td>
                <td className="px-5 py-4">{a.version}</td><td className="px-5 py-4">{a.status}{a.legacy && <small className="block text-gray-500">Existing document</small>}{a.send_requested_at && !a.sent_at && <small className="block text-gray-500">{a.send_error || "Invitation queued"}</small>}</td>
                <td className="px-5 py-4">{date(a.created_at)}</td><td className="px-5 py-4">{date(a.agent_signed_at)}</td><td className="px-5 py-4">{date(a.company_signed_at)}</td><td className="px-5 py-4">{date(a.expires_on)}</td>
                <td className="px-5 py-4"><div className="flex gap-3"><a href={a.view_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-emerald-900 underline"><Eye size={14} />View</a><a href={a.download_url} className="inline-flex items-center gap-1 text-emerald-900 underline"><Download size={14} />Download</a></div></td>
            </tr>) : <tr><td colSpan={8} className="p-10 text-center text-gray-500">No agreements match these filters.</td></tr>}</tbody>
        </table></div>
        <Pagination links={agreements.links} />
    </div>;
}
