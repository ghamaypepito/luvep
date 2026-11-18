import { hrPath } from "@/lib/hrPath";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import { useState, useRef } from "react";
import axios from "axios";
import { Errors } from "./Dtr";
import MyInfoTabs from "@/components/hr/MyInfoTabs";
import PersonalDocumentUpload, { hrDocumentAccept } from "@/components/hr/PersonalDocumentUpload";
function Draft({ policy }) {
    const fileInput = useRef(null);
    const form = useForm({
        title: policy?.draft_title || "",
        body: policy?.draft_body || "",
        version: policy?.revision || 0,
        file: null,
    });
    return (
        <details className="hr-panel">
            <summary>
                <strong>
                    {policy
                        ? `Edit draft · ${policy.draft_title}`
                        : "Add a policy or guide"}
                </strong>
            </summary>
            <form
                className="hr-form hr-form-wide"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(
                        policy ? hrPath(`/policies/${policy.id}/draft`) : hrPath("/policies"),
                        {
                            preserveScroll: true,
                            forceFormData: true,
                            onSuccess: () => {
                                if (!policy) form.reset();
                                else form.reset("file");
                                if (fileInput.current) fileInput.current.value = "";
                            },
                        },
                    );
                }}
            >
                <fieldset className="hr-report-editor-fields" disabled={form.processing}>
                <Errors errors={form.errors} />
                <label>
                    Policy title
                    <input
                        required
                        value={form.data.title}
                        maxLength={200}
                        placeholder="For example, Leave Policy and Guide"
                        onChange={(e) => form.setData("title", e.target.value)}
                    />
                </label>
                <label>
                    Policy / guide text
                    <textarea
                        required
                        rows={14}
                        value={form.data.body}
                        maxLength={100000}
                        onChange={(e) => form.setData("body", e.target.value)}
                    />
                    <span>
                        Plain text with paragraphs. Publishing creates a
                        permanent version.
                    </span>
                </label>
                <label>Supporting file · PDF, Word, PNG or JPEG, up to 10 MiB
                    <input ref={fileInput} type="file" accept={hrDocumentAccept} disabled={form.processing} onChange={e => form.setData('file', e.target.files[0] || null)} />
                </label>
                {policy?.draft_attachment && <p>Current draft file: <a className="hr-text-link" href={policy.draft_attachment.url}>{policy.draft_attachment.name}</a></p>}
                <div className="hr-actions">
                    <button
                        className="hr-button-secondary"
                        disabled={form.processing}
                    >
                        Save draft
                    </button>
                    {policy && (
                        <button
                            type="button"
                            className="hr-button"
                            disabled={
                                form.processing ||
                                !form.data.title.trim() ||
                                !form.data.body.trim()
                            }
                            onClick={() => {
                                if (
                                    window.confirm(
                                        "Publish this exact text as a new policy version for employees?",
                                    )
                                )
                                    form.post(
                                        hrPath(`/policies/${policy.id}/publish`),
                                        { preserveScroll: true, forceFormData: true, onSuccess: () => { form.reset("file"); if (fileInput.current) fileInput.current.value = ""; } },
                                    );
                            }}
                        >
                            Publish new version
                        </button>
                    )}
                </div>
                </fieldset>
            </form>
        </details>
    );
}
function Published({ policy, manage }) {
    const { hr } = usePage().props;
    const [selected, select] = useState(policy.versions[0]?.id);
    const [report, setReport] = useState(null);
    const [reportError, setReportError] = useState("");
    const version =
        policy.versions.find((v) => v.id === selected) || policy.versions[0];
    const form = useForm({ declaration: false });
    if (!version) return null;
    const latest = version.id === policy.versions[0].id;
    return (
        <section className="hr-panel">
            <div className="hr-section-bar">
                <div>
                    <h2>{version.title}</h2>
                    <p>
                        Published{" "}
                        {new Date(version.published_at).toLocaleDateString()}
                    </p>
                </div>
                <label className="hr-select-label">
                    Version
                    <select
                        value={version.id}
                        onChange={(e) => {
                            select(Number(e.target.value));
                            form.reset();
                            setReport(null);
                        }}
                    >
                        {policy.versions.map((v) => (
                            <option value={v.id} key={v.id}>
                                {v.version}
                                {v.id === policy.versions[0].id
                                    ? " · latest"
                                    : ""}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {!latest && (
                <p className="hr-notice">
                    You are reading an older version. Acknowledging it does not
                    acknowledge the latest policy.
                </p>
            )}
            <div className="hr-policy-body">{version.body}</div>
            {version.attachment && <p><a className="hr-button-secondary" href={version.attachment.url}>Download {version.attachment.name}</a></p>}
            {version.acknowledged_at ? (
                <p className="hr-feedback">
                    Acknowledged{" "}
                    {new Date(version.acknowledged_at).toLocaleString()}
                </p>
            ) : (
                hr.employee &&
                !hr.preview && (
                    <form
                        className="hr-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                hrPath(`/policies/versions/${version.id}/acknowledge`),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Errors errors={form.errors} />
                        <label className="hr-checkbox">
                            <input
                                type="checkbox"
                                checked={form.data.declaration}
                                onChange={(e) =>
                                    form.setData(
                                        "declaration",
                                        e.target.checked,
                                    )
                                }
                            />
                            I confirm I have read this policy, version{" "}
                            {version.version}.
                        </label>
                        <div>
                            <button
                                className="hr-button"
                                disabled={
                                    form.processing || !form.data.declaration
                                }
                            >
                                Record acknowledgement
                            </button>
                        </div>
                    </form>
                )
            )}
            {manage && (
                <div className="hr-policy-report">
                    <button
                        className="hr-button-secondary"
                        onClick={async () => {
                            try {
                                const { data } = await axios.get(
                                    hrPath(`/policies/versions/${version.id}/acknowledgements`),
                                );
                                setReport(data.employees);
                                setReportError("");
                            } catch {
                                setReportError(
                                    "Unable to load acknowledgement status. Please retry.",
                                );
                            }
                        }}
                    >
                        View acknowledgement status
                    </button>
                    {reportError && (
                        <p className="hr-error" role="alert">
                            {reportError}
                        </p>
                    )}
                    {report && (
                        <ul className="hr-employee-list">
                            {report.map((e) => (
                                <li key={e.employee_id}>
                                    <strong>{e.name}</strong>
                                    <span>
                                        {e.acknowledged_at
                                            ? new Date(
                                                  e.acknowledged_at,
                                              ).toLocaleString()
                                            : "Awaiting acknowledgement"}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </section>
    );
}
export default function Policies({ policies, manage, scope }) {
    const { hr } = usePage().props;
    return (
        <>
            <Head title="Policies & guide · Luvep" />
            {scope === "mine" && <MyInfoTabs />}
            <div className="hr-page-heading">
                <h1>Policies & guide</h1>
                <p>
                    Read published workplace policies and record your
                    acknowledgement of the exact version.
                </p>
            </div>
            {scope === 'mine' && hr.permissions?.['policies.publish'] && <p><Link className="hr-text-link" href={hrPath('/policies')}>Manage official policies and guide</Link></p>}
            {hr.employee && !hr.preview && <PersonalDocumentUpload employeeId={hr.employee.id} policy />}
            {manage && <Draft />}
            {policies.map((p) => (
                <div key={p.id}>
                    {manage && (
                        <Draft key={`draft:${p.id}:${p.revision}`} policy={p} />
                    )}
                    <Published
                        key={`read:${p.id}:${p.revision}`}
                        policy={p}
                        manage={manage}
                    />
                </div>
            ))}
            {!policies.length && (
                <section className="hr-panel">
                    <h2>No published policies yet</h2>
                    <p>
                        HR will publish policies here when they are ready for
                        acknowledgement.
                    </p>
                </section>
            )}
        </>
    );
}
