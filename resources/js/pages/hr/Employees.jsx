import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Employees({ employees, search = '' }) {
    const { hr } = usePage().props;
    const [query, setQuery] = useState(search);
    return <><Head title="People · Luvep" /><div className="hr-page-heading"><h1>People</h1><p>Employee records and employment details.</p>{hr.permissions.enroll && <Link className="hr-button" href="/people/new">Enroll employee</Link>}</div>
        <form className="hr-search" onSubmit={e => {e.preventDefault();router.get('/people', {search: query}, {preserveState: true});}}><label htmlFor="people-search">Find an employee</label><div><input id="people-search" value={query} onChange={e => setQuery(e.target.value)} maxLength={100} /><button className="hr-button-secondary">Search</button></div></form>
        <section className="hr-panel">{employees.data.length ? <div className="hr-table-wrap"><table><thead><tr><th>Employee</th><th>Department</th><th>Position</th><th>Status</th></tr></thead><tbody>{employees.data.map(e => <tr key={e.id}><td><Link href={`/people/${e.id}`}>{e.name}</Link></td><td>{e.department || 'Not set'}</td><td>{e.position || 'Not set'}</td><td>{e.status}</td></tr>)}</tbody></table></div> : <p>No employees match this view.</p>}<div className="hr-pagination">{employees.prev_page_url && <Link href={employees.prev_page_url}>Previous</Link>}{employees.next_page_url && <Link href={employees.next_page_url}>Next</Link>}</div></section></>;
}
