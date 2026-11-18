<?php

namespace Tests\Feature\Affiliates;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AffiliateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function partner(array $fields = []): User
    {
        return User::factory()->create($fields + ['role' => 'agent', 'is_active' => true, 'referral_code' => 'AGT-PARTNER']);
    }

    private function payload(array $fields = []): array
    {
        return $fields + ['first_name' => 'Jamie', 'last_name' => 'Example', 'email' => 'jamie@example.test',
            'phone' => '+64 21 000 0100', 'country' => 'New Zealand', 'service' => 'Study in New Zealand', 'consent' => true];
    }

    private function url(User $partner): string
    {
        return '/affiliate/register/'.$partner->referral_code;
    }

    public function test_public_registration_creates_one_tagged_lead_with_original_affiliate_and_a_confirmation(): void
    {
        Mail::fake();
        $partner = $this->partner(['name' => 'A very long affiliate partnership name for overseas education opportunities']);
        $usersBefore = User::count();
        $this->get($this->url($partner))->assertOk()->assertSee('Referred by '.$partner->name)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post($this->url($partner), $this->payload(['email' => ' JAMIE@EXAMPLE.TEST ']))->assertRedirect($this->url($partner));
        $this->get($this->url($partner))->assertSee('Thank you for registering.');
        $lead = Lead::sole();
        $this->assertSame('jamie@example.test', $lead->email);
        $this->assertSame($partner->id, $lead->agent_id);
        $this->assertSame($partner->id, $lead->original_affiliate_id);
        $this->assertNotNull($lead->referred_at);
        $this->assertSame('New Leads', $lead->status);
        $this->assertSame('affiliate-link', $lead->source);
        $this->assertSame('New Zealand', $lead->residence_country);
        $this->assertSame(1, $lead->tags()->count());
        $tag = $lead->tags()->sole();
        $this->assertStringStartsWith('Affiliate partner: ', $tag->name);
        $this->assertLessThanOrEqual(60, mb_strlen($tag->name));
        $this->assertSame('affiliate-partner-'.$partner->id, $tag->slug);
        $this->assertStringContainsString('Study in New Zealand', $lead->notes()->sole()->body);
        $log = ActivityLog::where('action', 'affiliate.registration.created')->sole();
        $this->assertSame($partner->id, $log->properties['affiliate_id']);
        $this->assertTrue($log->properties['contact_consent']);
        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseCount('education_event_email_subscriptions', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_existing_normalized_email_is_preserved_without_reassignment_duplicates_or_new_access(): void
    {
        Mail::fake();
        $original = $this->partner();
        $other = $this->partner(['referral_code' => 'AGT-OTHER']);
        $lead = Lead::create(['first_name' => 'Existing', 'last_name' => 'Person', 'email' => ' JAMIE@Example.test ', 'phone' => 'original',
            'agent_id' => $original->id, 'original_affiliate_id' => $original->id, 'referred_at' => now()->subDay(), 'status' => 'Converted',
            'is_student' => true, 'education_stage' => 'Approved Visa']);
        $tag = LeadTag::create(['name' => 'Existing tag', 'slug' => 'existing-tag', 'color' => 'blue']);
        $lead->tags()->attach($tag);
        $before = $lead->fresh()->getRawOriginal();
        $this->post($this->url($other), $this->payload())->assertRedirect($this->url($other));
        $this->get($this->url($other))->assertSee('Thank you for registering.')->assertDontSee('Existing Person')->assertDontSee('original');
        $this->assertDatabaseCount('leads', 1);
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        $this->assertSame([$tag->id], $lead->tags()->pluck('lead_tags.id')->all());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('lead_notes', 0);
        Mail::assertNothingSent();
    }

    public function test_repeat_submission_does_not_create_another_record_or_duplicate_tag(): void
    {
        $partner = $this->partner();
        $this->post($this->url($partner), $this->payload())->assertRedirect();
        $lead = Lead::sole();
        $this->post($this->url($partner), $this->payload(['email' => 'JAMIE@EXAMPLE.TEST']))->assertRedirect();
        $this->assertSame($lead->id, Lead::sole()->id);
        $this->assertDatabaseCount('lead_tag_lead', 1);
        $this->assertDatabaseCount('lead_notes', 1);
    }

    public function test_archived_email_is_never_recreated_or_restored(): void
    {
        $partner = $this->partner();
        $lead = Lead::create(['first_name' => 'Archived', 'last_name' => 'Person', 'email' => 'jamie@example.test']);
        $lead->delete();
        $this->post($this->url($partner), $this->payload())->assertRedirect();
        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
        $this->assertNull($lead->fresh()->original_affiliate_id);
    }

    public function test_the_url_selects_the_partner_and_posted_ids_and_origin_are_ignored(): void
    {
        $partner = $this->partner();
        $other = $this->partner(['referral_code' => 'AGT-OTHER']);
        $this->post($this->url($partner), $this->payload(['agent_id' => $other->id, 'original_affiliate_id' => $other->id, 'ref' => $other->referral_code]))->assertRedirect();
        $this->assertSame($partner->id, Lead::sole()->agent_id);
        $this->assertSame($partner->id, Lead::sole()->original_affiliate_id);
    }

    public function test_invalid_inactive_deleted_and_non_partner_codes_cannot_register(): void
    {
        $this->get('/affiliate/register/UNKNOWN')->assertNotFound()->assertSee('This referral link is unavailable.');
        $this->post('/affiliate/register/UNKNOWN', $this->payload())->assertNotFound();
        foreach ([['is_active' => false], ['role' => 'sales'], ['deleted_at' => now()]] as $index => $overrides) {
            $partner = $this->partner($overrides + ['referral_code' => 'AGT-INVALID'.$index]);
            $this->get($this->url($partner))->assertNotFound();
            $this->post($this->url($partner), $this->payload())->assertNotFound();
        }
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_contact_consent_and_valid_fields_are_required(): void
    {
        $partner = $this->partner();
        $this->post($this->url($partner), $this->payload(['consent' => false, 'email' => 'not email', 'service' => 'Unlisted']))
            ->assertSessionHasErrors(['consent', 'email', 'service']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_submission_limit_stops_additional_requests_without_extra_leads(): void
    {
        $partner = $this->partner();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post($this->url($partner), $this->payload())->assertRedirect();
        }
        $this->post($this->url($partner), $this->payload())->assertStatus(429);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_referral_code_generation_preserves_old_links_and_serializes_stale_models(): void
    {
        $partner = $this->partner(['referral_code' => null]);
        $stale = $partner->fresh();
        $code = $partner->ensureReferralCode();
        $this->assertSame(20, strlen($code));
        $this->assertSame($code, $stale->ensureReferralCode());
        $this->assertSame($code, $partner->fresh()->referral_code);
        $this->assertNull($this->partner(['referral_code' => 'AGT-INACTIVE', 'is_active' => false])->ensureReferralCode());
    }

    public function test_report_scope_keeps_original_referral_when_current_assignment_changes(): void
    {
        $partner = $this->partner();
        $other = $this->partner(['referral_code' => 'AGT-OTHER']);
        $this->post($this->url($partner), $this->payload())->assertRedirect();
        $lead = Lead::sole();
        $lead->update(['agent_id' => $other->id]);
        $this->assertSame(1, Lead::referredByAffiliate($partner->id)->count());
        $this->assertSame(0, Lead::referredByAffiliate($other->id)->count());
        $this->expectException(ValidationException::class);
        $lead->update(['original_affiliate_id' => $other->id]);
    }
}
