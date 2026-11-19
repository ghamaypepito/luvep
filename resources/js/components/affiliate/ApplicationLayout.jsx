import { Head } from "@inertiajs/react";
import "../../../css/affiliate.css";

export default function ApplicationLayout({ title, description, children }) {
    return <main className="affiliate-portal min-h-screen bg-[#f4f6ef] px-4 py-8 text-[#1a3326] sm:py-14">
        <Head title={title} />
        <div className="mx-auto max-w-2xl">
            <img src="/images/ep-logo.png" alt="EP" className="mb-6 h-12 w-auto" />
            <article className="rounded-[24px] border border-[#dfe6d8] bg-white p-6 shadow-sm sm:p-10">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
                <p className="mt-3 leading-relaxed text-[#53655a]">{description}</p>
                {children}
            </article>
        </div>
    </main>;
}
