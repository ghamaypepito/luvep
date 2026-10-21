import React, { useEffect, useMemo, useState, useRef } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Search, CheckCircle2, AlertCircle, ArrowRight,
    Save, X, Upload, FileText, ChevronDown,
    Eye, FileType, Plus, Trash2, Edit3, Edit,
    Check, Clock, MessageSquare, ShieldCheck,
    User as UserIcon, Folder as FolderIcon,
    Sparkles, GraduationCap, MapPin, Award,
    Mail, Calendar as CalendarIcon, Download, PenTool, Eraser, Camera,
} from 'lucide-react';
import Navbar from '@/components/layout/Navbar';
import Footer from '@/components/layout/Footer';
import ProgramDetailsModal from '@/components/ui/ProgramDetailsModal';

// Human-readable file size from a byte count.
const fmtBytes = (bytes) => {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

/**
 * Public application tracking page. Mono dark-gray / white theme.
 *
 * Reading order is deliberately top-down by importance:
 *   1. Hero (centered) — title + tracking code search
 *   2. Summary bar — name + current stage
 *   3. Journey — full-width vertical timeline (priority)
 *   4. Information + Documents — 2-column row underneath
 */
// Document endpoints switch to the authenticated lead-portal routes when the
// tracker is embedded in the lead portal — the public /track/{code} endpoints
// are disabled for immigration cases (privacy). Shared via context so the deep
// row/modal components don't each need the base threaded through.
const DocApiContext = React.createContext(null);
function useDocApi() {
    return React.useContext(DocApiContext) || {
        view: (d) => d.url || `/admin/documents/${d.id}/download?inline=1`,
        uploadUrl: () => '#',
        itemUrl: (id) => `#${id}`,
        replaceUrl: (id) => `#${id}`,
    };
}

export default function TrackingPage({
    code = null,
    lead = null,
    avatar = null,
    info = null,
    documents = [],
    shared_documents = [],
    agreements = [],
    timeline = [],
    visa = null,
    proposal = null,
    error = null,
    // When true the page is rendered INSIDE the authenticated lead portal
    // (LeadLayout already provides chrome), so we drop our own Navbar/Footer,
    // the full-height wrapper and the code-lookup hero. The lead is always
    // resolved in this mode, so those pieces would never render anyway.
    embedded = false,
    // Lead-portal "sidebar mode": the three tabs live in the LeadLayout
    // sidebar (Dashboard / Requirements / My Profile) instead of an in-page
    // tab strip. The active tab is driven by the route (`initialTab`), the
    // in-page strip is hidden, and tab jumps navigate via `onTabNavigate`.
    sidebarTabs = false,
    initialTab = 'overview',
    onTabNavigate = null,
}) {
    const [input, setInput] = useState(code || '');
    const flash = usePage().props.flash || {};

    // Route document operations to the portal (private, owner-scoped) when
    // embedded, or to the public tracker endpoints otherwise.
    const docApi = useMemo(() => (embedded ? {
        embedded: true,
        view: (d) => `/portal/lead/documents/${d.id}/download?inline=1`,
        uploadUrl: (key) => `/portal/lead/documents/checklist/${key}/upload`,
        itemUrl: (id) => `/portal/lead/documents/${id}`,
        replaceUrl: (id) => `/portal/lead/documents/${id}/replace`,
    } : {
        embedded: false,
        view: (d) => d.url || `/admin/documents/${d.id}/download?inline=1`,
        uploadUrl: () => `/track/${code}/document`,
        itemUrl: (id) => `/track/${code}/document/${id}`,
        replaceUrl: (id) => `/track/${code}/document/${id}`,
    }), [embedded, code]);

    // URL-synced tab + filter state. Reads initial values from
    // ?tab=, ?section=, ?status= on first render and writes back via
    // history.replaceState as the user clicks around. Direct links to a
    // specific tab/filter combination work without an extra round-trip.
    const [urlTab, setUrlTab] = useUrlParam('tab', initialTab);
    // In sidebar mode the tab is controlled by the route prop (so navigating
    // between the sidebar pages switches it without a remount); otherwise it's
    // the client-side URL-synced state.
    const activeTab = sidebarTabs ? initialTab : urlTab;
    const setActiveTab = sidebarTabs ? (tab) => onTabNavigate?.(tab) : setUrlTab;
    const [sectionFilter, setSectionFilter] = useUrlParam('section', 'all');
    const [statusFilter, setStatusFilter] = useUrlParam('status', 'all');

    // Modal state lifted to the page root so both the Visa-tab rows AND
    // the Documents tab "+ Add" can trigger the same upload modal. The
    // pre-selected docType lets a row's "Upload" button jump straight to
    // the file picker, skipping the picker step.
    const [uploadOpen, setUploadOpen] = useState(false);
    const [uploadPreselect, setUploadPreselect] = useState(null);
    const [editingDoc, setEditingDoc] = useState(null);
    const [deletingDoc, setDeletingDoc] = useState(null);
    const [journeyOpen, setJourneyOpen] = useState(false);

    const openUpload = (item = null) => {
        setUploadPreselect(item ? { key: item.key, label: item.label, hint: item.hint } : null);
        setUploadOpen(true);
    };
    const closeUpload = () => {
        setUploadOpen(false);
        setUploadPreselect(null);
    };

    const submitLookup = (e) => {
        e.preventDefault();
        const trimmed = input.trim();
        if (!trimmed) return;
        router.get(`/track/${encodeURIComponent(trimmed)}`);
    };

    return (
        <DocApiContext.Provider value={docApi}>
        <div className={embedded ? 'font-sans' : 'min-h-screen bg-white font-urbanist flex flex-col'}>
            <Head title={embedded ? 'Application Tracker — EP' : 'Track Your Application — EP'} />
            {!embedded && <Navbar />}

            <main className={embedded ? '' : 'flex-1 bg-gray-50'}>
                {/* Hero — landing state before a code resolves. Clean white
                    card on a soft gray backdrop; matches the staff portal
                    dashboard look. */}
                {!lead && (
                    <section className="py-16 sm:py-20">
                        <div className="container mx-auto px-6 md:px-12 max-w-2xl">
                            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-8 sm:p-10 text-center">
                                <div className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 text-[10px] font-bold uppercase tracking-[0.22em] mb-4">
                                    <Sparkles size={11} strokeWidth={2.5} />
                                    Application Tracker
                                </div>
                                <h1 className="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight mb-2">
                                    Your journey, on one page.
                                </h1>
                                <p className="text-sm text-gray-500 max-w-md mx-auto leading-relaxed">
                                    Enter the tracking code we sent you to see your details, documents, and progress.
                                </p>
                                <form
                                    onSubmit={submitLookup}
                                    className="mt-8 flex flex-col sm:flex-row gap-2 max-w-lg mx-auto"
                                >
                                    <div className="flex-1 flex items-center gap-2.5 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus-within:border-gray-900 focus-within:bg-white transition-all">
                                        <Search size={15} className="text-gray-400 flex-shrink-0" />
                                        <input
                                            type="text"
                                            placeholder="EP-AB23CDEF"
                                            value={input}
                                            onChange={(e) => setInput(e.target.value)}
                                            className="flex-1 bg-transparent text-gray-900 placeholder-gray-400 focus:outline-none tracking-[0.18em] uppercase text-sm font-mono"
                                            autoFocus={!lead}
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        className="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gray-900 text-white rounded-xl text-sm font-bold hover:bg-black transition-colors shadow-sm"
                                    >
                                        Track <ArrowRight size={14} strokeWidth={2.5} />
                                    </button>
                                </form>
                            </div>
                        </div>
                    </section>
                )}

                <section className="container mx-auto px-6 md:px-12 max-w-7xl py-8">
                    {flash.success && (
                        <div className="mb-4 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 text-sm text-emerald-800 flex items-center gap-2.5">
                            <CheckCircle2 size={16} className="text-emerald-600 flex-shrink-0" />
                            {flash.success}
                        </div>
                    )}

                    {error && (
                        <div className="bg-red-50 border border-red-200 rounded-xl px-5 py-4 flex items-start gap-3 max-w-2xl mx-auto shadow-sm">
                            <AlertCircle size={18} className="text-red-500 flex-shrink-0 mt-0.5" />
                            <div>
                                <p className="text-sm font-semibold text-red-700 mb-0.5">Code not found</p>
                                <p className="text-xs text-red-600 leading-relaxed">{error}</p>
                            </div>
                        </div>
                    )}

                    {lead && (
                        <div className="space-y-4">
                            {/* Identity banner shows on the public tracker and on
                                the lead portal's My Profile — but NOT on the
                                Dashboard / Requirements sidebar pages, where it
                                would just repeat on every view. */}
                            {(!sidebarTabs || activeTab === 'profile') && (
                                <IdentityHeader
                                    lead={lead}
                                    visa={visa}
                                    timeline={timeline}
                                    avatar={avatar}
                                    code={lead.tracking_code}
                                    faceKey={
                                        (visa?.checklist || []).find((it) => (it.key || '').toLowerCase().includes('face'))?.key
                                        || avatar?.checklist_key
                                        || 'face_image'
                                    }
                                />
                            )}

                            {/* Programs suggested by the adviser. On the public
                                tracker it sits above the tabs; in the lead-portal
                                dashboard it's rendered inside ClientDashboard, so
                                only show it here when NOT in sidebar mode. */}
                            {!sidebarTabs && proposal && proposal.programs?.length > 0 && (
                                <ProposalShortlist proposal={proposal} code={lead.tracking_code} />
                            )}

                            {/* Tab strip + tab content. Counts on the Visa
                                and Documents tabs nudge the lead toward
                                the one with action items. */}
                            <div>
                                {/* In sidebar mode the tabs live in the portal
                                    sidebar, so the in-page strip is hidden. */}
                                {!sidebarTabs && (
                                    <TabStrip
                                        active={activeTab}
                                        onChange={setActiveTab}
                                        counts={{
                                            attention: attentionCount({ documents, sharedDocuments: shared_documents, visa }),
                                            visa: (visa?.checklist?.length || 0),
                                        }}
                                    />
                                )}

                                {activeTab === 'overview' && (
                                    sidebarTabs ? (
                                        <ClientDashboard
                                            lead={lead}
                                            visa={visa}
                                            documents={documents}
                                            sharedDocuments={shared_documents}
                                            agreements={agreements}
                                            timeline={timeline}
                                            proposal={proposal}
                                            onSeeFull={() => setJourneyOpen(true)}
                                            onGoToVisa={() => setActiveTab('visa')}
                                        />
                                    ) : (
                                        <OverviewTab
                                            lead={lead}
                                            visa={visa}
                                            documents={documents}
                                            sharedDocuments={shared_documents}
                                            agreements={agreements}
                                            timeline={timeline}
                                            onSeeFull={() => setJourneyOpen(true)}
                                            onGoToVisa={() => setActiveTab('visa')}
                                        />
                                    )
                                )}
                                {activeTab === 'visa' && (
                                    <DocumentsHubTab
                                        visa={visa}
                                        documents={documents}
                                        sharedDocuments={shared_documents}
                                        agreements={agreements}
                                        showAdviserDocs={true}
                                        sectionFilter={sectionFilter}
                                        onSectionFilter={setSectionFilter}
                                        statusFilter={statusFilter}
                                        onStatusFilter={setStatusFilter}
                                        onOpenUpload={openUpload}
                                        onOpenReplace={setEditingDoc}
                                        onDelete={setDeletingDoc}
                                    />
                                )}
                                {activeTab === 'profile' && (
                                    <div className={`bg-white border border-gray-100 shadow-sm p-4 sm:p-6 ${sidebarTabs ? 'rounded-2xl' : 'rounded-b-2xl border-t-0'}`}>
                                        <InformationPanel code={lead.tracking_code} info={info} />
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {!lead && !error && (
                        <div className="text-center py-16">
                            <p className="text-[11px] font-bold tracking-[0.3em] uppercase text-gray-400 mb-3">
                                Where to find your code
                            </p>
                            <p className="text-sm text-gray-600 max-w-md mx-auto leading-relaxed">
                                Your tracking code starts with{' '}
                                <span className="font-mono font-bold text-[#282728]">EP-</span>{' '}
                                followed by 8 letters and numbers. It was sent to you when you submitted
                                your assessment or enquiry.
                            </p>
                        </div>
                    )}
                </section>
            </main>

            {/* Modals — single instance each, lifted to page root so
                both the Visa-tab rows and the Documents tab share them. */}
            <JourneyModal
                open={journeyOpen}
                onClose={() => setJourneyOpen(false)}
                timeline={timeline}
                currentStage={lead?.stage}
            />
            {lead && (
                <DocUploadModal
                    open={uploadOpen}
                    onClose={closeUpload}
                    code={lead.tracking_code}
                    lead={lead}
                    visa={visa}
                    documents={documents}
                    initialDocType={uploadPreselect}
                />
            )}
            {lead && (
                <DocEditModal
                    code={lead.tracking_code}
                    doc={editingDoc}
                    onClose={() => setEditingDoc(null)}
                />
            )}
            {lead && (
                <DocDeleteModal
                    code={lead.tracking_code}
                    doc={deletingDoc}
                    onClose={() => setDeletingDoc(null)}
                />
            )}

            {!embedded && <Footer />}
        </div>
        </DocApiContext.Provider>
    );
}

// ─── Panels ──────────────────────────────────────────────────────────────

function Chip({ children }) {
    return (
        <span className="px-2.5 py-1 bg-gray-50 text-[#282728] text-[9px] font-bold uppercase tracking-[0.2em] border border-gray-200">
            {children}
        </span>
    );
}

function ChipDark({ children }) {
    return (
        <span className="px-2.5 py-1 bg-[#282728] text-white text-[9px] font-bold uppercase tracking-[0.2em] border border-[#282728]">
            {children}
        </span>
    );
}

function PanelShell({ title, subtitle, action, children }) {
    return (
        // Quiet card lift — single hairline border + a barely-there shadow
        // so each card reads as deliberate rather than an outline-only box.
        <div className="bg-white border border-gray-200/80 shadow-[0_1px_3px_rgba(0,0,0,0.03)] p-7">
            <div className="flex items-start justify-between gap-4 mb-6 pb-5 border-b border-gray-100">
                <div>
                    <h3 className="text-lg font-semibold text-[#282728] tracking-tight">
                        {title}
                    </h3>
                    {subtitle && (
                        <p className="text-[13px] text-gray-500 mt-1.5 leading-relaxed">{subtitle}</p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </div>
    );
}

function InformationPanel({ code, info }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ ...info });

    if (!info) return null;

    const save = (e) => {
        e.preventDefault();
        post(`/track/${code}/info`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const cancel = () => {
        reset();
        setEditing(false);
    };

    const editAction = !editing ? (
        <button
            type="button"
            onClick={() => setEditing(true)}
            className="text-[10px] font-bold uppercase tracking-[0.22em] text-[#282728] flex items-center gap-1.5 border border-[#282728] px-2.5 py-1.5 hover:bg-[#282728] hover:text-white transition-colors"
        >
            <Edit size={11} /> Edit
        </button>
    ) : (
        <div className="flex items-center gap-2">
            <button
                type="button"
                onClick={cancel}
                disabled={processing}
                className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 flex items-center gap-1.5 border border-gray-300 px-2.5 py-1.5 hover:bg-gray-50"
            >
                <X size={11} /> Cancel
            </button>
            <button
                type="button"
                onClick={save}
                disabled={processing}
                className="text-[10px] font-bold uppercase tracking-[0.22em] text-white bg-[#282728] flex items-center gap-1.5 px-2.5 py-1.5 hover:bg-black disabled:opacity-50"
            >
                <Save size={11} /> {processing ? 'Saving…' : 'Save'}
            </button>
        </div>
    );

    return (
        <PanelShell
            title="Your Information"
            subtitle="Edit anything that's out of date — we'll sync it across your file."
            action={editAction}
        >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                <InfoField label="First name"        name="first_name"        editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Middle name"       name="middle_name"       editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Last name"         name="last_name"         editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Other names"       name="other_names"       editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Gender"            name="gender"            editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Marital status"    name="marital_status"    editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Date of birth"     name="dob"               editing={editing} data={data} setData={setData} errors={errors} type="date" />
                <InfoField label="Email"             name="email"             editing={editing} data={data} setData={setData} errors={errors} type="email" />
                <InfoField label="Phone"             name="phone"             editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Country of birth"  name="country_of_birth"  editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Place of birth"    name="place_of_birth"    editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Citizenship"       name="citizenship"       editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Residence city"    name="residence_city"    editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Residence state"   name="residence_state"   editing={editing} data={data} setData={setData} errors={errors} />
                <InfoField label="Residence country" name="residence_country" editing={editing} data={data} setData={setData} errors={errors} />
                {/* Passport number + expiry are captured in the Documents
                    section when the lead uploads their passport — the
                    fields auto-save there. Keeping them off the personal
                    information form avoids two places to type the same
                    thing. */}
            </div>
        </PanelShell>
    );
}

function InfoField({ label, name, editing, data, setData, errors, type = 'text' }) {
    const value = data[name];
    const err = errors[name];

    // Date values render in the underlying browser/locale format when
    // they're shown as uppercase plain text, which would just emit the
    // exact same digits — skip the uppercase flip on date types so the
    // display still reads naturally (e.g. "2026-06-09" stays as-is).
    const upperValue = type !== 'date';

    return (
        <div className="space-y-1.5">
            <label className={`block text-[13px] font-semibold transition-colors ${err ? 'text-red-500' : 'text-gray-700'}`}>
                {label}
            </label>
            {editing ? (
                <input
                    type={type}
                    value={value || ''}
                    onChange={(e) => setData(name, e.target.value)}
                    className={`w-full bg-transparent border-b py-2 text-[15px] text-[#282728] focus:outline-none focus:border-[#282728] transition-colors ${
                        err ? 'border-red-400' : 'border-gray-200'
                    }`}
                />
            ) : (
                <p className={`text-[15px] text-[#282728] py-2 border-b border-gray-100 tracking-wide ${
                    upperValue ? 'uppercase' : ''
                }`}>
                    {value || <span className="text-gray-300 normal-case">—</span>}
                </p>
            )}
            {err && <p className="text-[11px] font-semibold text-red-500 mt-0.5">{err}</p>}
        </div>
    );
}

// Document-type catalogue. Drives the picker AND lights up the
// passport-metadata fields when checklist_key === 'passport'.
const DOC_TYPES = [
    { key: 'passport',   label: 'Passport',          hint: 'Bio-data page — we\'ll save the number + expiry to your file.' },
    { key: 'diploma',    label: 'Diploma',           hint: 'Highest qualification certificate.' },
    { key: 'transcript', label: 'Transcript of Records', hint: 'Academic record / TOR.' },
    { key: 'cv',         label: 'CV / Resume',       hint: 'Latest CV in PDF or DOC.' },
    { key: 'coe',        label: 'Certificate of Employment', hint: 'Most recent employer-issued certificate.' },
    { key: 'other',      label: 'Other supporting document', hint: 'Anything else we\'ve asked for.' },
];

const TABS = ['overview', 'visa', 'profile'];

/**
 * Read a query-string param from the current URL with a fallback. Wrapped
 * so SSR/initial-render reads don't crash if `window` is missing.
 */
function readUrlParam(name, fallback) {
    if (typeof window === 'undefined') return fallback;
    const v = new URLSearchParams(window.location.search).get(name);
    return v == null || v === '' ? fallback : v;
}

/**
 * URL-synced state. Initial value comes from the query string; updates
 * push back via history.replaceState so the URL is shareable but we don't
 * pile up history entries. No Inertia round-trip — the page already has
 * the full data and we just slice/filter it client-side.
 */
function useUrlParam(name, fallback) {
    const [value, setValue] = useState(() => readUrlParam(name, fallback));
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const url = new URL(window.location.href);
        if (value === fallback || value == null || value === '') {
            url.searchParams.delete(name);
        } else {
            url.searchParams.set(name, value);
        }
        const next = url.pathname + (url.search ? url.search : '') + url.hash;
        if (next !== window.location.pathname + window.location.search + window.location.hash) {
            window.history.replaceState({}, '', next);
        }
    }, [name, value, fallback]);
    return [value, setValue];
}

/**
 * Identity header — two-column block at the very top of the tracked
 * application page. Left = application name + visa + tracking code.
 * Right = current journey status badge + "Step X of N".
 */
function IdentityHeader({ lead, visa, timeline = [], avatar = null, code = '', faceKey = 'face_image' }) {
    const fullName = [lead?.first_name, lead?.last_name].filter(Boolean).join(' ') || 'Application';
    // Which department is handling the case — surfaced beside the visa type.
    const department = lead?.is_immigration_case ? 'Immigration'
        : lead?.is_student ? 'Education'
            : lead?.is_accommodation_client ? 'Accommodation'
                : 'Sales';
    const [photoMenu, setPhotoMenu] = useState(false);
    const [photoBusy, setPhotoBusy] = useState(false);
    const photoInputRef = useRef(null);

    const uploadPhoto = (e) => {
        const file = e.target.files?.[0];
        if (! file) return;
        setPhotoBusy(true);
        router.post(`/track/${code}/document`, { file, checklist_key: faceKey }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => { setPhotoBusy(false); setPhotoMenu(false); if (photoInputRef.current) photoInputRef.current.value = ''; },
        });
    };
    const removePhoto = () => {
        if (! avatar?.document_id) return;
        setPhotoBusy(true);
        router.delete(`/track/${code}/document/${avatar.document_id}`, {
            preserveScroll: true,
            onFinish: () => { setPhotoBusy(false); setPhotoMenu(false); },
        });
    };
    const visaName = visa?.name || null;
    const trackingCode = lead?.tracking_code || '';

    const currentIdx = timeline.findIndex((s) => s.status === 'current');
    const declinedStep = timeline.find((s) => s.alternative);
    const allCompleted = timeline.length > 0 && timeline.every((s) => s.status === 'completed');

    let statusLabel = 'In progress';
    let stepLabel = null;
    let badgeTone = 'bg-blue-100 text-blue-700 border-blue-200';
    if (declinedStep) {
        statusLabel = 'Application declined';
        badgeTone = 'bg-red-100 text-red-700 border-red-200';
    } else if (allCompleted) {
        statusLabel = 'Visa issued';
        badgeTone = 'bg-emerald-100 text-emerald-700 border-emerald-200';
    } else if (currentIdx >= 0) {
        statusLabel = timeline[currentIdx].label;
        stepLabel = `Step ${currentIdx + 1} of ${timeline.length}`;
    }

    // Initials for the avatar chip on the left.
    const initials = (fullName || '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((s) => s.charAt(0).toUpperCase())
        .join('');

    // HR-style profile layout, dark-gray palette. Banner sits above a
    // circular avatar that overlaps onto white; name + status pill + kebab
    // on one row; four pill fields below (Visa / Application / Email /
    // Tracking Code). No greens on the header chrome — status pill keeps
    // its state tone so the applicant can read progress at a glance.
    const isComplete = allCompleted && ! declinedStep;
    const hasEmail = !! lead?.email;
    const hasPhone = !! lead?.phone;

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            {/* BANNER — deep gray gradient with faint decorative arcs.
                Fixed 128px on mobile / 144px desktop so the avatar has
                room to overlap by half its height without clipping. */}
            <div
                className="h-32 sm:h-36 relative overflow-hidden"
                style={{
                    background:
                        'linear-gradient(120deg, #1f2937 0%, #111827 55%, #0c1611 100%)',
                }}
            >
                <svg
                    aria-hidden
                    className="absolute right-6 -top-12 opacity-15"
                    width="280" height="220" viewBox="0 0 280 220" fill="none"
                >
                    <circle cx="160" cy="130" r="100" stroke="#ffffff" strokeWidth="1.5" strokeOpacity="0.6" />
                    <circle cx="220" cy="60"  r="70"  stroke="#ffffff" strokeWidth="1"   strokeOpacity="0.5" />
                    <circle cx="90"  cy="170" r="50"  stroke="#ffffff" strokeWidth="1"   strokeOpacity="0.4" />
                </svg>
                <div
                    aria-hidden
                    className="absolute inset-0 opacity-[0.08] mix-blend-overlay pointer-events-none"
                    style={{
                        backgroundImage:
                            "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>\")",
                    }}
                />
            </div>

            {/* IDENTITY — avatar overlaps the banner on its own line; the
                name + status sit cleanly below it in the white area. */}
            <div className="px-4 sm:px-6 pb-5">
                {/* Avatar overlaps the banner by ~half its height. Shows the
                    client's uploaded Face image when present; the camera
                    button lets them add / change / remove it. */}
                <div className="-mt-12 mb-3 relative inline-block">
                    <div className="w-24 h-24 rounded-full overflow-hidden ring-4 ring-white shadow-lg bg-gray-100 text-gray-400 flex items-center justify-center font-black text-2xl">
                        {avatar?.url
                            ? <img src={avatar.url} alt={fullName} className="w-full h-full object-cover" />
                            : (initials || <UserIcon size={32} />)}
                    </div>
                    <button
                        type="button"
                        onClick={() => setPhotoMenu((v) => ! v)}
                        disabled={photoBusy}
                        title="Change profile photo"
                        className="absolute bottom-0 right-0 w-8 h-8 rounded-full bg-[#282728] text-white flex items-center justify-center ring-2 ring-white hover:bg-black transition-colors disabled:opacity-50"
                    >
                        <Camera size={14} />
                    </button>

                    {photoMenu && (
                        <div className="absolute z-20 left-0 top-[104px] w-48 bg-white border border-gray-200 shadow-lg rounded-lg py-1" onMouseLeave={() => setPhotoMenu(false)}>
                            <button
                                type="button"
                                onClick={() => photoInputRef.current?.click()}
                                className="w-full text-left px-3 py-2 text-[13px] text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                            >
                                <Upload size={13} /> {avatar?.url ? 'Change photo' : 'Add photo'}
                            </button>
                            {avatar?.url && (
                                <button
                                    type="button"
                                    onClick={removePhoto}
                                    className="w-full text-left px-3 py-2 text-[13px] text-rose-600 hover:bg-rose-50 flex items-center gap-2"
                                >
                                    <Trash2 size={13} /> Remove photo
                                </button>
                            )}
                        </div>
                    )}
                    <input
                        ref={photoInputRef}
                        type="file"
                        accept="image/jpeg,image/jpg"
                        className="hidden"
                        onChange={uploadPhoto}
                    />
                </div>

                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 min-w-0">
                            <h1 className="text-xl sm:text-2xl font-bold text-[#282728] tracking-tight leading-tight truncate">
                                {fullName}
                            </h1>
                            {isComplete && (
                                <span
                                    title="Visa issued"
                                    className="inline-flex items-center justify-center w-5 h-5 rounded-full bg-[#282728] text-white shrink-0"
                                >
                                    <Check size={12} strokeWidth={3} />
                                </span>
                            )}
                        </div>
                        <p className="text-[13px] text-gray-500 mt-0.5 truncate">
                            {trackingCode && <span className="font-mono">{trackingCode}</span>}
                            {trackingCode && visaName && <span className="mx-1.5 text-gray-300">·</span>}
                            {visaName || 'Application'}
                        </p>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                        {visaName && (
                            <span className="inline-flex items-center px-3 py-1.5 rounded-md text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                {visaName}
                            </span>
                        )}
                        <span className="text-[11px] font-medium text-gray-500">
                            {department}
                        </span>
                    </div>
                </div>


                {/* PILL FIELDS — Visa / Application / Email / Tracking Code.
                    Email cell hides when the lead has no address on file so
                    we're not surfacing a dangling em-dash. */}
                <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <PillField
                        icon={<Award size={12} />}
                        label="Type of Visa"
                        value={visaName || '—'}
                    />
                    <PillField
                        icon={<Mail size={12} />}
                        label="Contact Email"
                        value={hasEmail ? lead.email : '—'}
                        truncate
                    />
                    <PillField
                        icon={<UserIcon size={12} />}
                        label="Contact Number"
                        value={hasPhone ? lead.phone : '—'}
                    />
                    <PillField
                        icon={<CalendarIcon size={12} />}
                        label="Tracking Code"
                        value={trackingCode || '—'}
                        mono
                    />
                </div>
            </div>
        </div>
    );
}

// Reference-style pill field: icon + tiny uppercase label + value.
// Dark-gray theme throughout — no dots, no accents. The reference's
// coloured dots were noisy and don't earn their weight on 4 fields.
function PillField({ icon, label, value, truncate = false, mono = false }) {
    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50/60 px-4 py-3">
            <p className="text-[11.5px] font-semibold text-gray-500 mb-1 flex items-center gap-1.5">
                <span className="text-gray-400">{icon}</span>
                {label}
            </p>
            <p
                className={`text-[13.5px] font-semibold text-[#282728] ${truncate ? 'truncate' : ''} ${mono ? 'font-mono' : ''}`}
                title={truncate ? value : undefined}
            >
                {value}
            </p>
        </div>
    );
}

/**
 * Journey snapshot — horizontal 3-step stepper showing where the lead is
 * in the application journey. Each visible node shows a state circle, the
 * "Step N" label, the step name, a status word, and an optional
 * description (immigration journey only — general timeline has no
 * per-step description).
 *
 * Selection rule — always shows exactly 3 consecutive timeline entries:
 *   - If a step is "current", centre the window on it: (currentIdx-1,
 *     currentIdx, currentIdx+1), clamped to the timeline ends.
 *   - If no current step (terminal Issued), show the last three completed
 *     entries.
 *   - Declined: the alternative "application_declined" entry replaces the
 *     stepper with a red outcome card.
 *
 * Edge cases preserved:
 *   - First step (no completed): window starts at index 0.
 *   - Last step (no next): window ends at last index.
 */
function JourneySnapshot({ timeline = [], onSeeFull }) {
    const declined = timeline.find((s) => s.alternative);
    const allCompleted = timeline.length > 0
        && !declined
        && timeline.filter((s) => !s.alternative).every((s) => s.status === 'completed');

    // Pick three consecutive entries to display, centred on `current` when
    // possible. Clamp so we never slice past the timeline ends.
    const visibleSteps = useMemo(() => {
        const main = timeline.filter((s) => !s.alternative);
        if (main.length === 0) return [];
        const currentIdx = main.findIndex((s) => s.status === 'current');
        const anchor = currentIdx >= 0 ? currentIdx : main.length - 1;
        const start = Math.max(0, Math.min(anchor - 1, main.length - 3));
        const end = Math.min(main.length, start + 3);
        return main.slice(start, end).map((step, i) => ({
            ...step,
            globalIndex: start + i + 1, // 1-based "Step N" label
        }));
    }, [timeline]);

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm px-6 py-5">
            <div className="flex items-center justify-between mb-5">
                <div className="flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-lg bg-gray-100 text-gray-800 flex items-center justify-center">
                        <ArrowRight size={14} />
                    </div>
                    <h2 className="text-sm font-bold text-gray-900 tracking-tight">Your journey</h2>
                </div>
                <button
                    type="button"
                    onClick={onSeeFull}
                    className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-[11px] font-semibold text-gray-700 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                >
                    See full journey <ArrowRight size={12} />
                </button>
            </div>

            {declined ? (
                <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <p className="text-[10px] font-bold tracking-[0.2em] uppercase text-red-600 mb-1">
                        Outcome
                    </p>
                    <p className="text-sm font-semibold text-red-900">{declined.label}</p>
                    {declined.description && (
                        <p className="text-[12px] text-red-700 mt-1">{declined.description}</p>
                    )}
                </div>
            ) : allCompleted ? (
                <div className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                    <p className="text-[10px] font-bold tracking-[0.2em] uppercase text-gray-700 mb-1">
                        Complete
                    </p>
                    <p className="text-sm font-semibold text-gray-900">All journey steps complete.</p>
                </div>
            ) : visibleSteps.length > 0 ? (
                <HorizontalStepper steps={visibleSteps} />
            ) : null}
        </div>
    );
}

/**
 * Horizontal 3-step tracker. A single connecting line sits behind the
 * circles, spanning from the centre of the first column to the centre of
 * the third (left-[16.67%] right-[16.67%]). The portion before/under the
 * current step's circle is rendered in `bg-[#282728]` (filled) and the
 * portion after is `bg-gray-200` (pending), giving the classic
 * "progressbar" feel without computing pixel widths.
 */
function HorizontalStepper({ steps }) {
    // Find the column index (0/1/2) of the current step within the visible
    // window. Used to colour the connecting line up to that point.
    const currentVisibleIdx = steps.findIndex((s) => s.status === 'current');
    // If no current is visible (all completed in window), treat the whole
    // line as completed.
    const filledCols = currentVisibleIdx >= 0 ? currentVisibleIdx : steps.length - 1;

    return (
        <div className="relative pt-1">
            {/* Connecting line — runs through the circle centres. Vertical
                position 20px = half of the 40px circle height. */}
            <div
                aria-hidden
                className="absolute top-[20px] left-[16.667%] right-[16.667%] h-[2px] bg-gray-200"
            />
            {/* Filled portion of the line, from start through the current
                step's column centre. 0 → 1/2 (50%) → 1 (100%) for 3 cols. */}
            {filledCols > 0 && (
                <div
                    aria-hidden
                    className="absolute top-[20px] left-[16.667%] h-[2px] bg-gray-900"
                    style={{ width: `${filledCols * (100 / 3)}%` }}
                />
            )}
            <div className="grid grid-cols-3 gap-2 relative">
                {steps.map((step) => (
                    <StepNode key={step.key} step={step} />
                ))}
            </div>
        </div>
    );
}

function StepNode({ step }) {
    const { status, globalIndex, label, description } = step;
    const statusWord = {
        completed: 'Completed',
        current: 'In Progress',
        pending: 'Pending',
    }[status] || 'Pending';
    return (
        <div className="flex flex-col items-center text-center px-2">
            <StepCircle status={status} />
            <p className="mt-3 text-[10px] font-semibold uppercase tracking-[0.22em] text-gray-400">
                Step {globalIndex}
            </p>
            <p className={`mt-1 text-[14px] font-semibold leading-snug ${
                status === 'pending' ? 'text-gray-400' : 'text-gray-900'
            }`}>
                {label}
            </p>
            <p className={`mt-1 text-[11px] italic ${
                status === 'completed' ? 'text-gray-700' :
                status === 'current'   ? 'text-gray-900' :
                'text-gray-400'
            }`}>
                {statusWord}
            </p>
            {description && (
                <p className="mt-2 text-[11px] text-gray-500 leading-relaxed max-w-[200px]">
                    {description}
                </p>
            )}
        </div>
    );
}

function StepCircle({ status }) {
    if (status === 'completed') {
        // Dark filled — "this happened, no longer active".
        return (
            <span className="relative z-10 inline-flex w-10 h-10 rounded-full bg-gray-900 items-center justify-center flex-shrink-0 shadow-sm">
                <Check size={16} className="text-white" strokeWidth={3} />
            </span>
        );
    }
    if (status === 'current') {
        // White circle with dark ring + dark dot — active-focus.
        return (
            <span className="relative z-10 inline-flex w-10 h-10 rounded-full bg-white border-2 border-gray-900 items-center justify-center flex-shrink-0 ring-4 ring-gray-200 shadow-sm">
                <span className="block w-2.5 h-2.5 rounded-full bg-gray-900" />
            </span>
        );
    }
    // Pending — soft gray ring, no fill.
    return (
        <span className="relative z-10 inline-flex w-10 h-10 rounded-full bg-white border-2 border-gray-200 items-center justify-center flex-shrink-0">
            <span className="block w-2 h-2 rounded-full bg-gray-300" />
        </span>
    );
}

/**
 * Modal wrapping the existing TimelinePanel — triggered by the "See full
 * journey" link on the snapshot.
 */
function JourneyModal({ open, onClose, timeline, currentStage }) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onClose} className="absolute inset-0 bg-[#0c1611]/70 backdrop-blur-sm" />
            <div className="relative bg-white border border-gray-200 shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
                <div className="flex items-start justify-between gap-4 px-6 py-5 border-b border-gray-100 sticky top-0 bg-white z-10">
                    <h3 className="text-lg font-semibold text-[#282728] tracking-tight">
                        Application journey
                    </h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-gray-400 hover:text-[#282728]"
                        aria-label="Close"
                    >
                        <X size={18} />
                    </button>
                </div>
                <div className="px-6 py-6">
                    <TimelinePanel timeline={timeline} currentStage={currentStage} />
                </div>
            </div>
        </div>
    );
}

/**
 * Tab strip. Active tab is bold-underlined; idle tabs are gray text. Each
 * tab can carry a small numeric badge (e.g. checklist count, document
 * count).
 */
function TabStrip({ active, onChange, counts = {} }) {
    // 'visa' was previously a separate tab for the requirements checklist;
    // 'documents' was the gallery of all uploads. They've been merged into
    // a single 'documents' tab that surfaces uploads first, then the
    // outstanding requirements, then the agreement pack.
    const items = [
        { key: 'overview', label: 'Overview',          Icon: Sparkles,   badge: counts.attention },
        { key: 'visa',     label: 'Requirements',      Icon: FolderIcon, badge: counts.visa },
        { key: 'profile',  label: 'My profile',        Icon: UserIcon,   badge: null },
    ];
    return (
        <div className="bg-white rounded-t-2xl border border-b-0 border-gray-100 shadow-sm">
            <div className="flex overflow-x-auto px-4 border-b border-gray-100">
                {items.map((it) => {
                    const isActive = active === it.key;
                    return (
                        <button
                            key={it.key}
                            type="button"
                            onClick={() => onChange(it.key)}
                            className={`inline-flex items-center gap-2 px-4 py-3 text-[13px] font-semibold whitespace-nowrap transition-colors border-b-2 -mb-px ${
                                isActive
                                    ? 'text-gray-900 border-gray-900'
                                    : 'text-gray-500 border-transparent hover:text-gray-800'
                            }`}
                            aria-current={isActive ? 'page' : undefined}
                        >
                            <it.Icon size={14} strokeWidth={2} />
                            <span>{it.label}</span>
                            {typeof it.badge === 'number' && it.badge > 0 && (
                                <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${
                                    isActive ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600'
                                }`}>
                                    {it.badge}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}


function MessagesTabStub() {
    return (
        <div className="bg-white rounded-b-2xl border border-t-0 border-gray-100 shadow-sm px-6 py-16 text-center">
            <MessageSquare size={28} className="mx-auto text-gray-300 mb-3" strokeWidth={1.5} />
            <p className="text-[13px] font-semibold text-gray-900 mb-1">
                Messages from your adviser will appear here
            </p>
            <p className="text-[12px] text-gray-500 max-w-sm mx-auto leading-relaxed">
                Coming soon — your adviser will be able to send you updates
                and you can reply through this page.
            </p>
        </div>
    );
}

/**
 * DocumentsHubTab — merged "Documents" tab that replaces the previous
 * Visa-requirements tab AND the flat document gallery. Visually layered
 * top-down by what the client cares about most:
 *
 *   1. Trust strip      — encryption + access scope, professional reassurance
 *   2. Progress strip   — at-a-glance "X of Y approved" + status breakdown
 *   3. Your uploads     — what they've submitted, grouped by status
 *   4. Requirements     — what's still outstanding (uploads-first ranking
 *                         means the call to action sits below the work
 *                         they've already done, which feels less nagging)
 *   5. Agreements pack  — consultancy + engagement docs to sign / view
 *
 * The lifted upload / replace / delete modal state lives on the page; this
 * component just calls the callbacks it's given.
 */
/** Count of "needs attention" items — drives the Overview tab badge. */
function attentionCount({ documents = [], sharedDocuments = [], visa = null }) {
    const checklist = visa?.checklist || [];
    const byKey = new Map();
    for (const d of documents) {
        if (! d.checklist_key) continue;
        const p = byKey.get(d.checklist_key);
        if (! p || new Date(d.created_at) > new Date(p.created_at)) byKey.set(d.checklist_key, d);
    }
    const outstanding = checklist.filter((it) => {
        if (it.required === false) return false;
        const doc = byKey.get(it.key);
        return ! doc || doc.status === 'Rejected';
    }).length;
    const unsigned = sharedDocuments.filter((d) => d.signable && ! d.signed).length;
    const rejected = documents.filter((d) => d.status === 'Rejected').length;
    return outstanding + unsigned + rejected;
}

function AlertCard({ icon: Icon, title, text, action, actionLabel }) {
    return (
        <div className="bg-white border border-gray-100 rounded-2xl p-4 flex items-start gap-3 shadow-sm">
            <div className="w-9 h-9 rounded-xl bg-gray-900 flex items-center justify-center text-white shrink-0">
                <Icon size={17} />
            </div>
            <div className="flex-1 min-w-0">
                <p className="text-sm font-bold text-gray-900">{title}</p>
                <p className="text-xs text-gray-500 mt-0.5 leading-relaxed">{text}</p>
            </div>
            {action && (
                <button onClick={action} className="px-3 py-1.5 rounded-lg text-white text-xs font-bold bg-[#436235] hover:bg-[#37522c] transition-colors shrink-0">
                    {actionLabel}
                </button>
            )}
        </div>
    );
}

function ProgressRing({ pct }) {
    const r = 54;
    const c = 2 * Math.PI * r;
    const off = c - (Math.max(0, Math.min(100, pct)) / 100) * c;
    return (
        <div className="relative w-[150px] h-[150px] mx-auto">
            <svg width="150" height="150" viewBox="0 0 150 150">
                <circle cx="75" cy="75" r={r} fill="none" stroke="#eef2f5" strokeWidth="14" />
                <circle
                    cx="75" cy="75" r={r} fill="none" stroke="url(#ringGrad)" strokeWidth="14"
                    strokeLinecap="round" strokeDasharray={c} strokeDashoffset={off}
                    transform="rotate(-90 75 75)"
                />
                <defs>
                    <linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stopColor="#00A693" />
                        <stop offset="1" stopColor="#436235" />
                    </linearGradient>
                </defs>
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-3xl font-bold text-gray-900 tabular-nums">{pct}%</span>
                <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-400">complete</span>
            </div>
        </div>
    );
}

/**
 * Colourful client dashboard — the lead portal's "Dashboard" (sidebar mode).
 * Welcome hero + at-a-glance KPI chips + document-progress ring + the
 * "needs your attention" list. Built for the client (ePathways green/teal),
 * distinct from the staff-facing OverviewTab.
 */
function ClientDashboard({ lead, visa, documents = [], sharedDocuments = [], proposal = null, onGoToVisa }) {
    const firstName = (lead?.first_name || 'there').split(' ')[0];
    const checklist = visa?.checklist || [];

    const byKey = new Map();
    for (const d of documents) {
        if (! d.checklist_key) continue;
        const p = byKey.get(d.checklist_key);
        if (! p || new Date(d.created_at) > new Date(p.created_at)) byKey.set(d.checklist_key, d);
    }
    const have = checklist.filter((it) => {
        const doc = byKey.get(it.key);
        return doc && doc.status !== 'Rejected';
    }).length;
    const total = checklist.length;
    const pct = total ? Math.round((have / total) * 100) : 0;
    const approved = documents.filter((d) => d.status === 'Approved').length;
    const inReview = documents.filter((d) => d.status === 'UnderReview' || d.status === 'Submitted').length;
    const rejected = documents.filter((d) => d.status === 'Rejected').length;
    const stillNeeded = checklist.filter((it) => {
        if (it.required === false) return false;
        const doc = byKey.get(it.key);
        return ! doc || doc.status === 'Rejected';
    }).length;

    const kpis = [
        { label: 'Complete',     value: `${pct}%`,   Icon: Award,        chip: 'bg-[#436235]/10 text-[#436235]' },
        { label: 'Approved',     value: approved,    Icon: CheckCircle2, chip: 'bg-[#436235]/10 text-[#436235]' },
        { label: 'In review',    value: inReview,    Icon: Clock,        chip: 'bg-gray-100 text-gray-700' },
        { label: 'Still needed', value: stillNeeded, Icon: Upload,       chip: 'bg-gray-100 text-gray-700' },
    ];

    const alerts = [];
    sharedDocuments.filter((d) => d.signable && ! d.signed).forEach((d) => alerts.push({
        icon: PenTool,
        title: `Sign your ${d.title}`,
        text: 'Your adviser has prepared it below — review and add your signature.',
    }));
    if (stillNeeded > 0) alerts.push({
        icon: Upload,
        title: `${stillNeeded} document${stillNeeded === 1 ? '' : 's'} still needed`,
        text: 'Upload the outstanding items so your adviser can proceed.',
        action: onGoToVisa, actionLabel: 'Upload',
    });
    if (rejected > 0) alerts.push({
        icon: AlertCircle,
        title: `${rejected} document${rejected === 1 ? '' : 's'} to re-upload`,
        text: 'One or more uploads were not accepted. Please replace them.',
        action: onGoToVisa, actionLabel: 'Review',
    });

    return (
        <div className="space-y-5">
            {/* Welcome hero */}
            <div
                className="relative overflow-hidden rounded-3xl p-6 sm:p-8 text-white shadow-lg shadow-gray-900/20"
                style={{ background: 'linear-gradient(135deg, #111827 0%, #1f2937 50%, #0b0f19 100%)' }}
            >
                <div className="absolute -right-12 -top-12 w-52 h-52 rounded-full bg-white/[0.06]" />
                <div className="absolute right-24 top-20 w-24 h-24 rounded-full bg-white/[0.06]" />
                <div className="relative">
                    <p className="text-gray-400 text-[11px] font-bold uppercase tracking-[0.22em]">Welcome back</p>
                    <h2 className="text-2xl sm:text-3xl font-bold mt-1.5">Hi {firstName} 👋</h2>
                    <p className="text-gray-300 text-sm mt-2 max-w-md leading-relaxed">
                        Here's where your {lead?.inz_visa_type || 'application'} stands.{' '}
                        {stillNeeded > 0
                            ? `You have ${stillNeeded} document${stillNeeded === 1 ? '' : 's'} left to upload.`
                            : "You're all caught up — nice work!"}
                    </p>
                    <div className="mt-5 flex items-center gap-3 flex-wrap">
                        <button
                            onClick={onGoToVisa}
                            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white text-gray-900 text-sm font-bold hover:bg-gray-100 transition-colors shadow-sm"
                        >
                            <Upload size={15} /> Upload documents
                        </button>
                        {lead?.tracking_code && (
                            <span className="px-3 py-2 rounded-xl bg-white/10 text-white text-xs font-mono tracking-wide">
                                {lead.tracking_code}
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* KPI chips */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                {kpis.map((k) => (
                    <div key={k.label} className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
                        <div className={`inline-flex items-center justify-center w-10 h-10 rounded-xl ${k.chip}`}>
                            <k.Icon size={18} />
                        </div>
                        <div className="text-3xl font-bold text-gray-900 tabular-nums mt-3">{k.value}</div>
                        <div className="text-[11px] font-bold text-gray-400 uppercase tracking-wide mt-0.5">{k.label}</div>
                    </div>
                ))}
            </div>

            {/* Progress + needs attention */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                    <h3 className="text-sm font-bold text-gray-800 mb-3">Documents progress</h3>
                    <ProgressRing pct={pct} />
                    <p className="text-xs text-gray-500 text-center mt-3">
                        <span className="font-semibold text-gray-700">{have}</span> of {total} uploaded
                    </p>
                </div>

                <div className="lg:col-span-2 space-y-3">
                    <h3 className="text-sm font-bold text-gray-800">Needs your attention</h3>
                    {alerts.length === 0 ? (
                        <div className="bg-white border border-gray-100 rounded-2xl p-5 flex items-center gap-3 shadow-sm">
                            <div className="w-10 h-10 rounded-xl bg-gray-900 flex items-center justify-center text-white">
                                <CheckCircle2 size={20} />
                            </div>
                            <div>
                                <p className="text-sm font-bold text-gray-900">You're all caught up</p>
                                <p className="text-xs text-gray-500 mt-0.5">Nothing needs your attention right now.</p>
                            </div>
                        </div>
                    ) : (
                        alerts.map((a, i) => <AlertCard key={i} {...a} />)
                    )}
                </div>
            </div>

            {/* Adviser's shortlisted programs, if any. */}
            {proposal && proposal.programs?.length > 0 && (
                <ProposalShortlist proposal={proposal} code={lead?.tracking_code} />
            )}
        </div>
    );
}

/**
 * Overview tab — a one-glance summary: what needs the client's attention
 * (sign the agreement, upload outstanding docs), document stats, the
 * journey, and the adviser's shared documents (with e-sign).
 */
function OverviewTab({ lead = null, visa, documents = [], sharedDocuments = [], agreements = [], timeline = [], onSeeFull, onGoToVisa }) {
    const checklist = visa?.checklist || [];

    const docsByKey = useMemo(() => {
        const m = new Map();
        for (const d of documents) {
            if (! d.checklist_key) continue;
            const p = m.get(d.checklist_key);
            if (! p || new Date(d.created_at) > new Date(p.created_at)) m.set(d.checklist_key, d);
        }
        return m;
    }, [documents]);

    const outstanding = useMemo(() => checklist.filter((it) => {
        if (it.required === false) return false;
        const doc = docsByKey.get(it.key);
        return ! doc || doc.status === 'Rejected';
    }).length, [checklist, docsByKey]);

    const unsignedAgreements = sharedDocuments.filter((d) => d.signable && ! d.signed);
    const rejectedCount = documents.filter((d) => d.status === 'Rejected').length;
    const approved = documents.filter((d) => d.status === 'Approved').length;
    const review = documents.filter((d) => d.status === 'UnderReview').length;
    const submitted = documents.filter((d) => d.status === 'Submitted').length;

    // Uploaded vs not-uploaded across the requirement checklist — drives the
    // documents progress donut on the left.
    const haveCount = useMemo(
        () => checklist.filter((it) => {
            const doc = docsByKey.get(it.key);
            return doc && doc.status !== 'Rejected';
        }).length,
        [checklist, docsByKey],
    );
    const missingCount = Math.max(0, checklist.length - haveCount);

    const alerts = [];
    unsignedAgreements.forEach((d) => alerts.push({
        tone: 'amber', icon: <PenTool size={15} />,
        title: `Sign your ${d.title}`,
        text: 'Your adviser has prepared it below — review and add your signature.',
    }));
    if (outstanding > 0) alerts.push({
        tone: 'blue', icon: <Upload size={15} />,
        title: `${outstanding} document${outstanding === 1 ? '' : 's'} still needed`,
        text: 'Upload the outstanding items so your adviser can proceed.',
        action: onGoToVisa, actionLabel: 'Upload',
    });
    if (rejectedCount > 0) alerts.push({
        tone: 'red', icon: <AlertCircle size={15} />,
        title: `${rejectedCount} document${rejectedCount === 1 ? '' : 's'} to re-upload`,
        text: 'One or more uploads were not accepted. Please replace them.',
        action: onGoToVisa, actionLabel: 'Review',
    });

    const stats = [
        { label: 'Approved', value: approved, tone: 'text-emerald-700' },
        { label: 'In review', value: review, tone: 'text-blue-700' },
        { label: 'Submitted', value: submitted, tone: 'text-gray-700' },
        { label: 'Still needed', value: outstanding, tone: 'text-amber-700' },
    ];

    // "On file" only when the free-assessment form was actually submitted
    // (creates educationExps/studyPlans rows). ai_analysis_status alone can
    // carry a default value on manually-added leads, so it can't be trusted
    // as the gate — but it's still useful for the analysing sub-state.
    const hasAssessment = Boolean(lead?.has_free_assessment);
    const assessmentStatus = lead?.ai_analysis_status ?? null;
    const trackingCode = lead?.tracking_code || '';
    const assessmentDone = hasAssessment && assessmentStatus === 'completed';
    const assessmentPending = hasAssessment && assessmentStatus === 'processing';

    return (
        <div className="bg-white rounded-b-2xl border border-t-0 border-gray-100 shadow-sm p-4 sm:p-6 space-y-6">
            {/* Free Assessment + Needs your attention — sit on one row on
                desktop, stack on mobile. Both blocks keep the same header
                treatment so they read as a pair. */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div>
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">Free Assessment</p>
                    {hasAssessment ? (
                        <div className={`flex items-start gap-3 px-4 py-3 rounded-xl border h-full ${assessmentDone ? 'bg-emerald-50 border-emerald-100' : 'bg-blue-50 border-blue-100'}`}>
                            <div className={`w-9 h-9 shrink-0 rounded-full flex items-center justify-center ${assessmentDone ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700'}`}>
                                {assessmentDone ? <CheckCircle2 size={18} /> : <Clock size={18} />}
                            </div>
                            <div className="min-w-0">
                                <p className={`text-[13px] font-bold ${assessmentDone ? 'text-emerald-800' : 'text-blue-800'}`}>
                                    {assessmentDone ? 'Free Assessment on file' : assessmentPending ? 'Analysing your answers…' : 'Free Assessment received'}
                                </p>
                                <p className="text-[12px] text-gray-600 mt-0.5">
                                    {assessmentDone
                                        ? 'Shared with your adviser.'
                                        : 'We\'ll notify you when your snapshot is ready.'}
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center gap-3 px-4 py-3 rounded-xl bg-gray-900 text-white h-full">
                            <div className="w-9 h-9 shrink-0 rounded-full bg-white/10 flex items-center justify-center">
                                <Sparkles size={16} />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] font-bold">Start your Free Assessment</p>
                                <p className="text-[12px] text-white/70 mt-0.5 truncate">
                                    Short questionnaire — get an eligibility snapshot.
                                </p>
                            </div>
                            <a
                                href={`/free-assessment${trackingCode ? `?code=${encodeURIComponent(trackingCode)}` : ''}`}
                                className="inline-flex items-center gap-1 rounded-lg bg-white text-gray-900 px-3 py-1.5 text-[12px] font-bold hover:bg-gray-100 transition-colors shrink-0"
                            >
                                Start
                                <ArrowRight size={13} />
                            </a>
                        </div>
                    )}
                </div>

                <div>
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">Needs your attention</p>
                    {alerts.length > 0 ? (
                        <ul className="space-y-2">{alerts.map((a, i) => <AlertRow key={i} alert={a} />)}</ul>
                    ) : (
                        <div className="flex items-center gap-2.5 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-100 h-full">
                            <CheckCircle2 size={16} className="text-emerald-600 flex-shrink-0" />
                            <p className="text-[13px] text-emerald-800 font-medium">You're all caught up — nothing needs your attention right now.</p>
                        </div>
                    )}
                </div>
            </div>

            {/* Summary row — documents graph (uploaded vs not) on the left,
                document stats in a 2×2 grid on the right. */}
            <div className="flex flex-col lg:flex-row gap-4 items-stretch">
                <div className="lg:flex-1 min-w-0 flex flex-col">
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">Documents progress</p>
                    <DocsDonut uploaded={haveCount} notUploaded={missingCount} />
                </div>

                <div className="lg:w-[360px] flex-shrink-0 flex flex-col">
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">Documents</p>
                    <div className="grid grid-cols-2 grid-rows-2 gap-3 flex-1">
                        {stats.map((s) => (
                            <div key={s.label} className="rounded-xl border border-gray-100 bg-gray-50/60 px-4 py-3 flex flex-col justify-center">
                                <p className={`text-2xl font-bold tabular-nums ${s.tone}`}>{s.value}</p>
                                <p className="text-[11px] text-gray-500 mt-0.5">{s.label}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Journey (self-contained card) — hidden for now.
            <JourneySnapshot timeline={timeline} onSeeFull={onSeeFull} /> */}

            {/* Agreements to sign */}
            {agreements.length > 0 && (
                <div>
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">Agreements to sign</p>
                    <ul className="space-y-2">{agreements.map((a) => <AgreementCardRow key={a.id} agreement={a} />)}</ul>
                </div>
            )}

            {/* Documents from your adviser — download + e-sign */}
            {sharedDocuments.length > 0 && (
                <div>
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">Documents from your adviser</p>
                    <ul className="space-y-2">{sharedDocuments.map((d) => <SharedDocRow key={d.id} doc={d} />)}</ul>
                </div>
            )}
        </div>
    );
}

/** Donut of uploaded vs not-uploaded documents, with % complete centred. */
function DocsDonut({ uploaded = 0, notUploaded = 0 }) {
    const total = uploaded + notUploaded;
    const pct = total ? Math.round((uploaded / total) * 100) : 0;
    const r = 58;
    const c = 2 * Math.PI * r;
    const up = total ? c * (uploaded / total) : 0;

    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50/60 p-5 flex-1 flex flex-col sm:flex-row items-center justify-center gap-6">
            <div className="relative w-[150px] h-[150px] flex-shrink-0">
                <svg width="150" height="150" viewBox="0 0 150 150">
                    <circle cx="75" cy="75" r={r} fill="none" stroke="#e5e7eb" strokeWidth="16" />
                    {up > 0 && (
                        <circle
                            cx="75" cy="75" r={r} fill="none" stroke="#059669" strokeWidth="16"
                            strokeDasharray={`${up} ${c}`} strokeLinecap="round"
                            transform="rotate(-90 75 75)"
                        />
                    )}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-3xl font-bold text-gray-900 tabular-nums">{pct}%</span>
                    <span className="text-[11px] text-gray-500">complete</span>
                </div>
            </div>
            <div className="space-y-2">
                <div className="flex items-center gap-2 text-[13px]">
                    <span className="w-3 h-3 rounded-full bg-emerald-600" />
                    <span className="text-gray-700 font-semibold tabular-nums">{uploaded}</span>
                    <span className="text-gray-500">uploaded</span>
                </div>
                <div className="flex items-center gap-2 text-[13px]">
                    <span className="w-3 h-3 rounded-full bg-gray-300" />
                    <span className="text-gray-700 font-semibold tabular-nums">{notUploaded}</span>
                    <span className="text-gray-500">not uploaded</span>
                </div>
                <div className="pt-1 text-[11px] text-gray-400">
                    {total} document{total === 1 ? '' : 's'} in total
                </div>
            </div>
        </div>
    );
}

function AlertRow({ alert: a }) {
    const tones = {
        amber: 'bg-amber-50 border-amber-200 text-amber-600',
        blue: 'bg-blue-50 border-blue-200 text-blue-600',
        red: 'bg-red-50 border-red-200 text-red-600',
    };
    return (
        <li className={`flex items-start gap-3 px-4 py-3 rounded-xl border ${tones[a.tone] || tones.blue}`}>
            <span className="mt-0.5 flex-shrink-0">{a.icon}</span>
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold text-gray-900">{a.title}</p>
                <p className="text-[12px] text-gray-600 mt-0.5">{a.text}</p>
            </div>
            {a.action && (
                <button onClick={a.action} className="text-[11px] font-bold uppercase tracking-wide text-gray-900 hover:underline flex-shrink-0 mt-0.5 whitespace-nowrap">
                    {a.actionLabel} →
                </button>
            )}
        </li>
    );
}

function DocumentsHubTab({
    visa,
    documents = [],
    sharedDocuments = [],
    agreements = [],
    showAdviserDocs = true,
    sectionFilter,
    onSectionFilter,
    statusFilter,
    onStatusFilter,
    onOpenUpload,
    onOpenReplace,
    onDelete,
}) {
    const checklist = visa?.checklist || [];

    // Index uploaded docs by checklist_key. A key can hold SEVERAL files (the
    // client can "Upload another" without replacing the first), so we keep the
    // full list per key, oldest first, and treat the latest as the row's status.
    const docsByKey = useMemo(() => {
        const map = new Map();
        for (const d of documents) {
            if (! d.checklist_key) continue;
            const key = checklist.find((item) => item.aliases?.includes(d.checklist_key))?.key || d.checklist_key;
            if (! map.has(key)) map.set(key, []);
            map.get(key).push(d);
        }
        for (const arr of map.values()) {
            arr.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        }
        return map;
    }, [documents, checklist]);

    // Set of keys that belong to the checklist — used to split uploaded docs
    // into "belongs to a checklist item" vs "other".
    const checklistKeySet = useMemo(() => new Set(checklist.flatMap((it) => [it.key, ...(it.aliases || [])])), [checklist]);

    // Build a requirement row from a checklist item, attaching its uploaded
    // file (if any) so the row stays put + shows the file after upload.
    // State: missing | submitted | review | approved | rejected.
    const buildRow = (it) => {
        const docs = docsByKey.get(it.key) || [];
        // The latest upload drives the row's status/state; all files display.
        const doc = docs.length ? docs[docs.length - 1] : null;
        // Prefer the explicit Section field; fall back to splitting a legacy
        // "Section · Name" label for visas saved before the split.
        const parsed = it.category
            ? { section: it.category, name: it.label || it.key }
            : parseChecklistLabel(it.label || it.key);
        const state = ! doc ? 'missing'
            : doc.status === 'Rejected' ? 'rejected'
                : doc.status === 'Approved' ? 'approved'
                    : doc.status === 'UnderReview' ? 'review'
                        : 'submitted';
        return {
            key: it.key,
            section: parsed.section,
            // Universal items carry a bare label (no "Section · Name"), so use
            // the label as the display name.
            name: it.universal ? (it.label || parsed.name) : parsed.name,
            label: it.label || it.key,
            hint: it.hint || '',
            required: it.required !== false,
            doc,
            docs,
            state,
            // Staff-set status from the Documents tab (accepted / under_review /
            // needs_attention). When present it drives the STATUS badge so the
            // tracker mirrors exactly what staff see.
            staffStatus: it.staff_status || null,
            rejectedDoc: state === 'rejected' ? doc : null,
        };
    };

    // When the server hands us an explicit section order (the general/education
    // checklist), the whole requirements list obeys it — including the SV
    // Information Form, which joins the "Information Form" section instead of
    // being pinned on top, so the very first section is the one staff intend
    // (e.g. Personal Documents). Immigration checklists send no order, so the
    // VIF stays pinned and sections keep their natural order (unchanged).
    const sectionOrder = Array.isArray(visa?.section_order) && visa.section_order.length ? visa.section_order : null;
    const UNIVERSAL_SECTION = 'Information Form';

    // Universal items (e.g. SV Information Form) pinned to the very top — only
    // when there's no explicit order (immigration).
    const svRows = useMemo(
        () => sectionOrder ? [] : checklist.filter((it) => it.universal).map(buildRow),
        [checklist, docsByKey, sectionOrder] // eslint-disable-line react-hooks/exhaustive-deps
    );

    // The rest of the checklist, grouped by section further down. With an
    // explicit order, universal items fold into their natural section.
    const requirementRows = useMemo(
        () => checklist
            .filter((it) => sectionOrder ? true : ! it.universal)
            .map((it) => {
                const row = buildRow(it);
                return sectionOrder && it.universal ? { ...row, section: UNIVERSAL_SECTION } : row;
            }),
        [checklist, docsByKey, sectionOrder] // eslint-disable-line react-hooks/exhaustive-deps
    );

    const requirementsBySection = useMemo(() => {
        const m = new Map();
        for (const row of requirementRows) {
            if (! m.has(row.section)) m.set(row.section, []);
            m.get(row.section).push(row);
        }
        const entries = Array.from(m.entries()).map(([section, items]) => ({ section, items }));
        if (sectionOrder) {
            const rank = (s) => { const i = sectionOrder.indexOf(s); return i === -1 ? 999 : i; };
            entries.sort((a, b) => rank(a.section) - rank(b.section));
        }
        return entries;
    }, [requirementRows, sectionOrder]);

    // Count still needed (missing or rejected) — drives the section subtitle.
    const outstandingCount = useMemo(
        () => requirementRows.filter((r) => r.state === 'missing' || r.state === 'rejected').length,
        [requirementRows]
    );

    // Uploaded files that don't map to any checklist item — shown on their own
    // in the last section.
    const otherDocs = useMemo(
        () => documents.filter((d) => ! d.checklist_key || ! checklistKeySet.has(d.checklist_key)),
        [documents, checklistKeySet]
    );

    // Group uploads by canonical status. Sort within each group newest-first.
    const uploadGroups = useMemo(() => {
        const buckets = { Approved: [], UnderReview: [], Submitted: [], Rejected: [] };
        for (const d of documents) {
            const k = d.status && buckets[d.status] ? d.status : 'Submitted';
            buckets[k].push(d);
        }
        for (const k of Object.keys(buckets)) {
            buckets[k].sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        }
        return buckets;
    }, [documents]);

    const counts = useMemo(() => ({
        uploads:     documents.length,
        approved:    uploadGroups.Approved.length,
        review:      uploadGroups.UnderReview.length,
        submitted:   uploadGroups.Submitted.length,
        rejected:    uploadGroups.Rejected.length,
        outstanding: outstandingCount,
        agreements:  agreements.length,
        requirementsTotal: checklist.length,
        other:       otherDocs.length,
    }), [documents, uploadGroups, outstandingCount, agreements, checklist, otherDocs]);

    const progressPct = counts.requirementsTotal > 0
        ? Math.round((counts.approved / counts.requirementsTotal) * 100)
        : 0;

    return (
        <div className="bg-white border-x border-b border-gray-200">
            <DocsTrustStrip />

            {/* SECTION 0 — universal top documents (SV Information Form),
                pinned above everything else on every tracker. Only present
                when staff haven't hidden it for this case. */}
            {svRows.length > 0 && (
                <DocsSection
                    title="SV Information Form"
                    eyebrow="Start here"
                >
                    <div className="bg-gray-50/70 border border-gray-100 rounded-xl p-3 sm:p-4">
                        <ChecklistTable
                            rows={svRows}
                            onOpenUpload={onOpenUpload}
                            onOpenReplace={onOpenReplace}
                            onDelete={onDelete}
                        />
                    </div>
                </DocsSection>
            )}

            {/* SECTION 1 — agreements (consultancy paperwork comes first,
                ahead of any document collection). Hidden on the general
                education tracker (sectionOrder present) so only the four
                requirement sections show. */}
            {! sectionOrder && showAdviserDocs && agreements.length > 0 && (
                <DocsSection
                    title="Agreements to sign"
                    eyebrow="Consultancy"
                    subtitle={`${agreements.length} agreement${agreements.length === 1 ? '' : 's'} from your adviser`}
                >
                    <ul className="space-y-2">
                        {agreements.map((a) => (
                            <AgreementCardRow key={a.id} agreement={a} />
                        ))}
                    </ul>
                </DocsSection>
            )}

            {/* SECTION 1.5 — documents the adviser shared for the client to
                download (engagement pack: written agreement + IAA standards). */}
            {showAdviserDocs && sharedDocuments.length > 0 && (
                <DocsSection
                    title="Documents from your adviser"
                    eyebrow="For you"
                    subtitle={`${sharedDocuments.length} document${sharedDocuments.length === 1 ? '' : 's'} to download`}
                >
                    <ul className="space-y-2">
                        {sharedDocuments.map((d) => (
                            <SharedDocRow key={d.id} doc={d} />
                        ))}
                    </ul>
                </DocsSection>
            )}

            {/* SECTION 2 — the document checklist. EVERY item stays under its
                section; once a file is uploaded the row shows it inline (View +
                status) instead of disappearing. Items are only removed when a
                staff member unchecks them (hidden_track_documents, filtered
                server-side). */}
            <DocsSection hideHeader last={counts.other === 0 || !! sectionOrder}>
                {counts.requirementsTotal === 0 ? (
                    <DocsEmptyRequirements />
                ) : (
                    <div className="space-y-5">
                        {requirementsBySection.map(({ section, items }) => (
                            <div key={section} className="bg-gray-50/70 border border-gray-100 rounded-xl p-3 sm:p-4">
                                <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2 inline-flex items-center gap-1.5">
                                    <FolderIcon size={11} strokeWidth={2} /> {section}
                                </p>
                                <ChecklistTable
                                    rows={items}
                                    onOpenUpload={onOpenUpload}
                                    onOpenReplace={onOpenReplace}
                                    onDelete={onDelete}
                                />
                            </div>
                        ))}
                    </div>
                )}
            </DocsSection>

            {/* Preserve historical uploads even when the active checklist changes. */}
            {counts.other > 0 && (
                <DocsSection
                    title="Other documents"
                    eyebrow="Uploads"
                    subtitle={`${counts.other} file${counts.other === 1 ? '' : 's'} not tied to a checklist item`}
                    last
                >
                    <UploadsTable
                        documents={otherDocs}
                        onOpenReplace={onOpenReplace}
                        onDelete={onDelete}
                    />
                </DocsSection>
            )}
        </div>
    );
}

// ── Trust + Progress strips ────────────────────────────────────────────────

function DocsTrustStrip() {
    return (
        <div className="px-4 sm:px-6 py-3 border-b border-gray-100 bg-gray-50/40">
            <p className="text-[11px] text-gray-600 inline-flex items-center gap-2">
                <ShieldCheck size={13} className="text-emerald-600" strokeWidth={2} />
                <span>
                    <span className="font-semibold text-[#282728]">Private &amp; encrypted.</span>{' '}
                    Only your assigned adviser at EP can view what you upload here.
                </span>
            </p>
        </div>
    );
}

function DocsProgressStrip({ progressPct, counts, visaName }) {
    return (
        <div className="px-4 sm:px-6 py-4 border-b border-gray-100">
            <div className="flex items-center justify-between gap-4 mb-2 flex-wrap">
                <div>
                    {visaName && (
                        <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-1">
                            {visaName}
                        </p>
                    )}
                    <p className="text-[13px] text-[#282728]">
                        <span className="font-semibold">{counts.approved}</span>
                        <span className="text-gray-500"> of </span>
                        <span className="font-semibold">{counts.requirementsTotal || counts.uploads}</span>
                        <span className="text-gray-500"> approved</span>
                    </p>
                </div>
                <div className="text-[11px] text-gray-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                    {counts.review > 0     && <span className="text-blue-700">{counts.review} under review</span>}
                    {counts.submitted > 0  && <span className="text-amber-700">{counts.submitted} submitted</span>}
                    {counts.rejected > 0   && <span className="text-red-700">{counts.rejected} need attention</span>}
                </div>
            </div>
            <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div
                    className="h-full bg-emerald-500 transition-[width] duration-500"
                    style={{ width: `${Math.min(100, progressPct)}%` }}
                />
            </div>
        </div>
    );
}

// ── Section shell + empty states ───────────────────────────────────────────

function DocsSection({ title, eyebrow, subtitle, action, children, last = false, hideHeader = false }) {
    return (
        <section className={`px-4 sm:px-6 py-5 ${last ? '' : 'border-b border-gray-100'}`}>
            {! hideHeader && (
                <div className="flex items-end justify-between gap-3 mb-4 flex-wrap">
                    <div>
                        {eyebrow && (
                            <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-1">
                                {eyebrow}
                            </p>
                        )}
                        <h2 className="text-[15px] font-bold text-[#282728]">{title}</h2>
                        {subtitle && <p className="text-[12px] text-gray-500 mt-0.5">{subtitle}</p>}
                    </div>
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

function DocsEmptyUploads({ onOpenUpload }) {
    return (
        <div className="text-center py-10 border border-dashed border-gray-200">
            <Upload size={22} className="mx-auto text-gray-300 mb-2" strokeWidth={1.5} />
            <p className="text-[13px] text-gray-500 mb-3">
                You haven't uploaded any files yet.
            </p>
            <button
                type="button"
                onClick={onOpenUpload}
                className="text-[11px] font-bold uppercase tracking-[0.18em] text-[#282728] inline-flex items-center gap-1.5 border border-[#282728] px-3 py-2 hover:bg-[#282728] hover:text-white transition-colors"
            >
                <Plus size={12} /> Upload your first file
            </button>
        </div>
    );
}

function DocsEmptyRequirements() {
    return (
        <div className="border border-dashed border-gray-200 py-6 text-center">
            <p className="text-[12px] text-gray-500">
                No requirements have been published for your visa yet — your adviser will add them shortly.
            </p>
        </div>
    );
}

function DocsRequirementsComplete() {
    return (
        <div className="border border-emerald-100 bg-emerald-50/50 py-5 px-4 text-center">
            <Check size={18} className="mx-auto text-emerald-600 mb-1.5" strokeWidth={2.5} />
            <p className="text-[13px] font-semibold text-emerald-800">
                All requirements received.
            </p>
            <p className="text-[11px] text-emerald-700/80 mt-0.5">
                Your adviser will let you know if anything needs follow-up.
            </p>
        </div>
    );
}

// ── Uploads table ──────────────────────────────────────────────────────────
//
// Tabular layout mirroring the staff-side Documents tab:
//   File · Attachment · Status · Note from staff
//
// Sorted with rejected uploads at the top (they need the client's action)
// then everything else newest-first. The Note column is read-only here —
// only the staff side can edit it on the case profile.

const UPLOAD_STATUS_TONE = {
    Approved:    'bg-emerald-50 text-emerald-700 border-emerald-200',
    UnderReview: 'bg-blue-50 text-blue-700 border-blue-200',
    Submitted:   'bg-amber-50 text-amber-700 border-amber-200',
    Rejected:    'bg-red-50 text-red-700 border-red-200',
};

const UPLOAD_STATUS_LABEL = {
    Approved:    'Accepted / Satisfactory',
    UnderReview: 'Under review',
    Submitted:   'Submitted',
    Rejected:    'Required attention',
};

// Staff-set checklist status (leads.document_checklist) → tracker badge. Mirrors
// the labels/tones on the staff Documents tab so both surfaces read the same.
const STAFF_STATUS_TONE = {
    accepted:        'bg-emerald-50 text-emerald-700 border-emerald-200',
    under_review:    'bg-amber-50 text-amber-700 border-amber-200',
    needs_attention: 'bg-rose-50 text-rose-700 border-rose-200',
};
const STAFF_STATUS_LABEL = {
    accepted:        'Accepted',
    under_review:    'Under Review',
    needs_attention: 'Required Attention',
};

// Lower index = higher priority (sorted to top).
const UPLOAD_STATUS_RANK = {
    Rejected:    0,
    UnderReview: 1,
    Submitted:   2,
    Approved:    3,
};

function UploadsTable({ documents, onOpenReplace, onDelete }) {
    const rows = useMemo(() => {
        const arr = [...documents];
        arr.sort((a, b) => {
            const ra = UPLOAD_STATUS_RANK[a.status] ?? 99;
            const rb = UPLOAD_STATUS_RANK[b.status] ?? 99;
            if (ra !== rb) return ra - rb;
            return new Date(b.created_at) - new Date(a.created_at);
        });
        return arr;
    }, [documents]);

    return (
        <>
            {/* Desktop table */}
            <div className="hidden md:block border border-gray-100">
                <table className="w-full text-[13px]" style={{ tableLayout: 'fixed' }}>
                    <colgroup>
                        <col style={{ width: '34%' }} />
                        <col style={{ width: '22%' }} />
                        <col style={{ width: '16%' }} />
                        <col />
                    </colgroup>
                    <thead>
                        <tr className="bg-gray-100 border-b border-gray-200 text-[10px] font-bold tracking-[0.18em] uppercase text-gray-500">
                            <th className="text-left px-4 py-2.5">File</th>
                            <th className="text-left px-4 py-2.5">Attachment</th>
                            <th className="text-left px-4 py-2.5">Status</th>
                            <th className="text-left px-4 py-2.5">Note from staff</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((d) => (
                            <UploadRow
                                key={d.id}
                                doc={d}
                                onOpenReplace={onOpenReplace}
                                onDelete={onDelete}
                            />
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Mobile — same data as stacked cards */}
            <div className="md:hidden space-y-2">
                {rows.map((d) => (
                    <UploadMobileCard
                        key={d.id}
                        doc={d}
                        onOpenReplace={onOpenReplace}
                        onDelete={onDelete}
                    />
                ))}
            </div>
        </>
    );
}

function UploadRow({ doc, onOpenReplace, onDelete }) {
    const docApi = useDocApi();
    const sizeKb = doc.size ? `${(doc.size / 1024).toFixed(0)} KB` : null;
    // Public tracker: use the file's public storage URL. Fall back to the
    // staff download route only if no public URL is available.
    const viewUrl = docApi.view(doc);
    return (
        <tr className="border-t border-gray-50 align-top hover:bg-gray-50/40">
            {/* File */}
            <td className="px-4 py-3">
                <div className="flex items-start gap-2.5 min-w-0">
                    <FileText size={14} className="text-gray-400 flex-shrink-0 mt-0.5" />
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-[#282728] truncate" title={doc.original_name}>
                            {doc.original_name}
                        </p>
                        <p className="text-[10.5px] text-gray-500 mt-0.5">
                            {[sizeKb, doc.checklist_key].filter(Boolean).join(' · ')}
                            {doc.created_at && <> · {formatStamp(doc.created_at)}</>}
                        </p>
                    </div>
                </div>
            </td>

            {/* Attachment — View / Replace / Remove */}
            <td className="px-4 py-3">
                <div className="flex items-center gap-1.5 flex-wrap">
                    <a
                        href={viewUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        title="View in browser"
                        className="inline-flex items-center justify-center p-1.5 border border-gray-200 bg-white text-gray-600 hover:text-[#282728] hover:bg-gray-50"
                    >
                        <Eye size={12} />
                    </a>
                    {doc.is_editable && (
                        <>
                            <button
                                type="button"
                                onClick={() => onOpenReplace(doc)}
                                title="Replace"
                                className="inline-flex items-center justify-center p-1.5 border border-gray-200 bg-white text-gray-600 hover:text-[#282728] hover:bg-gray-50"
                            >
                                <Upload size={12} />
                            </button>
                            <button
                                type="button"
                                onClick={() => onDelete(doc)}
                                title="Remove"
                                className="inline-flex items-center justify-center p-1.5 border border-gray-200 bg-white text-gray-400 hover:text-red-700 hover:bg-red-50 hover:border-red-200"
                            >
                                <Trash2 size={12} />
                            </button>
                        </>
                    )}
                </div>
            </td>

            {/* Status pill — read-only on the client side */}
            <td className="px-4 py-3">
                <span className={`inline-flex items-center px-2 py-0.5 text-[10px] font-bold tracking-[0.12em] uppercase border ${UPLOAD_STATUS_TONE[doc.status] || 'bg-gray-50 text-gray-500 border-gray-200'}`}>
                    {UPLOAD_STATUS_LABEL[doc.status] || doc.status || '—'}
                </span>
            </td>

            {/* Note from staff */}
            <td className="px-4 py-3">
                {doc.note ? (
                    <p className={`text-[11.5px] leading-snug ${doc.status === 'Rejected' ? 'text-red-700' : 'text-gray-700'}`}>
                        {doc.note}
                    </p>
                ) : (
                    <span className="text-[11px] text-gray-300">—</span>
                )}
            </td>
        </tr>
    );
}

function UploadMobileCard({ doc, onOpenReplace, onDelete }) {
    const docApi = useDocApi();
    const sizeKb = doc.size ? `${(doc.size / 1024).toFixed(0)} KB` : null;
    const viewUrl = docApi.view(doc);
    return (
        <div className="border border-gray-200 bg-white px-3.5 py-3">
            <div className="flex items-start gap-3">
                <div className="w-9 h-9 bg-gray-50 border border-gray-100 flex items-center justify-center flex-shrink-0">
                    <FileText size={15} className="text-gray-400" strokeWidth={2} />
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2">
                        <p className="text-[13px] font-semibold text-[#282728] truncate min-w-0" title={doc.original_name}>
                            {doc.original_name}
                        </p>
                        <span className={`inline-flex items-center px-1.5 py-0.5 text-[9px] font-bold tracking-[0.1em] uppercase border flex-shrink-0 ${UPLOAD_STATUS_TONE[doc.status] || 'bg-gray-50 text-gray-500 border-gray-200'}`}>
                            {UPLOAD_STATUS_LABEL[doc.status] || doc.status || '—'}
                        </span>
                    </div>
                    <p className="text-[10.5px] text-gray-500 mt-0.5">
                        {[sizeKb, doc.checklist_key].filter(Boolean).join(' · ')}
                        {doc.created_at && <> · {formatStamp(doc.created_at)}</>}
                    </p>
                    {doc.note && (
                        <p className={`text-[11px] mt-1.5 px-2 py-1 border leading-snug ${doc.status === 'Rejected' ? 'text-red-700 bg-red-50 border-red-100' : 'text-gray-700 bg-gray-50 border-gray-100'}`}>
                            <span className="font-semibold">Note:</span> {doc.note}
                        </p>
                    )}
                    <div className="flex items-center gap-3 mt-2">
                        <a
                            href={viewUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[11px] font-semibold text-[#282728] inline-flex items-center gap-1 hover:underline"
                        >
                            <Eye size={11} strokeWidth={2} /> View
                        </a>
                        {doc.is_editable && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => onOpenReplace(doc)}
                                    className="text-[11px] font-semibold text-[#282728] hover:underline"
                                >
                                    Replace
                                </button>
                                <button
                                    type="button"
                                    onClick={() => onDelete(doc)}
                                    className="text-[11px] font-semibold text-gray-400 hover:text-red-700 ml-auto"
                                >
                                    Remove
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Checklist as a table ───────────────────────────────────────────────────
// Columns: File name · Attachment (the file for this case) · Status · Actions
// (View / Upload another / Replace). Scrolls horizontally on small screens.

const trackBtn = "inline-flex items-center gap-1 px-2 py-1 border border-gray-200 bg-white text-[11px] font-semibold text-gray-600 hover:text-[#282728] hover:bg-gray-50 transition-colors";

function ChecklistTable({ rows, onOpenUpload, onOpenReplace, onDelete }) {
    return (
        <div className="overflow-x-auto bg-white border border-gray-200 rounded-lg shadow-sm">
            <table className="w-full text-[13px] min-w-[560px]" style={{ tableLayout: 'fixed' }}>
                <colgroup>
                    <col style={{ width: '30%' }} />
                    <col style={{ width: '26%' }} />
                    <col style={{ width: '15%' }} />
                    <col />
                </colgroup>
                <thead>
                    <tr className="bg-gray-700 border-b border-gray-700 text-[10px] font-bold tracking-[0.16em] uppercase text-gray-100">
                        <th className="text-left px-4 py-2.5">File name</th>
                        <th className="text-left px-4 py-2.5">Attachment</th>
                        <th className="text-left px-4 py-2.5">Status</th>
                        <th className="text-left px-4 py-2.5">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <ChecklistRow
                            key={row.key}
                            row={row}
                            onOpenUpload={onOpenUpload}
                            onOpenReplace={onOpenReplace}
                            onDelete={onDelete}
                        />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ChecklistRow({ row, onOpenUpload, onOpenReplace, onDelete }) {
    const docApi = useDocApi();
    const doc = row.doc;
    const isRejected = row.state === 'rejected';
    const hasFile = doc && ! isRejected;
    const viewUrl = doc ? (doc.url || `/admin/documents/${doc.id}/download?inline=1`) : null;

    const dot = isRejected ? 'bg-red-500' : hasFile ? 'bg-emerald-500' : 'bg-gray-300';

    // Staff-set status wins — the tracker mirrors the staff Documents tab.
    // Falls back to the file-based status when staff haven't set one.
    const statusBadge = row.staffStatus ? (
        <span className={`inline-flex items-center px-1.5 py-0.5 text-[9px] font-bold tracking-[0.1em] uppercase border ${STAFF_STATUS_TONE[row.staffStatus] || 'bg-gray-50 text-gray-500 border-gray-200'}`}>
            {STAFF_STATUS_LABEL[row.staffStatus] || row.staffStatus}
        </span>
    ) : hasFile ? (
        <span className={`inline-flex items-center px-1.5 py-0.5 text-[9px] font-bold tracking-[0.1em] uppercase border ${UPLOAD_STATUS_TONE[doc.status] || 'bg-emerald-50 text-emerald-700 border-emerald-200'}`}>
            {UPLOAD_STATUS_LABEL[doc.status] || doc.status}
        </span>
    ) : isRejected ? (
        <span className="inline-flex items-center px-1.5 py-0.5 text-[9px] font-bold tracking-[0.1em] uppercase border bg-red-50 text-red-700 border-red-200">Rejected</span>
    ) : (
        <span className="inline-flex items-center px-1.5 py-0.5 text-[9px] font-bold tracking-[0.1em] uppercase border bg-gray-50 text-gray-500 border-gray-200">Not submitted</span>
    );

    return (
        <tr className="border-t border-gray-50 align-top hover:bg-gray-50/40">
            {/* File name (the document / checklist item) */}
            <td className="px-4 py-3">
                <div className="flex items-start gap-2 min-w-0">
                    <span className={`mt-1 w-2 h-2 rounded-full flex-shrink-0 ${dot}`} />
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-[#282728] flex items-center gap-1.5 flex-wrap">
                            {row.name}
                            {! hasFile && ! isRejected && ! row.required && (
                                <span className="px-1.5 py-0.5 text-[9px] font-bold tracking-wide uppercase bg-gray-50 text-gray-500 border border-gray-200">Optional</span>
                            )}
                        </p>
                        {isRejected && row.rejectedDoc?.note ? (
                            <p className="text-[11px] text-red-700 mt-0.5">Reason: {row.rejectedDoc.note}</p>
                        ) : row.hint ? (
                            <p className="text-[11px] text-gray-500 mt-0.5">{row.hint}</p>
                        ) : null}
                    </div>
                </div>
            </td>

            {/* Attachment — every file uploaded for this item, stacked. */}
            <td className="px-4 py-3">
                {row.docs.length ? (
                    <div className="flex flex-col gap-1.5 min-w-0">
                        {row.docs.map((d) => {
                            const url = docApi.view(d);
                            return (
                                <span key={d.id} className="inline-flex items-center gap-1.5 text-[12px] text-gray-700 min-w-0 max-w-full">
                                    <FileText size={12} className="text-gray-400 flex-shrink-0" />
                                    <a href={url} target="_blank" rel="noopener noreferrer" className="truncate hover:underline" title={d.original_name}>{d.original_name}</a>
                                    {d.is_editable && (
                                        <button type="button" onClick={() => onDelete(d)} title="Remove this file" className="text-gray-300 hover:text-red-600 flex-shrink-0">
                                            <Trash2 size={11} />
                                        </button>
                                    )}
                                </span>
                            );
                        })}
                    </div>
                ) : (
                    <span className="text-[12px] text-gray-400 italic">No file attached</span>
                )}
            </td>

            {/* Status — reflects the latest upload for this item */}
            <td className="px-4 py-3">{statusBadge}</td>

            {/* Actions — add more files (never replaces existing ones) */}
            <td className="px-4 py-3">
                <div className="flex items-center gap-1.5 flex-wrap">
                    {hasFile ? (
                        <button type="button" onClick={() => onOpenUpload(row)} className={trackBtn}>
                            <Plus size={12} /> Upload another
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={() => onOpenUpload(row)}
                            className="inline-flex items-center gap-1 bg-[#282728] text-white text-[11px] font-bold uppercase tracking-wide px-3 py-1.5 hover:bg-black transition-colors"
                        >
                            <Upload size={12} /> {isRejected ? 'Re-upload' : 'Upload'}
                        </button>
                    )}
                </div>
            </td>
        </tr>
    );
}

// ── Requirement rows ───────────────────────────────────────────────────────

function RequirementRow({ row, onOpenUpload, onOpenReplace, onDelete }) {
    const docApi = useDocApi();
    const doc = row.doc;
    const isRejected = row.state === 'rejected';
    const hasFile = doc && ! isRejected;         // an accepted / pending file
    const viewUrl = doc ? (doc.url || `/admin/documents/${doc.id}/download?inline=1`) : null;

    const circle = isRejected ? (
        <span className="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full border-2 border-red-500 bg-red-100 flex-shrink-0">
            <X size={11} className="text-red-700" strokeWidth={3} />
        </span>
    ) : hasFile ? (
        <span className="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full border-2 border-emerald-500 bg-emerald-100 flex-shrink-0">
            <Check size={11} className="text-emerald-700" strokeWidth={3} />
        </span>
    ) : (
        <span className="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full border-2 border-gray-300 border-dashed bg-transparent flex-shrink-0" />
    );

    return (
        <li className="px-3 sm:px-4 py-3 bg-white flex items-start gap-3">
            {circle}
            <div className="flex-1 min-w-0">
                <p className="text-[13px] font-semibold text-[#282728] flex items-center gap-1.5 flex-wrap">
                    {row.name}
                    {hasFile ? (
                        <span className={`px-1.5 py-0.5 text-[9px] font-bold tracking-wide uppercase border ${UPLOAD_STATUS_TONE[doc.status] || 'bg-emerald-50 text-emerald-700 border-emerald-200'}`}>
                            {UPLOAD_STATUS_LABEL[doc.status] || doc.status}
                        </span>
                    ) : isRejected ? (
                        <span className="px-1.5 py-0.5 text-[9px] font-bold tracking-wide uppercase bg-red-50 text-red-700 border border-red-200">
                            Needs re-upload
                        </span>
                    ) : row.required ? (
                        <span className="px-1.5 py-0.5 text-[9px] font-bold tracking-wide uppercase bg-red-50 text-red-700 border border-red-200">
                            Required
                        </span>
                    ) : (
                        <span className="px-1.5 py-0.5 text-[9px] font-bold tracking-wide uppercase bg-gray-50 text-gray-500 border border-gray-200">
                            Optional
                        </span>
                    )}
                </p>
                {isRejected && row.rejectedDoc?.note ? (
                    <p className="text-[11px] text-red-700 mt-1">
                        Reason: {row.rejectedDoc.note}
                    </p>
                ) : row.hint ? (
                    <p className="text-[11px] text-gray-500 mt-0.5">{row.hint}</p>
                ) : null}

                {/* Every uploaded file stays here under its checklist row — a new
                    upload is added to the list, it never replaces the first. */}
                {row.docs.length > 0 && (
                    <div className="mt-2 space-y-1.5">
                        {row.docs.map((d) => {
                            const url = docApi.view(d);
                            return (
                                <div key={d.id} className="flex items-center gap-2 border border-gray-100 bg-gray-50/70 px-2.5 py-1.5">
                                    <FileText size={13} className="text-gray-400 flex-shrink-0" />
                                    <span className="text-[12px] text-[#282728] truncate flex-1 min-w-0" title={d.original_name}>
                                        {d.original_name}
                                    </span>
                                    <a href={url} target="_blank" rel="noopener noreferrer" title="View"
                                        className="inline-flex items-center justify-center p-1 border border-gray-200 bg-white text-gray-600 hover:text-[#282728] hover:bg-gray-50">
                                        <Eye size={11} />
                                    </a>
                                    {d.is_editable && (
                                        <button type="button" onClick={() => onDelete(d)} title="Remove"
                                            className="inline-flex items-center justify-center p-1 border border-gray-200 bg-white text-gray-600 hover:text-red-600 hover:bg-gray-50">
                                            <X size={11} />
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                        {hasFile && (
                            <button type="button" onClick={() => onOpenUpload(row)}
                                className="text-[10px] font-bold uppercase tracking-wide inline-flex items-center gap-1 text-gray-600 border border-gray-200 bg-white px-2.5 py-1 hover:bg-gray-50">
                                <Plus size={11} /> Upload another
                            </button>
                        )}
                    </div>
                )}
            </div>

            {/* Upload / Re-upload prompt — only while nothing is accepted yet. */}
            {! hasFile && (
                <button
                    type="button"
                    onClick={() => onOpenUpload(row)}
                    className="text-[10px] font-bold uppercase tracking-wide inline-flex items-center gap-1 bg-[#282728] text-white px-3 py-1.5 hover:bg-black transition-colors flex-shrink-0"
                >
                    <Upload size={11} /> {isRejected ? 'Re-upload' : 'Upload'}
                </button>
            )}
        </li>
    );
}

// ── Agreement card row (with View + Sign) ──────────────────────────────────

function AgreementCardRow({ agreement: a }) {
    const isSigned = a.status === 'signed';
    return (
        <li className={`flex items-start justify-between gap-3 px-4 py-3 border ${
            isSigned ? 'border-gray-100 bg-gray-50/60' : 'border-gray-200 bg-white'
        }`}>
            <div className="flex items-start gap-3 min-w-0">
                <div className="w-9 h-9 bg-gray-50 border border-gray-100 flex items-center justify-center flex-shrink-0">
                    <FileText size={15} className={isSigned ? 'text-emerald-600' : 'text-gray-400'} strokeWidth={2} />
                </div>
                <div className="min-w-0">
                    <p className={`text-[13px] font-semibold ${isSigned ? 'text-gray-600' : 'text-[#282728]'}`}>
                        {a.title}
                    </p>
                    <p className="text-[11px] text-gray-500 mt-0.5">
                        {isSigned
                            ? <>Signed {formatStamp(a.signed_at)}</>
                            : <>Sent {formatStamp(a.sent_at)} · Awaiting your signature</>}
                    </p>
                </div>
            </div>
            <div className="flex items-center gap-2 flex-shrink-0">
                {a.view_url && (
                    <a
                        href={a.view_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-[11px] font-semibold text-[#282728] inline-flex items-center gap-1 hover:underline"
                    >
                        <Eye size={11} strokeWidth={2} /> View
                    </a>
                )}
                {! isSigned && a.sign_url && (
                    <a
                        href={a.sign_url}
                        className="text-[10px] font-bold uppercase tracking-wide inline-flex items-center gap-1 bg-[#282728] text-white px-3 py-1.5 hover:bg-black transition-colors"
                    >
                        Sign →
                    </a>
                )}
            </div>
        </li>
    );
}
/**
 * A staff-shared / generated document the client can view / download —
 * and, for the Written Agreement, e-sign. Streams through the code-gated
 * tracker routes.
 */
function SharedDocRow({ doc: d }) {
    const [signing, setSigning] = useState(false);
    const kb = d.size ? (d.size < 1024 * 1024
        ? `${Math.max(1, Math.round(d.size / 1024))} KB`
        : `${(d.size / (1024 * 1024)).toFixed(1)} MB`) : null;

    return (
        <li className="px-4 py-3 border border-gray-200 bg-white">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-start gap-3 min-w-0">
                    <div className="w-9 h-9 bg-gray-50 border border-gray-100 flex items-center justify-center flex-shrink-0">
                        <FileText size={15} className={d.signed ? 'text-emerald-600' : 'text-gray-400'} strokeWidth={2} />
                    </div>
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-[#282728] truncate">{d.title}</p>
                        <p className="text-[11px] text-gray-500 mt-0.5">
                            {formatStamp(d.created_at)}{kb ? ` · ${kb}` : ''}
                            {d.signable && (d.signed
                                ? <span className="text-emerald-600 font-semibold"> · Signed {formatStamp(d.signed_at)}</span>
                                : <span className="text-amber-600 font-semibold"> · Awaiting your signature</span>)}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                    {d.view_url && (
                        <a
                            href={d.view_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[11px] font-semibold text-[#282728] inline-flex items-center gap-1 hover:underline"
                        >
                            <Eye size={12} strokeWidth={2} /> View
                        </a>
                    )}
                    <a
                        href={d.download_url}
                        className="text-[11px] font-semibold text-[#282728] inline-flex items-center gap-1 hover:underline"
                    >
                        <Download size={12} strokeWidth={2} /> Download
                    </a>
                    {d.signable && ! d.signed && (
                        <button
                            onClick={() => setSigning(true)}
                            className="text-[10px] font-bold uppercase tracking-wide inline-flex items-center gap-1.5 bg-[#282728] text-white px-3 py-1.5 hover:bg-black transition-colors"
                        >
                            <PenTool size={11} strokeWidth={2.2} /> Sign
                        </button>
                    )}
                    {d.signable && d.signed && (
                        <span className="text-[10px] font-bold uppercase tracking-wide inline-flex items-center gap-1.5 text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-1.5">
                            <Check size={11} strokeWidth={2.5} /> Signed
                        </span>
                    )}
                </div>
            </div>

            {signing && (
                <ClientSignModal doc={d} onClose={() => setSigning(false)} />
            )}
        </li>
    );
}

/**
 * Client signing modal for the Written Agreement — draw a signature on the
 * canvas, watch it land on the document live, then submit.
 */
function ClientSignModal({ doc: d, onClose }) {
    const [submitting, setSubmitting] = useState(false);
    const [hasDrawing, setHasDrawing] = useState(false);
    // Terms acknowledgment lives in the sign panel now — the checkbox
    // in the PDF body is a mirror only. Client must tick this before
    // the Sign button unlocks.
    const [acknowledged, setAcknowledged] = useState(false);

    const canvasRef = useRef(null);
    const iframeRef = useRef(null);
    const drawing = useRef(false);
    const last = useRef({ x: 0, y: 0 });

    useEffect(() => {
        const c = canvasRef.current;
        if (! c) return;
        const ctx = c.getContext('2d');
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
    }, []);

    // Push the current signature into the live document preview so it
    // appears on the agreement's signature line in real time.
    const pushSignature = (dataUrl) => {
        iframeRef.current?.contentWindow?.postMessage(
            { type: 'applicant-signature', value: dataUrl || '' },
            '*'
        );
    };
    // Mirror the ack tick into the preview's checkbox glyph so the client
    // sees the doc update as they toggle.
    const pushAck = (value) => {
        iframeRef.current?.contentWindow?.postMessage(
            { type: 'acknowledged', value: !! value },
            '*'
        );
    };
    // Re-push signature + ack when the iframe finishes loading so the
    // preview reflects current UI state (matters after a src swap).
    const onIframeLoad = () => {
        // Give the iframe's inline script a tick to attach its listener.
        setTimeout(() => {
            if (hasDrawing) pushSignature(canvasRef.current?.toDataURL('image/png'));
            pushAck(acknowledged);
        }, 60);
    };

    const pos = (e) => {
        const r = canvasRef.current.getBoundingClientRect();
        const cx = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
        const cy = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
        return { x: cx * (canvasRef.current.width / r.width), y: cy * (canvasRef.current.height / r.height) };
    };
    const start = (e) => { e.preventDefault(); drawing.current = true; last.current = pos(e); };
    const move = (e) => {
        if (! drawing.current) return;
        e.preventDefault();
        const ctx = canvasRef.current.getContext('2d');
        const p = pos(e);
        ctx.beginPath(); ctx.moveTo(last.current.x, last.current.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        last.current = p; setHasDrawing(true);
    };
    const end = () => {
        if (! drawing.current) return;
        drawing.current = false;
        // Mirror the just-drawn stroke onto the live document preview.
        pushSignature(canvasRef.current.toDataURL('image/png'));
    };
    const clear = () => {
        const c = canvasRef.current;
        c.getContext('2d').clearRect(0, 0, c.width, c.height);
        setHasDrawing(false);
        pushSignature('');
    };

    const submit = () => {
        setSubmitting(true);
        router.post(d.sign_url, {
            signature_data: canvasRef.current.toDataURL('image/png'),
            acknowledged: acknowledged ? 1 : 0,
        }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => setSubmitting(false),
            onFinish: () => setSubmitting(false),
        });
    };

    const canSubmit = hasDrawing && acknowledged;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50" onClick={onClose}>
            <div className="bg-white w-full max-w-[1400px] h-[94vh] shadow-xl flex flex-col overflow-hidden rounded-lg" onClick={(e) => e.stopPropagation()}>
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                    <h3 className="text-sm font-bold text-[#282728] flex items-center gap-2">
                        <PenTool size={15} /> Sign {d.title}
                    </h3>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X size={18} /></button>
                </div>

                <div className="flex-1 flex flex-col sm:flex-row min-h-0">
                    {/* Live document preview (left column) */}
                    <div className="flex-1 min-w-0 min-h-[240px] bg-gray-100 border-b sm:border-b-0 sm:border-r border-gray-100">
                        {(d.preview_url || d.view_url) ? (
                            <iframe
                                ref={iframeRef}
                                src={d.preview_url || `${d.view_url}#toolbar=1&view=FitH`}
                                title="Document preview"
                                className="w-full h-full bg-white"
                                onLoad={onIframeLoad}
                            />
                        ) : (
                            <div className="w-full h-full flex items-center justify-center text-xs text-gray-400">
                                Preview unavailable
                            </div>
                        )}
                    </div>

                    {/* Signature capture (right column) */}
                    <div className="sm:w-[480px] sm:flex-none p-4 sm:p-6 overflow-y-auto">
                        <p className="text-[12px] text-gray-500 mb-3">
                            Review the agreement on the left, then draw your signature below. By signing you confirm you have read and agree to its terms.
                        </p>
                        <p className="text-[10px] font-bold tracking-[0.14em] uppercase text-gray-400 mb-2 flex items-center gap-1.5">
                            <PenTool size={12} /> Draw your signature
                        </p>
                        <div className="border-2 border-dashed border-gray-200 bg-white">
                            <canvas
                                ref={canvasRef}
                                width={430} height={300}
                                className="w-full h-[300px] touch-none cursor-crosshair block"
                                onMouseDown={start} onMouseMove={move} onMouseUp={end} onMouseLeave={end}
                                onTouchStart={start} onTouchMove={move} onTouchEnd={end}
                            />
                        </div>
                        <button onClick={clear} className="mt-2 text-[12px] font-semibold text-gray-500 hover:text-gray-700 inline-flex items-center gap-1.5">
                            <Eraser size={13} /> Clear
                        </button>

                        {/* Terms acknowledgment — moved here from the
                            static PDF checkbox. Ticking mirrors into
                            the preview's ack box in real time. */}
                        <label
                            className={`mt-5 flex items-start gap-2.5 p-3 rounded-lg border transition-colors cursor-pointer ${
                                acknowledged
                                    ? 'border-emerald-300 bg-emerald-50'
                                    : 'border-gray-200 bg-white hover:border-gray-300'
                            }`}
                        >
                            <button
                                type="button"
                                onClick={() => { const v = ! acknowledged; setAcknowledged(v); pushAck(v); }}
                                className={`mt-0.5 w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 transition-colors ${
                                    acknowledged ? 'bg-emerald-600 border-emerald-600' : 'bg-white border-gray-400'
                                }`}
                                aria-checked={acknowledged}
                                role="checkbox"
                            >
                                {acknowledged && <Check size={11} className="text-white" strokeWidth={3} />}
                            </button>
                            <span
                                className="text-[12px] text-gray-700 leading-snug select-none"
                                onClick={(e) => { e.preventDefault(); const v = ! acknowledged; setAcknowledged(v); pushAck(v); }}
                            >
                                I have read and agreed to the <span className="font-semibold">{d.title}</span> terms.
                            </span>
                        </label>
                    </div>
                </div>

                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-2 flex-shrink-0">
                    <button onClick={onClose} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800">Cancel</button>
                    <button
                        onClick={submit}
                        disabled={! canSubmit || submitting}
                        className="px-4 py-2 bg-[#282728] text-white text-sm font-semibold hover:bg-black disabled:opacity-40 inline-flex items-center gap-2"
                    >
                        {submitting ? 'Signing…' : 'Sign agreement'}
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Parse a "Section · Item Name" checklist label into its two halves. The
 * seeder embeds the section in the label this way because the underlying
 * schema is flat — we recover the grouping at render time.
 */
function parseChecklistLabel(label) {
    const raw = String(label || '');
    const idx = raw.indexOf(' · ');
    if (idx === -1) return { section: 'General', name: raw };
    return { section: raw.slice(0, idx), name: raw.slice(idx + 3) };
}

/**
 * Group checklist items by their parsed section, preserving the order in
 * which sections first appear. Each item is tagged with whether the lead
 * has already uploaded a doc against that key. Section order matters
 * because the seeder writes the items in the intended display order.
 */
function groupChecklistBySection(items, uploadedKeys) {
    const order = [];
    const byKey = new Map();
    for (const it of items) {
        const { section, name } = parseChecklistLabel(it.label);
        if (!byKey.has(section)) {
            byKey.set(section, []);
            order.push(section);
        }
        byKey.get(section).push({
            key: it.key,
            section,
            name,
            label: it.label,
            hint: it.hint || '',
            required: it.required !== false,
            alreadyUploaded: uploadedKeys.has(it.key),
        });
    }
    return order.map(section => ({ section, items: byKey.get(section) }));
}

/**
 * Visa-checklist picker. Renders items grouped by section with a search
 * input (when the list is long), a red asterisk for required items, and a
 * subtle "Uploaded" badge on items that already have a doc attached.
 * Appends an "Other supporting document" fallback at the bottom so leads
 * can still upload things outside the checklist.
 */
function ChecklistPicker({ sections, allEmpty, search, onSearchChange, showSearch, onPick }) {
    const otherFallback = {
        key: 'other',
        label: 'Other supporting document',
        hint: 'Anything outside the checklist we have asked for.',
    };
    return (
        <div>
            {showSearch && (
                <div className="mb-3 flex items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-200 focus-within:border-[#282728] focus-within:bg-white transition-colors">
                    <Search size={13} className="text-gray-400 flex-shrink-0" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Search the checklist…"
                        className="flex-1 bg-transparent text-sm text-[#282728] focus:outline-none placeholder-gray-400"
                    />
                </div>
            )}

            <div className="space-y-4 max-h-[55vh] overflow-y-auto pr-1">
                {allEmpty && (
                    <p className="text-[12px] text-gray-500 text-center py-6">
                        Nothing on your checklist matches that search.
                    </p>
                )}

                {sections.map(({ section, items }) => (
                    <div key={section}>
                        <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">
                            {section}
                        </p>
                        <div className="space-y-1.5">
                            {items.map((it) => (
                                <button
                                    key={it.key}
                                    type="button"
                                    onClick={() => onPick({ key: it.key, label: it.label, hint: it.hint })}
                                    className="w-full text-left flex items-center justify-between gap-3 px-3 py-2.5 border border-gray-200/80 hover:border-[#282728] hover:shadow-sm hover:-translate-y-px transition-all group"
                                >
                                    <div className="flex-1 min-w-0">
                                        <p className="text-[13px] font-semibold text-[#282728] flex items-center gap-1.5">
                                            <span className="truncate">{it.name}</span>
                                            {it.required && (
                                                <span className="text-red-500 text-[11px] font-bold" title="Required">*</span>
                                            )}
                                        </p>
                                        {it.hint && (
                                            <p className="text-[11px] text-gray-500 mt-0.5 truncate">{it.hint}</p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2 flex-shrink-0">
                                        {it.alreadyUploaded && (
                                            <span className="text-[9px] font-bold uppercase tracking-[0.16em] text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-1">
                                                Uploaded
                                            </span>
                                        )}
                                        <ChevronDown
                                            size={14}
                                            strokeWidth={2}
                                            className="text-gray-300 group-hover:text-[#282728] group-hover:translate-x-1 rotate-[-90deg] transition-all"
                                        />
                                    </div>
                                </button>
                            ))}
                        </div>
                    </div>
                ))}

                {/* Always-available fallback for things outside the checklist. */}
                <div>
                    <p className="text-[10px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-2">
                        Other
                    </p>
                    <button
                        type="button"
                        onClick={() => onPick(otherFallback)}
                        className="w-full text-left flex items-center justify-between gap-3 px-3 py-2.5 border border-gray-200/80 hover:border-[#282728] hover:shadow-sm hover:-translate-y-px transition-all group"
                    >
                        <div className="flex-1 min-w-0">
                            <p className="text-[13px] font-semibold text-[#282728]">{otherFallback.label}</p>
                            <p className="text-[11px] text-gray-500 mt-0.5">{otherFallback.hint}</p>
                        </div>
                        <ChevronDown
                            size={14}
                            strokeWidth={2}
                            className="text-gray-300 group-hover:text-[#282728] group-hover:translate-x-1 rotate-[-90deg] flex-shrink-0 transition-all"
                        />
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Modal-driven upload flow. Step 1 = pick a type (visa-specific checklist
 * if available, else hardcoded DOC_TYPES); step 2 = (passport metadata, if
 * applicable) + file + upload button. Backdrop and Esc both close. The
 * previous list-then-form layout inside the panel was confusing because
 * the document type picker fought with the gallery for the same space —
 * moving it into a modal cleans that up.
 */
function DocUploadModal({ open, onClose, code, lead = null, visa = null, documents = [], initialDocType = null }) {
    const docApi = useDocApi();
    const [docType, setDocType] = useState(null);
    const [search, setSearch] = useState('');
    const { data, setData, post, processing, errors, reset } = useForm({
        files: [],
        checklist_key: '',
        passport_number: '',
        passport_expiry: '',
    });

    // When the parent opens the modal with a pre-selected item (e.g. the
    // Visa-tab table row's "Upload" button), skip the picker step and jump
    // straight to the file-chooser body.
    useEffect(() => {
        if (open && initialDocType) {
            setDocType(initialDocType);
            setData('checklist_key', initialDocType.key);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialDocType?.key]);

    // Prefer the visa-specific checklist when the lead is on an immigration
    // case with a known visa type. Otherwise fall back to the hardcoded
    // DOC_TYPES list so non-immigration leads (and immigration leads whose
    // visa type isn't catalogued) still get a working picker.
    const useChecklist = !!(lead?.is_immigration_case && Array.isArray(visa?.checklist) && visa.checklist.length > 0);

    const uploadedKeys = useMemo(() => new Set(
        (documents || []).map(d => d.checklist_key).filter(Boolean)
    ), [documents]);

    const checklistSections = useMemo(
        () => useChecklist ? groupChecklistBySection(visa.checklist, uploadedKeys) : [],
        [useChecklist, visa?.checklist, uploadedKeys]
    );

    const totalChecklistItems = useChecklist
        ? checklistSections.reduce((n, s) => n + s.items.length, 0)
        : 0;
    const showSearch = totalChecklistItems > 10;

    const filteredSections = useMemo(() => {
        if (!useChecklist) return [];
        const q = search.trim().toLowerCase();
        if (!q) return checklistSections;
        return checklistSections
            .map(s => ({
                section: s.section,
                items: s.items.filter(i =>
                    i.name.toLowerCase().includes(q) ||
                    (i.hint || '').toLowerCase().includes(q) ||
                    s.section.toLowerCase().includes(q)
                ),
            }))
            .filter(s => s.items.length > 0);
    }, [useChecklist, checklistSections, search]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    if (!open) return null;

    const close = () => {
        setDocType(null);
        setSearch('');
        reset();
        onClose();
    };

    const pickDocType = (t) => {
        setDocType(t);
        setData('checklist_key', t.key);
    };

    const upload = (e) => {
        e.preventDefault();
        if (!data.files.length || !docType) return;

        post(docApi.uploadUrl(data.checklist_key), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            {/* Backdrop */}
            <div
                onClick={close}
                className="absolute inset-0 bg-[#0c1611]/70 backdrop-blur-sm"
            />

            {/* Modal card */}
            <div className="relative bg-white border border-gray-200 shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                {/* Header */}
                <div className="flex items-start justify-between gap-4 px-6 py-5 border-b border-gray-100">
                    <div>
                        <h3 className="text-lg font-semibold text-[#282728] tracking-tight">
                            {docType ? `Upload · ${docType.label}` : 'Add a document'}
                        </h3>
                        <p className="text-[12px] text-gray-500 mt-1">
                            {docType
                                ? 'Choose the file and we\'ll attach it to your application.'
                                : 'Pick the type of document — we use it to file everything correctly.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={close}
                        className="text-gray-400 hover:text-[#282728] flex-shrink-0"
                        aria-label="Close"
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* Body */}
                <div className="px-6 py-5">
                    {!docType ? (
                        useChecklist ? (
                            <ChecklistPicker
                                sections={filteredSections}
                                allEmpty={filteredSections.length === 0}
                                search={search}
                                onSearchChange={setSearch}
                                showSearch={showSearch}
                                onPick={pickDocType}
                            />
                        ) : (
                            <div className="space-y-2">
                                {DOC_TYPES.map((t) => (
                                    <button
                                        key={t.key}
                                        type="button"
                                        onClick={() => pickDocType(t)}
                                        className="w-full text-left flex items-center justify-between gap-3 px-4 py-3 border border-gray-200/80 hover:border-[#282728] hover:shadow-sm hover:-translate-y-px transition-all group"
                                    >
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[14px] font-semibold text-[#282728]">{t.label}</p>
                                            <p className="text-[11px] text-gray-500 mt-0.5">{t.hint}</p>
                                        </div>
                                        <ChevronDown
                                            size={16}
                                            strokeWidth={2}
                                            className="text-gray-300 group-hover:text-[#282728] group-hover:translate-x-1 rotate-[-90deg] flex-shrink-0 transition-all"
                                        />
                                    </button>
                                ))}
                            </div>
                        )
                    ) : (
                        <form onSubmit={upload} className="space-y-4">
                            {/* Passport-specific fields */}
                            {docType.key === 'passport' && (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 border border-gray-200">
                                    <div>
                                        <label className="block text-[11px] font-semibold text-gray-700 mb-1">
                                            Passport number
                                        </label>
                                        <input
                                            type="text"
                                            value={data.passport_number}
                                            onChange={(e) => setData('passport_number', e.target.value)}
                                            className="w-full bg-transparent border-b border-gray-200 py-1.5 text-sm text-[#282728] focus:outline-none focus:border-[#282728]"
                                            placeholder="e.g. P1234567"
                                        />
                                        {errors.passport_number && (
                                            <p className="text-[10px] text-red-500 mt-0.5">{errors.passport_number}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-[11px] font-semibold text-gray-700 mb-1">
                                            Expiry date
                                        </label>
                                        <input
                                            type="date"
                                            value={data.passport_expiry}
                                            onChange={(e) => setData('passport_expiry', e.target.value)}
                                            className="w-full bg-transparent border-b border-gray-200 py-1.5 text-sm text-[#282728] focus:outline-none focus:border-[#282728]"
                                        />
                                        {errors.passport_expiry && (
                                            <p className="text-[10px] text-red-500 mt-0.5">{errors.passport_expiry}</p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* File picker — supports multiple files per item */}
                            <label className="cursor-pointer block">
                                <div className="flex items-center gap-2 px-3 py-3 bg-gray-50 border border-gray-200 hover:bg-gray-100 transition-colors text-sm text-gray-600">
                                    <Upload size={14} className="text-gray-400" />
                                    <span className="flex-1 truncate">
                                        {data.files.length
                                            ? `${data.files.length} file${data.files.length === 1 ? '' : 's'} selected · ${fmtBytes(data.files.reduce((s, f) => s + (f.size || 0), 0))} total`
                                            : 'Choose file(s)…'}
                                    </span>
                                </div>
                                <input
                                    type="file"
                                    multiple
                                    className="hidden"
                                    accept={(docType?.key || '').toLowerCase().includes('face') ? '.jpg,.jpeg' : '.pdf'}
                                    onChange={(e) => setData('files', [...data.files, ...Array.from(e.target.files || [])].slice(0, 10))}
                                />
                            </label>
                            {data.files.length > 0 && (
                                <ul className="mt-2 space-y-1">
                                    {data.files.map((f, i) => (
                                        <li key={i} className="flex items-center gap-2 text-[12px] text-gray-600 bg-gray-50 px-2.5 py-1.5 border border-gray-100">
                                            <span className="flex-1 truncate">{f.name}</span>
                                            <span className="text-[11px] text-gray-400 tabular-nums flex-shrink-0">{fmtBytes(f.size)}</span>
                                            <button
                                                type="button"
                                                onClick={() => setData('files', data.files.filter((_, j) => j !== i))}
                                                className="text-gray-400 hover:text-red-500 flex-shrink-0"
                                                aria-label="Remove file"
                                            >
                                                <X size={13} />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="mt-1 text-[11px] text-gray-400">
                                {(docType?.key || '').toLowerCase().includes('face')
                                    ? 'Upload the face image as a JPEG (.jpg) file.'
                                    : 'Upload this document as a PDF (.pdf) file.'}
                                {' '}Up to 10 files (max 50&nbsp;MB each).
                            </p>
                            {(errors.files || errors['files.0']) && (
                                <p className="text-[11px] text-red-500">{errors.files || errors['files.0']}</p>
                            )}
                        </form>
                    )}
                </div>

                {/* Footer */}
                {docType && (
                    <div className="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50">
                        <button
                            type="button"
                            onClick={() => { setDocType(null); reset(); }}
                            disabled={processing}
                            className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 hover:text-[#282728] flex items-center gap-1.5"
                        >
                            <ArrowRight size={12} className="rotate-180" /> Change type
                        </button>
                        <button
                            type="button"
                            onClick={upload}
                            disabled={!data.files.length || processing}
                            className="px-5 py-2.5 bg-[#282728] text-white text-[11px] font-bold uppercase tracking-[0.22em] hover:bg-black transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2"
                        >
                            <Upload size={12} /> {processing ? 'Uploading…' : 'Upload'}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Premium gallery card for a single uploaded document.
 *
 * Image docs (jpg/png) render their actual preview from the public
 * storage URL; everything else renders a quiet file-type label. The
 * whole card is a link to the file so a click opens it in a new tab.
 */
/**
 * Edit-existing-document modal. Lets the lead swap the file and/or change
 * the document type while the doc is still in Submitted state.
 */
function DocEditModal({ code, doc, onClose }) {
    const docApi = useDocApi();
    const { data, setData, post, processing, errors, reset } = useForm({
        file: null,
        checklist_key: '',
    });

    useEffect(() => {
        if (!doc) return;
        setData({ file: null, checklist_key: doc.checklist_key || '' });
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [doc?.id]);

    if (!doc) return null;

    const close = () => { reset(); onClose(); };

    const submit = (e) => {
        e.preventDefault();
        post(docApi.replaceUrl(doc.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={close} className="absolute inset-0 bg-[#0c1611]/70 backdrop-blur-sm" />
            <div className="relative bg-white border border-gray-200 shadow-2xl w-full max-w-lg">
                <div className="flex items-start justify-between gap-4 px-6 py-5 border-b border-gray-100">
                    <div>
                        <h3 className="text-lg font-semibold text-[#282728] tracking-tight">
                            Edit document
                        </h3>
                        <p className="text-[12px] text-gray-500 mt-1 truncate" title={doc.original_name}>
                            {doc.original_name}
                        </p>
                    </div>
                    <button type="button" onClick={close} className="text-gray-400 hover:text-[#282728] flex-shrink-0" aria-label="Close">
                        <X size={18} />
                    </button>
                </div>

                <form onSubmit={submit} className="px-6 py-5 space-y-4">
                    <div>
                        <label className="block text-[11px] font-semibold text-gray-700 mb-1.5">
                            Replace file <span className="text-gray-400 font-normal">(leave blank to keep the existing one)</span>
                        </label>
                        <label className="cursor-pointer block">
                            <div className="flex items-center gap-2 px-3 py-3 bg-gray-50 border border-gray-200 hover:bg-gray-100 transition-colors text-sm text-gray-600">
                                <Upload size={14} className="text-gray-400" />
                                <span className="flex-1 truncate">
                                    {data.file ? data.file.name : 'Choose a replacement file…'}
                                </span>
                            </div>
                            <input
                                type="file"
                                className="hidden"
                                accept=".pdf,.doc,.docx,.xls,.csv,.jpg,.jpeg,.png,.gif"
                                onChange={(e) => setData('file', e.target.files?.[0] || null)}
                            />
                        </label>
                        {errors.file && <p className="text-[11px] text-red-500 mt-1">{errors.file}</p>}
                    </div>
                </form>

                <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50">
                    <button
                        type="button"
                        onClick={close}
                        disabled={processing}
                        className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 hover:text-[#282728] px-3 py-2"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={processing}
                        className="px-5 py-2.5 bg-[#282728] text-white text-[11px] font-bold uppercase tracking-[0.22em] hover:bg-black transition-colors disabled:opacity-40 flex items-center gap-2"
                    >
                        <Save size={12} /> {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Hard-delete confirmation. Same modal shell — single dangerous CTA on
 * the right, Cancel on the left.
 */
function DocDeleteModal({ code, doc, onClose }) {
    const docApi = useDocApi();
    const { delete: destroy, processing } = useForm();

    useEffect(() => {
        if (!doc) return;
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [doc, onClose]);

    if (!doc) return null;

    const confirm = () => {
        destroy(docApi.itemUrl(doc.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onClose} className="absolute inset-0 bg-[#0c1611]/70 backdrop-blur-sm" />
            <div className="relative bg-white border border-gray-200 shadow-2xl w-full max-w-md">
                <div className="px-6 py-5 border-b border-gray-100">
                    <h3 className="text-lg font-semibold text-[#282728] tracking-tight">
                        Remove this document?
                    </h3>
                    <p className="text-[13px] text-gray-500 mt-1.5 leading-relaxed">
                        <span className="font-semibold text-[#282728]">{doc.original_name}</span> will
                        be permanently removed from your application. You can re-upload a new file at any time.
                    </p>
                </div>
                <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={processing}
                        className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 hover:text-[#282728] px-3 py-2"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={confirm}
                        disabled={processing}
                        className="px-5 py-2.5 bg-red-600 text-white text-[11px] font-bold uppercase tracking-[0.22em] hover:bg-red-700 transition-colors disabled:opacity-40 flex items-center gap-2"
                    >
                        <Trash2 size={12} /> {processing ? 'Removing…' : 'Remove'}
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Reference-style progress tracker — Completed / In Progress / Pending
 * states, horizontal rail with a filled progress line, simple state
 * indicators (no per-step icons). Backend ships the canonical list of
 * steps; this component just paints them.
 */
function TimelinePanel({ timeline = [], currentStage }) {
    // Backend now returns the canonical roadmap left→right with
    // status per step — no client-side reversing or guessing.
    const steps = timeline;
    const total = steps.length;
    const currentIndex = Math.max(0, steps.findIndex((s) => s.status === 'current'));
    const completedCount = steps.filter((s) => s.status === 'completed').length;
    // Index of the first alternative-outcome step (declined, etc.) —
    // -1 if there isn't one. The progress line splits at this point:
    // the segment leading into the alternative is rendered dashed.
    const altIndex = steps.findIndex((s) => s.alternative);
    const hasAlternative = altIndex >= 0;
    // Fill stops *at* the current node (or the alternative, when there
    // is no in-progress step because the case has terminated).
    const stopIndex = currentIndex > 0
        ? currentIndex
        : (hasAlternative ? altIndex : completedCount - 1);
    const fillPct = total <= 1 ? 0 : (Math.max(0, stopIndex) / (total - 1)) * 100;
    // Percent position of the alternative step, used as the boundary
    // where the dashed segment starts.
    const altPct = hasAlternative && total > 1 ? (altIndex / (total - 1)) * 100 : 100;

    return (
        <PanelShell
            title="Your Journey"
            subtitle={
                <span>
                    Current stage ·{' '}
                    <span className="font-semibold text-[#282728]">
                        {currentStage || 'Not set'}
                    </span>
                    {total > 0 && (
                        <span className="text-gray-400">
                            {' '}— {completedCount} of {total} completed
                        </span>
                    )}
                </span>
            }
        >
            {total === 0 ? (
                <div className="text-center py-12">
                    <p className="text-sm text-gray-400">
                        Your application is in our pipeline — no milestones yet. Check back soon.
                    </p>
                </div>
            ) : (
                <>
                    <div className="overflow-x-auto -mx-2 px-2 pt-2 pb-1">
                        <div className="relative min-w-max md:min-w-0">
                            {/* Background track — solid section runs up
                                to (but not into) the alternative step;
                                dashed segment runs from there to the end
                                when an alternative outcome exists. */}
                            <div
                                className="absolute top-[14px] h-[2px] bg-gray-200 mx-[7%]"
                                style={hasAlternative
                                    ? { left: 0, right: `${100 - altPct}%`, marginRight: 0 }
                                    : { left: 0, right: 0 }}
                            />
                            {hasAlternative && (
                                <div
                                    className="absolute top-[12px] mx-[7%] border-t-[2px] border-dashed border-gray-300"
                                    style={{ left: `${altPct}%`, right: 0, marginLeft: 0 }}
                                />
                            )}
                            {/* Filled progress — charcoal up to the
                                current / stop index. Also flips to dashed
                                if the stop sits past the alternative
                                boundary. */}
                            <div
                                className="absolute top-[14px] h-[2px] bg-[#282728] mx-[7%] transition-all duration-700"
                                style={{ left: 0, right: `${100 - fillPct}%`, marginRight: 0 }}
                            />

                            <ol className="relative flex items-start gap-3">
                                {steps.map((step, i) => (
                                    <JourneyStep key={step.key} step={step} index={i} />
                                ))}
                            </ol>
                        </div>
                    </div>
                    {hasAlternative && (
                        <p className="text-[10px] text-gray-400 text-center mt-3">
                            Solid line = standard progression · Dashed line = alternative outcome
                        </p>
                    )}
                </>
            )}
        </PanelShell>
    );
}

function JourneyStep({ step, index }) {
    const isAlt = !!step.alternative;
    return (
        <li className="relative flex flex-col items-center text-center flex-1 min-w-[140px] md:min-w-0 px-1.5">
            <StatusDot status={step.status} alternative={isAlt} />

            <p className="text-[11px] font-medium text-gray-500 mt-3 mb-0.5">
                {isAlt ? 'Outcome' : `Step ${index + 1}`}
            </p>
            <h4 className={`text-[14px] font-bold leading-tight mb-1 ${
                isAlt
                    ? 'text-rose-700'
                    : step.status === 'pending'
                        ? 'text-gray-400'
                        : 'text-[#282728]'
            }`}>
                {step.label}
            </h4>
            <StatusLabel status={step.status} alternative={isAlt} />
            {step.description && (
                <p className="text-[11px] text-gray-500 leading-snug mt-1.5 max-w-[180px] mx-auto">
                    {step.description}
                </p>
            )}
            {step.at && (
                <time className="block text-[10px] text-gray-400 tracking-wider mt-1">
                    {formatStamp(step.at)}
                </time>
            )}
        </li>
    );
}

function StatusDot({ status, alternative = false }) {
    // Alternative-outcome step (e.g. "Application declined") — rose-tinted
    // ring + white X-circle so it reads as a branch, not a continuation.
    if (alternative) {
        return (
            <span className="relative inline-flex w-7 h-7 rounded-full bg-rose-600 items-center justify-center ring-[6px] ring-white shadow-sm">
                <AlertCircle size={14} className="text-white" strokeWidth={2.5} />
            </span>
        );
    }
    if (status === 'completed') {
        // Solid charcoal with white check — "this is done"
        return (
            <span className="relative inline-flex w-7 h-7 rounded-full bg-[#282728] items-center justify-center ring-[6px] ring-white shadow-sm">
                <CheckCircle2 size={14} className="text-white" strokeWidth={3} />
            </span>
        );
    }
    if (status === 'current') {
        // Outer charcoal ring with a smaller charcoal dot inside —
        // the "target / bullseye" idiom from the reference. A soft ping
        // ring fades outward as a quiet "you are here" beacon.
        return (
            <span className="relative inline-flex w-7 h-7 items-center justify-center">
                <span className="absolute inset-[-6px] rounded-full bg-[#282728]/15 animate-ping" />
                <span className="relative inline-flex w-7 h-7 rounded-full border-[3px] border-[#282728] bg-white items-center justify-center ring-[6px] ring-white shadow-sm">
                    <span className="w-2.5 h-2.5 rounded-full bg-[#282728]" />
                </span>
            </span>
        );
    }
    // Pending — empty light ring with a small gray dot
    return (
        <span className="relative inline-flex w-7 h-7 rounded-full bg-gray-200 items-center justify-center ring-[6px] ring-white">
            <span className="w-1.5 h-1.5 rounded-full bg-white" />
        </span>
    );
}

function StatusLabel({ status, alternative = false }) {
    if (alternative) {
        return (
            <span className="text-[11px] font-semibold text-rose-700">
                Declined
            </span>
        );
    }
    if (status === 'completed') {
        return (
            <span className="text-[11px] font-semibold text-emerald-600">
                Completed
            </span>
        );
    }
    if (status === 'current') {
        return (
            <span className="text-[11px] font-semibold text-[#282728]">
                In Progress
            </span>
        );
    }
    return (
        <span className="text-[11px] font-medium text-gray-400">
            Pending
        </span>
    );
}

// ── Proposal Shortlist ──────────────────────────────────────────────────
// Staff has picked up to 3 programs on the internal Proposal & Agreements
// page. The lead sees them here as cards and can pick one — the pick is
// saved via POST /track/{code}/choose-program and flows back through the
// preferred_program_id column on leads.
//
// The card whose id matches `preferred_program_id` gets the "Your choice"
// badge + darker treatment; "Change" clears the pick server-side so the
// lead can re-choose without any confirm modal (it's their preference,
// low stakes).
function ProposalShortlist({ proposal, code }) {
    const [savingId, setSavingId] = useState(null);
    // Which program's details modal is open. Reading the full write-up should
    // not cost the client their place on the tracker, so it opens over the
    // page rather than navigating off to the public program page.
    const [detailId, setDetailId] = useState(null);
    const chosenId = proposal.preferred_program_id;

    const submit = (programId) => {
        setSavingId(programId ?? -1);
        router.post(`/track/${code}/choose-program`, { program_id: programId }, {
            preserveScroll: true,
            onFinish: () => setSavingId(null),
        });
    };

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-6 py-5 border-b border-gray-100 flex items-start justify-between gap-4 bg-gradient-to-br from-gray-50 to-white">
                <div className="flex items-center gap-3 min-w-0">
                    <div className="w-9 h-9 rounded-lg bg-gray-100 text-gray-800 flex items-center justify-center shrink-0">
                        <Sparkles size={16} strokeWidth={2.25} />
                    </div>
                    <div className="min-w-0">
                        <div className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-0.5">
                            Suggested for you
                        </div>
                        <h2 className="text-base font-bold text-gray-900 tracking-tight">
                            {chosenId ? 'Your chosen study path' : 'Programs your adviser prepared'}
                        </h2>
                        <p className="text-[12px] text-gray-500 mt-0.5 leading-relaxed">
                            {chosenId
                                ? 'You can switch to another option at any time — your adviser follows up on the one you\'ve chosen.'
                                : 'Pick the one you\'d like to pursue. Your adviser will move ahead with your choice.'}
                        </p>
                    </div>
                </div>
                <div className="text-right shrink-0 hidden sm:block">
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold tabular-nums bg-gray-100 text-gray-700">
                        {proposal.programs.length} option{proposal.programs.length === 1 ? '' : 's'}
                    </span>
                </div>
            </div>

            <div className="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {proposal.programs.map((p) => {
                    const isChosen = chosenId === p.id;
                    const isSaving = savingId === p.id;
                    return (
                        <article
                            key={p.id}
                            className={`relative flex flex-col rounded-xl border shadow-sm transition-all overflow-hidden ${
                                isChosen
                                    ? 'border-emerald-500 ring-2 ring-emerald-500/25 bg-emerald-50'
                                    : 'border-gray-100 bg-white hover:border-gray-300 hover:shadow-md'
                            }`}
                        >
                            {isChosen && (
                                <span className="absolute top-3 right-3 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600 text-white text-[9px] font-bold uppercase tracking-[0.14em] shadow-sm">
                                    <Check size={10} strokeWidth={3} /> Chosen
                                </span>
                            )}
                            {p.image_url ? (
                                <div className="h-28 w-full overflow-hidden bg-gray-100">
                                    <img src={p.image_url} alt="" className="w-full h-full object-cover" />
                                </div>
                            ) : (
                                <div className="h-28 w-full bg-gradient-to-br from-gray-100 via-gray-50 to-white flex items-center justify-center">
                                    <GraduationCap size={30} className="text-gray-400" />
                                </div>
                            )}
                            <div className="p-4 flex-1 flex flex-col">
                                <div className="flex items-center gap-1.5 mb-2">
                                    {p.level != null && (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-gray-100 text-gray-800 border border-gray-200">
                                            Level {p.level}
                                        </span>
                                    )}
                                    {p.category && (
                                        <span className="text-[10px] font-medium text-gray-500 capitalize">{p.category}</span>
                                    )}
                                </div>
                                <h3 className="text-[14px] font-bold text-gray-900 leading-snug mb-2">{p.title}</h3>
                                <ul className="text-[12px] text-gray-600 space-y-1 mb-3">
                                    {p.location && (
                                        <li className="inline-flex items-center gap-1.5">
                                            <MapPin size={11} className="text-gray-400" /> {p.location}
                                        </li>
                                    )}
                                    {p.duration_months && (
                                        <li className="inline-flex items-center gap-1.5">
                                            <Clock size={11} className="text-gray-400" /> {p.duration_months} months
                                        </li>
                                    )}
                                    {p.intake_months && (
                                        <li className="inline-flex items-center gap-1.5">
                                            <Award size={11} className="text-gray-400" /> Intake: {p.intake_months}
                                        </li>
                                    )}
                                </ul>
                                {p.price_text && (
                                    <p className="text-[11px] font-semibold text-gray-700 mb-3">{p.price_text}</p>
                                )}
                                {p.reason && (
                                    <div className="mb-3 rounded-lg border border-emerald-100 bg-emerald-50/70 px-3 py-2">
                                        <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-700 mb-0.5">Why we suggested this</p>
                                        <p className="text-[12px] text-emerald-900 leading-relaxed">{p.reason}</p>
                                    </div>
                                )}
                                <div className="mt-auto flex items-center gap-2">
                                    {isChosen ? (
                                        <button
                                            type="button"
                                            onClick={() => submit(null)}
                                            disabled={isSaving || savingId === -1}
                                            className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 bg-white text-[11px] font-bold text-gray-700 hover:border-gray-400 hover:text-gray-900 hover:bg-gray-50 transition-colors disabled:opacity-50"
                                        >
                                            {savingId === -1 ? 'Clearing…' : 'Change'}
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => submit(p.id)}
                                            disabled={isSaving}
                                            className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-gray-900 text-white text-[11px] font-bold hover:bg-black transition-colors disabled:opacity-60 shadow-sm"
                                        >
                                            {isSaving ? 'Saving…' : (<>Choose this <ArrowRight size={11} strokeWidth={2.5} /></>)}
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => setDetailId(p.id)}
                                        className="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 text-gray-500 hover:text-gray-900 hover:border-gray-400 hover:bg-gray-50 transition-colors"
                                        title="See full details"
                                        aria-label={`See full details for ${p.title}`}
                                    >
                                        <Eye size={12} />
                                    </button>
                                </div>
                            </div>
                        </article>
                    );
                })}
            </div>

            {/* Previously suggested — earlier proposals kept for reference,
                read-only. The program the client had chosen at the time (if
                any) stays highlighted. */}
            {Array.isArray(proposal.previous) && proposal.previous.length > 0 && (
                <div className="border-t border-gray-100 px-4 sm:px-5 py-4">
                    <details className="group/prev">
                        <summary className="cursor-pointer list-none inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-gray-400 hover:text-gray-600 select-none">
                            <ChevronDown size={13} className="-rotate-90 transition-transform group-open/prev:rotate-0" />
                            Previously suggested ({proposal.previous.length})
                        </summary>
                        <div className="mt-3 space-y-4">
                            {proposal.previous.map((v) => (
                                <div key={v.id} className="rounded-xl border border-gray-100 bg-gray-50/40 p-3">
                                    {v.created_at && (
                                        <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">
                                            {new Date(v.created_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}
                                        </div>
                                    )}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        {v.programs.map((p) => {
                                            const wasChosen = v.selected_program_id === p.id;
                                            return (
                                                <button
                                                    key={p.id}
                                                    type="button"
                                                    onClick={() => setDetailId(p.id)}
                                                    title={`See full details for ${p.title}`}
                                                    className={`flex w-full items-center gap-2 rounded-lg border px-2.5 py-2 text-left transition-colors hover:border-gray-300 hover:shadow-sm ${wasChosen ? 'border-emerald-300 bg-emerald-50' : 'border-gray-100 bg-white'}`}
                                                >
                                                    {p.level != null && (
                                                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider shrink-0 ${wasChosen ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 border border-gray-200'}`}>
                                                            Level {p.level}
                                                        </span>
                                                    )}
                                                    <span className={`text-[12px] truncate ${wasChosen ? 'font-bold text-emerald-900' : 'text-gray-700'}`} title={p.title}>{p.title}</span>
                                                    {p.location && <span className="text-[10px] text-gray-400 whitespace-nowrap ml-auto">{p.location}</span>}
                                                    {wasChosen && (
                                                        <span className="inline-flex items-center gap-0.5 text-emerald-600 shrink-0" title="Previously chosen">
                                                            <Check size={11} strokeWidth={3} />
                                                        </span>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </details>
                </div>
            )}

            {detailId !== null && (
                <ProgramDetailsModal code={code} programId={detailId} onClose={() => setDetailId(null)} />
            )}
        </section>
    );
}

function formatStamp(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleString(undefined, {
            day: 'numeric', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return iso;
    }
}
