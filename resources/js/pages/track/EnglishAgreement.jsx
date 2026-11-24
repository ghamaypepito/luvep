import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { ClientSignModal } from './TrackingPage';

export default function EnglishAgreement({ document: agreement }) {
    const [signing, setSigning] = useState(false);
    return <main className="max-w-5xl mx-auto p-4 sm:p-8 space-y-4">
        <Head title="English Agreement" />
        <h1 className="text-2xl font-bold">English Agreement</h1>
        <p className="text-sm text-gray-600">Review your issued agreement below. No portal password is needed for this document link.</p>
        <p className="text-xs text-gray-500">Keep this link private. It is valid for seven days; staff can resend access if it expires.</p>
        <div className="flex gap-3 items-center flex-wrap">
            <a className="underline" href={agreement.download_url} target="_blank" rel="noreferrer">Open / save PDF</a>
            {agreement.signed ? <p role="status" className="font-semibold text-emerald-700">Signed — thank you. Your signed copy is available below.</p>
                : <button className="rounded-lg bg-gray-900 text-white px-4 py-2" onClick={() => setSigning(true)}>Review and sign</button>}
        </div>
        <iframe title="Issued English agreement" src={agreement.view_url} className="w-full h-[70vh] border rounded-lg bg-white" />
        {signing && <ClientSignModal doc={agreement} onClose={() => setSigning(false)} />}
    </main>;
}
