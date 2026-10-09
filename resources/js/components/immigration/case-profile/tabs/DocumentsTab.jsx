import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import {
    FileText, Download, Upload, Eye, Check, Loader2,
    Send, X as XIcon, AlertCircle, Paperclip, ChevronDown, ChevronRight, Plus, MessageSquare,
    MoreVertical, Trash2, Flag, Calendar,
} from "lucide-react";
import CaseFilesModal from "@/components/immigration/CaseFilesModal";
import { ThreadItem, ThreadComposer } from "@/components/immigration/case-profile/threads";
import { IntakeViewModal } from "@/pages/portal/immigration/Assessments";
import { immBase, leadBase, docDownloadUrl } from "@/lib/caseRoutes";

// Documents tab — table view that joins the visa-type checklist with
// uploaded LeadDocuments by checklist_key. Each row is a required (or
// optional) document slot; if a file has been uploaded it shows here
// inline with a view link, status dropdown, and editable notes.
//
// Uses the existing endpoints:
//   POST /admin/leads/{lead}/documents/{doc}/status   — status + note update
//   GET  /admin/documents/{doc}/download              — staff download
//
// Orphan documents (uploaded with a checklist_key that doesn't match the
// current visa-type checklist, or with no key at all) are folded under
// an "Other" group at the bottom so nothing is hidden.

// Display labels only — the stored status values stay "Approved" / "Rejected"
// (logic + client mapping depend on them); we just present them more softly.
const STATUS_OPTIONS = [
    { value: "Submitted",   label: "Submitted" },
    { value: "UnderReview", label: "Under review" },
    { value: "Checked",     label: "For Adviser Review" },
    { value: "Approved",    label: "Approved" },
    { value: "Rejected",    label: "Needs attention" },
];

// The verdict a staff member may set depends on their role. The manager triages
// (Under review → For Adviser Review); the Licensed Immigration Adviser decides
// (Approved / Needs attention). "Submitted" is the client's own upload state and
// is never staff-settable — it only appears as the current value. Admins keep
// the full ladder.
const MANAGER_STATUS_OPTIONS = [
    { value: "UnderReview", label: "Under review" },
    { value: "Checked",     label: "For Adviser Review" },
];
const ADVISER_STATUS_OPTIONS = [
    { value: "Approved",    label: "Approved" },
    { value: "Rejected",    label: "Needs attention" },
];

// The settable options depend on WHICH PORTAL is being viewed, not the viewer's
// role — an admin working the immigration portal triages like the manager; the
// same admin in the adviser portal decides like the adviser. Always include the
// document's current status as a fallback entry so the select shows the real
// value even when it isn't one this portal sets (e.g. a manager looking at an
// already-Approved doc, or a fresh "Submitted" upload).
function statusOptionsFor(url, currentStatus) {
    const base = (url || "").startsWith("/portal/immigration-adviser")
        ? ADVISER_STATUS_OPTIONS
        : MANAGER_STATUS_OPTIONS;

    if (currentStatus && ! base.some((o) => o.value === currentStatus)) {
        const known = STATUS_OPTIONS.find((o) => o.value === currentStatus);
        return [{ value: currentStatus, label: known ? known.label : currentStatus }, ...base];
    }
    return base;
}

const STATUS_TONE = {
    Submitted:   "bg-yellow-50 text-yellow-700 border-yellow-200",
    UnderReview: "bg-blue-50 text-blue-700 border-blue-200",
    Checked:     "bg-purple-50 text-purple-700 border-purple-200",
    Approved:    "bg-emerald-50 text-emerald-700 border-emerald-200",
    Rejected:    "bg-red-50 text-red-700 border-red-200",
    StaffShared: "bg-gray-50 text-gray-700 border-gray-200",
};

// Leading dot on the Verdict column — a compact at-a-glance signal.
const VERDICT_DOT = {
    Submitted:   "bg-amber-500",
    UnderReview: "bg-blue-500",
    Checked:     "bg-purple-500",
    Approved:    "bg-teal-600",
    Rejected:    "bg-red-500",
    StaffShared: "bg-gray-400",
};

// The status text color, matched to the dot — a clean colored label instead of
// a boxed pill.
const VERDICT_TEXT = {
    Submitted:   "text-amber-600",
    UnderReview: "text-blue-600",
    Checked:     "text-purple-600",
    Approved:    "text-teal-600",
    Rejected:    "text-red-600",
    StaffShared: "text-gray-600",
};

export default function DocumentsTab({
    lead,
    documents = [],
    documentRequests = [],
    checklist = { items: [] },
    checklistProgress = { required_total: 0, required_approved: 0, total: 0, approved: 0 },
    threads = [],
    caseStaff = [],
    vif = null,
    engagement = {},
}) {
    // Build 12 phase 6 — document-anchored threads render on their document's
    // row (and nowhere else). Group them by the document they anchor to.
    const threadsByDoc = useMemo(() => {
        const map = new Map();
        for (const t of threads) {
            if (t.anchor_type !== "document" || ! t.anchor_id) continue;
            if (! map.has(t.anchor_id)) map.set(t.anchor_id, []);
            map.get(t.anchor_id).push(t);
        }
        return map;
    }, [threads]);
    // Checklist-anchored notes — a note on a requirement itself, keyed by the
    // checklist key. These need no uploaded file, so staff can note a slot before
    // the client provides anything. Grouped by anchor_key.
    const threadsByKey = useMemo(() => {
        const map = new Map();
        for (const t of threads) {
            if (t.anchor_type !== "checklist" || ! t.anchor_key) continue;
            if (! map.has(t.anchor_key)) map.set(t.anchor_key, []);
            map.get(t.anchor_key).push(t);
        }
        return map;
    }, [threads]);
    // Replies to a document's reviewer note — grouped by document id so they
    // render nested under that document's reviewer note.
    const reviewerRepliesByDoc = useMemo(() => {
        const map = new Map();
        for (const t of threads) {
            if (t.anchor_type !== "reviewer_note" || ! t.anchor_id) continue;
            if (! map.has(t.anchor_id)) map.set(t.anchor_id, []);
            map.get(t.anchor_id).push(t);
        }
        return map;
    }, [threads]);
    // Client-submitted proof-of-payment uploads — surfaced on the invoice row so
    // staff can verify (approve) them right there.
    const proofDocs = useMemo(
        () => documents.filter((d) => d.source_variant === "proof_of_payment"),
        [documents],
    );
    // "File history" modal — every file on the case with its status, kept
    // separate from the checklist table below.
    const [filesOpen, setFilesOpen] = useState(false);
    // Collapsible document sections — collapsed by DEFAULT. expandedCats holds
    // the categories the user has opened (empty = everything collapsed).
    const [expandedCats, setExpandedCats] = useState(() => new Set());
    const toggleCat = (cat) => setExpandedCats((prev) => {
        const next = new Set(prev);
        next.has(cat) ? next.delete(cat) : next.add(cat);
        return next;
    });
    // "Request a document" modal — a free-text ad-hoc request not tied to a
    // checklist slot.
    const [requestOpen, setRequestOpen] = useState(false);
    // Draft for the "Request documents" modal, lifted here so closing the modal
    // KEEPS the draft — it's cleared only after a successful send.
    const [reqDraft, setReqDraft] = useState(() => ({ picked: new Set(), customLabels: [""], message: "" }));
    // Status filter for the checklist (All / Needs client action / Submitted /
    // For Adviser Review / Approved).
    const [filter, setFilter] = useState("all");

    // Build a checklist-keyed map of uploaded documents (latest wins per key).
    // Orphans (no matching checklist entry) get collected separately.
    const items = checklist.items || [];
    const knownKeys = new Set(items.map((i) => i.key));

    // A checklist key can hold several files (the client can "Upload another"
    // without replacing the first), so keep the full list per key, oldest first.
    const docsByKey = useMemo(() => {
        const map = new Map();
        for (const d of documents) {
            const key = d.checklist_key;
            if (! key) continue;
            if (! map.has(key)) map.set(key, []);
            map.get(key).push(d);
        }
        for (const arr of map.values()) {
            arr.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        }
        return map;
    }, [documents]);

    // The "Request Information Form" row displays INZ's RFI letter(s) that the
    // adviser attached (source_variant 'rfi'). The client responds through the
    // individual extracted "Request Information — INZ" document requests, so this
    // row is the source letter, not the client's response. (Legacy client
    // uploads keyed 'rfi' are still shown here so nothing is lost.)
    const rfiDocs = useMemo(
        () => documents
            .filter((d) => d.source_variant === "rfi" || d.checklist_key === "rfi")
            .sort((a, b) => new Date(a.created_at) - new Date(b.created_at)),
        [documents],
    );

    // Uploads answering an RFI request render inside the Request Information
    // rows — keep them out of the orphan list so they don't show twice.
    const rfiRequestIds = useMemo(
        () => new Set(documentRequests.filter((r) => r.origin === "rfi").map((r) => r.id)),
        [documentRequests],
    );
    const orphans = useMemo(
        () => documents.filter((d) =>
            (! d.checklist_key || ! knownKeys.has(d.checklist_key))
            // Client RFI responses and the staff RFI letter both live in the
            // "Request Information Form" row above, not in the orphan list.
            && d.checklist_key !== "rfi"
            && d.source_variant !== "rfi"
            && ! (d.request_id && rfiRequestIds.has(d.request_id)),
        ),
        [documents, knownKeys, rfiRequestIds],
    );

    const rows = items.map((item) => {
        const rawLabel = item.label || item.key;
        // Seed labels are stored as "Category · Document name" — split on
        // the middle dot so the table can show a clean two-line cell with
        // the category as the small subtitle.
        const split = typeof rawLabel === "string" ? rawLabel.split(" · ") : [rawLabel];
        const hasCategoryPrefix = split.length > 1;
        return {
            kind:     "checklist",
            key:      item.key,
            label:    hasCategoryPrefix ? split.slice(1).join(" · ") : rawLabel,
            category: item.category || (hasCategoryPrefix ? split[0] : categoryFromKey(item.key)) || "Other",
            required: !! item.required,
            hidden:   !! item.hidden,
            // The latest upload drives status/notes/threads; all files display.
            document: (docsByKey.get(item.key) || []).slice(-1)[0] || null,
            documents: docsByKey.get(item.key) || [],
        };
    });

    const orphanRows = orphans.map((d) => {
        // Adviser-generated engagement documents (Written Agreement + IAA
        // standards) get their own clearly-named group rather than being
        // lumped in with unmatched uploads.
        const variant = typeof d.source_variant === "string" ? d.source_variant : "";
        const isEngagement = variant.startsWith("engagement:");
        // Both conventions — "invoice" (engagement pack) and "invoice:<no>" (tax
        // invoice) — belong in one Invoices group.
        const isInvoice = variant === "invoice" || variant.startsWith("invoice:");
        const isInz = variant.startsWith("inz:");
        const isDecline = variant === "decline";
        const isGenerated = d.source === "generated";
        return {
            kind:     "orphan",
            key:      `orphan-${d.id}`,
            label:    d.original_name,
            category: isDecline
                ? "Visa outcome"
                : isInvoice
                    ? "Invoices"
                    : isEngagement
                        ? "Engagement documents"
                        : isInz
                            ? "INZ forms (generated)"
                            : isGenerated
                                ? "Generated documents"
                                : "Other (no checklist match)",
            required: false,
            document: d,
        };
    });

    // "Request Information Form" row — displays INZ's RFI letter(s) the adviser
    // attached. It lives in its own "Request Information" section (placed right
    // after Immigration Team below) and appears ONLY for cases that have an RFI
    // letter, i.e. that reached the Request for Information stage — not on every
    // case. The extracted per-document requests are NOT here: they belong under
    // the "Request documents" button, rendered from documentRequests.
    const hasRfi = rfiDocs.length > 0;
    // The documents INZ asked for (extracted from the RFI letter). They render
    // inside the "Request Information" section — below the letter — NOT under the
    // manual "Request documents" button, since they aren't ad-hoc staff requests.
    const rfiRequests = documentRequests.filter((r) => r.origin === "rfi");
    const rfiRow = {
        kind:      "checklist",
        key:       "rfi",
        label:     "Request Information Form",
        category:  "Request Information",
        required:  false,
        hidden:    false,
        document:  rfiDocs.slice(-1)[0] || null,
        documents: rfiDocs,
    };
    // The section shows when the case has an RFI letter and/or extracted requests.
    const showRfiSection = hasRfi || rfiRequests.length > 0;
    const rowsWithRfi = [];
    let rfiInserted = false;
    for (const r of rows) {
        rowsWithRfi.push(r);
        if (showRfiSection && ! rfiInserted && isVifLabel(r.label)) { rowsWithRfi.push(rfiRow); rfiInserted = true; }
    }
    if (showRfiSection && ! rfiInserted) rowsWithRfi.push(rfiRow);

    const allRows = [...rowsWithRfi, ...orphanRows];
    const totals = useMemo(() => {
        const total = allRows.length;
        const approved = allRows.filter((r) => r.document?.status === "Approved").length;
        return { total, approved };
    }, [allRows]);

    // The Immigration team's own documents — the Visa Information Form, the
    // engagement pack, and the invoice — are grouped under one "Immigration
    // Team" section rather than three separate ones.
    const immigrationTeamCategory = (cat) => {
        const c = (cat || "").toString().toLowerCase();
        return (c.includes("information form") || c === "invoices" || c.includes("engagement"))
            ? "Immigration Team"
            : (cat || "Other");
    };

    // Group rows by category into ordered sections (Applicant → Financial →
    // Sponsor → Other), preserving the checklist order. First appearance of a
    // category fixes its position, so the seeded order drives the layout.
    const groupedRows = [];
    const groupIndex = new Map();
    for (const row of allRows) {
        const category = immigrationTeamCategory(row.category);
        if (! groupIndex.has(category)) {
            groupIndex.set(category, groupedRows.length);
            groupedRows.push([category, []]);
        }
        groupedRows[groupIndex.get(category)][1].push(row);
    }

    // Keep "Request Information" (INZ's RFI letter) directly under the
    // "Immigration Team" section, regardless of first-appearance order.
    {
        const riIdx = groupedRows.findIndex(([c]) => c === "Request Information");
        const itIdx = groupedRows.findIndex(([c]) => c === "Immigration Team");
        if (riIdx !== -1 && itIdx !== -1 && riIdx !== itIdx + 1) {
            const [ri] = groupedRows.splice(riIdx, 1);
            const insertAt = groupedRows.findIndex(([c]) => c === "Immigration Team") + 1;
            groupedRows.splice(insertAt, 0, ri);
        }
    }

    // Which filter bucket a row falls into. Drives the filter-tab counts and
    // the segmented progress bar. A required slot with nothing uploaded (or a
    // rejected upload) still needs the client to act.
    const bucketOf = (row) => {
        const s = row.document?.status;
        if (s === "Approved") return "approved";
        if (s === "Checked") return "adviser";
        if (s === "Submitted" || s === "UnderReview" || s === "StaffShared") return "submitted";
        if (s === "Rejected") return "needs";
        if (! row.document) return "needs";
        return "submitted";
    };
    const counts = { needs: 0, submitted: 0, adviser: 0, approved: 0 };
    for (const r of allRows) counts[bucketOf(r)] += 1;
    const FILTERS = [
        { key: "all",       label: "All",                count: allRows.length },
        { key: "needs",     label: "Needs client action", count: counts.needs },
        { key: "submitted", label: "Submitted",          count: counts.submitted },
        { key: "adviser",   label: "For Adviser Review",  count: counts.adviser },
        { key: "approved",  label: "Approved",           count: counts.approved },
    ];
    // Apply the active filter to the grouped rows, dropping now-empty sections.
    const shownGrouped = filter === "all"
        ? groupedRows
        : groupedRows
            .map(([cat, rs]) => [cat, rs.filter((r) => bucketOf(r) === filter)])
            .filter(([, rs]) => rs.length > 0);

    if (allRows.length === 0) {
        return (
            <div className="space-y-4">
                {vif && <VifCard vif={vif} />}
                <div className="text-center py-12 border border-dashed border-gray-200 rounded-xl bg-gray-50/50">
                    <FileText size={32} className="mx-auto text-gray-300" />
                    <p className="mt-3 text-sm font-semibold text-gray-700">
                        No checklist configured for this visa type
                    </p>
                    <p className="text-xs text-gray-500 mt-1">
                        {checklist.visa
                            ? `Source: ${checklist.source} · ${checklist.visa}`
                            : "No documents on file and no checklist available."}
                    </p>
                </div>
            </div>
        );
    }

    // Progress count uses REQUIRED items only — optional items don't move
    // the needle on "case ready for submission" (per Phase 4 spec).
    // Falls back to all-items when no required flags are set.
    const reqTotal    = checklistProgress.required_total ?? 0;
    const reqApproved = checklistProgress.required_approved ?? 0;
    const pct = reqTotal > 0 ? Math.round((reqApproved / reqTotal) * 100) : 0;

    // The VIF generator lives inline on the "Visa Information Form" checklist
    // row; only fall back to the standalone card when no such row exists.
    const hasVifRow = !! vif && allRows.some((r) => r.kind === "checklist" && isVifLabel(r.label));

    return (
        <div className="space-y-4">
            {vif && ! hasVifRow && <VifCard vif={vif} />}
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="min-w-0">
                    <h2 className="text-lg font-bold text-gray-900">Document checklist</h2>
                    <p className="text-[13px] text-gray-500 mt-0.5">
                        {checklist.visa || "All uploads"}
                        {reqTotal > 0 && (
                            <> · <span className="font-semibold text-gray-800">{reqApproved} of {reqTotal}</span> required documents approved</>
                        )}
                        {reqTotal === 0 && totals.total > 0 && (
                            <> · {totals.approved} of {totals.total} approved</>
                        )}
                        {counts.adviser > 0 && (
                            <> · {counts.adviser} waiting on the adviser</>
                        )}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {/* Separate from the checklist below: what has actually
                        come in, with each file's status. */}
                    <button
                        type="button"
                        onClick={() => setFilesOpen(true)}
                        title="Every file on this case with its review status"
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-[13px] font-semibold border border-gray-200 text-gray-700 hover:border-gray-900 hover:text-gray-900 transition-colors"
                    >
                        File history ({documents.length})
                    </button>
                    {documents.length > 0 && (
                        <DownloadAllMenu leadId={lead.id} />
                    )}
                    <button
                        type="button"
                        onClick={() => setRequestOpen(true)}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-[13px] font-semibold bg-teal-600 text-white hover:bg-teal-700 transition-colors"
                    >
                        Request documents
                        {(reqDraft.picked.size > 0 || reqDraft.customLabels.some((l) => l.trim()) || reqDraft.message.trim()) && (
                            <span className="w-1.5 h-1.5 rounded-full bg-white/90" title="Draft in progress" />
                        )}
                    </button>
                </div>
            </div>

            {(reqTotal > 0 || allRows.length > 0) && (
                <div className="flex h-2 rounded-full overflow-hidden bg-gray-100">
                    {counts.approved > 0 && (
                        <div className="bg-teal-600 transition-[width] duration-500" style={{ width: `${(counts.approved / allRows.length) * 100}%` }} />
                    )}
                    {counts.adviser > 0 && (
                        <div className="bg-teal-400 transition-[width] duration-500" style={{ width: `${(counts.adviser / allRows.length) * 100}%` }} />
                    )}
                    {counts.submitted > 0 && (
                        <div className="bg-amber-500 transition-[width] duration-500" style={{ width: `${(counts.submitted / allRows.length) * 100}%` }} />
                    )}
                </div>
            )}

            {/* Status filter tabs — All / Needs client action / Submitted /
                For Adviser Review / Approved. */}
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2 flex-wrap">
                    {FILTERS.map((f) => {
                        const active = filter === f.key;
                        return (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => setFilter(f.key)}
                                className={`inline-flex items-center gap-1.5 pl-3.5 pr-2.5 py-1.5 rounded-full text-[13px] font-semibold border transition-colors ${
                                    active
                                        ? "bg-gray-900 text-white border-gray-900"
                                        : "bg-white text-gray-600 border-gray-200 hover:border-gray-400"
                                }`}
                            >
                                {f.label}
                                <span className={`inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-bold ${
                                    active ? "bg-white/20 text-white" : "bg-gray-100 text-gray-600"
                                }`}>
                                    {f.count}
                                </span>
                            </button>
                        );
                    })}
                </div>
                <p className="text-[12px] text-gray-400">
                    Source: <span className="font-semibold">{checklist.source || "none"}</span>
                </p>
            </div>

            <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-gray-50 rounded-lg text-[10.5px] font-bold uppercase tracking-wider text-gray-400">
                                <th className="text-left px-4 py-3 w-[34%] rounded-l-lg">Document</th>
                                <th className="text-left px-4 py-3 w-[26%]">Attachment</th>
                                <th className="text-left px-4 py-3 w-[22%]">Verdict</th>
                                <th className="text-left px-4 py-3 w-[18%] rounded-r-lg">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shownGrouped.map(([category, groupRows]) => {
                                const approved = groupRows.filter((r) => r.document?.status === "Approved").length;
                                const checklistRows = groupRows.filter((r) => r.kind === "checklist");
                                const adviserWait = groupRows.filter((r) => bucketOf(r) === "adviser").length;
                                const needAction = groupRows.filter((r) => bucketOf(r) === "needs").length;
                                // The "Request Information" section also lists the
                                // documents INZ asked for (below the letter) — fold
                                // them into the section's counts.
                                const sectionRfiReqs = (category === "Request Information" && filter === "all") ? rfiRequests : [];
                                const secApproved = approved + sectionRfiReqs.filter((r) => r.latest_document?.status === "Approved").length;
                                const secTotal = groupRows.length + sectionRfiReqs.length;
                                // While a filter is active, always show matching
                                // rows regardless of the section's collapse state.
                                const collapsed = filter === "all" ? ! expandedCats.has(category) : false;
                                const allApproved = approved === groupRows.length && groupRows.length > 0;
                                const rightNote = allApproved
                                    ? "completed"
                                    : adviserWait > 0
                                        ? `${adviserWait} with the adviser`
                                        : needAction > 0
                                            ? `${needAction} need client action`
                                            : null;
                                return (
                                    <Fragment key={category}>
                                        <tr className="bg-gray-800 border-y border-gray-700">
                                            {/* The whole dark bar collapses/expands the section. */}
                                            <td
                                                colSpan={4}
                                                className="px-4 py-2.5 cursor-pointer select-none hover:bg-gray-700/60 transition-colors"
                                                onClick={() => toggleCat(category)}
                                                title={collapsed ? "Expand section" : "Collapse section"}
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="flex items-center gap-2">
                                                        {checklistRows.length > 0 && (
                                                            <span onClick={(e) => e.stopPropagation()}>
                                                                <SectionSelectAll leadId={lead.id} rows={checklistRows} />
                                                            </span>
                                                        )}
                                                        <span className="text-[11px] font-bold uppercase tracking-wider text-gray-100">
                                                            {category}
                                                        </span>
                                                        <span className="text-[11px] font-semibold text-gray-400">
                                                            {secApproved}/{secTotal}
                                                        </span>
                                                        {category === "Request Information" && lead.rfi_deadline && (
                                                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-300 ml-1">
                                                                <Calendar size={11} /> Due {formatDate(lead.rfi_deadline)}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {rightNote && (
                                                        <span className="text-[11px] text-gray-300">{rightNote}</span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                        {! collapsed && groupRows.map((row) => (
                                            <Row
                                                key={row.key}
                                                row={row}
                                                leadId={lead.id}
                                                docThreads={row.document ? (threadsByDoc.get(row.document.id) || []) : []}
                                                threadsByDoc={threadsByDoc}
                                                keyThreads={threadsByKey.get(row.key) || []}
                                                reviewerReplies={row.document ? (reviewerRepliesByDoc.get(row.document.id) || []) : []}
                                                caseStaff={caseStaff}
                                                vif={isVifLabel(row.label) ? vif : null}
                                                engagementSent={!! engagement.sent}
                                                engagementSentAt={engagement.sent_at}
                                                engagementSigned={!! engagement.signed}
                                                engagementSignedAt={engagement.signed_at}
                                                invoicePaid={!! engagement.invoice_paid}
                                                invoicePaidAt={engagement.invoice_paid_at}
                                                proofs={proofDocs}
                                            />
                                        ))}
                                        {! collapsed && sectionRfiReqs.map((req) => (
                                            <RfiRequestTableRow key={`rfireq-${req.id}`} req={req} leadId={lead.id} />
                                        ))}
                                    </Fragment>
                                );
                            })}
                            {shownGrouped.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="px-4 py-10 text-center text-[13px] text-gray-400">
                                        Nothing in this view.
                                        <button type="button" onClick={() => setFilter("all")} className="ml-1 font-semibold text-gray-600 hover:text-gray-900 underline">
                                            Show all
                                        </button>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
            </div>

            {/* Request a document — an ad-hoc request for something not on the
                checklist. Emails the client and logs a document request. Below
                the button, the requests already sent (and whether they've been
                fulfilled) are listed so staff can see what was asked for. */}
            <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/50 px-4 py-3">
                <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-gray-800">Request documents</p>
                        <p className="text-[11px] text-gray-500 mt-0.5">Ask the client to upload documents — pick from the checklist or add your own.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setRequestOpen(true)}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-black flex-shrink-0"
                    >
                        <Plus size={14} /> Request documents
                    </button>
                </div>

                {/* Ad-hoc requests only. RFI-extracted documents live in the
                    "Request Information" section above, not here. */}
                {documentRequests.filter((r) => r.origin !== "rfi").length > 0 && (
                    <div className="mt-3 pt-3 border-t border-gray-200 space-y-1.5">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            Requested from client ({documentRequests.filter((r) => r.origin !== "rfi").length})
                        </p>
                        {documentRequests.filter((r) => r.origin !== "rfi").map((r) => (
                            <RequestRow key={r.id} req={r} leadId={lead.id} />
                        ))}
                    </div>
                )}
            </div>

            {requestOpen && (
                <RequestAnyDocument
                    leadId={lead.id}
                    checklistItems={rows.filter((r) => ! r.hidden).map((r) => ({
                        key: r.key, label: r.label, category: r.category,
                        required: r.required, provided: !! r.document,
                    }))}
                    draft={reqDraft}
                    setDraft={setReqDraft}
                    onClose={() => setRequestOpen(false)}
                />
            )}

            {filesOpen && (
                <CaseFilesModal
                    leadId={lead.id}
                    leadName={lead.name || [lead.first_name, lead.last_name].filter(Boolean).join(' ')}
                    onClose={() => setFilesOpen(false)}
                />
            )}
        </div>
    );
}

// Reply affordance under the reviewer note. The reviewer note itself is a value
// on the document (not a thread), so a "reply" is posted as a document-anchored
// note — it then appears in the discussion below the note.
function ReviewerReplyBox({ leadId, docId }) {
    const [open, setOpen] = useState(false);
    const [body, setBody] = useState("");
    const [posting, setPosting] = useState(false);

    const submit = () => {
        if (! body.trim()) { toast.error("Write a reply first"); return; }
        setPosting(true);
        router.post(`${immBase()}/cases/${leadId}/threads`,
            { anchor_type: "reviewer_note", anchor_id: docId, body, client_visible: false },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => { toast.success("Reply added"); setBody(""); setOpen(false); },
                onError: (e) => toast.error(Object.values(e)[0] || "Could not reply"),
                onFinish: () => setPosting(false),
            });
    };

    if (! open) {
        return (
            <button type="button" onClick={() => setOpen(true)}
                className="mt-2 text-[12px] font-semibold text-amber-700 hover:text-amber-900">
                Reply
            </button>
        );
    }

    return (
        <div className="mt-2 space-y-2">
            <textarea
                autoFocus
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={2}
                maxLength={2000}
                placeholder="Reply to this note…"
                className="w-full text-[13px] px-3 py-2 bg-white border border-amber-200 rounded-lg focus:outline-none focus:border-amber-400 resize-none"
            />
            <div className="flex items-center justify-end gap-2">
                <button type="button" onClick={() => { setOpen(false); setBody(""); }}
                    className="text-[12px] font-semibold text-gray-400 hover:text-gray-700">Cancel</button>
                <button type="button" onClick={submit} disabled={posting}
                    className="inline-flex items-center px-3 py-1.5 rounded-lg bg-amber-500 text-white text-[12px] font-semibold hover:bg-amber-600 disabled:opacity-50">Post reply</button>
            </div>
        </div>
    );
}

function Row({ row, leadId, docThreads = [], threadsByDoc = new Map(), keyThreads = [], reviewerReplies = [], caseStaff = [], vif = null, engagementSent = false, engagementSentAt = null, engagementSigned = false, engagementSignedAt = null, invoicePaid = false, invoicePaidAt = null, proofs = [] }) {
    const doc = row.document;
    const [verifyOpen, setVerifyOpen] = useState(false);
    const pageUrl = usePage().url || "";
    const statusOptions = statusOptionsFor(pageUrl, doc?.status);
    // Engagement + invoice docs show a lifecycle badge (Draft → Sent → Signed/Paid)
    // rather than the review-status dropdown used for client-uploaded documents.
    const variant = typeof doc?.source_variant === "string" ? doc.source_variant : "";
    const isInvoice = variant === "invoice" || variant.startsWith("invoice:");
    const isEngagementDoc = variant.startsWith("engagement:");
    const isWrittenAgreement = variant === "engagement:written_agreement";
    // "Sent" once the pack's emailed, or the tax invoice was shared with the client.
    // "Paid" is per-invoice: the invoice document is stamped Approved when a
    // proof of payment opened from this row is verified. A case-level flag would
    // (wrongly) light up every invoice at once.
    const invoicePaidNow = isInvoice && doc?.status === "Approved";
    const invoicePaidDate = doc?.reviewed_at || invoicePaidAt;
    const invoiceSent = engagementSent || doc?.status === "StaffShared";
    const [status, setStatus] = useState(doc?.status || "");
    const [note, setNote] = useState(doc?.note || "");
    const [savingStatus, setSavingStatus] = useState(false);
    const [savingNote, setSavingNote] = useState(false);
    const [savedNote, setSavedNote] = useState(false);
    const [rejectOpen, setRejectOpen] = useState(false);
    // Which attached file is open in the in-window preview popup (with comments).
    const [previewDoc, setPreviewDoc] = useState(null);
    // Notes/threads expand — the Notes column shows a "N notes" summary and the
    // full discussion only opens on click, keeping the table clean.
    const [notesOpen, setNotesOpen] = useState(false);

    const isChecklistRow = row.kind === "checklist";
    const files = row.documents && row.documents.length ? row.documents : (doc ? [doc] : []);
    const docNoteThreads = files.flatMap((d) => threadsByDoc.get(d.id) || []);
    // All notes on this requirement: file threads + checklist-anchored notes
    // (which need no upload) + reviewer-note replies, newest last.
    const noteThreads = [...docNoteThreads, ...keyThreads, ...reviewerReplies]
        .slice()
        .sort((a, b) => new Date(a.created_at || 0) - new Date(b.created_at || 0));
    const noteCount = noteThreads.length + (note ? 1 : 0);
    // The most-recent discussion note (thread), previewed under the note count.
    // The reviewer note is surfaced separately as a pinned flag.
    const lastThread = noteThreads[noteThreads.length - 1] || null;
    // Whether staff can leave a note here — any checklist slot (even empty) or a
    // real uploaded document. Engagement/invoice rows keep their lifecycle badge.
    const canNote = !! doc || isChecklistRow;

    const persist = (nextStatus, nextNote, kind) => {
        if (! doc) return;
        const isStatus = kind === "status";
        if (isStatus) setSavingStatus(true); else setSavingNote(true);

        router.post(
            `${leadBase()}/${leadId}/documents/${doc.id}/status`,
            { status: nextStatus, note: nextNote },
            {
                preserveScroll: true,
                preserveState: true,
                only: ["documents"],
                onSuccess: () => {
                    if (isStatus) toast.success("Status updated");
                    else { setSavedNote(true); setTimeout(() => setSavedNote(false), 1500); }
                },
                onError: (errs) => {
                    toast.error(Object.values(errs)[0] || "Update failed");
                    if (isStatus) setStatus(doc.status || "");
                    else setNote(doc.note || "");
                },
                onFinish: () => {
                    if (isStatus) setSavingStatus(false); else setSavingNote(false);
                },
            },
        );
    };

    const pickStatus = (next) => {
        if (! next || next === status) return;
        // Rejection must carry an explanation — divert through a modal that
        // requires the note before posting. A silent rejection is worse than
        // none. (Phase 4 spec: "Reject requires reviewer notes".)
        if (next === "Rejected") {
            setRejectOpen(true);
            return;
        }
        setStatus(next);
        persist(next, note, "status");
    };

    const confirmReject = (reason) => {
        setStatus("Rejected");
        setNote(reason);
        setRejectOpen(false);
        // Single POST updates both status + note so the audit trail is atomic.
        persist("Rejected", reason, "status");
    };

    const onNoteBlur = () => {
        if (! doc) return;
        if ((note || "") === (doc.note || "")) return;
        persist(status || doc.status, note, "note");
    };

    return (
        <Fragment>
        <tr className="border-b border-gray-50 last:border-b-0 align-top">
            {/* Document slot. For checklist rows the leading icon is a
                tracker-visibility checkbox (checked = shown on the applicant's
                tracking link, unchecked = hidden). Orphan rows keep the file
                icon since they aren't part of the checklist. */}
            <td className="px-4 py-3">
                <div className="flex items-start gap-2">
                    {row.kind === "checklist" ? (
                        <TrackVisibilityCheckbox
                            leadId={leadId}
                            checklistKey={row.key}
                            hidden={row.hidden}
                        />
                    ) : (
                        <FileText size={14} className="text-gray-300 flex-shrink-0 mt-0.5" />
                    )}
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900 leading-tight">
                            {row.label}
                            {row.required && <span className="ml-1 text-red-500">*</span>}
                        </p>
                    </div>
                </div>
            </td>

            {/* Attachment column — filename + View + Download for uploaded;
                Upload button (opens file picker) for empty slots. On the Visa
                Information Form row, the EP VIF generator (Preview / PDF /
                Word) is surfaced inline ABOVE the upload controls — staff can use
                the system-generated VIF or upload their own; both stay available. */}
            <td className="px-4 py-3">
                {vif && (
                    <div className="mb-2">
                        <VifButtons vif={vif} leadId={leadId} checklistKey={row.key} keyThreads={keyThreads} />
                    </div>
                )}
                {doc ? (
                    <div className="flex flex-col gap-1.5">
                        {files.map((d) => (
                            <div key={d.id} className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 max-w-[300px]">
                                <FileText size={16} className="text-gray-300 flex-shrink-0" />
                                <span className="flex flex-col min-w-0 flex-1">
                                    <span className="text-[12px] text-gray-800 truncate" title={d.original_name}>
                                        {d.original_name}
                                    </span>
                                    <span className="text-[10px] text-gray-400">
                                        {d.created_at ? `${formatDate(d.created_at)}` : ""}
                                        {d.uploaded_by ? `${d.created_at ? " · " : ""}${d.uploaded_by}` : ""}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setPreviewDoc(d)}
                                    title="View & comment"
                                    className="text-[12px] font-semibold text-teal-700 hover:text-teal-900 flex-shrink-0"
                                >
                                    View
                                </button>
                                <FileMenu doc={d} leadId={leadId} checklistKey={row.kind === "checklist" ? row.key : null} />
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <UploadSlot
                            leadId={leadId}
                            checklistKey={row.key}
                            label="Upload"
                            empty
                        />
                    </div>
                )}
            </td>

            {/* Verdict — review status (dropdown) plus who decided it and when.
                Merges the former Status + Reviewed-by columns per the mockup. */}
            <td className="px-4 py-3">
                {isEngagementDoc ? (
                    // Engagement docs: the Written Agreement is Draft → Sent → Signed;
                    // the standard docs (standards / conduct / complaints) are Draft → Sent.
                    (isWrittenAgreement && engagementSigned) ? (
                        <span className="inline-flex flex-col">
                            <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-teal-600">
                                <span className="w-2 h-2 rounded-full bg-teal-600 flex-shrink-0" /> Signed
                            </span>
                            {engagementSignedAt && <span className="text-[10.5px] text-gray-400 pl-4">{formatDate(engagementSignedAt)}</span>}
                        </span>
                    ) : engagementSent ? (
                        <span className="inline-flex flex-col">
                            <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-blue-600">
                                <span className="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0" /> Sent
                            </span>
                            {engagementSentAt && <span className="text-[10.5px] text-gray-400 pl-4">{formatDate(engagementSentAt)}</span>}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-gray-500">
                            <span className="w-2 h-2 rounded-full bg-gray-300 flex-shrink-0" /> Draft
                        </span>
                    )
                ) : isInvoice ? (
                    // The invoice's verdict is its lifecycle: Draft → Sent → Paid.
                    invoicePaidNow ? (
                        <span className="inline-flex flex-col">
                            <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-teal-600">
                                <span className="w-2 h-2 rounded-full bg-teal-600 flex-shrink-0" /> Paid
                            </span>
                            {invoicePaidDate && <span className="text-[10.5px] text-gray-400 pl-4">{formatDate(invoicePaidDate)}</span>}
                        </span>
                    ) : invoiceSent ? (
                        <span className="inline-flex flex-col gap-0.5">
                            <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-blue-600">
                                <span className="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0" /> Sent
                            </span>
                            {engagementSentAt && <span className="text-[10.5px] text-gray-400 pl-4">{formatDate(engagementSentAt)}</span>}
                            {proofs.length > 0
                                ? <button type="button" onClick={() => setVerifyOpen(true)} className="text-[11px] font-semibold text-teal-700 hover:text-teal-900 pl-4 text-left">Verify payment ({proofs.length})</button>
                                : <span className="text-[10.5px] text-gray-400 pl-4">no proof yet</span>}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-gray-500">
                            <span className="w-2 h-2 rounded-full bg-gray-300 flex-shrink-0" /> Draft
                        </span>
                    )
                ) : doc ? (
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <VerdictSelect value={status} options={statusOptions} onPick={pickStatus} saving={savingStatus} />
                            {savingStatus && <span className="text-[10px] text-gray-400">Saving…</span>}
                        </div>
                        {row.document?.reviewed_by ? (
                            <p className="text-[10.5px] text-gray-400 pl-4 truncate" title={row.document.reviewed_by}>
                                {row.document.reviewed_by}
                                {row.document.reviewed_by_role && <> · {String(row.document.reviewed_by_role).replace(/_/g, " ")}</>}
                                {row.document.reviewed_at && <> · {formatDate(row.document.reviewed_at)}</>}
                            </p>
                        ) : (
                            <p className="text-[10.5px] text-gray-400 pl-4">not yet checked</p>
                        )}
                    </div>
                ) : (
                    <span className="inline-flex items-center gap-2 text-[11px] font-semibold text-gray-500">
                        <span className="w-2 h-2 rounded-full bg-gray-300" />
                        Not submitted
                    </span>
                )}
            </td>

            {/* Notes — a compact summary that opens the full discussion in place.
                Shows the latest note with who wrote it and when. Available on any
                checklist slot, even before the client uploads anything. */}
            <td className="px-4 py-3">
                {canNote ? (
                    <div className="max-w-[240px]">
                        {/* Reviewer note — pinned as an always-visible flag so staff
                            are alerted about this document without expanding. */}
                        {note && (
                            <div className="mb-1.5 flex items-start gap-1.5 rounded-md bg-amber-50 border border-amber-200 px-2 py-1">
                                <Flag size={11} className="text-amber-500 mt-[3px] flex-shrink-0" />
                                <span className="min-w-0">
                                    <span className="block text-[11px] leading-snug text-amber-900 line-clamp-2">{note}</span>
                                    {(doc?.reviewed_by || doc?.reviewed_at) && (
                                        <span className="block text-[9.5px] text-amber-700/80 mt-0.5 truncate">
                                            {doc?.reviewed_by || "Staff"}
                                            {doc?.reviewed_at && <> · {formatDateTime(doc.reviewed_at)}</>}
                                        </span>
                                    )}
                                </span>
                            </div>
                        )}
                        <button
                            type="button"
                            onClick={() => setNotesOpen((v) => ! v)}
                            className="text-left group w-full"
                        >
                            <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-gray-700 group-hover:text-gray-900">
                                {noteCount > 0 ? `${noteCount} note${noteCount === 1 ? "" : "s"}` : "Add a note"}
                                {notesOpen
                                    ? <ChevronDown size={12} className="text-gray-400" />
                                    : <ChevronRight size={12} className="text-gray-400" />}
                            </span>
                            {/* Last discussion note (the reviewer note is already shown
                                above as a flag, so this previews the latest thread). */}
                            {lastThread && ! notesOpen && (
                                <span className="block mt-0.5">
                                    <span className="block text-[11px] text-gray-500 line-clamp-2">{lastThread.body}</span>
                                    {(lastThread.author || lastThread.created_at) && (
                                        <span className="block text-[10px] text-gray-400 mt-0.5 truncate">
                                            {lastThread.author || "Staff"}
                                            {lastThread.created_at && <> · {formatDateTime(lastThread.created_at)}</>}
                                        </span>
                                    )}
                                </span>
                            )}
                        </button>
                    </div>
                ) : (
                    <span className="text-[11px] text-gray-300">—</span>
                )}
            </td>
            {rejectOpen && (
                <RejectModal
                    rowLabel={row.label}
                    initialNote={note}
                    onClose={() => setRejectOpen(false)}
                    onConfirm={confirmReject}
                />
            )}
        </tr>
        {/* Comments render full-width below the row. They're per FILE — a
            checklist slot can hold several uploads (e.g. two Passport.pdf), and
            each keeps its own thread, labelled by filename when there's more
            than one. */}
        {canNote && notesOpen && (
            <tr className="border-b border-gray-50 last:border-b-0 bg-gray-50/40">
                <td colSpan={4} className="px-4 pb-4 pt-1">
                    {(() => {
                        const multi = files.length > 1;
                        return (
                            <div className="ml-6 max-w-3xl space-y-3">
                                {/* Composer at the top. Checklist rows note against
                                    the requirement (no upload needed); engagement /
                                    invoice docs note against the file. */}
                                {isChecklistRow ? (
                                    <ThreadComposer
                                        leadId={leadId}
                                        caseStaff={caseStaff}
                                        fixedAnchor={{ anchor_type: "checklist", anchor_key: row.key }}
                                        plain
                                        placeholder={doc ? "Write a note about this requirement…" : "Leave a note about this requirement…"}
                                    />
                                ) : (
                                    files.map((d) => (
                                        <ThreadComposer
                                            key={`compose-${d.id}`}
                                            leadId={leadId}
                                            caseStaff={caseStaff}
                                            fixedAnchor={{ anchor_type: "document", anchor_id: d.id }}
                                            plain
                                            placeholder={multi ? `Write a note about ${d.original_name}…` : "Write a note about this document…"}
                                        />
                                    ))
                                )}

                                {/* Reviewer note — below the composer, as a card. The
                                    private status note; editable, saves on blur. */}
                                {doc && (
                                    <div className="rounded-xl border border-amber-200 bg-amber-50/70 px-3.5 py-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex items-center gap-2 min-w-0">
                                                <span className="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-400 text-white flex-shrink-0">
                                                    <Flag size={13} />
                                                </span>
                                                <span className="text-[13px] font-bold text-gray-900 truncate">{doc?.reviewed_by || "Reviewer note"}</span>
                                                {doc?.reviewed_at && <span className="text-[11.5px] text-gray-400 flex-shrink-0">{formatDateTime(doc.reviewed_at)}</span>}
                                            </div>
                                            <span className="text-[9.5px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 flex-shrink-0">Reviewer note</span>
                                        </div>
                                        <div className="relative mt-2">
                                            <input
                                                type="text"
                                                value={note}
                                                onChange={(e) => setNote(e.target.value)}
                                                onBlur={onNoteBlur}
                                                placeholder="Add a reviewer note…"
                                                maxLength={500}
                                                disabled={savingNote}
                                                className="w-full text-[13px] px-3 py-2 rounded-lg border border-amber-200 bg-white focus:outline-none focus:border-amber-400 disabled:opacity-50"
                                            />
                                            {savedNote && (
                                                <span className="absolute right-2.5 top-2.5 inline-flex items-center text-emerald-600"><Check size={12} /></span>
                                            )}
                                        </div>
                                        {/* Replies to the reviewer note, nested under it. */}
                                        {reviewerReplies.filter((t) => ! t.parent_id).length > 0 && (
                                            <div className="mt-2.5 ml-5 space-y-2">
                                                {reviewerReplies.filter((t) => ! t.parent_id).map((t) => (
                                                    <ThreadItem
                                                        key={t.id}
                                                        thread={t}
                                                        leadId={leadId}
                                                        caseStaff={caseStaff}
                                                        anchor={{ anchor_type: "reviewer_note", anchor_id: doc.id }}
                                                        childrenOf={(id) => reviewerReplies.filter((x) => x.parent_id === id).sort((a, b) => a.id - b.id)}
                                                        isReply
                                                    />
                                                ))}
                                            </div>
                                        )}
                                        {/* Staff can reply to the reviewer note — nests under it. */}
                                        <ReviewerReplyBox leadId={leadId} docId={doc.id} />
                                    </div>
                                )}

                                {/* Requirement notes (checklist-anchored). */}
                                {isChecklistRow && keyThreads.filter((t) => ! t.parent_id).map((t) => (
                                    <ThreadItem
                                        key={t.id}
                                        thread={t}
                                        leadId={leadId}
                                        caseStaff={caseStaff}
                                        anchor={{ anchor_type: "checklist", anchor_key: row.key }}
                                        childrenOf={(id) => keyThreads.filter((x) => x.parent_id === id).sort((a, b) => a.id - b.id)}
                                    />
                                ))}

                                {/* Existing per-file document comments. */}
                                {files.map((d) => {
                                    const dThreads = threadsByDoc.get(d.id) || [];
                                    const roots = dThreads.filter((t) => ! t.parent_id);
                                    if (roots.length === 0 && ! multi) return null;
                                    return (
                                        <div key={d.id} className="space-y-2">
                                            {multi && (
                                                <p className="text-[10.5px] font-semibold text-gray-500 inline-flex items-center gap-1.5">
                                                    <FileText size={11} className="text-gray-400" /> {d.original_name}
                                                </p>
                                            )}
                                            {roots.map((t) => (
                                                <ThreadItem
                                                    key={t.id}
                                                    thread={t}
                                                    leadId={leadId}
                                                    caseStaff={caseStaff}
                                                    anchor={{ anchor_type: "document", anchor_id: d.id }}
                                                    childrenOf={(id) => dThreads.filter((x) => x.parent_id === id).sort((a, b) => a.id - b.id)}
                                                />
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })()}
                </td>
            </tr>
        )}
        {previewDoc && (
            <DocPreviewModal
                doc={previewDoc}
                label={row.label}
                leadId={leadId}
                caseStaff={caseStaff}
                threads={threadsByDoc.get(previewDoc.id) || []}
                onClose={() => setPreviewDoc(null)}
            />
        )}
        {verifyOpen && (
            <ProofVerifyModal
                proofs={proofs}
                leadId={leadId}
                invoiceId={doc?.id}
                onClose={() => setVerifyOpen(false)}
            />
        )}
        </Fragment>
    );
}

// Proof-of-payment verification — previews the client-uploaded proof inline and,
// when several are submitted, exposes a tab per proof so staff can flip between
// them. Verifying posts the same document-status endpoint the review flow uses;
// an Approved proof advances the case stage to "Invoice Paid" and flips the
// invoice badge to "Paid".
function ProofVerifyModal({ proofs = [], leadId, invoiceId = null, onClose }) {
    const [busyId, setBusyId] = useState(null);
    const [activeId, setActiveId] = useState(proofs[0]?.id ?? null);

    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        const prev = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        window.addEventListener("keydown", onKey);
        return () => { document.body.style.overflow = prev; window.removeEventListener("keydown", onKey); };
    }, [onClose]);

    const active = proofs.find((p) => p.id === activeId) || proofs[0] || null;

    const act = (proof, status) => {
        if (!proof) return;
        setBusyId(proof.id);
        router.post(
            `${leadBase()}/${leadId}/documents/${proof.id}/status`,
            { status, invoice_id: status === "Approved" ? invoiceId : null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(status === "Approved" ? "Payment verified" : "Proof rejected");
                    onClose();
                },
                onError: () => toast.error("Could not update the proof"),
                onFinish: () => setBusyId(null),
            },
        );
    };

    const inlineUrl = active ? docDownloadUrl(active.id, { inline: true }) : null;
    const isPdf = (active?.mime || "").includes("pdf") || /\.pdf$/i.test(active?.original_name || "");
    const isImage = (active?.mime || "").startsWith("image/") || /\.(png|jpe?g|gif|webp|heic)$/i.test(active?.original_name || "");
    const approved = active?.status === "Approved";
    const rejected = active?.status === "Rejected";

    return createPortal(
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="flex h-[88vh] w-[94vw] max-w-[1000px] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" onClick={(e) => e.stopPropagation()}>
                {/* Header */}
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                    <div>
                        <p className="mb-0.5 text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400">Proof of payment</p>
                        <h3 className="text-sm font-semibold text-gray-900">Verify to mark the invoice paid</h3>
                    </div>
                    <button type="button" onClick={onClose} className="flex-shrink-0 text-gray-400 hover:text-gray-700"><XIcon size={18} /></button>
                </div>

                {/* Tabs — one per submitted proof */}
                {proofs.length > 1 && (
                    <div className="flex flex-wrap gap-1.5 border-b border-gray-100 bg-gray-50 px-4 py-2">
                        {proofs.map((p, i) => {
                            const isActive = p.id === active?.id;
                            const dot = p.status === "Approved" ? "bg-teal-500" : p.status === "Rejected" ? "bg-red-400" : "bg-amber-400";
                            return (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => setActiveId(p.id)}
                                    className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-medium ${isActive ? "bg-white text-gray-900 shadow-sm ring-1 ring-gray-200" : "text-gray-500 hover:text-gray-800"}`}
                                >
                                    <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />
                                    Proof {i + 1}
                                </button>
                            );
                        })}
                    </div>
                )}

                {/* Preview */}
                <div className="flex min-h-0 flex-1 flex-col bg-gray-100">
                    {!active ? (
                        <div className="flex flex-1 items-center justify-center text-[13px] text-gray-500">No proof of payment uploaded yet.</div>
                    ) : isImage ? (
                        <div className="flex flex-1 items-start justify-center overflow-auto p-4">
                            <img src={inlineUrl} alt={active.original_name} className="h-auto max-w-full rounded-lg shadow-sm" />
                        </div>
                    ) : isPdf ? (
                        <iframe src={inlineUrl} title={active.original_name} className="w-full flex-1 border-0" />
                    ) : (
                        <div className="flex flex-1 flex-col items-center justify-center p-8 text-center">
                            <FileText size={40} className="text-gray-300" />
                            <p className="mt-3 text-sm text-gray-600">No inline preview for this file type.</p>
                            <a href={docDownloadUrl(active.id)} className="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-[12px] font-semibold text-gray-700 hover:bg-white"><Download size={13} /> Download to view</a>
                        </div>
                    )}
                </div>

                {/* Footer — filename + verify/reject for the active proof */}
                {active && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-white px-5 py-3">
                        <div className="min-w-0">
                            <p className="truncate text-[13px] font-medium text-gray-800">{active.original_name || "Proof of payment"}</p>
                            <p className="text-[11px] text-gray-400">
                                {approved ? "Verified" : rejected ? "Rejected" : "Awaiting verification"}
                                {active.created_at ? ` · uploaded ${formatDate(active.created_at)}` : ""}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <a href={inlineUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-[12px] font-semibold text-gray-600 hover:bg-gray-50"><Eye size={13} /> Open in tab</a>
                            {!approved && !rejected && (
                                <button
                                    type="button"
                                    disabled={busyId === active.id}
                                    onClick={() => act(active, "Rejected")}
                                    className="rounded-lg border border-red-200 px-3 py-1.5 text-[12px] font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                                >
                                    Reject
                                </button>
                            )}
                            {!rejected && (
                                <button
                                    type="button"
                                    disabled={busyId === active.id}
                                    onClick={() => act(active, "Approved")}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-3.5 py-1.5 text-[12px] font-semibold text-white hover:bg-teal-700 disabled:opacity-50"
                                >
                                    {busyId === active.id ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
                                    {approved ? "Mark this invoice paid" : "Verify payment"}
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </div>,
        document.body,
    );
}

// Bulk-download dropdown — "Download approved (ZIP)" (the lodgement bundle) or
// "Download all (ZIP)" (every document on the case).
function DownloadAllMenu({ leadId }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (! open) return;
        const onDoc = (e) => { if (! ref.current?.contains(e.target)) setOpen(false); };
        document.addEventListener("mousedown", onDoc);
        return () => document.removeEventListener("mousedown", onDoc);
    }, [open]);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wider bg-gray-900 text-white hover:bg-black transition-colors"
            >
                <Download size={12} /> Download (ZIP) <ChevronDown size={12} />
            </button>
            {open && (
                <div className="absolute right-0 mt-1 w-56 bg-white rounded-lg shadow-xl border border-gray-100 py-1 z-20">
                    <a
                        href={`${leadBase()}/${leadId}/documents/download-all`}
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2 px-3 py-2 text-[12px] text-gray-700 hover:bg-gray-50"
                    >
                        <Download size={13} className="text-gray-400" />
                        <span><span className="font-semibold">Approved only</span><span className="block text-[10px] text-gray-400">The lodgement bundle</span></span>
                    </a>
                    <a
                        href={`${leadBase()}/${leadId}/documents/download-all?all=1`}
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2 px-3 py-2 text-[12px] text-gray-700 hover:bg-gray-50"
                    >
                        <Download size={13} className="text-gray-400" />
                        <span><span className="font-semibold">All documents</span><span className="block text-[10px] text-gray-400">Every file on the case</span></span>
                    </a>
                </div>
            )}
        </div>
    );
}

// Per-file actions menu (⋮) — Download + Delete, tucked away to keep the
// attachment cell tidy. Portal-rendered so the dropdown escapes the scrollable
// table. Delete asks for a second click to confirm (destructive, irreversible).
// Custom verdict dropdown — a colored trigger + an on-brand popover menu
// (the native <select> can't be styled and looked out of place).
function VerdictSelect({ value, options, onPick, saving }) {
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0 });
    const btnRef = useRef(null);
    const MENU_W = 200;
    // Role/portal-aware options are passed in (manager vs adviser see different
    // verdicts); fall back to the full set. The current label is resolved
    // against the full set so a value outside the caller's list still renders.
    const menuOptions = options && options.length ? options : STATUS_OPTIONS;
    const current = menuOptions.find((o) => o.value === value) || STATUS_OPTIONS.find((o) => o.value === value);

    useEffect(() => {
        if (! open) return;
        const place = () => {
            const r = btnRef.current?.getBoundingClientRect();
            if (r) setCoords({ top: r.bottom + 4, left: Math.max(8, Math.min(r.left, window.innerWidth - MENU_W - 8)) });
        };
        place();
        window.addEventListener("scroll", place, true);
        window.addEventListener("resize", place);
        return () => { window.removeEventListener("scroll", place, true); window.removeEventListener("resize", place); };
    }, [open]);

    return (
        <>
            <button
                ref={btnRef}
                type="button"
                disabled={saving}
                onClick={() => setOpen((o) => ! o)}
                title="Change verdict"
                className={`inline-flex items-center gap-1.5 text-[13px] font-semibold rounded-md px-1.5 py-0.5 -ml-1.5 hover:bg-gray-50 focus:outline-none disabled:opacity-50 ${VERDICT_TEXT[value] || "text-gray-600"}`}
            >
                <span className={`w-2 h-2 rounded-full flex-shrink-0 ${VERDICT_DOT[value] || "bg-gray-300"}`} />
                {current?.label || "Set verdict"}
                <ChevronDown size={13} className="text-gray-400" />
            </button>
            {open && createPortal(
                <>
                    <div className="fixed inset-0 z-[59]" onClick={() => setOpen(false)} />
                    <div
                        style={{ position: "fixed", top: coords.top, left: coords.left, width: MENU_W }}
                        className="z-[60] bg-white rounded-lg shadow-xl border border-gray-100 py-1"
                    >
                        {menuOptions.map((o) => {
                            const active = o.value === value;
                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => { setOpen(false); onPick(o.value); }}
                                    className={`w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12.5px] hover:bg-gray-50 ${active ? "bg-gray-50 font-semibold" : ""}`}
                                >
                                    <span className={`w-2 h-2 rounded-full flex-shrink-0 ${VERDICT_DOT[o.value] || "bg-gray-300"}`} />
                                    <span className={VERDICT_TEXT[o.value] || "text-gray-700"}>{o.label}</span>
                                    {active && <Check size={13} className="ml-auto text-gray-400" />}
                                </button>
                            );
                        })}
                    </div>
                </>,
                document.body,
            )}
        </>
    );
}

function FileMenu({ doc, leadId, checklistKey = null }) {
    const [open, setOpen] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [sharing, setSharing] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0 });
    const btnRef = useRef(null);
    const fileRef = useRef(null);
    const MENU_W = 168;

    useEffect(() => {
        if (! open) return;
        const place = () => {
            const r = btnRef.current?.getBoundingClientRect();
            if (r) setCoords({ top: r.bottom + 4, left: Math.max(8, Math.min(r.right - MENU_W, window.innerWidth - MENU_W - 8)) });
        };
        place();
        window.addEventListener("scroll", place, true);
        window.addEventListener("resize", place);
        return () => { window.removeEventListener("scroll", place, true); window.removeEventListener("resize", place); };
    }, [open]);

    const close = () => { setOpen(false); setConfirming(false); };

    const del = () => {
        setBusy(true);
        router.delete(`${leadBase()}/${leadId}/documents/${doc.id}`, {
            preserveScroll: true,
            preserveState: true,
            only: ["documents"],
            onSuccess: () => toast.success("Document deleted"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not delete"),
            onFinish: () => { setBusy(false); close(); },
        });
    };

    // Send to client = surface this document on the client's portal + /track.
    const sendToClient = () => {
        setSharing(true);
        router.post(`${leadBase()}/${leadId}/documents/${doc.id}/send-to-client`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ["documents"],
            onSuccess: () => toast.success("Sent to client"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not send"),
            onFinish: () => { setSharing(false); close(); },
        });
    };

    // Replace = upload a new file against the same checklist slot.
    const onReplace = (e) => {
        const files = Array.from(e.target.files || []);
        if (files.length === 0) return;
        setUploading(true);
        router.post(
            `${leadBase()}/${leadId}/documents/checklist/${encodeURIComponent(checklistKey)}/upload`,
            { files },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                only: ["documents"],
                onSuccess: () => toast.success("File uploaded"),
                onError: (errs) => toast.error(Object.values(errs)[0] || "Upload failed"),
                onFinish: () => {
                    setUploading(false);
                    if (fileRef.current) fileRef.current.value = "";
                    close();
                },
            },
        );
    };

    return (
        <>
            <button
                ref={btnRef}
                type="button"
                onClick={() => setOpen((o) => !o)}
                title="More"
                className="inline-flex items-center justify-center p-1.5 rounded-md border border-gray-200 bg-white text-gray-600 hover:text-gray-900 hover:bg-gray-50"
            >
                <MoreVertical size={12} />
            </button>
            {checklistKey && (
                <input
                    ref={fileRef}
                    type="file"
                    multiple
                    onChange={onReplace}
                    className="hidden"
                    accept="application/pdf,image/*,.doc,.docx,.xls,.xlsx"
                />
            )}
            {open && createPortal(
                <>
                    <div className="fixed inset-0 z-[59]" onClick={close} />
                    <div
                        style={{ position: "fixed", top: coords.top, left: coords.left, width: MENU_W }}
                        className="z-[60] bg-white rounded-lg shadow-xl border border-gray-100 py-1"
                    >
                        <a
                            href={docDownloadUrl(doc.id)}
                            onClick={close}
                            className="flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50"
                        >
                            <Download size={13} className="text-gray-400" /> Download
                        </a>
                        {doc?.status === "StaffShared" ? (
                            <div className="flex items-center gap-2 px-3 py-1.5 text-[12px] text-teal-700"><Check size={13} /> Shared with client</div>
                        ) : (
                            <button
                                type="button"
                                onClick={sendToClient}
                                disabled={sharing}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            >
                                {sharing ? <Loader2 size={13} className="animate-spin text-gray-400" /> : <Send size={13} className="text-gray-400" />}
                                {sharing ? "Sending…" : "Send to client"}
                            </button>
                        )}
                        {checklistKey && (
                            <button
                                type="button"
                                onClick={() => fileRef.current?.click()}
                                disabled={uploading}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            >
                                {uploading ? <Loader2 size={13} className="animate-spin text-gray-400" /> : <Upload size={13} className="text-gray-400" />}
                                {uploading ? "Uploading…" : "Replace"}
                            </button>
                        )}
                        {! confirming ? (
                            <button
                                type="button"
                                onClick={() => setConfirming(true)}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] text-rose-600 hover:bg-rose-50"
                            >
                                <Trash2 size={13} /> Delete
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={del}
                                disabled={busy}
                                className="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[12px] font-semibold text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50"
                            >
                                {busy ? <Loader2 size={13} className="animate-spin" /> : <Trash2 size={13} />} Click to confirm
                            </button>
                        )}
                    </div>
                </>,
                document.body,
            )}
        </>
    );
}

// In-window document preview popup with a comment box — the "view" button opens
// this instead of navigating away, so staff (or the adviser) can read the file
// and drop a comment on it in one place. Comments are the document's thread
// (attribution + history for free), so they also show on the row below.
function DocPreviewModal({ doc, label, leadId, caseStaff = [], threads = [], onClose }) {
    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        const prev = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        window.addEventListener("keydown", onKey);
        return () => { document.body.style.overflow = prev; window.removeEventListener("keydown", onKey); };
    }, [onClose]);

    const inlineUrl = docDownloadUrl(doc.id, { inline: true });
    const isPdf = (doc.mime || "").includes("pdf");
    const isImage = (doc.mime || "").startsWith("image/");

    return createPortal(
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/40" onClick={onClose}>
            <div className="w-[94vw] max-w-[1150px] h-[88vh] rounded-2xl bg-white shadow-xl flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400 mb-0.5">{label}</p>
                        <h3 className="text-sm font-semibold text-gray-900 truncate">{doc.original_name}</h3>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700 flex-shrink-0"><XIcon size={18} /></button>
                </div>

                <div className="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">
                    {/* Preview */}
                    <div className="lg:flex-1 min-h-0 bg-gray-100 border-b lg:border-b-0 lg:border-r border-gray-100 flex flex-col">
                        {isImage ? (
                            <div className="flex-1 overflow-auto p-4 flex items-start justify-center">
                                <img src={inlineUrl} alt={doc.original_name} className="max-w-full h-auto rounded-lg shadow-sm" />
                            </div>
                        ) : isPdf ? (
                            <iframe src={inlineUrl} title={doc.original_name} className="flex-1 w-full border-0" />
                        ) : (
                            <div className="flex-1 flex flex-col items-center justify-center text-center p-8">
                                <FileText size={40} className="text-gray-300" />
                                <p className="mt-3 text-sm text-gray-600">No inline preview for this file type.</p>
                                <a href={docDownloadUrl(doc.id)} className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-[12px] font-semibold hover:bg-white"><Download size={13} /> Download to view</a>
                            </div>
                        )}
                        <div className="flex items-center gap-1.5 px-4 py-2 border-t border-gray-200 bg-white">
                            <a href={inlineUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-200 text-gray-600 text-[11px] font-semibold hover:bg-gray-50"><Eye size={12} /> Open in tab</a>
                            <a href={docDownloadUrl(doc.id)} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-200 text-gray-600 text-[11px] font-semibold hover:bg-gray-50"><Download size={12} /> Download</a>
                        </div>
                    </div>

                    {/* Comments */}
                    <div className="lg:w-[360px] flex-shrink-0 overflow-y-auto overscroll-contain p-4 bg-gray-50">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-2 inline-flex items-center gap-1.5"><MessageSquare size={12} /> Comments</p>
                        <div className="space-y-1.5 mb-3">
                            {threads.length === 0 && <p className="text-[12px] text-gray-400">No comments yet. Add the first below.</p>}
                            {threads.map((t) => <ThreadItem key={t.id} thread={t} leadId={leadId} />)}
                        </div>
                        <ThreadComposer
                            leadId={leadId}
                            caseStaff={caseStaff}
                            fixedAnchor={{ anchor_type: "document", anchor_id: doc.id }}
                            compact
                            plain
                            placeholder="Add a comment…"
                        />
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}

/**
 * Per-item toggle controlling whether this checklist item shows on the
 * applicant's public tracking link. Posts to the staff endpoint and
 * partial-reloads the checklist so the flag reflects the saved state.
 */
function TrackVisibilityCheckbox({ leadId, checklistKey, hidden }) {
    // Optimistic local state so the box flips instantly; falls back to the
    // server value once the partial reload lands (or reverts on error).
    const [optimistic, setOptimistic] = useState(null);
    const shown = optimistic ?? ! hidden;

    const onChange = (e) => {
        const nextShown = e.target.checked;
        setOptimistic(nextShown);
        router.post(
            `${leadBase()}/${leadId}/documents/track-visibility`,
            { checklist_keys: [checklistKey], hidden: ! nextShown },
            {
                preserveScroll: true,
                preserveState: true,
                only: ["checklist"],
                onSuccess: () => toast.success(nextShown ? "Shown on tracker" : "Hidden from tracker"),
                onError: () => { setOptimistic(null); toast.error("Could not update visibility"); },
                onFinish: () => setOptimistic(null),
            },
        );
    };

    return (
        <input
            type="checkbox"
            checked={shown}
            onChange={onChange}
            title={shown
                ? "Shown on the applicant's tracker — uncheck to hide"
                : "Hidden from the applicant's tracker — check to show"}
            className="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-0 w-3.5 h-3.5 flex-shrink-0 cursor-pointer"
        />
    );
}

/**
 * Section-level "select all" — shows/hides every checklist item in a
 * category at once. Checked = all shown; indeterminate when only some are.
 */
function SectionSelectAll({ leadId, rows }) {
    const ref = useRef(null);
    // Optimistic override for instant feedback (true = all shown).
    const [optimistic, setOptimistic] = useState(null);
    const shownCount = rows.filter((r) => ! r.hidden).length;
    const allShown = optimistic ?? (rows.length > 0 && shownCount === rows.length);
    const someShown = optimistic == null && shownCount > 0 && shownCount < rows.length;

    useEffect(() => {
        if (ref.current) ref.current.indeterminate = someShown;
    }, [someShown]);

    const onChange = (e) => {
        const nextShown = e.target.checked;
        setOptimistic(nextShown);
        router.post(
            `${leadBase()}/${leadId}/documents/track-visibility`,
            { checklist_keys: rows.map((r) => r.key), hidden: ! nextShown },
            {
                preserveScroll: true,
                preserveState: true,
                only: ["checklist"],
                onSuccess: () => toast.success(nextShown ? "Section shown on tracker" : "Section hidden from tracker"),
                onError: () => { setOptimistic(null); toast.error("Could not update visibility"); },
                onFinish: () => setOptimistic(null),
            },
        );
    };

    return (
        <input
            ref={ref}
            type="checkbox"
            checked={allShown}
            onChange={onChange}
            title="Show/hide every item in this section on the applicant's tracker"
            className="rounded border-gray-400 text-emerald-600 focus:ring-0 w-3.5 h-3.5 flex-shrink-0 cursor-pointer"
        />
    );
}

/**
 * Rejection modal — collects the mandatory reviewer note before the
 * status flips to Rejected. A rejected document without a reason on the
 * audit trail is worse than no rejection (consultant can't follow up,
 * client has no actionable feedback). Reason length capped at 500 to
 * match the LeadDocument.note schema column.
 */
function RejectModal({ rowLabel, initialNote = "", onClose, onConfirm }) {
    const [reason, setReason] = useState(initialNote || "");
    const trimmed = reason.trim();
    const isValid = trimmed.length > 0;
    if (typeof document === "undefined") return null;

    return createPortal(
        (
            <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={onClose}>
                <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full" onClick={(e) => e.stopPropagation()}>
                    <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                                <AlertCircle size={14} className="text-red-500" />
                                Reject document
                            </h2>
                            <p className="text-[11px] text-gray-500 mt-0.5 truncate">{rowLabel}</p>
                        </div>
                        <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700">
                            <XIcon size={16} />
                        </button>
                    </header>
                    <div className="px-5 py-4 space-y-2">
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider">
                            Reason for rejection <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            rows={4}
                            maxLength={500}
                            placeholder="e.g. Issued more than 6 months ago — please obtain a fresh certificate."
                            className="w-full text-xs px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                            autoFocus
                        />
                        <p className="text-[10px] text-gray-400">
                            This note is shown to the client on their tracker so they know what to fix. {trimmed.length}/500
                        </p>
                    </div>
                    <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={() => isValid && onConfirm(trimmed)}
                            disabled={! isValid}
                            className="inline-flex items-center gap-1 px-3 py-1.5 rounded-md text-xs font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <XIcon size={11} /> Reject document
                        </button>
                    </footer>
                </div>
            </div>
        ),
        document.body,
    );
}

/**
 * Inline upload button bound to a checklist key. Triggers a hidden
 * file input and POSTs multipart to the existing staff-checklist-upload
 * endpoint:
 *   POST /admin/leads/{lead}/documents/checklist/{key}/upload
 *
 * Uses Inertia's router.post so CSRF + partial-prop reload (just
 * `documents`) are both handled automatically. After the upload the
 * row re-renders with the new doc + status dropdown active.
 *
 * `empty`: the slot has no doc yet — render a slightly more prominent
 * primary-style button. Otherwise (replace mode), render as a quiet
 * icon-only secondary button matching the View/Download buttons.
 */
function UploadSlot({ leadId, checklistKey, label = "Upload", empty = false }) {
    const inputRef = useRef(null);
    const [uploading, setUploading] = useState(false);

    if (! checklistKey) return null; // orphan rows can't be re-uploaded against a key

    const onPick = () => inputRef.current?.click();

    const onChange = (e) => {
        const files = Array.from(e.target.files || []);
        if (files.length === 0) return;
        setUploading(true);
        router.post(
            `${leadBase()}/${leadId}/documents/checklist/${encodeURIComponent(checklistKey)}/upload`,
            { files },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                only: ["documents"],
                onSuccess: () => toast.success(`${files.length} file${files.length === 1 ? "" : "s"} uploaded`),
                onError:   (errs) => toast.error(Object.values(errs)[0] || "Upload failed"),
                onFinish:  () => {
                    setUploading(false);
                    if (inputRef.current) inputRef.current.value = "";
                },
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={onPick}
                disabled={uploading}
                title={empty ? "Upload file for this checklist item" : "Replace with a new file"}
                className={
                    empty
                        ? "inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                        : "inline-flex items-center justify-center p-1.5 rounded-md border border-gray-200 bg-white text-gray-600 hover:text-gray-900 hover:bg-gray-50 disabled:opacity-50"
                }
            >
                {uploading
                    ? <Loader2 size={empty ? 11 : 12} className="animate-spin" />
                    : <Upload size={empty ? 11 : 12} />}
                {empty && <span>{uploading ? "Uploading…" : label}</span>}
            </button>
            <input
                ref={inputRef}
                type="file"
                multiple
                onChange={onChange}
                className="hidden"
                accept="application/pdf,image/*,.doc,.docx,.xls,.xlsx"
            />
        </>
    );
}

/**
 * Inline "Request from client" trigger. Opens a small modal that posts
 * to LeadDocumentController::requestStore — that endpoint already handles
 * everything: creates a LeadDocumentRequest, sends the lead an email
 * (via CommunicationService template 'doc_request' with fallback to the
 * legacy Mailable), and the audit trail. We just feed it one item.
 *
 * The checklist label becomes the request's `label`; the consultant's
 * optional message becomes the `description` shown in the email.
 */
function RequestFromClient({ leadId, rowLabel, rowRequired }) {
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState("");
    const [submitting, setSubmitting] = useState(false);

    // Closing keeps the typed message — this component stays mounted (only the
    // portal toggles), so the draft is still here when reopened.
    const attemptClose = () => setOpen(false);

    const send = () => {
        if (submitting) return;
        setSubmitting(true);
        router.post(
            `${leadBase()}/${leadId}/documents/requests`,
            {
                items: [{
                    label:       rowLabel,
                    description: message.trim() || null,
                    required:    !! rowRequired,
                }],
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    toast.success("Request sent to client");
                    setOpen(false);
                    setMessage("");
                },
                onError: (errs) => toast.error(Object.values(errs)[0] || "Request failed"),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                title={`Email the client a link to upload "${rowLabel}"`}
                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-semibold border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
            >
                <Send size={11} /> Request
            </button>
            {open && typeof document !== "undefined" && createPortal(
                (
                    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={attemptClose}>
                        <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full" onClick={(e) => e.stopPropagation()}>
                            <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                                        <Send size={14} className="text-gray-500" /> Request from client
                                    </h2>
                                    <p className="text-[11px] text-gray-500 mt-0.5 truncate">{rowLabel}</p>
                                </div>
                                <button type="button" onClick={attemptClose} className="text-gray-400 hover:text-gray-700">
                                    <XIcon size={16} />
                                </button>
                            </header>
                            <div className="px-5 py-4 space-y-2">
                                <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider">
                                    Message (optional)
                                </label>
                                <textarea
                                    value={message}
                                    onChange={(e) => setMessage(e.target.value)}
                                    rows={4}
                                    maxLength={500}
                                    placeholder="e.g. Please upload the bio page only — not the full passport."
                                    className="w-full text-xs px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                                    autoFocus
                                />
                                <p className="text-[10px] text-gray-400">
                                    We'll email the client a link to upload this document via their tracker.
                                </p>
                            </div>
                            <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={send}
                                    disabled={submitting}
                                    className="inline-flex items-center gap-1 px-3 py-1.5 rounded-md text-xs font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                                >
                                    {submitting ? <Loader2 size={11} className="animate-spin" /> : <Send size={11} />}
                                    Send request
                                </button>
                            </footer>
                        </div>
                    </div>
                ),
                document.body,
            )}
        </>
    );
}

// One sent request — what was asked for, when/by whom, whether it's arrived,
// and a control to withdraw it.
function RequestRow({ req, leadId }) {
    const [busy, setBusy] = useState(false);
    const [sending, setSending] = useState(false);
    const [expanded, setExpanded] = useState(false);
    // A description is "long" once it's likely to be clipped on one line — show
    // the See more / See less toggle only then.
    const longDesc = (req.description || "").length > 90;
    const cancel = () => {
        if (busy) return;
        setBusy(true);
        router.delete(`${leadBase()}/${leadId}/documents/requests/${req.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success("Request removed"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not remove"),
            onFinish: () => setBusy(false),
        });
    };
    // Re-send the request email — uses the "Document requested" automation template.
    const resend = () => {
        if (sending) return;
        setSending(true);
        router.post(`${leadBase()}/${leadId}/documents/requests/${req.id}/resend`, {}, {
            preserveScroll: true,
            preserveState: true,
            onError: (e) => toast.error(Object.values(e)[0] || "Could not send"),
            onFinish: () => setSending(false),
        });
    };
    const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short" }) : "");

    return (
        <div className="flex items-center gap-2 bg-white rounded-lg border border-gray-100 px-3 py-2">
            <FileText size={13} className="text-gray-400 flex-shrink-0" />
            <div className="min-w-0 flex-1">
                <p className="text-[12px] font-medium text-gray-800 truncate">
                    {req.label}{req.required && <span className="text-rose-500"> *</span>}
                </p>
                {req.description && (
                    <div className="text-[10.5px] text-gray-500">
                        <p className={expanded ? "whitespace-pre-wrap" : "truncate"}>{req.description}</p>
                        {longDesc && (
                            <button
                                type="button"
                                onClick={() => setExpanded((v) => !v)}
                                className="font-semibold text-teal-600 hover:text-teal-800"
                            >
                                {expanded ? "See less" : "See more"}
                            </button>
                        )}
                    </div>
                )}
                <p className="text-[10px] text-gray-400">
                    Requested {fmt(req.requested_at)}{req.requested_by ? ` · ${req.requested_by}` : ""}
                </p>
            </div>
            {req.fulfilled ? (
                <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <Check size={10} /> Received
                </span>
            ) : (
                <span className="inline-flex items-center text-[10px] font-bold uppercase px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                    Waiting
                </span>
            )}
            <button
                type="button"
                onClick={resend}
                disabled={sending}
                title="Re-send the request email to the client"
                className="p-1 rounded-md text-gray-300 hover:text-violet-600 disabled:opacity-50"
            >
                {sending ? <Loader2 size={12} className="animate-spin" /> : <Send size={12} />}
            </button>
            <button
                type="button"
                onClick={cancel}
                disabled={busy}
                title="Withdraw this request"
                className="p-1 rounded-md text-gray-300 hover:text-rose-600 disabled:opacity-50"
            >
                {busy ? <Loader2 size={12} className="animate-spin" /> : <Trash2 size={12} />}
            </button>
        </div>
    );
}

// A document INZ requested (extracted from the RFI letter), rendered as a row
// inside the "Request Information" checklist table — so the letter and the
// documents it asks for sit together. Shows the client's uploaded file once it
// arrives, else an "awaiting" state; staff can re-send the reminder or withdraw.
function RfiRequestTableRow({ req, leadId }) {
    const pageUrl = usePage().url || "";
    const doc = req.latest_document || null;
    const [busy, setBusy] = useState(false);
    const [sending, setSending] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [status, setStatus] = useState(doc?.status || "");
    const [savingStatus, setSavingStatus] = useState(false);
    const [rejectOpen, setRejectOpen] = useState(false);
    const [previewDoc, setPreviewDoc] = useState(null);
    const fileRef = useRef(null);
    const longDesc = (req.description || "").length > 90;
    const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short" }) : "");
    const statusOptions = statusOptionsFor(pageUrl, doc?.status);

    // Staff upload the requested file on the client's behalf (client sent it
    // directly). It's tied to this request, fulfilling the row like a client upload.
    const onFile = (e) => {
        const files = Array.from(e.target.files || []);
        if (! files.length) return;
        const fd = new FormData();
        files.forEach((f) => fd.append("files[]", f));
        setUploading(true);
        router.post(`${leadBase()}/${leadId}/documents/requests/${req.id}/upload`, fd, {
            forceFormData: true, preserveScroll: true, preserveState: true,
            onSuccess: () => toast.success("Uploaded on behalf of the client"),
            onError: (er) => toast.error(Object.values(er)[0] || "Upload failed"),
            onFinish: () => { setUploading(false); if (fileRef.current) fileRef.current.value = ""; },
        });
    };
    // Change the review status of the uploaded file — same dropdown + endpoint as
    // every other document row (portal-dependent options; Reject needs a reason).
    const persistStatus = (nextStatus, nextNote) => {
        if (! doc) return;
        setSavingStatus(true);
        router.post(`${leadBase()}/${leadId}/documents/${doc.id}/status`,
            { status: nextStatus, note: nextNote },
            {
                preserveScroll: true, preserveState: true, only: ["documentRequests", "documents"],
                onSuccess: () => toast.success("Status updated"),
                onError: (errs) => { toast.error(Object.values(errs)[0] || "Update failed"); setStatus(doc.status || ""); },
                onFinish: () => setSavingStatus(false),
            });
    };
    const pickStatus = (next) => {
        if (! next || next === status) return;
        if (next === "Rejected") { setRejectOpen(true); return; }
        setStatus(next);
        persistStatus(next, doc?.note || "");
    };
    const cancel = () => {
        if (busy) return;
        setBusy(true);
        router.delete(`${leadBase()}/${leadId}/documents/requests/${req.id}`, {
            preserveScroll: true, preserveState: true,
            onSuccess: () => toast.success("Request removed"),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not remove"),
            onFinish: () => setBusy(false),
        });
    };
    const resend = () => {
        if (sending) return;
        setSending(true);
        router.post(`${leadBase()}/${leadId}/documents/requests/${req.id}/resend`, {}, {
            preserveScroll: true, preserveState: true,
            onError: (e) => toast.error(Object.values(e)[0] || "Could not send"),
            onFinish: () => setSending(false),
        });
    };

    return (
        <tr className="border-b border-gray-50 last:border-b-0 align-top">
            <td className="px-4 py-3">
                <div className="flex items-start gap-2">
                    <FileText size={14} className="text-gray-300 flex-shrink-0 mt-0.5" />
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900 leading-tight">
                            {req.label}{req.required && <span className="ml-1 text-red-500">*</span>}
                        </p>
                        {req.description && (
                            <div className="text-[10.5px] text-gray-500 mt-0.5">
                                <p className={expanded ? "whitespace-pre-wrap" : "truncate max-w-[420px]"}>{req.description}</p>
                                {longDesc && (
                                    <button type="button" onClick={() => setExpanded((v) => !v)} className="font-semibold text-teal-600 hover:text-teal-800">
                                        {expanded ? "See less" : "See more"}
                                    </button>
                                )}
                            </div>
                        )}
                        <p className="text-[10px] text-gray-400 mt-0.5">
                            Requested {fmt(req.requested_at)}{req.requested_by ? ` · ${req.requested_by}` : ""}
                        </p>
                    </div>
                </div>
            </td>

            {/* Attachment — the standard file card (View + menu) once uploaded,
                or a staff upload button while awaiting. */}
            <td className="px-4 py-3">
                <input ref={fileRef} type="file" multiple className="hidden" onChange={onFile} />
                {doc ? (
                    <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 max-w-[300px]">
                        <FileText size={16} className="text-gray-300 flex-shrink-0" />
                        <span className="flex flex-col min-w-0 flex-1">
                            <span className="text-[12px] text-gray-800 truncate" title={doc.original_name}>{doc.original_name}</span>
                            {doc.size ? <span className="text-[10px] text-gray-400 tabular-nums">{formatBytes(doc.size)}</span> : null}
                        </span>
                        <button type="button" onClick={() => setPreviewDoc(doc)} title="View & comment"
                            className="text-[12px] font-semibold text-teal-700 hover:text-teal-900 flex-shrink-0">View</button>
                        <FileMenu doc={doc} leadId={leadId} checklistKey={null} />
                    </div>
                ) : (
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={() => fileRef.current?.click()} disabled={uploading}
                            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-gray-900 text-white text-[11px] font-semibold hover:bg-black disabled:opacity-50">
                            {uploading ? <Loader2 size={11} className="animate-spin" /> : <Upload size={11} />} Upload
                        </button>
                        <span className="text-[10px] text-gray-400">or awaiting client</span>
                    </div>
                )}
            </td>

            {/* Verdict — review-status dropdown (portal-dependent) once a file is
                in, matching every other document row. */}
            <td className="px-4 py-3">
                {doc ? (
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <VerdictSelect value={status} options={statusOptions} onPick={pickStatus} saving={savingStatus} />
                            {savingStatus && <span className="text-[10px] text-gray-400">Saving…</span>}
                        </div>
                        {doc.reviewed_by ? (
                            <p className="text-[10.5px] text-gray-400 pl-4 truncate" title={doc.reviewed_by}>
                                {doc.reviewed_by}
                                {doc.reviewed_by_role && <> · {String(doc.reviewed_by_role).replace(/_/g, " ")}</>}
                                {doc.reviewed_at && <> · {formatDate(doc.reviewed_at)}</>}
                            </p>
                        ) : (
                            <p className="text-[10.5px] text-gray-400 pl-4">not yet checked</p>
                        )}
                    </div>
                ) : (
                    <span className="inline-flex items-center gap-2 text-[11px] font-semibold text-gray-500">
                        <span className="w-2 h-2 rounded-full bg-amber-400" /> Waiting
                    </span>
                )}
            </td>

            {/* Request actions — re-send the reminder or withdraw the request. */}
            <td className="px-4 py-3">
                <div className="flex items-center gap-1">
                    <button type="button" onClick={resend} disabled={sending} title="Re-send the request email to the client"
                        className="p-1 rounded-md text-gray-300 hover:text-violet-600 disabled:opacity-50">
                        {sending ? <Loader2 size={12} className="animate-spin" /> : <Send size={12} />}
                    </button>
                    <button type="button" onClick={cancel} disabled={busy} title="Withdraw this request"
                        className="p-1 rounded-md text-gray-300 hover:text-rose-600 disabled:opacity-50">
                        {busy ? <Loader2 size={12} className="animate-spin" /> : <Trash2 size={12} />}
                    </button>
                </div>
            </td>

            {rejectOpen && (
                <RejectModal
                    rowLabel={req.label}
                    initialNote={doc?.note || ""}
                    onClose={() => setRejectOpen(false)}
                    onConfirm={(reason) => { setStatus("Rejected"); setRejectOpen(false); persistStatus("Rejected", reason); }}
                />
            )}
            {previewDoc && (
                <DocPreviewModal doc={previewDoc} label={req.label} leadId={leadId} onClose={() => setPreviewDoc(null)} />
            )}
        </tr>
    );
}

// Ad-hoc document request — a free-text label (not tied to a checklist slot),
// emailed to the client and logged as a LeadDocumentRequest via the same
// endpoint the per-row request uses.
function RequestAnyDocument({ leadId, checklistItems = [], draft, setDraft, onClose }) {
    // Draft lives in the parent so closing the modal KEEPS it — picked checklist
    // keys, the "Other" custom rows, and the message all persist until sent.
    const picked = draft.picked;
    const customLabels = draft.customLabels;
    const message = draft.message;
    const [submitting, setSubmitting] = useState(false);

    const setMessage = (v) => setDraft((prev) => ({ ...prev, message: v }));
    const setCustomAt = (idx, val) => setDraft((prev) => ({ ...prev, customLabels: prev.customLabels.map((l, i) => (i === idx ? val : l)) }));
    const addCustom = () => setDraft((prev) => ({ ...prev, customLabels: [...prev.customLabels, ""] }));
    const removeCustom = (idx) => setDraft((prev) => ({ ...prev, customLabels: prev.customLabels.length <= 1 ? [""] : prev.customLabels.filter((_, i) => i !== idx) }));
    const customCount = customLabels.filter((l) => l.trim()).length;

    // Closing just hides the modal — the draft is kept in the parent. Clearing
    // only happens after a successful send.
    useEffect(() => {
        const onKey = (e) => { if (e.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    // Group checklist items by category so the picker mirrors the checklist table.
    const groups = useMemo(() => {
        const m = new Map();
        for (const it of checklistItems) {
            const cat = it.category || "Other";
            if (! m.has(cat)) m.set(cat, []);
            m.get(cat).push(it);
        }
        return Array.from(m.entries());
    }, [checklistItems]);

    const toggle = (key) => setDraft((prev) => {
        const next = new Set(prev.picked);
        next.has(key) ? next.delete(key) : next.add(key);
        return { ...prev, picked: next };
    });

    const count = picked.size + customCount;

    const send = () => {
        if (submitting) return;
        // Build one request item per picked checklist document + each custom one.
        const items = checklistItems
            .filter((it) => picked.has(it.key))
            .map((it) => ({ label: it.label, description: message.trim() || null, required: !! it.required }));
        for (const l of customLabels) {
            if (l.trim()) items.push({ label: l.trim(), description: message.trim() || null, required: true });
        }
        if (items.length === 0) { toast.error("Pick at least one document to request"); return; }
        setSubmitting(true);
        router.post(
            `${leadBase()}/${leadId}/documents/requests`,
            { items, message: message.trim() || null },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    toast.success(`Requested ${items.length} document${items.length > 1 ? "s" : ""} from the client`);
                    setDraft({ picked: new Set(), customLabels: [""], message: "" }); // sent → clear the draft
                    onClose();
                },
                onError: (errs) => toast.error(Object.values(errs)[0] || "Request failed"),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return createPortal(
        <div className="fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4" role="dialog" aria-modal="true" onClick={onClose}>
            <div className="bg-white rounded-2xl shadow-2xl max-w-lg w-full max-h-[85vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
                <header className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3 flex-shrink-0">
                    <div>
                        <h2 className="text-sm font-bold text-gray-900 inline-flex items-center gap-2">
                            <Plus size={14} className="text-gray-500" /> Request documents
                        </h2>
                        <p className="text-[11px] text-gray-500 mt-0.5">Tick which documents to ask the client for — your draft is kept if you close.</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700"><XIcon size={16} /></button>
                </header>

                <div className="px-5 py-4 space-y-4 overflow-y-auto">
                    {/* Checklist picker */}
                    {groups.length > 0 ? (
                        <div className="space-y-3">
                            {groups.map(([cat, its]) => (
                                <div key={cat}>
                                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5">{cat}</p>
                                    <div className="space-y-1">
                                        {its.map((it) => (
                                            <label key={it.key} className="flex items-center gap-2.5 px-2.5 py-2 rounded-lg border border-gray-100 hover:bg-gray-50 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={picked.has(it.key)}
                                                    onChange={() => toggle(it.key)}
                                                    className="rounded border-gray-300"
                                                />
                                                <span className="text-[12.5px] text-gray-800 flex-1 min-w-0 truncate">{it.label}</span>
                                                {it.required && <span className="text-[9px] font-bold uppercase tracking-wide text-rose-500">Required</span>}
                                                {it.provided && <span className="text-[9px] font-semibold uppercase tracking-wide text-emerald-600">Provided</span>}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-[12px] text-gray-500">No checklist items for this case yet — use "Other" below.</p>
                    )}

                    {/* Off-checklist custom requests — one or more */}
                    <div className="pt-1">
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1">Other (not on the checklist)</label>
                        <div className="space-y-1.5">
                            {customLabels.map((label, idx) => (
                                <div key={idx} className="flex items-center gap-1.5">
                                    <input
                                        type="text"
                                        value={label}
                                        onChange={(e) => setCustomAt(idx, e.target.value)}
                                        maxLength={120}
                                        placeholder="e.g. Employment contract"
                                        className="flex-1 min-w-0 text-xs px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900"
                                        onKeyDown={(e) => { if (e.key === "Enter" && label.trim() && idx === customLabels.length - 1) { e.preventDefault(); addCustom(); } }}
                                    />
                                    {(customLabels.length > 1 || label.trim()) && (
                                        <button type="button" onClick={() => removeCustom(idx)} title="Remove"
                                            className="flex-shrink-0 w-7 h-7 inline-flex items-center justify-center rounded-md text-gray-400 hover:text-rose-600 hover:bg-rose-50">
                                            <XIcon size={14} />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <button type="button" onClick={addCustom}
                            className="mt-1.5 inline-flex items-center gap-1 text-[11px] font-semibold text-teal-700 hover:text-teal-800">
                            <Plus size={12} /> Add another document
                        </button>
                    </div>

                    {/* Shared instruction line */}
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 uppercase tracking-wider mb-1">Message to client (optional)</label>
                        <textarea
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                            rows={2}
                            maxLength={500}
                            placeholder="Any instructions to include with the request…"
                            className="w-full text-xs px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:border-gray-900 resize-none"
                        />
                    </div>
                </div>

                <footer className="px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-2 flex-shrink-0">
                    <span className="text-[11px] text-gray-500">{count} selected</span>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={onClose} className="px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                        <button
                            type="button"
                            onClick={send}
                            disabled={submitting || count === 0}
                            className="inline-flex items-center gap-1 px-3 py-1.5 rounded-md text-xs font-semibold bg-gray-900 text-white hover:bg-black disabled:opacity-50"
                        >
                            {submitting ? <Loader2 size={11} className="animate-spin" /> : <Send size={11} />}
                            Send request{count > 1 ? `s (${count})` : ""}
                        </button>
                    </div>
                </footer>
            </div>
        </div>,
        document.body,
    );
}

// VisaType.checklist_items often use dotted keys like "identity.passport"
// or "admission.tor" — surface the leading segment as a category fallback
// when the JSON doesn't carry an explicit category field.
// VIF — the official Visa Information Form built from the case's assessment.
// Always reflects the latest intake; downloaded on demand (not stored).
// The generate actions for the ePathways VIF — filled from the case's visa
// assessment. Reused inside the "Visa Information Form" checklist row so the
// generator lives right where that requirement is fulfilled.
function VifButtons({ vif, leadId = null, checklistKey = null, keyThreads = [] }) {
    // "Preview VIF" opens the full readiness workspace (same modal as the visa
    // assessment "Open"); the PDF / Word downloads live in a ⋮ menu.
    const [open, setOpen] = useState(false);

    // In case context the modal's notes ARE this VIF row's checklist thread —
    // seed from it and post back to it, so a note in the modal shows on the row.
    const caseNotes = useMemo(
        () => (keyThreads || []).filter((t) => ! t.parent_id).map((t) => ({
            id: t.id, author: t.author, role: t.author_role, body: t.body, at: t.created_at,
        })),
        [keyThreads],
    );
    const canThread = !! (leadId && checklistKey);
    const postToThread = canThread
        ? (body) => new Promise((resolve) => {
            router.post(`${immBase()}/cases/${leadId}/threads`,
                { anchor_type: "checklist", anchor_key: checklistKey, body, addressed_to_id: null, requires_answer: false, client_visible: false },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => { toast.success("Note added"); resolve(true); },
                    onError: (e) => { toast.error(Object.values(e)[0] || "Could not post note"); resolve(false); },
                });
        })
        : undefined;

    return (
        <div className="flex items-center gap-1.5 flex-wrap">
            <button type="button" onClick={() => setOpen(true)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-[#009688]/30 text-[#009688] text-[11px] font-semibold hover:bg-[#009688]/5">
                <Eye size={12} /> Preview VIF
            </button>
            <DownloadMenu items={[
                { label: "Download PDF", href: vif.pdf_url },
                { label: "Download Word", href: vif.word_url },
            ]} />
            {open && (
                <IntakeViewModal
                    intake={{ visa_type: vif.type, id: vif.id, can_convert: false }}
                    onClose={() => setOpen(false)}
                    notesOverride={canThread ? caseNotes : undefined}
                    onPostNote={postToThread}
                />
            )}
        </div>
    );
}

// A ⋮ menu of download links (portal-rendered so it escapes the scrollable
// table). Used for the VIF's PDF / Word exports.
function DownloadMenu({ items = [] }) {
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState({ top: 0, left: 0 });
    const btnRef = useRef(null);
    const MENU_W = 176;

    useEffect(() => {
        if (! open) return;
        const place = () => {
            const r = btnRef.current?.getBoundingClientRect();
            if (r) setCoords({ top: r.bottom + 4, left: Math.max(8, Math.min(r.left, window.innerWidth - MENU_W - 8)) });
        };
        place();
        window.addEventListener("scroll", place, true);
        window.addEventListener("resize", place);
        return () => { window.removeEventListener("scroll", place, true); window.removeEventListener("resize", place); };
    }, [open]);

    return (
        <>
            <button
                ref={btnRef}
                type="button"
                onClick={() => setOpen((o) => !o)}
                title="Download options"
                className="inline-flex items-center justify-center p-1.5 rounded-lg border border-gray-200 bg-white text-gray-600 hover:text-gray-900 hover:bg-gray-50"
            >
                <MoreVertical size={13} />
            </button>
            {open && createPortal(
                <>
                    <div className="fixed inset-0 z-[59]" onClick={() => setOpen(false)} />
                    <div
                        style={{ position: "fixed", top: coords.top, left: coords.left, width: MENU_W }}
                        className="z-[60] bg-white rounded-lg shadow-xl border border-gray-100 py-1"
                    >
                        {items.map((it) => (
                            <a
                                key={it.label}
                                href={it.href}
                                onClick={() => setOpen(false)}
                                className="flex items-center gap-2 px-3 py-1.5 text-[12px] text-gray-700 hover:bg-gray-50"
                            >
                                <Download size={13} className="text-gray-400" /> {it.label}
                            </a>
                        ))}
                    </div>
                </>,
                document.body,
            )}
        </>
    );
}

// True when a checklist row is the Visa Information Form slot — the VIF
// generator is surfaced inline on that row rather than as a separate card.
const isVifLabel = (label) => /visa information form/i.test(label || "");

// Standalone fallback card — only shown when the checklist has no VIF row to
// host the generator (e.g. a visa type whose checklist omits it).
function VifCard({ vif }) {
    return (
        <div className="rounded-xl border border-[#009688]/25 bg-[#009688]/5 p-4 flex items-center gap-3 flex-wrap">
            <div className="w-9 h-9 rounded-xl bg-[#009688]/15 flex items-center justify-center flex-shrink-0">
                <FileText size={17} className="text-[#009688]" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-bold text-gray-900">Visa Information Form (Assessment)</div>
                <p className="text-[12px] text-gray-500">Official EP VIF, filled from this case's visa assessment.</p>
            </div>
            <VifButtons vif={vif} />
        </div>
    );
}

function categoryFromKey(key) {
    if (! key || typeof key !== "string") return null;
    const parts = key.split(/[.\-:]/);
    if (parts.length < 2) return null;
    return parts[0].replace(/\b\w/g, (c) => c.toUpperCase());
}

const formatDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—";

// Date + time — used on notes so staff see exactly when a note was left.
const formatDateTime = (iso) =>
    iso ? new Date(iso).toLocaleString("en-NZ", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

// Human-readable file size from a byte count.
const formatBytes = (bytes) => {
    if (!bytes && bytes !== 0) return "";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
