import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import {
    Search, Activity, LogIn, ShieldAlert, ChevronDown, ChevronRight, ChevronLeft,
    Globe, MapPin,
} from 'lucide-react';

// Single neutral chip style for every role / portal / action. The visual
// system stays monochrome (dark gray + white + soft gray); scannability
// comes from layout and clear labels rather than colour coding. Severe
// actions (failed sign-ins, deletes) get an inverted dark chip to draw
// the eye without breaking the palette.
const NEUTRAL_CHIP = 'bg-gray-100 text-gray-700 border-gray-200';
const DARK_CHIP    = 'bg-gray-900 text-white border-gray-900';

const tagStyle = (_key) => NEUTRAL_CHIP;
const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '—');

function actionStyle(action = '') {
    if (action === 'login.failed' || action.endsWith('.deleted')) return DARK_CHIP;
    return NEUTRAL_CHIP;
}
const actionLabel = (a = '') => a.replace(/[._]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

const fmtAbsolute = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleString('en-US', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};

const timeAgo = (iso) => {
    if (!iso) return '';
    const diff = Date.now() - new Date(iso).getTime();
    const s = Math.floor(diff / 1000);
    if (s < 60) return 'just now';
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    const d = Math.floor(h / 24);
    if (d < 30) return `${d}d ago`;
    return new Date(iso).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
};

export default function ActivityLogs({ logs = [], actions = [], portals = [] }) {
    const [search, setSearch] = useState('');
    const [actionFilter, setActionFilter] = useState('All');
    const [portalFilter, setPortalFilter] = useState('All');
    const [expanded, setExpanded] = useState(null);

    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);

    const filtered = logs.filter(l => {
        const hay = `${l.actor_name || ''} ${l.user?.email || ''} ${l.action || ''} ${l.description || ''} ${l.ip_address || ''} ${l.portal || ''}`.toLowerCase();
        const matchesSearch = !search || hay.includes(search.toLowerCase());
        const matchesAction = actionFilter === 'All' || l.action === actionFilter;
        const matchesPortal = portalFilter === 'All' || l.portal === portalFilter;
        return matchesSearch && matchesAction && matchesPortal;
    });

    const totalItems = filtered.length;
    const totalPages = pageSize === 'all' ? 1 : Math.max(1, Math.ceil(totalItems / pageSize));
    const startIdx = pageSize === 'all' ? 0 : (currentPage - 1) * pageSize;
    const endIdx = pageSize === 'all' ? totalItems : Math.min(startIdx + pageSize, totalItems);
    const pageItems = pageSize === 'all' ? filtered : filtered.slice(startIdx, endIdx);

    useEffect(() => { setCurrentPage(1); }, [search, actionFilter, portalFilter, pageSize]);
    useEffect(() => { if (currentPage > totalPages) setCurrentPage(totalPages); }, [currentPage, totalPages]);

    const getPageNumbers = () => {
        const delta = 1;
        const pages = [];
        const left = Math.max(2, currentPage - delta);
        const right = Math.min(totalPages - 1, currentPage + delta);
        pages.push(1);
        if (left > 2) pages.push('…');
        for (let i = left; i <= right; i++) pages.push(i);
        if (right < totalPages - 1) pages.push('…');
        if (totalPages > 1) pages.push(totalPages);
        return pages;
    };

    const signIns = logs.filter(l => l.action === 'login').length;
    const failed = logs.filter(l => l.action === 'login.failed').length;

    const summaryCards = [
        { label: 'Total Events', value: logs.length, icon: <Activity className="w-5 h-5" />, dark: true, onClick: () => { setActionFilter('All'); setPortalFilter('All'); } },
        { label: 'Sign-ins', value: signIns, icon: <LogIn className="w-5 h-5" />, onClick: () => setActionFilter('login') },
        { label: 'Failed Sign-ins', value: failed, icon: <ShieldAlert className="w-5 h-5" />, onClick: () => setActionFilter('login.failed') },
    ];

    return (
        <div className="space-y-6 max-w-[1600px] mx-auto pb-12">
            <Head title="Activity Log" />

            <div className="hidden lg:flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Activity Log</h1>
                    <p className="text-sm text-gray-500 mt-1">Audit trail of sign-ins and admin actions across every portal — who, what, when, role and IP address.</p>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {summaryCards.map((card, idx) => (
                    <div
                        key={idx}
                        onClick={card.onClick}
                        className={`p-5 rounded-2xl cursor-pointer transition-all duration-300 hover:scale-[1.02] ${card.dark ? 'bg-gray-900 text-white shadow-lg' : 'bg-white text-gray-900 shadow-sm border border-gray-100'}`}
                    >
                        <div className="flex items-center justify-between mb-3">
                            <span className={`text-sm font-medium ${card.dark ? 'text-gray-300' : 'text-gray-500'}`}>{card.label}</span>
                            <span className={`p-1.5 rounded-lg ${card.dark ? 'bg-white/10 text-white' : 'bg-gray-100 text-gray-500'}`}>{card.icon}</span>
                        </div>
                        <p className="text-3xl font-bold tracking-tight">{card.value}</p>
                    </div>
                ))}
            </div>

            <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex flex-col gap-3">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between lg:gap-4">
                    <div className="w-full lg:w-80 relative">
                        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <Search className="h-5 w-5 text-gray-400" />
                        </div>
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="block w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-xl bg-gray-50 placeholder-gray-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-all"
                            placeholder="Search by user, email, action, IP, description..."
                        />
                    </div>

                    {portals.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-xs font-semibold text-gray-400 mr-1 flex items-center gap-1"><Globe size={13} /> Portal</span>
                            {['All', ...portals].map(tab => (
                                <button key={tab} onClick={() => setPortalFilter(tab)}
                                    className={`px-3 py-1.5 rounded-xl text-xs font-bold border transition-all duration-200 ${portalFilter === tab ? 'bg-gray-900 text-white border-gray-900 shadow-sm' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'}`}>
                                    {tab === 'All' ? 'All' : cap(tab)}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {actions.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
                        <span className="text-xs font-semibold text-gray-400 mr-1">Action</span>
                        {['All', ...actions].map(tab => (
                            <button key={tab} onClick={() => setActionFilter(tab)}
                                className={`px-3 py-1.5 rounded-xl text-xs font-bold border transition-all duration-200 ${actionFilter === tab ? 'bg-gray-900 text-white border-gray-900 shadow-sm' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'}`}>
                                {tab === 'All' ? 'All' : actionLabel(tab)}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="overflow-x-auto min-h-[400px]">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-gray-50/50 border-b border-gray-100">
                                <th className="px-4 py-4 w-8"></th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">When</th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actor</th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Role</th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Portal</th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">IP Address</th>
                                <th className="px-4 py-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {pageItems.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-6 py-16 text-center text-gray-400">
                                        <Activity className="w-10 h-10 mx-auto mb-3 text-gray-200" />
                                        <p className="font-semibold">No activity yet</p>
                                        <p className="text-sm mt-1">Sign-ins and admin actions will appear here.</p>
                                    </td>
                                </tr>
                            ) : pageItems.map(log => {
                                const details = { ...(log.properties || {}), ...(log.metadata ? { audit: log.metadata } : {}) };
                                const hasProps = Object.keys(details).length > 0;
                                const isOpen = expanded === log.id;
                                return (
                                    <React.Fragment key={log.id}>
                                        <tr
                                            className={`transition-colors ${hasProps ? 'cursor-pointer hover:bg-gray-50' : 'hover:bg-gray-50/60'}`}
                                            onClick={() => hasProps && setExpanded(isOpen ? null : log.id)}
                                        >
                                            <td className="px-4 py-3 text-gray-300">
                                                {hasProps && (isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />)}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="text-sm font-medium text-gray-900">{timeAgo(log.created_at)}</div>
                                                <div className="text-[11px] text-gray-400">{fmtAbsolute(log.created_at)}</div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="w-7 h-7 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                                                        {(log.actor_name || '?').charAt(0).toUpperCase()}
                                                    </div>
                                                    <div>
                                                        <div className="text-sm font-semibold text-gray-900">{log.actor_name || 'Unknown'}</div>
                                                        {log.user?.email && <div className="text-[11px] text-gray-400">{log.user.email}</div>}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {log.actor_role
                                                    ? <span className={`inline-flex px-2 py-0.5 rounded-full text-[11px] font-bold border ${tagStyle(log.actor_role)}`}>{cap(log.actor_role)}</span>
                                                    : <span className="text-gray-300 text-xs">—</span>}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className={`inline-flex px-2.5 py-1 rounded-full text-[11px] font-bold border ${actionStyle(log.action)}`}>{actionLabel(log.action)}</span>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {log.portal
                                                    ? <span className={`inline-flex px-2 py-0.5 rounded-full text-[11px] font-bold border ${tagStyle(log.portal)}`}>{cap(log.portal)}</span>
                                                    : <span className="text-gray-300 text-xs">—</span>}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600 font-mono">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <MapPin size={12} className="text-gray-300" />
                                                    {log.ip_address || '—'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-600 max-w-[320px] truncate">{log.description || '—'}</td>
                                        </tr>
                                        {isOpen && hasProps && (
                                            <tr className="bg-gray-50/60">
                                                <td></td>
                                                <td colSpan={7} className="px-4 py-3">
                                                    <div className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Details</div>
                                                    <pre className="text-xs text-gray-700 bg-white border border-gray-200 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap">
{JSON.stringify(details, null, 2)}
                                                    </pre>
                                                </td>
                                            </tr>
                                        )}
                                    </React.Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {totalItems > 0 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50/30">
                        <div className="flex items-center gap-2 text-sm text-gray-500">
                            <span>Show</span>
                            <select
                                value={pageSize}
                                onChange={e => setPageSize(e.target.value === 'all' ? 'all' : parseInt(e.target.value, 10))}
                                className="bg-white border border-gray-200 text-gray-700 text-sm rounded-lg py-1 pl-2 pr-7 outline-none hover:bg-gray-50 cursor-pointer"
                            >
                                <option value={25}>25</option>
                                <option value={50}>50</option>
                                <option value={100}>100</option>
                                <option value="all">All</option>
                            </select>
                            <span>per page</span>
                        </div>

                        <div className="text-sm text-gray-500">
                            Showing <span className="font-semibold text-gray-900">{startIdx + 1}</span> to <span className="font-semibold text-gray-900">{endIdx}</span> of <span className="font-semibold text-gray-900">{totalItems}</span>
                        </div>

                        {pageSize !== 'all' && totalPages > 1 && (
                            <div className="flex items-center gap-1">
                                <button
                                    onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                    disabled={currentPage === 1}
                                    className="flex items-center gap-1 px-3 py-1.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    <ChevronLeft size={14} /> Prev
                                </button>
                                {getPageNumbers().map((p, i) => (
                                    p === '…' ? (
                                        <span key={'ellipsis-' + i} className="px-2 text-gray-400 text-sm">…</span>
                                    ) : (
                                        <button
                                            key={p}
                                            onClick={() => setCurrentPage(p)}
                                            className={`min-w-[32px] px-2 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                                                currentPage === p ? 'bg-gray-900 text-white shadow-sm' : 'text-gray-700 hover:bg-gray-100'
                                            }`}
                                        >
                                            {p}
                                        </button>
                                    )
                                ))}
                                <button
                                    onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                                    disabled={currentPage === totalPages}
                                    className="flex items-center gap-1 px-3 py-1.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    Next <ChevronRight size={14} />
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
