<?php

namespace App\Services\Claude;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeClient
{
    public function __construct(private ClaudeSettings $settings) {}

    /** Fixed destination, separate trusted instructions, optional staff reference blocks, text-only replies. Never log prompts or provider bodies. */
    public function chat(array $messages, string $surface, array $references = []): array
    {
        if (! in_array($surface, ['pathy', 'staff'], true)) {
            throw new \InvalidArgumentException('Unknown assistant.');
        }
        $empty = ['content' => null, 'tokens' => null, 'model' => $this->settings->model($surface), 'error' => 'not_configured'];
        $key = $this->settings->apiKey();
        if ($key === '' || (! $this->settings->enabled($surface) || ! app(\App\Services\AIService::class)->isEnabled())) {
            return $empty;
        }
        $system = implode("\n\n", array_column(array_filter($messages, fn ($m) => $m['role'] === 'system'), 'content'));
        $turns = array_values(array_map(fn ($m) => ['role' => $m['role'], 'content' => (string) $m['content']],
            array_filter($messages, fn ($m) => in_array($m['role'], ['user', 'assistant'], true))));
        if ($turns === []) {
            return $empty;
        }

        if ($references !== []) {
            if ($surface !== 'staff' || $turns[array_key_last($turns)]['role'] !== 'user') {
                throw new \InvalidArgumentException('References require a staff user turn.');
            }
            $last = array_key_last($turns);
            $turns[$last]['content'] = [['type' => 'text', 'text' => $turns[$last]['content']], ...$references];
        }

        $options = str_starts_with($this->settings->model($surface), 'claude-sonnet-5-5')
            ? ['thinking' => ['type' => 'between_tools']] : [];
        try {
            $response = Http::withToken($key)->acceptJson()
                ->withHeaders(['anthropic-version' => config('claude.api_version')])
                ->timeout((int) config('claude.timeout_seconds', 35))->connectTimeout(5)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->settings->model($surface), 'system' => $system,
                    'messages' => $turns, 'max_tokens' => (int) config("claude.max_tokens.{$surface}"),
                    ...$options,
                ]);
            if (! $response->successful()) {
                Log::warning('Claude connector request failed', ['surface' => $surface, 'status' => $response->status()]);

                if ($response->status() === 400 && $response->json('error.type') === 'invalid_request_error' && str_contains(strtolower((string) $response->json('error.message')), 'credit balance')) {
                    return array_replace($empty, ['error' => 'billing']);
                }

                return array_replace($empty, ['error' => match ($response->status()) {
                    401, 403 => 'authentication', 429 => 'rate_limit', default => 'provider_unavailable',
                }]);
            }
            $blocks = $response->json('content', []);
            $text = implode("\n", array_column(array_filter(is_array($blocks) ? $blocks : [], fn ($b) => ($b['type'] ?? null) === 'text' && is_string($b['text'] ?? null)), 'text'));
            if (trim($text) === '') {
                return array_replace($empty, ['error' => 'empty_response']);
            }

            return ['content' => $text, 'model' => $response->json('model', $empty['model']),
                'tokens' => (int) $response->json('usage.input_tokens', 0) + (int) $response->json('usage.output_tokens', 0), 'error' => null];
        } catch (\Throwable) {
            Log::warning('Claude connector unavailable', ['surface' => $surface]);

            return array_replace($empty, ['error' => 'connection']);
        }
    }

    /** Verify the saved credential and model without generating a paid chat response. */
    public function testConnection(string $surface): array
    {
        $model = $this->settings->model($surface);
        $key = $this->settings->apiKey();
        if ($key === '') {
            return ['connected' => false, 'model' => $model];
        }
        try {
            $response = Http::withToken($key)->acceptJson()
                ->withHeaders(['anthropic-version' => config('claude.api_version')])
                ->timeout(15)->connectTimeout(5)
                ->get('https://api.anthropic.com/v1/models/'.rawurlencode($model));

            return ['connected' => $response->successful() && is_string($response->json('id')), 'model' => $model];
        } catch (\Throwable) {
            return ['connected' => false, 'model' => $model];
        }
    }
}
