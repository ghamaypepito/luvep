import { Head } from "@inertiajs/react";
import Pagination from "@/components/ui/Pagination";
export default function TicketDocumentation({ articles = [] }) {
    const rows = Array.isArray(articles) ? articles : articles.data ?? [];
    return <main className="max-w-4xl mx-auto space-y-6">
        <Head title="Ticket Documentation" />
        <header><a href="/admin/system-tickets" className="text-sm text-blue-700">← System tickets</a><h1 className="mt-3 text-2xl font-bold">Ticket documentation</h1><p className="mt-1 text-sm text-gray-500">Reviewed solutions published by the support team.</p></header>
        {!rows.length && <p className="rounded-2xl border bg-white p-8 text-sm text-gray-500">No documentation has been published yet.</p>}
        {rows.map((article, i) => <article key={article.id ?? i} className="rounded-2xl border bg-white p-6"><h2 className="text-lg font-semibold">{article.title ?? article.documentation_title}</h2><p className="mt-1 text-xs text-gray-500">{article.ticket_ref ? `${article.ticket_ref} · ` : ""}{article.published_at || article.documentation_published_at ? new Date(article.published_at ?? article.documentation_published_at).toLocaleDateString() : ""}</p><p className="mt-4 text-sm leading-7 text-gray-700 whitespace-pre-wrap break-words">{article.body ?? article.documentation_body}</p></article>)}
        <Pagination links={articles.links ?? []} />
    </main>;
}
