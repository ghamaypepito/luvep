<?php

namespace Tests\Feature\Education;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventEmailSeriesPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_preview_requires_education_portal_access(): void
    {
        $url = '/portal/education/email-series';

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'sales']))->get($url)->assertForbidden();
    }

    public function test_education_staff_can_review_all_eight_emails(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/email-series')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/education/EventEmailSeries')
                ->has('emails', 8)
                ->where('emails.0.timing', '1 day after enrolment')
                ->where('emails.7.timing', 'Every 365 days, starting on day 365')
                ->where('emails.0.html', fn (string $html) => str_contains($html, 'Hi Maria,')
                    && str_contains($html, '/images/luvep-hr-logo.png')
                    && ! str_contains($html, 'href=')));
    }
}
