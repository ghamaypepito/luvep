import DtrCalendar from "@/components/hr/DtrCalendar";
import { Head } from "@inertiajs/react";

export default function DtrReview({ entries, weeks, filters, oldestPending }) {
    return <>
        <Head title="Time record reviews · Luvep" />
        <header className="hr-page-heading"><h1>Time record reviews</h1><p>Review attendance and work records for employees in your care. Select a daily record or weekly declaration to review it.</p></header>
        <DtrCalendar records={entries} weeks={weeks} filters={filters} path="/dtr/review" title="Time reviews" oldestPending={oldestPending} />
    </>;
}
