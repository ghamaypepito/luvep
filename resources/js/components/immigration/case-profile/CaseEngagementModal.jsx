import React from "react";
import { NewEngagementModal } from "@/pages/portal/immigration/Engagement";

export default function CaseEngagementModal({ leadId, leadName, engagement = {}, onClose }) {
    const selectedCase = { id: leadId, name: leadName, email: engagement.email };
    return <NewEngagementModal cases={[selectedCase]} initialCase={selectedCase}
        documents={engagement.documents || []} signers={engagement.signers || []}
        defaultSignerId={engagement.default_signer_id} onClose={onClose} />;
}
