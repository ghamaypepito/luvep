<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        Central, server-rendered per-page SEO. Metadata comes from config/seo.php
        via App\Support\Seo. A controller may pass a dynamic `seo` Inertia prop
        (e.g. programme detail pages) which takes precedence over the config
        lookup. Canonical / OG URLs are built from the fixed canonical base
        (config('app.canonical_url')), never the request host, with query
        strings dropped — so staging / localhost / the old domain never leak.

        The app has no Inertia SSR, so @inertiaHead emits nothing here on the
        served HTML: these server-rendered tags are the single source crawlers
        see, with no duplicate-title conflict from client-side <Head> components.
    --}}
    @php
        $seo = data_get($page ?? [], 'props.seo') ?: app(\App\Support\Seo::class)->forPath(request()->path());
    @endphp
    @include('partials.seo')

    @include('partials.startup-recovery-head')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">

    @viteReactRefresh
    {{-- CSS is consolidated in production; Vite injects it through JS in development. --}}
    @vite(app(\Illuminate\Foundation\Vite::class)->isRunningHot() ? ['resources/js/app.jsx'] : ['resources/js/app.jsx', 'style.css'])
    @inertiaHead
</head>

<body>
    @include('partials.startup-recovery-body')
    @inertia
</body>

</html>