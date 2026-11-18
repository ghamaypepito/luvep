import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";

export default function useEngagementWorkflow(caseId, settings, draft, onClose) {
    const [prepared, setPrepared] = useState(null);
    const [savedPackId, setSavedPackId] = useState(draft ? (draft.pack_id || "legacy") : null);
    const [busy, setBusy] = useState(null);
    const [review, setReview] = useState(null);
    const [error, setError] = useState("");
    const signature = JSON.stringify([caseId, settings]);
    const previous = useRef(signature);
    const latest = useRef(signature);
    latest.current = signature;
    const inFlight = useRef(false);
    useEffect(() => {
        if (previous.current !== signature) {
            setPrepared(null); setSavedPackId(null); setReview(null); setError("");
            previous.current = signature;
        }
    }, [signature]);

    const request = async (action, body) => {
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || "");
        const response = await fetch(`/admin/leads/${caseId}/engagement/${action}`, {
            method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrf },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(" ") || data.message || "The request failed. Try again.");
        return data;
    };
    const run = async (action, body, success) => {
        if (inFlight.current) return;
        inFlight.current = true; setBusy(action); setError("");
        const startedWith = signature;
        try { const data = await request(action, body); if (latest.current === startedWith) success(data); }
        catch (failure) { setError(failure.message); }
        finally { inFlight.current = false; setBusy(null); }
    };
    return {
        prepared, savedPackId, busy, review, error,
        generate: () => run("prepare", settings, (data) => { setPrepared(data); setSavedPackId(null); toast.success("Documents generated for preview. No email sent."); }),
        save: () => prepared && run("save", { token: prepared.token }, (data) => {
            setSavedPackId(data.pack_id); toast.success("Draft saved. No email sent."); router.reload({ only: ["generated", "cases"], preserveScroll: true });
        }),
        verify: () => savedPackId && run("review", { pack_id: savedPackId }, setReview),
        closeReview: () => { if (!inFlight.current) { setReview(null); setError(""); } },
        send: (token) => run("send-reviewed", { token }, () => {
            toast.success("Engagement emailed to the client."); setReview(null); onClose(); router.reload({ only: ["generated"] });
        }),
    };
}
