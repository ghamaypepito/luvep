import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import {
    School as SchoolIcon, ArrowLeft, Pencil, MapPin, Globe, User, Mail, Phone,
    KeyRound, Link2, FileText, Download, Eye, EyeOff, Lock, Copy, Check,
} from "lucide-react";
import { SchoolFormModal } from "@/pages/admin/Schools";

// School profile — the full record for one institution: contact, portal login,
// agreement (admin download), and notes. Reached by clicking a school name.
export default function SchoolProfile({ school = {}, portalBase = "/admin", hasAgreement = false, canViewAgreement = false, agreementUrl = null }) {
    const [editing, setEditing] = useState(false);
    const location = [school.city, school.country].filter(Boolean).join(", ");

    return (
        <div className="space-y-5 max-w-[900px] mx-auto pb-12">
            <Head title={`${school.name || "School"} — Profile`} />

            <Link href={`${portalBase}/schools`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-gray-900">
                <ArrowLeft size={14} /> Back to schools
            </Link>

            {/* Header card */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="flex items-start gap-4 min-w-0">
                        <span className="w-14 h-14 rounded-2xl bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0 overflow-hidden">
                            {school.logo_url
                                ? <img src={school.logo_url} alt={`${school.name} logo`} className="w-full h-full object-contain" />
                                : <SchoolIcon size={26} />}
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{school.name}</h1>
                            <div className="flex items-center gap-3 mt-1.5 flex-wrap text-sm text-gray-600">
                                {location && <span className="inline-flex items-center gap-1"><MapPin size={13} className="text-gray-400" /> {location}</span>}
                                {school.website && (
                                    <a href={school.website} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-blue-600 font-medium">
                                        <Globe size={13} /> {school.website.replace(/^https?:\/\//, "").replace(/\/$/, "")}
                                    </a>
                                )}
                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ${
                                    school.status === "active" ? "bg-emerald-50 text-emerald-700 border-emerald-200" : "bg-gray-100 text-gray-600 border-gray-200"
                                }`}>{school.status || "active"}</span>
                            </div>
                        </div>
                    </div>
                    <button onClick={() => setEditing(true)} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 transition-colors shadow-sm">
                        <Pencil size={14} /> Edit
                    </button>
                </div>
            </div>

            {/* Contacts */}
            {portalBase === '/portal/education' && (
                <Link href={`${portalBase}/schools/${school.id}/management`} className="block rounded-xl bg-emerald-800 p-4 font-semibold text-white">
                    School dashboard · Students, communications, courses and intakes →
                </Link>
            )}
            <Card icon={User} title={`Contacts${(school.contacts?.length) ? ` · ${school.contacts.length}` : ""}`}>
                {(!school.contacts || school.contacts.length === 0) ? (
                    <p className="text-sm text-gray-400">No contacts yet.</p>
                ) : (
                    <div className="divide-y divide-gray-100">
                        {school.contacts.map((c, i) => (
                            <div key={i} className="py-3 first:pt-0 last:pb-0">
                                <div className="flex items-baseline gap-2 flex-wrap">
                                    <span className="text-sm font-semibold text-gray-900">{c.name || <span className="text-gray-400 font-normal">Unnamed contact</span>}</span>
                                    {c.role && <span className="text-[11px] text-indigo-700 bg-indigo-50 rounded px-1.5 py-0.5 font-medium">{c.role}</span>}
                                </div>
                                <div className="mt-1.5 space-y-1.5">
                                    {c.email && <Row icon={Mail} label="Email" value={c.email} copyable dense />}
                                    {c.phone && <Row icon={Phone} label="Phone" value={c.phone} copyable dense />}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            {/* Portal */}
            <Card icon={KeyRound} title="Portal">
                <Row icon={User} label="Email / Username" value={school.portal_username} copyable />
                <PasswordRow value={school.portal_password} />
                <Row icon={Link2} label="Portal link" value={school.portal_link} isLink copyable />
            </Card>

            {/* Agreement */}
            <Card icon={FileText} title="Agreement">
                {!hasAgreement ? (
                    <p className="text-sm text-gray-400">No agreement on file.</p>
                ) : canViewAgreement ? (
                    <a href={agreementUrl} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 transition-colors">
                        <Download size={14} /> Download agreement
                        {school.agreement_name && <span className="font-normal text-gray-300 truncate max-w-[240px]">· {school.agreement_name}</span>}
                    </a>
                ) : (
                    <p className="text-sm text-gray-500 inline-flex items-center gap-1.5"><Lock size={13} className="text-gray-400" /> On file — admin access only.</p>
                )}
            </Card>

            {/* Description / notes */}
            {school.description && (
                <Card icon={FileText} title="Notes">
                    <p className="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{school.description}</p>
                </Card>
            )}

            {editing && (
                <SchoolFormModal initial={school} onClose={() => setEditing(false)} />
            )}
        </div>
    );
}

function Card({ icon: Icon, title, children }) {
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div className="flex items-center gap-2 mb-4">
                {Icon && <Icon size={15} className="text-gray-400" />}
                <h2 className="text-sm font-bold uppercase tracking-wider text-gray-500">{title}</h2>
            </div>
            <div className="space-y-3">{children}</div>
        </div>
    );
}

function CopyButton({ value }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        try { navigator.clipboard.writeText(String(value)); setCopied(true); setTimeout(() => setCopied(false), 1200); } catch { /* ignore */ }
    };
    return (
        <button onClick={copy} className="p-1 rounded text-gray-300 hover:text-gray-600 hover:bg-gray-100 flex-shrink-0" title="Copy">
            {copied ? <Check size={13} className="text-emerald-600" /> : <Copy size={13} />}
        </button>
    );
}

function Row({ icon: Icon, label, value, isLink, copyable, dense }) {
    const has = value != null && String(value).trim() !== "";

    // Dense: a compact icon + value line (used inside a contact block, where the
    // name/role already provide the heading).
    if (dense) {
        return (
            <div className="flex items-center gap-2">
                {Icon && <Icon size={13} className="text-gray-400 flex-shrink-0" />}
                <span className="text-[13px] text-gray-700 break-all min-w-0">{value}</span>
                {has && copyable && <CopyButton value={value} />}
            </div>
        );
    }

    return (
        <div className="flex items-start gap-3">
            {Icon && <Icon size={15} className="text-gray-400 mt-0.5 flex-shrink-0" />}
            <div className="min-w-0 flex-1">
                <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400">{label}</p>
                {has ? (
                    isLink ? (
                        <a href={value} target="_blank" rel="noopener noreferrer" className="text-sm text-blue-600 font-medium break-all hover:underline">{value}</a>
                    ) : (
                        <p className="text-sm text-gray-800 break-words">{value}</p>
                    )
                ) : <p className="text-sm text-gray-300">—</p>}
            </div>
            {has && copyable && <CopyButton value={value} />}
        </div>
    );
}

function PasswordRow({ value }) {
    const [show, setShow] = useState(false);
    const has = value != null && String(value).trim() !== "";
    return (
        <div className="flex items-start gap-3">
            <KeyRound size={15} className="text-gray-400 mt-0.5 flex-shrink-0" />
            <div className="min-w-0 flex-1">
                <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400">Password</p>
                {has ? (
                    <p className="text-sm text-gray-800 font-mono break-all">{show ? value : "••••••••••"}</p>
                ) : <p className="text-sm text-gray-300">—</p>}
            </div>
            {has && (
                <>
                    <button onClick={() => setShow((s) => !s)} className="p-1 rounded text-gray-300 hover:text-gray-600 hover:bg-gray-100 flex-shrink-0" title={show ? "Hide" : "Show"}>
                        {show ? <EyeOff size={13} /> : <Eye size={13} />}
                    </button>
                    <CopyButton value={value} />
                </>
            )}
        </div>
    );
}
