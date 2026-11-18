import { Head } from "@inertiajs/react";
import { User, Mail, Briefcase, ShieldCheck, Lock, Phone } from "lucide-react";
import PortalPageHeader from "@/components/portal/PortalPageHeader";
import AvatarUploader from "@/components/AvatarUploader";
import ReferralLinkCard from "@/components/affiliate/ReferralLinkCard";

export default function AgentProfile({ user, referral = null }) {
    return (
        <div className="space-y-6 max-w-3xl mx-auto pb-12">
            <Head title="My Profile — Affiliate Partner" />
            <PortalPageHeader
                eyebrow="Account"
                title="My Profile"
                description="Your Affiliate Partner account on EP."
            />

            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="p-6 flex items-center gap-5">
                    <AvatarUploader accent="bg-teal-600" />
                    <div className="min-w-0">
                        <h2 className="text-xl font-medium text-gray-900 tracking-tight">{user?.name || "—"}</h2>
                        <p className="text-sm text-gray-500 mt-0.5">{user?.email || "—"}</p>
                        <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-teal-600 mt-1.5">
                            <Briefcase size={9} className="inline" /> {user?.role === "agent" ? "Affiliate Partner" : user?.role || "—"}
                        </p>
                    </div>
                </div>

                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-px bg-gray-100 border-t border-gray-100">
                    <Row icon={<User size={14} />} label="Name" value={user?.name || "—"} />
                    <Row icon={<Mail size={14} />} label="Email" value={user?.email || "—"} />
                    <Row icon={<Phone size={14} />} label="Phone" value={user?.phone || "—"} />
                    <Row icon={<Briefcase size={14} />} label="Role" value={user?.role === "agent" ? "Affiliate Partner" : user?.role || "—"} />
                </dl>
            </section>

            {/* Referral link — same URL the dashboard card shows; kept
                here too so agents can find it under Account. */}
            <ReferralLinkCard referral={referral} />

            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div className="flex items-center gap-2.5 mb-4">
                    <ShieldCheck size={16} className="text-teal-600" />
                    <h2 className="text-sm font-bold uppercase tracking-[0.18em] text-gray-900">Security</h2>
                </div>
                <ul className="space-y-3">
                    <li className="flex items-center justify-between gap-3 p-3 rounded-xl border border-gray-100">
                        <div className="flex items-center gap-3">
                            <Lock size={14} className="text-gray-400" />
                            <div>
                                <p className="text-sm font-medium text-gray-900">Password</p>
                                <p className="text-[11px] text-gray-500">Update your account password</p>
                            </div>
                        </div>
                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Coming soon</span>
                    </li>
                    <li className="flex items-center justify-between gap-3 p-3 rounded-xl border border-gray-100">
                        <div className="flex items-center gap-3">
                            <ShieldCheck size={14} className="text-gray-400" />
                            <div>
                                <p className="text-sm font-medium text-gray-900">Two-factor authentication</p>
                                <p className="text-[11px] text-gray-500">Add an extra step to sign-in</p>
                            </div>
                        </div>
                        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Coming soon</span>
                    </li>
                </ul>
            </section>
        </div>
    );
}

function Row({ icon, label, value }) {
    return (
        <div className="bg-white px-6 py-4 flex items-start gap-3">
            <div className="w-9 h-9 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center flex-shrink-0">{icon}</div>
            <div className="min-w-0 flex-1">
                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-gray-400 mb-1">{label}</p>
                <p className="text-sm font-medium text-gray-900 truncate">{value}</p>
            </div>
        </div>
    );
}
