import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { router } from "@inertiajs/react";
import { immBase } from "@/lib/caseRoutes";

export default function RecentVisaDialog({ doc, leadId, onClose }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const dialog = useRef(null);
    const cancel = useRef(null);
    useEffect(() => {
        const opener = document.activeElement;
        const modal = dialog.current;
        modal.showModal();
        cancel.current?.focus();
        return () => { modal.close(); opener?.focus(); };
    }, []);
    const save = () => {
        if (busy) return;
        setBusy(true);
        setError("");
        router.post(immBase() + "/cases/" + leadId + "/documents/" + doc.id + "/recent-visa", {}, {
            preserveScroll: true, preserveState: true,
            only: ["documents", "checklist", "checklistGrouped", "checklistProgress", "unstructuredDocuments", "activity", "flash"],
            onSuccess: onClose,
            onError: errors => setError(Object.values(errors).flat().join(" ") || "Could not classify this document."),
            onFinish: () => setBusy(false),
        });
    };
    return createPortal(
        <dialog ref={dialog} aria-labelledby="recent-visa-title"
            className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl bg-white p-5 shadow-xl backdrop:bg-black/40"
            onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }}>
            <h3 id="recent-visa-title" className="text-base font-semibold">Move to Recent visa / eVisa</h3>
            <p className="mt-3 break-words text-sm text-gray-700">{doc.original_name}</p>
            <p className="mt-2 text-sm text-gray-600">Confirm this file is a recent visa grant or eVisa for this client. Its file, upload details and review status will be kept.</p>
            <p className="mt-2 text-sm text-gray-600">If needed, an optional Recent visa / eVisa checklist row will be added to this case only, initially hidden from the client. Staff can manage its visibility using the checklist checkbox.</p>
            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
            <div className="mt-5 flex justify-end gap-2">
                <button ref={cancel} type="button" disabled={busy} onClick={onClose} className="rounded-lg border px-3 py-2 text-sm disabled:opacity-50">Cancel</button>
                <button type="button" disabled={busy} onClick={save} className="rounded-lg bg-gray-900 px-3 py-2 text-sm text-white disabled:opacity-50">{busy ? "Moving…" : "Move document"}</button>
            </div>
        </dialog>, document.body,
    );
}
