import React, { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft, MapPin, Users, Search, Mail, Phone, StickyNote,
    ChevronRight as ChevronRightIcon,
} from 'lucide-react';
// Reuse the exact pieces the Open-opportunities table uses so this table
// reads identically to Leads.jsx (colour-coded stage picker, priority-
// tinted avatar, expandable dashboard panel, same helpers).
import {
    StagePicker, LeadDashboardPanel, initials, priorityMeta, priorityRank, fmtDateShort, fmtTime,
} from '@/pages/portal/sales/Leads';

const priorityLabel = (p) => (p ? `Priority: ${p[0].toUpperCase()}${p.slice(1)}` : 'No priority set');

// ─── Full-page agent leads view ───────────────────────────────────────
//
// Everything a single recruiting agent has brought in, rendered with the
// same table design as the main Leads table (Open opportunities). Reached
// from the Agents tab on /portal/sales/leads via the "View leads" action.
//
export default function AgentLeads({ agent, leads = [], statuses = [], portalBase = '/portal/sales', showProfileUpdates = false }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [openStageId, setOpenStageId] = useState(null);
    const [savingId, setSavingId] = useState(null);
    const [expandedId, setExpandedId] = useState(null);
    const [rows, setRows] = useState(leads);
    // Education preserves this page after writes. Use the returned server rows
    // so the update date/author do not remain frozen in the initial state.
    const rowsForView = showProfileUpdates ? leads : rows;
    const columnCount = showProfileUpdates ? 8 : 7;
    const toggleExpand = (id) => setExpandedId(expandedId === id ? null : id);

    const changeStage = (lead, stage) => {
        setSavingId(lead.id);
        router.post(`${portalBase}/leads/${lead.id}`, { status: stage }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setRows((prev) => prev.map((r) => (r.id === lead.id ? { ...r, status: stage, stage } : r))),
            onFinish: () => setSavingId(null),
        });
        setOpenStageId(null);
    };

    const filtered = useMemo(() => {
        const q = searchTerm.trim().toLowerCase();
        const matched = ! q ? rowsForView : rowsForView.filter((r) => {
            const name = (r.name || '').toLowerCase();
            const email = (r.email || '').toLowerCase();
            return name.includes(q) || email.includes(q);
        });
        // Priority always leads: urgent → medium → low → none.
        return [...matched].sort((a, b) => priorityRank(a.priority) - priorityRank(b.priority));
    }, [rowsForView, searchTerm]);

    return (
        <div className="space-y-6 max-w-[1400px] mx-auto pb-12">
            <Head title={`Leads — ${agent.name}`} />

            {/* Header */}
            <div className="flex flex-col gap-3">
                {/* Sub-agents land straight on this screen — there's no parent
                    leads table to go back to, so the drill-in link is hidden. */}
                {portalBase !== '/portal/sub-agent' && (
                    <Link
                        href={`${portalBase}/leads?tab=agents`}
                        className="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors w-max"
                    >
                        <ArrowLeft size={16} /> Back to Leads
                    </Link>
                )}
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div
                            className="w-12 h-12 rounded-full bg-gray-900 text-white flex items-center justify-center font-bold overflow-hidden shrink-0"
                        >
                            {agent.avatar_url
                                ? <img src={agent.avatar_url} alt={agent.name} className="w-full h-full object-cover" />
                                : initials(agent.name)}
                        </div>
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400 mb-1">
                                Agent leads
                            </p>
                            <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{agent.name}</h1>
                            <p className="text-sm text-gray-500 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                {agent.email && (
                                    <span className="inline-flex items-center gap-1">
                                        <Mail size={12} className="text-gray-400" />{agent.email}
                                    </span>
                                )}
                                {agent.phone && (
                                    <span className="inline-flex items-center gap-1">
                                        <Phone size={12} className="text-gray-400" />{agent.phone}
                                    </span>
                                )}
                                {agent.location && (
                                    <span className="inline-flex items-center gap-1">
                                        <MapPin size={12} className="text-gray-400" />{agent.location}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                    <div className="w-full sm:w-72 relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 w-4 h-4" />
                        <input
                            type="text"
                            placeholder="Search name or email…"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-xl bg-white focus:bg-white focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-gray-900 transition-all"
                        />
                    </div>
                </div>
            </div>

            {/* Summary row */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <SummaryTile
                    icon={<Users className="w-5 h-5" />}
                    label="Leads recruited"
                    value={rowsForView.length}
                    tone="blue"
                />
                <SummaryTile
                    icon={<Users className="w-5 h-5" />}
                    label="In pipeline"
                    value={rowsForView.filter((r) => r.status !== 'Closed' && r.status !== 'Not Qualified').length}
                    tone="amber"
                />
            </div>

            {/* Table — mirrors the Open-opportunities design */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-visible">
                <div className="overflow-x-auto overflow-y-visible">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                <th className="pl-4 pr-2 py-3 w-6" />
                                <th className="px-3 py-3">Lead</th>
                                <th className="px-3 py-3">Stage</th>
                                <th className="px-3 py-3">Contact</th>
                                <th className="px-3 py-3">Location</th>
                                <th className="px-3 py-3">Note</th>
                                <th className="px-3 py-3">Added</th>
                                {showProfileUpdates && <th className="px-3 py-3">Updated</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filtered.length === 0 ? (
                                <tr>
                                    <td colSpan={columnCount} className="px-6 py-16 text-center text-gray-400">
                                        <Users size={26} className="mx-auto mb-2 text-gray-300" />
                                        <p className="text-sm font-medium">No leads yet</p>
                                        <p className="text-xs text-gray-400 mt-1">
                                            This agent hasn't added any leads that are still in the pipeline.
                                        </p>
                                    </td>
                                </tr>
                            ) : filtered.map((r) => {
                                const isExpanded = expandedId === r.id;
                                return (
                                    <React.Fragment key={r.id}>
                                        <tr className={`group transition-colors ${isExpanded ? 'bg-blue-50/30' : 'hover:bg-gray-50/50'}`}>
                                            {/* Expand toggle */}
                                            <td className="pl-4 pr-2 py-2.5">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpand(r.id)}
                                                    className="inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                                                    title={isExpanded ? 'Collapse details' : 'Expand details'}
                                                >
                                                    <ChevronRightIcon size={14} className={`transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
                                                </button>
                                            </td>

                                            {/* LEAD — priority-tinted avatar + name */}
                                            <td className="px-3 py-2.5">
                                                <div className="flex items-center gap-2.5 min-w-[180px]">
                                                    <div
                                                        className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0 ${
                                                            priorityMeta(r.priority) ? `${priorityMeta(r.priority).dot} text-white` : 'bg-gray-200 text-gray-500'
                                                        }`}
                                                        title={priorityLabel(r.priority)}
                                                    >
                                                        {initials(r.name)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        {showProfileUpdates ? (
                                                            <Link href={`${portalBase}/leads/${r.id}`} className="block font-semibold text-gray-900 text-xs truncate hover:text-blue-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600" title={`Open ${r.name}'s profile`}>
                                                                {r.name}
                                                            </Link>
                                                        ) : <div className="font-semibold text-gray-900 text-xs truncate">{r.name}</div>}
                                                        {r.lead_id && (
                                                            <div className="text-[10px] text-gray-400 font-mono truncate">{r.lead_id}</div>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>

                                            {/* STAGE — colour-coded picker */}
                                            <td className="px-3 py-2.5 relative">
                                                <StagePicker
                                                    lead={r}
                                                    stages={statuses}
                                                    open={openStageId === r.id}
                                                    onToggle={() => setOpenStageId(openStageId === r.id ? null : r.id)}
                                                    onClose={() => setOpenStageId(null)}
                                                    onSelect={(stage) => changeStage(r, stage)}
                                                    isSaving={savingId === r.id}
                                                />
                                            </td>

                                            {/* CONTACT */}
                                            <td className="px-3 py-2.5">
                                                <div className="flex flex-col gap-0.5 min-w-0">
                                                    <div className="inline-flex items-center gap-1.5 text-gray-600">
                                                        <Mail size={11} className="text-gray-300 flex-shrink-0" />
                                                        <span className="truncate max-w-[180px]">{r.email || '—'}</span>
                                                    </div>
                                                    {r.phone ? (
                                                        <div className="inline-flex items-center gap-1.5 text-gray-500 whitespace-nowrap">
                                                            <Phone size={11} className="text-gray-300 flex-shrink-0" />
                                                            <span>{r.phone}</span>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </td>

                                            {/* LOCATION */}
                                            <td className="px-3 py-2.5">
                                                {r.location ? (
                                                    <div className="inline-flex items-center gap-1.5 text-gray-600">
                                                        <MapPin size={11} className="text-gray-300 flex-shrink-0" />
                                                        <span className="truncate max-w-[140px]">{r.location}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>

                                            {/* NOTE — latest note author + snippet */}
                                            <td className="px-3 py-2.5">
                                                {r.latest_note ? (
                                                    <div className="flex flex-col gap-0.5 min-w-0 max-w-[220px]">
                                                        <div className="inline-flex items-center gap-1.5 text-gray-700 font-medium">
                                                            <StickyNote size={11} className="text-gray-300 flex-shrink-0" />
                                                            <span className="truncate">{r.latest_note.author}</span>
                                                        </div>
                                                        {r.latest_note.body ? (
                                                            <span className="text-[10px] text-gray-400 truncate">{r.latest_note.body}</span>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>

                                            {/* ADDED */}
                                            <td className="px-3 py-2.5 whitespace-nowrap">
                                                <div className="text-gray-600">{fmtDateShort(r.created_at)}</div>
                                                <div className="text-[10px] text-gray-400">{fmtTime(r.created_at)}</div>
                                            </td>
                                            {showProfileUpdates && <td className="px-3 py-2.5 max-w-[200px]">
                                                <div className="text-gray-600 whitespace-nowrap">{fmtDateShort(r.updated_at)}</div>
                                                <div className="text-[10px] text-gray-400">{fmtTime(r.updated_at)}</div>
                                                {r.updated_by && <div className="text-[10px] text-gray-500 truncate" title={r.updated_by}>by {r.updated_by}</div>}
                                                {r.updated_desc && <div className="text-[10px] text-gray-400 truncate" title={r.updated_desc}>{r.updated_desc}</div>}
                                            </td>}
                                        </tr>

                                        {isExpanded && (
                                            <tr className="bg-blue-50/20 border-t border-blue-100/60">
                                                <td colSpan={columnCount} className="px-6 py-4">
                                                    <LeadDashboardPanel lead={r} portalBase={portalBase} />
                                                </td>
                                            </tr>
                                        )}
                                    </React.Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

// ─── Summary tile — same shape as EventRegistrants ───────────────────
function SummaryTile({ icon, label, value, tone = 'gray', valueClass = '' }) {
    const tones = {
        blue:  'bg-blue-50 text-blue-600',
        amber: 'bg-amber-50 text-amber-700',
        gray:  'bg-gray-100 text-gray-700',
    };
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
            <div className={`w-11 h-11 rounded-xl flex items-center justify-center shrink-0 ${tones[tone] || tones.gray}`}>
                {icon}
            </div>
            <div className="min-w-0">
                <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-gray-500">{label}</p>
                <p className={`text-2xl font-bold text-gray-900 leading-none mt-1 ${valueClass}`}>{value}</p>
            </div>
        </div>
    );
}
