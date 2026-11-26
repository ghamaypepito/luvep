import { Head } from "@inertiajs/react";
import { CheckCircle2 } from "lucide-react";

// Confirmation shown after a client e-signs their Exalt agreement.
export default function AgreementSigned({ clientName, typeLabel, downloadUrl }) {
    return (
        <div className="min-h-screen bg-[#eef0f4]">
            <Head title="Agreement signed" />
            <div className="mx-auto flex min-h-screen max-w-lg items-center justify-center px-4">
                <div className="rounded-3xl border border-gray-100 bg-white p-10 text-center shadow-sm">
                    <CheckCircle2 size={48} className="mx-auto text-emerald-500" />
                    <h1 className="mt-4 text-2xl font-bold text-gray-900">Thank you{clientName ? `, ${clientName.split(" ")[0]}` : ""}!</h1>
                    <p className="mt-3 text-sm leading-relaxed text-gray-600">
                        Your <strong>{typeLabel}</strong> has been signed. Our team has been notified and will be in touch
                        with the next steps.
                    </p>
                    {downloadUrl && <a href={downloadUrl} className="mt-4 inline-block font-semibold text-blue-700 underline">Download your signed agreement</a>}
                    <p className="mt-4 text-sm text-gray-500">We&rsquo;re looking forward to having you with us 😊</p>
                </div>
            </div>
        </div>
    );
}
