import { useState } from "react";
import { Link, router } from "@inertiajs/react";
import { confirmDialog } from "../ui/ConfirmDialog";

const labels = { activate: "Reactivate account", disable: "Disable account", archive: "Archive partner", restore: "Restore partner" };
const descriptions = {
    activate: "Restore portal access and the partner’s referral link.",
    disable: "Suspend portal access and stop new submissions through the partner’s referral link.",
    archive: "Move this account to Archived. Its referral history and agreements will remain available to administrators.",
    restore: "Return this partner to the directory. Portal access stays closed until the account is eligible and activated.",
};

export default function AccountActions({ agent }) {
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const act = async action => {
        const message = action === "archive" && agent.account_state === "awaiting_signatures"
            ? "Close the current signing invitation and archive this partner. After restore, issue a new agreement for both parties to sign."
            : descriptions[action];
        if (busy || !await confirmDialog({ title: labels[action], message, confirmText: labels[action] })) return;
        setBusy(true);
        setError("");
        router.post(agent.lifecycle_url, { action, expected: agent.account_state }, {
            preserveScroll: true,
            onError: errors => setError(errors.user || "Refresh the profile and try again."),
            onFinish: () => setBusy(false),
        });
    };
    return <section className="rounded-2xl border border-[#e1e7dc] bg-white p-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
            <div><h2 className="text-lg font-semibold">Partner account</h2>
                <p className="mt-1 text-sm capitalize">{agent.account_state?.replace("_", " ")}</p></div>
            <div className="flex flex-wrap gap-2">
                {agent.application_url && <Link className="affiliate-button" href={agent.application_url}>View application</Link>}
                {(agent.account_actions || []).map(action => <button key={action} className="affiliate-button" disabled={busy} onClick={() => act(action)}>{labels[action]}</button>)}
            </div>
        </div>
        {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
    </section>;
}
