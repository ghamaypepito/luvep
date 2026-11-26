import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import ArticleDialog, { ArticleStatus } from '@/components/editorial/ArticleDialog';
import SocialPostDialog, { socialStatus } from '@/components/editorial/SocialPostDialog';
import GhlPostDialog from '@/components/editorial/GhlPostDialog';
import '@/components/editorial/content-calendar.css';

const path = '/admin/content-calendar';
const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const dayKey = date => date.toISOString().slice(0, 10);
const manilaDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' });
const monthLabel = new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', year: 'numeric', month: 'long' });
const dayLabel = new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', weekday: 'long', month: 'long', day: 'numeric' });

function TopicButton({ topic, onClick, onDragStart, onDragEnd }) {
    const movable = topic.status === 'draft' && !topic.published_slug;
    return <button type="button" className="calendar-article-event" aria-label={topic.title + ', Article, ' + topic.status.replaceAll('_', ' ')}
        onClick={onClick} draggable={movable} onDragStart={movable ? event => onDragStart(event, 'article', topic) : undefined} onDragEnd={movable ? onDragEnd : undefined}>
        <span className="calendar-event-compact" aria-hidden="true">A</span><span className="calendar-event-title">{topic.title}</span>
        {topic.pillar && <small className="calendar-event-meta">{topic.pillar}</small>}
        <span className="calendar-event-status"><ArticleStatus status={topic.status} />{topic.published_slug && topic.status !== 'published' && <small>Live version</small>}</span>
    </button>;
}
function SocialButton({ post, onClick, onDragStart, onDragEnd }) {
    const movable = post.source !== 'ghl' && post.can_move === true;
    return <button type="button" className="calendar-social-event" aria-label={post.title + ', Social post, ' + (socialStatus[post.status] || post.status) + (post.source === 'ghl' ? ', GHL' : '')}
        onClick={onClick} draggable={movable} onDragStart={movable ? event => onDragStart(event, 'social', post) : undefined} onDragEnd={movable ? onDragEnd : undefined}>
        <span className="calendar-event-compact" aria-hidden="true">S</span><span className="calendar-event-title">{post.title}</span><small className="calendar-event-meta">{post.planned_time} · {socialStatus[post.status] || post.status}{post.source === 'ghl' && ' · GHL'}</small>
    </button>;
}
export default function ContentCalendar({ month, topics = [], undated = [], canPublish, canApproveSocialPosts = false, canPublishSocialPosts = false, socialPosts = [], undatedSocialPosts = [] }) {
    const [selection, setSelection] = useState(() => {
        const id = new URLSearchParams(window.location.search).get('social_post');
        return id && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id) ? { kind: 'social', id } : null;
    }), [choosingDate, setChoosingDate] = useState(null);
    const [dragging, setDragging] = useState(null), [dropDate, setDropDate] = useState(null), [moveMessage, setMoveMessage] = useState('');
    const moving = useRef(false), opener = useRef(null), newArticle = useRef(null);
    const ghlRequest = useRef(null);
    const [ghl, setGhl] = useState({ month, posts: [], loading: true, page: 0, has_more: false, error: '' });
    const [year, monthNumber] = month.split('-').map(Number);
    const firstDay = new Date(Date.UTC(year, monthNumber - 1, 1)), startOffset = (firstDay.getUTCDay() + 6) % 7;
    const daysInMonth = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate(), today = manilaDate.format(new Date());
    const cellCount = Math.ceil((startOffset + daysInMonth) / 7) * 7;
    function loadGhl(page = 1) {
        ghlRequest.current?.abort();
        const controller = new AbortController(); ghlRequest.current = controller;
        setGhl(previous => ({ ...(previous.month === month ? previous : { month, posts: [], page: 0, has_more: false }), loading: true, error: '' }));
        axios.get('/social/ghl/posts', { signal: controller.signal, headers: { Accept: 'application/json' }, params: {
            from: dayKey(firstDay), to: dayKey(new Date(Date.UTC(year, monthNumber, 0))), timezone: 'Asia/Manila', type: 'all', page,
        } }).then(({ data }) => {
            if (controller.signal.aborted) return;
            setGhl(previous => ({ ...data, month, loading: false, error: '', posts: [...new Map([...(page === 1 ? [] : previous.posts), ...data.posts].map(post => [post.id, post])).values()] }));
        }).catch(error => {
            if (!controller.signal.aborted) setGhl(previous => ({ ...previous, loading: false, error: error.response?.data?.message || 'GHL posts could not load. Luvep drafts are still available. Try refreshing GHL.' }));
        });
    }
    useEffect(() => { loadGhl(); return () => ghlRequest.current?.abort(); }, [month]);
    const currentGhl = ghl.month === month ? ghl : { posts: [], loading: true };
    const remotePosts = currentGhl.posts.filter(post => ['scheduled', 'published'].includes(post.status)).map(post => {
        const instant = post.date ? new Date(post.date) : null;
        return { ...post, source: 'ghl', title: post.caption?.trim().slice(0, 100) || 'Untitled ' + (post.post_type === 'story' ? 'Story' : 'social post'),
            planned_date: instant && !Number.isNaN(instant.getTime()) ? manilaDate.format(instant) : null,
            planned_time: instant && !Number.isNaN(instant.getTime()) ? new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit' }).format(instant) : '',
            local_id: [...socialPosts, ...undatedSocialPosts].find(local => local.ghl_post_id && [post.id, post.parent_id].includes(local.ghl_post_id))?.id };
    });
    const remoteIds = new Set(remotePosts.flatMap(post => [post.id, post.parent_id]).filter(Boolean));
    const localOnly = post => !post.ghl_post_id || !remoteIds.has(post.ghl_post_id);
    const calendarSocials = [...socialPosts.filter(localOnly), ...remotePosts.filter(post => post.planned_date?.startsWith(month))];
    const undatedSocials = [...undatedSocialPosts.filter(localOnly), ...remotePosts.filter(post => !post.planned_date)];
    const topicsByDate = new Map(), socialsByDate = new Map();
    for (const topic of topics) { if (!topicsByDate.has(topic.planned_date)) topicsByDate.set(topic.planned_date, []); topicsByDate.get(topic.planned_date).push(topic); }
    for (const post of [...calendarSocials].sort((a, b) => (a.planned_time || '').localeCompare(b.planned_time || ''))) { if (!socialsByDate.has(post.planned_date)) socialsByDate.set(post.planned_date, []); socialsByDate.get(post.planned_date).push(post); }
    const upcoming = [...topics.map(item => ({ ...item, kind: 'article' })), ...calendarSocials.map(item => ({ ...item, kind: 'social' }))]
        .filter(item => item.planned_date >= today && item.status !== 'published').sort((a, b) => a.planned_date.localeCompare(b.planned_date)).slice(0, 3);
    function openArticle(event, id, date = '') { opener.current = event.currentTarget; setSelection({ kind: 'article', id, date }); }
    function openSocial(event, id = null, date = '') { opener.current = event.currentTarget; setSelection({ kind: 'social', id, date }); }
    function openPost(event, post) { if (post.source === 'ghl') { opener.current = event.currentTarget; setSelection({ kind: 'ghl', post }); } else openSocial(event, post.id); }
    function chooseDate(event, date) { opener.current = event.currentTarget.querySelector('.calendar-day-create') || event.currentTarget; setChoosingDate(date); }
    function close() {
        setSelection(null); setChoosingDate(null);
        requestAnimationFrame(() => (opener.current?.isConnected ? opener.current : newArticle.current)?.focus());
    }
    function startDrag(event, kind, item) {
        if (moving.current) { event.preventDefault(); return; }
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(item.id));
        setMoveMessage('');
        setDragging({ kind, item });
    }
    function endDrag() { setDragging(null); setDropDate(null); }
    async function moveContent(event, date) {
        if (!dragging || moving.current) return;
        event.preventDefault();
        const { kind, item } = dragging, plannedDate = dayKey(date);
        endDrag();
        if (item.planned_date === plannedDate) return;
        const isSocial = kind === 'social';
        const endpoint = isSocial ? '/social/marketing/posts/' : path + '/articles/';
        moving.current = true;
        try {
            await axios.patch(endpoint + item.id + '/planned-date', {
                planned_date: plannedDate,
                ...(isSocial ? { version: item.version } : { revision: item.revision }),
            }, { headers: { Accept: 'application/json' } });
            setMoveMessage('Moved to ' + dayLabel.format(date) + '.' + (isSocial && item.status === 'approved' ? ' The new date needs approval.' : ''));
        } catch (error) {
            setMoveMessage(error.response?.data?.message || 'The date change could not be confirmed. Check the calendar before trying again.');
        } finally {
            router.reload({ only: isSocial ? ['socialPosts', 'undatedSocialPosts'] : ['topics', 'undated'], onFinish: () => { moving.current = false; } });
        }
    }
    return <div className="calendar-workspace mx-auto max-w-[1500px] pb-12">
        <Head title="Content Calendar" />
        <a href="/social/compose" className="calendar-back"><ArrowLeft size={16} aria-hidden="true" /> Marketing workspace</a>
        <header className="calendar-heading"><div><h1>Content Calendar</h1><p>Plan articles and social posts, together.</p></div><div className="calendar-header-actions"><button type="button" className="calendar-button secondary" ref={newArticle} onClick={event => openArticle(event, null)}><Plus size={17} aria-hidden="true" /> New article</button><button type="button" className="calendar-button" onClick={event => openSocial(event)}><Plus size={17} aria-hidden="true" /> New social post</button></div></header>
        <div className="calendar-summary" aria-label="This month's content">
            <article><span>Content loaded this month</span><strong>{topics.length + calendarSocials.length}</strong></article><article><span>Social posts loaded</span><strong>{calendarSocials.length}</strong></article><article><span>For review in Luvep</span><strong>{[...topics, ...socialPosts].filter(item => item.status === 'in_review').length}</strong></article>
        </div>
        <section className="calendar-ghl-sync" aria-label="GHL calendar sync" aria-busy={currentGhl.loading}>
            <div><strong>Luv, Ep Facebook · GHL</strong><p role="status">{currentGhl.loading ? 'Loading GHL posts…' : currentGhl.error || `${remotePosts.length} scheduled and published posts loaded · Manila time`}</p>
                {currentGhl.synced_at && <small>Last refreshed {new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(currentGhl.synced_at))} · Manila</small>}
                {currentGhl.has_more && <p>More GHL posts are available. Counts cover the posts loaded so far.</p>}
                {!!currentGhl.error && !!remotePosts.length && <p>Showing the last loaded GHL posts; statuses may have changed.</p>}</div>
            <div className="calendar-header-actions"><button type="button" className="calendar-button secondary" disabled={currentGhl.loading} onClick={() => loadGhl()}>Refresh GHL</button>{currentGhl.has_more && <button type="button" className="calendar-button secondary" disabled={currentGhl.loading} onClick={() => loadGhl(currentGhl.page + 1)}>Load more GHL posts</button>}</div>
        </section>
        <div className="calendar-layout"><section aria-labelledby="calendar-month" className="editorial-calendar">
            <div className="calendar-month-toolbar"><div><h2 id="calendar-month">{monthLabel.format(firstDay)}</h2><p>Manila time</p></div><nav aria-label="Calendar month"><Link className="calendar-icon" href={path + '?month=' + dayKey(new Date(Date.UTC(year, monthNumber - 2, 1))).slice(0, 7)} aria-label="Previous month"><ChevronLeft size={16} /></Link><Link className="calendar-button secondary" href={path + '?month=' + today.slice(0, 7)}>Today</Link><Link className="calendar-icon" href={path + '?month=' + dayKey(new Date(Date.UTC(year, monthNumber, 1))).slice(0, 7)} aria-label="Next month"><ChevronRight size={16} /></Link></nav></div>
            <p className="calendar-help">Drag an article draft or a Luvep social post with an editable date to another day. You can also open the item and change its date. GHL posts open in a read-only preview.</p>
            {moveMessage && <p className="calendar-move-message" role="status">{moveMessage}</p>}
            {topics.length === 0 && calendarSocials.length === 0 && <p className="calendar-help">{currentGhl.loading || currentGhl.error ? 'No Luvep content is planned for this month.' : 'No content is planned for this month.'} Choose a day to add an article or social post.</p>}
            <div className="editorial-calendar-weekdays">{weekdays.map(day => <span key={day}>{day}</span>)}</div>
            <div className="editorial-calendar-days">{Array.from({ length: cellCount }, (_, index) => {
                const date = new Date(Date.UTC(year, monthNumber - 1, index + 1 - startOffset)), dateKey = dayKey(date);
                const dayTopics = topicsByDate.get(dateKey) || [], daySocials = socialsByDate.get(dateKey) || [];
                return <div key={dateKey} data-drop-target={dropDate === dateKey ? 'true' : undefined}
                    onClick={event => { if (!dragging && !event.target.closest('button,a,input')) chooseDate(event, dateKey); }}
                    onDragOver={event => { if (!dragging || moving.current) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; setDropDate(dateKey); }}
                    onDragLeave={event => { if (!event.currentTarget.contains(event.relatedTarget)) setDropDate(current => current === dateKey ? null : current); }}
                    onDrop={event => moveContent(event, date)}
                    className={'editorial-calendar-day' + (!dayTopics.length && !daySocials.length ? ' editorial-calendar-day-empty' : '') + (date.getUTCMonth() !== firstDay.getUTCMonth() ? ' calendar-outside' : '')}>
                    <button type="button" className={'calendar-day-create' + (dateKey === today ? ' calendar-today' : '')} aria-label={'Add content on ' + dayLabel.format(date)} onClick={event => chooseDate(event, dateKey)}><time dateTime={dateKey} aria-current={dateKey === today ? 'date' : undefined}>{date.getUTCDate()}</time><Plus size={12} aria-hidden="true" /></button>
                    {!!dayTopics.length && <ul>{dayTopics.map(topic => <li key={topic.id}><TopicButton topic={topic} onClick={event => openArticle(event, topic.id)} onDragStart={startDrag} onDragEnd={endDrag} /></li>)}</ul>}
                    {!!daySocials.length && <ul>{daySocials.map(post => <li key={(post.source || 'local') + post.id}><SocialButton post={post} onClick={event => openPost(event, post)} onDragStart={startDrag} onDragEnd={endDrag} /></li>)}</ul>}
                </div>;
            })}</div><div className="calendar-legend"><span>Article</span><span>Social post</span></div>
        </section><aside className="calendar-upcoming"><h2>Coming up</h2>{upcoming.length ? upcoming.map(item => <button type="button" key={item.kind + (item.source || 'local') + item.id} onClick={event => item.kind === 'social' ? openPost(event, item) : openArticle(event, item.id)}><time>{item.planned_date}</time><strong>{item.title}</strong><small>{item.kind === 'social' ? 'Social · ' + (socialStatus[item.status] || item.status) + (item.source === 'ghl' ? ' · GHL' : '') : 'Article · ' + item.status.replaceAll('_', ' ')}</small></button>) : <p>No upcoming content in this month.</p>}</aside></div>
        <section className="calendar-undated" aria-labelledby="undated-drafts"><h2 id="undated-drafts"><CalendarDays size={18} aria-hidden="true" /> Undated drafts</h2>{undated.length ? <ul>{undated.map(topic => <li key={topic.id}><TopicButton topic={topic} onClick={event => openArticle(event, topic.id)} onDragStart={startDrag} onDragEnd={endDrag} /></li>)}</ul> : <p>Drafts without a planned date appear here.</p>}</section>
        {!!undatedSocials.length && <section className="calendar-undated"><h2>Undated social posts</h2><p>Latest 100 Luvep posts without a planned time, and loaded GHL posts without a date.</p><ul>{undatedSocials.map(post => <li key={(post.source || 'local') + post.id}><SocialButton post={post} onClick={event => openPost(event, post)} onDragStart={startDrag} onDragEnd={endDrag} /></li>)}</ul></section>}
        {choosingDate && <ContentTypeDialog date={choosingDate} onClose={close} onChoose={kind => { setChoosingDate(null); setSelection({ kind, id: null, date: choosingDate }); }} />}
        {selection?.kind === 'social' && <SocialPostDialog key={selection.id || 'new-social'} postId={selection.id} initialDate={selection.date} canApprove={canApproveSocialPosts} canPublish={canPublishSocialPosts} onClose={close} onSaved={() => router.reload({ only: ['socialPosts', 'undatedSocialPosts'] })} />}
        {selection?.kind === 'ghl' && <GhlPostDialog post={selection.post} onClose={close} onOpenLocal={id => setSelection({ kind: 'social', id })} />}
        {selection?.kind === 'article' && <ArticleDialog key={selection.id || 'new-article'} articleId={selection.id} initialDate={selection.date} canPublish={canPublish} onClose={close} onSaved={() => router.reload({ only: ['topics', 'undated'] })} />}
    </div>;
}
function ContentTypeDialog({ date, onClose, onChoose }) {
    const ref = useRef(null);
    useEffect(() => { ref.current.showModal(); }, []);
    return <dialog ref={ref} className="calendar-type-dialog" aria-labelledby="content-type-title" onCancel={event => { event.preventDefault(); onClose(); }}>
        <header><div><h2 id="content-type-title">Create content</h2><p>{dayLabel.format(new Date(date + 'T00:00:00Z'))}</p></div><button type="button" className="calendar-icon" aria-label="Close content chooser" onClick={onClose}>×</button></header>
        <button type="button" className="calendar-type-choice" onClick={() => onChoose('article')}><strong>+ New article</strong><small>Write a Luvep blog article</small></button>
        <button type="button" className="calendar-type-choice social" onClick={() => onChoose('social')}><strong>+ New social post</strong><small>Create for Luv, Ep through GHL</small></button>
    </dialog>;
}
