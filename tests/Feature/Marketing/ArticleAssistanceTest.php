<?php

namespace Tests\Feature\Marketing;

use App\Models\EditorialArticle;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArticleAssistanceTest extends TestCase
{
    use RefreshDatabase;

    private const GENERATE = '/admin/content-calendar/generate';

    private const ARTICLES = '/admin/content-calendar/articles';

    private const IMAGES = '/admin/content-calendar/images';

    private function input(): array
    {
        return ['brief' => ['prompt' => 'Explain how prospective students can organise questions for the EP team.'], 'visual_format' => 'featured'];
    }

    private function generatedContent(int $slides = 1): array
    {
        return ['title' => 'Plan your next conversation', 'summary' => 'Get ready to talk to the EP team.', 'primary_keyword' => 'study planning', 'meta_title' => 'Study planning with EP', 'meta_description' => 'Organise your questions and plan your next conversation with EP.', 'pillar' => 'Study',
            'sections' => array_fill(0, 3, ['heading' => 'Your next step', 'paragraphs' => ['Start with your questions. <script>alert(1)</script>']]),
            'visual_direction' => 'A clean green layout with a notebook photo.', 'slides' => array_fill(0, $slides, ['headline' => 'Plan your next step', 'supporting_copy' => 'Bring your questions to the EP team.', 'cta' => 'Talk to EP', 'alt' => 'An EP branded study-planning graphic'])];
    }

    private function connect(): void
    {
        Http::preventStrayRequests();
        config(['ai.enabled' => true, 'services.ghl.article_facebook_enabled' => false]);
        Setting::set('ai_enabled', true, 'bool');
        Setting::set('claude_connector.staff_enabled', true, 'bool');
        Setting::set('claude_connector.api_key', Crypt::encryptString('test-only-key'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_only_active_calendar_staff_can_generate_or_upload(): void
    {
        Http::preventStrayRequests();
        $this->postJson(self::GENERATE, $this->input())->assertUnauthorized();
        $this->postJson(self::IMAGES)->assertUnauthorized();
        foreach ([['role' => 'education'], ['role' => 'lead', 'module_permissions' => ['social_marketing']], ['role' => 'admin', 'is_active' => false]] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->actingAs($user)->postJson(self::GENERATE, $this->input())->assertForbidden();
            $this->actingAs($user)->postJson(self::IMAGES)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_brief_generates_complete_safe_suggestions_without_saving_or_publishing(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->generatedContent())]]])]);
        $this->postJson(self::GENERATE, $this->input())->assertOk()->assertJsonPath('draft', true)
            ->assertJsonPath('article.slug', 'plan-your-next-conversation')->assertJsonPath('article.author', 'EP Editorial Team')
            ->assertJsonPath('article.meta_title', 'Study planning with EP')->assertJsonPath('article.primary_keyword', 'study planning')
            ->assertJsonCount(1, 'slides')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages' && $request['max_tokens'] === 4000 && str_contains($request['system'], 'never invent'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('editorial_articles', 0);
    }

    public function test_digital_members_can_generate_a_carousel_and_bad_input_or_output_keeps_drafts_untouched(): void
    {
        $this->connect();
        $this->actingAs(User::factory()->create(['role' => 'education', 'module_permissions' => ['social_marketing']]));
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->generatedContent(3))]]])]);
        $this->postJson(self::GENERATE, [...$this->input(), 'visual_format' => 'carousel'])->assertOk()->assertJsonCount(3, 'slides');
        $this->postJson(self::GENERATE, ['brief' => ['prompt' => 'too short'], 'visual_format' => 'featured'])->assertUnprocessable();
        Http::assertSentCount(1);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"title":"Incomplete"}']]])]);
        $this->postJson(self::GENERATE, $this->input())->assertStatus(502)->assertJsonPath('message', 'The AI returned an incomplete article. Your draft has not changed. Try again.');
        $this->assertDatabaseCount('editorial_articles', 0);
    }

    public function test_imported_briefs_can_generate_and_save_without_losing_planning_fields(): void
    {
        $this->connect();
        $disk = Storage::fake('local');
        $prompt = str_repeat('Explain how to prepare questions for the team. ', 145).'End of brief.';
        $disk->put('editorial/plan.json', json_encode([[
            'id' => 'EP-01',
            'title' => 'Plan your next conversation',
            'slug' => 'plan-your-next-conversation',
            'date' => '2026-11-26',
            'pillar' => 'Study',
            'primary' => 'study planning',
            'metaTitle' => 'Study planning with EP',
            'metaDescription' => 'Organise your questions.',
            'prompt' => $prompt,
            'author' => 'EP Editorial Team',
            'support' => ['study questions', 'first conversation'],
        ]], JSON_THROW_ON_ERROR));
        $this->artisan('editorial:import-newsletter-plan', ['path' => $disk->path('editorial/plan.json')])->assertSuccessful();
        $draft = EditorialArticle::sole();
        $url = self::ARTICLES.'/'.$draft->id;
        $brief = $this->getJson($url)->assertOk()->json('brief');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->generatedContent())]]])]);

        $generated = $this->postJson(self::GENERATE, ['brief' => $brief, 'visual_format' => 'featured'])
            ->assertOk()->assertJsonPath('article.meta_title', 'Study planning with EP')->json('article');
        Http::assertSent(fn ($request) => json_decode($request['messages'][0]['content'], true)['brief'] === $brief);
        $this->assertNull($draft->fresh()->body_html);
        $this->putJson($url, [...$generated, 'revision' => $draft->revision, 'brief' => $brief])->assertOk()
            ->assertJsonPath('brief.author', 'EP Editorial Team')->assertJsonPath('brief.support', $brief['support'])
            ->assertJsonPath('brief.prompt', $prompt)->assertJsonPath('status', 'draft');
        $this->assertNull($draft->fresh()->published_at);
        $this->postJson(self::GENERATE, ['brief' => [...$brief, 'support' => [['not a keyword']]], 'visual_format' => 'featured'])
            ->assertUnprocessable()->assertJsonValidationErrors('brief.support.0');
        Http::assertSentCount(1);
    }

    public function test_disabled_ai_keeps_the_draft_and_spends_no_tokens(): void
    {
        $this->connect();
        Setting::set('claude_connector.staff_enabled', false, 'bool');
        $this->postJson(self::GENERATE, $this->input())->assertStatus(503)->assertJsonPath('error', 'not_configured');
        Http::assertNothingSent();
    }

    public function test_uploads_validate_real_images_and_return_public_urls_without_creating_an_article(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(self::IMAGES, ['images' => [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.jpg')]])->assertCreated()
            ->assertJsonCount(2, 'images')->assertJson(fn ($json) => $json->where('images.0.url', fn ($url) => parse_url($url, PHP_URL_SCHEME) === 'https' && str_contains($url, '/storage/article-images/'))->etc());
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->postJson(self::IMAGES, ['images' => [UploadedFile::fake()->createWithContent('unsafe.svg', '<svg onload="alert(1)"></svg>')]])->assertUnprocessable();
        $this->postJson(self::IMAGES, ['images' => [UploadedFile::fake()->createWithContent('fake.png', 'not an image')]])->assertUnprocessable();
        $this->postJson(self::IMAGES, ['images' => [UploadedFile::fake()->image('wide.png', 9000, 1)]])->assertUnprocessable();
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('editorial_articles', 0);
    }

    public function test_briefs_and_carousels_persist_and_edits_require_new_approval_without_changing_the_live_images(): void
    {
        $this->withoutVite();
        config(['services.ghl.article_facebook_enabled' => false]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $images = [['url' => 'https://luvep.com/one.png', 'alt' => 'Cover'], ['url' => 'https://luvep.com/two.png', 'alt' => 'Second slide']];
        $payload = ['title' => 'Planning', 'slug' => 'planning', 'brief' => ['prompt' => 'Original editorial brief'], 'summary' => 'Plan with EP.', 'body_html' => '<p>Ask our team.</p>', 'primary_keyword' => 'planning', 'meta_title' => 'Planning', 'meta_description' => 'Prepare your questions.', 'author' => 'EP', 'featured_images' => $images];
        $draft = $this->postJson(self::ARTICLES, $payload)->assertCreated()->assertJsonPath('brief.prompt', 'Original editorial brief')->assertJsonPath('image_url', $images[0]['url'])->json();
        foreach (['submit', 'approve', 'publish'] as $stage) {
            $draft = $this->postJson(self::ARTICLES.'/'.$draft['id'].'/'.$stage, ['revision' => $draft['revision']])->assertOk()->json();
        }
        $this->get('/articles/planning')->assertOk()->assertSee('Featured image carousel')->assertSee('https://luvep.com/two.png');
        $edited = $this->putJson(self::ARTICLES.'/'.$draft['id'], [...$payload, 'revision' => $draft['revision'], 'brief' => ['prompt' => 'Revised editorial brief'], 'featured_images' => [['url' => 'https://luvep.com/new.png', 'alt' => 'New cover']]])->assertOk()->assertJsonPath('status', 'draft')->json();
        $this->assertSame($images, EditorialArticle::find($draft['id'])->published['featured_images']);
        $this->get('/articles/planning')->assertOk()->assertDontSee('https://luvep.com/new.png');
        $this->postJson(self::ARTICLES.'/'.$draft['id'].'/publish', ['revision' => $edited['revision']])->assertConflict();
        foreach (['javascript:alert(1)', 'http://example.com/insecure.png'] as $unsafe) {
            $this->putJson(self::ARTICLES.'/'.$draft['id'], [...$payload, 'revision' => $edited['revision'], 'featured_images' => [['url' => $unsafe, 'alt' => 'Unsafe']]])->assertUnprocessable();
        }
    }
}
