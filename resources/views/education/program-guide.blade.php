<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#e7efdf">
    <meta name="description" content="Read the ePathways International Student Program Guide for studying in New Zealand.">
    <title>International Student Program Guide | ePathways</title>
    <link rel="stylesheet" href="/program-guide/reader.css?v={{ filemtime(public_path('program-guide/reader.css')) }}">
    <script src="/program-guide/reader.js?v={{ filemtime(public_path('program-guide/reader.js')) }}" defer></script>
</head>
<body>
    <header class="masthead">
        <a href="/" class="brand" aria-label="ePathways home"><img src="/program-guide/ep-logo.png" alt="" width="93" height="53"></a>
        <div class="heading">
            <h1>International Student Program Guide</h1>
            <p>Study in New Zealand with ePathways</p>
        </div>
        <a class="pdf-link" href="/program-guide/program-guide.pdf" target="_blank" rel="noopener">
            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5h6v6m0-6-9 9M19 13v6H5V5h6"/></svg>
            <span>Open PDF<span class="sr-only"> in a new tab</span></span>
        </a>
    </header>

    <main>
        <div class="stage" id="stage" role="region" aria-label="Guide pages">
            <div class="reader-frame">
                <div class="spread" id="spread" data-pages="1">
                    <img src="/program-guide/pages/01.webp" width="1132" height="1600" alt="Cover of the International Student Program Guide" fetchpriority="high" draggable="false">
                </div>
            </div>
        </div>

        <nav class="toolbar" aria-label="Guide controls" id="toolbar" hidden>
            <button id="previous" type="button" aria-label="Previous page" disabled>
                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <div class="position">
                <label class="sr-only" for="page">Go to page</label>
                <select id="page">
                    @for ($page = 1; $page <= 45; $page++)
                        <option value="{{ $page }}">{{ $page }}</option>
                    @endfor
                </select>
                <span aria-hidden="true">/ 45</span>
                <output class="sr-only" id="page-status" aria-live="polite"></output>
            </div>
            <button id="next" type="button" aria-label="Next page">
                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </button>
            <button id="zoom" type="button" aria-label="Zoom page" aria-pressed="false">
                <svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 5 5M8 10.5h5m-2.5-2.5v5"/></svg>
            </button>
        </nav>

        <p class="page-error" id="page-error" role="status" hidden>That page couldn’t load. Try again or open the PDF.</p>
        <noscript><p class="no-script">Open the PDF to read the full guide.</p></noscript>
    </main>
</body>
</html>
