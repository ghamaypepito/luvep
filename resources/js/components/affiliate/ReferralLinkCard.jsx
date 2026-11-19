import { Copy, ExternalLink, Link as LinkIcon } from 'lucide-react';
import { toast } from 'sonner';

export default function ReferralLinkCard({ referral }) {
    if (!referral?.url) return null;

    async function copyLink() {
        try {
            await navigator.clipboard.writeText(referral.url);
            toast.success('Affiliate link copied.');
        } catch {
            toast.error('Select and copy the affiliate link below.');
        }
    }

    return (
        <section id="affiliate-link" className="affiliate-referral-card">
            <div className="flex items-center gap-3">
                <span className="affiliate-icon"><LinkIcon size={19} /></span>
                <div>
                    <h2>Your affiliate link</h2>
                    <p>Share your link so new registrations are attributed to you.</p>
                </div>
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-2">
                <input aria-label="Affiliate referral link" readOnly value={referral.url} className="min-w-0 flex-1 rounded-lg border border-[#dfe6da] bg-[#f5f8f1] px-3 py-2.5 text-sm" onFocus={event => event.target.select()} />
                <button type="button" className="affiliate-button" onClick={copyLink}><Copy size={15} />Copy link</button>
                <a className="affiliate-button affiliate-button-primary" href={referral.url} target="_blank" rel="noopener noreferrer"><ExternalLink size={15} />Open registration</a>
            </div>
        </section>
    );
}
