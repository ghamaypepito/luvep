import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import ArticleDialog, { ArticleStatus } from '@/components/editorial/ArticleDialog';
import SocialPostDialog, { socialStatus } from '@/components/editorial/SocialPostDialog';
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
        onClick={onClick} draggable={movable} onDragStart={movable ? event => onDragStart(event, topic) : undefined} onDragEnd={movable ? onDragEnd : undefined}>
        <span className="calendar-event-compact" aria-hidden="true">A</span><span className="calendar-event-title">{topic.title}</span>
        {topic.pillar && <small className="calendar-event-meta">{topic.pillar}</small>}
        <span className="calendar-event-status"><ArticleStatus status={topic.status} />{topic.published_slug && topic.status !== 'published' && <small>Live version</small>}</span>
    </button>;
}
function SocialButton({ post, onClick }) {
    return <button type="button" className="calendar-social-event" aria-label={post.title + ', Social post, ' + socialStatus[post.status]} onClick={onClick}>
        <span className="calendar-event-compact" aria-hidden="true">S</span><span className="calendar-event-title">{post.title}</span><small className="calendar-event-meta">{post.planned_time} · {socialStatus[post.status]}</small>
    </button>;
}
export default function ContentCalendar({ month, topics = [], undated = [], canPublish, socialPosts = [], undatedSocialPosts = [] }) {
    const [selection, setSelection] = useState(() => {
        const id = new URLSearchParams(window.location.search).get('social_post');
        return id && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id) ? { kind: 'social', id } : null;
    }), [choosingDate, setChoosingDate] = useState(null);
    const [dragging, setDragging] = useState(null), [dropDate, setDropDate] = useState(null), [moveMessage, setMoveMessage] = useState('');
    const moving = useRef(false), opener = useRef(null), newArticle = useRef(null);
    const [year, monthNumber] = month.split('-').map(Number);
    const firstDay = new Date(Date.UTC(year, monthNumber - 1, 1)), startOffset = (firstDay.getUTCDay() + 6) % 7;
    const daysInMonth = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate(), today = manilaDate.format(new Date());
    const cellCount = Math.ceil((startOffset + daysInMonth) / 7) * 7;
    const topicsByDate = new Map(), socialsByDate = new Map();
    for (const topic of topics) { if (!topicsByDate.has(topic.planned_date)) topicsByDate.set(topic.planned_date, []); topicsByDate.get(topic.planned_date).push(topic); }
    for (const post of socialPosts) { if (!socialsByDate.has(post.planned_date)) socialsByDate.set(post.planned_date, []); socialsByDate.get(post.planned_date).push(post); }
    const upcoming = [...topics.map(item => ({ ...item, kind: 'article' })), ...socialPosts.map(item => ({ ...item, kind: 'social' }))]
        .filter(item => item.planned_date >= today && item.status !== 'published').sort((a, b) => a.planned_date.localeCompare(b.planned_date)).slice(0, 3);
    function openArticle(event, id, date = '') { opener.current = event.currentTarget; setSelection({ kind: 'article', id, date }); }
    function openSocial(event, id = null, date = '') { opener.current = event.currentTarget; setSelection({ kind: 'social', id, date }); }
    function chooseDate(event, date) { opener.current = event.currentTarget.querySelector('.calendar-day-create') || event.currentTarget; setChoosingDate(date); }
    function close() {
        setSelection(null); setChoosingDate(null);
        requestAnimationFrame(() => (opener.current?.isConnected ? opener.current : newArticle.current)?.focus());
    }
    function startDrag(event, topic) {
        if (moving.current) { event.preventDefault(); return; }
        event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(topic.id)); setMoveMessage(''); setDragging(topic);
    }
    function endDrag() { setDragging(null); setDropDate(null); }
    async function moveDraft(event, date) {
        if (!dragging || moving.current) return;
        event.preventDefault();
        const topic = dragging, plannedDate = dayKey(date);
        endDrag();
        if (topic.planned_date === plannedDate) return;
        moving.current = true;
        try {
            await axios.patch(path + '/articles/' + topic.id + '/planned-date', { planned_date: plannedDate, revision: topic.revision }, { headers: { Accept: 'application/json' } });
            setMoveMessage('Moved to ' + dayLabel.format(date) + '.');
        } catch (error) { setMoveMessage(error.response?.data?.message || 'The draft could not be moved. Try again.'); }
        finally { router.reload({ only: ['topics', 'undated'], onFinish: () => { moving.current = false; } }); }
    }
    return <div className="calendar-workspace mx-auto max-w-[1500px] pb-12">
        <Head title="Content Calendar" />
        <a href="/social/compose" className="calendar-back"><ArrowLeft size={16} aria-hidden="true" /> Marketing workspace</a>
        <header className="calendar-heading"><div><h1>Content Calendar</h1><p>Plan articles and social posts, together.</p></div><div className="calendar-header-actions"><button type="button" className="calendar-button secondary" ref={newArticle} onClick={event => openArticle(event, null)}><Plus size={17} aria-hidden="true" /> New article</button><button type="button" className="calendar-button" onClick={event => openSocial(event)}><Plus size={17} aria-hidden="true" /> New social post</button></div></header>
        <div className="calendar-summary" aria-label="This month's content">
            <article><span>Planned this month</span><strong>{topics.length + socialPosts.length}</strong></article><article><span>Social posts</span><strong>{socialPosts.length}</strong></article><article><span>For review</span><strong>{[...topics, ...socialPosts].filter(item => item.status === 'in_review').length}</strong></article>
        </div>
        <div className="calendar-layout"><section aria-labelledby="calendar-month" className="editorial-calendar">
            <div className="calendar-month-toolbar"><div><h2 id="calendar-month">{monthLabel.format(firstDay)}</h2><p>Manila time</p></div><nav aria-label="Calendar month"><Link className="calendar-icon" href={path + '?month=' + dayKey(new Date(Date.UTC(year, monthNumber - 2, 1))).slice(0, 7)} aria-label="Previous month"><ChevronLeft size={16} /></Link><Link className="calendar-button secondary" href={path + '?month=' + today.slice(0, 7)}>Today</Link><Link className="calendar-icon" href={path + '?month=' + dayKey(new Date(Date.UTC(year, monthNumber, 1))).slice(0, 7)} aria-label="Next month"><ChevronRight size={16} /></Link></nav></div>
            <p className="calendar-help">Drag an unpublished article draft to another day. Open a social post to edit its timing; edits require fresh approval.</p>
            {moveMessage && <p className="calendar-move-message" role="status">{moveMessage}</p>}
            {topics.length === 0 && socialPosts.length === 0 && <p className="calendar-help">No content is planned for this month. Choose a day to add an article or social post.</p>}
            <div className="editorial-calendar-weekdays">{weekdays.map(day => <span key={day}>{day}</span>)}</div>
            <div className="editorial-calendar-days">{Array.from({ length: cellCount }, (_, index) => {
                const date = new Date(Date.UTC(year, monthNumber - 1, index + 1 - startOffset)), dateKey = dayKey(date);
                const dayTopics = topicsByDate.get(dateKey) || [], daySocials = socialsByDate.get(dateKey) || [];
                return <div key={dateKey} data-drop-target={dropDate === dateKey ? 'true' : undefined}
                    onClick={event => { if (!dragging && !event.target.closest('button,a,input')) chooseDate(event, dateKey); }}
                    onDragOver={event => { if (!dragging || moving.current) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; setDropDate(dateKey); }}
                    onDragLeave={event => { if (!event.currentTarget.contains(event.relatedTarget)) setDropDate(current => current === dateKey ? null : current); }}
                    onDrop={event => moveDraft(event, date)}
                    className={'editorial-calendar-day' + (!dayTopics.length && !daySocials.length ? ' editorial-calendar-day-empty' : '') + (date.getUTCMonth() !== firstDay.getUTCMonth() ? ' calendar-outside' : '')}>
                    <button type="button" className={'calendar-day-create' + (dateKey === today ? ' calendar-today' : '')} aria-label={'Add content on ' + dayLabel.format(date)} onClick={event => chooseDate(event, dateKey)}><time dateTime={dateKey} aria-current={dateKey === today ? 'date' : undefined}>{date.getUTCDate()}</time><Plus size={12} aria-hidden="true" /></button>
                    {!!dayTopics.length && <ul>{dayTopics.map(topic => <li key={topic.id}><TopicButton topic={topic} onClick={event => openArticle(event, topic.id)} onDragStart={startDrag} onDragEnd={endDrag} /></li>)}</ul>}
                    {!!daySocials.length && <ul>{daySocials.map(post => <li key={post.id}><SocialButton post={post} onClick={event => openSocial(event, post.id)} /></li>)}</ul>}
                </div>;
            })}</div><div className="calendar-legend"><span>Article</span><span>Social post</span></div>
        </section><aside className="calendar-upcoming"><h2>Coming up</h2>{upcoming.length ? upcoming.map(item => <button type="button" key={item.kind + item.id} onClick={event => item.kind === 'social' ? openSocial(event, item.id) : openArticle(event, item.id)}><time>{item.planned_date}</time><strong>{item.title}</strong><small>{item.kind === 'social' ? 'Social · ' + socialStatus[item.status] : 'Article · ' + item.status.replaceAll('_', ' ')}</small></button>) : <p>No upcoming content in this month.</p>}</aside></div>
        <section className="calendar-undated" aria-labelledby="undated-drafts"><h2 id="undated-drafts"><CalendarDays size={18} aria-hidden="true" /> Undated drafts</h2>{undated.length ? <ul>{undated.map(topic => <li key={topic.id}><TopicButton topic={topic} onClick={event => openArticle(event, topic.id)} onDragStart={startDrag} onDragEnd={endDrag} /></li>)}</ul> : <p>Drafts without a planned date appear here.</p>}</section>
        {!!undatedSocialPosts.length && <section className="calendar-undated"><h2>Undated social posts</h2><p>Latest 100 saved posts without a planned time.</p><ul>{undatedSocialPosts.map(post => <li key={post.id}><SocialButton post={post} onClick={event => openSocial(event, post.id)} /></li>)}</ul></section>}
        {choosingDate && <ContentTypeDialog date={choosingDate} onClose={close} onChoose={kind => { setChoosingDate(null); setSelection({ kind, id: null, date: choosingDate }); }} />}
        {selection?.kind === 'social' && <SocialPostDialog key={selection.id || 'new-social'} postId={selection.id} initialDate={selection.date} canPublish={canPublish} onClose={close} onSaved={() => router.reload({ only: ['socialPosts', 'undatedSocialPosts'] })} />}
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
