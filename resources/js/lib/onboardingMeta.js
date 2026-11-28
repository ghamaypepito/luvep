// Shared display metadata + helpers for the Onboarding pipeline (EOI
// submissions). Used by the list, kanban, and detail views.

export const STATUS_LABEL = {
    new: "New",
    reviewed: "Reviewed",
    shortlisted: "Shortlisted",
    viewing_email_sent: "Viewing booking email sent",
    viewing_booked: "Viewing booked",
    viewing_completed: "Viewing completed",
    post_viewing_followup: "Post-viewing follow-up",
    pre_tenancy_form_sent: "Pre-tenancy form email sent",
    pre_tenancy_form_completed: "Pre-tenancy form completed",
    agreement_sent: "Agreement sent",
    agreement_signed: "Agreement signed",
    invoice_sent: "Invoice sent",
    payment_confirmed: "Payment confirmed",
    moved_in: "Moved in",
    declined: "Declined",
    not_proceeding: "Not proceeding",
};

// Badge classes per status (blue → teal → amber → green → dark green; reds/grey
// for terminals), matching the spec's colour scheme.
export const STATUS_STYLES = {
    new: "bg-blue-50 text-blue-700",
    reviewed: "bg-sky-50 text-sky-700",
    shortlisted: "bg-teal-50 text-teal-700",
    viewing_email_sent: "bg-cyan-50 text-cyan-700",
    viewing_booked: "bg-amber-50 text-amber-700",
    viewing_completed: "bg-amber-100 text-amber-800",
    post_viewing_followup: "bg-lime-50 text-lime-700",
    pre_tenancy_form_sent: "bg-yellow-50 text-yellow-700",
    pre_tenancy_form_completed: "bg-yellow-100 text-yellow-800",
    agreement_sent: "bg-orange-50 text-orange-700",
    agreement_signed: "bg-orange-100 text-orange-800",
    invoice_sent: "bg-emerald-50 text-emerald-700",
    payment_confirmed: "bg-emerald-100 text-emerald-800",
    moved_in: "bg-green-700 text-white",
    declined: "bg-rose-50 text-rose-600",
    not_proceeding: "bg-gray-100 text-gray-500",
};

// Column dot colour for the kanban headers.
export const STATUS_DOT = {
    new: "bg-blue-500",
    reviewed: "bg-sky-500",
    shortlisted: "bg-teal-500",
    viewing_email_sent: "bg-cyan-500",
    viewing_booked: "bg-amber-500",
    viewing_completed: "bg-amber-600",
    post_viewing_followup: "bg-lime-500",
    pre_tenancy_form_sent: "bg-yellow-500",
    pre_tenancy_form_completed: "bg-yellow-600",
    agreement_sent: "bg-orange-500",
    agreement_signed: "bg-orange-600",
    invoice_sent: "bg-emerald-500",
    payment_confirmed: "bg-emerald-600",
    moved_in: "bg-green-700",
    declined: "bg-rose-500",
    not_proceeding: "bg-gray-400",
};

export const statusLabel = (s) => STATUS_LABEL[s] ?? s;

export const tempBadge = (t) => (t === "hot" ? "🔥 Hot" : t === "cold" ? "❄ Cold" : null);

// Days-at-stage colour: red >14, amber >7, gray otherwise.
export const daysStyle = (d) => {
    if (d == null) return "text-gray-400";
    if (d > 14) return "text-rose-600 font-semibold";
    if (d > 7) return "text-amber-600 font-semibold";
    return "text-gray-500";
};

// Stage transitions that require collecting data before the move. Targets not
// listed here are a plain status PATCH; `moved_in` is handled by the Convert
// to Tenant modal separately.
export const STAGE_INPUTS = {
    viewing_booked: [{ name: "viewing_scheduled_at", label: "Viewing date & time", type: "datetime-local", required: true }],
    viewing_completed: [{ name: "viewing_outcome", label: "Viewing outcome (optional)", type: "textarea" }],
    payment_confirmed: [{ name: "verified_move_in_amount_nzd", label: "Verified amount received (NZD)", type: "number", required: true }],
    declined: [{ name: "declined_reason", label: "Reason for declining", type: "textarea", required: true }],
    not_proceeding: [{ name: "not_proceeding_reason", label: "Reason for not proceeding", type: "textarea", required: true }],
};

export const initials = (name) =>
    (name || "?").split(" ").filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join("");
