<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorialCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/content-calendar/articles';

    private function article(array $overrides = []): array
    {
        return array_replace([
            'title' => 'NZ Study Checklist',
            'slug' => 'nz-study-checklist',
            'pillar' => 'Study',
            'planned_date' => '2026-11-17',
            'summary' => 'A guide to planning your next step.',
            'body_html' => '<h2>Plan your next step</h2><p>Start with your documents.</p>',
            'primary_keyword' => 'nz study checklist',
            'meta_title' => 'NZ Study Checklist',
            'meta_description' => 'Plan your next step with our NZ study checklist.',
            'author' => 'EP Editorial Team',
            'image_url' => 'https://luvep.com/images/study.webp',
            'image_alt' => 'A notebook and a checklist',
        ], $overrides);
    }

    private function stage(array $article, string $action): array
    {
        return $this->postJson(self::BASE.'/'.$article['id'].'/'.$action, [
            'revision' => $article['revision'],
        ])->assertOk()->json();
    }

    public function test_staff_can_save_and_submit_but_only_super_admins_can_approve_and_publish(): void
    {
        $this->postJson(self::BASE, $this->article())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson(self::BASE, $this->article())->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $draft = $this->postJson(self::BASE, $this->article())->assertCreated()
            ->assertJsonPath('status', 'draft')->json();
        $this->getJson(self::BASE.'/'.$draft['id'])->assertOk()
            ->assertJsonPath('planned_date', '2026-11-17');
        $submitted = $this->stage($draft, 'submit');
        $this->assertSame('in_review', $submitted['status']);
        foreach (['approve', 'publish'] as $action) {
            $this->postJson(self::BASE.'/'.$draft['id'].'/'.$action, ['revision' => $draft['revision']])
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $approved = $this->stage($submitted, 'approve');
        $this->assertSame('approved', $approved['status']);
        $published = $this->stage($approved, 'publish');
        $this->assertSame('published', $published['status']);
        $this->assertSame('nz-study-checklist', $published['published_slug']);
    }

    public function test_saved_edits_restart_review_and_keep_the_public_snapshot_and_slug(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $draft = $this->postJson(self::BASE, $this->article())->assertCreated()->json();
        $approved = $this->stage($this->stage($draft, 'submit'), 'approve');

        $url = self::BASE.'/'.$draft['id'];
        $updated = $this->putJson($url, $this->article(['revision' => $draft['revision'], 'title' => 'Edited Draft']))
            ->assertOk()->assertJsonPath('status', 'draft')->json();
        $this->postJson($url.'/publish', ['revision' => $approved['revision']])->assertConflict();
        $this->putJson($url, $this->article(['revision' => $draft['revision']]))->assertConflict();

        $published = $this->stage($this->stage($this->stage($updated, 'submit'), 'approve'), 'publish');
        $newDraft = $this->putJson($url, $this->article(['revision' => $published['revision'], 'title' => 'Future draft']))
            ->assertOk()->assertJsonPath('published_slug', 'nz-study-checklist')->json();
        $this->assertDatabaseHas('editorial_articles', ['id' => $draft['id'], 'published_slug' => 'nz-study-checklist', 'status' => 'draft']);
        $snapshot = \App\Models\EditorialArticle::findOrFail($draft['id'])->published;
        $this->assertSame('Edited Draft', $snapshot['title']);
        $this->putJson($url, $this->article(['revision' => $newDraft['revision'], 'slug' => 'new-url']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_validation_sanitizes_rich_text_and_reserves_each_slug(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $draft = $this->postJson(self::BASE, $this->article([
            'body_html' => '<h2>Useful heading</h2><script>danger()</script><p onclick="danger()">Content</p><a href="javascript:danger()">link</a>',
        ]))->assertCreated()->json();
        $this->assertStringContainsString('<h2>Useful heading</h2>', $draft['body_html']);
        $this->assertStringNotContainsString('danger', $draft['body_html']);
        $this->postJson(self::BASE, $this->article())->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson(self::BASE, $this->article(['slug' => 'image-check', 'image_url' => 'http://example.com/unsafe.svg']))
            ->assertUnprocessable()->assertJsonValidationErrors('image_url');
    }

    public function test_multibyte_body_keeps_its_end_after_sanitizing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $html = '<p>'.str_repeat('詩', 70000).' End of article.</p>';
        $draft = $this->postJson(self::BASE, $this->article(['body_html' => $html]))->assertCreated();
        $this->assertTrue(str_ends_with($draft->json('body_html'), ' End of article.</p>'));
    }

    public function test_planning_briefs_import_idempotently_and_keep_staff_edits(): void
    {
        $disk = Storage::fake('local');
        $disk->put('editorial/test-plan.json', json_encode([[
            'id' => 'EP-01',
            'title' => 'Planning your first article',
            'slug' => 'planning-your-first-article',
            'date' => '2026-11-17',
            'pillar' => 'Study',
            'primary' => 'editorial planning',
            'metaTitle' => 'Editorial planning',
            'metaDescription' => 'Write a planning guide.',
            'prompt' => 'Draft a guide.',
        ]], JSON_THROW_ON_ERROR));
        $arguments = ['path' => $disk->path('editorial/test-plan.json')];

        $this->artisan('editorial:import-newsletter-plan', $arguments)->assertSuccessful();
        $this->assertDatabaseCount('editorial_articles', 1);
        $this->assertDatabaseHas('editorial_articles', ['topic_key' => 'EP-01', 'planned_date' => '2026-11-17', 'status' => 'draft']);
        \App\Models\EditorialArticle::where('topic_key', 'EP-01')->update(['title' => 'Editor revised']);
        $this->artisan('editorial:import-newsletter-plan', $arguments)->assertSuccessful();
        $this->assertDatabaseCount('editorial_articles', 1);
        $this->assertDatabaseHas('editorial_articles', ['topic_key' => 'EP-01', 'title' => 'Editor revised']);
    }

    public function test_import_rolls_back_and_reports_a_reserved_slug_without_disclosing_briefs(): void
    {
        \App\Models\EditorialArticle::create(['title' => 'Staff draft', 'slug' => 'reserved']);
        $disk = Storage::fake('local');
        $topic = [
            'id' => 'EP-01',
            'title' => 'New article',
            'slug' => 'new-article',
            'date' => '2026-11-17',
            'pillar' => 'Study',
            'primary' => 'article planning',
            'metaTitle' => 'Article planning',
            'metaDescription' => 'Draft an article.',
            'prompt' => 'Private planning text.',
        ];
        $disk->put('editorial/test-conflict.json', json_encode([
            $topic, array_replace($topic, ['id' => 'EP-02', 'slug' => 'reserved']),
        ], JSON_THROW_ON_ERROR));

        $this->artisan('editorial:import-newsletter-plan', ['path' => $disk->path('editorial/test-conflict.json')])
            ->expectsOutput('An article already uses a URL slug in this plan. No briefs were imported.')
            ->assertFailed();
        $this->assertDatabaseCount('editorial_articles', 1);
    }

    public function test_calendar_returns_month_topics_and_an_undated_list(): void
    {
        $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(self::BASE, $this->article(['planned_date' => '2027-03-17']))->assertCreated();
        $this->postJson(self::BASE, $this->article(['title' => 'Undated', 'slug' => 'undated', 'planned_date' => null]))
            ->assertCreated();
        $this->get('/admin/content-calendar?month=2027-03')->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/editorial/ContentCalendar', false)
                ->where('month', '2027-03')
                ->where('canPublish', false)
                ->has('topics', 1)
                ->has('undated', 1)
                ->where('undated.0.title', 'Undated'));
    }
}
