import { Head, Link } from "@inertiajs/react";
import DtrHistory from "@/components/hr/DtrHistory";

export default function DtrChanges({ records }) {
    return <>
        <Head title="DTR changes · Luvep" />
        <div className="hr-page-heading"><h1>DTR changes</h1><p>View daily-report changes for your team, including drafts and submitted records.</p></div>
        {records.data.length ? records.data.map(record => <article className="hr-panel" key={`${record.id}:${record.hr_version}`}>
            <div className="hr-section-bar"><h2>{record.employee_name} · {record.work_date}</h2><span className="hr-status">{record.hr_status}</span></div>
            {record.submitted_at && <p>Submitted {new Date(record.submitted_at).toLocaleString()} for work date {record.work_date}.</p>}
            <DtrHistory entry={record} />
        </article>) : <section className="hr-panel"><p>No time records are available for your team yet.</p></section>}
        {records.last_page > 1 && <nav className="hr-actions" aria-label="Team record pages">
            {records.prev_page_url && <Link className="hr-button-secondary" href={records.prev_page_url}>Previous records</Link>}
            <span>Page {records.current_page} of {records.last_page}</span>
            {records.next_page_url && <Link className="hr-button-secondary" href={records.next_page_url}>Next records</Link>}
        </nav>}
    </>;
}
