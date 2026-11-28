import { useEffect, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Search } from "lucide-react";
import Pagination from "@/components/ui/Pagination";

const path = "/portal/accommodation/onboarding";
const field = "rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700";

function OwnerSelect({ submission, team }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const owner = submission.assigned_to;

    const change = (event) => {
        const value = event.target.value;
        setBusy(true);
        setError("");
        router.post(`/portal/accommodation/applications/${submission.id}/assign`, { user_id: value || null }, {
            preserveScroll: true,
            onError: (errors) => setError(errors.user_id || "Could not save the assignment."),
            onFinish: () => setBusy(false),
        });
    };

    return <>
        <select aria-label={`Assign ${submission.full_legal_name}`} value={owner?.id ?? ""} onChange={change} disabled={busy} className={field}>
            <option value="">Unassigned</option>
            {owner && !team.some(({ id }) => id === owner.id) && <option value={owner.id} disabled>{owner.name}</option>}
            {team.map(({ id, name }) => <option key={id} value={id}>{name}</option>)}
        </select>
        {error && <p role="alert" className="mt-1 text-xs text-rose-700">{error}</p>}
    </>;
}
export default function Applications({ submissions = {}, options = {}, filters = {} }) {
    const [search, setSearch] = useState(filters.search ?? "");
    const stage = filters.stage ?? "";
    const assignee = filters.assigned_to ?? "";
    const team = options.team ?? [];
    const rows = submissions.data ?? [];
    const apply = (patch) => router.get(path, Object.fromEntries(
        Object.entries({ search: search.trim(), stage, assigned_to: assignee, ...patch }).filter(([, value]) => value !== ""),
    ), { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (search.trim() !== (filters.search ?? "")) {
            const timer = setTimeout(() => apply({ search: search.trim() }), 350);
            return () => clearTimeout(timer);
        }
    }, [search, filters.search, stage, assignee]);

    return (
        <main className="mx-auto max-w-7xl space-y-5 pb-10">
            <Head title="Accommodation Onboarding" />
            <h1 className="text-2xl font-bold text-gray-900">Onboarding</h1>
            <div className="flex flex-wrap items-center gap-3">
                <label className="relative min-w-60 flex-1">
                    <Search size={16} className="pointer-events-none absolute left-3 top-2.5 text-gray-500" />
                    <input aria-label="Search by name, email or mobile" placeholder="Search name, email or mobile…"
                        className={`${field} w-full pl-9`} type="search" maxLength={150} value={search} onChange={(event) => setSearch(event.target.value)} />
                </label>
                <select aria-label="Stage" value={stage} className={field} onChange={(event) => apply({ stage: event.target.value })}>
                    <option value="">All stages</option>
                    {(options.stages ?? []).map(({ id, label }) => <option key={id} value={id}>{label}</option>)}
                </select>
                <select aria-label="Assignee" value={assignee === "me" ? "" : assignee} className={field}
                    onChange={(event) => apply({ assigned_to: event.target.value })}>
                    <option value="">All assignees</option>
                    {team.map(({ id, name }) => <option key={id} value={id}>{name}</option>)}
                    <option value="unassigned">Unassigned</option>
                </select>
                <button type="button" aria-pressed={assignee === "me"} onClick={() => apply({ assigned_to: assignee === "me" ? "" : "me" })}
                    className={`${field} font-semibold aria-pressed:border-[#1F5A8B] aria-pressed:bg-blue-50`}>Assigned to me</button>
            </div>
            <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead><tr className="border-b border-gray-100 bg-gray-50 text-gray-700">
                        {["Applicant", "Property", "Assigned to", "Stage", "Submitted", "Actions"].map((name) => <th key={name} scope="col" className="px-4 py-3">{name}</th>)}
                    </tr></thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row) => <tr key={row.id}>
                            <td className="px-4 py-3 font-semibold text-gray-900">{row.full_legal_name}</td>
                            <td className="px-4 py-3">{row.property_label || "—"}</td>
                            <td className="px-4 py-3"><OwnerSelect submission={row} team={team} /></td>
                            <td className="px-4 py-3">{row.stage_label}</td>
                            <td className="whitespace-nowrap px-4 py-3">{new Date(row.created_at).toLocaleDateString("en-NZ", { timeZone: "Pacific/Auckland" })}</td>
                            <td className="px-4 py-3"><Link href={`/portal/accommodation/applications/${row.id}`}
                                className="inline-block rounded-lg bg-[#1F5A8B] px-4 py-2 font-semibold text-white">View</Link></td>
                        </tr>)}
                        {!rows.length && <tr><td colSpan={6} className="px-4 py-12 text-center text-gray-600">No applicants match these filters.</td></tr>}
                    </tbody>
                </table>
            </div>
            <Pagination links={submissions.links} />
        </main>
    );
}
