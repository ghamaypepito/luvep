import { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import {
    MessageSquare, Plus, Mail, Smartphone, Folder, FolderPlus,
    ChevronRight, Pencil, Trash2, FolderInput, X, CornerUpLeft, Image as ImageIcon,
    Building2, Copy, AlertTriangle,
} from "lucide-react";

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString("en-US", { day: "2-digit", month: "short", year: "numeric" }) : "—");
const titleCase = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : "");

/**
 * Shared list of message templates, reused by the admin area and every
 * department portal. Templates can be grouped into shared folders: the root
 * shows folder cards plus ungrouped templates; clicking a folder drills into
 * it. Grouping is driven client-side off each template's folder_id — the
 * server just persists folder_id via the folders/move endpoints.
 */
export default function TemplateListView({ templates = [], folders = [], departmentOptions = [], basePath = "/admin/message-templates", scopeLabel = "", fixedDepartment = null }) {
    const isAdmin = basePath.startsWith("/admin");
    // Department tabs + move-to-department show wherever department options are
    // available — which is now every portal, not just admin (the template
    // library is shared, so the same organiser belongs on every screen).
    const showDepartments = departmentOptions.length > 0;
    const [activeTab, setActiveTab] = useState("all");
    // Department segmentation (admin only). "__all" = every department.
    const [deptTab, setDeptTab] = useState("__all");
    const [currentFolderId, setCurrentFolderId] = useState(null); // null = root
    const [selected, setSelected] = useState(() => new Set());
    const [moveOpen, setMoveOpen] = useState(false);
    const [deptMoveOpen, setDeptMoveOpen] = useState(false);
    const [folderDeptOpen, setFolderDeptOpen] = useState(false);
    const [folderDupOpen, setFolderDupOpen] = useState(false);
    // In-app dialog instead of window.prompt/confirm.
    // { type: 'create'|'rename'|'delete', name, folder?, preselectIds? }
    const [dialog, setDialog] = useState(null);

    const currentFolder = folders.find((f) => f.id === currentFolderId) || null;

    const byChannel = (t) => (activeTab === "email" ? t.channels.includes("email") : activeTab === "sms" ? t.channels.includes("sms") : true);
    // Department filter — "__all" matches everything; "" matches the shared set.
    // Only applies at the root: inside a folder, folder membership is what
    // decides (a folder is single-department already).
    const byDept = (t) => (!showDepartments || deptTab === "__all" || currentFolderId !== null ? true : (t.department || "") === deptTab);

    // Templates shown in the current view: inside a folder → that folder's
    // members; at root → only ungrouped templates (folders hold the rest).
    const visibleTemplates = useMemo(
        () => templates.filter(byChannel).filter(byDept).filter((t) => (currentFolderId === null ? !t.folder_id : t.folder_id === currentFolderId)),
        [templates, activeTab, deptTab, showDepartments, currentFolderId],
    );

    const folderCount = (id) => templates.filter((t) => t.folder_id === id).length;

    // Folders shown under the active department tab ("__all" shows them all).
    const visibleFolders = useMemo(
        () => folders.filter((f) => !showDepartments || deptTab === "__all" || (f.department || "") === deptTab),
        [folders, deptTab, showDepartments],
    );

    // Department tabs (admin only): "All", then Shared + each portal, each with
    // a live count over the channel-filtered set.
    const deptTabs = useMemo(() => {
        const base = templates.filter(byChannel);
        const countFor = (val) => (val === "__all" ? base.length : base.filter((t) => (t.department || "") === val).length);
        const tabs = [{ value: "__all", label: "All" }];
        for (const o of departmentOptions) tabs.push({ value: o.value, label: o.value === "" ? "Shared" : o.label });
        return tabs.map((t) => ({ ...t, count: countFor(t.value) }));
    }, [templates, departmentOptions, activeTab]);

    const toggleSelect = (id) => setSelected((prev) => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });
    const clearSelection = () => setSelected(new Set());

    // Header "select all" — acts on whatever's currently visible (respects the
    // active department + channel tab and folder view).
    const allVisibleSelected = visibleTemplates.length > 0 && visibleTemplates.every((t) => selected.has(t.id));
    const someVisibleSelected = visibleTemplates.some((t) => selected.has(t.id));
    const toggleSelectAll = () => setSelected((prev) => {
        const next = new Set(prev);
        if (allVisibleSelected) visibleTemplates.forEach((t) => next.delete(t.id));
        else visibleTemplates.forEach((t) => next.add(t.id));
        return next;
    });

    const enterFolder = (id) => { setCurrentFolderId(id); clearSelection(); };

    const post = (url, body, extra = {}) => router.post(url, body, { preserveScroll: true, preserveState: false, ...extra });

    // The department the current tab represents ('' = Shared). New folders and
    // templates created from within a tab are filed under it.
    const tabDept = fixedDepartment ?? (deptTab === "__all" ? "" : deptTab);

    // Dialog openers. New folders inherit the active department tab.
    const createFolder = (preselectIds = null) => setDialog({ type: "create", name: "", preselectIds: preselectIds ? [...preselectIds] : [], department: tabDept });
    const renameFolder = (folder) => setDialog({ type: "rename", name: folder.name, folder });
    const deleteFolder = (folder) => setDialog({ type: "delete", folder });

    const moveFolderToDept = (folder, department) => {
        post(`${basePath}/folders/${folder.id}/department`, { department });
        setFolderDeptOpen(false);
    };

    // Clone the folder + all its templates into another department (originals
    // untouched). Confirms first via the dialog.
    const duplicateFolderToDept = (folder, department) => {
        setFolderDupOpen(false);
        post(`${basePath}/folders/${folder.id}/duplicate`, { department });
    };

    // Dialog confirm — runs the action for the open dialog.
    const submitDialog = () => {
        if (!dialog) return;
        const name = (dialog.name || "").trim();

        if (dialog.type === "create") {
            if (!name) return;
            post(`${basePath}/folders`, { name, template_ids: dialog.preselectIds || [], department: dialog.department ?? "" });
            clearSelection();
        } else if (dialog.type === "rename") {
            if (!name || name === dialog.folder.name) { setDialog(null); return; }
            router.put(`${basePath}/folders/${dialog.folder.id}`, { name }, { preserveScroll: true });
        } else if (dialog.type === "delete") {
            router.delete(`${basePath}/folders/${dialog.folder.id}`, {
                preserveScroll: true,
                onSuccess: () => setCurrentFolderId((c) => (c === dialog.folder.id ? null : c)),
            });
        } else if (dialog.type === "deleteTemplates") {
            post(`${basePath}/delete`, { ids: dialog.ids });
            clearSelection();
        }
        setDialog(null);
    };

    const moveTo = (folderId) => {
        if (selected.size === 0) return;
        post(`${basePath}/move`, { ids: [...selected], folder_id: folderId });
        clearSelection();
        setMoveOpen(false);
    };

    const removeFromFolder = (id) => post(`${basePath}/move`, { ids: [id], folder_id: null });

    const setDepartment = (department) => {
        if (selected.size === 0) return;
        post(`${basePath}/move-department`, { ids: [...selected], department });
        clearSelection();
        setDeptMoveOpen(false);
    };

    // Bulk delete only appears once templates are selected, and confirms first.
    const confirmDeleteSelected = () => {
        if (selected.size === 0) return;
        setDialog({ type: "deleteTemplates", ids: [...selected], count: selected.size });
    };

    // New template lands in the current folder's department, else the active tab.
    const createDept = currentFolder ? (currentFolder.department || "") : (showDepartments && deptTab !== "__all" ? tabDept : null);
    const createHref = (() => {
        const params = new URLSearchParams();
        if (currentFolderId) params.set("folder_id", currentFolderId);
        if (activeTab !== "all") params.set("channel", activeTab);
        if (createDept !== null) params.set("department", createDept);
        const qs = params.toString();
        return `${basePath}/create${qs ? `?${qs}` : ""}`;
    })();

    // Email Branding lives alongside templates in the same scope (admin or portal).
    const brandingHref = basePath.replace("message-templates", "email-branding").replace("email-templates", "email-branding");

    return (
        <div className="space-y-6 max-w-5xl mx-auto">
            <Head title="Message Templates" />

            <header className="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <MessageSquare className="w-6 h-6 text-gray-700" /> Message Templates
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Reusable email &amp; SMS templates sent to leads{scopeLabel ? ` · ${scopeLabel}` : ""}.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Link href={brandingHref} className="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-semibold rounded-xl hover:bg-gray-50 flex items-center gap-2">
                        <ImageIcon size={15} /> Email branding
                    </Link>
                    {currentFolderId === null && (
                        <button
                            type="button"
                            onClick={() => createFolder()}
                            className="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-semibold rounded-xl hover:bg-gray-50 flex items-center gap-2"
                        >
                            <FolderPlus size={15} /> New folder
                        </button>
                    )}
                    <Link href={createHref} className="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black flex items-center gap-2">
                        <Plus size={15} /> New template
                    </Link>
                </div>
            </header>

            {/* Breadcrumb when inside a folder */}
            {currentFolder && (
                <div className="flex items-center gap-1.5 text-sm">
                    <button type="button" onClick={() => enterFolder(null)} className="text-gray-500 hover:text-gray-900">All templates</button>
                    <ChevronRight size={14} className="text-gray-300" />
                    <span className="font-semibold text-gray-900 flex items-center gap-1.5"><Folder size={14} className="text-amber-500" /> {currentFolder.name}</span>
                    <button type="button" onClick={() => renameFolder(currentFolder)} title="Rename" className="ml-2 text-gray-400 hover:text-gray-700"><Pencil size={14} /></button>
                    <button type="button" onClick={() => deleteFolder(currentFolder)} title="Delete folder" className="text-gray-400 hover:text-rose-600"><Trash2 size={14} /></button>
                    {showDepartments && (
                        <>
                            <span className="mx-1 h-4 w-px bg-gray-200" />
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${currentFolder.department ? "bg-indigo-50 text-indigo-700" : "bg-gray-100 text-gray-500"}`}>
                                {currentFolder.department ? titleCase(currentFolder.department) : "Shared"}
                            </span>
                            <div className="relative">
                                <button type="button" onClick={() => { setFolderDeptOpen((o) => !o); setFolderDupOpen(false); }} title="Move this folder to another department" className="flex items-center gap-1 text-gray-400 hover:text-gray-700 text-xs font-semibold">
                                    <Building2 size={13} /> Move
                                </button>
                                {folderDeptOpen && (
                                    <div className="absolute left-0 mt-1 w-56 max-h-64 overflow-y-auto bg-white text-gray-800 rounded-xl shadow-lg border border-gray-100 py-1 z-20">
                                        {departmentOptions.map((o) => (
                                            <button key={o.value || "shared"} type="button" onClick={() => moveFolderToDept(currentFolder, o.value)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                                                <Building2 size={14} className={o.value ? "text-indigo-500" : "text-gray-400"} /> {o.value === "" ? "Shared (all departments)" : o.label}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="relative">
                                <button type="button" onClick={() => { setFolderDupOpen((o) => !o); setFolderDeptOpen(false); }} title="Duplicate this folder and all its templates into another department" className="flex items-center gap-1 text-gray-400 hover:text-indigo-600 text-xs font-semibold">
                                    <Copy size={13} /> Duplicate to…
                                </button>
                                {folderDupOpen && (
                                    <div className="absolute left-0 mt-1 w-64 max-h-64 overflow-y-auto bg-white text-gray-800 rounded-xl shadow-lg border border-gray-100 py-1 z-20">
                                        <div className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Copy folder + templates to</div>
                                        {departmentOptions.map((o) => (
                                            <button key={o.value || "shared"} type="button" onClick={() => duplicateFolderToDept(currentFolder, o.value)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                                                <Copy size={14} className={o.value ? "text-indigo-500" : "text-gray-400"} /> {o.value === "" ? "Shared (all departments)" : o.label}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            )}

            {/* Department segmentation — admin only. Keeps the cross-department
                library from piling into one screen. */}
            {showDepartments && (
                <div className="flex items-center gap-1.5 flex-wrap">
                    {deptTabs.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => { setDeptTab(tab.value); clearSelection(); }}
                            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors ${
                                deptTab === tab.value
                                    ? "bg-gray-900 text-white border-gray-900"
                                    : "bg-white text-gray-600 border-gray-200 hover:border-gray-300 hover:text-gray-900"
                            }`}
                        >
                            {tab.label}
                            <span className={`inline-flex items-center justify-center min-w-[1.25rem] px-1 rounded-full text-[10px] ${deptTab === tab.value ? "bg-white/20 text-white" : "bg-gray-100 text-gray-500"}`}>
                                {tab.count}
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {/* Channel filter tabs */}
            <div className="flex items-center gap-1 border-b border-gray-100">
                {[
                    { key: "all", label: "All Templates" },
                    { key: "email", label: "Email Templates" },
                    { key: "sms", label: "SMS Templates" },
                ].map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => setActiveTab(tab.key)}
                        className={`px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors ${activeTab === tab.key ? "border-gray-900 text-gray-900" : "border-transparent text-gray-400 hover:text-gray-700"}`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {/* Selection action bar */}
            {selected.size > 0 && (
                <div className="flex items-center gap-3 bg-gray-900 text-white rounded-2xl px-4 py-2.5 text-sm">
                    <span className="font-semibold">{selected.size} selected</span>
                    <div className="flex items-center gap-2 ml-auto">
                        {currentFolderId === null && (
                            <button type="button" onClick={() => createFolder(selected)} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20">
                                <FolderPlus size={14} /> Group into new folder
                            </button>
                        )}
                        <div className="relative">
                            <button type="button" onClick={() => setMoveOpen((o) => !o)} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20">
                                <FolderInput size={14} /> Move to…
                            </button>
                            {moveOpen && (
                                <div className="absolute right-0 mt-1 w-56 max-h-64 overflow-y-auto bg-white text-gray-800 rounded-xl shadow-lg border border-gray-100 py-1 z-20">
                                    {currentFolderId !== null && (
                                        <button type="button" onClick={() => moveTo(null)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                                            <CornerUpLeft size={14} className="text-gray-400" /> Root (ungrouped)
                                        </button>
                                    )}
                                    {visibleFolders.filter((f) => f.id !== currentFolderId).map((f) => (
                                        <button key={f.id} type="button" onClick={() => moveTo(f.id)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                                            <Folder size={14} className="text-amber-500" /> {f.name}
                                        </button>
                                    ))}
                                    {visibleFolders.filter((f) => f.id !== currentFolderId).length === 0 && currentFolderId === null && (
                                        <div className="px-3 py-2 text-xs text-gray-400">No folders in this tab.</div>
                                    )}
                                </div>
                            )}
                        </div>
                        {showDepartments && (
                            <div className="relative">
                                <button type="button" onClick={() => { setDeptMoveOpen((o) => !o); setMoveOpen(false); }} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20">
                                    <Building2 size={14} /> Move to department…
                                </button>
                                {deptMoveOpen && (
                                    <div className="absolute right-0 mt-1 w-56 max-h-64 overflow-y-auto bg-white text-gray-800 rounded-xl shadow-lg border border-gray-100 py-1 z-20">
                                        {departmentOptions.map((o) => (
                                            <button key={o.value || "shared"} type="button" onClick={() => setDepartment(o.value)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                                                <Building2 size={14} className={o.value ? "text-indigo-500" : "text-gray-400"} /> {o.value === "" ? "Shared (all departments)" : o.label}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                        <button type="button" onClick={confirmDeleteSelected} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-500/90 hover:bg-rose-500 text-white">
                            <Trash2 size={14} /> Delete
                        </button>
                        <button type="button" onClick={clearSelection} className="p-1.5 rounded-lg hover:bg-white/20"><X size={14} /></button>
                    </div>
                </div>
            )}

            {/* Folder grid — root only, scoped to the active department tab. */}
            {currentFolderId === null && visibleFolders.length > 0 && (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    {visibleFolders.map((f) => (
                        <button
                            key={f.id}
                            type="button"
                            onClick={() => enterFolder(f.id)}
                            className="group text-left bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-amber-200 transition-all p-4 flex items-center gap-3"
                        >
                            <span className="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center flex-shrink-0"><Folder size={20} /></span>
                            <span className="min-w-0">
                                <span className="block font-semibold text-gray-900 text-sm truncate">{f.name}</span>
                                <span className="block text-xs text-gray-400">{folderCount(f.id)} template{folderCount(f.id) === 1 ? "" : "s"}</span>
                            </span>
                            <ChevronRight size={16} className="ml-auto text-gray-300 group-hover:text-gray-500 flex-shrink-0" />
                        </button>
                    ))}
                </div>
            )}

            {/* Template table */}
            <div className="bg-white rounded-3xl border border-gray-50 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="bg-gray-50/50 border-y border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <th className="px-4 py-3 w-10">
                                    <input
                                        type="checkbox"
                                        ref={(el) => { if (el) el.indeterminate = !allVisibleSelected && someVisibleSelected; }}
                                        checked={allVisibleSelected}
                                        onChange={toggleSelectAll}
                                        disabled={visibleTemplates.length === 0}
                                        title="Select all"
                                        className="rounded border-gray-300 disabled:opacity-40"
                                    />
                                </th>
                                <th className="px-6 py-3">Template</th>
                                <th className="px-6 py-3">Key</th>
                                <th className="px-6 py-3">Department</th>
                                <th className="px-6 py-3">Channels</th>
                                <th className="px-6 py-3">Active</th>
                                <th className="px-6 py-3">Updated</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {visibleTemplates.length === 0 ? (
                                <tr><td colSpan={8} className="px-6 py-16 text-center text-sm text-gray-400">
                                    {currentFolder ? "This folder is empty." : "No templates here."}
                                </td></tr>
                            ) : (
                                visibleTemplates.map((t) => (
                                    <tr key={t.id} className={`hover:bg-gray-50/40 ${selected.has(t.id) ? "bg-indigo-50/40" : ""}`}>
                                        <td className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={selected.has(t.id)}
                                                onChange={() => toggleSelect(t.id)}
                                                onClick={(e) => e.stopPropagation()}
                                                className="rounded border-gray-300"
                                            />
                                        </td>
                                        <td className="px-6 py-3 font-semibold text-gray-900 text-sm cursor-pointer" onClick={() => router.visit(`${basePath}/${t.id}`)}>{t.name}</td>
                                        <td className="px-6 py-3 cursor-pointer" onClick={() => router.visit(`${basePath}/${t.id}`)}>
                                            {t.key
                                                ? <code className="text-xs bg-gray-100 rounded px-1.5 py-0.5 text-gray-600">{t.key}</code>
                                                : <span className="inline-flex items-center gap-1 text-xs font-semibold text-amber-600"><AlertTriangle size={12} /> Set a key</span>}
                                        </td>
                                        <td className="px-6 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${t.department ? "bg-indigo-50 text-indigo-700" : "bg-gray-100 text-gray-500"}`}>
                                                {t.department ? titleCase(t.department) : "Shared"}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3">
                                            <div className="flex items-center gap-1.5">
                                                {t.channels.includes("email") && <span className="inline-flex items-center gap-1 text-xs bg-blue-50 text-blue-700 rounded-full px-2 py-0.5"><Mail size={11} /> Email</span>}
                                                {t.channels.includes("sms") && <span className="inline-flex items-center gap-1 text-xs bg-emerald-50 text-emerald-700 rounded-full px-2 py-0.5"><Smartphone size={11} /> SMS</span>}
                                            </div>
                                        </td>
                                        <td className="px-6 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${t.is_active ? "bg-emerald-50 text-emerald-700" : "bg-gray-100 text-gray-500"}`}>
                                                {t.is_active ? "Active" : "Inactive"}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3 text-sm text-gray-500">{fmtDate(t.updated_at)}</td>
                                        <td className="px-6 py-3 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <button type="button" onClick={() => router.visit(`${basePath}/${t.id}/duplicate`)} title="Duplicate (creates a copy with a blank key)" className="text-gray-300 hover:text-indigo-600"><Copy size={15} /></button>
                                                {currentFolder && (
                                                    <button type="button" onClick={() => removeFromFolder(t.id)} title="Remove from folder" className="text-gray-300 hover:text-rose-600"><CornerUpLeft size={15} /></button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Folder dialog (create / rename / delete) */}
            {dialog && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={() => setDialog(null)}>
                    <div className="absolute inset-0 bg-gray-900/40 backdrop-blur-sm" />
                    <div className="relative bg-white rounded-2xl shadow-xl border border-gray-100 w-full max-w-sm p-6" onClick={(e) => e.stopPropagation()}>
                        {dialog.type === "deleteTemplates" ? (
                            <>
                                <div className="flex items-center gap-3 mb-3">
                                    <span className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center flex-shrink-0"><Trash2 size={18} /></span>
                                    <h3 className="text-base font-bold text-gray-900">Delete {dialog.count} template{dialog.count === 1 ? "" : "s"}</h3>
                                </div>
                                <p className="text-sm text-gray-500 mb-6">
                                    Permanently delete the selected template{dialog.count === 1 ? "" : "s"}? This can&rsquo;t be undone from here.
                                </p>
                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setDialog(null)} className="px-4 py-2 text-sm font-semibold text-gray-600 rounded-xl hover:bg-gray-100">Cancel</button>
                                    <button type="button" onClick={submitDialog} className="px-4 py-2 text-sm font-semibold text-white bg-rose-600 rounded-xl hover:bg-rose-700">Delete {dialog.count} template{dialog.count === 1 ? "" : "s"}</button>
                                </div>
                            </>
                        ) : dialog.type === "delete" ? (
                            <>
                                <div className="flex items-center gap-3 mb-3">
                                    <span className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center flex-shrink-0"><Trash2 size={18} /></span>
                                    <h3 className="text-base font-bold text-gray-900">Delete folder</h3>
                                </div>
                                <p className="text-sm text-gray-500 mb-6">
                                    Delete <span className="font-semibold text-gray-700">&ldquo;{dialog.folder.name}&rdquo;</span>? Its {folderCount(dialog.folder.id)} template{folderCount(dialog.folder.id) === 1 ? "" : "s"} move back to the root — they are not deleted.
                                </p>
                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setDialog(null)} className="px-4 py-2 text-sm font-semibold text-gray-600 rounded-xl hover:bg-gray-100">Cancel</button>
                                    <button type="button" onClick={submitDialog} className="px-4 py-2 text-sm font-semibold text-white bg-rose-600 rounded-xl hover:bg-rose-700">Delete folder</button>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="flex items-center gap-3 mb-4">
                                    <span className="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center flex-shrink-0">
                                        {dialog.type === "create" ? <FolderPlus size={18} /> : <Pencil size={18} />}
                                    </span>
                                    <h3 className="text-base font-bold text-gray-900">
                                        {dialog.type === "create" ? "New folder" : "Rename folder"}
                                    </h3>
                                </div>
                                {dialog.type === "create" && showDepartments && (
                                    <p className="text-xs text-gray-500 mb-2">Filed under <span className="font-semibold">{dialog.department ? titleCase(dialog.department) : "Shared"}</span>.</p>
                                )}
                                {dialog.type === "create" && dialog.preselectIds?.length > 0 && (
                                    <p className="text-xs text-gray-400 mb-2">{dialog.preselectIds.length} selected template{dialog.preselectIds.length === 1 ? "" : "s"} will be moved into it.</p>
                                )}
                                <label className="block text-xs font-semibold text-gray-500 mb-1.5">Folder name</label>
                                <input
                                    autoFocus
                                    value={dialog.name}
                                    onChange={(e) => setDialog((d) => ({ ...d, name: e.target.value }))}
                                    onKeyDown={(e) => { if (e.key === "Enter") submitDialog(); if (e.key === "Escape") setDialog(null); }}
                                    placeholder="e.g. Onboarding"
                                    className="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                                />
                                <div className="flex justify-end gap-2 mt-6">
                                    <button type="button" onClick={() => setDialog(null)} className="px-4 py-2 text-sm font-semibold text-gray-600 rounded-xl hover:bg-gray-100">Cancel</button>
                                    <button type="button" onClick={submitDialog} disabled={!dialog.name.trim()} className="px-4 py-2 text-sm font-semibold text-white bg-gray-900 rounded-xl hover:bg-black disabled:opacity-40">
                                        {dialog.type === "create" ? "Create folder" : "Save"}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
