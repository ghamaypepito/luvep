import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import PdfDocumentPreview from "./PdfDocumentPreview";

export default function EngagementEmailReview({ review, busy = false, error = "", onClose, onSend }) {
    const dialog = useRef(null);
    const cancel = useRef(null);
    const [checked, setChecked] = useState(false);
    const [attachment, setAttachment] = useState(review.documents[0] ?? null);
    useEffect(() => {
        const previous = document.activeElement;
        dialog.current?.showModal();
        cancel.current?.focus();
        return () => { dialog.current?.close(); previous?.focus?.(); };
    }, []);
    return createPortal(
        <dialog ref={dialog} aria-labelledby="engagement-review-title"
            className="m-auto w-[min(1200px,96vw)] max-w-none rounded-2xl p-0 text-gray-900 backdrop:bg-black/50"
            onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }}
            onClick={(event) => { if (event.target === dialog.current && !busy) onClose(); }}>
            <div className="flex max-h-[92dvh] flex-col" onClick={(event) => event.stopPropagation()}>
                <header className="border-b border-gray-200 px-5 py-4">
                    <h2 id="engagement-review-title" className="text-lg font-semibold">Verify &amp; Email</h2>
                    <p className="mt-1 text-sm text-gray-600">Review the email and every attachment before sending to the client.</p>
                </header>
                <div className="grid min-h-0 flex-1 overflow-y-auto lg:grid-cols-[1fr_420px]">
                    <section className="min-w-0 p-5">
                        <dl className="mb-4 space-y-1.5 break-words text-sm">
                            <div><dt className="inline font-semibold">From: </dt><dd className="inline">{review.from}</dd></div>
                            <div><dt className="inline font-semibold">To: </dt><dd className="inline">{review.to.join(", ")}</dd></div>
                            {review.cc?.length > 0 && <div><dt className="inline font-semibold">Cc: </dt><dd className="inline">{review.cc.join(", ")}</dd></div>}
                            {review.bcc?.length > 0 && <div><dt className="inline font-semibold">Bcc: </dt><dd className="inline">{review.bcc.join(", ")}</dd></div>}
                            <div><dt className="inline font-semibold">Subject: </dt><dd className="inline">{review.subject}</dd></div>
                        </dl>
                        <iframe title="Email preview" srcDoc={review.html} sandbox="" className="h-[45dvh] min-h-[280px] w-full rounded-lg border border-gray-200 bg-white" />
                    </section>
                    <section className="min-w-0 border-t border-gray-200 p-5 lg:border-l lg:border-t-0">
                        <h3 className="text-sm font-semibold">Attachments ({review.documents.length})</h3>
                        <div className="my-3 space-y-2">
                            {review.documents.map((file) => <button key={file.preview_url} type="button"
                                onClick={() => setAttachment(file)} aria-pressed={attachment?.preview_url === file.preview_url}
                                className={`w-full break-words rounded-lg border px-3 py-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 ${attachment?.preview_url === file.preview_url ? "border-teal-700 bg-teal-50 text-teal-900" : "border-gray-300 hover:bg-gray-50"}`}>
                                {file.name}
                            </button>)}
                        </div>
                        {attachment && <PdfDocumentPreview title="Attachment preview" url={attachment.preview_url} className="h-[37dvh] min-h-[240px] w-full rounded-lg border border-gray-200" />}
                    </section>
                </div>
                <footer className="space-y-3 border-t border-gray-200 px-5 py-4">
                    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
                    <label className="flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={checked} disabled={busy} onChange={(event) => setChecked(event.target.checked)} className="mt-1" />
                        I checked the recipient, email content, and attached documents.
                    </label>
                    <div className="flex justify-end gap-2">
                        <button ref={cancel} type="button" disabled={busy} onClick={onClose} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold hover:bg-gray-50 disabled:opacity-50">Cancel</button>
                        <button type="button" disabled={busy || !checked} onClick={() => { if (checked && !busy) onSend(review.token); }}
                            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-50">
                            {busy ? "Sending…" : "Send Email"}
                        </button>
                    </div>
                </footer>
            </div>
        </dialog>, document.body
    );
}
