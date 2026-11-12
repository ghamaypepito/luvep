import { Link } from "@inertiajs/react";

export default function AssessmentCaseLink({ assessment }) {
    if (assessment.case_url) {
        return <Link href={assessment.case_url} className="inline-flex rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white">Open case</Link>;
    }
    return assessment.conversion_issue
        ? <p role="alert" className="max-w-sm text-xs text-amber-800">{assessment.conversion_issue}</p>
        : null;
}
