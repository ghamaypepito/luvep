import { useEffect, useMemo, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import SignatureCanvas from "react-signature-canvas";
import { FileSignature, Eraser, ShieldCheck, AlertCircle, Loader2, Type, PenTool, Upload } from "lucide-react";

// Render a typed name into a script-font signature image (data URL).
function renderNameSignature(name) {
    const text = (name || "").trim();
    if (!text || typeof document === "undefined") return "";
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

// Public, tokenised e-signing page for an Exalt flat/house-sharing agreement.
// The agreement renders in an iframe; the client signs (type a cursive
// signature, draw one, or upload an image) and posts back.
export default function SignAgreement({ token, clientName, typeLabel, documentUrl, signers = [] }) {
    const sigRef = useRef(null);
    const [signerIndex, setSignerIndex] = useState(signers.find(s => !s.signed)?.index ?? 0);
    const [signerName, setSignerName] = useState(signers.find(s => !s.signed)?.name || clientName || "");
    const [termsAccepted, setTermsAccepted] = useState(false);
    const [sigMode, setSigMode] = useState("type"); // type | draw | upload
    const [hasInk, setHasInk] = useState(false);
    const [uploadDataUrl, setUploadDataUrl] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});
    const [serverError, setServerError] = useState(null);

    useEffect(() => {
        const next = signers.find(s => !s.signed);
        if (next && signers.find(s => s.index === signerIndex)?.signed) {
            setSignerIndex(next.index); setSignerName(next.name); setTermsAccepted(false);
            setUploadDataUrl(null); sigRef.current?.clear(); setHasInk(false);
        }
    }, [signers]);

    const typedSignature = useMemo(() => renderNameSignature(signerName), [signerName]);
    const clear = () => { sigRef.current?.clear(); setHasInk(false); };

    const onPickFile = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => setUploadDataUrl(reader.result);
        reader.readAsDataURL(file);
    };

    // The signature image (data URL) for the current mode.
    const currentSignature = () => {
        if (sigMode === "type") return typedSignature;
        if (sigMode === "upload") return uploadDataUrl || "";
        return sigRef.current && !sigRef.current.isEmpty() ? sigRef.current.getCanvas().toDataURL("image/png") : "";
    };

    const validate = () => {
        const e = {};
        if (!signerName.trim()) e.signer_name = "Please type your full legal name.";
        if (!termsAccepted) e.terms_accepted = "You must agree to the terms to continue.";
        if (!currentSignature()) {
            e.signature = sigMode === "draw" ? "Please sign in the box below."
                : sigMode === "upload" ? "Please upload your signature image."
                    : "Type your name to generate a signature.";
        }
        setErrors(e);
        return Object.keys(e).length === 0;
    };

    const submit = (event) => {
        event.preventDefault();
        if (submitting) return;
        setServerError(null);
        if (!validate()) return;

        setSubmitting(true);
        router.post(`/agreement/${token}/sign`, {
            signer_name: signerName.trim(),
            ...(signers.length ? { signer_index: signerIndex } : {}),
            signature_data: currentSignature(),
            terms_accepted: termsAccepted ? 1 : 0,
        }, {
            preserveScroll: true,
            onError: (errs) => { setErrors(errs); setServerError(Object.values(errs)[0] || "Signature could not be recorded."); },
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <div className="min-h-screen bg-[#eef0f4] py-10 px-4">
            <Head title={`Sign — ${typeLabel || "Agreement"}`} />
            <div className="mx-auto max-w-7xl space-y-5">
                <header className="space-y-1">
                    <p className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.2em] text-[#1F5A8B]">
                        <FileSignature size={12} /> Exalt Property Management
                    </p>
                    <h1 className="text-2xl font-bold text-gray-900">{typeLabel}</h1>
                    <p className="text-sm text-gray-600">For: {clientName || "Client"}</p>
                </header>

                <div className="grid gap-5 lg:grid-cols-[1fr_22rem] lg:items-start">
                <form onSubmit={submit} className="order-2 space-y-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:order-2 lg:sticky lg:top-6">
                    {serverError && (
                        <div className="flex items-start gap-2 rounded-md border border-red-100 bg-red-50 p-3 text-xs text-red-800">
                            <AlertCircle size={14} className="mt-0.5 shrink-0" /> {serverError}
                        </div>
                    )}
                    {signers.length > 0 && <label className="block text-sm font-semibold">Tenant signing
                        <select aria-label="Tenant signing" value={signerIndex} onChange={e => {
                            const index = Number(e.target.value); setSignerIndex(index);
                            setSignerName(signers.find(s => s.index === index)?.name || '');
                            setTermsAccepted(false); setUploadDataUrl(null); sigRef.current?.clear(); setHasInk(false);
                        }} className="mt-1 w-full rounded-md border border-gray-300 p-2">
                            {signers.map(s => <option key={s.index} value={s.index} disabled={s.signed}>{s.name}{s.signed ? ' — signed' : ''}</option>)}
                        </select>
                        <span className="mt-1 block text-xs font-normal text-gray-500">Each listed tenant must sign. The signed copy and pending-payment tenant record are created after the final signature.</span>
                    </label>}

                    <label className="flex cursor-pointer items-start gap-2.5">
                        <input type="checkbox" checked={termsAccepted} onChange={(e) => setTermsAccepted(e.target.checked)} className="mt-1 h-4 w-4" />
                        <span className="text-sm text-gray-900">I have read and agree to the terms of this agreement, including the Terms &amp; Conditions and House Rules.</span>
                    </label>
                    {errors.terms_accepted && <p className="-mt-2 text-xs text-red-700">{errors.terms_accepted}</p>}

                    <div>
                        <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-gray-500">Type your full legal name</label>
                        <input type="text" value={signerName} readOnly={signers.length > 0} onChange={(e) => setSignerName(e.target.value)} maxLength={200} placeholder="e.g. Maria Cruz" className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-[#1F5A8B] focus:outline-none" />
                        {signers.length > 0 && <p className="mt-1 text-xs text-gray-500">This name is printed on the issued agreement. Ask staff for a corrected agreement before signing if it is wrong.</p>}
                        {errors.signer_name && <p className="mt-1 text-xs text-red-700">{errors.signer_name}</p>}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-gray-500">Your signature</label>
                        {/* Mode tabs */}
                        <div className="mb-2 flex items-center gap-2">
                            {[
                                { key: "type", label: "Type", icon: <Type size={13} /> },
                                { key: "draw", label: "Draw", icon: <PenTool size={13} /> },
                                { key: "upload", label: "Upload", icon: <Upload size={13} /> },
                            ].map((t) => (
                                <button key={t.key} type="button" onClick={() => setSigMode(t.key)} className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] font-semibold ${sigMode === t.key ? "bg-[#1F5A8B] text-white" : "bg-gray-100 text-gray-600 hover:bg-gray-200"}`}>
                                    {t.icon} {t.label}
                                </button>
                            ))}
                        </div>

                        {sigMode === "type" ? (
                            <div className="flex h-[180px] items-center justify-center overflow-hidden rounded-md border border-gray-300 bg-white">
                                {typedSignature
                                    ? <img src={typedSignature} alt="Signature preview" className="max-h-[140px] max-w-[90%] object-contain" />
                                    : <p className="px-4 text-center text-xs text-gray-400">Type your full legal name above to generate a signature.</p>}
                            </div>
                        ) : sigMode === "draw" ? (
                            <div>
                                <div className="rounded-md border border-gray-300 bg-white" style={{ touchAction: "none" }}>
                                    <SignatureCanvas ref={sigRef} onBegin={() => setHasInk(true)} penColor="#111" canvasProps={{ width: 720, height: 180, className: "w-full h-[180px]" }} />
                                </div>
                                <button type="button" onClick={clear} className="mt-1.5 inline-flex items-center gap-1 text-[11px] text-gray-500 hover:text-gray-900"><Eraser size={11} /> Clear</button>
                            </div>
                        ) : (
                            <label className="flex h-[180px] cursor-pointer flex-col items-center justify-center overflow-hidden rounded-md border-2 border-dashed border-gray-300 bg-white hover:border-[#1F5A8B]">
                                {uploadDataUrl ? (
                                    <img src={uploadDataUrl} alt="Signature preview" className="max-h-[140px] max-w-[85%] object-contain" />
                                ) : (
                                    <>
                                        <Upload size={22} className="text-gray-300" />
                                        <span className="mt-2 text-xs text-gray-400">Click to choose a PNG or JPG</span>
                                        <span className="mt-0.5 text-[10.5px] text-gray-300">Transparent PNG works best</span>
                                    </>
                                )}
                                <input type="file" accept="image/png,image/jpeg" className="hidden" onChange={onPickFile} />
                            </label>
                        )}
                        {errors.signature && <p className="mt-1 text-xs text-red-700">{errors.signature}</p>}
                    </div>

                    <div className="flex items-center justify-between gap-3 pt-2">
                        <p className="inline-flex flex-1 items-center gap-1.5 text-[10.5px] text-gray-400">
                            <ShieldCheck size={11} /> We record your typed name, drawn signature, IP address and timestamp for the audit trail.
                        </p>
                        <button type="submit" disabled={submitting} className="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-[#1F5A8B] px-5 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">
                            {submitting && <Loader2 size={13} className="animate-spin" />} Sign agreement →
                        </button>
                    </div>
                </form>

                <section className="order-1 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:order-1">
                    <div className="border-b border-gray-100 px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">Your agreement</div>
                    <iframe title="Agreement" src={documentUrl} className="h-[85vh] w-full" />
                </section>
                </div>
            </div>
        </div>
    );
}
