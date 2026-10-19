import { hrPath } from "@/lib/hrPath";
import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import { Eye } from "lucide-react";

export default function Preview({ employees, search = "" }) {
    const [query, setQuery] = useState(search);
    const [pending, setPending] = useState(null);
    return (
        <>
            <Head title="Employee preview · Luvep" />
            <div className="hr-page-heading">
                <h1>Login as employee</h1>
                <p>
                    See an employee's dashboard in read-only mode. Your admin
                    account stays signed in, and access is recorded.
                </p>
            </div>
            <form
                className="hr-search"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get(
                        hrPath("/preview"),
                        { search: query },
                        { preserveState: true },
                    );
                }}
            >
                <label htmlFor="employee-search">Find an employee</label>
                <div>
                    <input
                        id="employee-search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        maxLength={100}
                    />
                    <button className="hr-button-secondary">Search</button>
                </div>
            </form>
            <section className="hr-panel">
                {!employees.data.length && (
                    <p>No eligible employees match your search.</p>
                )}
                <ul className="hr-employee-list">
                    {employees.data.map((e) => (
                        <li key={e.id}>
                            <div>
                                <strong>{e.name}</strong>
                                <p>{e.department || "Department not set"}</p>
                            </div>
                            <button
                                disabled={pending !== null}
                                className="hr-button-secondary"
                                onClick={() => {
                                    setPending(e.id);
                                    router.post(
                                        hrPath(`/preview/${e.id}`),
                                        {},
                                        { onFinish: () => setPending(null) },
                                    );
                                }}
                            >
                                <Eye size={16} />
                                {pending === e.id
                                    ? "Opening…"
                                    : "View dashboard"}
                            </button>
                        </li>
                    ))}
                </ul>
                <div className="hr-pagination">
                    {employees.prev_page_url && (
                        <Link href={employees.prev_page_url}>Previous</Link>
                    )}
                    {employees.next_page_url && (
                        <Link href={employees.next_page_url}>Next</Link>
                    )}
                </div>
            </section>
        </>
    );
}
