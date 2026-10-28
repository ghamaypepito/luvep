import ApprovalReviewLink from '@/components/verification/ApprovalReviewLink';
import React, { useContext, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import NoteProgramPicker, { NotePrograms } from '@/components/leads/NoteProgramPicker';
import AiRecordAssistant from '@/components/ai/AiRecordAssistant';
import {
    ArrowLeft, User, BookOpen, DollarSign, GraduationCap,
    FileText, Download, Edit, Phone, Mail, MapPin,
    CheckCircle2, XCircle, FileQuestion, Calendar,
    TrendingUp, AlertTriangle, Clock, History, ChevronDown, Check,
    User as UserIcon, ArrowRight, Sparkles, FolderOpen, Copy, Info, Undo2, Send,
    Globe, Home, Wand2, Users as UsersIcon, Eye,
    Paperclip, FileImage, Film, Music,
    Briefcase, Trash2, RefreshCw, MoreVertical, Plus, X, MessageSquare, Search, Pin, Loader2,
    Building2, CalendarCheck,
} from 'lucide-react';
import { LeadStatStrip, LeadProgressStrip, LeadActivityTimeline } from '@/components/leads/LeadOverviewStrips';
import { CHECKLIST, STATUSES, STATUS_CHIP, STATUS_LABEL, SECTION_STATUSES, IMPORTANT_NOTES, renderFilename, currentSectionIndex } from '@/data/leadDocumentChecklist';
import { ThreadItem, ThreadComposer } from '@/components/immigration/case-profile/threads';
import { LeadDocViewerModal, LeadDocFileMenu } from '@/components/ui/LeadDocViewerModal';
import SendUpdateModal from '@/components/leads/SendUpdateModal';
import LeadBookingModal from '@/components/leads/LeadBookingModal';
import ProgramDetailsModal from '@/components/ui/ProgramDetailsModal';
import LeadHealthBadge from '@/components/ai/LeadHealthBadge';
import CaseHealthBadge from '@/components/ai/CaseHealthBadge';
import CommunicationsPanel from '@/components/sales/CommunicationsPanel';
import ComposeMessagePanel from '@/components/sales/ComposeMessagePanel';

// Stage colour map — kept consistent with the leads list.
const STAGE_STYLES = {
    "New Leads":                      "bg-rose-100 text-rose-800 border-rose-200",
    "Contact Attempted":              "bg-orange-100 text-orange-800 border-orange-200",
    "Contacted for Booking":          "bg-yellow-100 text-yellow-800 border-yellow-200",
    "Booking Confirmation":"bg-cyan-100 text-cyan-800 border-cyan-200",
    "Missed the Meeting":             "bg-pink-100 text-pink-800 border-pink-200",
    "Qualified but Not Ready":        "bg-slate-100 text-slate-700 border-slate-200",
    "Qualified but No Funds":         "bg-slate-100 text-slate-700 border-slate-200",
    "Qualified":                      "bg-amber-100 text-amber-800 border-amber-200",
    "Booked Consultation":            "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Did Not Book Consultation":      "bg-stone-100 text-stone-700 border-stone-200",
    "No Show":                        "bg-teal-100 text-teal-800 border-teal-200",
    "Consultation Done":              "bg-purple-100 text-purple-800 border-purple-200",
    "Proposal Sent":                  "bg-sky-100 text-sky-800 border-sky-200",
    "Program Selected":               "bg-teal-100 text-teal-800 border-teal-200",
    "Consultancy Agreement Sent":     "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Consultancy Agreement Signed":   "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Consultancy Agreement":          "bg-indigo-100 text-indigo-800 border-indigo-200",
    "English Pro":                    "bg-emerald-50 text-emerald-700 border-emerald-200",
    "School Enrollment":              "bg-green-100 text-green-800 border-green-200",
    "Visa Process":                   "bg-lime-100 text-lime-800 border-lime-200",
    "Not Qualified":                  "bg-red-100 text-red-700 border-red-200",
    "Work Pathway / Other":           "bg-blue-100 text-blue-800 border-blue-200",
    // Department (Education / English / Immigration) stage colours, so the
    // header badge/dropdown look right for a lead moved off the sales pipeline.
    "Endorsed to School":             "bg-sky-100 text-sky-800 border-sky-200",
    "Conditional Offer":              "bg-amber-100 text-amber-800 border-amber-200",
    "Unconditional Offer":            "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Endorsed to Immigration":        "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Visa Lodged":                    "bg-violet-100 text-violet-800 border-violet-200",
    "Approved in Principle":          "bg-cyan-100 text-cyan-800 border-cyan-200",
    "Request for Information":        "bg-orange-100 text-orange-800 border-orange-200",
    "Approved Visa":                  "bg-green-100 text-green-800 border-green-200",
    "Started Course":                 "bg-teal-100 text-teal-800 border-teal-200",
    "PTE Review":                     "bg-purple-100 text-purple-800 border-purple-200",
    "DIY Review":                     "bg-violet-100 text-violet-800 border-violet-200",
    "For PTE Mocktest":               "bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200",
    "For PTE Exam":                   "bg-pink-100 text-pink-800 border-pink-200",
    "For Assessment":                 "bg-amber-100 text-amber-800 border-amber-200",
    "Endorsed":                       "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Agreement Sent":                 "bg-indigo-100 text-indigo-800 border-indigo-200",
    "Agreement Signed":               "bg-emerald-100 text-emerald-800 border-emerald-200",
    "For Agreement & Invoice":        "bg-amber-100 text-amber-800 border-amber-200",
    "Invoice Paid":                   "bg-emerald-100 text-emerald-800 border-emerald-200",
    "Request to Lodged":              "bg-sky-100 text-sky-800 border-sky-200",
    "Interim Visa Issued":            "bg-cyan-100 text-cyan-800 border-cyan-200",
    "RFI Responded":                  "bg-lime-100 text-lime-800 border-lime-200",
    "Decline Visa":                   "bg-red-100 text-red-700 border-red-200",
    "Withdrawn":                      "bg-gray-100 text-gray-600 border-gray-200",
};
const stageClass = (s) => STAGE_STYLES[s] || "bg-gray-100 text-gray-700 border-gray-200";

// Searchable "add a program" picker for the Programs Offered card. Type to
// filter the catalogue by program title or school, click to shortlist. Clears
// itself after each pick so several can be added in a row.
function ProgramAddPicker({ options = [], excludeIds = [], disabled = false, onPick }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [rect, setRect] = useState(null);
    const boxRef = useRef(null);
    const menuRef = useRef(null);

    // The card is overflow-hidden (for its rounded header), so the dropdown is
    // rendered in a portal positioned under the input to avoid being clipped.
    const reposition = () => {
        const el = boxRef.current;
        if (el) { const r = el.getBoundingClientRect(); setRect({ top: r.bottom, left: r.left, width: r.width }); }
    };
    useEffect(() => {
        if (! open) return;
        reposition();
        const onDoc = (e) => {
            if (boxRef.current?.contains(e.target) || menuRef.current?.contains(e.target)) return;
            setOpen(false);
        };
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);
        document.addEventListener('mousedown', onDoc);
        return () => {
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
            document.removeEventListener('mousedown', onDoc);
        };
    }, [open]);

    const q = query.trim().toLowerCase();
    const available = options.filter((p) => ! excludeIds.includes(p.id));
    const filtered = q
        ? available.filter((p) => `${p.title} ${p.school || ''}`.toLowerCase().includes(q))
        : available;

    return (
        <div ref={boxRef} className="relative">
            <div className="relative">
                <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                <input
                    type="text"
                    value={query}
                    disabled={disabled}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    placeholder={disabled ? 'Maximum reached' : 'Add a program…'}
                    className="w-full pl-8 pr-3 py-2 rounded-lg border border-gray-200 text-sm bg-white focus:border-gray-400 outline-none disabled:bg-gray-50 disabled:text-gray-400"
                />
            </div>
            {open && ! disabled && rect && createPortal(
                <div
                    ref={menuRef}
                    style={{ position: 'fixed', top: rect.top + 4, left: rect.left, width: rect.width, zIndex: 60 }}
                    className="max-h-60 overflow-auto bg-white border border-gray-200 rounded-lg shadow-xl py-1"
                >
                    {filtered.length === 0 ? (
                        <p className="px-3 py-2 text-[12px] text-gray-400">No matching programs.</p>
                    ) : filtered.slice(0, 60).map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => { onPick?.(p.id); setQuery(''); setOpen(false); }}
                            className="w-full text-left px-3 py-2 hover:bg-gray-50"
                        >
                            <span className="block text-sm text-gray-800 truncate">{p.title}</span>
                            <span className="block text-[11px] text-gray-400 truncate">
                                {[p.school, p.level != null ? `Level ${p.level}` : null].filter(Boolean).join(' · ') || '—'}
                            </span>
                        </button>
                    ))}
                </div>,
                document.body,
            )}
        </div>
    );
}

// The student's enrolment programs (multi-select on the education student
// modal, stored delimited). One card per program showing its school; each
// clickable card opens the full program-detail modal. Only rendered with data.
function EnrolmentProgramsCard({ programText, programSchools = [], unionSchools = "", programOptions = [], onViewProgram }) {
    const split = (t) => (t && String(t).trim() ? String(t).split(" · ").map((s) => s.trim()).filter(Boolean) : []);
    const programs = split(programText);
    if (programs.length === 0) return null;

    // Each catalogue option carries its resolved school + level + id.
    const optFor = (title) => programOptions.find((o) => o.title === title) || null;
    // Legacy students stored only a de-duplicated union of schools; use it as an
    // index fallback so their schools still show before a per-program re-save.
    const union = split(unionSchools);
    // School for a program = its saved per-program school (aligned by index),
    // then the program's catalogue school, then the legacy union by index.
    const schoolFor = (title, i) => {
        const per = Array.isArray(programSchools) ? String(programSchools[i] || "").trim() : "";
        return per || optFor(title)?.school || union[i] || "";
    };

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-6 py-5 border-b border-gray-100 flex items-center justify-between gap-3 bg-gradient-to-br from-gray-50 to-white">
                <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center shrink-0">
                        <GraduationCap size={16} strokeWidth={2.25} />
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-0.5">Enrolment</p>
                        <h2 className="text-base font-bold text-gray-900 tracking-tight">Programs &amp; schools</h2>
                    </div>
                </div>
                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold tabular-nums bg-gray-100 text-gray-700 shrink-0">
                    {programs.length} program{programs.length === 1 ? '' : 's'}
                </span>
            </div>
            <div className="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                {programs.map((title, i) => {
                    const opt = optFor(title);
                    const school = schoolFor(title, i);
                    const clickable = !! opt?.id;
                    const Tag = clickable ? 'button' : 'div';
                    return (
                        <Tag
                            key={i}
                            type={clickable ? 'button' : undefined}
                            onClick={clickable ? () => onViewProgram?.(opt.id) : undefined}
                            title={clickable ? 'View program details' : undefined}
                            className={`text-left flex flex-col rounded-xl border border-gray-200 bg-white p-3.5 transition-colors ${clickable ? 'hover:border-[#436235] hover:bg-emerald-50/40 cursor-pointer' : ''}`}
                        >
                            {opt?.level != null && opt.level !== '' && (
                                <span className="inline-flex items-center self-start px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-gray-900 text-white mb-1.5">Level {opt.level}</span>
                            )}
                            <p className="text-[13px] font-bold text-gray-900 leading-snug">{title}</p>
                            <p className="mt-1 inline-flex items-center gap-1.5 text-[11.5px] text-gray-500">
                                <Building2 size={12} className="text-gray-400 shrink-0" />
                                {school || <span className="text-gray-300">No school on file</span>}
                            </p>
                            {clickable && (
                                <span className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-[#436235]">
                                    <Eye size={11} /> View details
                                </span>
                            )}
                        </Tag>
                    );
                })}
            </div>
        </div>
    );
}

// Read-only mirror of the tracker's program shortlist for staff: lists the
// programs offered to this lead and highlights (green) the one the client
// chose from their tracker. Rendered on the Lead Stats tab under the AI card.
function ProposedProgramsCard({ proposal, leadId, programOptions = [], onViewProgram }) {
    const writeBase = useWriteBase();
    // Which programs go in front of a client is an advice call — staff shortlist
    // them, the client picks one on their tracker. A recruiting agent or
    // sub-agent reads that shortlist; they do not decide it. So the search box
    // and the per-card remove come off in their portals, leaving the tiles and
    // "View program" intact.
    const canShortlist = !['/portal/agent', '/portal/sub-agent'].includes(writeBase);
    const programs = proposal?.programs || [];
    const chosenId = proposal?.preferred_program_id ?? null;
    const chosen = programs.find((p) => p.id === chosenId) || null;
    const currentIds = programs.map((p) => p.id);
    const MAX = 5;
    const [reviewing, setReviewing] = useState(null);

    // Inline shortlist edit — persists to leads.proposed_program_ids without
    // spawning a proposal version (that's the Proposal & Agreements flow).
    const saveShortlist = (ids) => {
        router.post(`${writeBase}/leads/${leadId}/shortlist`, { program_ids: ids }, {
            preserveScroll: true,
            preserveState: false,
            onError: (e) => toast.error(Object.values(e)[0] || 'Could not update programs'),
        });
    };
    const addProgram = (pid) => { if (currentIds.length < MAX && ! currentIds.includes(pid)) saveShortlist([...currentIds, pid]); };
    const removeProgram = (pid) => saveShortlist(currentIds.filter((x) => x !== pid));

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-6 py-5 border-b border-gray-100 flex items-start justify-between gap-4 bg-gradient-to-br from-gray-50 to-white">
                <div className="flex items-center gap-3 min-w-0">
                    <div className="w-9 h-9 rounded-lg bg-[#436235]/10 text-[#436235] flex items-center justify-center shrink-0">
                        <Sparkles size={16} strokeWidth={2.25} />
                    </div>
                    <div className="min-w-0">
                        <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-0.5">Programs offered</p>
                        <ApprovalReviewLink leadId={leadId} status={proposal?.review_status} />
                        <h2 className="text-base font-bold text-gray-900 tracking-tight">
                            {chosen ? 'Client chose a program' : 'Programs proposed to this lead'}
                        </h2>
                        <p className="text-[12px] text-gray-500 mt-0.5 leading-relaxed">
                            {programs.length === 0
                                ? (canShortlist
                                    ? 'No programs proposed yet — search and add programs below.'
                                    : 'No programs proposed yet — staff shortlist these for the client.')
                                : chosen
                                    ? <>Client selected <span className="font-semibold text-gray-700">{chosen.title}</span> from their tracker.</>
                                    : "These are the programs staff shortlisted. The client picks one on their tracker."}
                        </p>
                    </div>
                </div>
                {programs.length > 0 && (
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold tabular-nums bg-gray-100 text-gray-700 shrink-0">
                        {programs.length} option{programs.length === 1 ? '' : 's'}
                    </span>
                )}
            </div>

            {/* Inline add — search the catalogue and shortlist a program (up to 5). */}
            {leadId && canShortlist && (
                <div className="px-6 py-3 border-b border-gray-100 bg-gray-50/40">
                    <div className="max-w-md">
                        <ProgramAddPicker
                            options={programOptions}
                            excludeIds={currentIds}
                            disabled={currentIds.length >= MAX}
                            onPick={addProgram}
                        />
                        <p className="text-[10.5px] text-gray-400 mt-1">
                            {currentIds.length >= MAX
                                ? `Maximum ${MAX} programs — remove one to add another.`
                                : `Search by program or school · ${currentIds.length}/${MAX} added`}
                        </p>
                    </div>
                </div>
            )}

            {programs.length > 0 && (
                <div className="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {programs.map((p) => {
                        const isChosen = chosenId === p.id;
                        return (
                            <article
                                key={p.id}
                                className={`relative flex flex-col rounded-xl border shadow-sm overflow-hidden ${
                                    isChosen ? 'border-emerald-500 ring-2 ring-emerald-500/25 bg-emerald-50' : 'border-gray-100 bg-white'
                                }`}
                            >
                                {/* Remove from shortlist */}
                                {canShortlist && (
                                    <button
                                        type="button"
                                        onClick={() => removeProgram(p.id)}
                                        title="Remove from shortlist"
                                        className="absolute top-2 left-2 z-10 w-6 h-6 rounded-full bg-white/90 border border-gray-200 text-gray-400 hover:text-red-600 hover:border-red-200 flex items-center justify-center shadow-sm"
                                    >
                                        <X size={12} />
                                    </button>
                                )}
                                {isChosen && (
                                    <span className="absolute top-3 right-3 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600 text-white text-[9px] font-bold uppercase tracking-[0.14em] shadow-sm">
                                        <Check size={10} strokeWidth={3} /> Chosen
                                    </span>
                                )}
                                {p.image_url ? (
                                    <div className="h-24 w-full overflow-hidden bg-gray-100">
                                        <img src={p.image_url} alt="" className="w-full h-full object-cover" />
                                    </div>
                                ) : (
                                    <div className="h-24 w-full bg-gradient-to-br from-gray-100 via-gray-50 to-white flex items-center justify-center">
                                        <GraduationCap size={26} className="text-gray-400" />
                                    </div>
                                )}
                                <div className="p-4 flex-1 flex flex-col">
                                    <div className="flex items-center gap-1.5 mb-2">
                                        {p.level != null && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-gray-100 text-gray-800 border border-gray-200">
                                                Level {p.level}
                                            </span>
                                        )}
                                        {p.category && <span className="text-[10px] font-medium text-gray-500 capitalize">{p.category}</span>}
                                    </div>
                                    <h3 className="text-[13px] font-bold text-gray-900 leading-snug mb-1">{p.title}</h3>
                                    {/* Two providers can offer the same qualification, so the
                                        school is what tells one shortlist card from another. */}
                                    {p.school && (
                                        <p className="text-[11px] font-medium text-gray-500 leading-snug mb-2 flex items-start gap-1.5">
                                            <Building2 size={11} className="text-gray-400 mt-0.5 shrink-0" /> {p.school}
                                        </p>
                                    )}
                                    {p.review_status && <ReviewBadge status={p.review_status} />}
                                    <ul className="text-[11px] text-gray-600 space-y-1 mb-3">
                                        {p.location && (
                                            <li className="inline-flex items-center gap-1.5"><MapPin size={11} className="text-gray-400" /> {p.location}</li>
                                        )}
                                        {p.duration_months && (
                                            <li className="inline-flex items-center gap-1.5"><Clock size={11} className="text-gray-400" /> {p.duration_months} months</li>
                                        )}
                                        {p.intake_months && (
                                            <li className="inline-flex items-center gap-1.5"><Calendar size={11} className="text-gray-400" /> Intake: {p.intake_months}</li>
                                        )}
                                    </ul>
                                    {(p.fee != null || p.price_text) && (
                                        <p className="text-[11px] font-semibold text-gray-700 mb-3">
                                            {p.fee != null ? Number(p.fee).toLocaleString() : p.price_text}
                                        </p>
                                    )}
                                    <div className="mt-auto flex items-center gap-3">
                                        {canShortlist && (
                                            <button
                                                type="button"
                                                onClick={() => setReviewing(p)}
                                                className="inline-flex items-center gap-1.5 text-[11px] font-bold text-[#436235] hover:text-[#2f4725] transition-colors"
                                            >
                                                <Eye size={12} /> View
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => onViewProgram?.(p.id)}
                                            className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 hover:text-[#436235] transition-colors"
                                        >
                                            View program
                                        </button>
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}

            {reviewing && (
                <ProgramReviewModal
                    program={reviewing}
                    leadId={leadId}
                    writeBase={writeBase}
                    onClose={() => setReviewing(null)}
                />
            )}
        </div>
    );
}

// Small status chip on a proposed-program card.
function ReviewBadge({ status }) {
    const map = {
        verified: ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Verified'],
        rejected: ['bg-rose-50 text-rose-600 border-rose-200', 'Rejected'],
        needs_check: ['bg-amber-50 text-amber-700 border-amber-200', 'Needs check'],
    };
    const [cls, label] = map[status] || ['bg-gray-50 text-gray-600 border-gray-200', status];
    return (
        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border mb-2 ${cls}`}>
            {label}
        </span>
    );
}

// Review a shortlisted program: verified / rejected, remarks, and the amount.
function ProgramReviewModal({ program, leadId, writeBase, onClose }) {
    const [status, setStatus] = useState(program.review_status || null);
    const [remarks, setRemarks] = useState(program.remarks || '');
    const [amount, setAmount] = useState(program.fee != null ? String(program.fee) : '');
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.post(`${writeBase}/leads/${leadId}/programs/${program.id}/review`, {
            status,
            remarks,
            amount: amount === '' ? null : amount,
        }, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { toast.success('Program review saved.'); onClose(); },
            onError: (e) => toast.error(Object.values(e || {})[0] || 'Could not save the review.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <>
            <div className="fixed inset-0 bg-black/40 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
                <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl" onClick={(e) => e.stopPropagation()}>
                    <div className="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">Review program</p>
                            <h3 className="text-[15px] font-bold text-gray-900 leading-snug">{program.title}</h3>
                            {program.school && (
                                <p className="text-[11px] font-medium text-gray-500 leading-snug">{program.school}</p>
                            )}
                            <a href={program.public_url} target="_blank" rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-[11px] font-semibold text-[#436235] hover:underline mt-0.5">
                                <Eye size={11} /> View full program
                            </a>
                        </div>
                        <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-700 shrink-0"><X size={18} /></button>
                    </div>

                    <div className="p-5 space-y-4">
                        <div>
                            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5">Decision</div>
                            <div className="grid grid-cols-2 gap-2">
                                <button type="button" onClick={() => setStatus('verified')}
                                    className={`inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border text-[12px] font-bold transition-colors ${
                                        status === 'verified' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-emerald-700 border-emerald-200 hover:bg-emerald-50'
                                    }`}>
                                    <Check size={13} /> Verified
                                </button>
                                <button type="button" onClick={() => setStatus('rejected')}
                                    className={`inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border text-[12px] font-bold transition-colors ${
                                        status === 'rejected' ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-rose-600 border-rose-200 hover:bg-rose-50'
                                    }`}>
                                    <X size={13} /> Reject
                                </button>
                            </div>
                        </div>

                        <div>
                            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5">Amount</div>
                            <input type="number" min="0" value={amount} onChange={(e) => setAmount(e.target.value)}
                                placeholder={program.price_text || 'Set amount'}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-[13px] focus:outline-none focus:border-gray-900" />
                        </div>

                        <div>
                            <div className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5">Remarks / notes</div>
                            <textarea rows={3} value={remarks} onChange={(e) => setRemarks(e.target.value)}
                                placeholder="Internal remarks about this program…"
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-[13px] focus:outline-none focus:border-gray-900 resize-y" />
                        </div>
                    </div>

                    <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-end gap-2">
                        <button type="button" onClick={onClose} className="text-[13px] font-semibold text-gray-500 hover:text-gray-800">Cancel</button>
                        <button type="button" onClick={save} disabled={saving}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[13px] font-bold hover:bg-black disabled:opacity-40">
                            {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Save review
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}


/**
 * The URL prefix this profile's write endpoints live under for the current
 * viewer: '/admin' for admin and the department portals, '/portal/agent' or
 * '/portal/sub-agent' for the recruiting portals, which mirror the same routes
 * behind a row-scoping middleware.
 *
 * Decided server-side (LeadController::show) and threaded through context
 * rather than props: a dozen panels several levels deep issue writes, and a
 * single provider cannot drift the way twelve prop chains can. Hard-coding
 * '/admin' here did more than 403 for a recruiting portal — following the
 * redirect afterwards swapped the sidebar to another role's chrome mid-task.
 */
const WriteBaseContext = React.createContext('/admin');
const useWriteBase = () => useContext(WriteBaseContext);

export default function LeadDetails({ lead: backendLead, proposal = null, activity = [], stageTimeline = [], checklistFiles = {}, documentThreads = [], programOptions = [], documentOrphans = [], statuses = [], stageLists = {}, notes = [], tags = [], allTags = [], tasks = [], staffOptions = [], eventRegistration = null, currentUser = null, portalBase = '/admin', writeBase = '/admin', overview = null }) {
    // Derive the "Back to Leads" URL from the current path so sales users
    // return to /portal/sales/leads, education users to /portal/education/leads,
    // and admins to /admin/leads — never a 403.
    const currentUrl = usePage().url || '';
    const leadsListUrl = currentUrl.replace(/\/leads\/[^/?#]+.*$/, '/leads') || '/admin/leads';
    // A `?nav=<section>` hint means this page was opened from another sidebar
    // section (e.g. the Students screen). Send "Back" there instead of the Leads
    // list, and label it accordingly — every portal that links here with a hint
    // has a matching `${base}/<section>` route.
    const backNavHint = (() => {
        const q = currentUrl.includes('?') ? currentUrl.split('?')[1] : '';
        return new URLSearchParams(q).get('nav');
    })();
    const backToLeadsUrl = backNavHint
        ? leadsListUrl.replace(/\/leads$/, `/${backNavHint}`)
        : leadsListUrl;
    const backToLabel = backNavHint || 'leads';

    // Honour ?tab=documents (or stats / personal / activity) in the URL so
    // links from the Education Documents folder list land directly on the
    // Documents tab instead of always opening on Lead Stats.
    const initialTab = (() => {
        const q = currentUrl.includes('?') ? currentUrl.split('?')[1] : '';
        const params = new URLSearchParams(q);
        const t = params.get('tab');
        return ['stats', 'personal', 'activity', 'documents', 'communications'].includes(t) ? t : 'stats';
    })();

    const [activeTab, setActiveTab] = useState(initialTab);
    const [showSendUpdate, setShowSendUpdate] = useState(false);
    const [composeOpen, setComposeOpen] = useState(false);
    const [bookingOpen, setBookingOpen] = useState(false);
    // Program-detail modal (opened from the Programs offered + Enrolment cards).
    const [viewProgramId, setViewProgramId] = useState(null);
    const [stageOpen, setStageOpen] = useState(false);

    // "Edit Lead as a whole" — when true, every Personal Info section opens
    // in edit mode at once instead of the one-at-a-time gate. Driven by the
    // header "Edit Lead" button and the ?edit=1 deep-link from the Leads
    // table's row menu.
    const editParam = (() => {
        const q = currentUrl.includes('?') ? currentUrl.split('?')[1] : '';
        return new URLSearchParams(q).get('edit') === '1';
    })();
    const [editAll, setEditAll] = useState(editParam);

    // Archive (soft-delete) this lead — recoverable. Notes, documents, tasks
    // and history all survive; it just drops off the lists.
    const archiveLead = () => {
        if (! window.confirm('Delete this lead? It will be archived — notes, documents and history are kept and it can be restored.')) return;
        router.delete(`${writeBase}/leads/${backendLead.id}`);
    };

    // Toggle whole-record edit mode and make sure the Personal Info tab is
    // showing so the now-open section forms are visible.
    const toggleEditAll = () => {
        setEditAll((on) => {
            const next = ! on;
            if (next) setActiveTab('personal');
            return next;
        });
    };
    const [savingStage, setSavingStage] = useState(false);
    const stageRef = useRef(null);

    // Close stage popover on outside click + ESC
    useEffect(() => {
        if (!stageOpen) return;
        const onDoc = (e) => { if (!stageRef.current?.contains(e.target)) setStageOpen(false); };
        const onKey = (e) => { if (e.key === 'Escape') setStageOpen(false); };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [stageOpen]);

    // Department-aware stage. A lead moved to Study / English / Immigration is
    // tracked by its own stage column, so the header must show THAT stage and
    // its dropdown — not the sales pipeline — to match the Students list.
    const leadDept = (() => {
        const l = backendLead || {};
        if (l.is_immigration_case || l.immigration_stage) return 'immigration';
        if (l.is_student || l.education_stage) return 'education';
        if (l.is_english_student || l.english_stage) return 'english';
        return 'sales';
    })();
    const DEPT_FIELD = { sales: 'status', education: 'education_stage', english: 'english_stage', immigration: 'immigration_stage' };
    const stageField = DEPT_FIELD[leadDept];
    const stageValue = leadDept === 'sales'
        ? (backendLead?.status || 'New Leads')
        : (backendLead?.[stageField] || null);
    const deptStages = leadDept === 'sales' ? statuses : (stageLists[leadDept] || []);

    const changeStage = (value) => {
        setStageOpen(false);
        if (!backendLead || value === stageValue) return;
        setSavingStage(true);
        router.post(`${writeBase}/leads/${backendLead.id}/stage`, { status: value, field: stageField }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSavingStage(false),
        });
    };

    // ── Personal Info tab — single-edit-at-a-time gate ────────────────────
    // The 9 section cards each manage their own form state via <Section/>;
    // this top-level key gates the Edit buttons so only one section can be
    // in edit mode at a time across the whole tab.
    const [currentEditKey, setCurrentEditKey] = useState(null);

    // Files actually on file, for the Documents tab badge.
    const documentsCount = React.useMemo(() => {
        const fromChecklist = Object.values(checklistFiles || {})
            .reduce((n, v) => n + (Array.isArray(v) ? v.length : 0), 0);
        return fromChecklist + (documentOrphans?.length || 0);
    }, [checklistFiles, documentOrphans]);

    // If no lead data is passed, show a loading or error state
    if (!backendLead) {
        return (
            <div className="flex flex-col items-center justify-center min-h-[400px] space-y-4">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                <p className="text-gray-600 font-medium font-inter">Loading lead details...</p>
                <Link href={backToLeadsUrl} className="text-blue-600 hover:underline text-sm font-semibold">Back to {backToLabel.charAt(0).toUpperCase() + backToLabel.slice(1)}</Link>
            </div>
        );
    }

    // Helper to format dates for display
    const formatDate = (dateStr) => {
        if (!dateStr) return null;
        try {
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return dateStr;
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (e) {
            return dateStr;
        }
    };

    // Extracting nested JSON data safely
    const workInfo = backendLead.work_info || {};
    const financialInfo = backendLead.financial_info || {};
    const studyPlan = backendLead.study_plans?.[0] || {};

    // Categorize education experiences
    const highSchool = backendLead.education_exps?.find(e =>
        e.level?.toLowerCase().includes('high') ||
        e.level?.toLowerCase().includes('secondary') ||
        ['10', '11', '12'].some(l => e.level?.includes(l))
    ) || {};

    const tertiary = backendLead.education_exps?.find(e =>
        e.level?.toLowerCase().includes('bachelor') ||
        e.level?.toLowerCase().includes('university') ||
        e.level?.toLowerCase().includes('college') ||
        e.level?.toLowerCase().includes('tertiary')
    ) || {};

    // Shortcuts to the JSON-bucketed fields imported from CSV / quick-lead
    // forms. Used inline below to fill the relevant existing sections.
    const importedEdu    = (backendLead.education_notes && !Array.isArray(backendLead.education_notes)) ? backendLead.education_notes : {};
    const importedWork   = (backendLead.work_info && !Array.isArray(backendLead.work_info)) ? backendLead.work_info : {};
    const importedFamily = (backendLead.family_info && !Array.isArray(backendLead.family_info)) ? backendLead.family_info : {};

    // Aggregate all education documents into one list
    let allDocuments = backendLead.education_notes?.education_docs || [];
    if (allDocuments.length === 0) {
        allDocuments = backendLead.education_exps?.reduce((acc, exp) => {
            if (exp.documents && Array.isArray(exp.documents)) {
                return [...acc, ...exp.documents];
            }
            return acc;
        }, []) || [];
    }

    const workExperiences = Array.isArray(backendLead.work_info) ? backendLead.work_info : [];
    const workDocuments = workExperiences.reduce((acc, work) => {
        if (work.supporting_docs && Array.isArray(work.supporting_docs)) {
            return [...acc, ...work.supporting_docs];
        }
        return acc;
    }, []);

    // Find any gap explanation in education history
    const gapExp = backendLead.education_exps?.find(e => e.gap_explanation)?.gap_explanation;

    // Map Backend Data to the Frontend Display Object (Maintaining original UI structure)
    const lead = {
        id: backendLead.lead_id || `LP-${backendLead.id}`,
        trackingCode: backendLead.tracking_code || null,
        lastSeenAt: backendLead.last_seen_at || null,
        status: backendLead.status || 'New',
        stage: backendLead.stage || 'N/A',
        branch: backendLead.branch || 'Main',
        country: backendLead.country || '—',
        submittedAt: formatDate(backendLead.created_at),

        // 1. Personal Information
        personal: {
            surname: backendLead.last_name || '—',
            firstName: backendLead.first_name || '—',
            otherNames: backendLead.other_names || workInfo.other_names || 'None',
            gender: backendLead.gender || '—',
            phone: backendLead.phone || '—',
            email: backendLead.email || '—',
            maritalStatus: backendLead.marital_status || '—',
            dob: backendLead.dob || workInfo.dob || '—',
            countryOfBirth: backendLead.country_of_birth || workInfo.country_of_birth || '—',
            placeOfBirth: backendLead.place_of_birth || workInfo.place_of_birth || '—',
            citizenship: backendLead.citizenship || workInfo.citizenship || '—',
            residence: backendLead.residence_city || workInfo.city || workInfo.residence || '—',
            hasPassport: backendLead.has_passport === 'Yes' || !!workInfo.passport || (workInfo.passport_expiry && workInfo.passport_expiry !== '—'),
            passportExpiry: backendLead.passport_expiry || workInfo.passport_expiry || '—',
            passportFile: backendLead.passport_path || workInfo.passport_file || 'No file uploaded'
        },

        // 2. Study Plans in New Zealand
        studyPlans: {
            preferredCourse: studyPlan.preferred_course || '—',
            qualificationLevel: studyPlan.qualification_level || '—',
            preferredCity: studyPlan.preferred_city || '—',
            preferredIntake: studyPlan.preferred_intake || '—',
            englishTest: {
                taken: !!studyPlan.english_test_taken,
                type: studyPlan.english_test_type || '—',
                date: formatDate(studyPlan.english_test_date),
                overall: studyPlan.score_overall || '—',
                reading: studyPlan.score_reading || '—',
                writing: studyPlan.score_writing || '—',
                listening: studyPlan.score_listening || '—',
                speaking: studyPlan.score_speaking || '—'
            }
        },

        // 3. Financial Information
        financial: {
            hasTuitionFunds: !!financialInfo.tuition_funds,
            hasLivingExpenses: !!financialInfo.living_expenses,
            notes: financialInfo.notes || 'No additional notes provided.'
        },

        // 4. Education Background
        education: {
            highSchool: {
                completed: !!highSchool.id,
                level: highSchool.level || '—',
                institution: highSchool.institution || '—',
                dateStarted: formatDate(highSchool.start_date),
                dateCompleted: formatDate(highSchool.end_date),
                averageMarks: highSchool.average_marks || '—'
            },
            tertiary: {
                completed: !!tertiary.id,
                bachelors: {
                    field: tertiary.field_of_study || '—',
                    institution: tertiary.institution || '—',
                    dateStarted: formatDate(tertiary.start_date),
                    dateCompleted: formatDate(tertiary.end_date),
                    averageMarks: tertiary.average_marks || '—'
                }
            },
            documents: allDocuments.length > 0 ? allDocuments : ['No documents listed'],
            workDocuments: workDocuments.length > 0 ? workDocuments : ['No documents listed'],
            gap: {
                hasGap: !!gapExp,
                length: '—',
                activity: gapExp || '—'
            }
        }
    };

    // AI Analysis data
    const aiAnalysis = backendLead.ai_analysis;
    const aiStatus = backendLead.ai_analysis_status || 'pending';

    const getStatusStyle = (status) => {
        switch(status) {
            case 'New': return 'bg-blue-100 text-blue-700 border-blue-200';
            case 'Contacted': return 'bg-yellow-100 text-yellow-700 border-yellow-200';
            case 'Qualified': return 'bg-purple-100 text-purple-700 border-purple-200';
            case 'Processing': return 'bg-indigo-100 text-indigo-700 border-indigo-200';
            case 'Closed': return 'bg-emerald-100 text-emerald-700 border-emerald-200';
            default: return 'bg-gray-100 text-gray-700 border-gray-200';
        }
    };

    const getScoreLevel = (pct) => {
        if (pct >= 75) return { label: 'Strong', color: 'text-emerald-600', bg: 'bg-emerald-500', badge: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
        if (pct >= 50) return { label: 'Moderate', color: 'text-amber-600', bg: 'bg-amber-500', badge: 'bg-amber-50 text-amber-700 border-amber-200' };
        if (pct >= 25) return { label: 'Low', color: 'text-orange-600', bg: 'bg-orange-500', badge: 'bg-orange-50 text-orange-700 border-orange-200' };
        return { label: 'Critical', color: 'text-red-600', bg: 'bg-red-500', badge: 'bg-red-50 text-red-700 border-red-200' };
    };

    const ScoreCard = ({ label, score, max, summary }) => {
        const pct = max > 0 ? Math.round((score / max) * 100) : 0;
        const level = getScoreLevel(pct);
        return (
            <div className="bg-white border border-gray-100 rounded-xl p-5 hover:shadow-sm transition-shadow">
                <div className="flex items-center justify-between mb-3">
                    <span className="text-xs font-bold text-gray-700 uppercase tracking-wider">{label}</span>
                    <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full border ${level.badge}`}>{level.label}</span>
                </div>
                <div className="flex items-end gap-3 mb-3">
                    <span className={`text-2xl font-black ${level.color}`}>{pct}%</span>
                    <span className="text-xs text-gray-500 font-medium pb-1">{score} / {max} pts</span>
                </div>
                <div className="w-full h-2 bg-gray-100 rounded-full overflow-hidden mb-3">
                    <div className={`h-full rounded-full ${level.bg} transition-all duration-700`} style={{ width: `${pct}%` }} />
                </div>
                {summary && <p className="text-xs text-gray-600 leading-relaxed">{summary}</p>}
            </div>
        );
    };

    const ScoreCircle = ({ score }) => {
        const circumference = 2 * Math.PI * 40;
        const offset = circumference - (score / 100) * circumference;
        const level = getScoreLevel(score);
        return (
            <div className="relative w-28 h-28">
                <svg className="w-28 h-28 -rotate-90" viewBox="0 0 96 96">
                    <circle cx="48" cy="48" r="40" stroke="#f3f4f6" strokeWidth="6" fill="none" />
                    <circle cx="48" cy="48" r="40" stroke={level.bg === 'bg-emerald-500' ? '#10b981' : level.bg === 'bg-amber-500' ? '#f59e0b' : level.bg === 'bg-orange-500' ? '#f97316' : '#ef4444'} strokeWidth="6" fill="none" strokeLinecap="round" strokeDasharray={circumference} strokeDashoffset={offset} style={{ transition: 'stroke-dashoffset 1s ease-out' }} />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className={`text-2xl font-black ${level.color}`}>{score}</span>
                    <span className="text-[8px] font-bold text-gray-500 uppercase tracking-wider">/100</span>
                </div>
            </div>
        );
    };

    return (
        <WriteBaseContext.Provider value={writeBase}>
        <div className="space-y-6 max-w-[1200px] mx-auto pb-12">
            <Head title={`Lead Details - ${lead.personal.firstName} ${lead.personal.surname}`} />
            <AiRecordAssistant
                subjectId={backendLead.id}
                label={`${backendLead.first_name ?? ''} ${backendLead.last_name ?? ''}`.trim() || backendLead.lead_id}
                immigration={!!backendLead.is_immigration_case}
            />

            {/* ── Header ───────────────────────────────────────
                Identity, what is missing about it, and the two actions staff take
                from here. The pipeline stage sits inline rather than in its own
                block, and the stat strip below carries the rest. */}
            <Link href={backToLeadsUrl} className="inline-flex items-center gap-1.5 -mb-2 text-[12px] font-semibold text-gray-400 hover:text-gray-700 transition-colors">
                <ArrowLeft size={13} /> Back to {backToLabel}
            </Link>

            {/* Identity + the five facts underneath read as one block, so they
                share a card rather than sitting in two with a gap between. */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <div className="flex items-start justify-between gap-4 flex-wrap px-5 py-4">
                    <div className="flex items-start gap-3 min-w-0">
                        <div className="w-10 h-10 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center text-[12px] font-bold shrink-0">
                            {`${(backendLead.first_name || "?")[0] || ""}${(backendLead.last_name || "")[0] || ""}`.toUpperCase() || "?"}
                        </div>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-[20px] font-bold text-gray-900 tracking-tight truncate">
                                    {lead.personal.firstName} {lead.personal.surname}
                                </h1>
                                <div ref={stageRef} className="relative">
                                    <button
                                        type="button"
                                        disabled={savingStage}
                                        onClick={() => setStageOpen(!stageOpen)}
                                        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide hover:shadow-sm transition-all disabled:opacity-50 ${stageClass(stageValue)}`}
                                        title="Click to change stage"
                                    >
                                        {stageValue || "Set stage"}
                                        <ChevronDown size={10} strokeWidth={2.5} className="opacity-60" />
                                    </button>
                                    {stageOpen && (
                                        <div role="listbox" className="absolute z-50 top-full left-0 mt-1 bg-white rounded-xl shadow-2xl border border-gray-100 py-1.5 w-[280px] max-h-[420px] overflow-y-auto">
                                            <p className="px-3 pt-2 pb-1.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                                                Move to stage{leadDept !== "sales" ? ` · ${leadDept}` : ""}
                                            </p>
                                            {deptStages.map((s) => {
                                                const active = s === stageValue;
                                                return (
                                                    <button
                                                        key={s}
                                                        type="button"
                                                        role="option"
                                                        aria-selected={active}
                                                        onClick={() => changeStage(s)}
                                                        className={`w-full text-left px-3 py-1.5 text-[12px] hover:bg-gray-50 flex items-center justify-between gap-2 ${active ? "font-bold text-gray-900" : "text-gray-600"}`}
                                                    >
                                                        <span className="truncate">{s}</span>
                                                        {active && <Check size={12} className="text-gray-900 shrink-0" />}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                                <span className="text-[11.5px] font-mono text-gray-400">{lead.id}</span>
                                <span className="text-[11.5px] text-gray-400">
                                    · {backendLead.source || "added manually"} · {formatDate(backendLead.created_at)}
                                </span>
                            </div>

                            {/* Contact line — anything missing reads amber, because a
                                blank here is what stalls the assessment. */}
                            <div className="flex items-center gap-2 flex-wrap mt-1 text-[12.5px]">
                                {backendLead.email
                                    ? <a href={`mailto:${backendLead.email}`} className="text-gray-600 hover:text-gray-900 truncate">{backendLead.email}</a>
                                    : <span className="text-amber-600">No email</span>}
                                <span className="text-gray-300">·</span>
                                {backendLead.phone
                                    ? <span className="text-gray-600">{backendLead.phone}</span>
                                    : <span className="text-amber-600">No phone number</span>}
                                <span className="text-gray-300">·</span>
                                {(backendLead.residence_city || backendLead.residence_country)
                                    ? <span className="text-gray-600 truncate">{[backendLead.residence_city, backendLead.residence_country].filter(Boolean).join(", ")}</span>
                                    : <span className="text-amber-600">No location</span>}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            onClick={() => setComposeOpen(true)}
                            className="px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
                        >
                            Message lead
                        </button>
                        <button
                            type="button"
                            onClick={() => setBookingOpen(true)}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
                        >
                            <CalendarCheck size={15} className="text-[#436235]" /> Book
                        </button>
                        <ConvertMenu lead={backendLead} canRevert={currentUser?.is_admin} />
                        <LeadActionsMenu
                            editAll={editAll}
                            onToggleEdit={toggleEditAll}
                            onArchive={archiveLead}
                            trackingCode={lead.trackingCode}
                            hasEmail={!! backendLead.email}
                            onSendTrackerLink={() => router.post(`${writeBase}/leads/${backendLead.id}/send-tracker-link`, {}, { preserveScroll: true, preserveState: true })}
                            onSendUpdate={() => setShowSendUpdate(true)}
                            onCompose={() => setComposeOpen(true)}
                        />
                    </div>
                </div>

                {/* Stage, owner, record readiness, programs, last contact —
                    inside this card so identity and status read as one block.
                    Its own overflow-hidden clips the bottom corners so the stage
                    dropdown above (in the now overflow-visible card) isn't cut off. */}
                <div className="rounded-b-2xl overflow-hidden">
                    <LeadStatStrip overview={overview} />
                </div>
            </div>

            <SendUpdateModal
                leadId={backendLead.id}
                leadEmail={backendLead.email}
                open={showSendUpdate}
                onClose={() => setShowSendUpdate(false)}
            />

            {bookingOpen && (
                <LeadBookingModal lead={backendLead} onClose={() => setBookingOpen(false)} />
            )}

            {viewProgramId && (
                <ProgramDetailsModal
                    endpoint={`/admin/programs/${viewProgramId}/details`}
                    onClose={() => setViewProgramId(null)}
                />
            )}

            {/* Tab strip */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <div className="flex items-center border-b border-gray-100 px-3 overflow-x-auto">
                    <TabButton active={activeTab === 'stats'} onClick={() => setActiveTab('stats')}>
                        Overview
                    </TabButton>
                    <TabButton
                        active={activeTab === 'personal'}
                        onClick={() => setActiveTab('personal')}
                        badge={overview?.readiness ? overview.readiness.filled : null}
                    >
                        Profile information
                    </TabButton>
                    <TabButton
                        active={activeTab === 'activity'}
                        onClick={() => setActiveTab('activity')}
                        badge={activity.length > 0 ? activity.length : null}
                    >
                        Journey
                    </TabButton>
                    <TabButton
                        active={activeTab === 'documents'}
                        onClick={() => setActiveTab('documents')}
                        badge={documentsCount}
                    >
                        Documents
                    </TabButton>
                    <TabButton
                        active={activeTab === 'communications'}
                        onClick={() => setActiveTab('communications')}
                    >
                        Communications
                    </TabButton>
                </div>
            </div>

            {/* ── Journey tab — Key milestones strip on top, Internal
                  Notes (left, wider) + Stage Timeline (right) below,
                  full-width activity log at the bottom ── */}
            {activeTab === 'activity' && (
                <div className="space-y-4">
                    {/* INZ tracking — only when this lead is an immigration case. */}
                    {backendLead.is_immigration_case && (
                        <InzTrackingPanel lead={backendLead} />
                    )}

                    {/* Key milestones — full width above the 2-col row. */}
                    <KeyMilestonesPanel lead={backendLead} />

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div className="lg:col-span-2">
                            <NotesPanel leadId={backendLead.id} notes={notes} currentUser={currentUser} staffOptions={staffOptions} programOptions={programOptions} />
                        </div>
                        <JourneyPanel
                            lead={backendLead}
                            staffOptions={staffOptions}
                            currentStage={backendLead.status}
                            stageTimeline={stageTimeline}
                        />
                    </div>
                    <ActivityLogPanel activity={activity} />
                </div>
            )}

            {/* ── Documents tab ── */}
            {activeTab === 'documents' && (
                <DocumentsPanel lead={backendLead} checklistFiles={checklistFiles} orphans={documentOrphans} currentUser={currentUser} threads={documentThreads} staffOptions={staffOptions} />
            )}

            {/* ── Communications tab — message history sent to this lead ── */}
            {activeTab === 'communications' && (
                <CommunicationsPanel leadId={backendLead.id} leadEmail={backendLead.email} />
            )}

            {/* Compose message slide-over (email / SMS / both) */}
            <ComposeMessagePanel
                open={composeOpen}
                onClose={() => setComposeOpen(false)}
                lead={{
                    id: backendLead.id,
                    name: `${lead.personal.firstName} ${lead.personal.surname}`.trim(),
                    email: backendLead.email,
                    phone: backendLead.phone,
                }}
            />

            {/* ── Lead Stats tab — dashboard-style quick stats + AI hero ── */}
            <div className={activeTab === 'stats' ? 'space-y-5' : 'hidden'}>
                {/* Where this lead is — the six-step rail, with what is holding
                    it up called out beside the heading. */}
                <LeadProgressStrip overview={overview} />

                {/* Notes and tasks side by side — the two things staff act on. */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                    <NotesPanel leadId={backendLead.id} notes={notes} currentUser={currentUser} staffOptions={staffOptions} programOptions={programOptions} />
                    <TasksPanel leadId={backendLead.id} tasks={tasks} staffOptions={staffOptions} currentUser={currentUser} />
                </div>

                <ProposedProgramsCard proposal={proposal} leadId={backendLead.id} programOptions={programOptions} onViewProgram={setViewProgramId} />
                <EnrolmentProgramsCard programText={studyPlan.preferred_course} programSchools={studyPlan.program_schools} unionSchools={backendLead.student_school} programOptions={programOptions} onViewProgram={setViewProgramId} />
                <AICapabilityHero lead={backendLead} />
                <TagsPanel leadId={backendLead.id} tags={tags} allTags={allTags} />

                <LeadActivityTimeline
                    activity={activity}
                    pending={overview?.steps?.filter((x) => x.state === 'todo').map((x) => ({
                        key: x.key, label: x.label, caption: x.caption,
                    })) || []}
                />
            </div>

            {/* ── Personal Info tab — full profile sections below ── */}
            <div className={activeTab === 'personal' ? 'space-y-6' : 'hidden'}>

            {/* Quick Contact & Summary Bar */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div className="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <Phone size={18} />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-600 font-medium">Phone</p>
                        <p className="text-sm font-semibold text-gray-900 truncate">{lead.personal.phone}</p>
                    </div>
                </div>
                <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div className="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <Mail size={18} />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-600 font-medium">Email</p>
                        <p className="text-sm font-semibold text-gray-900 truncate">{lead.personal.email}</p>
                    </div>
                </div>
                <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div className="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <MapPin size={18} />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-600 font-medium">Location</p>
                        <p className="text-sm font-semibold text-gray-900 truncate">{lead.country}</p>
                    </div>
                </div>
                <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div className="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <Calendar size={18} />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-600 font-medium">Applied On</p>
                        <p className="text-sm font-semibold text-gray-900 truncate">{lead.submittedAt}</p>
                    </div>
                </div>
            </div>

            {/* Event Registration Form — shown when this lead came in via
                an event registration. Rebuilt server-side from wherever
                each field's value landed (lead columns, work_info JSON,
                educationExps/studyPlans rows, event_response for custom
                fields). Read-only snapshot of what the lead submitted. */}
            {eventRegistration && <EventRegistrationCard data={eventRegistration} />}

            {/* AI Analysis Section */}
            {aiStatus !== 'pending' && (
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/30">
                        <div className="w-8 h-8 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center">
                            <TrendingUp size={18} />
                        </div>
                        <h2 className="text-lg font-bold text-gray-900">AI Eligibility Analysis</h2>
                        {aiStatus === 'processing' && (
                            <span className="ml-auto flex items-center gap-2 text-xs font-bold text-blue-600 bg-blue-50 px-3 py-1 rounded-full border border-blue-100">
                                <Clock size={12} className="animate-spin" /> Processing...
                            </span>
                        )}
                        {aiStatus === 'failed' && (
                            <span className="ml-auto flex items-center gap-2 text-xs font-bold text-red-600 bg-red-50 px-3 py-1 rounded-full border border-red-100">
                                <AlertTriangle size={12} /> Failed
                            </span>
                        )}
                    </div>

                    {aiStatus === 'completed' && aiAnalysis && (
                        <div className="p-6 space-y-6">
                            {/* Overall Score + Pathway Hero */}
                            <div className="flex flex-col sm:flex-row items-center gap-8 p-6 bg-gray-50/50 rounded-2xl border border-gray-100">
                                <ScoreCircle score={aiAnalysis.overall_score ?? 0} />
                                <div className="flex-1 text-center sm:text-left">
                                    <p className="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Overall Eligibility Score</p>
                                    <p className={`text-sm font-bold mb-3 ${getScoreLevel(aiAnalysis.overall_score ?? 0).color}`}>
                                        {getScoreLevel(aiAnalysis.overall_score ?? 0).label} Candidate
                                    </p>
                                    {aiAnalysis.recommended_pathway && (
                                        <div className="inline-flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-lg px-4 py-2">
                                            <GraduationCap size={14} className="text-purple-600" />
                                            <span className="text-xs font-bold text-purple-800">Recommended: {aiAnalysis.recommended_pathway}</span>
                                        </div>
                                    )}
                                    {aiAnalysis.pathway_reasoning && (
                                        <p className="text-xs text-gray-600 mt-3 leading-relaxed">{aiAnalysis.pathway_reasoning}</p>
                                    )}
                                </div>
                            </div>

                            {/* Category Score Cards */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <ScoreCard label="Financial Readiness" score={aiAnalysis.categories?.financial_readiness?.score ?? 0} max={50} summary={aiAnalysis.categories?.financial_readiness?.summary} />
                                <ScoreCard label="Education" score={aiAnalysis.categories?.education?.score ?? 0} max={25} summary={aiAnalysis.categories?.education?.summary} />
                                <ScoreCard label="Work Experience" score={aiAnalysis.categories?.work_experience?.score ?? 0} max={15} summary={aiAnalysis.categories?.work_experience?.summary} />
                                <ScoreCard label="Immigration & Character" score={aiAnalysis.categories?.immigration_risk?.score ?? 0} max={10} summary={aiAnalysis.categories?.immigration_risk?.summary} />
                            </div>

                            {/* Strengths & Concerns */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {aiAnalysis.strengths?.length > 0 && (
                                    <div className="bg-emerald-50/50 border border-emerald-100 rounded-xl p-5">
                                        <h4 className="text-[10px] font-bold text-emerald-700 uppercase tracking-widest mb-4 flex items-center gap-2">
                                            <CheckCircle2 size={14} /> Strengths
                                        </h4>
                                        <ul className="space-y-2.5">
                                            {aiAnalysis.strengths.map((s, i) => (
                                                <li key={i} className="flex items-start gap-2.5 text-xs text-gray-700 leading-relaxed">
                                                    <span className="w-1 h-1 rounded-full bg-emerald-400 mt-1.5 flex-shrink-0" />
                                                    {s}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                {aiAnalysis.concerns?.length > 0 && (
                                    <div className="bg-amber-50/50 border border-amber-100 rounded-xl p-5">
                                        <h4 className="text-[10px] font-bold text-amber-700 uppercase tracking-widest mb-4 flex items-center gap-2">
                                            <AlertTriangle size={14} /> Concerns
                                        </h4>
                                        <ul className="space-y-2.5">
                                            {aiAnalysis.concerns.map((c, i) => (
                                                <li key={i} className="flex items-start gap-2.5 text-xs text-gray-700 leading-relaxed">
                                                    <span className="w-1 h-1 rounded-full bg-amber-400 mt-1.5 flex-shrink-0" />
                                                    {c}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>

                            {/* Summary */}
                            {aiAnalysis.summary && (
                                <div className="bg-blue-50/30 border border-blue-100 rounded-xl p-5">
                                    <h4 className="text-[10px] font-bold text-blue-700 uppercase tracking-widest mb-3">AI Summary</h4>
                                    <p className="text-sm text-gray-700 leading-relaxed">{aiAnalysis.summary}</p>
                                </div>
                            )}
                        </div>
                    )}

                    {aiStatus === 'processing' && (
                        <div className="p-10 text-center">
                            <div className="w-10 h-10 border-3 border-gray-200 border-t-purple-500 rounded-full animate-spin mx-auto mb-4" />
                            <p className="text-sm text-gray-600 font-medium">AI analysis is currently in progress.</p>
                            <p className="text-xs text-gray-500 mt-1">Refresh the page to check for updates.</p>
                        </div>
                    )}

                    {aiStatus === 'failed' && (
                        <div className="p-10 text-center">
                            <AlertTriangle size={24} className="text-red-400 mx-auto mb-3" />
                            <p className="text-sm text-gray-600 font-medium">The automated analysis could not be completed.</p>
                            <p className="text-xs text-gray-500 mt-1">Please review the lead data manually.</p>
                        </div>
                    )}
                </div>
            )}


            {/* ── 9-section editable Personal Info layout ─────────────
                Each card uses the shared <Section/> primitive that
                manages its own edit/save/cancel state and posts a
                partial payload to /admin/leads/{id}/personal. Only ONE
                section can be in edit mode at a time — `currentEditKey`
                gates the Edit buttons on the others.

                The order matches the new build spec:
                1 Identity · 2 Passport · 3 Current NZ Visa · 4 Study
                Plans · 5 Financial · 6 Employment · 7 Education · 8
                Family · 9 Health & Character. */}
            <div className="grid grid-cols-1 gap-6">

                {/* 1. Personal Identity */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={1}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="1. Personal Information"
                    icon={User}
                    initial={snapshotForSection(backendLead, 1)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="First Name *" field="first_name" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Middle Name" field="middle_name" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Surname" field="last_name" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Suffix" field="suffix" form={form} setF={setF} errors={errors} placeholder="Jr., Sr., III…" />
                            <Field editing={editing} label="Other Names" field="other_names" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Preferred Name" field="preferred_name" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Email" field="email" form={form} setF={setF} errors={errors} type="email" />
                            <Field editing={editing} label="Phone" field="phone" form={form} setF={setF} errors={errors} type="tel" />
                            <Field editing={editing} label="WhatsApp" field="whatsapp" form={form} setF={setF} errors={errors} type="tel" />
                            <Field editing={editing} label="Referral" field="referral" form={form} setF={setF} errors={errors} placeholder="Who referred them" />
                            <Field editing={editing} label="Gender" field="gender" form={form} setF={setF} errors={errors} type="select" options={GENDER_OPTIONS} />
                            <Field editing={editing} label="Marital Status" field="marital_status" form={form} setF={setF} errors={errors} type="select" options={MARITAL_OPTIONS} />
                            <Field editing={editing} label="Date of Birth" field="dob" form={form} setF={setF} errors={errors} type="date" />
                            {/* Age is derived from DOB by the Lead model
                                (see getAgeAttribute) — read-only, always
                                shown so staff don't have to do the math. */}
                            <DataRow label="Age" value={form.age != null && form.age !== '' ? `${form.age}` : null} />
                            <Field editing={editing} label="Country of Birth" field="country_of_birth" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Place of Birth" field="place_of_birth" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Citizenship" field="citizenship" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Current Country" field="residence_country" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="State / Province" field="residence_state" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Current City" field="residence_city" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Address Line 1" field="residence_address_line_1" form={form} setF={setF} errors={errors} fullWidth />
                            <Field editing={editing} label="Address Line 2" field="residence_address_line_2" form={form} setF={setF} errors={errors} fullWidth />
                            <Field editing={editing} label="Postcode" field="residence_address_postcode" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Continuous NZ Residence" field="has_been_in_nz_continuously" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Continuous Residence (months)" field="nz_continuous_residence_months" form={form} setF={setF} errors={errors} type="number" show={form.has_been_in_nz_continuously === true || form.has_been_in_nz_continuously === '1'} />
                        </FieldGrid>
                    )}
                </Section>

                {/* 2. Passport & Identity Documents */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={2}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="2. Passport & Identity Documents"
                    icon={FileText}
                    initial={snapshotForSection(backendLead, 2)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="Has Passport" field="has_passport" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Passport Number" field="passport_number"
                                form={form} setF={setF} errors={errors}
                                show={form.has_passport === true || form.has_passport === '1'}
                                hint="Encrypted at rest." />
                            <Field editing={editing} label="Issuing Country" field="passport_issuing_country"
                                form={form} setF={setF} errors={errors}
                                show={form.has_passport === true || form.has_passport === '1'} />
                            <Field editing={editing} label="Issue Date" field="passport_issue_date"
                                form={form} setF={setF} errors={errors} type="date"
                                show={form.has_passport === true || form.has_passport === '1'} />
                            <Field editing={editing} label="Expiry Date" field="passport_expiry"
                                form={form} setF={setF} errors={errors} type="date"
                                show={form.has_passport === true || form.has_passport === '1'} />
                        </FieldGrid>
                    )}
                </Section>

                {/* 3. Current NZ Visa */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={3}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="3. Current NZ Visa"
                    icon={Globe}
                    initial={snapshotForSection(backendLead, 3)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="Visa Applying For" field="inz_visa_type" form={form} setF={setF} errors={errors} placeholder="e.g. Student Visa, Tourist Visa" hint="From the registration form's “Visa applying for”." fullWidth />
                            <Field editing={editing} label="Current Visa Type" field="current_nz_visa_type" form={form} setF={setF} errors={errors} placeholder="e.g. AEWV, Student Visa, Visitor" />
                            <Field editing={editing} label="Visa Number" field="current_nz_visa_number" form={form} setF={setF} errors={errors} hint="Encrypted at rest." />
                            <Field editing={editing} label="Issued Date" field="current_nz_visa_issued_date" form={form} setF={setF} errors={errors} type="date" />
                            <Field editing={editing} label="Expiry Date" field="current_nz_visa_expiry_date" form={form} setF={setF} errors={errors} type="date" />
                            <Field editing={editing} label="Previous NZ Visa Type" field="previous_nz_visa_type" form={form} setF={setF} errors={errors} fullWidth />
                        </FieldGrid>
                    )}
                </Section>

                {/* 4. Study Plans in NZ */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={4}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="4. Study Plans in New Zealand"
                    icon={BookOpen}
                    initial={snapshotForSection(backendLead, 4)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="Preferred Course" field="preferred_course" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Preferred Qualification Level" field="preferred_qualification_level" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Preferred City of Study" field="preferred_city_of_study" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Preferred Intake" field="preferred_intake" form={form} setF={setF} errors={errors} placeholder="e.g. Feb 2027" />
                            <Field editing={editing} label="Target Institution" field="target_institution" form={form} setF={setF} errors={errors} fullWidth />
                            <Field editing={editing} label="English Test Type" field="english_test_type" form={form} setF={setF} errors={errors} type="select" options={ENGLISH_TEST_OPTIONS} />
                            <Field editing={editing} label="English Test Date" field="english_test_date" form={form} setF={setF} errors={errors} type="date" />
                            <Field editing={editing} label="Overall Score" field="english_test_overall_score" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Listening" field="english_test_listening" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Reading" field="english_test_reading" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Writing" field="english_test_writing" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Speaking" field="english_test_speaking" form={form} setF={setF} errors={errors} type="number" />
                        </FieldGrid>
                    )}
                </Section>

                {/* 5. Financial Information */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={5}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="5. Financial Information"
                    icon={DollarSign}
                    initial={snapshotForSection(backendLead, 5)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="Funding Source" field="funding_source" form={form} setF={setF} errors={errors} type="select" options={FUNDING_OPTIONS} />
                            <Field editing={editing} label="Estimated Total Cost (NZD)" field="estimated_total_cost_nzd" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Available Funds (NZD)" field="available_funds_nzd" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Annual Income" field="annual_income_nzd" form={form} setF={setF} errors={errors} type="number" />
                            <Field editing={editing} label="Income Currency" field="annual_income_currency" form={form} setF={setF} errors={errors} placeholder="NZD" />
                            <Field editing={editing} label="Supports Partner / Dependents" field="supports_partner_or_dependents" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Owns Property in Home Country" field="has_property_in_home_country" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Bank Funds Evidence Provided" field="bank_funds_evidence_provided" form={form} setF={setF} errors={errors} type="boolean" />
                        </FieldGrid>
                    )}
                </Section>

                {/* 6. Employment */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={6}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="6. Employment"
                    icon={Briefcase}
                    initial={snapshotForSection(backendLead, 6)}
                >
                    {({ editing, form, setF, errors }) => {
                        const isEmployed = form.employment_type === 'Employed' || form.employment_type === 'Self-employed';
                        return (
                            <FieldGrid>
                                <Field editing={editing} label="Employment Type" field="employment_type" form={form} setF={setF} errors={errors} type="select" options={EMPLOYMENT_OPTIONS} />
                                <Field editing={editing} label="Years of Relevant Experience" field="years_of_relevant_experience" form={form} setF={setF} errors={errors} type="number" />
                                <Field editing={editing} label="Current Employer Name" field="current_employer_name" form={form} setF={setF} errors={errors} show={isEmployed} fullWidth />
                                <Field editing={editing} label="Position Title" field="current_position_title" form={form} setF={setF} errors={errors} show={isEmployed} />
                                <Field editing={editing} label="Employer Country" field="current_employer_country" form={form} setF={setF} errors={errors} show={isEmployed} />
                                <Field editing={editing} label="Employer Phone" field="current_employer_phone" form={form} setF={setF} errors={errors} show={isEmployed} />
                                <Field editing={editing} label="Employer Email" field="current_employer_email" form={form} setF={setF} errors={errors} type="email" show={isEmployed} />
                                <Field editing={editing} label="Employment Start Date" field="current_employment_start_date" form={form} setF={setF} errors={errors} type="date" show={isEmployed} />
                                <Field editing={editing} label="Current Salary (NZD)" field="current_salary_nzd" form={form} setF={setF} errors={errors} type="number" show={isEmployed} />
                                <Field editing={editing} label="ANZSCO-listed Role" field="has_anzsco_listed_role" form={form} setF={setF} errors={errors} type="boolean" />
                                <Field editing={editing} label="ANZSCO Code" field="anzsco_code" form={form} setF={setF} errors={errors} show={form.has_anzsco_listed_role === true || form.has_anzsco_listed_role === '1'} />
                                <Field editing={editing} label="NZ Professional Registration" field="has_nz_professional_registration" form={form} setF={setF} errors={errors} type="boolean" />
                                <Field editing={editing} label="Registration Body" field="nz_professional_registration_body" form={form} setF={setF} errors={errors} show={form.has_nz_professional_registration === true || form.has_nz_professional_registration === '1'} fullWidth />
                            </FieldGrid>
                        );
                    }}
                </Section>

                {/* 7. Education Background */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={7}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="7. Education Background"
                    icon={GraduationCap}
                    initial={snapshotForSection(backendLead, 7)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="Highest Qualification" field="highest_qualification" form={form} setF={setF} errors={errors} type="select" options={QUALIFICATION_OPTIONS} />
                            <Field editing={editing} label="Year Completed" field="highest_qualification_year_completed" form={form} setF={setF} errors={errors} type="number" placeholder="YYYY" />
                            <Field editing={editing} label="Field of Study" field="highest_qualification_field" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="Country of Study" field="highest_qualification_country" form={form} setF={setF} errors={errors} />
                            <Field editing={editing} label="NZQA Assessment Done" field="has_nzqa_assessment" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="NZQA Level" field="nzqa_assessment_level" form={form} setF={setF} errors={errors} show={form.has_nzqa_assessment === true || form.has_nzqa_assessment === '1'} />
                        </FieldGrid>
                    )}
                </Section>

                {/* 8. Family & Partner */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={8}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="8. Family & Partner"
                    icon={UserIcon}
                    initial={snapshotForSection(backendLead, 8)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid>
                            <Field editing={editing} label="Has Children" field="has_children" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Number of Children" field="number_of_children" form={form} setF={setF} errors={errors} type="number" show={form.has_children === true || form.has_children === '1'} />
                            <Field editing={editing} label="Has Dependent Partner" field="has_dependent_partner" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Partner in NZ" field="partner_in_nz" form={form} setF={setF} errors={errors} type="boolean" />
                            <Field editing={editing} label="Intends to Bring Family" field="intends_to_bring_family" form={form} setF={setF} errors={errors} type="boolean" fullWidth />
                            <Field
                                editing={editing}
                                label="Other Dependent Children — Details"
                                field="dependent_children_notes"
                                form={form}
                                setF={setF}
                                errors={errors}
                                type="textarea"
                                show={form.has_children === true || form.has_children === '1'}
                                hint="For each other dependent child, list: full name, DOB, gender, country of birth, citizenship and residence, plus passport details if held. (Per-child structured records will replace this in a future build.)"
                                fullWidth
                            />
                        </FieldGrid>
                    )}
                </Section>

                {/* 9. Health & Character */}
                <Section
                    leadId={backendLead.id}
                    sectionKey={9}
                    currentEditKey={currentEditKey}
                    setCurrentEditKey={setCurrentEditKey}
                    editAll={editAll}
                    title="9. Health & Character"
                    icon={AlertTriangle}
                    initial={snapshotForSection(backendLead, 9)}
                >
                    {({ editing, form, setF, errors }) => (
                        <FieldGrid cols={1}>
                            <FieldGrid>
                                <Field editing={editing} label="Health Disclosure" field="has_health_disclosure" form={form} setF={setF} errors={errors} type="boolean" />
                                <Field editing={editing} label="Character Disclosure" field="has_character_disclosure" form={form} setF={setF} errors={errors} type="boolean" />
                                <Field editing={editing} label="Visa Previously Declined" field="has_been_declined_visa" form={form} setF={setF} errors={errors} type="boolean" />
                                <Field editing={editing} label="Criminal Record" field="has_criminal_record" form={form} setF={setF} errors={errors} type="boolean" />
                            </FieldGrid>
                            <Field editing={editing} label="Health Disclosure Notes (min 10 chars)" field="health_disclosure_notes" form={form} setF={setF} errors={errors} type="textarea"
                                show={form.has_health_disclosure === true || form.has_health_disclosure === '1'} fullWidth />
                            <Field editing={editing} label="Character Disclosure Notes (min 10 chars)" field="character_disclosure_notes" form={form} setF={setF} errors={errors} type="textarea"
                                show={form.has_character_disclosure === true || form.has_character_disclosure === '1'} fullWidth />
                            <Field editing={editing} label="Declined Visa Details (min 10 chars)" field="declined_visa_details" form={form} setF={setF} errors={errors} type="textarea"
                                show={form.has_been_declined_visa === true || form.has_been_declined_visa === '1'} fullWidth />
                            <Field editing={editing} label="Criminal Record Details (min 10 chars)" field="criminal_record_details" form={form} setF={setF} errors={errors} type="textarea"
                                show={form.has_criminal_record === true || form.has_criminal_record === '1'} fullWidth />
                            <Field
                                editing={editing}
                                label="Meets 184-day NZ Presence Rule (2 Years)"
                                field="meets_184_day_rule_two_years"
                                form={form}
                                setF={setF}
                                errors={errors}
                                type="boolean"
                                hint="PRV applicants must have spent at least 184 days in NZ in each of the last two years."
                                fullWidth
                            />
                        </FieldGrid>
                    )}
                </Section>

                {/* Registration & imported answers — JSON-bucketed fields
                    (partner/spouse, children, pathway, registration extras)
                    that have no dedicated column. Self-hides when empty. */}
                <ImportedProfileSections lead={backendLead} />

            </div>
            </div>
        </div>
        </WriteBaseContext.Provider>
    );
}

// ── Imported profile sections — renders the JSON-bucketed fields from
// the CSV import (education / work / partner / children / intent) inside
// the Personal Info tab. Only sub-cards with actual data render.

function ImportedProfileSections({ lead }) {
    const edu    = lead?.education_notes || {};
    const work   = lead?.work_info       || {};
    const family = lead?.family_info     || {};

    // Helpers — extract known keys + leave the original JSON visible
    // (handy when the importer brings in fields we haven't named yet).
    const eduFields = [
        { k: 'current_education_attainment', label: 'Current education' },
        { k: 'bachelor_course',              label: "Bachelor's degree course" },
    ];
    const workFields = [
        { k: 'current_job',      label: 'Current job / occupation' },
        { k: 'current_location', label: 'Current location' },
    ];
    const intentFields = [
        { k: 'pathway',                label: 'Pathway interested in' },
        { k: 'willing_to_invest',      label: 'Willing to invest in study abroad' },
        { k: 'willing_to_proceed',     label: 'Willing to proceed with NZ student visa' },
        { k: 'preferred_contact_time', label: 'Preferred contact time' },
    ];
    const partnerFields = [
        { k: 'partner_name',             label: 'Partner / spouse name' },
        { k: 'partner_age',              label: 'Partner age' },
        { k: 'partner_education',        label: 'Partner education' },
        { k: 'partner_education_other',  label: 'Partner education (other)' },
        { k: 'partner_work_experience',  label: 'Partner work experience' },
        { k: 'partner_years_experience', label: 'Partner years of experience' },
    ];
    const childrenFields = [
        { k: 'number_of_children',         label: 'Number of children' },
        { k: 'children_ages',              label: "Children's ages" },
        { k: 'will_bring_children',        label: 'Will you bring your children' },
        { k: 'will_bring_children_other', label: 'Children — other answer' },
    ];
    // Registration-only extras that have no dedicated Lead column — captured
    // in education_notes by the registration form. Surfaced here so nothing
    // the applicant submitted is lost.
    const registrationFields = [
        { k: 'pathway_interest', label: 'Pathway interested in' },
        { k: 'age',              label: 'Age (as registered)' },
        { k: 'experience',       label: 'Experience' },
        { k: 'advisor_question', label: 'Question for our advisor' },
    ];

    const has = (bag, fields) => fields.some((f) => bag[f.k]);

    const anything = has(family, intentFields) || has(edu, eduFields) || has(work, workFields)
        || has(family, partnerFields) || has(family, childrenFields) || has(edu, registrationFields);

    // Nothing to show (e.g. a manually-created lead with no JSON buckets) —
    // render nothing rather than an empty card.
    if (! anything) return null;

    return (
        <SectionCard title="Registration &amp; Other Details" icon={FileText}>
            <div className="space-y-6">
                <SubSection title="Pathway & intent" show={has(family, intentFields)}>
                    <ProfileGrid bag={family} fields={intentFields} />
                </SubSection>

                <SubSection title="Registration answers" show={has(edu, registrationFields)}>
                    <ProfileGrid bag={edu} fields={registrationFields} />
                </SubSection>

                <SubSection title="Education" show={has(edu, eduFields)}>
                    <ProfileGrid bag={edu} fields={eduFields} />
                </SubSection>

                <SubSection title="Work" show={has(work, workFields)}>
                    <ProfileGrid bag={work} fields={workFields} />
                </SubSection>

                <SubSection title="Partner / spouse" show={has(family, partnerFields)}>
                    <ProfileGrid bag={family} fields={partnerFields} />
                </SubSection>

                <SubSection title="Children" show={has(family, childrenFields)}>
                    <ProfileGrid bag={family} fields={childrenFields} />
                </SubSection>
            </div>
        </SectionCard>
    );
}

function SubSection({ title, show, children }) {
    if (!show) return null;
    return (
        <div className="pt-1">
            <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-3">{title}</h3>
            {children}
        </div>
    );
}

// Presentational helpers — defined at module scope (NOT inside the page
// component) so they keep a stable identity across re-renders. Defining them
// inline would remount their subtree on every keystroke, stealing focus from
// inputs in the inline Personal Information editor.

function DataRow({ label, value, fullWidth = false }) {
    return (
        <div className={`flex flex-col gap-1 ${fullWidth ? 'col-span-1 md:col-span-2' : ''}`}>
            <span className="text-xs font-semibold text-gray-500 tracking-wide uppercase">{label}</span>
            <span className="text-sm font-medium text-gray-900 bg-gray-50/50 px-3 py-2 rounded-lg border border-gray-100">{value || '-'}</span>
        </div>
    );
}

function SectionCard({ title, icon: Icon, children, action = null }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/30">
                <div className="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">
                    <Icon size={18} />
                </div>
                <h2 className="text-lg font-bold text-gray-900">{title}</h2>
                {action && <div className="ml-auto">{action}</div>}
            </div>
            <div className="p-6">{children}</div>
        </div>
    );
}

// Read-only DataRow normally; an inline input/select while the Personal
// Information card is in edit mode. `form` + `setPF` come from the page.
function PersonalField({ editing, label, field, value, form, setPF, type = 'text', options = null, fullWidth = false }) {
    if (!editing) return <DataRow label={label} value={value} fullWidth={fullWidth} />;
    const cls = "text-sm font-medium text-gray-900 bg-white px-3 py-2 rounded-lg border border-gray-300 outline-none focus:border-gray-900 transition-colors";
    return (
        <div className={`flex flex-col gap-1 ${fullWidth ? 'col-span-1 md:col-span-2' : ''}`}>
            <span className="text-xs font-semibold text-gray-500 tracking-wide uppercase">{label}</span>
            {options ? (
                <select value={form[field] ?? ''} onChange={setPF(field)} className={cls}>
                    <option value="">—</option>
                    {options.map((o) => <option key={o} value={o}>{o}</option>)}
                </select>
            ) : (
                <input type={type} value={form[field] ?? ''} onChange={setPF(field)} className={cls} />
            )}
        </div>
    );
}

// Personal Information edit options — used by the inline editor on the
// Personal Information card.
const MARITAL_OPTIONS = ['Single', 'Married', 'De Facto', 'Civil Union', 'Separated', 'Divorced', 'Widowed'];
const GENDER_OPTIONS  = ['Male', 'Female', 'Other'];
const FUNDING_OPTIONS = ['Self', 'Sponsor', 'Scholarship', 'Loan', 'Mixed'];
const EMPLOYMENT_OPTIONS = ['Employed', 'Self-employed', 'Unemployed', 'Student', 'Retired'];
const QUALIFICATION_OPTIONS = ['Doctorate', 'Master', 'Postgraduate Diploma', 'Bachelor', 'Diploma', 'Certificate', 'High School', 'None'];
const ENGLISH_TEST_OPTIONS = ['IELTS', 'TOEFL', 'PTE', 'NZCEL', 'Cambridge', 'Duolingo'];

// ── Editable Personal-tab primitives ───────────────────────────────────
//
// A `Section` wraps SectionCard with self-contained edit/save/cancel
// state. Children receive `{ editing, form, setF, errors }` via render
// prop so each Field knows whether to render as a DataRow or an input.
// Save POSTs only the changed-and-included keys to /admin/leads/{id}/personal
// so two sections being edited won't overwrite each other.

function Section({ leadId, sectionKey, currentEditKey, setCurrentEditKey, editAll = false,
                   title, icon, initial, children }) {
    const writeBase = useWriteBase();
    // `editAll` (header "Edit Lead" button) opens every section at once;
    // otherwise the single-section gate applies. A read-only viewer never
    // enters edit mode, which also hides this card's Edit / Save controls.
    const editing = editAll || currentEditKey === sectionKey;
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    // Reset the form to the initial snapshot whenever (a) the lead prop
    // changes (page refresh after a sibling section saved) or (b) edit
    // mode is exited via Cancel.
    useEffect(() => { setForm(initial); }, [JSON.stringify(initial)]);

    const setF = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const startEdit = () => {
        setErrors({});
        setForm(initial);
        setCurrentEditKey(sectionKey);
    };
    const cancel = () => {
        setErrors({});
        setForm(initial);
        // In edit-all mode the per-section Cancel just resets this card's
        // fields; the global "Done Editing" button exits the mode.
        if (! editAll) setCurrentEditKey(null);
    };
    const save = () => {
        setSaving(true);
        setErrors({});
        // The controller's required `first_name` rule means we must
        // always include it on every section save, even if the section
        // doesn't edit name fields directly. Add it to the payload as a
        // pass-through guard.
        const payload = { ...form, first_name: form.first_name ?? initial.first_name };
        router.post(`${writeBase}/leads/${leadId}/personal`, payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { if (! editAll) setCurrentEditKey(null); },
            onError: (errs) => setErrors(errs || {}),
            onFinish: () => setSaving(false),
        });
    };

    // In edit-all mode the per-section Edit button is suppressed (every
    // card is already open); only Save / Cancel show.
    const action = editing ? (
        <div className="flex items-center gap-2">
            <button
                type="button"
                onClick={cancel}
                disabled={saving}
                className="px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors disabled:opacity-50"
            >
                Cancel
            </button>
            <button
                type="button"
                onClick={save}
                disabled={saving}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-gray-900 hover:bg-gray-800 transition-colors disabled:opacity-40"
            >
                <Check size={13} /> {saving ? 'Saving…' : 'Save'}
            </button>
        </div>
    ) : (
        <button
            type="button"
            onClick={startEdit}
            disabled={currentEditKey !== null}
            title={currentEditKey !== null ? 'Finish editing the other section first.' : 'Edit this section'}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-700 bg-white border border-gray-200 hover:bg-gray-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        >
            <Edit size={13} /> Edit
        </button>
    );

    return (
        <SectionCard title={title} icon={icon} action={action}>
            {children({ editing, form, setF, errors })}
        </SectionCard>
    );
}

/**
 * Single inline field. Falls back to DataRow when not in edit mode.
 * Supports text / email / tel / date / number / textarea / select /
 * boolean (yes/no select) inputs.
 *
 * `show` is an optional render-gate so conditional fields (revealed
 * only when a parent flag is set) can stay declarative.
 */
function Field({ editing, label, field, form, setF, errors = {},
                 type = 'text', options = null, fullWidth = false,
                 show = true, placeholder = '', hint = null }) {
    if (! show) return null;
    const raw = form[field];
    const err = errors[field];

    // Read mode — pure display.
    if (! editing) {
        let display;
        if (type === 'boolean') {
            display = raw === true || raw === 1 || raw === '1' || raw === 'true' ? 'Yes'
                : (raw === false || raw === 0 || raw === '0' || raw === 'false') ? 'No'
                : null;
        } else if (type === 'date' && raw) {
            try { display = new Date(raw).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }); }
            catch { display = String(raw); }
        } else {
            display = raw === null || raw === undefined || raw === '' ? null : String(raw);
        }
        return <DataRow label={label} value={display} fullWidth={fullWidth} />;
    }

    // Edit mode — input.
    const baseCls = `text-sm font-medium text-gray-900 bg-white px-3 py-2 rounded-lg border ${err ? 'border-red-400' : 'border-gray-300'} outline-none focus:border-gray-900 transition-colors`;

    let input;
    if (type === 'textarea') {
        input = (
            <textarea
                value={raw ?? ''}
                onChange={(e) => setF(field, e.target.value)}
                placeholder={placeholder}
                rows={3}
                className={baseCls + ' resize-y min-h-[80px]'}
            />
        );
    } else if (type === 'select') {
        input = (
            <select value={raw ?? ''} onChange={(e) => setF(field, e.target.value)} className={baseCls}>
                <option value="">—</option>
                {(options || []).map((o) => (
                    <option key={typeof o === 'string' ? o : o.value} value={typeof o === 'string' ? o : o.value}>
                        {typeof o === 'string' ? o : o.label}
                    </option>
                ))}
            </select>
        );
    } else if (type === 'boolean') {
        // Render boolean as a yes/no select so the empty option remains
        // distinguishable from explicit "No" (tri-state on the wire).
        input = (
            <select
                value={raw === true || raw === '1' || raw === 1 ? '1'
                    : raw === false || raw === '0' || raw === 0 ? '0'
                    : ''}
                onChange={(e) => {
                    const v = e.target.value;
                    setF(field, v === '' ? null : v === '1');
                }}
                className={baseCls}
            >
                <option value="">—</option>
                <option value="1">Yes</option>
                <option value="0">No</option>
            </select>
        );
    } else {
        input = (
            <input
                type={type === 'date' && raw ? 'date' : type}
                value={type === 'date' && raw ? String(raw).slice(0, 10) : raw ?? ''}
                onChange={(e) => setF(field, e.target.value)}
                placeholder={placeholder}
                className={baseCls}
            />
        );
    }

    return (
        <div className={`flex flex-col gap-1 ${fullWidth ? 'col-span-1 md:col-span-2' : ''}`}>
            <span className="text-xs font-semibold text-gray-500 tracking-wide uppercase">{label}</span>
            {input}
            {hint && ! err && <span className="text-[11px] text-gray-400 italic">{hint}</span>}
            {err && <span className="text-[11px] font-semibold text-red-600">{err}</span>}
        </div>
    );
}

// Maps a lead row's columns into the form snapshot for a given section.
// Section keys (1-9) are kept stable so saving section 2 doesn't blow
// away section 5's pending edits — even though the page enforces
// one-section-at-a-time on the UX side.
function snapshotForSection(lead, key) {
    const d = (v) => (v ? String(v).slice(0, 10) : '');
    switch (key) {
        case 1: // Personal identity
            return {
                first_name: lead.first_name || '',
                last_name: lead.last_name || '',
                middle_name: lead.middle_name || '',
                suffix: lead.suffix || '',
                other_names: lead.other_names || '',
                preferred_name: lead.preferred_name || '',
                email: lead.email || '',
                phone: lead.phone || '',
                whatsapp: lead.whatsapp || '',
                referral: lead.referral || '',
                gender: lead.gender || '',
                marital_status: lead.marital_status || '',
                dob: d(lead.dob),
                // Age is read-only in the UI (derived from dob on the
                // Lead model), so we don't send it back on save — the
                // form just displays it via the appended attribute.
                age: lead.age ?? '',
                country_of_birth: lead.country_of_birth || '',
                place_of_birth: lead.place_of_birth || '',
                citizenship: lead.citizenship || '',
                residence_city: lead.residence_city || '',
                residence_state: lead.residence_state || '',
                residence_country: lead.residence_country || '',
                residence_address_line_1: lead.residence_address_line_1 || '',
                residence_address_line_2: lead.residence_address_line_2 || '',
                residence_address_postcode: lead.residence_address_postcode || '',
                has_been_in_nz_continuously: lead.has_been_in_nz_continuously,
                nz_continuous_residence_months: lead.nz_continuous_residence_months ?? '',
            };
        case 2: // Passport
            return {
                first_name: lead.first_name || '',
                has_passport: lead.has_passport,
                passport_number: lead.passport_number || '',
                passport_issuing_country: lead.passport_issuing_country || '',
                passport_issue_date: d(lead.passport_issue_date),
                passport_expiry: d(lead.passport_expiry),
            };
        case 3: // Current NZ visa
            return {
                first_name: lead.first_name || '',
                inz_visa_type: lead.inz_visa_type || '',
                current_nz_visa_type: lead.current_nz_visa_type || '',
                current_nz_visa_number: lead.current_nz_visa_number || '',
                current_nz_visa_issued_date: d(lead.current_nz_visa_issued_date),
                current_nz_visa_expiry_date: d(lead.current_nz_visa_expiry_date),
                previous_nz_visa_type: lead.previous_nz_visa_type || '',
            };
        case 4: // Study plans
            return {
                first_name: lead.first_name || '',
                preferred_course: lead.preferred_course || '',
                preferred_qualification_level: lead.preferred_qualification_level || '',
                preferred_city_of_study: lead.preferred_city_of_study || '',
                preferred_intake: lead.preferred_intake || '',
                target_institution: lead.target_institution || '',
                english_test_type: lead.english_test_type || '',
                english_test_overall_score: lead.english_test_overall_score ?? '',
                english_test_listening: lead.english_test_listening ?? '',
                english_test_reading: lead.english_test_reading ?? '',
                english_test_writing: lead.english_test_writing ?? '',
                english_test_speaking: lead.english_test_speaking ?? '',
                english_test_date: d(lead.english_test_date),
            };
        case 5: // Financial
            return {
                first_name: lead.first_name || '',
                funding_source: lead.funding_source || '',
                estimated_total_cost_nzd: lead.estimated_total_cost_nzd ?? '',
                available_funds_nzd: lead.available_funds_nzd ?? '',
                annual_income_nzd: lead.annual_income_nzd ?? '',
                annual_income_currency: lead.annual_income_currency || 'NZD',
                supports_partner_or_dependents: lead.supports_partner_or_dependents,
                has_property_in_home_country: lead.has_property_in_home_country,
                bank_funds_evidence_provided: lead.bank_funds_evidence_provided,
            };
        case 6: // Employment
            return {
                first_name: lead.first_name || '',
                employment_type: lead.employment_type || '',
                current_employer_name: lead.current_employer_name || '',
                current_position_title: lead.current_position_title || '',
                current_employer_country: lead.current_employer_country || '',
                current_employer_phone: lead.current_employer_phone || '',
                current_employer_email: lead.current_employer_email || '',
                current_employment_start_date: d(lead.current_employment_start_date),
                current_salary_nzd: lead.current_salary_nzd ?? '',
                years_of_relevant_experience: lead.years_of_relevant_experience ?? '',
                has_anzsco_listed_role: lead.has_anzsco_listed_role,
                anzsco_code: lead.anzsco_code || '',
                has_nz_professional_registration: lead.has_nz_professional_registration,
                nz_professional_registration_body: lead.nz_professional_registration_body || '',
            };
        case 7: // Education background
            return {
                first_name: lead.first_name || '',
                highest_qualification: lead.highest_qualification || '',
                highest_qualification_field: lead.highest_qualification_field || '',
                highest_qualification_country: lead.highest_qualification_country || '',
                highest_qualification_year_completed: lead.highest_qualification_year_completed ?? '',
                has_nzqa_assessment: lead.has_nzqa_assessment,
                nzqa_assessment_level: lead.nzqa_assessment_level || '',
            };
        case 8: // Family
            return {
                first_name: lead.first_name || '',
                has_children: lead.has_children,
                number_of_children: lead.number_of_children ?? '',
                dependent_children_notes: lead.dependent_children_notes || '',
                has_dependent_partner: lead.has_dependent_partner,
                partner_in_nz: lead.partner_in_nz,
                intends_to_bring_family: lead.intends_to_bring_family,
            };
        case 9: // Health & character
            return {
                first_name: lead.first_name || '',
                has_health_disclosure: lead.has_health_disclosure,
                health_disclosure_notes: lead.health_disclosure_notes || '',
                has_character_disclosure: lead.has_character_disclosure,
                character_disclosure_notes: lead.character_disclosure_notes || '',
                has_been_declined_visa: lead.has_been_declined_visa,
                declined_visa_details: lead.declined_visa_details || '',
                has_criminal_record: lead.has_criminal_record,
                criminal_record_details: lead.criminal_record_details || '',
                meets_184_day_rule_two_years: lead.meets_184_day_rule_two_years,
            };
        default:
            return { first_name: lead.first_name || '' };
    }
}

// Wrapper grid — used inside every section's render-prop body.
function FieldGrid({ children, cols = 2 }) {
    const colsCls = cols === 1 ? 'grid-cols-1' : 'grid-cols-1 md:grid-cols-2';
    return <div className={`grid ${colsCls} gap-x-6 gap-y-5`}>{children}</div>;
}

function ProfileGrid({ bag, fields }) {
    return (
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
            {fields.filter((f) => bag[f.k] !== undefined && bag[f.k] !== null && bag[f.k] !== '').map((f) => (
                <div key={f.k} className="flex flex-col gap-1">
                    <dt className="text-xs font-semibold text-gray-500 tracking-wide uppercase">{f.label}</dt>
                    <dd className="text-sm font-medium text-gray-900 bg-gray-50/50 px-3 py-2 rounded-lg border border-gray-100">
                        {String(bag[f.k])}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

// ── INZ tracking panel — visible on the Journey tab for immigration cases.

const INZ_STATUSES = ['Lodged', 'Info Requested', 'Decision Pending', 'Approved', 'Declined', 'Withdrawn'];

function InzTrackingPanel({ lead }) {
    const writeBase = useWriteBase();
    const [form, setForm] = useState({
        inz_visa_type:   lead.inz_visa_type   || '',
        inz_lodged_at:   lead.inz_lodged_at   ? String(lead.inz_lodged_at).slice(0, 10) : '',
        inz_reference:   lead.inz_reference   || '',
        inz_status:      lead.inz_status      || '',
        inz_decision_at: lead.inz_decision_at ? String(lead.inz_decision_at).slice(0, 10) : '',
    });
    const [saving, setSaving] = useState(false);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    const submit = (e) => {
        e?.preventDefault?.();
        setSaving(true);
        router.post(`${writeBase}/leads/${lead.id}/inz`, form, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <form onSubmit={submit} className="bg-white rounded-2xl border border-amber-200 shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-amber-100 bg-gradient-to-br from-amber-50 to-white flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center">
                        <Globe size={15} />
                    </div>
                    <div>
                        <h2 className="text-sm font-bold uppercase tracking-wider text-gray-800">INZ tracking</h2>
                        <p className="text-[11px] text-gray-500 mt-0.5">Lodgement, reference, current INZ status, and decision date.</p>
                    </div>
                </div>
                <button type="submit" disabled={saving} className="px-4 py-1.5 bg-amber-600 text-white rounded-lg text-[11px] font-bold uppercase tracking-wider hover:bg-amber-700 transition-colors disabled:opacity-40">
                    {saving ? 'Saving…' : 'Save INZ'}
                </button>
            </div>

            <div className="p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <label className="block">
                    <span className="block text-[11px] font-semibold text-gray-600 mb-1.5">Visa type</span>
                    <input type="text" value={form.inz_visa_type} onChange={set('inz_visa_type')} placeholder="e.g. Student Visa"
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-amber-500" />
                </label>
                <label className="block">
                    <span className="block text-[11px] font-semibold text-gray-600 mb-1.5">Lodged on</span>
                    <input type="date" value={form.inz_lodged_at} onChange={set('inz_lodged_at')}
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-amber-500" />
                </label>
                <label className="block">
                    <span className="block text-[11px] font-semibold text-gray-600 mb-1.5">INZ reference</span>
                    <input type="text" value={form.inz_reference} onChange={set('inz_reference')} placeholder="e.g. APP1234567"
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-amber-500" />
                </label>
                <label className="block">
                    <span className="block text-[11px] font-semibold text-gray-600 mb-1.5">INZ status</span>
                    <select value={form.inz_status} onChange={set('inz_status')}
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-amber-500 bg-white">
                        <option value="">—</option>
                        {INZ_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </label>
                <label className="block">
                    <span className="block text-[11px] font-semibold text-gray-600 mb-1.5">Decision date</span>
                    <input type="date" value={form.inz_decision_at} onChange={set('inz_decision_at')}
                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:border-amber-500" />
                </label>
            </div>
        </form>
    );
}

// ── Lead actions menu — every header action except "Move to" lives in a
//    three-dot dropdown so the toolbar stays compact. Each item calls back
//    into LeadDetails; the parent still owns state (edit-all toggle, modals,
//    router.post) so this component is a thin renderer.
function LeadActionsMenu({
    editAll,
    onToggleEdit,
    onArchive,
    trackingCode,
    hasEmail,
    onSendTrackerLink,
    onSendUpdate,
    onCompose,
}) {
    const [open, setOpen] = useState(false);

    const copyTrackerLink = () => {
        const url = `${window.location.origin}/track/${trackingCode}`;
        // Just the URL — WhatsApp / SMS / email auto-link it, and a
        // duplicate "Code: ..." line is noise for the client.
        navigator.clipboard?.writeText(url).then(
            () => toast.success('Tracking link copied', { description: url }),
            () => toast.error('Could not copy — your browser blocked clipboard access')
        );
        setOpen(false);
    };

    const wrap = (fn) => () => { setOpen(false); fn && fn(); };

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => ! o)}
                title="More actions"
                className="flex items-center justify-center w-10 h-10 rounded-xl bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors shadow-sm"
            >
                <MoreVertical size={18} />
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} />
                    <div className="absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-xl border border-gray-100 py-1.5 z-40">
                        <MenuItem icon={Edit} onClick={wrap(onToggleEdit)}>
                            {editAll ? 'Done editing' : 'Edit lead'}
                        </MenuItem>

                        {trackingCode && (
                            <>
                                <div className="my-1 border-t border-gray-100" />
                                <p className="px-3 pt-1 pb-0.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">Tracker</p>
                                <MenuItem icon={Copy} onClick={copyTrackerLink}>
                                    Copy track link
                                </MenuItem>
                                <MenuItem
                                    icon={Mail}
                                    disabled={! hasEmail}
                                    disabledTitle="Lead has no email on file"
                                    onClick={wrap(onSendTrackerLink)}
                                >
                                    Send tracker link
                                </MenuItem>
                            </>
                        )}

                        <div className="my-1 border-t border-gray-100" />
                        <p className="px-3 pt-1 pb-0.5 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">Email</p>
                        <MenuItem
                            icon={Send}
                            disabled={! hasEmail}
                            disabledTitle="Lead has no email on file"
                            onClick={wrap(onSendUpdate)}
                        >
                            Send update
                        </MenuItem>
                        <MenuItem icon={Send} onClick={wrap(onCompose)}>
                            Compose message
                        </MenuItem>

                        <div className="my-1 border-t border-gray-100" />
                        <MenuItem icon={Download} onClick={wrap(() => {})}>
                            Export PDF
                        </MenuItem>

                        <div className="my-1 border-t border-gray-100" />
                        <MenuItem icon={Trash2} onClick={wrap(onArchive)} tone="danger">
                            Delete
                        </MenuItem>
                    </div>
                </>
            )}
        </div>
    );
}

function MenuItem({ icon: Icon, children, onClick, disabled, disabledTitle, tone = 'default' }) {
    const toneClass = tone === 'danger'
        ? 'text-red-600 hover:bg-red-50'
        : 'text-gray-700 hover:bg-gray-50';
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={disabled ? disabledTitle : undefined}
            className={`w-full flex items-center gap-2.5 px-3 py-2 text-sm font-medium transition-colors ${toneClass} disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent`}
        >
            <Icon size={15} className="text-gray-400 flex-shrink-0" />
            <span className="truncate">{children}</span>
        </button>
    );
}

// ── Convert menu — single "Move to" dropdown listing all three flags
//    (Student / Case / Accommodation) so the header stays compact. Active
//    flags display a check + an admin-only revert affordance inline.

const MENU_TONE = {
    emerald: { dot: 'bg-emerald-500', icon: 'text-emerald-600', badge: 'bg-emerald-100 text-emerald-700 border-emerald-200' },
    amber:   { dot: 'bg-amber-500',   icon: 'text-amber-600',   badge: 'bg-amber-100 text-amber-700 border-amber-200' },
    cyan:    { dot: 'bg-cyan-500',    icon: 'text-cyan-600',    badge: 'bg-cyan-100 text-cyan-700 border-cyan-200' },
    purple:  { dot: 'bg-purple-500',  icon: 'text-purple-600',  badge: 'bg-purple-100 text-purple-700 border-purple-200' },
};

function ConvertMenu({ lead, canRevert }) {
    const writeBase = useWriteBase();
    const [open, setOpen] = useState(false);
    const wrapRef = useRef(null);

    // Close on outside click / Escape so the menu behaves like a normal
    // disclosure widget.
    useEffect(() => {
        if (!open) return;
        const onDocClick = (e) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onDocClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const items = [
        {
            key: 'student',
            label: 'Study',
            sublabel: 'education',
            icon: <GraduationCap size={14} />,
            tone: 'emerald',
            active: !!lead.is_student,
            endpoint: `${writeBase}/leads/${lead.id}/convert-to-student`,
            revertEndpoint: `${writeBase}/leads/${lead.id}/revert-student`,
            confirmText: 'Convert to a student? They stay on the same record — all documents, notes and history come with them.',
        },
        {
            key: 'case',
            label: 'Case',
            sublabel: 'immigration',
            icon: <Globe size={14} />,
            tone: 'amber',
            active: !!lead.is_immigration_case,
            endpoint: `${writeBase}/leads/${lead.id}/convert-to-case`,
            revertEndpoint: `${writeBase}/leads/${lead.id}/revert-case`,
            confirmText: "Open as an immigration case? Lands them in Immigration's Cases queue with full document folder access.",
        },
        {
            key: 'accommodation',
            label: 'Housing',
            sublabel: 'accommodation',
            icon: <Home size={14} />,
            tone: 'cyan',
            active: !!lead.is_accommodation_client,
            endpoint: `${writeBase}/leads/${lead.id}/convert-to-accommodation`,
            revertEndpoint: `${writeBase}/leads/${lead.id}/revert-accommodation`,
            confirmText: "Open as an accommodation client? They appear in the Accommodation team's queue.",
        },
        {
            key: 'english',
            label: 'English',
            sublabel: 'language',
            icon: <Globe size={14} />,
            tone: 'purple',
            active: !!lead.is_english_student,
            endpoint: `${writeBase}/leads/${lead.id}/convert-to-english`,
            revertEndpoint: `${writeBase}/leads/${lead.id}/revert-english`,
            confirmText: "Convert to an English student? Lands them in the English team's queue (PTE / DIY / mocktest pipeline).",
        },
    ];

    const activeCount = items.filter((i) => i.active).length;

    const convert = (item) => {
        if (!confirm(`${lead.first_name ? `${lead.first_name}: ` : ''}${item.confirmText}`)) return;
        router.post(item.endpoint, {}, { preserveScroll: true, preserveState: true });
        setOpen(false);
    };

    const revert = (item) => {
        if (!confirm(`Revert this ${item.label.toLowerCase()} status? History stays attached.`)) return;
        router.post(item.revertEndpoint, {}, { preserveScroll: true, preserveState: true });
        setOpen(false);
    };

    return (
        <div ref={wrapRef} className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-gray-800 text-sm font-semibold transition-colors shadow-sm"
            >
                <ArrowRight size={15} />
                Move to
                {activeCount > 0 && (
                    <span className="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-white/20 text-[10px] font-bold tabular-nums">
                        {activeCount}
                    </span>
                )}
                <ChevronDown size={14} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute right-0 mt-2 w-72 bg-white border border-gray-200 rounded-xl shadow-lg z-30 overflow-hidden">
                    <div className="px-3 py-2 border-b border-gray-100 bg-gray-50">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500">Move this lead to…</p>
                    </div>
                    <ul className="py-1">
                        {items.map((item) => {
                            const t = MENU_TONE[item.tone] || MENU_TONE.emerald;
                            return (
                                <li key={item.key}>
                                    <div className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 group">
                                        <button
                                            type="button"
                                            onClick={() => !item.active && convert(item)}
                                            disabled={item.active}
                                            className={`flex-1 flex items-center gap-2.5 text-left text-sm ${
                                                item.active ? 'cursor-default' : 'cursor-pointer'
                                            }`}
                                        >
                                            <span className={`w-7 h-7 rounded-lg flex items-center justify-center ${t.badge}`}>
                                                <span className={t.icon}>{item.icon}</span>
                                            </span>
                                            <span className="flex flex-col leading-tight">
                                                <span className={`font-medium ${item.active ? 'text-gray-900' : 'text-gray-700'}`}>
                                                    {item.label}
                                                </span>
                                                <span className="text-[10px] italic text-gray-400">
                                                    {item.sublabel}
                                                </span>
                                            </span>
                                            {item.active && (
                                                <span className="ml-auto inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700">
                                                    <Check size={12} /> Active
                                                </span>
                                            )}
                                        </button>
                                        {item.active && canRevert && (
                                            <button
                                                type="button"
                                                onClick={() => revert(item)}
                                                className="inline-flex items-center justify-center w-7 h-7 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors"
                                                title={`Admin only — revert ${item.label}`}
                                            >
                                                <Undo2 size={12} />
                                            </button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}

// ── Tab button ─────────────────────────────────────────────────────────────

function TabButton({ active, onClick, icon, badge, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`px-3.5 py-3 text-[13px] font-semibold transition-colors border-b-2 -mb-px inline-flex items-center gap-1.5 whitespace-nowrap ${
                active
                    ? 'text-gray-900 border-gray-900'
                    : 'text-gray-400 border-transparent hover:text-gray-700'
            }`}
        >
            {children}
            {badge != null && (
                <span className="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-500 tabular-nums">
                    {badge}
                </span>
            )}
        </button>
    );
}

// ── Stats quick-row — 4 mini stat cards ───────────────────────────────────

function StatsQuickRow({ lead, tasks, tags, notes }) {
    const aiScore = lead?.ai_analysis?.overall_score;
    const openTasks = tasks.filter((t) => !t.completed).length;
    const dueToday = tasks.filter((t) => !t.completed && t.overdue).length;
    const pinnedNotes = notes.filter((n) => n.pinned).length;

    const scoreTone =
        aiScore == null
            ? { num: 'text-gray-400', accent: 'bg-gray-200' }
            : aiScore >= 70
                ? { num: 'text-emerald-700', accent: 'bg-emerald-400' }
                : aiScore >= 40
                    ? { num: 'text-amber-700', accent: 'bg-amber-400' }
                    : { num: 'text-red-700', accent: 'bg-red-400' };

    return (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <StatCard
                eyebrow="AI Score"
                icon={<Sparkles size={14} />}
                value={aiScore != null ? aiScore : '—'}
                valueSuffix={aiScore != null ? '/100' : null}
                valueClass={scoreTone.num}
                detail={aiScore != null ? `${Math.round(aiScore)}% capability` : 'Awaiting analysis'}
                spark={<MiniBar value={aiScore ?? 0} max={100} accent={scoreTone.accent} />}
            />
            <StatCard
                eyebrow="Open tasks"
                icon={<Clock size={14} />}
                value={openTasks}
                detail={dueToday > 0 ? `${dueToday} overdue` : 'On track'}
                detailTone={dueToday > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'}
                spark={
                    <div className="flex items-end gap-0.5 h-7">
                        {Array.from({ length: 7 }).map((_, i) => (
                            <span key={i} className={`flex-1 rounded-sm ${i < openTasks ? 'bg-gray-900' : 'bg-gray-100'}`} style={{ height: `${(i + 2) * 12}%` }} />
                        ))}
                    </div>
                }
            />
            <StatCard
                eyebrow="Active tags"
                icon={<TrendingUp size={14} />}
                value={tags.length}
                detail={tags.length === 0 ? 'No tags yet' : tags.slice(0, 3).map((t) => t.name).join(' · ')}
                spark={
                    tags.length > 0 ? (
                        <div className="flex gap-1 flex-wrap">
                            {tags.slice(0, 4).map((t) => (
                                <span key={t.id} className={`w-3 h-3 rounded-full ${tagColor(t.color).split(' ')[0]}`}></span>
                            ))}
                        </div>
                    ) : (
                        <div className="h-7"></div>
                    )
                }
            />
            <StatCard
                eyebrow="Notes"
                icon={<Edit size={14} />}
                value={notes.length}
                detail={pinnedNotes > 0 ? `${pinnedNotes} pinned` : (notes.length > 0 ? 'On record' : 'None yet')}
                spark={
                    <div className="flex items-center gap-1">
                        {Array.from({ length: Math.min(notes.length, 6) }).map((_, i) => (
                            <span key={i} className="w-1.5 h-7 rounded-full bg-gray-900"></span>
                        ))}
                        {notes.length === 0 && <div className="h-7"></div>}
                    </div>
                }
            />
        </div>
    );
}

function StatCard({ eyebrow, icon, value, valueSuffix, valueClass = 'text-gray-900', detail, detailTone = 'text-gray-400', spark }) {
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 flex flex-col gap-3 transition-shadow hover:shadow-md">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2 text-gray-500">
                    <span className="text-gray-400">{icon}</span>
                    <p className="text-[10px] font-bold uppercase tracking-[0.22em]">{eyebrow}</p>
                </div>
            </div>
            <div>
                <p className={`text-3xl sm:text-4xl font-bold tracking-tight tabular-nums leading-none ${valueClass}`}>
                    {value}
                    {valueSuffix && (
                        <span className="text-base sm:text-lg text-gray-400 font-medium">{valueSuffix}</span>
                    )}
                </p>
                <p className={`text-[11px] mt-1.5 ${detailTone}`}>{detail}</p>
            </div>
            <div className="mt-auto pt-1">{spark}</div>
        </div>
    );
}

function MiniBar({ value, max, accent }) {
    const pct = Math.min(100, Math.max(0, (value / max) * 100));
    return (
        <div className="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div
                className={`h-full ${accent} rounded-full transition-all duration-700`}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

// ── Circular gauge — animated SVG ring ────────────────────────────────────

function CircularGauge({ value, max = 100, size = 180, stroke = 12, tone = 'gray' }) {
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const pct = Math.min(100, Math.max(0, (value / max) * 100));
    const dashOffset = circumference * (1 - pct / 100);

    const colors = {
        gray:    { ring: '#e5e7eb', stroke: '#6b7280', text: '#374151' },
        emerald: { ring: '#d1fae5', stroke: '#10b981', text: '#065f46' },
        amber:   { ring: '#fef3c7', stroke: '#f59e0b', text: '#92400e' },
        red:     { ring: '#fee2e2', stroke: '#ef4444', text: '#991b1b' },
        brand:   { ring: '#dde6d3', stroke: '#436235', text: '#1a3c0f' },
    }[tone] || { ring: '#e5e7eb', stroke: '#6b7280', text: '#374151' };

    return (
        <div className="relative inline-flex items-center justify-center flex-shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke={colors.ring} strokeWidth={stroke} />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={colors.stroke}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={dashOffset}
                    style={{ transition: 'stroke-dashoffset 900ms cubic-bezier(0.22, 1, 0.36, 1)' }}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-4xl sm:text-5xl font-bold tabular-nums leading-none" style={{ color: colors.text }}>
                    {value != null ? value : '—'}
                </span>
                <span className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400 mt-2">
                    / {max}
                </span>
            </div>
        </div>
    );
}

// ── AI Capability hero — dashboard-style verdict + gauge + detail grid ────

const DEPARTMENT_STYLE = {
    Sales:         { dot: 'bg-rose-400',    chip: 'bg-rose-500/15 text-rose-100 border-rose-300/30' },
    Education:     { dot: 'bg-indigo-400',  chip: 'bg-indigo-500/15 text-indigo-100 border-indigo-300/30' },
    English:       { dot: 'bg-amber-400',   chip: 'bg-amber-500/15 text-amber-100 border-amber-300/30' },
    Immigration:   { dot: 'bg-emerald-400', chip: 'bg-emerald-500/15 text-emerald-100 border-emerald-300/30' },
    Accommodation: { dot: 'bg-cyan-400',    chip: 'bg-cyan-500/15 text-cyan-100 border-cyan-300/30' },
};
const departmentStyle = (d) => DEPARTMENT_STYLE[d] || { dot: 'bg-gray-400', chip: 'bg-white/10 text-white/80 border-white/15' };

function AICapabilityHero({ lead }) {
    const ai = lead?.ai_analysis || {};
    const status = lead?.ai_analysis_status;
    const score = ai.overall_score;
    const verdict = ai.verdict || ai.summary || ai.recommendation || null;
    const pathway = ai.recommended_pathway || ai.pathway || null;
    const department = ai.recommended_department || null;
    const departmentReason = ai.department_reasoning || null;
    const strengths = Array.isArray(ai.strengths) ? ai.strengths : [];
    const concerns = Array.isArray(ai.concerns) ? ai.concerns : (Array.isArray(ai.risks) ? ai.risks : []);
    const nextSteps = Array.isArray(ai.next_steps) ? ai.next_steps : [];
    const deptMeta = departmentStyle(department);

    const meta =
        score == null
            ? { tone: 'gray', label: 'Awaiting AI review', sub: status === 'processing' ? 'Analysis in progress' : 'No assessment yet' }
            : score >= 70
                ? { tone: 'brand', label: 'Strong candidate', sub: 'High likelihood of NZ success' }
                : score >= 40
                    ? { tone: 'amber', label: 'Promising — needs support', sub: 'Address concerns to strengthen profile' }
                    : { tone: 'red', label: 'Significant blockers', sub: 'Major gaps to resolve before applying' };

    return (
        <section className="bg-[#282728] text-white rounded-2xl overflow-hidden">
            {/* Hero row */}
            <div className="p-6 sm:p-8 grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-center">
                {/* Gauge */}
                <div className="lg:col-span-4 flex justify-center">
                    <CircularGauge value={score ?? 0} max={100} tone={meta.tone} size={180} stroke={14} />
                </div>

                {/* Verdict */}
                <div className="lg:col-span-8">
                    <div className="flex items-center gap-2.5 mb-3">
                        <Sparkles size={14} className="text-[#a8c89a]" />
                        <p className="text-[10px] font-bold uppercase tracking-[0.32em] text-[#a8c89a]">
                            AI Capability Assessment
                        </p>
                    </div>
                    <h2 className="text-2xl sm:text-3xl font-medium tracking-tight text-white">
                        {meta.label}
                    </h2>
                    <p className="text-sm text-white/55 font-light mt-1.5">
                        {meta.sub}
                    </p>

                    {verdict && (
                        <p className="text-sm sm:text-base text-white/80 mt-5 leading-relaxed max-w-2xl font-light">
                            {verdict}
                        </p>
                    )}
                    {!verdict && score == null && status !== 'processing' && (
                        <p className="text-sm text-white/55 mt-5 italic max-w-xl">
                            This lead hasn&apos;t completed the full assessment yet — encourage them to submit the free assessment for an AI-driven capability review.
                        </p>
                    )}

                    <div className="mt-5 flex flex-wrap items-center gap-2">
                        {pathway && (
                            <div className="inline-flex items-center gap-2.5 px-3 py-2 rounded-xl bg-white/[0.06] border border-white/10">
                                <TrendingUp size={13} className="text-[#a8c89a]" />
                                <span className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/55">
                                    Pathway
                                </span>
                                <span className="text-sm text-white font-medium">{pathway}</span>
                            </div>
                        )}
                        {department && (
                            <div
                                className={`inline-flex items-center gap-2.5 px-3 py-2 rounded-xl border ${deptMeta.chip}`}
                                title={departmentReason || ''}
                            >
                                <span className={`w-2 h-2 rounded-full ${deptMeta.dot}`} />
                                <span className="text-[10px] font-bold uppercase tracking-[0.22em] opacity-70">
                                    Route to
                                </span>
                                <span className="text-sm font-semibold">{department}</span>
                            </div>
                        )}
                    </div>
                    {department && departmentReason && (
                        <p className="text-[11px] text-white/50 mt-2.5 italic leading-relaxed max-w-2xl">
                            {departmentReason}
                        </p>
                    )}
                </div>
            </div>

            {/* Detail strip — strengths / concerns / next steps */}
            {(strengths.length > 0 || concerns.length > 0 || nextSteps.length > 0) && (
                <div className="bg-white grid grid-cols-1 md:grid-cols-3 gap-px bg-gray-100">
                    {strengths.length > 0 && (
                        <DetailCell icon={<CheckCircle2 size={14} />} label="Strengths" valueList={strengths} tone="emerald" />
                    )}
                    {concerns.length > 0 && (
                        <DetailCell icon={<AlertTriangle size={14} />} label="Concerns" valueList={concerns} tone="amber" />
                    )}
                    {nextSteps.length > 0 && (
                        <DetailCell icon={<ArrowRight size={14} />} label="Next steps" valueList={nextSteps} tone="blue" />
                    )}
                </div>
            )}
        </section>
    );
}

function DetailCell({ icon, label, value, valueList, tone = 'gray' }) {
    const toneIcon = {
        gray:    'text-gray-500 bg-gray-100',
        emerald: 'text-emerald-700 bg-emerald-100',
        amber:   'text-amber-700 bg-amber-100',
        blue:    'text-blue-700 bg-blue-100',
    }[tone];

    return (
        <div className="bg-white px-5 py-4 flex items-start gap-3.5">
            <div className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${toneIcon}`}>
                {icon}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400 mb-1.5">
                    {label}
                </p>
                {valueList ? (
                    <ul className="space-y-1">
                        {valueList.slice(0, 5).map((v, i) => (
                            <li key={i} className="text-sm text-gray-700 leading-snug flex items-start gap-1.5">
                                <span className="text-gray-400 mt-1">•</span>
                                <span>{typeof v === 'string' ? v : v?.text || JSON.stringify(v)}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-gray-800 font-medium">{value}</p>
                )}
            </div>
        </div>
    );
}

// ── Activity log — unified source touchpoints + audit log ──────────────────

const ACTIVITY_KIND_STYLE = {
    'source.origin':   { chip: "bg-[#436235]/10 text-[#436235] border-[#436235]/30", label: "Origin" },
    'source.booking':  { chip: "bg-blue-50 text-blue-700 border-blue-200",           label: "Booking" },
    'source.event':    { chip: "bg-purple-50 text-purple-700 border-purple-200",     label: "Event" },
    'source.resubmit': { chip: "bg-amber-50 text-amber-700 border-amber-200",        label: "Resubmit" },
    'lead.updated':    { chip: "bg-gray-100 text-gray-700 border-gray-200",          label: "Update" },
};
const activityStyle = (kind) =>
    ACTIVITY_KIND_STYLE[kind] || { chip: "bg-gray-100 text-gray-700 border-gray-200", label: "Event" };

// ── Journey panel — stage timeline only. Pre-screening/goal-setting are
//    captured via Internal Notes ("kind" selector); key dates live in
//    KeyMilestonesPanel above the two-column row.

function JourneyPanel({ stageTimeline = [] }) {
    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100 bg-gradient-to-br from-[#436235]/5 to-white flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-lg bg-[#436235]/10 text-[#436235] flex items-center justify-center">
                    <TrendingUp size={15} />
                </div>
                <h2 className="text-sm font-bold uppercase tracking-wider text-gray-800">Journey</h2>
            </div>

            <div className="p-6">
                <SectionLabel>Stage timeline</SectionLabel>
                <p className="text-[11px] text-gray-500 mt-1 mb-4">
                    Every stage this lead has moved through. The bold row is where they are right now.
                </p>
                <StageTimeline timeline={stageTimeline} />
            </div>
        </section>
    );
}

// Visual rules per timeline node type.
const TIMELINE_KIND = {
    stage:     { dot: 'bg-white border-gray-300',           dotCurrent: 'bg-[#436235] border-[#436235] ring-4 ring-[#436235]/15', chipFallback: 'bg-gray-100 text-gray-700 border-gray-200' },
    prescreen: { dot: 'bg-amber-100 border-amber-300',      dotCurrent: '', chipFallback: 'bg-amber-50 text-amber-800 border-amber-200' },
    goal:      { dot: 'bg-purple-100 border-purple-300',    dotCurrent: '', chipFallback: 'bg-purple-50 text-purple-800 border-purple-200' },
};

function StageTimeline({ timeline = [] }) {
    const fmtDate = (iso) => iso ? new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
    const fmtTime = (iso) => iso ? new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }) : '';

    // Reverse so the current stage sits at the top — most recent first.
    const ordered = [...timeline].reverse();

    if (timeline.length === 0) {
        return (
            <div className="text-center py-8 text-xs text-gray-400">
                No stage history yet — every transition will appear here.
            </div>
        );
    }

    return (
        <ol className="relative">
            {/* vertical spine */}
            <div className="absolute left-[7px] top-2 bottom-2 w-px bg-gray-200" aria-hidden />

            {ordered.map((t, i) => {
                const kind = TIMELINE_KIND[t.type] || TIMELINE_KIND.stage;
                const isStage = t.type === 'stage';
                // For stage rows use the canonical stage palette; pre-screen
                // and goal rows use the kind's fallback so they look
                // visually distinct without flooding the timeline.
                const chip = isStage ? stageClass(t.stage) : kind.chipFallback;
                const kindLabel = t.type === 'prescreen' ? 'Pre-screen' : t.type === 'goal' ? 'Goal' : null;

                return (
                    <li key={i} className="relative pl-7 pb-5 last:pb-0">
                        {/* node dot — filled brand-green for the current stage,
                            color-tinted for pre-screen / goal events, hollow
                            gray for past stages. */}
                        <span
                            className={`absolute left-0 top-1 w-3.5 h-3.5 rounded-full border-2 ${
                                t.is_current ? kind.dotCurrent : kind.dot
                            }`}
                            aria-hidden
                        />

                        <div className={`flex items-start justify-between gap-3 flex-wrap ${t.is_current ? '' : 'opacity-90'}`}>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    {kindLabel && (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-gray-100 text-gray-600 border border-gray-200">
                                            {kindLabel}
                                        </span>
                                    )}
                                    <span
                                        className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] border uppercase tracking-wider ${chip} ${
                                            t.is_current ? 'font-extrabold ring-1 ring-current/30' : 'font-semibold'
                                        }`}
                                    >
                                        {t.stage}
                                    </span>
                                    {t.is_current && (
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-[#436235] text-white">
                                            Current
                                        </span>
                                    )}
                                </div>

                                <p className={`mt-1.5 text-[11px] ${t.is_current ? 'font-bold text-gray-900' : 'text-gray-500'}`}>
                                    {fmtDate(t.entered_at)} <span className="text-gray-400">{fmtTime(t.entered_at)}</span>
                                </p>

                                {t.detail && (
                                    <p className="mt-1 text-[11px] text-gray-600 italic leading-snug">
                                        “{t.detail}”
                                    </p>
                                )}
                            </div>

                            {t.actor_name && (
                                <span className="text-[10px] text-gray-400 whitespace-nowrap">
                                    by {t.actor_name}
                                </span>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

// Standalone Key Milestones strip — lives above the Notes / Timeline row
// on the Journey tab so the two key dates pop as the lead's engagement
// timeline. Posts directly to the journey endpoint with just the dates.
function KeyMilestonesPanel({ lead }) {
    const writeBase = useWriteBase();
    const dateOnly = (v) => (v ? String(v).slice(0, 10) : '');
    const [dates, setDates] = useState({
        date_of_first_contact: dateOnly(lead?.date_of_first_contact),
        date_of_engagement:    dateOnly(lead?.date_of_engagement),
    });
    const [saving, setSaving] = useState(false);

    const save = (next) => {
        setDates(next);
        setSaving(true);
        router.post(`${writeBase}/leads/${lead.id}/journey`, next, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSaving(false),
        });
    };

    const daysBetween = (a, b) => Math.abs(Math.round((new Date(b) - new Date(a)) / 86400000));

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-2 bg-gradient-to-r from-blue-50/40 via-white to-[#436235]/5">
                <div className="flex items-center gap-2.5">
                    <Calendar size={15} className="text-[#436235]" />
                    <h2 className="text-xs font-bold uppercase tracking-wider text-gray-800">Key dates</h2>
                </div>
                {saving && <span className="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Saving…</span>}
            </div>

            <div className="p-5">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <MilestoneTile
                        eyebrow="First contact"
                        value={dates.date_of_first_contact}
                        onChange={(e) => save({ ...dates, date_of_first_contact: e.target.value })}
                        tone="blue"
                    />
                    <MilestoneTile
                        eyebrow="Engagement"
                        value={dates.date_of_engagement}
                        onChange={(e) => save({ ...dates, date_of_engagement: e.target.value })}
                        tone="brand"
                    />
                </div>
                {dates.date_of_first_contact && dates.date_of_engagement && (
                    <p className="text-[11px] text-gray-500 mt-3 text-center italic">
                        Took {daysBetween(dates.date_of_first_contact, dates.date_of_engagement)} days to convert from first contact to engagement.
                    </p>
                )}
            </div>
        </section>
    );
}

// Highlighted milestone card — big formatted date + "X days ago", with an
// invisible <input type="date"> overlay so clicking the tile opens the
// native date picker.
function MilestoneTile({ eyebrow, value, onChange, tone = 'blue' }) {
    const TONES = {
        blue:  { ring: 'border-blue-200 bg-gradient-to-br from-blue-50 to-white', dot: 'bg-blue-500',           eyebrow: 'text-blue-700',  num: 'text-blue-700',  glyph: 'bg-blue-100 text-blue-700' },
        brand: { ring: 'border-[#436235]/30 bg-gradient-to-br from-[#436235]/8 to-white', dot: 'bg-[#436235]', eyebrow: 'text-[#436235]', num: 'text-[#436235]', glyph: 'bg-[#436235]/10 text-[#436235]' },
    };
    const t = TONES[tone] || TONES.blue;

    const dateObj = value ? new Date(value + 'T00:00:00') : null;
    const formatted = dateObj && !isNaN(dateObj.getTime())
        ? dateObj.toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric' })
        : null;
    const daysAgo = dateObj && !isNaN(dateObj.getTime())
        ? Math.round((Date.now() - dateObj.getTime()) / 86400000)
        : null;

    return (
        <label className={`relative block rounded-2xl border-2 ${t.ring} p-4 cursor-pointer hover:shadow-md transition-shadow group`}>
            <div className="flex items-center gap-2 mb-2">
                <span className={`w-7 h-7 rounded-lg flex items-center justify-center ${t.glyph}`}>
                    <Calendar size={13} />
                </span>
                <p className={`text-[10px] font-bold uppercase tracking-[0.22em] ${t.eyebrow}`}>{eyebrow}</p>
                <Edit size={10} className="ml-auto text-gray-300 group-hover:text-gray-600 transition-colors" />
            </div>

            {formatted ? (
                <>
                    <p className={`text-xl font-bold tracking-tight tabular-nums ${t.num}`}>{formatted}</p>
                    <p className="text-[11px] text-gray-500 mt-0.5">
                        {daysAgo === 0 ? 'Today'
                            : daysAgo > 0 ? `${daysAgo} day${daysAgo === 1 ? '' : 's'} ago`
                                : `In ${Math.abs(daysAgo)} day${Math.abs(daysAgo) === 1 ? '' : 's'}`}
                    </p>
                </>
            ) : (
                <p className="text-base font-medium text-gray-400 italic">Not set</p>
            )}

            {/* Invisible native picker — sits over the tile so clicking
                anywhere opens the calendar. */}
            <input
                type="date"
                value={value}
                onChange={onChange}
                className="absolute inset-0 opacity-0 cursor-pointer"
                title={eyebrow}
            />
        </label>
    );
}

function SectionLabel({ children }) {
    return (
        <p className="text-[10px] font-bold text-gray-500 uppercase tracking-[0.22em]">{children}</p>
    );
}

// Legacy <Field label children/> helper removed — replaced by the
// editable <Field/> primitive higher in the file (used across the 9
// Personal Info section cards). No callsites left for the old shape.

function ActivityLogPanel({ activity = [] }) {
    if (activity.length === 0) {
        return (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-14 text-center">
                <Clock size={28} className="mx-auto text-gray-300 mb-3" />
                <p className="text-base font-medium text-gray-700">No activity yet</p>
                <p className="text-sm text-gray-500 font-light mt-1.5">
                    Form submissions and field updates will be logged here.
                </p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <History size={16} className="text-gray-400" />
                    <h2 className="text-sm font-bold uppercase tracking-wider text-gray-700">Activity log</h2>
                </div>
                <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                    {activity.length} {activity.length === 1 ? "event" : "events"}
                </span>
            </div>
            <ol className="divide-y divide-gray-100">
                {activity.map((entry, i) => (
                    <ActivityRow key={i} entry={entry} />
                ))}
            </ol>
        </div>
    );
}

// Friendly labels for the most common Lead columns. Anything not in this
// map falls through to a snake_case → Sentence-case fallback.
const FIELD_LABELS = {
    status:            'Stage',
    first_name:        'First name',
    last_name:         'Last name',
    email:             'Email',
    phone:             'Phone',
    source:            'Source',
    notes:             'Notes',
    dob:               'Date of birth',
    gender:            'Gender',
    marital_status:    'Marital status',
    country_of_birth:  'Country of birth',
    citizenship:       'Citizenship',
    residence_country: 'Country of residence',
    has_passport:      'Passport',
    education_notes:   'Education notes',
    gap_explanation:   'Gap explanation',
    work_info:         'Work info',
    financial_info:    'Financial info',
    immigration_info:  'Immigration info',
    character_info:    'Character info',
    health_info:       'Health info',
    family_info:       'Family info',
    portal_invited_at: 'Portal invited',
    portal_invitation_sent_at: 'Invitation sent',
};
const fieldLabel = (key) =>
    FIELD_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function ActivityRow({ entry }) {
    const fmt = (iso) => iso ? new Date(iso).toLocaleString("en-NZ", {
        day: "2-digit", month: "short", year: "numeric",
        hour: "2-digit", minute: "2-digit",
    }) : "—";

    const isSource = entry.kind?.startsWith('source.');
    const style = activityStyle(entry.kind);

    // The LogsActivity trait stores changes as { field: { old, new } } —
    // NOT { before: {}, after: {} }. Build before/after objects from that
    // per-field shape so we can render a real diff.
    const changesRaw = entry.changes || {};
    const before = {};
    const after  = {};
    !isSource && Object.entries(changesRaw).forEach(([key, val]) => {
        before[key] = val?.old;
        after[key]  = val?.new;
    });
    const isStageChange = !isSource && Object.prototype.hasOwnProperty.call(after, 'status');
    const otherKeys = !isSource ? Object.keys(after).filter((k) => k !== 'status') : [];

    return (
        <li className="px-6 py-4 flex gap-3">
            {/* Kind chip — colour-coded by source / update */}
            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-wider w-[80px] justify-center flex-shrink-0 h-fit mt-0.5 ${style.chip}`}>
                {style.label}
            </span>

            <div className="flex-1 min-w-0">
                {/* Source entries — show form/touchpoint */}
                {isSource && (
                    <>
                        <p className="text-sm font-medium text-gray-900">{entry.title}</p>
                        {entry.detail && (
                            <p className="text-[11px] text-gray-500 mt-0.5">{entry.detail}</p>
                        )}
                        <div className="flex items-center gap-3 text-[10px] text-gray-400 font-medium mt-1.5">
                            {entry.reference && <span className="font-mono">{entry.reference}</span>}
                            <span>{fmt(entry.date)}</span>
                        </div>
                    </>
                )}

                {/* Audit entries — show actor + what changed */}
                {!isSource && (
                    <>
                        <div className="flex items-baseline justify-between gap-3 flex-wrap mb-1">
                            <p className="text-sm text-gray-900">
                                <span className="font-semibold">{entry.actor_name || "System"}</span>
                                <span className="text-gray-400 font-normal ml-1.5 text-[10px] uppercase tracking-widest">· {entry.actor_role || "system"}</span>
                            </p>
                            <span className="text-[10px] text-gray-400 font-medium whitespace-nowrap">
                                {fmt(entry.date)}
                            </span>
                        </div>

                        {isStageChange && (
                            <p className="text-sm text-gray-700 flex items-center gap-2 flex-wrap">
                                <span>Moved stage to</span>
                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border uppercase ${stageClass(after.status)}`}>
                                    {after.status || '—'}
                                </span>
                            </p>
                        )}

                        {/* Field-level diff — what was edited */}
                        {otherKeys.length > 0 && (
                            <div className="mt-1.5">
                                {!isStageChange && (
                                    <p className="text-[11px] text-gray-500 mb-1">
                                        Edited {otherKeys.length} {otherKeys.length === 1 ? 'field' : 'fields'}
                                    </p>
                                )}
                                <ul className="space-y-1">
                                    {otherKeys.slice(0, 6).map((key) => (
                                        <li key={key} className="text-[11px] flex items-start gap-1.5 flex-wrap leading-relaxed">
                                            <span className="font-semibold text-gray-700">{fieldLabel(key)}:</span>
                                            <span className="line-through text-gray-400 max-w-[160px] truncate" title={String(before?.[key] ?? '')}>{formatVal(before?.[key])}</span>
                                            <ArrowRight size={10} className="text-gray-300 mt-1 flex-shrink-0" />
                                            <span className="text-gray-900 font-medium max-w-[220px] truncate" title={String(after[key] ?? '')}>{formatVal(after[key])}</span>
                                        </li>
                                    ))}
                                    {otherKeys.length > 6 && (
                                        <li className="text-[11px] text-gray-400 italic">
                                            + {otherKeys.length - 6} more
                                        </li>
                                    )}
                                </ul>
                            </div>
                        )}

                        {!isStageChange && otherKeys.length === 0 && (
                            <p className="text-sm text-gray-600">{entry.title}</p>
                        )}
                    </>
                )}
            </div>
        </li>
    );
}

// ── Legacy — kept for backward compat refs if any ──────────────────────────

function HistoryPanel({ history = [] }) {
    if (history.length === 0) {
        return (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-14 text-center">
                <Clock size={28} className="mx-auto text-gray-300 mb-3" />
                <p className="text-base font-medium text-gray-700">No history yet</p>
                <p className="text-sm text-gray-500 font-light mt-1.5">
                    Every change to this lead will be logged here with who, what, and when.
                </p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100 flex items-center gap-2.5">
                <History size={16} className="text-gray-400" />
                <h2 className="text-sm font-bold uppercase tracking-wider text-gray-700">Activity history</h2>
            </div>
            <ol className="divide-y divide-gray-100">
                {history.map((entry) => (
                    <HistoryRow key={entry.id} entry={entry} />
                ))}
            </ol>
        </div>
    );
}

function HistoryRow({ entry }) {
    const fmt = (iso) => iso ? new Date(iso).toLocaleString("en-NZ", {
        day: "2-digit", month: "short", year: "numeric",
        hour: "2-digit", minute: "2-digit",
    }) : "—";

    // The LogsActivity trait stores a `changes` payload with before/after.
    // Stage changes are the most common audit event, so they get a
    // dedicated, human-readable rendering ("moved to X" + colour diff).
    const changes = entry.changes || {};
    const before = changes.before || {};
    const after  = changes.after  || {};
    const isStageChange = Object.prototype.hasOwnProperty.call(after, 'status');

    // Other field keys that aren't `status` — used for the generic diff.
    const otherKeys = Object.keys(after).filter((k) => k !== 'status');

    return (
        <li className="px-6 py-4 flex gap-4">
            {/* Actor avatar */}
            <div className="w-9 h-9 rounded-full bg-gray-900 text-white flex items-center justify-center text-[11px] font-bold flex-shrink-0">
                {(entry.actor_name || "S").slice(0, 1).toUpperCase()}
            </div>

            <div className="flex-1 min-w-0">
                <div className="flex items-baseline justify-between gap-3 flex-wrap">
                    <p className="text-sm text-gray-900">
                        <span className="font-semibold">{entry.actor_name}</span>
                        <span className="text-gray-400 font-normal ml-1.5 text-xs uppercase tracking-widest">· {entry.actor_role}</span>
                    </p>
                    <span className="text-[11px] text-gray-400 font-medium whitespace-nowrap">
                        {fmt(entry.created_at)}
                    </span>
                </div>

                {/* Headline action — stage moves get a specific sentence
                    naming the new stage, everything else falls back to the
                    trait's generic description. */}
                {isStageChange ? (
                    <p className="text-sm text-gray-700 mt-1.5 flex items-center gap-2 flex-wrap">
                        <span>Moved this lead to</span>
                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border uppercase ${stageClass(after.status)}`}>
                            {after.status || '—'}
                        </span>
                        {before.status && (
                            <span className="text-xs text-gray-400 inline-flex items-center gap-1.5">
                                from
                                <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold border uppercase opacity-70 ${stageClass(before.status)}`}>
                                    {before.status}
                                </span>
                            </span>
                        )}
                    </p>
                ) : (
                    <p className="text-sm text-gray-600 mt-1">
                        {entry.description}
                    </p>
                )}

                {/* Other field changes (only render if there are non-stage edits) */}
                {otherKeys.length > 0 && (
                    <ul className="mt-2 space-y-0.5">
                        {otherKeys.slice(0, 6).map((key) => (
                            <li key={key} className="text-[11px] text-gray-500 font-mono">
                                <span className="text-gray-400">{key}:</span>{' '}
                                <span className="line-through text-gray-300">{formatVal(before?.[key])}</span>
                                {' → '}
                                <span className="text-gray-700">{formatVal(after[key])}</span>
                            </li>
                        ))}
                        {otherKeys.length > 6 && (
                            <li className="text-[11px] text-gray-400 italic">
                                + {otherKeys.length - 6} more
                            </li>
                        )}
                    </ul>
                )}
            </div>
        </li>
    );
}

// ── Tasks panel — follow-up reminders ────────────────────────────────────

const PRIORITY_STYLE = {
    low:    "bg-gray-100 text-gray-600 border-gray-200",
    normal: "bg-blue-50 text-blue-700 border-blue-200",
    high:   "bg-amber-50 text-amber-700 border-amber-200",
    urgent: "bg-red-100 text-red-700 border-red-200",
};

function TasksPanel({ leadId, tasks, staffOptions, currentUser }) {
    const writeBase = useWriteBase();
    const [title, setTitle] = useState('');
    const [showOptions, setShowOptions] = useState(false);
    const [dueAt, setDueAt] = useState('');
    const [priority, setPriority] = useState('normal');
    const [assigneeId, setAssigneeId] = useState(currentUser?.id || '');
    const [submitting, setSubmitting] = useState(false);

    const fmt = (iso) => iso
        ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })
        : '';

    const submit = (e) => {
        e?.preventDefault?.();
        if (!title.trim()) return;
        setSubmitting(true);
        router.post(`${writeBase}/leads/${leadId}/tasks`, {
            title: title.trim(),
            due_at: dueAt || null,
            priority,
            assignee_id: assigneeId || null,
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setTitle(''); setDueAt(''); setPriority('normal');
                setAssigneeId(currentUser?.id || '');
                setShowOptions(false);
            },
            onFinish: () => setSubmitting(false),
        });
    };

    const toggleComplete = (task) => {
        router.post(`${writeBase}/leads/${leadId}/tasks/${task.id}`,
            { completed: !task.completed },
            { preserveScroll: true, preserveState: true });
    };

    const remove = (task) => {
        confirm('Delete this task?')
            ? router.delete(`${writeBase}/leads/${leadId}/tasks/${task.id}`, { preserveScroll: true, preserveState: true })
            : null;
    };

    const open = tasks.filter((t) => !t.completed);
    const done = tasks.filter((t) => t.completed);
    const overdueCount = open.filter((t) => t.overdue).length;
    const ordered = [...open, ...done];
    const donePct = tasks.length > 0 ? Math.round((done.length / tasks.length) * 100) : 0;

    return (
        // Same shell as the Case Profile's task panel: a flat white card with its
        // heading inline, rather than a tinted header band.
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div className="flex items-center justify-between gap-3 mb-3">
                <h2 className="text-[14px] font-bold text-gray-900">
                    Tasks on this lead{' '}
                    <span className="text-[11px] font-normal text-gray-400">
                        {open.length} open · {done.length} done · from the task board
                    </span>
                </h2>
                {overdueCount > 0 && (
                    <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-red-50 text-red-700 shrink-0">
                        {overdueCount} overdue
                    </span>
                )}
            </div>
            <div className="h-1 w-full bg-gray-100 rounded-full overflow-hidden mb-3">
                <div
                    className="h-full bg-teal-500 rounded-full transition-all duration-700"
                    style={{ width: `${donePct}%` }}
                />
            </div>

            {/* Always-visible inline compose — expands to show options on focus */}
            <form onSubmit={submit} className="rounded-xl border border-gray-200 bg-gray-50/40 px-3 py-2.5 mb-3">
                <div className="flex items-center gap-2">
                    <Check size={14} className="text-gray-300 flex-shrink-0" />
                    <input
                        type="text"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        onFocus={() => setShowOptions(true)}
                        placeholder="Add a task — e.g. Call Maria about visa timing"
                        className="flex-1 bg-transparent text-sm placeholder-gray-400 outline-none py-1.5"
                    />
                    {title.trim() && (
                        <button
                            type="submit"
                            disabled={submitting}
                            className="px-3 py-1.5 bg-gray-900 text-white rounded-md text-[11px] font-bold uppercase tracking-wider hover:bg-gray-800 transition-colors disabled:opacity-40"
                        >
                            {submitting ? '…' : 'Add'}
                        </button>
                    )}
                </div>
                {showOptions && (
                    <div className="mt-2 flex flex-wrap items-center gap-2 pl-6">
                        <input
                            type="datetime-local"
                            value={dueAt}
                            onChange={(e) => setDueAt(e.target.value)}
                            className="px-2 py-1 border border-gray-200 rounded-md text-[11px] bg-white outline-none focus:border-gray-400"
                            title="Due date/time"
                        />
                        <select
                            value={priority}
                            onChange={(e) => setPriority(e.target.value)}
                            className="px-2 py-1 border border-gray-200 rounded-md text-[11px] bg-white outline-none focus:border-gray-400"
                            title="Priority"
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                        <select
                            value={assigneeId}
                            onChange={(e) => setAssigneeId(e.target.value)}
                            className="px-2 py-1 border border-gray-200 rounded-md text-[11px] bg-white outline-none focus:border-gray-400"
                            title="Assignee"
                        >
                            <option value="">Unassigned</option>
                            {staffOptions.map((u) => (
                                <option key={u.id} value={u.id}>{u.name}</option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={() => { setShowOptions(false); setTitle(''); setDueAt(''); setPriority('normal'); }}
                            className="text-[10px] text-gray-400 hover:text-gray-700 uppercase tracking-widest font-bold ml-auto"
                        >
                            Reset
                        </button>
                    </div>
                )}
            </form>

            {/* List */}
            {tasks.length === 0 ? (
                <p className="text-[12px] text-gray-400">No tasks linked to this lead yet.</p>
            ) : (
                <ul className="space-y-2">
                    {ordered.map((t) => {
                        const canDelete = currentUser && (currentUser.is_admin || currentUser.id === t.created_by);
                        const priorityStyle = PRIORITY_STYLE[t.priority] || PRIORITY_STYLE.normal;
                        const attachments = Array.isArray(t.attachments) ? t.attachments : [];
                        return (
                            <li
                                key={t.id}
                                className={`group rounded-xl border px-3 py-2.5 transition-colors ${t.completed ? 'border-gray-100 bg-white opacity-60' : t.overdue ? 'border-red-100 bg-red-50/40' : 'border-gray-100 bg-white hover:border-gray-200'}`}
                            >
                                <div className="flex items-center gap-3">
                                    {/* Checkbox */}
                                    <button
                                        type="button"
                                        onClick={() => toggleComplete(t)}
                                        className={`w-[18px] h-[18px] rounded-md border-2 flex items-center justify-center flex-shrink-0 transition-colors ${t.completed ? 'bg-teal-500 border-teal-500 text-white' : 'border-gray-300 hover:border-gray-900'}`}
                                        title={t.completed ? 'Mark incomplete' : 'Mark complete'}
                                    >
                                        {t.completed && <Check size={11} strokeWidth={3} />}
                                    </button>

                                    {/* Title + inline meta */}
                                    <div className="flex-1 min-w-0 flex items-center gap-2.5 flex-wrap">
                                        <span className={`text-sm ${t.completed ? 'line-through text-gray-400' : 'text-gray-900 font-medium'} truncate`}>
                                            {t.title}
                                        </span>
                                        {t.priority !== 'normal' && (
                                            <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${priorityStyle}`}>
                                                {t.priority}
                                            </span>
                                        )}
                                        {t.due_at && (
                                            <span className={`text-[10px] font-semibold tabular-nums ${t.overdue ? 'text-red-600' : 'text-gray-400'}`}>
                                                {t.overdue ? '⚠ ' : ''}{fmt(t.due_at)}
                                            </span>
                                        )}
                                        {attachments.length > 0 && (
                                            <span
                                                className="inline-flex items-center gap-0.5 text-[10px] font-bold uppercase tracking-wider text-gray-400"
                                                title={`${attachments.length} attachment${attachments.length === 1 ? '' : 's'}`}
                                            >
                                                <Paperclip size={10} /> {attachments.length}
                                            </span>
                                        )}
                                    </div>

                                    {/* Assignee avatar */}
                                    {t.assignee && (
                                        <span
                                            className="w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-[10px] font-bold flex items-center justify-center flex-shrink-0"
                                            title={t.assignee.name}
                                        >
                                            {t.assignee.name.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()}
                                        </span>
                                    )}

                                    {/* Hover-revealed delete */}
                                    {canDelete && (
                                        <button
                                            type="button"
                                            onClick={() => remove(t)}
                                            className="text-[10px] uppercase tracking-widest font-bold text-gray-300 hover:text-red-600 transition-colors flex-shrink-0 opacity-0 group-hover:opacity-100"
                                        >
                                            ×
                                        </button>
                                    )}
                                </div>

                                {/* Attachment thumbnails — visible when the task has files
                                    uploaded via the Task Board. Images render as 40×40
                                    previews, everything else as a typed pill with the
                                    filename truncated. Clicking opens the file in a new tab. */}
                                {attachments.length > 0 && (
                                    <div className="mt-2 ml-[30px] flex flex-wrap items-center gap-2">
                                        {attachments.map((a) => (
                                            <TaskAttachmentChip key={a.id} attachment={a} />
                                        ))}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

// Small typed chip for task attachments shown inline on the lead-detail
// Tasks card. Images use a thumbnail; other file types fall back to a
// content-type icon. Click opens the asset in a new tab — the storage url
// (Storage::disk('public')) is public-readable per LeadTaskAttachment::$appends.
function TaskAttachmentChip({ attachment: a }) {
    const ext  = (a.original_filename || '').split('.').pop()?.toUpperCase().slice(0, 4) || 'FILE';
    const mime = (a.mime_type || '').toLowerCase();
    const Icon = a.is_image ? FileImage
        : mime.startsWith('video/') ? Film
        : mime.startsWith('audio/') ? Music
        : FileText;

    if (a.is_image) {
        return (
            <a
                href={a.url}
                target="_blank"
                rel="noopener noreferrer"
                title={a.original_filename}
                className="block w-10 h-10 rounded-md overflow-hidden border border-gray-200 hover:border-gray-400 transition-colors flex-shrink-0"
            >
                <img src={a.url} alt={a.original_filename} className="w-full h-full object-cover" />
            </a>
        );
    }
    return (
        <a
            href={a.url}
            target="_blank"
            rel="noopener noreferrer"
            title={a.original_filename}
            className="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border border-gray-200 bg-white hover:bg-gray-50 hover:border-gray-400 text-[10px] font-semibold text-gray-700 max-w-[180px] transition-colors"
        >
            <Icon size={11} className="text-gray-400 flex-shrink-0" />
            <span className="truncate">{a.original_filename}</span>
            <span className="font-mono text-[9px] text-gray-400 flex-shrink-0">{ext}</span>
        </a>
    );
}

// ── Tags panel — free-form labels with auto-suggest ──────────────────────

const TAG_COLOR_STYLE = {
    gray:    "bg-gray-100 text-gray-700 border-gray-200",
    red:     "bg-red-100 text-red-700 border-red-200",
    amber:   "bg-amber-100 text-amber-700 border-amber-200",
    green:   "bg-green-100 text-green-800 border-green-200",
    blue:    "bg-blue-100 text-blue-700 border-blue-200",
    purple:  "bg-purple-100 text-purple-700 border-purple-200",
    pink:    "bg-pink-100 text-pink-700 border-pink-200",
    cyan:    "bg-cyan-100 text-cyan-700 border-cyan-200",
    emerald: "bg-emerald-100 text-emerald-700 border-emerald-200",
    fuchsia: "bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200",
};
const tagColor = (c) => TAG_COLOR_STYLE[c] || TAG_COLOR_STYLE.gray;

function TagsPanel({ leadId, tags, allTags }) {
    const writeBase = useWriteBase();
    const [input, setInput] = useState('');
    const [focused, setFocused] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const attachedIds = new Set(tags.map((t) => t.id));
    const suggestions = input.trim()
        ? allTags
            .filter((t) => t.name.toLowerCase().includes(input.trim().toLowerCase()) && !attachedIds.has(t.id))
            .slice(0, 6)
        : [];

    const attach = (name) => {
        const clean = (name || '').trim();
        if (!clean) return;
        setSubmitting(true);
        router.post(`${writeBase}/leads/${leadId}/tags`, { name: clean }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setInput(''),
            onFinish: () => setSubmitting(false),
        });
    };

    const detach = (tag) => router.delete(`${writeBase}/leads/${leadId}/tags/${tag.id}`, { preserveScroll: true, preserveState: true });

    const onKey = (e) => {
        e.key === 'Enter'
            ? (e.preventDefault(), attach(input))
            : null;
    };

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
            <div className="px-5 py-3.5 border-b border-gray-100 bg-gradient-to-br from-purple-50/40 to-white">
                <div className="flex items-center justify-between mb-2.5">
                    <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-lg bg-purple-100 text-purple-700 flex items-center justify-center">
                            <TrendingUp size={14} />
                        </div>
                        <h2 className="text-xs font-bold uppercase tracking-wider text-gray-800">Tags</h2>
                    </div>
                    <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400 tabular-nums">
                        {tags.length} {tags.length === 1 ? "tag" : "tags"}
                    </span>
                </div>
                {/* Colour ribbon preview — shows the palette of attached tags */}
                <div className="flex items-center gap-1 h-1.5">
                    {tags.length === 0 ? (
                        <div className="w-full h-full rounded-full bg-gray-100" />
                    ) : (
                        tags.map((t) => (
                            <span
                                key={t.id}
                                className={`flex-1 h-full rounded-full ${tagColor(t.color).split(' ')[0]}`}
                                title={t.name}
                            />
                        ))
                    )}
                </div>
            </div>

            {/* Single chip-row — attached chips + inline add input in one
                visual band. Feels like a real tag editor, no separate
                "Add" button row. */}
            <div className="px-5 py-3.5 relative">
                <div
                    className={`flex flex-wrap items-center gap-1.5 px-2 py-1.5 rounded-lg border transition-colors ${
                        focused ? 'border-gray-900 bg-white' : 'border-gray-200 bg-gray-50/60 hover:bg-white'
                    }`}
                >
                    {tags.map((t) => (
                        <span
                            key={t.id}
                            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider border ${tagColor(t.color)}`}
                        >
                            {t.name}
                            <button
                                type="button"
                                onClick={() => detach(t)}
                                className="opacity-50 hover:opacity-100 transition-opacity ml-0.5"
                                title="Remove tag"
                            >
                                ×
                            </button>
                        </span>
                    ))}
                    <input
                        type="text"
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        onKeyDown={onKey}
                        onFocus={() => setFocused(true)}
                        onBlur={() => setTimeout(() => setFocused(false), 150)}
                        placeholder={tags.length === 0 ? "Type a tag — HOT, BUDGET, FAMILY… press Enter to add" : "Add tag…"}
                        disabled={submitting}
                        className="flex-1 min-w-[140px] bg-transparent text-xs placeholder-gray-400 outline-none py-1 px-1"
                    />
                </div>

                {/* Suggestions popover */}
                {focused && suggestions.length > 0 && (
                    <div className="absolute z-10 left-5 right-5 mt-1 bg-white rounded-lg border border-gray-200 shadow-lg py-1">
                        <p className="px-3 pt-1 pb-1 text-[9px] font-bold text-gray-400 uppercase tracking-[0.2em]">Existing tags</p>
                        {suggestions.map((s) => (
                            <button
                                key={s.id}
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); attach(s.name); }}
                                className="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex items-center gap-2"
                            >
                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ${tagColor(s.color)}`}>
                                    {s.name}
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}

// ── Notes panel — internal staff notes ───────────────────────────────────

// ── Documents tab — checklist of required NZ application docs ────────────

// Flat checklist table — one self-contained row per required document, with
// the columns staff asked for: File · Attachment · Status (dropdown) · Notes.
// Everything happens inline: upload a file straight from the row, generate the
// system agreements, set status / notes — no drill-in page. Each section
// header row carries the per-section verify / request-revisions controls that
// gate the lead-portal flow.
function ChecklistTable({ sections = CHECKLIST, state = {}, checklistFiles = {}, lead, onSave, verifications = {}, onVerifySection, hiddenKeys = new Set(), onToggleTrack, onRemoveCustom, onDuplicateCustom, threadsByKey = {}, currentUser = null, staffOptions = [] }) {
    // Collapsible sections — collapsed by DEFAULT, mirroring the immigration
    // Case Profile Documents tab. `expanded` holds the sections the user opened.
    const [expanded, setExpanded] = useState(() => new Set());
    const toggle = (key) => setExpanded((prev) => {
        const next = new Set(prev);
        next.has(key) ? next.delete(key) : next.add(key);
        return next;
    });

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <table className="w-full text-left">
                <thead>
                    <tr className="bg-gray-50/60 border-b border-gray-100 text-[10px] font-bold uppercase tracking-widest text-gray-500">
                        <th className="pl-4 pr-2 py-3 w-10 text-center" title="Show on the public /track page"></th>
                        <th className="px-6 py-3">Document</th>
                        <th className="px-6 py-3">Attachment</th>
                        <th className="px-6 py-3 w-40">Status</th>
                        <th className="px-6 py-3 w-44">Reviewed by</th>
                        <th className="px-6 py-3 w-64">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    {sections.map((section) => {
                        const ver = verifications[section.key]?.status || null;
                        const verMeta = ver ? SECTION_STATUSES[ver] : null;
                        // Section verification — Verify / Request-revisions are
                        // now just options in this single status dropdown.
                        // Choosing "Revisions needed" prompts for an optional
                        // note to the lead.
                        const setSectionStatus = (value) => {
                            if (! value) return;
                            if (value === 'revisions_needed') {
                                const note = window.prompt('Revision note for the lead (optional):', verifications[section.key]?.notes || '');
                                onVerifySection(section.key, 'revisions_needed', note || null);
                            } else {
                                onVerifySection(section.key, value, null);
                            }
                        };
                        // Tri-state select-all: reads whether every / some / none
                        // of this section's items are currently visible in the
                        // public tracker. Flipping it hides or shows them all.
                        const itemKeys = section.items.map((it) => it.id);
                        const shownCount = itemKeys.filter((k) => ! hiddenKeys.has(k)).length;
                        const allShown = shownCount === itemKeys.length;
                        const someShown = shownCount > 0 && shownCount < itemKeys.length;
                        // "X/Y" completion count — how many items in this section
                        // have at least one uploaded file (mirrors the immigration
                        // tab's approved/total chip).
                        const withFilesCount = itemKeys.filter((k) => (checklistFiles[k]?.length || 0) > 0).length;
                        const isOpen = expanded.has(section.key);
                        return (
                            <React.Fragment key={section.key}>
                                {/* Section header — matches the immigration Case
                                    Documents tab (bg-gray-200 / border-gray-300):
                                    tracker checkbox, a chevron to collapse/expand,
                                    the section name and an X/Y count. */}
                                <tr className="bg-gray-200 border-y border-gray-300">
                                    <td className="pl-4 pr-2 py-2 w-10 text-center">
                                        <input
                                            type="checkbox"
                                            checked={allShown}
                                            ref={(el) => { if (el) el.indeterminate = someShown; }}
                                            onChange={() => onToggleTrack && onToggleTrack(itemKeys, allShown)}
                                            title="Show all of this section on the public /track page"
                                            className="w-4 h-4 rounded border-gray-400 text-gray-900 focus:ring-1 focus:ring-gray-500 cursor-pointer"
                                        />
                                    </td>
                                    <td colSpan={5} className="px-6 py-2">
                                        <div className="flex items-center justify-between gap-3 flex-wrap">
                                            <button
                                                type="button"
                                                onClick={() => toggle(section.key)}
                                                className="flex items-center gap-1.5 group"
                                                title={isOpen ? 'Collapse section' : 'Expand section'}
                                            >
                                                <ChevronDown size={13} className={`text-gray-500 group-hover:text-gray-900 transition-transform ${isOpen ? '' : '-rotate-90'}`} />
                                                <span className="text-[11px] font-bold uppercase tracking-wider text-gray-700 group-hover:text-gray-900">{section.section}</span>
                                                <span className="text-[10.5px] font-semibold text-gray-500">{withFilesCount}/{itemKeys.length}</span>
                                            </button>
                                            <select
                                                value={ver || ''}
                                                onChange={(e) => setSectionStatus(e.target.value)}
                                                className={`rounded-md border px-2 py-1 text-[10px] font-bold uppercase tracking-wider outline-none focus:border-gray-400 ${verMeta ? verMeta.chip : 'bg-white text-gray-500 border-gray-300'}`}
                                            >
                                                <option value="">Set status</option>
                                                {Object.entries(SECTION_STATUSES).map(([key, meta]) => (
                                                    <option key={key} value={key}>{meta.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                    </td>
                                </tr>
                                {isOpen && section.items.map((it) => (
                                    <ChecklistRow
                                        key={it.id}
                                        item={it}
                                        lead={lead}
                                        entry={state[it.id] || {}}
                                        files={checklistFiles[it.id] || []}
                                        onSave={onSave}
                                        hidden={hiddenKeys.has(it.id)}
                                        onToggleTrack={onToggleTrack}
                                        onRemoveCustom={it.custom ? onRemoveCustom : undefined}
                                        onDuplicateCustom={it.custom ? onDuplicateCustom : undefined}
                                        threads={threadsByKey[it.id] || []}
                                        currentUser={currentUser}
                                        staffOptions={staffOptions}
                                    />
                                ))}
                            </React.Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

// One checklist row — owns its own upload / generate state so the File,
// Attachment, Status and Notes columns all work inline without leaving the
// table.
function ChecklistRow({ item, lead, entry, files = [], onSave, hidden = false, onToggleTrack, onRemoveCustom, onDuplicateCustom, threads = [], currentUser = null, staffOptions = [] }) {
    const writeBase = useWriteBase();
    const [uploading, setUploading] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [variantOpen, setVariantOpen] = useState(false);
    const [notesOpen, setNotesOpen] = useState(false);
    const [previewDoc, setPreviewDoc] = useState(null);
    const fileInputRef = useRef(null);

    // Per-document discussion (shared thread system with immigration). A note
    // is anchored to this checklist item by anchor_key = item.id. Root notes
    // render with nested replies; the composer posts to /admin/leads/{id}/threads.
    const rootThreads = threads.filter((t) => ! t.parent_id);
    const childrenOf = (pid) => threads.filter((t) => t.parent_id === pid);
    const docAnchor = { anchor_type: 'document', anchor_key: item.id };
    const openCount = rootThreads.filter((t) => ! t.resolved_at).length;

    // System agreements that can be auto-generated from a Blade template.
    const canGenerate = item.id === 'agree.consultancy' || item.id === 'agree.engagement_english';
    const needsVariantPicker = item.id === 'agree.consultancy'; // Single / Partner
    const hasGenerated = files.some((f) => f.source === 'generated');

    const runGenerate = (variant) => {
        setGenerating(true);
        const form = new FormData();
        if (variant) form.append('variant', variant);
        router.post(`${writeBase}/leads/${lead.id}/documents/checklist/${item.id}/generate`, form, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onFinish: () => { setGenerating(false); setVariantOpen(false); },
        });
    };
    const handleGenerateClick = () => (needsVariantPicker ? setVariantOpen((o) => !o) : runGenerate(null));

    const handleFiles = (e) => {
        const picked = Array.from(e.target.files || []);
        if (picked.length === 0) return;
        const form = new FormData();
        picked.forEach((f) => form.append('files[]', f));
        setUploading(true);
        router.post(`${writeBase}/leads/${lead.id}/documents/checklist/${item.id}/upload`, form, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onFinish: () => {
                setUploading(false);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    };

    return (
        <>
        <tr className="border-b border-gray-50 hover:bg-emerald-50/20 transition-colors align-top">
            {/* Tracker checkbox — leftmost column. When unchecked, this row's
                checklist_key is pushed onto the lead's hidden_track_documents
                list and the public /track page suppresses it. */}
            <td className="pl-4 pr-2 py-3 w-10 text-center align-middle">
                <input
                    type="checkbox"
                    checked={! hidden}
                    onChange={() => onToggleTrack && onToggleTrack([item.id], ! hidden)}
                    title={hidden ? 'Hidden from the public tracker — tick to show' : 'Shown on the public tracker — untick to hide'}
                    className="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-1 focus:ring-gray-400 cursor-pointer"
                />
            </td>

            {/* File */}
            <td className="px-6 py-3">
                <div className="flex items-center gap-2.5">
                    <FileText size={14} className="text-gray-400 shrink-0" />
                    <span className="text-sm text-gray-800">{item.name}</span>
                    {item.system && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-[#436235]/10 text-[#436235] border border-[#436235]/20">
                            System
                        </span>
                    )}
                    {item.custom && (
                        <>
                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-blue-50 text-blue-700 border border-blue-200">
                                Custom
                            </span>
                            {onDuplicateCustom && (
                                <button
                                    type="button"
                                    onClick={() => onDuplicateCustom(item)}
                                    title="Duplicate this document"
                                    className="inline-flex items-center justify-center w-5 h-5 rounded text-gray-300 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                                >
                                    <Copy size={12} />
                                </button>
                            )}
                            {onRemoveCustom && (
                                <button
                                    type="button"
                                    onClick={() => onRemoveCustom(item)}
                                    title="Delete this document from the lead"
                                    className="inline-flex items-center justify-center w-5 h-5 rounded text-gray-300 hover:text-red-600 hover:bg-red-50 transition-colors"
                                >
                                    <Trash2 size={12} />
                                </button>
                            )}
                        </>
                    )}
                </div>
            </td>

            {/* Attachment — file links + inline upload + generate */}
            <td className="px-6 py-3">
                <div className="flex flex-col gap-1.5">
                    {files.map((f) => (
                        <div key={f.id} className="flex items-center gap-2 group/file">
                            {f.source === 'generated'
                                ? <Wand2 size={11} className="text-violet-500 shrink-0" />
                                : <Paperclip size={11} className="text-gray-400 shrink-0" />}
                            <span className="truncate max-w-[150px] text-[11px] font-medium text-gray-700" title={f.original_name}>{f.original_name}</span>
                            <div className="flex items-center gap-0.5">
                                <button type="button" onClick={() => setPreviewDoc(f)} title="View"
                                    className="inline-flex items-center gap-1 px-1.5 h-6 rounded text-[11px] font-semibold text-gray-500 hover:text-blue-600 hover:bg-blue-50 transition-colors">
                                    <Eye size={12} /> View
                                </button>
                                <LeadDocFileMenu doc={f} leadId={lead.id} checklistKey={item.id}
                                    basePath={`${writeBase}/leads`} canDelete canReject={Boolean(currentUser?.is_admin)}
                                    only={['lead', 'checklistFiles', 'documentOrphans']} />
                            </div>
                        </div>
                    ))}

                    <input ref={fileInputRef} type="file" multiple onChange={handleFiles} className="hidden" />

                    <div className="flex items-center gap-2 flex-wrap">
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            disabled={uploading}
                            className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 hover:text-emerald-700 disabled:opacity-50"
                        >
                            <Paperclip size={11} />
                            {uploading ? 'Uploading…' : files.length > 0 ? 'Upload another' : 'Upload'}
                        </button>

                        {/* Generate button intentionally removed from here —
                            generation now lives on the sidebar's Proposal &
                            Agreements page. Generated files still surface
                            in this row because they're LeadDocument records
                            keyed by checklist_key. */}
                    </div>
                </div>
            </td>

            {/* Status — inline dropdown */}
            <td className="px-6 py-3">
                <select
                    value={entry.status || ''}
                    onChange={(e) => onSave(item.id, { status: e.target.value || null })}
                    className={`w-full rounded-lg border px-2 py-1.5 text-[11px] font-medium outline-none focus:border-gray-400 ${
                        entry.status
                            ? STATUS_CHIP[entry.status]
                            : (files.length > 0 ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-white text-gray-500 border-gray-200')
                    }`}
                >
                    <option value="">{files.length > 0 ? 'Submitted' : 'Pending'}</option>
                    {STATUSES.map((s) => (
                        <option key={s.key} value={s.key}>{s.label}</option>
                    ))}
                </select>
            </td>

            {/* Reviewed by — whoever last reviewed an uploaded file for this
                item (from the doc queue), and when. Dash until reviewed. */}
            <td className="px-6 py-3">
                {(() => {
                    const reviewed = files
                        .filter((f) => f.reviewed_by)
                        .sort((a, b) => new Date(b.reviewed_at || 0) - new Date(a.reviewed_at || 0))[0];
                    return reviewed ? (
                        <div className="min-w-0">
                            <p className="text-[12px] font-semibold text-gray-800 truncate" title={reviewed.reviewed_by}>{reviewed.reviewed_by}</p>
                            {reviewed.reviewed_at && (
                                <p className="text-[10px] text-gray-400 tabular-nums">{new Date(reviewed.reviewed_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                            )}
                        </div>
                    ) : (
                        <span className="text-[11px] text-gray-300">—</span>
                    );
                })()}
            </td>

            {/* Notes — toggles a threaded discussion (same system the
                immigration Case Profile uses). Shows note count / open flag. */}
            <td className="px-6 py-3">
                <button
                    type="button"
                    onClick={() => setNotesOpen((o) => ! o)}
                    className={`inline-flex items-center gap-1.5 text-[11px] font-semibold rounded-lg px-2 py-1.5 border transition-colors ${
                        notesOpen
                            ? 'text-gray-900 bg-gray-100 border-gray-300'
                            : threads.length > 0
                                ? 'text-gray-700 bg-white border-gray-200 hover:border-gray-300'
                                : 'text-gray-400 bg-white border-gray-200 hover:text-gray-600 hover:border-gray-300'
                    }`}
                >
                    <MessageSquare size={12} />
                    {threads.length > 0 ? `${threads.length} note${threads.length > 1 ? 's' : ''}` : 'Add a note'}
                    {openCount > 0 && (
                        <span className="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-amber-100 text-amber-700 text-[9px] font-bold">
                            {openCount}
                        </span>
                    )}
                </button>
            </td>
        </tr>
        {notesOpen && (
            <tr className="bg-gray-50/50 border-b border-gray-100">
                <td className="w-10" />
                <td colSpan={5} className="px-6 pb-5 pt-1">
                    <div className="max-w-3xl">
                        <p className="text-[10.5px] font-bold uppercase tracking-widest text-gray-400 mb-1">Reviewer notes · {item.name}</p>
                        {rootThreads.length > 0 && (
                            <div className="mb-1">
                                {rootThreads.map((t) => (
                                    <ThreadItem
                                        key={t.id}
                                        thread={t}
                                        leadId={lead.id}
                                        anchor={docAnchor}
                                        childrenOf={childrenOf}
                                        caseStaff={staffOptions}
                                        basePath={`${writeBase}/leads`}
                                    />
                                ))}
                            </div>
                        )}
                        <ThreadComposer
                            leadId={lead.id}
                            fixedAnchor={docAnchor}
                            plain
                            caseStaff={staffOptions}
                            placeholder="Write a note about this document…"
                            basePath={`${writeBase}/leads`}
                        />
                    </div>
                </td>
            </tr>
        )}
        {previewDoc && (
            <LeadDocViewerModal
                doc={previewDoc}
                label={item.name}
                leadId={lead.id}
                staffOptions={staffOptions}
                threads={rootThreads}
                childrenOf={childrenOf}
                anchor={docAnchor}
                basePath={`${writeBase}/leads`}
                onClose={() => setPreviewDoc(null)}
            />
        )}
        </>
    );
}

// Inline notes input for a checklist row — keeps a local draft so typing is
// smooth and only persists (onSave) when the value actually changed on blur.
function ChecklistNotesCell({ value, onSave }) {
    const [draft, setDraft] = useState(value);
    useEffect(() => { setDraft(value); }, [value]);
    return (
        <input
            type="text"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={() => { if (draft !== value) onSave(draft); }}
            placeholder="Add a note…"
            className="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-[11px] text-gray-700 outline-none focus:border-gray-400"
        />
    );
}

function DocumentsPanel({ lead, checklistFiles = {}, orphans = [], currentUser = null, threads = [], staffOptions = [] }) {
    const writeBase = useWriteBase();
    // Bucket document threads by the checklist item they're anchored to
    // (anchor_key = checklist_key), so each row can render its own discussion.
    const threadsByKey = React.useMemo(() => {
        const map = {};
        (threads || []).forEach((t) => {
            if (t.anchor_type !== 'document' || ! t.anchor_key) return;
            (map[t.anchor_key] ||= []).push(t);
        });
        return map;
    }, [threads]);
    // The lead's saved checklist state lives on the backend in a JSON column
    // keyed by item id. We keep a local copy here so edits feel instant and
    // we can debounce notes typing if needed.
    const initial = lead?.document_checklist || {};
    const [state, setState] = useState(initial);
    const [verifications, setVerifications] = useState(lead?.section_verifications || {});

    // Application-tracker visibility — mirrors the immigration Case profile.
    // `hidden_track_documents` is a JSON list of checklist_key strings that
    // are suppressed on the public /track/{code} view. Local state so the
    // checkboxes flip instantly; server call happens in parallel.
    const [hiddenKeys, setHiddenKeys] = useState(() => new Set(lead?.hidden_track_documents || []));
    const toggleTrack = (keys, hidden) => {
        const next = new Set(hiddenKeys);
        keys.forEach((k) => hidden ? next.add(k) : next.delete(k));
        setHiddenKeys(next);
        router.post(`${writeBase}/leads/${lead.id}/documents/track-visibility`, {
            checklist_keys: keys,
            hidden,
        }, { preserveScroll: true, preserveState: true });
    };

    const saveSectionStatus = (sectionKey, status, notes = null) => {
        const next = { ...verifications, [sectionKey]: { ...(verifications[sectionKey] || {}), status, ...(notes !== null ? { notes } : {}) } };
        setVerifications(next);
        router.post(`${writeBase}/leads/${lead.id}/documents/section-verification`, {
            section_key: sectionKey,
            status,
            notes,
        }, { preserveScroll: true, preserveState: true });
    };

    const save = (itemId, patch) => {
        const next = { ...state, [itemId]: { ...(state[itemId] || {}), ...patch } };
        setState(next);

        router.post(`${writeBase}/leads/${lead.id}/documents/checklist`, {
            key:    itemId,
            status: next[itemId].status ?? null,
            date:   next[itemId].date   ?? null,
            notes:  next[itemId].notes  ?? null,
        }, { preserveScroll: true, preserveState: true });
    };

    // General / education leads (the "Document Checklist" type) only work
    // through three requirement sections, in a fixed order — mirroring the
    // public tracker. Immigration cases keep the full checklist (they manage
    // every section). Ordering: Personal → Offer & Academic → Agreements.
    const sections = React.useMemo(() => {
        const base = lead?.is_immigration_case
            ? CHECKLIST
            : ['personal', 'academic', 'agreements']
                .map((k) => CHECKLIST.find((s) => s.key === k))
                .filter(Boolean)
                .map((section) => ({ ...section, items: section.items.filter((item) => item.id !== 'agree.proposal') }));

        // Per-lead ad-hoc documents (leads.custom_documents) drop into the
        // folder staff chose — this lead only. Each is a custom.* item so
        // uploads/status use the same key-based flow. Items targeting an
        // existing folder merge into it; the rest form their own section
        // (e.g. "Additional Documents").
        const custom = Array.isArray(lead?.custom_documents) ? lead.custom_documents : [];
        if (custom.length === 0) return base;

        const bySection = {};
        custom.forEach((c) => {
            const sec = c.section || 'Additional Documents';
            (bySection[sec] ||= []).push({ id: c.key, name: c.name, custom: true, section: sec });
        });

        const used = new Set();
        const merged = base.map((s) => {
            const extra = bySection[s.section];
            if (! extra) return s;
            used.add(s.section);
            return { ...s, items: [...s.items, ...extra] };
        });
        const leftover = Object.entries(bySection)
            .filter(([sec]) => ! used.has(sec))
            .map(([sec, items]) => ({ key: 'lead_custom_' + sec.replace(/\W+/g, '_').toLowerCase(), section: sec, items }));

        return [...merged, ...leftover];
    }, [lead?.is_immigration_case, lead?.custom_documents]);

    // Folder choices for the "Add document" modal — the standard folders plus
    // the catch-all "Additional Documents".
    const folderOptions = React.useMemo(() => {
        const base = lead?.is_immigration_case
            ? CHECKLIST.map((s) => s.section)
            : ['Personal Documents', 'Offer and Academic Documents', 'Agreements'];
        return [...base, 'Additional Documents'];
    }, [lead?.is_immigration_case]);

    // Add / remove a per-lead ad-hoc document.
    const [addOpen, setAddOpen] = useState(false);
    const [newDocName, setNewDocName] = useState('');
    const [newDocSection, setNewDocSection] = useState('');
    const [addingDoc, setAddingDoc] = useState(false);
    const openAddDocument = () => { setNewDocName(''); setNewDocSection(''); setAddOpen(true); };
    const submitAddDocument = (e) => {
        e?.preventDefault?.();
        const name = newDocName.trim();
        if (! name) return;
        setAddingDoc(true);
        router.post(`${writeBase}/leads/${lead.id}/documents/custom`, { name, section: newDocSection }, {
            preserveScroll: true, preserveState: true,
            onSuccess: () => { setAddOpen(false); setNewDocName(''); },
            onFinish: () => setAddingDoc(false),
        });
    };
    const removeCustomDocument = (item) => {
        if (! window.confirm(`Remove "${item.name}" from this lead's documents? Any files already uploaded to it are kept.`)) return;
        router.delete(`${writeBase}/leads/${lead.id}/documents/custom/${item.id}`, {
            preserveScroll: true, preserveState: true,
        });
    };
    const duplicateCustomDocument = (item) => {
        router.post(`${writeBase}/leads/${lead.id}/documents/custom`, {
            name: item.name,
            section: item.section || '',
        }, { preserveScroll: true, preserveState: true });
    };

    // High-level progress strip — count items where the lead has actually
    // uploaded a file, plus the manually-set status counts.
    const allItems = sections.flatMap((s) => s.items);
    const total = allItems.length;
    const counts = allItems.reduce((acc, it) => {
        const s = state[it.id]?.status;
        s ? (acc[s] = (acc[s] || 0) + 1) : null;
        return acc;
    }, {});
    const withFiles = allItems.filter((it) => (checklistFiles[it.id]?.length || 0) > 0).length;
    const accepted = counts.accepted || 0;
    const underReview = counts.under_review || 0;
    const needsAttention = counts.needs_attention || 0;
    const remaining = total - accepted;
    const pct = total > 0 ? Math.round((accepted / total) * 100) : 0;

    // ─── "Download all" picker ────────────────────────────────────────────
    // Flat list of every uploaded file (checklist items + orphan uploads),
    // each carrying its LeadDocument id + a label, for the selection modal.
    const uploadedDocs = React.useMemo(() => {
        const out = [];
        sections.forEach((s) => {
            s.items.forEach((it) => {
                (checklistFiles[it.id] || []).forEach((f) => {
                    out.push({ id: f.id, name: f.original_name || 'Document', item: it.name, section: s.section });
                });
            });
        });
        (orphans || []).forEach((f) => {
            out.push({ id: f.id, name: f.original_name || 'Document', item: null, section: 'Other uploads' });
        });
        return out;
    }, [sections, checklistFiles, orphans]);

    // Group the flat list by section for a tidy, scannable modal.
    const groupedDownloads = React.useMemo(() => {
        const map = new Map();
        uploadedDocs.forEach((d) => {
            if (! map.has(d.section)) map.set(d.section, []);
            map.get(d.section).push(d);
        });
        return Array.from(map, ([section, docs]) => ({ section, docs }));
    }, [uploadedDocs]);

    const [downloadOpen, setDownloadOpen] = useState(false);
    const [selectedDocIds, setSelectedDocIds] = useState(() => new Set());
    // Opening pre-selects everything — the common case is "download all".
    const openDownloadPicker = () => {
        setSelectedDocIds(new Set(uploadedDocs.map((d) => d.id)));
        setDownloadOpen(true);
    };
    const toggleDoc = (id) => setSelectedDocIds((prev) => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });
    const allDownloadsSelected = uploadedDocs.length > 0 && selectedDocIds.size === uploadedDocs.length;
    const toggleAllDownloads = () =>
        setSelectedDocIds(allDownloadsSelected ? new Set() : new Set(uploadedDocs.map((d) => d.id)));
    const downloadSelected = () => {
        if (selectedDocIds.size === 0) return;
        const ids = uploadedDocs.filter((d) => selectedDocIds.has(d.id)).map((d) => d.id);
        // The endpoint streams a ZIP as an attachment, so navigating triggers a
        // download without leaving the page.
        window.location.href = `${writeBase}/leads/${lead.id}/documents/download-all?ids=${ids.join(',')}`;
        setDownloadOpen(false);
    };

    return (
        <div className="space-y-4">
            {/* Progress header */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100 bg-gradient-to-br from-emerald-50/40 to-white flex items-center justify-between gap-4 flex-wrap">
                    <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                            <FolderOpen size={16} />
                        </div>
                        <div>
                            <h2 className="text-sm font-bold uppercase tracking-wider text-gray-800">Documents</h2>
                            <p className="text-[11px] text-gray-500 mt-0.5">Submit + view — checklist of every document this lead needs.</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 text-[11px] font-bold uppercase tracking-widest tabular-nums">
                        <span className="text-[#436235]">{withFiles} with files</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-emerald-700">{accepted} accepted</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-amber-700">{underReview} under review</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-rose-700">{needsAttention} attention</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-gray-500">{remaining} remaining</span>
                    </div>
                </div>
                <div className="px-6 py-3 flex items-center gap-3">
                    <div className="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div className="h-full bg-emerald-400 rounded-full transition-all duration-700" style={{ width: `${pct}%` }} />
                    </div>
                    <span className="text-[11px] font-bold text-gray-600 tabular-nums">{pct}%</span>
                </div>
            </div>

            {/* Important notes */}
            <div className="bg-amber-50/60 border border-amber-200 rounded-2xl p-4 flex items-start gap-3">
                <Info size={16} className="text-amber-700 flex-shrink-0 mt-0.5" />
                <div>
                    <p className="text-[11px] font-bold uppercase tracking-widest text-amber-800 mb-1.5">Important notes</p>
                    <ul className="text-xs text-amber-900 space-y-0.5 leading-relaxed">
                        {IMPORTANT_NOTES.map((n, i) => (
                            <li key={i} className="flex items-start gap-1.5">
                                <span className="text-amber-600 mt-1">•</span>
                                <span>{n}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            {/* Add a per-lead ad-hoc document — a row that only this lead has. */}
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <p className="text-[12px] text-gray-500">Need something not on the list? Add a document for this lead only.</p>
                <div className="flex items-center gap-2">
                    {/* Open a picker to choose which uploaded documents to bundle
                        into a ZIP. Disabled until at least one file exists. */}
                    <button
                        type="button"
                        onClick={openDownloadPicker}
                        disabled={uploadedDocs.length === 0}
                        title={uploadedDocs.length === 0 ? 'No uploaded documents to download yet' : 'Choose documents to download'}
                        className={`inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border text-[12px] font-semibold transition-colors ${
                            uploadedDocs.length === 0
                                ? 'border-gray-200 bg-white text-gray-400 cursor-not-allowed'
                                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 hover:border-gray-300'
                        }`}
                    >
                        <Download size={14} /> Download all
                    </button>
                    <button
                        type="button"
                        onClick={openAddDocument}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-black transition-colors"
                    >
                        <Plus size={14} /> Add document
                    </button>
                </div>
            </div>

            {/* Add-document modal — a per-lead ad-hoc requirement. */}
            {addOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onMouseDown={(e) => { if (e.target === e.currentTarget) setAddOpen(false); }}
                >
                    <form
                        onSubmit={submitAddDocument}
                        className="w-full max-w-md bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden"
                    >
                        <div className="px-6 py-4 border-b border-gray-100 flex items-center gap-2.5 bg-gray-50/40">
                            <div className="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                                <FileText size={16} />
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-gray-900">Add a document</h3>
                                <p className="text-[11px] text-gray-500 mt-0.5">Added to this lead only — it won't affect any other lead.</p>
                            </div>
                            <button type="button" onClick={() => setAddOpen(false)} className="ml-auto w-7 h-7 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 flex items-center justify-center">
                                <X size={16} />
                            </button>
                        </div>
                        <div className="p-6 space-y-4">
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Folder</label>
                                <input
                                    type="text"
                                    list="lead-doc-folders"
                                    value={newDocSection}
                                    onChange={(e) => setNewDocSection(e.target.value)}
                                    placeholder="e.g. Personal Documents"
                                    maxLength={120}
                                    className="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 text-sm text-gray-900 bg-white outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 transition-all"
                                />
                                <datalist id="lead-doc-folders">
                                    {folderOptions.map((f) => (
                                        <option key={f} value={f} />
                                    ))}
                                </datalist>
                                <p className="text-[10.5px] text-gray-400 mt-1">Type a folder name or pick an existing one. Blank goes to “Additional Documents”.</p>
                            </div>
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Document name</label>
                                <input
                                    type="text"
                                    autoFocus
                                    value={newDocName}
                                    onChange={(e) => setNewDocName(e.target.value)}
                                    placeholder="e.g. Marriage Certificate"
                                    maxLength={120}
                                    className="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 text-sm text-gray-900 outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 transition-all"
                                />
                            </div>
                        </div>
                        <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2 bg-gray-50/40">
                            <button type="button" onClick={() => setAddOpen(false)} className="px-4 py-2 rounded-lg text-[12px] font-semibold text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={addingDoc || ! newDocName.trim()}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-black transition-colors disabled:opacity-50"
                            >
                                <Plus size={14} /> {addingDoc ? 'Adding…' : 'Add document'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Download picker — tick which uploaded documents to bundle into a
                ZIP, with a Select-all toggle for the quick "download everything". */}
            {downloadOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onMouseDown={(e) => { if (e.target === e.currentTarget) setDownloadOpen(false); }}
                >
                    <div className="w-full max-w-lg bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden flex flex-col max-h-[85vh]">
                        <div className="px-6 py-4 border-b border-gray-100 flex items-center gap-2.5 bg-gray-50/40">
                            <div className="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
                                <Download size={16} />
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-gray-900">Download documents</h3>
                                <p className="text-[11px] text-gray-500 mt-0.5">Tick the files to include, then download them as a ZIP.</p>
                            </div>
                            <button type="button" onClick={() => setDownloadOpen(false)} className="ml-auto w-7 h-7 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 flex items-center justify-center">
                                <X size={16} />
                            </button>
                        </div>

                        {/* Select-all toggle + running count */}
                        <div className="px-6 py-2.5 border-b border-gray-100 flex items-center justify-between bg-white">
                            <label className="inline-flex items-center gap-2 text-[12px] font-semibold text-gray-700 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    checked={allDownloadsSelected}
                                    onChange={toggleAllDownloads}
                                    className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                />
                                Select all
                            </label>
                            <span className="text-[11px] text-gray-500 tabular-nums">{selectedDocIds.size} of {uploadedDocs.length} selected</span>
                        </div>

                        {/* Grouped list of every uploaded file */}
                        <div className="flex-1 overflow-y-auto px-2 py-2">
                            {groupedDownloads.map((g) => (
                                <div key={g.section} className="mb-1.5">
                                    <p className="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400">{g.section}</p>
                                    {g.docs.map((d) => (
                                        <label key={d.id} className="flex items-center gap-3 px-4 py-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={selectedDocIds.has(d.id)}
                                                onChange={() => toggleDoc(d.id)}
                                                className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shrink-0"
                                            />
                                            <FileText size={14} className="text-gray-400 shrink-0" />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-[13px] font-medium text-gray-800 truncate" title={d.name}>{d.name}</p>
                                                {d.item && <p className="text-[11px] text-gray-400 truncate">{d.item}</p>}
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            ))}
                        </div>

                        <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2 bg-gray-50/40">
                            <button type="button" onClick={() => setDownloadOpen(false)} className="px-4 py-2 rounded-lg text-[12px] font-semibold text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors">
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={downloadSelected}
                                disabled={selectedDocIds.size === 0}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-black transition-colors disabled:opacity-50"
                            >
                                <Download size={14} /> Download{selectedDocIds.size > 0 ? ` (${selectedDocIds.size})` : ''}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Single self-contained checklist table — upload, generate,
                status, notes and per-section verification all happen inline. */}
            <ChecklistTable
                sections={sections}
                state={state}
                checklistFiles={checklistFiles}
                lead={lead}
                onSave={save}
                verifications={verifications}
                onVerifySection={saveSectionStatus}
                hiddenKeys={hiddenKeys}
                onToggleTrack={toggleTrack}
                onRemoveCustom={removeCustomDocument}
                onDuplicateCustom={duplicateCustomDocument}
                threadsByKey={threadsByKey}
                currentUser={currentUser}
                staffOptions={staffOptions}
            />

            {/* Legacy uploads — files with no checklist_key. These were
                submitted before the checklist flow existed (or via the old
                "Your uploads" widget, since removed). We keep them visible so
                nothing is silently orphaned. */}
            {orphans.length > 0 && (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="px-6 py-4 border-b border-gray-100 bg-gray-50/40">
                        <h3 className="text-sm font-bold uppercase tracking-wider text-gray-700">Other uploads</h3>
                        <p className="text-[11px] text-gray-500 mt-1">Files not tied to a checklist item — usually legacy or unsolicited uploads.</p>
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {orphans.map((f) => (
                            <li key={f.id} className="px-6 py-3 flex items-center gap-3">
                                <FileText size={14} className="text-gray-400 shrink-0" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-gray-800 truncate">{f.original_name}</p>
                                    <p className="text-[11px] text-gray-500">
                                        {(f.size / 1024).toFixed(1)} KB
                                        {f.uploaded_by ? ` · uploaded by ${f.uploaded_by}` : ''}
                                        {f.created_at ? ` · ${new Date(f.created_at).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}` : ''}
                                    </p>
                                </div>
                                <a href={`/admin/documents/${f.id}/download?inline=1`} target="_blank" rel="noreferrer"
                                    className="inline-flex items-center justify-center w-8 h-8 rounded text-gray-500 hover:text-blue-600 hover:bg-blue-50 transition-colors" title="View">
                                    <Eye size={14} />
                                </a>
                                <a href={`/admin/documents/${f.id}/download`}
                                    className="inline-flex items-center justify-center w-8 h-8 rounded text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 transition-colors" title="Download">
                                    <Download size={14} />
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

// Aggregate per-folder counts used by both the folder card and the drill-in
// header. Done in one place so the two surfaces stay consistent.
function sectionStats(section, state, checklistFiles) {
    let totalFiles = 0, totalBytes = 0, withFiles = 0, uploaded = 0;
    section.items.forEach((it) => {
        const fs = checklistFiles[it.id] || [];
        totalFiles += fs.length;
        totalBytes += fs.reduce((s, f) => s + (f.size || 0), 0);
        fs.length > 0 ? withFiles++ : null;
        state[it.id]?.status === 'uploaded' ? uploaded++ : null;
    });
    return { totalFiles, totalBytes, withFiles, uploaded, docCount: section.items.length };
}

// Folder tab colour palette — purely for visual variety so each folder
// in the grid feels distinct. Body stays white either way. Picked by
// position in the section list so adjacent folders never share a tab.
const FOLDER_TAB_PALETTE = [
    { tab: 'bg-blue-500',    bar: 'bg-blue-500' },
    { tab: 'bg-emerald-500', bar: 'bg-emerald-500' },
    { tab: 'bg-violet-500',  bar: 'bg-violet-500' },
    { tab: 'bg-amber-500',   bar: 'bg-amber-500' },
    { tab: 'bg-pink-500',    bar: 'bg-pink-500' },
    { tab: 'bg-cyan-500',    bar: 'bg-cyan-500' },
    { tab: 'bg-orange-500',  bar: 'bg-orange-500' },
    { tab: 'bg-rose-500',    bar: 'bg-rose-500' },
    { tab: 'bg-teal-500',    bar: 'bg-teal-500' },
    { tab: 'bg-fuchsia-500', bar: 'bg-fuchsia-500' },
];

function FolderCard({ section, stats, onClick, paletteIndex = 0, verification = null }) {
    const { totalFiles, totalBytes, withFiles, docCount } = stats;
    const completion = docCount > 0 ? Math.round((withFiles / docCount) * 100) : 0;
    const { tab: tabSurface, bar: barColor } = FOLDER_TAB_PALETTE[paletteIndex % FOLDER_TAB_PALETTE.length];
    const verStatus = verification?.status;
    const verMeta = verStatus ? SECTION_STATUSES[verStatus] : null;

    return (
        <button
            type="button"
            onClick={onClick}
            className="relative pt-3 text-left group/folder"
        >
            {/* Folder tab silhouette (only this changes colour) */}
            <div className={`absolute top-0 left-5 w-24 h-3 rounded-t-lg ${tabSurface}`} aria-hidden />
            <div className={`absolute top-2.5 left-[100px] w-3 h-3 ${tabSurface}`} style={{ clipPath: 'polygon(0 100%, 0 0, 100% 100%)' }} aria-hidden />

            <div className="bg-white text-gray-900 rounded-2xl rounded-tl-md shadow-lg group-hover/folder:shadow-xl group-hover/folder:-translate-y-0.5 transition-all p-5 min-h-[160px] flex flex-col border border-gray-100">
                <div className="flex items-start justify-between gap-2">
                    <h3 className="text-base font-bold leading-tight tracking-tight pr-2 text-gray-900">{section.section}</h3>
                    {verMeta && (
                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border whitespace-nowrap flex-shrink-0 ${verMeta.chip}`}>
                            {verMeta.label}
                        </span>
                    )}
                </div>

                <p className="text-[12px] font-medium mt-1.5 text-gray-500">
                    {docCount} {docCount === 1 ? 'doc' : 'docs'}
                </p>

                <div className="flex-1" />

                <div className="space-y-2">
                    <p className="text-[11px] font-medium text-gray-600 tabular-nums">
                        {withFiles} of {docCount} uploaded
                    </p>
                    <div className="h-1 rounded-full overflow-hidden bg-gray-100">
                        <div
                            className={`h-full ${withFiles > 0 ? barColor : 'bg-gray-200'} rounded-full transition-all duration-700`}
                            style={{ width: `${completion}%` }}
                        />
                    </div>
                </div>
            </div>
        </button>
    );
}

// Drill-in view — header with back + folder summary + verification actions,
// then the existing ChecklistCard gallery for items inside the folder.
function FolderDetail({ section, lead, state, onSave, checklistFiles, currentUser, verification, onVerify, onBack }) {
    const stats = sectionStats(section, state, checklistFiles);
    const verStatus = verification?.status;
    const verMeta = verStatus ? SECTION_STATUSES[verStatus] : null;
    const [revisionsOpen, setRevisionsOpen] = useState(false);
    const [revisionsNote, setRevisionsNote] = useState(verification?.notes || '');

    const submitRevisions = () => {
        onVerify('revisions_needed', revisionsNote || null);
        setRevisionsOpen(false);
    };

    return (
        <div className="space-y-4">
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-4">
                <div className="flex items-center gap-4 flex-wrap">
                    <button
                        type="button"
                        onClick={onBack}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wider text-gray-600 hover:text-gray-900 hover:bg-gray-50 border border-gray-200 transition-colors"
                    >
                        <ArrowLeft size={12} />
                        All folders
                    </button>
                    <div className="flex-1 min-w-0">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400">Folder</p>
                        <h2 className="text-xl font-bold tracking-tight text-gray-900">{section.section}</h2>
                    </div>
                    <div className="flex items-center gap-3 text-[11px] font-bold uppercase tracking-widest tabular-nums">
                        <span className="text-gray-700">{stats.docCount} docs</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-emerald-700">{stats.withFiles} with files</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-gray-500">{fmtTotalSize(stats.totalBytes)}</span>
                    </div>
                </div>

                {/* Verification action bar — gates the lead's next section. */}
                <div className="border-t border-gray-100 pt-4 flex items-center gap-3 flex-wrap">
                    <div className="flex items-center gap-2">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500">Section status</p>
                        {verMeta ? (
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ${verMeta.chip}`}>
                                {verMeta.label}
                            </span>
                        ) : (
                            <span className="text-[11px] italic text-gray-400">Not yet reviewed</span>
                        )}
                        {verification?.verified_by && (
                            <span className="text-[10px] text-gray-400">by {verification.verified_by}</span>
                        )}
                    </div>

                    <div className="ml-auto flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setRevisionsOpen((o) => !o)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wider text-rose-700 hover:text-white hover:bg-rose-600 border border-rose-200 hover:border-rose-600 transition-colors"
                        >
                            <AlertTriangle size={12} />
                            Request revisions
                        </button>
                        <button
                            type="button"
                            onClick={() => onVerify('verified')}
                            disabled={verStatus === 'verified'}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wider text-white bg-emerald-600 hover:bg-emerald-700 border border-emerald-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <CheckCircle2 size={12} />
                            {verStatus === 'verified' ? 'Verified' : 'Verify section'}
                        </button>
                    </div>
                </div>

                {/* Revisions note inline editor */}
                {revisionsOpen && (
                    <div className="border-t border-gray-100 pt-4 space-y-2">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-rose-700">
                            What does the lead need to fix?
                        </p>
                        <textarea
                            value={revisionsNote}
                            onChange={(e) => setRevisionsNote(e.target.value)}
                            rows={2}
                            placeholder="e.g. Passport scan is unclear — please re-upload a higher-res copy."
                            className="w-full px-3 py-2 border border-rose-200 rounded-lg text-sm bg-white outline-none focus:border-rose-400 resize-none"
                        />
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setRevisionsOpen(false)}
                                className="text-[10px] uppercase tracking-widest font-bold text-gray-500 px-3 py-1.5"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={submitRevisions}
                                className="text-[10px] uppercase tracking-widest font-bold text-white bg-rose-600 hover:bg-rose-700 px-3 py-1.5 rounded-md"
                            >
                                Send revisions
                            </button>
                        </div>
                    </div>
                )}

                {/* Existing revisions note (read-only) */}
                {!revisionsOpen && verStatus === 'revisions_needed' && verification?.notes && (
                    <div className="border-t border-gray-100 pt-4">
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-rose-700 mb-1">Revisions requested</p>
                        <p className="text-xs text-rose-900 italic leading-relaxed">{verification.notes}</p>
                    </div>
                )}

                {/* Lead's in-portal acknowledgment — only surfaced inside
                    the Agreements folder. Shows green when ticked, gray
                    when still awaiting. */}
                {section.key === 'agreements' && (
                    <div className="border-t border-gray-100 pt-4">
                        {lead?.agreements_acknowledged_at ? (
                            <div className="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200">
                                <CheckCircle2 size={14} className="text-emerald-600" />
                                <p className="text-xs font-semibold text-emerald-800">
                                    Lead acknowledged the agreements on {new Date(lead.agreements_acknowledged_at).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' })}
                                </p>
                            </div>
                        ) : (
                            <div className="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200">
                                <Clock size={14} className="text-amber-600" />
                                <p className="text-xs font-semibold text-amber-800">
                                    Waiting for the lead to acknowledge both agreements in their portal.
                                </p>
                            </div>
                        )}
                    </div>
                )}
            </div>

            <ChecklistSection
                section={section}
                lead={lead}
                state={state}
                onSave={onSave}
                checklistFiles={checklistFiles}
                currentUser={currentUser}
            />
        </div>
    );
}

// Sum the total bytes uploaded against a checklist item — used by the
// folder card's stat line so each card reads "N files · X MB".
const sumSize = (files = []) => files.reduce((t, f) => t + (f.size || 0), 0);
const fmtTotalSize = (b) => {
    if (!b) return '0 KB';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(0)} KB`;
    if (b < 1024 * 1024 * 1024) return `${(b / (1024 * 1024)).toFixed(1)} MB`;
    return `${(b / (1024 * 1024 * 1024)).toFixed(1)} GB`;
};

// Inner doc-card palette — status drives a colored top accent + footer tint
// on an otherwise clean white card. Reverts to the original design used
// before the folder redesign, since inner cards are not folders themselves.
const CARD_TONE = {
    accepted:        { border: "border-emerald-200", bar: "bg-emerald-400", glyph: "bg-emerald-100 text-emerald-700", cap: "bg-emerald-50/40" },
    under_review:    { border: "border-amber-200",   bar: "bg-amber-400",   glyph: "bg-amber-100 text-amber-700",     cap: "bg-amber-50/40"   },
    needs_attention: { border: "border-rose-200",    bar: "bg-rose-400",    glyph: "bg-rose-100 text-rose-700",       cap: "bg-rose-50/40"    },
    unset:           { border: "border-gray-100",    bar: "bg-gray-200",    glyph: "bg-gray-50 text-gray-500",        cap: "bg-white" },
};
const cardTone = (status) => CARD_TONE[status] || CARD_TONE.unset;

// Sort priority — has-files first, then accepted > under_review >
// needs_attention > unset. Cards with actual work done float to the top
// of each section so you can see progress at a glance.
const SORT_PRIORITY = {
    accepted:        1,
    under_review:    2,
    needs_attention: 3,
    unset:           4,
};

function ChecklistSection({ section, lead, state, onSave, checklistFiles = {}, currentUser = null }) {
    const sortedItems = [...section.items].sort((a, b) => {
        const aHasFiles = (checklistFiles[a.id]?.length || 0) > 0;
        const bHasFiles = (checklistFiles[b.id]?.length || 0) > 0;
        if (aHasFiles !== bHasFiles) return aHasFiles ? -1 : 1;
        const aPri = SORT_PRIORITY[state[a.id]?.status || 'unset'] || 4;
        const bPri = SORT_PRIORITY[state[b.id]?.status || 'unset'] || 4;
        return aPri - bPri;
    });

    return (
        <section>
            <div className="flex items-baseline justify-between mb-2.5 px-1">
                <div className="flex items-center gap-2.5">
                    <div className="w-1 h-4 bg-[#436235] rounded-full" />
                    <h3 className="text-[11px] font-bold uppercase tracking-[0.22em] text-gray-800">{section.section}</h3>
                </div>
                <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400 tabular-nums">
                    {section.items.length} {section.items.length === 1 ? "doc" : "docs"}
                </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                {sortedItems.map((item) => (
                    <ChecklistCard
                        key={item.id}
                        item={item}
                        lead={lead}
                        entry={state[item.id] || {}}
                        onSave={onSave}
                        files={checklistFiles[item.id] || []}
                        currentUser={currentUser}
                    />
                ))}
            </div>
        </section>
    );
}

function ChecklistCard({ item, lead, entry, onSave, files = [], currentUser = null }) {
    const writeBase = useWriteBase();
    const [notesDraft, setNotesDraft] = useState(entry.notes || "");
    const [statusOpen, setStatusOpen] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [generateOpen, setGenerateOpen] = useState(false);
    const [generating, setGenerating] = useState(false);
    const fileInputRef = useRef(null);
    const filename = renderFilename(item.filename, lead);
    const status = entry.status || null;
    const fileCount = files.length;

    // Both checklist items support auto-generation:
    //   agree.consultancy        — Single/Partner variant (opens a picker modal)
    //   agree.engagement_english — Single template (generates directly)
    const canGenerate = item.id === 'agree.consultancy' || item.id === 'agree.engagement_english';
    const needsVariantPicker = item.id === 'agree.consultancy';
    const hasGenerated = files.some(f => f.source === 'generated');

    const runGenerate = (variant) => {
        setGenerating(true);
        const form = new FormData();
        if (variant) form.append('variant', variant);
        router.post(`${writeBase}/leads/${lead.id}/documents/checklist/${item.id}/generate`, form, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onFinish: () => {
                setGenerating(false);
                setGenerateOpen(false);
            },
        });
    };

    // Click handler — consultancy opens the variant picker, engagement
    // generates inline since there's only one template.
    const handleGenerateClick = () => {
        if (needsVariantPicker) {
            setGenerateOpen(true);
        } else {
            runGenerate(null);
        }
    };

    const copyFilename = () => {
        filename && navigator.clipboard?.writeText(filename);
    };

    const flushNotes = () => {
        if ((notesDraft || "") !== (entry.notes || "")) {
            onSave(item.id, { notes: notesDraft || null });
        }
    };

    const triggerUpload = () => fileInputRef.current?.click();

    const handleFiles = (e) => {
        const picked = Array.from(e.target.files || []);
        if (picked.length === 0) return;
        const form = new FormData();
        picked.forEach((f) => form.append('files[]', f));
        setUploading(true);
        router.post(`${writeBase}/leads/${lead.id}/documents/checklist/${item.id}/upload`, form, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onFinish: () => {
                setUploading(false);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    };

    const removeFile = (file) => {
        if (!confirm(`Delete ${file.original_name}?`)) return;
        router.delete(`${writeBase}/leads/${lead.id}/documents/${file.id}`, { preserveScroll: true, preserveState: true });
    };

    const fmtSize = (b) => {
        if (!b) return '';
        if (b < 1024) return `${b} B`;
        if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
        return `${(b / (1024 * 1024)).toFixed(1)} MB`;
    };
    const fmtDate = (iso) => iso ? new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '';

    const tone = cardTone(status);

    return (
        <article className={`bg-white rounded-2xl border-2 ${tone.border} shadow-sm hover:shadow-md transition-shadow overflow-hidden flex flex-col ${tone.muted ? "opacity-70" : ""}`}>
            {/* Accent bar — colored by status */}
            <div className={`h-1 ${tone.bar}`} />

            <div className="p-4 flex-1 flex flex-col gap-3">
                {/* Header — glyph + title */}
                <div className="flex items-start gap-3">
                    <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${tone.glyph}`}>
                        <FileText size={16} />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <h4 className="text-sm font-semibold text-gray-900 leading-snug">{item.name}</h4>
                            {item.system && (
                                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-[#436235]/10 text-[#436235] border border-[#436235]/20">
                                    System
                                </span>
                            )}
                            {fileCount > 0 && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-emerald-100 text-emerald-700 border border-emerald-200">
                                    <FolderOpen size={9} />
                                    {fileCount} {fileCount === 1 ? 'file' : 'files'}
                                </span>
                            )}
                        </div>
                        {item.description && (
                            <p className="text-[11px] text-gray-500 mt-1 leading-relaxed">{item.description}</p>
                        )}
                    </div>
                </div>

                {/* Filename hint */}
                {filename && (
                    <button
                        type="button"
                        onClick={copyFilename}
                        className="group/fn inline-flex items-center gap-1.5 text-[11px] font-mono text-gray-600 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded-md px-2 py-1.5 transition-colors self-start max-w-full"
                        title="Copy filename to clipboard"
                    >
                        <span className="truncate">{filename}</span>
                        <Copy size={10} className="text-gray-400 group-hover/fn:text-gray-700 flex-shrink-0" />
                    </button>
                )}

                {/* Uploaded files list — each file is a row with download +
                    delete. Uploaded by the lead OR by staff on their behalf.
                    Files with source='generated' get a violet pill so staff
                    can tell auto-generated PDFs apart from manual uploads. */}
                {fileCount > 0 && (
                    <ul className="space-y-1.5">
                        {files.map((f) => (
                            <li key={f.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-gray-50 border border-gray-200 group/file">
                                {f.source === 'generated' ? (
                                    <Wand2 size={12} className="text-violet-500 flex-shrink-0" />
                                ) : (
                                    <FileText size={12} className="text-gray-400 flex-shrink-0" />
                                )}
                                <a
                                    href={`/admin/documents/${f.id}/download?inline=1`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex-1 min-w-0 text-[11px] font-medium text-gray-800 hover:text-blue-600 truncate"
                                    title={`View ${f.original_name}`}
                                >
                                    {f.original_name}
                                </a>
                                {f.source === 'generated' && (
                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-violet-100 text-violet-700 border border-violet-200">
                                        <Sparkles size={9} />
                                        {f.source_variant?.includes('partner') ? 'Generated · Partner' : f.source_variant?.includes('single') ? 'Generated · Single' : 'Generated'}
                                    </span>
                                )}
                                <span className="text-[10px] text-gray-400 tabular-nums hidden sm:inline">
                                    {fmtSize(f.size)}
                                </span>
                                <span className="text-[10px] text-gray-400 tabular-nums hidden md:inline">
                                    {fmtDate(f.created_at)}
                                </span>
                                <a
                                    href={`/admin/documents/${f.id}/download?inline=1`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center justify-center w-6 h-6 rounded text-gray-500 hover:text-violet-600 hover:bg-violet-50 transition-colors"
                                    title="View"
                                >
                                    <Eye size={11} />
                                </a>
                                <a
                                    href={`/admin/documents/${f.id}/download`}
                                    className="inline-flex items-center justify-center w-6 h-6 rounded text-gray-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                                    title="Download"
                                >
                                    <Download size={11} />
                                </a>
                                <button
                                    type="button"
                                    onClick={() => removeFile(f)}
                                    className="inline-flex items-center justify-center w-6 h-6 rounded text-gray-300 hover:text-red-600 hover:bg-red-50 transition-colors opacity-0 group-hover/file:opacity-100"
                                    title="Delete file"
                                >
                                    <XCircle size={11} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {/* Upload button + hidden input */}
                <input
                    ref={fileInputRef}
                    type="file"
                    multiple
                    onChange={handleFiles}
                    className="hidden"
                />
                <button
                    type="button"
                    onClick={triggerUpload}
                    disabled={uploading}
                    className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 border-2 border-dashed border-gray-300 rounded-lg text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-800 hover:border-gray-400 hover:bg-gray-50/80 transition-all disabled:opacity-50"
                >
                    <Download size={12} className="rotate-180" />
                    {uploading ? 'Uploading…' : fileCount > 0 ? 'Upload another' : 'Upload file(s)'}
                </button>

                {/* Generate button removed — generation now lives on the
                    sidebar's Proposal & Agreements page. Generated files
                    still appear in this card because they're LeadDocument
                    records keyed by checklist_key. */}

                {/* Spacer pushes the footer to the card bottom */}
                <div className="flex-1" />

                {/* Footer band — status, date, notes */}
                <div className={`-mx-4 -mb-4 px-4 pt-3 pb-3 border-t border-gray-100 ${tone.cap} space-y-2`}>
                    <div className="flex items-center gap-2">
                        <div className="relative flex-1">
                            <button
                                type="button"
                                onClick={() => setStatusOpen((o) => !o)}
                                className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border w-full justify-between ${
                                    status ? STATUS_CHIP[status] : "bg-white text-gray-400 border-gray-200 hover:bg-gray-50"
                                }`}
                            >
                                <span className="truncate">{status ? STATUS_LABEL[status] : "Set status"}</span>
                                <ChevronDown size={10} className="flex-shrink-0 opacity-60" />
                            </button>

                            {statusOpen && (
                                <div className="absolute z-20 bottom-full mb-1 left-0 right-0 bg-white border border-gray-200 rounded-md shadow-lg py-1 min-w-[150px]">
                                    {STATUSES.map((s) => (
                                        <button
                                            key={s.key}
                                            type="button"
                                            onClick={() => { onSave(item.id, { status: s.key }); setStatusOpen(false); }}
                                            className="w-full text-left px-2 py-1.5 hover:bg-gray-50"
                                        >
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ${s.chip}`}>
                                                {s.label}
                                            </span>
                                        </button>
                                    ))}
                                    {status && (
                                        <button
                                            type="button"
                                            onClick={() => { onSave(item.id, { status: null }); setStatusOpen(false); }}
                                            className="w-full text-left px-2 py-1.5 text-[10px] uppercase tracking-widest font-bold text-gray-400 hover:bg-gray-50 border-t border-gray-100 mt-1"
                                        >
                                            Clear status
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        <input
                            type="date"
                            value={entry.date || ""}
                            onChange={(e) => onSave(item.id, { date: e.target.value || null })}
                            className="px-2 py-1 border border-gray-200 rounded-md text-[11px] bg-white outline-none focus:border-gray-400 w-[125px]"
                            title="Date"
                        />
                    </div>

                    <input
                        type="text"
                        value={notesDraft}
                        onChange={(e) => setNotesDraft(e.target.value)}
                        onBlur={flushNotes}
                        placeholder="Add a note…"
                        className="w-full px-2 py-1 border border-gray-200 rounded-md text-[11px] bg-white outline-none focus:border-gray-400"
                    />
                </div>
            </div>

            {generateOpen && (
                <GenerateAgreementModal
                    lead={lead}
                    onClose={() => !generating && setGenerateOpen(false)}
                    onConfirm={runGenerate}
                    generating={generating}
                />
            )}
        </article>
    );
}

// Variant-picker modal for Consultancy Agreement generation. The lead's
// marital_status pre-selects Partner when relevant ("Married", "Partnered",
// "De Facto", etc.) but staff can always override.
function GenerateAgreementModal({ lead, onClose, onConfirm, generating }) {
    const suggestedPartner = /married|partner|de\s*facto|spouse/i.test(lead?.marital_status || '');
    const [variant, setVariant] = useState(suggestedPartner ? 'partner' : 'single');
    const clientName = `${lead?.first_name || ''} ${lead?.last_name || ''}`.trim() || 'this lead';

    // Portal to document.body so the modal escapes any ancestor transforms
    // (the checklist card sits in a grid with framer-motion higher up that
    // creates a containing block, breaking position:fixed otherwise).
    if (typeof document === 'undefined') return null;
    return createPortal((
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[70] flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden">
                <div className="px-8 py-6 border-b border-gray-100 flex items-start justify-between gap-4">
                    <div className="flex-1 min-w-0">
                        <div className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-violet-100 text-violet-700 rounded-md text-[10px] font-bold uppercase tracking-widest mb-2">
                            <Wand2 size={11} /> AI-assisted
                        </div>
                        <h2 className="text-xl font-bold text-gray-900">Generate Consultancy Agreement</h2>
                        <p className="text-sm text-gray-500 mt-1.5">Pick the variant for <span className="font-semibold text-gray-700">{clientName}</span>. The PDF is attached to this lead's documents and visible in their portal.</p>
                    </div>
                    <button onClick={onClose} disabled={generating} className="text-gray-400 hover:text-gray-700 p-1 rounded disabled:opacity-50 flex-shrink-0">
                        <XCircle size={22} />
                    </button>
                </div>

                <div className="p-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {[
                        { key: 'single',  title: 'SINGLE',  sub: 'Applicant only',           fee: 'PhP 100,000', icon: <User size={22} /> },
                        { key: 'partner', title: 'PARTNER', sub: 'Main applicant + partner', fee: 'PhP 150,000', icon: <UsersIcon size={22} /> },
                    ].map(opt => (
                        <button
                            key={opt.key}
                            type="button"
                            onClick={() => setVariant(opt.key)}
                            disabled={generating}
                            className={`text-left rounded-2xl border-2 p-6 transition-all disabled:opacity-50 ${variant === opt.key ? 'border-violet-600 bg-violet-50' : 'border-gray-200 bg-white hover:border-gray-300'}`}
                        >
                            <div className="flex items-center justify-between mb-4">
                                <div className={`w-12 h-12 rounded-xl flex items-center justify-center ${variant === opt.key ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-500'}`}>
                                    {opt.icon}
                                </div>
                                {variant === opt.key && <CheckCircle2 size={20} className="text-violet-600" />}
                            </div>
                            <div className="text-[11px] font-bold tracking-[0.22em] uppercase text-gray-500 mb-1">{opt.title}</div>
                            <div className="text-base font-semibold text-gray-900 mb-3">{opt.sub}</div>
                            <div className="text-2xl font-bold text-gray-900 tracking-tight">{opt.fee}</div>
                        </button>
                    ))}
                </div>

                <div className="px-8 pb-3">
                    {suggestedPartner ? (
                        <p className="text-xs text-gray-500">Auto-suggested <span className="font-semibold text-violet-700">Partner</span> (lead marital status: {lead?.marital_status}).</p>
                    ) : (
                        <p className="text-xs text-gray-400">Default <span className="font-semibold text-gray-600">Single</span> — change if applicant has a partner.</p>
                    )}
                </div>

                <div className="px-8 py-5 border-t border-gray-100 bg-gray-50/60 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={generating}
                        className="px-5 py-2.5 text-xs font-bold uppercase tracking-wider text-gray-700 hover:bg-gray-100 rounded-xl transition-colors disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={() => onConfirm(variant)}
                        disabled={generating}
                        className="inline-flex items-center gap-2 px-6 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-xs font-bold uppercase tracking-wider rounded-xl transition-colors shadow-sm disabled:opacity-60 disabled:cursor-not-allowed whitespace-nowrap"
                    >
                        {generating ? (
                            <>
                                <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                Generating PDF…
                            </>
                        ) : (
                            <>
                                <Wand2 size={13} /> Generate {variant === 'partner' ? 'Partner' : 'Single'} variant
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    ), document.body);
}

const NOTE_KIND_META = {
    general:      { label: 'General',       chip: 'bg-gray-100 text-gray-700 border-gray-200' },
    pre_screen:   { label: 'Pre-screening', chip: 'bg-amber-100 text-amber-800 border-amber-200' },
    goal_setting: { label: 'Goal-setting',  chip: 'bg-purple-100 text-purple-800 border-purple-200' },
};
const GOAL_STATUS_OPTS = ['Consultation Done', 'For Proposal', 'Proposal Sent', 'No Show'];
// Pre-screeners are real staff users — sourced from the staffOptions
// dropdown filtered to roles that legitimately pre-screen leads.
const PRESCREEN_ROLES = ['sales', 'education', 'admin'];

function NotesPanel({ leadId, notes, currentUser, staffOptions = [], programOptions = [] }) {
    const [notePrograms, setNotePrograms] = useState([]);
    const writeBase = useWriteBase();
    const [body, setBody] = useState('');
    const [pinned, setPinned] = useState(false);
    const [kind, setKind] = useState('general');
    const [preScreenedBy, setPreScreenedBy] = useState('');
    const [preScreenMode, setPreScreenMode] = useState('');
    const [preScreenDate, setPreScreenDate] = useState('');
    const [goalStatus, setGoalStatus] = useState('');
    const [goalBy, setGoalBy] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [editBody, setEditBody] = useState('');

    // Auto-grow the compose + edit textareas so long notes expand the box
    // instead of showing an inner scrollbar. Runs after every keystroke
    // and every time we open/reset the edit form.
    const composeRef = useRef(null);
    const editRef = useRef(null);
    const grow = (el) => {
        if (! el) return;
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    };
    useEffect(() => { grow(composeRef.current); }, [body]);
    useEffect(() => { grow(editRef.current); }, [editBody, editingId]);

    const reset = () => {
        setBody(''); setPinned(false); setKind('general'); setNotePrograms([]);
        setPreScreenedBy(''); setPreScreenMode(''); setPreScreenDate('');
        setGoalStatus(''); setGoalBy('');
    };

    const submit = (e) => {
        e.preventDefault();
        if (!body.trim()) return;
        setSubmitting(true);
        const payload = {
            body: body.trim(),
            programs: notePrograms,
            pinned,
            kind,
            pre_screened_by:     kind === 'pre_screen'   ? (preScreenedBy || null) : null,
            pre_screen_mode:     kind === 'pre_screen'   ? (preScreenMode || null) : null,
            pre_screen_date:     kind === 'pre_screen'   ? (preScreenDate || null) : null,
            goal_setting_status: kind === 'goal_setting' ? (goalStatus    || null) : null,
            goal_setting_by:     kind === 'goal_setting' ? (goalBy        || null) : null,
        };
        router.post(`${writeBase}/leads/${leadId}/notes`, payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: reset,
            onFinish: () => setSubmitting(false),
        });
    };

    const togglePin = (note) => router.post(`${writeBase}/leads/${leadId}/notes/${note.id}`,
        { pinned: !note.pinned },
        { preserveScroll: true, preserveState: true });

    const saveEdit = (note) => {
        if (!editBody.trim()) return;
        router.post(`${writeBase}/leads/${leadId}/notes/${note.id}`,
            { body: editBody.trim() },
            { preserveScroll: true, preserveState: true, onSuccess: () => setEditingId(null) });
    };

    const remove = (note) => {
        confirm('Delete this note?')
            ? router.delete(`${writeBase}/leads/${leadId}/notes/${note.id}`, { preserveScroll: true, preserveState: true })
            : null;
    };

    const fmt = (iso) => iso
        ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })
        : '';

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div className="flex items-center justify-between gap-2 mb-3">
                <h2 className="text-[14px] font-bold text-gray-900">
                    Internal notes
                    <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 ml-1.5">Team only</span>
                </h2>
                <span className="text-[11px] font-normal text-gray-400 shrink-0">
                    {notes.length} note{notes.length === 1 ? "" : "s"}
                </span>
            </div>

            {/* Compose — one bordered block, controls on the footer row. */}
            <form onSubmit={submit} className="rounded-xl border border-gray-200 bg-gray-50/40 p-2.5 space-y-2.5">
                {/* Kind selector */}
                <div className="flex items-center gap-1.5 flex-wrap">
                    {Object.entries(NOTE_KIND_META).map(([k, meta]) => (
                        <button
                            key={k}
                            type="button"
                            onClick={() => setKind(k)}
                            className={`inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border transition-all ${
                                kind === k ? meta.chip : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50'
                            }`}
                        >
                            {meta.label}
                        </button>
                    ))}
                </div>

                {/* Kind-specific fields */}
                {kind === 'pre_screen' && (
                    <div className="space-y-3.5">
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-1.5">Pre-screened by</p>
                            {(() => {
                                const prescreeners = staffOptions.filter((u) => PRESCREEN_ROLES.includes(u.role));
                                if (prescreeners.length === 0) {
                                    return (
                                        <p className="text-[11px] text-gray-400 italic">
                                            No sales / education staff on file. Set a user&apos;s role to <span className="font-mono">sales</span>, <span className="font-mono">education</span>, or <span className="font-mono">admin</span> in Admin → Users.
                                        </p>
                                    );
                                }
                                return (
                                    <select
                                        value={preScreenedBy}
                                        onChange={(e) => setPreScreenedBy(e.target.value)}
                                        className="w-full px-3 py-1.5 border border-gray-200 rounded-md text-xs bg-white outline-none focus:border-gray-900"
                                    >
                                        <option value="">— Select staff —</option>
                                        {prescreeners.map((u) => (
                                            <option key={u.id} value={u.name}>{u.name} · {u.role}</option>
                                        ))}
                                    </select>
                                );
                            })()}
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            {/* Mode — Google Meet vs phone call. */}
                            <div>
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-1.5">Mode</p>
                                <div className="flex gap-1.5">
                                    {[
                                        { k: 'gmeet', label: 'Google Meet' },
                                        { k: 'call',  label: 'Phone Call' },
                                    ].map((m) => {
                                        const active = preScreenMode === m.k;
                                        return (
                                            <button
                                                key={m.k}
                                                type="button"
                                                onClick={() => setPreScreenMode(active ? '' : m.k)}
                                                className={`flex-1 px-3 py-1.5 rounded-md text-[11px] font-bold border transition-all ${
                                                    active ? 'bg-amber-600 text-white border-amber-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                                                }`}
                                            >
                                                {m.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Date the pre-screen happened. */}
                            <div>
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-1.5">Date</p>
                                <input
                                    type="date"
                                    value={preScreenDate}
                                    onChange={(e) => setPreScreenDate(e.target.value)}
                                    className="w-full px-3 py-1.5 border border-gray-200 rounded-md text-xs bg-white outline-none focus:border-gray-900"
                                />
                            </div>
                        </div>
                    </div>
                )}

                {kind === 'goal_setting' && (
                    <div className="space-y-3.5">
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-1.5">Goal-setting status</p>
                            <div className="flex flex-wrap gap-1.5">
                                {GOAL_STATUS_OPTS.map((s) => {
                                    const active = goalStatus === s;
                                    return (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() => setGoalStatus(active ? '' : s)}
                                            className={`inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border transition-all ${
                                                active ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                                            }`}
                                        >
                                            {s}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-500 mb-1.5">Goal-setting by</p>
                            <select
                                value={goalBy}
                                onChange={(e) => setGoalBy(e.target.value)}
                                className="w-full px-3 py-1.5 border border-gray-200 rounded-md text-xs bg-white outline-none focus:border-gray-900"
                            >
                                <option value="">— Select staff —</option>
                                {staffOptions.map((u) => (
                                    <option key={u.id} value={u.name}>{u.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                )}

                <NoteProgramPicker options={programOptions} value={notePrograms} onChange={setNotePrograms} />
                <textarea
                    ref={composeRef}
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    placeholder="Type here"
                    rows={2}
                    className="w-full text-[13px] bg-transparent outline-none resize-none overflow-hidden placeholder-gray-400"
                    style={{ minHeight: '2.5rem' }}
                />
                <div className="flex items-center justify-between gap-2 pt-1.5 border-t border-gray-100">
                    <button
                        type="button"
                        onClick={() => setPinned((v) => !v)}
                        title="Pin to top"
                        className={`inline-flex items-center justify-center w-7 h-7 rounded-full transition-colors ${pinned ? 'text-amber-600 bg-amber-50' : 'text-gray-400 hover:bg-gray-200 hover:text-gray-700'}`}
                    >
                        <Pin size={13} />
                    </button>
                    <button
                        type="submit"
                        disabled={submitting || !body.trim()}
                        className="px-3.5 py-1.5 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-gray-800 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        {submitting ? 'Posting…' : 'Post'}
                    </button>
                </div>
            </form>

            {/* List */}
            {notes.length === 0 ? (
                <p className="text-[12px] text-gray-400 mt-3">No notes yet.</p>
            ) : (
                <ul className="mt-4 space-y-3">
                    {notes.map((n) => {
                        const canEdit = currentUser && (currentUser.is_admin || currentUser.id === n.user_id);
                        const isEditing = editingId === n.id;
                        return (
                            <li key={n.id} className={`rounded-xl border p-3 ${n.kind === 'risk' ? 'border-red-100 bg-red-50/40' : n.pinned ? 'border-teal-100 bg-teal-50/40' : 'border-gray-100 bg-white'}`}>
                                <div className="flex items-center justify-between gap-3 mb-2">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <div className="w-6 h-6 rounded-full bg-gray-900 text-white text-[10px] font-bold flex items-center justify-center flex-shrink-0">
                                            {(n.author_name || 'S').slice(0, 1).toUpperCase()}
                                        </div>
                                        <span className="text-xs font-semibold text-gray-900 truncate">{n.author_name}</span>
                                        <span className="text-[10px] text-gray-400 uppercase tracking-widest font-bold truncate">· {n.author_role}</span>
                                        {n.kind && n.kind !== 'general' && NOTE_KIND_META[n.kind] && (
                                            <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider border ${NOTE_KIND_META[n.kind].chip}`}>
                                                {NOTE_KIND_META[n.kind].label}
                                            </span>
                                        )}
                                        {n.pinned && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 border border-amber-200">
                                                Pinned
                                            </span>
                                        )}
                                    </div>
                                    <span className="text-[10px] text-gray-400 whitespace-nowrap">{fmt(n.created_at)}</span>
                                </div>

                                <NotePrograms programs={n.programs} />
                                {/* Kind-specific meta — pre-screener name,
                                    mode, date, goal status + assigned staff,
                                    rendered as small chips above the body. */}
                                {(n.pre_screened_by || n.pre_screen_mode || n.pre_screen_date || n.goal_setting_status || n.goal_setting_by) && (
                                    <div className="flex flex-wrap items-center gap-1.5 mb-2">
                                        {n.pre_screened_by && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200">
                                                By {n.pre_screened_by}
                                            </span>
                                        )}
                                        {n.pre_screen_mode && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200">
                                                {n.pre_screen_mode === 'gmeet' ? 'Google Meet' : 'Phone Call'}
                                            </span>
                                        )}
                                        {n.pre_screen_date && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold tracking-wide bg-amber-50/60 text-amber-700 border border-amber-200 tabular-nums">
                                                {new Date(String(n.pre_screen_date).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' })}
                                            </span>
                                        )}
                                        {n.goal_setting_status && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 border border-purple-200">
                                                {n.goal_setting_status}
                                            </span>
                                        )}
                                        {n.goal_setting_by && (
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold tracking-wide bg-gray-50 text-gray-600 border border-gray-200">
                                                with {n.goal_setting_by}
                                            </span>
                                        )}
                                    </div>
                                )}

                                {isEditing ? (
                                    <div>
                                        <textarea
                                            ref={editRef}
                                            value={editBody}
                                            onChange={(e) => setEditBody(e.target.value)}
                                            rows={2}
                                            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white outline-none focus:border-gray-900 resize-none overflow-hidden"
                                            style={{ minHeight: '3rem' }}
                                            autoFocus
                                        />
                                        <div className="flex items-center justify-end gap-2 mt-2">
                                            <button type="button" onClick={() => setEditingId(null)} className="text-[10px] uppercase tracking-widest font-bold text-gray-500 px-2 py-1">Cancel</button>
                                            <button type="button" onClick={() => saveEdit(n)} className="text-[10px] uppercase tracking-widest font-bold text-white bg-gray-900 hover:bg-gray-800 px-3 py-1.5 rounded-md">Save</button>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <p className="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{n.body}</p>
                                        <div className="flex items-center gap-3 mt-2 text-[10px] uppercase tracking-widest font-bold">
                                            <button type="button" onClick={() => togglePin(n)} className="text-gray-400 hover:text-amber-600 transition-colors">
                                                {n.pinned ? 'Unpin' : 'Pin'}
                                            </button>
                                            {canEdit && (
                                                <>
                                                    <button type="button" onClick={() => { setEditingId(n.id); setEditBody(n.body); }} className="text-gray-400 hover:text-gray-900 transition-colors">Edit</button>
                                                    <button type="button" onClick={() => remove(n)} className="text-gray-400 hover:text-red-600 transition-colors">Delete</button>
                                                </>
                                            )}
                                        </div>
                                    </>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

// ── Sources panel — every form this lead has filled ──────────────────────

const SOURCE_KIND_STYLE = {
    origin:   { chip: "bg-[#436235]/10 text-[#436235] border-[#436235]/30", label: "Origin" },
    booking:  { chip: "bg-blue-50 text-blue-700 border-blue-200",           label: "Booking" },
    event:    { chip: "bg-purple-50 text-purple-700 border-purple-200",     label: "Event" },
    resubmit: { chip: "bg-amber-50 text-amber-700 border-amber-200",        label: "Resubmit" },
};

function SourcesPanel({ sources = [] }) {
    const fmt = (iso) => iso
        ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" })
        : "—";

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <FileText size={15} className="text-gray-400" />
                    <h2 className="text-xs font-bold uppercase tracking-wider text-gray-700">Sources</h2>
                </div>
                <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                    {sources.length} touchpoint{sources.length === 1 ? "" : "s"}
                </span>
            </div>

            {sources.length === 0 ? (
                <div className="px-5 py-8 text-center text-xs text-gray-400">
                    No sources recorded.
                </div>
            ) : (
                <ol className="divide-y divide-gray-100">
                    {sources.map((s, i) => {
                        const style = SOURCE_KIND_STYLE[s.kind] || SOURCE_KIND_STYLE.resubmit;
                        return (
                            <li key={i} className="px-5 py-3 flex items-center gap-3">
                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-wider w-[80px] justify-center flex-shrink-0 ${style.chip}`}>
                                    {style.label}
                                </span>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium text-gray-900 truncate">{s.label}</p>
                                    {s.detail && (
                                        <p className="text-[11px] text-gray-500 mt-0.5 truncate">{s.detail}</p>
                                    )}
                                </div>
                                <div className="flex flex-col items-end text-right flex-shrink-0">
                                    {s.reference && (
                                        <span className="text-[10px] font-mono text-gray-400">{s.reference}</span>
                                    )}
                                    <span className="text-[11px] text-gray-500">{fmt(s.date)}</span>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function formatVal(v) {
    if (v === null || v === undefined || v === '') return '(empty)';
    if (typeof v === 'boolean') return v ? 'Yes' : 'No';
    if (typeof v === 'object') {
        const s = JSON.stringify(v);
        return s.length > 60 ? s.slice(0, 60) + '…' : s;
    }
    const s = String(v);
    return s.length > 60 ? s.slice(0, 60) + '…' : s;
}

// ── Event registration snapshot ─────────────────────────────────────────────
//
// Read-only card that reconstructs the public registration form as the
// lead filled it. Grouped by section, lays out two-up on wider screens.
// Data comes pre-resolved from the controller — values for default
// fields come from their dedicated lead columns / JSON buckets, custom
// fields come straight from event_response. Nothing here is editable
// (lead profile edits already happen below in the Personal Info tab).
function EventRegistrationCard({ data }) {
    const { event, registered_at, fields = [] } = data || {};
    if (! event || fields.length === 0) return null;

    // Group fields by section so the card mirrors the public registration
    // page layout.
    const sectionGroups = (() => {
        const map = new Map();
        const order = [];
        for (const f of fields) {
            const sec = f.section || 'Additional';
            if (! map.has(sec)) { map.set(sec, []); order.push(sec); }
            map.get(sec).push(f);
        }
        return order.map((sec) => ({ section: sec, items: map.get(sec) }));
    })();

    const fmtDate = (iso) => iso
        ? new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' })
        : null;
    const registeredAtPretty = registered_at
        ? new Date(registered_at).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : null;

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="flex items-center gap-3 px-6 py-4 border-b border-gray-100 bg-gray-50/30">
                <div className="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">
                    <Calendar size={16} />
                </div>
                <div className="flex-1 min-w-0">
                    <h2 className="text-lg font-bold text-gray-900 truncate">
                        Event registration
                    </h2>
                    <p className="text-[12px] text-gray-500 mt-0.5">
                        <span className="font-semibold text-gray-700">{event.name}</span>
                        {event.type ? <span className="text-gray-400"> · {event.type}</span> : null}
                        {event.date_from ? <span className="text-gray-400"> · {fmtDate(event.date_from)}</span> : null}
                    </p>
                </div>
                {registeredAtPretty && (
                    <span className="hidden sm:inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 bg-white border border-gray-200 px-2.5 py-1 rounded-md">
                        <Clock size={11} /> {registeredAtPretty}
                    </span>
                )}
            </div>

            <div className="p-6 space-y-6">
                {sectionGroups.map(({ section, items }) => (
                    <section key={section}>
                        <h3 className="text-[11px] font-bold uppercase tracking-[0.18em] text-gray-500 mb-3 pb-2 border-b border-gray-100">
                            {section}
                        </h3>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                            {items.map((f) => (
                                <EventResponseRow key={f.key} field={f} />
                            ))}
                        </div>
                    </section>
                ))}
                {event.event_code && (
                    <p className="text-[11px] text-gray-400 italic pt-2 border-t border-gray-50">
                        Registration code:{' '}
                        <span className="font-mono">{event.event_code}</span>
                    </p>
                )}
            </div>
        </div>
    );
}

function EventResponseRow({ field }) {
    const { label, type, value } = field;
    const fullWidth = type === 'textarea';
    const isEmpty = value === null || value === undefined || value === '';

    return (
        <div className={fullWidth ? 'sm:col-span-2' : ''}>
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400 mb-1">
                {label}
            </p>
            {isEmpty ? (
                <p className="text-[13px] text-gray-300 italic">Not provided</p>
            ) : type === 'textarea' ? (
                <p className="text-[13px] text-gray-800 leading-relaxed whitespace-pre-wrap bg-gray-50/60 border border-gray-100 rounded-lg px-3 py-2.5">
                    {String(value)}
                </p>
            ) : (
                <p className="text-[13px] text-gray-800 font-medium">
                    {String(value)}
                </p>
            )}
        </div>
    );
}

