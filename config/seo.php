<?php

/*
|--------------------------------------------------------------------------
| On-page SEO configuration
|--------------------------------------------------------------------------
|
| Central, maintainable source of per-page SEO metadata. Resolved at request
| time by App\Support\Seo and rendered server-side in resources/views/app.blade.php,
| so every public URL advertises its own <title>, meta description, canonical,
| Open Graph and Twitter tags plus structured data — without touching each
| controller or page component.
|
| Canonical / OG URLs are always built from the fixed canonical base
| (config('app.canonical_url'), default https://luvep.com), never the request
| host, so staging / localhost / the old domain can never leak into metadata.
|
| To add SEO for a new public page: add one entry to the 'pages' map keyed by
| its path (leading slash; '/' is the homepage). Dynamic pages (programme
| details) are handled by App\Support\Seo::forProgram().
|
*/

return [

    // Brand name used in <title> suffixes, og:site_name and structured data.
    // Kept here (not hard-coded) so a rename is a one-line change.
    'brand' => 'EP',

    // Fallback title/description for any page not listed in 'pages'.
    'default_title' => 'EP | Education, Immigration, Employment & Settlement Services in New Zealand',
    'default_description' => 'EP is a New Zealand education and immigration consultancy. Explore study pathways, visa assessments, licensed immigration advice and student accommodation support.',

    // Default Open Graph / Twitter share image, relative to the canonical base.
    // Dedicated 1200×630 EP social sharing card. Static/public pages use this;
    // programme detail pages use their own image and fall back to this.
    // (The Organization JSON-LD logo remains the square EP logo at
    // /images/ep-logo.png — see organizationSchema().)
    'og_image' => '/images/og-luvep.png',

    // Square EP logo for schema.org/Organization "logo" (kept distinct from
    // the wide og_image share card). /images/ep-logo.png IS the current EP
    // logo — the "ep-" filename is legacy only and other components depend on
    // this path, so it is not renamed.
    'logo' => '/images/ep-logo.png',

    'twitter_card' => 'summary_large_image',

    // Path prefixes that must never be indexed (defence-in-depth alongside
    // robots.txt). Anything matching gets <meta name="robots" content="noindex,
    // nofollow">. Public marketing pages default to index,follow.
    'noindex_prefixes' => [
        'admin', 'portal', 'lead-portal', 'dtr',
        'login', 'logout', 'forgot-password', 'reset-password',
        'track', 'engagement', 'agreement', 'pre-tenancy', 'apply', 'application',
        'assessment', 'assessment-result',
        'booking/payment', 'booking/reschedule', 'booking/cancel', 'booking/busy',
        'api', 'webhook', 'stripe',
    ],

    /*
    | Organization structured data (schema.org/Organization), emitted once on
    | the homepage. Only factual values already present in the project are used;
    | anything null falls back to config/services.php or is omitted entirely.
    | No address is asserted (none is configured) — see the SEO report for the
    | LocalBusiness upgrade recommendation once an address is available.
    */
    'organization' => [
        'description' => 'New Zealand education and immigration consultancy supporting students and migrants with study pathways, visa assessments, licensed immigration advice and accommodation.',
        'area_served' => 'New Zealand',
        // null => pulled from config('services.contact.phone') at runtime.
        'telephone' => null,
        // Verified primary support address (Google Workspace migration complete).
        'email' => 'support@luvep.com',
        // Official social profiles for sameAs. Empty => falls back to the
        // Facebook page in config/services.php. NOTE: that handle is still
        // "epathwaysnz" pending social migration — review before relying on it.
        'same_as' => [],
    ],

    /*
    | Per-page metadata, keyed by path. 'title' is the full <title> string.
    | 'og_title' / 'og_description' are optional and default to title/description.
    | 'breadcrumb' is the page's label in a Home > Page BreadcrumbList (omit on
    | the homepage). Copy is descriptive of the actual page content — no invented
    | services, statistics, guarantees, rankings or immigration outcomes.
    */
    'pages' => [
        '/' => [
            'title' => 'EP | Education, Immigration, Employment & Settlement Services in New Zealand',
            'description' => 'EP is a New Zealand education and immigration consultancy. Explore study pathways, visa assessments, licensed immigration advice and student accommodation support.',
        ],
        '/about-us' => [
            'title' => 'About Us | EP',
            'description' => 'Learn about EP — a New Zealand education and immigration consultancy helping students and migrants study, work and settle in New Zealand.',
            'breadcrumb' => 'About Us',
        ],
        '/immigration' => [
            'title' => 'New Zealand Immigration Services | EP',
            'description' => 'New Zealand immigration support for resident, work, student, visitor and family visas. Start with an assessment and get advice from a licensed immigration adviser.',
            'breadcrumb' => 'Immigration',
        ],
        '/education-journey' => [
            'title' => 'Study in New Zealand — Education Journey | EP',
            'description' => 'Plan your study journey in New Zealand with EP. Explore programmes, pathways and guidance for international students from enrolment to graduation.',
            'breadcrumb' => 'Education Journey',
        ],
        '/programs-levels' => [
            'title' => 'Programs & Levels | EP',
            'description' => 'Browse New Zealand study programmes by level — certificates, diplomas and degrees — with entry requirements, intakes and outcomes.',
            'breadcrumb' => 'Programs & Levels',
        ],
        '/fee-guide' => [
            'title' => 'Programme Fee Guide | EP',
            'description' => 'Indicative tuition and study-cost estimates for New Zealand programmes offered through EP. Compare fees across levels and institutions.',
            'breadcrumb' => 'Fee Guide',
        ],
        '/accommodation' => [
            'title' => 'Student Accommodation in New Zealand | EP',
            'description' => 'Accommodation support for students and migrants in New Zealand. Explore available rooms and homes and enquire about a place with EP.',
            'breadcrumb' => 'Accommodation',
        ],
        '/visa-approved' => [
            'title' => 'Visa Approved — Client Successes | EP',
            'description' => 'A gallery of visa-approved students and clients supported by EP on their New Zealand education and immigration journey.',
            'breadcrumb' => 'Visa Approved',
        ],
        '/activities' => [
            'title' => 'Activities — Events & Announcements | EP',
            'description' => 'Upcoming events, announcements and live sessions from EP for students and migrants heading to New Zealand.',
            'breadcrumb' => 'Activities',
        ],

        // Visa interest / intake entry pages (public informational forms).
        '/resident-interest' => [
            'title' => 'New Zealand Resident Visa Interest | EP',
            'description' => 'Register your interest in a New Zealand Resident Visa. Complete the interest form and EP will help you plan your next steps.',
            'breadcrumb' => 'Resident Visa Interest',
        ],
        '/work-interest' => [
            'title' => 'Work Visa (AEWV) Interest | EP',
            'description' => 'Register your interest in a New Zealand work visa (AEWV). Share your details and EP will help you plan your application.',
            'breadcrumb' => 'Work Visa Interest',
        ],
        '/student-interest' => [
            'title' => 'Student Visa Interest | EP',
            'description' => 'Register your interest in studying in New Zealand on a student visa. Complete the form and EP will guide your enrolment and visa steps.',
            'breadcrumb' => 'Student Visa Interest',
        ],
        '/visitor-interest' => [
            'title' => 'Visitor Visa Interest | EP',
            'description' => 'Register your interest in a New Zealand visitor visa. Share your travel plans and EP will help with your next steps.',
            'breadcrumb' => 'Visitor Visa Interest',
        ],
        '/family-interest' => [
            'title' => 'Family Visa Interest | EP',
            'description' => 'Register your interest in a New Zealand family or partner visa. Complete the form and EP will guide your options.',
            'breadcrumb' => 'Family Visa Interest',
        ],
    ],
];
