<?php

namespace App\Services\Ghl;

use App\Models\EducationEventEmailSubscription;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EducationEventEmailSync
{
    public const NURTURE_WORKFLOW = '3c85a5bc-ff59-4cf8-bb91-e26d84e5f5cc';

    public const NURTURE_READY = 'luvep-education-event-nurture-ready';

    public const ADVANCED = 'luvep-education-stage-advanced';

    public const NEWSLETTER_OPT_IN = 'luvep-education-newsletter-opt-in';

    public function __construct(private GhlConnection $connection) {}

    public function run(): void
    {
        if (! Setting::get('education_ghl_nurture_enabled', false)) {
            return;
        }

        $token = $this->connection->token();
        if ($token === '') {
            throw new RuntimeException('The Luvep HighLevel connection needs a token.');
        }
        $client = Http::withToken($token)->acceptJson()->withHeaders(['Version' => '2021-07-28'])
            ->baseUrl('https://services.leadconnectorhq.com')->connectTimeout(4)->timeout(8);

        EducationEventEmailSubscription::query()
            ->where(fn ($query) => $query->whereNull('exited_at')
                ->orWhere(fn ($query) => $query->whereNotNull('newsletter_confirmed_at')->whereNull('newsletter_synced_at')))
            ->eachById(function (EducationEventEmailSubscription $subscription) use ($client) {
                Cache::lock('education-email-sync:'.$subscription->id, 120)->get(function () use ($client, $subscription) {
                    try {
                        $this->sync($client, $subscription->fresh());
                    } catch (RuntimeException|\Illuminate\Http\Client\ConnectionException $e) {
                        Log::warning('Education email sync will retry', [
                            'subscription_id' => $subscription->id,
                            'reason' => $e instanceof RuntimeException ? $e->getMessage() : 'HighLevel connection timed out',
                        ]);
                    }
                });
            });
    }

    private function sync(PendingRequest $client, EducationEventEmailSubscription $subscription): void
    {
        $lead = $subscription->lead;
        $validIdentity = $lead && ! $lead->trashed()
            && mb_strtolower(trim((string) $lead->email)) === $subscription->email;
        $advanced = $validIdentity && ($lead->status !== 'New Leads'
            || ($lead->education_stage && $lead->education_stage !== 'New Lead'));
        $visit = $subscription->registration->visit;
        $cancelled = $visit?->status === 'cancelled';

        if (! $validIdentity || $advanced || $cancelled || $subscription->exited_at) {
            if (! $subscription->exited_at && $subscription->ghl_contact_id && $subscription->follow_up_confirmed_at) {
                $response = $client->delete('contacts/'.$subscription->ghl_contact_id.'/workflow/'.self::NURTURE_WORKFLOW);
                $this->check($response, 'remove nurture');
            }
            $subscription->exited_at ??= now();
            if ($advanced && $subscription->newsletter_confirmed_at && ! $subscription->newsletter_synced_at) {
                $this->findOrUpsertContact($client, $subscription);
                $this->addTags($client, $subscription, [self::NEWSLETTER_OPT_IN]);
                $this->addTags($client, $subscription, [self::ADVANCED]);
                $subscription->newsletter_synced_at = now();
            }
            $subscription->save();

            return;
        }

        if (! $subscription->follow_up_confirmed_at || $subscription->enrolled_at) {
            return;
        }

        // Wait for the managed schedule. An unconfirmed/manual event does not start a drip.
        $start = $visit?->startsAt();
        if (! $start || $start->isFuture()) {
            return;
        }
        if ($start->lt(now()->subDays(14))) {
            $subscription->update(['exited_at' => now()]);

            return;
        }

        $this->findOrUpsertContact($client, $subscription);
        $freshLead = $lead->fresh();
        if (! $freshLead || $freshLead->trashed() || $freshLead->status !== 'New Leads'
            || ($freshLead->education_stage && $freshLead->education_stage !== 'New Lead')
            || mb_strtolower(trim((string) $freshLead->email)) !== $subscription->email) {
            return;
        }

        $this->addTags($client, $subscription, [self::NURTURE_READY]);
        $subscription->update(['enrolled_at' => now()]);
    }

    private function findOrUpsertContact(PendingRequest $client, EducationEventEmailSubscription $subscription): void
    {
        if ($subscription->ghl_contact_id) {
            return;
        }

        $response = $client->get('contacts/', [
            'locationId' => GhlConnection::LOCATION, 'query' => $subscription->email, 'limit' => 100,
        ]);
        $this->check($response, 'lookup contact');
        $contacts = collect($response->json('contacts', []))
            ->filter(fn ($contact) => mb_strtolower((string) ($contact['email'] ?? '')) === $subscription->email);
        if ($contacts->count() > 1) {
            throw new RuntimeException('More than one HighLevel contact has this email.');
        }

        $contact = $contacts->first();
        if (! $contact) {
            $name = $subscription->registration->contact_details;
            $response = $client->post('contacts/upsert', [
                'locationId' => GhlConnection::LOCATION, 'email' => $subscription->email,
                'firstName' => $name['first_name'], 'lastName' => $name['last_name'],
            ]);
            $this->check($response, 'upsert contact');
            $contact = $response->json('contact');
        }
        if (empty($contact['id']) || mb_strtolower((string) ($contact['email'] ?? '')) !== $subscription->email) {
            throw new RuntimeException('HighLevel contact identity did not match.');
        }

        $subscription->update(['ghl_contact_id' => $contact['id']]);
    }

    private function addTags(PendingRequest $client, EducationEventEmailSubscription $subscription, array $tags): void
    {
        $this->check($client->post('contacts/'.$subscription->ghl_contact_id.'/tags', ['tags' => $tags]), 'add workflow tags');
    }

    private function check(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("HighLevel {$operation} returned HTTP {$response->status()}.");
        }
    }
}
