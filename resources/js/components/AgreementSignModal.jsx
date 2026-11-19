import { useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import SignatureCanvas from "react-signature-canvas";
import {
    FileSignature, Eye, PenTool, Eraser, Upload, ShieldCheck,
    AlertCircle, Loader2, X, Type, FileText,
} from "lucide-react";

// Render a typed name into a script-font signature image (data URL) — same
// approach as the staff SignatureCard's "Auto-generated" mode, so it stays
// visually consistent across the app.
function renderNameSignature(name) {
    const text = (name || "").trim();
    if (! text || typeof document === "undefined") return "";
    const canvas = document.createElement("canvas");
    canvas.width = 560;
    canvas.height = 170;
    const ctx = canvas.getContext("2d");
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "#111827";
    ctx.textBaseline = "middle";
    ctx.textAlign = "center";
    const fontAt = (px) => `italic ${px}px "Segoe Script","Brush Script MT","Snell Roundhand","Apple Chancery","Comic Sans MS",cursive`;
    let size = 66;
    ctx.font = fontAt(size);
    while (size > 22 && ctx.measureText(text).width > 520) {
        size -= 4;
        ctx.font = fontAt(size);
    }
    ctx.fillText(text, 280, 90);
    return canvas.toDataURL("image/png");
}

/**
 * Shared e-signature modal — the uniform draw/upload capture used across the
 * app (mirrors SignatureCard's modes). Type your legal name, draw or upload a
 * signature, and POST it. The signature is sent as `signature_data` (a base64
 * PNG data URL) regardless of draw vs upload, so every backend path is identical.
 *
 * Props:
 *   postUrl      — where to POST { signer_name, signature_data, terms_accepted? }
 *   title        — modal heading
 *   requireTerms — show + require the "I have read and agree" checkbox (agent side)
 *   reviewUrl    — optional link to open the PDF before signing
 *   onClose      — close handler
 */
export default function AgreementSignModal({
    postUrl,
    agreementId = null,
    title = "Sign agreement",
    requireTerms = false,
    reviewUrl = null,
    // Optional extra inputs collected alongside the signature (e.g. the
    // Affiliate Partner's Schedule B bank + contact details). Each is
    // { key, label, placeholder, type? }. `extraInitial` seeds their values.
    extraFields = [],
    extraInitial = {},
    extraTitle = null,
    // When set, the modal opens wide with a live document preview on the right
    // (like the staff generate modal). The preview reflects the extra-field
    // edits so the agent sees their details land in the doc as they type.
    previewUrl = null,
    onClose,
}) {
    const sigRef = useRef(null);
    const [mode, setMode] = useState("auto"); // auto | draw | upload
    const [signerName, setSignerName] = useState("");
    const [terms, setTerms] = useState(false);
    const [hasInk, setHasInk] = useState(false);
    const [uploadData, setUploadData] = useState(null);
    const [extra, setExtra] = useState(() => {
        const seed = {};
        extraFields.forEach((f) => { seed[f.key] = extraInitial[f.key] ?? ""; });
        return seed;
    });
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});
    const [serverError, setServerError] = useState(null);

    const clear = () => { sigRef.current?.clear(); setHasInk(false); };

    const onPickFile = (e) => {
        const file = e.target.files?.[0];
        if (! file) return;
        const reader = new FileReader();
        reader.onload = () => setUploadData(reader.result);
        reader.readAsDataURL(file);
    };

    // The auto-generated signature (script render of the typed name), recomputed
    // as the name changes so its preview updates live.
    const autoData = useMemo(() => renderNameSignature(signerName), [signerName]);

    // Live document preview (wide layout) — debounced so it refreshes shortly
    // after the agent stops typing their bank/contact details.
    const [previewFields, setPreviewFields] = useState(() => ({ ...extra }));
    const [previewLoading, setPreviewLoading] = useState(true);
    const debounce = useRef(null);
    useEffect(() => {
        if (! previewUrl) return;
        clearTimeout(debounce.current);
        debounce.current = setTimeout(() => setPreviewFields({ ...extra }), 500);
        return () => clearTimeout(debounce.current);
    }, [extra, previewUrl]);
    const previewSrc = useMemo(() => {
        if (! previewUrl) return null;
        const params = new URLSearchParams(previewUrl.split("?")[1] || "");
        Object.entries(previewFields).forEach(([k, v]) => params.set(k, v ?? ""));
        const q = params.toString();
        return q ? `${previewUrl.split("?")[0]}?${q}` : previewUrl;
    }, [previewUrl, previewFields]);
    useEffect(() => { if (previewSrc) setPreviewLoading(true); }, [previewSrc]);

    const signatureData = () =>
        mode === "auto" ? autoData
            : mode === "upload" ? (uploadData || "")
                : (sigRef.current?.getCanvas().toDataURL("image/png") || "");

    const validate = () => {
        const e = {};
        if (! signerName.trim()) e.signer_name = "Please type your full legal name.";
        if (requireTerms && ! terms) e.terms_accepted = "You must agree to the terms to continue.";
        if (mode === "auto" && ! autoData) e.signature = "Type your name above to auto-generate a signature.";
        if (mode === "draw" && (! hasInk || sigRef.current?.isEmpty())) e.signature = "Please draw your signature below.";
        if (mode === "upload" && ! uploadData) e.signature = "Please choose a signature image to upload.";
        setErrors(e);
        return Object.keys(e).length === 0;
    };

    const submit = (event) => {
        event.preventDefault();
        if (submitting) return;
        setServerError(null);
        if (! validate()) return;

        setSubmitting(true);
        const payload = { signer_name: signerName.trim(), signature_data: signatureData(), ...extra };
        if (agreementId !== null) payload.agreement_id = agreementId;
        if (requireTerms) payload.terms_accepted = terms ? 1 : 0;

        router.post(postUrl, payload, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errs) => {
                setErrors(errs);
                setServerError(Object.values(errs)[0] || "Signature could not be recorded.");
            },
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <div role="dialog" aria-modal="true" aria-label={title} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-3">
            <div className={`bg-white rounded-2xl shadow-xl w-full flex flex-col overflow-hidden ${previewUrl ? "max-w-[1400px] h-[94vh]" : "max-w-2xl max-h-[94vh]"}`}>
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between shrink-0">
                    <h3 className="text-[15px] font-bold text-gray-900 flex items-center gap-2">
                        <FileSignature size={17} /> {title}
                    </h3>
                    <button type="button" aria-label="Close signing dialog" onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center"><X size={18} /></button>
                </div>

                <div className="flex-1 flex min-h-0">
                <form onSubmit={submit} className={`overflow-y-auto p-5 space-y-4 ${previewUrl ? "w-[440px] border-r border-gray-100 shrink-0" : "flex-1"}`}>
                    {serverError && (
                        <div className="flex items-start gap-2 p-3 rounded-lg bg-red-50 border border-red-100 text-xs text-red-800">
                            <AlertCircle size={14} className="shrink-0 mt-0.5" /> {serverError}
                        </div>
                    )}

                    {reviewUrl && (
                        <a href={reviewUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-700 hover:text-gray-900 underline underline-offset-2">
                            <Eye size={13} /> Open the agreement to review before signing
                        </a>
                    )}

                    {extraFields.length > 0 && (
                        <div className="rounded-lg border border-gray-200 bg-gray-50/60 p-4 space-y-3">
                            {extraTitle && <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500">{extraTitle}</p>}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                {extraFields.map((f) => (
                                    <label key={f.key} className={`block ${f.type === "textarea" ? "sm:col-span-2" : ""}`}>
                                        <span className="block text-[11px] font-semibold text-gray-600 mb-1">{f.label}</span>
                                        {f.type === "textarea" ? (
                                            <textarea
                                                rows={2}
                                                value={extra[f.key] ?? ""}
                                                onChange={(e) => setExtra((x) => ({ ...x, [f.key]: e.target.value }))}
                                                placeholder={f.placeholder}
                                                className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-gray-900 bg-white"
                                            />
                                        ) : (
                                            <input
                                                type="text"
                                                value={extra[f.key] ?? ""}
                                                onChange={(e) => setExtra((x) => ({ ...x, [f.key]: e.target.value }))}
                                                placeholder={f.placeholder}
                                                maxLength={500}
                                                className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-gray-900 bg-white"
                                            />
                                        )}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    {requireTerms && (
                        <>
                            <label className="flex items-start gap-2.5 cursor-pointer">
                                <input type="checkbox" checked={terms} onChange={(e) => setTerms(e.target.checked)} className="mt-1 w-4 h-4" />
                                <span className="text-sm text-gray-900">I have read and agree to the terms of this Affiliate Partner Agreement.</span>
                            </label>
                            {errors.terms_accepted && <p className="text-xs text-red-700 -mt-2">{errors.terms_accepted}</p>}
                        </>
                    )}

                    <div>
                        <label htmlFor="agreement-signer-name" className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Type your full legal name</label>
                        <input
                            type="text"
                            id="agreement-signer-name"
                            value={signerName}
                            onChange={(e) => setSignerName(e.target.value)}
                            maxLength={200}
                            placeholder="e.g. Lillian Novida Ejorango"
                            className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:border-gray-900"
                        />
                        {errors.signer_name && <p className="text-xs text-red-700 mt-1">{errors.signer_name}</p>}
                    </div>

                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <label className="text-[11px] font-bold uppercase tracking-wider text-gray-500">Your signature</label>
                            <div className="flex items-center gap-2">
                                {[
                                    { key: "auto", label: "Auto-generated", icon: <Type size={12} /> },
                                    { key: "draw", label: "Draw", icon: <PenTool size={12} /> },
                                    { key: "upload", label: "Upload", icon: <Upload size={12} /> },
                                ].map((t) => (
                                    <button
                                        key={t.key}
                                        type="button"
                                        onClick={() => setMode(t.key)}
                                        className={`text-[11px] font-semibold px-2.5 py-1 rounded-lg inline-flex items-center gap-1.5 transition-colors ${mode === t.key ? "bg-gray-900 text-white" : "bg-gray-100 text-gray-600 hover:bg-gray-200"}`}
                                    >
                                        {t.icon} {t.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {mode === "auto" ? (
                            <div className="border-2 border-dashed border-gray-200 rounded-md bg-white h-[180px] flex items-center justify-center overflow-hidden">
                                {autoData
                                    ? <img src={autoData} alt="Signature preview" className="max-h-[140px] max-w-[85%] object-contain" />
                                    : <p className="text-xs text-gray-400 px-4 text-center">Type your full legal name above to auto-generate a signature.</p>}
                            </div>
                        ) : mode === "draw" ? (
                            <>
                                <div className="border border-gray-300 rounded-md bg-white" style={{ touchAction: "none" }}>
                                    <SignatureCanvas
                                        ref={sigRef}
                                        onBegin={() => setHasInk(true)}
                                        penColor="#111"
                                        canvasProps={{ width: 720, height: 180, className: "w-full h-[180px]" }}
                                    />
                                </div>
                                <div className="flex justify-end mt-1.5">
                                    <button type="button" onClick={clear} className="inline-flex items-center gap-1 text-[11px] text-gray-500 hover:text-gray-900"><Eraser size={11} /> Clear</button>
                                </div>
                            </>
                        ) : (
                            <label className="border-2 border-dashed border-gray-300 rounded-md bg-white flex flex-col items-center justify-center h-[180px] cursor-pointer hover:border-gray-400 transition-colors">
                                {uploadData ? (
                                    <img src={uploadData} alt="Signature preview" className="max-h-[130px] max-w-[80%] object-contain" />
                                ) : (
                                    <>
                                        <Upload size={22} className="text-gray-300" />
                                        <span className="text-xs text-gray-400 mt-2">Click to choose a PNG or JPG</span>
                                        <span className="text-[10.5px] text-gray-300 mt-0.5">Transparent PNG works best</span>
                                    </>
                                )}
                                <input type="file" accept="image/png,image/jpeg" className="hidden" onChange={onPickFile} />
                            </label>
                        )}
                        {errors.signature && <p className="text-xs text-red-700 mt-1">{errors.signature}</p>}
                    </div>

                    <p className="text-[10.5px] text-gray-400 inline-flex items-center gap-1.5">
                        <ShieldCheck size={11} /> We record your typed name, signature, and timestamp for the audit trail.
                    </p>
                </form>

                {previewUrl && (
                    <div className="flex-1 flex flex-col min-h-0 bg-gray-50">
                        <div className="px-4 py-2.5 border-b border-gray-100 bg-white flex items-center gap-2 shrink-0">
                            <span className="text-[11px] font-bold uppercase tracking-[0.14em] text-gray-500">Preview</span>
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-900 text-white text-[11px] font-semibold">
                                <FileText size={11} /> Affiliate Partner Agreement
                            </span>
                        </div>
                        <div className="flex-1 relative min-h-0">
                            <iframe
                                key={previewSrc}
                                src={previewSrc}
                                title="Agreement preview"
                                sandbox="allow-same-origin"
                                onLoad={() => setPreviewLoading(false)}
                                className="absolute inset-0 w-full h-full bg-white"
                            />
                            {previewLoading && (
                                <div className="absolute inset-0 flex items-center justify-center bg-white/85 backdrop-blur-sm z-10">
                                    <Loader2 size={22} className="animate-spin text-gray-500" />
                                </div>
                            )}
                        </div>
                    </div>
                )}
                </div>

                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-3 shrink-0 bg-white">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={submitting}
                        className="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-sm font-bold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                    >
                        {submitting && <Loader2 size={13} className="animate-spin" />} Sign agreement →
                    </button>
                </div>
            </div>
        </div>
    );
}
