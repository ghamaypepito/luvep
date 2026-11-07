<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    @include('partials.seo')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">

    @viteReactRefresh
    @vite(app(\Illuminate\Foundation\Vite::class)->isRunningHot() ? ['resources/js/articles.jsx'] : ['resources/js/articles.jsx', 'style.css'])
</head>
<body class="font-urbanist text-stone-900">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60] focus:bg-white focus:px-4 focus:py-2">
        Skip to content
    </a>
    <div id="article-navbar" class="sticky top-0 z-50 min-h-[3.5rem] sm:min-h-[4.5rem]"></div>
    <noscript>
        <nav aria-label="Main" class="mx-auto max-w-7xl px-6 pb-6">
            <a href="/">EP home</a>
        </nav>
    </noscript>
    @yield('content')
    @php
        $contact = \Illuminate\Support\Arr::only(config('services.contact'), [
            'phone', 'whatsapp', 'messenger', 'facebook', 'email',
        ]);
    @endphp
    <div id="article-footer" data-contact="{{ json_encode($contact) }}"></div>
    <noscript>
        <footer class="border-t border-stone-200 px-6 py-8 text-center">
            <a href="{{ route('articles.index', absolute: false) }}">Articles</a>
            <span class="mx-3" aria-hidden="true">·</span>
            <a href="/about-us">About us</a>
        </footer>
    </noscript>
</body>
</html>
