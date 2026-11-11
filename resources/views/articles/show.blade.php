@extends('articles.layout')

@section('content')
    <main id="main-content" tabindex="-1" class="mx-auto max-w-5xl scroll-mt-20 px-6 pt-9 pb-16 sm:px-8 sm:pt-14 sm:pb-24">
        <article>
            @php($hasFeaturedImage = !empty($content['image_url']))
            <div class="{{ $hasFeaturedImage ? 'relative mx-auto w-fit max-w-full' : 'flex justify-end' }}">
                @if ($hasFeaturedImage)
                    <img src="{{ $content['image_url'] }}" alt="{{ $content['image_alt'] ?? '' }}"
                        class="block max-h-[34rem] max-w-full rounded-xl object-contain">
                @endif

                <a href="{{ route('articles.index', absolute: false) }}"
                    class="{{ $hasFeaturedImage ? 'absolute right-3 bottom-3 bg-white px-3 shadow-sm sm:right-4 sm:bottom-4' : '' }} inline-flex min-h-11 items-center gap-2 rounded-md text-sm font-semibold text-brand-green underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green">
                    <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m12 19-7-7 7-7m-7 7h14"/>
                    </svg>
                    All articles
                </a>
            </div>
            <header class="mx-auto mt-8 max-w-[44rem] sm:mt-11">
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

            <div class="published-article-body mx-auto mt-9 max-w-[44rem] break-words text-lg leading-relaxed text-stone-800 sm:mt-12">
                {{-- The immutable published revision is sanitized on the server before it can be published. --}}
                {!! $content['body_html'] !!}
            </div>
        </article>
    </main>
@endsection
