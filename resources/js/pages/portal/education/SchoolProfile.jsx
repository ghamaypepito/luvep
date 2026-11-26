import SchoolInformation from '@/pages/admin/SchoolProfile';
import SchoolWorkspace from './SchoolWorkspace';

export default function EducationSchoolProfile({ workspace, ...props }) {
    if (!workspace) return <SchoolInformation {...props} />;
    return <>
        <SchoolWorkspace {...workspace} />
        <details id="school-information" className="m-5 rounded-xl border bg-white p-4">
            <summary className="cursor-pointer font-semibold">School information, contacts and existing agreement</summary>
            <SchoolInformation {...props} />
        </details>
    </>;
}
