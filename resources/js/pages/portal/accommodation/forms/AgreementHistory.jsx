import { Head, Link, useForm } from "@inertiajs/react";

const root = "/portal/accommodation/forms/agreements";
const button = "inline-flex rounded-lg bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50";
const link = "text-sm font-semibold text-blue-700 underline";
const labels = {
    signing_link: "Client signing link",
    client_copy: "Completed PDF to client",
    exalt_copy: "Completed PDF to Exalt",
};
const states = { queued: "Queued", sent: "Sent", failed: "Failed" };
const dateTime = new Intl.DateTimeFormat("en-NZ", {
    dateStyle: "medium", timeStyle: "short", timeZone: "Pacific/Auckland",
});
const format = (timestamp) => dateTime.format(new Date(timestamp));

export default function AgreementHistory({ agreement, emails, originalUrl, countersignedUrl, reviewHash, needsExaltSignature, canEmailExalt, exaltMailboxConfigured }) {
    const signature = useForm({ original_hash: reviewHash, confirmed: false });
    const exaltEmail = useForm({});
    const base = root + "/" + agreement.id;
    const milestones = [
        ["Agreement issued", agreement.issued],
        ["Signing page viewed", agreement.viewed],
        [agreement.tracksAllTenants ? "All required tenants signed" : "Client signature recorded", agreement.signed],
        ["Exalt countersigned", agreement.countersign?.signed_at],
    ];

    return <main className="mx-auto max-w-4xl space-y-5 p-4 sm:p-6">
        <Head title="Agreement history" />
        <Link className={link} href={root}>Back to Generated Agreements</Link>
        <section className="space-y-3 rounded-xl border bg-white p-5">
            <h1 className="text-2xl font-bold">{agreement.name} · Agreement history</h1>
            <p className="text-sm text-gray-600">Agreement #{agreement.id} · {agreement.type}</p>
            <div className="flex flex-wrap gap-4">
                {originalUrl && <a className={link} href={originalUrl + "?inline=1"} target="_blank" rel="noreferrer">Open original signed PDF</a>}
                {countersignedUrl && <a className={link} href={countersignedUrl}>Download Exalt countersigned PDF</a>}
            </div>
            {needsExaltSignature && !reviewHash && <p className="text-sm text-gray-600">This original needs Ma’am Alane’s review for an Exalt countersignature.</p>}
        </section>
        <section className="space-y-3 rounded-xl border bg-white p-5">
            <h2 className="text-lg font-semibold">Signing activity</h2>
            <dl className="space-y-2 text-sm">
                {agreement.exaltSigned && <div className="flex justify-between gap-3"><dt>Exalt signature applied at issue</dt><dd>{agreement.exaltSigned}</dd></div>}
                {milestones.filter(([, date]) => date).map(([label, date]) => <div className="flex justify-between gap-3" key={label}>
                    <dt>{label}</dt><dd>{format(date)} NZ time</dd>
                </div>)}
                {agreement.countersign?.name && <div className="flex justify-between"><dt>Exalt signatory</dt><dd>{agreement.countersign.name}</dd></div>}
            </dl>
        </section>
        <section className="space-y-3 rounded-xl border bg-white p-5">
            <h2 className="text-lg font-semibold">Email history</h2>
            {emails.length === 0 ? <p className="text-sm text-gray-600">There are no tracked email attempts on this record.</p> :
                <table className="w-full text-left text-sm">
                    <thead><tr><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Updated (NZ time)</th></tr></thead>
                    <tbody className="divide-y">{emails.map((email) => <tr key={email.id}>
                        <td className="py-2">{labels[email.kind]}</td><td>{states[email.status]}</td>
                        <td>{email.at ? format(email.at) : "—"}</td>
                    </tr>)}</tbody>
                </table>}
            {canEmailExalt && (exaltMailboxConfigured
                ? <button className={button} disabled={exaltEmail.processing} type="button" onClick={() => exaltEmail.post(base + "/email-exalt", { preserveScroll: true })}>Email completed PDF to Exalt</button>
                : <p className="text-sm text-gray-600">Exalt’s PDF email inbox needs to be configured.</p>)}
        </section>
        {reviewHash && <form className="space-y-3 rounded-xl border bg-white p-5" onSubmit={(event) => {
            event.preventDefault();
            signature.post(base + "/countersign", { preserveScroll: true });
        }}>
            <h2 className="text-lg font-semibold">Countersign for Exalt</h2>
            <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" checked={signature.data.confirmed} onChange={(event) => signature.setData("confirmed", event.target.checked)} />
                <span>I reviewed the original PDF, confirmed that every required tenant signature is present and Exalt has not signed it, and agree to countersign using my saved Exalt signature.</span>
            </label>
            {Object.entries(signature.errors).map(([key, message]) => <p role="alert" key={key} className="text-sm text-red-700">{message}</p>)}
            <button className={button} disabled={!signature.data.confirmed || signature.processing}>Countersign and save a copy</button>
        </form>}
    </main>;
}
