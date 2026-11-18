import { Sparkles } from "lucide-react";

/**
 * "Ask AI" trigger on a record page. Opens the shared side panel
 * scoped to this record (grounded answers + immigration guardrail are
 * enforced server-side). One assistant surface, not a separate widget.
 *
 * Props:
 *   subjectId — the Lead id the assistant should scope to
 *   label     — unused now (kept for call-site compatibility)
 */
export default function AiRecordAssistant({ subjectId }) {
    if (!subjectId) return null;

    return (
        <button type="button"
            onClick={() => window.dispatchEvent(new CustomEvent("ep-assistant-open", { detail: { subjectId } }))}
            className="relative inline-flex items-center gap-2 rounded-full bg-gray-900 text-white pl-4 pr-5 py-3 shadow-lg hover:bg-gray-800 transition-colors"
        >
            <Sparkles size={18} className="text-amber-300" />
            <span className="text-sm font-semibold">Ask AI</span>
        </button>
    );
}
