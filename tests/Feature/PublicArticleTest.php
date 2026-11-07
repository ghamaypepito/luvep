<?php

namespace Tests\Feature;

use App\Models\EditorialArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['app.canonical_url' => 'https://luvep.com']);
    }

    public function test_empty_library_is_public_and_has_canonical_metadata(): void
    {
        $this->get('/articles?source=footer')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSeeText('Articles')
            ->assertSee('<link rel="canonical" href="https://luvep.com/articles">', false);
    }

    public function test_sitemap_lists_the_library(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>https://luvep.com/articles</loc>', false);
    }

    public function test_detail_renders_the_live_revision_and_its_metadata_in_the_initial_response(): void
    {
        $this->publishedArticle('study-in-new-zealand');
        $response = $this->get('https://staging.example/articles/study-in-new-zealand?source=email')
            ->assertOk()
            ->assertSee('<h2>Choosing a programme</h2>', false)
            ->assertSee('<title>Plan your study in NZ</title>', false)
            ->assertSee('<meta name="description" content="Decide where to study.">', false)
            ->assertSee('<link rel="canonical" href="https://luvep.com/articles/study-in-new-zealand">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:image" content="https://example.com/study.jpg">', false)
            ->assertSee('<meta property="og:image:alt" content="A campus courtyard">', false)
            ->assertSee('alt="A campus courtyard"', false)
            ->assertSeeText('Mere Rangi')
            ->assertSeeText('9 November 2026')
            ->assertDontSee('Unreleased revision', false);

        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $response->getContent(), $matches);
        $schema = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Article', $schema['@type']);
        $this->assertSame('Study in New Zealand </script>', $schema['headline']);
        $this->assertSame('Mere Rangi', $schema['author']['name']);
        $this->assertSame('2026-11-09T10:00:00+00:00', $schema['datePublished']);
        $this->assertSame('2026-11-10T10:00:00+00:00', $schema['dateModified']);
    }

    public function test_drafts_stay_private_across_detail_library_and_sitemap(): void
    {
        EditorialArticle::create(['title' => 'Unreleased article', 'slug' => 'unreleased-article']);
        $this->publishedArticle('study-in-new-zealand');

        $this->get('/articles/unreleased-article')->assertNotFound();
        $this->get('/articles')->assertOk()
            ->assertSee('href="/articles/study-in-new-zealand"', false)
            ->assertDontSee('Unreleased article');
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('<loc>https://luvep.com/articles/study-in-new-zealand</loc>', false)
            ->assertSee('<lastmod>2026-11-10T10:00:00+00:00</lastmod>', false)
            ->assertDontSee('/articles/unreleased-article');
    }

    public function test_library_paginates_with_indexable_pages_and_rejects_out_of_range_requests(): void
    {
        foreach (range(1, 13) as $number) {
            $this->publishedArticle('study-guide-'.$number);
        }

        $this->get('/articles')->assertOk()
            ->assertSee('href="/articles/study-guide-13"', false)
            ->assertDontSee('href="/articles/study-guide-1"', false);
        $this->get('/articles?page=2&source=footer')->assertOk()
            ->assertSee('href="/articles/study-guide-1"', false)
            ->assertDontSee('href="/articles/study-guide-13"', false)
            ->assertSee('<link rel="canonical" href="https://luvep.com/articles?page=2">', false);
        $this->get('/articles?page=3')->assertNotFound();
    }

    private function publishedArticle(string $slug): void
    {
        EditorialArticle::create([
            'title' => 'Unreleased revision',
            'slug' => $slug,
            'published_slug' => $slug,
            'published_at' => '2026-11-09 10:00:00',
            'published_updated_at' => '2026-11-10 10:00:00',
            'published' => [
                'title' => 'Study in New Zealand </script>',
                'summary' => 'Start planning your study in New Zealand.',
                'body_html' => '<h2>Choosing a programme</h2><p>Compare your options.</p>',
                'primary_keyword' => 'study in New Zealand',
                'meta_title' => 'Plan your study in NZ',
                'meta_description' => 'Decide where to study.',
                'author' => 'Mere Rangi',
                'image_url' => 'https://example.com/study.jpg',
                'image_alt' => 'A campus courtyard',
            ],
        ]);
    }
}
