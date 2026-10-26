<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class ProgramSlackNotifier
{
    private function webhook(): ?string
    {
        $stored = Setting::get('education_slack_webhook_encrypted');
        if ($stored) {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                return null;
            }
        }

        return config('services.slack.education_webhook');
    }

    public function configured(): bool
    {
        return (bool) ($this->webhook() || (config('services.slack.notifications.bot_user_oauth_token') && Setting::get('education_slack_channel_id', config('services.slack.notifications.channel'))));
    }

    public function send(string $programme, string $event, array $summary, string $staffUrl): bool
    {
        if (! $this->configured()) {
            return false;
        }
        $clean = fn ($s) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], preg_replace('/[\r\n]+/', ' ', (string) $s));
        $summary = array_intersect_key($summary, array_flip(['Name', 'School', 'Event', 'Schedule', 'Status']));
        $person = $summary['Name'] ?? 'Candidate';
        unset($summary['Name']);
        $text = $clean($person).(str_contains(strtolower($event), 'registration') ? ' registered for' : ' RSVP’d to').' this event! 🎉'."\n".$clean($programme).' · '.$clean($event)."\n".implode("\n", array_map(fn ($key, $value) => $clean($key).': '.$clean($value), array_keys($summary), array_values($summary)))."\n".$staffUrl;
        $payload = ['text' => $text, 'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false, 'link_names' => false];
        try {
            if ($hook = $this->webhook()) {
                if (! preg_match('#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', $hook)) {
                    throw new \RuntimeException;
                }$response = Http::timeout(12)->post($hook, $payload);
                $ok = $response->successful() && trim($response->body()) === 'ok';
            } else {
                $response = Http::withToken(config('services.slack.notifications.bot_user_oauth_token'))->timeout(12)->post('https://slack.com/api/chat.postMessage', $payload + ['channel' => Setting::get('education_slack_channel_id', config('services.slack.notifications.channel'))]);
                $ok = $response->successful() && $response->json('ok') === true;
            }if (! $ok) {
                throw new \RuntimeException;
            }

            return true;
        } catch (\Throwable) {
            throw new \RuntimeException('Slack delivery failed. Check the connection, channel and app permissions.');
        }
    }
}
