import React, { useEffect, useRef, useState } from "react";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";

// Render the actual private PDF bytes, including in browsers without an
// embedded PDF plug-in. Nothing is uploaded to an external document viewer.
export default function PdfDocumentPreview({ url, title = "Document preview", className = "" }) {
    const pages = useRef(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [zoom, setZoom] = useState(100);
    const downloadUrl = new URL(url, window.location.origin);
    downloadUrl.searchParams.delete("inline");
    downloadUrl.searchParams.set("download", "1");
    useEffect(() => {
        let cancelled = false;
        let loadingTask;
        let rendering;
        setLoading(true); setError("");
        pages.current?.replaceChildren();
        (async () => {
            const pdfjs = await import("pdfjs-dist");
            if (cancelled) return;
            pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
            loadingTask = pdfjs.getDocument({ url, withCredentials: true, isEvalSupported: false });
            const document = await loadingTask.promise;
            for (let number = 1; number <= document.numPages && !cancelled; number++) {
                const page = await document.getPage(number);
                if (cancelled) break;
                const viewport = page.getViewport({ scale: 1.5 });
                const canvas = window.document.createElement("canvas");
                canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
                canvas.style.cssText = `display:block;width:${zoom}%;height:auto;margin:0 auto 12px;background:white;`;
                canvas.setAttribute("role", "img"); canvas.setAttribute("aria-label", `Page ${number} of ${document.numPages}`);
                pages.current?.appendChild(canvas);
                rendering = page.render({ canvas, canvasContext: canvas.getContext("2d"), viewport });
                await rendering.promise;
            }
            if (!cancelled) setLoading(false);
        })().catch(() => {
            if (!cancelled) { setLoading(false); setError("Could not display the PDF. Download it below, or generate the documents again if the preview expired."); }
        });
        return () => { cancelled = true; rendering?.cancel(); loadingTask?.destroy(); };
    }, [url, zoom]);
    return <section aria-label={title} className={`flex min-h-0 flex-col bg-gray-100 ${className}`}>
        <div className="flex flex-shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-2 text-xs">
            <label className="flex items-center gap-2">Zoom
                <select aria-label={`${title} zoom`} value={zoom} onChange={(event) => setZoom(Number(event.target.value))} className="rounded border border-gray-300 px-1 py-0.5">
                    <option value={100}>Fit width</option><option value={150}>150%</option><option value={200}>200%</option>
                </select>
            </label>
            <a href={downloadUrl.pathname + downloadUrl.search} download className="font-semibold text-teal-800 underline">Download PDF</a>
        </div>
        <div className="min-h-0 flex-1 overflow-auto p-2">
            {loading && <p role="status" className="p-3 text-sm text-gray-600">Loading PDF…</p>}
            {error && <p role="alert" className="p-3 text-sm text-red-700">{error}</p>}
            <div ref={pages} />
        </div>
    </section>;
}
