import { useEffect, useRef, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { ArchiveRestore, Download, Pencil, Search, Trash2 } from "lucide-react";
import { toast } from "sonner";
import Pagination from "@/components/ui/Pagination";

const base = "/portal/immigration/cases";
const archive = `${base}/archived`;
const nameFields = [["first_name", "First name"], ["middle_name", "Middle name"], ["last_name", "Last name"], ["suffix", "Suffix"]];
const nameOf = (record) => nameFields.map(([key]) => record[key]).filter(Boolean).join(" ") || record.lead_id;

export default function ArchivedCases({ cases, search = "" }) {
    const [query, setQuery] = useState(search);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [busy, setBusy] = useState(false);
    const refresh = (event) => {
        event.preventDefault();
        router.get(archive, { search: query });
    };
    const restore = (record) => {
        if (busy) return;
        if (!window.confirm(`Restore ${nameOf(record)} to the active Cases list?`)) return;
        router.post(`${archive}/${record.id}/restore`, {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (errors) => toast.error(Object.values(errors)[0] || "Could not update the case."),
        });
    };

    return <div className="space-y-5">
        <Head title="Archived Cases" />
        <h1 className="text-2xl font-bold text-gray-900">Archived Cases</h1>
        <nav aria-label="Case views" className="flex flex-wrap gap-2">
            <Link href={base} className="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Active Cases</Link>
            <Link href={archive} aria-current="page" className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white">Archived Cases</Link>
        </nav>
        <form onSubmit={refresh} role="search" className="flex max-w-lg gap-2">
            <input aria-label="Search by name or case reference" value={query} onChange={event => setQuery(event.target.value)} maxLength={120} placeholder="Search by name or case reference" className="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" />
            <button type="submit" className="flex items-center gap-1.5 rounded-lg bg-gray-900 px-3 text-sm font-semibold text-white"><Search size={14} /> Search</button>
        </form>
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table className="w-full text-left text-sm">
                <thead className="border-b border-gray-200 bg-gray-50 text-gray-600"><tr>
                    <th scope="col" className="p-3">Case</th><th scope="col" className="p-3">Stage at archive</th><th scope="col" className="p-3">Archived</th><th scope="col" className="p-3">Actions</th>
                </tr></thead>
                <tbody className="divide-y divide-gray-100">
                    {cases.data.length === 0 && <tr><td colSpan={4} className="p-6 text-center text-gray-500">No cases found.</td></tr>}
                    {cases.data.map(record => <tr key={record.id}>
                        <th scope="row" className="p-3 font-semibold text-gray-900">{nameOf(record)}<span className="block text-xs font-normal text-gray-500">{record.lead_id}</span></th>
                        <td className="p-3">{record.immigration_stage || "—"}</td>
                        <td className="p-3">{new Date(record.deleted_at).toLocaleDateString("en-NZ", { timeZone: "Pacific/Auckland" })}</td>
                        <td className="p-3">
                            <div className="flex flex-wrap gap-3">
                                <button type="button" disabled={busy} className="inline-flex items-center gap-1 font-semibold hover:underline" onClick={() => setEditing(record)}><Pencil size={14} /> Correct name</button>
                                <button type="button" disabled={busy} className="inline-flex items-center gap-1 font-semibold text-emerald-800 hover:underline" onClick={() => restore(record)}><ArchiveRestore size={14} /> Restore</button>
                                <a href={`${archive}/${record.id}/download`} className="inline-flex items-center gap-1 font-semibold hover:underline"><Download size={14} /> Download</a>
                                <button type="button" disabled={busy} className="inline-flex items-center gap-1 font-semibold text-red-700 hover:underline" onClick={() => setDeleting(record)}><Trash2 size={14} /> Delete</button>
                            </div>
                        </td>
                    </tr>)}
                </tbody>
            </table>
        </div>
        <Pagination links={cases.links} />
        {editing && <NameEditor key={editing.id} record={editing} onClose={() => setEditing(null)} />}
        {deleting && <DeleteCase key={deleting.id} record={deleting} onClose={() => setDeleting(null)} />}
    </div>;
}

function NameEditor({ record, onClose }) {
    const { data, setData, patch, errors, processing } = useForm(Object.fromEntries(nameFields.map(([key]) => [key, record[key] ?? ""])));
    const submit = (event) => {
        event.preventDefault();
        patch(`${archive}/${record.id}/name`, { preserveScroll: true, onSuccess: onClose });
    };

    return <CaseDialog label="correct-case-name" onClose={onClose} processing={processing}>
        <form onSubmit={submit} className="space-y-3">
            <h2 id="correct-case-name" className="text-lg font-bold">Correct name · {record.lead_id}</h2>
            {nameFields.map(([key, label]) => <label key={key} className="block text-sm font-semibold">{label}
                <input autoFocus={key === "first_name"} required={key === "first_name"} maxLength={key === "suffix" ? 20 : 120} value={data[key]} onChange={event => setData(key, event.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-normal" />
                {errors[key] && <span role="alert" className="text-xs text-red-700">{errors[key]}</span>}
            </label>)}
            <div className="flex justify-end gap-2">
                <button type="button" onClick={onClose} disabled={processing} className="rounded-lg px-3 py-2 text-sm font-semibold">Cancel</button>
                <button type="submit" disabled={processing} className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white">Save name</button>
            </div>
        </form>
    </CaseDialog>;
}

function DeleteCase({ record, onClose }) {
    const form = useForm({ confirmation: "" });
    const submit = (event) => {
        event.preventDefault();
        if (form.data.confirmation !== "DELETE ACCOUNT" || form.processing) return;
        form.delete(`${archive}/${record.id}`, { preserveScroll: true, onSuccess: onClose });
    };
    return <CaseDialog label="delete-archived-case" onClose={onClose} processing={form.processing}>
        <form onSubmit={submit} className="space-y-3">
            <h2 id="delete-archived-case" className="text-lg font-bold">Permanently delete {nameOf(record)}?</h2>
            <p className="text-sm text-gray-700">{record.lead_id} and its case records and files will be permanently deleted. Files used by another record will be kept.</p>
            <a href={`${archive}/${record.id}/download`} className="inline-flex items-center gap-1 text-sm font-semibold hover:underline"><Download size={14} /> Download case records and files (optional)</a>
            <label className="block text-sm font-semibold">Type DELETE ACCOUNT
                <input autoFocus autoComplete="off" value={form.data.confirmation} onChange={event => form.setData("confirmation", event.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2" />
            </label>
            {form.errors.confirmation && <p role="alert" className="text-sm text-red-700">{form.errors.confirmation}</p>}
            <div className="flex justify-end gap-2">
                <button type="button" onClick={onClose} disabled={form.processing} className="rounded-lg px-3 py-2 text-sm font-semibold">Cancel</button>
                <button type="submit" disabled={form.processing || form.data.confirmation !== "DELETE ACCOUNT"} className="rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Delete permanently</button>
            </div>
        </form>
    </CaseDialog>;
}

function CaseDialog({ label, onClose, processing, children }) {
    const dialog = useRef(null);
    useEffect(() => {
        const element = dialog.current;
        element.showModal();
        return () => element.close();
    }, []);
    return <dialog ref={dialog} aria-labelledby={label} onCancel={event => { event.preventDefault(); if (!processing) onClose(); }} className="m-auto w-[min(92vw,28rem)] rounded-xl border border-gray-200 bg-white p-5 shadow-xl backdrop:bg-black/35">
        {children}
    </dialog>;
}
