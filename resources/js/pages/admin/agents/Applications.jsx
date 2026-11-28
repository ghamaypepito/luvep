import { Head, Link } from "@inertiajs/react";
import Pagination from "../../../components/ui/Pagination";

export default function Applications({ applications }) {
    return <div className="space-y-6">
        <Head title="Affiliate applications" />
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div><h1 className="text-3xl font-semibold">Affiliate applications</h1>
                <p className="mt-2 text-sm text-[#53655a]">Review verified applicants and follow their progress through signing.</p></div>
            <a href="/affiliate/apply" target="_blank" rel="noreferrer" className="affiliate-button">Open application form</a>
        </header>
        <div className="overflow-x-auto rounded-2xl border border-[#e1e7dc] bg-white">
            <table className="w-full text-left text-sm">
                <thead className="bg-[#f4f7f1] text-xs text-[#53655a]"><tr>{["Applicant", "Country or location", "Status", "Submitted"].map(label =>
                    <th key={label} scope="col" className="px-5 py-3">{label}</th>)}</tr></thead>
                <tbody>{applications.data.map(row => <tr className="border-t border-[#e5ebe1]" key={row.id}>
                    <td className="px-5 py-4"><Link className="font-semibold text-[#285d46] underline" href={row.detail_url}>{row.name}</Link><small className="mt-1 block">{row.email}</small></td>
                    <td className="px-5 py-4">{row.location}</td><td className="px-5 py-4">{row.status}</td>
                    <td className="px-5 py-4">{row.submitted_at ? new Date(row.submitted_at).toLocaleDateString("en-NZ") : "Not submitted"}</td>
                </tr>)}</tbody>
            </table>
            {!applications.data.length && <p className="p-8 text-center text-sm text-[#53655a]">No affiliate applications yet.</p>}
        </div>
        <Pagination links={applications.links} />
    </div>;
}
