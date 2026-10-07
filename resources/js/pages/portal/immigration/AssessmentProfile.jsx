import { Head, router } from "@inertiajs/react";
import { IntakeViewModal } from "./Assessments";

/**
 * Pre-screening potential-client profile — the Visa Assessment detail as a full
 * page (not a modal). Reuses IntakeViewModal in page mode; it self-fetches the
 * form sections / readiness from the existing intake data endpoint, and renders
 * the pre-screening strip + convert action inline. Procedural, not advice.
 */
export default function AssessmentProfile({ intake }) {
    const backToList = () => router.visit("/portal/immigration/assessments");

    return (
        <>
            <Head title={`${intake?.name || "Assessment"} · Pre-screening`} />
            <div className="p-4 sm:p-6 max-w-[1440px] mx-auto">
                <IntakeViewModal intake={intake} asPage onClose={backToList} />
            </div>
        </>
    );
}
