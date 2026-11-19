import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { AlertTriangle } from "lucide-react";

// A global, promise-based confirm modal that replaces the browser's native
// window.confirm ("127.0.0.1 says …"). Call `confirmDialog(msg)` or
// `confirmDialog({ title, message, confirmText, cancelText, tone })` and await
// the boolean. A single <ConfirmHost/> mounted at the app root renders it.

const listeners = new Set();
let resolver = null;

export function confirmDialog(opts) {
    const options = typeof opts === "string" ? { message: opts } : (opts || {});
    return new Promise((resolve) => {
        resolver = resolve;
        const state = {
            title: options.title || "Are you sure?",
            message: options.message || "",
            confirmText: options.confirmText || "Confirm",
            cancelText: options.cancelText || "Cancel",
            tone: options.tone || "default", // default | danger
        };
        listeners.forEach((l) => l(state));
    });
}

function settle(result) {
    const r = resolver;
    resolver = null;
    listeners.forEach((l) => l(null));
    if (r) r(result);
}

export function ConfirmHost() {
    const [state, setState] = useState(null);

    useEffect(() => {
        const l = (s) => setState(s);
        listeners.add(l);
        return () => listeners.delete(l);
    }, []);

    useEffect(() => {
        if (!state) return;
        const onKey = (e) => {
            if (e.key === "Escape") settle(false);
        };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [state]);

    if (!state) return null;
    const danger = state.tone === "danger";

    return createPortal(
        <div className="fixed inset-0 z-[100] bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={() => settle(false)}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-4 flex items-start gap-3">
                    <span className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${danger ? "bg-rose-50 text-rose-600" : "bg-gray-100 text-gray-600"}`}>
                        <AlertTriangle size={18} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-sm font-bold text-gray-900">{state.title}</h2>
                        {state.message && <p className="text-[13px] text-gray-600 mt-1 whitespace-pre-line leading-snug">{state.message}</p>}
                    </div>
                </div>
                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" onClick={() => settle(false)} className="px-4 py-2 text-[13px] font-semibold text-gray-600 hover:text-gray-900">
                        {state.cancelText}
                    </button>
                    <button type="button" autoFocus onClick={() => settle(true)}
                        className={`px-4 py-2 rounded-lg text-white text-[13px] font-bold ${danger ? "bg-rose-600 hover:bg-rose-700" : "bg-gray-900 hover:bg-black"}`}>
                        {state.confirmText}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
