<?php

namespace App\Services\Claude;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class ClaudeSettings
{
    public function apiKey(): string
    {
        $encrypted = Setting::get('claude_connector.api_key');
        if ($encrypted) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                return ''; // Never silently fall through to another credential.
            }
        }

        return trim((string) config('claude.api_key'));
    }

    public function enabled(string $surface): bool
    {
        return (bool) Setting::get("claude_connector.{$surface}_enabled", false);
    }

    public function model(string $surface): string
    {
        return (string) Setting::get("claude_connector.{$surface}_model", config('claude.model'));
    }

    public function instructions(string $surface): string
    {
        return (string) Setting::get("claude_connector.{$surface}_instructions", config("claude.instructions.{$surface}"));
    }

    public function publicState(): array
    {
        $state = ['global_enabled' => app(\App\Services\AIService::class)->isEnabled(), 'configured' => $this->apiKey() !== '',
            'key_source' => Setting::get('claude_connector.api_key') ? 'saved' : (config('claude.api_key') ? 'environment' : 'none')];
        foreach (['pathy', 'staff'] as $surface) {
            $state[$surface.'_enabled'] = $this->enabled($surface);
            $state[$surface.'_model'] = $this->model($surface);
            $state[$surface.'_instructions'] = $this->instructions($surface);
        }

        return $state;
    }
}
