import { useEffect, useRef } from 'react';
import { ExternalLink, X } from 'lucide-react';

export default function GhlPostDialog({ post, onClose, onOpenLocal }) {
    const dialog = useRef(null);
    useEffect(() => { dialog.current.showModal(); }, []);
    return <dialog ref={dialog} className="social-dialog calendar-ghl-dialog" aria-labelledby="ghl-post-title" onCancel={event => { event.preventDefault(); onClose(); }}>
        <div className="social-dialog-shell">
        <header className="social-dialog-header"><div><h2 id="ghl-post-title">GHL social post</h2><p>{post.account_name} · {post.status} · {{ post: 'Feed', reel: 'Reel', story: 'Story' }[post.post_type] || 'Social post'}</p></div><button type="button" className="calendar-icon" aria-label="Close GHL post" onClick={onClose}><X size={18} /></button></header>
        <div className="social-dialog-scroll calendar-ghl-details">
            <p>{post.planned_date ? `${post.planned_date} · ${post.planned_time} · Manila time` : 'GHL did not supply a date for this post.'}</p>
            <p className="calendar-ghl-caption">{post.caption || 'No caption supplied.'}</p>
            {post.media?.map((media, index) => <figure key={index}>
                {media.kind === 'video' ? <video controls playsInline preload="none" src={media.url} poster={media.poster || undefined} aria-label={'Post video ' + (index + 1)} /> : <img src={media.url} alt={'Post image ' + (index + 1)} loading="lazy" referrerPolicy="no-referrer" />}
                <figcaption><a href={media.url} target="_blank" rel="noopener noreferrer">Open {media.kind === 'video' ? 'video' : 'image'} {index + 1} <ExternalLink size={12} aria-hidden="true" /></a></figcaption>
            </figure>)}
            <p>This is a read-only GHL record. Manage its publishing schedule in GHL.</p>
            {post.preview_url && <a className="calendar-text-button" href={post.preview_url} target="_blank" rel="noopener noreferrer">Open published preview <ExternalLink size={14} aria-hidden="true" /></a>}
        </div>
        <footer className="social-dialog-footer"><button type="button" className="calendar-button secondary" onClick={onClose}>Close</button><div className="calendar-header-actions">
            {post.local_id && <button type="button" className="calendar-button secondary" onClick={() => onOpenLocal(post.local_id)}>Open Luvep brief</button>}
            <a className="calendar-button" href="https://app.gohighlevel.com/v2/location/EPwLVKAvmJdF8IvwBLwO/marketing/social-planner/post" target="_blank" rel="noopener noreferrer">Open GHL Social Planner <ExternalLink size={14} aria-hidden="true" /></a>
        </div></footer>
        </div>
    </dialog>;
}
