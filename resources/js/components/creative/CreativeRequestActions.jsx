import { router } from "@inertiajs/react";
import { useEffect, useId, useRef, useState } from "react";

export default function CreativeRequestActions({ creative }) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState("");
    const pending = useRef(false);
    const dialog = useRef(null);
    const cancel = useRef(null);
    const titleId = useId();

    useEffect(() => {
        if (confirming) {
            const modal = dialog.current;
            modal.showModal();
            cancel.current.focus();
            return () => { if (modal.open) modal.close(); };
        }
    }, [confirming]);

    function dismiss() {
        dialog.current?.close();
        setConfirming(false);
    }

    function submit(action) {
        if (pending.current) return;
        pending.current = true;
        setProcessing(true);
        setError("");
        const data = { expected_status: creative.status };
        const options = {
            preserveScroll: true,
            onSuccess: dismiss,
            onError: errors => setError(Object.values(errors).flat().join(" ") || "Could not save. Please try again."),
            onFinish: () => { pending.current = false; setProcessing(false); },
        };
        if (action === "delete") router.delete(`/creative-requests/${creative.id}`, { ...options, data });
        else router.post(`/creative-requests/${creative.id}/${action === "deliver" ? "status" : "restore"}`, action === "deliver" ? { ...data, status: "completed" } : data, options);
    }

    return <div className="cw-request-actions">
        <div className="cw-actions" aria-label={`Actions for ${creative.request_ref}`}>
            {creative.can_manage && creative.status !== "completed" && <button type="button" className="cw-text-button" disabled={processing} onClick={() => submit("deliver")}>Mark delivered</button>}
            {creative.can_delete && <button type="button" className="cw-text-button cw-danger-text" disabled={processing} onClick={() => { setError(""); setConfirming(true); }}>Delete</button>}
            {creative.can_restore && <button type="button" className="cw-text-button" disabled={processing} onClick={() => submit("restore")}>Restore</button>}
        </div>
        {!confirming && error && <p className="cw-error" role="alert">{error}</p>}
        {confirming && <dialog ref={dialog} className="cw-delete-dialog" aria-labelledby={titleId} onCancel={event => { event.preventDefault(); if (!pending.current) dismiss(); }}>
            <h2 id={titleId}>Delete this request?</h2>
            <p><strong>{creative.title}</strong> will move to Deleted requests. Its task will leave the active task board and DTR work list.</p>
            <p>The brief, reference files and task are kept. An administrator can restore them.</p>
            {error && <p className="cw-error" role="alert">{error}</p>}
            <div className="cw-actions"><button ref={cancel} type="button" className="cw-button cw-secondary" disabled={processing} onClick={dismiss}>Cancel</button><button type="button" className="cw-button cw-danger-button" disabled={processing} onClick={() => submit("delete")}>{processing ? "Deleting…" : "Delete request"}</button></div>
        </dialog>}
    </div>;
}
