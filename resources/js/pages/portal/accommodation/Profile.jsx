import React from "react";
import { Head } from "@inertiajs/react";
import { User as UserIcon, Mail, ShieldCheck } from "lucide-react";
import SignatureCard from "@/components/SignatureCard";

// Accommodation staff "My Profile" — account details + the e-signature that is
// placed as the "Exalt Property Admin" signatory on every agreement they generate.
export default function AccommodationProfile({ user = {}, signature = null }) {
    const roleLabel = (r) => (r ? r.charAt(0).toUpperCase() + r.slice(1).replace(/_/g, " ") : "—");

    return (
        <div className="space-y-6 max-w-3xl mx-auto">
            <Head title="My Profile" />

            <div>
                <h1 className="text-2xl font-bold text-gray-900">My Profile</h1>
                <p className="mt-1 text-sm text-gray-500">Your account details and the e-signature used on agreements you generate.</p>
            </div>

            <section className="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 className="mb-4 text-sm font-bold uppercase tracking-[0.18em] text-gray-900">Account</h2>
                <dl className="space-y-3">
                    <div className="flex items-center gap-3">
                        <UserIcon size={16} className="text-gray-400" />
                        <dt className="w-24 text-xs font-medium uppercase tracking-wide text-gray-400">Name</dt>
                        <dd className="text-sm font-semibold text-gray-900">{user.name || "—"}</dd>
                    </div>
                    <div className="flex items-center gap-3">
                        <Mail size={16} className="text-gray-400" />
                        <dt className="w-24 text-xs font-medium uppercase tracking-wide text-gray-400">Email</dt>
                        <dd className="text-sm text-gray-700">{user.email || "—"}</dd>
                    </div>
                    <div className="flex items-center gap-3">
                        <ShieldCheck size={16} className="text-gray-400" />
                        <dt className="w-24 text-xs font-medium uppercase tracking-wide text-gray-400">Role</dt>
                        <dd className="text-sm text-gray-700">{roleLabel(user.role)}</dd>
                    </div>
                </dl>
            </section>

            {/* E-signature — saved to the account and auto-placed as the Exalt
                Property Admin signatory on agreements this user generates. */}
            <SignatureCard
                signature={signature}
                name={user.name || ""}
                saveUrl="/profile/signature"
                deleteUrl="/profile/signature"
                accent="bg-[#1F5A8B]"
            />
        </div>
    );
}
