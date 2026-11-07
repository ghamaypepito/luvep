import { useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import ArticleDialog, { ArticleStatus } from '@/components/editorial/ArticleDialog';
import '@/components/editorial/content-calendar.css';

const calendarPath = '/admin/content-calendar';
const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const manilaDate = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
});
const monthLabel = new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', year: 'numeric', month: 'long' });
const dayLabel = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC', weekday: 'long', month: 'long', day: 'numeric',
});
const dayKey = (date) => date.toISOString().slice(0, 10);
const monthUrl = (month) => `${calendarPath}?month=${month}`;
const navClass = 'inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-green-700';

function TopicButton({ topic, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="w-full min-w-0 rounded-md bg-white px-2.5 py-2 text-left ring-1 ring-inset ring-gray-200 hover:bg-green-50 hover:ring-green-300 focus-visible:outline-2 focus-visible:outline-green-700"
        >
            <span className="block wrap-anywhere text-sm font-semibold leading-snug text-gray-900">{topic.title}</span>
            {topic.pillar && <span className="mt-1 block wrap-anywhere text-xs leading-snug text-gray-600">{topic.pillar}</span>}
            <span className="mt-2 flex flex-wrap items-center gap-1.5">
                <ArticleStatus status={topic.status} />
                {topic.published_slug && topic.status !== 'published' && (
                    <span className="text-xs font-medium text-green-800">Live version</span>
                )}
            </span>
        </button>
    );
}

export default function ContentCalendar({ month, topics, undated, canPublish }) {
    const [selection, setSelection] = useState(null);
    const opener = useRef(null);
    const newArticle = useRef(null);
    const [year, monthNumber] = month.split('-').map(Number);
    const firstDay = new Date(Date.UTC(year, monthNumber - 1, 1));
    const startOffset = (firstDay.getUTCDay() + 6) % 7;
    const daysInMonth = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();
    const today = manilaDate.format(new Date());
    const topicsByDate = new Map();
    for (const topic of topics) {
        if (!topicsByDate.has(topic.planned_date)) topicsByDate.set(topic.planned_date, []);
        topicsByDate.get(topic.planned_date).push(topic);
    }
    const cellCount = Math.ceil((startOffset + daysInMonth) / 7) * 7;
    const previousMonth = dayKey(new Date(Date.UTC(year, monthNumber - 2, 1))).slice(0, 7);
    const nextMonth = dayKey(new Date(Date.UTC(year, monthNumber, 1))).slice(0, 7);

    function openArticle(event, id) {
        opener.current = event.currentTarget;
        setSelection({ id });
    }

    function closeArticle() {
        setSelection(null);
        requestAnimationFrame(() => {
            const target = opener.current?.isConnected ? opener.current : newArticle.current;
            target?.focus();
        });
    }

    return (
        <div className="mx-auto max-w-[1500px] pb-12">
            <Head title="Content Calendar" />
            <a href="/social/compose" className="inline-flex items-center gap-2 text-sm font-medium text-green-800 hover:underline focus-visible:outline-2 focus-visible:outline-green-700">
                <ArrowLeft size={16} aria-hidden="true" />
                Marketing workspace
            </a>
            <header className="mt-3 flex flex-wrap items-start justify-between gap-x-5 gap-y-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-gray-900">Content Calendar</h1>
                    <p className="mt-1.5 text-sm text-gray-600">Plan, draft and publish Luvep articles.</p>
                </div>
                <button
                    type="button"
                    ref={newArticle}
                    onClick={(event) => openArticle(event, null)}
                    className="inline-flex min-h-10 items-center gap-2 rounded-md bg-green-800 px-4 text-sm font-semibold text-white hover:bg-green-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-800"
                >
                    <Plus size={17} aria-hidden="true" />
                    New article
                </button>
            </header>

            <section aria-labelledby="calendar-month" className="editorial-calendar mt-7">
                <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-gray-200 pt-5 pb-4">
                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 id="calendar-month" className="text-lg font-semibold text-gray-900">{monthLabel.format(firstDay)}</h2>
                        <p className="text-xs text-gray-600">Manila time</p>
                    </div>
                    <nav aria-label="Calendar month" className="flex items-center gap-2">
                        <Link className={navClass} href={monthUrl(previousMonth)} aria-label="Previous month"><ChevronLeft size={16} aria-hidden="true" /></Link>
                        <Link className={navClass} href={monthUrl(today.slice(0, 7))}>Today</Link>
                        <Link className={navClass} href={monthUrl(nextMonth)} aria-label="Next month"><ChevronRight size={16} aria-hidden="true" /></Link>
                    </nav>
                </div>
                {topics.length === 0 && (
                    <p className="mb-3 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                        No articles are planned for this month. Set a planned date on a draft to add it to the calendar.
                    </p>
                )}
                <div className="editorial-calendar-weekdays overflow-hidden rounded-t-xl border border-b-0 border-gray-200 bg-gray-50">
                    {weekdays.map((weekday) => (
                        <span key={weekday} className="px-3 py-2.5 text-xs font-semibold text-gray-700">{weekday.slice(0, 3)}</span>
                    ))}
                </div>
                <div className="editorial-calendar-days">
                    {Array.from({ length: cellCount }, (_, index) => {
                        const date = new Date(Date.UTC(year, monthNumber - 1, index + 1 - startOffset));
                        const dateKey = dayKey(date);
                        const inMonth = date.getUTCMonth() === firstDay.getUTCMonth();
                        const dayTopics = topicsByDate.get(dateKey) ?? [];
                        const isToday = dateKey === today;
                        return (
                            <div key={dateKey} className={`editorial-calendar-day ${dayTopics.length ? '' : 'editorial-calendar-day-empty'} min-w-0 p-2.5 ${inMonth ? 'bg-white' : 'bg-gray-50'}`}>
                                <time
                                    dateTime={dateKey}
                                    aria-current={isToday ? 'date' : undefined}
                                    className={`inline-flex min-h-6 items-center rounded px-1.5 text-xs font-semibold ${isToday ? 'bg-green-800 text-white' : inMonth ? 'text-gray-800' : 'text-gray-500'}`}
                                >
                                    <span className="editorial-calendar-date-label">{dayLabel.format(date)}</span>
                                    <span className="editorial-calendar-day-number" aria-hidden="true">{date.getUTCDate()}</span>
                                </time>
                                {dayTopics.length > 0 && (
                                    <ul className="mt-1.5 space-y-2">
                                        {dayTopics.map((topic) => (
                                            <li key={topic.id}><TopicButton topic={topic} onClick={(event) => openArticle(event, topic.id)} /></li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        );
                    })}
                </div>
            </section>

            <section aria-labelledby="undated-drafts" className="mt-7 border-t border-gray-200 pt-5">
                <div className="flex items-center gap-2.5">
                    <CalendarDays size={18} className="text-gray-600" aria-hidden="true" />
                    <h2 id="undated-drafts" className="text-base font-semibold text-gray-900">Undated drafts</h2>
                </div>
                {undated.length ? (
                    <ul className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {undated.map((topic) => (
                            <li key={topic.id}><TopicButton topic={topic} onClick={(event) => openArticle(event, topic.id)} /></li>
                        ))}
                    </ul>
                ) : (
                    <p className="mt-2 text-sm text-gray-600">Drafts without a planned date appear here.</p>
                )}
            </section>

            {selection && (
                <ArticleDialog
                    articleId={selection.id}
                    canPublish={canPublish}
                    onClose={closeArticle}
                    onSaved={() => router.reload({ only: ['topics', 'undated'] })}
                />
            )}
        </div>
    );
}
