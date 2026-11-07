<?php

namespace App\Console\Commands;

use App\Models\EditorialSocialDelivery;
use App\Services\Ghl\GhlPublisher;
use App\Services\Ghl\GhlSocialReader;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DispatchEditorialSocial extends Command
{
    protected $signature = 'editorial:dispatch-social';

    protected $description = 'Send newly published Luvep articles to the selected Facebook Page via HighLevel';

    public function handle(GhlPublisher $publisher): int
    {
        if (! config('services.ghl.article_facebook_enabled')) {
            return self::SUCCESS;
        }

        $pending = EditorialSocialDelivery::query()
            ->where('account_id', GhlSocialReader::ACCOUNT)
            ->where('status', 'pending');
        if (! $pending->exists()) {
            return self::SUCCESS;
        }

        try {
            $publisher->assertReady();
        } catch (ValidationException) {
            (clone $pending)->where('waiting_for_connection', false)->update(['waiting_for_connection' => true]);
            $this->warn('Editorial Facebook shares are waiting for the verified HighLevel connection and active Page.');

            return self::SUCCESS;
        }

        foreach ($pending->lazyById() as $delivery) {
            // Claim before the provider call. A crash after sending must not replay a create.
            if (! (clone $pending)->whereKey($delivery->id)->update(['status' => 'sending', 'waiting_for_connection' => false])) {
                continue;
            }

            $postId = $publisher->scheduleFacebookShare($delivery->payload);
            $delivery->update([
                'status' => $postId === null ? 'uncertain' : 'scheduled',
                'provider_post_id' => $postId,
            ]);
        }

        return self::SUCCESS;
    }
}
