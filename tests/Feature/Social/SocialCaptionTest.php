<?php

namespace Tests\Feature\Social;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialCaptionTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/social/workspace-test/ai/caption';

    private function payload(): array
    {
        return ['campaign' => 'Study options', 'service' => 'Education', 'channels' => ['Instagram'], 'tone' => 'Friendly'];
    }

    private function connect(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        config(['ai.enabled' => true]);
        Setting::set('ai_enabled', true, 'bool');
        Setting::set('claude_connector.api_key', Crypt::encryptString('private-test-key-1234567890'));
        Setting::set('claude_connector.staff_enabled', true, 'bool');
    }

    public function test_only_authorized_admin_users_can_generate(): void
    {
        Http::preventStrayRequests();
        $this->postJson($this->url, $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'education']))->postJson($this->url, $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_validated_caption_uses_claude_and_returns_a_draft_without_publishing(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Explore study options with EP.']], 'usage' => ['input_tokens' => 4, 'output_tokens' => 5]])]);
        $this->postJson($this->url, $this->payload())->assertOk()->assertJsonPath('caption', 'Explore study options with EP.')->assertJsonPath('draft', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.anthropic.com/v1/messages' && str_contains($r['system'], 'Never invent') && str_contains($r['messages'][0]['content'], 'Study options'));
    }

    public function test_invalid_input_does_not_spend_tokens(): void
    {
        $this->connect();
        Http::preventStrayRequests();
        $p = $this->payload();
        $p['channels'] = ['unknown'];
        $this->postJson($this->url, $p)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_disabled_staff_connector_does_not_spend_tokens(): void
    {
        $this->connect();
        Setting::set('claude_connector.staff_enabled', false, 'bool');
        Http::preventStrayRequests();
        $this->postJson($this->url, $this->payload())->assertStatus(503)->assertJsonPath('error', 'not_configured');
        Http::assertNothingSent();
    }

    public function test_low_credit_errors_are_actionable_and_never_expose_upstream_bodies(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low. private-upstream-detail']], 400)]);
        $this->postJson($this->url, $this->payload())->assertStatus(503)->assertJsonPath('error', 'billing')->assertDontSee('private-upstream-detail')->assertDontSee('private-test-key');
    }

    public function test_layout_returns_validated_editable_design_data_without_uploaded_images_leaving_browser(): void
    {
        $this->connect();
        $design = ['headline' => 'Your next chapter', 'supporting_copy' => 'Explore study planning with EP.', 'cta' => 'Book a conversation', 'caption' => 'Talk to the EP team.', 'layout' => 'photo-top'];
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($design)]]])]);
        $this->postJson('/social/workspace-test/ai/layout', array_merge($this->payload(), ['brief' => 'Use my uploaded photo and a short headline.']))->assertOk()->assertJsonPath('design.headline', $design['headline'])->assertJsonPath('draft', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['system'], 'JSON') && ! str_contains(json_encode($r->data()), 'data:image'));
    }

    public function test_layout_rejects_invalid_model_output_without_exposing_it(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'private-malformed-response']]])]);
        $this->postJson('/social/workspace-test/ai/layout', array_merge($this->payload(), ['brief' => 'Make a layout']))->assertStatus(502)->assertDontSee('private-malformed-response');
    }

    public function test_layout_is_protected_and_validates_brief_before_provider_call(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/social/workspace-test/ai/layout', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'education']))->postJson('/social/workspace-test/ai/layout', $this->payload())->assertForbidden();
        $this->connect();
        $this->postJson('/social/workspace-test/ai/layout', $this->payload())->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_reference_files_reach_claude_as_content_blocks_not_system_instructions(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'A reference-led caption.']]])]);
        $pdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\n%%EOF");
        $text = \Illuminate\Http\UploadedFile::fake()->createWithContent('notes.txt', 'Reference fact: campus tour on Saturday.');
        $image = \Illuminate\Http\UploadedFile::fake()->image('moodboard.png', 20, 20);
        $this->post($this->url, array_merge($this->payload(), ['brief' => 'Use the attached references.', 'references' => [$pdf, $text, $image]]), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('draft', true);
        Http::assertSent(function ($r) {
            $content = $r['messages'][0]['content'];

            return is_array($content) && str_contains($r['system'], 'untrusted')
                && ! str_contains($r['system'], 'campus tour')
                && collect($content)->contains(fn ($b) => ($b['type'] ?? null) === 'document' && ($b['source']['media_type'] ?? null) === 'application/pdf')
                && collect($content)->contains(fn ($b) => ($b['type'] ?? null) === 'image' && ($b['source']['media_type'] ?? null) === 'image/png')
                && str_contains(json_encode($content), 'campus tour on Saturday');
        });
    }

    public function test_unsupported_or_too_many_references_do_not_call_claude(): void
    {
        $this->connect();
        Http::preventStrayRequests();
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('payload.html', '<html>Not a brief</html>');
        $this->post($this->url, array_merge($this->payload(), ['references' => [$file]]), ['Accept' => 'application/json'])->assertUnprocessable();
        $files = [];
        for ($i = 0; $i < 4; $i++) {
            $files[] = \Illuminate\Http\UploadedFile::fake()->createWithContent("note{$i}.txt", 'Reference text');
        }
        $this->post($this->url, array_merge($this->payload(), ['references' => $files]), ['Accept' => 'application/json'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_layout_includes_references_and_keeps_them_out_of_response_and_logs(): void
    {
        $this->connect();
        $design = ['headline' => 'Visit campus', 'supporting_copy' => 'Explore your next step.', 'cta' => 'Talk to EP', 'caption' => 'Plan with EP.', 'layout' => 'photo-top'];
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($design)]]])]);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('private-notes.txt', 'Private reference marker 82913');
        $this->post('/social/workspace-test/ai/layout', array_merge($this->payload(), ['brief' => 'Use my references.', 'references' => [$file]]), ['Accept' => 'application/json'])->assertOk()->assertDontSee('82913')->assertDontSee('private-notes.txt');
        Http::assertSent(fn ($r) => str_contains(json_encode($r['messages'][0]['content']), '82913'));
        $this->assertStringNotContainsString('82913', json_encode(\App\Models\ActivityLog::all()->toArray()));
    }

    public function test_reference_total_and_text_limits_are_enforced_before_provider_call(): void
    {
        $this->connect();
        Http::preventStrayRequests();
        $pdf = "%PDF-1.4\n".str_repeat('x', 3 * 1024 * 1024);
        $files = [];
        for ($i = 0; $i < 3; $i++) {
            $files[] = \Illuminate\Http\UploadedFile::fake()->createWithContent("brief{$i}.pdf", $pdf);
        }
        $this->post($this->url, array_merge($this->payload(), ['references' => $files]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('references');
        $text = \Illuminate\Http\UploadedFile::fake()->createWithContent('long.txt', str_repeat('a', 51201));
        $this->post($this->url, array_merge($this->payload(), ['references' => [$text]]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('references');
        Http::assertNothingSent();
    }
}
