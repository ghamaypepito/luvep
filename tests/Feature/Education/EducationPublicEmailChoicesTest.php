<?php

namespace Tests\Feature\Education;

use App\Models\EducationEventEmailSubscription;
use App\Models\EducationEventRegistration;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationPublicEmailChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_saves_only_selected_choices_and_requires_signed_confirmation(): void
    {
        Mail::fake();
        $visit = $this->visit();
        $payload = $this->registration($visit) + ['email_newsletter' => '1'];

        $this->get('/education/register')->assertOk()->assertSee('name="email_follow_up"', false)->assertSee('name="email_newsletter"', false);
        $this->postJson('/education/register', $payload)->assertCreated();
        $this->postJson('/education/register', $payload + ['email_follow_up' => false])->assertOk();

        $registration = EducationEventRegistration::sole();
        $this->assertFalse($registration->email_follow_up);
        $this->assertTrue($registration->email_newsletter);
        $this->assertDatabaseCount('education_event_email_subscriptions', 0);

        $url = $this->preferences($registration);
        $this->get($url)->assertViewHas('choices', ['follow_up' => false, 'newsletter' => true]);
        $this->post($url, ['newsletter' => '1'])->assertOk();
        $subscription = EducationEventEmailSubscription::sole();
        $this->assertNull($subscription->follow_up_confirmed_at);
        $this->assertNotNull($subscription->newsletter_confirmed_at);
    }

    public function test_only_a_verified_rsvp_for_the_same_event_and_identity_supplies_saved_choices(): void
    {
        Mail::fake();
        $visit = $this->visit();
        $other = $this->visit();
        $this->get('/education/rsvp')->assertOk()->assertSee('name="email_follow_up"', false)->assertSee('name="email_newsletter"', false);
        $this->postJson('/education/rsvp', $this->rsvp($other) + ['email_newsletter' => true])->assertCreated();
        $this->get($this->verification(EducationRsvp::sole()))->assertRedirect();

        $this->postJson('/education/rsvp', $this->rsvp($visit) + ['email_follow_up' => true])->assertCreated();
        $rsvp = EducationRsvp::where('education_visit_id', $visit->id)->sole();
        $this->assertTrue($rsvp->email_follow_up);
        $this->assertFalse($rsvp->email_newsletter);

        $this->postJson('/education/register', $this->registration($visit))->assertCreated();
        $registration = EducationEventRegistration::sole();
        $url = $this->preferences($registration);
        $this->get($url)->assertViewHas('choices', ['follow_up' => false, 'newsletter' => false]);
        $this->get($this->verification($rsvp))->assertRedirect();
        $this->get($url)->assertViewHas('choices', ['follow_up' => true, 'newsletter' => false]);
        $this->assertDatabaseCount('education_event_email_subscriptions', 0);

        $this->post($url, ['follow_up' => '1'])->assertOk();
        $subscription = EducationEventEmailSubscription::sole();
        $this->assertNotNull($subscription->follow_up_confirmed_at);
        $this->assertNull($subscription->newsletter_confirmed_at);
    }

    private function visit(): EducationVisit
    {
        return EducationVisit::create([
            'slug' => (string) Str::uuid(), 'school' => 'Davao City', 'kind' => 'school',
            'activity' => 'Luvep Education event', 'status' => 'open', 'timezone' => 'Asia/Manila',
        ]);
    }

    private function rsvp(EducationVisit $visit): array
    {
        return [
            'submission_id' => (string) Str::uuid(), 'visit_id' => $visit->id,
            'full_name' => 'Test Attendee', 'email' => 'event@example.test', 'phone' => '+639171234567',
            'consent' => true,
        ];
    }

    private function registration(EducationVisit $visit): array
    {
        return [
            'submission_id' => (string) Str::uuid(), 'visit_id' => $visit->id,
            'first_name' => 'Test', 'last_name' => 'Attendee', 'email' => 'event@example.test',
            'phone' => '+639171234567', 'goal' => 'Find the right course', 'country' => 'Philippines',
            'planning_timeline' => 'Just exploring', 'education_level' => 'High School Graduate', 'consent' => true,
        ];
    }

    private function preferences(EducationEventRegistration $registration): string
    {
        return URL::temporarySignedRoute('education.register.email-preferences', now()->addDay(), ['registration' => $registration->submission_id]);
    }

    private function verification(EducationRsvp $rsvp): string
    {
        return URL::temporarySignedRoute('education.rsvp.verify', now()->addDay(), ['rsvp' => $rsvp->public_id]);
    }
}
