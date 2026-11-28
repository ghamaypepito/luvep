import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import { toast } from "sonner";

const path = "/portal/education/students/english-archive";
const link = "text-sm font-semibold text-indigo-700 hover:underline";

export default function EnglishStudentArchive({ students, search = "" }) {
    const [query, setQuery] = useState(search);
    const [restoring, setRestoring] = useState(null);

    function restore(student) {
        if (!window.confirm(`Restore "${student.name}" to the English queue?`)) return;
        setRestoring(student.id);
        router.patch(`/portal/education/students/${student.id}/english-archive`, { archived: false }, {
            preserveScroll: true,
            onSuccess: () => toast.success("Student restored to the English queue."),
            onError: () => toast.error("Could not restore the English student."),
            onFinish: () => setRestoring(null),
        });
    }

    return <div className="space-y-5">
        <Head title="Archived English students" />
        <header>
            <Link href="/portal/education/students?tab=english" className={link}>Back to English students</Link>
            <h1 className="mt-2 text-2xl font-bold text-gray-900">Archived English students</h1>
        </header>
        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
            event.preventDefault();
            router.get(path, query.trim() ? { search: query.trim() } : {});
        }}>
            <label className="text-sm font-medium text-gray-700">
                Search by name or lead ID
                <input value={query} maxLength={120} onChange={(event) => setQuery(event.target.value)}
                    className="mt-1 block rounded-lg border border-gray-300 bg-white px-3 py-2" />
            </label>
            <button className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Search</button>
        </form>
        <div className="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
            {students.data.length === 0 ? <p className="p-5 text-sm text-gray-500">{search ? "No archived students match your search." : "No archived English students."}</p>
                : <table className="w-full text-left text-sm">
                    <thead className="bg-gray-50 text-gray-600"><tr><th className="p-4">Student</th><th className="p-4">Lead ID</th><th className="p-4">Actions</th></tr></thead>
                    <tbody>{students.data.map(student => <tr key={student.id} className="border-t border-gray-100">
                        <th scope="row" className="p-4 font-semibold text-gray-900">{student.name}</th>
                        <td className="p-4 text-gray-600">{student.lead_id || "—"}</td>
                        <td className="p-4"><button type="button" disabled={restoring !== null} className={`${link} disabled:opacity-50`}
                            onClick={() => restore(student)}>{restoring === student.id ? "Restoring…" : "Restore"}</button></td>
                    </tr>)}</tbody>
                </table>}
        </div>
        {students.last_page > 1 && <nav aria-label="Archived English students pages" className="flex items-center gap-4 text-sm">
            {students.prev_page_url && <Link href={students.prev_page_url} className={link}>Previous</Link>}
            <span>Page {students.current_page} of {students.last_page}</span>
            {students.next_page_url && <Link href={students.next_page_url} className={link}>Next</Link>}
        </nav>}
    </div>;
}
