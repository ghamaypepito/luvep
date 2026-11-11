@extends('articles.layout')

@section('content')
    <main id="main-content" tabindex="-1" class="scroll-mt-20">
        <header class="bg-brand-green text-white">
            <div class="mx-auto max-w-5xl px-6 py-14 sm:px-8 sm:py-20">
                <h1 class="text-5xl font-extrabold tracking-tight sm:text-[4rem]">Articles</h1>
                <p class="mt-4 max-w-[36rem] text-lg leading-relaxed text-green-50 sm:text-xl">
                    Guides and updates on studying, working and settling in New Zealand.
                </p>
            </div>
        </header>

        <section class="mx-auto max-w-5xl px-6 py-12 sm:px-8 sm:py-16" aria-labelledby="archive-title">
            <h2 id="archive-title" class="border-b border-stone-200 pb-4 text-2xl font-bold tracking-tight">
                Latest articles
            </h2>

            @if ($articles->isEmpty())
                <p class="py-12 text-lg text-stone-600">No articles have been published yet.</p>
            @else
                <ul class="divide-y divide-stone-200">
                    @foreach ($articles as $article)
                        @php($content = $article->published)
                        <li class="py-7 sm:py-8">
                            <article class="grid items-start gap-5 sm:grid-cols-[minmax(0,1fr)_13rem] sm:gap-10">
                                <div class="min-w-0">
                                    <time datetime="{{ $article->published_at->toAtomString() }}" class="text-sm font-semibold text-stone-600">
                                        {{ $article->published_at->format('j F Y') }}
                                    </time>
                                    <h3 class="mt-2 break-words text-2xl leading-tight font-bold tracking-tight sm:text-3xl">
                                        <a href="{{ route('articles.show', $article->published_slug, absolute: false) }}" class="underline-offset-4 hover:text-brand-green hover:underline focus-visible:underline">
                                            {{ $content['title'] }}
                                        </a>
                                    </h3>
                                    <p class="mt-3 max-w-[64ch] break-words text-base leading-relaxed text-stone-700 sm:text-lg">
                                        {{ $content['summary'] }}
                                    </p>
                                </div>
                                @if (!empty($content['image_url']))
                                    <img src="{{ $content['image_url'] }}" alt="{{ $content['image_alt'] ?? '' }}" loading="lazy"
                                        class="aspect-[16/10] w-full rounded-xl object-cover">
                                @endif
                            </article>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($articles->hasPages())
                <nav aria-label="Article pages" class="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-stone-200 pt-6">
                    @unless ($articles->onFirstPage())
                        <a href="{{ $articles->currentPage() === 2 ? route('articles.index', absolute: false) : $articles->previousPageUrl() }}"
                            rel="prev" class="rounded-md border border-stone-300 px-4 py-2 font-semibold text-brand-green hover:bg-stone-100">Previous page</a>
                    @endunless
                    <p class="text-sm font-medium text-stone-600">Page {{ $articles->currentPage() }} of {{ $articles->lastPage() }}</p>
                    @if ($articles->hasMorePages())
                        <a href="{{ $articles->nextPageUrl() }}"
                            rel="next" class="rounded-md border border-stone-300 px-4 py-2 font-semibold text-brand-green hover:bg-stone-100">Next page</a>
                    @endif
                </nav>
            @endif
        </section>
    </main>
@endsection
