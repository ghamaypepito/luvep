import { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { confirmDialog } from "../../../components/ui/ConfirmDialog";

export default function Application({ application }) {
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const decide = async approved => {
        const url = approved ? application.approve_url : application.decline_url;
        if (!url || busy) return;
        if (!approved && !await confirmDialog({ title: "Decline this application?", message: "EP will email the applicant with a link to their decision.", confirmText: "Decline application" })) return;
        setBusy(true);
        setError("");
        router.post(url, {}, { onError: errors => setError(errors.application || "Refresh the application and try again."), onFinish: () => setBusy(false) });
    };
    const date = value => value && new Date(value).toLocaleDateString("en-NZ");
    return <div className="max-w-3xl space-y-5">
        <Head title={application.name + " · Affiliate application"} />
        <Link href="/admin/agents/applications" className="text-sm font-semibold text-[#285d46] underline">All applications</Link>
        <header><h1 className="text-3xl font-semibold">{application.name}</h1>
            <p className="mt-2 text-sm text-[#53655a]">Affiliate application · {application.status}</p></header>
        <section className="rounded-2xl border border-[#e1e7dc] bg-white p-6">
            <h2 className="text-lg font-semibold">Application details</h2>
            <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                {[["Email", application.email], ["Phone", application.phone], ["Country or location", application.location], ["Company", application.company], ["Submitted", date(application.created_at)], ["Email verified", date(application.verified_at)], ["Reviewed", date(application.reviewed_at)], ["Reviewer", application.reviewer], ["Selected agreement", application.agreement_version && "Version " + application.agreement_version], ["Activated", date(application.activated_at)]]
                    .filter(([,value]) => value).map(([label,value]) => <div key={label}><dt className="text-xs text-[#53655a]">{label}</dt><dd className="mt-1 break-words text-sm font-semibold">{value}</dd></div>)}
            </dl>
        </section>
        {application.approve_url && <section className="rounded-2xl border border-[#dbe7ca] bg-[#f2f7e8] p-6">
            <h2 className="text-lg font-semibold">Review application</h2>
            <p className="mt-2 text-sm">Approval creates an inactive partner account. Prepare the agreement on the partner profile. Both parties must sign before the account opens.</p>
            <div className="mt-4 flex flex-wrap gap-2"><button className="affiliate-button affiliate-button-primary" disabled={busy} onClick={() => decide(true)}>Approve application</button>
                <button className="affiliate-button" disabled={busy} onClick={() => decide(false)}>Decline application</button></div>
            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
        </section>}
        {application.partner_url && <Link className="affiliate-button affiliate-button-primary" href={application.partner_url}>Open partner profile</Link>}
    </div>;
}
