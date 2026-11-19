import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Bell, CheckCircle2 } from 'lucide-react';
import axios from 'axios';

export default function Doorbell({ ringUrl }) {
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [error, setError] = useState('');

    async function ring() {
        if (sending || sent) return;
        setSending(true);
        setError('');
        try {
            await axios.post(ringUrl, {});
            setSent(true);
        } catch (e) {
            const status = e.response?.status;
            setError(status === 429
                ? 'Reception was recently notified. Please wait a minute before ringing again.'
                : status === 503
                    ? 'Reception alerts are unavailable. Please contact the office directly.'
                    : 'Your ring could not be sent. Please try again or contact the office directly.');
        } finally {
            setSending(false);
        }
    }

    return <main className="min-h-screen bg-[#f5f6f4] px-5 py-16 flex items-center justify-center">
        <Head title="Reception" />
        <section className="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <p className="text-sm font-bold tracking-widest text-[#254b3e]">EP.</p>
            <h1 className="mt-6 text-3xl font-bold text-gray-900">Reception</h1>
            <p className="mt-3 text-sm leading-6 text-gray-600">Welcome. Ring to let our team know you are at the entrance.</p>
            <button type="button" onClick={ring} disabled={sending || sent}
                className="mt-8 flex min-h-14 w-full items-center justify-center gap-2 rounded-xl bg-[#254b3e] px-5 py-4 font-semibold text-white disabled:opacity-70 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#254b3e]">
                {sent ? <CheckCircle2 size={22} aria-hidden="true" /> : <Bell size={22} aria-hidden="true" />}
                {sending ? 'Notifying reception…' : sent ? 'Reception notified' : 'Ring reception'}
            </button>
            {sent && <p role="status" className="mt-5 text-sm leading-6 text-[#254b3e]">Our team has been notified. Please wait at the entrance.</p>}
            {error && <p role="alert" className="mt-5 text-sm leading-6 text-red-700">{error}</p>}
        </section>
    </main>;
}
