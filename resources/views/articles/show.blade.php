@extends('articles.layout')

@section('content')
    <main id="main-content" tabindex="-1" class="mx-auto max-w-5xl scroll-mt-20 px-6 pt-9 pb-16 sm:px-8 sm:pt-14 sm:pb-24">
        <nav aria-label="Breadcrumb" class="mx-auto max-w-[44rem]">
            <a href="{{ route('articles.index', absolute: false) }}" class="inline-flex items-center gap-2 font-semibold text-brand-green underline-offset-4 hover:underline focus-visible:underline">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m12 19-7-7 7-7m-7 7h14"/>
                </svg>
                All articles
            </a>
        </nav>
        <article class="mt-7 sm:mt-9">
            <header class="mx-auto max-w-[44rem]">
                <h1 class="text-balance break-words text-4xl leading-[1.05] font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                    {{ $content['title'] }}
                </h1>
                <p class="mt-5 break-words text-lg leading-relaxed text-stone-700 sm:text-xl">{{ $content['summary'] }}</p>
                <p class="mt-6 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-semibold text-stone-600">
                    <span>{{ $content['author'] ?: config('seo.brand') }}</span>
                    <span aria-hidden="true">·</span>
                    <time datetime="{{ $article->published_at->toAtomString() }}">{{ $article->published_at->format('j F Y') }}</time>
                </p>
            </header>

            @if (!empty($content['image_url']))
                <img src="{{ $content['image_url'] }}" alt="{{ $content['image_alt'] ?? '' }}"
                    class="mx-auto mt-8 max-h-[34rem] max-w-full rounded-xl object-contain sm:mt-10">
            @endif

            <div class="published-article-body mx-auto mt-9 max-w-[44rem] break-words text-lg leading-relaxed text-stone-800 sm:mt-12">
                {{-- The immutable published revision is sanitized on the server before it can be published. --}}
                {!! $content['body_html'] !!}
            </div>
        </article>
    </main>
@endsection
