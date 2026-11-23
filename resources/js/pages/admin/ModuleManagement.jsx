import { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Boxes, Search, Save, Check, ShieldCheck, User as UserIcon } from "lucide-react";

// The server drops redundant child grants when their parent is present.
function normalizedModuleGrants(keys) {
    const grants = new Set(keys);
    return [...grants].filter((key) => ! key.includes(".") || ! grants.has(key.split(".")[0]));
}

// Super-admin-only: grant per-user access to restricted modules. Every other
// module is grandfathered (visible by role) and never appears here — only the
// modules that ship hidden-by-default are toggled per user.
export default function ModuleManagement({ modules = [], users = [], portals = [] }) {
    const [search, setSearch] = useState("");
    const [selectedId, setSelectedId] = useState(null);
    const [granted, setGranted] = useState(() => new Set());
    const [grantedPortals, setGrantedPortals] = useState(() => new Set());
    const [saving, setSaving] = useState(false);
    const [savingPortals, setSavingPortals] = useState(false);
    const [moduleNotice, setModuleNotice] = useState(null);

    const selected = useMemo(() => users.find((u) => u.id === selectedId) || null, [users, selectedId]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (! q) return users;
        return users.filter((u) =>
            (u.name || "").toLowerCase().includes(q)
            || (u.email || "").toLowerCase().includes(q)
            || (u.role || "").toLowerCase().includes(q)
        );
    }, [users, search]);

    const selectUser = (u) => {
        setModuleNotice(null);
        setSelectedId(u.id);
        setGranted(new Set(u.modules || []));
        setGrantedPortals(new Set(u.portals || []));
    };

    const togglePortal = (key) => setGrantedPortals((prev) => {
        const next = new Set(prev);
        next.has(key) ? next.delete(key) : next.add(key);
        return next;
    });

    const featureKeys = (m) => (m.features || []).map((f) => f.key);

    // "Full access" is a convenience that mirrors "every part on" — but the
    // individual parts stay independently toggleable. We store the individual
    // feature keys (not the bare parent) so you can enable/disable any single
    // part freely; a legacy bare-parent grant is expanded to parts on first edit.
    const wholeOn = (m) => {
        const fks = featureKeys(m);
        if (fks.length === 0) return granted.has(m.key);        // module with no parts
        return granted.has(m.key) || fks.every((k) => granted.has(k));
    };
    const featureOn = (m, key) => granted.has(m.key) || granted.has(key);

    const toggleWhole = (m) => {
        setModuleNotice(null);
        setGranted((prev) => {
            const next = new Set(prev);
            const fks = featureKeys(m);
            if (fks.length === 0) {                                 // no parts → simple toggle
                next.has(m.key) ? next.delete(m.key) : next.add(m.key);
                return next;
            }
            const on = next.has(m.key) || fks.every((k) => next.has(k));
            next.delete(m.key);                                    // drop legacy bare-parent form
            fks.forEach((k) => next.delete(k));
            if (! on) fks.forEach((k) => next.add(k));             // turning ON = every part
            return next;
        });
    };

    const toggleFeature = (m, key) => {
        setModuleNotice(null);
        setGranted((prev) => {
            const next = new Set(prev);
            // Expand a legacy whole-module grant into explicit parts so a single
            // part can be flipped without losing the others.
            if (next.has(m.key)) {
                next.delete(m.key);
                featureKeys(m).forEach((k) => next.add(k));
            }
            next.has(key) ? next.delete(key) : next.add(key);
            return next;
        });
    };

    // Dirty check vs the user's saved grant set.
    const dirty = useMemo(() => {
        if (! selected) return false;
        const saved = new Set(selected.modules || []);
        if (saved.size !== granted.size) return true;
        for (const k of granted) if (! saved.has(k)) return true;
        return false;
    }, [selected, granted]);

    const save = () => {
        if (! selected || selected.is_super_admin || saving) return;
        const submitted = normalizedModuleGrants(granted);
        const target = selected;
        setModuleNotice(null);
        setSaving(true);
        router.post(`/admin/module-management/${target.id}`, { modules: submitted }, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => setModuleNotice({
                type: "error",
                message: `Access was not saved. ${Object.values(errors).flat().join(" ")}`,
            }),
            onSuccess: (page) => {
                const saved = page.props.users?.find((u) => u.id === target.id);
                const actual = new Set(normalizedModuleGrants(saved?.modules || []));
                const serverError = page.props.flash?.error;
                const verified = ! serverError && Array.isArray(saved?.modules)
                    && actual.size === submitted.length && submitted.every((key) => actual.has(key));
                if (! verified) {
                    setModuleNotice({ type: "error", message: serverError || "Could not verify the requested access was saved. Reload this page and check the user's saved options." });
                    if (saved) setGranted(new Set(saved.modules));
                    return;
                }
                setGranted(new Set(saved.modules));
                setModuleNotice({ type: "success", message: `Module access saved for ${saved.name}.` });
            },
            onFinish: () => setSaving(false),
        });
    };

    const portalsDirty = useMemo(() => {
        if (! selected) return false;
        const saved = new Set(selected.portals || []);
        if (saved.size !== grantedPortals.size) return true;
        for (const k of grantedPortals) if (! saved.has(k)) return true;
        return false;
    }, [selected, grantedPortals]);

    const savePortals = () => {
        if (! selected || selected.is_admin) return;
        setSavingPortals(true);
        router.post(`/admin/module-management/${selected.id}/portals`, { portals: [...grantedPortals] }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSavingPortals(false),
        });
    };

    const roleBadge = (role) => (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-gray-100 text-gray-600">
            {(role || "").replace(/_/g, " ")}
        </span>
    );

    return (
        <div className="space-y-6 max-w-6xl mx-auto">
            <Head title="Module Management" />

            <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400 mb-1">Super Admin</p>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <Boxes size={22} /> Module Management
                </h1>
                <p className="text-sm text-gray-500 mt-1 max-w-3xl">
                    Grant a user access to restricted modules. Everything staff can see today is unchanged — only the
                    modules below ship hidden by default and must be switched on per user. Super admins always see every module.
                </p>
            </div>

            {modules.length === 0 && portals.length === 0 ? (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-10 text-center text-gray-400">
                    <Boxes size={26} className="mx-auto mb-2 text-gray-300" />
                    <p className="text-sm font-medium">No restricted modules yet</p>
                    <p className="text-xs mt-1">Modules that ship hidden-by-default will appear here to assign.</p>
                </div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* User picker */}
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
                        <div className="p-4 border-b border-gray-100">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search name, email or role…"
                                    className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-gray-900"
                                />
                            </div>
                        </div>
                        <div className="max-h-[60vh] overflow-y-auto divide-y divide-gray-50">
                            {filtered.length === 0 ? (
                                <p className="text-xs text-gray-400 italic px-4 py-6 text-center">No matching users.</p>
                            ) : filtered.map((u) => (
                                <button
                                    key={u.id}
                                    type="button"
                                    onClick={() => selectUser(u)}
                                    disabled={saving || savingPortals}
                                    className={`w-full text-left px-4 py-3 flex items-center gap-3 transition-colors ${
                                        selectedId === u.id ? "bg-gray-900 text-white" : "hover:bg-gray-50"
                                    }`}
                                >
                                    <div className={`w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold shrink-0 ${
                                        selectedId === u.id ? "bg-white/15 text-white" : "bg-gray-100 text-gray-500"
                                    }`}>
                                        {(u.name || "?").slice(0, 2).toUpperCase()}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-semibold truncate">{u.name}</div>
                                        <div className={`text-[11px] truncate ${selectedId === u.id ? "text-gray-300" : "text-gray-500"}`}>{u.email}</div>
                                    </div>
                                    {u.modules?.length > 0 && (
                                        <span className={`text-[10px] font-bold tabular-nums px-1.5 py-0.5 rounded-full ${
                                            selectedId === u.id ? "bg-white/20 text-white" : "bg-emerald-50 text-emerald-700"
                                        }`}>
                                            {u.modules.length}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Module toggles */}
                    <div className="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                        {moduleNotice && (
                            <p role={moduleNotice.type === "success" ? "status" : "alert"}
                                className={`mb-4 text-sm ${moduleNotice.type === "success" ? "text-emerald-700" : "text-red-700"}`}>
                                {moduleNotice.message}
                            </p>
                        )}
                        {! selected ? (
                            <div className="h-full min-h-[300px] flex flex-col items-center justify-center text-center text-gray-400">
                                <UserIcon size={26} className="mb-2 text-gray-300" />
                                <p className="text-sm font-medium text-gray-600">Pick a user</p>
                                <p className="text-xs mt-1">Choose someone on the left to set which modules they can see.</p>
                            </div>
                        ) : (
                            <>
                                <div className="flex items-center gap-3 pb-4 border-b border-gray-100 mb-4">
                                    <div className="w-11 h-11 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center text-sm font-bold">
                                        {(selected.name || "?").slice(0, 2).toUpperCase()}
                                    </div>
                                    <div className="min-w-0">
                                        <div className="text-base font-bold text-gray-900 flex items-center gap-2">
                                            {selected.name} {roleBadge(selected.role)}
                                        </div>
                                        <div className="text-xs text-gray-500">{selected.email}</div>
                                    </div>
                                </div>

                                {selected.is_super_admin ? (
                                    <div className="flex items-start gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                                        <ShieldCheck size={16} className="mt-0.5 shrink-0" />
                                        <span>Super admins already see every module — there&rsquo;s nothing to grant.</span>
                                    </div>
                                ) : (
                                    <>
                                        <div className="space-y-2.5">
                                            {modules.map((m) => {
                                                const on = wholeOn(m);
                                                const hasFeatures = (m.features || []).length > 0;
                                                return (
                                                    <div key={m.key} className={`rounded-xl border transition-colors ${on ? "border-emerald-300 bg-emerald-50/60" : "border-gray-200"}`}>
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleWhole(m)}
                                                            aria-pressed={on}
                                                            disabled={saving}
                                                            className="w-full text-left flex items-center gap-3 px-4 py-3"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <div className="text-sm font-semibold text-gray-900">
                                                                    {m.label}{hasFeatures && <span className="ml-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400">Full access</span>}
                                                                </div>
                                                                {m.description && <div className="text-[11px] text-gray-500 mt-0.5">{m.description}</div>}
                                                            </div>
                                                            <span className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${on ? "bg-emerald-500" : "bg-gray-300"}`}>
                                                                <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${on ? "translate-x-4" : "translate-x-0.5"}`} />
                                                            </span>
                                                        </button>

                                                        {hasFeatures && (
                                                            <div className="border-t border-gray-100 px-3 py-2 space-y-1">
                                                                <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 px-1 pb-0.5">
                                                                    Grant specific parts
                                                                </p>
                                                                {m.features.map((f) => {
                                                                    const fon = featureOn(m, f.key);
                                                                    return (
                                                                        <button
                                                                            key={f.key}
                                                                            type="button"
                                                                            onClick={() => toggleFeature(m, f.key)}
                                                                            aria-pressed={fon}
                                                                            disabled={saving}
                                                                            className="w-full text-left flex items-center gap-3 pl-4 pr-2 py-2 rounded-lg transition-colors hover:bg-gray-50"
                                                                        >
                                                                            <div className="min-w-0 flex-1">
                                                                                <div className="text-[13px] font-medium text-gray-800">{f.label}</div>
                                                                                {f.description && <div className="text-[11px] text-gray-500 mt-0.5">{f.description}</div>}
                                                                            </div>
                                                                            <span className={`relative inline-flex h-4 w-7 shrink-0 items-center rounded-full transition-colors ${fon ? "bg-emerald-500" : "bg-gray-300"}`}>
                                                                                <span className={`inline-block h-3 w-3 transform rounded-full bg-white shadow transition-transform ${fon ? "translate-x-3.5" : "translate-x-0.5"}`} />
                                                                            </span>
                                                                        </button>
                                                                    );
                                                                })}
                                                            </div>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        <div className="flex items-center justify-end gap-3 mt-5">
                                            {dirty && <span className="text-[11px] text-amber-600 font-semibold">Unsaved changes</span>}
                                            <button
                                                type="button"
                                                onClick={save}
                                                disabled={! dirty || saving}
                                                className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black disabled:opacity-50 transition-colors"
                                            >
                                                {saving ? <Check size={15} /> : <Save size={15} />} Save access
                                            </button>
                                        </div>
                                    </>
                                )}

                                {/* Portal access — grant extra department portals beyond the user's role */}
                                {portals.length > 0 && (
                                    <div className="mt-6 pt-5 border-t border-gray-100">
                                        <h3 className="text-sm font-bold text-gray-900">Portal access</h3>
                                        <p className="text-xs text-gray-500 mt-0.5 mb-3">
                                            Give this user access to extra department portals. Their own role portal is always available.
                                        </p>
                                        {selected.is_admin ? (
                                            <div className="flex items-start gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                                                <ShieldCheck size={16} className="mt-0.5 shrink-0" />
                                                <span>{selected.role === "super_admin" ? "Super admins" : "Admins"} already reach every portal.</span>
                                            </div>
                                        ) : (
                                            <>
                                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                                    {portals.map((p) => {
                                                        const isOwn = p.key === selected.role;
                                                        const on = isOwn || grantedPortals.has(p.key);
                                                        return (
                                                            <button
                                                                key={p.key}
                                                                type="button"
                                                                disabled={isOwn}
                                                                onClick={() => togglePortal(p.key)}
                                                                className={`text-left flex items-center gap-2.5 px-3 py-2.5 rounded-xl border transition-colors ${on ? "border-emerald-300 bg-emerald-50/60" : "border-gray-200 hover:bg-gray-50"} ${isOwn ? "opacity-70 cursor-default" : ""}`}
                                                            >
                                                                <span className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${on ? "bg-emerald-500" : "bg-gray-300"}`}>
                                                                    <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${on ? "translate-x-4" : "translate-x-0.5"}`} />
                                                                </span>
                                                                <span className="min-w-0">
                                                                    <span className="block text-[13px] font-semibold text-gray-900 truncate">{p.label}</span>
                                                                    {isOwn && <span className="block text-[10px] font-bold uppercase tracking-wider text-gray-400">Own role</span>}
                                                                </span>
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                                <div className="flex items-center justify-end gap-3 mt-4">
                                                    {portalsDirty && <span className="text-[11px] text-amber-600 font-semibold">Unsaved changes</span>}
                                                    <button
                                                        type="button"
                                                        onClick={savePortals}
                                                        disabled={! portalsDirty || savingPortals}
                                                        className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black disabled:opacity-50 transition-colors"
                                                    >
                                                        {savingPortals ? <Check size={15} /> : <Save size={15} />} Save portals
                                                    </button>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
