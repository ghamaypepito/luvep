<?php

namespace App\Http\Controllers;

use App\Models\EditorialArticle;
use App\Models\Program;
use Illuminate\Http\Response;

/**
 * Public XML sitemap for search engines.
 *
 * URLs are built from a fixed canonical base (config('app.canonical_url'),
 * defaulting to the production domain) rather than the request host or APP_URL,
 * so the sitemap always advertises https://luvep.com and never leaks a staging
 * or localhost host regardless of where it is served from.
 *
 * Only PUBLIC, indexable, canonical pages are listed. Authenticated portals,
 * token-bearer surfaces (tracker, engagement/agreement signing), transactional
 * funnels and internal APIs are deliberately excluded — see the exclusion list
 * in robots.txt.
 */
class SitemapController extends Controller
{
    /**
     * Static public pages that should be indexed. Paths are relative to the
     * canonical base and must correspond to real public routes in web.php.
     */
    private const STATIC_PAGES = [
        ['path' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['path' => '/about-us', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['path' => '/immigration', 'priority' => '0.9', 'changefreq' => 'weekly'],
        ['path' => '/education-journey', 'priority' => '0.9', 'changefreq' => 'weekly'],
        ['path' => '/programs-levels', 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['path' => '/fee-guide', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ['path' => '/accommodation', 'priority' => '0.7', 'changefreq' => 'weekly'],
        ['path' => '/visa-approved', 'priority' => '0.6', 'changefreq' => 'weekly'],
        ['path' => '/articles', 'priority' => '0.7', 'changefreq' => 'weekly'],
        ['path' => '/activities', 'priority' => '0.6', 'changefreq' => 'weekly'],
        // NOTE: /booking and /free-assessment are intentionally excluded — they
        // are transactional/conversion pages, not SEO landing pages. They remain
        // publicly accessible via their routes; they are only kept out of the sitemap.
        ['path' => '/resident-interest', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['path' => '/work-interest', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['path' => '/student-interest', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['path' => '/visitor-interest', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['path' => '/family-interest', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ];

    public function index(): Response
    {
        $base = rtrim(config('app.canonical_url'), '/');

        $urls = [];

        foreach (self::STATIC_PAGES as $page) {
            $urls[] = [
                'loc' => $base.$page['path'],
                'priority' => $page['priority'],
                'changefreq' => $page['changefreq'],
                'lastmod' => null,
            ];
        }

        // Dynamic: published programme detail pages. Route key is the slug and
        // publicShow() 404s anything not published, so only list published rows.
        Program::where('status', 'published')
            ->whereNotNull('slug')
            ->get(['slug', 'updated_at'])
            ->each(function (Program $program) use (&$urls, $base) {
                $urls[] = [
                    'loc' => $base.'/program-details/'.$program->slug,
                    'priority' => '0.7',
                    'changefreq' => 'monthly',
                    'lastmod' => optional($program->updated_at)->toAtomString(),
                ];
            });

        EditorialArticle::published()
            ->get(['published_slug', 'published_at', 'published_updated_at'])
            ->each(function (EditorialArticle $article) use (&$urls, $base) {
                $urls[] = [
                    'loc' => $base.route('articles.show', $article->published_slug, absolute: false),
                    'priority' => '0.7',
                    'changefreq' => 'monthly',
                    'lastmod' => ($article->published_updated_at ?? $article->published_at)->toAtomString(),
                ];
            });

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }
}
