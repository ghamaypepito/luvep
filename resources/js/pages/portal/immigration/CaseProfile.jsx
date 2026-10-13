import { useEffect, useMemo, useState } from "react";
import { Head, usePage, router } from "@inertiajs/react";
import CaseProfileHeader from "@/components/immigration/case-profile/CaseProfileHeader";
import OverviewTab from "@/components/immigration/case-profile/tabs/OverviewTab";
import PersonalTab from "@/components/immigration/case-profile/tabs/PersonalTab";
import DocumentsTab from "@/components/immigration/case-profile/tabs/DocumentsTab";
import CommunicationsTab from "@/components/immigration/case-profile/tabs/CommunicationsTab";
import AIHealthTab from "@/components/immigration/case-profile/tabs/AIHealthTab";
import ProcessTab from "@/components/immigration/case-profile/tabs/ProcessTab";
import NotesTab from "@/components/immigration/case-profile/tabs/NotesTab";
import FinancialsTab from "@/components/immigration/case-profile/tabs/FinancialsTab";
import DependantsTab from "@/components/immigration/case-profile/tabs/DependantsTab";
import StagesTab from "@/components/immigration/case-profile/tabs/StagesTab";
import AiRecordAssistant from "@/components/ai/AiRecordAssistant";
import {
    FileText, MessageSquare, Sparkles, StickyNote, User, Workflow, DollarSign, Users, LayoutDashboard, Milestone,
    Lock, Plus, ArrowRight, Layers, Loader2,
} from "lucide-react";

// Multi-visa chain bar — shows the client's visa applications (Visa N of M),
// a switcher between them, a read-only banner on a closed application, and the
// "apply for another visa" action once this case is approved.
function VisaChainBar({ visaChain, leadId, visaTypes }) {
    const { sequence = 1, total = 1, closed = false, can_start_next = false, chain = [] } = visaChain || {};
    const [open, setOpen] = useState(false);
    const [vt, setVt] = useState("");
    const [saving, setSaving] = useState(false);

    if (total <= 1 && !can_start_next && !closed) return null;

    const start = () => {
        if (!vt) return;
        setSaving(true);
        router.post(`/portal/immigration/cases/${leadId}/next-visa`, { visa_type_id: vt }, { onFinish: () => setSaving(false) });
    };

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2 flex-wrap">
                    <Layers size={15} className="text-[#009688]" />
                    <span className="text-[11px] font-bold uppercase tracking-wider text-[#009688]">Visa {sequence} of {total}</span>
                    {closed && (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-amber-50 text-amber-700 border-amber-200">
                            <Lock size={10} /> Closed — read only
                        </span>
                    )}
                    {chain.length > 1 && (
                        <div className="flex items-center gap-1 ml-1">
                            {chain.map((c) => (
                                <button key={c.lead_id} type="button" disabled={c.is_current}
                                    onClick={() => router.visit(`/portal/immigration/cases/${c.lead_id}/profile`)}
                                    title={c.visa_type || `Visa ${c.sequence}`}
                                    className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold border transition-colors ${
                                        c.is_current ? "bg-gray-900 text-white border-gray-900 cursor-default"
                                            : "bg-white text-gray-600 border-gray-200 hover:border-[#009688] hover:text-[#009688]"}`}>
                                    Visa {c.sequence}{c.status === "closed" ? " ✓" : ""}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {can_start_next && (
                    open ? (
                        <div className="flex items-center gap-2">
                            <select value={vt} onChange={(e) => setVt(e.target.value)}
                                className="rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688] bg-white">
                                <option value="">Choose the new visa…</option>
                                {visaTypes.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                            </select>
                            <button type="button" onClick={start} disabled={saving || !vt}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#009688] text-white text-[12px] font-bold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                                {saving ? <Loader2 size={13} className="animate-spin" /> : <ArrowRight size={13} />} Start
                            </button>
                            <button type="button" onClick={() => setOpen(false)} className="text-[12px] font-semibold text-gray-500 hover:underline">Cancel</button>
                        </div>
                    ) : (
                        <button type="button" onClick={() => setOpen(true)}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b]">
                            <Plus size={14} /> Apply for another visa
                        </button>
                    )
                )}
            </div>
            {can_start_next && open && (
                <p className="mt-2 text-[11px] text-gray-400">A new case is created with this client's details and the documents the new visa also needs; this approved application is then closed.</p>
            )}
        </div>
    );
}

// Build 11.D — Case Profile page. Six-tab workspace for an immigration case.
// Distinct from admin/LeadDetails.jsx (which continues to serve sales leads).
// Visual basis: the IntakeDetails profile (gray + white CRM theme).

const TABS = [
    { key: "overview",       label: "Overview",           icon: LayoutDashboard, Comp: OverviewTab },
    { key: "personal",       label: "Personal",           icon: User,          Comp: PersonalTab },
    { key: "dependants",     label: "Family",             icon: Users,         Comp: DependantsTab },
    { key: "documents",      label: "Documents",          icon: FileText,      Comp: DocumentsTab },
    { key: "stages",         label: "Stages",             icon: Milestone,     Comp: StagesTab },
    { key: "financials",     label: "Financials",         icon: DollarSign,    Comp: FinancialsTab },
    { key: "communications", label: "Communications",     icon: MessageSquare, Comp: CommunicationsTab },
    { key: "ai_health",      label: "AI Health",       icon: Sparkles,        Comp: AIHealthTab },
    { key: "notes",          label: "Notes & Activity", icon: StickyNote,     Comp: NotesTab },
];

const VALID_TABS = new Set(TABS.map((t) => t.key));

export default function CaseProfile() {
    const { props } = usePage();
    const {
        lead = {}, intake = null, documents = [], documentRequests = [], checklist = { items: [] },
        checklistGrouped = {}, unstructuredDocuments = [],
        checklistProgress = { required_total: 0, required_approved: 0, total: 0, approved: 0 },
        communications = [], clientReplies = [], agreements = [], notes = [], activity = [],
        findings = { items: [], evaluated_at: null, couldnt_verify: [] },
        process = { started: false, steps: [], payment: null, partner: null },
        threads = [], caseStaff = [], attention = null,
        financials = { record: null, payments: [], totals: {}, referred_by: null },
        inzForms = [], dependents = [], vif = null, caseOptions = [], tiedTo = null, visaTypes = [],
        assessmentCompleteness = null, engagement = {}, tasks = { items: [] },
        visaProgress = { tracked: false, activities: [] }, stageTimeline = [],
        visaChain = { sequence: 1, total: 1, closed: false, can_start_next: false, chain: [] },
    } = props;

    // Deep-link tab via ?tab=…  — preserved from the legacy
    // /portal/immigration/leads/{id}?tab=documents convert-redirect URL.
    const initialTab = useMemo(() => {
        if (typeof window === "undefined") return "overview";
        const t = new URLSearchParams(window.location.search).get("tab");
        return t && VALID_TABS.has(t) ? t : "overview";
    }, []);
    const [activeTab, setActiveTab] = useState(initialTab);

    // Navigating between two case profiles reuses this same page component
    // (Inertia doesn't remount it), so reset the tab whenever the case changes
    // — a fresh case opens on Overview unless the URL asks for a specific tab.
    useEffect(() => {
        const t = typeof window !== "undefined"
            ? new URLSearchParams(window.location.search).get("tab")
            : null;
        setActiveTab(t && VALID_TABS.has(t) ? t : "overview");
    }, [lead.id]);

    useEffect(() => {
        if (typeof window === "undefined") return;
        const url = new URL(window.location.href);
        if (url.searchParams.get("tab") === activeTab) return;
        url.searchParams.set("tab", activeTab);
        window.history.replaceState({}, "", url);
    }, [activeTab]);

    const tabProps = {
        lead, intake, documents, documentRequests, checklist, checklistGrouped, unstructuredDocuments, checklistProgress,
        communications, clientReplies, agreements, notes, activity, findings, process, threads, caseStaff, financials, inzForms, dependents, vif, caseOptions, visaTypes,
        assessmentCompleteness, engagement, attention, tasks,
        visaProgress, stageTimeline,
        onNavigate: setActiveTab,
    };
    const ActiveTab = TABS.find((t) => t.key === activeTab)?.Comp ?? PersonalTab;

    const fullName = `${lead.first_name ?? ""} ${lead.last_name ?? ""}`.trim() || lead.lead_id || "Case";

    return (
        <div className="max-w-[1300px] mx-auto pb-12 space-y-5">
            <Head title={`${fullName} — Case profile`} />

            <CaseProfileHeader
                lead={lead} intake={intake} attention={attention} tiedTo={tiedTo} engagement={engagement}
                visaTypes={visaTypes} financials={financials} tasks={tasks} dependents={dependents}
                checklist={checklist} checklistProgress={checklistProgress}
                onNavigate={setActiveTab}
            />

            <VisaChainBar visaChain={visaChain} leadId={lead.id} visaTypes={visaTypes} />

            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
                <nav className="flex items-stretch min-w-max">
                    {TABS.map(({ key, label, icon: Icon }) => {
                        const active = key === activeTab;
                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setActiveTab(key)}
                                className={`inline-flex items-center gap-2 px-5 py-3.5 text-sm font-semibold border-b-2 transition-colors ${
                                    active
                                        ? "border-gray-900 text-gray-900"
                                        : "border-transparent text-gray-500 hover:text-gray-900 hover:bg-gray-50"
                                }`}
                            >
                                <Icon size={15} className={active ? "text-gray-900" : "text-gray-400"} />
                                {label}
                            </button>
                        );
                    })}
                </nav>
            </div>

            {/* Overview is its own dashboard of cards on the page background;
                every other tab keeps the single white-card frame. */}
            {activeTab === "overview" ? (
                <ActiveTab {...tabProps} />
            ) : (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <ActiveTab {...tabProps} />
                </div>
            )}

            <AiRecordAssistant subjectId={lead.id} label={`${fullName} · immigration case`} immigration />
        </div>
    );
}
