import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { QRCodeCanvas } from 'qrcode.react';

export default function Reception({ receptionUrl }) {
    const qr = useRef(null);
    const { post, processing } = useForm({});
    function download() {
        const canvas = qr.current?.querySelector('canvas');
        if (!canvas) return;
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'reception-qr.png';
        link.click();
    }

    return <div className="mx-auto max-w-2xl p-6">
        <Head title="Reception QR" />
        <h1 className="text-2xl font-bold text-gray-900">Reception QR</h1>
        <p className="mt-2 text-sm leading-6 text-gray-600">Visitors scan this code and press Ring reception. Active Immigration managers receive an in-app notification.</p>
        <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
            {receptionUrl ? <>
                <div ref={qr} className="flex justify-center"><QRCodeCanvas value={receptionUrl} size={280} level="M" marginSize={4} title="Scan to ring reception" /></div>
                <h2 className="mt-4 text-center text-xl font-semibold">Reception</h2>
                <p className="mt-2 text-center text-sm text-gray-600">Scan to let our team know you are here.</p>
                <a href={receptionUrl} target="_blank" rel="noopener noreferrer" className="mt-4 block break-all text-center text-sm text-teal-800 underline">Open visitor page</a>
                <div className="mt-5 text-center"><button type="button" onClick={download} className="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white">Download QR PNG</button></div>
                <p className="mt-5 text-sm leading-6 text-gray-600">Confirm the physical reception entrance before placing the printed code. Opening the page does not ring. Repeat rings are limited to once a minute.</p>
            </> : <>
                <p className="text-sm text-gray-600">Create the reception link to prepare its QR code.</p>
                <button type="button" disabled={processing} onClick={() => post('/portal/immigration/reception')} className="mt-4 rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{processing ? 'Creating…' : 'Create reception QR'}</button>
            </>}
        </section>
        <p className="mt-5 text-sm text-gray-600">Alerts use the existing <Link href="/portal/immigration/notifications" className="font-semibold text-teal-800 underline">notification inbox</Link>. Keep Luvep open to see the notification bell.</p>
    </div>;
}
