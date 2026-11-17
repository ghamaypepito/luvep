<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\User;
use App\Services\Immigration\PassportPrefillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ExtractPassportDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 70;

    public bool $failOnTimeout = true;

    public function __construct(public int $leadId, public int $userId, public int $documentId) {}

    public function handle(PassportPrefillService $service): void
    {
        $user = User::find($this->userId);
        $lead = Lead::immigrationCase()->find($this->leadId);
        if (! $user || ! $lead || ! PassportPrefillService::enabledFor($user)) {
            return;
        }
        try {
            // A later upload supersedes this queued request, not earlier files.
            if ($service->document($lead)->id !== $this->documentId) {
                return;
            }
            $service->propose($lead, $user, true);
        } catch (\Throwable $e) {
            $key = PassportPrefillService::stateKey($this->leadId, $this->userId);
            $state = Cache::get($key, []);
            if (($state['document_id'] ?? null) === $this->documentId && ($state['status'] ?? null) === 'queued') {
                Cache::put($key, ['status' => 'failed', 'document_id' => $this->documentId], now()->addMinutes(15));
            }
            \Illuminate\Support\Facades\Log::warning('Automatic passport draft unavailable', ['lead_id' => $this->leadId, 'document_id' => $this->documentId, 'exception' => get_class($e)]);
        }
    }
}
