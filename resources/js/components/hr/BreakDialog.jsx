import { useEffect, useRef } from "react";
import { Coffee } from "lucide-react";
export default function BreakDialog({ state, pending, resume, error }) {
    const dialog = useRef(null);
    useEffect(() => {
        if (state?.status === "on_break") {
            if (!dialog.current.open) dialog.current.showModal();
        } else dialog.current.close();
    }, [state?.status, state?.break_started_at]);
    return (
        <dialog
            ref={dialog}
            className="hr-break-dialog"
            aria-labelledby="break-title"
        >
            <div className="hr-break-symbol">
                <Coffee size={28} />
            </div>
            <h2 id="break-title">You’re on a break</h2>
            <p>
                Your work timer is paused. When you’re ready, resume to start
                recording work time again.
            </p>
            {error && (
                <p className="hr-error" role="alert">
                    {error}
                </p>
            )}
            <div className="hr-dialog-actions">
                <button
                    className="hr-button"
                    onClick={resume}
                    disabled={pending}
                >
                    I’m back · resume work
                </button>
                <button
                    className="hr-button-secondary"
                    onClick={() => dialog.current.close()}
                >
                    Keep break running
                </button>
            </div>
        </dialog>
    );
}
