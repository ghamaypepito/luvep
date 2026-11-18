import { useState } from "react";
import { router } from "@inertiajs/react";
import { CheckCircle2, Clock3, FileSignature } from "lucide-react";
import ApplicationLayout from "../../components/affiliate/ApplicationLayout";
import AgreementSignModal from "../../components/AgreementSignModal";

const instructions = {
    "Awaiting email verification": "Confirm your email address to send your application to EP for review.",
    "Awaiting review": "EP is reviewing your application. We’ll email you when there’s an update.",
    "Awaiting signatures": "EP has approved your application. Your account opens when you and EP have signed the same agreement.",
    "Declined": "EP has reviewed your application and declined it.",
    "Activated": "You and EP have signed your onboarding agreement.",
};

export default function Progress({ application, verifyUrl, setupUrl, agreement }) {
    const [signing, setSigning] = useState(false);
    const [verifying, setVerifying] = useState(false);
    return <ApplicationLayout title="Your affiliate application" description={application.name}>
        <section className="mt-6 rounded-xl border border-[#dbe7ca] bg-[#f2f7e8] p-5">
            <h2 className="text-lg font-semibold">{application.status}</h2>
            <p className="mt-2 text-sm leading-relaxed">{instructions[application.status]}</p>
            {!agreement && application.status === "Awaiting signatures" && <p className="mt-2 text-sm">Use the link in EP’s agreement invitation when it arrives.</p>}
            {verifyUrl && <button className="affiliate-button affiliate-button-primary mt-4" disabled={verifying}
                onClick={() => router.post(verifyUrl, {}, { onStart: () => setVerifying(true), onFinish: () => setVerifying(false) })}>Confirm my email</button>}
            {setupUrl && <a href={setupUrl} className="affiliate-button affiliate-button-primary mt-4">Set up your portal password</a>}
        </section>
        {agreement && <section className="mt-6 space-y-4">
            <div className="flex items-center gap-3"><span className="affiliate-icon"><FileSignature size={19} /></span><div>
                <h2 className="text-lg font-semibold">Affiliate Partner Agreement</h2>
                <p className="text-sm text-[#53655a]">Version {agreement.version} · {agreement.status}{agreement.expires_on && " · Expires " + agreement.expires_on}</p>
            </div></div>
            <ul className="divide-y divide-[#e2e9dd] rounded-xl border border-[#e2e9dd]">
                {[["Your signature", agreement.agent_signed], ["EP signature", agreement.company_signed]].map(([label, signed]) =>
                    <li key={label} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        {label}<span className="flex items-center gap-1.5">{signed ? <CheckCircle2 size={15} /> : <Clock3 size={15} />}{signed ? "Signed" : "Pending"}</span>
                    </li>)}
            </ul>
            <div className="flex flex-wrap gap-2">
                <a className="affiliate-button" href={agreement.document_url} target="_blank" rel="noreferrer">View PDF</a>
                <a className="affiliate-button" href={agreement.download_url}>Download PDF</a>
                {agreement.sign_url && <button className="affiliate-button affiliate-button-primary" onClick={() => setSigning(true)}>Sign agreement</button>}
            </div>
        </section>}
        {signing && <AgreementSignModal title={`Sign agreement · Version ${agreement.version}`} postUrl={agreement.sign_url} agreementId={agreement.id}
            requireTerms reviewUrl={agreement.document_url} extraFields={agreement.fields} extraInitial={agreement.initial}
            extraTitle="Bank and contact details" onClose={() => setSigning(false)} />}
    </ApplicationLayout>;
}
