import { Head, router } from "@inertiajs/react";
import PortalPageHeader from "@/components/portal/PortalPageHeader";
import WeeklyReport, { WeekButtons } from "@/components/WeeklyReport";

export default function Reports({ preset, weeklyReport }) {
    return (
        <div className="space-y-6 pb-12">
            <Head title="Accommodation reports" />
            <PortalPageHeader eyebrow="Updates and reporting" title="Accommodation reports" description="Weekly Task Board activity and notes." />
            <WeekButtons preset={preset} onChange={(selected) => router.get("/portal/accommodation/reports", { preset: selected }, { preserveScroll: true })} />
            <WeeklyReport report={weeklyReport} saveUrl="/portal/accommodation/reports/note" />
        </div>
    );
}
