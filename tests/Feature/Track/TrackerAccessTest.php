<?php

namespace Tests\Feature\Track;

use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $attrs = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Track', 'last_name' => 'Lead'], $attrs));
    }

    public function test_rate_limit_triggers_after_thirty_requests(): void
    {
        $lead = $this->lead();
        $code = $lead->tracking_code;

        for ($i = 0; $i < 30; $i++) {
            $this->get("/track/{$code}")->assertOk();
        }

        $this->get("/track/{$code}")->assertStatus(429);
    }

    public function test_invalid_code_returns_404_not_a_crash(): void
    {
        $this->get('/track/NOPE-NOPE-NOPE')
            ->assertStatus(404)
            ->assertInertia(fn ($page) => $page->component('track/TrackingPage')->where('error', fn ($e) => ! empty($e)));
    }

    public function test_visit_stamps_last_seen_at(): void
    {
        $lead = $this->lead();
        $this->assertNull($lead->last_seen_at);

        $this->get("/track/{$lead->tracking_code}")->assertOk();

        $this->assertNotNull($lead->fresh()->last_seen_at);
    }

    public function test_visit_is_logged_in_activity_logs(): void
    {
        $lead = $this->lead();

        $this->get("/track/{$lead->tracking_code}")->assertOk();

        $log = ActivityLog::where('action', 'lead.tracker_view')->where('entity_id', $lead->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($lead->tracking_code, $log->actor_name);
        $this->assertSame('public', $log->portal);
    }

    public function test_visit_log_excludes_sensitive_data(): void
    {
        $lead = $this->lead([
            'passport_number' => 'P9999999',
            'email' => 'secret@example.com',
        ]);

        $this->get("/track/{$lead->tracking_code}")->assertOk();

        $log = ActivityLog::where('action', 'lead.tracker_view')->where('entity_id', $lead->id)->first();
        $serialized = json_encode([$log->description, $log->properties, $log->metadata, $log->changes]);

        $this->assertStringNotContainsString('P9999999', $serialized);
        $this->assertStringNotContainsString('secret@example.com', $serialized);
    }

    public function test_general_lead_with_picked_visa_still_gets_document_checklist(): void
    {
        // A registration/education lead is NOT an immigration case. Even when
        // it carries an inz_visa_type (the "Visa applying for" they picked),
        // the tracker must show the general Document Checklist (the 4 ordered
        // sections), NOT the immigration visa's own checklist — while the
        // header label reflects the picked visa.
        $lead = $this->lead([
            'is_immigration_case' => false,
            'inz_visa_type' => 'Tourist Visa',
        ]);

        $this->get("/track/{$lead->tracking_code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/TrackingPage')
                // Label reflects the selected visa …
                ->where('visa.name', 'Tourist Visa')
                // … but the requirement sections are the general 4, in order.
                ->where('visa.section_order', [
                    'Education Documents',
                    'Other Documents',
                ])
            );
    }

    public function test_general_lead_without_visa_shows_document_checklist_label(): void
    {
        $lead = $this->lead(['is_immigration_case' => false]);

        $this->get("/track/{$lead->tracking_code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/TrackingPage')
                ->where('visa.name', 'Document Checklist')
            );
    }

    public function test_repeat_visits_within_window_do_not_spam_the_log(): void
    {
        $lead = $this->lead();

        $this->get("/track/{$lead->tracking_code}");
        $this->get("/track/{$lead->tracking_code}");
        $this->get("/track/{$lead->tracking_code}");

        // Debounced — one log entry despite three visits in the same window.
        $this->assertSame(1, ActivityLog::where('action', 'lead.tracker_view')->where('entity_id', $lead->id)->count());
    }
}
