<?php

namespace Tests\Feature\Education;

use App\Models\EducationEventEmailSubscription;
use App\Models\EducationEventRegistration;
use App\Models\EducationVisit;
use App\Models\Lead;
use App\Models\Setting;
use App\Services\Ghl\EducationEventEmailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationEventEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_confirmation_is_required_and_only_saves_chosen_subscriptions(): void
    {
        $registration = $this->registration();
        $url = $this->preferenceUrl($registration);
        $this->get($url)->assertOk()->assertSee('Choose your Education emails');
        $this->assertDatabaseCount('education_event_email_subscriptions', 0);
        $this->postJson(strtok($url, '?'), ['follow_up' => '1'])->assertForbidden();
        $this->postJson($url, [])->assertUnprocessable();
        $this->post($url, ['follow_up' => '1'])->assertOk()->assertSee('Your choices are saved');
        $subscription = EducationEventEmailSubscription::sole();
        $this->assertNotNull($subscription->follow_up_confirmed_at);
        $this->assertNull($subscription->newsletter_confirmed_at);
        $this->post($url, ['newsletter' => '1'])->assertOk();
        $this->assertNotNull($subscription->fresh()->newsletter_confirmed_at);
        $this->assertDatabaseCount('education_event_email_subscriptions', 1);
    }

    public function test_only_confirmed_new_leads_enrol_once_then_exit_into_newsletter_on_stage_change(): void
    {
        $registration = $this->registration();
        Setting::set('education_ghl_nurture_enabled', true, 'bool');
        Setting::set('ghl_connector.token', Crypt::encryptString('synthetic-test-token'));
        Http::preventStrayRequests();
        Http::fake([
            '*/contacts/?*' => Http::response(['contacts' => []]),
            '*/contacts/upsert' => Http::response(['contact' => ['id' => 'synthetic-ghl-contact', 'email' => 'event@example.test']]),
            '*/contacts/synthetic-ghl-contact/*' => Http::response(['success' => true]),
        ]);

        $this->artisan('education:sync-event-email')->assertSuccessful();
        Http::assertNothingSent();
        $this->post($this->preferenceUrl($registration), ['follow_up' => '1', 'newsletter' => '1'])->assertOk();
        $this->artisan('education:sync-event-email')->assertSuccessful();
        $subscription = EducationEventEmailSubscription::sole();
        $this->assertNotNull($subscription->enrolled_at);
        $this->artisan('education:sync-event-email')->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => ($request->data()['tags'] ?? null) === [EducationEventEmailSync::NURTURE_READY]);

        $registration->lead->update(['education_stage' => 'Pre-Screening Done']);
        $this->artisan('education:sync-event-email')->assertSuccessful();
        $this->assertNotNull($subscription->fresh()->exited_at);
        $this->assertNotNull($subscription->fresh()->newsletter_synced_at);
        $exit = Http::recorded()->slice(3)->values();
        $this->assertSame('DELETE', $exit[0][0]->method());
        $this->assertSame([EducationEventEmailSync::NEWSLETTER_OPT_IN], $exit[1][0]['tags']);
        $this->assertSame([EducationEventEmailSync::ADVANCED], $exit[2][0]['tags']);

        $registration->lead->update(['education_stage' => 'New Lead']);
        $this->artisan('education:sync-event-email')->assertSuccessful();
        Http::assertSentCount(6);
    }

    private function preferenceUrl(EducationEventRegistration $registration): string
    {
        return URL::temporarySignedRoute('education.register.email-preferences', now()->addDay(), [
            'registration' => $registration->submission_id,
        ]);
    }

    private function registration(): EducationEventRegistration
    {
        $lead = Lead::create([
            'lead_id' => 'EDUCATION-NURTURE-TEST', 'first_name' => 'Test', 'last_name' => 'Attendee',
            'email' => 'event@example.test', 'status' => 'New Leads',
        ]);
        $visit = EducationVisit::create([
            'slug' => (string) Str::uuid(), 'school' => 'Davao City', 'kind' => 'school',
            'activity' => 'Luvep Education event', 'status' => 'closed', 'timezone' => 'Asia/Manila',
            'schedule_confirmed' => true, 'event_date' => now('Asia/Manila')->subDay()->toDateString(),
            'event_time' => '19:00',
        ]);

        return EducationEventRegistration::create([
            'lead_id' => $lead->id, 'education_visit_id' => $visit->id, 'submission_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'event_name' => $visit->activity,
            'event_date' => $visit->event_date, 'event_time' => '19:00', 'event_timezone' => 'Asia/Manila',
            'country' => 'Philippines', 'school' => 'Davao City', 'goal' => 'Find the right course',
            'planning_timeline' => 'Just exploring', 'education_level' => 'High School Graduate',
            'contact_details' => ['email' => 'event@example.test', 'first_name' => 'Test', 'last_name' => 'Attendee'],
            'consented_at' => now(),
        ]);
    }
}
