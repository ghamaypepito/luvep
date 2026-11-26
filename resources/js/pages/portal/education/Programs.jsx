// Education portal Programs — same module + UI as the admin Programs page.
// Rendered under portal/education/* so app.jsx wraps it in EducationLayout;
// portalBase points the create / edit / delete actions at the education
// routes (which map to the shared ProgramController CRUD).
import AdminPrograms from "@/pages/admin/Programs";
import { Link } from '@inertiajs/react';

export default function EducationPrograms({ programs = [], schools = [] }) {
    return (
        <>
        <details className="m-4 rounded-xl border bg-white p-4"><summary>School programme intakes and deadlines</summary>
            <div className="flex flex-wrap gap-3 pt-3">{schools.map(school => <Link className="text-blue-700 underline" key={school.id} href={`/portal/education/schools/${school.id}/management?tab=intake`}>{school.name}</Link>)}</div>
        </details>
        <AdminPrograms
            programs={programs}
            schools={schools}
            portalBase="/portal/education"
        />
        </>
    );
}
