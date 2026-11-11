    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    <meta property="og:type" content="{{ $seo['og']['type'] }}">
    <meta property="og:site_name" content="{{ $seo['og']['site_name'] }}">
    <meta property="og:title" content="{{ $seo['og']['title'] }}">
    <meta property="og:description" content="{{ $seo['og']['description'] }}">
    <meta property="og:url" content="{{ $seo['og']['url'] }}">
    @if (!empty($seo['og']['image']))
        <meta property="og:image" content="{{ $seo['og']['image'] }}">
        @if (!empty($seo['og']['image_alt']))
            <meta property="og:image:alt" content="{{ $seo['og']['image_alt'] }}">
        @endif
    @endif
    @if (!empty($seo['article']))
        <meta property="article:published_time" content="{{ $seo['article']['published_time'] }}">
        <meta property="article:modified_time" content="{{ $seo['article']['modified_time'] }}">
    @endif

    <meta name="twitter:card" content="{{ $seo['twitter']['card'] }}">
    <meta name="twitter:title" content="{{ $seo['twitter']['title'] }}">
    <meta name="twitter:description" content="{{ $seo['twitter']['description'] }}">
    @if (!empty($seo['twitter']['image']))
        <meta name="twitter:image" content="{{ $seo['twitter']['image'] }}">
    @endif

    @foreach ($seo['jsonld'] ?? [] as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endforeach
