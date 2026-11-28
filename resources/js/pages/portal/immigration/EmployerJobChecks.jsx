import { Head, Link, router, useForm } from "@inertiajs/react";
import { useState } from "react";

const path = "/portal/immigration/employer-job-checks";
const inputClass = "w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm";
const panelClass = "rounded-xl border border-gray-200 bg-white p-5";
const primaryClass = "rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50";

const employerFields = [
    ["legal_name", "Legal company name"],
    ["trading_name", "Trading name"],
    ["employer_identifier", "NZBN or INZ employer ID"],
    ["business_activity", "Business products and services", "textarea"],
    ["accreditation_type", "Accreditation type", { standard: "Standard", high_volume: "High volume", other: "Other" }],
    ["accreditation_expires_on", "Accreditation expiry", "date"],
    ["approved_positions", "Approved positions", "number"],
];

const caseFields = [
    ["Role intake", [
        ["occupation_code", "ANZSCO or NOL occupation code"],
        ["positions", "Positions requested", "number"],
        ["work_location", "Work location"],
        ["guaranteed_hours", "Guaranteed hours per week", "decimal"],
        ["maximum_hours", "Maximum hours per week", "decimal"],
        ["wage_min", "Minimum hourly wage (NZD)", "decimal"],
        ["wage_max", "Maximum hourly wage (NZD)", "decimal"],
        ["job_requirements", "Experience and qualifications", "textarea"],
    ]],
    ["Onboarding", [
        ["engagement_status", "Engagement", { sent: "Sent", signed: "Signed" }],
        ["invoice_status", "Invoice", { unpaid: "Unpaid", paid: "Paid" }],
        ["inz1279_status", "INZ 1279", { requested: "Requested", received: "Received", verified: "Verified" }],
    ]],
    ["Advertising and INZ", [
        ["ad_url", "Live advertisement URL", "url"],
        ["ad_starts_on", "Advertisement start date", "date"],
        ["ad_closes_on", "Advertisement closing date", "date"],
        ["inz_reference", "INZ application or Job Check reference"],
        ["lodged_on", "Lodgement date", "date"],
        ["decision_on", "Decision date", "date"],
        ["token_expires_on", "Recorded token expiry", "date"],
        ["notes", "Internal case notes", "textarea"],
    ]],
];

function Field({ label, value, error, onChange, type = "text", required = false, placeholder = "Select an option" }) {
    const props = { className: inputClass, value: value ?? "", onChange: event => onChange(event.target.value), required, "aria-invalid": Boolean(error) };

    return (
        <label className="block space-y-1 text-sm font-medium text-gray-700">
            <span>{label}</span>
            {typeof type === "object" ? (
                <select {...props}>
                    <option value="">{placeholder}</option>
                    {Object.entries(type).map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                </select>
            ) : type === "textarea" ? (
                <textarea {...props} rows={3} />
            ) : (
                <input {...props} type={type === "decimal" ? "number" : type} step={type === "decimal" ? "any" : undefined} />
            )}
            {error && <span className="block text-red-700" role="alert">{error}</span>}
        </label>
    );
}

function EmployerForm({ employer }) {
    const form = useForm(Object.fromEntries(employerFields.map(([key]) => [key, employer?.[key] ?? ""])));

    function submit(event) {
        event.preventDefault();
        if (employer) {
            form.patch(`${path}/employers/${employer.id}`, { preserveScroll: true });
        } else {
            form.post(`${path}/employers`, { preserveScroll: true, onSuccess: () => form.reset() });
        }
    }

    return (
        <form onSubmit={submit} className="mt-4 space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                {employerFields.map(([key, label, type]) => (
                    <Field key={key} label={label} type={type} required={["legal_name", "employer_identifier"].includes(key)}
                        value={form.data[key]} error={form.errors[key]} onChange={value => form.setData(key, value)} />
                ))}
            </div>
            <button className={primaryClass} disabled={form.processing}>{employer ? "Save employer" : "Add employer"}</button>
        </form>
    );
}

function CaseList({ cases, employers, staff, canManage, stages, search }) {
    const [query, setQuery] = useState(search);
    const form = useForm({ employer_id: "", role_title: "", ...(canManage ? { assigned_to: "" } : {}) });
    const bind = key => ({ value: form.data[key], error: form.errors[key], onChange: value => form.setData(key, value) });

    return (
        <div className="space-y-4">
            {canManage && <details className={panelClass}>
                <summary className="cursor-pointer font-semibold">Add employer</summary>
                <EmployerForm />
            </details>}
            {employers.length > 0 && <details className={panelClass}>
                <summary className="cursor-pointer font-semibold">Create Job Check</summary>
                <form className="mt-4 space-y-4" onSubmit={event => { event.preventDefault(); form.post(path); }}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Employer" type={Object.fromEntries(employers.map(employer => [employer.id, employer.legal_name]))} required {...bind("employer_id")} />
                        <Field label="Role title" required {...bind("role_title")} />
                        {canManage && <Field label="Assigned to" type={Object.fromEntries(staff.map(person => [person.id, person.name]))}
                            placeholder="Assign to me" {...bind("assigned_to")} />}
                    </div>
                    <button className={primaryClass} disabled={form.processing}>Create Job Check</button>
                </form>
            </details>}
            <section className={panelClass}>
                <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={event => {
                    event.preventDefault();
                    router.get(path, query.trim() ? { search: query.trim() } : {}, { preserveState: true });
                }}>
                    <Field label="Search by employer or role" type="search" value={query} onChange={setQuery} />
                    <button className={primaryClass}>Search</button>
                </form>
                {cases.data.length === 0 ? <p className="text-sm text-gray-600">No matching Job Checks.</p> : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b text-gray-500"><tr>
                                {["Role", "Employer", "Stage", "Assigned to"].map(heading => <th scope="col" className="p-3" key={heading}>{heading}</th>)}
                            </tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {cases.data.map(jobCheck => <tr key={jobCheck.id}>
                                    <td className="p-3"><Link className="font-semibold text-teal-800 hover:underline" href={`${path}/${jobCheck.id}`}>{jobCheck.role_title}</Link></td>
                                    <td className="p-3">{jobCheck.employer.legal_name}</td>
                                    <td className="p-3">{stages[jobCheck.stage]}</td>
                                    <td className="p-3">{jobCheck.assignee?.name ?? "Unassigned"}</td>
                                </tr>)}
                            </tbody>
                        </table>
                    </div>
                )}
                {(cases.prev_page_url || cases.next_page_url) && (
                    <nav aria-label="Job Check pages" className="mt-4 flex gap-4 text-sm text-teal-800">
                        {cases.prev_page_url && <Link href={cases.prev_page_url}>Previous page</Link>}
                        {cases.next_page_url && <Link href={cases.next_page_url}>Next page</Link>}
                    </nav>
                )}
            </section>
        </div>
    );
}

function CaseDetail({ jobCheck, stages, canManage, staff }) {
    const employer = jobCheck.employer;
    const form = useForm({
        role_title: jobCheck.role_title,
        stage: jobCheck.stage,
        ...(canManage ? { assigned_to: jobCheck.assigned_to ?? "" } : {}),
        details: { ...jobCheck.details },
    });
    const bind = key => ({ value: form.data[key], error: form.errors[key], onChange: value => form.setData(key, value) });

    return (
        <div className="space-y-4">
            <Link href={path} className="text-sm font-semibold text-teal-800 hover:underline">Back to Employer Job Checks</Link>
            <section className={panelClass}>
                <h2 className="font-semibold">{employer.legal_name}</h2>
                <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt className="text-gray-500">NZBN or INZ employer ID</dt><dd>{employer.employer_identifier}</dd></div>
                    <div><dt className="text-gray-500">Accreditation expiry</dt><dd>{employer.accreditation_expires_on ?? "Not recorded"}</dd></div>
                    <div><dt className="text-gray-500">Approved positions</dt><dd>{employer.approved_positions ?? "Not recorded"}</dd></div>
                </dl>
                {canManage && <details className="mt-4">
                    <summary className="cursor-pointer text-sm font-semibold">Edit employer</summary>
                    <EmployerForm employer={employer} />
                </details>}
            </section>
            <form className="space-y-4" onSubmit={event => {
                event.preventDefault();
                form.patch(`${path}/${jobCheck.id}`, { preserveScroll: true });
            }}>
                <section className={panelClass}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Role title" required {...bind("role_title")} />
                        <Field label="Stage" type={stages} required {...bind("stage")} />
                        {canManage && <Field label="Assigned to" type={Object.fromEntries(staff.map(person => [person.id, person.name]))}
                            placeholder="Unassigned" {...bind("assigned_to")} />}
                    </div>
                </section>
                {caseFields.map(([title, fields]) => <section className={panelClass} key={title}>
                    <h2 className="mb-4 font-semibold">{title}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {fields.map(([key, label, type]) => (
                            <Field key={key} label={label} type={type} value={form.data.details[key]} error={form.errors[`details.${key}`]}
                                onChange={value => form.setData("details", { ...form.data.details, [key]: value })} />
                        ))}
                    </div>
                </section>)}
                {form.errors.details && <p role="alert" className="text-sm text-red-700">{form.errors.details}</p>}
                <button className={primaryClass} disabled={form.processing}>Save Job Check</button>
            </form>
        </div>
    );
}

export default function EmployerJobChecks(props) {
    const title = props.jobCheck?.role_title ?? "Employer Job Checks";

    return (
        <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
            <Head title={title} />
            <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
            {props.jobCheck
                ? <CaseDetail key={props.jobCheck.id} {...props} />
                : <CaseList {...props} />}
        </div>
    );
}
