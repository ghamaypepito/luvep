import { Head } from "@inertiajs/react";
import { ArrowUpRight, Monitor, Smartphone } from "lucide-react";
import { useEffect, useRef, useState } from "react";

function EmailPreview({ email }) {
    const frameRef = useRef(null);

    useEffect(() => {
        const frame = frameRef.current;
        const observer = new ResizeObserver(([entry]) => {
            frame.style.height = Math.ceil(entry.target.getBoundingClientRect().height) + "px";
        });
        function observeEmail() {
            observer.disconnect();
            const body = frame.contentDocument?.body;
            if (body) observer.observe(body);
        }
        frame.addEventListener("load", observeEmail);
        observeEmail();

        return () => {
            frame.removeEventListener("load", observeEmail);
            observer.disconnect();
        };
    }, []);

    return (
        <iframe
            ref={frameRef}
            title={email.subject + " email preview"}
            srcDoc={email.html}
            sandbox="allow-same-origin"
            className="block min-h-64 w-full border-0 bg-[#edf2ed]"
        />
    );
}

export default function EventEmailSeries({ emails, workflowUrl }) {
    const [selected, setSelected] = useState(emails[0].id);
    const [mobile, setMobile] = useState(false);
    const email = emails.find(({ id }) => id === selected);

    return (
        <div className="space-y-5 pb-8 text-gray-900">
            <Head title="Event follow-up · Education" />

            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="mb-1 text-sm font-medium text-gray-500">Education / Emails</p>
                    <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Event follow-up</h1>
                    <p className="mt-2 max-w-2xl text-sm text-gray-600">
                        Review the Luvep email series for event leads. These previews use a sample applicant.
                    </p>
                </div>
                <a href={workflowUrl} target="_blank" rel="noopener noreferrer"
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium hover:bg-gray-50">
                    Open workflow in HighLevel <ArrowUpRight size={16} aria-hidden="true" />
                </a>
            </header>

            <div className="grid items-start gap-4 lg:grid-cols-[minmax(12rem,16rem)_minmax(0,1fr)]">
                <div className="lg:hidden">
                    <label htmlFor="event-email" className="mb-1.5 block text-sm font-medium">Choose an email</label>
                    <select id="event-email" value={selected} onChange={(event) => setSelected(event.target.value)}
                        className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                        {emails.map((item) => <option key={item.id} value={item.id}>{item.label} · {item.subject}</option>)}
                    </select>
                </div>

                <nav aria-label="Event follow-up emails" className="hidden rounded-xl border border-gray-200 bg-white p-2 lg:block">
                    <h2 className="px-2 pb-2 pt-1 text-sm font-semibold">Email sequence</h2>
                    <ol className="space-y-1">
                        {emails.map((item) => (
                            <li key={item.id}>
                                <button type="button" aria-pressed={selected === item.id} onClick={() => setSelected(item.id)}
                                    className="w-full rounded-lg px-2.5 py-2 text-left hover:bg-gray-50 aria-pressed:bg-emerald-50">
                                    <span className="block text-xs font-semibold text-emerald-800">{item.label}</span>
                                    <span className="mt-0.5 block text-sm leading-snug">{item.subject}</span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </nav>

                <section aria-label="Selected email" className="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div className="space-y-3 border-b border-gray-200 px-4 py-4 sm:px-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm font-medium text-emerald-800">{email.timing}</p>
                            <div role="group" aria-label="Preview size" className="flex gap-1 rounded-lg bg-gray-100 p-1">
                                <button type="button" aria-pressed={!mobile} onClick={() => setMobile(false)}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold aria-pressed:bg-white aria-pressed:shadow-sm">
                                    <Monitor size={15} aria-hidden="true" /> Desktop
                                </button>
                                <button type="button" aria-pressed={mobile} onClick={() => setMobile(true)}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold aria-pressed:bg-white aria-pressed:shadow-sm">
                                    <Smartphone size={15} aria-hidden="true" /> Mobile
                                </button>
                            </div>
                        </div>
                        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm" aria-live="polite">
                            <dt className="text-gray-500">Subject</dt>
                            <dd className="font-semibold">{email.subject}</dd>
                            <dt className="text-gray-500">Preview text</dt>
                            <dd>{email.preheader}</dd>
                        </dl>
                    </div>

                    <div className="bg-[#edf2ed] p-2 sm:p-3">
                        <div className={"mx-auto overflow-hidden rounded-lg " + (mobile ? "max-w-[390px]" : "max-w-[680px]")}>
                            <EmailPreview key={email.id} email={email} />
                        </div>
                    </div>
                </section>
            </div>
            <p className="text-sm text-gray-600">
                This page shows the reviewed copy. HighLevel manages sending, scheduling, and live workflow changes.
            </p>
        </div>
    );
}
